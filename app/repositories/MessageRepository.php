<?php

/**
 * MessageRepository - Xử lý database cho tin nhắn
 */
class MessageRepository 
{
    private PDO $db;

    public function __construct() 
    {
        $this->db = Database::getInstance();
    }

    /**
     * Lấy tin nhắn theo phòng (trả về array of TextMessage/FileMessage objects)
     */
    public function findByRoom(int $roomId, int $limit = 50): array
    {
        if ($roomId <= 0 || $limit <= 0 || $limit > 1000) {
            throw new \InvalidArgumentException('Tham số không hợp lệ');
        }

        $sql = "
            SELECT m.*, u.username
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.room_id = :room_id
            ORDER BY m.sent_at ASC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Chuyển raw array → TextMessage / FileMessage objects
        $messages = [];
        foreach ($rows as $row) {
            $sender = new User((int)$row['sender_id'], $row['username']);
            $sentAt = new DateTime($row['sent_at']);

            if ($row['type'] === 'text') {
                $msg = new TextMessage((int)$row['id'], $sender, $row['content'], $sentAt);
            } else {
                $msg = new FileMessage((int)$row['id'], $sender, $row['content'], $row['type'], $sentAt);
            }
            
            // Lưu is_read vào property tạm (sẽ dùng trong controller)
            $msg->is_read = (int)$row['is_read'];
            
            $messages[] = $msg;
        }

        return $messages;
    }

    /**
     * Lưu tin nhắn mới
     */
    public function saveMessage(int $roomId, int $senderId, string $content, string $type = 'text'): int 
    {
        // Validate
        if ($roomId <= 0 || $senderId <= 0 || empty($content) || strlen($content) > 5000) {
            throw new \InvalidArgumentException('Dữ liệu không hợp lệ');
        }

        if (!in_array($type, ['text', 'image', 'file'])) {
            throw new \InvalidArgumentException('Type không hợp lệ');
        }

        $sql = "INSERT INTO messages (room_id, sender_id, content, type, sent_at) VALUES (:room_id, :sender_id, :content, :type, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':sender_id', $senderId, PDO::PARAM_INT);
        $stmt->bindValue(':content', $content, PDO::PARAM_STR);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$this->db->lastInsertId();
    }

    /**
     * Đánh dấu tin nhắn đã đọc
     */
    public function markAsRead(int $msgId, int $userId): void
    {
        if ($msgId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('ID không hợp lệ');
        }

        $sql = "UPDATE messages SET is_read = 1 WHERE id = :msg_id AND sender_id != :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':msg_id', $msgId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Đánh dấu tất cả tin nhắn trong phòng là đã đọc
     */
    public function markRoomAsRead(int $roomId, int $currentUserId): bool
    {
        if ($roomId <= 0 || $currentUserId <= 0) {
            throw new \InvalidArgumentException('ID không hợp lệ');
        }

        $sql = "UPDATE messages SET is_read = 1 WHERE room_id = :room_id AND sender_id != :user_id AND is_read = 0";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $currentUserId, PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    /**
     * Lấy tin nhắn mới sau một ID (cho polling)
     */
    public function getMessagesAfterId(int $roomId, int $lastId): array
    {
        if ($roomId <= 0 || $lastId < 0) {
            throw new \InvalidArgumentException('Tham số không hợp lệ');
        }

        $sql = "
            SELECT m.id, m.room_id, m.sender_id, m.content, m.type, m.sent_at, u.username 
            FROM messages m 
            JOIN users u ON m.sender_id = u.id 
            WHERE m.room_id = :room_id AND m.id > :last_id 
            ORDER BY m.id ASC 
            LIMIT 50
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':last_id', $lastId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lấy tin nhắn theo ID
     */
    public function getMessageById(int $messageId): ?array
    {
        if ($messageId <= 0) return null;

        $sql = "SELECT m.*, u.username FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.id = :message_id LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':message_id', $messageId, PDO::PARAM_INT);
        $stmt->execute();
        
        $message = $stmt->fetch(PDO::FETCH_ASSOC);
        return $message ?: null;
    }

    /**
     * Xóa tin nhắn
     */
    public function deleteMessage(int $messageId): bool
    {
        if ($messageId <= 0) return false;

        $sql = "DELETE FROM messages WHERE id = :message_id LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':message_id', $messageId, PDO::PARAM_INT);
        
        return $stmt->execute() && $stmt->rowCount() > 0;
    }
}
