<?php /* PWA head tags + service-worker registration. Include inside <head>. */ ?>
<?php require_once __DIR__ . '/../../app/helpers/PathConfig.php'; ?>
<?php $__shopSlug = class_exists('ShopUrl', false) ? (string) ShopUrl::current() : ''; ?>
<link rel="manifest" href="<?php echo $__shopSlug !== '' && preg_match('/^[a-z0-9-]+$/', $__shopSlug) ? public_url('manifest.php?s=' . $__shopSlug) : public_url('manifest.webmanifest'); ?>">
<meta name="theme-color" content="#0f766e">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Dazu POS">
<link rel="apple-touch-icon" href="<?php echo public_url('assets/icons/apple-touch-icon.png'); ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo public_url('assets/icons/favicon-32.png'); ?>">
<?php /* 1%–100% loader for slow pages, forms and connections (invisible when things are fast). */ ?>
<?php /* Speed: open connections to the style/icon servers early, and fetch the next page as soon as a link is pointed at or touched. */ ?>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<script src="<?php echo public_url('assets/js/page-loader.js'); ?>?v=<?php echo @filemtime(__DIR__ . '/../assets/js/page-loader.js'); ?>"></script>
<script src="<?php echo public_url('assets/js/instant-nav.js'); ?>?v=<?php echo @filemtime(__DIR__ . '/../assets/js/instant-nav.js'); ?>" defer></script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('<?php echo public_url('sw.js'); ?>', { scope: '<?php echo public_url(); ?>' })
      .catch(function (e) { console.warn('SW registration failed', e); });
  });
}
</script>
<?php include __DIR__ . '/password_toggle.php'; ?>
