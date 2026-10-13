<?php
// public/support/logout.php
require_once __DIR__ . '/../../app/app.php';
unset($_SESSION['support_auth'], $_SESSION['support_csrf']);
header('Location: ' . public_url('support/login.php'));
