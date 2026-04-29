<?php
class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $host = '127.0.0.1';
        $dbname = 'chat_app';
        $username = 'root';
        $password = '';

        try {
            if (class_exists('PDO')) {
                $options = array(
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                );
                $this->conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password, $options);
            }
        } catch (PDOException $ex) {
            die("Connection failed: " . $ex->getMessage());
        }
    }

    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance->conn;
    }
}