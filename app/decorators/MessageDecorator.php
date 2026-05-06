<?php
/**
 * Interface MessageDecorator
 * Decorator Pattern - đúng theo yêu cầu
 */
interface MessageDecorator
{
    public function decorate(BaseMessage $msg): string;
}