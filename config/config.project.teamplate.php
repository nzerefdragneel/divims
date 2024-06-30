<?php

$config = array(
  // OVH credentials, validity: unlimited, permissions : GET,POST,PUT,DELETE /domain/zone/{dns-zone}/*


  'email'=> '',

  'bare_metal_servers_count' => 0,
  'server_max_recycling_uptime' => 86400*30, // 1 day
  'encrypt_ip'=>'',

  // digitalocean
  'digitalocean_secret' => '',
  'ssh_key_id'=>'',
  // Log level used by the application
  'log_level' => \Monolog\Logger::INFO,

  //Number of servers in the pool
  'pool_size' => 3,
  //Number of maximum participants on the whole pool
  'pool_capacity' => 10000,

  //Bigbluebutton version minor number e.g 2.3
  'bbb_version' => '3.0',

  /*
   * Cloning configuration
   */
  //Max number of parallel processes to clone servers
  //'clone_max_workers' => 5,

  // Hoster
  'hoster_api' => "DOC", //e.g. SCW
  
  //Prefix of the image to be cloned at Scaleway
  //Used to retrieve the id of the image to clone from
  'clone_image_name' => "",
  'clone_image_id'=>156899666,
  'clone_region_id'=>6,

  //Old data
  // Domain FQDN of the machine that will be cloned : "old" own
  // Example : bbb-w1.example.com
  'clone_old_domain' => '',
  'clone_old_external_ipv4' => '', //e.g. 132.11.12.13
  'clone_old_external_ipv6' => '',
  'clone_old_internal_ipv4' => '',

  // API Secret of the BigBlueButton machine to be cloned
  'clone_bbb_secret' => '',

  // Clone uses a wildcard certificate for HTTPS ? (true/false)
  'clone_use_wildcard_cert' => true,
  // New server name
  // begin with clone_hostname_template
  // Example : bbb1, bbb2, ...
  'clone_hostname_template' => 'bbbX',

  'domain_name'=>'',
  // New BigBlueButton domain name
  // Put an 'X' as a placeholder for the clone number
  // Example : bbb1.scalelitebbb.systems
  'clone_domain_template' => '',
  //Commercial type of the newly created SCW instance
  'clone_commercial_type' => '', // e.g. GP1-M
  //In case we're cloning and reusing IPs from previous machines (specs upgrade)
  'clone_reuse_public_ip' => false,
  'clone_old_commercial_type' => '', //e.g GP1-S
  

  // FQDN domaine name of the scalelite server
  // Example : scalelite.example.com
  'scalelite_host' => '',
  // Should we add the cloned server to Scalelite's inventory and enable it
  'clone_add_to_scalelite' => true,
  'clone_enable_in_scalelite' => true,

  // Name of the private SSH key file
  'ssh_rsa' => '.ssh/id_rsa',
  'ssh_port' => 22,
  'ssh_user' => 'root',

  //Poll max number of parallel workers
  //'poll_max_workers' => 100,

);

?>
