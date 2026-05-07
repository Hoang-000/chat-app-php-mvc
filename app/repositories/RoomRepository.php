<?php

/**
 * RoomRepository - Quản lý phòng chat và filter
 */
class RoomRepository 
{
    private PDO $db;

    public function __construct() 
    {
        $this->db = Database::getInstance();
    }

    /**
     * Lấy danh sách phòng với filter: 'all', 'unread', 'groups'
     */
    public function getAllRooms(int $userId, string $filterType = 'all'): array 
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('User ID không hợp lệ');
        }

        // Base query: Lấy phòng + tin nhắn cuối + unread count + tên người kia (nếu private)
        $sql = "
            SELECT 
                cr.id AS room_id,
                cr.name AS room_name,
                cr.type AS room_type,
                cr.created_at AS room_created_at,
                
                latest_msg.content AS last_message,
                latest_msg.type AS last_message_type,
                latest_msg.sender_id AS last_sender_id,
                latest_msg.sent_at AS last_message_time,
                
                CASE 
                    WHEN DATE(latest_msg.sent_at) = CURDATE() THEN DATE_FORMAT(latest_msg.sent_at, '%H:%i')
                    ELSE DATE_FORMAT(latest_msg.sent_at, '%d/%m %H:%i')
                END AS last_time,
                
                u.username AS last_sender_name,
                COALESCE(unread.unread_count, 0) AS unread_count,
                COALESCE(rm.is_pinned, 0) AS is_pinned,
                
                other_user.username AS other_user_name
                
            FROM chat_rooms cr
            
            INNER JOIN room_members rm ON cr.id = rm.room_id AND rm.user_id = :user_id_1
            
            LEFT JOIN (
                SELECT m1.room_id, m1.content, m1.sent_at, m1.sender_id, m1.type
                FROM messages m1
                INNER JOIN (
                    SELECT room_id, MAX(sent_at) AS max_sent_at, MAX(id) AS max_id
                    FROM messages
                    GROUP BY room_id
                ) m2 ON m1.room_id = m2.room_id AND m1.sent_at = m2.max_sent_at AND m1.id = m2.max_id
            ) latest_msg ON cr.id = latest_msg.room_id
            
            LEFT JOIN users u ON latest_msg.sender_id = u.id
            
            LEFT JOIN (
                SELECT room_id, COUNT(*) as unread_count
                FROM messages
                WHERE is_read = 0 AND sender_id != :user_id_2
                GROUP BY room_id
            ) unread ON cr.id = unread.room_id
            
            LEFT JOIN (
                SELECT rm2.room_id, u2.username
                FROM room_members rm2
                INNER JOIN users u2 ON rm2.user_id = u2.id
                WHERE rm2.user_id != :user_id_4
            ) other_user ON cr.id = other_user.room_id AND cr.type = 'private'
            
            WHERE 1=1
        ";

        // Áp dụng filter
        if ($filterType === 'unread') {
            $sql .= " AND EXISTS (
                SELECT 1 FROM messages m 
                WHERE m.room_id = cr.id AND m.is_read = 0 AND m.sender_id != :user_id_3
            )";
        } elseif ($filterType === 'groups') {
            $sql .= " AND cr.type = 'group'";
        }

        $sql .= " ORDER BY COALESCE(rm.is_pinned, 0) DESC, COALESCE(latest_msg.sent_at, cr.created_at) DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id_1', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id_2', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id_4', $userId, PDO::PARAM_INT);
        
        if ($filterType === 'unread') {
            $stmt->bindValue(':user_id_3', $userId, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format dữ liệu
        foreach ($rooms as &$room) {
            $room['last_message_time_formatted'] = $room['last_time'] ?? '';
            
            if (!empty($room['last_message'])) {
                if ($room['last_message_type'] === 'text') {
                    $room['last_message_display'] = $this->truncate($room['last_message'], 50);
                } elseif ($room['last_message_type'] === 'image') {
                    $room['last_message_display'] = '📷 Hình ảnh';
                } else {
                    $room['last_message_display'] = '📎 Tệp đính kèm';
                }
            } else {
                $room['last_message_display'] = 'Chưa có tin nhắn';
            }
            
            // LOGIC MỚI: Nếu private → hiện tên người kia, nếu group → hiện tên phòng
            if ($room['room_type'] === 'private') {
                $room['room_name_display'] = $room['other_user_name'] ?? 'Unknown User';
            } else {
                $room['room_name_display'] = $room['room_name'] ?? 'Unnamed Room';
            }
            
            $room['avatar_letter'] = mb_substr($room['room_name_display'], 0, 1);
        }
        
        return $rooms;
    }

    /**
     * Lấy thông tin phòng theo ID
     */
    public function getRoomById(int $roomId): ?array
    {
        if ($roomId <= 0) return null;

        $sql = "SELECT * FROM chat_rooms WHERE id = :room_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->execute();
        
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        return $room ?: null;
    }

    /**
     * Lấy danh sách thành viên trong phòng
     */
    public function getRoomMembers(int $roomId): array
    {
        if ($roomId <= 0) return [];

        $sql = "
            SELECT u.id AS user_id, u.username, rm.joined_at
            FROM room_members rm
            INNER JOIN users u ON rm.user_id = u.id
            WHERE rm.room_id = :room_id
            ORDER BY rm.joined_at ASC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Kiểm tra user có trong phòng không
     */
    public function isUserInRoom(int $roomId, int $userId): bool
    {
        if ($roomId <= 0 || $userId <= 0) return false;

        $sql = "SELECT COUNT(*) as count FROM room_members WHERE room_id = :room_id AND user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($result['count'] > 0);
    }

    /**
     * Tạo phòng mới (với transaction)
     */
    public function create(string $roomName, string $type, int $userId): int
    {
        if (empty($roomName) || !in_array($type, ['private', 'group']) || $userId <= 0) {
            throw new \InvalidArgumentException('Dữ liệu không hợp lệ');
        }

        $this->db->beginTransaction();

        try {
            // Tạo phòng
            $sql = "INSERT INTO chat_rooms (name, type, created_at) VALUES (:name, :type, NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':name', $roomName, PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->execute();
            
            $roomId = (int)$this->db->lastInsertId();

            // Thêm user vào room_members
            $sql = "INSERT INTO room_members (room_id, user_id, joined_at) VALUES (:room_id, :user_id, NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $this->db->commit();
            return $roomId;
            
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw new \RuntimeException('Không thể tạo phòng: ' . $e->getMessage());
        }
    }

    /**
     * Tìm phòng 1-1 giữa 2 user
     */
    public function findPrivateRoom(int $userId1, int $userId2): ?int
    {
        if ($userId1 <= 0 || $userId2 <= 0) return null;

        $sql = "
            SELECT rm.room_id
            FROM room_members rm
            INNER JOIN chat_rooms cr ON rm.room_id = cr.id
            WHERE cr.type = 'private' AND rm.user_id IN (:user1, :user2)
            GROUP BY rm.room_id
            HAVING COUNT(DISTINCT rm.user_id) = 2
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user1', $userId1, PDO::PARAM_INT);
        $stmt->bindValue(':user2', $userId2, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['room_id'] : null;
    }

    /**
     * Tạo phòng 1-1 mới
     */
    public function createPrivateRoom(string $roomName, int $userId1, int $userId2): int
    {
        if ($userId1 <= 0 || $userId2 <= 0) {
            throw new \InvalidArgumentException('User ID không hợp lệ');
        }

        $this->db->beginTransaction();

        try {
            $sql = "INSERT INTO chat_rooms (name, type, created_at) VALUES (:name, 'private', NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':name', $roomName, PDO::PARAM_STR);
            $stmt->execute();
            
            $roomId = (int)$this->db->lastInsertId();

            $sqlMember = "INSERT INTO room_members (room_id, user_id, joined_at) VALUES (:room_id, :user_id, NOW())";
            $stmtMember = $this->db->prepare($sqlMember);
            
            $stmtMember->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmtMember->bindValue(':user_id', $userId1, PDO::PARAM_INT);
            $stmtMember->execute();
            
            $stmtMember->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmtMember->bindValue(':user_id', $userId2, PDO::PARAM_INT);
            $stmtMember->execute();

            $this->db->commit();
            return $roomId;
            
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw new \RuntimeException('Không thể tạo phòng 1-1: ' . $e->getMessage());
        }
    }

    /**
     * Thêm thành viên vào phòng
     */
    public function addMemberToRoom(int $roomId, int $userId): bool
    {
        if ($roomId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('ID không hợp lệ');
        }

        if ($this->isUserInRoom($roomId, $userId)) {
            return true;
        }

        $sql = "INSERT INTO room_members (room_id, user_id, joined_at) VALUES (:room_id, :user_id, NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    /**
     * Xóa user khỏi phòng
     */
    public function removeUserFromRoom(int $roomId, int $userId): bool
    {
        if ($roomId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('ID không hợp lệ');
        }

        if (!$this->isUserInRoom($roomId, $userId)) {
            throw new \RuntimeException('User không phải thành viên');
        }

        $sql = "DELETE FROM room_members WHERE room_id = :room_id AND user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        
        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    /**
     * Toggle ghim phòng
     */
    public function togglePinRoom(int $roomId, int $userId): array
    {
        if ($roomId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('ID không hợp lệ');
        }

        if (!$this->isUserInRoom($roomId, $userId)) {
            throw new \RuntimeException('User không phải thành viên');
        }

        // Lấy trạng thái hiện tại
        $sql = "SELECT COALESCE(is_pinned, 0) as is_pinned FROM room_members WHERE room_id = :room_id AND user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            throw new \RuntimeException('Không tìm thấy bản ghi');
        }
        
        $newPinned = $result['is_pinned'] == 1 ? 0 : 1;

        // Cập nhật
        $sql = "UPDATE room_members SET is_pinned = :is_pinned WHERE room_id = :room_id AND user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':is_pinned', $newPinned, PDO::PARAM_INT);
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return ['is_pinned' => ($newPinned === 1)];
    }

    /**
     * Helper: Rút gọn text
     */
    private function truncate(string $text, int $maxLength = 50): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return mb_substr($text, 0, $maxLength) . '...';
    }
}
