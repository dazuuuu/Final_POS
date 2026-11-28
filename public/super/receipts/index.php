<?php
// public/super/receipts/index.php — Receipts: how many times each receipt
// was printed (and when/by whom), whether it was submitted/handed over (and
// to whom), and its edit history (who changed what, and when).
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth(Capabilities::SALES_VIEW);
require_once ROOT_PATH . '/app/helpers/uploads.php';

$pdo = Database::pdo();
$tid = (int) TenantContext::tenantId();
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

$type = $_GET['type'] ?? 'all';
$type = in_array($type, ['all', 'sale', 'order'], true) ? $type : 'all';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }

$rows = [];
if ($type !== 'order') {
    $st = $pdo->prepare(
        "SELECT s.id, 'sale' AS entity_type, s.receipt_number, s.total, s.customer_name, s.staff_id, s.edit_count, s.edited_at, s.created_at,
                u.username AS staff_name
           FROM sales s LEFT JOIN users u ON u.id = s.staff_id
          WHERE s.tenant_id = ? AND s.created_at >= ? AND s.created_at < ? + INTERVAL 1 DAY
          ORDER BY s.created_at DESC LIMIT 300"
    );
    $st->execute([$tid, $from, $to]);
    $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
}
if ($type !== 'sale') {
    $st = $pdo->prepare(
        "SELECT o.id, 'order' AS entity_type, o.receipt_number, o.total, o.table_name AS customer_name, o.opened_by AS staff_id, o.edit_count, o.edited_at, o.created_at,
                u.username AS staff_name
           FROM orders o LEFT JOIN users u ON u.id = o.opened_by
          WHERE o.tenant_id = ? AND o.created_at >= ? AND o.created_at < ? + INTERVAL 1 DAY
          ORDER BY o.created_at DESC LIMIT 300"
    );
    $st->execute([$tid, $from, $to]);
    $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
}
usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

$statuses = ReceiptTracking::allStatuses($tid);
$AL = new Models\AuditLogModel($pdo);

$detail = null;
if (!empty($_GET['view'])) {
    [$dt, $did] = explode(':', $_GET['view'], 2) + [1 => 0];
    if (isset(ReceiptTracking::TYPES[$dt])) {
        $detail = ['type' => $dt, 'id' => (int) $did, 'history' => array_filter($AL->recent($dt, 200), fn($r) => (int) $r['entity_id'] === (int) $did)];
    }
}

$page_title = 'Receipts';
ob_start();
?>
<div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-3">
  <form class="row g-2 align-items-end">
    <div class="col-auto">
      <label class="form-label small mb-1">Type</label>
      <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>All</option>
        <option value="sale" <?php echo $type === 'sale' ? 'selected' : ''; ?>>Sales</option>
        <option value="order" <?php echo $type === 'order' ? 'selected' : ''; ?>>Orders</option>
      </select>
    </div>
    <div class="col-auto"><label class="form-label small mb-1">From</label><input type="date" name="from" value="<?php echo $h($from); ?>" class="form-control form-control-sm"></div>
    <div class="col-auto"><label class="form-label small mb-1">To</label><input type="date" name="to" value="<?php echo $h($to); ?>" class="form-control form-control-sm"></div>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Filter</button></div>
  </form>
</div></div>

<?php if ($detail): ?>
<div class="card border-0 shadow-sm mb-3" style="border-radius:14px;"><div class="card-body p-3">
  <h2 class="h6 fw-bold mb-3">Edit history — <?php echo $h(ReceiptTracking::TYPES[$detail['type']]); ?> #<?php echo $detail['id']; ?></h2>
  <?php if (!$detail['history']): ?><p class="small text-muted mb-0">No edits recorded.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th>When</th><th>By</th><th>Changes</th></tr></thead>
    <tbody>
    <?php foreach ($detail['history'] as $r): $ch = json_decode($r['changes'] ?? '[]', true) ?: []; ?>
      <tr>
        <td class="text-nowrap small"><?php echo date('j M, H:i', strtotime($r['created_at'])); ?></td>
        <td class="small"><?php echo $h($r['username'] ?? '—'); ?></td>
        <td class="small">
          <?php foreach ($ch as $c): ?>
            <div><?php echo $h($c['label']); ?>: <span class="text-muted"><?php echo $h($c['from']); ?></span> → <?php echo $h($c['to']); ?></div>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <a href="<?php echo public_url('super/receipts/?type=' . $type . '&from=' . $from . '&to=' . $to); ?>" class="btn btn-sm btn-link">← Back to list</a>
</div></div>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-3">
  <h2 class="h6 fw-bold mb-3">Receipts <span class="badge bg-light text-dark"><?php echo count($rows); ?></span></h2>
  <?php if (!$rows): ?><p class="small text-muted mb-0">Nothing in this period.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th>Receipt</th><th>Type</th><th>Customer</th><th class="text-end">Total</th><th>By</th><th>Printed</th><th>Submitted</th><th>Edits</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $key = $r['entity_type'] . ':' . $r['id'];
        $s = $statuses[$key] ?? null;
    ?>
      <tr>
        <td class="small fw-semibold text-nowrap"><?php echo $h($r['receipt_number']); ?><div class="text-muted"><?php echo date('j M, H:i', strtotime($r['created_at'])); ?></div></td>
        <td class="small"><?php echo $h(ReceiptTracking::TYPES[$r['entity_type']] ?? $r['entity_type']); ?></td>
        <td class="small"><?php echo $h($r['customer_name'] ?: '—'); ?></td>
        <td class="text-end small">KES <?php echo number_format((float) $r['total'], 0); ?></td>
        <td class="small"><?php echo $h($r['staff_name'] ?? '—'); ?></td>
        <td class="small">
          <?php if ($s && (int) $s['print_count'] > 0): ?>
            <?php echo (int) $s['print_count']; ?>× <span class="text-muted">(<?php echo $h($s['printed_by_name'] ?? ''); ?>, <?php echo date('j M H:i', strtotime($s['last_printed_at'])); ?>)</span>
          <?php else: ?><span class="text-muted">Not printed</span><?php endif; ?>
        </td>
        <td class="small">
          <?php if ($s && !empty($s['submitted_at'])): ?>
            <span class="text-success">To <?php echo $h($s['submitted_to']); ?></span>
          <?php else: ?><span class="text-muted">Not submitted</span><?php endif; ?>
        </td>
        <td class="small">
          <?php if ((int) $r['edit_count'] > 0): ?>
            <a href="<?php echo public_url('super/receipts/?view=' . $key . '&type=' . $type . '&from=' . $from . '&to=' . $to); ?>"><?php echo (int) $r['edit_count']; ?>×</a>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div></div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
