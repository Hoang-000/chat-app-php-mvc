<?php

/**
 * Class Controller
 *
 * Base class cho toàn bộ controller trong hệ thống MVC.
 * Cung cấp phương thức view() để render template từ thư mục app/views/.
 */
class Controller
{
    /**
     * Render một view và truyền dữ liệu xuống template.
     *
     * @param string $view Đường dẫn view tương đối (ví dụ: 'chat/index')
     * @param array  $data Mảng dữ liệu sẽ được extract thành biến trong view
     * @return void
     */
    protected function view(string $view, array $data = []): void
    {
        // extract tạo biến riêng lẻ: $roomId, $rooms, $messages...
        // đồng thời giữ nguyên $data để view có thể dùng cả 2 cách
        extract($data);

        $viewFile = BASE_PATH . '/app/views/' . $view . '.php';

        if (!file_exists($viewFile)) {
            http_response_code(500);
            die("View không tồn tại: {$viewFile}");
        }

        require_once $viewFile;
    }
}
