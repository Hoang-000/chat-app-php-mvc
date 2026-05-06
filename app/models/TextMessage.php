<?php

class TextMessage extends BaseMessage
{
    private string $text;

    public function __construct(int $id, BaseUser $sender, string $text, ?DateTime $sentAt = null)
    {
        parent::__construct($id, $sender, $sentAt);
        $this->text = $text;
    }

    public function getContent(): string
    {
        return $this->text;
    }

    public function getType(): string
    {
        return self::TYPE_TEXT;
    }
}