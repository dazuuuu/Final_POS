-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Oct 13, 2026 at 01:42 PM
-- Server version: 10.11.19-MariaDB-cll-lve
-- PHP Version: 8.4.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dazutech_archimedes`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(150) DEFAULT NULL,
  `role` varchar(60) DEFAULT NULL,
  `entity_type` varchar(60) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `entity_label` varchar(200) DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `changes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `tenant_id`, `user_id`, `username`, `role`, `entity_type`, `entity_id`, `entity_label`, `action`, `changes`, `created_at`) VALUES
(
-- --------------------------------------------------------

--
-- Table structure for table `blogs`
--

CREATE TABLE `blogs` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `excerpt` text DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `featured_image` varchar(255) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `author_id` int(11) NOT NULL,
  `status` enum('draft','published','archived') DEFAULT 'draft',
  `view_count` int(11) DEFAULT 0,
  `is_featured` tinyint(1) DEFAULT 0,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `meta_keywords` varchar(255) DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_categories`
--

CREATE TABLE `blog_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(20) DEFAULT '#667eea',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_faqs`
--

CREATE TABLE `blog_faqs` (
  `id` int(11) NOT NULL,
  `blog_id` int(11) NOT NULL,
  `question` varchar(300) NOT NULL,
  `answer` text NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_sections`
--

CREATE TABLE `blog_sections` (
  `id` int(11) NOT NULL,
  `blog_id` int(11) NOT NULL,
  `section_type` enum('text_only','text_image_left','text_image_right','image_gallery','video','youtube','code_block','quote') DEFAULT 'text_only',
  `title` varchar(255) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `media_url` varchar(500) DEFAULT NULL,
  `media_type` enum('image','video','youtube') DEFAULT 'image',
  `video_id` varchar(100) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_tags`
--

CREATE TABLE `blog_tags` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_tag_relations`
--

CREATE TABLE `blog_tag_relations` (
  `blog_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `book_attributes`
--

CREATE TABLE `book_attributes` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `type` enum('grade','publisher','author','edition','brand') NOT NULL,
  `name` varchar(160) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `book_attributes`
--

INSERT INTO `book_attributes` (`id`, `tenant_id`, `type`, `name`, `created_at`, `updated_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `type` enum('subject','stationery') NOT NULL DEFAULT 'subject',
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('active','draft') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `tenant_id`, `name`, `type`, `image_path`, `status`, `created_at`, `updated_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `enquiries`
--

CREATE TABLE `enquiries` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `service` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` enum('new','read','contacted','closed') DEFAULT 'new',
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `notes` text DEFAULT NULL,
  `contacted_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enquiry_replies`
--

CREATE TABLE `enquiry_replies` (
  `id` int(11) NOT NULL,
  `enquiry_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `reply` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gallery`
--

CREATE TABLE `gallery` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `media_type` enum('image','video') DEFAULT 'image',
  `file_path` varchar(500) NOT NULL,
  `thumbnail_path` varchar(500) DEFAULT NULL,
  `video_url` varchar(500) DEFAULT NULL,
  `video_embed_code` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_featured` tinyint(1) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `view_count` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gallery_categories`
--

CREATE TABLE `gallery_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `held_orders`
--

CREATE TABLE `held_orders` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `customer_name` varchar(120) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `held_orders`
--

INSERT INTO `held_orders` (`id`, `tenant_id`, `customer_name`, `staff_id`, `created_at`) VALUES
(2, 6, 'Walk-in Customer', 13, '2026-08-21 13:44:29'),
(3, 6, 'Walk-in Customer', 13, '2026-08-22 11:38:16'),
(6, 6, 'Walk-in Custo8904054779191mer', 13, '2026-09-08 15:23:45'),
(7, 6, 'Walk-in Custo89040547791918904054779191mer', 13, '2026-09-08 15:23:45'),
(8, 6, 'Walk-in Customer', 13, '2026-09-23 15:45:28'),
(9, 6, 'Walk-in Customer', 16, '2026-09-24 14:15:37');

-- --------------------------------------------------------

--
-- Table structure for table `held_order_items`
--

CREATE TABLE `held_order_items` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `held_order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(160) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `quantity` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `held_order_items`
--

INSERT INTO `held_order_items` (`id`, `tenant_id`, `held_order_id`, `product_id`, `product_name`, `unit_price`, `quantity`) VALUES


-- --------------------------------------------------------

--
-- Table structure for table `hero_slides`
--

CREATE TABLE `hero_slides` (
  `id` int(10) UNSIGNED NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(20) DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `customer_phone` varchar(30) DEFAULT NULL,
  `customer_email` varchar(150) DEFAULT NULL,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `payment_status` enum('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  `payment_method` varchar(10) DEFAULT NULL,
  `mpesa_channel` varchar(10) DEFAULT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(200) NOT NULL,
  `unit` varchar(30) DEFAULT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `attempt_time` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `email`, `ip_address`, `attempt_time`) VALUES
(6, 'dazuhubs@gmail.com', '102.219.209.76', '2026-08-07 13:05:53'),
(7, 'dazuhubs@gmail.com', '102.219.209.76', '2026-08-07 13:06:20'),
(8, 'dazuhubs@gmail.com', '102.219.209.76', '2026-08-07 13:07:22'),
(9, '', '102.219.209.76', '2026-08-07 13:33:05'),
(10, '', '102.219.209.76', '2026-08-07 13:36:13'),
(11, 'karanjav494@gmail.com', '102.219.209.76', '2026-08-07 14:39:39'),
(12, 'karanjav494@gmail.com', '102.219.209.76', '2026-08-07 14:39:45'),
(13, 'dazuhubs@gmail.com', '102.219.209.76', '2026-08-07 14:40:02'),
(14, 'dazuhubs@gmail.com', '102.219.209.76', '2026-08-07 14:40:03'),
(15, 'dazuhubs@gmail.com', '197.254.8.98', '2026-08-08 06:31:40'),
(16, 'dazuhubs@gmail.com', '105.161.97.43', '2026-08-13 10:45:19'),
(17, 'karanjav494@gmail.com', '105.161.97.43', '2026-08-13 10:45:23'),
(18, 'dazuhubs@gmail.com', '197.254.8.98', '2026-08-14 13:10:02'),
(19, 'dazuhubs@gmail.c', '102.219.209.77', '2026-09-07 09:37:13'),
(20, 'dazuhubs@gmail.co', '102.219.209.78', '2026-09-15 08:53:38'),
(21, 'dazuhubs@gmail.co', '102.219.209.78', '2026-09-15 08:53:41'),
(22, 'dazuhubs@gmail.com````````', '102.219.209.76', '2026-09-17 15:27:32'),
(23, 'dazuhubs@gmail.com````````', '102.219.209.76', '2026-09-17 15:27:33'),
(24, 'dazuhubs@gmail.com````````', '102.219.209.76', '2026-09-17 15:27:33'),
(25, 'dazuhubs@gmail.com````````', '102.219.209.76', '2026-09-17 15:27:33'),
(26, 'dazuhubs@gmail.com````````', '102.219.209.76', '2026-09-17 15:27:34'),
(27, 'dazuhubs@gmail.com6902105168019', '102.219.209.77', '2026-10-07 13:38:10'),
(28, 'dazuhubs@gmail.c', '102.219.209.77', '2026-10-10 06:24:03');

-- --------------------------------------------------------

--
-- Table structure for table `login_otps`
--

CREATE TABLE `login_otps` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `code_hash` varchar(255) NOT NULL,
  `purpose` varchar(32) NOT NULL DEFAULT 'login_2fa',
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `max_attempts` tinyint(4) NOT NULL DEFAULT 5,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_otps`
--

INSERT INTO `login_otps` (`id`, `user_id`, `tenant_id`, `code_hash`, `purpose`, `attempts`, `max_attempts`, `expires_at`, `consumed_at`, `ip`, `created_at`) VALUES
(2, 11, 6, '$2y$10$zZigBzZy3zUWCCVVZUMTt.r3/GX1mbtJKTz0HawziWyaEz1UM9Sre', 'password_reset', 0, 5, '2026-08-07 16:16:31', '2026-08-09 22:39:44', '102.219.209.76', '2026-08-07 16:06:31'),
(3, 11, 6, '$2y$10$aT45NPLZ0lzfE6P06dpDqOTiXfIR1nmUDTdxjGdmvVxkfvss1RIw6', 'password_reset', 0, 5, '2026-08-09 22:49:44', NULL, '197.254.8.98', '2026-08-09 22:39:45');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `table_name` varchar(120) NOT NULL,
  `customer_phone` varchar(30) DEFAULT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `channel` enum('walkin','tab') NOT NULL DEFAULT 'tab',
  `opened_by` int(11) NOT NULL,
  `receipt_number` varchar(32) NOT NULL,
  `status` enum('open','paid','void') NOT NULL DEFAULT 'open',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','mpesa','split') DEFAULT NULL,
  `cash_amount` decimal(12,2) DEFAULT NULL,
  `mpesa_amount` decimal(12,2) DEFAULT NULL,
  `amount_tendered` decimal(12,2) DEFAULT NULL,
  `change_due` decimal(12,2) DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `invoice_sent_at` datetime DEFAULT NULL,
  `delivery_note_sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `tenant_id`, `table_name`, `customer_phone`, `customer_email`, `channel`, `opened_by`, `receipt_number`, `status`, `subtotal`, `discount_amount`, `total`, `payment_method`, `cash_amount`, `mpesa_amount`, `amount_tendered`, `change_due`, `paid_by`, `paid_at`, `created_at`, `updated_at`, `invoice_sent_at`, `delivery_note_sent_at`) VALUES


-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(160) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `added_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `tenant_id`, `order_id`, `product_id`, `product_name`, `unit_price`, `quantity`, `line_total`, `added_by`, `created_at`) VALUES
0.00, 1.00, 50.00, 13, '2026-10-13 12:22:00'),
(2956, 6, 1487, 926, 'Clear clipboard', 150.00, 1.00, 150.00, 13, '2026-10-13 12:22:00'),
(2957, 6, 1488, 922, 'Markpen M&G', 50.00, 2.00, 100.00, 13, '2026-10-13 12:25:50'),
(2958, 6, 1488, 1105, 'Staple pins 24/61m blue pack', 50.00, 1.00, 50.00, 13, '2026-10-13 12:25:50'),
(2959, 6, 1489, 922, 'Markpen M&G', 50.00, 1.00, 50.00, 13, '2026-10-13 13:13:05');

-- --------------------------------------------------------

--
-- Table structure for table `page_headers`
--

CREATE TABLE `page_headers` (
  `id` int(10) UNSIGNED NOT NULL,
  `page_key` varchar(60) NOT NULL,
  `title` varchar(150) DEFAULT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `product_type` enum('book','stationery') NOT NULL DEFAULT 'book',
  `category_id` int(11) DEFAULT NULL,
  `subcategory_id` int(11) DEFAULT NULL,
  `grade_id` int(11) DEFAULT NULL,
  `publisher_id` int(11) DEFAULT NULL,
  `author_id` int(11) DEFAULT NULL,
  `edition_id` int(11) DEFAULT NULL,
  `brand_id` int(11) DEFAULT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `name` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(20) NOT NULL DEFAULT 'piece',
  `size_value` decimal(10,2) DEFAULT NULL,
  `size_unit` enum('ml','l') DEFAULT NULL,
  `buying_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `wholesale_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `retail_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `offer_price` decimal(12,2) DEFAULT NULL,
  `offer_starts_at` datetime DEFAULT NULL,
  `offer_ends_at` datetime DEFAULT NULL,
  `colors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`colors`)),
  `sizes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sizes`)),
  `image_path` varchar(255) DEFAULT NULL,
  `low_stock_threshold` int(11) NOT NULL DEFAULT 10,
  `low_stock_notified_at` datetime DEFAULT NULL,
  `status` enum('active','draft','archived') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `tenant_id`, `product_type`, `category_id`, `subcategory_id`, `grade_id`, `publisher_id`, `author_id`, `edition_id`, `brand_id`, `barcode`, `supplier_id`, `name`, `description`, `quantity`, `unit`, `size_value`, `size_unit`, `buying_price`, `selling_price`, `wholesale_price`, `retail_price`, `offer_price`, `offer_starts_at`, `offer_ends_at`, `colors`, `sizes`, `image_path`, `low_stock_threshold`, `low_stock_notified_at`, `status`, `created_at`, `updated_at`) VALUES
ULL, 451, 'AE411522', NULL, 'CBC Toys watercolors (small )', NULL, 53.00, 'piece', NULL, NULL, 50.00, 100.00, 100.00, 100.00, NULL, NULL, NULL, '[\"blue\"]', NULL, NULL, 10, NULL, 'active', '2026-10-10 11:05:54', '2026-10-10 11:05:54'),

--
-- Table structure for table `product_categories`
--

CREATE TABLE `product_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `small_title` varchar(100) NOT NULL,
  `major_title` varchar(200) NOT NULL,
  `project_slug` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `status` enum('draft','published','archived') DEFAULT 'draft',
  `view_count` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_categories`
--

CREATE TABLE `project_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `category_slug` varchar(100) NOT NULL,
  `category_description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_gallery`
--

CREATE TABLE `project_gallery` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `image_title` varchar(100) DEFAULT NULL,
  `image_description` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_tags`
--

CREATE TABLE `project_tags` (
  `id` int(11) NOT NULL,
  `tag_name` varchar(50) NOT NULL,
  `tag_slug` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_tag_relations`
--

CREATE TABLE `project_tag_relations` (
  `project_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_videos`
--

CREATE TABLE `project_videos` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `video_title` varchar(200) DEFAULT NULL,
  `video_url` varchar(500) NOT NULL,
  `video_embed_code` text DEFAULT NULL,
  `video_type` enum('youtube','vimeo','local','other') DEFAULT 'youtube',
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `scope` enum('platform','tenant') NOT NULL DEFAULT 'tenant',
  `capabilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`capabilities`)),
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `role_name`, `scope`, `capabilities`, `created_at`) VALUES
(1, 'superadmin', 'platform', '[\"*\"]', '2026-06-17 13:29:32'),
(2, 'admin', 'tenant', '[\"inventory.view\", \"inventory.edit\", \"stock.enter\", \"sales.record\", \"sales.view\", \"customers.manage\", \"catalogue.send\", \"reports.view\", \"staff.manage\", \"settings.manage\", \"billing.manage\"]', '2026-06-17 13:29:32'),
(3, 'user', 'tenant', '[\"inventory.view\", \"sales.record\", \"sales.view\"]', '2026-06-17 13:29:32'),
(4, 'platform_admin', 'platform', '[\"*\"]', '2026-06-20 02:10:12'),
(5, 'tenant_owner', 'tenant', '[\"inventory.view\", \"inventory.edit\", \"stock.enter\", \"sales.record\", \"sales.view\", \"payments.process\", \"customers.manage\", \"catalogue.send\", \"reports.view\", \"staff.manage\", \"settings.manage\", \"billing.manage\"]', '2026-06-20 02:10:14'),
(6, 'staff', 'tenant', '[\"inventory.view\", \"sales.record\", \"sales.view\"]', '2026-06-20 02:10:16');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `sale_type` enum('retail','wholesale') NOT NULL DEFAULT 'retail',
  `receipt_number` varchar(32) NOT NULL,
  `payment_method` enum('cash','mpesa','split','credit') NOT NULL DEFAULT 'cash',
  `mpesa_channel` varchar(10) DEFAULT NULL,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_given` decimal(12,2) DEFAULT NULL,
  `change_given` decimal(12,2) DEFAULT NULL,
  `cash_amount` decimal(12,2) DEFAULT NULL,
  `mpesa_amount` decimal(12,2) DEFAULT NULL,
  `customer_name` varchar(120) DEFAULT NULL,
  `customer_phone` varchar(30) DEFAULT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `status` enum('completed','voided') NOT NULL DEFAULT 'completed',
  `payment_status` enum('pending','paid','part_paid','credit','failed') NOT NULL DEFAULT 'paid',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(160) NOT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'piece',
  `unit_price` decimal(12,2) NOT NULL,
  `price_type` enum('retail','wholesale') NOT NULL DEFAULT 'retail',
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `quantity` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_payments`
--

CREATE TABLE `sale_payments` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(20) NOT NULL DEFAULT 'cash',
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `short_description` text DEFAULT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `status` enum('draft','published','archived') DEFAULT 'draft',
  `view_count` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_benefits`
--

CREATE TABLE `service_benefits` (
  `id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `benefit_title` varchar(200) NOT NULL,
  `benefit_description` text DEFAULT NULL,
  `icon_class` varchar(100) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_faqs`
--

CREATE TABLE `service_faqs` (
  `id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `question` varchar(300) NOT NULL,
  `answer` text NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_gallery`
--

CREATE TABLE `service_gallery` (
  `id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `image_title` varchar(100) DEFAULT NULL,
  `image_description` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_sections`
--

CREATE TABLE `service_sections` (
  `id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `section_type` enum('text_only','text_image_left','text_image_right','image_gallery','video') DEFAULT 'text_only',
  `title` varchar(200) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `media_url` varchar(500) DEFAULT NULL,
  `media_type` enum('image','video','youtube','vimeo') DEFAULT 'image',
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `site_settings`
--

CREATE TABLE `site_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_reclock_authorizations`
--

CREATE TABLE `staff_reclock_authorizations` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `authorized_by` int(11) NOT NULL,
  `authorized_at` datetime NOT NULL DEFAULT current_timestamp(),
  `used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `staff_reclock_authorizations`
--

INSERT INTO `staff_reclock_authorizations` (`id`, `tenant_id`, `user_id`, `authorized_by`, `authorized_at`, `used_at`) VALUES
(2, 6, 16, 11, '2026-09-24 12:14:25', '2026-09-24 12:14:45'),
(3, 6, 16, 11, '2026-09-28 11:30:11', '2026-09-28 11:30:28'),
(4, 6, 16, 11, '2026-09-28 11:30:13', '2026-10-03 16:14:10'),
(5, 6, 16, 11, '2026-09-28 11:30:15', '2026-10-08 08:44:51');

-- --------------------------------------------------------

--
-- Table structure for table `staff_time_logs`
--

CREATE TABLE `staff_time_logs` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `clock_in_at` datetime NOT NULL,
  `clock_out_at` datetime DEFAULT NULL,
  `auto_closed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `staff_time_logs`
--

INSERT INTO `staff_time_logs` (`id`, `tenant_id`, `user_id`, `clock_in_at`, `clock_out_at`, `auto_closed`, `created_at`) VALUES
(18:21:56', 0, '2026-10-12 08:04:05'),
(123, 6, 16, '2026-10-13 07:49:23', NULL, 0, '2026-10-13 07:49:23'),
(124, 6, 13, '2026-10-13 12:21:00', NULL, 0, '2026-10-13 12:21:00');

-- --------------------------------------------------------

--
-- Table structure for table `stock_intakes`
--

CREATE TABLE `stock_intakes` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `staff_id` int(11) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_intakes`
--

INSERT INTO `stock_intakes` (`id`, `tenant_id`, `supplier_id`, `staff_id`, `notes`, `created_at`) VALUES

(2869, 6, NULL, 11, NULL, '2026-10-08 10:26:11'),
(2870, 6, NULL, 11, NULL, '2026-10-08 10:28:52'),
(2871, 6, NULL, 11, NULL, '2026-10-08 10:34:10'),
(2872, 6, NULL, 11, NULL, '2026-10-08 10:47:24'),
(2873, 6, NULL, 11, NULL, '2026-10-08 11:12:27'),
(2874, 6, NULL, 11, NULL, '2026-10-08 14:55:31'),
(2875, 6, NULL, 11, NULL, '2026-10-08 14:58:40'),
(2876, 6, NULL, 11, NULL, '2026-10-10 10:19:29'),
(2877, 6, NULL, 11, NULL, '2026-10-10 10:25:00'),
(2878, 6, NULL, 11, NULL, '2026-10-10 10:28:31'),
(2879, 6, NULL, 11, NULL, '2026-10-10 10:52:55'),
(2880, 6, NULL, 11, NULL, '2026-10-10 11:05:54'),
(2881, 6, NULL, 11, NULL, '2026-10-10 11:42:17'),
(2882, 6, NULL, 11, NULL, '2026-10-10 12:07:30'),
(2884, 6, NULL, 11, NULL, '2026-10-10 12:28:26'),
(2885, 6, NULL, 11, NULL, '2026-10-10 13:16:39'),
(2886, 6, NULL, 11, NULL, '2026-10-10 13:18:14'),
(2887, 6, NULL, 11, NULL, '2026-10-10 14:24:59'),
(2888, 6, NULL, 11, NULL, '2026-10-10 14:29:55'),
(2889, 6, NULL, 11, NULL, '2026-10-10 14:31:12'),
(2890, 6, NULL, 11, NULL, '2026-10-10 14:33:13'),
(2891, 6, NULL, 11, NULL, '2026-10-10 15:04:32'),
(2892, 6, NULL, 11, NULL, '2026-10-10 15:46:49'),
(2893, 6, NULL, 11, NULL, '2026-10-10 16:00:18'),
(2894, 6, NULL, 11, NULL, '2026-10-10 16:16:48'),
(2895, 6, NULL, 11, NULL, '2026-10-10 16:24:28'),
(2896, 6, NULL, 11, NULL, '2026-10-10 16:25:59'),
(2897, 6, NULL, 11, NULL, '2026-10-10 16:41:38'),
(2898, 6, NULL, 11, NULL, '2026-10-10 16:45:18'),
(2899, 6, NULL, 11, NULL, '2026-10-10 18:07:00'),
(2900, 6, NULL, 11, NULL, '2026-10-12 09:30:30'),
(2901, 6, NULL, 11, NULL, '2026-10-12 11:36:33'),
(2902, 6, NULL, 11, NULL, '2026-10-12 12:19:40'),
(2903, 6, NULL, 11, NULL, '2026-10-12 12:44:26'),
(2904, 6, NULL, 11, NULL, '2026-10-12 13:06:50'),
(2905, 6, NULL, 11, NULL, '2026-10-12 13:48:17'),
(2906, 6, NULL, 11, NULL, '2026-10-12 14:01:31');

-- --------------------------------------------------------

--
-- Table structure for table `stock_intake_items`
--

CREATE TABLE `stock_intake_items` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `stock_intake_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(160) NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `buying_price` decimal(12,2) NOT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_intake_items`
--

INSERT INTO `stock_intake_items` (`id`, `tenant_id`, `stock_intake_id`, `product_id`, `product_name`, `quantity`, `buying_price`, `remark`, `created_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `store_categories`
--

CREATE TABLE `store_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subcategories`
--

CREATE TABLE `subcategories` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `status` enum('active','draft') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `billing_interval` enum('weekly','biweekly','monthly') NOT NULL DEFAULT 'monthly',
  `amount` decimal(10,2) NOT NULL,
  `status` enum('trialing','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'trialing',
  `current_period_start` datetime DEFAULT NULL,
  `current_period_end` datetime DEFAULT NULL,
  `grace_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscription_plans`
--

CREATE TABLE `subscription_plans` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `price_weekly` decimal(10,2) DEFAULT NULL,
  `price_biweekly` decimal(10,2) DEFAULT NULL,
  `price_monthly` decimal(10,2) DEFAULT NULL,
  `max_staff` int(11) DEFAULT NULL,
  `max_products` int(11) DEFAULT NULL,
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_public` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscription_stk`
--

CREATE TABLE `subscription_stk` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `subscription_id` int(11) DEFAULT NULL,
  `plan_id` int(11) NOT NULL,
  `billing_interval` enum('weekly','biweekly','monthly') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `checkout_request_id` varchar(64) DEFAULT NULL,
  `merchant_request_id` varchar(64) DEFAULT NULL,
  `status` enum('pending','success','failed','cancelled') NOT NULL DEFAULT 'pending',
  `result_code` int(11) DEFAULT NULL,
  `result_desc` varchar(191) DEFAULT NULL,
  `mpesa_receipt` varchar(32) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(160) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `tenant_id`, `name`, `phone`, `notes`, `created_at`, `updated_at`) VALUES
(1, 6, 'Artist', NULL, NULL, '2026-08-08 11:02:54', '2026-08-08 11:02:54'),
(2, 6, 's', NULL, NULL, '2026-08-10 17:15:05', '2026-08-10 17:15:05'),
(3, 6, 'KASUKU', NULL, NULL, '2026-08-31 15:15:21', '2026-08-31 15:15:21');

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `status` enum('active','suspended','cancelled') NOT NULL DEFAULT 'active',
  `logo_path` varchar(255) DEFAULT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'KES',
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `receipt_footer` varchar(255) DEFAULT NULL,
  `kra_pin` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tenants`
--

INSERT INTO `tenants` (`id`, `name`, `slug`, `owner_user_id`, `status`, `logo_path`, `currency`, `phone`, `address`, `receipt_footer`, `kra_pin`, `created_at`, `updated_at`) VALUES
(6, 'Archimedes Elite Bookshop', 'archimedes', 11, 'active', 'uploads/branding/tenant_6_15c12468.png', 'KES', '0727022062', 'Mama Ngina street', 'Thank you for shopping with us', 'A0016017600M', '2026-08-07 16:05:33', '2026-08-14 09:38:59');

-- --------------------------------------------------------

--
-- Table structure for table `testimonials`
--

CREATE TABLE `testimonials` (
  `id` int(11) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `customer_initial` varchar(5) DEFAULT NULL,
  `rating` int(11) DEFAULT 5,
  `testimonial_text` text NOT NULL,
  `service_tag` varchar(100) DEFAULT NULL,
  `role` varchar(100) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `is_featured` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `must_reset_password` tinyint(1) NOT NULL DEFAULT 0,
  `role_id` int(11) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `email_verified` tinyint(1) DEFAULT 0,
  `activation_token` varchar(64) DEFAULT NULL,
  `activation_expires` datetime DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `tenant_id`, `username`, `email`, `password_hash`, `pin_hash`, `position`, `must_reset_password`, `role_id`, `is_active`, `email_verified`, `activation_token`, `activation_expires`, `activated_at`, `created_at`, `updated_at`) VALUES
(11, 6, 'dazuhubs', 'dazuhubs@gmail.com', '$2y$10$F3Vqrg/bK0y6muoOe2CaXezJVtcLVrQ0136SPW1FD8cRhDJksyELO', NULL, NULL, 0, 5, 1, 1, NULL, NULL, NULL, '2026-08-07 13:05:33', '2026-08-07 13:05:33'),
(13, 6, 'NALIAKA', 'pin.57966620e5f47eac@staff.local', '$2y$10$WkDlPoWOvG0f.ITcGN3lkelbKjwDfEE1QWSU.J3bj4h.I/ukwLxNC', '$2y$10$qOPk2pt92qMQ.xFyAZD89uSv3l.XF3YKrL/NXWrPVKAhILnpe0XJW', 'Sales manager', 0, 6, 1, 1, NULL, NULL, NULL, '2026-08-07 13:27:27', '2026-08-07 13:27:27'),
(14, 6, 'mabongamercy2021', 'mabongamercy2021@gmail.com', '$2y$10$jdh5NC48dc8PkvcM0vspfe8Ec.1o/dhe.8zkEABtTtQv69TwtBDC6', NULL, NULL, 0, 5, 1, 1, NULL, NULL, NULL, '2026-08-07 13:35:53', '2026-08-07 13:35:53'),
(15, 6, 'nthigad58', 'nthigad58@gmail.com', '$2y$10$FZ9ZHP0bVQlFeAiRITlLm.jNyFlRssOOkFfQojUlwWg.mcXJ.Os3i', NULL, NULL, 0, 5, 1, 1, NULL, NULL, NULL, '2026-08-07 14:00:42', '2026-08-07 14:00:42'),
(16, 6, 'Brenda', 'pin.1c94fd06a49a3c3c@staff.local', '$2y$10$WbVaoQUW9o7cjkERjpToKu6px6tNqs8gFkdZLofNxlM9YtmxVoeHe', '$2y$10$BqJysnLgqHUfXOADInsTxOMNcWfAMxGbbxNTe4ZUSo1KRK3tuUJ9y', 'sales assistant', 0, 6, 1, 1, NULL, NULL, NULL, '2026-08-10 05:04:13', '2026-08-10 05:04:13');

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `capability` varchar(64) NOT NULL,
  `effect` enum('grant','revoke') NOT NULL DEFAULT 'grant',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_permissions`
--

INSERT INTO `user_permissions` (`id`, `tenant_id`, `user_id`, `capability`, `effect`, `created_at`) VALUES
(1, 6, 13, 'payments.process', 'grant', '2026-08-07 16:32:56'),
(2, 6, 13, 'stock.enter', 'grant', '2026-08-07 16:32:56'),
(3, 6, 13, 'inventory.edit', 'grant', '2026-08-07 16:32:56');

-- --------------------------------------------------------

--
-- Table structure for table `user_profiles`
--

CREATE TABLE `user_profiles` (
  `user_id` int(11) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_tenant_time` (`tenant_id`,`created_at`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`);

--
-- Indexes for table `blogs`
--
ALTER TABLE `blogs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_slug` (`slug`),
  ADD KEY `idx_author` (`author_id`),
  ADD KEY `idx_category` (`category_id`),
  ADD KEY `idx_published` (`published_at`),
  ADD KEY `idx_featured` (`is_featured`);

--
-- Indexes for table `blog_categories`
--
ALTER TABLE `blog_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `fk_blogcat_user` (`created_by`),
  ADD KEY `idx_slug` (`slug`);

--
-- Indexes for table `blog_faqs`
--
ALTER TABLE `blog_faqs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_blog` (`blog_id`);

--
-- Indexes for table `blog_sections`
--
ALTER TABLE `blog_sections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_blog` (`blog_id`),
  ADD KEY `idx_sort` (`sort_order`);

--
-- Indexes for table `blog_tags`
--
ALTER TABLE `blog_tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `blog_tag_relations`
--
ALTER TABLE `blog_tag_relations`
  ADD PRIMARY KEY (`blog_id`,`tag_id`),
  ADD KEY `fk_btr_tag` (`tag_id`);

--
-- Indexes for table `book_attributes`
--
ALTER TABLE `book_attributes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_attr_tenant_type_name` (`tenant_id`,`type`,`name`),
  ADD KEY `idx_attr_tenant_type` (`tenant_id`,`type`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cat_tenant_type_name` (`tenant_id`,`type`,`name`),
  ADD KEY `idx_cat_tenant` (`tenant_id`);

--
-- Indexes for table `enquiries`
--
ALTER TABLE `enquiries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_created` (`created_at`);
ALTER TABLE `enquiries` ADD FULLTEXT KEY `idx_search` (`name`,`email`,`message`);

--
-- Indexes for table `enquiry_replies`
--
ALTER TABLE `enquiry_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_enqreply_admin` (`admin_id`),
  ADD KEY `idx_enquiry` (`enquiry_id`);

--
-- Indexes for table `gallery`
--
ALTER TABLE `gallery`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_gallery_user` (`created_by`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_media_type` (`media_type`),
  ADD KEY `idx_sort` (`sort_order`);

--
-- Indexes for table `gallery_categories`
--
ALTER TABLE `gallery_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `held_orders`
--
ALTER TABLE `held_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_held_tenant` (`tenant_id`);

--
-- Indexes for table `held_order_items`
--
ALTER TABLE `held_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_helditem_held` (`held_order_id`),
  ADD KEY `idx_helditem_tenant` (`tenant_id`);

--
-- Indexes for table `hero_slides`
--
ALTER TABLE `hero_slides`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hero_active_order` (`is_active`,`sort_order`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inv_tenant_status` (`tenant_id`,`status`),
  ADD KEY `idx_inv_sale` (`sale_id`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invitem_invoice` (`invoice_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email_time` (`email`,`attempt_time`);

--
-- Indexes for table `login_otps`
--
ALTER TABLE `login_otps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_otp_user_purpose` (`user_id`,`purpose`),
  ADD KEY `idx_otp_expires` (`expires_at`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_order_receipt` (`tenant_id`,`receipt_number`),
  ADD KEY `idx_order_tenant` (`tenant_id`),
  ADD KEY `idx_order_status` (`tenant_id`,`status`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_orderitem_order` (`order_id`),
  ADD KEY `idx_orderitem_tenant` (`tenant_id`);

--
-- Indexes for table `page_headers`
--
ALTER TABLE `page_headers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `page_key` (`page_key`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prod_tenant_barcode` (`tenant_id`,`barcode`),
  ADD KEY `idx_prod_tenant` (`tenant_id`),
  ADD KEY `idx_prod_cat` (`category_id`),
  ADD KEY `idx_prod_subcat` (`subcategory_id`),
  ADD KEY `idx_prod_supplier` (`supplier_id`),
  ADD KEY `idx_prod_status` (`status`),
  ADD KEY `idx_prod_lowstock` (`tenant_id`,`quantity`),
  ADD KEY `idx_prod_grade` (`grade_id`),
  ADD KEY `idx_prod_publisher` (`publisher_id`),
  ADD KEY `idx_prod_author` (`author_id`),
  ADD KEY `idx_prod_edition` (`edition_id`),
  ADD KEY `idx_prod_brand` (`brand_id`);

--
-- Indexes for table `product_categories`
--
ALTER TABLE `product_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `parent_id` (`parent_id`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_slug` (`slug`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `project_slug` (`project_slug`),
  ADD KEY `fk_proj_user` (`created_by`),
  ADD KEY `idx_category` (`category_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_slug` (`project_slug`);

--
-- Indexes for table `project_categories`
--
ALTER TABLE `project_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_slug` (`category_slug`),
  ADD KEY `fk_projcat_user` (`created_by`),
  ADD KEY `idx_slug` (`category_slug`);

--
-- Indexes for table `project_gallery`
--
ALTER TABLE `project_gallery`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_sort` (`sort_order`);

--
-- Indexes for table `project_tags`
--
ALTER TABLE `project_tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tag_name` (`tag_name`),
  ADD UNIQUE KEY `tag_slug` (`tag_slug`);

--
-- Indexes for table `project_tag_relations`
--
ALTER TABLE `project_tag_relations`
  ADD PRIMARY KEY (`project_id`,`tag_id`),
  ADD KEY `fk_ptr_tag` (`tag_id`);

--
-- Indexes for table `project_videos`
--
ALTER TABLE `project_videos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project` (`project_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sale_receipt` (`tenant_id`,`receipt_number`),
  ADD KEY `idx_sale_tenant` (`tenant_id`),
  ADD KEY `idx_sale_staff` (`staff_id`),
  ADD KEY `idx_sale_created` (`tenant_id`,`created_at`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_item_sale` (`sale_id`),
  ADD KEY `idx_item_tenant` (`tenant_id`);

--
-- Indexes for table `sale_payments`
--
ALTER TABLE `sale_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sale` (`sale_id`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_slug` (`slug`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indexes for table `service_benefits`
--
ALTER TABLE `service_benefits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_service` (`service_id`);

--
-- Indexes for table `service_faqs`
--
ALTER TABLE `service_faqs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_service` (`service_id`);

--
-- Indexes for table `service_gallery`
--
ALTER TABLE `service_gallery`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_service` (`service_id`),
  ADD KEY `idx_sort` (`sort_order`);

--
-- Indexes for table `service_sections`
--
ALTER TABLE `service_sections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_service` (`service_id`),
  ADD KEY `idx_sort` (`sort_order`);

--
-- Indexes for table `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `staff_reclock_authorizations`
--
ALTER TABLE `staff_reclock_authorizations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reclock_user` (`tenant_id`,`user_id`,`used_at`);

--
-- Indexes for table `staff_time_logs`
--
ALTER TABLE `staff_time_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_timelog_tenant` (`tenant_id`),
  ADD KEY `idx_timelog_user_time` (`user_id`,`clock_in_at`),
  ADD KEY `idx_timelog_open` (`user_id`,`clock_out_at`);

--
-- Indexes for table `stock_intakes`
--
ALTER TABLE `stock_intakes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_intake_tenant` (`tenant_id`),
  ADD KEY `idx_intake_supplier` (`supplier_id`);

--
-- Indexes for table `stock_intake_items`
--
ALTER TABLE `stock_intake_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_intakeitem_intake` (`stock_intake_id`),
  ADD KEY `idx_intakeitem_tenant` (`tenant_id`),
  ADD KEY `idx_intakeitem_product` (`product_id`);

--
-- Indexes for table `store_categories`
--
ALTER TABLE `store_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_slug` (`slug`);

--
-- Indexes for table `subcategories`
--
ALTER TABLE `subcategories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_subcat_tenant_cat_name` (`tenant_id`,`category_id`,`name`),
  ADD KEY `idx_subcat_tenant` (`tenant_id`),
  ADD KEY `idx_subcat_cat` (`category_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sub_tenant` (`tenant_id`),
  ADD KEY `idx_sub_status` (`status`),
  ADD KEY `idx_sub_period_end` (`current_period_end`);

--
-- Indexes for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_plan_active` (`is_active`);

--
-- Indexes for table `subscription_stk`
--
ALTER TABLE `subscription_stk`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stk_checkout` (`checkout_request_id`),
  ADD KEY `idx_stk_tenant` (`tenant_id`),
  ADD KEY `idx_stk_status` (`status`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_supplier_tenant_name` (`tenant_id`,`name`),
  ADD KEY `idx_supplier_tenant` (`tenant_id`);

--
-- Indexes for table `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tenant_slug` (`slug`),
  ADD KEY `idx_tenant_status` (`status`);

--
-- Indexes for table `testimonials`
--
ALTER TABLE `testimonials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_rating` (`rating`),
  ADD KEY `idx_featured` (`is_featured`),
  ADD KEY `idx_sort` (`sort_order`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `uq_users_tenant_email` (`tenant_id`,`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_users_tenant` (`tenant_id`),
  ADD KEY `idx_users_activation` (`activation_token`),
  ADD KEY `fk_users_role` (`role_id`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_cap` (`user_id`,`capability`),
  ADD KEY `idx_perm_tenant` (`tenant_id`);

--
-- Indexes for table `user_profiles`
--
ALTER TABLE `user_profiles`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=912;

--
-- AUTO_INCREMENT for table `blogs`
--
ALTER TABLE `blogs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `blog_categories`
--
ALTER TABLE `blog_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `blog_faqs`
--
ALTER TABLE `blog_faqs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `blog_sections`
--
ALTER TABLE `blog_sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `blog_tags`
--
ALTER TABLE `blog_tags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `book_attributes`
--
ALTER TABLE `book_attributes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=453;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=245;

--
-- AUTO_INCREMENT for table `enquiries`
--
ALTER TABLE `enquiries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enquiry_replies`
--
ALTER TABLE `enquiry_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gallery`
--
ALTER TABLE `gallery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gallery_categories`
--
ALTER TABLE `gallery_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `held_orders`
--
ALTER TABLE `held_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `held_order_items`
--
ALTER TABLE `held_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `hero_slides`
--
ALTER TABLE `hero_slides`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `login_otps`
--
ALTER TABLE `login_otps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1490;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2960;

--
-- AUTO_INCREMENT for table `page_headers`
--
ALTER TABLE `page_headers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2106;

--
-- AUTO_INCREMENT for table `product_categories`
--
ALTER TABLE `product_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_categories`
--
ALTER TABLE `project_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `project_gallery`
--
ALTER TABLE `project_gallery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_tags`
--
ALTER TABLE `project_tags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_videos`
--
ALTER TABLE `project_videos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_payments`
--
ALTER TABLE `sale_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_benefits`
--
ALTER TABLE `service_benefits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_faqs`
--
ALTER TABLE `service_faqs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_gallery`
--
ALTER TABLE `service_gallery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_sections`
--
ALTER TABLE `service_sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_reclock_authorizations`
--
ALTER TABLE `staff_reclock_authorizations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `staff_time_logs`
--
ALTER TABLE `staff_time_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=125;

--
-- AUTO_INCREMENT for table `stock_intakes`
--
ALTER TABLE `stock_intakes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2907;

--
-- AUTO_INCREMENT for table `stock_intake_items`
--
ALTER TABLE `stock_intake_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2477;

--
-- AUTO_INCREMENT for table `store_categories`
--
ALTER TABLE `store_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `subcategories`
--
ALTER TABLE `subcategories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `subscription_stk`
--
ALTER TABLE `subscription_stk`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `testimonials`
--
ALTER TABLE `testimonials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `blogs`
--
ALTER TABLE `blogs`
  ADD CONSTRAINT `fk_blog_cat` FOREIGN KEY (`category_id`) REFERENCES `blog_categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_blog_user` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `blog_categories`
--
ALTER TABLE `blog_categories`
  ADD CONSTRAINT `fk_blogcat_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `blog_faqs`
--
ALTER TABLE `blog_faqs`
  ADD CONSTRAINT `fk_blogfaq_blog` FOREIGN KEY (`blog_id`) REFERENCES `blogs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `blog_sections`
--
ALTER TABLE `blog_sections`
  ADD CONSTRAINT `fk_blogsec_blog` FOREIGN KEY (`blog_id`) REFERENCES `blogs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `blog_tag_relations`
--
ALTER TABLE `blog_tag_relations`
  ADD CONSTRAINT `fk_btr_blog` FOREIGN KEY (`blog_id`) REFERENCES `blogs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_btr_tag` FOREIGN KEY (`tag_id`) REFERENCES `blog_tags` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `enquiry_replies`
--
ALTER TABLE `enquiry_replies`
  ADD CONSTRAINT `fk_enqreply_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_enqreply_enq` FOREIGN KEY (`enquiry_id`) REFERENCES `enquiries` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `gallery`
--
ALTER TABLE `gallery`
  ADD CONSTRAINT `fk_gallery_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_categories`
--
ALTER TABLE `product_categories`
  ADD CONSTRAINT `product_categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_proj_cat` FOREIGN KEY (`category_id`) REFERENCES `project_categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_proj_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `project_categories`
--
ALTER TABLE `project_categories`
  ADD CONSTRAINT `fk_projcat_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `project_gallery`
--
ALTER TABLE `project_gallery`
  ADD CONSTRAINT `fk_projgal_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `project_tag_relations`
--
ALTER TABLE `project_tag_relations`
  ADD CONSTRAINT `fk_ptr_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ptr_tag` FOREIGN KEY (`tag_id`) REFERENCES `project_tags` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `project_videos`
--
ALTER TABLE `project_videos`
  ADD CONSTRAINT `fk_projvid_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `services`
--
ALTER TABLE `services`
  ADD CONSTRAINT `fk_service_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `service_benefits`
--
ALTER TABLE `service_benefits`
  ADD CONSTRAINT `fk_svcben_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `service_faqs`
--
ALTER TABLE `service_faqs`
  ADD CONSTRAINT `fk_svcfaq_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `service_gallery`
--
ALTER TABLE `service_gallery`
  ADD CONSTRAINT `fk_svcgal_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `service_sections`
--
ALTER TABLE `service_sections`
  ADD CONSTRAINT `fk_svcsec_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

--
-- Constraints for table `user_profiles`
--
ALTER TABLE `user_profiles`
  ADD CONSTRAINT `fk_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
