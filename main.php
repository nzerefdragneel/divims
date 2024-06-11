<?php

/**
 * How to execute this script in the Docker container :
 * Change directory to the root directory of the application
 * docker run --rm -it --user $(id -u):$(id -g) -v $(pwd):/app/ php:parallel /bin/bash

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

use DiViMS\ServersPoolDOC;
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
            new NativeMailerHandler("nguyentranngocsuong94@gmail.com", "Warning : DiViM-S $project", "twitterdevtw@gmail.com", Logger::WARNING),
            "/app/tmp/${project}_email_warning.log", Logger::WARNING, 86400
        ),
        Logger::WARNING, Logger::WARNING
    )
);
// Mail error and more critical logs every hour
$logger->pushHandler(
    new DeduplicationHandler(
        new NativeMailerHandler("nguyentranngocsuong94@gmail.com", "Error : DiViM-S $project", "twitterdevtw@gmail.com", Logger::ERROR),
        "/app/tmp/${project}_email_error.log", Logger::ERROR, 3600
    )
);


$config = new Config($project, $logger);

// Start daemon
$pool = new ServersPoolDOC($config, $logger);
echo "oke!!!\n";


// test digital ocean
$digitalocean=new DOC($config, $logger);
$snapshotname=$config->get('clone_image_name');
// $pool->setupCertificate($config, $logger);
// $snapshotid=$pool->getSnapshotId($snapshotname);
// echo "snapshotid: $snapshotid\n";
// $pool->adaptCapacity();
while (true){
    $pool->poll(true);
    $pool->adaptCapacity();
    sleep(60);
}
// // $pool->poll(true);
// // $pool->adaptCapacity();
// $domainName = $config->get('domain_name');



// $ip="165.22.51.241";


// $pool->hosterCloneAndStartServer(1);
// $digitalocean->removeDomainRecordByIp($domainName,$ip);

//adaptCapacity



// $server=$pool->createAndenableServerOnScalelite(10);

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
//        $get=$digitalocean->getDropletbyId($droplet->id);
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

// $ssh = new SSH(['host' => $config->get('scalelite_host')], $config, $logger);

// $ssh->exec("docker exec -i scalelite-api bundle exec rake servers", ['max_tries' => 3]);
// $pool->poll(true);
// echo "list server___________________________\n";
// echo json_encode($pool->list, JSON_PRETTY_PRINT).'\n';
// echo "end list server___________________________\n";


//clone new server 
// $newinstance=$pool->hosterCloneAndStartServer(6);
// echo "newwwww_____________________\n";
// echo json_encode($newinstance, JSON_PRETTY_PRINT).'\n';
// echo "end newwwww_____________________\n";
// $domaintoreconfigure=$key = key($newinstance);  
//add subdomain

// $subdomain=$digitalocean->createDomainRecord($domainName, 'A', "bbb6", "152.42.212.132");
// echo json_encode($subdomain, JSON_PRETTY_PRINT).'\n';
//reconfigure bbb


// Check if the file exists before attempting to read it

// $ssh = new SSH(['host' => "157.245.193.98"], $config, $logger);
// $new_domain = "bbb6.scalelitebbb.systems";
// $secret = "Jzmzx5PsZvJOmza1zLClKJElF9E14KHsVmZNMlASA";
// $enable_in_scalelite = "true";
// $new_ip="152.42.212.132";
// $scalelite_ip="157.245.193.98";
// $bbb_nfs="/root/enableNFSonBBB.sh";

// // Path to the script to be updated on the remote server
// $target_script = '/root/addToScalelite.sh';
// $ssh->exec("sed -i -e \"s/^NEW_DOMAIN=.*/NEW_DOMAIN=\"$new_domain\"/\" /root/addToScalelite.sh", ['max_tries' => 3, 'sleep_time' => 5, 'timeout' => 10]);

// $ssh->exec("sed -i -e \"s/^SECRET=.*/SECRET=\"$secret\"/\" /root/addToScalelite.sh", ['max_tries' => 3, 'sleep_time' => 5, 'timeout' => 10]);

// $ssh->exec("sed -i -e \"s/^ENABLE_IN_SCALELITE=.*/ENABLE_IN_SCALELITE=\"$enable_in_scalelite\"/\" /root/addToScalelite.sh", ['max_tries' => 3, 'sleep_time' => 5, 'timeout' => 10]);
// $ssh->exec("sed -i -e \"s/^NEW_IP=.*/NEW_IP=\"$new_ip\"/\" /root/addToScalelite.sh", ['max_tries' => 3, 'sleep_time' => 5, 'timeout' => 10]);
// $ssh->exec("chmod +x $target_script");
// $ssh->exec($target_script, ['max_tries' => 3, 'sleep_time' => 5, 'timeout' => 10]);


// $sshbbb=new SSH(['host' => "bbb6.scalelitebbb.systems"], $config, $logger);
// $sshbbb->exec("sed -i -e \"s/^SCALELITE_SERVER_IP=.*/SCALELITE_SERVER_IP=\"$scalelite_ip\"/\" /root/enableNFSonBBB.sh", ['max_tries' => 3, 'sleep_time' => 5, 'timeout' => 10]);
// $sshbbb->exec("chmod +x $bbb_nfs");
// $sshbbb->exec($bbb_nfs, ['max_tries' => 3, 'sleep_time' => 5, 'timeout' => 10]);
// $sshbbb->exec("bbb-conf --restart", ['max_tries' => 3, 'sleep_time' => 5, 'timeout' => 10]);
// $sshbbb->exec("bbb-conf --check", ['max_tries' => 3, 'sleep_time' => 5, 'timeout' => 10]);

// // if ($ssh->exec("./addToScalelite.sh", ['max_tries' => 3, 'sleep_time' => 5, 'timeout' => 60*60])) {
// //     $out = $ssh->getOutput();
// // } else {
// //     if ($error_log) {
// //         $logger->error("Can not poll BigBlueButton server bbb6.scalelitebbb.systems  for stats.");
// //     }
// // }
// $ssh->exec("chmod +x $target_script");
// $ssh->exec("sed -i -e \"s/^SECRET=.*/SECRET=\"Jzmzx5PsZvJOmza1zLClKJElF9E14KHsVmZNMlASA\"/\" /root/addToScalelite.sh", ['max_tries' => 3, 'sleep_time' => 5, 'timeout' => 10]);

// echo "end list server___________________________\n";
// $pool->poll(true);

// $rsa = $params['rsa'] ?? $config->get('project_directory') . '/' . $config->get('ssh_rsa');
// echo $rsa;


// // Check if reading was successful
// if ($file_content !== false) {
//     echo $file_content;
// } else {
//     echo "Failed to read file contents.";
// }

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

