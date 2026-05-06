<?php

class User extends BaseUser
{
    public function __construct(int $id, string $name)
    {
        parent::__construct($id, $name);
    }
}