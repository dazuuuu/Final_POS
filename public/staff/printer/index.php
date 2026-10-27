<?php
// public/staff/printer/index.php — connect this device to a receipt printer
// (Bluetooth, Wi-Fi/LAN or the device's own printers) and turn on automatic
// printing after each sale. Settings are saved in this browser, because the
// printer belongs to the till device, not the account.
require_once __DIR__ . '/../../../app/app.php';
require_once ROOT_PATH . '/app/helpers/uploads.php';
PageGuard::auth();

$pdo    = Database::pdo();
$tenant = (new Models\TenantModel($pdo))->find(TenantContext::tenantId());
$isStaffViewer = TenantContext::role() === 'staff';
$page_title = 'Printer setup';

ob_start();
?>
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
<meta name="taso-print-endpoint" content="<?php echo htmlspecialchars(public_url('api/print/network.php')); ?>">
<?php $localServer = PrintLocal::server(); if ($localServer): ?><meta name="taso-print-local" content="1"><?php endif; ?>
<style>
  @media print { body *{visibility:hidden !important;} #sample,#sample *{visibility:visible !important;} #sample{position:absolute;left:0;top:0;width:100%;border:0 !important;} }
  .pmode{cursor:pointer;border:2px solid #e2e8f0;border-radius:12px;padding:12px 14px;display:flex;gap:12px;align-items:flex-start;height:100%;}
  .pmode:hover{border-color:#94a3b8;}
  .pmode.active{border-color:#2563eb;background:#eff6ff;}
  .pmode i{font-size:20px;width:24px;text-align:center;margin-top:2px;color:#2563eb;}
</style>
<div class="row g-4">
  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Printer setup</h2>
        <p class="text-muted small mb-3">Set up once on each device you sell from. When a sale is completed, the receipt prints automatically.</p>

        <div class="row g-2 mb-3" id="modes">
          <?php foreach ([
              ['bluetooth', 'fa-brands fa-bluetooth-b', 'Bluetooth printer', 'Thermal printers (BLE). Chrome on Android, Windows or Mac.'],
              ['wifi',      'fas fa-wifi',             'Wi-Fi printer', 'A printer on the shop Wi-Fi. Add it to this phone or computer once — then every receipt prints with one tap.'],
              ['system',    'fas fa-print',            'USB / other printer', 'Any printer already installed on this phone or computer (USB, AirPrint, shared).'],
              ...($localServer ? [['network', 'fas fa-network-wired', 'Wi-Fi printer by IP (silent)', 'This system runs on a computer in the shop: send receipts straight to the printer\'s IP, no dialog.']] : []),
              ['rawbt',     'fas fa-mobile-screen',    'Bluetooth via RawBT (Android)', 'Older Bluetooth printers paired in Android settings.'],
              ['off',       'fas fa-ban',              'No printer', 'Print button opens the normal print dialog.'],
          ] as [$val, $icon, $label, $hint]):
              // Basic (20k): Bluetooth and USB / device printing — Wi-Fi is Pro.
              $locked = Features::isBasic() && ($val === 'network' || $val === 'wifi'); ?>
          <div class="col-12 col-md-6">
            <?php if ($locked): ?>
            <a class="pmode text-decoration-none text-reset" style="opacity:.6" href="<?php echo public_url('super/upgrade.php?f=' . urlencode($label)); ?>">
              <i class="<?php echo $icon; ?>"></i>
              <span><span class="fw-semibold d-block"><?php echo $label; ?> <span class="badge bg-warning text-dark">Pro</span></span><span class="text-muted small"><?php echo $hint; ?></span></span>
            </a>
            <?php else: ?>
            <label class="pmode" data-mode="<?php echo $val; ?>">
              <input type="radio" name="mode" value="<?php echo $val; ?>" class="d-none">
              <i class="<?php echo $icon; ?>"></i>
              <span><span class="fw-semibold d-block"><?php echo $label; ?></span><span class="text-muted small"><?php echo $val === 'system' && Features::isBasic() ? 'USB printer (or any printer installed on this device).' : $hint; ?></span></span>
            </label>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="alert alert-light border small mb-3 d-none" data-for="bluetooth">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span>Printer: <strong id="btName">none connected</strong></span>
            <button type="button" class="btn btn-sm btn-primary ms-auto" id="btPair"><i class="fab fa-bluetooth-b me-1"></i>Find printer</button>
          </div>
          <div class="text-muted mt-2">Turn the printer on, then tap <em>Find printer</em> and pick it from the list. iPhones/iPads can't print over Bluetooth from a browser — use <em>Device printer</em> with an AirPrint printer instead.</div>
        </div>

        <div class="d-none" data-for="network">
          <div class="row g-2 mb-2">
            <div class="col-8"><label class="form-label small fw-semibold mb-1">Printer IP address</label><input class="form-control" id="ip" placeholder="192.168.1.50"></div>
            <div class="col-4"><label class="form-label small fw-semibold mb-1">Port</label><input class="form-control" id="port" type="number" min="9100" max="9109" value="9100"></div>
          </div>
          <div class="form-text mb-3">Print a self-test page on the printer (hold the feed button while switching it on) to find its IP. This works when the system runs on a computer in the shop. If it's online, connect the printer to this device's Wi-Fi printing instead and choose <em>Device printer</em>.</div>
        </div>

        <div class="alert alert-light border small mb-3 d-none" data-for="wifi">
          <div class="fw-semibold mb-1">Add the Wi-Fi printer to this device (once):</div>
          <ol class="mb-2 ps-3">
            <li>Connect the printer to the shop Wi-Fi (see its manual) and connect this phone / computer to the <b>same Wi-Fi</b>.</li>
            <li><b>Android:</b> Settings → Connected devices → Printing → turn on <em>Default print service</em> (or install <em>Mopria</em>); the printer appears by itself. <b>iPhone / iPad:</b> nothing to do — AirPrint printers appear automatically. <b>Windows / Mac:</b> Settings → Printers → Add printer.</li>
            <li>Tap <em>Test print</em> below, pick the printer, and print. Your choice is remembered.</li>
          </ol>
          After a sale a small receipt preview opens — tap <b>Print</b> and it goes to this printer.
        </div>

        <div class="alert alert-light border small mb-3 d-none" data-for="rawbt">
          Install <strong>RawBT</strong> from the Play Store, pair your printer in Android Bluetooth settings, and select it inside RawBT. Receipts are then handed to RawBT automatically.
        </div>

        <div class="alert alert-light border small mb-3 d-none" data-for="system">
          After a sale a small receipt preview opens with a Print button — choose your printer once and it's remembered. For fully silent printing on a computer, start Chrome with <code>--kiosk-printing</code> and set your receipt printer as the default.
        </div>

        <div class="row g-3 mb-3" data-for="bluetooth network rawbt">
          <div class="col-6 col-md-4">
            <label class="form-label small fw-semibold mb-1">Paper width</label>
            <select class="form-select" id="width"><option value="58">58 mm</option><option value="80">80 mm</option></select>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label small fw-semibold mb-1">Copies</label>
            <input class="form-control" id="copies" type="number" min="1" max="3" value="1">
          </div>
          <div class="col-12 col-md-4 d-flex flex-column justify-content-end">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="cut"><label class="form-check-label small" for="cut">Cut paper</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="drawer"><label class="form-check-label small" for="drawer">Open cash drawer</label></div>
          </div>
        </div>

        <div class="form-check form-switch mb-4" data-for="bluetooth network rawbt system wifi">
          <input class="form-check-input" type="checkbox" id="autoPrint">
          <label class="form-check-label fw-semibold" for="autoPrint">Print automatically after every sale</label>
        </div>

        <div class="d-flex gap-2">
          <button type="button" class="btn btn-primary" id="save">Save</button>
          <button type="button" class="btn btn-outline-secondary" id="test"><i class="fas fa-print me-1"></i>Test print</button>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <div class="text-muted small text-uppercase mb-2">Test receipt</div>
        <div class="sheet border rounded p-3" id="sample" style="font-size:13px;">
          <div style="text-align:center;border-bottom:2px dashed #cbd5e1;padding-bottom:8px;margin-bottom:8px;">
            <div style="font-size:16px;font-weight:700;"><?php echo htmlspecialchars($tenant['name'] ?? 'My Shop'); ?></div>
            <div style="font-size:12px;">Printer test</div>
            <div style="font-size:12px;" id="sampleDate"></div>
          </div>
          <table style="width:100%;font-size:13px;">
            <tr><td>Sample item</td><td style="text-align:center;">2</td><td style="text-align:right;">200.00</td></tr>
            <tr><td>Another item</td><td style="text-align:center;">1</td><td style="text-align:right;">50.00</td></tr>
          </table>
          <table style="width:100%;font-size:14px;border-top:2px dashed #cbd5e1;margin-top:6px;">
            <tr><td style="font-weight:700;">Total</td><td style="text-align:right;font-weight:700;">250.00</td></tr>
          </table>
          <p style="text-align:center;font-size:12px;margin-top:10px;">If you can read this, printing works.</p>
        </div>
      </div>
    </div>
  </div>
</div>
<?php if (Features::isBasic()): ?><meta name="taso-print-basic" content="1"><?php endif; ?>
<script src="<?php echo htmlspecialchars(public_url('assets/js/printer.js')); ?>"></script>
<script>
(function () {
  var cfg = TasoPrinter.load();
  var $ = function (id) { return document.getElementById(id); };
  document.getElementById('sampleDate').textContent = new Date().toLocaleString();

  function render() {
    document.querySelectorAll('.pmode').forEach(function (el) { el.classList.toggle('active', el.dataset.mode === cfg.mode); });
    document.querySelectorAll('[data-for]').forEach(function (el) {
      el.classList.toggle('d-none', el.dataset.for.split(' ').indexOf(cfg.mode) < 0);
    });
    $('btName').textContent = cfg.btName || 'none connected';
  }
  function read() {
    cfg.ip = $('ip').value.trim(); cfg.port = Number($('port').value) || 9100;
    cfg.width = Number($('width').value); cfg.copies = Math.min(3, Math.max(1, Number($('copies').value) || 1));
    cfg.cut = $('cut').checked; cfg.drawer = $('drawer').checked; cfg.autoPrint = $('autoPrint').checked;
  }

  $('ip').value = cfg.ip; $('port').value = cfg.port; $('width').value = String(cfg.width);
  $('copies').value = cfg.copies; $('cut').checked = cfg.cut; $('drawer').checked = cfg.drawer; $('autoPrint').checked = cfg.autoPrint;

  document.querySelectorAll('.pmode').forEach(function (el) {
    el.addEventListener('click', function () { cfg.mode = el.dataset.mode; render(); });
  });
  $('btPair').addEventListener('click', async function () {
    try { cfg.btName = await TasoPrinter.pairBluetooth(); render(); TasoPrinter.toast('Connected to ' + cfg.btName + '.', true); }
    catch (e) { if (e.name !== 'NotFoundError') TasoPrinter.toast(e.message, false); }
  });
  $('save').addEventListener('click', function () {
    read();
    if (cfg.mode === 'network' && !cfg.ip) { TasoPrinter.toast('Enter the printer IP address.', false); return; }
    var saved = TasoPrinter.load(); cfg.btName = saved.btName || cfg.btName;
    TasoPrinter.save(cfg); TasoPrinter.toast('Printer settings saved on this device.', true);
  });
  $('test').addEventListener('click', async function () {
    read();
    try {
      await TasoPrinter.print({ user: true, config: cfg, element: $('sample') });
      if (cfg.mode === 'bluetooth' || cfg.mode === 'network') { cfg.btName = TasoPrinter.load().btName; render(); TasoPrinter.toast('Test sent to printer.', true); }
    } catch (e) { if (e.name !== 'NotFoundError') TasoPrinter.toast(e.message || 'Printing failed.', false); }
  });
  render();
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/' . ($isStaffViewer ? 'staff' : 'tenants') . '/layout.php';
