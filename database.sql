CREATE DATABASE IF NOT EXISTS chat_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE chat_app;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS room_members;
DROP TABLE IF EXISTS chat_rooms;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Bảng Người dùng
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Bảng Phòng Chat (Nhóm/Cá nhân)
CREATE TABLE chat_rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NULL,
    type ENUM('private', 'group') DEFAULT 'group',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Bảng Thành viên phòng
CREATE TABLE room_members (
    room_id INT,
    user_id INT,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (room_id, user_id),
    FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Bảng Tin nhắn
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    sender_id INT NOT NULL,
    content TEXT NOT NULL,
    type ENUM('text', 'image', 'file') DEFAULT 'text',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_read TINYINT(1) DEFAULT 0,
    FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (room_id), -- Tối ưu cho Polling
    INDEX (sent_at)
) ENGINE=InnoDB;

-- DỮ LIỆU MẪU ĐỂ CẢ NHÓM TEST
INSERT INTO users (username, password) VALUES 
('Quang', 'password123'), 
('Nhu', 'password123'), 
('Dien', 'password123'),
('Minh', 'password123');


INSERT INTO chat_rooms (name, type) VALUES 
('Nhóm Dự Án PHP', 'group'), 
(NULL, 'private');

INSERT INTO room_members (room_id, user_id) VALUES (1, 1), (1, 2), (1, 3);

INSERT INTO messages (room_id, sender_id, content, type) VALUES 
(1, 1, 'Chào mọi người', 'text'),
(1, 2, 'Chào mừng đến với nhóm!', 'text');
