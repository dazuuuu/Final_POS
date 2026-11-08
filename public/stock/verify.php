<?php
// public/stock/verify.php — sign-in step 2: the 6-digit code emailed to the person.
require_once __DIR__ . '/_boot.php';

$p = $_SESSION['stock_pending'] ?? null;
if (!$p || time() - (int) $p['at'] > 30 * 60) {
    unset($_SESSION['stock_pending']);
    stock_go('login.php');
}
$pdo = Database::pdo();
$otp = new OtpService($pdo);
$error = '';
$notice = 'We emailed a 6-digit code to ' . preg_replace('/(?<=.).(?=[^@]*@)/', '•', $p['email']) . '.';
if (!empty($_SESSION['stock_wait'])) {
    $wait = (int) $_SESSION['stock_wait'] - time();
    unset($_SESSION['stock_wait']);
    if ($wait > 0) $notice = 'A code was emailed to you less than a minute ago — use the newest one. If it does not work, press "Send a new code" in ' . $wait . ' seconds.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    $issue = $otp->issue((int) $p['id'], null, 'stock_2fa', $_SERVER['REMOTE_ADDR'] ?? null);
    if ($issue['ok']) {
        (new MailService())->send($p['email'], 'Your sign-in code: ' . $issue['code'],
            '<p>Your ' . STOCK_NAME . ' sign-in code is:</p><p style="font-size:28px;font-weight:700;letter-spacing:6px">' . $issue['code'] . '</p>');
        $notice = 'A new code is on its way.';
    } else {
        $error = OtpService::message($issue['reason']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
    $res = $otp->verify((int) $p['id'], 'stock_2fa', $code);
    if (!$res['ok']) {
        $error = OtpService::message($res['reason']) . (isset($res['remaining']) && $res['remaining'] > 0 ? ' (' . $res['remaining'] . ' tries left)' : '');
    } else {
        $st = $pdo->prepare('SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active = 1');
        $st->execute([(int) $p['id']]);
        $user = $st->fetch();
        if (!$user || !Features::isStockControl((int) $user['tenant_id'])) stock_go('login.php');
        session_regenerate_id(true);
        unset($_SESSION['stock_pending']);
        TenantContext::establish($pdo, $user);
        if ($user['role_name'] === 'tenant_owner') SingleSession::claim($pdo, $user);
        UserSessions::start();
        $_SESSION['username'] = $user['username'];
        $_SESSION['logged_in'] = true;
        $_SESSION['otp_verified'] = true;
        $_SESSION['stock_verified'] = true;
        $_SESSION['stock_home'] = (int) $user['tenant_id'];
        $__acc = Features::accountId((int) $user['tenant_id']);
        $__slug = (string) Database::pdo()->query('SELECT slug FROM tenants WHERE id = ' . $__acc)->fetchColumn();
        $_SESSION['stock_slug'] = $__slug;
        $_SESSION['shop_slug'] = $__slug; // keeps them on their organisation's address
        $_SESSION['stock_rights'] = $user['role_name'] === 'staff' ? StockControl::rightsOf((int) $user['id']) : [];
        $_SESSION['must_reset'] = !empty($user['must_reset_password']);
        stock_go($_SESSION['must_reset'] ? 'password.php' : '');
    }
}

$title = 'Enter your code';
ob_start();
?>
<h2>Check your email</h2>
<p class="sub">Enter the 6-digit code to finish signing in. It works for <?php echo OtpService::TTL_MINUTES; ?> minutes.</p>
<form method="post" novalidate>
  <label for="code">Sign-in code</label>
  <div class="fld"><input class="in code" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" autofocus required></div>
  <button class="btn">Sign in</button>
</form>
<div class="alt">
  <form method="post"><button name="resend" value="1" class="link" style="background:none;border:0;padding:0;cursor:pointer;font:inherit">Send a new code</button></form>
  <a class="link" href="<?php echo stock_url('logout.php'); ?>">Use another account</a>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_auth.php';
