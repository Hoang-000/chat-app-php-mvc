<?php
require_once __DIR__ . '/MessageDecorator.php';

class MentionDecorator implements MessageDecorator
{
    use LoggerTrait;

    private const MENTION_PATTERN = '/@([a-zA-Z0-9_]{3,20})/u';
    private const LINK_TEMPLATE   = '<a href="/profile/%s" class="mention">@%s</a>';

    public function __construct()
    {
        $this->initLogger();
    }

    /**
     * Thực hiện decorate: chuyển @username thành link
     */
    public function decorate(BaseMessage $msg): string
    {
        $content = $msg->getContent();
        $this->log('MENTION_START', "ID={$msg->getId()} | {$content}");

        $converted = preg_replace_callback(self::MENTION_PATTERN, function ($m) {
            $safe = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            return sprintf(self::LINK_TEMPLATE, $safe, $safe);
        }, $content);

        $this->log('MENTION_DONE', "ID={$msg->getId()} | {$converted}");
        return $converted;
    }
}