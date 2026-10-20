<?php
// public/support/quotes/index.php — quotation requests from the landing page.
require_once __DIR__ . '/../_boot.php';

if (support_posted() && ($_POST['action'] ?? '') === 'status') {
    Quote::setStatus((int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? ''));
    support_flash('Updated.');
    header('Location: ' . public_url('support/quotes/' . (isset($_GET['s']) ? '?s=' . urlencode($_GET['s']) : '')));
    exit;
}

$filter = isset($_GET['s'], Quote::STATUSES[$_GET['s']]) ? $_GET['s'] : null;
$rows = Quote::all($filter);
$catalog = Features::catalog();
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$page_title = 'Quote requests';
$nav = 'quotes';
ob_start();
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-sm <?php echo $filter === null ? 'btn-dark' : 'btn-outline-secondary'; ?>" href="<?php echo public_url('support/quotes/'); ?>">All</a>
  <?php foreach (Quote::STATUSES as $k => $l): ?>
    <a class="btn btn-sm <?php echo $filter === $k ? 'btn-dark' : 'btn-outline-secondary'; ?>" href="<?php echo public_url('support/quotes/?s=' . $k); ?>"><?php echo $l; ?></a>
  <?php endforeach; ?>
</div>
<div class="s-card">
  <?php if (!$rows): ?><p class="text-muted mb-0">No quote requests<?php echo $filter ? ' here' : ' yet'; ?>.</p><?php else: ?>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
    <thead><tr class="text-muted small"><th>Received</th><th>Name</th><th>Contact</th><th>Package</th><th>Shop / business</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="text-nowrap"><?php echo date('j M Y H:i', strtotime($r['created_at'])); ?></td>
        <td class="fw-semibold"><?php echo $h($r['name']); ?></td>
        <td><a href="mailto:<?php echo $h($r['email']); ?>"><?php echo $h($r['email']); ?></a>
          <?php if ($r['phone']): ?><br><a href="tel:<?php echo $h(preg_replace('/[^0-9+]/', '', $r['phone'])); ?>"><?php echo $h($r['phone']); ?></a><?php endif; ?></td>
        <td><?php echo $h($catalog[$r['package_key']][0] ?? $r['package_key']); ?></td>
        <td><?php echo $h($r['shop_name'] ?: '—'); ?><?php if ($r['business']): ?><div class="text-muted small"><?php echo $h($r['business']); ?></div><?php endif; ?></td>
        <td>
          <form method="post" class="m-0"><?php echo support_csrf_field(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:140px">
              <?php foreach (Quote::STATUSES as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $r['status'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?>
            </select>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
