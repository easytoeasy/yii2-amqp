<?php

namespace pzr\amqp\cli\communication;

use Monolog\Logger as BaseLogger;
use pzr\amqp\cli\helper\AmqpIniHelper;
use pzr\amqp\cli\helper\FileHelper;

class PipeCommun extends BaseCommun
{
    private $fp = null;
    private $pipe_file;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->pipe_file = $config['pipe_file'];
    }

    public function open()
    {
       
    }

    public function close()
    {
        
    }

    public function read()
    {
        $buffer = FileHelper::read($this->pipe_file);
        if (empty($buffer)) return '';
        $this->flush();
        $this->logger->addLog(sprintf("[pipe] read buffer: %s", $buffer));
        $array = explode('|', $buffer);
        $data = array();
        foreach ($array as $k => $v) {
            if (empty($v)) continue;
            $arr = explode(',', $v);
            if (!is_array($arr)) continue;
            $data[] = $arr;
        }
        return $data;
    }

    public function write(string $queueName, string $program)
    {
        if (empty($queueName) || empty($program)) return false;
        $this->open();
        $string = $queueName . ',' . $program;
        $len = strlen($string);
        $size = FileHelper::write($this->pipe_file, $string);
        $level = $len == $size ? BaseLogger::INFO : BaseLogger::WARNING;
        $this->logger->addLog(sprintf("[pipe] write:%s, len:%d, succ:%d", $string, $len, $size), $level);
        return $size;
    }

    public function write_batch(array $array)
    {
        $strings = [];
        foreach ($array as $a) {
            if (empty($a['queueName']) || empty($a['program'])) continue;
            $strings[] = $a['queueName'] . ',' . $a['program'];
        }
        if (empty($strings)) return false;
        $string = implode('|', $strings);
        $len = strlen($string);
        $size = FileHelper::write($this->pipe_file, $string);
        $level = $len == $size ? BaseLogger::INFO : BaseLogger::ERROR;
        $this->logger->addLog(sprintf("[pipe] write:%s, len:%d, succ:%d", $string, $len, $size), $level);
        return $size;
    }

    public function flush()
    {
        @unlink($this->pipe_file) or 
            AmqpIniHelper::addLog('flush file error', BaseLogger::ERROR);
    }
}
