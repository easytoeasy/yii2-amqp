<?php

namespace pzr\amqp\cli\handler;

use Exception;
use Pheanstalk\Pheanstalk;
use pzr\amqp\cli\helper\ProcessHelper;

class BeanstalkHandler extends BaseHandler
{
    private $talker;

    public function __construct(array $config)
    {
        parent::__construct($config);
        isset($config['host']) or exit('invalid value : [beanstalk][host]');
        isset($config['port']) or exit('invalid value : [beanstalk][port]');
        $host = $config['host'];
        $port = $config['port'];
        $this->talker = Pheanstalk::create($host, $port);
    }

    public function addQueue(int $pid, int $ppid, string $queue, int $qos)
    {
        if (empty($pid) || empty($ppid) || empty($queue) || empty($qos)) {
            return false;
        }
        $job = [self::EVENT_ADD_QUEUE => [$pid, $ppid, $queue, $qos]];
        try {
            $this->logger->addLog(sprintf("[%s] %s=%s,%s", self::EVENT_ADD_QUEUE, $pid, $queue, $qos));
            $this->talker->useTube(self::QUEUE)->put(json_encode($job));
        } catch (Exception $e) {
        }

        return true;
    }

    public function delPid(int $pid = 0, int $ppid = 0)
    {
        if (empty($pid) && empty($ppid)) return false;
        $pidInfo = ProcessHelper::readPid($pid, $ppid);
        $event = empty($pid) ? self::EVENT_DELETE_PPID : self::EVENT_DELETE_PID;
        $deadPid = empty($pid) ? $ppid : $pid;
        $job = [$event => $deadPid];
        try {
            $this->talker->useTube(self::QUEUE)->put(json_encode($job));
        } catch (Exception $e) {
        }
        $this->logger->addLog(sprintf("[%s] %d_%d,  pidinfo:%s", $event, $pid, $ppid, json_encode($pidInfo)));
        return $pidInfo;
    }

    public function handle()
    {
        while (true) {
            $this->talker->watch(self::QUEUE);
            $job = $this->talker->reserve();

            try {
                $jobPayload = $job->getData();
                $body = json_decode($jobPayload, true);
                $this->write($body);
                $this->talker->delete($job);
            } catch (\Exception $e) {
                $this->talker->release($job);
            }
        }
    }

    public function write($body)
    {
        if (empty($body) || !is_array($body)) return false;
        $event = key($body);
        $data = $body[$event];
        switch ($event) {
            case HandlerInterface::EVENT_ADD_QUEUE:
                list($pid, $ppid, $queueName, $qos) = $data;
                ProcessHelper::handleAddQueue($pid, $ppid, $queueName, $qos);
                break;
            case HandlerInterface::EVENT_DELETE_PID:
                ProcessHelper::handleDelPid($data);
                break;
            case HandlerInterface::EVENT_DELETE_PPID:
                ProcessHelper::handleDelPpid($data);
                break;
        }
    }
}