<?php
// public/auth/logout.php
require_once __DIR__ . '/../../app/app.php';
// Support previewing an account: end the preview, keep the support login.
if (!empty($_SESSION['support_preview'])) {
    header('Location: ' . public_url('support/accounts/preview.php?exit=1'));
    exit;
}
// Landing-page demo: delete the demo shop and go back to the landing page.
if (Demo::active()) {
    Demo::end();
    header('Location: ' . public_url('?demo=done#demo'));
    exit;
}
SingleSession::release(Database::pdo());
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: ' . public_url('auth/login.php'));
exit;