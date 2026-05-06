<?php
trait LoggerTrait {
    public function log($message, $level = 'INFO') {
        $timestamp = date("Y-m-d H:i:s");
        $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;

        echo(error_log($logMessage));
    }
}