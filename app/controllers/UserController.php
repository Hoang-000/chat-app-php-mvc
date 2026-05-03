<?php

/**
 * ============================================
 * CLASS: UserController
 * ============================================
 * Controller xử lý các thao tác liên quan đến User
 * 
 * Responsibilities:
 * - Tìm kiếm user theo keyword
 * - Lấy thông tin user
 * 
 * @package App\Controllers
 */
class UserController 
{
    use LoggerTrait;
    
    private PDO $db;

    public function __construct() 
    {
        $this->initLogger();
        $this->log('USER_CONTROLLER_INIT', 'UserController đang khởi tạo...');
        
        try {
            $dbInstance = Database::getInstance();
            
            if ($dbInstance === null || !($dbInstance instanceof PDO)) {
                throw new \RuntimeException('Không thể kết nối database');
            }

            $this->db = $dbInstance;
            $this->log('USER_CONTROLLER_INIT_SUCCESS', 'Database đã kết nối thành công');
            
        } catch (\Throwable $e) {
            $this->log('USER_CONTROLLER_INIT_ERROR', 'Lỗi khởi tạo: ' . $e->getMessage());
            throw new \RuntimeException('UserController không thể khởi tạo: ' . $e->getMessage());
        }
    }

    /**
     * ============================================
     * ACTION: search - TÌM KIẾM USER
     * ============================================
     * API tìm kiếm user theo username hoặc email
     * 
     * QUERY PARAMETERS:
     * - keyword: Từ khóa tìm kiếm (bắt buộc, tối thiểu 2 ký tự)
     * 
     * RESPONSE JSON:
     * {
     *   "status": "success",
     *   "data": [
     *     {"id": 1, "username": "john", "email": "john@example.com"},
     *     ...
     *   ],
     *   "count": 5
     * }
     * 
     * @return void
     */
    public function search(): void
    {
        // Xóa output buffer
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        // Set header JSON
        header('Content-Type: application/json; charset=utf-8');

        try {
            // ============================================
            // BƯỚC 1: LẤY VÀ VALIDATE KEYWORD
            // ============================================
            $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';

            if (empty($keyword)) {
                throw new \InvalidArgumentException('Keyword không được rỗng');
            }

            if (mb_strlen($keyword) < 2) {
                throw new \InvalidArgumentException('Keyword phải có ít nhất 2 ký tự');
            }

            $this->log('SEARCH_USER_REQUEST', "keyword={$keyword}");

            // ============================================
            // BƯỚC 2: TÌM KIẾM USER TRONG DATABASE
            // ============================================
            // SQL: Tìm theo username hoặc email (LIKE %keyword%)
            $sql = "
                SELECT 
                    id,
                    username,
                    email,
                    created_at
                FROM users
                WHERE username LIKE :keyword1
                   OR email LIKE :keyword2
                ORDER BY username ASC
                LIMIT 20
            ";

            $stmt = $this->db->prepare($sql);
            
            if ($stmt === false) {
                throw new \RuntimeException('Không thể prepare query');
            }

            // Bind parameters với wildcard %keyword%
            $searchPattern = '%' . $keyword . '%';
            $stmt->bindValue(':keyword1', $searchPattern, PDO::PARAM_STR);
            $stmt->bindValue(':keyword2', $searchPattern, PDO::PARAM_STR);

            if (!$stmt->execute()) {
                throw new \RuntimeException('Không thể execute query');
            }

            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->log('SEARCH_USER_SUCCESS', "Tìm thấy " . count($users) . " user");

            // ============================================
            // BƯỚC 3: TRẢ VỀ JSON
            // ============================================
            http_response_code(200);
            
            echo json_encode([
                'status' => 'success',
                'data' => $users,
                'count' => count($users),
                'message' => 'Tìm kiếm thành công'
            ], JSON_UNESCAPED_UNICODE);
            
            exit;

        } catch (\InvalidArgumentException $e) {
            $this->log('SEARCH_USER_VALIDATION_ERROR', $e->getMessage());
            
            http_response_code(400);
            
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR'
            ], JSON_UNESCAPED_UNICODE);
            
            exit;

        } catch (\Throwable $e) {
            $this->log('SEARCH_USER_ERROR', $e->getMessage());
            
            http_response_code(500);
            
            echo json_encode([
                'status' => 'error',
                'message' => 'Không thể tìm kiếm user. Vui lòng thử lại.',
                'code' => 'RUNTIME_ERROR',
                'details' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            
            exit;
        }
    }

    /**
     * ============================================
     * ACTION: getById - LẤY THÔNG TIN USER THEO ID
     * ============================================
     * 
     * @return void
     */
    public function getById(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

            if ($userId <= 0) {
                throw new \InvalidArgumentException('User ID không hợp lệ');
            }

            $sql = "SELECT id, username, email, created_at FROM users WHERE id = :user_id LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new \RuntimeException('Không tìm thấy user');
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'data' => $user
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
}
