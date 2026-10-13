<?php
// public/support/_layout.php — Support console shell. Expects $page_title, $content, $nav.
$__flash = support_flash();
$__links = [
    'overview' => ['Overview', 'fa-gauge', 'support/'],
    'accounts' => ['Accounts', 'fa-users', 'support/accounts/'],
    'packages' => ['Packages', 'fa-box-open', 'support/packages/'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($page_title); ?> · Support</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--s-accent:#1f6feb;--s-dark:#0f172a;--s-bg:#f4f6fa;}
body{font-family:'Open Sans',sans-serif;background:var(--s-bg);color:#1f2937;}
.s-side{position:fixed;top:0;bottom:0;left:0;width:230px;background:var(--s-dark);color:#cbd5e1;padding:20px 14px;z-index:1000;}
.s-brand{font-weight:700;color:#fff;font-size:1.05rem;margin:0 8px 22px;}
.s-brand small{display:block;font-weight:400;color:#94a3b8;font-size:.75rem;}
.s-side a{display:flex;align-items:center;gap:10px;color:#cbd5e1;text-decoration:none;padding:10px 12px;border-radius:10px;font-weight:600;font-size:.9rem;margin-bottom:4px;}
.s-side a:hover{background:rgba(255,255,255,.06);color:#fff;}
.s-side a.on{background:var(--s-accent);color:#fff;}
.s-side .s-out{position:absolute;bottom:20px;left:14px;right:14px;}
.s-main{margin-left:230px;padding:28px 32px;}
.s-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px 20px;}
.s-stat .n{font-size:1.6rem;font-weight:700;}
.s-stat .l{color:#6b7280;font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;}
.pkg-on{background:#e8f0fe;color:#1f4fb8;font-weight:600;}
.table td,.table th{vertical-align:middle;}
.s-top{display:none;}
@media (max-width:860px){
  .s-side{position:static;width:auto;display:flex;flex-wrap:wrap;gap:4px;padding:10px;}
  .s-brand{width:100%;margin-bottom:8px;}
  .s-side .s-out{position:static;}
  .s-main{margin-left:0;padding:16px;}
}
</style>
</head>
<body>
<aside class="s-side">
  <div class="s-brand"><i class="fas fa-headset me-2"></i>Dazu Support<small><?php echo htmlspecialchars(SupportAccount::email()); ?></small></div>
  <?php foreach ($__links as $k => [$label, $icon, $url]): ?>
    <a class="<?php echo $nav === $k ? 'on' : ''; ?>" href="<?php echo public_url($url); ?>"><i class="fas <?php echo $icon; ?> fa-fw"></i><?php echo $label; ?></a>
  <?php endforeach; ?>
  <a class="s-out" href="<?php echo public_url('support/logout.php'); ?>"><i class="fas fa-arrow-right-from-bracket fa-fw"></i>Sign out</a>
</aside>
<main class="s-main">
  <?php if ($__flash): ?>
    <div class="alert alert-<?php echo $__flash[0]; ?>"><?php echo htmlspecialchars($__flash[1]); ?></div>
  <?php endif; ?>
  <?php echo $content; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
