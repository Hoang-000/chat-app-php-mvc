<?php

/**
 * UserRepository - Xử lý database cho User
 */
class UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Tìm user theo ID
     */
    public function findById(int $userId): ?User
    {
        try {
            $sql = "SELECT * FROM users WHERE id = :id LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                return null;
            }
            
            return new User((int)$row['id'], $row['username']);
            
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Tìm user theo username
     */
    public function findByUsername(string $username): ?User
    {
        try {
            $sql = "SELECT * FROM users WHERE username = :username LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->execute();
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                return null;
            }
            
            return new User((int)$row['id'], $row['username']);
            
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Lấy tất cả users
     */
    public function getAllUsers(): array
    {
        try {
            $sql = "SELECT * FROM users ORDER BY username ASC";
            $stmt = $this->db->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $users = [];
            foreach ($rows as $row) {
                $users[] = new User((int)$row['id'], $row['username']);
            }
            
            return $users;
            
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Tạo user mới
     */
    public function create(string $username, string $password): int
    {
        $sql = "INSERT INTO users (username, password, created_at) VALUES (:username, :password, NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->bindValue(':password', password_hash($password, PASSWORD_BCRYPT), PDO::PARAM_STR);
        $stmt->execute();
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Verify login
     */
    public function verifyLogin(string $username, string $password): ?User
    {
        try {
            $sql = "SELECT * FROM users WHERE username = :username LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->execute();
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row || !password_verify($password, $row['password'])) {
                return null;
            }
            
            return new User((int)$row['id'], $row['username']);
            
        } catch (\Throwable $e) {
            return null;
        }
    }
}
