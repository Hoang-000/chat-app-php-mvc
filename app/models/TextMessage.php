<?php
namespace App\Models;

class TextMessage extends BaseMessage {
    private string $text;

    public function __construct(int $id, User $sender, string $text) {
        parent::__construct($id, $sender);
        $this->text = $text;
    }

    public function getContent(): string {
        return $this->text;
    }

    public function getType(): string {
        return self::TYPE_TEXT;
    }
}