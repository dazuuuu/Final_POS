<?php
// public/support/logout.php
require_once __DIR__ . '/../../app/app.php';
unset($_SESSION['support_auth'], $_SESSION['support_csrf'], $_SESSION['support_role'], $_SESSION['support_agent'],
      $_SESSION['support_email'], $_SESSION['support_name']);
header('Location: ' . public_url('support/login.php'));
