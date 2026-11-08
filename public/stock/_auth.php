<?php
// public/stock/_auth.php — frame of the sign-in pages (sign in, code, forgot
// password). Opened at an organisation's address (domain/<slug>/) it shows
// that organisation's logo and name, so every organisation has its own page.
// Expects $title, $content, optional $error / $notice.
$__org = stock_org();
$__name = $__org['name'] ?? STOCK_NAME;
$__logo = $__org ? Branding::shopLogo($__org) : '';
$__ini = strtoupper(implode('', array_map(fn($w) => substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($__name)) ?: [], 0, 2))));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f6f7f9">
<meta name="robots" content="noindex">
<title><?php echo sh($title); ?> · <?php echo sh($__name); ?></title>
<?php if ($__logo): ?><link rel="icon" href="<?php echo sh($__logo); ?>"><?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="<?php echo public_url('assets/js/page-loader.js'); ?>?v=<?php echo @filemtime(ROOT_PATH . '/public/assets/js/page-loader.js'); ?>"></script>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;min-height:100svh;font:14.5px/1.55 Inter,system-ui,sans-serif;color:#111827;background:#eceef1;display:flex;align-items:center;justify-content:center;padding:28px 16px;}
.card{width:100%;max-width:440px;background:#fff;border:1px solid #e9ebef;border-radius:18px;padding:34px 32px 26px;box-shadow:0 24px 48px -28px rgba(17,24,39,.25);}
.org{display:flex;flex-direction:column;align-items:center;text-align:center;margin-bottom:24px;}
.mk{width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;font-weight:700;font-size:1.35rem;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:12px;}
.mk img{width:100%;height:100%;object-fit:contain;background:#fff;}
.org b{font-size:1.3rem;font-weight:700;letter-spacing:-.02em;}
.tag{margin-top:6px;font-size:.72rem;font-weight:600;color:#15803d;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:999px;padding:2px 10px;letter-spacing:.04em;text-transform:uppercase;}
h2{font-size:1.15rem;margin:0 0 4px;font-weight:600;}
.sub{color:#6b7280;margin:0 0 20px;font-size:.88rem;}
label{display:block;font-size:.8rem;font-weight:500;color:#374151;margin:0 0 6px;}
.fld{position:relative;margin-bottom:16px;}
.fld i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#9ca3af;}
.in{width:100%;border:1px solid #e3e6ea;border-radius:10px;padding:11px 13px 11px 38px;font:inherit;background:#fff;}
.in:focus{outline:none;border-color:#22c55e;box-shadow:0 0 0 3px #22c55e22;}
.in.code{padding-left:13px;letter-spacing:.55em;text-align:center;font-size:1.45rem;font-weight:700;}
.btn{width:100%;border:0;border-radius:10px;padding:12px;background:#16a34a;color:#fff;font:600 .95rem Inter,system-ui,sans-serif;cursor:pointer;}
.btn:hover{background:#15803d;}
.link{color:#15803d;font-weight:500;font-size:.86rem;text-decoration:none;}
.alt{display:flex;justify-content:space-between;align-items:center;margin-top:16px;font-size:.86rem;gap:10px;flex-wrap:wrap;}
.msg{padding:10px 12px;border-radius:10px;font-size:.86rem;margin-bottom:16px;}
.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;} .ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.secure{display:flex;gap:8px;align-items:center;justify-content:center;color:#6b7280;font-size:.76rem;margin-top:22px;padding-top:16px;border-top:1px solid #f0f2f4;}
@media(max-width:480px){.card{padding:26px 20px 20px;border-radius:16px;}}
</style>
</head>
<body>
<main class="card">
  <div class="org">
    <span class="mk"><?php if ($__logo): ?><img src="<?php echo sh($__logo); ?>" alt="<?php echo sh($__name); ?>"><?php else: echo sh($__ini ?: 'SC'); endif; ?></span>
    <b><?php echo sh($__name); ?></b>
    <span class="tag">Inventory management</span>
  </div>
  <?php if (!empty($error)): ?><div class="msg err"><?php echo sh($error); ?></div><?php endif; ?>
  <?php if (!empty($notice)): ?><div class="msg ok"><?php echo sh($notice); ?></div><?php endif; ?>
  <?php echo $content; ?>
  <div class="secure"><i class="fas fa-shield-halved"></i> Every sign-in is confirmed with a code sent to your email.</div>
</main>
</body>
</html>
