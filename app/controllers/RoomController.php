<?php

class RoomController extends Controller
{
    private RoomRepository $roomRepository;

    public function __construct(?RoomRepository $roomRepository = null)
    {
        parent::__construct();
        $this->roomRepository = $roomRepository ?? new RoomRepository();
    }

    public function index(): void
    {
        try {
            $currentUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $rooms = $this->roomRepository->getAllRooms($currentUserId, 'all');

            $data = [
                'rooms' => $rooms,
                'currentUserId' => $currentUserId,
                'URLROOT' => URLROOT
            ];

            $this->view('room/index', $data);

        } catch (\Exception $e) {
            http_response_code(500);
            echo "Đã xảy ra lỗi: " . htmlspecialchars($e->getMessage());
        }
    }

    public function create(): void
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

            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            if (empty($roomName)) {
                throw new \InvalidArgumentException('Tên phòng không được rỗng');
            }

            if (!in_array($type, ['private', 'group'])) {
                throw new \InvalidArgumentException('Loại phòng không hợp lệ');
            }

            $roomId = $this->roomRepository->create($roomName, $type, $currentUserId);

            http_response_code(200);
            echo json_encode(['status' => 'success', 'room_id' => $roomId, 'message' => 'Tạo phòng thành công'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể tạo phòng'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function delete(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
            $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

            if ($roomId <= 0 || $userId <= 0) {
                throw new \InvalidArgumentException('Room ID hoặc User ID không hợp lệ');
            }

            $this->roomRepository->removeUserFromRoom($roomId, $userId);

            http_response_code(200);
            echo json_encode(['status' => 'success', 'message' => 'Đã xóa phòng thành công'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function addMember(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'Chỉ chấp nhận POST request'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $roomId = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $currentUserId = $_SESSION['user_id'] ?? 0;

            if ($roomId <= 0 || $userId <= 0) {
                throw new \InvalidArgumentException('Room ID hoặc User ID không hợp lệ');
            }
            
            if ($currentUserId <= 0) {
                throw new \InvalidArgumentException('Bạn chưa đăng nhập');
            }
            
            // Kiểm tra phòng có tồn tại và là group không
            $room = $this->roomRepository->getRoomById($roomId);
            if (!$room) {
                throw new \RuntimeException('Không tìm thấy phòng');
            }
            
            if ($room['type'] !== 'group') {
                throw new \RuntimeException('Chỉ có thể thêm thành viên vào nhóm chat');
            }
            
            // Kiểm tra current user có trong phòng không
            if (!$this->roomRepository->isUserInRoom($roomId, $currentUserId)) {
                throw new \RuntimeException('Bạn không phải thành viên của nhóm này');
            }
            
            // Kiểm tra user đã trong phòng chưa
            if ($this->roomRepository->isUserInRoom($roomId, $userId)) {
                throw new \RuntimeException('Người dùng đã là thành viên của nhóm');
            }

            $this->roomRepository->addMemberToRoom($roomId, $userId);

            http_response_code(200);
            echo json_encode(['status' => 'success', 'message' => 'Đã thêm thành viên'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function getMembers(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

            if ($roomId <= 0) {
                throw new \InvalidArgumentException('Room ID không hợp lệ');
            }

            $members = $this->roomRepository->getRoomMembers($roomId);

            http_response_code(200);
            echo json_encode(['status' => 'success', 'data' => $members], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
