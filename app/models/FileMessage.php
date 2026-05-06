<?php
namespace App\Models;

class FileMessage extends BaseMessage {
    private string $filePath;

    public function __construct(int $id, User $sender, string $filePath) {
        parent::__construct($id, $sender);
        $this->filePath = $filePath;
    }

    public function getContent(): string {
        return "File: " . $this->filePath;
    }

    public function getType(): string {
        return self::TYPE_FILE;
    }
}