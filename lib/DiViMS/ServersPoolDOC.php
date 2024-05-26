<?php

namespace DiViMS;

use \DiViMS\SCW;
use \DiViMS\SSH;

use \parallel\{Runtime, Future, Channel, Events};


//https://sabre.io/vobject/icalendar/
//composer require sabre/vobject ~4.1
//https://sabre.io/vobject/usage_2/
use \Sabre\VObject;

// https://github.com/bigbluebutton/bigbluebutton-api-php
// https://github.com/bigbluebutton/bigbluebutton-api-php/wiki
// composer require bigbluebutton/bigbluebutton-api-php:~2.0.0
use \BigBlueButton\BigBlueButton;
use \BigBlueButton\Parameters\GetRecordingsParameters;
use \BigBlueButton\Parameters\EndMeetingParameters;



class ServersPoolDOC
{

    /**
     * Array of servers and metrics indexed by the Scalelite domain name of the server
     * @var array  'bbb-wX.example.com' => ['scalelite_state' -> '(enabled|disabled|cordoned)', 'scalelite_status' -> '(online|offline)',
     *  'meetings', 'users', 'largest_meeting', 'videos', 'scalelite_id', 'secret', 'scalelite_load', load_multiplier'
     *  'cpus', 'uptime', 'loadavg1', 'loadavg5', 'loadavg15', 'rxavg1', 'txavg1', 'internal_ipv4', 'external_ipv4', 'external_ipv6',
     *  'bbb_status' -> 'OK|KO',
     *  'hoster_id', 'hoster_state' -> (active|stopped|stopped in place|starting|stopping|locked|unreachable), 'hoster_state_duration','hoster_public_ip', 'hoster_private_ip',
     *  'divims_state' -> '(active|in maintenance)'
     *  'custom_state' -> 'unresponsive|to recycle|malfunctioning|null'
     *  'server_type' -> 'virtual machine|bare metal'
     */
    public $list = [];

    /**
     * Capacity (max number of participants) for a single server
     * @var int
     */
    private $server_capacity;

    /**
     * Configuration array
     * @var \DiViMS\Config
     */
    public $config;

    /**
     * Logger object
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * Hoster Server API
     * @var \DiViMS\SCW
     */
    private $hoster_api;

    /**
     * Zabbix controller API
     * @var \ZabbixApi\ZabbixApi
     */
    private $zabbix_api;

    private $digitalocean_client;

    public function __construct(\DiViMS\Config $config, \Psr\Log\LoggerInterface $logger)
    {

        $this->config = $config;
        $this->logger = $logger;
        $this->server_capacity = $this->config->get('pool_capacity') / $this->config->get('pool_size');
        if ($this->config->get('hoster_api') == 'DOC') {
            $this->hoster_api = new DOC($this->config, $this->logger);
        }


    }

    /**
     * Poll scalelite pool
     * @param bool $poll_active_servers Wether to poll active servers for statistics (CPU intensive)
     * @return array list of servers with data
     */
    public function poll(bool $poll_active_servers = false)
    {

        $this->logger->info("Poll/Gather data for servers");

        // Poll Scalelite
        if (!($servers = $this->pollScalelite())) return false;

        // Poll hoster API for virtual machine servers ids
        $hoster_servers = $this->pollHoster();
        if ($hoster_servers === false and $this->config->get('bare_metal_servers_count') == 0) return false;
        $servers = array_merge_recursive($servers, $hoster_servers);

        // Add bare metal servers if any
        $bare_metal_servers = [];
        for ($server_number = 1; $server_number <= $this->config->get('bare_metal_servers_count'); $server_number++) {
            $domain = $this->getServerDomain($server_number);
            $port = $this->config->get('ssh_port'); 
            $wait_timeout_seconds = 1; 
            if($fp = fsockopen($domain, $port, $errCode, $errStr, $wait_timeout_seconds)){   
                $state = 'active';
                $this->logger->debug("Bare metal server $domain detected : Reachable by SSH on port $port.", ['domain' => $domain]);
            } else {
                $state = 'unreachable';
                $this->logger->error("SSH unreachable bare metal server $domain detected. MANUAL INTERVENTION REQUIRED !", ['domain' => $domain]);
            } 
            fclose($fp);
            $bare_metal_servers[$domain] = [
                'hoster_state' => $state,
                'server_type' => 'bare metal',
            ];
        }
        $servers = array_merge_recursive($servers, $bare_metal_servers);


        // Poll active servers in parallel if required (CPU and time intensive)
        if ($poll_active_servers) {
            $active_servers = $this->getFilteredArray($servers, ['hoster_state' => 'active']);
            echo "list of active servers: " . json_encode($active_servers, JSON_PRETTY_PRINT);
            if (!empty($active_servers)) {
                $active_servers = $this->SSHPollBBBServers($active_servers);
                echo "list of active servers: " . json_encode($active_servers, JSON_PRETTY_PRINT);
                $servers = array_merge($servers, $active_servers);
            }
        }

        // Create tmp directory if not exists
        if (!is_dir($this->config->get('base_directory') . '/tmp')) {
            mkdir($this->config->get('base_directory') . '/tmp', 0777);
        }

        $maintenance_file = $this->config->get('base_directory') . '/tmp/' . $this->config->get('project') . $this->config->get('maintenance_file_suffix') . '.json';

        // Create file if not exists
        if (!file_exists($maintenance_file)) {
            file_put_contents($maintenance_file, '');
        }

        $servers_in_maintenance = json_decode(file_get_contents($maintenance_file), true);
        if (!empty($servers_in_maintenance)) {
            $this->logger->warning("Servers in maintenance. Ignoring for adaptation.", ['maintenance_list' => implode(',', $servers_in_maintenance)]);
            foreach($servers_in_maintenance as $number) {
                $domain = $this->getServerDomain($number);
                $servers[$domain]['divims_state'] = 'in maintenance';
            }
        }

        foreach ($servers as $domain => $v) {


            // If uptime is undefined, set a  negative value
            $servers[$domain]['uptime'] = $v['uptime'] ?? -1;
            if (($v['divims_state'] ?? '') != 'in maintenance') {
                $servers[$domain]['divims_state'] = 'active';
            }

            $bbb_status = $v['bbb_status'] ?? 'undefined';

            // Mark nonexistent servers
            if  (!isset($v['hoster_state'])) $servers[$domain]['hoster_state'] = 'nonexistent';

            // Set 'hoster_state_duration for active bare metal servers
            if ($v['server_type'] == 'bare metal' and $v['hoster_state'] == 'active') {
                $v['hoster_state_duration'] = round($v['uptime'] / 60);
                $servers[$domain]['hoster_state_duration'] = $v['hoster_state_duration'];
            }

            // Tag servers that need to be replaced
            $divims_state = $servers[$domain]['divims_state'];
            $scalelite_status = $v['scalelite_status'];
            if ($v['hoster_state'] == 'active' and $v['scalelite_status'] == 'offline' and $v['hoster_state_duration'] >= 240) {
                // Mark server as unresponsive if it is offline in Scalelite and active since at least 4 minutes
                $log_context = compact('domain', 'bbb_status', 'divims_state');
                if ($v['server_type'] == 'bare metal') {
                    $this->logger->error("Unresponsive bare metal server $domain detected. Tag server as 'unresponsive'. MANUAL INTERVENTION REQUIRED !", $log_context);
                } else {
                    $this->logger->error("Unresponsive virtual machine server $domain detected. Tag server as 'unresponsive'. Server will be powered off unless it is in maintenance.",  $log_context);
                }
                $servers[$domain]['custom_state'] = 'unresponsive';
            } elseif ($v['hoster_state'] == 'active' and $bbb_status == 'KO' and $v['hoster_state_duration'] >= 120) {
                // Also tag server as 'malfunctioning' when BBB malfunctions
                $log_context = compact('domain', 'scalelite_status', 'bbb_status', 'divims_state');
                if ($v['server_type'] == 'bare metal') {
                    $this->logger->error("BBB malfunction detected for bare metal server $domain. Tag server as 'malfunctioning'. Server will be rebooted unless it is in maintenance.", $log_context);
                } else {
                    $this->logger->error("BBB malfunction detected for virtual machine server $domain. Tag server as 'malfunctioning'.  Server will be powered off unless it is in maintenance.", $log_context);
                }
                $servers[$domain]['custom_state'] = 'malfunctioning';
            } elseif ($v['uptime'] >= $this->config->get('server_max_recycling_uptime')) {
                // Alternatively check if server should be recycled due to long uptime
                $server_max_recycling_uptime = $this->convertSecToTime($this->config->get('server_max_recycling_uptime'));
                $uptime = $this->convertSecToTime($v['uptime']);
                $log_context = compact('domain', 'bbb_status', 'divims_state', 'server_max_recycling_uptime', 'uptime');
                if ($v['server_type'] == 'bare metal') {
                    $this->logger->warning("Uptime above limit for bare metal server $domain detected. Server will be rebooted unless it is in maintenance.", $log_context);
                } else {
                    $this->logger->warning("Uptime above limit for virtual machine server $domain detected. Tag server as 'to recycle'. Server will be powered off unless it is in maintenance.", $log_context);
                }
                $servers[$domain]['custom_state'] = 'to recycle';
            } else {
                $servers[$domain]['custom_state'] = null; 
            }

        }
        $this->list = $servers;
        return $servers;
    }

    /**
     * Convert seconds to readable duration
     * Usage example : convertSecToTime(3500); //58 minutes and 20 seconds
     * https://stackoverflow.com/questions/49307094/php-function-to-convert-seconds-into-years-months-days-hours-minutes-and-sec
     * 
     * @param int $sec Number of seconds to convert
     * @return string The readable duration
     */
    private function convertSecToTime(int $secs)
    {
        if (!$secs = (int)$secs)
            return '0 seconds';

        $units = [
            'week' => 604800,
            'day' => 86400,
            'hour' => 3600,
            'minute' => 60,
            'second' => 1
        ];

        $strs = [];

        foreach ($units as $name => $int) {
            if ($secs < $int)
                continue;
            $num = (int) ($secs / $int);
            $secs = $secs % $int;
            $strs[] = "$num $name".(($num == 1) ? '' : 's');
        }

        return implode(', ', $strs);
    }

    /**
     * Get the list of servers with an optional filter
     * @param array $filter An array of pairs keys values e.g. array('scalelite_state' => 'enabled'). Logical 'and' between values.
     * @param bool $exclude_maintenance Whether to exclude servers in maintenance from the list or not
     * @param bool $exclude_bare_metal Whether to exclude bare metal servers from the list or not
     */
    public function getList(array $filter = [], bool $exclude_maintenance = true, bool $exclude_bare_metal = true)
    {
        $list = $this->list;

        // Default : exclude servers in maintenance (value set in poll() function)
        if ($exclude_maintenance) {
            foreach ($list as $domain => $v) {
                if ($v['divims_state'] == 'in maintenance') {
                    unset($list[$domain]);
                }
            }
        }

        // Default : exclude bare metal (physic) servers in maintenance (value set in poll() function)
        if ($exclude_bare_metal) {
            foreach ($list as $domain => $v) {
                if ($v['server_type'] == 'bare metal') {
                    unset($list[$domain]);
                }
            }
        }

        return $this->getFilteredArray($list, $filter);
    }

    /**
     * Filter a two-dimensional array
     * @param array $fiter An array of pairs keys values, performs a logical 'AND' between criteria
     */
    private function getFilteredArray(array $data, array $filter)
    {

        //We use array_filter because it is cleaner than foreach
        //although not faster : http://www.levijackson.net/are-array_-functions-faster-than-loops/
        $data = array_filter($data, function ($a) use ($filter) {
            $r = true;
            foreach ($filter as $k => $v) {
                if ($a[$k] != $v) {
                    $r = false;
                    break;
                }
            }
            return $r;
        });
        return $data;
    }

    /**
     * Compute hostname from server_number. Ex : arawa-p-bbb-w5
     * @param int $server_number 
     */
    public function getHostname(int $server_number)
    {
        return str_replace("X", $server_number, $this->config->get('clone_hostname_template'));
    }

    /**
     * Compute FQDN hostname from server_number. Ex : arawa-p-bbb-w5.ext.arawa.fr
     * @param int $server_number 
     */
    public function getHostnameFQDN(int $server_number)
    {
        return $this->getHostname($server_number) . '.' . $this->config->get('clone_dns_entry_subdomain') . '.' . $this->config->get('clone_dns_entry_zone');
    }

    /**
     * Compute server domain from server_number. Ex : bbb-w5.example.com
     * @param int $server_number 
     */
    public function getServerDomain(int $server_number)
    {
        return str_replace("X", $server_number, $this->config->get('clone_domain_template'));
    }

    /**
     * Compute server number from Scalelite domain
     * @param string $domain 
     */
    public function getServerNumberFromDomain(string $domain)
    {
        $pattern = str_replace(array('X', '.'), array('(\d+)', '\.'), $this->config->get('clone_domain_template'));
        preg_match("/$pattern/", $domain, $matches);
        return (isset($matches[1])) ? intval($matches[1]) : FALSE;
    }


     /**
     * Compute server numbers from Scalelite domains list
     * @param array $domains
     * @return array Ordered list of server numbers
     */
    public function getServerNumbersFromDomainsList(array $domains)
    {
        $numbers = [];
        foreach($domains as $domain) {
            $numbers[] = $this->getServerNumberFromDomain($domain);
        }
        sort($numbers, \SORT_NUMERIC);

        return $numbers;
    }

     /**
     * Compute server Scalelite domains from numbers list
     * @param array $numbers
     * @return array List of server domains
     */
    public function getServerDomainsFromNumbersList(array $numbers)
    {
        $domains = [];
        foreach($numbers as $number) {
            $domains[] = $this->getServerDomain($number);
        }

        return $domains;
    }

    /**
     * Compute server number from Hoster Hostname
     * @param string $hostname 
     */
    public function getServerNumberFromHostname(string $hostname)
    {
        $pattern = str_replace('X', '(\d+)', $this->config->get('clone_hostname_template'));
        preg_match("/$pattern/", $hostname, $matches);
        return (isset($matches[1])) ? intval($matches[1]) : FALSE;
    }

    /**
     * Compute parameters for parallel processing
     * @param int $min_id Minimum index to process
     * @param int $max_id Maximum index to process
     * @param int $workers Number of parallel processes to launch. Defaults to configration parameter 'poll_max_workers'.
     * @return array(int $batch_size, int $workers) Batch size and number of workers. 
     */
    public function getParallelParameters(int $min_id, int $max_id, int $workers = NULL)
    {

        if (is_null($workers)) {
            $workers = $this->config->get('poll_max_workers');
        }

        $total_ids = $max_id - $min_id;
        $workers = ($total_ids >= $workers) ? $workers : $total_ids;

        // Try to divide IDs evenly across the number of workers
        $batch_size = ceil($total_ids / $workers);
        // The last batch gets whatever is left over
        $last_batch = $total_ids % $batch_size;
        $quotient = intdiv($total_ids, $batch_size);
        $workers = ($last_batch == 0) ? $quotient : $quotient + 1;

        $this->logger->debug("Total IDs: $total_ids");
        $this->logger->debug("Workers : $workers");
        $this->logger->debug("Batch Size: $batch_size");
        $this->logger->debug("Last Batch: $last_batch");

        return [$batch_size, $workers];
    }

    /**
     * Query data from Scalelite host
     * @param string Hostname of the host
     */
    private function pollScalelite()
    {

        $this->logger->info('Poll Scalelite');

        // First get statuses
        $this->logger->info('Run "status" command on Scalelite to gather meeting info');
        $ssh = new SSH(['host' => $this->config->get('scalelite_host')], $this->config, $this->logger);
        //We put COLUMNS=1000 so that docker does not limit to 80 columns output (and wraps lines)

        if ($ssh->exec("sudo docker exec -e COLUMNS=1000 scalelite-api ./bin/rake status", ['max_tries' => 3])) {
            $table = $ssh->getOutput();
        } else {
            $this->logger->warning("Can not poll Scalelite server for meeting infos (./bin/rake status command).", ['ssh_return_value' => $ssh->getReturnValue()]);
        }

        if (isset($table)) {

            //echo $table;

            /*
                    HOSTNAME          STATE   STATUS  MEETINGS  USERS  LARGEST MEETING  VIDEOS
            bbb-w1.univ-paris8.fr   enabled  online         0      0                0       0
            bbb-w10.univ-paris8.fr  enabled  online         0      0                0       0
            bbb-w11.univ-paris8.fr  enabled  online         0      0                0       0
            ...
            */

            // Explode line by line, last line is empty
            $table = explode(PHP_EOL, $table);
            //var_dump($table);

            //Parse data
            $data = array();
            $entries = count($table) - 1;
            
            if ($entries != $this->config->get('pool_size')) {
                $this->logger->critical("Scalelite 'status' polling: Server entries count does not match pool size.", ['pool_size' => $this->config->get('pool_size'), 'result_count' => $entries]);
                return false;
            } else {
                $this->logger->info("Scalelite 'status' polling OK: $entries server entries matches pool size.");
            }

            for ($i = 1; $i <= $entries; $i++) {
                // Explode line
                $p1 = preg_split('/\s+/', $table[$i], -1, PREG_SPLIT_NO_EMPTY);

                $domain = $p1[0];

                if (isset($data[$domain])) {
                    $this->logger->critical("Scalelite polling: Duplicate entry in 'status' request.", ['domain' => $domain]);
                    return false;
                }

                $data[$domain] = [
                    //'scalelite_state' => $p1[1],
                    //'scalelite_status' => $p1[2],
                    'meetings' => intval($p1[3]),
                    'users' => intval($p1[4]),
                    'largest_meeting' => intval($p1[5]),
                    'videos' => intval($p1[6]),
                ];
            }
        }

        // Then get ids and secrets
        $this->logger->info('Run "servers" command on Scalelite to gather statuses and load');
        $ssh = new SSH(['host' => $this->config->get('scalelite_host')], $this->config, $this->logger);

        if ($ssh->exec("sudo docker exec scalelite-api ./bin/rake servers", ['max_tries' => 3])) {
            $table = $ssh->getOutput();
        } else {
            $this->logger->alert("Can not poll Scalelite server for servers info (./bin/rake servers command).", ['ssh_return_value' => $ssh->getReturnValue()]);
            return false;
        }

        /*
        id: 8204fdb0-de87-484c-b708-9f990d4ee561
            url: https://bbb-w29.example.com/bigbluebutton/api
            secret: xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
            enabled
            load: unavailable
            load multiplier: 1.0
            offline
        */

        // Explode line by line
        $table = explode(PHP_EOL, $table);
        //Parse data

        $line_count = count($table);
        $entries = $line_count / 7;

        if ($entries != $this->config->get('pool_size')) {
            $this->logger->critical("Scalelite 'servers' polling: Server entries count does not match pool size.", ['pool_size' => $this->config->get('pool_size'), 'result_count' => $entries]);
            return false;
        } else {
            $this->logger->info("Scalelite 'servers' polling OK: $entries server entries matches pool size.");
        }

        for ($i = 0; $i < $line_count; $i += 7) {
            $id = substr($table[$i], 4);
            preg_match('#https://([^/]+)#', $table[$i + 1], $matches);
            $domain = $matches[1];
            $secret = substr(trim($table[$i + 2]), 8);
            $scalelite_state = trim($table[$i + 3]);
            $load = substr(trim($table[$i + 4]), 6);
            $load = ($load == 'unavailable') ? -1.0 : floatval($load);
            $load_multiplier = floatval(substr(trim($table[$i + 5]), 17));
            $scalelite_status = trim($table[$i + 6]);

            //Add to existing indexed data
            $data[$domain] = $data[$domain] ?? [];
            $data[$domain] = array_merge(
                $data[$domain],
                [
                    'scalelite_id' => $id,
                    'secret' => $secret,
                    'scalelite_state' => $scalelite_state,
                    'scalelite_status' => $scalelite_status,
                    'scalelite_load' => $load,
                    'load_multiplier' => $load_multiplier
                ]
            );
        }
        //var_dump($data);

        return $data;
    }

    /**
     * Retrieve bbb server specific data in parallel processing
     * It is all the more effective that network speed test requires a 0.1 second delay on each host
     * See https://www.php.net/manual/fr/parallel.run.php
     * @param array $data Array of servers
     */
    private function SSHPollBBBServers(array $data, bool $error_log = true)
    {

        $this->logger->info('Poll BigBlueButton servers in parallel.');

        //Create a flat data array from indexed array
        foreach ($data as $domain => $v) {
            $flatdata[] = array_merge($v, ['domain' => $domain]);
        }

        $data = $flatdata;

        // Parallel processing
        $min_id = 0;
        $max_id = count($data);

        list($batch_size, $workers) = $this->getParallelParameters($min_id, $max_id);

        //Standalone function to be executed in a parallel thread
        //Receives all parameters : can not access global variables
        $producer = function (int $worker, int $start_id, int $end_id, array $data, bool $error_log, $serialized_config, $serialized_logger) {
            include_once __DIR__ . '/../vendor/autoload.php';

            spl_autoload_register(function ($class_name) {
                include __DIR__ . "/../" . str_replace('\\', '/', $class_name) . '.php';
            });

            $config = unserialize($serialized_config);
            $logger = unserialize($serialized_logger);

            $fetchCount = 1;
            $pool = new ServersPoolDOC($config, $logger);
            $more_data = [];
            for ($i = $start_id; $i < $end_id; $i++) {
                // The task here
                $domain = $data[$i]['domain'];
                $server_number = $pool->getServerNumberFromDomain($domain);
                echo "server number: $server_number\n";
                //echo $hostname;
                $time_pre = microtime(true);
                $ssh = new SSH(['host' => $domain], $config, $logger);
                if ($ssh->exec("/bin/bash < " . __DIR__ . "/../scripts/BBB-gatherStats", ['max_tries' => 3, 'sleep_time' => 5])) {
                    $out = $ssh->getOutput();
                } else {
                    if ($error_log) {
                        $logger->error("Can not poll BigBlueButton server $domain for stats.", ["domain" => $domain]);
                    }
                    $more_data[$i] = [];
                    continue;
                }

                $values = parse_ini_string($out);
                $time_post = microtime(true);
                $load = explode(' ', $values['load_averages']);
                //var_dump($load);
                $cpus = intval($values['cpu_count']);
                //var_dump($cpus);

                $more_data[$i] = [
                    'uptime' => intval($values['uptime']),
                    'cpus' => $cpus,
                    'loadavg1' => floatval($load[0]) / $cpus * 100,
                    'loadavg5' => floatval($load[1]) / $cpus * 100,
                    'loadavg15' => floatval($load[2]) / $cpus * 100,
                    'rxavg1' => floatval($values['rx_avg1']),
                    'txavg1' => floatval($values['tx_avg1']),
                    'internal_ipv4' => $values['internal_ipv4'],
                    'external_ipv4' => $values['external_ipv4'],
                    'external_ipv6' => $values['external_ipv6'],
                    'bbb_status' => $values['bbb_status'],
                ];
                //print "Worker " . $worker .  " finished batch " . $fetchCount . " for ID " . $i . " in ". ($time_post - $time_pre) . " seconds\n";
                //echo "Domain treated : $domain\n";
            }

            // This worker has completed their entire batch
            //print "Worker " . $worker .  " finished\n";
            //var_dump($more_data);
            return $more_data;
        };

        // Launch parallel processes (workers)
        try {
            for ($i = 0; $i < $workers; $i++) {
                $start_id = $min_id + ($i * $batch_size);
                $end_id = $start_id + $batch_size;
                if ($i == ($workers - 1)) {
                    $end_id = $max_id;
                }
                $active[$i] = true;
                $future[$i] = \parallel\run($producer, [($i + 1), $start_id, $end_id, $data, $error_log, serialize($this->config), serialize($this->logger)]);
            }
        } catch (\Error $err) {
            $this->logger->error('Parralel process "SSHPollBBBServers" failed.', ['message' => $err->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Parralel process "SSHPollBBBServers" failed', ['message' => $e->getMessage()]);
        }

        // Agregate new values when a worker finishes until all workers have finished
        while (!empty($active)) {
            foreach ($active as $i => $v) {
                if ($future[$i]->done()) { //If worker $i finished
                    unset($active[$i]); //Remove from active list
                    $start_id = $min_id + ($i * $batch_size);
                    $end_id = $start_id + $batch_size;
                    if ($i == ($workers - 1)) {
                        $end_id = $max_id;
                    }
                    // get value
                    //print "Treating Worker " . ($i+1) . "\n";
                    $more_data = $future[$i]->value();
                    // Merge new data into main array
                    for ($j = $start_id; $j < $end_id; $j++) {
                        $data[$j] = array_merge($data[$j], $more_data[$j]);
                    }
                    break;
                }
            }
        }

        //Recreate and indexed array
        $data = array_column($data, NULL, 'domain');
        return $data;
    }
    public function processServer($server, $domain_name) {
        // Parse the modification date
        $modification_date = \DateTime::createFromFormat("Y-m-d\TH:i:s\Z", $server->createdAt);
    
        // Check if the date was parsed successfully
        if ($modification_date === false) {
            $this->logger->error("Failed to parse date", [
                'createdAt' => $server->createdAt,
                'server_name' => $server->name,
            ]);
            $modification_date = new \DateTime('NOW'); // Use current date if parsing failed
        }
    
        // Calculate the hoster state duration
        $now = new \DateTime('NOW');
        $hoster_state_duration = $now->getTimestamp() - $modification_date->getTimestamp();
    
        // Initialize IP addresses
        $public_ip = '';
        $private_ip = '';
    
        // Extract IP addresses
        foreach ($server->networks as $network) {
            if ($network->type == 'public') {
                $public_ip = $network->ipAddress;
            } elseif ($network->type == 'private') {
                $private_ip = $network->ipAddress;
            }
            echo $network->type . ' ' . $network->ipAddress . PHP_EOL;
        }
    
        // Organize server data
        $server_data = [
            'hoster_id' => $server->id,
            'hoster_state' => $server->status,
            'hoster_state_duration' => $hoster_state_duration,
            'hoster_public_ip' => $public_ip,
            'hoster_private_ip' => $private_ip,
            'server_type' => 'virtual machine',
        ];
    
        // Log server details
        $this->logger->info("Server details", [
            'name' => $server->name,
            'hoster_id' => $server->id,
            'hoster_state' => $server->status,
            'hoster_state_duration' => $hoster_state_duration,
            'hoster_public_ip' => $public_ip,
            'hoster_private_ip' => $private_ip,
            'server_type' => 'virtual machine',
        ]);
    
        // Return organized server data
        return [$server->name . '.' . $domain_name => $server_data];
    }

    /**
     * Retrieve server data from hoster
     * @param array $data Array of servers
     */
    private function pollHoster()
    {

        $this->logger->info('Poll Hoster API for servers data');

        $server_data = [];
        if ($this->config->get('hoster_api') == 'DOC') {
            $search_pattern = str_replace('X', '', $this->config->get('clone_hostname_template'));
            $this->logger->debug("Hoster : Search hostnames with pattern: $search_pattern");
            $domain_name= $this->config->get('domain_name');
            $max_tries = 3;
            $try_count = 0;
            $sleep = 30;

            while (true) {
                $try_count++;
                $servers = $this->hoster_api->getServers(['name' => $search_pattern]);
                if (empty($servers)) {
                    $this->logger->warning("Hoster polling: No matching servers in Digital O API. Wait $sleep seconds before retrying.", ['try' => $try_count]);
                } else {
                    foreach ($servers as $server) {
                        if (preg_match('/^' . preg_quote($search_pattern, '/') . '/', $server->name)) {
                        
                            // Compute state duration in seconds from modification date
                            $result = $this->processServer($server, $domain_name);
                            $server_data = array_merge($server_data, $result);
                         }
                    }

                    return $server_data;
                }
                if ($try_count == $max_tries) break;
                sleep($sleep);
            }
            $this->logger->critical("Can not get servers data at Digital O. Digital O API seems unreachable. Abandon after $max_tries tries.");
            return false;
        }
    }

    /**
     * Compute stats on the servers' list
     * @param array $fiter An array of pairs keys values e.g. array('scalelite_state' => 'enabled')
     */
    public function getStats(array $filter = [])
    {

        $data = $this->getList($filter);

        if (empty($data)) {
            return false;
        }

        $count = count($data);

        //Compute averages
        $stats_items = ['meetings', 'users', 'videos', 'loadavg1', 'loadavg5', 'loadavg15', 'rxavg1', 'txavg1'];
        foreach ($stats_items as $v) {
            $stats[$v]['average'] = array_sum(array_column($data, $v)) / $count;
            $stats[$v]['deviation'] = \stats_standard_deviation(array_column($data, $v)); // écart-type
            $stats[$v]['relative_deviation'] = $stats[$v]['deviation'] / $stats[$v]['average']; //Coefficient de corrélation
            $stats[$v]['variance'] = \stats_variance(array_column($data, $v));
            $stats[$v]['percentile80'] = \stats_stat_percentile(array_column($data, $v), 0.8); //80% de l'échantillon est sous cette valeur
        }

        // Compute totals
        $stats_items = ['meetings', 'users', 'videos', 'cpus'];
        foreach ($stats_items as $v) {
            $stats[$v]['total'] = array_sum(array_column($data, $v));
        }

        return $stats;
    }

    /**
     * Execute an action on the Scalelite server
     * @param array $params ["action" => ('enable'|'cordon'), "domain" => 'domain.example.com', 'id' => scalelite_id]. id is optional but takes precedence over domain to compute the id.
     * @return bool Success status
     */
    public function scaleliteActOnServer(array $params = [])
    {
        if (isset($params['action'])) {
            $ssh = new SSH(['host' => $this->config->get('scalelite_host')], $this->config, $this->logger);
            $base_command = "sudo docker exec scalelite-api ./bin/rake";
            $domain = $params['domain'];
            $id = $params['id'] ?? $this->list[$domain]['scalelite_id'];

            switch ($params['action']) {
                case 'enable':
                    $this->logger->info("Enable server in Scalelite", ['domain' => $domain, 'id' => $id]);
                    if ($ssh->exec("$base_command servers:enable[$id]", ['max_tries' => 3])) {
                        return true;
                    } else {
                        $this->logger->error("Can not enable server $domain in Scalelite", ['domain' => $domain, 'id' => $id, 'Scalelite Host' => $this->config->get('scalelite_host'),]);
                        return false;
                    }

                    break;

                case 'cordon':
                    $this->logger->info("Cordon server in Scalelite", ['domain' => $domain, 'id' => $id]);
                    if ($ssh->exec("$base_command servers:cordon[$id]", ['max_tries' => 3])) {
                        return true;
                    } else {
                        $this->logger->error("Can not cordon server $domain in Scalelite", ['domain' => $domain]);
                        return false;
                    }
                    break;
            }
        }

        return false;
    }

    /**
     * Execute an action on the Scalelite server for several domains in parallel
     * @param array $params ["action" => ('enable'|'cordon'|'disable'), "domains" => ['domain1.example.com', 'domain2.example.com'...]]
     * @return array Success list
     */
    public function scaleliteActOnServersList(array $params = [])
    {
        $domains = $params['domains'];
        $action = $params['action'];
        switch ($action) {
            case 'cordon':
                $state = 'cordoned';
                break;
            case 'enable':
                $state = 'enabled';
                break;
            case 'disable':
                $state = 'disabled';
                break;
            default:
                $this->logger->error("Unknown Scalelite action '$action'. Can not change states.");
                return false;
        }

        if (empty($domains)) {
            $this->logger->info('Domains list is empty. No state to change in Scalelite');
            return false;
        }

        $this->logger->info(ucfirst($action) . " " . count($domains) . " servers in Scalelite in parallel.");

        // Parallel processing
        $min_id = 0;
        $max_id = count($domains);

        list($batch_size, $workers) = $this->getParallelParameters($min_id, $max_id, 5);

        //Standalone function to be executed in a parallel thread
        //Receives all parameters : can not access global variables
        $producer = function (int $worker, int $start_id, int $end_id, array $domains, string $action, array $list, $serialized_config, $serialized_logger) {
            include_once __DIR__ . '/../vendor/autoload.php';

            spl_autoload_register(function ($class_name) {
                include __DIR__ . "/../" . str_replace('\\', '/', $class_name) . '.php';
            });

            $config = unserialize($serialized_config);
            $logger = unserialize($serialized_logger);
            $pool = new ServersPool($config, $logger);
            $success_list = [];

            for ($i = $start_id; $i < $end_id; $i++) {
                $domain = $domains[$i];
                $result = $pool->scaleliteActOnServer(['action' => $action, 'id' => $list[$domain]['scalelite_id'], 'domain' => $domain]);
                if ($result) {
                    $success_list[$i] = $domain;
                }
            }

            return $success_list;
        };

        try {
            // Create our workers and have them start working on their task
            for ($i = 0; $i < $workers; $i++) {
                $start_id = $min_id + ($i * $batch_size);
                $end_id = $start_id + $batch_size;
                if ($i == ($workers - 1)) {
                    $end_id = $max_id;
                }
                $this->logger->debug("Launching parallel process.", ['process_number' => $i, 'start_id' => $start_id, 'end_id' => $end_id]);
                $active[$i] = true;
                $future[$i] = \parallel\run($producer, [($i + 1), $start_id, $end_id, $domains, $action, $this->list, serialize($this->config), serialize($this->logger)]);
                sleep(1);
            }
        } catch (\Error $err) {
            $this->logger->error('Parallel process "ScaleliteActOnServersList" failed.', ['error_message' => $err->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Parallel process "ScaleliteActOnServersList" failed.', ['error_message' => $e->getMessage()]);
        }

        // Wait until all parallel processes have finished
        $success_list = [];
        while (!empty($active)) {
            foreach ($active as $i => $v) {
                if ($future[$i]->done()) { //If worker $i finished
                    unset($active[$i]); //Remove from active list
                    $more_success_list = $future[$i]->value();
                    $success_list = array_merge($success_list, $more_success_list);
                    break;
                }
            }
        }

        // Update $this->list with new statuses
        foreach($success_list as $domain) {
            $this->list[$domain]['scalelite_state'] = $state;
        }
        $diff = array_diff($domains, $success_list);
        if (!empty($diff)) {
            $this->logger->error("Some states could not be changed in Scalelite", ["servers_in_error_list" => json_encode($diff, JSON_PRETTY_PRINT)]);
        }

        return $success_list;
    }

    /**
     * Use Hoster Api to perform action on a list of servers
     * @param $params array ['action' => ('poweron'|'poweroff'|'terminate'|'reboot'|'stop_in_place'|...), 'domains' => ['domain1n domain2, ...] | 'numbers' => [i1,i2,i3,...]] 
     * @return array Success list of server numbers or server domains
     */
    public function hosterActOnServersList(array $params)
    {

        if (!isset($params['action'])) {
            return false;
        }
        $action = $params['action'];
        $domains = $params['domains'] ?? [];
        $numbers = $params['numbers'] ?? [];
        if (empty($domains) and empty($numbers)) {
            return false;
        }
        if (!empty($domains)) {
            $numbers = $this->getServerNumbersFromDomainsList($domains);
        }

        if ($this->config->get('hoster_api') == 'DOC') {
            $search_pattern = str_replace('X', '', $this->config->get('clone_hostname_template'));
            $this->logger->debug("Search hostnames with pattern: $search_pattern");
            $servers = $this->hoster_api->getServers(['name' => $search_pattern]);
            //var_dump($servers);
            if (!isset($servers['servers'])) {
                $this->logger->error("No matching servers at hoster.", ['pattern' => $search_pattern]);
                return false;
            }
            $count = count($servers['servers']);
            $this->logger->debug("Matching servers count : $count");

            //Build hostnames list
            foreach ($numbers as $server_number) {
                $domain = $this->getServerDomain($server_number);
                $hostname = $this->getHostname($server_number);
                $hostname_list[] = $hostname;
            }

            $success_list = [];
            foreach ($servers['servers'] as $server) {
                if (in_array($server['name'], $hostname_list)) {
                    $server_id = $server['id'];
                    $this->logger->info("Perform hoster API action on server", ['action' => $action, 'hostname' => $server['name'], 'server_id' => $server_id]);
                    $result = $this->hoster_api->actOnServer($server_id, ['action' => $action]);
                    if ($result) {
                        $success_list[] = $this->getServerNumberFromHostname($server['name']);
                        continue;
                    }
                    usleep(200000);
                }
            }
        }

        if (isset($params['domains'])) {
            return $this->getServerDomainsFromNumbersList($success_list);
        }
        return $success_list;
    }

    /**
     * Create DNS entries for the new VM
     * A and AAAA record for the server
     * CNAME record for the domain, pointing to the server
     * 
     * @param array $data An array containing DNS data of the newly created VM ['hostname', 'external_ipv4', 'external_ipv6']
     **/
    public function createDNSEntriesOVH(array $server_data)
    {
        $ovh = new Api(
            $this->config->get('ovh_application_key'),
            $this->config->get('ovh_application_secret'),
            $this->config->get('ovh_endpoint'),
            $this->config->get('ovh_consumer_key')
        );

        //$result = $ovh->get('/domain/zone');

        // Create server A record
        $subdomain = $server_data['hostname'] . '.' . $this->config->get('clone_dns_entry_subdomain');
        $target = $server_data['external_ipv4'];
        $this->logger->info("Create A record", ['source' => $subdomain . '.' . $this->config->get('clone_dns_entry_zone'), 'target' => $target]);
        $result = $ovh->post('/domain/zone/' . $this->config->get('clone_dns_entry_zone') . '/record', array(
            'fieldType' => 'A', // Resource record Name (type: zone.NamedResolutionFieldTypeEnum)
            'subDomain' => $subdomain, // Resource record subdomain (type: string)
            'target' => $target, // Resource record target (type: string)
            'ttl' => NULL, // Resource record ttl (type: long)
        ));
        //print_r( $result );

        if ($this->config->get('clone_dns_create_ipv6')) {
            // Create server AAAA record
            $this->logger->info("Create AAAA record");
            $result = $ovh->post('/domain/zone/' . $this->config->get('clone_dns_entry_zone') . '/record', array(
                'fieldType' => 'AAAA', // Resource record Name (type: zone.NamedResolutionFieldTypeEnum)
                'subDomain' => $server_data['hostname'] . '.' . $this->config->get('clone_dns_entry_subdomain'), // Resource record subdomain (type: string)
                'target' => $server_data['external_ipv6'], // Resource record target (type: string)
                'ttl' => NULL, // Resource record ttl (type: long)
            ));
            //print_r( $result );
        }

        if (isset($server_data['cname_domain_name'])) {
            // Create domain CNAME record
            $subdomain = $server_data['cname_domain_name'];
            $target = $server_data['hostname'] . '.' . $this->config->get('clone_dns_entry_subdomain') . $this->config->get('clone_dns_entry_zone') . '.';
            $this->logger->info("Create CNAME record", ['source' => $subdomain . '.' . $this->config->get('clone_dns_entry_zone'), 'target' => $target]);
            $result = $ovh->post('/domain/zone/' . $this->config->get('clone_dns_entry_zone') . '/record', array(
                'fieldType' => 'CNAME', // Resource record Name (type: zone.NamedResolutionFieldTypeEnum)
                'subDomain' => $subdomain, // Resource record subdomain (type: string)
                'target' =>  $target, // Resource record target (type: string)
                'ttl' => NULL, // Resource record ttl (type: long)
            ));
            //print_r( $result );
        }

        //Refresh the zone
        $this->logger->info("Refresh zone and wait 5 seconds for zone to be updated");
        $result = $ovh->post('/domain/zone/'  . $this->config->get('clone_dns_entry_zone') . '/refresh');
        sleep(5);
    }


    /**
     * Add a server to the pool by cloning a backed up image
     * @param int $server_number the server number
     * @return array|bool Server data or false
     **/
    public function hosterCloneAndStartServer(int $server_number)
    {

        $hostname = $this->getHostname($server_number);
        $domain = $this->getServerDomain($server_number);

        $this->logger->info("Start cloning new VM.", compact('hostname', 'domain'));

        if ($this->config->get('hoster_api') == 'DOC') {
            // Retrieve server IP
            $external_ipv4 = shell_exec("getent hosts $domain | cut -d' ' -f1");
            $external_ipv4 = preg_replace("/\r\n|\r|\n/", '', $external_ipv4);
           
            // Retrieve image id
            $image_id = $this->config->get('clone_image_id');
            $region_id=$this->config->get('clone_region_id');
            $tries = 0;
            $server_id = 0;
            while (true) {
                $tries++;
                $result = $this->hoster_api->createDroplet(  
                    $hostname,                                    // Tên droplet
                    $region_id,                                         // ID của khu vực (thay vì slug)
                    110,                                       // ID của kích thước (thay vì slug)
                    $image_id,                                 // ID của image (thay vì slug)
                    false,                                     // Có sao lưu
                    false,                                      // không có IPv6
                    "24c014f8-7d48-4ebf-bc79-91ac5475d6e5",    // UUID của VPC
                    [41923124],                                // Số hiệu SSH
                    "",                                        // Dữ liệu người dùng
                    true,                                      // Giám sát
                    [],                                        // Danh sách volume
                    [],                                        // Tags
                    false                                      // Tắt agent
                );
                if ($result->id) {
                    $server_id = $result->id;
                    $this->logger->info("Server created", ['hostname' => $hostname, 'server_id' => $server_id]);
                    break;
                } elseif ($tries == 3) {
                    $this->logger->error("Server $hostname creation error : $tries failed tentatives. Aborting.", ['hostname' => $hostname, "api_message" => print_r($result, true)]);
                    return false;
                }
                sleep(1);
            }

            // Attach the existing IP to the newly cloned server
            $tries = 0;
            $domain_name = $this->config->get('domain_name');
           
            $server_data =[];
            while (true){
                $tries++;
                $dropletinfor=$this->hoster_api->getDropletbyId($server_id);
                $result = $this->processServer($dropletinfor, $domain_name);
                $key = key($result);  // Get the first key of the result array
                if (isset($result[$key]['hoster_public_ip']) && !empty($result[$key]['hoster_public_ip'])) {
                    $server_data = array_merge($server_data, $result);
                    return $server_data;
                }  
                elseif ($tries == 3) {
                    $this->logger->error("Server $hostname get information error : $tries failed tentatives. Aborting.", ['hostname' => $hostname, "api_message" => print_r($result, true)]);
                    return false;
                }
                sleep(30);
            }
        }
        return false;
 
    }

    public function generateNFSCommands()
    {

        $this->poll(true);

        //Create list for NFS server
        echo "== NFS exports list ==\n";

        foreach ($this->list as $domain => $v) {
            echo $v['external_ipv4'] . "(rw,sync,subtree_check) ";
            echo json_encode($v) . "\n";
        }

        // And Firewall rules
        echo "\n== Firewall rules ==\n";
        foreach ($this->list as $domain => $v) {
            echo 'firewall-cmd --permanent --zone="private" --add-source="' . $v['external_ipv4'] . '"' . "\n";
        }

        //Create list for NFS server
        /*
        echo "== NFS exports list ==\n";
        foreach($this->list as $domain => $v) {
            echo $v['internal_ipv4'] . "(rw,sync,subtree_check) ";
        }
        */

        // Temporary : remove Firewall rules for internal IP
        /*
        echo "\n== Firewall rules ==\n";
        foreach($this->list as $domain => $v) {
            echo 'firewall-cmd --permanent --zone="private" --remove-source="' . $v['internal_ipv4'] . '"' ."\n";
        }
        */
    }

    public function generateFirewallRules()
    {

        $this->poll();

        // And Firewall rules
        echo "\n== Firewall rules ==\n";
        foreach ($this->list as $domain => $v) {
            $external_ipv4 = shell_exec("getent hosts $domain | cut -d' ' -f1");
            $external_ipv4 = preg_replace("/\r\n|\r|\n/", '', $external_ipv4);
            echo 'firewall-cmd --permanent --zone="private" --add-source="' . $external_ipv4 . '"' . "\n";
        }

    }

    /**
     * Check Hoster servers and DNS entries against hoster entries
     * @param
     * @return
     **/
    public function checkHosterValidity()
    {

        if($server_data = $this->pollHoster()) {
            echo "== Checking DNS Entries ==\n";
            $error = false;
            $IP_error_list = [];
            $servers_count = count($server_data);
            echo "Found $servers_count servers at hoster\n";
            if ($servers_count < $this->config->get('pool_size')) {
                $missing_list = range(1, $this->config->get('pool_size'));
                foreach($server_data as $domain => $v) {
                    unset($missing_list[$this->getServerNumberFromDomain($domain)-1]);
                }
                echo "Missing servers at hoster (" . count($missing_list) . "):" . implode(',', $missing_list) . "\n";
            } elseif ($servers_count = $this->config->get('pool_size')) {
                echo "OK\n";
            }
            foreach ($server_data as $domain => $v) {
                $ipv4 = $v['hoster_public_ip'];
                $dns_ipv4 = shell_exec("getent ahosts $domain | awk '{ print $1; exit }'");
                $dns_ipv4 = preg_replace("/\r\n|\r|\n/", '', $dns_ipv4);
                if ($ipv4 != $dns_ipv4 OR $ipv4 == '') {
                    $IP_error_list[] = $this->getServerNumberFromDomain($domain);
                    $error = true;
                }
            }
            sort($IP_error_list);
            if ($error) {
                echo "Hoster IPV4 and DNS IPV4 do not match for these servers (" . count($IP_error_list) . ") : " . implode(',', $IP_error_list) ."\n";
            } else {
                echo "All DNS entries are OK ! :) \n";
            }
            
        }

    }

    /**
     * Check pool validity
     * @param 
     * @return
     **/
    public function checkPoolValidity()
    {

        echo "Configured pool size :" . $this->config->get('pool_size') . "\n";
        $this->poll(true);
        $list = $this->list;

        $count = count($list);
        echo "Pool count : $count servers\n";

        //Check if count of unique IPs matches list count
        $unique_ipv4s = count(array_unique(array_column($list, "external_ipv4")));
        $result = ($count == $unique_ipv4s) ? "OK" : "KO";
        echo "Unique IPV4s : $unique_ipv4s $result\n";

        //Servers added to Scalelite
        $servers_added_to_scalelite = $this->getList(['scalelite_state' => 'enabled']);
        echo "Servers added to Scalelite :" . count($servers_added_to_scalelite) . "\n";

        //Check missing servers
        for ($i = 1; $i <= $this->config->get('pool_size'); $i++) {
            $server_domain = $this->getServerDomain($i);
            $complete_list[$server_domain] = 1;
        }
        foreach ($list as $domain => $v) {
            unset($complete_list[$domain]);
        }
        echo "== Missing servers ==\n";
        foreach ($complete_list as $domain) {
            echo "$domain,";
        }
        echo "\n";

        //Check if local IP matches DNS IP
        //https://unix.stackexchange.com/questions/20784/how-can-i-resolve-a-hostname-to-an-ip-address-in-a-bash-script
        echo "== Checking IPs ==\n";
        $error = false;
        $IP_error_list = [];
        foreach ($list as $domain => $v) {
            $ipv4 = $v['external_ipv4'];
            $dns_ipv4 = shell_exec("getent ahosts $domain | awk '{ print $1; exit }'");
            $dns_ipv4 = preg_replace("/\r\n|\r|\n/", '', $dns_ipv4);
            if ($ipv4 != $dns_ipv4) {
                $IP_error_list[] = $this->getServerNumberFromDomain($domain);
                $error = true;
            }
        }
        if ($error) {
            sort($IP_error_list);
            echo "Local IPV4 and DNS IPV4 do not match for these servers (" . count($IP_error_list) . ") : " . implode(',', $IP_error_list) . "\n";
        } else {
            echo "All OK !\n";
        }
    }

    /**
     * Compute next active servers count by checking ICS schedule
     * @return mixed (int|bool) -1 if no change, int value if there is a change, false if there is an error
     **/
    public function getNextCapacityFromSchedule()
    {
        $this->logger->info("Start next active servers count evaluation by schedule.");
        $ical_stream = $this->config->get('ical_stream');

        // Create cache directory if not exists
        if (!is_dir($this->config->get('base_directory') . '/cache')) {
            mkdir($this->config->get('base_directory') . '/cache', 0777);
        }

        $ical_cached_file = $this->config->get('base_directory') . '/cache/' . $this->config->get('project') . $this->config->get('ical_cached_file_suffix') . '.ics';

        $this->logger->info("Fetch online capacity adaptation ical calendar");
        try {
            $max_tries = 3;
            $try_count = 1;
            while (true) {
                $file_content = file_get_contents($ical_stream);
                if ($file_content !== false) {
                    // Save file to disk  and continue
                    $this->logger->debug("Save online ical calendar to file.");
                    if (file_put_contents($ical_cached_file, $file_content) === false) {
                        $this->logger->warning("Save online ical calendar to file failed");
                    }
                    break;
                }
                if ($try_count == $max_tries) {
                    throw new \Exception("Fetch online calendar failed $max_tries times.");
                }
                $this->logger->warning("Fetch online capacity calendar failed. Retrying.", ['try_count' => $try_count]);
                $try_count++;
                // Sleep a random time between 0.8s and 1.5s
                usleep(rand(800000,1500000));
            }

        } catch (\Exception $e) {
            $this->logger->error('Can not fetch online adaptation calendar. Using local cached calendar instead.', ['message' => $e->getMessage(), 'ical_stream' => $ical_stream]);
        }

        try {
            $this->logger->debug("Open local cached capacity adaptation ical calendar");
            $file = $ical_cached_file;
            if (!file_exists($file)) {
                throw new \Exception('File does not exist.');
            }
            $handle = @fopen($file, 'r');
            if (!$handle) {
                throw new \Exception('File open failed.');
            }
        } catch (\Exception $e) {
            $this->logger->critical('Can not open local cached ical file. Abort.', ['message' => $e->getMessage(), 'ical_cached_file' => $file]);
            return false;
        }

        try {
            $calendar = VObject\Reader::read($handle);
        } catch (VObject\ParseException $e) {
            $this->logger->error("Parse calendar failed", ['error_message' => $e->getMessage()]);
            return false;
        }

        fclose($handle);

        $timezone = new \DateTimeZone((string)$calendar->VTIMEZONE->TZID);
        //$timezone = new DateTimeZone('Europe/Paris')

        $now = new \DateTime('now', $timezone);
        $before = new \DateTime("now -7 days", $timezone);

        //Retrieve events for last 7 days
        $calendar = $calendar->expand($before, $now);

        if (!empty($calendar->VEVENT)) {
            // get most recent event in the past
            $start = 0;
            foreach($calendar->VEVENT as $event) {
                $new_start = $event->DTSTART->getDateTime()->getTimestamp();
                if ($new_start > $start) {
                    $start = $new_start;
                    $my_event = $event;
                }
            }
            $event = $my_event;
            $summary = (string)$event->SUMMARY;
            //echo  $summary . "\n";
            $start = $event->DTSTART->getDateTime();
            $end = $event->DTEND->getDateTime();
            // Compute interval minutes between now and event
            $interval = round(($now->getTimestamp() - $start->getTimestamp()) / 60);
            //echo $start->format('Y-m-d H:i:sP') . "\n";
            //echo $start->format('Y-m-d H:i:sP') . "\n";
            $this->logger->info("Found matching adaptation event $interval minutes ago.", ['start' => $start->format(\DateTime::ATOM), 'end' => $end->format(\DateTime::ATOM)]);
            $data = parse_ini_string($summary);
            if (isset($data['users'])) {
                $users = $data['users'];
                $this->logger->info("Participant load required by schedule.", ['users' => $users]);
                // Test if $users is a ratio
                $percent_sign = strpos($users, '%');
                if ($percent_sign) {
                    $ratio = floatval(str_replace([' ', '%', ','], ['', '', '.'], $users));
                    if ($ratio >= 0 and $ratio <= 100) {
                        $this->logger->debug('Capacity ratio required', ['ratio' => $ratio . '%']);
                        $users = ceil($ratio * $this->config->get('pool_capacity') / 100.0);
                    } else {
                        $this->logger->critical('Ratio is beyond limits : must be between 0 and 100. Aborting.', ['ratio' => $ratio]);
                        return false;
                    }
                }
                $next_active_servers_count = ceil($users / $this->server_capacity);
                $this->logger->info("Next active servers count from schedule: $next_active_servers_count");
                return $next_active_servers_count;

            } else {
                $this->logger->error('Event is missing "users" absolute value or ratio.');
                return -1;
            }
        } else {
            $this->logger->error("Found no event at all in the last 7 days.");
        }
        return -1;
    }

    /**
     * Compute next capacity assessing current and past load
     * @return int Next active servers count
     **/
    public function getNextCapacityFromLoad()
    {
        $this->logger->info("Start next active servers count evaluation by load.");
        if (empty($this->list)) {
            $this->poll();
        }
        $load_data_file = $this->config->get('base_directory') . '/tmp/' . $this->config->get('project') . $this->config->get('load_adaptation_data_file_suffix') . '.json';

        // INclude bare metal servers in the list
        $potential_active_servers = $this->getList(['scalelite_state' => 'enabled'], true, false);
        $potential_active_servers_count = count($potential_active_servers);

        if ($this->config->get('bare_metal_servers_count') > 0) {
            //Only keep bare metal servers alive at minimum
            $active_servers_minimum_count = $this->config->get('bare_metal_servers_count');
        } else {
            //Minimum number of virtual machines servers to keep alive is a percentage of the pool size
            $active_servers_minimum_count = intval(ceil($this->config->get('pool_size') * $this->config->get('load_adaptation_active_servers_minimum_ratio')));
        }

        $this->logger->info("Current potential active servers count : $potential_active_servers_count");

        if ($potential_active_servers_count < $active_servers_minimum_count) {
            $this->logger->warning('Active servers count is less than minimal value.', compact('potential_active_servers_count','active_servers_minimum_count'));
        }
        $participants_capacity = $potential_active_servers_count * $this->config->get('load_adaptation_server_participants_capacity');
        $meetings_capacity = $potential_active_servers_count * $this->config->get('load_adaptation_server_meetings_capacity');

        $this->logger->info("Current capacity", ['participants_capacity' => $participants_capacity, 'meetings_capacity' => $meetings_capacity]);

        // Compute current load
        $participants_count = 0;
        $meetings_count = 0;
        // Include bare meral server in load computing
        foreach($this->getList(['hoster_state' => 'active'], true, false) as $domain => $v) {
            $participants_count += intval($v['users']);
            $meetings_count += intval($v['meetings']);
        }
        if ($participants_count == 0) $participants_count = 1;
        if ($meetings_count == 0) $meetings_count = 1;

        $current_load_data = ['participants_count' => $participants_count, 'meetings_count' => $meetings_count];

        // Retrieve past data from file
        // Discard file if it is too old : older than run frequency times 1.8
        if ((!file_exists($load_data_file)) or ((time() - filemtime($load_data_file)) >= ($this->config->get('controller_run_frequency') * 60 * 1.8))) {
            $this->logger->info("Past load data file does not exist or is too old. Using current data as past data.");
            $past_load_data = $current_load_data;
        } else {
            $past_load_data = json_decode(file_get_contents($load_data_file), true);
        }

        $this->logger->info('Past load: ', $past_load_data);
        $this->logger->info('Current load: ', $current_load_data);

        // write new data to file
        file_put_contents($load_data_file, json_encode($current_load_data));

        $participants_variation_ratio = round($participants_count / intval($past_load_data['participants_count']), 2);
        $meetings_variation_ratio = round($meetings_count / intval($past_load_data['meetings_count']), 2);

        $this->logger->info("Variation ratios", compact('participants_variation_ratio', 'meetings_variation_ratio'));

        $participants_load_ratio = @round(($participants_count / $participants_capacity) * 100, 1) . "%";
        $meetings_load_ratio = @round(($meetings_count / $meetings_capacity) * 100, 1) . "%";

        $this->logger->info("Load ratios", compact('participants_load_ratio', 'meetings_load_ratio'));

        if ($participants_variation_ratio < $this->config->get('load_adaptation_participants_variation_ratio_threshold')) {
            $factor = 1;
        } else {
            $factor = 2;
        }
        $next_participants_capacity = round($this->config->get("load_adaptation_participants_capacity_factor_$factor") * $participants_count);
        $next_participants_servers_count = intval(ceil($next_participants_capacity / $this->config->get('load_adaptation_server_participants_capacity')));

        if ($meetings_variation_ratio <= $this->config->get('load_adaptation_meetings_variation_ratio_threshold')) {
            $factor = 1;
        } else {
            $factor = 2;
        }
        $next_meetings_capacity = round($this->config->get("load_adaptation_meetings_capacity_factor_$factor") * $meetings_count);
        $next_meetings_servers_count = intval(ceil($next_meetings_capacity / $this->config->get('load_adaptation_server_meetings_capacity')));

        $next_active_servers_count = intval(max($next_participants_servers_count, $next_meetings_servers_count, $active_servers_minimum_count));

        $this->logger->info("Next active servers count from load: $next_active_servers_count", compact('next_participants_servers_count', 'next_meetings_servers_count', 'active_servers_minimum_count'));

        return $next_active_servers_count;
    }

    /**
     * Adapt pool capacity
     * @param 
     **/
    public function adaptCapacity()
    {

        $this->logger->info("Start capacity adaptation");

        if (empty($this->list)) {
            $this->poll(true);
        }

        //var_dump($this->list);

        $capacity_adaptation_policy = $this->config->get('capacity_adaptation_policy');
        $this->logger->info("Selected capacity adaptation policy: $capacity_adaptation_policy");
        switch ($capacity_adaptation_policy) {

            case 'schedule':
                // Query ICS calendar for capacity change
                $next_active_servers_count = $this->getNextCapacityFromSchedule();
                break;

            case 'load':
                $next_active_servers_count = $this->getNextCapacityFromLoad();
                break;

            case 'both':
                $next_active_servers_count = max($this->getNextCapacityFromSchedule(), $this->getNextCapacityFromLoad());
                $this->logger->info("Next active servers count (max from both evaluations): $next_active_servers_count.");
                break;

            default:
                $this->logger->error("Unknown capacity adaptation policy: $capacity_adaptation_policy");
                return false;

        }


        $current_active_servers = $this->getList(['scalelite_state' => 'enabled', 'hoster_state' => 'active'], true, false);

        $current_active_online_servers = $this->getList(['scalelite_state' => 'enabled', 'scalelite_status' => 'online', 'hoster_state' => 'active'], true, false);

        $soon_active_servers = $this->getList(['scalelite_state' => 'enabled', 'hoster_state' => 'starting'], true, false);

        $potential_active_servers = $this->getList(['scalelite_state' => 'enabled'], true, false);

        $current_active_unresponsive_servers = $this->getList(['scalelite_state' => 'enabled', 'hoster_state' => 'active', 'custom_state' => 'unresponsive'], true, false);
        $current_active_malfunctioning_servers = $this->getList(['scalelite_state' => 'enabled', 'hoster_state' => 'active', 'custom_state' => 'malfunctioning'], true, false);
        $current_active_to_recycle_servers = $this->getList(['scalelite_state' => 'enabled', 'hoster_state' => 'active', 'custom_state' => 'to recycle'], true, false);

        $current_active_to_replace_servers = array_merge($current_active_unresponsive_servers, $current_active_malfunctioning_servers, $current_active_to_recycle_servers);
        $current_active_to_replace_servers_count = count($current_active_to_replace_servers);

        // Unconditionnaly terminate 'unresponsive' servers
        $to_terminate_servers = [];
        $current_active_to_replace_servers_copy = $current_active_to_replace_servers;
        foreach ($current_active_unresponsive_servers as $domain => $v) {
            $this->logger->info("Unresponsive server $domain detected. Add server to cordon list.", ['domain' => $domain, 'custom_state' => $v['custom_state'], 'server_type' => $v['server_type']]);
            $to_terminate_servers[] = $domain;
            unset($current_active_servers[$domain]);
            unset($potential_active_servers[$domain]);
            unset($current_active_to_replace_servers_copy[$domain]);
        }

        // If there are still servers to replace, cordon them all
        // But keep one server alive in case the number of server to replace matches the number of online servers
        $additional_servers_to_enable_count = 0;
        if (!empty($current_active_online_servers) and count($current_active_to_replace_servers_copy) == count($current_active_online_servers)) {
            if (count($current_active_to_recycle_servers) >= 1 ) {
                // Preferably keep a server to recycle
                $v = end($current_active_to_recycle_servers);
                $domain = key($current_active_to_recycle_servers);
            } else {
                // Else keep a malfunctioning server
                $v = end($current_active_malfunctioning_servers);
                $domain = key($current_active_malfunctioning_servers);
            }

            $this->logger->info("Server is due to be terminated or rebooted but we keep it as the only active online server.", ['domain' => $domain, 'custom_state' => $v['custom_state'], 'server_type' => $v['server_type']]);
            // Start a new machine in advance to replace that one
            $additional_servers_to_enable_count = 1;
            // Remove server from the list of servers to be replaced
            unset($current_active_to_replace_servers_copy[$domain]);
        }
        // Tag all other servers to replace to be cordoned
        foreach ($current_active_to_replace_servers_copy as $domain => $v) {
            $this->logger->info("Server is due to be terminated or rebooted. Add server to cordon list.", ['domain' => $domain, 'custom_state' => $v['custom_state'], 'server_type' => $v['server_type']]);
            $to_terminate_servers[] = $domain;
            unset($current_active_to_replace_servers_copy[$domain]);
            unset($current_active_servers[$domain]);
            unset($potential_active_servers[$domain]);
        }
        if (!empty($to_terminate_servers)) {
            $this->scaleliteActOnServersList(['action' => 'cordon', 'domains' => $to_terminate_servers]);
        }
        // Update the number of potential active servers
        $potential_active_servers_count = count($potential_active_servers);
        $current_active_bare_metal_servers_count = count($this->getFilteredArray($current_active_servers, ['server_type' => 'bare metal']));

        $this->logger->info("Current active (active and enabled in Scalelite) servers count: " . count($current_active_servers));
        $this->logger->info("Current active (active and enabled in Scalelite) bare metal servers count: $current_active_bare_metal_servers_count");
        $this->logger->info("Soon active (starting and enabled in Scalelite) virtual machines servers count: " . count($soon_active_servers));
        $this->logger->info("Potential active (enabled in Scalelite) virtual machine servers count: $potential_active_servers_count");
        $this->logger->info("Servers to be replaced count : $current_active_to_replace_servers_count");

        if ($next_active_servers_count > 0) { //$next_capacity=-1 if no change in case of schedule policy

            /**
             * Enable or disable servers in Scalelite
             */

            $this->logger->info("Next required active servers count: $next_active_servers_count");
            $server_difference_count = $next_active_servers_count - $potential_active_servers_count + $additional_servers_to_enable_count;
            $this->logger->info("Server difference count : $server_difference_count");
            if ($next_active_servers_count > $this->config->get('pool_size')) {
                $this->logger->error("Next active servers count exceeds pool size. Limit count to pool size: " . $this->config->get('pool_size') . " servers");
                $next_active_servers_count = $this->config->get('pool_size');
            }

            // Cordon
            // Need to cordon servers in Scalelite
            if ($server_difference_count < 0) {
                $this->logger->info("Adaptation : Reduce active servers count by $server_difference_count servers");

                $potential_servers_to_cordon = $this->getFilteredArray($potential_active_servers, ['server_type' => 'virtual machine']);

                //Sort servers list by number of rooms (https://stackoverflow.com/questions/2699086/how-to-sort-multi-dimensional-array-by-value)            
                //So that we stop unused servers first => faster
                uasort($potential_servers_to_cordon, function ($a, $b) {
                    return $a['meetings'] <=> $b['meetings'];
                });

                // Disable the required number of servers in Scalelite so that they drain rooms
                $servers_to_cordon_count = 0;

            

                // Then register servers stopped or stopped in place (exceptional)
                foreach ($potential_servers_to_cordon as $domain => $v) {
                    if ($servers_to_cordon_count == abs($server_difference_count)) break;
                    $hoster_state = $v['hoster_state'];
                    if (in_array($hoster_state, ['stopped', 'stopped in place'])) {
                        $this->logger->info("Register $hoster_state server in cordon list.", ['domain' => $domain]);
                        $servers_to_cordon[] = $domain;
                        // Remove server from list so that it is not added twice
                        unset($potential_servers_to_cordon[$domain]);
                        $servers_to_cordon_count++;
                    }
                }

                // Then register servers starting
                foreach ($potential_servers_to_cordon as $domain => $v) {
                    if ($servers_to_cordon_count == abs($server_difference_count)) break;
                    $hoster_state = $v['hoster_state'];
                    if (in_array($hoster_state, ['starting'])) {
                        $this->logger->info("Register $hoster_state server in cordon list.", ['domain' => $domain]);
                        $servers_to_cordon[] = $domain;
                        // Remove server from list so that it is not added twice
                        unset($potential_servers_to_cordon[$domain]);
                        $servers_to_cordon_count++;
                    }
                }

                // Then register servers ordered by less remaining number of sessions
                foreach ($potential_servers_to_cordon as $domain => $v) {
                    if ($servers_to_cordon_count == abs($server_difference_count)) break;
                    $this->logger->info("Register {$v['hoster_state']} and {$v['scalelite_status']} in Scalelite server in cordon list.", ['domain' => $domain]);
                    $servers_to_cordon[] = $domain;
                    unset($potential_servers_to_cordon[$domain]);
                    $servers_to_cordon_count++;
                }

                // Cordon in Scalelite
                if ($servers_to_cordon_count == 0) {
                    $this->logger->error('No new server registered in cordon list although ' . abs($server_difference_count) . ' required.');
                } else {
                    if ($servers_to_cordon_count < abs($server_difference_count)) {
                        $this->logger->error("Only $servers_to_cordon_count servers registered in cordon list although " . abs($server_difference_count) . 'required.');
                    }
                    $this->scaleliteActOnServersList(['action' => 'cordon', 'domains' => $servers_to_cordon]);
                }
            }
            // Enable
            // Need to enable servers in Scalelite
            elseif ($server_difference_count > 0) {
                $this->logger->info("Adaptation : Raise active servers count by $server_difference_count servers");

                // Enable the required number of servers in Scalelite so that they are started
                $servers_to_enable_count = 0;
                $servers_to_enable = [];

                // Select servers that are not enabled
                $potential_servers_to_enable = array_merge($this->getList(['scalelite_state' => 'cordoned']), $this->getList(['scalelite_state' => 'disabled']));

                // First enable active servers without problems (custom_state = null)
                foreach ($potential_servers_to_enable as $domain => $v) {
                    if ($servers_to_enable_count == $server_difference_count) {
                        break;
                    }
                    if ($v['hoster_state'] == 'active' and $v['custom_state'] == null) {
                        $this->logger->info("Register active server in enable list.", ['domain' => $domain]);
                        $servers_to_enable[] = $domain;
                        unset($potential_servers_to_enable[$domain]);
                        $servers_to_enable_count++;
                    }
                }

                // Then enable servers ordered by their starting time
                $ordered_hoster_states = ['starting', 'stopped in place', 'stopped', 'nonexistent', 'stopping'];
                foreach ($ordered_hoster_states as $hoster_state) {
                    foreach ($potential_servers_to_enable as $domain => $v) {
                        if ($servers_to_enable_count == $server_difference_count) {
                            break 2;
                        }
                        if ($v['hoster_state'] == $hoster_state) {
                            $this->logger->info("Register $hoster_state server in enable list.", ['domain' => $domain]);
                            $servers_to_enable[] = $domain;
                            unset($potential_servers_to_enable[$domain]);
                            $servers_to_enable_count++;
                        }
                    }
                }

                // Then re-enable active servers with minor problems : 'to recycle' or 'malfunctioning'
                $ordered_custom_states = ['to recycle', 'malfunctioning'];
                foreach ($ordered_custom_states as $custom_state) {
                    foreach ($potential_servers_to_enable as $domain => $v) {
                        if ($servers_to_enable_count == $server_difference_count) {
                            break 2;
                        }
                        if ($v['hoster_state'] == 'active' and $v['custom_state'] == $custom_state) {
                            $this->logger->info("Register (re-enable) active and $custom_state server in enable list.", ['domain' => $domain]);
                            $servers_to_enable[] = $domain;
                            unset($potential_servers_to_enable[$domain]);
                            $servers_to_enable_count++;
                        }
                    }
                }

                // Enable servers in Scalelite
                if ($servers_to_enable_count == 0) {
                    $this->logger->warning("No new server registered in enable list although $server_difference_count required.");
                } else {
                    if ($servers_to_enable_count < $server_difference_count) {
                        $this->logger->warning("Only $servers_to_enable_count servers registered in enable list although $server_difference_count required.");
                    }
                    $this->scaleliteActOnServersList(['action' => 'enable', 'domains' => $servers_to_enable]);
                }

            } else {
                // $server_difference_count = 0
                // Give priority to already active servers over starting servers
                // Switch Scalelite states
                $active_disabled_servers = array_merge($this->getList(['hoster_state' => 'active', 'scalelite_state' => 'cordoned']), $this->getList(['hoster_state' => 'active', 'scalelite_state' => 'disabled']));
                $starting_enabled_servers = $this->getList(['hoster_state' => 'starting', 'scalelite_state' => 'enabled']);
                uasort($starting_enabled_servers, function ($a, $b) {
                    return $a['hoster_state_duration'] <=> $b['hoster_state_duration'];
                });
                $servers_to_switch_count = min(count($active_disabled_servers), count($starting_enabled_servers));
                $servers_to_cordon = [];
                $servers_to_enable = [];
                if ($servers_to_switch_count > 0) {
                    $this->logger->info("Switch $servers_to_switch_count servers states in Scalelite");
                    for($i = $servers_to_switch_count; $i == 0; --$i) {
                        $servers_to_cordon[] = key($starting_enabled_servers);
                        $servers_to_enable[] = key($active_disabled_servers);
                        next($active_disabled_servers);
                        next($starting_enabled_servers);
                    }

                    $enable_success_list = $this->scaleliteActOnServersList(['action' => 'enable', 'domains' => $servers_to_enable]);
                    $cordon_success_list = $this->scaleliteActOnServersList(['action' => 'cordon', 'domains' => $servers_to_cordon]);

                    if (count($enable_success_list) != count($cordon_success_list)) {
                        $this->logger->warning("Scalelite switch states success counts do not match");
                    }
                }
            }
        } else {
            $this->logger->info('No capacity change required');
        }

        /**
         * Terminate or Clone servers if needed
         */

        //Terminate
        //test if no remaining rooms and no recordings processing
        $servers_to_terminate = array_merge($this->getList(['scalelite_state' => 'cordoned'], true, false), $this->getList(['scalelite_state' => 'disabled'], true, false));

        foreach ($servers_to_terminate as $domain => $v) {

            // Create variables
            $hoster_state = $v['hoster_state'];
            $scalelite_status = $v['scalelite_status']; // online or offline
            $scalelite_state = $v['scalelite_state']; // cordoned or disabled
            $bbb_status = $v['bbb_status'];
            $server_type = $v['server_type'];
            $hoster_state_duration_minutes = round($v['hoster_state_duration']/60);

            // Skip non existent servers
            if ($hoster_state == 'nonexistent') {
                continue;
            }

            // Terminate "stopped and stopped in place" servers
            if (in_array($hoster_state, ['stopped', 'stopped in place'])) {
                $this->logger->warning("Add $hoster_state server $domain to terminate list.", compact('domain'));
                $servers_ready_for_terminate[$domain] = $v;
                continue;
            }
            // Can not poweroff a server currently stopping
            if ($hoster_state == 'stopping') {
                $stop_duration_minutes = $hoster_state_duration_minutes;
                $log_context = compact('domain', 'scalelite_state', 'stop_duration_minutes');
                $this->logger->info("Server $domain stopping. Can not terminate.", $log_context);
                continue;
            }
            // Can not terminate a server starting
            if ($hoster_state == 'starting') {
                $start_duration_minutes = $hoster_state_duration_minutes;
                $log_context = compact('domain', 'scalelite_state', 'start_duration_minutes');
                $this->logger->info("Server $domain starting. Can not terminate yet.", $log_context);
                continue;
            }
            // Terminate unresponsive servers
            if ($hoster_state == 'active' and $v['custom_state'] == 'unresponsive') {
                $log_context = compact('bbb_status', 'server_type');
                $this->logger->info("Add unresponsive server $domain to terminate list.", $log_context);
                $servers_ready_for_terminate[$domain] = $v;
                continue;
            }

            // Poweroff 'active' servers
            // check for remaining sessions or processing recordings
            if ($v['hoster_state'] == 'active') {
                // Check if server is active since at least 3 controller runs
                // to avoid stopping a server that has just been started and could be used in a very near future
                $active_duration_minutes = $hoster_state_duration_minutes;
                if ($active_duration_minutes < ($this->config->get('controller_run_frequency') * 3)) {
                    $log_context = compact('domain', 'scalelite_state', 'active_duration_minutes');
                    $this->logger->info("Server $domain ready for terminate but active since too little time. Not terminating yet.", $log_context);
                    continue;
                }

                if ($scalelite_status == 'offline') {
                    $log_context = compact('domain', 'scalelite_state', 'active_duration_minutes');
                    $this->logger->info("Server $domain active and ready for terminate but offline in Scalelite. Not terminating yet.", $log_context);
                    continue;
                }

                // Deal with online servers
                $this->logger->info("Trying to terminate online server. Check meetings and recordings first.", compact('domain', 'scalelite_state', 'active_duration_minutes'));
                try {
                    $bbb_secret = $v['secret'];
                    $bbb = new BigBlueButton("https://$domain/bigbluebutton/", $bbb_secret);

                    // Test if server has remaining meetings
                    $this->logger->debug('Test BBB server for remaining meetings.', ['domain' => $domain]);
                    $result = $bbb->getMeetings();
                    if ($result->getReturnCode() == 'SUCCESS') {
                        $meetings = $result->getMeetings();
                        if (!(empty($meetings))) {
                            $this->logger->info("Still remaining meetings. Can not poweroff", ['domain' => $domain]);

                            // Check if server has meetings that should be forcibly ended due to max duration reached
                            $meetings_max_duration = $this->config->get('meetings_max_duration');
                            $this->logger->debug("Checking for meetings older than $meetings_max_duration to forcibly end");
                            foreach($result->getRawXml()->meetings->meeting as $meeting) {
                                // Compute creation date in seconds from create time in epoch with milliseconds
                                $creation_time = round($meeting->createTime/1000);
                                $now = time();
                                $meeting_duration_minutes = round(($now - $creation_time)/60); // Meeting duration in minutes
                                if ($meeting_duration_minutes >= $meetings_max_duration) {
                                    $meeting_id = (string) $meeting->meetingID;
                                    $meeting_name = (string) $meeting->meetingName;
                                    $meeting_password = (string) $meeting->moderatorPW;
                                    $this->logger->warning("Meeting duration exceeds limit. Force end meeting with id $meeting_id", compact('meeting_id', 'meeting_name','meeting_duration_minutes'));
                                    $endMeetingParams = new EndMeetingParameters($meeting_id, $meeting_password);
                                    $response = $bbb->endMeeting($endMeetingParams);
                                    if ($response->getReturnCode() == 'SUCCESS') {
                                        $this->logger->info("End meeting successfull.");
                                    } else {
                                        $this->logger->error("End meeting with id $meeting_id failed.", compact('domain', 'meeting_id', 'meeting_name'));
                                    }
                                }
                            }
                            continue;
                        } else {
                            $this->logger->info("Server has no remaining meetings.", ['domain' => $domain]);
                        }
                    } else {
                        $this->logger->error("Can not retrieve meetings info for server $domain.");
                        continue;
                    }

                    // Test if server is still processing recordings
                    $parameters = new GetRecordingsParameters();
                    $parameters->setState('processing,processed');

                    $this->logger->debug('Test BBB server for recordings in state "processing" or "processed"', ['domain' => $domain]);
                    $result = $bbb->getRecordings($parameters);
                    if ($result->getReturnCode() == 'SUCCESS') {
                        $recordings = $result->getRecords();
                        //var_dump($recordings);
                        if (!(empty($recordings))) {
                            $this->logger->info("Server has recordings in 'processing' or 'processed' state. Can not terminate", ['domain' => $domain]);

                            // Check if server has recordings that reached max processing duration
                            $recordings_max_processing_duration = $this->config->get('recordings_max_processing_duration');
                            $this->logger->debug("Checking for recordings processing longer than $recordings_max_processing_duration");
                            foreach($result->getRawXml()->recordings->recording as $recording) {
                                // Compute meeting end date in seconds from create time in epoch with milliseconds
                                $creation_time = round($recording->endTime/1000);
                                $now = time();
                                $processing_duration_minutes = round(($now - $creation_time)/60); // Meeting duration in minutes
                                if ($processing_duration_minutes >= $recordings_max_processing_duration) {
                                    $recording_id = (string) $recording->recordID;
                                    $recording_state = (string) $recording->state;
                                    $meeting_id = (string) $recording->meetingID;
                                    $meeting_name = (string) $recording->name;
                                    $this->logger->warning("Recording processing duration exceeds limit for recording with id $recording_id.", compact('domain', 'recording_id', 'recording_state', 'meeting_id', 'meeting_name', 'processing_duration_minutes'));
                                }
                            }
                            continue;
                        } else {
                            $this->logger->info("Server has no recording in 'processing' or 'processed' state. Follow on checks.", ['domain' => $domain]);
                        }
                    } else {
                        $this->logger->error("Can not retrieve processing recordings information from API for server $domain.");
                        continue;
                    }

                    // Test if all published recordings were transfered to final storage
                    // For each recording compare source (BBB) and target (Scalelite) folders sizes
                    $parameters = new GetRecordingsParameters();
                    $parameters->setState('published');
                    $this->logger->debug('Test BBB server for recordings in "published" state', ['domain' => $domain]);
                    $result = $bbb->getRecordings($parameters);
                    if ($result->getReturnCode() == 'SUCCESS') {
                        $recordings = $result->getRecords();
                        if (!(empty($recordings))) {
                            $this->logger->info("Server has " . count($recordings) . " recording(s) in 'published' state. Check for successful transfer to final storage.", ['domain' => $domain]);
                            $this->logger->debug('Compare sizes of recordings folders between source and target.', ['domain' => $domain]);
                            $recordings_path_source = $this->config->get('recordings_path_source');
                            $recordings_path_target = $this->config->get('recordings_path_target');
                            $server_number = $this->getServerNumberFromDomain($domain);
                            $hostname_fqdn = $this->getHostnameFQDN($server_number);
                            $ssh_host = new SSH(['host' => $hostname_fqdn], $this->config, $this->logger);
                            $ssh_scalelite = new SSH(['host' => $this->config->get('scalelite_host')], $this->config, $this->logger);
                            foreach($result->getRawXml()->recordings->recording as $recording) {
                                $recording_id = (string) $recording->recordID;
                                $command_host = "'{ source_size=\$(sudo find $recordings_path_source/$recording_id -type f -print0 | du --files0-from=- -bc | tail -1 | cut -f1); echo \$source_size; }'";
                                if (!$ssh_host->exec($command_host, ['max_tries' => 3])) {
                                    $log_context = compact('domain', 'recording_id');
                                    $this->logger->error("Get source (BBB) recording with id $recording_id folder size failed with SSH error code " . $ssh_host->getReturnValue() . '. Can not terminate server.', $log_context);
                                    continue 2;
                                } elseif (($source_size = intval($ssh_host->getOutput())) != 0) {
                                    $command_scalelite = "'{ target_size=\$(sudo find $recordings_path_target/$recording_id -type f -print0 | du --files0-from=- -bc | tail -1 | cut -f1); echo \$target_size; }'";
                                    if (!$ssh_scalelite->exec($command_host, ['max_tries' => 3])) {
                                        $log_context = compact('domain', 'recording_id');
                                        $this->logger->error("Get target (Scalelite) recording with id $recording_id folder size failed with SSH error code " . $ssh_host->getReturnValue() . '. Can not terminate server.', $log_context);
                                        continue 2;
                                    } elseif (($target_size = intval($ssh_scalelite->getOutput())) != $source_size) {
                                        $log_context = compact('domain', 'recording_id', 'source_size', 'target_size');
                                        $this->logger->info("Source (BBB) and target (Scalelite) recording with id $recording_id folder sizes do not match. Can not terminate server.", $log_context);
                                        continue 2;
                                    } else {
                                        //success case
                                        $log_context = compact('domain', 'recording_id');
                                        $this->logger->info("Source (BBB) and target (Scalelite) recording folder sizes match. Follow on checks.", $log_context);
                                    }
                                } else {
                                    $this->logger->warning("Source (BBB) recording with id $recording_id folder size is nul. Can not terminate server.", compact('domain', 'recording_id'));
                                    continue 2;
                                }
                            }
                            $this->logger->info("All recordings transfer checks successful. Can terminate.", ['domain' => $domain]);
                        } else {
                            $this->logger->info("Server has no recording in 'published' state. Can terminate.", ['domain' => $domain]);
                        }
                    } else {
                        $this->logger->error("Can not retrieve 'published' recordings information from API for server $domain.", ['domain' => $domain]);
                        continue;
                    }

                } catch (\RuntimeException $e) {
                    $this->logger->error("Can not retrieve info from BBB server $domain.", ["domain" => $domain, "BBB_api_error" => $e->getMessage()]);
                    continue;
                } catch (\Exception $e) {
                    $this->logger->error("Can not retrieve info from BBB server $domain", ['domain' => $domain, "BBB_api_error" => $e->getMessage()]);
                    continue;
                }

                $this->logger->info('Add active and online in Scalelite server to terminate list.', ['domain' => $domain]);
                $servers_ready_for_terminate[$domain] = $v;
            }
        }

        if (!empty($servers_ready_for_terminate)) {

            $bare_metal_servers_to_reboot = $this->getFilteredArray($servers_ready_for_terminate, ['server_type' => 'bare metal']);
            $virtual_machines_to_terminate = $this->getFilteredArray($servers_ready_for_terminate, ['server_type' => 'virtual machine']);

            // Reboot and re-enable bare metal servers
            $servers_to_enable = [];
            foreach($bare_metal_servers_to_reboot as $domain => $v) {
                $this->logger->info("Reboot bare metal server $domain", ['custom_state' => $v['custom_state']]);
                $server_number = $this->getServerNumberFromDomain($domain);
                $hostname_fqdn = $this->getHostnameFQDN($server_number);
                $ssh = new SSH(['host' => $hostname_fqdn], $this->config, $this->logger);
                if (!$ssh->exec("sudo shutdown --reboot +1", ['max_tries' => 3])) {
                    $this->logger->error("Could not reboot bare metal server $domain", ['ssh_return_value' => $ssh->getReturnValue(), 'custom_state' => $v['custom_state']]);
                    continue;
                }
                $servers_to_enable[] = $domain;

            }
            // Re-enable bare-metal servers in Scalelite
            if (!empty($servers_to_enable)) {
                $enable_success_list = $this->scaleliteActOnServersList(['action' => 'enable', 'domains' => $servers_to_enable]);
            }

            // Poweroff virtual machines at hoster
            if (!empty($virtual_machines_to_terminate)) {
                $terminated_servers = $this->hosterActOnServersList(['action' => 'terminate', 'domains' => array_keys($virtual_machines_to_terminate)]);

                $not_terminated_servers = array_diff(array_keys($virtual_machines_to_terminate), $terminated_servers);

                if (!empty($not_terminated_servers)) {
                    $this->logger->warning('Some virtual machines could not be terminated', ['servers_in_error' => json_encode($not_terminated_servers)]);
                }
            }

        }

        // Clone and start
        $servers_to_clone_or_poweron = $this->getList(['scalelite_state' => 'enabled']);

        foreach ($servers_to_clone_or_poweron as $domain => $v) {
            $hoster_state = $v['hoster_state'];
            $scalelite_status = $v['scalelite_status'];

            if ($hoster_state == 'nonexistent') {
                $this->logger->info("Add $hoster_state and enabled in Scalelite server to clone list.", ['domain' => $domain]);
                $servers_ready_for_clone[] = $domain;
            }
            if (in_array($hoster_state, ['stopped', 'stopped in place'])) {
                $this->logger->info("Add $hoster_state and enabled in Scalelite server to poweron list.", ['domain' => $domain]);
                $servers_ready_for_poweron[] = $domain;
            } elseif (in_array($hoster_state, ['starting'])) {
                $this->logger->info("Server is already starting and enabled in Scalelite.", ['domain' => $domain, 'start_duration_minutes' => round($v['hoster_state_duration']/60)]);
            } elseif (in_array($hoster_state, ['stopping'])) {
                $this->logger->info("Server is stopping and enabled in Scalelite. Not powering on now.", ['domain' => $domain,  'stop_duration_minutes' => round($v['hoster_state_duration']/60)]);
            } elseif ($hoster_state == 'active' and $scalelite_status == 'offline') {
                $this->logger->info("Server is already active and enabled in Scalelite but not yet online in Scalelite.", ['domain' => $domain]);
            }
        }

        if (!empty($servers_ready_for_clone)) {
            // Poweroff servers at hoster
            $cloned_servers = [];
            foreach($servers_ready_for_clone as $domain) {
                $server_number = $this->getServerNumberFromDomain($domain);
                if ($this->hosterCloneAndStartServer($server_number) !== false) $cloned_servers[] = $domain;
            }
            $not_cloned_servers = array_diff($servers_ready_for_clone, $cloned_servers);

            if (!empty($not_cloned_servers)) {
                $this->logger->warning('Some servers could not be cloned.', ['servers_in_error' => json_encode($not_cloned_servers)]);
            }
        }

        if (!empty($servers_ready_for_poweron)) {
            // Poweroff servers at hoster
            $poweredon_servers = $this->hosterActOnServersList(['action' => 'poweron', 'domains' => $servers_ready_for_poweron]);
            $not_poweredon_servers = array_diff($servers_ready_for_poweron, $poweredon_servers);

            if (!empty($not_poweredon_servers)) {
                $this->logger->warning('Some servers could not be powered on', ['servers_in_error' => json_encode($not_poweredon_servers, JSON_PRETTY_PRINT)]);
            }
        }
        $this->logger->info("End capacity adaptation.");
		$this->logger->info("             ===             ");
    }

    /**
     * Check BBB servers health state and perform repair tasks
     * @param bool $repair Wether servers should be repaired or not. Default true.
     **/
    public function restartServers(bool $fix = true)
    {
        $active_servers = $this->getFilteredArray($this->list, ['hoster_state' => 'active']);
        $active_servers = $this->SSHPollBBBServers($active_servers);

        foreach ($active_servers as $domain => $v) {
            if (($v['bbb_status'] ?? NULL) == 'KO') {
                $server_number = $this->getServerNumberFromDomain($domain);
                $hostname_fqdn = $this->getHostnameFQDN($server_number);
                $ssh = new SSH(['host' => $hostname_fqdn], $this->config, $this->logger);
                $this->logger->info("Restart BBB service.", ["domain" => $domain]);
                if (!$ssh->exec("bbb-conf --restart", ['max_tries' => 3, 'timeout' => 90])) {
                    $this->logger->error("Can not restart BBB service for server $domain.", ["domain" => $domain]);
                }
            }
        }
    }

    /**
     * Get statistics
     **/
    public function getStatistics()
    {
        $this->logger->info('Start statistics gathering.');

        // Gather fresh data
        if (empty($this->list)) {
            $this->poll();
        }

        echo "== Hoster ==\n";
        $states = ['active', 'stopped', 'stopped in place', 'starting', 'stopping', 'locked', 'unreachable'];
        foreach ($states as $state) {
            $servers_list = array_keys($this->getList(['hoster_state' => $state], false, false));
            echo "* $state: " . count($servers_list) . " [" . implode(',', $this->getServerNumbersFromDomainsList($servers_list)) .  "]\n";
        }

        echo "== Scalelite ==\n";
        $states = ['online', 'offline'];
        foreach ($states as $state) {
            $servers_list = array_keys($this->getList(['scalelite_status' => $state], false, false));
            echo "* $state: " . count($servers_list) . " [" . implode(',', $this->getServerNumbersFromDomainsList($servers_list)) . "]\n";
        }
        $states = ['enabled', 'disabled', 'cordoned'];
        foreach ($states as $state) {
            $servers_list = array_keys($this->getList(['scalelite_state' => $state], false, false));
            echo "* $state: " . count($servers_list) . " [" . implode(',', $this->getServerNumbersFromDomainsList($servers_list)) . "]\n";
        }

        echo "== Divims Maintenance ==\n";
        $states = ['in maintenance'];
        foreach ($states as $state) {
            $servers_list = array_keys($this->getList(['divims_state' => $state], false, false));
            echo "* $state: " . count($servers_list) . " [" . implode(',', $this->getServerNumbersFromDomainsList($servers_list)) . "]\n";
        }

        $this->logger->info('End gather statistics.');
    }

}
