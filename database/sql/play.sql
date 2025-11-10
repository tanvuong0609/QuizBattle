-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Máy chủ: 127.0.0.1:3366
-- Thời gian đã tạo: Th10 10, 2025 lúc 07:08 PM
-- Phiên bản máy phục vụ: 10.4.32-MariaDB
-- Phiên bản PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Cơ sở dữ liệu: `play`
--

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `players`
--

CREATE TABLE `players` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `room_code` varchar(255) DEFAULT NULL,
  `score` int(11) DEFAULT 0,
  `last_answer` int(11) DEFAULT NULL,
  `is_ready` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `players`
--

INSERT INTO `players` (`id`, `name`, `room_code`, `score`, `last_answer`, `is_ready`, `created_at`) VALUES
(1, 'huynh', 'room_691222cb92202', 0, 1, 0, '2025-11-10 17:37:15'),
(2, 'trí', 'room_691222cb92202', 2, 0, 0, '2025-11-10 17:37:25'),
(3, 'hà', 'room_691222cb92202', 2, 2, 0, '2025-11-10 17:37:32'),
(4, 'vượng', 'room_691222cb92202', 0, 1, 0, '2025-11-10 17:37:42'),
(5, 'h', 'room_6912249f3d930', 1, 1, 0, '2025-11-10 17:45:03'),
(6, 'f', 'room_6912249f3d930', 1, 2, 0, '2025-11-10 17:45:11'),
(7, 't', 'room_6912249f3d930', 1, 1, 0, '2025-11-10 17:45:17'),
(8, 'm', 'room_6912249f3d930', 3, 0, 0, '2025-11-10 17:45:24'),
(9, 'd', 'room_6912249f3d930', 0, NULL, 0, '2025-11-10 17:56:55'),
(10, 'd', 'room_69122767c793e', 0, NULL, 0, '2025-11-10 17:56:55'),
(11, 'g', 'room_6912277b1dc1a', 0, NULL, 0, '2025-11-10 17:57:15'),
(12, 'g', 'room_6912277e160ef', 0, NULL, 0, '2025-11-10 17:57:18'),
(13, 'g', 'room_6912277e5fca6', 0, NULL, 0, '2025-11-10 17:57:18'),
(14, 'g', 'room_6912277ec780d', 0, NULL, 0, '2025-11-10 17:57:18'),
(15, 'g', 'room_6912277f050b8', 0, NULL, 0, '2025-11-10 17:57:19'),
(16, 'd', 'room_6912277e160ef', 0, NULL, 0, '2025-11-10 17:59:24'),
(17, 'g', 'room_691228025cf93', 0, NULL, 0, '2025-11-10 17:59:30'),
(18, 'f', 'room_6912277e160ef', 0, NULL, 0, '2025-11-10 17:59:33'),
(19, 'f', 'room_6912277e5fca6', 0, NULL, 0, '2025-11-10 17:59:41'),
(20, 'r', 'room_6912277e160ef', 0, NULL, 0, '2025-11-10 17:59:50'),
(21, 'b', 'room_6912277e160ef', 0, NULL, 0, '2025-11-10 17:59:57'),
(22, 'g', 'room_6912277e5fca6', 0, NULL, 0, '2025-11-10 18:00:15'),
(23, 'a', 'room_6912277e160ef', 0, NULL, 0, '2025-11-10 18:00:48'),
(24, 'a', 'room_6912277e160ef', 0, NULL, 0, '2025-11-10 18:00:54'),
(25, 'a', 'room_691228ac9d632', 1, 1, 0, '2025-11-10 18:02:20'),
(26, 's', 'room_691228ac9d632', 5, 2, 0, '2025-11-10 18:02:24'),
(27, 'd', 'room_691228ac9d632', 1, 1, 0, '2025-11-10 18:02:27'),
(28, 'f', 'room_691228ac9d632', 2, 0, 0, '2025-11-10 18:02:31');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `questions`
--

CREATE TABLE `questions` (
  `id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `options_json` text NOT NULL,
  `correct_index` int(11) NOT NULL,
  `time_limit` int(11) DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `questions`
--

INSERT INTO `questions` (`id`, `question_text`, `options_json`, `correct_index`, `time_limit`) VALUES
(1, 'Thủ đô Việt Nam là?', '[\"Hà Nội\",\"Huế\",\"Đà Nẵng\"]', 0, 10),
(2, '2 + 2 = ?', '[\"3\",\"4\",\"5\"]', 1, 10),
(3, 'Bác Hồ sinh năm nào?', '[\"1890\",\"1900\",\"1911\"]', 0, 10),
(4, 'Màu cờ Việt Nam là?', '[\"Đỏ\",\"Xanh\",\"Vàng\"]', 0, 10),
(5, 'Sông Hồng chảy qua thành phố nào?', '[\"Hà Nội\",\"Huế\",\"Đà Nẵng\"]', 0, 10),
(6, 'Ai là tác giả của bài thơ \"Sóng\"?', '[\"Xuân Quỳnh\",\"Tố Hữu\",\"Nguyễn Du\"]', 0, 10),
(7, 'Loài vật nào có vú nhưng biết bay?', '[\"Dơi\",\"Chim sẻ\",\"Chim cánh cụt\"]', 0, 10),
(8, 'Ngôn ngữ lập trình nào được dùng để viết trang web động trên máy chủ?', '[\"HTML\",\"PHP\",\"CSS\"]', 1, 10);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `rooms`
--

CREATE TABLE `rooms` (
  `id` int(11) NOT NULL,
  `room_code` varchar(255) DEFAULT NULL,
  `status` enum('waiting','playing','ended') DEFAULT 'waiting',
  `current_question` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `rooms`
--

INSERT INTO `rooms` (`id`, `room_code`, `status`, `current_question`, `created_at`) VALUES
(1, 'room_69121daf341dd', 'ended', 0, '2025-11-10 17:15:27'),
(2, 'room_691222cb92202', 'ended', 0, '2025-11-10 17:37:15'),
(3, 'room_6912249f3d930', 'playing', 0, '2025-11-10 17:45:03'),
(4, 'room_69122767c793e', 'waiting', 0, '2025-11-10 17:56:55'),
(5, 'room_6912277b1dc1a', 'waiting', 0, '2025-11-10 17:57:15'),
(6, 'room_6912277e160ef', 'playing', 0, '2025-11-10 17:57:18'),
(7, 'room_6912277e5fca6', 'waiting', 0, '2025-11-10 17:57:18'),
(8, 'room_6912277ec780d', 'waiting', 0, '2025-11-10 17:57:18'),
(9, 'room_6912277f050b8', 'waiting', 0, '2025-11-10 17:57:19'),
(10, 'room_691228025cf93', 'waiting', 0, '2025-11-10 17:59:30'),
(11, 'room_691228ac9d632', 'ended', 0, '2025-11-10 18:02:20');

--
-- Chỉ mục cho các bảng đã đổ
--

--
-- Chỉ mục cho bảng `players`
--
ALTER TABLE `players`
  ADD PRIMARY KEY (`id`);

--
-- Chỉ mục cho bảng `questions`
--
ALTER TABLE `questions`
  ADD PRIMARY KEY (`id`);

--
-- Chỉ mục cho bảng `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `room_code` (`room_code`);

--
-- AUTO_INCREMENT cho các bảng đã đổ
--

--
-- AUTO_INCREMENT cho bảng `players`
--
ALTER TABLE `players`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT cho bảng `questions`
--
ALTER TABLE `questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT cho bảng `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
