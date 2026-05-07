<?php

class ChatController extends Controller
{
    private MessageRepository $messageRepository;
    private RoomRepository $roomRepository;
    private UserRepository $userRepository;

    public function __construct(
        ?MessageRepository $messageRepository = null,
        ?RoomRepository $roomRepository = null,
        ?UserRepository $userRepository = null
    ) {
        parent::__construct();
        $this->messageRepository = $messageRepository ?? new MessageRepository();
        $this->roomRepository = $roomRepository ?? new RoomRepository();
        $this->userRepository = $userRepository ?? new UserRepository();
    }

    public function index(): void
    {
        try {
            // Lấy user_id từ SESSION (sau khi login)
            $currentUserId = $_SESSION['user_id'] ?? 1;
            
            // Nếu có user_id trong URL thì ưu tiên dùng nó (backward compatibility)
            if (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0) {
                $currentUserId = (int)$_GET['user_id'];
            }

            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 1;
            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID không hợp lệ');
            }
            
            // KIỂM TRA USER CÓ TRONG PHÒNG KHÔNG
            if (!$this->roomRepository->isUserInRoom($roomId, $currentUserId)) {
                // Redirect về phòng đầu tiên của user
                $rooms = $this->roomRepository->getAllRooms($currentUserId, 'all');
                if (!empty($rooms)) {
                    $firstRoom = reset($rooms);
                    header('Location: ' . URLROOT . '/index.php?controller=chat&action=index&room_id=' . $firstRoom['room_id']);
                    exit;
                } else {
                    throw new \RuntimeException('Bạn không có quyền truy cập phòng này và không có phòng nào khác');
                }
            }

            $filterType = $_GET['filter'] ?? 'all';
            if (!in_array($filterType, ['all', 'unread', 'groups'])) {
                throw new \InvalidArgumentException('Filter type không hợp lệ');
            }

            $this->messageRepository->markRoomAsRead($roomId, $currentUserId);
            $rooms = $this->roomRepository->getAllRooms($currentUserId, $filterType);
            $room = $this->roomRepository->getRoomById($roomId);
            
            // Lấy số thành viên
            $members = $this->roomRepository->getRoomMembers($roomId);
            $memberCount = count($members);
            
            // Xác định title: Nếu private → hiện tên người kia, nếu group → hiện tên phòng
            $title = $this->getRoomDisplayName($room, $members, $currentUserId);
            
            $messages = $this->messageRepository->findByRoom($roomId, 50);
            $decoratedMessages = $this->decorateMessages($messages, $room['type'] ?? 'private');

            $data = [
                'roomId'        => $roomId,
                'rooms'         => $rooms,
                'messages'      => $decoratedMessages,
                'title'         => $title,
                'currentUserId' => $currentUserId,
                'filterType'    => $filterType,
                'memberCount'   => $memberCount,
                'members'       => $members,
                'roomType'      => $room['type'] ?? 'private',
                'URLROOT'       => URLROOT,
            ];

            $this->view('chat/index', $data);

        } catch (\Exception $e) {
            http_response_code(500);
            echo "Đã xảy ra lỗi khi tải trang chat: " . htmlspecialchars($e->getMessage());
        }
    }
    
    /**
     * Lấy tên hiển thị của phòng: Nếu private → tên người kia, nếu group → tên phòng
     */
    private function getRoomDisplayName(?array $room, array $members, int $currentUserId): string
    {
        if (!$room) return 'Phòng chat';
        
        if ($room['type'] === 'private') {
            // Tìm thành viên không phải mình
            foreach ($members as $member) {
                if ((int)$member['user_id'] !== $currentUserId) {
                    return $member['username'] ?? 'Unknown User';
                }
            }
            return 'Private Chat';
        }
        
        return $room['name'] ?? 'Unnamed Room';
    }

    private function decorateMessages(array $messages, string $roomType = 'private'): array
    {
        $emojiDecorator = new EmojiDecorator();
        $mentionDecorator = new MentionDecorator();

        $result = [];
        foreach ($messages as $msg) {
            $content = $emojiDecorator->decorate($msg);
            
            // Chỉ áp dụng MentionDecorator cho GROUP chat
            if ($roomType === 'group') {
                $tempMsg = new TextMessage($msg->getId(), $msg->getSender(), $content, $msg->getSentAt());
                $content = $mentionDecorator->decorate($tempMsg);
            }
            
            $result[] = [
                'id'        => $msg->getId(),
                'sender_id' => $msg->getSender()->getId(),
                'username'  => $msg->getSender()->getName(),
                'content'   => $content,
                'type'      => $msg->getType(),
                'sent_at'   => $msg->getSentAt()->format('Y-m-d H:i:s'),
                'is_read'   => isset($msg->is_read) ? $msg->is_read : 0
            ];
        }
        return $result;
    }

    public function send(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'Chỉ chấp nhận POST request', 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $roomId = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
            $senderId = isset($_POST['sender_id']) ? (int)$_POST['sender_id'] : 0;
            $content = isset($_POST['content']) ? trim($_POST['content']) : '';
            $messageType = 'text';
            $filePath = null;

            $errors = [];
            if ($roomId <= 0) $errors[] = 'room_id không hợp lệ';
            if ($senderId <= 0) $errors[] = 'sender_id không hợp lệ';
            
            // KIỂM TRA USER CÓ TRONG PHÒNG KHÔNG
            if (!$this->roomRepository->isUserInRoom($roomId, $senderId)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Bạn không có quyền gửi tin trong phòng này', 'code' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['file'];
                $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if ($file['size'] > 10 * 1024 * 1024) {
                    $errors[] = 'File quá lớn (tối đa 10MB)';
                } else {
                    $messageType = in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif']) ? 'image' : 'file';
                    $uniqueFileName = time() . '_' . uniqid() . '.' . $fileExt;
                    $uploadPath = __DIR__ . '/../../public/uploads/' . $uniqueFileName;
                    
                    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                        $filePath = 'uploads/' . $uniqueFileName;
                        $content = $filePath;
                    } else {
                        $errors[] = 'Không thể lưu file';
                    }
                }
            } else {
                if (empty($content)) $errors[] = 'Nội dung tin nhắn không được rỗng';
                if (strlen($content) > 5000) $errors[] = 'Nội dung tin nhắn quá dài';
            }

            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ', 'errors' => $errors], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $messageId = $this->messageRepository->saveMessage($roomId, $senderId, $content, $messageType);
            
            // Lấy room type để quyết định có áp dụng MentionDecorator không
            $room = $this->roomRepository->getRoomById($roomId);
            $roomType = $room['type'] ?? 'private';
            
            // ÁP DỤNG DECORATORS cho content trước khi trả về
            $displayContent = $content;
            if ($messageType === 'text') {
                $emojiDecorator = new EmojiDecorator();
                $displayContent = str_replace(array_keys($emojiDecorator->getEmojiList()), array_values($emojiDecorator->getEmojiList()), $content);
                
                // Chỉ áp dụng MentionDecorator cho GROUP chat
                if ($roomType === 'group') {
                    $mentionDecorator = new MentionDecorator();
                    $displayContent = preg_replace_callback('/@([a-zA-Z0-9_]{3,20})/u', function ($m) {
                        $safe = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                        return sprintf('<a href="/profile/%s" class="mention">@%s</a>', $safe, $safe);
                    }, $displayContent);
                }
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'message' => 'Tin nhắn đã được gửi thành công',
                'data' => [
                    'id' => $messageId,
                    'message_id' => $messageId,
                    'room_id' => $roomId,
                    'sender_id' => $senderId,
                    'content' => $displayContent,
                    'type' => $messageType,
                    'file_path' => $filePath,
                    'created_at' => date('Y-m-d H:i:s'),
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể lưu tin nhắn', 'code' => 'RUNTIME_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function filterRooms(): void
    {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            $filterType = $_GET['type'] ?? 'all';
            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;

            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $rooms = $this->roomRepository->getAllRooms($currentUserId, $filterType);
            $rooms = array_values($rooms);
            
            http_response_code(200);
            echo json_encode(['status' => 'success', 'data' => $rooms, 'count' => count($rooms), 'filter' => $filterType], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể lọc danh sách phòng', 'code' => 'RUNTIME_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function getRoomInfo(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID không hợp lệ');
            }

            $room = $this->roomRepository->getRoomById($roomId);
            if (!$room) {
                throw new \RuntimeException('Không tìm thấy phòng');
            }

            $members = $this->roomRepository->getRoomMembers($roomId);

            echo json_encode(['status' => 'success', 'data' => ['room' => $room, 'members' => $members]], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function getNewMessages(): void
    {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
            $lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;

            if ($roomId <= 0) throw new \InvalidArgumentException('Room ID không hợp lệ');
            if ($lastId < 0) throw new \InvalidArgumentException('Last ID không hợp lệ');
            if ($currentUserId <= 0) throw new \InvalidArgumentException('User ID không hợp lệ');

            $messages = $this->messageRepository->getMessagesAfterId($roomId, $lastId);
            
            // Lấy room type
            $room = $this->roomRepository->getRoomById($roomId);
            $roomType = $room['type'] ?? 'private';
            
            // ÁP DỤNG DECORATORS
            $emojiDecorator = new EmojiDecorator();
            $emojiMap = $emojiDecorator->getEmojiList();

            $formattedMessages = [];
            foreach ($messages as $msg) {
                $content = $msg['content'];
                
                // Chỉ áp dụng decorators cho text message
                if (($msg['type'] ?? 'text') === 'text') {
                    $content = str_replace(array_keys($emojiMap), array_values($emojiMap), $content);
                    
                    // Chỉ áp dụng MentionDecorator cho GROUP chat
                    if ($roomType === 'group') {
                        $content = preg_replace_callback('/@([a-zA-Z0-9_]{3,20})/u', function ($m) {
                            $safe = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                            return sprintf('<a href="/profile/%s" class="mention">@%s</a>', $safe, $safe);
                        }, $content);
                    }
                }
                
                $formattedMessages[] = [
                    'id' => (int)$msg['id'],
                    'room_id' => (int)$msg['room_id'],
                    'sender_id' => (int)$msg['sender_id'],
                    'username' => $msg['username'] ?? 'Unknown',
                    'content' => $content,
                    'type' => $msg['type'] ?? 'text',
                    'file_path' => $msg['type'] !== 'text' ? $msg['content'] : null,
                    'sent_at' => $msg['sent_at']
                ];
            }

            http_response_code(200);
            echo json_encode(['status' => 'success', 'data' => $formattedMessages, 'count' => count($formattedMessages), 'timestamp' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể lấy tin nhắn mới', 'code' => 'RUNTIME_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function getRoomMessages(): void
    {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;

            if ($roomId <= 0) throw new \InvalidArgumentException('Room ID không hợp lệ');
            if ($currentUserId <= 0) throw new \InvalidArgumentException('User ID không hợp lệ');

            $this->messageRepository->markRoomAsRead($roomId, $currentUserId);

            $room = $this->roomRepository->getRoomById($roomId);
            if (!$room) throw new \RuntimeException('Không tìm thấy phòng');

            $members = $this->roomRepository->getRoomMembers($roomId);
            $memberCount = count($members);

            $messages = $this->messageRepository->findByRoom($roomId, 50);

            $formattedMessages = [];
            foreach ($messages as $msg) {
                $formattedMessages[] = [
                    'id'        => $msg->getId(),
                    'room_id'   => $roomId,
                    'sender_id' => $msg->getSender()->getId(),
                    'username'  => $msg->getSender()->getName(),
                    'content'   => $msg->getContent(),
                    'type'      => $msg->getType(),
                    'sent_at'   => $msg->getSentAt()->format('Y-m-d H:i:s'),
                    'is_me'     => ($msg->getSender()->getId() === $currentUserId)
                ];
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'room' => [
                        'id' => (int)$room['id'],
                        'name' => $room['name'] ?? 'Phòng chat',
                        'type' => $room['type'] ?? 'private',
                        'member_count' => $memberCount
                    ],
                    'messages' => $formattedMessages
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể lấy tin nhắn', 'code' => 'RUNTIME_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function markAsRead(): void
    {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;

            if ($roomId <= 0) throw new \InvalidArgumentException('Room ID không hợp lệ');
            if ($currentUserId <= 0) throw new \InvalidArgumentException('User ID không hợp lệ');

            $this->messageRepository->markRoomAsRead($roomId, $currentUserId);

            echo json_encode(['status' => 'success', 'message' => 'Đã đánh dấu đã đọc'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function pinRoom(): void
    {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
            $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

            if ($roomId <= 0) throw new \InvalidArgumentException('Room ID không hợp lệ');
            if ($userId <= 0) throw new \InvalidArgumentException('User ID không hợp lệ');

            $result = $this->roomRepository->togglePinRoom($roomId, $userId);

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'message' => $result['is_pinned'] ? 'Đã ghim phòng' : 'Đã bỏ ghim phòng',
                'data' => ['room_id' => $roomId, 'is_pinned' => $result['is_pinned']]
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể ghim phòng', 'code' => 'RUNTIME_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function deleteRoom(): void
    {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            // Đọc từ POST (JS gửi qua FormData)
            $roomId = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
            $userId = $_SESSION['user_id'] ?? 0;

            if ($roomId <= 0) throw new \InvalidArgumentException('Room ID không hợp lệ');
            if ($userId <= 0) throw new \InvalidArgumentException('User ID không hợp lệ');

            // Lấy thông tin phòng để kiểm tra loại
            $room = $this->roomRepository->getRoomById($roomId);
            if (!$room) throw new \RuntimeException('Không tìm thấy phòng');
            
            // Kiểm tra user có trong phòng không
            if (!$this->roomRepository->isUserInRoom($roomId, $userId)) {
                throw new \RuntimeException('Bạn không phải thành viên của phòng này');
            }

            // Xóa user khỏi room_members (không xóa phòng thật)
            $this->roomRepository->removeUserFromRoom($roomId, $userId);

            http_response_code(200);
            echo json_encode([
                'status' => 'success', 
                'message' => 'Đã rời khỏi phòng chat thành công', 
                'data' => ['room_id' => $roomId, 'user_id' => $userId]
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể xóa phòng', 'code' => 'RUNTIME_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function createRoom(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $input = $_POST;
            if (empty($input)) {
                $rawInput = file_get_contents('php://input');
                $input = json_decode($rawInput, true) ?? [];
            }

            // Lấy user_id từ SESSION
            $currentUserId = $_SESSION['user_id'] ?? 0;
            
            // Backward compatibility: Nếu có trong URL thì dùng
            if (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0) {
                $currentUserId = (int)$_GET['user_id'];
            }
            
            $roomName = isset($input['room_name']) ? trim($input['room_name']) : '';
            $type = isset($input['type']) ? trim($input['type']) : 'group';
            $targetUserId = isset($input['target_user_id']) ? (int)$input['target_user_id'] : 0;

            $memberIds = [];
            if (!empty($input['member_ids'])) {
                if (is_array($input['member_ids'])) {
                    $memberIds = array_map('intval', $input['member_ids']);
                } else {
                    $memberIds = array_filter(array_map('intval', explode(',', $input['member_ids'])));
                }
                $memberIds = array_values(array_filter($memberIds, fn($id) => $id > 0));
            }

            $errors = [];
            if ($currentUserId <= 0) $errors[] = 'User ID không hợp lệ';
            if (empty($roomName)) $errors[] = 'Tên phòng không được rỗng';
            if (!in_array($type, ['private', 'group'])) $errors[] = 'Loại phòng không hợp lệ';

            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ', 'errors' => $errors], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($type === 'private' && $targetUserId > 0) {
                $existingRoomId = $this->roomRepository->findPrivateRoom($currentUserId, $targetUserId);
                if ($existingRoomId) {
                    http_response_code(200);
                    echo json_encode(['status' => 'success', 'room_id' => $existingRoomId, 'message' => 'Đã tìm thấy đoạn chat', 'is_existing' => true], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $newRoomId = $this->roomRepository->createPrivateRoom($roomName, $currentUserId, $targetUserId);
            } else {
                $newRoomId = $this->roomRepository->create($roomName, $type, $currentUserId);

                foreach ($memberIds as $memberId) {
                    if ($memberId !== $currentUserId) {
                        $this->roomRepository->addMemberToRoom($newRoomId, $memberId);
                    }
                }
            }

            http_response_code(200);
            echo json_encode(['status' => 'success', 'room_id' => $newRoomId, 'message' => 'Tạo phòng thành công'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể tạo phòng', 'details' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function searchUsers(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;

            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $db = Database::getInstance();
            
            // Nếu keyword rỗng, lấy tất cả user (trừ mình)
            if (empty($keyword)) {
                $sql = "SELECT id, username FROM users WHERE id != :current_user_id ORDER BY username ASC";
                $stmt = $db->prepare($sql);
                $stmt->bindValue(':current_user_id', $currentUserId, PDO::PARAM_INT);
            } else {
                $sql = "SELECT id, username FROM users WHERE username LIKE :keyword AND id != :current_user_id ORDER BY username ASC LIMIT 20";
                $stmt = $db->prepare($sql);
                $stmt->bindValue(':keyword', '%' . $keyword . '%', PDO::PARAM_STR);
                $stmt->bindValue(':current_user_id', $currentUserId, PDO::PARAM_INT);
            }
            
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $users, 'count' => count($users)], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể tìm kiếm user'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function deleteMessage(): void
    {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'Chỉ chấp nhận POST request'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
            $currentUserId = $_SESSION['user_id'] ?? 0;

            if ($messageId <= 0) {
                throw new \InvalidArgumentException('Message ID không hợp lệ');
            }

            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            // Kiểm tra tin nhắn có tồn tại và thuộc về user hiện tại không
            $message = $this->messageRepository->getMessageById($messageId);
            if (!$message) {
                throw new \RuntimeException('Không tìm thấy tin nhắn');
            }

            if ((int)$message['sender_id'] !== $currentUserId) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Bạn không có quyền xóa tin nhắn này'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Xóa tin nhắn
            $result = $this->messageRepository->deleteMessage($messageId);

            if ($result) {
                http_response_code(200);
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Đã xóa tin nhắn thành công',
                    'data' => ['message_id' => $messageId]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new \RuntimeException('Không thể xóa tin nhắn');
            }
            exit;

        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể xóa tin nhắn'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    public function pinMessage(): void
    {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'Chỉ chấp nhận POST request'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
            $roomId = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
            $currentUserId = $_SESSION['user_id'] ?? 0;

            if ($messageId <= 0 || $roomId <= 0 || $currentUserId <= 0) {
                throw new \InvalidArgumentException('Tham số không hợp lệ');
            }

            // Kiểm tra user có trong phòng không
            if (!$this->roomRepository->isUserInRoom($roomId, $currentUserId)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Bạn không có quyền ghim tin nhắn trong phòng này'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $db = Database::getInstance();
            
            // Kiểm tra tin nhắn đã được ghim chưa
            $checkSql = "SELECT id FROM pinned_messages WHERE room_id = :room_id AND message_id = :message_id";
            $checkStmt = $db->prepare($checkSql);
            $checkStmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $checkStmt->bindValue(':message_id', $messageId, PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->fetch()) {
                // Đã ghim -> Bỏ ghim
                $deleteSql = "DELETE FROM pinned_messages WHERE room_id = :room_id AND message_id = :message_id";
                $deleteStmt = $db->prepare($deleteSql);
                $deleteStmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
                $deleteStmt->bindValue(':message_id', $messageId, PDO::PARAM_INT);
                $deleteStmt->execute();
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Đã bỏ ghim tin nhắn',
                    'data' => ['is_pinned' => false]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                // Chưa ghim -> Ghim
                $insertSql = "INSERT INTO pinned_messages (room_id, message_id, pinned_by, pinned_at) VALUES (:room_id, :message_id, :pinned_by, NOW())";
                $insertStmt = $db->prepare($insertSql);
                $insertStmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
                $insertStmt->bindValue(':message_id', $messageId, PDO::PARAM_INT);
                $insertStmt->bindValue(':pinned_by', $currentUserId, PDO::PARAM_INT);
                $insertStmt->execute();
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Đã ghim tin nhắn',
                    'data' => ['is_pinned' => true]
                ], JSON_UNESCAPED_UNICODE);
            }
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể ghim tin nhắn'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    public function getPinnedMessages(): void
    {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID không hợp lệ');
            }

            $db = Database::getInstance();
            $sql = "
                SELECT m.*, u.username, pm.pinned_at
                FROM pinned_messages pm
                JOIN messages m ON pm.message_id = m.id
                JOIN users u ON m.sender_id = u.id
                WHERE pm.room_id = :room_id
                ORDER BY pm.pinned_at DESC
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->execute();
            
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $messages], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể lấy tin nhắn đã ghim'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
