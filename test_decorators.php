<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>TEST DECORATOR PATTERN - CHAT APP</h1>";

require_once __DIR__ . '/app/decorators/MessageDecorator.php';
require_once __DIR__ . '/app/decorators/EmojiDecorator.php';
require_once __DIR__ . '/app/decorators/MentionDecorator.php';
require_once __DIR__ . '/app/models/BaseMessageAdapter.php';

class MockMessage {
    private int $id, $userId;
    private string $content, $createdAt;
    public function __construct(int $id, int $userId, string $content) {
        $this->id = $id; $this->userId = $userId; $this->content = $content;
        $this->createdAt = date('Y-m-d H:i:s');
    }
    public function getId(): int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getContent(): string { return $this->content; }
    public function getCreatedAt(): string { return $this->createdAt; }
}

echo "<h2>Test EmojiDecorator</h2>";
$msg1 = new MockMessage(1, 1, "Hello :) How are you :D? :heart:");
$adapted1 = new BaseMessageAdapter($msg1);
$emoji = new EmojiDecorator($adapted1);
echo "<strong>Gốc:</strong> " . htmlspecialchars($msg1->getContent()) . "<br>";
echo "<strong>Sau Emoji:</strong> " . htmlspecialchars($emoji->getContent()) . "<br>";

echo "<h2>Test MentionDecorator</h2>";
$msg2 = new MockMessage(2, 1, "Hey @alice and @bob_123");
$adapted2 = new BaseMessageAdapter($msg2);
$mention = new MentionDecorator($adapted2);
echo "<strong>Gốc:</strong> " . htmlspecialchars($msg2->getContent()) . "<br>";
echo "<strong>Sau Mention:</strong> " . $mention->getContent() . "<br>";

echo "<h2>Test Chain (Emoji + Mention)</h2>";
$msg3 = new MockMessage(3, 1, "Chào @lead :) :fire:");
$adapted3 = new BaseMessageAdapter($msg3);
$chained = new MentionDecorator(new EmojiDecorator($adapted3));
echo "<strong>Gốc:</strong> " . htmlspecialchars($msg3->getContent()) . "<br>";
echo "<strong>Sau Chain:</strong> " . $chained->getContent() . "<br>";

echo "<h2>Log file</h2>";
$log = __DIR__ . '/logs/chat.log';
if (file_exists($log)) {
    echo "✅ Log file tồn tại.<br><pre>" . htmlspecialchars(file_get_contents($log)) . "</pre>";
} else {
    echo "❌ Chưa có log. Kiểm tra quyền ghi thư mục logs/";
}

echo "<hr><h2 style='color:green'>✅ TEST HOÀN TẤT</h2>";