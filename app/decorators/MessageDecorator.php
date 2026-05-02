<?php
/**
 * Interface MessageDecorator
 * 
 * Định nghĩa hợp đồng cho tất cả decorator tin nhắn.
 */
interface MessageDecorator
{
    public function getContent(): string;
    public function getId(): int;
    public function getUserId(): int;
    public function getCreatedAt(): string;
}