<?php

class MessageRepository {
    use LoggerTrait; 
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    
    // Lấy danh sách tin nhắn trong một phòng chat
    public function getMessagesByRoom($roomId, $limit = 50) {
        $sql = "SELECT m.*, u.username 
                FROM messages m 
                JOIN users u ON m.sender_id = u.id 
                WHERE m.room_id = :room_id 
                ORDER BY m.sent_at ASC 
                LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':room_id' => $roomId, 
            ':limit' => $limit
        ]);
        
        return $stmt->fetchAll();
    }

    //Lưu tin nhắn mới vào Database
    public function saveMessage($roomId, $senderId, $content) {
    try {
        $sql = "INSERT INTO messages (room_id, sender_id, content) 
                VALUES (:room_id, :sender_id, :content)";
        
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute([
            ':room_id' => $roomId,
            ':sender_id' => $senderId,
            ':content' => $content
        ]);
    } catch (Exception $e) {
        $this->log("Lỗi: " . $e->getMessage());
        return false;
    }
}
}