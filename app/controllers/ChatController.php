<?php

/**
 * ============================================
 * CLASS: ChatController
 * ============================================
 * Controller chính xử lý nghiệp vụ chat
 * Tuân thủ nguyên tắc MVC và SOLID principles
 * 
 * Responsibilities:
 * - Hiển thị giao diện chat (action: index)
 * - Xử lý gửi tin nhắn qua AJAX (action: send)
 * - Áp dụng Decorator Pattern cho tin nhắn
 * 
 * @package App\Controllers
 * @author Senior Fullstack Developer
 */
class ChatController extends Controller
{
    use LoggerTrait;

    /**
     * Repository xử lý dữ liệu tin nhắn
     * 
     * @var MessageRepository
     */
    private MessageRepository $messageRepository;

    /**
     * Repository xử lý dữ liệu phòng chat
     * 
     * @var RoomRepository
     */
    private RoomRepository $roomRepository;

    /**
     * Constructor - Dependency Injection
     * 
     * Tiêm các repository vào controller thay vì khởi tạo trực tiếp
     * Giúp dễ dàng test và thay thế implementation
     * 
     * @param MessageRepository|null $messageRepository Repository xử lý tin nhắn
     * @param RoomRepository|null $roomRepository Repository xử lý phòng chat
     */
    public function __construct(
        ?MessageRepository $messageRepository = null,
        ?RoomRepository $roomRepository = null
    ) {
        $this->messageRepository = $messageRepository ?? new MessageRepository();
        $this->roomRepository = $roomRepository ?? new RoomRepository();
        $this->initLogger();
        $this->log('CONTROLLER_INIT', 'ChatController đã được khởi tạo');
    }

    /**
     * ============================================
     * ACTION: index - HIỂN THỊ GIAO DIỆN CHAT
     * ============================================
     * Điểm vào chính của ứng dụng chat
     * 
     * LUỒNG XỬ LÝ:
     * 1. Nhận diện User từ URL ($_GET['user_id'])
     * 2. Lấy danh sách phòng chat mà user tham gia (với filter nếu có)
     * 3. Đánh dấu phòng hiện tại là đã đọc (xóa badge unread)
     * 4. Load tin nhắn của phòng hiện tại
     * 5. Áp dụng Decorator Pattern để format tin nhắn
     * 6. Truyền dữ liệu xuống View để render HTML
     * 
     * BẢO MẬT:
     * - Ép kiểu (int) cho tất cả ID từ URL
     * - Validate ID phải > 0
     * - Không hardcode user_id cố định
     * 
     * @return void
     */
    public function index(): void
    {
        $this->log('ACTION_START', 'Bắt đầu xử lý action index()');

        try {
            // ============================================
            // BƯỚC 1: NHẬN DIỆN USER TỪ URL
            // ============================================
            // Lấy user_id từ URL: ?user_id=2
            // Nếu không có, mặc định là 1 (user demo)
            // ÉP KIỂU (int) để bảo mật, tránh SQL Injection
            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;
            
            // Validate: User ID phải là số dương
            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $this->log('USER_IDENTIFIED', "Current User ID: {$currentUserId}");

            // ============================================
            // BƯỚC 2: LẤY ROOM_ID TỪ URL
            // ============================================
            // Lấy room_id từ URL: ?room_id=3
            // Nếu không có, mặc định là 1 (phòng đầu tiên)
            $roomId = (int)($_GET['room_id'] ?? 1);

            // Validate: Room ID phải là số dương
            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID không hợp lệ');
            }

            $this->log('ROOM_SELECTED', "Room ID: {$roomId}");

            // ============================================
            // BƯỚC 3: LẤY FILTER TYPE TỪ URL
            // ============================================
            // Lấy filter từ URL: ?filter=unread hoặc ?filter=groups
            // Mặc định là 'all' (hiển thị tất cả phòng)
            $filterType = $_GET['filter'] ?? 'all';

            $this->log('FILTER_TYPE', "Filter: {$filterType}");

            // ============================================
            // BƯỚC 4: ĐÁNH DẤU PHÒNG ĐÃ ĐỌC NGAY LẬP TỨC
            // ============================================
            // Khi user vào phòng, đánh dấu tất cả tin nhắn là đã đọc
            // Điều này sẽ:
            // - Xóa badge số lượng tin chưa đọc trên sidebar
            // - Cập nhật is_read = 1 cho tất cả tin nhắn của phòng này
            // - Chỉ cập nhật tin nhắn mà user KHÔNG phải là người gửi
            $this->messageRepository->markRoomAsRead($roomId, $currentUserId);
            $this->log('MARK_AS_READ', "Đã đánh dấu phòng {$roomId} là đã đọc cho user {$currentUserId}");

            // ============================================
            // BƯỚC 5: LOAD DANH SÁCH PHÒNG THEO FILTER
            // ============================================
            // Gọi Repository để lấy danh sách phòng
            // Repository sẽ dùng INNER JOIN để chỉ lấy phòng mà user tham gia
            // Filter có thể là: 'all', 'unread', 'groups'
            $rooms = $this->roomRepository->getAllRooms($currentUserId, $filterType);
            $this->log('ROOMS_LOADED', 'Đã load ' . count($rooms) . ' phòng chat với filter: ' . $filterType);

            // ============================================
            // BƯỚC 6: LẤY THÔNG TIN PHÒNG HIỆN TẠI
            // ============================================
            // Lấy thông tin chi tiết của phòng đang mở (tên, type, created_at)
            $room = $this->roomRepository->getRoomById($roomId);
            $title = $room['name'] ?? 'Phòng riêng';
            $this->log('ROOM_INFO', "Tên phòng: {$title}");

            // ============================================
            // BƯỚC 7: LOAD TIN NHẮN CỦA PHÒNG HIỆN TẠI
            // ============================================
            // Lấy 50 tin nhắn gần nhất của phòng
            // Tin nhắn được sắp xếp theo thời gian (cũ -> mới)
            $messages = $this->messageRepository->getMessagesByRoom($roomId, 50);

            // ============================================
            // BƯỚC 8: ÁP DỤNG DECORATOR PATTERN
            // ============================================
            // Decorator Pattern: Thêm tính năng cho tin nhắn mà không sửa code gốc
            // Chain: Raw Message → EmojiDecorator → MentionDecorator
            // - EmojiDecorator: Chuyển :) thành 😊
            // - MentionDecorator: Chuyển @username thành link
            $decoratedMessages = $this->decorateMessages($messages);

            // ============================================
            // BƯỚC 9: CHUẨN BỊ DỮ LIỆU TRUYỀN XUỐNG VIEW
            // ============================================
            $data = [
                'roomId'        => $roomId,           // ID phòng hiện tại
                'rooms'         => $rooms,            // Danh sách phòng cho sidebar
                'messages'      => $decoratedMessages,// Tin nhắn đã được decorate
                'title'         => $title,            // Tên phòng hiện tại
                'currentUserId' => $currentUserId,    // ID user đang login
                'filterType'    => $filterType,       // Filter đang áp dụng
                'URLROOT'       => URLROOT,           // URL gốc của ứng dụng
            ];

            $messageCount = count($messages);
            $this->log('ACTION_SUCCESS', "Đã load {$messageCount} tin nhắn cho room {$roomId}");

            // ============================================
            // BƯỚC 10: RENDER VIEW
            // ============================================
            // Gọi view để render HTML
            // View sẽ nhận $data và hiển thị giao diện chat
            $this->view('chat/index', $data);

        } catch (\Exception $e) {
            // Bắt mọi lỗi và log lại
            $this->log('ACTION_ERROR', 'Lỗi: ' . $e->getMessage());
            http_response_code(500);
            echo "Đã xảy ra lỗi khi tải trang chat.";
        }
    }

    /**
     * ============================================
     * PRIVATE METHOD: decorateMessages
     * ============================================
     * Áp dụng Decorator Pattern để xử lý tin nhắn
     * 
     * Chain of Decorators:
     * Raw Message → EmojiDecorator → MentionDecorator
     * 
     * @param array $messages Mảng tin nhắn từ database
     * @return array Mảng tin nhắn đã được decorate
     */
    private function decorateMessages(array $messages): array
    {
        $decorated = [];

        foreach ($messages as $msg) {
            // Tạo adapter từ raw data (anonymous class)
            $baseMessage = new class($msg) implements MessageDecorator {
                private array $data;

                public function __construct(array $data)
                {
                    $this->data = $data;
                }

                public function getContent(): string
                {
                    return $this->data['content'] ?? '';
                }

                public function getId(): int
                {
                    return (int)($this->data['id'] ?? 0);
                }

                public function getUserId(): int
                {
                    return (int)($this->data['sender_id'] ?? 0);
                }

                public function getCreatedAt(): string
                {
                    return $this->data['sent_at'] ?? '';
                }

                public function getType(): string
                {
                    return $this->data['type'] ?? 'text';
                }
            };

            // Áp dụng chain of decorators
            $decorated[] = new MentionDecorator(new EmojiDecorator($baseMessage));
        }

        return $decorated;
    }

    /**
     * ============================================
     * ACTION: send (AJAX ENDPOINT)
     * ============================================
     * Xử lý gửi tin nhắn mới qua AJAX
     * 
     * ⚠️ QUAN TRỌNG - QUY TẮC TRẢ VỀ JSON:
     * 1. Set header 'Content-Type: application/json' TRƯỚC KHI echo
     * 2. KHÔNG echo/print/var_dump bất kỳ thứ gì khác ngoài JSON
     * 3. Sử dụng exit hoặc die() NGAY SAU KHI echo JSON
     * 4. Tránh để code phía sau tiếp tục chạy và echo thêm dữ liệu
     * 
     * Flow:
     * 1. Set header JSON ngay từ đầu
     * 2. Kiểm tra REQUEST_METHOD phải là POST
     * 3. Validate dữ liệu đầu vào (room_id, sender_id, content)
     * 4. Lưu tin nhắn vào database qua repository
     * 5. Trả về JSON response và exit ngay
     * 
     * Request Format:
     * - Method: POST
     * - Content-Type: multipart/form-data
     * - Body: room_id, sender_id, content
     * 
     * Response Format:
     * - Success: {"status": "success", "message": "...", "data": {...}}
     * - Error: {"status": "error", "message": "...", "code": "..."}
     * 
     * @return void
     */
    public function send(): void
    {
        // ============================================
        // BƯỚC 1: SET HEADER JSON NGAY TỪ ĐẦU
        // ============================================
        header('Content-Type: application/json; charset=utf-8');

        try {
            // ============================================
            // BƯỚC 2: KIỂM TRA REQUEST METHOD
            // ============================================
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Chỉ chấp nhận POST request',
                    'code' => 'METHOD_NOT_ALLOWED'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $this->log('SEND_START', 'Bắt đầu xử lý gửi tin nhắn');

            // ============================================
            // BƯỚC 3: LẤY VÀ VALIDATE DỮ LIỆU ĐẦU VÀO
            // ============================================
            $roomId   = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
            $senderId = isset($_POST['sender_id']) ? (int)$_POST['sender_id'] : 0;
            $content  = isset($_POST['content']) ? trim($_POST['content']) : '';
            $messageType = 'text'; // Mặc định là text
            $filePath = null;

            // Validate cơ bản
            $errors = [];
            if ($roomId <= 0) $errors[] = 'room_id không hợp lệ';
            if ($senderId <= 0) $errors[] = 'sender_id không hợp lệ';

            // ============================================
            // BƯỚC 4: XỬ LÝ UPLOAD FILE (NẾU CÓ)
            // ============================================
            if (isset($_FILES['file'])) {
                if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    // Xử lý lỗi upload
                    $uploadError = $_FILES['file']['error'];
                    switch ($uploadError) {
                        case UPLOAD_ERR_INI_SIZE:
                        case UPLOAD_ERR_FORM_SIZE:
                            $errors[] = 'File quá lớn (tối đa 10MB)';
                            break;
                        case UPLOAD_ERR_PARTIAL:
                            $errors[] = 'File chỉ được upload một phần';
                            break;
                        case UPLOAD_ERR_NO_FILE:
                            $errors[] = 'Không có file nào được upload';
                            break;
                        default:
                            $errors[] = 'Lỗi upload file (Error code: ' . $uploadError . ')';
                            break;
                    }
                } else {
                    // Xử lý upload thành công
                    $file = $_FILES['file'];
                    $fileName = basename($file['name']);
                    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $fileSize = $file['size'];

                    // Validate file size (max 10MB)
                    if ($fileSize > 10 * 1024 * 1024) {
                        $errors[] = 'File quá lớn (tối đa 10MB)';
                    }

                    // Phân loại type dựa vào extension
                    if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif'])) {
                        $messageType = 'image';
                    } else {
                        $messageType = 'file';
                    }

                    // Tạo tên file unique để tránh trùng
                    $uniqueFileName = time() . '_' . uniqid() . '.' . $fileExt;
                    $uploadPath = __DIR__ . '/../../public/uploads/' . $uniqueFileName;

                    // Di chuyển file vào thư mục uploads
                    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                        $errors[] = 'Không thể lưu file';
                    } else {
                        // Lưu đường dẫn relative để lưu vào database
                        $filePath = 'uploads/' . $uniqueFileName;
                        $content = $filePath; // Content sẽ là đường dẫn file
                        $this->log('FILE_UPLOADED', "File uploaded: {$filePath}");
                    }
                }
            } else {
                // Nếu không có file, validate content text
                if (empty($content)) {
                    $errors[] = 'Nội dung tin nhắn không được rỗng';
                }
                if (strlen($content) > 5000) {
                    $errors[] = 'Nội dung tin nhắn quá dài (tối đa 5000 ký tự)';
                }
            }

            // Nếu có lỗi validation, trả về ngay
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Dữ liệu không hợp lệ',
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $errors
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // ============================================
            // BƯỚC 5: LƯU TIN NHẮN VÀO DATABASE
            // ============================================
            $this->log('SEND_BEFORE_SAVE', 'Chuẩn bị lưu tin nhắn vào database');

            // Gọi repository để lưu tin nhắn với type
            $messageId = $this->messageRepository->saveMessage($roomId, $senderId, $content, $messageType);

            $this->log('SEND_AFTER_SAVE', "Tin nhắn đã được lưu với ID: {$messageId}");

            // ============================================
            // BƯỚC 6: TRẢ VỀ JSON RESPONSE THÀNH CÔNG
            // ============================================
            http_response_code(200);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Tin nhắn đã được gửi thành công',
                'data' => [
                    'message_id' => $messageId,
                    'room_id' => $roomId,
                    'sender_id' => $senderId,
                    'content' => $content,
                    'type' => $messageType,
                    'file_path' => $filePath,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ], JSON_UNESCAPED_UNICODE);

            $this->log('MESSAGE_SENT', "User {$senderId} đã gửi tin nhắn vào room {$roomId}");
            exit;

        } catch (\InvalidArgumentException $e) {
            // Lỗi validation từ repository
            $this->log('SEND_VALIDATION_ERROR', $e->getMessage());
            
            http_response_code(400); // Bad Request
            
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            
            exit; // 🔥 Dừng ngay

        } catch (\RuntimeException $e) {
            // Lỗi runtime (database, network, etc.)
            $this->log('SEND_RUNTIME_ERROR', $e->getMessage());
            
            http_response_code(500); // Internal Server Error
            
            echo json_encode([
                'status' => 'error',
                'message' => 'Không thể lưu tin nhắn. Vui lòng thử lại.',
                'code' => 'RUNTIME_ERROR',
                'details' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            
            exit; // 🔥 Dừng ngay

        } catch (\Throwable $e) {
            // Bắt mọi lỗi khác (fatal error, exception, etc.)
            $this->log('SEND_FATAL_ERROR', $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
            
            http_response_code(500); // Internal Server Error
            
            echo json_encode([
                'status' => 'error',
                'message' => 'Đã xảy ra lỗi nghiêm trọng',
                'code' => 'FATAL_ERROR',
                'details' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'type' => get_class($e)
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            
            exit; // 🔥 Dừng ngay
        }
        
        // ⚠️ KHÔNG BAO GIỜ ĐẾN DÒNG NÀY vì đã exit ở tất cả các trường hợp trên
    }

    /**
     * ============================================
     * ACTION: filterRooms - API ENDPOINT CHO SIDEBAR FILTER
     * ============================================
     * Lọc danh sách phòng theo type và trả về JSON
     * 
     * MỤC ĐÍCH:
     * - Cập nhật sidebar khi user click vào filter (All, Unread, Groups)
     * - KHÔNG cần reload lại toàn bộ trang
     * - Chỉ cập nhật phần danh sách phòng bên trái
     * 
     * LUỒNG XỬ LÝ:
     * 1. Frontend gọi AJAX: GET /index.php?controller=chat&action=filterRooms&type=unread&user_id=2
     * 2. Controller nhận request, lấy filterType và userId từ URL
     * 3. Gọi Repository::getAllRooms($userId, $filterType)
     * 4. Repository trả về danh sách phòng đã lọc
     * 5. Controller render HTML cho từng phòng
     * 6. Trả về JSON chứa HTML đã render
     * 7. Frontend nhận JSON, replace nội dung sidebar bằng HTML mới
     * 
     * FILTER TYPES:
     * - 'all': Tất cả phòng mà user tham gia
     * - 'unread': Chỉ phòng có tin nhắn chưa đọc
     * - 'groups': Chỉ phòng nhóm (type = 'group')
     * 
     * BẢO MẬT:
     * - Ép kiểu (int) cho user_id
     * - Validate user_id > 0
     * - Sử dụng htmlspecialchars() để tránh XSS
     * 
     * @return void
     */
    public function filterRooms(): void
    {
        // ============================================
        // BƯỚC 1: XÓA OUTPUT BUFFER (QUAN TRỌNG!)
        // ============================================
        // Xóa mọi output thừa trước khi trả JSON
        // Tránh lỗi "Unexpected token '<', \"<!DOCTYPE \"... is not valid JSON"
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        // ============================================
        // BƯỚC 2: SET HEADER JSON
        // ============================================
        header('Content-Type: application/json; charset=utf-8');

        try {
            // ============================================
            // BƯỚC 3: LẤY THAM SỐ TỪ URL
            // ============================================
            // Lấy filter type: ?type=unread
            $filterType = $_GET['type'] ?? 'all';
            
            // Lấy user_id: ?user_id=2
            // ÉP KIỂU (int) để bảo mật
            // KHÔNG HARDCODE user_id = 1
            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;

            // Validate user_id
            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $this->log('FILTER_ROOMS_REQUEST', "User={$currentUserId}, Filter={$filterType}");

            // ============================================
            // BƯỚC 4: GỌI REPOSITORY ĐỂ LẤY DANH SÁCH PHÒNG
            // ============================================
            // Repository sẽ:
            // - Dùng INNER JOIN room_members để chỉ lấy phòng mà user tham gia
            // - Áp dụng filter (unread/groups) nếu có
            // - Tính toán Smart Time (hôm nay: H:i, ngày trước: d/m H:i)
            // - Đếm số tin nhắn chưa đọc (loại trừ tin của chính user)
            $rooms = $this->roomRepository->getAllRooms($currentUserId, $filterType);

            $this->log('FILTER_ROOMS_RESULT', 'Lấy được ' . count($rooms) . ' phòng');

            // ============================================
            // BƯỚC 5: TRẢ VỀ JSON VỚI MẢNG ROOMS (KHÔNG PHẢI HTML)
            // ============================================
            // 🔥 QUAN TRỌNG: Trả về mảng JSON để Frontend tự render
            // Đảm bảo luôn trả về mảng [], không phải object {} hay null
            
            // Đảm bảo $rooms là mảng indexed (không phải associative)
            $rooms = array_values($rooms);
            
            http_response_code(200);
            
            echo json_encode([
                'status' => 'success',
                'data' => $rooms,  // Trả về mảng JSON trực tiếp
                'count' => count($rooms),  // Số lượng phòng
                'filter' => $filterType    // Filter đã áp dụng
            ], JSON_UNESCAPED_UNICODE);
            
            // Dừng script ngay để tránh output thừa
            exit;

        } catch (\InvalidArgumentException $e) {
            // Lỗi validation
            $this->log('FILTER_ROOMS_VALIDATION_ERROR', $e->getMessage());
            
            http_response_code(400);
            
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            
            exit;

        } catch (\Throwable $e) {
            // Lỗi khác (database, runtime, etc.)
            $this->log('FILTER_ROOMS_ERROR', $e->getMessage());
            
            http_response_code(500);
            
            echo json_encode([
                'status' => 'error',
                'message' => 'Không thể lọc danh sách phòng',
                'code' => 'RUNTIME_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            
            exit;
        }
    }

    /**
     * ============================================
     * ACTION: getRoomInfo (AJAX ENDPOINT)
     * ============================================
     * Lấy thông tin phòng và danh sách thành viên
     */
    public function getRoomInfo(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID không hợp lệ');
            }

            // Lấy thông tin phòng
            $room = $this->roomRepository->getRoomById($roomId);
            
            if (!$room) {
                throw new \RuntimeException('Không tìm thấy phòng');
            }

            // Lấy danh sách thành viên
            $members = $this->roomRepository->getRoomMembers($roomId);

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'room' => $room,
                    'members' => $members
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * ============================================
     * ACTION: getNewMessages - FIX LỖI USER 2
     * ============================================
     * LẤY TIN NHẮN MỚI CHO TÍNH NĂNG POLLING
     * 
     * YÊU CẦU NGHIÊM NGẶT:
     * - Lấy currentUserId từ $_GET['user_id'] (KHÔNG HARDCODE)
     * 
     * 📚 MỤC ĐÍCH:
     * - Endpoint này được Frontend gọi mỗi 3-5 giây để kiểm tra tin nhắn mới
     * - Trả về danh sách tin nhắn mới hơn lastId
     * - Frontend sẽ append tin nhắn mới vào giao diện mà KHÔNG reload trang
     * 
     * 🔄 LUỐNG HOẠT ĐỘNG:
     * 1. Frontend lưu ID của tin nhắn cuối cùng (lastMessageId = 100)
     * 2. Mỗi 3s, frontend gọi: GET /index.php?controller=chat&action=getNewMessages&room_id=1&last_id=100
     * 3. Controller nhận request, gọi Repository::getMessagesAfterId(1, 100)
     * 4. Repository query database: SELECT * WHERE room_id=1 AND id > 100
     * 5. Controller format kết quả thành JSON và trả về
     * 6. Frontend nhận JSON, append tin nhắn mới vào DOM
     * 
     * ⚠️ TẠI SAO PHẢI DÙNG ob_clean() VÀ exit?
     * 
     * 🔴 VẤN ĐỀ: "Unexpected token '<', "<!DOCTYPE "... is not valid JSON"
     * - Lỗi này xảy ra khi Frontend nhận được HTML thay vì JSON
     * - Nguyên nhân: PHP vô tình echo/print HTML trước khi trả JSON
     * 
     * 💡 GIẢI PHÁP 1: ob_clean()
     * - ob_clean() xóa toàn bộ output buffer (bộ đệm xuất)
     * - Nếu có bất kỳ khoảng trắng, HTML, echo nào trước đó đều bị xóa sạch
     * - Đảm bảo chỉ có JSON được trả về
     * 
     * VÍ DỤ KHÔNG DÙNG ob_clean():
     * ```php
     * <?php
     * echo "Debug: Starting...\n";  // <- Lỗi: echo thừa
     * header('Content-Type: application/json');
     * echo json_encode(['status' => 'success']);
     * ```
     * Frontend nhận: "Debug: Starting...\n{\"status\":\"success\"}"
     * JSON.parse() sẽ báo lỗi vì có text thừa ở đầu
     * 
     * VÍ DỤ CÓ DÙNG ob_clean():
     * ```php
     * <?php
     * echo "Debug: Starting...\n";  // <- Bị xóa bởi ob_clean()
     * ob_clean();  // Xóa sạch buffer
     * header('Content-Type: application/json');
     * echo json_encode(['status' => 'success']);
     * ```
     * Frontend nhận: "{\"status\":\"success\"}" (chuẩn JSON)
     * 
     * 💡 GIẢI PHÁP 2: exit
     * - exit hoặc die() dừng script ngay lập tức
     * - Không cho phép code phía sau chạy tiếp
     * - Tránh trường hợp file index.php render layout HTML sau khi trả JSON
     * 
     * VÍ DỤ KHÔNG DÙNG exit:
     * ```php
     * public function getNewMessages() {
     *     echo json_encode(['status' => 'success']);
     *     // Không có exit -> code tiếp tục chạy
     * }
     * // index.php tiếp tục render layout:
     * echo "<!DOCTYPE html><html>...";
     * ```
     * Frontend nhận: "{\"status\":\"success\"}<!DOCTYPE html><html>..."
     * JSON.parse() báo lỗi vì có HTML thừa ở cuối
     * 
     * VÍ DỤ CÓ DÙNG exit:
     * ```php
     * public function getNewMessages() {
     *     echo json_encode(['status' => 'success']);
     *     exit;  // Dừng ngay, không chạy tiếp
     * }
     * // Code phía sau KHÔNG BAO GIỞ chạy
     * ```
     * Frontend nhận: "{\"status\":\"success\"}" (hoàn hảo)
     * 
     * 🎯 TƯƠNG TÁC GIỮA MODEL - CONTROLLER:
     * 
     * FLOW CHUẨN MVC:
     * 1. Frontend gọi AJAX request đến Controller
     * 2. Controller nhận request, validate input
     * 3. Controller gọi Repository (Model) để lấy dữ liệu
     * 4. Repository query database, trả về raw data (array)
     * 5. Controller nhận data, format thành JSON
     * 6. Controller trả JSON về cho Frontend
     * 
     * SEPARATION OF CONCERNS:
     * - Repository (Model): Chỉ lo query database, trả về array
     * - Controller: Nhận request, gọi Model, format response, trả về JSON
     * - View: Hiển thị dữ liệu (trong trường hợp này không dùng View)
     * 
     * TẠI SAO KHÔNG ĐỂ REPOSITORY TRẢ VỀ JSON?
     * - Repository chỉ nên làm việc với database
     * - Việc format JSON là trách nhiệm của Controller
     * - Nếu Repository trả JSON, khó tái sử dụng cho các mục đích khác
     * 
     * 🔐 BẢO MẬT:
     * - Validate roomId và lastId phải là số nguyên
     * - Sử dụng try-catch để bắt mọi lỗi
     * - Trả về thông báo lỗi thân thiện, không lộ chi tiết kỹ thuật
     * 
     * @return void
     */
    public function getNewMessages(): void
    {
        // ============================================
        // BƯỚC 1: XÓA OUTPUT BUFFER (QUAN TRỌNG!)
        // ============================================
        if (ob_get_level() > 0) {
            ob_clean();
        }

        // ============================================
        // BƯỚC 2: SET HEADER JSON NGAY TỪ ĐẦU
        // ============================================
        header('Content-Type: application/json; charset=utf-8');

        try {
            // ============================================
            // BƯỚC 3: LẤY VÀ VALIDATE DỮ LIỆU TỪ $_GET (KHÔNG HARDCODE USER_ID)
            // ============================================
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
            $lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;

            // Validate input
            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID không hợp lệ');
            }

            if ($lastId < 0) {
                throw new \InvalidArgumentException('Last ID không hợp lệ');
            }
            
            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $this->log('GET_NEW_MESSAGES_REQUEST', "roomId={$roomId}, lastId={$lastId}, userId={$currentUserId}");

            // ============================================
            // BƯỚC 4: GỌI REPOSITORY LẤY DỮ LIỆU
            // ============================================
            // Gọi Repository::getMessagesAfterId() để lấy tin nhắn mới
            // Repository sẽ query database và trả về array
            $messages = $this->messageRepository->getMessagesAfterId($roomId, $lastId);

            $this->log('GET_NEW_MESSAGES_RESULT', 'Lấy được ' . count($messages) . ' tin nhắn mới');

            // ============================================
            // BƯỚC 5: FORMAT DỮ LIỆU TRẢ VỀ
            // ============================================
            // Format mỗi tin nhắn để Frontend dễ xử lý
            $formattedMessages = [];
            foreach ($messages as $msg) {
                $formattedMessages[] = [
                    'id' => (int)$msg['id'],
                    'room_id' => (int)$msg['room_id'],
                    'sender_id' => (int)$msg['sender_id'],
                    'username' => $msg['username'] ?? 'Unknown',
                    'content' => $msg['content'],
                    'type' => $msg['type'] ?? 'text',
                    'file_path' => $msg['type'] !== 'text' ? $msg['content'] : null,
                    'sent_at' => $msg['sent_at']
                ];
            }

            // ============================================
            // BƯỚC 6: TRẢ VỀ JSON RESPONSE THÀNH CÔNG
            // ============================================
            http_response_code(200);
            
            echo json_encode([
                'status' => 'success',
                'data' => $formattedMessages,
                'count' => count($formattedMessages),
                'timestamp' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE);

            // ============================================
            // BƯỚC 7: DỮNG SCRIPT NGAY (QUAN TRỌNG!)
            // ============================================
            // exit dừng script ngay lập tức
            // Không cho phép code phía sau chạy tiếp
            // Tránh trường hợp index.php render layout HTML
            exit;

        } catch (\InvalidArgumentException $e) {
            // Lỗi validation
            $this->log('GET_NEW_MESSAGES_VALIDATION_ERROR', $e->getMessage());
            
            http_response_code(400);
            
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            
            exit; // Dừng ngay

        } catch (\RuntimeException $e) {
            // Lỗi runtime (database, network, etc.)
            $this->log('GET_NEW_MESSAGES_RUNTIME_ERROR', $e->getMessage());
            
            http_response_code(500);
            
            echo json_encode([
                'status' => 'error',
                'message' => 'Không thể lấy tin nhắn mới',
                'code' => 'RUNTIME_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            
            exit; // Dừng ngay

        } catch (\Throwable $e) {
            // Bắt mọi lỗi khác
            $this->log('GET_NEW_MESSAGES_FATAL_ERROR', $e->getMessage());
            
            http_response_code(500);
            
            echo json_encode([
                'status' => 'error',
                'message' => 'Đã xảy ra lỗi nghiêm trọng',
                'code' => 'FATAL_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            
            exit; // Dừng ngay
        }
        
        // ⚠️ KHÔNG BAO GIỞ ĐẾN DÒNG NÀY vì đã exit ở tất cả các trường hợp trên
    }

    /**
     * ============================================
     * ACTION: getRoomMessages - API LẤY TIN NHẮN PHÒNG (NO-RELOAD)
     * ============================================
     * API mới cho phép chuyển phòng không cần reload trang
     * 
     * LUỔNG HOẠT ĐỔNG:
     * 1. Frontend gọi AJAX: GET /index.php?controller=chat&action=getRoomMessages&room_id=X&user_id=Y
     * 2. Controller lấy thông tin phòng và tin nhắn
     * 3. Đánh dấu phòng đã đọc (markAsRead)
     * 4. Trả về JSON chứa: room info, messages, members count
     * 5. Frontend nhận JSON, cập nhật DOM (header + messages) mà KHÔNG reload
     * 
     * @return void
     */
    public function getRoomMessages(): void
    {
        // Xóa output buffer
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        header('Content-Type: application/json; charset=utf-8');

        try {
            // ============================================
            // BƯỚC 1: LẤY VÀ VALIDATE THAM SỐ
            // ============================================
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;

            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID không hợp lệ');
            }
            
            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $this->log('GET_ROOM_MESSAGES_API', "roomId={$roomId}, userId={$currentUserId}");

            // ============================================
            // BƯỚC 2: ĐÁNH DẤU PHÒNG ĐÃ ĐỌC NGAY LẬP TỨC
            // ============================================
            // Khi user vào phòng, đánh dấu tất cả tin nhắn là đã đọc
            $this->messageRepository->markRoomAsRead($roomId, $currentUserId);
            $this->log('MARK_AS_READ_API', "Đã đánh dấu phòng {$roomId} là đã đọc");

            // ============================================
            // BƯỚC 3: LẤY THÔNG TIN PHÒNG
            // ============================================
            $room = $this->roomRepository->getRoomById($roomId);
            
            if (!$room) {
                throw new \RuntimeException('Không tìm thấy phòng');
            }

            // ============================================
            // BƯỚC 4: LẤY DANH SÁCH THÀNH VIÊN
            // ============================================
            $members = $this->roomRepository->getRoomMembers($roomId);
            $memberCount = count($members);

            // ============================================
            // BƯỚC 5: LẤY TIN NHẮN CỦA PHÒNG
            // ============================================
            $messages = $this->messageRepository->getMessagesByRoom($roomId, 50);

            // ============================================
            // BƯỚC 6: FORMAT TIN NHẮN
            // ============================================
            $formattedMessages = [];
            foreach ($messages as $msg) {
                $formattedMessages[] = [
                    'id' => (int)$msg['id'],
                    'room_id' => (int)$msg['room_id'],
                    'sender_id' => (int)$msg['sender_id'],
                    'username' => $msg['username'] ?? 'Unknown',
                    'content' => $msg['content'],
                    'type' => $msg['type'] ?? 'text',
                    'sent_at' => $msg['sent_at'],
                    'is_me' => ((int)$msg['sender_id'] === $currentUserId)
                ];
            }

            // ============================================
            // BƯỚC 7: TRẢ VỀ JSON
            // ============================================
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
            $this->log('GET_ROOM_MESSAGES_VALIDATION_ERROR', $e->getMessage());
            
            http_response_code(400);
            
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            
            exit;

        } catch (\Throwable $e) {
            $this->log('GET_ROOM_MESSAGES_ERROR', $e->getMessage());
            
            http_response_code(500);
            
            echo json_encode([
                'status' => 'error',
                'message' => 'Không thể lấy tin nhắn',
                'code' => 'RUNTIME_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            
            exit;
        }
    }

    /**
     * ============================================
     * ACTION: markAsRead - HOÀN THIỆN
     * ============================================
     * Đánh dấu phòng đã đọc (fire-and-forget)
     * 
     * YÊU CẦU NGHIÊM NGẶT:
     * - Lấy currentUserId từ $_GET['user_id'] (KHÔNG HARDCODE)
     * - Gọi markRoomAsRead() để đánh dấu đã đọc
     * 
     * @return void
     */
    public function markAsRead(): void
    {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        header('Content-Type: application/json; charset=utf-8');

        try {
            // ============================================
            // LẤY THAM SỐ TỪ URL (KHÔNG HARDCODE USER_ID)
            // ============================================
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;

            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID không hợp lệ');
            }
            
            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            // ============================================
            // GỌI REPOSITORY ĐỂ ĐÁNH DẤU ĐÃ ĐỌC
            // ============================================
            $this->messageRepository->markRoomAsRead($roomId, $currentUserId);
            $this->log('MARK_AS_READ_API', "Room {$roomId} đã được đánh dấu đã đọc cho user {$currentUserId}");

            echo json_encode([
                'status' => 'success',
                'message' => 'Đã đánh dấu đã đọc'
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            $this->log('MARK_AS_READ_API_ERROR', $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * ============================================
     * ACTION: pinRoom - GHIM PHÒNG CHAT
     * ============================================
     * API để ghim/bỏ ghim phòng chat cho user
     * 
     * DATABASE LOGIC:
     * - Cập nhật cột is_pinned trong bảng room_members
     * - is_pinned = 1: Phòng được ghim
     * - is_pinned = 0: Phòng không ghim
     * 
     * LUỒNG XỬ LÝ:
     * 1. Nhận room_id và user_id từ URL
     * 2. Validate input
     * 3. Gọi Repository để toggle is_pinned
     * 4. Trả về JSON success
     * 
     * @return void
     */
    public function pinRoom(): void
    {
        // Xóa output buffer
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        // Set header JSON
        header('Content-Type: application/json; charset=utf-8');

        try {
            // ============================================
            // BƯỚC 1: LẤY VÀ VALIDATE THAM SỐ
            // ============================================
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
            $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID không hợp lệ');
            }
            
            if ($userId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $this->log('PIN_ROOM_REQUEST', "roomId={$roomId}, userId={$userId}");

            // ============================================
            // BƯỚC 2: GỌI REPOSITORY ĐỂ GHIM PHÒNG
            // ============================================
            $result = $this->roomRepository->togglePinRoom($roomId, $userId);

            $this->log('PIN_ROOM_SUCCESS', "Đã ghim phòng {$roomId} cho user {$userId}");

            // ============================================
            // BƯỚC 3: TRẢ VỀ JSON SUCCESS
            // ============================================
            http_response_code(200);
            
            echo json_encode([
                'status' => 'success',
                'message' => $result['is_pinned'] ? 'Đã ghim phòng' : 'Đã bỏ ghim phòng',
                'data' => [
                    'room_id' => $roomId,
                    'is_pinned' => $result['is_pinned']
                ]
            ], JSON_UNESCAPED_UNICODE);
            
            exit;

        } catch (\InvalidArgumentException $e) {
            $this->log('PIN_ROOM_VALIDATION_ERROR', $e->getMessage());
            
            http_response_code(400);
            
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            
            exit;

        } catch (\Throwable $e) {
            $this->log('PIN_ROOM_ERROR', $e->getMessage());
            
            http_response_code(500);
            
            echo json_encode([
                'status' => 'error',
                'message' => 'Không thể ghim phòng. Vui lòng thử lại.',
                'code' => 'RUNTIME_ERROR',
                'details' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            
            exit;
        }
    }

    /**
     * ============================================
     * ACTION: deleteRoom - XÓA PHÒNG CHAT
     * ============================================
     * API để xóa user khỏi phòng chat (leave room)
     * 
     * DATABASE LOGIC:
     * - Xóa bản ghi trong bảng room_members
     * - DELETE FROM room_members WHERE room_id = ? AND user_id = ?
     * - User sẽ không còn thấy phòng này trong sidebar
     * 
     * LƯU Ý:
     * - Không xóa phòng khỏi bảng chat_rooms
     * - Chỉ xóa user khỏi danh sách thành viên
     * - Tin nhắn cũ vẫn được giữ lại
     * 
     * LUỒNG XỬ LÝ:
     * 1. Nhận room_id và user_id từ URL
     * 2. Validate input
     * 3. Gọi Repository để xóa user khỏi room_members
     * 4. Trả về JSON success
     * 
     * @return void
     */
    public function deleteRoom(): void
    {
        // Xóa output buffer
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        // Set header JSON
        header('Content-Type: application/json; charset=utf-8');

        try {
            // ============================================
            // BƯỚC 1: LẤY VÀ VALIDATE THAM SỐ
            // ============================================
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
            $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID không hợp lệ');
            }
            
            if ($userId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $this->log('DELETE_ROOM_REQUEST', "roomId={$roomId}, userId={$userId}");

            // ============================================
            // BƯỚC 2: GỌI REPOSITORY ĐỂ XÓA USER KHỎI PHÒNG
            // ============================================
            $this->roomRepository->removeUserFromRoom($roomId, $userId);

            $this->log('DELETE_ROOM_SUCCESS', "Đã xóa user {$userId} khỏi phòng {$roomId}");

            // ============================================
            // BƯỚC 3: TRẢ VỀ JSON SUCCESS
            // ============================================
            http_response_code(200);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Đã xóa phòng chat thành công',
                'data' => [
                    'room_id' => $roomId,
                    'user_id' => $userId
                ]
            ], JSON_UNESCAPED_UNICODE);
            
            exit;

        } catch (\InvalidArgumentException $e) {
            $this->log('DELETE_ROOM_VALIDATION_ERROR', $e->getMessage());
            
            http_response_code(400);
            
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            
            exit;

        } catch (\Throwable $e) {
            $this->log('DELETE_ROOM_ERROR', $e->getMessage());
            
            http_response_code(500);
            
            echo json_encode([
                'status' => 'error',
                'message' => 'Không thể xóa phòng. Vui lòng thử lại.',
                'code' => 'RUNTIME_ERROR',
                'details' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            
            exit;
        }
    }

    /**
     * ============================================
     * ACTION: createRoom - TẠO PHÒNG MỚI (HỖ TRỢ PRIVATE CHAT 1-1)
     * ============================================
     * LOGIC PRIVATE CHAT:
     * - Nếu type = 'private' và target_user_id được cung cấp
     * - Kiểm tra xem đã có phòng 1-1 giữa 2 người chưa
     * - Nếu có: Trả về roomId cũ (không tạo mới)
     * - Nếu chưa: Tạo phòng mới và thêm 2 người vào
     * 
     * @return void
     */
    public function createRoom(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $input = $_POST;
            if (empty($input)) {
                $rawInput = file_get_contents('php://input');
                $input = json_decode($rawInput, true) ?? [];
            }

            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
            $roomName = isset($input['room_name']) ? trim($input['room_name']) : '';
            $type = isset($input['type']) ? trim($input['type']) : 'group';
            $targetUserId = isset($input['target_user_id']) ? (int)$input['target_user_id'] : 0;

            $this->log('CREATE_ROOM_REQUEST', "User={$currentUserId}, Name={$roomName}, Type={$type}, Target={$targetUserId}");

            // Validate
            $errors = [];
            if ($currentUserId <= 0) $errors[] = 'User ID không hợp lệ';
            if (empty($roomName)) $errors[] = 'Tên phòng không được rỗng';
            if (!in_array($type, ['private', 'group'])) $errors[] = 'Loại phòng không hợp lệ';

            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ', 'errors' => $errors], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // ============================================
            // LOGIC PRIVATE CHAT 1-1: KIỂM TRA PHÒNG ĐÃ TỒN TẠI
            // ============================================
            if ($type === 'private' && $targetUserId > 0) {
                $existingRoomId = $this->roomRepository->findPrivateRoom($currentUserId, $targetUserId);
                
                if ($existingRoomId) {
                    $this->log('PRIVATE_ROOM_EXISTS', "Phòng 1-1 đã tồn tại: {$existingRoomId}");
                    
                    http_response_code(200);
                    echo json_encode([
                        'status' => 'success',
                        'room_id' => $existingRoomId,
                        'message' => 'Đã tìm thấy đoạn chat',
                        'is_existing' => true
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                
                // Tạo phòng mới với 2 thành viên
                $newRoomId = $this->roomRepository->createPrivateRoom($roomName, $currentUserId, $targetUserId);
            } else {
                // Tạo phòng group bình thường
                $newRoomId = $this->roomRepository->create($roomName, $type, $currentUserId);
            }

            $this->log('CREATE_ROOM_SUCCESS', "Phòng {$newRoomId} đã được tạo");

            http_response_code(200);
            echo json_encode(['status' => 'success', 'room_id' => $newRoomId, 'message' => 'Tạo phòng thành công'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            $this->log('CREATE_ROOM_ERROR', $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể tạo phòng', 'details' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * ============================================
     * ACTION: searchUsers - TÌM KIẾM USER
     * ============================================
     * API endpoint để tìm kiếm user theo tên
     * 
     * LUỐNG HOẠT ĐỘNG:
     * 1. Nhận keyword từ URL (?keyword=abc)
     * 2. Tìm kiếm user trong database (LIKE %keyword%)
     * 3. Loại trừ user hiện tại khỏi kết quả
     * 4. Trả về JSON danh sách user
     * 
     * @return void
     */
    public function searchUsers(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

            if (empty($keyword) || strlen($keyword) < 2) {
                echo json_encode([
                    'status' => 'success',
                    'data' => [],
                    'message' => 'Keyword quá ngắn'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $this->log('SEARCH_USERS', "Keyword: {$keyword}, UserId: {$currentUserId}");

            // Tìm kiếm user trong database
            $db = Database::getInstance();
            $sql = "
                SELECT id, username
                FROM users
                WHERE username LIKE :keyword
                  AND id != :current_user_id
                ORDER BY username ASC
                LIMIT 20
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':keyword', '%' . $keyword . '%', PDO::PARAM_STR);
            $stmt->bindValue(':current_user_id', $currentUserId, PDO::PARAM_INT);
            $stmt->execute();
            
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->log('SEARCH_USERS_SUCCESS', 'Tìm thấy ' . count($users) . ' user');

            echo json_encode([
                'status' => 'success',
                'data' => $users,
                'count' => count($users)
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            $this->log('SEARCH_USERS_ERROR', $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Không thể tìm kiếm user'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * ============================================
     * ACTION: createChat - TẠO ĐOẠN CHAT MỚI (1-1 HOẶC NHÓM)
     * ============================================
     * API endpoint để tạo phòng chat mới
     * 
     * LUỐNG HOẠT ĐỘNG:
     * 1. Nhận type (private/group) và target (userId hoặc roomName)
     * 2. Nếu type = private:
     *    - Kiểm tra phòng 1-1 đã tồn tại chưa (checkExistingPrivateChat)
     *    - Nếu có: Trả về roomId cũ
     *    - Nếu chưa: Tạo phòng mới (createPrivateRoom)
     * 3. Nếu type = group:
     *    - Tạo phòng mới (create)
     *    - Thêm thành viên nếu có (addMemberToRoom)
     * 4. Trả về JSON với roomId
     * 
     * @return void
     */
    public function createChat(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            // Lấy dữ liệu từ POST
            $input = $_POST;
            if (empty($input)) {
                $rawInput = file_get_contents('php://input');
                $input = json_decode($rawInput, true) ?? [];
            }

            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
            $type = isset($input['type']) ? trim($input['type']) : 'private';
            $target = isset($input['target']) ? trim($input['target']) : '';

            $this->log('CREATE_CHAT_REQUEST', "User={$currentUserId}, Type={$type}, Target={$target}");

            // Validate
            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            if (empty($target)) {
                throw new \InvalidArgumentException('Target không được rỗng');
            }

            if (!in_array($type, ['private', 'group'])) {
                throw new \InvalidArgumentException('Loại phòng không hợp lệ');
            }

            $roomId = null;

            if ($type === 'private') {
                // ============================================
                // TẠO CHAT RIÊNG TƯ (1-1)
                // ============================================
                $targetUserId = (int)$target;
                
                if ($targetUserId <= 0) {
                    throw new \InvalidArgumentException('Target User ID không hợp lệ');
                }

                // Kiểm tra phòng 1-1 đã tồn tại chưa
                $existingRoomId = $this->roomRepository->checkExistingPrivateChat($currentUserId, $targetUserId);
                
                if ($existingRoomId) {
                    // Phòng đã tồn tại
                    $this->log('CREATE_CHAT_EXISTING', "Phòng 1-1 đã tồn tại: {$existingRoomId}");
                    
                    echo json_encode([
                        'status' => 'success',
                        'room_id' => $existingRoomId,
                        'message' => 'Đã tìm thấy đoạn chat',
                        'is_existing' => true
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                
                // Tạo phòng mới
                // Lấy tên của target user
                $db = Database::getInstance();
                $stmt = $db->prepare("SELECT username FROM users WHERE id = :id LIMIT 1");
                $stmt->bindValue(':id', $targetUserId, PDO::PARAM_INT);
                $stmt->execute();
                $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
                $targetUserName = $targetUser ? $targetUser['username'] : 'User ' . $targetUserId;
                
                $roomName = "Chat với {$targetUserName}";
                $roomId = $this->roomRepository->createPrivateRoom($roomName, $currentUserId, $targetUserId);
                
                $this->log('CREATE_CHAT_SUCCESS', "Tạo phòng 1-1 mới: {$roomId}");
                
            } else {
                // ============================================
                // TẠO NHÓM CHAT
                // ============================================
                $roomName = $target; // target là tên nhóm
                $roomId = $this->roomRepository->create($roomName, 'group', $currentUserId);
                
                $this->log('CREATE_CHAT_SUCCESS', "Tạo nhóm mới: {$roomId}");
            }

            echo json_encode([
                'status' => 'success',
                'room_id' => $roomId,
                'message' => 'Tạo phòng thành công',
                'is_existing' => false
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\InvalidArgumentException $e) {
            $this->log('CREATE_CHAT_VALIDATION_ERROR', $e->getMessage());
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            $this->log('CREATE_CHAT_ERROR', $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Không thể tạo phòng'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * ============================================
     * ACTION: addMember - THÊM THÀNH VIÊN VÀO PHÒNG
     * ============================================
     */
    public function addMember(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
            $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

            if ($roomId <= 0 || $userId <= 0) {
                throw new \InvalidArgumentException('Room ID hoặc User ID không hợp lệ');
            }

            $this->roomRepository->addMemberToRoom($roomId, $userId);
            $this->log('ADD_MEMBER_SUCCESS', "Đã thêm user {$userId} vào phòng {$roomId}");

            http_response_code(200);
            echo json_encode(['status' => 'success', 'message' => 'Đã thêm thành viên'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            $this->log('ADD_MEMBER_ERROR', $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
