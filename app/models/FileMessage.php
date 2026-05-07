<?php

class FileMessage extends BaseMessage
{
    private string $filePath;
    private string $fileType; // 'image' | 'file'

    public function __construct(int $id, User $sender, string $filePath, string $fileType = 'file', ?DateTime $sentAt = null)
    {
        parent::__construct($id, $sender, $sentAt);
        $this->filePath = $filePath;
        $this->fileType = $fileType;
    }

    public function getContent(): string
    {
        return $this->filePath;
    }

    public function getType(): string
    {
        return $this->fileType; // 'image' hoặc 'file'
    }
}