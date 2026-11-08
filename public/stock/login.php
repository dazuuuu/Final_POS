<?php
// public/stock/login.php — Stock Control sign-in, step 1: email + password.
// A 6-digit code is then emailed (step 2, verify.php) — always required.
require_once __DIR__ . '/_boot.php';

if (stock_signed_in()) stock_go('');

$pdo = Database::pdo();
$error = '';
$f = stock_flash();
$notice = $f && $f[1] !== 'err' ? $f[0] : '';
if ($f && $f[1] === 'err') $error = $f[0];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass = (string) ($_POST['password'] ?? '');
    $st = $pdo->prepare('SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();
    $ok = $u && $u['tenant_id'] && password_verify($pass, (string) $u['password_hash'])
        && in_array($u['role_name'], ['tenant_owner', 'staff'], true) && Features::isStockControl((int) $u['tenant_id']);
    // On an organisation's own sign-in page, only its own people.
    if ($ok && ($org = stock_org()) && Features::accountId((int) $u['tenant_id']) !== (int) $org['id']) $ok = false;
    if (!$ok) {
        usleep(400000); // slow down guessing
        $error = 'Wrong email or password.';
    } elseif (!(int) $u['is_active']) {
        $error = 'Your access has been turned off. Speak to your administrator.';
    } elseif (!(int) $u['email_verified']) {
        $error = 'Confirm your email first — open the link we emailed you.';
    } elseif (AccountLock::isLocked(Features::accountId((int) $u['tenant_id']))) {
        $error = 'This organisation\'s account is locked. Please contact support.';
    } else {
        $issue = (new OtpService($pdo))->issue((int) $u['id'], (int) $u['tenant_id'], 'stock_2fa', $_SERVER['REMOTE_ADDR'] ?? null);
        if ($issue['ok']) {
            $sent = (new MailService())->send($u['email'], 'Your sign-in code: ' . $issue['code'],
                '<p>Your ' . STOCK_NAME . ' sign-in code is:</p><p style="font-size:28px;font-weight:700;letter-spacing:6px">' . $issue['code'] . '</p>'
                . '<p>It works for ' . OtpService::TTL_MINUTES . ' minutes. If you did not try to sign in, change your password.</p>');
            if (!$sent) $error = 'We could not email your code right now. Please try again in a minute.';
        } elseif ($issue['reason'] === 'cooldown') {
            // A code went out less than a minute ago: use it, or ask for a new one shortly.
            $_SESSION['stock_wait'] = time() + (int) $issue['retry_after'];
        }
        if (!$error) {
            session_regenerate_id(true);
            $_SESSION['stock_pending'] = ['id' => (int) $u['id'], 'email' => $u['email'], 'at' => time()];
            header('Location: ' . stock_url('verify.php'));
            exit;
        }
    }
}

$title = 'Sign in';
ob_start();
?>
<h2>Welcome back</h2>
<p class="sub">Sign in with your email and password. We'll email you a code to confirm it's you.</p>
<form method="post" novalidate>
  <label for="em">Email</label>
  <div class="fld"><i class="far fa-envelope"></i><input class="in" id="em" name="email" type="email" value="<?php echo sh($email); ?>" placeholder="you@company.com" autocomplete="username" autofocus required></div>
  <label for="pw">Password</label>
  <div class="fld"><i class="fas fa-lock"></i><input class="in" id="pw" name="password" type="password" placeholder="Your password" autocomplete="current-password" required></div>
  <button class="btn">Continue</button>
</form>
<div class="alt"><a class="link" href="<?php echo stock_url('forgot.php'); ?>">Forgot password?</a></div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_auth.php';
