<?php
// public/super/whatsapp/index.php (and staff/whatsapp/) — "My WhatsApp": each person saves the WhatsApp
// number they send receipts and credit overviews from. Owners, admins and staff each have their own.
require_once __DIR__ . '/../../../app/app.php';
require_once ROOT_PATH . '/app/helpers/uploads.php';
PageGuard::auth();
$isStaff = TenantContext::role() === 'staff';
$self = public_url(($isStaff ? 'staff' : 'super') . '/whatsapp/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash']['error'] = 'Session expired — reload the page and try again.';
    } elseif (WhatsApp::saveMine(trim((string) ($_POST['number'] ?? '')))) {
        $_SESSION['flash']['success'] = trim((string) ($_POST['number'] ?? '')) === '' ? 'Your WhatsApp number was removed.' : 'Your WhatsApp number is saved.';
    } else {
        $_SESSION['flash']['error'] = 'That doesn\'t look like a phone number — e.g. 0712 345 678.';
    }
    header('Location: ' . $self);
    exit;
}
$mine = WhatsApp::mine();
$page_title = 'My WhatsApp';
ob_start();
?>
<div class="row"><div class="col-12 col-lg-7">
  <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
    <div class="d-flex gap-3 align-items-start mb-3">
      <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:46px;height:46px;background:#e7f9ee;color:#25d366;"><i class="fab fa-whatsapp fa-lg"></i></div>
      <div><h2 class="h6 fw-bold mb-1">My WhatsApp number</h2>
        <p class="small text-muted mb-0">Receipts and credit overviews open in WhatsApp, ready to send to the client. Save the number you send from — it is added to the message so clients know where to reply. This is your own number; other people on the account set theirs.</p></div>
    </div>
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
      <div class="col-12 col-sm"><label class="form-label small mb-1">WhatsApp number</label>
        <input type="tel" name="number" class="form-control" inputmode="tel" placeholder="e.g. 0712 345 678" value="<?php echo $mine ? htmlspecialchars('0' . substr($mine, 3)) : ''; ?>"></div>
      <div class="col-auto"><button class="btn btn-success"><i class="fas fa-check me-1"></i>Save</button></div>
    </form>
    <?php if ($mine): ?>
      <div class="small text-muted mt-2">Saved: <strong>+<?php echo htmlspecialchars($mine); ?></strong> · <a target="_blank" rel="noopener" href="https://wa.me/<?php echo htmlspecialchars($mine); ?>?text=<?php echo rawurlencode('Test from my POS'); ?>">send myself a test</a> · clear the box and press Save to remove it.</div>
    <?php endif; ?>
    <hr>
    <div class="small text-muted"><i class="fas fa-circle-info me-1"></i>To send: open any receipt (or the receipt popup after a sale), tap <strong>WhatsApp</strong>, type the client's number and press Send. WhatsApp opens on this device with the message ready — you press send there.</div>
  </div></div>
</div></div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/' . ($isStaff ? 'staff' : 'tenants') . '/layout.php';
