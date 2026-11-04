<?php
// public/templates/auth/layout.php
// The sign-in / sign-up screens (login, staff PIN, signup, forgot password,
// confirm, locked…): a split page — on the left a soft Dazu-green panel with a
// picture of the POS, a headline and a line of text; on the right the logo,
// and a white card with the page's form ($content).
// Multi-tenant: shared screens show the platform's brand (Support → Settings);
// opened at a shop's own address (domain/<slug>/…) they show that shop's brand.
// Pages may set: $__authHeadline / $__authText (left side), $__authRole.
// $__authMono = true (support login) uses the black-and-white centred card instead.
if (!empty($__authMono)) {
    include __DIR__ . '/layout_mono.php';
    return;
}
$__authBrand = Branding::platform();
$__authShop  = $__authBrand['name'];
$__authLogo  = $__authBrand['logo'];
if (class_exists('ShopUrl', false) && ShopUrl::requestSlug() !== null
    && ($__authTenant = (new Models\TenantModel(Database::pdo()))->primary())) {
    $__authShop = $__authTenant['name'];
    $__authLogo = Branding::shopLogo($__authTenant);
}
$__authHeadline = $__authHeadline ?? 'Sell, track & grow';
$__authText = $__authText ?? $__authBrand['name'] . ' is the point of sale for modern shops, salons, restaurants and electronics stores. Sell, count stock, give credit and print receipts on one platform.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($page_title ?? 'Sign in'); ?> — <?php echo htmlspecialchars($__authShop); ?></title>
    <?php include __DIR__ . '/../../components/pwa_head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root{ --pos-green:#1f9a5f; --pos-green-dark:#167d4c; --pos-green-light:#eef8f2; --pos-ink:#15171a; --mute:#8b9095; --line:#dfe3e8; }
        *{box-sizing:border-box}
        html,body{height:100%}
        body{margin:0;font-family:Archivo,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:var(--pos-ink);background:#fff;}
        .auth-split{display:grid;grid-template-columns:1.12fr 1fr;min-height:100vh;min-height:100svh;}

        /* ---------- left: the picture ---------- */
        .auth-art{background:var(--pos-green-light);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:48px 40px;position:relative;overflow:hidden;}
        .art{position:relative;width:min(470px,100%);aspect-ratio:1.18;margin-bottom:34px;}
        .art .screen{position:absolute;left:9%;right:9%;top:6%;height:62%;background:#1b1d20;border-radius:14px 14px 4px 4px;padding:9px;box-shadow:0 30px 50px -28px rgba(18,64,40,.55);}
        .art .screen-in{background:#fff;border-radius:6px;height:100%;display:grid;grid-template-columns:28% 1fr;overflow:hidden;}
        .art .side{background:#0f3d27;padding:8px 6px;}
        .art .side i{display:block;height:5px;border-radius:3px;background:rgba(255,255,255,.22);margin:6px 2px;}
        .art .side i.on{background:#3fcf86;}
        .art .main{padding:9px;}
        .art .kp{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;margin-bottom:7px;}
        .art .kp b{display:block;height:20px;border:1px solid #eef0f2;border-radius:4px;position:relative;}
        .art .kp b::after{content:"";position:absolute;left:5px;right:40%;top:7px;height:4px;border-radius:2px;background:#d9dde2;}
        .art .chart{height:46%;border:1px solid #eef0f2;border-radius:4px;display:flex;align-items:flex-end;gap:4%;padding:6px 8px;}
        .art .chart i{flex:1;background:#cfeedd;border-radius:2px 2px 0 0;}
        .art .chart i.h{background:var(--pos-green);}
        .art .base{position:absolute;left:2%;right:2%;top:68%;height:5%;background:linear-gradient(#e5e7ea,#c9cdd2);border-radius:2px 2px 14px 14px;}
        .art .card{position:absolute;background:#fff;border-radius:8px;box-shadow:0 10px 24px -10px rgba(20,60,40,.35);font-size:11px;color:#2b2f33;padding:9px 11px;}
        .art .c1{left:-4%;top:28%;width:38%;}
        .art .c2{right:-6%;top:10%;width:34%;}
        .art .c3{right:4%;bottom:6%;width:44%;}
        .art .c1 b,.art .c2 b,.art .c3 b{display:block;font-weight:600;font-size:11.5px;}
        .art .c2 .n{font-size:15px;font-weight:600;color:var(--pos-green);}
        .art .ring{position:absolute;left:8%;bottom:2%;width:64px;height:64px;border-radius:50%;border:2px solid #15171a;background:conic-gradient(var(--pos-green) 0 38%,#fff 38% 100%);}
        .art .ring::after{content:"";position:absolute;left:50%;top:50%;width:2px;height:22px;background:#15171a;transform-origin:top;transform:rotate(210deg);}
        .art .sq{position:absolute;width:22px;height:22px;border:2px solid #15171a;}
        .art .sq.a{right:1%;top:48%;} .art .sq.b{left:46%;top:-2%;width:16px;height:16px;border-color:var(--pos-green);}
        .art .dot{position:absolute;width:44px;height:44px;border-radius:50%;background:#cfeedd;left:-2%;top:6%;}
        .auth-art h2{font-size:1.75rem;font-weight:500;margin:0 0 12px;text-align:center;letter-spacing:-.01em;}
        .auth-art p{color:var(--mute);max-width:420px;text-align:center;line-height:1.6;font-size:.95rem;margin:0;}

        /* ---------- right: the form ---------- */
        .auth-side{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:44px 28px;}
        .auth-brand{display:flex;align-items:center;gap:8px;margin-bottom:26px;text-decoration:none;color:var(--pos-green);font-weight:600;font-size:1.15rem;}
        .auth-brand img{height:36px;width:auto;mix-blend-mode:multiply;} /* the logo's white background melts into the page */
        .auth-card{width:min(400px,100%);background:#fff;padding:30px 28px;box-shadow:0 18px 40px -18px rgba(23,40,30,.25);border-radius:4px;}
        .auth-title{font-size:1.65rem;font-weight:500;text-align:center;margin:0 0 6px;letter-spacing:-.01em;}
        .auth-sub{color:var(--mute);font-size:.88rem;text-align:center;margin-bottom:22px;line-height:1.5;}
        .form-label{display:block;font-size:.8rem;font-weight:500;color:#5a5f66;margin-bottom:6px;}
        .form-control{border:1px solid var(--line)!important;border-radius:2px!important;padding:11px 13px!important;font-size:.92rem!important;color:var(--pos-ink)!important;box-shadow:none!important;}
        .form-control:focus{border-color:var(--pos-green)!important;}
        .btn-auth{display:block;width:100%;padding:12px;font-weight:600;font-size:.92rem;background:var(--pos-green)!important;border:0!important;border-radius:2px!important;color:#fff!important;text-align:center;text-decoration:none;}
        .btn-auth:hover{background:var(--pos-green-dark)!important;}
        .btn-line{display:block;width:100%;padding:11px;font-weight:600;font-size:.9rem;background:#fff;border:1.5px solid var(--pos-green);color:var(--pos-green);border-radius:2px;text-align:center;text-decoration:none;}
        .btn-line:hover{background:var(--pos-green-light);color:var(--pos-green-dark);}
        .auth-alert{border-radius:2px;padding:10px 13px;font-size:.86rem;margin-bottom:16px;}
        .auth-alert.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
        .auth-alert.ok{background:var(--pos-green-light);color:#11613b;border:1px solid #cdeedb;}
        .auth-foot{text-align:center;margin-top:20px;font-size:.88rem;color:#3f444a;}
        .auth-foot a,.auth-side a.link{color:var(--pos-green);text-decoration:none;font-weight:600;}
        .auth-or{display:flex;align-items:center;gap:10px;color:var(--mute);font-size:.8rem;margin:16px 0;}
        .auth-or::before,.auth-or::after{content:"";flex:1;height:1px;background:#eceef1;}
        .otp-input{letter-spacing:.5em;text-align:center;font-size:1.4rem;font-weight:700}
        .logo-role{display:none;}

        @media(max-width:900px){
          .auth-split{grid-template-columns:1fr;}
          .auth-art{padding:30px 22px 26px;}
          .art{width:min(340px,92%);margin-bottom:18px;}
          .auth-art h2{font-size:1.3rem;} .auth-art p{font-size:.86rem;}
          .auth-side{padding:28px 16px 40px;justify-content:flex-start;}
          .form-control{font-size:16px!important;min-height:46px;}
        }
        @media(max-width:520px){ .auth-art{display:none;} .auth-side{padding-top:36px;} .auth-card{padding:24px 18px;box-shadow:none;border:1px solid #eceef1;} }
    </style>
</head>
<body>
<?php include __DIR__ . '/../../components/boot_splash.php'; ?>
<div class="auth-split">
  <section class="auth-art" aria-hidden="true">
    <div class="art">
      <span class="dot"></span><span class="sq a"></span><span class="sq b"></span>
      <div class="screen"><div class="screen-in">
        <div class="side"><i class="on"></i><i></i><i></i><i></i><i></i><i></i></div>
        <div class="main"><div class="kp"><b></b><b></b><b></b></div>
          <div class="chart"><i style="height:40%"></i><i style="height:62%"></i><i style="height:48%"></i><i class="h" style="height:86%"></i><i style="height:58%"></i><i style="height:72%"></i><i class="h" style="height:94%"></i></div></div>
      </div></div>
      <div class="base"></div>
      <div class="card c1"><b><i class="fas fa-receipt me-1" style="color:var(--pos-green)"></i>Receipt printed</b>Sale #00148 · KES 673</div>
      <div class="card c2"><b>Today</b><span class="n">KES 36,140</span><br>148 receipts</div>
      <div class="card c3"><b><i class="fas fa-circle-check me-1" style="color:var(--pos-green)"></i>Stock updated</b>Maize flour 2kg · 42 left</div>
      <span class="ring"></span>
    </div>
    <h2><?php echo htmlspecialchars($__authHeadline); ?></h2>
    <p><?php echo htmlspecialchars($__authText); ?></p>
  </section>
  <section class="auth-side">
    <a class="auth-brand" href="<?php echo public_url(''); ?>">
      <?php if ($__authLogo): ?><img src="<?php echo htmlspecialchars($__authLogo); ?>" alt=""><?php endif; ?>
      <span><?php echo htmlspecialchars($__authShop); ?></span>
    </a>
    <div class="auth-card">
      <?php echo $content ?? ''; ?>
    </div>
    <?php echo $__authBelow ?? ''; ?>
  </section>
</div>
</body>
</html>
