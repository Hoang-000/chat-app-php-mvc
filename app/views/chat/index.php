<?php
$currentUserId = $data['currentUserId'] ?? 1;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($data['title'] ?? 'Chat App') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $data['URLROOT'] ?>/css/chat.css">
</head>
<body>
    <div class="app-container">
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1>Messages</h1>
                <div class="header-actions">
                    <button class="icon-btn" id="theme-toggle" title="Chuyển đổi sáng/tối">
                        <i class="fas fa-sun"></i>
                    </button>
                    <button class="icon-btn" id="new-chat-btn" title="Tạo cuộc trò chuyện mới">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="icon-btn" title="Thêm tùy chọn">
                        <i class="fas fa-ellipsis-h"></i>
                    </button>
                </div>
            </div>
            <div class="search-container">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="search-input" placeholder="Search messages...">
                </div>
            </div>
            <div class="filter-chips">
                <button class="chip active" data-filter="all">All</button>
                <button class="chip" data-filter="unread">Unread</button>
                <button class="chip" data-filter="groups">Groups</button>
            </div>
            
            <!-- DANH SÁCH PHÒNG CHAT ĐỘNG -->
            <div class="room-list-container" id="room-list-container">
                <?php if (!empty($data['rooms'])): ?>
                    <?php foreach ($data['rooms'] as $room): ?>
                        <?php 
                        // Kiểm tra phòng hiện tại để thêm class 'active'
                        $isActive = ($room['room_id'] == $data['roomId']);
                        $activeClass = $isActive ? 'active' : '';
                        
                        // Xác định loại phòng (group hoặc private)
                        $roomTypeClass = ($room['room_type'] === 'group') ? 'group' : 'private';
                        
                        // Lấy chữ cái đầu của tên phòng làm avatar
                        $avatarLetter = $room['avatar_letter'] ?? 'R';
                        
                        // ============================================
                        // FIX LỖI "F5 MẤT TRẠNG THÁI GHIM"
                        // ============================================
                        // Kiểm tra trạng thái ghim từ dữ liệu PHP
                        $isPinned = isset($room['is_pinned']) && ((int)$room['is_pinned'] === 1);
                        
                        // Hiển thị icon ghim nếu phòng được ghim
                        $pinIcon = $isPinned ? '<i class="fas fa-thumbtack" style="color: var(--accent-color); margin-left: 6px; font-size: 12px;"></i>' : '';
                        
                        // Text cho dropdown menu
                        $pinText = $isPinned ? 'Bỏ ghim' : 'Ghim đoạn chat';
                        ?>
                        <div class="room-item <?= $activeClass ?> <?= $roomTypeClass ?>" 
                             data-room-id="<?= $room['room_id'] ?>" 
                             data-room-name="<?= htmlspecialchars($room['room_name_display']) ?>"
                             data-is-pinned="<?= $isPinned ? '1' : '0' ?>">
                            <div class="room-avatar"><?= htmlspecialchars($avatarLetter) ?></div>
                            <div class="room-info">
                                <div class="room-header">
                                    <div class="room-name">
                                        <?= htmlspecialchars($room['room_name_display']) ?>
                                        <?= $pinIcon ?>
                                    </div>
                                    <?php if (!empty($room['last_message_time_formatted'])): ?>
                                        <div class="room-time"><?= htmlspecialchars($room['last_message_time_formatted']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="room-preview">
                                    <div class="room-message"><?= htmlspecialchars($room['last_message_display']) ?></div>
                                    <?php if ($room['unread_count'] > 0): ?>
                                        <div class="unread-badge"><?= $room['unread_count'] ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="room-menu-trigger">
                                <i class="fas fa-ellipsis-v"></i>
                            </div>
                            <div class="room-dropdown-menu">
                                <div class="dropdown-item" data-action="pin">
                                    <i class="fas fa-thumbtack"></i>
                                    <span><?= $pinText ?></span>
                                </div>
                                <div class="dropdown-item dropdown-item-danger" data-action="delete">
                                    <i class="fas fa-trash"></i>
                                    <span>Xóa đoạn chat</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" style="padding: 40px 20px;">
                        <i class="fas fa-inbox"></i>
                        <p>Chưa có phòng chat nào</p>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <!-- CHAT AREA -->
        <main class="chat-area">
            <header class="chat-header">
                <button class="mobile-back-btn" id="mobile-back-btn" title="Quay lại">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="chat-header-info">
                    <h2 id="chat-room-name"><?= htmlspecialchars($data['title'] ?? 'Chat Room') ?></h2>
                    <p id="chat-room-subtitle"><?= count($data['messages']) ?> tin nhắn</p>
                </div>
                <div class="chat-header-actions">
                    <button class="icon-btn" title="Ghim tin nhắn">
                        <i class="fas fa-thumbtack"></i>
                    </button>
                    <button class="icon-btn" title="Bình chọn">
                        <i class="fas fa-poll"></i>
                    </button>
                    <button class="icon-btn" title="Tìm kiếm">
                        <i class="fas fa-search"></i>
                    </button>
                    <button class="icon-btn" id="info-btn" title="Thông tin">
                        <i class="fas fa-info-circle"></i>
                    </button>
                </div>
            </header>

            <div class="pinned-message-bar" id="pinned-message-bar" style="display: none;">
                <i class="fas fa-thumbtack"></i>
                <span class="pinned-text">Đã ghim: Lịch họp dự án lúc 3h chiều</span>
                <button class="close-pinned-btn" title="Đóng">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- KHU VỰC TIN NHẮN -->
            <div class="messages-container" id="messages-container">
                <?php if (empty($data['messages'])): ?>
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <p>Chưa có tin nhắn nào trong phòng chat này</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($data['messages'] as $msg): ?>
                        <?php
                        $isMyMessage  = ((int)$msg['sender_id'] === (int)$currentUserId);
                        $messageClass = $isMyMessage ? 'my-message' : 'other-message';
                        $messageType  = $msg['type'] ?? 'text';
                        $senderName   = $isMyMessage ? 'You' : ($msg['username'] ?? 'User #' . $msg['sender_id']);
                        ?>
                        <div class="message-wrapper <?= $messageClass ?>" data-message-id="<?= $msg['id'] ?>" data-user-id="<?= $msg['sender_id'] ?>">
                            <div class="message-avatar"><?= htmlspecialchars(mb_substr($senderName, 0, 1)) ?></div>
                            <div class="message-content">
                                <div class="message-sender"><?= htmlspecialchars($senderName) ?></div>
                                <div class="message-bubble">
                                    <div class="message-text">
                                        <?php if ($messageType === 'image'): ?>
                                            <img src="<?= $data['URLROOT'] ?>/<?= htmlspecialchars($msg['content']) ?>" style="max-width: 200px; border-radius: 8px; margin: 5px 0;" alt="Image">
                                        <?php elseif ($messageType === 'file'): ?>
                                            <?php $fileName = basename($msg['content']); ?>
                                            <a href="<?= $data['URLROOT'] ?>/<?= htmlspecialchars($msg['content']) ?>" target="_blank" class="message-file">
                                                <i class="fas fa-file"></i> <?= htmlspecialchars($fileName) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= $msg['content'] ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="message-time">
                                        <?= htmlspecialchars($msg['sent_at']) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- FORM NHẬP TIN NHẮN -->
            <div class="message-input-container">
                <form class="message-form" id="message-form">
                    <input type="hidden" id="room-id" value="<?= $data['roomId'] ?? 1 ?>">
                    <input type="file" id="file-input" accept="*/*" multiple style="display: none;">
                    <input type="file" id="image-input" accept="image/*" multiple style="display: none;">
                    
                    <div id="attachment-preview" class="attachment-preview-container" style="display: none; padding: 10px; border-top: 1px solid var(--border-color); gap: 10px; overflow-x: auto;"></div>
                    
                    <div class="attach-buttons">
                        <button type="button" class="attach-btn" id="emoji-btn" title="Emoji">
                            <i class="fas fa-smile"></i>
                        </button>
                        <button type="button" class="attach-btn" id="image-attach-btn" title="Gửi ảnh">
                            <i class="fas fa-image"></i>
                        </button>
                        <button type="button" class="attach-btn" id="file-attach-btn" title="Gửi tệp">
                            <i class="fas fa-paperclip"></i>
                        </button>
                    </div>
                    <input 
                        type="text" 
                        id="message-input" 
                        placeholder="Type a message..." 
                        autocomplete="off"
                    >
                    <button type="submit" class="send-btn" id="send-btn">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                    
                    <!-- EMOJI PICKER -->
                    <div id="emoji-picker" style="display: none; position: absolute; bottom: 60px; left: 20px; background: #2a2a2a; border: 1px solid #444; border-radius: 8px; padding: 10px; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">
                        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px;">
                            <span class="emoji-item" style="font-size: 24px; cursor: pointer; transition: transform 0.2s;" data-emoji="😀">😀</span>
                            <span class="emoji-item" style="font-size: 24px; cursor: pointer; transition: transform 0.2s;" data-emoji="😂">😂</span>
                            <span class="emoji-item" style="font-size: 24px; cursor: pointer; transition: transform 0.2s;" data-emoji="❤️">❤️</span>
                            <span class="emoji-item" style="font-size: 24px; cursor: pointer; transition: transform 0.2s;" data-emoji="👍">👍</span>
                            <span class="emoji-item" style="font-size: 24px; cursor: pointer; transition: transform 0.2s;" data-emoji="😢">😢</span>
                            <span class="emoji-item" style="font-size: 24px; cursor: pointer; transition: transform 0.2s;" data-emoji="🔥">🔥</span>
                            <span class="emoji-item" style="font-size: 24px; cursor: pointer; transition: transform 0.2s;" data-emoji="🎉">🎉</span>
                            <span class="emoji-item" style="font-size: 24px; cursor: pointer; transition: transform 0.2s;" data-emoji="💯">💯</span>
                            <span class="emoji-item" style="font-size: 24px; cursor: pointer; transition: transform 0.2s;" data-emoji="🙏">🙏</span>
                            <span class="emoji-item" style="font-size: 24px; cursor: pointer; transition: transform 0.2s;" data-emoji="😍">😍</span>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <!-- MODAL THÔNG TIN PHÒNG -->
    <div class="modal-overlay" id="room-info-modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-info-circle"></i> Thông tin phòng</h3>
                <button class="modal-close-btn" id="close-modal-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modal-body">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i> Đang tải...
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL TẠO PHÒNG MỚI -->
    <div class="modal-overlay" id="new-chat-modal" style="display: none;">
        <div class="modal-content modal-new-chat">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Tạo đoạn chat mới</h3>
                <button class="modal-close-btn" id="close-new-chat-modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="new-chat-form">
                    <div class="form-group">
                        <label for="chat-type">
                            <i class="fas fa-layer-group"></i> Loại đoạn chat
                        </label>
                        <select id="chat-type" class="form-control">
                            <option value="private">💬 Chat riêng tư (1-1)</option>
                            <option value="group">👥 Nhóm chat</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="chat-name">
                            <i class="fas fa-signature"></i> Tên phòng/người nhận
                        </label>
                        <input 
                            type="text" 
                            id="chat-name" 
                            class="form-control" 
                            placeholder="Nhập tên nhóm hoặc tên người nhận..."
                            required
                        >
                        <small class="form-hint">Ví dụ: "Nhóm dự án ABC" hoặc "Nguyễn Văn A"</small>
                    </div>

                    <div class="form-group" id="member-ids-group" style="display: none;">
                        <label for="member-ids">
                            <i class="fas fa-users"></i> ID thành viên (tùy chọn)
                        </label>
                        <input 
                            type="text" 
                            id="member-ids" 
                            class="form-control" 
                            placeholder="Ví dụ: 2,3,4"
                        >
                        <small class="form-hint">Nhập ID người dùng cách nhau bởi dấu phẩy</small>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-new-chat">
                            <i class="fas fa-times"></i> Hủy
                        </button>
                        <button type="submit" class="btn btn-primary" id="create-new-chat">
                            <i class="fas fa-check"></i> Tạo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Truyền URLROOT vào JavaScript
        window.URLROOT = '<?= $data['URLROOT'] ?>';
    </script>
    <script src="<?= $data['URLROOT'] ?>/js/chat.js"></script>
    <style>
        .emoji-item:hover {
            transform: scale(1.3);
        }
    </style>

    <!-- ============================================ -->
    <!-- IMAGE ZOOM MODAL (LIGHTBOX) -->
    <!-- ============================================ -->
    <!-- Modal phóng to ảnh khi user click vào ảnh trong tin nhắn -->
    <!-- Sử dụng CSS thuần, không dùng thư viện ngoài -->
    <!-- Hiển thị ảnh ở giữa màn hình với nền đen mờ -->
    <!-- Click vào nút X hoặc click ra ngoài ảnh để đóng -->
    <div id="image-zoom-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.8); align-items:center; justify-content:center; flex-direction:column;">
        <!-- Nút đóng Modal (dấu X) -->
        <span id="close-zoom" style="position:absolute; top:20px; right:35px; color:#f1f1f1; font-size:40px; font-weight:bold; cursor:pointer; transition: color 0.2s;" onmouseover="this.style.color='#d97706'" onmouseout="this.style.color='#f1f1f1'">&times;</span>
        
        <!-- Ảnh được phóng to -->
        <img id="zoomed-image" style="max-width:90%; max-height:90%; border-radius:10px; box-shadow: 0 4px 8px rgba(0,0,0,0.5); object-fit: contain;" alt="Zoomed Image">
    </div>
</body>
</html>
