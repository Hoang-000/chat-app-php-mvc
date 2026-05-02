<?php

/**
 * Trait LoggerTrait
 * 
 * Cung cấp chức năng ghi log cho các decorator.
 * Tự động tạo thư mục logs/ nếu chưa tồn tại.
 * Ghi log mọi hành động decoration vào file logs/chat.log
 * 
 * Cách dùng: use LoggerTrait; trong bất kỳ class decorator nào.
 * 
 * @package App\Traits
 */
trait LoggerTrait
{
    /**
     * Đường dẫn đến file log.
     * Được tính tương đối từ thư mục gốc của dự án.
     */
    private string $logFile = '';

    /**
     * Khởi tạo đường dẫn log và tạo thư mục nếu chưa có.
     * Gọi hàm này trong constructor của class sử dụng trait.
     *
     * @return void
     */
    private function initLogger(): void
    {
        // Tính đường dẫn tuyệt đối đến thư mục logs/ từ thư mục gốc dự án
        $projectRoot = dirname(__DIR__, 2); // Lùi 2 cấp: decorators → app → project root
        $logsDir     = $projectRoot . DIRECTORY_SEPARATOR . 'logs';

        // Tự động tạo thư mục logs/ nếu chưa tồn tại
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }

        $this->logFile = $logsDir . DIRECTORY_SEPARATOR . 'chat.log';
    }

    /**
     * Ghi một dòng log vào file logs/chat.log.
     * Định dạng: [YYYY-MM-DD HH:MM:SS] [TÊN_CLASS] ACTION: nội_dung
     *
     * @param string $action  Tên hành động đang được thực hiện (ví dụ: EMOJI_DECORATED)
     * @param string $message Nội dung mô tả chi tiết của hành động
     * @return void
     */
    private function log(string $action, string $message): void
    {
        // Đảm bảo logger đã được khởi tạo
        if (empty($this->logFile)) {
            $this->initLogger();
        }

        // Lấy tên class hiện tại để phân biệt log từ các decorator khác nhau
        $className = static::class;

        // Tạo dòng log với timestamp, tên class, action và nội dung
        $timestamp = date('Y-m-d H:i:s');
        $logEntry  = "[{$timestamp}] [{$className}] {$action}: {$message}" . PHP_EOL;

        // Ghi vào file (chế độ append - thêm vào cuối file, không ghi đè)
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Ghi log khi bắt đầu quá trình decoration.
     *
     * @param int    $messageId ID của tin nhắn đang được xử lý
     * @param string $original  Nội dung gốc trước khi decoration
     * @return void
     */
    private function logStart(int $messageId, string $original): void
    {
        $this->log(
            'DECORATION_START',
            "MessageID={$messageId} | Nội dung gốc: " . mb_substr($original, 0, 100)
        );
    }

    /**
     * Ghi log sau khi decoration hoàn tất.
     *
     * @param int    $messageId ID của tin nhắn vừa được xử lý
     * @param string $decorated Nội dung sau khi decoration
     * @return void
     */
    private function logDone(int $messageId, string $decorated): void
    {
        $this->log(
            'DECORATION_DONE',
            "MessageID={$messageId} | Nội dung sau xử lý: " . mb_substr($decorated, 0, 100)
        );
    }
}
