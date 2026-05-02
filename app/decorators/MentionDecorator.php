<?php
require_once __DIR__ . '/MessageDecorator.php';

class MentionDecorator implements MessageDecorator
{
    private const MENTION_PATTERN = '/@([a-zA-Z0-9_]{3,20})/u';
    private const LINK_TEMPLATE = '<a href="/profile/%s" class="mention">@%s</a>';

    private string $logFile = '';
    private $message;

    public function __construct(MessageDecorator $message) {
        $this->message = $message;
        $this->initLogger();
    }

    private function initLogger(): void {
        $logsDir = __DIR__ . '/../../logs';
        if (!is_dir($logsDir)) mkdir($logsDir, 0755, true);
        $this->logFile = $logsDir . '/chat.log';
    }

    private function log(string $action, string $message): void {
        if (!$this->logFile) return;
        $logEntry = '[' . date('Y-m-d H:i:s') . '] [MentionDecorator] ' . $action . ': ' . $message . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public function getContent(): string {
        $original = $this->message->getContent();
        $this->log('DECORATION_START', "ID={$this->getId()} | $original");
        $converted = preg_replace_callback(self::MENTION_PATTERN, function($m) {
            $safe = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            return sprintf(self::LINK_TEMPLATE, $safe, $safe);
        }, $original);
        $this->log('DECORATION_DONE', "ID={$this->getId()} | $converted");
        return $converted;
    }

    public function getId(): int { return $this->message->getId(); }
    public function getUserId(): int { return $this->message->getUserId(); }
    public function getCreatedAt(): string { return $this->message->getCreatedAt(); }
}