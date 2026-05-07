<?php

class EmojiDecorator implements MessageDecorator
{
    private array $emojiMap;

    public function __construct()
    {
        $this->emojiMap = [
            ':)'  => '😊', ':-)'  => '😊', ':D'   => '😃', ':-D'  => '😃',
            ':('  => '😞', ':-('  => '😞', ':P'   => '😛', ':-P'  => '😛',
            ';)'  => '😉', ';-)'  => '😉', ':O'   => '😮', ':-O'  => '😮',
            ':*'  => '😘', 'B)'   => '😎',
            ':happy:'   => '🎉', ':heart:'   => '❤️', ':love:'    => '❤️',
            ':fire:'    => '🔥', ':star:'    => '⭐', ':thumbs:'  => '👍',
            ':ok:'      => '👍', ':clap:'    => '👏', ':sad:'     => '😢',
            ':cry:'     => '😭', ':laugh:'   => '😂', ':smile:'   => '😊',
            ':cool:'    => '😎', ':wave:'    => '👋', ':check:'   => '✅',
            ':cross:'   => '❌', ':warning:' => '⚠️', ':rocket:'  => '🚀',
            ':cake:'    => '🎂', ':gift:'    => '🎁',
        ];
        // Sort theo độ dài giảm dần để tránh conflict (:-) vs :))
        uksort($this->emojiMap, fn($a, $b) => strlen($b) - strlen($a));
    }

    public function decorate(BaseMessage $msg): string
    {
        $content = $msg->getContent();
        return str_replace(array_keys($this->emojiMap), array_values($this->emojiMap), $content);
    }

    /**
     * Lấy danh sách emoji để render picker UI
     * @return array ['shortcode' => 'emoji']
     */
    public function getEmojiList(): array
    {
        return $this->emojiMap;
    }
}