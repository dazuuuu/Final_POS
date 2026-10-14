<?php
// public/super/sales/duplicates.php — find and fix duplicate sales.
// A duplicate is a sale with the same staff, the same items and the same
// total as one made just before it (a double-tapped "Pay", a re-sent
// receipt, or one copied over from an old POS). Each group keeps the first
// sale; the copies can be reversed one by one or all at once — reversing
// returns the stock and takes the money out of sales and profit reports
// (SaleModel / OrderModel::reverseDuplicate).
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::SALES_VIEW);
if (TenantContext::role() !== 'tenant_owner') {
    header('Location: ' . public_url('super/sales/'));
    exit;
}

$pdo = Database::pdo();
$tid = (int) TenantContext::tenantId();
$SA = new Models\SaleModel($pdo);
$OR = new Models\OrderModel($pdo);

$windows = [2 => '2 minutes', 10 => '10 minutes', 30 => '30 minutes', 60 => '1 hour', 1440 => 'same day'];
$within = isset($windows[(int) ($_GET['within'] ?? 0)]) ? (int) $_GET['within'] : 10;
$ranges = ['week' => 7, 'month' => 30, 'quarter' => 90, 'all' => 0];
$range = isset($ranges[$_GET['range'] ?? '']) ? $_GET['range'] : 'month';
$self = public_url('super/sales/duplicates.php?' . http_build_query(['within' => $within, 'range' => $range]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $done = 0;
    $errors = [];
    foreach ((array) ($_POST['pick'] ?? []) as $key) {
        if (!preg_match('/^(sale|order):(\d+)$/', (string) $key, $m)) continue;
        $res = $m[1] === 'sale'
            ? $SA->reverseDuplicate((int) $m[2], (int) TenantContext::userId())
            : $OR->reverseDuplicate((int) $m[2], (int) TenantContext::userId());
        $res['ok'] ? $done++ : $errors[] = $res['error'] ?? 'Could not reverse one sale.';
    }
    if ($done) $_SESSION['flash']['success'] = "$done duplicate sale" . ($done === 1 ? '' : 's') . ' reversed — stock returned and reports corrected.';
    if ($errors) $_SESSION['flash']['error'] = implode(' ', array_unique($errors));
    header('Location: ' . $self);
    exit;
}

// ---- load completed sales + paid tabs in the range, with their items ----
$days = $ranges[$range];
$since = $days ? date('Y-m-d 00:00:00', strtotime("-$days days")) : '1970-01-01';

$st = $pdo->prepare(
    "SELECT 'sale' AS source, s.id, s.receipt_number, s.total, s.payment_method, s.customer_name, s.staff_id,
            s.created_at AS at, u.username AS staff_name, 1 AS fixable
       FROM sales s LEFT JOIN users u ON u.id = s.staff_id
      WHERE s.tenant_id = ? AND s.status = 'completed' AND s.created_at >= ?"
);
$st->execute([$tid, $since]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$st = $pdo->prepare(
    "SELECT 'order' AS source, o.id, o.receipt_number, o.total, o.payment_method, c.name AS customer_name,
            COALESCE(o.paid_by, o.opened_by) AS staff_id, COALESCE(o.paid_at, o.created_at) AS at,
            u.username AS staff_name, o.channel = 'walkin' AS fixable
       FROM orders o LEFT JOIN users u ON u.id = COALESCE(o.paid_by, o.opened_by)
                     LEFT JOIN customers c ON c.id = o.customer_id
      WHERE o.tenant_id = ? AND o.status = 'paid' AND COALESCE(o.paid_at, o.created_at) >= ?"
);
$st->execute([$tid, $since]);
$rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));

$items = ['sale' => [], 'order' => []];
foreach (['sale' => ['sale_items', 'sale_id'], 'order' => ['order_items', 'order_id']] as $k => [$table, $fk]) {
    $ids = array_column(array_filter($rows, fn($r) => $r['source'] === $k), 'id');
    foreach (array_chunk($ids, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $q = $pdo->prepare("SELECT $fk AS pid, product_name, quantity FROM $table WHERE tenant_id = ? AND $fk IN ($in) AND quantity > 0 ORDER BY product_name");
        $q->execute(array_merge([$tid], $chunk));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $i) $items[$k][(int) $i['pid']][] = $i;
    }
}

// ---- group: same staff + total + items, each within $within minutes of the previous ----
usort($rows, fn($a, $b) => strcmp($a['at'], $b['at']));
$bySig = [];
foreach ($rows as $r) {
    $r['items'] = $items[$r['source']][(int) $r['id']] ?? [];
    $sig = $r['staff_id'] . '|' . number_format((float) $r['total'], 2, '.', '') . '|'
         . implode(';', array_map(fn($i) => mb_strtolower($i['product_name']) . 'x' . (float) $i['quantity'], $r['items']));
    $bySig[$sig][] = $r;
}
$groups = [];
foreach ($bySig as $list) {
    $cur = [];
    foreach ($list as $r) {
        if ($cur && strtotime($r['at']) - strtotime(end($cur)['at']) > $within * 60) {
            if (count($cur) > 1) $groups[] = $cur;
            $cur = [];
        }
        $cur[] = $r;
    }
    if (count($cur) > 1) $groups[] = $cur;
}
usort($groups, fn($a, $b) => strcmp($b[0]['at'], $a[0]['at']));
$copies = array_sum(array_map(fn($g) => count($g) - 1, $groups));
$extra = array_sum(array_map(fn($g) => array_sum(array_column(array_slice($g, 1), 'total')), $groups));

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$cur = Database::pdo()->query('SELECT currency FROM tenants WHERE id = ' . $tid)->fetchColumn() ?: 'KES';
$page_title = 'Duplicate sales';
ob_start();
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h1 class="h5 mb-0 fw-bold"><i class="fas fa-clone me-1"></i>Duplicate sales</h1>
  <a class="btn btn-sm btn-outline-secondary" href="<?php echo public_url('super/sales/'); ?>"><i class="fas fa-arrow-left me-1"></i>Sales</a>
</div>

<form method="get" class="card border-0 shadow-sm mb-3" style="border-radius:12px"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
  <div><label class="form-label small mb-1">Same sale repeated within</label>
    <select name="within" class="form-select form-select-sm"><?php foreach ($windows as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $k === $within ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label small mb-1">Look back</label>
    <select name="range" class="form-select form-select-sm"><?php foreach (['week' => 'Last 7 days', 'month' => 'Last 30 days', 'quarter' => 'Last 90 days', 'all' => 'All time'] as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $k === $range ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-sm btn-primary">Scan</button>
  <p class="small text-muted mb-0 ms-md-auto" style="max-width:380px">A duplicate = same staff, same items, same total, made within the time above. The first sale is kept; the copies are ticked.</p>
</div></form>

<?php if (!$groups): ?>
  <div class="alert alert-success"><i class="fas fa-circle-check me-1"></i>No duplicate sales found in this period.</div>
<?php else: ?>
<form method="post" onsubmit="return confirm('Reverse the ticked sales? Their stock is returned and their money is removed from sales and profit reports.');">
  <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span><strong><?php echo $copies; ?></strong> possible duplicate<?php echo $copies === 1 ? '' : 's'; ?> in <?php echo count($groups); ?> group<?php echo count($groups) === 1 ? '' : 's'; ?> ·
      <?php echo $h($cur); ?> <?php echo number_format($extra, 0); ?> counted twice</span>
    <button class="btn btn-sm btn-danger"><i class="fas fa-wand-magic-sparkles me-1"></i>Reverse ticked duplicates</button>
  </div>
  <?php foreach ($groups as $g): ?>
  <div class="card border-0 shadow-sm mb-2" style="border-radius:12px"><div class="card-body p-3">
    <div class="small text-muted mb-2"><?php echo $h($g[0]['staff_name'] ?: 'Unknown staff'); ?> ·
      <?php echo $h(implode(', ', array_map(fn($i) => rtrim(rtrim(number_format((float) $i['quantity'], 2), '0'), '.') . ' × ' . $i['product_name'], $g[0]['items'])) ?: 'no items'); ?></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr class="small text-muted text-uppercase"><th style="width:32px"></th><th>Receipt</th><th>Date &amp; time</th><th>Customer</th><th>Pay</th><th class="text-end">Total</th></tr></thead>
      <tbody>
      <?php foreach ($g as $n => $r): $key = $r['source'] . ':' . $r['id']; ?>
        <tr class="<?php echo $n ? '' : 'table-success'; ?>">
          <td><?php if (!$n): ?><span class="badge bg-success">Keep</span>
              <?php elseif ($r['fixable']): ?><input class="form-check-input" type="checkbox" name="pick[]" value="<?php echo $h($key); ?>" checked>
              <?php else: ?><span class="badge bg-secondary" title="Open tabs can't be auto-reversed — edit them instead">Tab</span><?php endif; ?></td>
          <td class="small fw-semibold"><a href="<?php echo public_url(($r['source'] === 'sale' ? 'super/sales/receipt.php?id=' : 'super/orders/receipt.php?id=') . (int) $r['id']); ?>"><?php echo $h($r['receipt_number']); ?></a></td>
          <td class="small text-nowrap"><?php echo date('D j M Y, g:i:s a', strtotime($r['at'])); ?>
            <?php if ($n): ?><span class="text-danger">(+<?php echo max(0, strtotime($r['at']) - strtotime($g[$n - 1]['at'])); ?>s)</span><?php endif; ?></td>
          <td class="small"><?php echo $h($r['customer_name'] ?: '—'); ?></td>
          <td class="small"><?php echo $h(ucfirst((string) $r['payment_method'])); ?></td>
          <td class="text-end fw-semibold"><?php echo $h($cur); ?> <?php echo number_format((float) $r['total'], 0); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div></div>
  <?php endforeach; ?>
</form>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
