-- Shop export · account #41 · 2026-11-27 20:15:07
-- Import it from Business → Backup & move on any Dazu POS account.

INSERT INTO `tenants` (`id`, `name`, `slug`, `owner_user_id`, `status`, `logo_path`, `currency`, `phone`, `address`, `receipt_footer`, `kra_pin`, `license_key`, `license_deadline`, `license_activated_at`, `license_requested_at`, `signup_completed_at`, `imported_at`, `created_at`, `updated_at`, `parent_tenant_id`, `multi_shop`, `product_fields`, `theme`, `demo_expires_at`, `locked_at`, `lock_reason`, `lock_allow`, `support_org`, `day_open`, `day_close`, `store_kind`, `timezone`) VALUES
('41', 'Services basics', 'services-basics', '77', 'active', NULL, 'KES', NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 12:41:07', NULL, '2026-11-25 16:45:50', NULL, '2026-11-17 12:41:06', '2026-11-25 16:45:50', NULL, '1', NULL, NULL, NULL, NULL, NULL, NULL, '1', NULL, NULL, NULL, 'Africa/Nairobi');

INSERT INTO `roles` (`id`, `role_name`, `scope`, `capabilities`, `created_at`) VALUES
('1', 'superadmin', 'platform', '[\"*\"]', '2026-06-17 16:29:32'),
('2', 'admin', 'tenant', '[\"inventory.view\", \"inventory.edit\", \"stock.enter\", \"sales.record\", \"sales.view\", \"customers.manage\", \"catalogue.send\", \"reports.view\", \"staff.manage\", \"settings.manage\", \"billing.manage\"]', '2026-06-17 16:29:32'),
('3', 'user', 'tenant', '[\"inventory.view\", \"sales.record\", \"sales.view\"]', '2026-06-17 16:29:32'),
('4', 'platform_admin', 'platform', '[\"*\"]', '2026-06-20 05:10:12'),
('5', 'tenant_owner', 'tenant', '[\"inventory.view\", \"inventory.edit\", \"stock.enter\", \"sales.record\", \"sales.view\", \"payments.process\", \"customers.manage\", \"catalogue.send\", \"reports.view\", \"staff.manage\", \"settings.manage\", \"billing.manage\"]', '2026-06-20 05:10:14'),
('6', 'staff', 'tenant', '[\"inventory.view\", \"sales.record\", \"sales.view\"]', '2026-06-20 05:10:16');






INSERT INTO `products` (`id`, `tenant_id`, `product_type`, `category_id`, `subcategory_id`, `grade_id`, `publisher_id`, `author_id`, `edition_id`, `brand_id`, `barcode`, `supplier_id`, `name`, `description`, `quantity`, `unit`, `size_value`, `size_unit`, `buying_price`, `selling_price`, `wholesale_price`, `retail_price`, `offer_price`, `offer_starts_at`, `offer_ends_at`, `colors`, `sizes`, `image_path`, `low_stock_threshold`, `low_stock_notified_at`, `status`, `created_at`, `updated_at`, `is_serialized`, `warranty_months`, `source_image`) VALUES
('5202', '41', 'book', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Black shampoo', NULL, '9.00', 'piece', NULL, NULL, '380.00', '500.00', '0.00', '500.00', NULL, NULL, NULL, NULL, NULL, NULL, '10', NULL, 'active', '2026-11-25 16:27:22', '2026-11-25 17:23:11', '0', NULL, NULL);

INSERT INTO `users` (`id`, `tenant_id`, `username`, `email`, `password_hash`, `pin_hash`, `position`, `must_reset_password`, `role_id`, `is_active`, `email_verified`, `activation_token`, `activation_expires`, `activated_at`, `created_at`, `updated_at`, `session_token`) VALUES
('80', '41', 'amoeybe', 'amoeybe@gmail.com', '$2y$10$j6zH0aq5QN387Gc5BiUXxeAk/k30xVfeE5C8wfmqYa.s7zyNHEwpC', NULL, NULL, '0', '5', '1', '1', NULL, NULL, '2026-11-25 16:54:17', '2026-11-25 16:49:28', '2026-11-27 19:30:04', '7620670920e176d65d541dd5e603bca8'),
('77', '41', 'Server', 'services@gmail.com', '$2y$10$95mAIMBrtmcUPD9goDGtlezOwWf6opfDhBA.NYNHyHN1u0EpfUu9W', NULL, NULL, '0', '5', '1', '1', NULL, NULL, '2026-11-17 12:41:06', '2026-11-17 12:41:06', '2026-11-25 16:49:51', NULL);

INSERT INTO `user_permissions` (`id`, `tenant_id`, `user_id`, `capability`, `effect`, `created_at`) VALUES
('35', '41', '80', 'inventory.edit', 'revoke', '2026-11-25 16:49:48'),
('36', '41', '80', 'stock.enter', 'revoke', '2026-11-25 16:49:48'),
('40', '41', '80', 'finance.view', 'grant', '2026-11-27 06:27:31');





INSERT INTO `stock_intakes` (`id`, `tenant_id`, `supplier_id`, `staff_id`, `notes`, `total_amount`, `amount_paid`, `amount_due`, `payment_status`, `created_at`) VALUES
('5387', '41', NULL, '77', NULL, '3800.00', '0.00', '3800.00', 'credit', '2026-11-25 16:27:22');

INSERT INTO `stock_intake_items` (`id`, `tenant_id`, `stock_intake_id`, `product_id`, `product_name`, `quantity`, `buying_price`, `remark`, `created_at`) VALUES
('6143', '41', '5387', '5202', 'Black shampoo', '10.00', '380.00', NULL, '2026-11-25 16:27:22');




INSERT INTO `orders` (`id`, `tenant_id`, `table_name`, `customer_id`, `customer_phone`, `customer_email`, `customer_company`, `customer_location`, `channel`, `sale_type`, `opened_by`, `receipt_number`, `status`, `edit_count`, `edited_at`, `subtotal`, `discount_amount`, `total`, `amount_paid`, `payment_method`, `cash_amount`, `mpesa_amount`, `amount_tendered`, `change_due`, `paid_by`, `paid_at`, `created_at`, `updated_at`, `invoice_sent_at`, `delivery_note_sent_at`, `delivery_person`, `delivery_fee`) VALUES
('5781', '41', 'Walk-in Customer', NULL, NULL, NULL, NULL, NULL, 'walkin', 'retail', '80', 'RCP-005781', 'paid', '0', NULL, '500.00', '0.00', '500.00', '500.00', 'mpesa', NULL, '500.00', NULL, NULL, '80', '2026-11-25 17:23:11', '2026-11-25 17:23:11', '2026-11-25 17:23:11', NULL, NULL, NULL, '0.00');

INSERT INTO `order_items` (`id`, `tenant_id`, `order_id`, `product_id`, `product_name`, `unit_price`, `price_type`, `quantity`, `line_total`, `added_by`, `created_at`) VALUES
('10594', '41', '5781', '5202', 'Black shampoo', '500.00', 'retail', '1.00', '500.00', '80', '2026-11-25 17:23:11');

INSERT INTO `order_payments` (`id`, `tenant_id`, `order_id`, `amount`, `method`, `cash_amount`, `mpesa_amount`, `amount_tendered`, `change_due`, `received_by`, `created_at`) VALUES
('2683', '41', '5781', '500.00', 'mpesa', '0.00', '500.00', NULL, NULL, '80', '2026-11-25 17:23:11');











INSERT INTO `svc_tickets` (`id`, `tenant_id`, `number`, `customer_id`, `customer_name`, `customer_phone`, `customer_email`, `status`, `scheduled_at`, `notes`, `subtotal`, `discount_amount`, `total`, `payment_method`, `cash_amount`, `mpesa_amount`, `amount_tendered`, `change_due`, `created_by`, `paid_by`, `paid_at`, `created_at`, `source`) VALUES
('10', '41', 'SVC-000010', NULL, 'Mary', NULL, NULL, 'open', NULL, NULL, '250.00', '0.00', '250.00', NULL, NULL, NULL, NULL, NULL, '77', NULL, NULL, '2026-11-17 14:51:02', 'desk');

INSERT INTO `svc_ticket_items` (`id`, `ticket_id`, `tenant_id`, `service_id`, `service_name`, `kind`, `product_id`, `quantity`, `price`, `staff_id`, `status`, `done_at`, `commission_pct`, `commission_amount`, `payout_id`, `staff_keeps_all`) VALUES
('13', '10', '41', '53', 'Clean shave', 'service', NULL, '1.00', '250.00', '77', 'done', '2026-11-25 16:58:34', NULL, NULL, NULL, '0');

























