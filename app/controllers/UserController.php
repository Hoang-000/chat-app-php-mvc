<?php

class UserController extends Controller
{
    private UserRepository $userRepository;

    public function __construct(?UserRepository $userRepository = null)
    {
        parent::__construct();
        $this->userRepository = $userRepository ?? new UserRepository();
    }

    /**
     * Action: Login
     * GET: Hiển thị form chọn user
     * POST: Xử lý đăng nhập
     */
    public function login(): void
    {
        // Nếu đã login rồi thì redirect về chat
        if (isset($_SESSION['user_id'])) {
            header('Location: index.php?controller=chat&action=index&user_id=' . $_SESSION['user_id']);
            exit;
        }

        // POST: Xử lý đăng nhập
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

            if ($userId > 0) {
                $_SESSION['user_id'] = $userId;
                header('Location: index.php?controller=chat&action=index&user_id=' . $userId);
                exit;
            }

            $error = 'Vui lòng chọn user!';
        }

        // GET: Hiển thị form login
        $users = $this->userRepository->getAllUsers();
        $this->view('user/login', ['users' => $users, 'error' => $error ?? null]);
    }

    /**
     * Action: Logout
     */
    public function logout(): void
    {
        session_destroy();
        header('Location: index.php?controller=user&action=login');
        exit;
    }

    public function search(): void
    {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';

            if (empty($keyword)) {
                throw new \InvalidArgumentException('Keyword không được rỗng');
            }

            if (mb_strlen($keyword) < 2) {
                throw new \InvalidArgumentException('Keyword phải có ít nhất 2 ký tự');
            }

            $db = Database::getInstance();
            $sql = "SELECT id, username, email, created_at FROM users WHERE username LIKE :keyword1 OR email LIKE :keyword2 ORDER BY username ASC LIMIT 20";

            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException('Không thể prepare query');
            }

            $searchPattern = '%' . $keyword . '%';
            $stmt->bindValue(':keyword1', $searchPattern, PDO::PARAM_STR);
            $stmt->bindValue(':keyword2', $searchPattern, PDO::PARAM_STR);

            if (!$stmt->execute()) {
                throw new \RuntimeException('Không thể execute query');
            }

            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            echo json_encode(['status' => 'success', 'data' => $users, 'count' => count($users), 'message' => 'Tìm kiếm thành công'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể tìm kiếm user', 'code' => 'RUNTIME_ERROR'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function getById(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

            if ($userId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $user = $this->userRepository->findById($userId);

            if (!$user) {
                throw new \RuntimeException('Không tìm thấy user');
            }

            http_response_code(200);
            echo json_encode(['status' => 'success', 'data' => ['id' => $user->getId(), 'username' => $user->getName()]], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public function getAll(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $users = $this->userRepository->getAllUsers();

            $formattedUsers = [];
            foreach ($users as $user) {
                $formattedUsers[] = [
                    'id' => $user->getId(),
                    'username' => $user->getName()
                ];
            }

            http_response_code(200);
            echo json_encode(['status' => 'success', 'data' => $formattedUsers, 'count' => count($formattedUsers)], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể lấy danh sách user'], JSON_UNESCAPED_UNICODE);
            exit;
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

            $username = isset($input['username']) ? trim($input['username']) : '';
            $password = isset($input['password']) ? trim($input['password']) : '';

            if (empty($username) || empty($password)) {
                throw new \InvalidArgumentException('Username và password không được rỗng');
            }

            $userId = $this->userRepository->create($username, $password);

            http_response_code(200);
            echo json_encode(['status' => 'success', 'user_id' => $userId, 'message' => 'Tạo user thành công'], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Không thể tạo user'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
