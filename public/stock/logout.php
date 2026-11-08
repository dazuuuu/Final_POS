<?php
// public/stock/logout.php — sign out of Stock Control.
require_once __DIR__ . '/_boot.php';
if (!empty($_SESSION['support_preview'])) { header('Location: ' . public_url('support/accounts/preview.php?exit=1')); exit; } // previewing: back to support
if (class_exists('UserSessions')) { try { UserSessions::end('logout'); } catch (\Throwable $e) {} }
foreach (array_keys($_SESSION) as $k) unset($_SESSION[$k]);
session_regenerate_id(true);
stock_go('login.php', 'You are signed out.');
