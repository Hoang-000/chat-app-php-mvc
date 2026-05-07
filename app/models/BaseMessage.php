<?php

abstract class BaseMessage
{
    public const TYPE_TEXT  = 'text';
    public const TYPE_IMAGE = 'image';
    public const TYPE_FILE  = 'file';

    protected int      $id;
    protected User     $sender;
    protected DateTime $sentAt;
    public int         $is_read = 0;

    public function __construct(int $id, User $sender, ?DateTime $sentAt = null)
    {
        $this->id     = $id;
        $this->sender = $sender;
        $this->sentAt = $sentAt ?? new DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSender(): User
    {
        return $this->sender;
    }

    public function getSentAt(): DateTime
    {
        return $this->sentAt;
    }

    abstract public function getContent(): string;

    abstract public function getType(): string;
}