<?php

/**
 * ============================================
 * CLASS: MessageRepository
 * ============================================
 * Repository Pattern - Xử lý tất cả thao tác database liên quan đến tin nhắn
 * Tách biệt logic database khỏi controller (Separation of Concerns)
 * 
 * Responsibilities:
 * - Lấy danh sách tin nhắn theo phòng
 * - Lưu tin nhắn mới vào database
 * - Validate dữ liệu trước khi lưu
 * 
 * Security:
 * - Sử dụng PDO Prepared Statement để chống SQL Injection
 * - Validate tất cả input trước khi query
 * 
 * @package App\Repositories
 * @author Senior Fullstack Developer
 */
class MessageRepository 
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
     * Lấy singleton instance của Database class
     * Kiểm tra kết nối hợp lệ trước khi sử dụng
     * 
     * @throws \RuntimeException Nếu không thể kết nối database
     */
    public function __construct() 
    {
        $this->initLogger();
        $this->log('REPO_INIT', 'MessageRepository đang khởi tạo...');
        
        try {
            // Lấy singleton instance của Database
            $dbInstance = Database::getInstance();
            
            // Kiểm tra instance có hợp lệ không
            if ($dbInstance === null) {
                throw new \RuntimeException('Database::getInstance() trả về NULL');
            }

            // Kiểm tra có phải PDO object không
            if (!($dbInstance instanceof PDO)) {
                throw new \RuntimeException(
                    'Database không phải PDO object. Kiểu thực tế: ' . gettype($dbInstance)
                );
            }

            $this->db = $dbInstance;

            // Test kết nối bằng query đơn giản
            $testQuery = $this->db->query('SELECT 1');
            if ($testQuery === false) {
                throw new \RuntimeException('Không thể thực thi query test');
            }

            $this->log('REPO_INIT_SUCCESS', 'Database đã kết nối thành công');
            
        } catch (\Throwable $e) {
            $this->log('REPO_INIT_ERROR', 'Lỗi khởi tạo: ' . $e->getMessage());
            throw new \RuntimeException(
                'MessageRepository không thể khởi tạo: ' . $e->getMessage()
            );
        }
    }

    /**
     * ============================================
     * METHOD: getMessagesByRoom
     * ============================================
     * Lấy danh sách tin nhắn trong một phòng chat
     * 
     * Query:
     * - JOIN với bảng users để lấy username
     * - ORDER BY sent_at ASC (tin nhắn cũ nhất lên đầu)
     * - LIMIT để giới hạn số lượng tin nhắn
     * 
     * @param int $roomId ID của phòng chat
     * @param int $limit Số lượng tin nhắn tối đa (mặc định 50)
     * @return array Mảng tin nhắn (mỗi phần tử là associative array)
     * @throws \InvalidArgumentException Nếu tham số không hợp lệ
     * @throws \RuntimeException Nếu query thất bại
     */
    /**
     * Đúng tên theo yêu cầu cô: findByRoom()
     * Trả về array of TextMessage/FileMessage objects
     */
    public function findByRoom(int $roomId, int $limit = 50): array
    {
        try {
            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID phải lớn hơn 0');
            }
            if ($limit <= 0 || $limit > 1000) {
                throw new \InvalidArgumentException('Limit phải trong khoảng 1-1000');
            }

            $this->log('FIND_BY_ROOM_START', "roomId={$roomId}, limit={$limit}");

            $sql = "SELECT m.*, u.username
                    FROM messages m
                    JOIN users u ON m.sender_id = u.id
                    WHERE m.room_id = :room_id
                    ORDER BY m.sent_at ASC
                    LIMIT :limit";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':limit',   $limit,  PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Chuyển raw array → TextMessage / FileMessage objects
            $messages = [];
            foreach ($rows as $row) {
                $sender  = new User((int)$row['sender_id'], $row['username']);
                $sentAt  = new DateTime($row['sent_at']);

                if ($row['type'] === 'text') {
                    $messages[] = new TextMessage((int)$row['id'], $sender, $row['content'], $sentAt);
                } else {
                    $messages[] = new FileMessage((int)$row['id'], $sender, $row['content'], $row['type'], $sentAt);
                }
            }

            $this->log('FIND_BY_ROOM_SUCCESS', 'Lấy được ' . count($messages) . ' tin nhắn');
            return $messages;

        } catch (\InvalidArgumentException $e) {
            $this->log('FIND_BY_ROOM_VALIDATION_ERROR', $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->log('FIND_BY_ROOM_ERROR', $e->getMessage());
            throw new \RuntimeException('findByRoom() thất bại: ' . $e->getMessage());
        }
    }

    /**
     * Giữ lại getMessagesByRoom() để không làm vỡ các chỗ khác đang gọi
     * Gọi lại findByRoom() bên trong
     */
    public function getMessagesByRoom(int $roomId, int $limit = 50): array
    {
        return $this->findByRoom($roomId, $limit);
    }

    /**
     * ============================================
     * METHOD: saveMessage
     * ============================================
     * Lưu tin nhắn mới vào database
     * 
     * Security:
     * - Sử dụng PDO Prepared Statement (chống SQL Injection)
     * - Validate tất cả input trước khi lưu
     * - Sanitize content để tránh XSS
     * 
     * Flow:
     * 1. Validate input (room_id, sender_id, content)
     * 2. Prepare SQL statement
     * 3. Bind parameters với kiểu dữ liệu cụ thể
     * 4. Execute query
     * 5. Trả về ID của tin nhắn vừa lưu
     * 
     * @param int $roomId ID của phòng chat
     * @param int $senderId ID của người gửi
     * @param string $content Nội dung tin nhắn hoặc đường dẫn file
     * @param string $type Loại tin nhắn: 'text', 'image', 'file'
     * @return int ID của tin nhắn vừa được lưu
     * @throws \InvalidArgumentException Nếu dữ liệu không hợp lệ
     * @throws \RuntimeException Nếu không thể lưu tin nhắn
     */
    public function saveMessage(int $roomId, int $senderId, string $content, string $type = 'text'): int 
    {
        try {
            $this->log('SAVE_MESSAGE_START', "roomId={$roomId}, senderId={$senderId}, type={$type}");

            // ============================================
            // BƯỚC 1: VALIDATE INPUT
            // ============================================
            $errors = [];

            if ($roomId <= 0) {
                $errors[] = 'Room ID phải lớn hơn 0';
            }

            if ($senderId <= 0) {
                $errors[] = 'Sender ID phải lớn hơn 0';
            }

            if (empty($content)) {
                $errors[] = 'Nội dung tin nhắn không được rỗng';
            }

            if (strlen($content) > 5000) {
                $errors[] = 'Nội dung tin nhắn quá dài (tối đa 5000 ký tự)';
            }

            // Validate type
            if (!in_array($type, ['text', 'image', 'file'])) {
                $errors[] = 'Type không hợp lệ (chỉ chấp nhận: text, image, file)';
            }

            if (!empty($errors)) {
                throw new \InvalidArgumentException(implode(', ', $errors));
            }

            // Sanitize content (chỉ với text, không sanitize đường dẫn file)
            if ($type === 'text') {
                $content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
            }

            // ============================================
            // BƯỚC 2: KIỂM TRA KẾT NỐI DATABASE
            // ============================================
            if (!isset($this->db)) {
                throw new \RuntimeException('Database connection không tồn tại');
            }

            // ============================================
            // BƯỚC 3: PREPARE SQL STATEMENT
            // ============================================
            $sql = "INSERT INTO messages (room_id, sender_id, content, type, sent_at) 
                    VALUES (:room_id, :sender_id, :content, :type, NOW())";
            
            $this->log('SAVE_MESSAGE_SQL', $sql);
            
            $stmt = $this->db->prepare($sql);
            
            if ($stmt === false) {
                $errorInfo = $this->db->errorInfo();
                throw new \RuntimeException(
                    'Không thể prepare statement. PDO Error: ' . 
                    'SQLSTATE[' . $errorInfo[0] . '] ' . 
                    'Code: ' . $errorInfo[1] . ' ' . 
                    'Message: ' . $errorInfo[2]
                );
            }

            // ============================================
            // BƯỚC 4: BIND PARAMETERS
            // ============================================
            // Bind với kiểu dữ liệu cụ thể để tăng bảo mật
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':sender_id', $senderId, PDO::PARAM_INT);
            $stmt->bindValue(':content', $content, PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);

            $this->log('SAVE_MESSAGE_PARAMS', json_encode([
                'room_id' => $roomId,
                'sender_id' => $senderId,
                'content_length' => strlen($content),
                'type' => $type
            ]));

            // ============================================
            // BƯỚC 5: EXECUTE QUERY
            // ============================================
            $executed = $stmt->execute();

            if ($executed === false) {
                $errorInfo = $stmt->errorInfo();
                throw new \RuntimeException(
                    'Không thể execute statement. SQL Error: ' . 
                    'SQLSTATE[' . $errorInfo[0] . '] ' . 
                    'Code: ' . $errorInfo[1] . ' ' . 
                    'Message: ' . $errorInfo[2]
                );
            }

            // ============================================
            // BƯỚC 6: LẤY ID CỦA TIN NHẮN VỪA LƯU
            // ============================================
            $lastInsertId = (int)$this->db->lastInsertId();

            if ($lastInsertId <= 0) {
                throw new \RuntimeException('Không thể lấy ID của tin nhắn vừa lưu');
            }

            $rowCount = $stmt->rowCount();

            $this->log('SAVE_MESSAGE_SUCCESS', "Inserted ID: {$lastInsertId}, Rows affected: {$rowCount}");

            // Trả về ID của tin nhắn vừa lưu
            return $lastInsertId;

        } catch (\InvalidArgumentException $e) {
            // Re-throw validation error
            $this->log('SAVE_MESSAGE_VALIDATION_ERROR', $e->getMessage());
            throw $e;

        } catch (\PDOException $e) {
            // Bắt lỗi PDO cụ thể
            $errorDetails = [
                'type' => 'PDOException',
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'sqlstate' => $e->errorInfo[0] ?? 'N/A',
                'driver_code' => $e->errorInfo[1] ?? 'N/A',
                'driver_message' => $e->errorInfo[2] ?? 'N/A'
            ];

            $this->log('SAVE_MESSAGE_PDO_ERROR', json_encode($errorDetails));

            throw new \RuntimeException(
                'Lỗi database khi lưu tin nhắn: ' . $e->getMessage()
            );

        } catch (\Throwable $e) {
            // Bắt mọi lỗi khác
            $this->log('SAVE_MESSAGE_ERROR', $e->getMessage());

            throw new \RuntimeException(
                'Không thể lưu tin nhắn: ' . $e->getMessage()
            );
        }
    }

    /**
     * ============================================
     * METHOD: getMessageById (BONUS)
     * ============================================
     * Lấy thông tin chi tiết của một tin nhắn theo ID
     * 
     * @param int $messageId ID của tin nhắn
     * @return array|null Thông tin tin nhắn hoặc null nếu không tìm thấy
     */
    public function getMessageById(int $messageId): ?array
    {
        try {
            if ($messageId <= 0) {
                throw new \InvalidArgumentException('Message ID phải lớn hơn 0');
            }

            $sql = "SELECT m.*, u.username 
                    FROM messages m 
                    JOIN users u ON m.sender_id = u.id 
                    WHERE m.id = :message_id 
                    LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':message_id', $messageId, PDO::PARAM_INT);
            $stmt->execute();
            
            $message = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $message ?: null;

        } catch (\Throwable $e) {
            $this->log('GET_MESSAGE_BY_ID_ERROR', $e->getMessage());
            return null;
        }
    }

    /**
     * ============================================
     * METHOD: deleteMessage (BONUS)
     * ============================================
     * Xóa một tin nhắn theo ID
     * 
     * @param int $messageId ID của tin nhắn cần xóa
     * @return bool True nếu xóa thành công, False nếu thất bại
     */
    public function deleteMessage(int $messageId): bool
    {
        try {
            if ($messageId <= 0) {
                throw new \InvalidArgumentException('Message ID phải lớn hơn 0');
            }

            $sql = "DELETE FROM messages WHERE id = :message_id LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':message_id', $messageId, PDO::PARAM_INT);
            $executed = $stmt->execute();
            
            $rowCount = $stmt->rowCount();
            
            $this->log('DELETE_MESSAGE', "Deleted message ID: {$messageId}, Rows affected: {$rowCount}");
            
            return $executed && $rowCount > 0;

        } catch (\Throwable $e) {
            $this->log('DELETE_MESSAGE_ERROR', $e->getMessage());
            return false;
        }
    }

    /**
     * ============================================
     * METHOD: markRoomAsRead
     * ============================================
     * ĐÁNH DẤU TẤT CẢ TIN NHẮN TRONG PHÒNG LÀ ĐÃ ĐỌC
     * 
     * 📚 MỤC ĐÍCH:
     * - Khi user vào phòng chat, tất cả tin nhắn của NGƯỜI KHÁC sẽ được đánh dấu đã đọc
     * - Chỉ đánh dấu tin nhắn mà sender_id != currentUserId (không đánh dấu tin của chính mình)
     * - Xóa badge "Unread" trên sidebar ngay lập tức
     * 
     * 🔄 LUỒNG HOẠT ĐỘNG:
     * 1. User click vào phòng chat
     * 2. Controller::index() được gọi
     * 3. Controller gọi markRoomAsRead($roomId, $currentUserId)
     * 4. Repository UPDATE messages SET is_read = 1 WHERE room_id = X AND sender_id != Y
     * 5. Tất cả tin nhắn của người khác trong phòng này được đánh dấu đã đọc
     * 6. Badge "Unread" biến mất
     * 
     * 💡 TẠI SAO KHÔNG ĐÁNH DẤU TIN NHẮN CỦA CHÍNH MÌNH?
     * - Tin nhắn của chính mình luôn được coi là "đã đọc" (vì mình vừa gửi)
     * - Chỉ cần đánh dấu tin nhắn của NGƯỜI KHÁC thôi
     * - Điều kiện: sender_id != :user_id
     * 
     * 🔐 BẢO MẬT:
     * - Sử dụng PDO Prepared Statement chống SQL Injection
     * - Validate roomId và userId phải là số nguyên dương
     * 
     * 📝 VẤN ĐÁP:
     * "Hàm markRoomAsRead() đánh dấu tất cả tin nhắn của người khác trong phòng là đã đọc.
     * Em dùng UPDATE với điều kiện sender_id != :user_id để chỉ đánh dấu tin của người khác,
     * không đánh dấu tin của chính mình. Hàm này được gọi ngay khi user vào phòng."
     * 
     * @param int $roomId ID của phòng chat
     * @param int $currentUserId ID của user hiện tại
     * @return bool True nếu thành công, False nếu thất bại
     * @throws \InvalidArgumentException Nếu tham số không hợp lệ
     * @throws \RuntimeException Nếu query thất bại
     */
    /**
     * Đúng tên theo yêu cầu cô: markAsRead()
     */
    public function markAsRead(int $msgId, int $userId): void
    {
        try {
            if ($msgId <= 0 || $userId <= 0) {
                throw new \InvalidArgumentException('msgId và userId phải lớn hơn 0');
            }

            $sql = "UPDATE messages SET is_read = 1 WHERE id = :msg_id AND sender_id != :user_id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':msg_id',  $msgId,  PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $this->log('MARK_AS_READ', "msgId={$msgId}, userId={$userId}");
        } catch (\Throwable $e) {
            $this->log('MARK_AS_READ_ERROR', $e->getMessage());
            throw new \RuntimeException('markAsRead() thất bại: ' . $e->getMessage());
        }
    }

    /**
     * Giữ lại markRoomAsRead() để không làm vỡ các chỗ khác đang gọi
     */
    public function markRoomAsRead(int $roomId, int $currentUserId): bool
    {
        try {
            // ============================================
            // BƯỚC 1: VALIDATE INPUT
            // ============================================
            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID phải lớn hơn 0');
            }

            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID phải lớn hơn 0');
            }

            $this->log('MARK_AS_READ_START', "roomId={$roomId}, userId={$currentUserId}");

            // ============================================
            // BƯỚC 2: CHUẨN BỊ SQL QUERY
            // ============================================
            // UPDATE tất cả tin nhắn trong phòng này
            // Chỉ đánh dấu tin nhắn của NGƯỜI KHÁC (sender_id != currentUserId)
            // Set is_read = 1 (đã đọc)
            $sql = "UPDATE messages 
                    SET is_read = 1 
                    WHERE room_id = :room_id 
                      AND sender_id != :user_id 
                      AND is_read = 0";
            
            // ============================================
            // BƯỚC 3: PREPARE STATEMENT
            // ============================================
            $stmt = $this->db->prepare($sql);
            
            if ($stmt === false) {
                $errorInfo = $this->db->errorInfo();
                throw new \RuntimeException(
                    'Không thể prepare query: ' . json_encode($errorInfo)
                );
            }

            // ============================================
            // BƯỚC 4: BIND PARAMETERS
            // ============================================
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $currentUserId, PDO::PARAM_INT);

            // ============================================
            // BƯỚC 5: EXECUTE QUERY
            // ============================================
            $executed = $stmt->execute();

            if (!$executed) {
                $errorInfo = $stmt->errorInfo();
                throw new \RuntimeException(
                    'Không thể execute query: ' . json_encode($errorInfo)
                );
            }
            
            // ============================================
            // BƯỚC 6: LẤY SỐ DÒNG BỊ ẢNH HƯỞNG
            // ============================================
            $rowCount = $stmt->rowCount();
            
            $this->log('MARK_AS_READ_SUCCESS', "Đã đánh dấu {$rowCount} tin nhắn là đã đọc");
            
            return true;

        } catch (\InvalidArgumentException $e) {
            $this->log('MARK_AS_READ_VALIDATION_ERROR', $e->getMessage());
            throw $e;

        } catch (\Throwable $e) {
            $this->log('MARK_AS_READ_ERROR', $e->getMessage());
            throw new \RuntimeException(
                'markRoomAsRead() thất bại: ' . $e->getMessage()
            );
        }
    }

    /**
     * ============================================
     * METHOD: getMessagesAfterId
     * ============================================
     * LẤY TIN NHẮN MỚI SAU MỘT ID CỤ THỂ (CHO POLLING)
     * 
     * 📚 MỤC ĐÍCH:
     * - Hàm này được thiết kế cho tính năng POLLING (Long Polling / Short Polling)
     * - Frontend sẽ gọi API mỗi 3-5 giây để kiểm tra tin nhắn mới
     * - Chỉ lấy những tin nhắn có ID lớn hơn lastId (tin nhắn mới hơn)
     * 
     * 🔄 LUỒNG HOẠT ĐỘNG:
     * 1. Frontend lưu ID của tin nhắn cuối cùng đã hiển thị (lastMessageId)
     * 2. Mỗi 3s, frontend gọi API: getNewMessages?room_id=1&last_id=100
     * 3. Backend query database: SELECT * FROM messages WHERE room_id=1 AND id > 100
     * 4. Trả về mảng tin nhắn mới (nếu có)
     * 5. Frontend append tin nhắn mới vào giao diện
     * 
     * 🎯 TƯƠNG TÁC VỚI CONTROLLER:
     * - Controller (ChatController::getNewMessages) sẽ gọi hàm này
     * - Controller nhận $roomId và $lastId từ $_GET
     * - Controller truyền 2 tham số này vào hàm getMessagesAfterId()
     * - Repository trả về mảng tin nhắn mới
     * - Controller format thành JSON và trả về cho frontend
     * 
     * 💡 TẠI SAO KHÔNG DÙNG WEBSOCKET?
     * - WebSocket phức tạp hơn, cần server hỗ trợ (Node.js, Socket.io)
     * - Polling đơn giản, dễ implement với PHP thuần
     * - Phù hợp với quy mô nhỏ, ít người dùng đồng thời
     * 
     * ⚠️ HẠN CHẾ CỦA POLLING:
     * - Tốn băng thông (gọi API liên tục dù không có tin nhắn mới)
     * - Độ trễ 3-5 giây (không realtime 100%)
     * - Tăng tải server khi có nhiều user
     * 
     * 🔐 BẢO MẬT:
     * - Sử dụng PDO Prepared Statement chống SQL Injection
     * - Validate roomId và lastId phải là số nguyên dương
     * - JOIN với bảng users để lấy username (tránh lộ thông tin nhạy cảm)
     * 
     * @param int $roomId ID của phòng chat cần lấy tin nhắn
     * @param int $lastId ID của tin nhắn cuối cùng mà client đã có
     * @return array Mảng tin nhắn mới (mỗi phần tử là associative array)
     * @throws \InvalidArgumentException Nếu tham số không hợp lệ
     * @throws \RuntimeException Nếu query thất bại
     */
    public function getMessagesAfterId(int $roomId, int $lastId): array
    {
        try {
            // ============================================
            // BƯỚC 1: VALIDATE INPUT
            // ============================================
            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID phải lớn hơn 0');
            }

            if ($lastId < 0) {
                throw new \InvalidArgumentException('Last ID không được âm');
            }

            $this->log('GET_NEW_MESSAGES_START', "roomId={$roomId}, lastId={$lastId}");

            // ============================================
            // BƯỚC 2: CHUẨN BỊ SQL QUERY
            // ============================================
            // Query lấy tin nhắn mới hơn lastId
            // JOIN với bảng users để lấy username
            // ORDER BY id ASC để tin nhắn cũ hiển thị trước
            $sql = "SELECT m.id, m.room_id, m.sender_id, m.content, m.type, m.sent_at, u.username 
                    FROM messages m 
                    JOIN users u ON m.sender_id = u.id 
                    WHERE m.room_id = :room_id 
                      AND m.id > :last_id 
                    ORDER BY m.id ASC 
                    LIMIT 50";
            
            // ============================================
            // BƯỚC 3: PREPARE STATEMENT
            // ============================================
            $stmt = $this->db->prepare($sql);
            
            if ($stmt === false) {
                $errorInfo = $this->db->errorInfo();
                throw new \RuntimeException(
                    'Không thể prepare query: ' . json_encode($errorInfo)
                );
            }

            // ============================================
            // BƯỚC 4: BIND PARAMETERS
            // ============================================
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':last_id', $lastId, PDO::PARAM_INT);

            // ============================================
            // BƯỚC 5: EXECUTE QUERY
            // ============================================
            $executed = $stmt->execute();

            if (!$executed) {
                $errorInfo = $stmt->errorInfo();
                throw new \RuntimeException(
                    'Không thể execute query: ' . json_encode($errorInfo)
                );
            }
            
            // ============================================
            // BƯỚC 6: FETCH KẾT QUẢ
            // ============================================
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->log('GET_NEW_MESSAGES_SUCCESS', 'Lấy được ' . count($messages) . ' tin nhắn mới');
            
            return $messages;

        } catch (\InvalidArgumentException $e) {
            $this->log('GET_NEW_MESSAGES_VALIDATION_ERROR', $e->getMessage());
            throw $e;

        } catch (\Throwable $e) {
            $this->log('GET_NEW_MESSAGES_ERROR', $e->getMessage());
            throw new \RuntimeException(
                'getMessagesAfterId() thất bại: ' . $e->getMessage()
            );
        }
    }
}
