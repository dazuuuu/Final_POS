<?php
// public/shop.php — what domain/<slug> opens (see the .htaccess rewrites).
//   shop not claimed yet → its signup (business name pre-filled)
//   claimed              → opens the shop's own address (domain/<slug>/):
//                          its staff PIN screen, or the dashboard if signed in.
require_once __DIR__ . '/../app/app.php';

$slug = strtolower(trim((string) ($_GET['s'] ?? ''), '/'));
$acct = preg_match('/^[a-z0-9-]{1,150}$/', $slug) ? Signup::findBySlug($slug) : null;
if (!$acct) {
    http_response_code(404);
    $page_title = 'Shop not found';
    ob_start(); ?>
    <div class="auth-title">Shop not found</div>
    <div class="auth-sub">Check the address. New here? <a href="<?php echo public_url('auth/signup.php'); ?>">Set up your shop</a>.</div>
    <?php $content = ob_get_clean();
    include __DIR__ . '/templates/auth/layout.php';
    exit;
}
if (!$acct['owner_user_id']) {
    header('Location: ' . public_url('auth/signup.php?shop=' . urlencode($slug)));
    exit;
}
header('Location: ' . rtrim((string) BASE_URL, '/') . '/' . $slug . '/');
exit;
