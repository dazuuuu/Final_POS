-- full_schema.sql — complete current schema: every table and column the app uses,
-- including the ones models used to create on first use, plus account activation keys.
-- Regenerated 2026-10-13 from a verified build. Installed by public/setup/.
SET FOREIGN_KEY_CHECKS = 0;
CREATE TABLE `app_updates` (
  `version` varchar(20) NOT NULL,
  `applied_by` int DEFAULT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `username` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `role` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `entity_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `entity_id` int DEFAULT NULL,
  `entity_label` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `action` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `changes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_tenant_time` (`tenant_id`,`created_at`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `blog_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '#667eea',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `fk_blogcat_user` (`created_by`),
  KEY `idx_slug` (`slug`),
  CONSTRAINT `fk_blogcat_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `blog_faqs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `blog_id` int NOT NULL,
  `question` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `answer` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_blog` (`blog_id`),
  CONSTRAINT `fk_blogfaq_blog` FOREIGN KEY (`blog_id`) REFERENCES `blogs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `blog_sections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `blog_id` int NOT NULL,
  `section_type` enum('text_only','text_image_left','text_image_right','image_gallery','video','youtube','code_block','quote') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'text_only',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `media_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `media_type` enum('image','video','youtube') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'image',
  `video_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_blog` (`blog_id`),
  KEY `idx_sort` (`sort_order`),
  CONSTRAINT `fk_blogsec_blog` FOREIGN KEY (`blog_id`) REFERENCES `blogs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `blog_tag_relations` (
  `blog_id` int NOT NULL,
  `tag_id` int NOT NULL,
  PRIMARY KEY (`blog_id`,`tag_id`),
  KEY `fk_btr_tag` (`tag_id`),
  CONSTRAINT `fk_btr_blog` FOREIGN KEY (`blog_id`) REFERENCES `blogs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_btr_tag` FOREIGN KEY (`tag_id`) REFERENCES `blog_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `blog_tags` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `blogs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `excerpt` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `featured_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `author_id` int NOT NULL,
  `status` enum('draft','published','archived') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'draft',
  `view_count` int DEFAULT '0',
  `is_featured` tinyint(1) DEFAULT '0',
  `meta_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `meta_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `meta_keywords` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_status` (`status`),
  KEY `idx_slug` (`slug`),
  KEY `idx_author` (`author_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_published` (`published_at`),
  KEY `idx_featured` (`is_featured`),
  CONSTRAINT `fk_blog_cat` FOREIGN KEY (`category_id`) REFERENCES `blog_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_blog_user` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `book_attributes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `type` enum('grade','publisher','author','edition','brand') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attr_tenant_type_name` (`tenant_id`,`type`,`name`),
  KEY `idx_attr_tenant_type` (`tenant_id`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `cart_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cart_session_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cart_product` (`cart_session_id`,`product_id`),
  KEY `idx_cart` (`cart_session_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `cart_items_ibfk_1` FOREIGN KEY (`cart_session_id`) REFERENCES `cart_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `cart_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` varchar(255) NOT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `cart_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `catalogue_feedback` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `product_id` int NOT NULL,
  `kind` enum('like','comment') NOT NULL,
  `visitor_token` char(32) NOT NULL,
  `name` varchar(60) DEFAULT NULL,
  `body` varchar(500) DEFAULT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_catfb_product` (`tenant_id`,`product_id`,`kind`),
  KEY `idx_catfb_visitor` (`tenant_id`,`visitor_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type` enum('subject','stationery') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'subject',
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','draft') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_tenant_type_name` (`tenant_id`,`type`,`name`),
  KEY `idx_cat_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `company_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phone` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `location` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customers_tenant_name` (`tenant_id`,`name`),
  KEY `idx_customers_tenant_email` (`tenant_id`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `device_serials` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `warehouse_item_id` int DEFAULT NULL,
  `serial` varchar(64) NOT NULL,
  `serial2` varchar(64) DEFAULT NULL,
  `status` enum('warehouse','in_stock','sold','in_repair') NOT NULL DEFAULT 'in_stock',
  `warranty_months` int DEFAULT NULL,
  `order_id` int DEFAULT NULL,
  `order_item_id` int DEFAULT NULL,
  `sold_at` datetime DEFAULT NULL,
  `warranty_ends_at` date DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `customer_phone` varchar(40) DEFAULT NULL,
  `return_count` int NOT NULL DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `color` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_serial` (`account_id`,`serial`),
  KEY `idx_device_serial2` (`account_id`,`serial2`),
  KEY `idx_device_product` (`tenant_id`,`product_id`,`status`),
  KEY `idx_device_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `enquiries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `service` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` enum('new','read','contacted','closed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'new',
  `priority` enum('low','medium','high') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'medium',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `contacted_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_email` (`email`),
  KEY `idx_created` (`created_at`),
  FULLTEXT KEY `idx_search` (`name`,`email`,`message`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `enquiry_replies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `enquiry_id` int NOT NULL,
  `admin_id` int NOT NULL,
  `reply` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_enqreply_admin` (`admin_id`),
  KEY `idx_enquiry` (`enquiry_id`),
  CONSTRAINT `fk_enqreply_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_enqreply_enq` FOREIGN KEY (`enquiry_id`) REFERENCES `enquiries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `finance_expenses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `title` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `category` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'General',
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','mpesa','bank') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'cash',
  `expense_date` date NOT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `receipt_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_finance_expenses_tenant` (`tenant_id`,`expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `gallery` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `media_type` enum('image','video') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'image',
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `thumbnail_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `video_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `video_embed_code` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tags` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `is_featured` tinyint(1) DEFAULT '0',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'active',
  `view_count` int DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_gallery_user` (`created_by`),
  KEY `idx_status` (`status`),
  KEY `idx_media_type` (`media_type`),
  KEY `idx_sort` (`sort_order`),
  CONSTRAINT `fk_gallery_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `gallery_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `held_order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `held_order_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_helditem_held` (`held_order_id`),
  KEY `idx_helditem_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `held_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `customer_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `customer_id` int DEFAULT NULL,
  `sale_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'retail',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `staff_id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_held_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `hero_slides` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `caption` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hero_active_order` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `attempt_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_time` (`email`,`attempt_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `login_otps` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `tenant_id` int DEFAULT NULL,
  `code_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `purpose` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'login_2fa',
  `attempts` tinyint NOT NULL DEFAULT '0',
  `max_attempts` tinyint NOT NULL DEFAULT '5',
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_otp_user_purpose` (`user_id`,`purpose`),
  KEY `idx_otp_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `manual_credit_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `credit_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` enum('cash','mpesa','bank') NOT NULL DEFAULT 'cash',
  `received_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mcp_credit` (`credit_id`),
  KEY `idx_mcp_tenant` (`tenant_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `manual_credits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `customer_name` varchar(160) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` varchar(255) DEFAULT NULL,
  `credit_date` date NOT NULL,
  `status` enum('open','paid') NOT NULL DEFAULT 'open',
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mc_tenant` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `order_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `price_type` enum('retail','wholesale') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'retail',
  `quantity` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `added_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orderitem_order` (`order_id`),
  KEY `idx_orderitem_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `order_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `order_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(10) NOT NULL,
  `cash_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `mpesa_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_tendered` decimal(12,2) DEFAULT NULL,
  `change_due` decimal(12,2) DEFAULT NULL,
  `received_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orderpay_order` (`tenant_id`,`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `order_return_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `return_id` int NOT NULL,
  `order_item_id` int DEFAULT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(160) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_orderretitem_return` (`tenant_id`,`return_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `order_returns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `order_id` int NOT NULL,
  `return_value` decimal(12,2) NOT NULL DEFAULT '0.00',
  `refund_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `refund_method` varchar(10) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `processed_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orderret_order` (`tenant_id`,`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `table_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `customer_id` int DEFAULT NULL,
  `customer_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `customer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `customer_company` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `customer_location` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `channel` enum('walkin','tab') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'tab',
  `sale_type` enum('retail','wholesale') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'retail',
  `opened_by` int NOT NULL,
  `receipt_number` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('open','paid','void') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'open',
  `edit_count` int NOT NULL DEFAULT '0',
  `edited_at` datetime DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','mpesa','split') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cash_amount` decimal(12,2) DEFAULT NULL,
  `mpesa_amount` decimal(12,2) DEFAULT NULL,
  `amount_tendered` decimal(12,2) DEFAULT NULL,
  `change_due` decimal(12,2) DEFAULT NULL,
  `paid_by` int DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `invoice_sent_at` datetime DEFAULT NULL,
  `delivery_note_sent_at` datetime DEFAULT NULL,
  `delivery_person` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `delivery_fee` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_receipt` (`tenant_id`,`receipt_number`),
  KEY `idx_order_tenant` (`tenant_id`),
  KEY `idx_order_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `page_headers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `page_key` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtitle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `page_key` (`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `product_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text,
  `image_path` varchar(500) DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `parent_id` (`parent_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_slug` (`slug`),
  CONSTRAINT `product_categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `product_type` enum('book','stationery') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'book',
  `category_id` int DEFAULT NULL,
  `subcategory_id` int DEFAULT NULL,
  `grade_id` int DEFAULT NULL,
  `publisher_id` int DEFAULT NULL,
  `author_id` int DEFAULT NULL,
  `edition_id` int DEFAULT NULL,
  `brand_id` int DEFAULT NULL,
  `barcode` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'piece',
  `size_value` decimal(10,2) DEFAULT NULL,
  `size_unit` enum('ml','l') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `buying_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `selling_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `wholesale_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `retail_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `offer_price` decimal(12,2) DEFAULT NULL,
  `offer_starts_at` datetime DEFAULT NULL,
  `offer_ends_at` datetime DEFAULT NULL,
  `colors` json DEFAULT NULL,
  `sizes` json DEFAULT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `low_stock_threshold` int NOT NULL DEFAULT '10',
  `low_stock_notified_at` datetime DEFAULT NULL,
  `status` enum('active','draft','archived') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_serialized` tinyint(1) NOT NULL DEFAULT '0',
  `warranty_months` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prod_tenant_barcode` (`tenant_id`,`barcode`),
  KEY `idx_prod_tenant` (`tenant_id`),
  KEY `idx_prod_cat` (`category_id`),
  KEY `idx_prod_subcat` (`subcategory_id`),
  KEY `idx_prod_supplier` (`supplier_id`),
  KEY `idx_prod_status` (`status`),
  KEY `idx_prod_lowstock` (`tenant_id`,`quantity`),
  KEY `idx_prod_grade` (`grade_id`),
  KEY `idx_prod_publisher` (`publisher_id`),
  KEY `idx_prod_author` (`author_id`),
  KEY `idx_prod_edition` (`edition_id`),
  KEY `idx_prod_brand` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `project_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `category_slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `category_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_slug` (`category_slug`),
  KEY `fk_projcat_user` (`created_by`),
  KEY `idx_slug` (`category_slug`),
  CONSTRAINT `fk_projcat_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `project_gallery` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `image_title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `image_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_sort` (`sort_order`),
  CONSTRAINT `fk_projgal_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `project_tag_relations` (
  `project_id` int NOT NULL,
  `tag_id` int NOT NULL,
  PRIMARY KEY (`project_id`,`tag_id`),
  KEY `fk_ptr_tag` (`tag_id`),
  CONSTRAINT `fk_ptr_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ptr_tag` FOREIGN KEY (`tag_id`) REFERENCES `project_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `project_tags` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tag_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `tag_slug` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tag_name` (`tag_name`),
  UNIQUE KEY `tag_slug` (`tag_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `project_videos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `video_title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `video_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `video_embed_code` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `video_type` enum('youtube','vimeo','local','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'youtube',
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  CONSTRAINT `fk_projvid_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `projects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `small_title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `major_title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `project_slug` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `cover_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('draft','published','archived') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'draft',
  `view_count` int DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_slug` (`project_slug`),
  KEY `fk_proj_user` (`created_by`),
  KEY `idx_category` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_slug` (`project_slug`),
  CONSTRAINT `fk_proj_cat` FOREIGN KEY (`category_id`) REFERENCES `project_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_proj_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `purchase_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `name` varchar(190) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `package_type` varchar(30) NOT NULL DEFAULT 'carton',
  `packages` decimal(12,2) NOT NULL,
  `units_per_package` int NOT NULL DEFAULT '1',
  `package_price` decimal(12,2) DEFAULT NULL,
  `units_total` decimal(14,2) NOT NULL,
  `units_remaining` decimal(14,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_item` (`tenant_id`,`units_remaining`),
  KEY `idx_purchase_item_p` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `purchase_transfers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `target_tenant_id` int NOT NULL,
  `move_mode` enum('package','unit') NOT NULL,
  `sell_mode` enum('package','unit') NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `units` decimal(14,2) NOT NULL,
  `product_id` int NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `retail_price` decimal(12,2) DEFAULT NULL,
  `wholesale_price` decimal(12,2) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_transfer` (`tenant_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `supplier` varchar(150) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `total_cost` decimal(14,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(10) DEFAULT NULL,
  `expense_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_tenant` (`tenant_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rest_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(80) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rc` (`tenant_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rest_dish_ingredients` (
  `dish_id` int NOT NULL,
  `ingredient_id` int NOT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT '1.000',
  `measure` varchar(10) NOT NULL DEFAULT '',
  `base_quantity` decimal(12,4) DEFAULT NULL,
  `line_price` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`dish_id`,`ingredient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rest_dishes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(120) NOT NULL,
  `category_id` int DEFAULT NULL,
  `category` varchar(80) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `available_from` time DEFAULT NULL,
  `available_to` time DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rd_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rest_ingredients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(120) NOT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT '0.000',
  `unit` varchar(10) NOT NULL DEFAULT 'pcs',
  `pieces_per_unit` decimal(12,3) DEFAULT NULL,
  `cost_price` decimal(12,2) DEFAULT NULL,
  `charge_price` decimal(12,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ri_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rest_order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `item_type` enum('dish','counter') NOT NULL,
  `ref_id` int NOT NULL,
  `name` varchar(160) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT '1.000',
  `removed` json DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `line_total` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_roi_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rest_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `order_no` varchar(20) NOT NULL,
  `source` enum('pos','online') NOT NULL DEFAULT 'pos',
  `order_type` enum('dine_in','takeaway','delivery') NOT NULL DEFAULT 'dine_in',
  `table_label` varchar(40) DEFAULT NULL,
  `customer_name` varchar(120) DEFAULT NULL,
  `customer_phone` varchar(30) DEFAULT NULL,
  `delivery_address` varchar(255) DEFAULT NULL,
  `client_ip` varchar(45) DEFAULT NULL,
  `status` enum('held','open','paid','cancelled') NOT NULL DEFAULT 'open',
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `change_due` decimal(12,2) NOT NULL DEFAULT '0.00',
  `user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `paid_at` datetime DEFAULT NULL,
  `paid_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ro_tenant` (`tenant_id`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rest_outlet_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `outlet` enum('counter','kitchen') NOT NULL,
  `stock_id` int NOT NULL,
  `type_name` varchar(120) NOT NULL DEFAULT '',
  `quantity` decimal(12,3) NOT NULL DEFAULT '0.000',
  `selling_price` decimal(12,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roi` (`tenant_id`,`outlet`,`stock_id`,`type_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rest_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `order_id` int NOT NULL,
  `method` varchar(20) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reference` varchar(60) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rp_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rest_settings` (
  `tenant_id` int NOT NULL,
  `public_token` varchar(32) NOT NULL,
  `online_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `allow_dine_in` tinyint(1) NOT NULL DEFAULT '1',
  `allow_takeaway` tinyint(1) NOT NULL DEFAULT '1',
  `allow_delivery` tinyint(1) NOT NULL DEFAULT '1',
  `notice` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`),
  UNIQUE KEY `uq_rs_token` (`public_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rest_stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(120) NOT NULL,
  `unit` varchar(10) NOT NULL DEFAULT 'pcs',
  `pieces_per_unit` decimal(12,3) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT '0.000',
  `supplier_id` int DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `cost_price` decimal(12,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rs_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rest_stock_receipts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `stock_id` int NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `supplier_id` int DEFAULT NULL,
  `cost_price` decimal(12,2) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rsr_stock` (`tenant_id`,`stock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `rest_transfers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `stock_id` int NOT NULL,
  `outlet` enum('counter','kitchen') NOT NULL,
  `store_quantity` decimal(12,3) NOT NULL,
  `breakdown` json DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rt_tenant` (`tenant_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `scope` enum('platform','tenant') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'tenant',
  `capabilities` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `sale_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `sale_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `unit` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'piece',
  `unit_price` decimal(12,2) NOT NULL,
  `price_type` enum('retail','wholesale') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'retail',
  `unit_cost` decimal(12,2) NOT NULL DEFAULT '0.00',
  `quantity` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item_sale` (`sale_id`),
  KEY `idx_item_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `sale_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `sale_id` int NOT NULL,
  `staff_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'cash',
  `note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sale` (`sale_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `sales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `staff_id` int NOT NULL,
  `sale_type` enum('retail','wholesale') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'retail',
  `receipt_number` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `payment_method` enum('cash','mpesa','split','credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'cash',
  `mpesa_channel` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_due` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_given` decimal(12,2) DEFAULT NULL,
  `change_given` decimal(12,2) DEFAULT NULL,
  `cash_amount` decimal(12,2) DEFAULT NULL,
  `mpesa_amount` decimal(12,2) DEFAULT NULL,
  `customer_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `customer_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `customer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('completed','voided') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'completed',
  `edit_count` int NOT NULL DEFAULT '0',
  `edited_at` datetime DEFAULT NULL,
  `payment_status` enum('pending','paid','part_paid','credit','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'paid',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sale_receipt` (`tenant_id`,`receipt_number`),
  KEY `idx_sale_tenant` (`tenant_id`),
  KEY `idx_sale_staff` (`staff_id`),
  KEY `idx_sale_created` (`tenant_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `saved_for_later` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cart_session_id` int NOT NULL,
  `product_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_saved` (`cart_session_id`,`product_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `saved_for_later_ibfk_1` FOREIGN KEY (`cart_session_id`) REFERENCES `cart_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `saved_for_later_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `service_benefits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `benefit_title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `benefit_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `icon_class` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_service` (`service_id`),
  CONSTRAINT `fk_svcben_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `service_faqs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `question` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `answer` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_service` (`service_id`),
  CONSTRAINT `fk_svcfaq_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `service_gallery` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `image_title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `image_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_service` (`service_id`),
  KEY `idx_sort` (`sort_order`),
  CONSTRAINT `fk_svcgal_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `service_sections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `section_type` enum('text_only','text_image_left','text_image_right','image_gallery','video') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'text_only',
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `media_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `media_type` enum('image','video','youtube','vimeo') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'image',
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_service` (`service_id`),
  KEY `idx_sort` (`sort_order`),
  CONSTRAINT `fk_svcsec_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `services` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `short_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `cover_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('draft','published','archived') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'draft',
  `view_count` int DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_status` (`status`),
  KEY `idx_slug` (`slug`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_service_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `shop_transfer_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transfer_id` int NOT NULL,
  `from_product_id` int NOT NULL,
  `to_product_id` int NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `buying_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `serials` text,
  PRIMARY KEY (`id`),
  KEY `idx_shop_transfer_item` (`transfer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `shop_transfers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `from_tenant_id` int NOT NULL,
  `to_tenant_id` int NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `item_count` int NOT NULL DEFAULT '0',
  `total_qty` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_value` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shop_transfer_from` (`from_tenant_id`,`created_at`),
  KEY `idx_shop_transfer_to` (`to_tenant_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `site_settings` (
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `staff_attendance_settings` (
  `tenant_id` int NOT NULL,
  `clock_in_time` time NOT NULL DEFAULT '08:00:00',
  `clock_out_time` time NOT NULL DEFAULT '18:00:00',
  `late_grace_minutes` int NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `staff_reclock_authorizations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `user_id` int NOT NULL,
  `authorized_by` int NOT NULL,
  `authorized_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reclock_user` (`tenant_id`,`user_id`,`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `staff_time_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `user_id` int NOT NULL,
  `clock_in_at` datetime NOT NULL,
  `clock_out_at` datetime DEFAULT NULL,
  `auto_closed` tinyint(1) NOT NULL DEFAULT '0',
  `late_clock_in` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_timelog_tenant` (`tenant_id`),
  KEY `idx_timelog_user_time` (`user_id`,`clock_in_at`),
  KEY `idx_timelog_open` (`user_id`,`clock_out_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `stock_intake_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `stock_intake_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `buying_price` decimal(12,2) NOT NULL,
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_intakeitem_intake` (`stock_intake_id`),
  KEY `idx_intakeitem_tenant` (`tenant_id`),
  KEY `idx_intakeitem_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `stock_intakes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `supplier_id` int DEFAULT NULL,
  `staff_id` int NOT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_due` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('paid','part_paid','credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'paid',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_intake_tenant` (`tenant_id`),
  KEY `idx_intake_supplier` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `store_cart` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_product` (`user_id`,`product_id`),
  KEY `product_id` (`product_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `store_cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `store_cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `store_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `store_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text,
  `image_path` varchar(500) DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_active` (`is_active`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `store_order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `parcel_id` varchar(32) NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `quantity` int NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `fulfillment_status` enum('processing','ready_for_pickup','out_for_delivery','picked_up','delivered','arrived','cancelled') NOT NULL DEFAULT 'processing',
  `fulfilled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parcel_id` (`parcel_id`),
  KEY `idx_items_order` (`order_id`),
  KEY `idx_items_parcel` (`parcel_id`),
  KEY `idx_items_status` (`fulfillment_status`),
  CONSTRAINT `fk_items_order_v2` FOREIGN KEY (`order_id`) REFERENCES `store_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `store_order_items_backup` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `store_order_items_backup_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `store_orders_backup` (`id`) ON DELETE CASCADE,
  CONSTRAINT `store_order_items_backup_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `store_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `store_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_number` varchar(32) NOT NULL,
  `user_id` int NOT NULL,
  `customer_name` varchar(150) NOT NULL,
  `contact_phone` varchar(20) NOT NULL,
  `fulfillment_method` enum('walkin','delivery') NOT NULL DEFAULT 'walkin',
  `pickup_location` varchar(255) DEFAULT NULL,
  `delivery_notes` varchar(500) DEFAULT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'KES',
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  `payment_method` varchar(20) NOT NULL DEFAULT 'mpesa',
  `mpesa_merchant_request_id` varchar(64) DEFAULT NULL,
  `mpesa_checkout_request_id` varchar(64) DEFAULT NULL,
  `mpesa_receipt` varchar(32) DEFAULT NULL,
  `mpesa_phone` varchar(20) DEFAULT NULL,
  `mpesa_payer_name` varchar(150) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `idx_orders_user` (`user_id`),
  KEY `idx_orders_pay` (`payment_status`),
  KEY `idx_orders_checkout` (`mpesa_checkout_request_id`),
  CONSTRAINT `fk_orders_user_v2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `store_orders_backup` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL,
  `user_id` int NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','processing','completed','cancelled') DEFAULT 'pending',
  `payment_status` enum('pending','paid','failed') DEFAULT 'pending',
  `shipping_address` text,
  `billing_address` text,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_order_number` (`order_number`),
  CONSTRAINT `store_orders_backup_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `store_products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text,
  `price` decimal(10,2) NOT NULL,
  `compare_price` decimal(10,2) DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `stock_quantity` int DEFAULT '0',
  `category_id` int DEFAULT NULL,
  `featured_image` varchar(500) DEFAULT NULL,
  `gallery_images` text,
  `status` enum('active','inactive','draft') DEFAULT 'draft',
  `is_featured` tinyint(1) DEFAULT '0',
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text,
  `sort_order` int DEFAULT '0',
  `view_count` int DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `created_by` (`created_by`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category_id`),
  KEY `idx_price` (`price`),
  FULLTEXT KEY `idx_search` (`name`,`description`),
  CONSTRAINT `store_products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `store_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `store_products_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `store_saved_for_later` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `product_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_product_saved` (`user_id`,`product_id`),
  KEY `product_id` (`product_id`),
  KEY `idx_user_saved` (`user_id`),
  CONSTRAINT `store_saved_for_later_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `store_saved_for_later_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `store_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `subcategories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `category_id` int NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('active','draft') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subcat_tenant_cat_name` (`tenant_id`,`category_id`,`name`),
  KEY `idx_subcat_tenant` (`tenant_id`),
  KEY `idx_subcat_cat` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `subscription_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `price_weekly` decimal(10,2) DEFAULT NULL,
  `price_biweekly` decimal(10,2) DEFAULT NULL,
  `price_monthly` decimal(10,2) DEFAULT NULL,
  `max_staff` int DEFAULT NULL,
  `max_products` int DEFAULT NULL,
  `features` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_public` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `subscription_stk` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `user_id` int NOT NULL,
  `subscription_id` int DEFAULT NULL,
  `plan_id` int NOT NULL,
  `billing_interval` enum('weekly','biweekly','monthly') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `phone` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `checkout_request_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `merchant_request_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('pending','success','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `result_code` int DEFAULT NULL,
  `result_desc` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mpesa_receipt` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stk_checkout` (`checkout_request_id`),
  KEY `idx_stk_tenant` (`tenant_id`),
  KEY `idx_stk_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `plan_id` int NOT NULL,
  `billing_interval` enum('weekly','biweekly','monthly') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'monthly',
  `amount` decimal(10,2) NOT NULL,
  `status` enum('trialing','active','past_due','suspended','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'trialing',
  `current_period_start` datetime DEFAULT NULL,
  `current_period_end` datetime DEFAULT NULL,
  `grace_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sub_tenant` (`tenant_id`),
  KEY `idx_sub_status` (`status`),
  KEY `idx_sub_period_end` (`current_period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `suppliers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supplier_tenant_name` (`tenant_id`,`name`),
  KEY `idx_supplier_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `support_ticket_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `author` enum('user','support') COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `author_name` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `body` text COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stm_ticket` (`ticket_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `support_tickets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `user_name` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_role` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `subject` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `category` varchar(40) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'general',
  `priority` enum('low','normal','high','urgent') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'normal',
  `status` enum('open','in_progress','waiting','resolved','closed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'open',
  `page_url` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `unread_by_user` tinyint(1) NOT NULL DEFAULT 0,
  `unread_by_support` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_st_account` (`account_id`,`status`),
  KEY `idx_st_status` (`status`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `svc_commission_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `service_id` int DEFAULT NULL,
  `weekday` tinyint DEFAULT NULL,
  `staff_id` int DEFAULT NULL,
  `pct` decimal(5,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_svc_rule_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `svc_service_shops` (
  `service_id` int NOT NULL,
  `tenant_id` int NOT NULL,
  PRIMARY KEY (`service_id`,`tenant_id`),
  KEY `idx_svc_shop` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `svc_services` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `duration_min` int DEFAULT NULL,
  `commission_pct` decimal(5,2) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_svc_account` (`account_id`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `svc_ticket_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `service_id` int DEFAULT NULL,
  `service_name` varchar(150) NOT NULL,
  `kind` enum('service','product') NOT NULL DEFAULT 'service',
  `product_id` int DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT '1.00',
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `staff_id` int DEFAULT NULL,
  `status` enum('pending','done') NOT NULL DEFAULT 'pending',
  `done_at` datetime DEFAULT NULL,
  `commission_pct` decimal(5,2) DEFAULT NULL,
  `commission_amount` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_svc_item_ticket` (`ticket_id`),
  KEY `idx_svc_item_staff` (`staff_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `svc_tickets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `number` varchar(20) NOT NULL DEFAULT '',
  `customer_id` int DEFAULT NULL,
  `customer_name` varchar(150) NOT NULL,
  `customer_phone` varchar(40) DEFAULT NULL,
  `customer_email` varchar(150) DEFAULT NULL,
  `status` enum('booked','open','paid','cancelled') NOT NULL DEFAULT 'open',
  `scheduled_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(10) DEFAULT NULL,
  `cash_amount` decimal(12,2) DEFAULT NULL,
  `mpesa_amount` decimal(12,2) DEFAULT NULL,
  `amount_tendered` decimal(12,2) DEFAULT NULL,
  `change_due` decimal(12,2) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `paid_by` int DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_svc_ticket_shop` (`tenant_id`,`status`,`created_at`),
  KEY `idx_svc_ticket_sched` (`tenant_id`,`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `tenant_packages` (
  `tenant_id` int NOT NULL,
  `package_key` varchar(64) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`,`package_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `tenants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `owner_user_id` int DEFAULT NULL,
  `status` enum('active','suspended','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `logo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `currency` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'KES',
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `receipt_footer` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `kra_pin` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `license_key` varchar(32) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `license_deadline` datetime DEFAULT NULL,
  `license_activated_at` datetime DEFAULT NULL,
  `license_requested_at` datetime DEFAULT NULL,
  `signup_completed_at` datetime DEFAULT NULL,
  `imported_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `parent_tenant_id` int DEFAULT NULL,
  `multi_shop` tinyint(1) NOT NULL DEFAULT '1',
  `product_fields` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_slug` (`slug`),
  KEY `idx_tenant_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `testimonials` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `customer_email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `customer_phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `customer_initial` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `rating` int DEFAULT '5',
  `testimonial_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `service_tag` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('pending','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `is_featured` tinyint(1) DEFAULT '0',
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `approved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_rating` (`rating`),
  KEY `idx_featured` (`is_featured`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `user_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `user_id` int NOT NULL,
  `capability` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `effect` enum('grant','revoke') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'grant',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_cap` (`user_id`,`capability`),
  KEY `idx_perm_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `user_profiles` (
  `user_id` int NOT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int DEFAULT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `pin_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `position` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `must_reset_password` tinyint(1) NOT NULL DEFAULT '0',
  `role_id` int NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `email_verified` tinyint(1) DEFAULT '0',
  `activation_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `activation_expires` datetime DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_users_tenant_email` (`tenant_id`,`email`),
  KEY `idx_email` (`email`),
  KEY `idx_username` (`username`),
  KEY `idx_users_tenant` (`tenant_id`),
  KEY `idx_users_activation` (`activation_token`),
  KEY `fk_users_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `warehouse_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(160) NOT NULL,
  `category_id` int DEFAULT NULL,
  `category` varchar(120) DEFAULT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'piece',
  `quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `buying_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_serialized` tinyint(1) NOT NULL DEFAULT '0',
  `warranty_months` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wh_tenant_name` (`tenant_id`,`name`),
  KEY `idx_wh_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `warehouse_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `item_id` int NOT NULL,
  `item_name` varchar(160) NOT NULL,
  `type` enum('in','transfer') NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `buying_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `target_tenant_id` int DEFAULT NULL,
  `product_id` int DEFAULT NULL,
  `retail_price` decimal(12,2) DEFAULT NULL,
  `wholesale_price` decimal(12,2) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_whm_tenant` (`tenant_id`,`created_at`),
  KEY `idx_whm_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `warranty_claims` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `serial_id` int NOT NULL,
  `issue` text NOT NULL,
  `under_warranty` tinyint(1) NOT NULL DEFAULT '0',
  `covered` tinyint(1) NOT NULL DEFAULT '0',
  `charge_reason` varchar(255) DEFAULT NULL,
  `repair_fee` decimal(12,2) NOT NULL DEFAULT '0.00',
  `fee_paid` tinyint(1) NOT NULL DEFAULT '0',
  `payment_method` varchar(10) DEFAULT NULL,
  `status` enum('received','repairing','ready','collected') NOT NULL DEFAULT 'received',
  `resolution` varchar(255) DEFAULT NULL,
  `received_by` int DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `collected_by` int DEFAULT NULL,
  `collected_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_claim_serial` (`serial_id`),
  KEY `idx_claim_tenant` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `page_headers` VALUES (5,'services','Our Services','Comprehensive digital solutions tailored to elevate your business.',NULL,'2026-10-12 13:38:42');
INSERT INTO `page_headers` VALUES (6,'projects','Our Projects','A selection of the work we are proud of.',NULL,'2026-10-12 13:38:42');
INSERT INTO `page_headers` VALUES (7,'blogs','Our Blog','Insights, ideas and updates from the team.',NULL,'2026-10-12 13:38:42');
INSERT INTO `page_headers` VALUES (8,'contact','Get in Touch','We would love to hear about your project.',NULL,'2026-10-12 13:38:42');
INSERT INTO `project_categories` VALUES (6,'Web Development','web-development','Web development projects including websites and web applications',NULL,'2026-10-12 13:38:42','2026-10-12 13:38:42');
INSERT INTO `project_categories` VALUES (7,'Mobile Apps','mobile-apps','Mobile application development projects',NULL,'2026-10-12 13:38:42','2026-10-12 13:38:42');
INSERT INTO `project_categories` VALUES (8,'UI/UX Design','ui-ux-design','User interface and experience design projects',NULL,'2026-10-12 13:38:42','2026-10-12 13:38:42');
INSERT INTO `project_categories` VALUES (9,'E-commerce','ecommerce','E-commerce platform and online store projects',NULL,'2026-10-12 13:38:42','2026-10-12 13:38:42');
INSERT INTO `project_categories` VALUES (10,'Custom Software','custom-software','Custom software development projects',NULL,'2026-10-12 13:38:42','2026-10-12 13:38:42');
INSERT INTO `roles` VALUES (1,'superadmin','platform','[\"*\"]','2026-06-17 16:29:32');
INSERT INTO `roles` VALUES (2,'admin','tenant','[\"inventory.view\", \"inventory.edit\", \"stock.enter\", \"sales.record\", \"sales.view\", \"customers.manage\", \"catalogue.send\", \"reports.view\", \"staff.manage\", \"settings.manage\", \"billing.manage\"]','2026-06-17 16:29:32');
INSERT INTO `roles` VALUES (3,'user','tenant','[\"inventory.view\", \"sales.record\", \"sales.view\"]','2026-06-17 16:29:32');
INSERT INTO `roles` VALUES (4,'platform_admin','platform','[\"*\"]','2026-06-20 05:10:12');
INSERT INTO `roles` VALUES (5,'tenant_owner','tenant','[\"inventory.view\", \"inventory.edit\", \"stock.enter\", \"sales.record\", \"sales.view\", \"payments.process\", \"customers.manage\", \"catalogue.send\", \"reports.view\", \"staff.manage\", \"settings.manage\", \"billing.manage\"]','2026-06-20 05:10:14');
INSERT INTO `roles` VALUES (6,'staff','tenant','[\"inventory.view\", \"sales.record\", \"sales.view\"]','2026-06-20 05:10:16');
INSERT INTO `site_settings` VALUES ('logo_alt','Ismano','2026-10-12 13:38:42');
INSERT INTO `site_settings` VALUES ('logo_path',NULL,'2026-10-12 13:38:42');
INSERT INTO `site_settings` VALUES ('site_name','Ismano','2026-10-12 13:38:42');
INSERT INTO `store_categories` VALUES (1,'Electronics','electronics','Electronic devices and gadgets',NULL,1,1,'2026-10-12 13:38:42','2026-10-12 13:38:42');
INSERT INTO `store_categories` VALUES (2,'Clothing','clothing','Fashion and apparel',NULL,2,1,'2026-10-12 13:38:42','2026-10-12 13:38:42');
INSERT INTO `store_categories` VALUES (3,'Books','books','Books and publications',NULL,3,1,'2026-10-12 13:38:42','2026-10-12 13:38:42');
INSERT INTO `store_categories` VALUES (4,'Home & Living','home-living','Home decor and living essentials',NULL,4,1,'2026-10-12 13:38:42','2026-10-12 13:38:42');
INSERT INTO `store_categories` VALUES (5,'Sports','sports','Sports equipment and gear',NULL,5,1,'2026-10-12 13:38:42','2026-10-12 13:38:42');
INSERT INTO `subscription_plans` VALUES (2,'Standard','Everything you need to run your shop',250.00,500.00,1000.00,3,200,NULL,1,1,'2026-10-12 13:38:42');
INSERT INTO `subscription_plans` VALUES (3,'Pro','Growing shops, more staff/stock',700.00,1300.00,2500.00,15,NULL,NULL,0,0,'2026-10-12 13:38:42');
INSERT INTO `subscription_plans` VALUES (4,'Test (2 weeks)','Temporary test plan — remove before launch',NULL,10.00,NULL,NULL,NULL,NULL,1,0,'2026-10-12 13:38:42');
SET FOREIGN_KEY_CHECKS = 1;
