<?php
namespace App\Models;

use DateTimeImmutable;

abstract class BaseMessage {
    public const TYPE_TEXT = 'text';
    public const TYPE_FILE = 'file';

    protected int $id;
    protected User $sender;
    protected DateTimeImmutable $sentAt;

    public function __construct(int $id, User $sender) {
        $this->id = $id;
        $this->sender = $sender;
        $this->sentAt = new DateTimeImmutable();
    }

    public function getId(): int {
        return $this->id;
    }

    public function getSender(): User {
        return $this->sender;
    }

    public function getSentAt(): DateTimeImmutable {
        return $this->sentAt;
    }

    abstract public function getContent(): string;

    abstract public function getType(): string;
}