<?php
require_once __DIR__ . '/MessageDecorator.php';

class EmojiDecorator implements MessageDecorator
{
    use LoggerTrait;

    private array $emojiMap = [
        ':)'  => '😊', ':-)'  => '😊', ':D'   => '😃', ':-D'  => '😃',
        ':('  => '😞', ':-('  => '😞', ':P'   => '😛', ':-P'  => '😛',
        ';)'  => '😉', ';-)'  => '😉', ':O'   => '😮', ':-O'  => '😮',
        ':*'  => '😘', 'B)'   => '😎',
        ':happy:'   => '🎉', ':heart:'   => '❤️', ':love:'    => '❤️',
        ':fire:'    => '🔥', ':star:'    => '⭐',    ':thumbs:'  => '👍',
        ':ok:'      => '👍', ':clap:'    => '👏', ':sad:'     => '😢',
        ':cry:'     => '😭', ':laugh:'   => '😂', ':smile:'   => '😊',
        ':cool:'    => '😎', ':wave:'    => '👋', ':check:'   => '✅',
        ':cross:'   => '❌',    ':warning:' => '⚠️', ':rocket:'  => '🚀',
        ':cake:'    => '🎂', ':gift:'    => '🎁',
    ];

    public function __construct()
    {
        $this->initLogger();
    }

    /**
     * Thực hiện decorate: chuyển ký hiệu emoji thành unicode
     */
    public function decorate(BaseMessage $msg): string
    {
        $content = $msg->getContent();
        $this->log('EMOJI_START', "ID={$msg->getId()} | {$content}");

        $converted = $this->convertEmojis($content);

        $this->log('EMOJI_DONE', "ID={$msg->getId()} | {$converted}");
        return $converted;
    }

    private function convertEmojis(string $content): string
    {
        uksort($this->emojiMap, fn($a, $b) => strlen($b) - strlen($a));
        return str_replace(array_keys($this->emojiMap), array_values($this->emojiMap), $content);
    }
}