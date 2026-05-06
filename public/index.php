<?php
/**
 * ============================================
 * FILE: public/index.php
 * ENTRY POINT - ĐIỂM VÀO CỦA ỨNG DỤNG
 * ============================================
 * 
 * Nhiệm vụ:
 * 1. Bật báo lỗi để debug (chỉ trong môi trường development)
 * 2. Định nghĩa các hằng số (BASE_PATH, URLROOT)
 * 3. Autoload các class
 * 4. Khởi tạo database
 * 5. Routing: Gọi controller và action tương ứng
 * 
 * ⚠️ QUAN TRỌNG: 
 * - KHÔNG được echo/print bất kỳ thứ gì ngoài luồng xử lý chính
 * - Tránh làm hỏng JSON response của API endpoints
 */

// ============================================
// BƯỚC 1: CẤU HÌNH BÁO LỖI
// ============================================
// Bật báo lỗi để debug (nên tắt trong production)
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ============================================
// BƯỚC 2: BẮT MỌI LỖI CHÍ MẠNG
// ============================================
try {
    
    // ============================================
    // BƯỚC 3: ĐỊNH NGHĨA ĐƯỜNG DẪN VÀ URL
    // ============================================
    // BASE_PATH: Đường dẫn tuyệt đối đến thư mục gốc của project
    define('BASE_PATH', dirname(__DIR__));
    
    // URLROOT: URL gốc của ứng dụng (bao gồm /public)
    // 🔥 QUAN TRỌNG: Phải có /public ở cuối vì file index.php nằm trong /public
    define('URLROOT', 'http://localhost/chat-app-php-mvc/public');
    
    // ============================================
    // BƯỚC 4: AUTOLOADER
    // ============================================
    // Tự động load các class khi được gọi
    // Tìm kiếm trong các thư mục: core, models, controllers, repositories, decorators, traits
    spl_autoload_register(function ($class) {
        // Hỗ trợ namespace: App\Models\User → lấy 'User'
        $parts     = explode('\\', $class);
        $className = end($parts);

        $dirs = ['core', 'models', 'controllers', 'repositories', 'decorators', 'traits'];
        foreach ($dirs as $dir) {
            $file = BASE_PATH . "/app/$dir/$className.php";
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
        throw new Exception("AUTOLOADER: Không tìm thấy class '$class'");
    });
    
    // ============================================
    // BƯỚC 5: KHỞI TẠO DATABASE
    // ============================================
    // Lấy singleton instance của Database
    $db = Database::getInstance();
    
    // ============================================
    // BƯỚC 6: ROUTING - XÁC ĐỊNH CONTROLLER VÀ ACTION
    // ============================================
    // Lấy controller từ query string, mặc định là 'chat'
    $controllerName = ucfirst($_GET['controller'] ?? 'chat') . 'Controller';
    
    // Lấy action từ query string, mặc định là 'index'
    $action = $_GET['action'] ?? 'index';
    
    // ============================================
    // BƯỚC 7: VALIDATE CONTROLLER VÀ ACTION
    // ============================================
    // Kiểm tra class controller có tồn tại không
    if (!class_exists($controllerName)) {
        throw new Exception("ROUTING: Class '$controllerName' không tồn tại");
    }
    
    // Kiểm tra method action có tồn tại trong controller không
    if (!method_exists($controllerName, $action)) {
        throw new Exception("ROUTING: Method '$action' không tồn tại trong '$controllerName'");
    }
    
    // ============================================
    // BƯỚC 8: KHỞI TẠO VÀ CHẠY CONTROLLER
    // ============================================
    // Khởi tạo controller
    $controller = new $controllerName();
    
    // Gọi method action
    $controller->$action();
    
    // ⚠️ KHÔNG ECHO GÌ Ở ĐÂY - Để controller tự xử lý output
    
} catch (\Throwable $e) {
    // ============================================
    // XỬ LÝ LỖI CHÍ MẠNG
    // ============================================
    // Kiểm tra xem có phải AJAX request không
    $isAjaxRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
                     && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    // Hoặc kiểm tra xem có phải API endpoint không (action = send, delete, update, etc.)
    $isApiEndpoint = isset($_GET['action']) && in_array($_GET['action'], ['send', 'delete', 'update', 'create']);
    
    if ($isAjaxRequest || $isApiEndpoint) {
        // ============================================
        // TRẢ VỀ JSON ERROR CHO AJAX/API REQUEST
        // ============================================
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        
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
        
        exit; // Dừng ngay sau khi trả về JSON
        
    } else {
        // ============================================
        // HIỂN THỊ HTML ERROR CHO BROWSER REQUEST
        // ============================================
        echo '<!DOCTYPE html>';
        echo '<html><head><meta charset="utf-8"><title>Lỗi hệ thống</title></head><body>';
        echo '<h1 style="color:red;">🔥 LỖI CHÍ MẠNG 🔥</h1>';
        echo '<h2>Thông báo lỗi:</h2>';
        echo '<p style="background:#ffcccc;padding:10px;border:2px solid red;font-size:16px;">';
        echo '<strong>' . htmlspecialchars($e->getMessage()) . '</strong>';
        echo '</p>';
        echo '<h3>Chi tiết:</h3>';
        echo '<ul>';
        echo '<li><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . '</li>';
        echo '<li><strong>Dòng:</strong> ' . $e->getLine() . '</li>';
        echo '<li><strong>Loại lỗi:</strong> ' . get_class($e) . '</li>';
        echo '</ul>';
        echo '<h3>Stack Trace:</h3>';
        echo '<pre style="background:#f0f0f0;padding:10px;overflow:auto;">';
        echo htmlspecialchars($e->getTraceAsString());
        echo '</pre>';
        echo '</body></html>';
        
        exit; // Dừng ngay sau khi hiển thị lỗi
    }
}
