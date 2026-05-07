<?php

class MentionDecorator implements MessageDecorator
{
    private const MENTION_PATTERN = '/@([a-zA-Z0-9_]{3,20})/u';
    private const LINK_TEMPLATE   = '<a href="/profile/%s" class="mention">@%s</a>';

    public function decorate(BaseMessage $msg): string
    {
        $content = $msg->getContent();
        
        return preg_replace_callback(self::MENTION_PATTERN, function ($m) {
            $safe = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            return sprintf(self::LINK_TEMPLATE, $safe, $safe);
        }, $content);
    }
}