<?php
// public/auth/confirm-email.php?t=TOKEN — the link in "Confirm your email" (EmailConfirm).
// Confirms the login, sends the login-details email and says thank you.
require_once __DIR__ . '/../../app/app.php';
$res = EmailConfirm::confirm((string) ($_GET['t'] ?? ''));
$brand = Branding::platform();
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$ok = $res['ok'];
$title = $ok ? 'Thank You!' : ($res['reason'] === 'expired' ? 'Link expired' : 'Link not valid');
$text = $ok
    ? ($res['reason'] === 'already' ? 'Your email was already confirmed. Your login details are in your inbox — sign in whenever you are ready.'
        : 'Your email address is confirmed and your login is switched on. We have sent your login details to ' . $res['email'] . ' — check your inbox, then sign in.')
    : ($res['reason'] === 'expired' ? 'This confirm link is more than ' . EmailConfirm::DAYS . ' days old. Ask whoever created your login to send a new one, or request it from the sign-in page.'
        : 'This link is not valid — it may have been used already or copied incompletely. Open the newest "Confirm your email" message, or ask for a new link.');
$login = $ok && !empty($res['login_url']) ? $res['login_url'] : public_url('auth/login.php');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $h($title); ?> — <?php echo $h($brand['name']); ?></title>
<link rel="icon" type="image/png" href="<?php echo $h(public_url('assets/icons/favicon-32.png')); ?>">
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f5f7;font-family:-apple-system,'Helvetica Neue',Helvetica,Arial,sans-serif;padding:20px;}
.card{background:#fff;border-radius:6px;max-width:980px;width:100%;padding:56px 40px 64px;text-align:center;box-shadow:0 1px 2px rgba(0,0,0,.05);}
.card img.pic{width:280px;max-width:70%;height:auto;}
h1{font-size:clamp(2.6rem,7vw,4.4rem);font-weight:700;color:#1b1b1b;margin:26px 0 18px;letter-spacing:-.02em;}
p{font-size:1.25rem;line-height:1.45;color:#555;max-width:760px;margin:0 auto;}
.reg{margin-top:56px;color:#555;font-size:1.15rem;line-height:2.1;}
.btn{display:inline-block;margin-top:34px;background:#1f9a5f;color:#fff;text-decoration:none;font-weight:600;padding:14px 30px;border-radius:10px;font-size:1rem;}
.btn:hover{background:#167d4c;}
@media(max-width:560px){.card{padding:36px 20px 44px}p{font-size:1.05rem}}
</style>
</head>
<body>
<div class="card">
  <img class="pic" src="<?php echo $h(public_url('assets/img/' . ($ok ? 'email-done.png' : 'email-confirm.png'))); ?>" alt="">
  <h1><?php echo $h($title); ?></h1>
  <p><?php echo $h($text); ?></p>
  <a class="btn" href="<?php echo $h($login); ?>"><?php echo $ok ? 'Go to sign in' : 'Go to sign in'; ?></a>
  <div class="reg">Best regards,<br>The <?php echo $h($brand['name']); ?> Team</div>
</div>
</body>
</html>
