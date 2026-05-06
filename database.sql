-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 03, 2026 at 04:27 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `chat_app`
--

-- --------------------------------------------------------

--
-- Table structure for table `chat_rooms`
--

CREATE TABLE `chat_rooms` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `type` enum('private','group') DEFAULT 'group',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chat_rooms`
--

INSERT INTO `chat_rooms` (`id`, `name`, `type`, `created_at`) VALUES
(1, 'Nhóm Dự Án PHP', 'group', '2026-05-02 16:50:19'),
(2, NULL, 'private', '2026-05-02 16:50:19'),
(3, 'Nhóm Ăn Nhậu 🍺', 'group', '2026-05-02 20:15:37'),
(4, 'Thông báo dự án', 'group', '2026-05-02 20:15:37'),
(5, NULL, 'private', '2026-05-02 20:15:37'),
(6, NULL, 'private', '2026-05-02 20:15:37'),
(7, 'Minh', 'private', '2026-05-03 00:46:21'),
(8, 'NHU', 'private', '2026-05-03 00:46:56'),
(9, 'Nhóm PHP', 'group', '2026-05-03 00:51:24');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `type` enum('text','image','file') DEFAULT 'text',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `room_id`, `sender_id`, `content`, `type`, `sent_at`, `is_read`) VALUES
(1, 1, 1, 'Chào mọi người', 'text', '2026-05-02 16:50:19', 1),
(2, 1, 2, 'Chào mừng đến với nhóm!', 'text', '2026-05-02 16:50:19', 1),
(3, 1, 1, 'adada', 'text', '2026-05-02 19:30:38', 1),
(4, 1, 1, 'adadađâ', 'text', '2026-05-02 19:30:57', 1),
(5, 1, 1, 'alo ae', 'text', '2026-05-02 19:34:49', 1),
(6, 1, 1, 'helo', 'text', '2026-05-02 19:34:56', 1),
(7, 1, 1, 'adad', 'text', '2026-05-02 19:35:20', 1),
(8, 1, 1, 'đâ', 'text', '2026-05-02 19:51:16', 1),
(9, 1, 1, 'adada', 'text', '2026-05-02 20:00:05', 1),
(10, 1, 1, 'uploads/1777752336_69f65910d3d28.png', 'image', '2026-05-02 20:05:36', 1),
(11, 1, 1, 'uploads/1777752343_69f659170b64a.png', 'image', '2026-05-02 20:05:43', 1),
(12, 1, 1, 'uploads/1777752370_69f65932e3d8b.jpg', 'image', '2026-05-02 20:06:10', 1),
(13, 1, 1, 'đâ', 'text', '2026-05-02 20:06:56', 1),
(14, 1, 1, 'uploads/1777752467_69f65993d81a0.png', 'image', '2026-05-02 20:07:47', 1),
(15, 1, 1, 'uploads/1777752673_69f65a613cf63.png', 'image', '2026-05-02 20:11:13', 1),
(16, 1, 1, 'uploads/1777752678_69f65a6640cc4.png', 'image', '2026-05-02 20:11:18', 1),
(17, 1, 1, 'uploads/1777752705_69f65a814a888.png', 'image', '2026-05-02 20:11:45', 1),
(18, 1, 1, 'uploads/1777752794_69f65ada80380.png', 'image', '2026-05-02 20:13:14', 1),
(19, 2, 4, 'Khi nào đi nhậu thế Lợi?', 'text', '2026-05-02 20:15:37', 1),
(20, 3, 5, 'Check file báo cáo giúp tôi với', 'text', '2026-05-02 20:15:37', 1),
(21, 4, 6, 'Gửi ông cái ảnh thiết kế này', 'image', '2026-05-02 20:15:37', 1),
(22, 1, 2, 'Cái này xong rồi nhé!', 'text', '2026-05-02 20:15:37', 1),
(23, 2, 1, 'uploads/1777753037_69f65bcd23891.png', 'image', '2026-05-02 20:17:17', 1),
(24, 1, 1, 'uploads/1777753299_69f65cd379170.png', 'image', '2026-05-02 20:21:39', 1),
(25, 2, 1, 'uploads/1777754091_69f65febb0e11.png', 'image', '2026-05-02 20:34:51', 1),
(26, 2, 1, 'uploads/1777755773_69f6667d7a024.png', 'image', '2026-05-02 21:02:53', 1),
(27, 2, 1, 'uploads/1777755800_69f66698e1d63.png', 'image', '2026-05-02 21:03:20', 1),
(28, 2, 1, 'uploads/1777756224_69f66840cf444.png', 'image', '2026-05-02 21:10:24', 1),
(29, 1, 1, 'uploads/1777756236_69f6684c744d3.png', 'image', '2026-05-02 21:10:36', 1),
(30, 2, 1, 'uploads/1777757038_69f66b6e50e00.png', 'image', '2026-05-02 21:23:58', 1),
(31, 2, 1, 'uploads/1777757053_69f66b7de767a.png', 'image', '2026-05-02 21:24:13', 1),
(32, 1, 1, 'uploads/1777757087_69f66b9f43aa0.user', 'file', '2026-05-02 21:24:47', 1),
(33, 3, 1, 'uploads/1777757144_69f66bd859b79.png', 'image', '2026-05-02 21:25:44', 1),
(34, 3, 1, 'uploads/1777757144_69f66bd85deeb.cs', 'file', '2026-05-02 21:25:44', 1),
(35, 4, 1, 'uploads/1777757698_69f66e021407e.png', 'image', '2026-05-02 21:34:58', 1),
(36, 4, 1, 'uploads/1777757698_69f66e021ffbe.png', 'image', '2026-05-02 21:34:58', 1),
(37, 3, 1, 'uploads/1777757763_69f66e43320e8.sql', 'file', '2026-05-02 21:36:03', 1),
(38, 3, 1, 'uploads/1777757792_69f66e6060971.sql', 'file', '2026-05-02 21:36:32', 1),
(39, 3, 1, 'uploads/1777757792_69f66e606bfd8.ico', 'file', '2026-05-02 21:36:32', 1),
(40, 3, 1, '😍', 'text', '2026-05-02 21:47:37', 1),
(41, 3, 1, 'd', 'text', '2026-05-02 21:56:34', 1),
(42, 3, 1, 'd', 'text', '2026-05-02 21:56:40', 1),
(43, 4, 1, 'd', 'text', '2026-05-02 21:57:57', 1),
(44, 2, 1, 'ada', 'text', '2026-05-02 21:58:07', 1),
(45, 2, 1, 'hey', 'text', '2026-05-02 22:00:23', 1),
(46, 2, 1, 'đâ', 'text', '2026-05-02 22:03:58', 1),
(47, 1, 1, 'dmm', 'text', '2026-05-02 22:14:07', 1),
(48, 3, 1, 'ê', 'text', '2026-05-02 22:14:13', 1),
(49, 5, 1, 'trời ơi', 'text', '2026-05-02 22:21:12', 1),
(50, 4, 1, 'chào em cô gái lam hồng', 'text', '2026-05-02 22:21:41', 1),
(51, 4, 1, 'thái bình ơi thái bình', 'text', '2026-05-02 22:21:48', 1),
(52, 3, 1, 'ada', 'text', '2026-05-02 22:23:54', 1),
(53, 4, 2, 'ơi', 'text', '2026-05-02 22:26:15', 1),
(54, 1, 1, 'd', 'text', '2026-05-02 22:26:30', 1),
(55, 1, 2, 'ad', 'text', '2026-05-02 22:28:20', 1),
(56, 1, 1, 'đâ', 'text', '2026-05-02 22:28:52', 1),
(57, 1, 2, 'ơi sao', 'text', '2026-05-02 22:29:00', 1),
(58, 1, 2, 'đi ăn k', 'text', '2026-05-02 22:29:07', 1),
(59, 1, 1, 'không', 'text', '2026-05-02 22:29:14', 1),
(60, 1, 1, 'chịu cụ r', 'text', '2026-05-02 22:29:20', 1),
(61, 1, 2, 'hahahha', 'text', '2026-05-02 22:29:29', 1),
(62, 1, 2, 'ada', 'text', '2026-05-02 22:30:32', 1),
(63, 1, 2, 'đâ', 'text', '2026-05-02 22:30:32', 1),
(64, 1, 2, 'đa', 'text', '2026-05-02 22:30:34', 1),
(65, 3, 2, 'đâ', 'text', '2026-05-02 23:33:51', 1),
(66, 3, 2, 'dsđ', 'text', '2026-05-02 23:33:52', 1),
(67, 3, 2, 'đ', 'text', '2026-05-02 23:33:54', 1),
(68, 1, 1, 'hehe', 'text', '2026-05-02 23:35:04', 1),
(69, 1, 1, 'uploads/1777764904_69f68a2861515.png', 'image', '2026-05-02 23:35:04', 1),
(70, 1, 1, 'uploads/1777765866_69f68dea68ab5.png', 'image', '2026-05-02 23:51:06', 1),
(71, 1, 1, 'alo', 'text', '2026-05-02 23:51:08', 1),
(72, 1, 3, 'dd', 'text', '2026-05-02 23:54:32', 1),
(73, 7, 1, 'ông ơi', 'text', '2026-05-03 00:46:28', 0),
(74, 8, 1, 'hello', 'text', '2026-05-03 00:47:00', 0),
(75, 1, 1, 'uploads/1777770276_69f69f2467db3.png', 'image', '2026-05-03 01:04:36', 0),
(76, 1, 1, 'uploads/1777770276_69f69f24740ba.png', 'image', '2026-05-03 01:04:36', 0),
(77, 8, 1, 'đa', 'text', '2026-05-03 01:17:14', 0),
(78, 4, 1, 'ss', 'text', '2026-05-03 01:17:19', 0),
(79, 5, 1, 'uploads/1777772652_69f6a86cabaf9.jpg', 'image', '2026-05-03 01:44:12', 0),
(80, 5, 1, 'uploads/1777772652_69f6a86cbc709.png', 'image', '2026-05-03 01:44:12', 0),
(81, 5, 1, '😢🙏', 'text', '2026-05-03 01:44:21', 0),
(82, 5, 1, 'uploads/1777774902_69f6b136d908a.png', 'image', '2026-05-03 02:21:42', 0),
(83, 5, 1, 'uploads/1777775116_69f6b20cdfa58.png', 'image', '2026-05-03 02:25:16', 0),
(84, 5, 1, 'uploads/1777775116_69f6b20ceb140.png', 'image', '2026-05-03 02:25:16', 0),
(85, 5, 1, 'uploads/1777775117_69f6b20d0df43.png', 'image', '2026-05-03 02:25:17', 0);

-- --------------------------------------------------------

--
-- Table structure for table `room_members`
--

CREATE TABLE `room_members` (
  `room_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_pinned` tinyint(1) DEFAULT 0 COMMENT 'Trạng thái ghim phòng: 0 = không ghim, 1 = đã ghim'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `room_members`
--

INSERT INTO `room_members` (`room_id`, `user_id`, `joined_at`, `is_pinned`) VALUES
(1, 1, '2026-05-02 16:50:19', 0),
(1, 2, '2026-05-02 16:50:19', 0),
(1, 3, '2026-05-02 16:50:19', 0),
(2, 1, '2026-05-02 20:15:37', 0),
(2, 4, '2026-05-02 20:15:37', 0),
(2, 5, '2026-05-02 20:15:37', 0),
(3, 1, '2026-05-02 20:15:37', 0),
(3, 2, '2026-05-02 20:15:37', 0),
(4, 1, '2026-05-02 20:15:37', 1),
(4, 6, '2026-05-02 20:15:37', 0),
(5, 1, '2026-05-02 20:15:37', 0),
(8, 1, '2026-05-03 00:46:56', 0),
(9, 1, '2026-05-03 00:51:24', 0);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `created_at`) VALUES
(1, 'Quang', 'password123', '2026-05-02 16:50:19'),
(2, 'Nhu', 'password123', '2026-05-02 16:50:19'),
(3, 'Dien', 'password123', '2026-05-02 16:50:19'),
(4, 'Minh', 'password123', '2026-05-02 16:50:19'),
(5, 'Thảo_Design', '123', '2026-05-02 20:15:37'),
(6, 'Phong_Dev', '123', '2026-05-02 20:15:37'),
(7, 'Tuấn_Pro', '123', '2026-05-02 20:15:37');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `chat_rooms`
--
ALTER TABLE `chat_rooms`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `room_id` (`room_id`),
  ADD KEY `sent_at` (`sent_at`);

--
-- Indexes for table `room_members`
--
ALTER TABLE `room_members`
  ADD PRIMARY KEY (`room_id`,`user_id`),
  ADD KEY `idx_room_members_pinned` (`user_id`,`is_pinned`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `chat_rooms`
--
ALTER TABLE `chat_rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=86;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `room_members`
--
ALTER TABLE `room_members`
  ADD CONSTRAINT `room_members_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `room_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
