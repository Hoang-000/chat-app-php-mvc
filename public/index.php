<?php
/**
 * Entry Point - Điểm vào ứng dụng
 */

// Bật báo lỗi (tắt trong production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    // Định nghĩa đường dẫn
    define('BASE_PATH', dirname(__DIR__));
    define('URLROOT', 'http://localhost/chat-app-php-mvc/public');
    
    // Autoloader
    spl_autoload_register(function ($class) {
        $parts = explode('\\', $class);
        $className = end($parts);

        $dirs = ['core', 'models', 'controllers', 'repositories', 'decorators', 'traits'];
        foreach ($dirs as $dir) {
            $file = BASE_PATH . "/app/$dir/$className.php";
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
        throw new Exception("Class '$class' không tìm thấy");
    });
    
    // Khởi tạo session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Khởi tạo database
    $db = Database::getInstance();
    
    // Routing
    $controller = $_GET['controller'] ?? 'chat';
    $controllerName = ucfirst($controller) . 'Controller';
    $action = $_GET['action'] ?? 'index';
    
    // Middleware: Redirect về login nếu chưa đăng nhập và không phải UserController
    if (!isset($_SESSION['user_id']) && $controller !== 'user') {
        header('Location: index.php?controller=user&action=login');
        exit;
    }
    
    // Validate
    if (!class_exists($controllerName)) {
        throw new Exception("Controller '$controllerName' không tồn tại");
    }
    
    if (!method_exists($controllerName, $action)) {
        throw new Exception("Action '$action' không tồn tại");
    }
    
    // Chạy controller
    $controller = new $controllerName();
    $controller->$action();
    
} catch (\Throwable $e) {
    // Kiểm tra AJAX/API request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
              && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    $isApi = isset($_GET['action']) && in_array($_GET['action'], ['send', 'delete', 'update', 'create']);
    
    if ($isAjax || $isApi) {
        // Trả về JSON error
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        
        echo json_encode([
            'status' => 'error',
            'message' => 'Lỗi hệ thống',
            'details' => [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
    
    // Hiển thị HTML error
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Lỗi</title></head><body>';
    echo '<h1 style="color:red;">Lỗi hệ thống</h1>';
    echo '<p style="background:#ffcccc;padding:10px;border:2px solid red;">';
    echo '<strong>' . htmlspecialchars($e->getMessage()) . '</strong></p>';
    echo '<h3>Chi tiết:</h3><ul>';
    echo '<li><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . '</li>';
    echo '<li><strong>Line:</strong> ' . $e->getLine() . '</li>';
    echo '</ul>';
    echo '<pre style="background:#f0f0f0;padding:10px;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</body></html>';
    exit;
}
