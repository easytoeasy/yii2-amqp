<?php

namespace pzr\amqp;

use PhpAmqpLib\Message\AMQPMessage;
use pzr\amqp\event\ExecEvent;
use pzr\amqp\event\PushEvent;

/**
 * 通过AMQP实现RPC调用
 */
class AmqpRpc extends Amqp
{
    /** @var string 临时队列名称 */
    private $_callbackQueueName;

    /** @var array 临时记录所有请求的correlation_id */
    private $_corrids = array();

    /** @var array 返回的响应体 */
    private $_responses = array();

    /** @var int 临时队列消费者的QOS */
    private $_qos = 1;

    /** @var int 临时队列消费者的超时时间，单位seconds */
    private $_timeout = 3;

    /**
     * 单条消息处理
     *
     * @param AmqpJob $job 
     * @return void
     */
    public function push($job)
    {
        $this->open();
        $this->trigger(self::EVENT_BEFORE_PUSH, new PushEvent(['job' => $job]));
        // 匿名队列
        list($this->_callbackQueueName,,) = $this->channel->queue_declare("", false, false, true, false);
        // 发送请求
        $corrid = $job->getUuid();
        $payload = new AMQPMessage(
            $this->serializer->serialize($job),
            [
                'correlation_id' => $corrid,
                'reply_to' => $this->_callbackQueueName,
            ]
        );
        if (empty($routingKey)) $routingKey = $this->routingKey;
        $this->channel->basic_publish($payload, $this->exchangeName, $routingKey);

        $this->trigger(self::EVENT_AFTER_PUSH, new PushEvent(['job' => $job]));
        // 开启临时队列的消费者：自动ack
        $this->channel->basic_qos(null, 1, null);
        $this->channel->basic_consume($this->_callbackQueueName, '', false, true, false, false, array($this, 'handleResponse'));
        // 等待RCK队列消费返回响应
        try {
            while (empty($this->_responses[$corrid])) {
                $this->channel->wait(null, false, $this->_timeout);
            }
        } finally {
            // 必须是对象才能够返回到请求方，否则会被Yii底层转成int型
            return new Response([
                'response' => $this->_responses[$corrid]
            ]);
        }
    }

    /**
     * 批量消息处理
     *
     * @param array $jobs
     * @return void
     */
    public function publish(array $jobs)
    {
        $this->open();
        if (!is_array($jobs)) {
            return false;
        }
        $event = new PushEvent(['jobs' => $jobs]);
        $this->trigger(self::EVENT_BEFORE_PUSH, $event);
        // 声明临时队列
        list($this->_callbackQueueName,,) = $this->channel->queue_declare("", false, false, true, false);
        // 批量发送消息
        $routingKey = $this->duplicater->getRoutingKey($this->routingKey, $this->duplicate);
        foreach ($jobs as $job) {
            if (!($job instanceof AmqpJob)) {
                continue;
            }
            $this->_corrids[] = $job->getUuid();
            $this->batchBasicPublish($job, $this->exchangeName, $routingKey);
        }
        $this->publishBatch($event->noWait);

        $this->trigger(self::EVENT_AFTER_PUSH, $event);
        /** 即使可以通过一个channel启动多个消费者，但是消费者处理消息也不是并发处理 */
        $this->channel->basic_qos(null, $this->_qos, null);
        $this->channel->basic_consume($this->_callbackQueueName, '', false, true, false, false, array($this, 'handleResponse'));
        // 等待RPC队列响应
        try {
            while (count($this->_corrids) != count($this->_responses)) {
                $this->channel->wait(null, false, $this->_timeout);
            }
        } finally {
            // 必须是对象才能够返回到请求方，否则会被Yii底层转成int型
            return new Response([
                'response' => $this->_responses
            ]);
        }
    }

    /**
     * 消费者处理临时队列的消息
     * 
     * @param AMQPMessage $payload
     * @return void
     */
    public function handleResponse(AMQPMessage $payload)
    {
        $corrid = $payload->get('correlation_id');
        $this->_responses[$corrid] = $payload->body;
    }

    /**
     * @inheritDoc
     *
     * @param AmqpJob $job
     * @param string $exchangeName
     * @param string $routingKey
     * @return void
     */
    public function batchBasicPublish($job, $exchangeName, $routingKey)
    {
        $message = $this->serializer->serialize($job);
        $this->channel->batch_basic_publish(
            new AMQPMessage($message, [
                'correlation_id' => $job->getUuid(),
                'reply_to' => $this->_callbackQueueName,
            ]),
            $exchangeName,
            $routingKey
        );
    }

    /**
     * @inheritDoc
     */
    public function consume($queueName, $qos = 1, $consumerTag = '', $noAck=false)
    {
        $this->open();
        $callback = function (AMQPMessage $payload, $noAck) {
            $this->handleMessage($payload, $noAck);
        };
        $this->channel->basic_qos(null, $qos, null);
        /** 开启自动ack，防止因为消息异常而导致一直无法消费成功 */
        $this->channel->basic_consume($queueName, $consumerTag, false, true, false, false, $callback);
        while (count($this->channel->callbacks)) {
            $this->channel->wait();
        }
    }

    /**
     * RPC的消费者处理消息
     * 
     * @inheritDoc
     * @param AMQPMessage $payload
     * @return ExecEvent
     */
    public function handleMessage(AMQPMessage $payload, $noAck)
    {
        $job = $this->serializer->unserialize($payload->getBody());

        if (!($job instanceof AmqpJob)) {
            return false;
        }

        $event = new ExecEvent(['job' => $job]);
        $this->trigger(self::EVENT_BEFORE_EXEC, $event);
        try {
            $event->result = $event->job->execute();
        } catch (\Exception $error) {
            $event->error = $error;
        } catch (\Throwable $error) {
            $event->error = $error;
        }
        $this->trigger(self::EVENT_AFTER_EXEC, $event);

        $this->pushResponse($payload, $event->result);
        return $event;
    }

    /**
     * 将响应的内容推到临时队列
     *
     * @param AMQPMessage $payload
     * @param ExecEvent $event
     * @return void
     */
    public function pushResponse($payload, $response)
    {
        $response = $this->serializer->serialize($response);
        $message = new AMQPMessage(
            $response,
            array('correlation_id' => $payload->get('correlation_id'))
        );

        // amqplib 1.2版本使用
        // $payload->getChannel()->basic_publish(
        //     $message,
        //     '',
        //     $payload->get('reply_to')
        // );

        $payload->delivery_info['channel']->basic_publish(
            $message,
            '',
            $payload->get('reply_to')
        );
    }

    /**
     * Set the value of _qos
     *
     * @return  self
     */
    public function setQos($_qos)
    {
        $this->_qos = $_qos;
        return $this;
    }

    /**
     * Set the value of _timeout
     *
     * @return  self
     */
    public function setTimeout($_timeout)
    {
        $this->_timeout = $_timeout;
        return $this;
    }

    /**
     * 批量请求的时候返回多个响应
     *
     * @return void
     */
    public function getResponses()
    {
        return $this->_responses;
    }
}
