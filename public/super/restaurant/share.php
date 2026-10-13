<?php
// public/super/restaurant/share.php — share the menu with customers: the public
// link, a QR code to print/download, per-table QR cards (the table number is
// filled in for dine-in), and online-ordering settings.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::SETTINGS_MANAGE);
require_once __DIR__ . '/_common.php';

$self = public_url('super/restaurant/share.php');
$settings = rest_settings($pdo, $tid);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    if (($_POST['action'] ?? '') === 'regenerate') {
        $pdo->prepare('UPDATE rest_settings SET public_token = ? WHERE tenant_id = ?')->execute([bin2hex(random_bytes(6)), $tid]);
        rest_flash('New link created. Old links and printed QR codes no longer work — print the new ones.');
    } else {
        $flags = ['online_enabled', 'allow_dine_in', 'allow_takeaway', 'allow_delivery'];
        $vals = array_map(fn($f) => empty($_POST[$f]) ? 0 : 1, $flags);
        if (!$vals[1] && !$vals[2] && !$vals[3]) {
            rest_flash('Keep at least one of dine in, takeaway or delivery on.', 'error');
        } else {
            $pdo->prepare('UPDATE rest_settings SET online_enabled=?, allow_dine_in=?, allow_takeaway=?, allow_delivery=?, notice=? WHERE tenant_id=?')
                ->execute([...$vals, mb_substr(trim($_POST['notice'] ?? ''), 0, 255) ?: null, $tid]);
            rest_flash('Online ordering settings saved.');
        }
    }
    header('Location: ' . $self);
    exit;
}

$link = rest_public_url($settings['public_token']);
$shopName = $__tenant['name'] ?? 'Our menu';

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
      <div class="card-body p-4 text-center">
        <h2 class="h5 mb-1">Your menu link</h2>
        <p class="text-muted small mb-3">Customers scan this to see the menu and order. They can't see anything else.</p>
        <?php if (!$settings['online_enabled']): ?><div class="alert alert-warning small py-2">Online ordering is <strong>off</strong> — customers can view the menu but not order.</div><?php endif; ?>
        <div id="qrMain" class="d-inline-block p-3 bg-white border rounded-3 mb-3"></div>
        <div class="input-group mb-2">
          <input class="form-control form-control-sm" id="linkIn" value="<?php echo htmlspecialchars($link); ?>" readonly>
          <button class="btn btn-sm btn-outline-secondary" id="copyBtn" type="button"><i class="fas fa-copy"></i></button>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-center">
          <a class="btn btn-sm btn-primary" href="<?php echo htmlspecialchars($link); ?>" target="_blank"><i class="fas fa-up-right-from-square me-1"></i>Open</a>
          <button class="btn btn-sm btn-outline-primary" id="dlBtn" type="button"><i class="fas fa-download me-1"></i>Download QR</button>
          <a class="btn btn-sm btn-success" target="_blank" href="https://wa.me/?text=<?php echo rawurlencode('Order from ' . $shopName . ': ' . $link); ?>"><i class="fab fa-whatsapp me-1"></i>WhatsApp</a>
        </div>
        <form method="post" class="mt-3" onsubmit="return confirm('Create a new link? The current link and every printed QR code will stop working.');">
          <?php echo csrf_field(); ?><input type="hidden" name="action" value="regenerate">
          <button class="btn btn-link btn-sm text-danger">Create a new link (disable the old one)</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Online ordering</h2>
        <form method="post">
          <?php echo csrf_field(); ?>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="online_enabled" id="on" value="1" <?php echo $settings['online_enabled'] ? 'checked' : ''; ?>>
            <label class="form-check-label fw-semibold" for="on">Accept orders from the menu link</label>
          </div>
          <div class="d-flex flex-wrap gap-3 mb-3">
            <?php foreach (['allow_dine_in' => 'Dine in (table)', 'allow_takeaway' => 'Takeaway / pick up', 'allow_delivery' => 'Delivery'] as $k => $l): ?>
              <div class="form-check"><input class="form-check-input" type="checkbox" name="<?php echo $k; ?>" id="<?php echo $k; ?>" value="1" <?php echo $settings[$k] ? 'checked' : ''; ?>><label class="form-check-label" for="<?php echo $k; ?>"><?php echo $l; ?></label></div>
            <?php endforeach; ?>
          </div>
          <div class="mb-3">
            <label class="form-label">Message to customers <span class="text-muted">(optional)</span></label>
            <input name="notice" class="form-control" maxlength="255" placeholder="e.g. Delivery within Kitale town only · Pay on delivery" value="<?php echo htmlspecialchars($settings['notice'] ?? ''); ?>">
          </div>
          <button class="btn btn-primary">Save</button>
        </form>
        <hr>
        <p class="small text-muted mb-0"><i class="fas fa-circle-info me-1"></i>Online orders arrive as <strong>Awaiting payment</strong> with an <span class="badge bg-primary">Online</span> badge on the Payments and Orders pages. Customers only see dishes available at that time, and track their order with the receipt number.</p>
      </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Table QR cards</h2>
        <p class="text-muted small mb-3">One QR per table — scanning it fills in the table number for dine-in orders.</p>
        <div class="d-flex gap-2 align-items-center mb-3">
          <span class="small">Tables</span>
          <input type="number" min="1" class="form-control form-control-sm" id="tFrom" value="1" style="width:80px;">
          <span class="small">to</span>
          <input type="number" min="1" class="form-control form-control-sm" id="tTo" value="10" style="width:80px;">
          <button class="btn btn-sm btn-primary" id="printTables" type="button"><i class="fas fa-print me-1"></i>Print cards</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
  var link = <?php echo json_encode($link); ?>, shop = <?php echo json_encode($shopName); ?>;
  new QRCode(document.getElementById('qrMain'), {text: link, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M});

  document.getElementById('copyBtn').onclick = function () {
    var i = document.getElementById('linkIn'); i.select();
    (navigator.clipboard ? navigator.clipboard.writeText(link) : Promise.resolve(document.execCommand('copy'))).then(function () {
      document.getElementById('copyBtn').innerHTML = '<i class="fas fa-check"></i>';
    });
  };
  document.getElementById('dlBtn').onclick = function () {
    var c = document.querySelector('#qrMain canvas'), a = document.createElement('a');
    a.href = c.toDataURL('image/png'); a.download = 'menu-qr.png'; a.click();
  };
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  document.getElementById('printTables').onclick = function () {
    var from = parseInt(document.getElementById('tFrom').value, 10) || 1, to = parseInt(document.getElementById('tTo').value, 10) || from;
    if (to < from || to - from > 99) { alert('Choose up to 100 tables.'); return; }
    var w = window.open('', '_blank'), box = document.createElement('div'), cards = '';
    for (var t = from; t <= to; t++) {
      new QRCode(box, {text: link + '&t=' + t, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M});
      var src = box.querySelector('canvas').toDataURL('image/png'); box.innerHTML = '';
      cards += '<div class="card"><div class="shop">' + esc(shop) + '</div><img src="' + src + '"><div class="t">Table ' + t + '</div><div class="h">Scan to see the menu &amp; order</div></div>';
    }
    w.document.write('<!doctype html><html><head><title>Table QR cards</title><style>body{font-family:sans-serif;margin:0;padding:10px;}'
      + '.grid{display:flex;flex-wrap:wrap;gap:12px;}.card{border:2px dashed #999;border-radius:12px;padding:14px;width:230px;text-align:center;page-break-inside:avoid;}'
      + '.shop{font-weight:bold;font-size:16px;margin-bottom:6px;}.t{font-size:22px;font-weight:bold;margin-top:6px;}.h{font-size:12px;color:#555;}img{width:200px;height:200px;}</style></head>'
      + '<body><div class="grid">' + cards + '</div><script>window.onload=function(){window.print();}<\/script></body></html>');
    w.document.close();
  };
})();
</script>
<?php
rest_render('Share menu', ob_get_clean());
