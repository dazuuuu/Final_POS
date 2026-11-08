<?php
// public/stock/settings.php — the organisation's name, logo and currency, and
// its own sign-in address. The logo and name show on the sign-in page and in the app.
require_once __DIR__ . '/_boot.php';
stock_guard(null, true);
$store = stock_store();
$db = Database::pdo();
$acc = StockControl::account();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!stock_csrf_ok()) stock_go('settings.php', 'This page expired — try again.', 'err');
    $name = trim((string) ($_POST['name'] ?? ''));
    $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_POST['currency'] ?? 'KES'))) ?: 'KES';
    if ($name === '') stock_go('settings.php', 'Enter the organisation name.', 'err');
    $logo = null;
    $f = $_FILES['logo'] ?? null;
    if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
        $mime = $f['error'] === UPLOAD_ERR_OK ? (mime_content_type($f['tmp_name']) ?: '') : '';
        if (!isset($types[$mime]) || $f['size'] > 3 * 1024 * 1024) stock_go('settings.php', 'The logo must be a PNG, JPG, WEBP or SVG under 3 MB.', 'err');
        $dir = ROOT_PATH . '/public/assets/uploads/branding';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = 'org' . $acc . '_' . bin2hex(random_bytes(4)) . '.' . $types[$mime];
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $file)) stock_go('settings.php', 'Could not save the logo — check folder permissions.', 'err');
        $logo = 'assets/uploads/branding/' . $file;
    }
    $db->prepare('UPDATE tenants SET name = ?, currency = ?' . ($logo ? ', logo_path = ?' : '') . (!empty($_POST['remove_logo']) && !$logo ? ', logo_path = NULL' : '') . ' WHERE id = ?')
       ->execute($logo ? [$name, $cur, $logo, $acc] : [$name, $cur, $acc]);
    $db->prepare('UPDATE tenants SET currency = ? WHERE parent_tenant_id = ?')->execute([$cur, $acc]); // one currency for every store
    stock_go('settings.php', 'Settings saved.');
}

$org = $db->query('SELECT * FROM tenants WHERE id = ' . (int) $acc)->fetch();
$logoUrl = Branding::shopLogo($org);
$signIn = Signup::shopUrl($org['slug']) . '/';
$title = 'Setting';
$tab = 'settings.php';
ob_start();
?>
<div class="sc-head"><div><h1>Setting</h1><p>Your organisation's name and logo appear on your sign-in page and across the app.</p></div></div>
<div class="grid g2">
  <form class="panel" method="post" enctype="multipart/form-data"><?php echo stock_csrf_field(); ?>
    <h2>Organisation</h2>
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px">
      <span style="width:64px;height:64px;border-radius:14px;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;overflow:hidden;background:#fff">
        <?php if ($logoUrl): ?><img src="<?php echo sh($logoUrl); ?>" alt="" style="width:100%;height:100%;object-fit:contain"><?php else: ?><i class="fas fa-image" style="color:#cbd5e1;font-size:1.4rem"></i><?php endif; ?></span>
      <div style="flex:1"><label class="f">Logo</label><input class="in" type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
        <?php if ($logoUrl): ?><label class="small mute" style="display:block;margin-top:6px"><input type="checkbox" name="remove_logo" value="1"> Remove the logo</label><?php endif; ?></div>
    </div>
    <label class="f">Organisation name</label><input class="in" name="name" value="<?php echo sh($org['name']); ?>" required style="margin-bottom:12px">
    <label class="f">Currency</label><input class="in" name="currency" value="<?php echo sh($org['currency'] ?: 'KES'); ?>" maxlength="8" style="margin-bottom:16px;max-width:140px">
    <button class="btn btn-p"><i class="fas fa-floppy-disk"></i> Save</button>
  </form>
  <div class="panel">
    <h2>Your sign-in page</h2>
    <p class="small mute" style="margin-top:0">Share this address with your administrators and staff. It shows your name and logo; everyone signs in with their email, password and a code emailed to them.</p>
    <div style="display:flex;gap:8px"><input class="in" id="addr" value="<?php echo sh($signIn); ?>" readonly onclick="this.select()">
      <button class="btn btn-l" type="button" onclick="navigator.clipboard.writeText(document.getElementById('addr').value);this.textContent='Copied'">Copy</button></div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
