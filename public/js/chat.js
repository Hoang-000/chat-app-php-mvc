/**
 * ============================================
 * CHAT APPLICATION - ES6 CLASS
 * ============================================
 */

// Fallback cho URLROOT
window.URLROOT = window.URLROOT || 'http://localhost/PHPnhom/chat-app-php-mvc/public';

class ChatApplication {
    constructor() {
        // ============================================
        // KHỞI TẠO: LẤY currentUserId TỪ URLSearchParams
        // ============================================
        // Đồng bộ với Backend: URL luôn có ?user_id=X
        // Nếu không có trong URL, mặc định là 1 (fallback)
        const urlParams = new URLSearchParams(window.location.search);
        this.currentUserId = parseInt(urlParams.get('user_id')) || 1;
        this.roomId = null;
        this.elements = {};
        this.isSending = false;
        this.pendingFiles = [];
        
        // Biến quản lý Polling
        this.lastMessageId = 0;      // ID tin nhắn cuối cùng (sẽ lấy từ HTML khi load)
        this.isPolling = false;       // Cờ hiệu chống spam request (lock state)
        this.pollingInterval = null;  // Lưu setInterval để có thể clearInterval
        
        console.log('👤 Current User ID:', this.currentUserId);
        this.init();
    }

    init() {
        this.cacheElements();
        this.bindEvents();
        this.loadRoomId();
        this.scrollToBottom();
        this.loadThemeFromStorage();
        
        // ============================================
        // KHÔI PHỤC FILTER TỪ URL (GIỮ TRẠNG THÁI FILTER)
        // ============================================
        // Khi user chuyển phòng, URL sẽ có ?filter=unread hoặc ?filter=groups
        // Đọc URLSearchParams để tự động click lại vào nút Filter đó
        const urlParams = new URLSearchParams(window.location.search);
        const activeFilter = urlParams.get('filter') || 'all'; // Mặc định 'all' nếu không có
        
        // Tự động click vào chip filter tương ứng
        this.elements.filterChips.forEach(chip => {
            if (chip.dataset.filter === activeFilter) {
                chip.classList.add('active');
            } else {
                chip.classList.remove('active');
            }
        });
        
        // Gọi handleFilterChange để load danh sách phòng theo filter
        if (activeFilter && activeFilter !== 'all') {
            this.handleFilterChange(activeFilter);
        }
        
        // Bắt đầu polling
        this.startPolling();
    }

    cacheElements() {
        this.elements = {
            messageForm: document.getElementById('message-form'),
            messageInput: document.getElementById('message-input'),
            sendBtn: document.getElementById('send-btn'),
            messagesContainer: document.getElementById('messages-container'),
            roomIdInput: document.getElementById('room-id'),
            themeToggle: document.getElementById('theme-toggle'),
            infoBtn: document.getElementById('info-btn'),
            searchInput: document.getElementById('search-input'),
            filterChips: document.querySelectorAll('.chip'),
            roomListContainer: document.getElementById('room-list-container'),
            imageAttachBtn: document.getElementById('image-attach-btn'),
            fileAttachBtn: document.getElementById('file-attach-btn'),
            fileInput: document.getElementById('file-input'),
            imageInput: document.getElementById('image-input'),
            mobileBackBtn: document.getElementById('mobile-back-btn'),
            closePinnedBtn: document.querySelector('.close-pinned-btn'),
            pinnedMessageBar: document.getElementById('pinned-message-bar'),
            roomInfoModal: document.getElementById('room-info-modal'),
            modalBody: document.getElementById('modal-body'),
            closeModalBtn: document.getElementById('close-modal-btn'),
            emojiBtn: document.getElementById('emoji-btn'),
            emojiPicker: document.getElementById('emoji-picker'),
            // New Chat Modal
            newChatBtn: document.getElementById('new-chat-btn'),
            newChatModal: document.getElementById('new-chat-modal'),
            newChatForm: document.getElementById('new-chat-form'),
            chatTypeSelect: document.getElementById('chat-type'),
            chatNameInput: document.getElementById('chat-name'),
            memberIdsInput: document.getElementById('member-ids'),
            memberIdsGroup: document.getElementById('member-ids-group'),
            cancelNewChatBtn: document.getElementById('cancel-new-chat'),
            closeNewChatModalBtn: document.getElementById('close-new-chat-modal'),
            createNewChatBtn: document.getElementById('create-new-chat')
        };
    }

    bindEvents() {
        this.elements.messageForm.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleSendMessage();
        });

        this.elements.messageInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.handleSendMessage();
            }
        });

        this.elements.messageInput.addEventListener('input', (e) => {
            this.toggleSendButton(e.target.value.trim());
        });

        if (this.elements.themeToggle) {
            this.elements.themeToggle.addEventListener('click', () => this.toggleTheme());
        }

        if (this.elements.searchInput) {
            this.elements.searchInput.addEventListener('input', (e) => {
                const keyword = e.target.value.trim();
                if (keyword.length >= 2) {
                    // Debounce: Chờ 500ms sau khi user ngừng gõ mới tìm
                    clearTimeout(this.searchTimeout);
                    this.searchTimeout = setTimeout(() => {
                        this.openSearchModal();
                        this.searchUsers(keyword);
                    }, 500);
                }
            });

            // Mở modal khi focus vào search input
            this.elements.searchInput.addEventListener('focus', () => {
                if (this.elements.searchInput.value.trim().length >= 2) {
                    this.openSearchModal();
                }
            });
        }

        // ============================================
        // EVENT LISTENER: BẮT SỰ KIỆN CLICK NÚT FILTER
        // ============================================
        // Lắng nghe click vào 3 nút Filter: All, Unread, Groups
        // Ngăn reload trang bằng preventDefault()
        this.elements.filterChips.forEach(chip => {
            chip.addEventListener('click', (e) => {
                // BƯỚC 1: NGĂN TRÌNH DUYỆT RELOAD TRANG
                e.preventDefault();
                e.stopPropagation();
                
                // BƯỚC 2: LẤY GIÁ TRỊ FILTER TỪ DATA-ATTRIBUTE
                const filterType = chip.dataset.filter; // 'all', 'unread', hoặc 'groups'
                
                // BƯỚC 3: CẬP NHẬT TRẠNG THÁI ACTIVE CHO NÚT
                // Xóa class 'active' khỏi tất cả các nút
                this.elements.filterChips.forEach(c => c.classList.remove('active'));
                // Thêm class 'active' vào nút vừa click
                chip.classList.add('active');
                
                // BƯỚC 4: GỌI HÀM XỬ LÝ FILTER (AJAX)
                this.handleFilterChange(filterType);
                
                console.log(`🔖 Filter clicked: ${filterType}`);
            });
        });

        // ============================================
        // EVENT LISTENER: BẮT SỰ KIỆN CLICK PHÒNG CHAT (FIX LỖI NaN)
        // ============================================
        // Sử dụng Event Delegation để lắng nghe click vào phòng
        // QUAN TRỌNG: Dùng closest() để tìm thẻ cha chứa data-room-id
        if (this.elements.roomListContainer) {
            this.elements.roomListContainer.addEventListener('click', (e) => {
                // ============================================
                // XỬ LÝ CLICK VÀO NÚT 3 CHẤM (MENU TRIGGER)
                // ============================================
                if (e.target.closest('.room-menu-trigger')) {
                    e.stopPropagation();
                    const menuTrigger = e.target.closest('.room-menu-trigger');
                    const roomItem = menuTrigger.closest('.room-item');
                    const dropdown = roomItem.querySelector('.room-dropdown-menu');
                    
                    // Đóng tất cả dropdown khác
                    document.querySelectorAll('.room-dropdown-menu').forEach(menu => {
                        if (menu !== dropdown) menu.classList.remove('show');
                    });
                    
                    // Toggle dropdown hiện tại
                    dropdown.classList.toggle('show');
                    return;
                }
                
                // ============================================
                // XỬ LÝ CLICK VÀO DROPDOWN ITEM (PIN/DELETE)
                // ============================================
                if (e.target.closest('.dropdown-item')) {
                    e.stopPropagation();
                    const dropdownItem = e.target.closest('.dropdown-item');
                    const action = dropdownItem.dataset.action;
                    
                    // ============================================
                    // FIX LỖI "GHIM PHÒNG NÀY NHẢY PHÒNG KIA"
                    // ============================================
                    // BẮT BUỘC: Lấy roomId từ closest('.room-item').dataset.roomId
                    // TUYỆT ĐỐI KHÔNG được lấy theo index hay cách gián tiếp khác
                    const roomItem = dropdownItem.closest('.room-item');
                    if (!roomItem) {
                        console.error('❌ Lỗi: Không tìm thấy .room-item');
                        return;
                    }
                    
                    // Lấy roomId TRỰC TIẾP từ data-room-id của chính phòng đó
                    const roomId = roomItem.dataset.roomId;
                    if (!roomId || isNaN(roomId)) {
                        console.error('❌ Lỗi: room_id không hợp lệ:', roomId);
                        console.error('roomItem:', roomItem);
                        console.error('dataset:', roomItem.dataset);
                        return;
                    }
                    
                    const roomName = roomItem.dataset.roomName || 'Phòng chat';
                    
                    console.log(`🎯 Action "${action}" trên phòng ID=${roomId} (${roomName})`);
                    
                    // Đóng dropdown
                    const dropdown = roomItem.querySelector('.room-dropdown-menu');
                    if (dropdown) {
                        dropdown.classList.remove('show');
                    }
                    
                    // Thực hiện action
                    if (action === 'pin') {
                        this.pinRoom(parseInt(roomId), roomName);
                    } else if (action === 'delete') {
                        this.deleteRoom(parseInt(roomId), roomName);
                    }
                    return;
                }
                
                // ============================================
                // XỬ LÝ CLICK VÀO PHÒNG CHAT (CHUYỂN PHÒNG)
                // ============================================
                // FIX LỖI NaN: Dùng closest() thay vì e.target trực tiếp
                const roomItem = e.target.closest('.room-item');
                
                // Nếu không click vào .room-item, bỏ qua
                if (!roomItem) {
                    return;
                }
                
                // Lấy room_id từ data-room-id
                const roomId = roomItem.dataset.roomId;
                
                // Validate room_id
                if (!roomId || isNaN(roomId)) {
                    console.error('❌ Lỗi: room_id không hợp lệ:', roomId);
                    console.error('roomItem:', roomItem);
                    console.error('dataset:', roomItem.dataset);
                    return;
                }
                
                const roomName = roomItem.dataset.roomName || 'Phòng chat';
                
                // Chuyển phòng
                this.handleRoomClick(parseInt(roomId), roomName);
            });
        }

        if (this.elements.infoBtn) {
            this.elements.infoBtn.addEventListener('click', () => this.showRoomInfo());
        }

        if (this.elements.imageAttachBtn) {
            this.elements.imageAttachBtn.addEventListener('click', () => this.elements.imageInput.click());
        }
        if (this.elements.fileAttachBtn) {
            this.elements.fileAttachBtn.addEventListener('click', () => this.elements.fileInput.click());
        }
        if (this.elements.imageInput) {
            this.elements.imageInput.addEventListener('change', (e) => {
                this.handleFileUpload(e.target.files, 'image');
                // Reset value để cho phép chọn lại cùng file
                e.target.value = '';
            });
        }
        if (this.elements.fileInput) {
            this.elements.fileInput.addEventListener('change', (e) => {
                this.handleFileUpload(e.target.files, 'file');
                // Reset value để cho phép chọn lại cùng file
                e.target.value = '';
            });
        }

        if (this.elements.mobileBackBtn) {
            this.elements.mobileBackBtn.addEventListener('click', () => this.showSidebar());
        }

        if (this.elements.closePinnedBtn) {
            this.elements.closePinnedBtn.addEventListener('click', () => this.closePinnedMessage());
        }

        if (this.elements.closeModalBtn) {
            this.elements.closeModalBtn.addEventListener('click', () => this.closeModal());
        }

        if (this.elements.roomInfoModal) {
            this.elements.roomInfoModal.addEventListener('click', (e) => {
                if (e.target === this.elements.roomInfoModal) {
                    this.closeModal();
                }
            });
        }

        // ============================================
        // NEW CHAT MODAL EVENTS
        // ============================================
        if (this.elements.newChatBtn) {
            this.elements.newChatBtn.addEventListener('click', () => this.openNewChatModal());
        }

        if (this.elements.closeNewChatModalBtn) {
            this.elements.closeNewChatModalBtn.addEventListener('click', () => this.closeNewChatModal());
        }

        if (this.elements.cancelNewChatBtn) {
            this.elements.cancelNewChatBtn.addEventListener('click', () => this.closeNewChatModal());
        }

        if (this.elements.newChatModal) {
            this.elements.newChatModal.addEventListener('click', (e) => {
                if (e.target === this.elements.newChatModal) {
                    this.closeNewChatModal();
                }
            });
        }

        if (this.elements.chatTypeSelect) {
            this.elements.chatTypeSelect.addEventListener('change', (e) => {
                // Hiển thị trường member IDs nếu chọn group
                if (e.target.value === 'group') {
                    this.elements.memberIdsGroup.style.display = 'block';
                    this.elements.chatNameInput.placeholder = 'Nhập tên nhóm...';
                } else {
                    this.elements.memberIdsGroup.style.display = 'none';
                    this.elements.chatNameInput.placeholder = 'Tìm kiếm người nhận...';
                }
            });
        }

        // Event listener cho ô input tìm kiếm trong modal
        if (this.elements.chatNameInput) {
            this.elements.chatNameInput.addEventListener('input', (e) => {
                const keyword = e.target.value.trim();
                const chatType = this.elements.chatTypeSelect.value;
                
                // Nếu là chat riêng tư và keyword >= 2 ký tự, hiển thị gợi ý
                if (chatType === 'private' && keyword.length >= 2) {
                    clearTimeout(this.searchModalTimeout);
                    this.searchModalTimeout = setTimeout(() => {
                        this.searchUsersInModal(keyword);
                    }, 300);
                } else {
                    // Ẩn gợi ý nếu keyword quá ngắn
                    this.hideUserSuggestions();
                }
            });
        }

        if (this.elements.newChatForm) {
            this.elements.newChatForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleCreateNewChat();
            });
        }

        // Emoji Picker
        if (this.elements.emojiBtn) {
            this.elements.emojiBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleEmojiPicker();
            });
        }
        
        if (this.elements.emojiPicker) {
            this.elements.emojiPicker.addEventListener('click', (e) => {
                if (e.target.classList.contains('emoji-item')) {
                    this.insertEmoji(e.target.dataset.emoji);
                }
            });
        }
        
        // Đóng emoji picker và dropdown menu khi click ra ngoài
        document.addEventListener('click', (e) => {
            // Đóng emoji picker
            if (this.elements.emojiPicker && 
                !this.elements.emojiPicker.contains(e.target) && 
                e.target !== this.elements.emojiBtn) {
                this.elements.emojiPicker.style.display = 'none';
            }
            
            // Đóng room dropdown menu khi click ra ngoài
            if (!e.target.closest('.room-menu-trigger') && !e.target.closest('.room-dropdown-menu')) {
                document.querySelectorAll('.room-dropdown-menu').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        });

        // ============================================
        // IMAGE ZOOM LIGHTBOX - EVENT DELEGATION
        // ============================================
        // Sử dụng Event Delegation để lắng nghe click vào ảnh trong tin nhắn
        // Vì tin nhắn được thêm động (qua AJAX), không thể addEventListener trực tiếp
        // Phải lắng nghe trên container cha (messagesContainer) và kiểm tra target
        // 
        // LUỒNG HOẠT ĐỘNG:
        // 1. User click vào ảnh trong tin nhắn
        // 2. Event bubble lên messagesContainer
        // 3. Kiểm tra e.target có phải là IMG và nằm trong .message-bubble không
        // 4. Nếu đúng, hiển thị Modal và gán src của ảnh vào Modal
        // 5. Modal hiển thị ảnh phóng to ở giữa màn hình
        // 
        // VẤN ĐÁP:
        // "Em dùng Event Delegation để lắng nghe click vào ảnh trong tin nhắn.
        // Vì tin nhắn được thêm động qua AJAX, không thể addEventListener trực tiếp.
        // Em lắng nghe trên messagesContainer, kiểm tra e.target có phải IMG không,
        // sau đó hiển thị Modal Lightbox với ảnh phóng to."
        this.elements.messagesContainer.addEventListener('click', (e) => {
            // Kiểm tra xem element được click có phải là IMG không
            // Và phải nằm trong .message-bubble (không phải avatar)
            if (e.target.tagName === 'IMG' && e.target.closest('.message-bubble')) {
                // Lấy Modal và ảnh trong Modal
                const modal = document.getElementById('image-zoom-modal');
                const modalImg = document.getElementById('zoomed-image');
                
                // Hiển thị Modal (display: flex để center ảnh)
                modal.style.display = 'flex';
                
                // Gán src của ảnh được click vào ảnh trong Modal
                modalImg.src = e.target.src;
                
                console.log('🖼️ Mở Lightbox:', e.target.src);
            }
        });

        // ============================================
        // ĐÓNG MODAL KHI CLICK VÀO NÚT X
        // ============================================
        const closeZoomBtn = document.getElementById('close-zoom');
        if (closeZoomBtn) {
            closeZoomBtn.addEventListener('click', () => {
                const modal = document.getElementById('image-zoom-modal');
                modal.style.display = 'none';
                console.log('❌ Đóng Lightbox (nút X)');
            });
        }

        // ============================================
        // ĐÓNG MODAL KHI CLICK RA NGOÀI ẢNH
        // ============================================
        // Khi user click vào vùng đen (modal background), đóng Modal
        // Kiểm tra e.target === modal để chỉ đóng khi click vào background
        // Không đóng khi click vào ảnh
        const imageZoomModal = document.getElementById('image-zoom-modal');
        if (imageZoomModal) {
            imageZoomModal.addEventListener('click', (e) => {
                // Chỉ đóng khi click vào chính Modal (background đen)
                // Không đóng khi click vào ảnh hoặc nút X
                if (e.target.id === 'image-zoom-modal') {
                    e.target.style.display = 'none';
                    console.log('❌ Đóng Lightbox (click ngoài)');
                }
            });
        }

        window.addEventListener('resize', () => this.handleResize());
    }

    loadRoomId() {
        if (this.elements.roomIdInput) {
            this.roomId = parseInt(this.elements.roomIdInput.value) || 1;
        } else {
            const urlParams = new URLSearchParams(window.location.search);
            this.roomId = parseInt(urlParams.get('room_id')) || 1;
        }
        console.log('📍 Room ID:', this.roomId);
    }

    /**
     * ============================================
     * HÀM CHÍNH: XỬ LÝ GỬI TIN NHẮN - FIX CẬP NHẬT SIDEBAR REAL-TIME
     * ============================================
     */
    async handleSendMessage() {
        const content = this.elements.messageInput.value.trim();
        const hasPendingFiles = this.pendingFiles.length > 0;
        
        if (!content && !hasPendingFiles) {
            console.warn('⚠️ Không có nội dung');
            return;
        }

        if (this.isSending) {
            console.warn('⚠️ Đang gửi...');
            return;
        }

        this.isSending = true;
        this.setLoadingState(true);

        try {
            // Gửi text nếu có
            if (content) {
                const formData = new FormData();
                formData.append('room_id', this.roomId);
                formData.append('sender_id', this.currentUserId);
                formData.append('content', content);

                const response = await fetch(window.URLROOT + '/index.php?controller=chat&action=send', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                const result = await response.json();
                if (result.status === 'success') {
                    this.appendMessage(result.data);
                    
                    // ============================================
                    // FIX LỐI 2: CẬP NHẬT SIDEBAR REAL-TIME
                    // ============================================
                    this.updateSidebarAfterSend(content);
                } else {
                    throw new Error(result.message);
                }
                
                this.elements.messageInput.value = '';
            }

            // Gửi từng file trong pendingFiles
            if (hasPendingFiles) {
                console.log(`📤 Gửi ${this.pendingFiles.length} file(s)`);
                
                for (let i = 0; i < this.pendingFiles.length; i++) {
                    const file = this.pendingFiles[i];
                    console.log(`📎 Đang gửi file ${i + 1}/${this.pendingFiles.length}:`, file.name);
                    
                    const formData = new FormData();
                    formData.append('room_id', this.roomId);
                    formData.append('sender_id', this.currentUserId);
                    formData.append('content', '');
                    formData.append('file', file);
                    
                    const response = await fetch(window.URLROOT + '/index.php?controller=chat&action=send', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    console.log(`✅ File ${i + 1} đã gửi:`, data);
                    if (data.status === 'success') {
                        this.appendMessage(data.data);
                        
                        // Cập nhật Sidebar cho file
                        const fileType = data.data.type;
                        const displayText = fileType === 'image' ? '📷 Hình ảnh' : '📎 Tệp đính kèm';
                        this.updateSidebarAfterSend(displayText);
                    }
                }
                
                // Reset sau khi gửi xong
                this.pendingFiles = [];
                this.renderPreview();
            }

            this.toggleSendButton('');
            this.scrollToBottom();
            console.log('✅ Hoàn tất!');

        } catch (error) {
            console.error('❌ Lỗi:', error);
            alert('Không thể gửi. Thử lại.');
        } finally {
            this.setLoadingState(false);
            this.isSending = false;
            this.elements.messageInput.focus();
        }
    }



    setLoadingState(isLoading) {
        this.elements.messageInput.disabled = isLoading;
        this.elements.sendBtn.disabled = isLoading;

        if (isLoading) {
            this.elements.sendBtn.classList.add('loading');
            const icon = this.elements.sendBtn.querySelector('i');
            if (icon) {
                icon.className = 'fas fa-spinner fa-spin';
            }
        } else {
            this.elements.sendBtn.classList.remove('loading');
            const icon = this.elements.sendBtn.querySelector('i');
            if (icon) {
                icon.className = 'fas fa-paper-plane';
            }
        }
    }

    /**
     * ============================================
     * HÀM appendMessage(data): HIỂN THỊ TIN NHẮN MỚI
     * ============================================
     * 
     * LOGIC:
     * - Nếu data.sender_id trùng với this.currentUserId: Hiện 'You' và đẩy sang phải
     * - Nếu không: Hiện tên người gửi và đẩy sang trái
     * - Sử dụng thời gian từ Server (data.created_at) để hiển thị trong bong bóng chat
     * 
     * @param {Object} data - {id, sender_id, sender_name, content, type, file_path, created_at}
     */
    appendMessage(data) {
        const emptyState = this.elements.messagesContainer.querySelector('.empty-state');
        if (emptyState) emptyState.remove();

        // ============================================
        // BƯỚC 1: PHÂN BIỆT NGƯỜI GỬI
        // ============================================
        const isMe = parseInt(data.sender_id) === this.currentUserId;
        const wrapperClass = isMe ? 'my-message' : 'other-message';
        
        // Nếu là mình: hiện 'You', nếu không: hiện tên người gửi
        const senderName = isMe ? 'You' : (data.sender_name || data.username || 'User ' + data.sender_id);
        const avatarChar = senderName.charAt(0).toUpperCase();

        // ============================================
        // BƯỚC 2: XỬ LÝ NỘI DUNG (Text / Image / File)
        // ============================================
        let contentHtml = '';
        if (data.type === 'image' && data.file_path) {
            contentHtml = `<img src="${window.URLROOT}/${this.escapeHTML(data.file_path)}" alt="Image" style="max-width: 200px; border-radius: 8px; cursor: pointer;">`;
        } else if (data.type === 'file' && data.file_path) {
            const fileName = data.file_path.split('/').pop();
            contentHtml = `<a href="${window.URLROOT}/${this.escapeHTML(data.file_path)}" target="_blank" class="message-file"><i class="fas fa-file"></i> ${this.escapeHTML(fileName)}</a>`;
        } else {
            contentHtml = this.escapeHTML(data.content || '');
        }

        // ============================================
        // BƯỚC 3: ĐỊNH DẠNG THỜI GIAN TỪ SERVER
        // ============================================
        // Sử dụng thời gian từ Server (data.created_at) thay vì getCurrentTime()
        // Format: "14:30" hoặc "Hôm qua 14:30" nếu cần
        let displayTime = '';
        if (data.created_at) {
            const msgDate = new Date(data.created_at);
            displayTime = msgDate.toLocaleTimeString('vi-VN', {
                hour: '2-digit',
                minute: '2-digit'
            });
        } else {
            // Fallback nếu Server không trả về created_at
            displayTime = this.getCurrentTime();
        }

        // ============================================
        // BƯỚC 4: ĐỔ HTML RA GIAO DIỆN
        // ============================================
        const html = `
            <div class="message-wrapper ${wrapperClass}" data-message-id="${data.id || 0}">
                <div class="message-avatar">${avatarChar}</div>
                <div class="message-content">
                    <div class="message-sender">${this.escapeHTML(senderName)}</div>
                    <div class="message-bubble">
                        <div class="message-text">${contentHtml}</div>
                        <div class="message-time">${displayTime}</div>
                    </div>
                </div>
            </div>
        `;

        this.elements.messagesContainer.insertAdjacentHTML('beforeend', html);
        this.scrollToBottom();
        
        // Cập nhật lastMessageId để Polling không lấy lại tin nhắn này
        if (data.id) {
            this.lastMessageId = Math.max(this.lastMessageId, data.id);
        }
    }

    toggleSendButton(content) {
        if (content.length > 0) {
            this.elements.sendBtn.classList.add('active');
        } else {
            this.elements.sendBtn.classList.remove('active');
        }
    }

    scrollToBottom() {
        this.elements.messagesContainer.scrollTo({
            top: this.elements.messagesContainer.scrollHeight,
            behavior: 'smooth'
        });
    }

    getCurrentTime() {
        return new Date().toLocaleTimeString('vi-VN', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    /**
     * ============================================
     * HÀM escapeHTML(text): CHỐNG XSS
     * ============================================
     * 
     * MỤC ĐÍCH:
     * - Chuyển đổi các ký tự đặc biệt thành HTML entities
     * - Ngăn chặn XSS (Cross-Site Scripting) attack
     * 
     * VÍ DỤ:
     * Input:  <script>alert('XSS')</script>
     * Output: &lt;script&gt;alert('XSS')&lt;/script&gt;
     * 
     * CÁCH HOẠT ĐỘNG:
     * - Tạo một div ảo
     * - Gán text vào textContent (trình duyệt tự động escape)
     * - Lấy innerHTML ra (kết quả đã escape)
     * 
     * @param {string} text - Chuỗi cần escape
     * @returns {string} - Chuỗi đã escape an toàn
     */
    escapeHTML(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    showErrorNotification(message) {
        alert(message);
    }

    toggleTheme() {
        const currentTheme = document.body.getAttribute('data-theme') || 'dark';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.body.setAttribute('data-theme', newTheme);

        const icon = this.elements.themeToggle.querySelector('i');
        if (newTheme === 'light') {
            icon.className = 'fas fa-moon';
        } else {
            icon.className = 'fas fa-sun';
        }

        localStorage.setItem('chatTheme', newTheme);
    }

    loadThemeFromStorage() {
        const savedTheme = localStorage.getItem('chatTheme');
        if (savedTheme) {
            document.body.setAttribute('data-theme', savedTheme);
            const icon = this.elements.themeToggle?.querySelector('i');
            if (icon) {
                icon.className = savedTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
            }
        }
    }

    handleSearch(keyword) {
        console.log('🔍 Tìm kiếm:', keyword);
    }

    /**
     * ============================================
     * HÀM openSearchModal: MỞ MODAL TÌM KIẾM USER
     * ============================================
     */
    openSearchModal() {
        let modal = document.getElementById('search-user-modal');
        
        // Nếu chưa có modal, tạo mới
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'search-user-modal';
            modal.className = 'modal-overlay';
            modal.style.cssText = 'display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;';
            
            modal.innerHTML = `
                <div class="modal-content" style="background: var(--bg-secondary); border-radius: 12px; width: 90%; max-width: 600px; max-height: 80vh; overflow: hidden; display: flex; flex-direction: column;">
                    <div class="modal-header" style="padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
                        <h3 style="margin: 0; color: var(--text-primary);"><i class="fas fa-search"></i> Tìm kiếm người dùng</h3>
                        <button class="modal-close-btn" id="close-search-modal" style="background: none; border: none; color: var(--text-secondary); font-size: 24px; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: background 0.2s;" onmouseover="this.style.background='var(--hover-color)'" onmouseout="this.style.background=''">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body" style="padding: 20px; overflow-y: auto; flex: 1;">
                        <div id="search-results" style="min-height: 200px;">
                            <div style="text-align: center; padding: 40px; color: #888;">
                                <i class="fas fa-search" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                                <p>Nhập tên người dùng để tìm kiếm</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Bind event đóng modal
            modal.querySelector('#close-search-modal').addEventListener('click', () => {
                this.closeSearchModal();
            });
            
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.closeSearchModal();
                }
            });
        }
        
        modal.style.display = 'flex';
        console.log('🔍 Mở modal tìm kiếm user');
    }

    /**
     * ============================================
     * HÀM closeSearchModal: ĐÓNG MODAL TÌM KIẾM
     * ============================================
     */
    closeSearchModal() {
        const modal = document.getElementById('search-user-modal');
        if (modal) {
            modal.style.display = 'none';
            console.log('❌ Đóng modal tìm kiếm');
        }
    }

    /**
     * ============================================
     * HÀM handleFilterChange: XỬ LÝ FILTER SIDEBAR (AJAX - NO RELOAD)
     * ============================================
     * 
     * LUỒNG HOẠT ĐỘNG:
     * 1. Nhận tham số filter từ nút vừa click ('all', 'unread', 'groups')
     * 2. Gọi API Backend bằng fetch() với URL động
     * 3. Nhận JSON trả về chứa danh sách phòng
     * 4. XỬ LÝ TRƯỜNG HỢP RỖNG (count = 0) - KHÔNG NÉM LỖI
     * 5. Gọi renderSidebar() để vẽ lại Sidebar
     * 6. GIỮ NGUYÊN nút 3 chấm và icon ghim trong renderSidebar()
     * 
     * YÊU CẦU:
     * - KHÔNG RELOAD TRANG (sử dụng fetch AJAX)
     * - Hiệu ứng fade-in/fade-out mượt mà
     * - Giữ nguyên UI components (nút 3 chấm, icon ghim)
     * 
     * @param {string} filter - 'all' | 'unread' | 'groups'
     */
    async handleFilterChange(filter) {
        try {
            console.log(`🔖 Bắt đầu filter: ${filter}`);
            
            // ============================================
            // BƯỚC 1: HIỆU ỨNG FADE-OUT (LUXURY UX)
            // ============================================
            this.elements.roomListContainer.style.opacity = '0';
            this.elements.roomListContainer.style.transition = 'opacity 0.2s ease';
            
            // Đợi animation fade-out hoàn tất (200ms)
            await new Promise(resolve => setTimeout(resolve, 200));
            
            // ============================================
            // BƯỚC 2: XÂY DỰNG URL ĐỘNG CHO API
            // ============================================
            // Format: index.php?controller=chat&action=filterRooms&user_id=X&type=Y
            // QUAN TRỌNG: Dùng this.currentUserId (lấy từ URL khi khởi tạo)
            const apiUrl = `${window.URLROOT}/index.php?controller=chat&action=filterRooms&user_id=${this.currentUserId}&type=${filter}`;
            
            console.log(`📡 Gọi API: ${apiUrl}`);
            
            // ============================================
            // BƯỚC 3: GỌI API BẰNG FETCH (AJAX)
            // ============================================
            const response = await fetch(apiUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            // Kiểm tra HTTP status
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            // Parse JSON response
            const result = await response.json();
            console.log('📥 Response từ Backend:', result);
            
            // ============================================
            // BƯỚC 4: KIỂM TRA STATUS TỪ BACKEND
            // ============================================
            if (result.status !== 'success') {
                throw new Error(result.message || 'Filter thất bại');
            }
            
            // ============================================
            // BƯỚC 5: XỬ LÝ TRƯỜNG HỢP KHÔNG CÓ PHÒNG (COUNT = 0)
            // ============================================
            // QUAN TRỌNG: Không ném lỗi khi count = 0, đây là trường hợp hợp lệ
            if (result.count === 0 || !result.data || (Array.isArray(result.data) && result.data.length === 0)) {
                console.log('ℹ️ Không có phòng nào phù hợp với filter');
                
                // Xóa sạch Sidebar và hiển thị thông báo "Không có đoạn chat nào"
                this.elements.roomListContainer.innerHTML = `
                    <div class="empty-state" style="text-align: center; padding: 40px 20px; color: #888;">
                        <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                        <p style="font-size: 14px; margin: 0;">Không có đoạn chat nào</p>
                    </div>
                `;
                
                // Fade-in lại Sidebar
                this.elements.roomListContainer.style.opacity = '1';
                
                console.log(`✅ Hoàn tất filter "${filter}" với 0 phòng`);
                return; // RETURN LUÔN, KHÔNG NÉM LỖI
            }
            
            // ============================================
            // BƯỚC 6: LẤY DANH SÁCH PHÒNG TỪ RESPONSE
            // ============================================
            let rooms = [];
            
            if (result.data && Array.isArray(result.data.rooms)) {
                // Backend trả về JSON array trong result.data.rooms
                rooms = result.data.rooms;
            } else if (result.data && result.data.html) {
                // Backend trả về HTML đã render sẵn (fallback)
                this.elements.roomListContainer.innerHTML = result.data.html;
                this.elements.roomListContainer.style.opacity = '1';
                console.log(`✅ Đã render ${result.count || 0} phòng (HTML mode)`);
                return;
            } else if (Array.isArray(result.data)) {
                // Backend trả về array trực tiếp trong result.data
                rooms = result.data;
            } else {
                // Nếu không phải các format trên, coi như mảng rỗng
                console.warn('⚠️ Format dữ liệu không chuẩn, coi như mảng rỗng');
                rooms = [];
            }
            
            console.log(`📋 Nhận được ${rooms.length} phòng từ Backend`);
            
            // ============================================
            // BƯỚC 7: GỌI renderSidebar() ĐỂ VẼ LẠI UI
            // ============================================
            // QUAN TRỌNG: renderSidebar() sẽ GIỮ NGUYÊN:
            // - Nút 3 chấm dọc (room-menu-trigger)
            // - Icon ghim 📌 (nếu is_pinned = 1)
            // - Dropdown menu (pin/delete)
            this.renderSidebar(rooms);
            
            // ============================================
            // BƯỚC 8: HIỆU ỨNG FADE-IN (LUXURY UX)
            // ============================================
            this.elements.roomListContainer.style.opacity = '1';
            
            console.log(`✅ Hoàn tất filter "${filter}" với ${rooms.length} phòng`);
            
        } catch (error) {
            // ============================================
            // XỬ LÝ LỖI: HIỂN THỊ THÔNG BÁO VÀ KHÔI PHỤC UI
            // ============================================
            console.error('❌ Lỗi khi filter:', error);
            
            // Khôi phục opacity để user thấy sidebar cũ
            this.elements.roomListContainer.style.opacity = '1';
            
            // Hiển thị thông báo lỗi cho user
            alert(`Không thể lọc phòng: ${error.message}`);
        }
    }

    /**
     * ============================================
     * HÀM renderSidebar(rooms): VẼ LẠI SIDEBAR
     * ============================================
     * 
     * CHỨC NĂNG:
     * - Vẽ lại danh sách phòng chat trong Sidebar bằng dữ liệu JSON
     * - Hiển thị thời gian (last_time) và số tin chưa đọc (unread_count)
     * - Đánh dấu phòng đang active
     * 
     * @param {Array} rooms - Mảng JSON chứa thông tin phòng chat
     * Format: [{id, name, type, last_message, last_time, unread_count, is_online}, ...]
     */
    renderSidebar(rooms) {
        if (!this.elements.roomListContainer) return;
        
        console.log('🎨 Render Sidebar với', rooms.length, 'phòng');
        
        if (!rooms || rooms.length === 0) {
            this.elements.roomListContainer.innerHTML = `
                <div class="empty-state" style="padding: 40px 20px;">
                    <i class="fas fa-inbox"></i>
                    <p>Chưa có phòng chat nào</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        rooms.forEach(room => {
            const roomId = room.room_id || room.id;
            if (!roomId) {
                console.error('❌ Lỗi: Phòng không có ID:', room);
                return;
            }
            
            const isActive = parseInt(roomId) === parseInt(this.roomId) ? 'active' : '';
            const roomName = room.room_name_display || room.room_name || room.name || 'Phòng chat';
            const avatarChar = (room.avatar_letter || roomName.charAt(0)).toUpperCase();
            const roomTypeClass = (room.room_type === 'group') ? 'group' : 'private';
            
            const unreadCount = room.unread_count || 0;
            const unreadBadge = unreadCount > 0 ? `<div class="unread-badge">${unreadCount}</div>` : '';
            
            const displayTime = room.last_message_time_formatted || room.last_time || '';
            const lastMessage = room.last_message_display || room.last_message || 'Chưa có tin nhắn';
            
            const isPinned = (parseInt(room.is_pinned) === 1) || (room.is_pinned === true) || (room.is_pinned === "1");
            const pinIcon = isPinned ? '<i class="fas fa-thumbtack" style="color: var(--accent-color); margin-left: 6px; font-size: 12px;"></i>' : '';
            const pinText = isPinned ? 'Bỏ ghim' : 'Ghim đoạn chat';
            
            html += `
                <div class="room-item ${isActive} ${roomTypeClass}" 
                     data-room-id="${roomId}" 
                     data-room-name="${this.escapeHTML(roomName)}"
                     data-is-pinned="${isPinned ? 1 : 0}">
                    <div class="room-avatar">${this.escapeHTML(avatarChar)}</div>
                    <div class="room-info">
                        <div class="room-header">
                            <div class="room-name">${this.escapeHTML(roomName)}${pinIcon}</div>
                            ${displayTime ? `<div class="room-time">${this.escapeHTML(displayTime)}</div>` : ''}
                        </div>
                        <div class="room-preview">
                            <div class="room-message">${this.escapeHTML(lastMessage)}</div>
                            ${unreadBadge}
                        </div>
                    </div>
                    <div class="room-menu-trigger">
                        <i class="fas fa-ellipsis-v"></i>
                    </div>
                    <div class="room-dropdown-menu">
                        <div class="dropdown-item" data-action="pin">
                            <i class="fas fa-thumbtack"></i>
                            <span>${pinText}</span>
                        </div>
                        <div class="dropdown-item dropdown-item-danger" data-action="delete">
                            <i class="fas fa-trash"></i>
                            <span>Xóa đoạn chat</span>
                        </div>
                    </div>
                </div>
            `;
        });
        
        this.elements.roomListContainer.innerHTML = html;
        console.log('✅ Đã render xong Sidebar');
    }

    /**
     * ============================================
     * HÀM formatTime(timestamp): ĐỊNH DẠNG THỜI GIAN
     * ============================================
     * 
     * LOGIC:
     * - Nếu hôm nay: Hiển thị giờ (14:30)
     * - Nếu hôm qua: Hiển thị "Hôm qua"
     * - Nếu tuần này: Hiển thị thứ ("Thứ 2")
     * - Nếu xa hơn: Hiển thị ngày ("12/03")
     * 
     * @param {string} timestamp - Chuỗi thời gian từ Server (ISO 8601 hoặc MySQL datetime)
     * @returns {string} - Chuỗi thời gian đã định dạng
     */
    formatTime(timestamp) {
        if (!timestamp) return '';
        
        const msgDate = new Date(timestamp);
        const now = new Date();
        const diffMs = now - msgDate;
        const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
        
        // Hôm nay: Hiển thị giờ
        if (diffDays === 0) {
            return msgDate.toLocaleTimeString('vi-VN', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        // Hôm qua
        if (diffDays === 1) {
            return 'Hôm qua';
        }
        
        // Tuần này: Hiển thị thứ
        if (diffDays < 7) {
            const days = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
            return days[msgDate.getDay()];
        }
        
        // Xa hơn: Hiển thị ngày/tháng
        return msgDate.toLocaleDateString('vi-VN', {
            day: '2-digit',
            month: '2-digit'
        });
    }

    /**
     * ============================================
     * HÀM handleRoomClick: CHUYỂN PHÒNG KHÔNG RELOAD (NO-RELOAD)
     * ============================================
     * 
     * ĐẶC TẢ LUỒNG:
     * 1. User click vào phòng ở Sidebar
     * 2. TUYỆT ĐỐI KHÔNG được reload trang
     * 3. Sử dụng fetch() gọi API lấy tin nhắn của phòng đó
     * 4. Cập nhật Header (Tên phòng, số thành viên) động bằng Javascript
     * 5. Xóa sạch tin nhắn cũ trong khung chat và render tin nhắn mới
     * 6. Gọi API markAsRead ngầm để cập nhật trạng thái đã đọc
     * 7. Cập nhật lại Sidebar (xóa badge unread hoặc xóa phòng khỏi tab Unread)
     * 
     * @param {number} id - ID của phòng chat
     * @param {string} name - Tên phòng chat
     */
    async handleRoomClick(id, name) {
        console.log('🏠 Chuyển sang room:', id, '-', name);
        
        // ============================================
        // BƯỚC 1: CẬP NHẬT TRẠNG THÁI ACTIVE TRONG SIDEBAR
        // ============================================
        document.querySelectorAll('.room-item').forEach(item => {
            item.classList.remove('active');
        });
        
        const clickedRoom = document.querySelector(`.room-item[data-room-id="${id}"]`);
        if (clickedRoom) {
            clickedRoom.classList.add('active');
        }
        
        // ============================================
        // BƯỚC 2: CẬP NHẬT NGAY TÊN PHÒNG LÊN HEADER
        // ============================================
        const roomNameElement = document.querySelector('.chat-header-info h2');
        if (roomNameElement) {
            roomNameElement.textContent = this.escapeHTML(name);
        }
        
        const subtitleElement = document.querySelector('.chat-header-info p');
        if (subtitleElement) {
            subtitleElement.textContent = 'Đang tải...';
        }
        
        // ============================================
        // BƯỚC 3: HIỂN THỊ LOADING TRONG KHUNG CHAT
        // ============================================
        this.elements.messagesContainer.innerHTML = `
            <div class="loading-state" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--text-tertiary);">
                <i class="fas fa-spinner fa-spin" style="font-size: 48px; margin-bottom: 16px; color: var(--accent-color);"></i>
                <p style="font-size: 14px;">Đang tải tin nhắn...</p>
            </div>
        `;
        
        try {
            // ============================================
            // BƯỚC 4: GỌI API LẤY TIN NHẮN PHÒNG (NO-RELOAD)
            // ============================================
            const response = await fetch(
                `${window.URLROOT}/index.php?controller=chat&action=getRoomMessages&room_id=${id}&user_id=${this.currentUserId}`
            );
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.status !== 'success') {
                throw new Error(result.message || 'Không thể lấy tin nhắn');
            }
            
            // ============================================
            // BƯỚC 5: CẬP NHẬT HEADER VỚI THÔNG TIN PHÒNG
            // ============================================
            const roomData = result.data.room;
            const messages = result.data.messages;
            
            if (roomNameElement) {
                roomNameElement.textContent = this.escapeHTML(roomData.name);
            }
            
            if (subtitleElement) {
                subtitleElement.textContent = `${roomData.member_count} thành viên • ${messages.length} tin nhắn`;
            }
            
            // ============================================
            // BƯỚC 6: XÓA SẠCH TIN NHẮN CŨ VÀ RENDER TIN NHẮN MỚI
            // ============================================
            this.elements.messagesContainer.innerHTML = '';
            
            if (messages.length === 0) {
                this.elements.messagesContainer.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <p>Chưa có tin nhắn nào trong phòng chat này</p>
                    </div>
                `;
            } else {
                messages.forEach(msg => {
                    this.renderMessage(msg);
                });
            }
            
            // ============================================
            // BƯỚC 7: CẬP NHẬT roomId HIỆN TẠI
            // ============================================
            this.roomId = id;
            
            if (this.elements.roomIdInput) {
                this.elements.roomIdInput.value = id;
            }
            
            // ============================================
            // BƯỚC 8: CẬP NHẬT lastMessageId ĐỂ POLLING
            // ============================================
            if (messages.length > 0) {
                const lastMsg = messages[messages.length - 1];
                this.lastMessageId = lastMsg.id;
            }
            
            this.scrollToBottom();
            
            // ============================================
            // BƯỚC 9: CẬP NHẬT LẠI SIDEBAR (XÓA BADGE UNREAD)
            // ============================================
            if (clickedRoom) {
                const badge = clickedRoom.querySelector('.unread-badge');
                if (badge) {
                    badge.remove();
                }
            }
            
            // Nếu đang ở tab Unread, refresh lại danh sách
            const activeChip = document.querySelector('.chip.active');
            if (activeChip && activeChip.dataset.filter === 'unread') {
                setTimeout(() => {
                    this.handleFilterChange('unread');
                }, 500);
            }
            
            console.log(`✅ Đã chuyển sang phòng ${id} thành công (NO-RELOAD)`);
            
        } catch (error) {
            console.error('❌ Lỗi khi chuyển phòng:', error);
            
            this.elements.messagesContainer.innerHTML = `
                <div class="error-state" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #ef4444;">
                    <i class="fas fa-exclamation-circle" style="font-size: 48px; margin-bottom: 16px;"></i>
                    <p style="font-size: 14px;">Không thể tải tin nhắn. Vui lòng thử lại.</p>
                </div>
            `;
        }
        
        // ============================================
        // BƯỚC 10: XỬ LÝ MOBILE
        // ============================================
        if (window.innerWidth <= 768) {
            const appContainer = document.querySelector('.app-container');
            if (appContainer) {
                appContainer.classList.add('mobile-chat-active');
            }
        }
    }

    /**
     * ============================================
     * HÀM renderMessage: RENDER MỘT TIN NHẮN
     * ============================================
     * Helper function để render tin nhắn vào DOM
     * 
     * @param {Object} msg - Tin nhắn {id, sender_id, username, content, type, sent_at, is_me}
     */
    renderMessage(msg) {
        const isMe = msg.is_me || (parseInt(msg.sender_id) === this.currentUserId);
        const wrapperClass = isMe ? 'my-message' : 'other-message';
        const senderName = isMe ? 'You' : (msg.username || 'User ' + msg.sender_id);
        const avatarChar = senderName.charAt(0).toUpperCase();

        let contentHtml = '';
        if (msg.type === 'image') {
            contentHtml = `<img src="${window.URLROOT}/${this.escapeHTML(msg.content)}" alt="Image" style="max-width: 200px; border-radius: 8px; cursor: pointer;">`;
        } else if (msg.type === 'file') {
            const fileName = msg.content.split('/').pop();
            contentHtml = `<a href="${window.URLROOT}/${this.escapeHTML(msg.content)}" target="_blank" class="message-file"><i class="fas fa-file"></i> ${this.escapeHTML(fileName)}</a>`;
        } else {
            contentHtml = this.escapeHTML(msg.content || '');
        }

        let displayTime = '';
        if (msg.sent_at) {
            const msgDate = new Date(msg.sent_at);
            displayTime = msgDate.toLocaleTimeString('vi-VN', {
                hour: '2-digit',
                minute: '2-digit'
            });
        } else {
            displayTime = this.getCurrentTime();
        }

        const html = `
            <div class="message-wrapper ${wrapperClass}" data-message-id="${msg.id || 0}">
                <div class="message-avatar">${avatarChar}</div>
                <div class="message-content">
                    <div class="message-sender">${this.escapeHTML(senderName)}</div>
                    <div class="message-bubble">
                        <div class="message-text">${contentHtml}</div>
                        <div class="message-time">${displayTime}</div>
                    </div>
                </div>
            </div>
        `;

        this.elements.messagesContainer.insertAdjacentHTML('beforeend', html);
    }

    /**
     * Hiển thị thông tin phòng
     */
    async showRoomInfo() {
        try {
            // Hiển modal
            this.elements.roomInfoModal.style.display = 'flex';
            this.elements.modalBody.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Đang tải...</div>';

            // Gọi API lấy thông tin
            const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=getRoomInfo&room_id=${this.roomId}`);
            if (!response.ok) throw new Error('Không thể tải thông tin');

            const result = await response.json();
            if (result.status === 'success') {
                this.renderRoomInfo(result.data);
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            console.error('❌ Lỗi:', error);
            this.elements.modalBody.innerHTML = '<div class="error-message"><i class="fas fa-exclamation-circle"></i> Không thể tải thông tin</div>';
        }
    }

    /**
     * Render thông tin phòng vào modal
     */
    renderRoomInfo(data) {
        const room = data.room;
        const members = data.members;

        let html = `
            <div class="room-info-section">
                <div class="room-info-header">
                    <div class="room-avatar-large">${room.name ? room.name.charAt(0).toUpperCase() : 'R'}</div>
                    <h4>${this.escapeHTML(room.name || 'Phòng chat')}</h4>
                    <p class="room-type-badge">
                        <i class="fas fa-${room.type === 'group' ? 'users' : 'user'}"></i>
                        ${room.type === 'group' ? 'Nhóm' : 'Riêng tư'}
                    </p>
                </div>

                <div class="room-info-details">
                    <div class="info-item">
                        <i class="fas fa-calendar"></i>
                        <span>Tạo lúc: ${new Date(room.created_at).toLocaleString('vi-VN')}</span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-users"></i>
                        <span>${members.length} thành viên</span>
                    </div>
                </div>

                <div class="members-section">
                    <h5><i class="fas fa-users"></i> Danh sách thành viên</h5>
                    <div class="members-list">
        `;

        members.forEach((member, index) => {
            const joinedDate = new Date(member.joined_at).toLocaleDateString('vi-VN');
            html += `
                <div class="member-item">
                    <div class="member-avatar">${member.username.charAt(0).toUpperCase()}</div>
                    <div class="member-info">
                        <div class="member-name">${this.escapeHTML(member.username)}</div>
                        <div class="member-joined">Tham gia: ${joinedDate}</div>
                    </div>
                    ${index === 0 ? '<span class="admin-badge"><i class="fas fa-crown"></i> Admin</span>' : ''}
                </div>
            `;
        });

        html += `
                    </div>
                </div>
            </div>
        `;

        this.elements.modalBody.innerHTML = html;
    }

    /**
     * ============================================
     * HÀM openNewChatModal: MỞ MODAL TẠO PHÒNG MỚI
     * ============================================
     */
    openNewChatModal() {
        if (this.elements.newChatModal) {
            this.elements.newChatModal.style.display = 'flex';
            // Reset form
            this.elements.newChatForm.reset();
            this.elements.memberIdsGroup.style.display = 'none';
            this.elements.chatNameInput.placeholder = 'Tìm kiếm người nhận...';
            this.elements.chatNameInput.focus();
            this.hideUserSuggestions();
            console.log('🆕 Mở modal tạo phòng mới');
        }
    }

    /**
     * ============================================
     * HÀM closeNewChatModal: ĐÓNG MODAL TẠO PHÒNG
     * ============================================
     */
    closeNewChatModal() {
        if (this.elements.newChatModal) {
            this.elements.newChatModal.style.display = 'none';
            console.log('❌ Đóng modal tạo phòng');
        }
    }

    /**
     * ============================================
     * HÀM handleCreateNewChat: TẠO PHÒNG MỚI
     * ============================================
     * 
     * LUỐNG HOẠT ĐỘNG:
     * 1. Lấy dữ liệu từ form (type, name, member_ids)
     * 2. Gửi AJAX request lên ChatController->createRoom
     * 3. Nếu thành công:
     *    - Đóng modal
     *    - Thêm phòng mới lên đầu Sidebar (prepend)
     *    - Tự động chuyển vào phòng đó (NO RELOAD)
     */
    async handleCreateNewChat() {
        const chatType = this.elements.chatTypeSelect.value;
        const chatName = this.elements.chatNameInput.value.trim();
        const memberIds = this.elements.memberIdsInput.value.trim();

        // Validation
        if (!chatName) {
            alert('⚠️ Vui lòng nhập tên phòng!');
            this.elements.chatNameInput.focus();
            return;
        }

        // Hiệu ứng loading
        const createBtn = this.elements.createNewChatBtn;
        const originalHTML = createBtn.innerHTML;
        createBtn.disabled = true;
        createBtn.classList.add('loading');
        createBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang tạo...';

        try {
            console.log(`🆕 Tạo phòng mới: ${chatName} (${chatType})`);

            // ============================================
            // FIX LỖI 400: GỬi JSON thay vì FormData
            // ============================================
            const payload = {
                room_name: chatName,
                type: chatType
            };

            if (memberIds) {
                payload.member_ids = memberIds;
            }

            console.log('📤 Payload gửi lên server:', payload);

            const response = await fetch(
                `${window.URLROOT}/index.php?controller=chat&action=createRoom&user_id=${this.currentUserId}`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                }
            );

            if (!response.ok) {
                const errorText = await response.text();
                console.error('❌ HTTP Error:', response.status, errorText);
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();
            console.log('📥 Response từ server:', result);

            if (result.status === 'success') {
                console.log('✅ Tạo phòng thành công:', result);

                // Đóng modal
                this.closeNewChatModal();

                // Lấy thông tin phòng mới
                const roomId = result.room_id;
                const roomName = chatName;

                // Thêm phòng mới lên đầu Sidebar (prepend)
                this.prependNewRoomToSidebar({
                    id: roomId,
                    name: roomName,
                    type: chatType,
                    last_message: 'Chưa có tin nhắn',
                    last_time: 'Vừa xong',
                    unread_count: 0,
                    is_online: false
                });

                // Tự động chuyển vào phòng mới (NO RELOAD)
                setTimeout(() => {
                    this.handleRoomClick(roomId, roomName);
                }, 300);

                alert(`✅ Đã tạo phòng "${roomName}" thành công!`);
            } else {
                throw new Error(result.message || 'Không thể tạo phòng');
            }
        } catch (error) {
            console.error('❌ Lỗi tạo phòng:', error);
            alert(`Không thể tạo phòng: ${error.message}`);
        } finally {
            // Khôi phục nút
            createBtn.disabled = false;
            createBtn.classList.remove('loading');
            createBtn.innerHTML = originalHTML;
        }
    }

    /**
     * ============================================
     * HÀM prependNewRoomToSidebar: THÊM PHÒNG MỚI LÊN ĐẦU
     * ============================================
     * 
     * Thêm phòng mới vào đầu danh sách Sidebar với animation fade-in
     */
    prependNewRoomToSidebar(room) {
        const avatarChar = room.name ? room.name.charAt(0).toUpperCase() : 'R';
        const unreadBadge = room.unread_count > 0 
            ? `<span class="unread-badge">${room.unread_count}</span>` 
            : '';
        const onlineIndicator = room.is_online 
            ? '<span class="online-indicator"></span>' 
            : '';

        const html = `
            <div class="room-item new-room-animation" 
                 data-room-id="${room.id}" 
                 data-room-name="${this.escapeHTML(room.name)}">
                <div class="room-avatar">
                    ${avatarChar}
                    ${onlineIndicator}
                </div>
                <div class="room-info">
                    <div class="room-header">
                        <h4 class="room-name">${this.escapeHTML(room.name)}</h4>
                        <div class="room-meta">
                            <span class="room-time">${room.last_time}</span>
                            <div class="room-menu-trigger">
                                <i class="fas fa-ellipsis-v"></i>
                            </div>
                        </div>
                    </div>
                    <div class="room-preview">
                        <p class="last-message">${this.escapeHTML(room.last_message)}</p>
                        ${unreadBadge}
                    </div>
                </div>
                <div class="room-dropdown-menu">
                    <div class="dropdown-item" data-action="pin">
                        <i class="fas fa-thumbtack"></i>
                        <span>Ghim đoạn chat</span>
                    </div>
                    <div class="dropdown-item dropdown-item-danger" data-action="delete">
                        <i class="fas fa-trash"></i>
                        <span>Xóa đoạn chat</span>
                    </div>
                </div>
            </div>
        `;

        // Thêm vào đầu danh sách (prepend)
        this.elements.roomListContainer.insertAdjacentHTML('afterbegin', html);

        // Xóa empty state nếu có
        const emptyState = this.elements.roomListContainer.querySelector('.empty-state');
        if (emptyState) emptyState.remove();

        console.log(`✅ Đã thêm phòng ${room.id} vào Sidebar`);
    }

    /**
     * Đóng modal
     */
    closeModal() {
        if (this.elements.roomInfoModal) {
            this.elements.roomInfoModal.style.display = 'none';
        }
    }

    handleFileUpload(files, type) {
        if (!files || files.length === 0) return;
        console.log(`📎 Đã chọn ${files.length} file(s) loại ${type}`);
        
        // Push files vào mảng pendingFiles
        Array.from(files).forEach(file => {
            this.pendingFiles.push(file);
        });
        
        // Render preview
        this.renderPreview();
    }

    renderPreview() {
        const previewContainer = document.getElementById('attachment-preview');
        if (!previewContainer) return;
        
        previewContainer.innerHTML = '';
        
        if (this.pendingFiles.length === 0) {
            previewContainer.style.display = 'none';
            return;
        }
        
        previewContainer.style.display = 'flex';
        
        this.pendingFiles.forEach((file, index) => {
            const wrapper = document.createElement('div');
            wrapper.style.cssText = 'position: relative; margin-right: 8px;';
            
            if (file.type.startsWith('image/')) {
                const img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.style.cssText = 'height: 60px; border-radius: 5px; object-fit: cover;';
                wrapper.appendChild(img);
            } else {
                const fileIcon = document.createElement('div');
                fileIcon.style.cssText = 'height: 60px; width: 60px; display: flex; align-items: center; justify-content: center; background: #333; border-radius: 5px;';
                fileIcon.innerHTML = `<i class="fas fa-file" style="font-size: 24px; color: #fff;"></i>`;
                wrapper.appendChild(fileIcon);
            }
            
            // Nút X xóa
            const removeBtn = document.createElement('span');
            removeBtn.className = 'remove-file';
            removeBtn.dataset.index = index;
            removeBtn.innerHTML = '&times;';
            removeBtn.style.cssText = 'position: absolute; top: -5px; right: -5px; cursor: pointer; color: red; font-weight: bold; background: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 16px;';
            removeBtn.addEventListener('click', () => this.removeFile(index));
            wrapper.appendChild(removeBtn);
            
            previewContainer.appendChild(wrapper);
        });
    }

    removeFile(index) {
        this.pendingFiles.splice(index, 1);
        this.renderPreview();
        console.log(`🗑️ Đã xóa file tại index ${index}`);
    }

    showSidebar() {
        const appContainer = document.querySelector('.app-container');
        if (appContainer) {
            appContainer.classList.remove('mobile-chat-active');
        }
    }

    closePinnedMessage() {
        if (this.elements.pinnedMessageBar) {
            this.elements.pinnedMessageBar.style.display = 'none';
        }
    }

    handleResize() {
        if (window.innerWidth > 768) {
            const appContainer = document.querySelector('.app-container');
            if (appContainer) {
                appContainer.classList.remove('mobile-chat-active');
            }
        }
    }

    toggleEmojiPicker() {
        if (!this.elements.emojiPicker) return;
        const isVisible = this.elements.emojiPicker.style.display === 'block';
        this.elements.emojiPicker.style.display = isVisible ? 'none' : 'block';
    }

    insertEmoji(emoji) {
        if (!this.elements.messageInput) return;
        const input = this.elements.messageInput;
        const cursorPos = input.selectionStart;
        const textBefore = input.value.substring(0, cursorPos);
        const textAfter = input.value.substring(cursorPos);
        
        input.value = textBefore + emoji + textAfter;
        input.focus();
        input.selectionStart = input.selectionEnd = cursorPos + emoji.length;
        
        this.toggleSendButton(input.value.trim());
        this.elements.emojiPicker.style.display = 'none';
    }

    /**
     * ============================================
     * SHORT POLLING - BẮT ĐẦU
     * ============================================
     * 
     * SHORT POLLING là kỹ thuật client gửi request định kỳ (mỗi 3s) để hỏi server:
     * "Có tin nhắn mới không?". Khác với Long Polling (giữ kết nối) hay WebSocket (2-way realtime).
     * 
     * Ưu điểm:
     * - Đơn giản, dễ implement
     * - Không cần WebSocket server phức tạp
     * - Tương thích mọi hosting PHP thông thường
     * 
     * Nhược điểm:
     * - Delay tối đa = polling interval (3s)
     * - Tốn băng thông nếu không có tin nhắn mới
     * 
     * VẤN ĐÁP: "Em dùng Short Polling vì dự án PHP MVC đơn giản, không cần WebSocket.
     * Mỗi 3 giây client gọi API getNewMessages với tham số last_id để chỉ lấy tin nhắn mới hơn."
     */
    startPolling() {
        // Lấy ID tin nhắn cuối cùng từ HTML (data-message-id) khi load trang
        const lastMsg = this.elements.messagesContainer.querySelector('.message-wrapper:last-child');
        if (lastMsg && lastMsg.dataset.messageId) {
            this.lastMessageId = parseInt(lastMsg.dataset.messageId);
        }
        
        // Gọi fetchNewMessages() mỗi 3000ms (3 giây)
        this.pollingInterval = setInterval(() => {
            this.fetchNewMessages();
        }, 3000);
        
        console.log('🔄 Polling started, lastMessageId:', this.lastMessageId);
    }

    /**
     * Dừng Polling (khi chuyển phòng hoặc đóng app)
     */
    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
            console.log('⏸️ Polling stopped');
        }
    }

    /**
     * ============================================
     * FETCH TIN NHẮN MỚI - XỬ LÝ EDGE CASES
     * ============================================
     * 
     * CỜ HIỆU isPolling (Lock State Pattern):
     * - Nếu request trước chưa xong (isPolling = true), BỎ QUA request mới
     * - Tránh spam request khi server chậm hoặc mạng lag
     * - Đảm bảo chỉ có 1 request polling tại 1 thời điểm
     * 
     * XỬ LÝ JSON PARSE:
     * - KHÔNG dùng response.json() trực tiếp vì nếu server trả HTML/Text sẽ throw lỗi khó debug
     * - Dùng response.text() trước, sau đó JSON.parse() trong try...catch
     * - Nếu lỗi parse, log ra rawText để dev thấy server đang trả gì (HTML error, echo debug, etc.)
     * 
     * VẤN ĐÁP: "Em dùng cờ isPolling để chống spam request khi server chậm.
     * Và dùng try...catch bọc JSON.parse() để bắt lỗi khi server trả HTML thay vì JSON,
     * giúp debug dễ dàng hơn là để throw lỗi 'Unexpected token <'."
     */
    async fetchNewMessages() {
        // EDGE CASE 1: Kiểm tra roomId hợp lệ
        if (!this.roomId) return;
        
        // EDGE CASE 2: Chống spam request (Lock State)
        if (this.isPolling) {
            console.warn('⚠️ Request trước chưa xong, bỏ qua polling này');
            return;
        }
        
        // Đặt cờ = true để lock
        this.isPolling = true;
        
        try {
            // Gọi API với last_id để chỉ lấy tin nhắn mới hơn
            const url = `${window.URLROOT}/index.php?controller=chat&action=getNewMessages&room_id=${this.roomId}&last_id=${this.lastMessageId}`;
            const response = await fetch(url);
            
            // EDGE CASE 3: Kiểm tra HTTP status
            if (!response.ok) {
                console.warn(`⚠️ HTTP ${response.status}`);
                return;
            }
            
            // CHỐNG SẬP JSON: Lấy raw text trước
            const rawText = await response.text();
            
            // Parse JSON trong try...catch để bắt lỗi
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseError) {
                // Nếu parse lỗi, log ra raw text để debug
                console.error('❌ Lỗi Server trả về HTML/Text rác thay vì JSON:');
                console.error('Raw Response:', rawText.substring(0, 500)); // Log 500 ký tự đầu
                console.error('Parse Error:', parseError.message);
                return;
            }
            
            // EDGE CASE 4: Kiểm tra data hợp lệ
            if (data.status === 'success' && data.data && Array.isArray(data.data) && data.data.length > 0) {
                console.log(`📩 Nhận ${data.data.length} tin nhắn mới từ server`);
                
                /**
                 * ============================================
                 * XỬ LÝ LỖI "ECHO BUG" (NHẠI TIN NHẮN)
                 * ============================================
                 * 
                 * VẤN ĐỀ:
                 * - Khi user gửi tin nhắn, giao diện đã tự cập nhật ngay (appendMyMessage)
                 * - Nhưng 3 giây sau, Polling lại lấy chính tin nhắn đó từ DB về
                 * - Nếu không filter, tin nhắn sẽ hiển thị 2 lần (1 lần "You", 1 lần "Quang")
                 * 
                 * GIẢI PHÁP:
                 * 1. LUÔN LUÔN cập nhật lastMessageId để lần sau không lấy lại nữa
                 * 2. CHECK ĐỘI ÂM: Nếu sender_id === currentUserId thì SKIP (không render)
                 * 3. Chỉ render tin nhắn của NGƯỜI KHÁC (appendOtherMessage)
                 * 
                 * VẤN ĐÁP: "Lỗi Echo Bug xảy ra khi Polling lấy lại tin nhắn của chính mình.
                 * Em fix bằng cách so sánh sender_id với currentUserId, nếu trùng thì skip,
                 * chỉ cập nhật lastMessageId để lần sau không lấy lại nữa."
                 */
                data.data.forEach(msg => {
                    // 1. LUÔN LUÔN cập nhật lastMessageId (dù có render hay không)
                    if (msg.id > this.lastMessageId) {
                        this.lastMessageId = msg.id;
                    }
                    
                    // 2. CHECK ĐỘI ÂM (ECHO): Nếu tin nhắn này do chính mình gửi thì BỎ QUA
                    if (parseInt(msg.sender_id) === parseInt(this.currentUserId)) {
                        console.log(`⏭️ Skip tin nhắn #${msg.id} (do chính mình gửi)`);
                        return; // Skip vòng lặp này, không render
                    }
                    
                    // 3. Nếu là tin của NGƯỜI KHÁC, thì in ra màn hình
                    console.log(`✅ Render tin nhắn #${msg.id} từ user #${msg.sender_id}`);
                    // Thêm sender_name vào data để appendMessage hiển thị đúng tên
                    msg.sender_name = msg.username;
                    this.appendMessage(msg);
                });
                
                this.scrollToBottom();
            }
            
        } catch (error) {
            // Bắt lỗi network hoặc lỗi khác
            console.error('❌ Polling error:', error);
        } finally {
            // QUAN TRỌNG: Luôn unlock cờ trong finally
            this.isPolling = false;
        }
    }

    /**
     * ============================================
     * HÀM pinRoom: GHIM PHÒNG CHAT
     * ============================================
     */
    async pinRoom(roomId, roomName) {
        try {
            console.log(`📌 Ghim phòng: ${roomName} (ID: ${roomId})`);
            
            const response = await fetch(
                `${window.URLROOT}/index.php?controller=chat&action=pinRoom&room_id=${roomId}&user_id=${this.currentUserId}`,
                { method: 'POST' }
            );
            
            if (!response.ok) throw new Error('Không thể ghim phòng');
            
            const result = await response.json();
            
            if (result.status === 'success') {
                const newPinnedState = result.data.is_pinned;
                const actionText = newPinnedState ? 'ghim' : 'bỏ ghim';
                
                console.log(`✅ Đã ${actionText} phòng "${roomName}"`);
                
                // Cập nhật ngay data-is-pinned trong DOM
                const roomItem = document.querySelector(`.room-item[data-room-id="${roomId}"]`);
                if (roomItem) {
                    roomItem.dataset.isPinned = newPinnedState ? '1' : '0';
                    
                    // Cập nhật icon ghim
                    const roomNameEl = roomItem.querySelector('.room-name');
                    if (roomNameEl) {
                        const existingIcon = roomNameEl.querySelector('.fa-thumbtack');
                        if (newPinnedState && !existingIcon) {
                            roomNameEl.insertAdjacentHTML('beforeend', '<i class="fas fa-thumbtack" style="color: var(--accent-color); margin-left: 6px; font-size: 12px;"></i>');
                        } else if (!newPinnedState && existingIcon) {
                            existingIcon.remove();
                        }
                    }
                    
                    // Cập nhật text dropdown
                    const dropdownText = roomItem.querySelector('.dropdown-item[data-action="pin"] span');
                    if (dropdownText) {
                        dropdownText.textContent = newPinnedState ? 'Bỏ ghim' : 'Ghim đoạn chat';
                    }
                }
                
                // Refresh lại sidebar để sắp xếp lại thứ tự
                const activeChip = document.querySelector('.chip.active');
                const currentFilter = activeChip ? activeChip.dataset.filter : 'all';
                this.handleFilterChange(currentFilter);
            } else {
                throw new Error(result.message || 'Không thể ghim phòng');
            }
        } catch (error) {
            console.error('❌ Lỗi ghim phòng:', error);
            alert('Không thể ghim phòng. Vui lòng thử lại.');
        }
    }

    /**
     * ============================================
     * HÀM updateSidebarAfterSend: CẬP NHẬT SIDEBAR SAU KHI GỬI TIN NHẮN
     * ============================================
     * 
     * CHỨC NĂNG:
     * - Cập nhật tin nhắn cuối cùng trong Sidebar
     * - Cập nhật thời gian hiện tại (HH:mm)
     * - Đẩy phòng lên đầu danh sách (nếu chưa ghim)
     * 
     * @param {string} messageContent - Nội dung tin nhắn vừa gửi
     */
    updateSidebarAfterSend(messageContent) {
        try {
            // ============================================
            // BƯỚC 1: TÌM THẺ HTML CỦA PHÒNG HIỆN TẠI TRONG SIDEBAR
            // ============================================
            const roomItem = document.querySelector(`.room-item[data-room-id="${this.roomId}"]`);
            if (!roomItem) {
                console.warn('⚠️ Không tìm thấy room-item trong Sidebar');
                return;
            }
            
            // ============================================
            // BƯỚC 2: CẬP NHẬT NỘI DUNG TIN NHẮN CUỐI
            // ============================================
            const messageElement = roomItem.querySelector('.room-message');
            if (messageElement) {
                // Rút gọn nếu quá dài
                let displayText = messageContent;
                if (displayText.length > 30) {
                    displayText = displayText.substring(0, 30) + '...';
                }
                messageElement.textContent = displayText;
            }
            
            // ============================================
            // BƯỚC 3: CẬP NHẬT THỚI GIAN HIỆN TẠI (HH:mm)
            // ============================================
            const timeElement = roomItem.querySelector('.room-time');
            if (timeElement) {
                const now = new Date();
                const hours = String(now.getHours()).padStart(2, '0');
                const minutes = String(now.getMinutes()).padStart(2, '0');
                timeElement.textContent = `${hours}:${minutes}`;
            }
            
            // ============================================
            // BƯỚC 4: ĐẨY PHÒNG LÊN ĐẦU DANH SÁCH (NẾU CHƯƠ GHIM)
            // ============================================
            const isPinned = roomItem.dataset.isPinned == '1';
            if (!isPinned) {
                // Lấy container chứa danh sách phòng
                const container = roomItem.parentElement;
                
                // Tìm phòng ghim cuối cùng (nếu có)
                const pinnedRooms = container.querySelectorAll('.room-item[data-is-pinned="1"]');
                
                if (pinnedRooms.length > 0) {
                    // Chèn sau phòng ghim cuối cùng
                    const lastPinnedRoom = pinnedRooms[pinnedRooms.length - 1];
                    lastPinnedRoom.insertAdjacentElement('afterend', roomItem);
                } else {
                    // Không có phòng ghim, đẩy lên đầu tiên
                    container.insertBefore(roomItem, container.firstChild);
                }
                
                // Hiệu ứng highlight ngắn
                roomItem.style.transition = 'background-color 0.3s ease';
                roomItem.style.backgroundColor = 'var(--hover-color)';
                setTimeout(() => {
                    roomItem.style.backgroundColor = '';
                }, 300);
            }
            
            console.log('✅ Đã cập nhật Sidebar sau khi gửi tin nhắn');
            
        } catch (error) {
            console.error('❌ Lỗi cập nhật Sidebar:', error);
        }
    }

    /**
     * ============================================
     * HÀM deleteRoom: XÓA PHÒNG CHAT - FIX LỖ HỔNG UI/UX
     * ============================================
     * 
     * LỐ HỔNG ĐÃ FIX:
     * - Sau khi xóa phòng, nếu đang mở phòng đó thì phải xóa sạch nội dung bên phải
     * - Reset Header và hiển thị empty state
     * - Tự động chuyển sang phòng khác nếu còn
     */
    async deleteRoom(roomId, roomName) {
        // Hiển thị confirm trước khi xóa
        const confirmDelete = confirm(
            `⚠️ Bạn có chắc chắn muốn xóa đoạn chat "${roomName}"?\n\nHành động này không thể hoàn tác!`
        );
        
        if (!confirmDelete) {
            console.log('❌ Hủy xóa phòng');
            return;
        }
        
        try {
            console.log(`🗑️ Xóa phòng: ${roomName} (ID: ${roomId})`);
            
            // ============================================
            // BƯỚC 1: GỌI API XÓA PHÒNG
            // ============================================
            const response = await fetch(
                `${window.URLROOT}/index.php?controller=chat&action=deleteRoom&room_id=${roomId}&user_id=${this.currentUserId}`,
                { method: 'POST' }
            );
            
            if (!response.ok) throw new Error('Không thể xóa phòng');
            
            const result = await response.json();
            
            if (result.status === 'success') {
                console.log(`✅ Đã xóa phòng ${roomId}`);
                
                // ============================================
                // BƯỚC 2: XÓA THẺ HTML KHỎI SIDEBAR (NO RELOAD)
                // ============================================
                const roomItem = document.querySelector(`.room-item[data-room-id="${roomId}"]`);
                if (roomItem) {
                    // Fade out animation
                    roomItem.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                    roomItem.style.opacity = '0';
                    roomItem.style.transform = 'translateX(-20px)';
                    
                    // Xóa khỏi DOM sau animation
                    setTimeout(() => {
                        roomItem.remove();
                    }, 300);
                }
                
                // ============================================
                // BƯỚC 3: KIỂM TRA NẾU ĐANG MỞ PHÒNG VỪ XÓA (FIX LỐ HỔNG)
                // ============================================
                if (parseInt(this.roomId) === parseInt(roomId)) {
                    console.log('⚠️ Đang mở phòng vừa xóa, cần xóa sạch nội dung bên phải');
                    
                    // ============================================
                    // BƯỚC 3.1: XÓA SẠCH NỘI DUNG KHUNG CHAT
                    // ============================================
                    this.elements.messagesContainer.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>Vui lòng chọn một đoạn chat để bắt đầu</p>
                        </div>
                    `;
                    
                    // ============================================
                    // BƯỚC 3.2: RESET HEADER (XÓA TÊN PHÒNG)
                    // ============================================
                    const roomNameElement = document.querySelector('.chat-header-info h2');
                    if (roomNameElement) {
                        roomNameElement.textContent = 'Chọn phòng chat';
                    }
                    
                    const subtitleElement = document.querySelector('.chat-header-info p');
                    if (subtitleElement) {
                        subtitleElement.textContent = '';
                    }
                    
                    // ============================================
                    // BƯỚC 3.3: TỰ ĐỘNG CHUYỂN SANG PHÒNG KHÁC (NẾU CÒN)
                    // ============================================
                    setTimeout(() => {
                        const firstRoom = document.querySelector('.room-item');
                        if (firstRoom) {
                            const newRoomId = parseInt(firstRoom.dataset.roomId);
                            const newRoomName = firstRoom.dataset.roomName || 'Phòng chat';
                            console.log(`🔄 Tự động chuyển sang phòng ${newRoomId}`);
                            this.handleRoomClick(newRoomId, newRoomName);
                        } else {
                            // Không còn phòng nào
                            console.log('ℹ️ Không còn phòng nào');
                            this.roomId = null;
                        }
                    }, 400);
                }
                
                alert(`✅ Đã xóa phòng "${roomName}"`);
            } else {
                throw new Error(result.message || 'Không thể xóa phòng');
            }
        } catch (error) {
            console.error('❌ Lỗi xóa phòng:', error);
            alert('Không thể xóa phòng. Vui lòng thử lại.');
        }
    }

    /**
     * ============================================
     * HÀM addUserToGroup: THÊM USER VÀO NHÓM HIỆN TẠI
     * ============================================
     * 
     * CHỨC NĂNG:
     * - Thêm user vào phòng chat hiện tại (currentRoomId)
     * - Gọi API ChatController->addMember
     * - Hiển thị thông báo thành công
     * - Tự động refresh Sidebar để cập nhật số thành viên
     * 
     * @param {number} userId - ID của user cần thêm
     */
    async addUserToGroup(userId) {
        try {
            if (!this.roomId) {
                alert('⚠️ Vui lòng chọn một phòng chat trước!');
                return;
            }

            if (!userId || userId <= 0) {
                alert('⚠️ User ID không hợp lệ!');
                return;
            }

            console.log(`➕ Thêm user ${userId} vào phòng ${this.roomId}`);

            // Gọi API thêm thành viên
            const response = await fetch(
                `${window.URLROOT}/index.php?controller=chat&action=addMember&room_id=${this.roomId}&user_id=${userId}`,
                { method: 'POST' }
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            if (result.status === 'success') {
                console.log('✅ Đã thêm thành viên thành công');
                
                // Hiển thị thông báo
                alert('✅ Đã thêm thành viên vào nhóm!');

                // Refresh lại Sidebar để cập nhật
                const activeChip = document.querySelector('.chip.active');
                const currentFilter = activeChip ? activeChip.dataset.filter : 'all';
                await this.handleFilterChange(currentFilter);

                // Đóng modal tìm kiếm nếu đang mở
                const searchModal = document.getElementById('search-user-modal');
                if (searchModal) {
                    searchModal.style.display = 'none';
                }
            } else {
                throw new Error(result.message || 'Không thể thêm thành viên');
            }
        } catch (error) {
            console.error('❌ Lỗi thêm thành viên:', error);
            alert(`Không thể thêm thành viên: ${error.message}`);
        }
    }

    /**
     * ============================================
     * HÀM createPrivateChat: TẠO/MỞ CHAT 1-1
     * ============================================
     * 
     * CHỨC NĂNG:
     * - Gọi API để kiểm tra phòng 1-1 đã tồn tại chưa
     * - Nếu có: Mở phòng đó luôn
     * - Nếu chưa: Tạo phòng mới và mở
     * - Tự động chuyển vào phòng chat (NO RELOAD)
     * 
     * @param {number} targetUserId - ID của user muốn chat
     * @param {string} targetUserName - Tên của user (hiển thị)
     */
    async createPrivateChat(targetUserId, targetUserName) {
        try {
            if (!targetUserId || targetUserId <= 0) {
                alert('⚠️ User ID không hợp lệ!');
                return;
            }

            console.log(`💬 Tạo/Mở chat 1-1 với user ${targetUserId} (${targetUserName})`);

            // Hiển thị loading
            const loadingMsg = document.createElement('div');
            loadingMsg.id = 'loading-private-chat';
            loadingMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.8); color: white; padding: 20px 40px; border-radius: 8px; z-index: 10000; font-size: 16px;';
            loadingMsg.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang mở đoạn chat...';
            document.body.appendChild(loadingMsg);

            // Gọi API tạo/tìm phòng 1-1
            const response = await fetch(
                `${window.URLROOT}/index.php?controller=chat&action=createRoom&user_id=${this.currentUserId}`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        room_name: `Chat với ${targetUserName}`,
                        type: 'private',
                        target_user_id: targetUserId
                    })
                }
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            if (result.status === 'success') {
                const roomId = result.room_id;
                const isExisting = result.is_existing || false;

                console.log(`✅ ${isExisting ? 'Đã tìm thấy' : 'Đã tạo'} phòng 1-1: ${roomId}`);

                // Xóa loading
                loadingMsg.remove();

                // Đóng modal tìm kiếm
                const searchModal = document.getElementById('search-user-modal');
                if (searchModal) {
                    searchModal.style.display = 'none';
                }

                // Nếu là phòng mới, thêm vào Sidebar
                if (!isExisting) {
                    this.prependNewRoomToSidebar({
                        id: roomId,
                        name: `Chat với ${targetUserName}`,
                        type: 'private',
                        last_message: 'Chưa có tin nhắn',
                        last_time: 'Vừa xong',
                        unread_count: 0,
                        is_online: false
                    });
                }

                // Tự động mở phòng chat (NO RELOAD)
                setTimeout(() => {
                    this.handleRoomClick(roomId, `Chat với ${targetUserName}`);
                }, 300);

            } else {
                throw new Error(result.message || 'Không thể tạo phòng chat');
            }
        } catch (error) {
            console.error('❌ Lỗi tạo chat 1-1:', error);
            
            // Xóa loading nếu có
            const loadingMsg = document.getElementById('loading-private-chat');
            if (loadingMsg) loadingMsg.remove();
            
            alert(`Không thể mở đoạn chat: ${error.message}`);
        }
    }

    /**
     * ============================================
     * HÀM searchUsers: TÌM KIẾM USER
     * ============================================
     * 
     * CHỨC NĂNG:
     * - Gọi API tìm kiếm user theo keyword
     * - Hiển thị kết quả trong modal
     * - Mỗi user có 2 nút:
     *   + "Chat 1-1": Gọi createPrivateChat()
     *   + "Thêm vào nhóm": Gọi addUserToGroup() (chỉ hiện khi đang ở Group Chat)
     * 
     * @param {string} keyword - Từ khóa tìm kiếm
     */
    async searchUsers(keyword) {
        try {
            if (!keyword || keyword.trim().length < 2) {
                console.warn('⚠️ Keyword quá ngắn');
                return;
            }

            console.log(`🔍 Tìm kiếm user: ${keyword}`);

            // Hiển thị loading trong modal
            const resultsContainer = document.getElementById('search-results');
            if (resultsContainer) {
                resultsContainer.innerHTML = '<div style="text-align: center; padding: 20px; color: #888;"><i class="fas fa-spinner fa-spin"></i> Đang tìm kiếm...</div>';
            }

            // Gọi API tìm kiếm (giả định có endpoint searchUsers)
            const response = await fetch(
                `${window.URLROOT}/index.php?controller=user&action=search&keyword=${encodeURIComponent(keyword)}`
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            if (result.status === 'success' && result.data && result.data.length > 0) {
                console.log(`✅ Tìm thấy ${result.data.length} user`);
                this.renderSearchResults(result.data);
            } else {
                resultsContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #888;"><i class="fas fa-user-slash" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i><p>Không tìm thấy user nào</p></div>';
            }
        } catch (error) {
            console.error('❌ Lỗi tìm kiếm user:', error);
            const resultsContainer = document.getElementById('search-results');
            if (resultsContainer) {
                resultsContainer.innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><i class="fas fa-exclamation-circle"></i> Lỗi tìm kiếm</div>';
            }
        }
    }

    /**
     * ============================================
     * HÀM renderSearchResults: HIỂN THỊ KẾT QUẢ TÌM KIẾM
     * ============================================
     * 
     * @param {Array} users - Mảng user [{id, username, email}, ...]
     */
    renderSearchResults(users) {
        const resultsContainer = document.getElementById('search-results');
        if (!resultsContainer) return;

        // Kiểm tra xem đang ở Group Chat không
        const currentRoom = document.querySelector(`.room-item[data-room-id="${this.roomId}"]`);
        const isGroupChat = currentRoom && currentRoom.classList.contains('group');

        let html = '';
        users.forEach(user => {
            const avatarChar = user.username ? user.username.charAt(0).toUpperCase() : 'U';
            
            html += `
                <div class="user-search-item" style="display: flex; align-items: center; padding: 12px; border-bottom: 1px solid var(--border-color); transition: background 0.2s;" onmouseover="this.style.background='var(--hover-color)'" onmouseout="this.style.background=''">
                    <div class="user-avatar" style="width: 40px; height: 40px; border-radius: 50%; background: var(--accent-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; margin-right: 12px;">
                        ${this.escapeHTML(avatarChar)}
                    </div>
                    <div class="user-info" style="flex: 1;">
                        <div class="user-name" style="font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">
                            ${this.escapeHTML(user.username)}
                        </div>
                        <div class="user-email" style="font-size: 12px; color: var(--text-secondary);">
                            ${this.escapeHTML(user.email || 'No email')}
                        </div>
                    </div>
                    <div class="user-actions" style="display: flex; gap: 8px;">
                        <button class="btn-chat-private" data-user-id="${user.id}" data-user-name="${this.escapeHTML(user.username)}" style="padding: 6px 12px; background: var(--accent-color); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                            <i class="fas fa-comment"></i> Chat 1-1
                        </button>
                        ${isGroupChat ? `
                        <button class="btn-add-to-group" data-user-id="${user.id}" style="padding: 6px 12px; background: var(--success-color, #10b981); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                            <i class="fas fa-user-plus"></i> Thêm vào nhóm
                        </button>
                        ` : ''}
                    </div>
                </div>
            `;
        });

        resultsContainer.innerHTML = html;

        // Bind events cho các nút
        resultsContainer.querySelectorAll('.btn-chat-private').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const userId = parseInt(e.currentTarget.dataset.userId);
                const userName = e.currentTarget.dataset.userName;
                this.createPrivateChat(userId, userName);
            });
        });

        resultsContainer.querySelectorAll('.btn-add-to-group').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const userId = parseInt(e.currentTarget.dataset.userId);
                this.addUserToGroup(userId);
            });
        });
    }

    /**
     * ============================================
     * HÀM searchUsersInModal: TÌM KIẾM USER TRONG MODAL TẠO CHAT
     * ============================================
     * Tìm kiếm user và hiển thị gợi ý ngay trong modal
     * 
     * @param {string} keyword - Từ khóa tìm kiếm
     */
    async searchUsersInModal(keyword) {
        try {
            if (!keyword || keyword.trim().length < 2) {
                this.hideUserSuggestions();
                return;
            }

            console.log(`🔍 Tìm kiếm user trong modal: ${keyword}`);

            // Gọi API tìm kiếm
            const response = await fetch(
                `${window.URLROOT}/index.php?controller=chat&action=searchUsers&keyword=${encodeURIComponent(keyword)}&user_id=${this.currentUserId}`
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            if (result.status === 'success' && result.data && result.data.length > 0) {
                console.log(`✅ Tìm thấy ${result.data.length} user`);
                this.showUserSuggestions(result.data);
            } else {
                this.hideUserSuggestions();
            }
        } catch (error) {
            console.error('❌ Lỗi tìm kiếm user:', error);
            this.hideUserSuggestions();
        }
    }

    /**
     * ============================================
     * HÀM showUserSuggestions: HIỂN THỊ GỢI Ý USER
     * ============================================
     * Hiển thị dropdown gợi ý user ngay dưới ô input
     * 
     * @param {Array} users - Mảng user [{id, username}, ...]
     */
    showUserSuggestions(users) {
        // Tìm hoặc tạo container gợi ý
        let suggestionsContainer = document.getElementById('user-suggestions');
        
        if (!suggestionsContainer) {
            suggestionsContainer = document.createElement('div');
            suggestionsContainer.id = 'user-suggestions';
            suggestionsContainer.style.cssText = 'position: absolute; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 8px; max-height: 200px; overflow-y: auto; z-index: 1000; width: calc(100% - 40px); margin-top: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);';
            
            // Chèn sau ô input
            this.elements.chatNameInput.parentElement.style.position = 'relative';
            this.elements.chatNameInput.parentElement.appendChild(suggestionsContainer);
        }

        let html = '';
        users.forEach(user => {
            const avatarChar = user.username ? user.username.charAt(0).toUpperCase() : 'U';
            html += `
                <div class="user-suggestion-item" data-user-id="${user.id}" data-user-name="${this.escapeHTML(user.username)}" style="display: flex; align-items: center; padding: 10px; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='var(--hover-color)'" onmouseout="this.style.background=''">
                    <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--accent-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; margin-right: 10px; font-size: 14px;">
                        ${this.escapeHTML(avatarChar)}
                    </div>
                    <div style="flex: 1;">
                        <div style="font-weight: 600; color: var(--text-primary); font-size: 14px;">
                            ${this.escapeHTML(user.username)}
                        </div>
                    </div>
                </div>
            `;
        });

        suggestionsContainer.innerHTML = html;
        suggestionsContainer.style.display = 'block';

        // Bind events
        suggestionsContainer.querySelectorAll('.user-suggestion-item').forEach(item => {
            item.addEventListener('click', (e) => {
                const userId = parseInt(e.currentTarget.dataset.userId);
                const userName = e.currentTarget.dataset.userName;
                
                // Lưu userId đã chọn
                this.selectedUserId = userId;
                
                // Cập nhật input
                this.elements.chatNameInput.value = userName;
                
                // Ẩn gợi ý
                this.hideUserSuggestions();
                
                console.log(`✅ Đã chọn user: ${userName} (ID: ${userId})`);
            });
        });
    }

    /**
     * ============================================
     * HÀM hideUserSuggestions: ẨN GỢI Ý USER
     * ============================================
     */
    hideUserSuggestions() {
        const suggestionsContainer = document.getElementById('user-suggestions');
        if (suggestionsContainer) {
            suggestionsContainer.style.display = 'none';
        }
    }

    /**
     * ============================================
     * HÀM loadMessages: LOAD TIN NHẮN PHÒNG (HELPER)
     * ============================================
     * Helper function để load tin nhắn của phòng
     * 
     * @param {number} roomId - ID phòng cần load
     */
    async loadMessages(roomId) {
        try {
            const response = await fetch(
                `${window.URLROOT}/index.php?controller=chat&action=getRoomMessages&room_id=${roomId}&user_id=${this.currentUserId}`
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            if (result.status === 'success') {
                const roomData = result.data.room;
                const messages = result.data.messages;

                // Cập nhật header
                const roomNameElement = document.querySelector('.chat-header-info h2');
                if (roomNameElement) {
                    roomNameElement.textContent = this.escapeHTML(roomData.name);
                }

                const subtitleElement = document.querySelector('.chat-header-info p');
                if (subtitleElement) {
                    subtitleElement.textContent = `${roomData.member_count} thành viên • ${messages.length} tin nhắn`;
                }

                // Render messages
                this.elements.messagesContainer.innerHTML = '';
                if (messages.length === 0) {
                    this.elements.messagesContainer.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-comments"></i>
                            <p>Chưa có tin nhắn nào trong phòng chat này</p>
                        </div>
                    `;
                } else {
                    messages.forEach(msg => {
                        this.renderMessage(msg);
                    });
                }

                // Cập nhật roomId
                this.roomId = roomId;
                if (this.elements.roomIdInput) {
                    this.elements.roomIdInput.value = roomId;
                }

                // Cập nhật lastMessageId
                if (messages.length > 0) {
                    const lastMsg = messages[messages.length - 1];
                    this.lastMessageId = lastMsg.id;
                }

                this.scrollToBottom();
                console.log(`✅ Đã load tin nhắn phòng ${roomId}`);
            } else {
                throw new Error(result.message || 'Không thể load tin nhắn');
            }
        } catch (error) {
            console.error('❌ Lỗi load tin nhắn:', error);
            this.elements.messagesContainer.innerHTML = `
                <div class="error-state" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #ef4444;">
                    <i class="fas fa-exclamation-circle" style="font-size: 48px; margin-bottom: 16px;"></i>
                    <p style="font-size: 14px;">Không thể tải tin nhắn. Vui lòng thử lại.</p>
                </div>
            `;
        }
    }
}

/**
 * ============================================
 * KHỞI TẠO ỨNG DỤNG
 * ============================================
 */
document.addEventListener('DOMContentLoaded', () => {
    const chatApp = new ChatApplication();
    window.chatApp = chatApp;
    console.log('✅ Chat Application đã khởi tạo!');
});
