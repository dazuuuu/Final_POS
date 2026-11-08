<?php
// public/stock/forgot.php — forgot password: email a code (step 1), then the
// code + a new password (step 2, ?step=2).
require_once __DIR__ . '/_boot.php';

$pdo = Database::pdo();
$otp = new OtpService($pdo);
$error = $notice = '';
$step2 = !empty($_SESSION['stock_reset']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$step2) {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $st = $pdo->prepare('SELECT u.id, u.email, u.tenant_id FROM users u WHERE u.email = ? AND u.is_active = 1 LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();
    // Same answer whether or not the email exists.
    if ($u && $u['tenant_id'] && Features::isStockControl((int) $u['tenant_id'])) {
        $issue = $otp->issue((int) $u['id'], (int) $u['tenant_id'], 'stock_reset', $_SERVER['REMOTE_ADDR'] ?? null);
        if ($issue['ok']) {
            (new MailService())->send($u['email'], 'Reset your password — code ' . $issue['code'],
                '<p>Your ' . STOCK_NAME . ' password reset code is:</p><p style="font-size:28px;font-weight:700;letter-spacing:6px">' . $issue['code'] . '</p>'
                . '<p>It works for ' . OtpService::TTL_MINUTES . ' minutes. If you did not ask for this, ignore this email.</p>');
        }
        $_SESSION['stock_reset'] = ['id' => (int) $u['id'], 'at' => time()];
    } else {
        $_SESSION['stock_reset'] = ['id' => 0, 'at' => time()];
    }
    header('Location: ' . stock_url('forgot.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step2) {
    if (isset($_POST['restart'])) { unset($_SESSION['stock_reset']); stock_go('forgot.php'); }
    $r = $_SESSION['stock_reset'];
    $pw = (string) ($_POST['password'] ?? '');
    if (strlen($pw) < 8) {
        $error = 'Choose a password of at least 8 characters.';
    } elseif ($pw !== (string) ($_POST['password2'] ?? '')) {
        $error = 'The two passwords are not the same.';
    } elseif (!$r['id']) {
        $error = OtpService::message('mismatch');
    } else {
        $res = $otp->verify((int) $r['id'], 'stock_reset', preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')));
        if (!$res['ok']) {
            $error = OtpService::message($res['reason']);
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ?, must_reset_password = 0 WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), (int) $r['id']]);
            unset($_SESSION['stock_reset']);
            stock_go('login.php', 'Password changed. Sign in with your new password.');
        }
    }
}

$title = 'Forgot password';
ob_start();
if (!$step2): ?>
<h2>Forgot password</h2>
<p class="sub">Enter your email. If it has access, we'll send a code to reset your password.</p>
<form method="post" novalidate>
  <label for="em">Email</label>
  <div class="fld"><i class="far fa-envelope"></i><input class="in" id="em" name="email" type="email" placeholder="you@company.com" autofocus required></div>
  <button class="btn">Send code</button>
</form>
<div class="alt"><a class="link" href="<?php echo stock_url('login.php'); ?>">Back to sign in</a></div>
<?php else: ?>
<h2>Choose a new password</h2>
<p class="sub">If your email has access, a 6-digit code is on its way. Enter it with your new password.</p>
<form method="post" novalidate>
  <label for="code">Code from the email</label>
  <div class="fld"><input class="in code" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus required></div>
  <label for="pw">New password</label>
  <div class="fld"><i class="fas fa-lock"></i><input class="in" id="pw" name="password" type="password" autocomplete="new-password" minlength="8" required></div>
  <label for="pw2">New password again</label>
  <div class="fld"><i class="fas fa-lock"></i><input class="in" id="pw2" name="password2" type="password" autocomplete="new-password" minlength="8" required></div>
  <button class="btn">Save new password</button>
</form>
<div class="alt"><form method="post"><button name="restart" value="1" class="link" style="background:none;border:0;padding:0;cursor:pointer;font:inherit">Use a different email</button></form>
  <a class="link" href="<?php echo stock_url('login.php'); ?>">Back to sign in</a></div>
<?php endif;
$content = ob_get_clean();
include __DIR__ . '/_auth.php';
