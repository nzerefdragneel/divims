<?php

namespace DiViMS;

use Exception;
use DigitalOceanV2\Client;
use Psr\Log\LoggerInterface;

class DOC
{
    private $clientDOC;

    /**
     * API Auth Token
     * @var string
     */
    private $auth_token = null;

    /**
     * Configuration object
     * @var \DiViMS\Config
     */
    private $config;

    /**
     * Logger object
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Construct a new wrapper instance
     *
     * @throws Exception if one parameter is missing or with bad value
     */
    public function __construct(\DiViMS\Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;

        $this->auth_token = $this->config->get('digitalocean_secret');
        
        if (!$this->auth_token) {
            throw new Exception("DigitalOcean API token is missing");
        }

        $this->clientDOC = new Client();
        $this->clientDOC->authenticate($this->auth_token);
    }

    public function createDomainRecord($domainName, $recordType, $recordName, $recordData)
    {
        try {
            // Create a new DomainRecord API instance
            $domainRecord = $this->clientDOC->domainRecord();

            // Create a new domain record
            $newRecord = $domainRecord->create($domainName, $recordType, $recordName, $recordData);

            $this->logger->info("Created new domain record", ['record_id' => $newRecord->id]);
            return $newRecord->id;
        } catch (Exception $e) {
            $this->logger->error("Error creating domain record", ['exception' => $e]);
            return false;
        }
    }
    public function getAllDomainRecords($domainName)
    {
        try {
            // Create a new DomainRecord API instance
            $domainRecord = $this->clientDOC->domainRecord();

            // Get all domain records
            $records = $domainRecord->getAll($domainName);

            $this->logger->info("Retrieved all domain records", ['records' => $records]);
            return $records;
        } catch (Exception $e) {
            $this->logger->error("Error retrieving domain records", ['exception' => $e]);
            return false;
        }
    }
    public function getDomainRecord($domainName, $recordId)
    {
        try {
            // Create a new DomainRecord API instance
            $domainRecord = $this->clientDOC->domainRecord();

            // Get a domain record
            $record = $domainRecord->getById($domainName, $recordId);
            $this->logger->info("Retrieved domain record", ['record' => $record]);
            return $record;
        } catch (Exception $e) {
            $this->logger->error("Error retrieving domain record", ['exception' => $e]);
            return false;
        }
    }
    public function removeDomainRecord($domainName, $recordId)
    {
        try {
            // Create a new DomainRecord API instance
            $domainRecord = $this->clientDOC->domainRecord();

            // Remove a domain record
            $domainRecord->remove($domainName, $recordId);
            $this->logger->info("Removed domain record", ['record_id' => $recordId]);
            return true;
        } catch (Exception $e) {
            $this->logger->error("Error removing domain record", ['exception' => $e]);
            return false;
        }
    }   
    
    function createDroplet($name, $region, $size, $image, $backups = false, $ipv6 = false, $vpcUuid = false, $sshKeys = [], $userData = '', $monitoring = true, $volumes = [], $tags = [], $disableAgent = false)
{
    try {
        // Tạo một thể hiện của class Droplet
        $droplet = $this->clientDOC->droplet();
        // Gọi phương thức create với các tham số được chỉ định
        $createdDroplet = $droplet->create(
            $name,
            $region,
            $size,
            $image,
            $backups,
            $ipv6,
            $vpcUuid,
            $sshKeys,
            $userData,
            $monitoring,
            $volumes,
            $tags,
            $disableAgent
        );

        // Trả về droplet đã được tạo
        return $createdDroplet;
    } catch (Exception $e) {
        // Xử lý ngoại lệ nếu có
        throw new Exception("Error creating droplet: " . $e->getMessage());
    }
}
    public function getAllDroplets()
    {
        try {
            // Create a new Droplet API instance
            $droplet = $this->clientDOC->droplet();

            // Get all droplets
            $droplets = $droplet->getAll();

            $this->logger->info("Retrieved all droplets", ['droplets' => $droplets]);
            return $droplets;
        } catch (Exception $e) {
            $this->logger->error("Error retrieving droplets", ['exception' => $e]);
            return false;
        }
    }   
    public function getDropletbyId($dropletid){
        try{
            $droplet=$this->clientDOC->droplet();
            $droplet=$droplet->getById($dropletid);
            $this->logger->info("Retrieved droplet by id", ['droplet' => $droplet]);
            return $droplet;
        }catch (Exception $e) {
            $this->logger->error("Error retrieving droplet by id", ['exception' => $e]);
            return false;
        }
    }
    public function rebootDroplet($dropletid){
        try {
            $droplet=$this->clientDOC->droplet();
            $droplet=$droplet->reboot($dropletid);
            $this->logger->info("Rebooted droplet", ['droplet' => $droplet]);
            return $droplet;
        } catch (Exception $e) {
            $this->logger->error("Error reboot droplet by id", ['exception' => $e]);
            return false;
        }
    }
    public function shutdownDroplet($dropletid){
        try {
            $droplet=$this->clientDOC->droplet();
            $droplet=$droplet->shutdown($dropletid);
            $this->logger->info("Shutdown droplet", ['droplet' => $droplet]);
            return $droplet;
        } catch (Exception $e) {
            $this->logger->error("Error shutdown droplet by id", ['exception' => $e]);
            return false;
        }
    }
    public function powerOffDroplet($dropletid){
        try {
            $droplet=$this->clientDOC->droplet();
            $droplet=$droplet->powerOff($dropletid);
            $this->logger->info("PowerOff droplet", ['droplet' => $droplet]);
            return $droplet;
        } catch (Exception $e) {
            $this->logger->error("Error powerOff droplet by id", ['exception' => $e]);
            return false;
        }
    }
    public function powerOnDroplet($dropletid){
        try {
            $droplet=$this->clientDOC->droplet();
            $droplet=$droplet->powerOn($dropletid);
            $this->logger->info("PowerOn droplet", ['droplet' => $droplet]);
            return $droplet;
        } catch (Exception $e) {
            $this->logger->error("Error powerOn droplet by id", ['exception' => $e]);
            return false;
        }
    }
    public function takeSnapshotDroplet($dropletid,$snapshotname){
        try{
            $droplet=$this->clientDOC->droplet();
            $droplet=$droplet->snapshot($dropletid,$snapshotname);
            $this->logger->info("Take snapshot droplet", ['droplet' => $droplet]);
            return $droplet;
        } catch (Exception $e){
            $this->logger->error("Error take snapshot droplet by id", ['exception' => $e]);
            return false;
        }
    }
    public function getAllSshKey(){
        try {
            $sshkey=$this->clientDOC->key();
            $sshkey=$sshkey->getAll();
            $this->logger->info("Retrieved all sshkey", ['sshkey' => $sshkey]);
            return $sshkey;
        } catch (Exception $e) {
            $this->logger->error("Error retrieving sshkey", ['exception' => $e]);
            return false;
        }
    }



}
