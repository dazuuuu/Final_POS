<?php
// public/templates/auth/layout.php
// Centered card used by register / login / otp-verify / activate / forgot-password.
// Multi-tenant: these screens are shared by every shop, so they always show
// the platform's brand (Support → Settings), never one client's logo.
// Opened at a shop's own address (domain/<slug>/admin/) → that shop's brand.
// Flat and plain: hairline borders, no shadows or gradients. A page may set
// $__authMono = true (the support login) for a black-and-white version.
$__authBrand = Branding::platform();
$__authShop  = $__authBrand['name'];
$__authLogo  = $__authBrand['logo'];
if (class_exists('ShopUrl', false) && ShopUrl::requestSlug() !== null
    && ($__authTenant = (new Models\TenantModel(Database::pdo()))->primary())) {
    $__authShop = $__authTenant['name'];
    $__authLogo = Branding::shopLogo($__authTenant);
    $__authIcon = 'fa-store';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($page_title ?? 'Admin'); ?> — <?php echo htmlspecialchars($__authShop); ?></title>
    <?php include __DIR__ . '/../../components/pwa_head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root{ --pos-green:#16a34a; --pos-green-dark:#15803d; --pos-green-light:#f0fdf4; --pos-bg:#f7f7fb; --pos-ink:#1f2330; }
        *{box-sizing:border-box;margin:0;padding:0}
        body{min-height:100vh;min-height:100svh;display:flex;align-items:center;justify-content:center;
             background:#fafafa;font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;
             padding:40px 20px;padding-top:max(24px,env(safe-area-inset-top));padding-bottom:max(24px,env(safe-area-inset-bottom));}

        .auth-card{width:400px;max-width:100%;background:#fff;border:1px solid #e4e4e7;border-radius:12px;overflow:hidden;}
        .auth-head{padding:32px 32px 0;text-align:center}
        .logo-icon{width:52px;height:52px;border-radius:10px;overflow:hidden;background:#fff;
                   border:1px solid #e4e4e7;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px}
        .logo-icon img{width:100%;height:100%;object-fit:contain}
        .logo-icon i{color:var(--pos-green);font-size:22px}
        .logo-name{font-size:1.05rem;font-weight:600;color:var(--pos-ink);letter-spacing:-.02em;display:block}
        .logo-role{display:inline-block;margin-top:4px;padding:1px 8px;border-radius:6px;background:#fff;border:1px solid #e4e4e7;
                   color:#52525b;font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em}
        .auth-body{padding:20px 32px 32px}
        .auth-title{font-size:1.3rem;font-weight:600;color:var(--pos-ink);text-align:center;margin:0 0 6px;letter-spacing:-.02em}
        .auth-sub{color:#7a7f8c;font-size:.88rem;text-align:center;margin-bottom:22px;line-height:1.5}

        .form-label{display:block;font-size:.8rem;font-weight:600;color:#5b6070;margin-bottom:6px}
        .field-wrap{position:relative}
        .field-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#b7bac3;pointer-events:none}
        .form-control{border:1px solid #e4e4e7!important;border-radius:8px!important;padding:11px 14px!important;
                      font-size:.92rem!important;color:var(--pos-ink)!important}
        .field-wrap .form-control{padding-left:42px!important}
        .form-control:focus{border-color:#a1a1aa!important;box-shadow:none!important}
        .field-focus-line{display:none}

        .btn-auth{width:100%;padding:13px;font-weight:700;font-size:.95rem;
                  background:var(--pos-green)!important;border:none!important;border-radius:8px!important;
                  color:#fff!important;letter-spacing:0;box-shadow:none!important;transition:background .15s!important}
        .btn-auth:hover{background:var(--pos-green-dark)!important}

        .auth-alert{border-radius:8px;padding:10px 14px;font-size:.88rem;margin-bottom:16px}
        .auth-alert.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
        .auth-alert.ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
        .otp-input{letter-spacing:.5em;text-align:center;font-size:1.4rem;font-weight:700}

        .auth-foot{text-align:center;margin-top:18px;font-size:.85rem;color:#7a7f8c}
        .auth-foot a{color:var(--pos-green);text-decoration:none;font-weight:600}
        .auth-foot a:hover{text-decoration:underline}

        /* Black and white (support login). */
        body.mono{background:#fff;}
        body.mono .auth-card{border-color:#e4e4e7;}
        body.mono .btn-auth{background:#09090b!important;}
        body.mono .btn-auth:hover{background:#27272a!important;}
        body.mono .auth-foot a,body.mono a{color:#09090b;}
        body.mono .auth-alert.ok{background:#fff;color:#09090b;border-color:#e4e4e7;}
        body.mono .auth-alert.err{background:#fff;color:#991b1b;border-color:#fecaca;}

        @media(max-width:480px){
          body{align-items:flex-start;padding-left:14px;padding-right:14px;}
          .auth-card{width:100%;border-radius:12px;}
          .auth-head{padding:22px 20px 0}
          .auth-body{padding:16px 20px 22px}
          .auth-title{font-size:1.25rem;}
          .auth-sub{font-size:.86rem;margin-bottom:18px;}
          .form-control{font-size:16px!important;min-height:48px!important;}
          .btn-auth{min-height:50px;font-size:1rem;}
          .logo-icon{width:50px;height:50px;border-radius:13px;}
        }
        @media(max-width:360px){
          body{padding-left:10px;padding-right:10px;}
          .auth-head{padding-left:16px;padding-right:16px;}
          .auth-body{padding-left:16px;padding-right:16px;}
        }
    </style>
</head>
<body class="<?php echo !empty($__authMono) ? 'mono' : ''; ?>">
    <?php if (empty($__authMono)) include __DIR__ . '/../../components/boot_splash.php'; ?>
    <div class="auth-card">
        <div class="auth-head">
            <div class="logo-icon">
                <?php if ($__authLogo): ?><img src="<?php echo htmlspecialchars($__authLogo); ?>" alt="">
                <?php else: ?><i class="fas <?php echo $__authIcon ?? 'fa-layer-group'; ?>"></i><?php endif; ?>
            </div>
            <span class="logo-name"><?php echo htmlspecialchars($__authShop); ?></span>
            <span class="logo-role"><?php echo htmlspecialchars($__authRole ?? 'Admin'); ?></span>
        </div>
        <div class="auth-body">
            <?php echo $content ?? ''; ?>
        </div>
    </div>
</body>
</html>
