<?php
// public/super/settings/index.php — business details shown on receipts:
// name, logo, location, and an optional KRA PIN (only printed when set).
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::SETTINGS_MANAGE);

$pdo = Database::pdo();
$tenantId = TenantContext::tenantId();
$tenantModel = new Models\TenantModel($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'theme') {
    $mode = in_array($_POST['mode'] ?? '', ['default', 'logo', 'custom'], true) ? $_POST['mode'] : 'default';
    ShopTheme::save((int) $tenantId, $mode, $_POST['brand'] ?? null, $_POST['sidebar'] ?? null);
    $now = ShopTheme::get((int) $tenantId)['mode'];
    $_SESSION['flash'][$mode === $now ? 'success' : 'error'] = $mode === $now
        ? ['default' => 'Back to the standard colours.', 'logo' => 'Colours picked from your logo.', 'custom' => 'Your colours are saved.'][$mode]
        : ($mode === 'logo' ? 'No colour could be picked from your logo (upload a coloured logo first). Colours were set back to the standard ones.' : 'Pick two valid colours.');
    header('Location: ' . public_url('super/settings/#colours'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'name'           => trim($_POST['name'] ?? ''),
        'phone'          => trim($_POST['phone'] ?? ''),
        'address'        => trim($_POST['address'] ?? ''),
        'currency'       => trim($_POST['currency'] ?? 'KES'),
        'receipt_footer' => trim($_POST['receipt_footer'] ?? ''),
        'kra_pin'        => trim($_POST['kra_pin'] ?? ''),
    ];

    // Logo upload (optional). Stored under public/uploads/branding/.
    if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $logo = save_tenant_logo($_FILES['logo'], $tenantId);
        if ($logo['ok']) {
            $data['logo_path'] = $logo['path'];
        } else {
            $_SESSION['flash']['error'] = $logo['error'];
        }
    }

    if ($data['name'] === '') {
        $_SESSION['flash']['error'] = 'Business name is required.';
    } else {
        $tenantModel->updateSettings($tenantId, $data);
        if (!empty($data['logo_path'])) {
            ShopTheme::logoChanged((int) $tenantId); // new logo → new colours (unless they chose their own)
        }
        if (empty($_SESSION['flash']['error'])) {
            $_SESSION['flash']['success'] = 'Settings updated.';
        }
    }
    header('Location: ' . public_url('super/settings/'));
    exit;
}

/** Minimal, safe image saver (swap for app/helpers/uploads.php if you prefer). */
function save_tenant_logo(array $file, int $tenantId): array
{
    if ($file['error'] !== UPLOAD_ERR_OK)               return ['ok' => false, 'error' => 'Upload failed.'];
    if ($file['size'] > 2 * 1024 * 1024)                return ['ok' => false, 'error' => 'Logo must be under 2MB.'];
    $info = @getimagesize($file['tmp_name']);
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!$info || !isset($allowed[$info['mime']]))      return ['ok' => false, 'error' => 'Logo must be PNG, JPG or WEBP.'];

    $dir = ROOT_PATH . '/public/uploads/branding';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $name = 'tenant_' . $tenantId . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$info['mime']];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Could not save the logo.'];
    }
    // Stored relative to the public web root; Branding::tenantLogo() resolves
    // it through public_url() when displaying it.
    return ['ok' => true, 'path' => 'uploads/branding/' . $name];
}

$__tenant = $tenantModel->find($tenantId);
$page_title = 'Settings';

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Business details</h2>
        <p class="text-muted small mb-3">Shown on every printed and emailed receipt.</p>
        <form method="post" enctype="multipart/form-data">
          <div class="mb-3">
            <label class="form-label fw-semibold">Business name</label>
            <input type="text" name="name" class="form-control" required
                   value="<?php echo htmlspecialchars($__tenant['name'] ?? ''); ?>">
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" class="form-control"
                     value="<?php echo htmlspecialchars($__tenant['phone'] ?? ''); ?>">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label fw-semibold">Currency</label>
              <input type="text" name="currency" class="form-control" maxlength="8"
                     value="<?php echo htmlspecialchars($__tenant['currency'] ?? 'KES'); ?>">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Business location</label>
            <input type="text" name="address" class="form-control" placeholder="e.g. Kitengela, St. Monica's Rd"
                   value="<?php echo htmlspecialchars($__tenant['address'] ?? ''); ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">KRA PIN <span class="text-muted fw-normal">(optional)</span></label>
            <input type="text" name="kra_pin" class="form-control" placeholder="e.g. PA006734580F"
                   value="<?php echo htmlspecialchars($__tenant['kra_pin'] ?? ''); ?>">
            <div class="form-text">Only printed on receipts when you fill this in.</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Receipt footer</label>
            <input type="text" name="receipt_footer" class="form-control"
                   placeholder="e.g. Thank you for shopping with us!"
                   value="<?php echo htmlspecialchars($__tenant['receipt_footer'] ?? ''); ?>">
          </div>
          <div class="mb-4">
            <label class="form-label fw-semibold">Logo</label>
            <input type="file" name="logo" class="form-control" accept="image/png,image/jpeg,image/webp">
            <div class="form-text">Shown on your dashboard, your staff's dashboard, and receipts. PNG/JPG/WEBP, under 2MB.</div>
          </div>
          <button class="btn btn-primary">Save changes</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body text-center p-4">
        <div class="text-muted small text-uppercase mb-2">Current logo</div>
        <?php if ($__logo = Branding::shopLogo($__tenant)): ?>
        <img src="<?php echo htmlspecialchars($__logo); ?>" alt="Logo" style="max-height:90px;max-width:100%;object-fit:contain;">
        <?php else: ?><div class="text-muted py-3"><i class="fas fa-store fa-2x d-block mb-2"></i>No logo yet</div><?php endif; ?>
        <div class="text-muted small mt-3">Shown on your shop's login and PIN screen, catalogue, your and your staff's dashboards, and receipts.</div>
      </div>
    </div>
  </div>

  <?php $__theme = ShopTheme::get((int) $tenantId);
        $__picked = ShopTheme::fromLogo(($__l = $__tenant['logo_path'] ?? '') !== '' ? PUBLIC_ROOT . '/' . ltrim(preg_replace('#^(https?://[^/]+)?/?(public/)?#', '', $__l), '/') : null); ?>
  <div class="col-12 col-lg-8" id="colours">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Colours</h2>
        <p class="text-muted small mb-3">The colours of your menu bar, sidebar and buttons, for you and your staff, in every shop of this account. Only the colours change; the layout stays the same.</p>
        <form method="post" id="themeForm">
          <input type="hidden" name="action" value="theme">
          <div class="row g-2 mb-3">
            <?php foreach ([
                ['logo', 'fa-wand-magic-sparkles', 'From my logo', $__picked ? 'Picked automatically from your logo. Updates when you upload a new one.' : 'Upload a coloured logo above first.', $__picked],
                ['custom', 'fa-palette', 'My own colours', 'Choose a main colour and a sidebar colour.', null],
                ['default', 'fa-rotate-left', 'Standard', 'The default template colours.', ShopTheme::DEFAULT],
            ] as [$val, $icon, $label, $hint, $sw]): ?>
            <div class="col-12 col-md-4">
              <label class="d-flex gap-2 p-3 h-100 border rounded-3 theme-opt" style="cursor:pointer;">
                <input type="radio" name="mode" value="<?php echo $val; ?>" class="form-check-input mt-1" <?php echo $__theme['mode'] === $val ? 'checked' : ''; ?> <?php echo $val === 'logo' && !$__picked ? 'disabled' : ''; ?>
                       <?php if ($sw): ?>data-brand="<?php echo $sw['brand']; ?>" data-sidebar="<?php echo $sw['sidebar']; ?>"<?php endif; ?>>
                <span>
                  <span class="fw-semibold d-block"><i class="fas <?php echo $icon; ?> me-1"></i><?php echo $label; ?></span>
                  <span class="text-muted small d-block"><?php echo $hint; ?></span>
                  <?php if ($sw): ?><span class="d-inline-flex gap-1 mt-2">
                    <span style="width:22px;height:22px;border-radius:6px;background:<?php echo $sw['brand']; ?>;"></span>
                    <span style="width:22px;height:22px;border-radius:6px;background:<?php echo $sw['sidebar']; ?>;"></span>
                  </span><?php endif; ?>
                </span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="row g-3 mb-3" id="customColours">
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold small">Main colour <span class="text-muted fw-normal">(bar &amp; buttons)</span></label>
              <input type="color" name="brand" class="form-control form-control-color w-100" value="<?php echo $__theme['brand']; ?>">
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label fw-semibold small">Sidebar colour</label>
              <input type="color" name="sidebar" class="form-control form-control-color w-100" value="<?php echo $__theme['sidebar']; ?>">
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <button class="btn btn-primary">Save colours</button>
            <span class="text-muted small">You see a preview as you choose.</span>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var f = document.getElementById('themeForm'), root = document.documentElement.style;
  var brand = f.elements.brand, side = f.elements.sidebar, custom = document.getElementById('customColours');
  function mix(hex, to, t) {
    var a = [1, 3, 5].map(function (i) { return parseInt(hex.substr(i, 2), 16); }), b = to === '#000' ? [0, 0, 0] : [255, 255, 255];
    return '#' + a.map(function (v, i) { return ('0' + Math.round(v + (b[i] - v) * t).toString(16)).slice(-2); }).join('');
  }
  function preview() {
    var on = f.querySelector('input[name=mode]:checked'); if (!on) return;
    custom.style.display = on.value === 'custom' ? '' : 'none';
    if (on.dataset.brand) { brand.value = on.dataset.brand; side.value = on.dataset.sidebar; }
    var b = brand.value, s = side.value;
    root.setProperty('--tk-green', b); root.setProperty('--pos-green', b);
    root.setProperty('--tk-green-dark', mix(b, '#000', .18)); root.setProperty('--pos-green-dark', mix(b, '#000', .18));
    root.setProperty('--tk-green-light', mix(b, '#fff', .88)); root.setProperty('--pos-green-light', mix(b, '#fff', .88));
    root.setProperty('--tk-navy', s); root.setProperty('--tk-navy-dark', mix(s, '#000', .2));
    root.setProperty('--tk-navy-hover', mix(s, '#fff', .1)); root.setProperty('--tk-nav-text', mix(s, '#fff', .78));
  }
  f.addEventListener('change', preview);
  brand.addEventListener('input', preview); side.addEventListener('input', preview);
  custom.style.display = (f.querySelector('input[name=mode]:checked') || {}).value === 'custom' ? '' : 'none';
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
