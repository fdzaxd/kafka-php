<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4 foldmethod=marker: */
// +---------------------------------------------------------------------------
// | SWAN [ $_SWANBR_SLOGAN_$ ]
// +---------------------------------------------------------------------------
// | Copyright $_SWANBR_COPYRIGHT_$
// +---------------------------------------------------------------------------
// | Version  $_SWANBR_VERSION_$
// +---------------------------------------------------------------------------
// | Licensed ( $_SWANBR_LICENSED_URL_$ )
// +---------------------------------------------------------------------------
// | $_SWANBR_WEB_DOMAIN_$
// +---------------------------------------------------------------------------

namespace Kafka\Consumer;

/**
+------------------------------------------------------------------------------
* Kafka protocol since Kafka v0.8
+------------------------------------------------------------------------------
*
* @package
* @version $_SWANBR_VERSION_$
* @copyright Copyleft
* @author $_SWANBR_AUTHOR_$
+------------------------------------------------------------------------------
*/

class Process
{
    use \Psr\Log\LoggerAwareTrait;
    use \Kafka\LoggerTrait;

    // {{{ consts
    // }}}
    // {{{ members

    protected $consumer = null;

    protected $isRunning = true;

    // }}}
    // {{{ functions
    // {{{ public function __construct()

    public function __construct(\Closure $consumer = null) {
        $this->consumer = $consumer; 
    }

    // }}}
    // {{{ public function init()

    /**
     * start consumer 
     *
     * @access public
     * @return void
     */
    public function init()
    {
        // init protocol
        $config = \Kafka\ConsumerConfig::getInstance();
        \Kafka\Protocol::init($config->getBrokerVersion(), $this->logger);

        // init process request
        $broker = \Kafka\Broker::getInstance();
        $broker->setProcess(function($data) {
            $this->processRequest($data);
        });

        // init state
        $this->state = \Kafka\Consumer\State::getInstance();
        if ($this->logger) {
            $this->state->setLogger($this->logger);
        }

        //$this->state->setOnConsumer($this->consumer);

        //$this->state->waitSyncMeta();

        //// repeat get update meta info
        //\Amp\repeat(function ($watcherId) {
        //    $this->state->waitSyncMeta();
        //    if (!$this->isRunning) {
        //        \Amp\cancel($watcherId);
        //    }
        //}, $msInterval = \Kafka\ConsumerConfig::getInstance()->getMetadataRefreshIntervalMs());
    }

    // }}}
    // {{{ public function start()

    /**
     * start consumer 
     *
     * @access public
     * @return void
     */
    public function start()
    {
        $this->init();

        // init protocol
        $config = \Kafka\ConsumerConfig::getInstance();
        \Kafka\Protocol::init($config->getBrokerVersion(), $this->logger);

        // init process request
        $broker = \Kafka\Broker::getInstance();
        $broker->setProcess(function($data) {
            $this->processRequest($data);
        });

        // init state
        $this->state = \Kafka\Consumer\State::getInstance();
        if ($this->logger) {
            $this->state->setLogger($this->logger);
        }

        $this->state->start();

        //$this->state->setOnConsumer($this->consumer);

        //$this->state->waitSyncMeta();

        //// repeat get update meta info
        //\Amp\repeat(function ($watcherId) {
        //    $this->state->waitSyncMeta();
        //    if (!$this->isRunning) {
        //        \Amp\cancel($watcherId);
        //    }
        //}, $msInterval = \Kafka\ConsumerConfig::getInstance()->getMetadataRefreshIntervalMs());
    }

    // }}}
    // {{{ public function stop()

    /**
     * stop consumer 
     *
     * @access public
     * @return void
     */
    public function stop()
    {
        $this->isRunning = false;
    }

    // }}}
    // {{{ protected function processRequest()

    /**
     * process Request 
     *
     * @access public
     * @return void
     */
    protected function processRequest($data, $fd)
    {
        $correlationId = \Kafka\Protocol\Protocol::unpack(\Kafka\Protocol\Protocol::BIT_B32, substr($data, 0, 4));
        $connections = \Kafka\Consumer\Connection::getInstance();
        switch($correlationId) {
        case \Kafka\Protocol\Protocol::METADATA_REQUEST:
            $meta = new \Kafka\Protocol\Metadata(\Kafka\ConsumerConfig::getInstance()->getBrokerVersion());
            $result = $meta->decode(substr($data, 4));
            if (!isset($result['brokers']) || !isset($result['topics'])) {
                $this->error('Get metadata is fail, brokers or topics is null.');
                $this->state->failSyncMeta();
            } else {
                $this->state->succSyncMeta($result['brokers'], $result['topics']);
            }
            break;
        case \Kafka\Protocol\Protocol::GROUP_COORDINATOR_REQUEST:
            $group = new \Kafka\Protocol\GroupCoordinator(\Kafka\ConsumerConfig::getInstance()->getBrokerVersion());
            $result = $group->decode(substr($data, 4));
            if (isset($result['errorCode']) && $result['errorCode'] == 0) {
                if (isset($result['coordinatorId'])) {
                    $this->state->succGetGroupBrokerId($result['coordinatorId']);
                } else { // sync brokers meta
                    $this->state->failGetGroupBrokerId(-1);
                }
            } else {
                $this->state->failGetGroupBrokerId($result['errorCode']);
            }
            break;
        case \Kafka\Protocol\Protocol::JOIN_GROUP_REQUEST:
            $group = new \Kafka\Protocol\JoinGroup(\Kafka\ConsumerConfig::getInstance()->getBrokerVersion());
            $result = $group->decode(substr($data, 4));
            if (isset($result['errorCode']) && $result['errorCode'] == 0) {
                $this->state->succJoinGroup($result);
            } else {
                $this->state->failJoinGroup($result['errorCode']);
            }
            break;
        case \Kafka\Protocol\Protocol::SYNC_GROUP_REQUEST:
            $group = new \Kafka\Protocol\SyncGroup(\Kafka\ConsumerConfig::getInstance()->getBrokerVersion());
            $result = $group->decode(substr($data, 4));
            if (isset($result['errorCode']) && $result['errorCode'] == 0) {
                $this->state->succSyncGroup($result);
            } else {
                $this->state->failSyncGroup($result['errorCode']);
            }
            break;
        case \Kafka\Protocol\Protocol::HEART_BEAT_REQUEST:
            $heart = new \Kafka\Protocol\Heartbeat(\Kafka\ConsumerConfig::getInstance()->getBrokerVersion());
            $result = $heart->decode(substr($data, 4));
            if (isset($result['errorCode']) && $result['errorCode'] == 0) {
                $this->state->succHeartbeat($result);
            } else {
                $this->state->failHeartbeat($result['errorCode']);
            }
            break;
        case \Kafka\Protocol\Protocol::OFFSET_REQUEST:
            $offset = new \Kafka\Protocol\Offset(\Kafka\ConsumerConfig::getInstance()->getBrokerVersion());
            $result = $offset->decode(substr($data, 4));
            $this->state->succOffset($result);
            break;
        case \Kafka\Protocol\Protocol::OFFSET_FETCH_REQUEST:
            $offset = new \Kafka\Protocol\FetchOffset(\Kafka\ConsumerConfig::getInstance()->getBrokerVersion());
            $result = $offset->decode(substr($data, 4));
            $this->state->succFetchOffset($result);
            break;
        case \Kafka\Protocol\Protocol::FETCH_REQUEST:
            $fetch = new \Kafka\Protocol\Fetch(\Kafka\ConsumerConfig::getInstance()->getBrokerVersion());
            $result = $fetch->decode(substr($data, 4));
            $this->state->succFetch($result);
            break;
        case \Kafka\Protocol\Protocol::OFFSET_COMMIT_REQUEST:
            $commit = new \Kafka\Protocol\CommitOffset(\Kafka\ConsumerConfig::getInstance()->getBrokerVersion());
            $result = $commit->decode(substr($data, 4));
            $this->state->succCommit($result);
            break;
        default:
            var_dump($correlationId);
        }
    }

    // }}}
    // {{{ protected function syncMeta()

    protected function syncMeta()
    {
        $this->debug('Start sync metadata request');
        $brokerList = explode(',', \Kafka\ConsumerConfig::getInstance()->getMetadataBrokerList());
        $brokerHost = array();
        foreach ($brokerList as $key => $val) {
            if (trim($val)) {
                $brokerHost[] = $val;
            }
        }
        if (count($brokerHost) == 0) {
            throw new \Kafka\Exception('Not set config `metadataBrokerList`');
        }
        shuffle($brokerHost);
        $broker = \Kafka\Broker::getInstance();
        foreach ($brokerHost as $host) {
            $socket = $broker->getMetaConnect($host);
            if ($socket) {
                $params = \Kafka\ConsumerConfig::getInstance()->getTopics();
                $this->debug('Start sync metadata request params:' . json_encode($params));
                $requestData = \Kafka\Protocol::encode(\Kafka\Protocol::METADATA_REQUEST, $params);
                $socket->write($requestData);
                return;
            }
        }
        throw new \Kafka\Exception('Not has broker can connection `metadataBrokerList`');
    }

    // }}}
    // }}}
}
