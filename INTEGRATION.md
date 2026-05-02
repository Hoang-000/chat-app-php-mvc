# INTEGRATION.md
## Hướng Dẫn Tích Hợp Decorator Pattern vào Dự Án Chat App

> **Tác giả:** Thành viên phụ trách Decorator Pattern  
> **Phiên bản PHP yêu cầu:** 8.0+  
> **Cập nhật lần cuối:** 2025

---

## 1. Tổng quan kiến trúc

```
Luồng xử lý tin nhắn (Message Processing Pipeline):

  BaseMessage (model gốc, KHÔNG SỬA)
       │
       ▼
  BaseMessageAdapter  ←── Adapter Pattern: giúp BaseMessage tương thích
       │                  với interface MessageDecorator
       ▼
  EmojiDecorator  ←── Decorator: chuyển :) → 😊, :heart: → ❤️
       │
       ▼
  MentionDecorator  ←── Decorator: chuyển @alice → <a href="/profile/alice">
       │
       ▼
  Controller lấy getContent() → View hiển thị
```

**Điểm quan trọng:**  
- `BaseMessage.php` gốc **KHÔNG bị sửa đổi**  
- Các decorator có thể **xếp chồng (chain)** theo bất kỳ thứ tự nào  
- Mọi hành động đều được **ghi log tự động** vào `logs/chat.log`

---

## 2. Cấu trúc file cần require

```php
// Bắt buộc require theo thứ tự này
require_once __DIR__ . '/app/decorators/MessageDecorator.php';   // Interface
require_once __DIR__ . '/app/traits/LoggerTrait.php';            // Trait log
require_once __DIR__ . '/app/decorators/EmojiDecorator.php';     // Decorator emoji
require_once __DIR__ . '/app/decorators/MentionDecorator.php';   // Decorator mention
require_once __DIR__ . '/app/models/BaseMessageAdapter.php';     // Adapter
```

---

## 3. Cách tích hợp vào Controller

### 3.1 Controller gửi tin nhắn (ví dụ: MessageController.php)

```php
<?php
// Trong MessageController::show() hoặc MessageController::index()

require_once __DIR__ . '/../app/decorators/MessageDecorator.php';
require_once __DIR__ . '/../app/traits/LoggerTrait.php';
require_once __DIR__ . '/../app/decorators/EmojiDecorator.php';
require_once __DIR__ . '/../app/decorators/MentionDecorator.php';
require_once __DIR__ . '/../app/models/BaseMessageAdapter.php';

class MessageController
{
    public function getMessages(int $roomId): array
    {
        // Lấy tin nhắn từ database (giả sử trả về mảng BaseMessage)
        $messages = Message::findByRoom($roomId);

        // Áp dụng decorator cho từng tin nhắn
        $decorated = [];
        foreach ($messages as $message) {
            // Bước 1: Bọc BaseMessage bằng Adapter
            $adapted = new BaseMessageAdapter($message);

            // Bước 2: Áp dụng decorator theo thứ tự mong muốn
            // Emoji trước → Mention sau (khuyến nghị)
            $decorated[] = new MentionDecorator(
                new EmojiDecorator($adapted)
            );
        }

        return $decorated;
    }

    // Trong API endpoint trả về JSON
    public function apiGetMessages(): void
    {
        $messages  = $this->getMessages((int)$_GET['room_id']);
        $result    = [];

        foreach ($messages as $msg) {
            $result[] = [
                'id'         => $msg->getId(),
                'user_id'    => $msg->getUserId(),
                'content'    => $msg->getContent(),   // ← Nội dung đã được xử lý
                'created_at' => $msg->getCreatedAt(),
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }
}
```

### 3.2 Chỉ dùng một decorator

```php
// Chỉ dùng EmojiDecorator (không cần mention)
$adapted    = new BaseMessageAdapter($baseMessage);
$withEmoji  = new EmojiDecorator($adapted);
echo $withEmoji->getContent();

// Chỉ dùng MentionDecorator (không cần emoji)
$adapted      = new BaseMessageAdapter($baseMessage);
$withMention  = new MentionDecorator($adapted);
echo $withMention->getContent();
```

---

## 4. Cách tích hợp vào Model

Nếu muốn tích hợp trực tiếp vào model (không khuyến nghị vì vi phạm SRP),
có thể thêm factory method vào model của bạn:

```php
<?php
// Trong file Message.php hoặc model tương đương của nhóm

class Message extends BaseMessage
{
    /**
     * Trả về đối tượng tin nhắn đã được áp dụng toàn bộ decorator.
     * Factory method giúp đóng gói logic decoration.
     */
    public function decorate(): MessageDecorator
    {
        require_once __DIR__ . '/../decorators/MessageDecorator.php';
        require_once __DIR__ . '/../traits/LoggerTrait.php';
        require_once __DIR__ . '/../decorators/EmojiDecorator.php';
        require_once __DIR__ . '/../decorators/MentionDecorator.php';
        require_once __DIR__ . '/BaseMessageAdapter.php';

        $adapted = new BaseMessageAdapter($this);
        return new MentionDecorator(new EmojiDecorator($adapted));
    }
}

// Sử dụng trong controller:
$decoratedMsg = $message->decorate();
echo $decoratedMsg->getContent();
```

---

## 5. Cách tích hợp vào View

### 5.1 View PHP thuần (file .php)

```php
<!-- Trong file view hiển thị danh sách tin nhắn, ví dụ: chat.php -->
<?php foreach ($messages as $message): ?>
    <div class="message" data-id="<?= $message->getId() ?>">
        <span class="username">User #<?= $message->getUserId() ?></span>
        <span class="time"><?= $message->getCreatedAt() ?></span>

        <!-- QUAN TRỌNG: Dùng echo không thoát HTML vì MentionDecorator
             đã tạo ra <a href="..."> cần được render là HTML thật -->
        <div class="content"><?= $message->getContent() ?></div>
        <!--                  ↑ KHÔNG dùng htmlspecialchars() ở đây!    -->
    </div>
<?php endforeach; ?>
```

> ⚠️ **Lưu ý bảo mật:** `MentionDecorator` đã xử lý XSS bên trong (dùng
> `htmlspecialchars` cho username). Nhưng bạn phải đảm bảo nội dung tin nhắn
> gốc đã được sanitize TRƯỚC khi lưu vào database để tránh XSS injection.

### 5.2 Thêm CSS cho mention link

```css
/* Thêm vào file CSS của dự án */
.mention {
    color: #1a73e8;
    font-weight: 600;
    text-decoration: none;
    background-color: rgba(26, 115, 232, 0.1);
    padding: 1px 4px;
    border-radius: 3px;
}

.mention:hover {
    text-decoration: underline;
    background-color: rgba(26, 115, 232, 0.2);
}
```

---

## 6. Cách thêm emoji shortcode mới

```php
$adapted    = new BaseMessageAdapter($baseMessage);
$emojiDec   = new EmojiDecorator($adapted);

// Thêm shortcode tuỳ chỉnh (method có thể chain)
$emojiDec->addEmojiMapping(':vn:', '🇻🇳')
         ->addEmojiMapping(':code:', '💻')
         ->addEmojiMapping(':bug:', '🐛');

echo $emojiDec->getContent();
```

---

## 7. Đọc và kiểm tra file log

File log được ghi tự động tại: `logs/chat.log`

```
[2025-01-15 10:30:45] [EmojiDecorator] DECORATION_START: MessageID=1 | Nội dung gốc: Xin chào :)
[2025-01-15 10:30:45] [EmojiDecorator] DECORATION_DONE: MessageID=1 | Nội dung sau xử lý: Xin chào 😊
[2025-01-15 10:30:45] [MentionDecorator] DECORATION_START: MessageID=1 | Nội dung gốc: Xin chào 😊
[2025-01-15 10:30:45] [MentionDecorator] MENTIONS_FOUND: MessageID=1 | Tìm thấy 1 mention: @alice
[2025-01-15 10:30:45] [MentionDecorator] DECORATION_DONE: MessageID=1 | Nội dung sau xử lý: ...
```

**Lệnh xem log real-time (trên server):**
```bash
# Xem 50 dòng log cuối cùng
tail -50 logs/chat.log

# Theo dõi log real-time
tail -f logs/chat.log

# Lọc log của EmojiDecorator
grep "EmojiDecorator" logs/chat.log

# Lọc log của MentionDecorator
grep "MentionDecorator" logs/chat.log

# Đếm số lần decoration trong ngày hôm nay
grep "$(date +%Y-%m-%d)" logs/chat.log | grep "DECORATION_DONE" | wc -l
```

---

## 8. Chạy script kiểm thử

Từ thư mục gốc dự án (nơi có `test_decorators.php`):

```bash
php test_decorators.php
```

Kết quả mong đợi:
```
✅ PASS | ':)' → '😊'
✅ PASS | ':D' → '😃'
✅ PASS | ':heart:' → '❤️'
✅ PASS | @alice được chuyển thành link
✅ PASS | File log đã được tạo: logs/chat.log
```

---

## 9. Xử lý lỗi thường gặp

| Lỗi | Nguyên nhân | Giải pháp |
|-----|-------------|-----------|
| `Class 'BaseMessageAdapter' not found` | Chưa require file | Thêm `require_once 'app/models/BaseMessageAdapter.php'` |
| `Interface 'MessageDecorator' not found` | Sai thứ tự require | Require `MessageDecorator.php` TRƯỚC các decorator |
| `InvalidArgumentException: phải có phương thức getId()` | BaseMessage gốc không có method cần thiết | Kiểm tra BaseMessage có đủ 4 method: `getId`, `getUserId`, `getContent`, `getCreatedAt` |
| Log không được ghi | Permission thư mục | Chạy `chmod 755 logs/` hoặc tạo thư mục `logs/` thủ công |
| Emoji không hiện đúng | Encoding trang web | Thêm `<meta charset="UTF-8">` vào HTML |
| `@username` không thành link | Username ngắn hơn 3 ký tự | Đây là thiết kế cố ý — username hợp lệ phải từ 3 ký tự trở lên |

---

## 10. Danh sách emoji shortcode hỗ trợ

| Shortcode | Emoji | Shortcode | Emoji |
|-----------|-------|-----------|-------|
| `:)` hoặc `:-)` | 😊 | `:happy:` | 🎉 |
| `:D` hoặc `:-D` | 😃 | `:heart:` | ❤️ |
| `:(` hoặc `:-(` | 😞 | `:fire:` | 🔥 |
| `:P` hoặc `:-P` | 😛 | `:star:` | ⭐ |
| `;)` hoặc `;-)` | 😉 | `:thumbs:` | 👍 |
| `:O` hoặc `:-O` | 😮 | `:clap:` | 👏 |
| `:*` | 😘 | `:laugh:` | 😂 |
| `B)` | 😎 | `:rocket:` | 🚀 |
| `:sad:` | 😢 | `:check:` | ✅ |
| `:cry:` | 😭 | `:cross:` | ❌ |
| `:wave:` | 👋 | `:warning:` | ⚠️ |
| `:cake:` | 🎂 | `:gift:` | 🎁 |

---

*Nếu có thắc mắc, liên hệ thành viên phụ trách phần Decorator Pattern của nhóm.*
