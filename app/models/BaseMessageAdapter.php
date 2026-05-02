<?php
require_once __DIR__ . '/../decorators/MessageDecorator.php';

class BaseMessageAdapter implements MessageDecorator
{
    private $baseMessage;

    public function __construct($baseMessage) {
        $required = ['getId', 'getUserId', 'getContent', 'getCreatedAt'];
        foreach ($required as $method) {
            if (!method_exists($baseMessage, $method)) {
                throw new Exception("Missing method $method in BaseMessage");
            }
        }
        $this->baseMessage = $baseMessage;
    }

    public function getContent(): string { return $this->baseMessage->getContent(); }
    public function getId(): int { return $this->baseMessage->getId(); }
    public function getUserId(): int { return $this->baseMessage->getUserId(); }
    public function getCreatedAt(): string { return $this->baseMessage->getCreatedAt(); }
}