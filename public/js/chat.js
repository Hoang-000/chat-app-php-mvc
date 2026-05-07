window.URLROOT = window.URLROOT || 'http://localhost/chat-app-php-mvc/public';

class ChatApplication {
    constructor() {
        // Lấy user_id từ biến global (PHP truyền xuống)
        this.currentUserId = window.CURRENT_USER_ID || 1;
        this.roomId = null;
        this.elements = {};
        this.isSending = false;
        this.pendingFiles = [];
        this.lastMessageId = 0;
        this.isPolling = false;
        this.pollingInterval = null;
        this.pinnedRooms = this.loadPinnedRooms();
        this.allRooms = [];
        
        console.log('👤 User ID:', this.currentUserId);
        this.init();
    }

    init() {
        this.cacheElements();
        this.bindEvents();
        this.loadRoomId();
        this.scrollToBottom();
        this.loadThemeFromStorage();
        
        // KHỚI TẠO lastMessageId từ tin nhắn cuối cùng trên trang
        this.initLastMessageId();
        
        const urlParams = new URLSearchParams(window.location.search);
        const activeFilter = urlParams.get('filter') || 'all';
        
        this.elements.filterChips.forEach(chip => {
            chip.classList.toggle('active', chip.dataset.filter === activeFilter);
        });
        
        // TẮT TẠM - Gây infinite reload loop
        // if (activeFilter !== 'all') this.handleFilterChange(activeFilter);
        
        this.cacheAllRooms();
        this.applyPinnedRooms();
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
            emojiPanelGrid: document.getElementById('emoji-panel-grid'),
            newChatBtn: document.getElementById('new-chat-btn'),
            newChatModal: document.getElementById('new-chat-modal'),
            newChatForm: document.getElementById('new-chat-form'),
            chatTypeSelect: document.getElementById('chat-type'),
            privateUserGroup: document.getElementById('private-user-group'),
            privateUserSelect: document.getElementById('private-user-select'),
            groupNameGroup: document.getElementById('group-name-group'),
            groupNameInput: document.getElementById('group-name'),
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
            
            // Autocomplete cho @mention (chỉ trong group chat)
            if (window.ROOM_TYPE === 'group') {
                this.handleMentionAutocomplete(e);
            }
        });

        if (this.elements.themeToggle) {
            this.elements.themeToggle.addEventListener('click', () => this.toggleTheme());
        }

        if (this.elements.searchInput) {
            this.elements.searchInput.addEventListener('input', (e) => {
                const keyword = e.target.value.trim();
                this.filterRoomList(keyword);
            });
        }

        this.elements.filterChips.forEach(chip => {
            chip.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const filterType = chip.dataset.filter;
                this.elements.filterChips.forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
                this.handleFilterChange(filterType);
            });
        });

        if (this.elements.roomListContainer) {
            this.elements.roomListContainer.addEventListener('click', (e) => {
                if (e.target.closest('.room-menu-trigger')) {
                    e.stopPropagation();
                    const roomItem = e.target.closest('.room-item');
                    const dropdown = roomItem.querySelector('.room-dropdown-menu');
                    document.querySelectorAll('.room-dropdown-menu').forEach(m => m !== dropdown && m.classList.remove('show'));
                    dropdown.classList.toggle('show');
                    return;
                }
                
                if (e.target.closest('.dropdown-item')) {
                    e.stopPropagation();
                    const dropdownItem = e.target.closest('.dropdown-item');
                    const roomItem = dropdownItem.closest('.room-item');
                    if (!roomItem) return;
                    
                    const roomId = roomItem.dataset.roomId;
                    if (!roomId || isNaN(roomId)) return;
                    
                    const roomName = roomItem.dataset.roomName || 'Phòng chat';
                    const roomType = roomItem.dataset.roomType || 'private';
                    const action = dropdownItem.dataset.action;
                    
                    roomItem.querySelector('.room-dropdown-menu')?.classList.remove('show');
                    
                    if (action === 'pin') this.pinRoom(parseInt(roomId), roomName);
                    else if (action === 'leave') this.leaveRoom(parseInt(roomId), roomName);
                    else if (action === 'delete') this.deleteRoom(parseInt(roomId), roomName, roomType);
                    return;
                }
                
                const roomItem = e.target.closest('.room-item');
                if (!roomItem) return;
                
                const roomId = roomItem.dataset.roomId;
                if (!roomId || isNaN(roomId)) return;
                
                this.handleRoomClick(parseInt(roomId), roomItem.dataset.roomName || 'Phòng chat');
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
                const isGroup = e.target.value === 'group';
                this.elements.privateUserGroup.style.display = isGroup ? 'none' : 'block';
                this.elements.groupNameGroup.style.display = isGroup ? 'block' : 'none';
                this.elements.memberIdsGroup.style.display = isGroup ? 'block' : 'none';
            });
        }

        if (this.elements.chatNameInput) {
            this.elements.chatNameInput.addEventListener('input', (e) => {
                const keyword = e.target.value.trim();
                const chatType = this.elements.chatTypeSelect.value;
                if (chatType === 'private' && keyword.length >= 2) {
                    clearTimeout(this.searchModalTimeout);
                    this.searchModalTimeout = setTimeout(() => this.searchUsers(keyword, true), 300);
                } else {
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

        // EMOJI SIDE PANEL - LOGIC ĐƠN GIẢN
        if (this.elements.emojiPanelGrid) {
            this.renderEmojiPanel();
            this.elements.emojiPanelGrid.addEventListener('click', (e) => {
                if (e.target.classList.contains('emoji-panel-item')) {
                    this.insertEmojiFromPanel(e.target.textContent);
                }
            });
        }

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.room-menu-trigger') && !e.target.closest('.room-dropdown-menu')) {
                document.querySelectorAll('.room-dropdown-menu').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        });

        this.elements.messagesContainer.addEventListener('click', (e) => {
            // Image zoom
            if (e.target.tagName === 'IMG' && e.target.closest('.message-bubble')) {
                const modal = document.getElementById('image-zoom-modal');
                const modalImg = document.getElementById('zoomed-image');
                modal.style.display = 'flex';
                modalImg.src = e.target.src;
            }
            
            // Message context menu
            if (e.target.closest('.message-wrapper')) {
                const messageWrapper = e.target.closest('.message-wrapper');
                const messageId = messageWrapper.dataset.messageId;
                const senderId = parseInt(messageWrapper.dataset.userId);
                
                // Chỉ hiển thị menu cho tin nhắn của mình
                if (senderId === this.currentUserId) {
                    this.showMessageContextMenu(e, messageWrapper, messageId);
                }
            }
        });

        const closeZoomBtn = document.getElementById('close-zoom');
        if (closeZoomBtn) {
            closeZoomBtn.addEventListener('click', () => {
                document.getElementById('image-zoom-modal').style.display = 'none';
            });
        }

        const imageZoomModal = document.getElementById('image-zoom-modal');
        if (imageZoomModal) {
            imageZoomModal.addEventListener('click', (e) => {
                if (e.target.id === 'image-zoom-modal') e.target.style.display = 'none';
            });
        }

        window.addEventListener('resize', () => this.handleResize());
        
        // Đóng context menu khi click bên ngoài
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.message-wrapper') && !e.target.closest('.message-context-menu')) {
                this.hideAllContextMenus();
            }
        });
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

    initLastMessageId() {
        // Lấy tất cả tin nhắn đã render trên trang
        const allMessages = this.elements.messagesContainer.querySelectorAll('.message-wrapper[data-message-id]');
        
        if (allMessages.length > 0) {
            // Lấy ID của tin nhắn cuối cùng
            const lastMessage = allMessages[allMessages.length - 1];
            const lastId = parseInt(lastMessage.dataset.messageId);
            
            if (lastId > 0) {
                this.lastMessageId = lastId;
                console.log(`🎯 [initLastMessageId] Khởi tạo lastMessageId = ${this.lastMessageId}`);
            }
        } else {
            console.log('ℹ️ [initLastMessageId] Không có tin nhắn nào trên trang');
        }
    }

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
                console.log('📨 Response gửi tin:', result);
                if (result.status === 'success') {
                    this.appendMessage(result.data);
                    
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
            this.showToast('error', 'Lỗi', 'Không thể gửi tin nhắn. Vui lòng thử lại.');
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

    appendMessage(data) {
        // CẬP NHẬT lastMessageId TRƯỚC KHI KIỂM TRA DUPLICATE
        if (data.id) {
            this.lastMessageId = Math.max(this.lastMessageId, data.id);
        }
        
        // KIỂM TRA DUPLICATE: Nếu message đã tồn tại thì bỏ qua
        if (data.id && document.querySelector(`.message-wrapper[data-message-id="${data.id}"]`)) {
            return;
        }

        const emptyState = this.elements.messagesContainer.querySelector('.empty-state');
        if (emptyState) emptyState.remove();

        const isMe = parseInt(data.sender_id) === this.currentUserId;
        const wrapperClass = isMe ? 'my-message' : 'other-message';
        const senderName = isMe ? 'You' : (data.sender_name || data.username || 'User ' + data.sender_id);
        const avatarChar = senderName.charAt(0).toUpperCase();
        let contentHtml = '';
        
        // FIX: Dùng class + loading lazy + KHÓA CỨNG width/height
        if (data.type === 'image' && data.file_path) {
            contentHtml = `<img src="${window.URLROOT}/${this.escapeHTML(data.file_path)}" alt="Image" class="message-image" width="220" height="220" loading="lazy">`;
        } else if (data.type === 'file' && data.file_path) {
            const fileName = data.file_path.split('/').pop();
            contentHtml = `<a href="${window.URLROOT}/${this.escapeHTML(data.file_path)}" target="_blank" class="message-file"><i class="fas fa-file"></i> ${this.escapeHTML(fileName)}</a>`;
        } else {
            // Nếu content chứa HTML mention tag thì giữ nguyên, không escape
            if (data.content && data.content.includes('<a href="/profile/') && data.content.includes('class="mention"')) {
                contentHtml = data.content;
            } else {
                contentHtml = this.escapeHTML(data.content || '');
            }
        }

        let displayTime = '';
        if (data.created_at) {
            const msgDate = new Date(data.created_at);
            displayTime = msgDate.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
        } else {
            displayTime = this.getCurrentTime();
        }
        
        // Seen indicator (chỉ hiển thị cho tin nhắn của mình)
        let seenIndicator = '';
        if (isMe) {
            const isRead = data.is_read === 1 || data.is_read === '1' || data.is_read === true;
            seenIndicator = isRead 
                ? '<i class="fas fa-check-double" style="color: #10b981; margin-left: 4px;"></i>' 
                : '<i class="fas fa-check" style="color: var(--text-muted); margin-left: 4px;"></i>';
        }
        
        const html = `
            <div class="message-wrapper ${wrapperClass}" data-message-id="${data.id || 0}">
                <div class="message-avatar">${avatarChar}</div>
                <div class="message-content">
                    <div class="message-sender">${this.escapeHTML(senderName)}</div>
                    <div class="message-bubble">
                        <div class="message-text">${contentHtml}</div>
                        <div class="message-time">${displayTime}${seenIndicator}</div>
                    </div>
                </div>
            </div>
        `;

        this.elements.messagesContainer.insertAdjacentHTML('beforeend', html);
        
        // FIX: Chờ ảnh load xong mới scroll
        if (data.type === 'image') {
            const lastImg = this.elements.messagesContainer.querySelector('.message-wrapper:last-child img');
            if (lastImg) {
                if (lastImg.complete) {
                    this.scrollToBottom();
                } else {
                    lastImg.onload = () => this.scrollToBottom();
                    lastImg.onerror = () => this.scrollToBottom();
                }
            }
        } else {
            this.scrollToBottom();
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
                        <button class="modal-close-btn" id="close-search-modal" style="background: none; border: none; color: var(--text-secondary); font-size: 24px; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: background 0.2s;" onmouseover="this.style.background='var(--hover-color)'" onmouseout="this.style.background=''"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="modal-body" id="search-results" style="padding: 20px; overflow-y: auto; flex: 1;">
                        <div style="text-align: center; color: var(--text-secondary); padding: 40px 20px;">
                            <i class="fas fa-search" style="font-size: 48px; opacity: 0.3; margin-bottom: 16px;"></i>
                            <p>Nhập từ khóa để tìm kiếm...</p>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Event listener đóng modal
            document.getElementById('close-search-modal').addEventListener('click', () => {
                this.toggleSearchModal(false);
            });
            
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.toggleSearchModal(false);
                }
            });
        }
        
        modal.style.display = 'flex';
    }

    toggleSearchModal(show) {
        const modal = document.getElementById('search-user-modal');
        if (modal) {
            modal.style.display = show ? 'flex' : 'none';
        } else if (show) {
            this.openSearchModal();
        }
    }

    async searchUsers(keyword, isForNewChat = false) {
        try {
            const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=searchUsers&keyword=${encodeURIComponent(keyword)}&user_id=${this.currentUserId}`);
            const data = await response.json();
            
            if (isForNewChat) {
                this.showUserSuggestions(data.data || []);
            } else {
                this.displaySearchResults(data.data || []);
            }
        } catch (error) {
            console.error('❌ Lỗi tìm kiếm:', error);
        }
    }

    displaySearchResults(users) {
        const resultsContainer = document.getElementById('search-results');
        if (!resultsContainer) return;
        
        if (users.length === 0) {
            resultsContainer.innerHTML = '<div style="text-align: center; color: var(--text-secondary); padding: 40px 20px;"><i class="fas fa-user-slash" style="font-size: 48px; opacity: 0.3; margin-bottom: 16px;"></i><p>Không tìm thấy người dùng</p></div>';
            return;
        }
        
        resultsContainer.innerHTML = users.map(user => `
            <div class="user-result-item" data-user-id="${user.id}" style="display: flex; align-items: center; padding: 12px; border-radius: 8px; cursor: pointer; transition: background 0.2s; margin-bottom: 8px;" onmouseover="this.style.background='var(--hover-color)'" onmouseout="this.style.background=''">
                <div class="user-avatar" style="width: 48px; height: 48px; border-radius: 50%; background: var(--primary-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; margin-right: 12px;">${user.username.charAt(0).toUpperCase()}</div>
                <div style="flex: 1;">
                    <div style="font-weight: 500; color: var(--text-primary);">${this.escapeHTML(user.username)}</div>
                    <div style="font-size: 14px; color: var(--text-secondary);">${this.escapeHTML(user.email || '')}</div>
                </div>
            </div>
        `).join('');
        
        resultsContainer.querySelectorAll('.user-result-item').forEach(item => {
            item.addEventListener('click', () => {
                const userId = parseInt(item.dataset.userId);
                this.createPrivateChat(userId);
            });
        });
    }

    async createPrivateChat(userId) {
        try {
            const formData = new FormData();
            formData.append('type', 'private');
            formData.append('member_ids', userId);
            
            const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=createRoom`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            if (data.status === 'success') {
                this.toggleSearchModal(false);
                window.location.href = `${window.URLROOT}/index.php?controller=chat&action=index&room_id=${data.data.room_id}`;
            } else {
                this.showToast('error', 'Lỗi', data.message || 'Không thể tạo đoạn chat');
            }
        } catch (error) {
            console.error('❌ Lỗi tạo chat:', error);
            this.showToast('error', 'Lỗi', 'Không thể tạo đoạn chat');
        }
    }

    handleFilterChange(filterType) {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('filter', filterType);
        window.location.search = urlParams.toString();
    }

    handleRoomClick(roomId, roomName) {
        console.log('🚪 Chuyển phòng:', roomId, roomName);
        window.location.href = `${window.URLROOT}/index.php?controller=chat&action=index&room_id=${roomId}`;
    }

    pinRoom(roomId, roomName) {
        const isPinned = this.pinnedRooms.includes(roomId);
        
        if (isPinned) {
            this.pinnedRooms = this.pinnedRooms.filter(id => id !== roomId);
            this.showToast('info', 'Đã bỏ ghim', `Đã bỏ ghim phòng "${roomName}"`);
        } else {
            this.pinnedRooms.push(roomId);
            this.showToast('success', 'Đã ghim', `Đã ghim phòng "${roomName}"`);
        }
        
        this.savePinnedRooms();
        this.applyPinnedRooms();
    }
    
    async leaveRoom(roomId, roomName) {
        if (!confirm(`Bạn có chắc muốn rời khỏi nhóm "${roomName}" không?\n\nBạn sẽ không thể xem tin nhắn cũ và nhận tin nhắn mới từ nhóm này.`)) return;
        
        try {
            const formData = new FormData();
            formData.append('room_id', roomId);
            
            const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=deleteRoom`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            if (data.status === 'success') {
                const roomItem = document.querySelector(`.room-item[data-room-id="${roomId}"]`);
                if (roomItem) {
                    roomItem.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                    roomItem.style.opacity = '0';
                    roomItem.style.transform = 'translateX(-20px)';
                    
                    setTimeout(() => {
                        roomItem.remove();
                        
                        if (this.roomId === roomId) {
                            const firstRoom = document.querySelector('.room-item');
                            if (firstRoom) {
                                const firstRoomId = firstRoom.dataset.roomId;
                                window.location.href = `${window.URLROOT}/index.php?controller=chat&action=index&room_id=${firstRoomId}`;
                            } else {
                                window.location.href = `${window.URLROOT}/index.php?controller=chat&action=index`;
                            }
                        }
                    }, 300);
                }
                
                this.showToast('success', 'Thành công', `Đã rời khỏi nhóm "${roomName}"`);
            } else {
                this.showToast('error', 'Lỗi', data.message || 'Không thể rời khỏi nhóm');
            }
        } catch (error) {
            console.error('❌ Lỗi rời nhóm:', error);
            this.showToast('error', 'Lỗi', 'Không thể rời khỏi nhóm');
        }
    }

    async deleteRoom(roomId, roomName, roomType) {
        // Phân biệt confirm message theo loại phòng
        let confirmMessage = '';
        if (roomType === 'group') {
            confirmMessage = `Bạn có chắc muốn giải tán nhóm "${roomName}" không?`;
        } else {
            confirmMessage = `Bạn có chắc muốn dừng chat với "${roomName}" không?`;
        }
        
        if (!confirm(confirmMessage)) return;
        
        try {
            // Gọi API để xóa user khỏi room_members (không xóa phòng thật)
            const formData = new FormData();
            formData.append('room_id', roomId);
            
            const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=deleteRoom`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            if (data.status === 'success') {
                // ẨN PHÒNG KHỊI SIDEBAR (Frontend only)
                const roomItem = document.querySelector(`.room-item[data-room-id="${roomId}"]`);
                if (roomItem) {
                    // Animation fade out
                    roomItem.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                    roomItem.style.opacity = '0';
                    roomItem.style.transform = 'translateX(-20px)';
                    
                    setTimeout(() => {
                        roomItem.remove();
                        
                        // Nếu đang ở phòng bị xóa → chuyển về phòng khác
                        if (this.roomId === roomId) {
                            const firstRoom = document.querySelector('.room-item');
                            if (firstRoom) {
                                const firstRoomId = firstRoom.dataset.roomId;
                                window.location.href = `${window.URLROOT}/index.php?controller=chat&action=index&room_id=${firstRoomId}`;
                            } else {
                                // Không còn phòng nào
                                window.location.href = `${window.URLROOT}/index.php?controller=chat&action=index`;
                            }
                        }
                    }, 300);
                }
                
                console.log('✅ Đã rời khỏi phòng');
            } else {
                alert(data.message || 'Không thể xóa phòng');
            }
        } catch (error) {
            console.error('❌ Lỗi xóa phòng:', error);
            alert('Không thể xóa phòng. Thử lại.');
        }
    }

    async showRoomInfo() {
        try {
            const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=getRoomInfo&room_id=${this.roomId}`);
            const data = await response.json();
            
            if (data.status === 'success') {
                this.displayRoomInfo(data.data);
            }
        } catch (error) {
            console.error('❌ Lỗi lấy thông tin phòng:', error);
        }
    }

    displayRoomInfo(roomData) {
        if (!this.elements.modalBody) return;
        
        const members = roomData.members || [];
        const roomType = roomData.room?.type || 'private';
        const isGroup = roomType === 'group';
        
        const membersHtml = members.map(member => `
            <div style="display: flex; align-items: center; padding: 10px; background: var(--bg-primary); border-radius: 8px; margin-bottom: 8px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; margin-right: 12px;">
                    ${this.escapeHTML(member.username.charAt(0).toUpperCase())}
                </div>
                <div>
                    <div style="font-weight: 500; color: var(--text-primary);">${this.escapeHTML(member.username)}</div>
                    <div style="font-size: 12px; color: var(--text-secondary);">Tham gia: ${member.joined_at ? new Date(member.joined_at).toLocaleDateString('vi-VN') : 'N/A'}</div>
                </div>
            </div>
        `).join('');
        
        // Nút thêm thành viên (chỉ hiển thị cho group)
        const addMemberButton = isGroup ? `
            <button id="add-member-btn" class="btn btn-primary" style="width: 100%; margin-top: 16px;">
                <i class="fas fa-user-plus"></i> Thêm thành viên
            </button>
        ` : '';
        
        this.elements.modalBody.innerHTML = `
            <div style="padding: 20px;">
                <h3 style="margin-bottom: 15px;">${this.escapeHTML(roomData.room?.name || 'Phòng chat')}</h3>
                <div style="margin-bottom: 20px;">
                    <p style="margin: 8px 0;"><strong><i class="fas fa-layer-group"></i> Loại:</strong> ${roomData.room?.type === 'group' ? 'Nhóm' : 'Riêng tư'}</p>
                    <p style="margin: 8px 0;"><strong><i class="fas fa-users"></i> Số thành viên:</strong> ${members.length}</p>
                </div>
                <h4 style="margin: 15px 0 10px 0; color: var(--text-primary);"><i class="fas fa-user-friends"></i> Danh sách thành viên</h4>
                <div style="max-height: 300px; overflow-y: auto;">
                    ${membersHtml || '<p style="text-align: center; color: var(--text-secondary); padding: 20px;">Không có thành viên</p>'}
                </div>
                ${addMemberButton}
            </div>
        `;
        
        // Event listener cho nút thêm thành viên
        if (isGroup) {
            const addBtn = document.getElementById('add-member-btn');
            if (addBtn) {
                addBtn.addEventListener('click', () => this.showAddMemberModal());
            }
        }
        
        this.elements.roomInfoModal.style.display = 'flex';
    }

    closeModal() {
        if (this.elements.roomInfoModal) {
            this.elements.roomInfoModal.style.display = 'none';
        }
    }

    handleFileUpload(files, type) {
        if (!files || files.length === 0) return;
        
        Array.from(files).forEach(file => {
            if (type === 'image' && !file.type.startsWith('image/')) {
                this.showToast('error', 'Lỗi', 'Chỉ chấp nhận file ảnh!');
                return;
            }
            this.pendingFiles.push(file);
        });
        
        this.renderPreview();
    }

    renderPreview() {
        let previewContainer = document.getElementById('file-preview-container');
        
        if (!previewContainer) {
            previewContainer = document.createElement('div');
            previewContainer.id = 'file-preview-container';
            previewContainer.style.cssText = 'display: flex; gap: 8px; padding: 8px; overflow-x: auto; background: var(--bg-secondary); border-radius: 8px; margin-bottom: 8px;';
            this.elements.messageForm.insertBefore(previewContainer, this.elements.messageInput);
        }
        
        if (this.pendingFiles.length === 0) {
            previewContainer.remove();
            return;
        }
        
        previewContainer.innerHTML = this.pendingFiles.map((file, index) => {
            const isImage = file.type.startsWith('image/');
            const preview = isImage ? URL.createObjectURL(file) : '';
            
            return `
                <div class="file-preview-item" style="position: relative; width: 80px; height: 80px; border-radius: 8px; overflow: hidden; background: var(--bg-primary);">
                    ${isImage ? `<img src="${preview}" style="width: 100%; height: 100%; object-fit: cover;">` : `<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--text-secondary);"><i class="fas fa-file" style="font-size: 32px;"></i></div>`}
                    <button class="remove-file-btn" data-index="${index}" style="position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,0.7); border: none; color: white; width: 24px; height: 24px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center;"><i class="fas fa-times"></i></button>
                </div>
            `;
        }).join('');
        
        previewContainer.querySelectorAll('.remove-file-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const index = parseInt(e.currentTarget.dataset.index);
                this.pendingFiles.splice(index, 1);
                this.renderPreview();
            });
        });
    }

    showSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const chatArea = document.querySelector('.chat-area');
        if (sidebar) sidebar.style.display = 'flex';
        if (chatArea) chatArea.style.display = 'none';
    }

    closePinnedMessage() {
        if (this.elements.pinnedMessageBar) {
            this.elements.pinnedMessageBar.style.display = 'none';
        }
    }

    openNewChatModal() {
        if (this.elements.newChatModal) {
            this.elements.newChatModal.style.display = 'flex';
            this.loadAllUsers();
        }
    }

    closeNewChatModal() {
        if (this.elements.newChatModal) {
            this.elements.newChatModal.style.display = 'none';
            this.elements.newChatForm.reset();
            this.elements.privateUserGroup.style.display = 'block';
            this.elements.groupNameGroup.style.display = 'none';
            this.elements.memberIdsGroup.style.display = 'none';
        }
    }

    async handleCreateNewChat() {
        const chatType = this.elements.chatTypeSelect.value;
        
        if (chatType === 'private') {
            const targetUserId = parseInt(this.elements.privateUserSelect.value);
            if (!targetUserId || targetUserId <= 0) {
                alert('Vui lòng chọn người nhận!');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('type', 'private');
                formData.append('room_name', 'Private Chat');
                formData.append('target_user_id', targetUserId);
                
                const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=createRoom`, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                if (data.status === 'success') {
                    this.closeNewChatModal();
                    window.location.href = `${window.URLROOT}/index.php?controller=chat&action=index&room_id=${data.room_id}`;
                } else {
                    alert(data.message || 'Lỗi tạo chat!');
                }
            } catch (error) {
                console.error('❌ Lỗi:', error);
                alert('Không thể tạo chat!');
            }
        } else {
            const groupName = this.elements.groupNameInput.value.trim();
            const memberIds = this.elements.memberIdsInput.value.trim();
            
            if (!groupName) {
                alert('Vui lòng nhập tên nhóm!');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('type', 'group');
                formData.append('room_name', groupName);
                if (memberIds) formData.append('member_ids', memberIds);
                
                const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=createRoom`, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                if (data.status === 'success') {
                    this.closeNewChatModal();
                    window.location.href = `${window.URLROOT}/index.php?controller=chat&action=index&room_id=${data.room_id}`;
                } else {
                    alert(data.message || 'Lỗi tạo chat!');
                }
            } catch (error) {
                console.error('❌ Lỗi:', error);
                alert('Không thể tạo chat!');
            }
        }
    }

    showUserSuggestions(users) {
        let suggestionsBox = document.getElementById('user-suggestions');
        
        if (!suggestionsBox) {
            suggestionsBox = document.createElement('div');
            suggestionsBox.id = 'user-suggestions';
            suggestionsBox.style.cssText = 'position: absolute; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 8px; max-height: 200px; overflow-y: auto; z-index: 1000; width: 100%; margin-top: 4px;';
            this.elements.chatNameInput.parentElement.style.position = 'relative';
            this.elements.chatNameInput.parentElement.appendChild(suggestionsBox);
        }
        
        if (users.length === 0) {
            suggestionsBox.innerHTML = '<div style="padding: 12px; color: var(--text-secondary);">Không tìm thấy</div>';
            return;
        }
        
        suggestionsBox.innerHTML = users.map(user => `
            <div class="user-suggestion-item" data-user-id="${user.id}" data-username="${this.escapeHTML(user.username)}" style="padding: 12px; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='var(--hover-color)'" onmouseout="this.style.background=''">
                <div style="font-weight: 500;">${this.escapeHTML(user.username)}</div>
                <div style="font-size: 12px; color: var(--text-secondary);">${this.escapeHTML(user.email || '')}</div>
            </div>
        `).join('');
        
        suggestionsBox.querySelectorAll('.user-suggestion-item').forEach(item => {
            item.addEventListener('click', () => {
                this.elements.chatNameInput.value = item.dataset.username;
                this.elements.memberIdsInput.value = item.dataset.userId;
                this.hideUserSuggestions();
            });
        });
    }

    hideUserSuggestions() {
        const suggestionsBox = document.getElementById('user-suggestions');
        if (suggestionsBox) suggestionsBox.remove();
    }

    updateSidebarAfterSend(content) {
        const roomItem = document.querySelector(`.room-item[data-room-id="${this.roomId}"]`);
        if (!roomItem) return;
        
        const lastMsg = roomItem.querySelector('.room-last-message');
        const timeEl = roomItem.querySelector('.room-time');
        
        if (lastMsg) lastMsg.textContent = content.substring(0, 30) + (content.length > 30 ? '...' : '');
        if (timeEl) timeEl.textContent = 'Vừa xong';
        
        const roomList = roomItem.parentElement;
        roomList.insertBefore(roomItem, roomList.firstChild);
    }

    startPolling() {
        if (this.isPolling) return;
        this.isPolling = true;
        
        this.pollingInterval = setInterval(() => {
            this.fetchNewMessages();
        }, 3000);
    }

    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
        this.isPolling = false;
    }

    async fetchNewMessages() {
        try {
            const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=getNewMessages&room_id=${this.roomId}&last_id=${this.lastMessageId}`);
            const data = await response.json();
            
            if (data.status === 'success' && data.data && data.data.length > 0) {
                data.data.forEach(msg => {
                    this.appendMessage(msg);
                    this.lastMessageId = Math.max(this.lastMessageId, msg.id);
                });
            }
        } catch (error) {
            console.error('❌ Lỗi polling:', error);
        }
    }

    handleResize() {
        // Handle responsive behavior
    }

    // ==================== EMOJI SIDE PANEL - LOGIC ĐƠN GIẢN ====================
    renderEmojiPanel() {
        if (!this.elements.emojiPanelGrid) return;
        
        const emojis = [
            '😀', '😃', '😄', '😁', '😆', '😅',
            '😂', '🤣', '😊', '😇', '🙂', '😉',
            '😍', '🥰', '😘', '😚', '😋', '😛',
            '🤔', '🤨', '😎', '🤩', '😏', '😒',
            '😞', '😔', '😢', '😭', '😤', '😠',
            '😡', '🤬', '😱', '😨', '😰', '🥺',
            '😥', '🤗', '🤭', '🥱', '😴', '😪',
            '😷', '🤒', '🤕', '🤠', '👍', '👎',
            '👏', '🙌', '🙏', '💪', '❤️', '💔',
            '💕', '💞', '💜', '💛', '💯', '🔥',
            '✨', '🎉', '🎊', '🎁', '🏆', '⭐'
        ];
        
        this.elements.emojiPanelGrid.innerHTML = emojis.map(emoji => 
            `<span class="emoji-panel-item">${emoji}</span>`
        ).join('');
    }

    insertEmojiFromPanel(emoji) {
        const input = this.elements.messageInput;
        if (!input) return;
        
        input.value += emoji;
        input.focus();
        this.toggleSendButton(input.value.trim());
    }
    
    handleMentionAutocomplete(e) {
        const input = e.target;
        const cursorPos = input.selectionStart;
        const textBeforeCursor = input.value.substring(0, cursorPos);
        
        // Tìm @ gần nhất trước cursor
        const match = textBeforeCursor.match(/@([a-zA-Z0-9_]*)$/);
        
        if (match) {
            const query = match[1];
            this.showMentionSuggestions(query, cursorPos - match[0].length);
        } else {
            this.hideMentionSuggestions();
        }
    }
    
    async showMentionSuggestions(query, atPosition) {
        // Lấy danh sách members trong phòng
        try {
            const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=getRoomInfo&room_id=${this.roomId}`);
            const data = await response.json();
            
            if (data.status === 'success' && data.data.members) {
                const members = data.data.members.filter(m => 
                    m.username.toLowerCase().includes(query.toLowerCase()) && 
                    parseInt(m.user_id) !== this.currentUserId
                );
                
                if (members.length > 0) {
                    this.renderMentionSuggestions(members, atPosition);
                } else {
                    this.hideMentionSuggestions();
                }
            }
        } catch (error) {
            console.error('❌ Lỗi lấy members:', error);
        }
    }
    
    renderMentionSuggestions(members, atPosition) {
        let suggestionsBox = document.getElementById('mention-suggestions');
        
        if (!suggestionsBox) {
            suggestionsBox = document.createElement('div');
            suggestionsBox.id = 'mention-suggestions';
            suggestionsBox.style.cssText = 'position: absolute; background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: 8px; max-height: 200px; overflow-y: auto; z-index: 1000; min-width: 200px; box-shadow: 0 4px 12px rgba(0,0,0,0.3);';
            this.elements.messageForm.style.position = 'relative';
            this.elements.messageForm.appendChild(suggestionsBox);
        }
        
        // Tính toán vị trí
        const inputRect = this.elements.messageInput.getBoundingClientRect();
        suggestionsBox.style.bottom = '100%';
        suggestionsBox.style.left = '0';
        suggestionsBox.style.marginBottom = '8px';
        
        suggestionsBox.innerHTML = members.map(member => `
            <div class="mention-suggestion-item" data-username="${this.escapeHTML(member.username)}" style="padding: 10px 14px; cursor: pointer; transition: background 0.2s; display: flex; align-items: center; gap: 10px;">
                <div style="width: 32px; height: 32px; border-radius: 8px; background: var(--bg-hover); display: flex; align-items: center; justify-content: center; font-weight: 600; color: var(--text-primary);">
                    ${this.escapeHTML(member.username.charAt(0).toUpperCase())}
                </div>
                <div style="font-weight: 500; color: var(--text-primary);">${this.escapeHTML(member.username)}</div>
            </div>
        `).join('');
        
        suggestionsBox.querySelectorAll('.mention-suggestion-item').forEach(item => {
            item.addEventListener('mouseenter', () => {
                item.style.background = 'var(--bg-hover)';
            });
            item.addEventListener('mouseleave', () => {
                item.style.background = '';
            });
            item.addEventListener('click', () => {
                this.insertMention(item.dataset.username);
            });
        });
    }
    
    insertMention(username) {
        const input = this.elements.messageInput;
        const cursorPos = input.selectionStart;
        const textBeforeCursor = input.value.substring(0, cursorPos);
        const textAfterCursor = input.value.substring(cursorPos);
        
        // Tìm và thay thế @ chưa hoàn chỉnh
        const newTextBefore = textBeforeCursor.replace(/@([a-zA-Z0-9_]*)$/, `@${username} `);
        
        input.value = newTextBefore + textAfterCursor;
        input.selectionStart = input.selectionEnd = newTextBefore.length;
        input.focus();
        
        this.hideMentionSuggestions();
        this.toggleSendButton(input.value.trim());
    }
    
    hideMentionSuggestions() {
        const suggestionsBox = document.getElementById('mention-suggestions');
        if (suggestionsBox) suggestionsBox.remove();
    }

    async loadAllUsers() {
        try {
            const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=searchUsers&keyword=&user_id=${this.currentUserId}`);
            const data = await response.json();
            
            if (data.status === 'success' && data.data) {
                const select = this.elements.privateUserSelect;
                select.innerHTML = '<option value="">-- Chọn người dùng --</option>';
                
                data.data.forEach(user => {
                    const option = document.createElement('option');
                    option.value = user.id;
                    option.textContent = user.username;
                    select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('❌ Lỗi load users:', error);
        }
    }
    
    // ==================== XÓA TIN NHẮN ====================
    showMessageContextMenu(event, messageWrapper, messageId) {
        event.stopPropagation();
        
        // Xóa menu cũ
        this.hideAllContextMenus();
        
        // Tạo menu mới
        let menu = messageWrapper.querySelector('.message-context-menu');
        if (!menu) {
            menu = document.createElement('div');
            menu.className = 'message-context-menu';
            menu.innerHTML = `
                <div class="message-menu-item" data-action="pin">
                    <i class="fas fa-thumbtack"></i>
                    <span>Ghim tin nhắn</span>
                </div>
                <div class="message-menu-item danger" data-action="delete">
                    <i class="fas fa-trash"></i>
                    <span>Xóa tin nhắn</span>
                </div>
            `;
            messageWrapper.appendChild(menu);
            
            // Event listener cho menu items
            menu.querySelector('[data-action="pin"]').addEventListener('click', (e) => {
                e.stopPropagation();
                this.pinMessage(messageId);
            });
            
            menu.querySelector('[data-action="delete"]').addEventListener('click', (e) => {
                e.stopPropagation();
                this.deleteMessage(messageId, messageWrapper);
            });
        }
        
        // Hiển thị menu
        setTimeout(() => menu.classList.add('show'), 10);
    }
    
    hideAllContextMenus() {
        document.querySelectorAll('.message-context-menu').forEach(menu => {
            menu.classList.remove('show');
            setTimeout(() => menu.remove(), 200);
        });
    }
    
    async deleteMessage(messageId, messageWrapper) {
        if (!confirm('Bạn có chắc muốn xóa tin nhắn này?')) return;
        
        try {
            const formData = new FormData();
            formData.append('message_id', messageId);
            
            const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=deleteMessage`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.status === 'success') {
                // Animation fade out
                messageWrapper.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                messageWrapper.style.opacity = '0';
                messageWrapper.style.transform = 'translateX(-20px)';
                
                setTimeout(() => {
                    messageWrapper.remove();
                    this.showToast('success', 'Thành công', 'Đã xóa tin nhắn');
                }, 300);
            } else {
                this.showToast('error', 'Lỗi', data.message || 'Không thể xóa tin nhắn');
            }
        } catch (error) {
            console.error('❌ Lỗi xóa tin nhắn:', error);
            this.showToast('error', 'Lỗi', 'Không thể xóa tin nhắn');
        }
    }
    
    // ==================== NOTIFICATION TOAST ====================
    showToast(type, title, message) {
        // Tạo container nếu chưa có
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        
        // Tạo toast
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const iconMap = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            info: 'fa-info-circle'
        };
        
        toast.innerHTML = `
            <div class="toast-icon">
                <i class="fas ${iconMap[type] || 'fa-info-circle'}"></i>
            </div>
            <div class="toast-content">
                <div class="toast-title">${this.escapeHTML(title)}</div>
                <div class="toast-message">${this.escapeHTML(message)}</div>
            </div>
            <button class="toast-close">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        container.appendChild(toast);
        
        // Event close
        toast.querySelector('.toast-close').addEventListener('click', () => {
            toast.style.animation = 'slideInRight 0.3s ease reverse';
            setTimeout(() => toast.remove(), 300);
        });
        
        // Tự đóng sau 4s
        setTimeout(() => {
            if (toast.parentElement) {
                toast.style.animation = 'slideInRight 0.3s ease reverse';
                setTimeout(() => toast.remove(), 300);
            }
        }, 4000);
    }
    
    // ==================== THÊM THÀNH VIÊN VÀO NHÓM ====================
    showAddMemberModal() {
        // Tạo modal
        let modal = document.getElementById('add-member-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'add-member-modal';
            modal.className = 'modal-overlay';
            modal.innerHTML = `
                <div class="modal-content modal-new-chat">
                    <div class="modal-header">
                        <h3><i class="fas fa-user-plus"></i> Thêm thành viên</h3>
                        <button class="modal-close-btn" id="close-add-member-modal">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="add-member-select">
                                <i class="fas fa-user"></i> Chọn người dùng
                            </label>
                            <select id="add-member-select" class="form-control">
                                <option value="">-- Chọn người dùng --</option>
                            </select>
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="btn btn-secondary" id="cancel-add-member">
                                <i class="fas fa-times"></i> Hủy
                            </button>
                            <button type="button" class="btn btn-primary" id="confirm-add-member">
                                <i class="fas fa-check"></i> Thêm
                            </button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Event listeners
            document.getElementById('close-add-member-modal').addEventListener('click', () => {
                modal.style.display = 'none';
            });
            
            document.getElementById('cancel-add-member').addEventListener('click', () => {
                modal.style.display = 'none';
            });
            
            document.getElementById('confirm-add-member').addEventListener('click', () => {
                this.handleAddMember();
            });
            
            modal.addEventListener('click', (e) => {
                if (e.target === modal) modal.style.display = 'none';
            });
        }
        
        // Load danh sách user
        this.loadUsersForAddMember();
        modal.style.display = 'flex';
    }
    
    async loadUsersForAddMember() {
        try {
            const response = await fetch(`${window.URLROOT}/index.php?controller=chat&action=searchUsers&keyword=&user_id=${this.currentUserId}`);
            const data = await response.json();
            
            if (data.status === 'success' && data.data) {
                const select = document.getElementById('add-member-select');
                select.innerHTML = '<option value="">-- Chọn người dùng --</option>';
                
                // Lọc ra những user chưa có trong phòng
                const roomInfoResponse = await fetch(`${window.URLROOT}/index.php?controller=chat&action=getRoomInfo&room_id=${this.roomId}`);
                const roomInfo = await roomInfoResponse.json();
                const existingMemberIds = roomInfo.data.members.map(m => parseInt(m.user_id));
                
                data.data.forEach(user => {
                    if (!existingMemberIds.includes(user.id)) {
                        const option = document.createElement('option');
                        option.value = user.id;
                        option.textContent = user.username;
                        select.appendChild(option);
                    }
                });
            }
        } catch (error) {
            console.error('❌ Lỗi load users:', error);
            this.showToast('error', 'Lỗi', 'Không thể tải danh sách người dùng');
        }
    }
    
    loadPinnedRooms() {
        const saved = localStorage.getItem(`pinnedRooms_${this.currentUserId}`);
        return saved ? JSON.parse(saved) : [];
    }
    
    savePinnedRooms() {
        localStorage.setItem(`pinnedRooms_${this.currentUserId}`, JSON.stringify(this.pinnedRooms));
    }
    
    cacheAllRooms() {
        const roomItems = document.querySelectorAll('.room-item');
        this.allRooms = Array.from(roomItems).map(item => ({
            element: item,
            id: parseInt(item.dataset.roomId),
            name: item.dataset.roomName?.toLowerCase() || '',
            lastMessage: item.querySelector('.room-last-message')?.textContent.toLowerCase() || ''
        }));
    }
    
    applyPinnedRooms() {
        if (!this.elements.roomListContainer) return;
        
        const roomItems = Array.from(document.querySelectorAll('.room-item'));
        
        roomItems.forEach(item => {
            const roomId = parseInt(item.dataset.roomId);
            const isPinned = this.pinnedRooms.includes(roomId);
            const pinIcon = item.querySelector('.room-pin-icon');
            
            if (isPinned) {
                item.classList.add('pinned');
                if (!pinIcon) {
                    const icon = document.createElement('i');
                    icon.className = 'fas fa-thumbtack room-pin-icon';
                    icon.style.cssText = 'color: var(--primary-color); margin-left: 8px; font-size: 12px;';
                    item.querySelector('.room-name')?.appendChild(icon);
                }
            } else {
                item.classList.remove('pinned');
                pinIcon?.remove();
            }
        });
        
        // Sắp xếp: phòng ghim lên đầu
        const pinnedItems = roomItems.filter(item => this.pinnedRooms.includes(parseInt(item.dataset.roomId)));
        const unpinnedItems = roomItems.filter(item => !this.pinnedRooms.includes(parseInt(item.dataset.roomId)));
        
        this.elements.roomListContainer.innerHTML = '';
        [...pinnedItems, ...unpinnedItems].forEach(item => {
            this.elements.roomListContainer.appendChild(item);
        });
    }
    
    filterRoomList(keyword) {
        if (!keyword) {
            this.allRooms.forEach(room => room.element.style.display = '');
            return;
        }
        
        const lowerKeyword = keyword.toLowerCase();
        this.allRooms.forEach(room => {
            const matches = room.name.includes(lowerKeyword) || room.lastMessage.includes(lowerKeyword);
            room.element.style.display = matches ? '' : 'none';
        });
    }
    
    async handleAddMember() {
        const select = document.getElementById('add-member-select');
        const userId = parseInt(select.value);
        
        if (!userId || userId <= 0) {
            this.showToast('error', 'Lỗi', 'Vui lòng chọn người dùng');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('room_id', this.roomId);
            formData.append('user_id', userId);
            
            const response = await fetch(`${window.URLROOT}/index.php?controller=room&action=addMember`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.status === 'success') {
                this.showToast('success', 'Thành công', 'Đã thêm thành viên vào nhóm');
                document.getElementById('add-member-modal').style.display = 'none';
                
                // Reload thông tin phòng
                setTimeout(() => {
                    this.showRoomInfo();
                }, 500);
            } else {
                this.showToast('error', 'Lỗi', data.message || 'Không thể thêm thành viên');
            }
        } catch (error) {
            console.error('❌ Lỗi thêm thành viên:', error);
            this.showToast('error', 'Lỗi', 'Không thể thêm thành viên');
        }
    }
}

// Khởi tạo ứng dụng khi DOM đã sẵn sàng
document.addEventListener('DOMContentLoaded', () => {
    window.chatApp = new ChatApplication();
});
