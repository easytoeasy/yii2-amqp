<?php

namespace pzr\amqp\duplicate;

/**
 * 随机路由方式
 */
class DuplicateRandom implements DuplicateInterface
{

    public function getRoutingKey($routingKey, $duplicate = 0)
    {
        $duplicate = intval($duplicate);
        if ($duplicate < 1) {
            return $routingKey;
        }
        $index = mt_rand(0, $duplicate-1);
        return $routingKey . '_' . $index;
    }
    
}
