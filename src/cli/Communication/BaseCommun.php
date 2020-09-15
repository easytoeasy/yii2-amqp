<?php

namespace pzr\amqp\cli\communication;

use pzr\amqp\cli\communication\CommunInterface;
use pzr\amqp\cli\logger\Logger;

abstract class BaseCommun implements CommunInterface
{
    protected $logger = null;

    public function __construct(array $config)
    {
        $access_log = $config['access_log'];
        $error_log = $config['error_log'];
        $level = $config['level'];
        unset($config['access_log']);
        unset($config['error_log']);
        unset($config['level']);
        $this->logger = new Logger($access_log, $error_log, $level);
    }

    

    
}
