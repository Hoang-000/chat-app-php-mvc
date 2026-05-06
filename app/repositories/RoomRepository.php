<?php

/**
 * ============================================
 * CLASS: RoomRepository
 * ============================================
 * Repository Pattern - Xử lý tất cả thao tác database liên quan đến phòng chat
 * 
 * Schema Database:
 * - Bảng: chat_rooms (id, name, type, created_at)
 * - Bảng: room_members (room_id, user_id, joined_at)
 * - Bảng: messages (id, room_id, sender_id, content, type, sent_at, is_read)
 * 
 * Responsibilities:
 * - Lấy danh sách phòng mà user tham gia
 * - Lấy tin nhắn cuối cùng của mỗi phòng
 * - Format thời gian tin nhắn
 * - Đếm số tin nhắn chưa đọc
 * 
 * @package App\Repositories
 * @author Senior Backend Developer
 */
class RoomRepository 
{
    use LoggerTrait;
    
    /**
     * PDO Database connection instance
     * 
     * @var PDO
     */
    private PDO $db;

    /**
     * Constructor - Khởi tạo kết nối database
     * 
     * @throws \RuntimeException Nếu không thể kết nối database
     */
    public function __construct() 
    {
        $this->initLogger();
        $this->log('ROOM_REPO_INIT', 'RoomRepository đang khởi tạo...');
        
        try {
            // Lấy singleton instance của Database
            $dbInstance = Database::getInstance();
            
            // Kiểm tra instance có hợp lệ không
            if ($dbInstance === null || !($dbInstance instanceof PDO)) {
                throw new \RuntimeException('Không thể kết nối database');
            }

            $this->db = $dbInstance;
            
            // Test kết nối bằng query đơn giản
            $testQuery = $this->db->query('SELECT 1');
            if ($testQuery === false) {
                throw new \RuntimeException('Không thể thực thi query test');
            }

            $this->log('ROOM_REPO_INIT_SUCCESS', 'Database đã kết nối thành công');
            
        } catch (\Throwable $e) {
            $this->log('ROOM_REPO_INIT_ERROR', 'Lỗi khởi tạo: ' . $e->getMessage());
            throw new \RuntimeException('RoomRepository không thể khởi tạo: ' . $e->getMessage());
        }
    }

    /**
     * ============================================
     * METHOD: getAllRooms - TÁI CẤU TRÚC HOÀN TOÀN (FIX LỖI 500 + LOGIC UNREAD)
     * ============================================
     * Lấy danh sách phòng chat với logic chuẩn chống lỗi PDO và filter unread chính xác
     * 
     * 🔴 VẤN ĐỀ ĐÃ FIX:
     * 1. Lỗi 500: PDO không cho phép bind cùng tên parameter nhiều lần
     * 2. Logic Unread sai: Hiện cả phòng không có tin mới hoặc tin do chính user gửi
     * 3. Duplicate: Trùng lặp phòng khi JOIN
     * 
     * ✅ GIẢI PHÁP:
     * 1. Dùng tên parameter riêng biệt: :user_id_1, :user_id_2, :user_id_3
     * 2. Logic Unread chuẩn: is_read = 0 AND sender_id != currentUserId
     * 3. Dùng WHERE EXISTS cho filter unread thay vì LEFT JOIN
     * 
     * @param int $userId ID của user đang login
     * @param string $filterType Loại filter: 'all', 'unread', 'groups'
     * @return array Mảng phòng chat
     * @throws \InvalidArgumentException Nếu userId không hợp lệ
     * @throws \RuntimeException Nếu có lỗi database
     */
    public function getAllRooms(int $userId, string $filterType = 'all'): array 
    {
        try {
            // ============================================
            // BƯỚC 1: VALIDATE INPUT
            // ============================================
            if ($userId <= 0) {
                throw new \InvalidArgumentException('User ID phải lớn hơn 0');
            }

            $this->log('GET_ALL_ROOMS_START', "User={$userId}, Filter={$filterType}");

            // ============================================
            // BƯỚC 2: XÂY DỰNG SQL QUERY - FIX LỖI PDO BINDING
            // ============================================
            // 🔥 QUAN TRỌNG: Mỗi lần dùng userId phải có tên parameter KHÁC NHAU
            // Không được dùng :user_id nhiều lần trong cùng 1 query
            $sql = "
                SELECT 
                    cr.id AS room_id,
                    cr.name AS room_name,
                    cr.type AS room_type,
                    cr.created_at AS room_created_at,
                    
                    -- Lấy nội dung tin nhắn cuối cùng
                    latest_msg.content AS last_message,
                    latest_msg.type AS last_message_type,
                    latest_msg.sender_id AS last_sender_id,
                    latest_msg.sent_at AS last_message_time,
                    
                    -- SMART TIME: Format thời gian thông minh
                    CASE 
                        WHEN DATE(latest_msg.sent_at) = CURDATE() THEN 
                            DATE_FORMAT(latest_msg.sent_at, '%H:%i')
                        ELSE 
                            DATE_FORMAT(latest_msg.sent_at, '%d/%m %H:%i')
                    END AS last_time,
                    
                    -- Tên người gửi tin nhắn cuối
                    u.username AS last_sender_name,
                    
                    -- UNREAD COUNT: Đếm tin nhắn chưa đọc (LOGIC CHUẨN)
                    -- CHỈ đếm tin: is_read = 0 VÀ sender_id != currentUserId
                    COALESCE(unread.unread_count, 0) AS unread_count,
                    
                    -- TRẠNG THÁI GHIM: Lấy từ room_members
                    COALESCE(rm.is_pinned, 0) AS is_pinned
                    
                FROM chat_rooms cr
                
                -- ============================================
                -- INNER JOIN: Chỉ lấy phòng mà user là thành viên
                -- ============================================
                -- 🔥 Dùng :user_id_1 (tên riêng biệt)
                INNER JOIN room_members rm 
                    ON cr.id = rm.room_id 
                    AND rm.user_id = :user_id_1
                
                -- ============================================
                -- LEFT JOIN: Lấy tin nhắn cuối cùng (CHỐNG DUPLICATE)
                -- ============================================
                LEFT JOIN (
                    SELECT 
                        m1.room_id, 
                        m1.content, 
                        m1.sent_at, 
                        m1.sender_id, 
                        m1.type
                    FROM messages m1
                    INNER JOIN (
                        SELECT room_id, MAX(sent_at) AS max_sent_at, MAX(id) AS max_id
                        FROM messages
                        GROUP BY room_id
                    ) m2 ON m1.room_id = m2.room_id 
                        AND m1.sent_at = m2.max_sent_at 
                        AND m1.id = m2.max_id
                ) latest_msg ON cr.id = latest_msg.room_id
                
                -- ============================================
                -- LEFT JOIN: Lấy tên người gửi
                -- ============================================
                LEFT JOIN users u ON latest_msg.sender_id = u.id
                
                -- ============================================
                -- LEFT JOIN: Đếm tin nhắn chưa đọc (LOGIC CHUẨN)
                -- ============================================
                -- 🔥 Dùng :user_id_2 (tên riêng biệt)
                -- LOGIC: is_read = 0 AND sender_id != currentUserId
                LEFT JOIN (
                    SELECT 
                        room_id, 
                        COUNT(*) as unread_count
                    FROM messages
                    WHERE is_read = 0 
                        AND sender_id != :user_id_2
                    GROUP BY room_id
                ) unread ON cr.id = unread.room_id
                
                WHERE 1=1
            ";

            // ============================================
            // BƯỚC 3: ÁP DỤNG FILTER - LOGIC CHUẨN
            // ============================================
            if ($filterType === 'unread') {
                // 🔥 LOGIC UNREAD CHUẨN:
                // Chỉ lấy phòng có ít nhất 1 tin nhắn thỏa mãn:
                // - is_read = 0 (chưa đọc)
                // - sender_id != currentUserId (không phải tin của mình)
                // 
                // Dùng WHERE EXISTS để đảm bảo chính xác
                // 🔥 Dùng :user_id_3 (tên riêng biệt)
                $sql .= "
                    AND EXISTS (
                        SELECT 1 
                        FROM messages m 
                        WHERE m.room_id = cr.id 
                            AND m.is_read = 0 
                            AND m.sender_id != :user_id_3
                    )
                ";
            } elseif ($filterType === 'groups') {
                // Filter 'groups': Chỉ lấy phòng nhóm
                $sql .= " AND cr.type = 'group'";
            }
            // Filter 'all': Không thêm điều kiện

            // ============================================
            // BƯỚC 4: SẮP XẾP KẾT QUẢ - ƯU TIÊN PHÒNG GHIM
            // ============================================
            // LOGIC: Phòng ghim (is_pinned=1) lên đầu, sau đó sắp xếp theo thời gian
            $sql .= "
                ORDER BY 
                    COALESCE(rm.is_pinned, 0) DESC,
                    COALESCE(latest_msg.sent_at, cr.created_at) DESC,
                    cr.id ASC
            ";
            
            // ============================================
            // BƯỚC 5: PREPARE QUERY
            // ============================================
            $stmt = $this->db->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException('Không thể prepare query');
            }

            // ============================================
            // BƯỚC 6: BIND PARAMETERS - FIX LỖI 500
            // ============================================
            // 🔥 QUAN TRỌNG: Bind từng parameter với tên RIÊNG BIỆT
            // Tránh lỗi: "Invalid parameter number: parameter was not defined"
            
            // Parameter 1: INNER JOIN room_members
            $stmt->bindValue(':user_id_1', $userId, PDO::PARAM_INT);
            
            // Parameter 2: LEFT JOIN unread count
            $stmt->bindValue(':user_id_2', $userId, PDO::PARAM_INT);
            
            // Parameter 3: WHERE EXISTS (chỉ bind khi filter = 'unread')
            if ($filterType === 'unread') {
                $stmt->bindValue(':user_id_3', $userId, PDO::PARAM_INT);
            }

            // ============================================
            // BƯỚC 7: EXECUTE QUERY
            // ============================================
            if (!$stmt->execute()) {
                throw new \RuntimeException('Không thể execute query');
            }
            
            $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // ============================================
            // BƯỚC 8: FORMAT DỮ LIỆU TRẢ VỀ
            // ============================================
            foreach ($rooms as &$room) {
                $room['last_message_time_formatted'] = $room['last_time'] ?? '';
                
                // Format nội dung tin nhắn
                if (!empty($room['last_message'])) {
                    if ($room['last_message_type'] === 'text') {
                        $room['last_message_display'] = $this->truncateMessage($room['last_message'], 50);
                    } elseif ($room['last_message_type'] === 'image') {
                        $room['last_message_display'] = '📷 Hình ảnh';
                    } elseif ($room['last_message_type'] === 'file') {
                        $room['last_message_display'] = '📎 Tệp đính kèm';
                    } else {
                        $room['last_message_display'] = $room['last_message'];
                    }
                } else {
                    $room['last_message_display'] = 'Chưa có tin nhắn';
                }
                
                // Format tên phòng
                if ($room['room_type'] === 'private' && empty($room['room_name'])) {
                    $room['room_name_display'] = 'Private Chat';
                } else {
                    $room['room_name_display'] = $room['room_name'] ?? 'Unnamed Room';
                }
                
                // Lấy chữ cái đầu tiên cho avatar
                $room['avatar_letter'] = mb_substr($room['room_name_display'], 0, 1);
            }
            
            $this->log('GET_ALL_ROOMS_SUCCESS', 'Lấy được ' . count($rooms) . ' phòng chat');
            
            return $rooms;

        } catch (\InvalidArgumentException $e) {
            $this->log('GET_ALL_ROOMS_VALIDATION_ERROR', $e->getMessage());
            throw $e;

        } catch (\Throwable $e) {
            $this->log('GET_ALL_ROOMS_ERROR', $e->getMessage());
            throw new \RuntimeException('getAllRooms() thất bại: ' . $e->getMessage());
        }
    }

    /**
     * ============================================
     * METHOD: getRoomById
     * ============================================
     * Lấy thông tin chi tiết một phòng chat theo ID
     * 
     * @param int $roomId ID của phòng chat
     * @return array|null Thông tin phòng hoặc null nếu không tìm thấy
     */
    public function getRoomById(int $roomId): ?array
    {
        try {
            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID phải lớn hơn 0');
            }

            // Query lấy thông tin phòng từ bảng chat_rooms
            $sql = "SELECT * FROM chat_rooms WHERE id = :room_id LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->execute();
            
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $room ?: null;

        } catch (\Throwable $e) {
            $this->log('GET_ROOM_BY_ID_ERROR', $e->getMessage());
            return null;
        }
    }

    /**
     * ============================================
     * METHOD: getRoomMembers
     * ============================================
     * Lấy danh sách thành viên trong một phòng chat
     * 
     * Logic JOIN phức tạp:
     * 1. Bảng room_members chứa quan hệ nhiều-nhiều giữa phòng và user
     * 2. INNER JOIN với bảng users để lấy thông tin chi tiết của từng thành viên
     * 3. Chỉ lấy thành viên của phòng cụ thể (WHERE room_id = ?)
     * 4. Sắp xếp theo thời gian tham gia (người tham gia sớm nhất lên đầu)
     * 
     * Schema liên quan:
     * - room_members (room_id, user_id, joined_at)
     * - users (id, username, email, password, created_at)
     * 
     * @param int $roomId ID của phòng chat
     * @return array Mảng thành viên với cấu trúc: [{user_id, username, joined_at}, ...]
     * @throws \InvalidArgumentException Nếu roomId không hợp lệ
     */
    public function getRoomMembers(int $roomId): array
    {
        try {
            // ============================================
            // BƯỚC 1: VALIDATE INPUT
            // ============================================
            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID phải lớn hơn 0');
            }

            $this->log('GET_ROOM_MEMBERS_START', "Lấy danh sách thành viên phòng {$roomId}");

            // ============================================
            // BƯỚC 2: XÂY DỰNG QUERY SQL VỚI INNER JOIN
            // ============================================
            // INNER JOIN đảm bảo chỉ lấy thành viên có tài khoản hợp lệ
            // Nếu dùng LEFT JOIN sẽ lấy cả thành viên bị xóa (không mong muốn)
            $sql = "
                SELECT 
                    -- Thông tin user từ bảng users
                    u.id AS user_id,              -- ID của user
                    u.username,                   -- Tên đăng nhập
                    
                    -- Thông tin membership từ bảng room_members
                    rm.joined_at                  -- Thời gian tham gia phòng
                    
                FROM room_members rm
                
                -- ============================================
                -- INNER JOIN: Lấy thông tin user từ bảng users
                -- ============================================
                -- INNER JOIN chỉ lấy bản ghi có khớp với nhau
                -- Nếu user bị xóa khỏi bảng users, sẽ không hiển thị
                INNER JOIN users u 
                    ON rm.user_id = u.id
                
                -- ============================================
                -- WHERE: Lọc theo phòng cụ thể
                -- ============================================
                WHERE rm.room_id = :room_id
                
                -- ============================================
                -- ORDER BY: Sắp xếp theo thời gian tham gia
                -- ============================================
                -- Người tham gia sớm nhất (admin/creator) lên đầu
                ORDER BY rm.joined_at ASC
            ";
            
            // ============================================
            // BƯỚC 3: PREPARE VÀ EXECUTE QUERY
            // ============================================
            $stmt = $this->db->prepare($sql);
            
            if ($stmt === false) {
                throw new \RuntimeException('Không thể prepare query getRoomMembers');
            }
            
            // Bind parameter room_id
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            
            // Execute query
            $executed = $stmt->execute();
            
            if (!$executed) {
                throw new \RuntimeException('Không thể execute query getRoomMembers');
            }
            
            // Fetch tất cả kết quả
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->log('GET_ROOM_MEMBERS_SUCCESS', "Lấy được " . count($members) . " thành viên");
            
            return $members;

        } catch (\InvalidArgumentException $e) {
            $this->log('GET_ROOM_MEMBERS_VALIDATION_ERROR', $e->getMessage());
            throw $e;
            
        } catch (\Throwable $e) {
            $this->log('GET_ROOM_MEMBERS_ERROR', $e->getMessage());
            return [];
        }
    }

    /**
     * ============================================
     * METHOD: isUserInRoom
     * ============================================
     * Kiểm tra xem user có phải thành viên của phòng không
     * 
     * @param int $roomId ID của phòng chat
     * @param int $userId ID của user
     * @return bool True nếu user là thành viên, False nếu không
     */
    public function isUserInRoom(int $roomId, int $userId): bool
    {
        try {
            if ($roomId <= 0 || $userId <= 0) {
                return false;
            }

            // Query kiểm tra membership
            $sql = "
                SELECT COUNT(*) as count
                FROM room_members
                WHERE room_id = :room_id AND user_id = :user_id
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return ($result['count'] > 0);

        } catch (\Throwable $e) {
            $this->log('IS_USER_IN_ROOM_ERROR', $e->getMessage());
            return false;
        }
    }

    /**
     * ============================================
     * PRIVATE METHOD: formatMessageTime
     * ============================================
     * Format thời gian tin nhắn theo dạng tương đối
     * 
     * Ví dụ:
     * - Vừa xong (< 1 phút)
     * - 5 phút trước
     * - 2 giờ trước
     * - Hôm qua
     * - 3 ngày trước
     * - 15/01/2024 (> 7 ngày)
     * 
     * @param string $datetime Thời gian dạng Y-m-d H:i:s
     * @return string Thời gian đã format
     */
    private function formatMessageTime(string $datetime): string
    {
        try {
            $timestamp = strtotime($datetime);
            $now = time();
            $diff = $now - $timestamp;

            // Vừa xong (< 1 phút)
            if ($diff < 60) {
                return 'Vừa xong';
            }

            // X phút trước (< 1 giờ)
            if ($diff < 3600) {
                $minutes = floor($diff / 60);
                return $minutes . ' phút trước';
            }

            // X giờ trước (< 24 giờ)
            if ($diff < 86400) {
                $hours = floor($diff / 3600);
                return $hours . ' giờ trước';
            }

            // Hôm qua
            if ($diff < 172800) {
                return 'Hôm qua';
            }

            // X ngày trước (< 7 ngày)
            if ($diff < 604800) {
                $days = floor($diff / 86400);
                return $days . ' ngày trước';
            }

            // Ngày cụ thể (> 7 ngày)
            return date('d/m/Y', $timestamp);

        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * ============================================
     * PRIVATE METHOD: truncateMessage
     * ============================================
     * Rút gọn tin nhắn nếu quá dài
     * 
     * @param string $message Nội dung tin nhắn
     * @param int $maxLength Độ dài tối đa
     * @return string Tin nhắn đã rút gọn
     */
    private function truncateMessage(string $message, int $maxLength = 50): string
    {
        if (mb_strlen($message) <= $maxLength) {
            return $message;
        }

        return mb_substr($message, 0, $maxLength) . '...';
    }

    /**
     * ============================================
     * METHOD: createRoom (BONUS)
     * ============================================
     * Tạo phòng chat mới
     * 
     * @param string|null $name Tên phòng (NULL nếu là private chat)
     * @param string $type Loại phòng ('private' hoặc 'group')
     * @param array $memberIds Mảng ID của các thành viên
     * @return int ID của phòng vừa tạo
     */
    public function createRoom(?string $name, string $type = 'group', array $memberIds = []): int
    {
        try {
            // Validate type
            if (!in_array($type, ['private', 'group'])) {
                throw new \InvalidArgumentException('Loại phòng không hợp lệ');
            }

            // Bắt đầu transaction
            $this->db->beginTransaction();

            // Tạo phòng mới trong bảng chat_rooms
            $sql = "INSERT INTO chat_rooms (name, type, created_at) 
                    VALUES (:name, :type, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->execute();
            
            $roomId = (int)$this->db->lastInsertId();

            // Thêm thành viên vào bảng room_members
            if (!empty($memberIds)) {
                $sqlMember = "INSERT INTO room_members (room_id, user_id, joined_at) 
                              VALUES (:room_id, :user_id, NOW())";
                $stmtMember = $this->db->prepare($sqlMember);

                foreach ($memberIds as $userId) {
                    $stmtMember->bindValue(':room_id', $roomId, PDO::PARAM_INT);
                    $stmtMember->bindValue(':user_id', $userId, PDO::PARAM_INT);
                    $stmtMember->execute();
                }
            }

            // Commit transaction
            $this->db->commit();
            
            $this->log('CREATE_ROOM_SUCCESS', "Tạo phòng mới: {$name} (ID: {$roomId})");
            
            return $roomId;

        } catch (\Throwable $e) {
            // Rollback nếu có lỗi
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->log('CREATE_ROOM_ERROR', $e->getMessage());
            throw new \RuntimeException('Không thể tạo phòng: ' . $e->getMessage());
        }
    }

    /**
     * ============================================
     * METHOD: create - TẠO PHÒNG MỚI VỚI TRANSACTION
     * ============================================
     * Tạo phòng chat mới và thêm user vào room_members
     * 
     * YÊU CẦU KHẮT KHE:
     * - Sử dụng Transaction để đảm bảo tính toàn vẹn dữ liệu
     * - BẮT BUỘC thêm user vào room_members sau khi tạo phòng
     * - Nếu có lỗi, rollback toàn bộ
     * 
     * LUỒNG XỬ LÝ:
     * 1. Validate input
     * 2. Bắt đầu transaction
     * 3. INSERT vào chat_rooms
     * 4. Lấy lastInsertId
     * 5. INSERT vào room_members (thêm user hiện tại)
     * 6. Commit transaction
     * 7. Trả về room_id
     * 
     * @param string $roomName Tên phòng
     * @param string $type Loại phòng ('private' hoặc 'group')
     * @param int $userId ID của user tạo phòng
     * @return int ID của phòng vừa tạo
     * @throws \InvalidArgumentException Nếu input không hợp lệ
     * @throws \RuntimeException Nếu có lỗi database
     */
    public function create(string $roomName, string $type, int $userId): int
    {
        try {
            // ============================================
            // BƯỚC 1: VALIDATE INPUT
            // ============================================
            if (empty($roomName)) {
                throw new \InvalidArgumentException('Tên phòng không được rỗng');
            }
            
            if (!in_array($type, ['private', 'group'])) {
                throw new \InvalidArgumentException('Loại phòng không hợp lệ');
            }
            
            if ($userId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $this->log('CREATE_ROOM_START', "Creating room: {$roomName}, type: {$type}, userId: {$userId}");

            // ============================================
            // BƯỚC 2: BẮT ĐẦU TRANSACTION
            // ============================================
            $this->db->beginTransaction();

            // ============================================
            // BƯỚC 3: INSERT VÀO BẢNG chat_rooms
            // ============================================
            $sqlRoom = "INSERT INTO chat_rooms (name, type, created_at) 
                        VALUES (:name, :type, NOW())";
            
            $stmtRoom = $this->db->prepare($sqlRoom);
            
            if ($stmtRoom === false) {
                throw new \RuntimeException('Không thể prepare query INSERT chat_rooms');
            }
            
            $stmtRoom->bindValue(':name', $roomName, PDO::PARAM_STR);
            $stmtRoom->bindValue(':type', $type, PDO::PARAM_STR);
            
            if (!$stmtRoom->execute()) {
                throw new \RuntimeException('Không thể INSERT vào chat_rooms');
            }

            // ============================================
            // BƯỚC 4: LẤY ID PHÒNG VỪA TẠO
            // ============================================
            $newRoomId = (int)$this->db->lastInsertId();
            
            if ($newRoomId <= 0) {
                throw new \RuntimeException('Không thể lấy lastInsertId');
            }

            $this->log('CREATE_ROOM_INSERTED', "Room ID: {$newRoomId}");

            // ============================================
            // BƯỚC 5: THÊM USER VÀO room_members (BẮT BUỘC)
            // ============================================
            $sqlMember = "INSERT INTO room_members (room_id, user_id, joined_at) 
                          VALUES (:room_id, :user_id, NOW())";
            
            $stmtMember = $this->db->prepare($sqlMember);
            
            if ($stmtMember === false) {
                throw new \RuntimeException('Không thể prepare query INSERT room_members');
            }
            
            $stmtMember->bindValue(':room_id', $newRoomId, PDO::PARAM_INT);
            $stmtMember->bindValue(':user_id', $userId, PDO::PARAM_INT);
            
            if (!$stmtMember->execute()) {
                throw new \RuntimeException('Không thể INSERT vào room_members');
            }

            $this->log('CREATE_ROOM_MEMBER_ADDED', "User {$userId} added to room {$newRoomId}");

            // ============================================
            // BƯỚC 6: COMMIT TRANSACTION
            // ============================================
            $this->db->commit();
            
            $this->log('CREATE_ROOM_SUCCESS', "Room {$newRoomId} created successfully");
            
            // ============================================
            // BƯỚC 7: TRẢ VỀ ROOM_ID
            // ============================================
            return $newRoomId;

        } catch (\Throwable $e) {
            // ============================================
            // ROLLBACK NẾU CÓ LỖI
            // ============================================
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
                $this->log('CREATE_ROOM_ROLLBACK', 'Transaction rolled back');
            }

            $this->log('CREATE_ROOM_ERROR', $e->getMessage());
            throw new \RuntimeException('Không thể tạo phòng: ' . $e->getMessage());
        }
    }

    /**
     * ============================================
     * METHOD: togglePinRoom - GHIM/BỞ GHIM PHÒNG
     * ============================================
     * Toggle trạng thái ghim phòng cho user
     * 
     * DATABASE LOGIC:
     * - Cập nhật cột is_pinned trong bảng room_members
     * - Nếu chưa có cột is_pinned, cần chạy migration:
     *   ALTER TABLE room_members ADD COLUMN is_pinned TINYINT(1) DEFAULT 0;
     * 
     * LUỒNG XỬ LÝ:
     * 1. Kiểm tra trạng thái hiện tại của is_pinned
     * 2. Toggle: Nếu đang ghim (1) thì bỏ ghim (0), và ngược lại
     * 3. UPDATE bảng room_members
     * 4. Trả về trạng thái mới
     * 
     * @param int $roomId ID của phòng chat
     * @param int $userId ID của user
     * @return array ['is_pinned' => bool] Trạng thái mới
     * @throws \InvalidArgumentException Nếu tham số không hợp lệ
     * @throws \RuntimeException Nếu có lỗi database
     */
    public function togglePinRoom(int $roomId, int $userId): array
    {
        try {
            // ============================================
            // BƯỚC 1: VALIDATE INPUT
            // ============================================
            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID phải lớn hơn 0');
            }
            
            if ($userId <= 0) {
                throw new \InvalidArgumentException('User ID phải lớn hơn 0');
            }

            $this->log('TOGGLE_PIN_ROOM_START', "roomId={$roomId}, userId={$userId}");

            // ============================================
            // BƯỚC 2: KIỂM TRA USER CÓ TRONG PHÒNG KHÔNG
            // ============================================
            if (!$this->isUserInRoom($roomId, $userId)) {
                throw new \RuntimeException('User không phải thành viên của phòng này');
            }

            // ============================================
            // BƯỚC 3: LẤY TRẠNG THÁI HIỆN TẠI
            // ============================================
            $sqlCheck = "
                SELECT COALESCE(is_pinned, 0) as is_pinned
                FROM room_members
                WHERE room_id = :room_id AND user_id = :user_id
            ";
            
            $stmtCheck = $this->db->prepare($sqlCheck);
            $stmtCheck->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmtCheck->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmtCheck->execute();
            
            $result = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                throw new \RuntimeException('Không tìm thấy bản ghi room_members');
            }
            
            $currentPinned = (int)$result['is_pinned'];
            $newPinned = $currentPinned === 1 ? 0 : 1; // Toggle

            $this->log('TOGGLE_PIN_ROOM_CURRENT', "Current: {$currentPinned}, New: {$newPinned}");

            // ============================================
            // BƯỚC 4: CẬP NHẬT TRẠNG THÁI MỚI
            // ============================================
            $sqlUpdate = "
                UPDATE room_members
                SET is_pinned = :is_pinned
                WHERE room_id = :room_id AND user_id = :user_id
            ";
            
            $stmtUpdate = $this->db->prepare($sqlUpdate);
            $stmtUpdate->bindValue(':is_pinned', $newPinned, PDO::PARAM_INT);
            $stmtUpdate->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmtUpdate->bindValue(':user_id', $userId, PDO::PARAM_INT);
            
            if (!$stmtUpdate->execute()) {
                throw new \RuntimeException('Không thể cập nhật is_pinned');
            }

            $this->log('TOGGLE_PIN_ROOM_SUCCESS', "Đã toggle pin: {$newPinned}");

            // ============================================
            // BƯỚC 5: TRẢ VỀ TRẠNG THÁI MỚI
            // ============================================
            return [
                'is_pinned' => ($newPinned === 1)
            ];

        } catch (\InvalidArgumentException $e) {
            $this->log('TOGGLE_PIN_ROOM_VALIDATION_ERROR', $e->getMessage());
            throw $e;

        } catch (\Throwable $e) {
            $this->log('TOGGLE_PIN_ROOM_ERROR', $e->getMessage());
            throw new \RuntimeException('togglePinRoom() thất bại: ' . $e->getMessage());
        }
    }

    /**
     * ============================================
     * METHOD: removeUserFromRoom - XÓA USER KHỎI PHÒNG
     * ============================================
     * Xóa user khỏi danh sách thành viên của phòng (leave room)
     * 
     * DATABASE LOGIC:
     * - Xóa bản ghi trong bảng room_members
     * - DELETE FROM room_members WHERE room_id = ? AND user_id = ?
     * - User sẽ không còn thấy phòng này trong sidebar
     * 
     * LƯU Ý:
     * - Không xóa phòng khỏi bảng chat_rooms
     * - Không xóa tin nhắn cũ của user
     * - Chỉ xóa quan hệ membership
     * 
     * LUỒNG XỬ LÝ:
     * 1. Validate input
     * 2. Kiểm tra user có trong phòng không
     * 3. DELETE bản ghi từ room_members
     * 4. Trả về thành công
     * 
     * @param int $roomId ID của phòng chat
     * @param int $userId ID của user
     * @return bool True nếu xóa thành công
     * @throws \InvalidArgumentException Nếu tham số không hợp lệ
     * @throws \RuntimeException Nếu có lỗi database
     */
    public function removeUserFromRoom(int $roomId, int $userId): bool
    {
        try {
            // ============================================
            // BƯỚC 1: VALIDATE INPUT
            // ============================================
            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID phải lớn hơn 0');
            }
            
            if ($userId <= 0) {
                throw new \InvalidArgumentException('User ID phải lớn hơn 0');
            }

            $this->log('REMOVE_USER_FROM_ROOM_START', "roomId={$roomId}, userId={$userId}");

            // ============================================
            // BƯỚC 2: KIỂM TRA USER CÓ TRONG PHÒNG KHÔNG
            // ============================================
            if (!$this->isUserInRoom($roomId, $userId)) {
                throw new \RuntimeException('User không phải thành viên của phòng này');
            }

            // ============================================
            // BƯỚC 3: XÓA BẢN GHI KHỎI room_members
            // ============================================
            $sql = "
                DELETE FROM room_members
                WHERE room_id = :room_id AND user_id = :user_id
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            
            if (!$stmt->execute()) {
                throw new \RuntimeException('Không thể xóa user khỏi phòng');
            }

            $rowsAffected = $stmt->rowCount();
            
            if ($rowsAffected === 0) {
                throw new \RuntimeException('Không có bản ghi nào bị xóa');
            }

            $this->log('REMOVE_USER_FROM_ROOM_SUCCESS', "Đã xóa user {$userId} khỏi phòng {$roomId}");

            // ============================================
            // BƯỚC 4: TRẢ VỀ THÀNH CÔNG
            // ============================================
            return true;

        } catch (\InvalidArgumentException $e) {
            $this->log('REMOVE_USER_FROM_ROOM_VALIDATION_ERROR', $e->getMessage());
            throw $e;

        } catch (\Throwable $e) {
            $this->log('REMOVE_USER_FROM_ROOM_ERROR', $e->getMessage());
            throw new \RuntimeException('removeUserFromRoom() thất bại: ' . $e->getMessage());
        }
    }

    /**
     * ============================================
     * METHOD: checkExistingPrivateChat - KIỂM TRA PHÒNG 1-1 ĐÃ TỒN TẠI
     * ============================================
     * Tìm phòng chat 1-1 giữa 2 user (alias của findPrivateRoom)
     * 
     * @param int $userId1 ID user thứ nhất
     * @param int $userId2 ID user thứ hai
     * @return int|null ID phòng nếu tìm thấy, null nếu không
     */
    public function checkExistingPrivateChat(int $userId1, int $userId2): ?int
    {
        return $this->findPrivateRoom($userId1, $userId2);
    }

    /**
     * ============================================
     * METHOD: findPrivateRoom - TÌM PHÒNG 1-1 ĐÃ TỒN TẠI
     * ============================================
     * Tìm phòng chat 1-1 giữa 2 user
     * 
     * LOGIC:
     * - Tìm room_id trong bảng room_members
     * - CHỈ CÓ ĐÚNG 2 thành viên là user1 và user2
     * - Phòng phải có type = 'private'
     * 
     * SQL STRATEGY:
     * - Dùng GROUP BY và HAVING COUNT = 2
     * - Đảm bảo cả 2 user đều có trong phòng
     * 
     * @param int $userId1 ID user thứ nhất
     * @param int $userId2 ID user thứ hai
     * @return int|null ID phòng nếu tìm thấy, null nếu không
     */
    public function findPrivateRoom(int $userId1, int $userId2): ?int
    {
        try {
            if ($userId1 <= 0 || $userId2 <= 0) {
                return null;
            }

            $this->log('FIND_PRIVATE_ROOM_START', "Tìm phòng 1-1: user1={$userId1}, user2={$userId2}");

            // SQL: Tìm room_id có đúng 2 thành viên là user1 và user2
            $sql = "
                SELECT rm.room_id
                FROM room_members rm
                INNER JOIN chat_rooms cr ON rm.room_id = cr.id
                WHERE cr.type = 'private'
                  AND rm.user_id IN (:user1, :user2)
                GROUP BY rm.room_id
                HAVING COUNT(DISTINCT rm.user_id) = 2
                LIMIT 1
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':user1', $userId1, PDO::PARAM_INT);
            $stmt->bindValue(':user2', $userId2, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $roomId = (int)$result['room_id'];
                $this->log('FIND_PRIVATE_ROOM_FOUND', "Tìm thấy phòng: {$roomId}");
                return $roomId;
            }
            
            $this->log('FIND_PRIVATE_ROOM_NOT_FOUND', 'Không tìm thấy phòng 1-1');
            return null;

        } catch (\Throwable $e) {
            $this->log('FIND_PRIVATE_ROOM_ERROR', $e->getMessage());
            return null;
        }
    }

    /**
     * ============================================
     * METHOD: createPrivateRoom - TẠO PHÒNG 1-1 MỚI
     * ============================================
     * Tạo phòng chat 1-1 giữa 2 user
     * 
     * LOGIC:
     * 1. Tạo phòng mới với type = 'private'
     * 2. Thêm cả 2 user vào room_members
     * 3. Sử dụng Transaction để đảm bảo tính toàn vẹn
     * 
     * @param string $roomName Tên phòng
     * @param int $userId1 ID user thứ nhất
     * @param int $userId2 ID user thứ hai
     * @return int ID phòng vừa tạo
     * @throws \RuntimeException Nếu có lỗi
     */
    public function createPrivateRoom(string $roomName, int $userId1, int $userId2): int
    {
        try {
            if ($userId1 <= 0 || $userId2 <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $this->log('CREATE_PRIVATE_ROOM_START', "Tạo phòng 1-1: {$roomName}, user1={$userId1}, user2={$userId2}");

            // Bắt đầu transaction
            $this->db->beginTransaction();

            // Tạo phòng mới
            $sql = "INSERT INTO chat_rooms (name, type, created_at) VALUES (:name, 'private', NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':name', $roomName, PDO::PARAM_STR);
            $stmt->execute();
            
            $roomId = (int)$this->db->lastInsertId();

            // Thêm user1 vào room_members
            $sqlMember = "INSERT INTO room_members (room_id, user_id, joined_at) VALUES (:room_id, :user_id, NOW())";
            $stmtMember = $this->db->prepare($sqlMember);
            
            $stmtMember->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmtMember->bindValue(':user_id', $userId1, PDO::PARAM_INT);
            $stmtMember->execute();
            
            // Thêm user2 vào room_members
            $stmtMember->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmtMember->bindValue(':user_id', $userId2, PDO::PARAM_INT);
            $stmtMember->execute();

            // Commit transaction
            $this->db->commit();
            
            $this->log('CREATE_PRIVATE_ROOM_SUCCESS', "Phòng {$roomId} đã được tạo");
            
            return $roomId;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->log('CREATE_PRIVATE_ROOM_ERROR', $e->getMessage());
            throw new \RuntimeException('Không thể tạo phòng 1-1: ' . $e->getMessage());
        }
    }

    /**
     * ============================================
     * METHOD: addMemberToRoom - THÊM THÀNH VIÊN VÀO PHÒNG
     * ============================================
     * Thêm user vào phòng chat
     * 
     * LOGIC:
     * - Kiểm tra user đã là thành viên chưa (tránh Duplicate Key)
     * - Nếu đã có: Trả về true luôn (không ném lỗi)
     * - Nếu chưa: INSERT vào room_members
     * 
     * @param int $roomId ID phòng
     * @param int $userId ID user
     * @return bool True nếu thành công
     * @throws \RuntimeException Nếu có lỗi
     */
    public function addMemberToRoom(int $roomId, int $userId): bool
    {
        try {
            if ($roomId <= 0 || $userId <= 0) {
                throw new \InvalidArgumentException('Room ID hoặc User ID không hợp lệ');
            }

            // Kiểm tra user đã là thành viên chưa
            if ($this->isUserInRoom($roomId, $userId)) {
                $this->log('ADD_MEMBER_SKIP', "User {$userId} đã là thành viên phòng {$roomId}");
                return true; // Trả về true luôn, không ném lỗi
            }

            $sql = "INSERT INTO room_members (room_id, user_id, joined_at) VALUES (:room_id, :user_id, NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            
            if (!$stmt->execute()) {
                throw new \RuntimeException('Không thể thêm thành viên');
            }

            $this->log('ADD_MEMBER_SUCCESS', "Đã thêm user {$userId} vào phòng {$roomId}");
            return true;

        } catch (\Throwable $e) {
            $this->log('ADD_MEMBER_ERROR', $e->getMessage());
            throw new \RuntimeException('addMemberToRoom() thất bại: ' . $e->getMessage());
        }
    }

}
