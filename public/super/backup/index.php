<?php
// public/super/backup/index.php — Settings → Export & import (every package): the owner downloads all their
// shop's data as one file (Export), and brings a file back in (Import). Use it
// to keep a backup, or to move the shop to another install and back.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::primaryOwner();

$__impAccount = Features::accountId((int) TenantContext::tenantId());
if (($_GET['export'] ?? '') === '1') {
    ShopExport::download($__impAccount);
}

$_SESSION['import_csrf'] ??= bin2hex(random_bytes(16));
$__impUrl = public_url('super/backup/');
$__impCsrf = $_SESSION['import_csrf'];

$page_title = 'Export & import';
ob_start();
?>
<h1 class="h4 mb-1">Export &amp; import your shop</h1>
<p class="text-muted mb-4">Two steps: <strong>Export</strong> downloads everything in your shop as one file. <strong>Import</strong> brings that file back into this account, or into your account on another Dazu POS.</p>

<div class="row g-3 mb-4">
  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
      <div class="card-body p-4">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge rounded-pill bg-success">1</span>
          <h2 class="h6 fw-bold mb-0"><i class="fas fa-file-export me-1 text-success"></i>Export my shop</h2>
        </div>
        <p class="text-muted small">Everything your package holds — products and stock, suppliers, customers, staff (with their PINs), sales, receipts, payments, credit book, expenses, attendance, services, devices &amp; warranties, restaurant menu, stock, orders and table bookings, rooms and guest stays — for your main shop and every branch.</p>
        <a class="btn btn-success" href="<?php echo public_url('super/backup/?export=1'); ?>"><i class="fas fa-download me-1"></i>Download export file</a>
        <div class="text-muted small mt-2">Saves a <code>.sql</code> file to this device. Keep it safe — it holds your staff logins.</div>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
      <div class="card-body p-4">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge rounded-pill bg-primary">2</span>
          <h2 class="h6 fw-bold mb-0"><i class="fas fa-file-import me-1 text-primary"></i>Import a file</h2>
        </div>
        <p class="text-muted small mb-1">Upload an export file below, check what it contains, then import. Works with files from this page and with older POS databases.</p>
        <p class="small mb-0"><i class="fas fa-triangle-exclamation text-warning me-1"></i>Import <em>adds</em> to what's already here. Staff already on this account are matched by email, but products and sales are added again — so import into a shop that doesn't have that data yet.</p>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/shop_import.php'; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
