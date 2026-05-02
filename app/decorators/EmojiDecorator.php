<?php
require_once __DIR__ . '/MessageDecorator.php';

class EmojiDecorator implements MessageDecorator
{
    private array $emojiMap = [
        ':)' => '😊', ':-)' => '😊', ':D' => '😃', ':-D' => '😃',
        ':(' => '😞', ':-(' => '😞', ':P' => '😛', ':-P' => '😛',
        ';)' => '😉', ';-)' => '😉', ':O' => '😮', ':-O' => '😮',
        ':*' => '😘', 'B)' => '😎', ':happy:' => '🎉', ':heart:' => '❤️',
        ':love:' => '❤️', ':fire:' => '🔥', ':star:' => '⭐', ':thumbs:' => '👍',
        ':ok:' => '👍', ':clap:' => '👏', ':sad:' => '😢', ':cry:' => '😭',
        ':laugh:' => '😂', ':smile:' => '😊', ':cool:' => '😎', ':wave:' => '👋',
        ':check:' => '✅', ':cross:' => '❌', ':warning:' => '⚠️', ':rocket:' => '🚀',
        ':cake:' => '🎂', ':gift:' => '🎁',
    ];

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
        $logEntry = '[' . date('Y-m-d H:i:s') . '] [EmojiDecorator] ' . $action . ': ' . $message . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public function getContent(): string {
        $original = $this->message->getContent();
        $this->log('DECORATION_START', "ID={$this->getId()} | $original");
        $converted = $this->convertEmojis($original);
        $this->log('DECORATION_DONE', "ID={$this->getId()} | $converted");
        return $converted;
    }

    private function convertEmojis(string $content): string {
        uksort($this->emojiMap, fn($a, $b) => strlen($b) - strlen($a));
        return str_replace(array_keys($this->emojiMap), array_values($this->emojiMap), $content);
    }

    public function getId(): int { return $this->message->getId(); }
    public function getUserId(): int { return $this->message->getUserId(); }
    public function getCreatedAt(): string { return $this->message->getCreatedAt(); }
}