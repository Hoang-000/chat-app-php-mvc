<?php

class ChatRoom
{
    private array $members  = [];
    private array $messages = [];

    public function join(User $user): void
    {
        $this->members[$user->getId()] = $user;
    }

    public function leave(User $user): void
    {
        unset($this->members[$user->getId()]);
    }

    public function broadcast(BaseMessage $msg): void
    {
        $senderId = $msg->getSender()->getId();

        if (!isset($this->members[$senderId])) {
            throw new \RuntimeException("User ID {$senderId} is not in this room!");
        }

        $this->messages[] = $msg;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getMembers(): array
    {
        return $this->members;
    }
}