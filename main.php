<?php

/**
 * How to execute this script in the Docker container :
 * Change directory to the root directory of the application
 * docker container run --rm --user $(id -u):$(id -g) -v $(pwd):/app/ php:parallel php /app/run/main.php  --project=<project>
 */

// Get base directory ('/app')
// $base_directory = '/' . explode('/', $_SERVER['PHP_SELF'])[1];

// include_once "$base_directory/run/init.php";
$base_directory = '/' . explode('/', $_SERVER['PHP_SELF'])[1];

// Ensure that $base_directory ends with a slash '/'
if ($base_directory == '/') {
    $base_directory ='.';
}

include_once "$base_directory/run/init.php";

use DiViMS\ServersPool;
use DiViMS\Config;
use DiViMS\SCW;
use DiViMS\SSH;

use \DiViMS\DOC;

// https://github.com/Seldaek/monolog
// composer require monolog/monolog
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\DeduplicationHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\FilterHandler;

// https://github.com/confirm/PhpZabbixApi
// https://www.zabbix.com/documentation/current/manual/api
// composer require 'confirm-it-solutions/php-zabbix-api:^2.4'
use ZabbixApi\ZabbixApi;
use ZabbixApi\Exception;

// https://github.com/bigbluebutton/bigbluebutton-api-php
// https://github.com/bigbluebutton/bigbluebutton-api-php/wiki
// composer require bigbluebutton/bigbluebutton-api-php:~2.0.0
use BigBlueButton\BigBlueButton;
use BigBlueButton\Parameters\GetRecordingsParameters;


/**
 * Command line options
 */
$short_options = "";
$long_options = ['project:', 'log-level:']; // -- project (required)
$options = getopt($short_options, $long_options);


/**
 * Define project
 */
//$project = basename(dirname($_SERVER['PHP_SELF']));
$project = $options['project'] ?? 'none';


// Create a log channel
$logger = new Logger($project);
$log_level = isset($options['log-level']) ? constant("Monolog\Logger::" . strtoupper($options['log-level'])) : Monolog\Logger::DEBUG;

$logger->setTimezone(new \DateTimeZone('Europe/Paris'));
// Store info logs locally
$logger->pushHandler(new StreamHandler("$base_directory/log/$project.log", Logger::INFO));
// Show logs on stdout
$logger->pushHandler(new StreamHandler('php://stdout', $log_level));
// Mail only warning logs every day
$logger->pushHandler(
    new FilterHandler(
        new DeduplicationHandler(
            new NativeMailerHandler("<mail_recipient_address>", "Warning : DiViM-S $project", "<mail_from_address>", Logger::WARNING),
            "/app/tmp/${project}_email_warning.log", Logger::WARNING, 86400
        ),
        Logger::WARNING, Logger::WARNING
    )
);
// Mail error and more critical logs every hour
$logger->pushHandler(
    new DeduplicationHandler(
        new NativeMailerHandler("<mail_recipient_address>", "Error : DiViM-S $project", "<mail_from_address>", Logger::ERROR),
        "/app/tmp/${project}_email_error.log", Logger::ERROR, 3600
    )
);

// Create config
$config = new Config($project, $logger);

// Start daemon
$pool = new ServersPool($config, $logger);
echo "oke!!!\n";


// test digital ocean
$digitalocean=new DOC($config, $logger);
$domainName = 'scalelitebbb.systems';
// $recordType = 'AAAA';
// $recordName = 'bar-name';
// $recordData = '2001:db8::ff00:42:8329';
// $recordId = $digitalocean->createDomainRecord($domainName, $recordType, $recordName, $recordData);
// echo "recordId: $recordId\n";

//test shh
// $ssh=$digitalocean->getAllSshKey();
// $ssh_key_ids = [];
// foreach ($ssh as $key) {
//     echo "ssh id: $key->id\n";
//     echo "ssh name: $key->name\n";
//     echo "ssh fingerprint: $key->fingerprint\n";
//     $ssh_key_ids[] = $key->id;
// }

// //test droplet
// $droplets=$digitalocean->getAllDroplets();
// if ($droplets) {
//     foreach ($droplets as $droplet) {
//        echo "Droplet id: $droplet->id\n";
//        echo "Droplet name: $droplet->name\n";
//         echo "Droplet status: $droplet->status\n";
//         $networks = $droplet->networks;
//         foreach ($networks as $network) {
//             // Kiểm tra xem mạng có loại là public không
//             if ($network->type === 'public') {
//                 // Hiển thị địa chỉ IP của mạng public
//                 echo "Public IP Address: $network->ipAddress\n";
//                 $domainName = 'scalelitebbb.systems';
//                 $recordType = 'A';
//                 $recordName = 'test'.$droplet->name;
//                 $recordData = $network->ipAddress;
//                 $recordId = $digitalocean->createDomainRecord($domainName, $recordType, $recordName, $recordData);
//             }
//         }
//     }
// }


// $newDroplet = $digitalocean->createDroplet(   "bbb2",                                 // Tên droplet
// "sgp1",                                 // Khu vực
// "s-1vcpu-1gb",                          // Kích thước
// "ubuntu-22-04-x64",                     // Image
// false,                                  // Có sao lưu
// false,                                  // Có IPv6
// "24c014f8-7d48-4ebf-bc79-91ac5475d6e5", // UUID của VPC
// [41923124],                             // Số hiệu SSH
// "",                                     // Dữ liệu người dùng
// true,                                   // Giám sát
// [],                                     // Danh sách volume
// [],                                     // Tags
// false                                   // Tắt agent
// );
// // Kiểm tra xem droplet đã được tạo thành công hay không
// echo "Droplet created: $newDroplet->id\n";

// 1. Instantiate Config and Logger objects (assuming they are already defined)
// $config = new Config(/* provide configuration parameters */);
// $logger = new Logger(/* provide logger parameters */);

// // 2. Provide mock values for configuration
// $host = 'example.com'; // Provide a mock host value
// $config->set('scalelite_host', $host);

// 3. Create SSH object

$ssh = new SSH(['host' => $config->get('scalelite_host')], $config, $logger);

$ssh->exec("docker exec -i scalelite-api bundle exec rake servers", ['max_tries' => 3]);
// $pool->poll(true);
echo json_encode($pool->list, JSON_PRETTY_PRINT);

// $pool->generateNFSCommands();
// $rsa = $params['rsa'] ?? $config->get('project_directory') . '/' . $config->get('ssh_rsa');
// echo $rsa;
// $file_content = file_get_contents($rsa);

// // Check if reading was successful
// if ($file_content !== false) {
//     echo $file_content;
// } else {
//     echo "Failed to read file contents.";
// }
/**
 * Clone
 */
//$range = range(3,75);
//$range = [50];
//$range=[20,33,56,133];
//$pool->addServersListToHoster($range);
//$pool->checkHosterValidity();
//$pool->addServersListToPool($range);
//$pool->checkPoolValidity();
//$pool->cloneServerSCW(3);
//$pool->hosterCloneAndStartServer(1);

/**
 * Adapt Pool capacity
 */
//$pool->getNextCapacityFromSchedule();
//$pool->getNextCapacityFromLoad();
//$pool->config->set('capacity_adaptation_policy', 'both');
//$pool->adaptCapacity();
//$pool->rebootUnresponsiveServers(true);

/**
 * Others
 */
//$pool->startAllServers();
//$pool->config->set('pool_size', 100);
//$pool->getStatistics();
//$pool->testConcurrency(25, 10);
//$pool->generateNFSCommands();
//$pool->generateFirewallRules();

/**
 * Test BigBlueButton Api
 */
/*
//*/
// $bbb_secret = $config->get('clone_bbb_secret');
// $domain = $config->get('clone_old_domain');
// putenv("BBB_SECRET=$bbb_secret");
// putenv("BBB_SERVER_BASE_URL=https://$domain/bigbluebutton/");
// $bbb = new BigBlueButton();
// $parameters = new GetRecordingsParameters();
// $parameters->setState('processing');
// $result = $bbb->getRecordings($parameters);
// if ($result->getReturnCode() == 'SUCCESS') {
//     echo "result success\n";
//     $recordings = $result->getRecords();
//     var_dump($recordings);
// }

// foreach($recordings as $recording) {
//     // if ($recording['state']=='published') echo "fuck";
//     echo $recording->getState();

// }

// $result = $bbb->getMeetings(new GetRecordingsParameters());
// $meetings = $result->getMeetings();
// var_dump($meetings);



/**
 * Test Zabbix Api
 */
/*
try {
    // connect to Zabbix API
    $api = new ZabbixApi($config->get('zabbix_api_url'), $config->get('zabbix_username'), $config->get('zabbix_password'));

    // get one host
    $hosts = $api->hostGet(['search' => ['host' => "server.example.com"], 'selectInterfaces' => ['interfaceid', 'dns']]);
    foreach ($hosts as $host) {
        //echo $host->host . ", ID: ". $host->hostid . "\n";
        //printf("id:%d host:%s\n", $host->hostid, $host->host);
        var_dump($host);
    }

    $hosts = $api->hostUpdate(['hostid' => '10373', 'status' => '1']);

    $hosts = $api->hostGet(['search' => ['host' => "server.example.com"]]);
    foreach ($hosts as $host) {
        //echo $host->host . ", ID: ". $host->hostid . "\n";
        //printf("id:%d host:%s\n", $host->hostid, $host->host);
        var_dump($host);
    }

} catch (Exception $e) {
    // Exception in ZabbixApi catched
    echo $e->getMessage();
}
//*/


/**
 * Test SCW Api
 */
/*
if($config->get('hoster_api') == 'SCW') {
    $scw = new SCW($config->get('scw_zone'), $config->get('scw_auth_token'));
    for ( $i=50; $i<=99; $i++ ) {
        $server_number = $i;
        $domain = $pool->getServerDomain($server_number);
        $hostname = $pool->getHostname($server_number);
        $logger->info("Searching hostname : $hostname");
        $servers = $scw->getServers(['name' => $hostname, 'project' => $config->get('scw_project_id')]);
        //var_dump($servers);
        if (! isset($servers['servers'])) {
            $logger->warning("No matching servers");
            continue;
        }
        $count = count($servers['servers']);
        $logger->info("Matching servers count : $count");
        foreach($servers['servers'] as $server) {
            if ($server['name'] == $hostname) {
                $server_id = $server['id'];
                $logger->debug("Server id : $server_id");
                $logger->info("Powering on server", ['domain' => $domain]);
                $result=$scw->actOnServer($server_id, ['action' => 'poweron']);
                var_dump($result);
            }
        }
        usleep(200000);
    }
    exit(0); 
}
//*/