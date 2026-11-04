<?php
// public/super/day/index.php — opening & closing sales, and sales tracking.
// Everyone sees their own day (opening figure, what they've sold, close day).
// Owners — and admins the owner allowed to see finances — also see every
// person's opening/closing and the sales per staff and per product over any
// date range. ?excel=1 / ?pdf=1 download the same report.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

$pdo = Database::pdo();
$tenantId = (int) TenantContext::tenantId();
$me = (int) TenantContext::userId();
$everyone = PageGuard::seesMoney();
$isStaff = TenantContext::role() === 'staff';
$tenant = (new Models\TenantModel($pdo))->find($tenantId);
$cur = $tenant['currency'] ?? 'KES';
$money = fn($n) => htmlspecialchars($cur) . ' ' . number_format((float) $n, 0);
$qty = fn($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.');
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$isOwner = TenantContext::isPrimaryOwner(); // only the business owner sets the hours
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hours' && $isOwner) {
    $ok = DaySales::setHours($tenantId, (string) ($_POST['open'] ?? ''), (string) ($_POST['close'] ?? ''));
    $_SESSION['flash'][$ok ? 'success' : 'error'] = $ok
        ? 'Business hours saved. They apply to every shop, admin and staff member from the next business day.'
        : 'Enter both times, e.g. 06:00 and 00:00.';
    header('Location: ' . public_url('super/day/'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
    DaySales::close($pdo, $tenantId, $me);
    $_SESSION['flash']['success'] = 'Your day is closed and your closing sales are saved. The shop\'s day carries on until closing time.';
    header('Location: ' . public_url('super/day/'));
    exit;
}

$valid = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) ? $d : null;
$bizDay = DaySales::today($tenantId);
$hours = DaySales::hours($tenantId);
$win = DaySales::window($tenantId, $bizDay);
$shopOpen = DaySales::isOpen($tenantId);
$fmtT = fn($t) => date('g:i a', strtotime($t));
$from = $valid($_GET['from'] ?? '') ?? $bizDay;
$to = $valid($_GET['to'] ?? '') ?? $from;
if ($to < $from) { [$from, $to] = [$to, $from]; }
$staffFilter = $everyone && !empty($_GET['staff']) ? (int) $_GET['staff'] : null;
$userFilter = $everyone ? $staffFilter : $me; // without finance rights: only your own sales

$mine = DaySales::open($pdo, $tenantId, $me);
$mineNow = DaySales::totalFor($pdo, $tenantId, $bizDay, $bizDay, $me);
// The shop's combined day (everyone's sales together).
$shopDay = $everyone ? DaySales::session($pdo, $tenantId, 0, $bizDay) : null;
$shopNow = $everyone ? DaySales::totalFor($pdo, $tenantId, $bizDay, $bizDay) : null;
$staffToday = $everyone ? array_values(array_filter(DaySales::sessions($pdo, $tenantId, $bizDay, $bizDay), fn($r) => (int) $r['user_id'] !== 0)) : [];
foreach ($staffToday as &$r) {
    $r['now'] = $r['closed_at'] !== null ? (float) $r['closing_sales'] : DaySales::totalFor($pdo, $tenantId, $bizDay, $bizDay, (int) $r['user_id'])['total'];
}
unset($r);
$sales = DaySales::sales($pdo, $tenantId, $from, $to, $userFilter);
$sessions = DaySales::sessions($pdo, $tenantId, $from, $to);
if (!$everyone) {
    $sessions = array_values(array_filter($sessions, fn($s) => (int) $s['user_id'] === $me));
} elseif ($staffFilter) {
    $sessions = array_values(array_filter($sessions, fn($s) => (int) $s['user_id'] === $staffFilter));
}
// Days still open: closing = what they've sold by now.
foreach ($sessions as &$s) {
    if ($s['closed_at'] === null) {
        $s['live_closing'] = DaySales::totalFor($pdo, $tenantId, $s['day'], $s['day'], (int) $s['user_id'] ?: null)['total'];
    }
}
unset($s);
$byStaff = DaySales::byStaff($sales);
$byProduct = DaySales::byProduct($sales);
$grand = array_sum(array_map(fn($s) => (float) $s['total'], $sales));

$people = [];
if ($everyone) {
    $st = $pdo->prepare('SELECT id, username FROM users WHERE tenant_id = ? AND is_active = 1 ORDER BY username');
    $st->execute([$tenantId]);
    $people = $st->fetchAll();
}

$label = $from === $to ? date('l, j M Y', strtotime($from)) : date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));
if (!empty($_GET['excel']) || !empty($_GET['pdf'])) {
    $who = $everyone ? ($staffFilter ? (array_column($people, 'username', 'id')[$staffFilter] ?? 'staff') : 'all') : ($_SESSION['username'] ?? 'me');
    $file = 'sales-' . preg_replace('/\W+/', '-', $who) . '-' . $from . ($to !== $from ? '-to-' . $to : '');
    $sheets = DaySales::sheets($sales, $sessions, $everyone);
    if (!empty($_GET['pdf'])) {
        PdfExport::download($file . '.pdf', 'Opening & closing sales' . ($everyone && !$staffFilter ? '' : ' — ' . $who), $label, $sheets, [
            'Total sold' => $cur . ' ' . number_format($grand, 0),
            'Sales' => (string) count($sales),
            'Products' => (string) count($byProduct),
        ]);
    }
    ExcelExport::download($file . '.xlsx', $sheets);
}

$qs = fn(array $extra) => '?' . http_build_query(array_filter(['from' => $from, 'to' => $to, 'staff' => $staffFilter] + $extra));
$closed = $mine['closed_at'] !== null;
$page_title = 'Opening & closing sales';
ob_start();
?>
<?php foreach (['success' => 'success', 'error' => 'danger'] as $k => $cls): if (!empty($_SESSION['flash'][$k])): ?>
  <div class="alert alert-<?php echo $cls; ?>"><?php echo $h($_SESSION['flash'][$k]); unset($_SESSION['flash'][$k]); ?></div>
<?php endif; endforeach; ?>

<div class="d-flex align-items-center flex-wrap gap-2 mb-3 small">
  <span class="badge <?php echo $shopOpen ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $shopOpen ? 'Shop open' : 'Shop closed'; ?></span>
  <span class="text-muted">Business hours <?php echo $fmtT($hours['open']); ?> – <?php echo $fmtT($hours['close']); ?>
    · <?php echo date('l j M', strtotime($bizDay)); ?> <?php echo $shopOpen ? 'closes ' . date('D g:i a', strtotime($win['close'])) : ($win['open'] > date('Y-m-d H:i:s') ? 'opens ' . date('D g:i a', strtotime($win['open'])) : 'closed'); ?>
    · sales close automatically at closing time</span>
  <?php if ($isOwner): ?><button class="btn btn-sm btn-link p-0 ms-1" type="button" onclick="document.getElementById('hoursForm').classList.toggle('d-none')"><i class="fas fa-clock me-1"></i>Change hours</button><?php endif; ?>
</div>
<?php if ($isOwner): ?>
<form id="hoursForm" method="post" class="card border-0 shadow-sm mb-4 d-none" style="border-radius:14px;">
  <div class="card-body p-3 row g-2 align-items-end">
    <input type="hidden" name="action" value="hours">
    <div class="col-12 small text-muted">Your shops' business day. It applies to you, your admins and every staff member, in every shop. Sales made after closing time count as the next day's opening sales. A closing time earlier than the opening time (e.g. 00:00 or 02:00) means just after midnight.</div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Opening time</label><input type="time" name="open" class="form-control form-control-sm" value="<?php echo $h($hours['open']); ?>" required></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Closing time</label><input type="time" name="close" class="form-control form-control-sm" value="<?php echo $h($hours['close']); ?>" required></div>
    <div class="col-12 col-md-3"><button class="btn btn-sm btn-primary w-100"><i class="fas fa-floppy-disk me-1"></i>Save hours</button></div>
  </div>
</form>
<?php endif; ?>

<?php if ($everyone): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3"><i class="fas fa-store me-2 text-primary"></i>Whole shop <span class="text-muted fw-normal small">· <?php echo date('l j M', strtotime($bizDay)); ?></span>
      <?php if ($shopDay && $shopDay['closed_at']): ?><span class="badge bg-secondary ms-1">Closed <?php echo date('g:i a', strtotime($shopDay['closed_at'])); ?></span><?php endif; ?></h2>
    <div class="ds-tiles mb-3">
      <div><span>Opening sales</span><strong><?php echo $money($shopDay['opening_sales'] ?? 0); ?></strong></div>
      <div><span>Closing sales<?php echo $shopDay && $shopDay['closed_at'] ? '' : ' (so far)'; ?></span><strong class="text-success"><?php echo $money($shopDay && $shopDay['closed_at'] ? $shopDay['closing_sales'] : $shopNow['total']); ?></strong></div>
      <div><span>Sales made</span><strong><?php echo (int) ($shopDay && $shopDay['closed_at'] ? $shopDay['closing_count'] : $shopNow['count']); ?></strong></div>
      <div><span>People working</span><strong><?php echo count($staffToday); ?></strong></div>
    </div>
    <?php if ($staffToday): ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr class="text-muted small text-uppercase"><th>Person</th><th>Started</th><th class="text-end">Opening</th><th class="text-end">Closing</th><th>Their day</th></tr></thead>
      <tbody>
      <?php foreach ($staffToday as $r): ?>
        <tr>
          <td class="small fw-semibold"><a href="<?php echo $h($qs(['staff' => $r['user_id']])); ?>"><?php echo $h($r['username'] ?? '—'); ?></a></td>
          <td class="small text-muted"><?php echo date('g:i a', strtotime($r['opened_at'])); ?></td>
          <td class="small text-end"><?php echo $money($r['opening_sales']); ?></td>
          <td class="small text-end fw-semibold"><?php echo $money($r['now']); ?></td>
          <td class="small"><?php echo $r['closed_at'] ? 'Closed ' . date('g:i a', strtotime($r['closed_at'])) : '<span class="text-success">Open</span>'; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
  <div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <h1 class="h5 fw-bold mb-0"><i class="fas fa-sun me-2 text-warning"></i>My day <span class="text-muted fw-normal small">· <?php echo date('l j M', strtotime($bizDay)); ?></span></h1>
      <?php if (!$closed): ?>
        <form method="post" onsubmit="return confirm('Close your own day? Your closing sales are saved as they are now. The shop\'s day carries on.');">
          <input type="hidden" name="action" value="close">
          <button class="btn btn-sm btn-dark"><i class="fas fa-moon me-1"></i>Close my day</button>
        </form>
      <?php else: ?>
        <span class="badge bg-secondary">Closed at <?php echo date('g:i a', strtotime($mine['closed_at'])); ?></span>
      <?php endif; ?>
    </div>
    <div class="ds-tiles">
      <div><span>Opened at</span><strong><?php echo date('g:i a', strtotime($mine['opened_at'])); ?></strong></div>
      <div><span>Opening sales</span><strong><?php echo $money($mine['opening_sales']); ?></strong></div>
      <div><span>Closing sales<?php echo $closed ? '' : ' (so far)'; ?></span><strong class="text-success"><?php echo $money($closed ? $mine['closing_sales'] : $mineNow['total']); ?></strong></div>
      <div><span>Sales made</span><strong><?php echo (int) ($closed ? $mine['closing_count'] : $mineNow['count']); ?></strong></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
  <div class="card-body p-3">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-6 col-md-3"><label class="form-label small mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?php echo $h($from); ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?php echo $h($to); ?>"></div>
      <?php if ($everyone): ?>
      <div class="col-12 col-md-3"><label class="form-label small mb-1">Person</label>
        <select name="staff" class="form-select form-select-sm">
          <option value="">Everyone</option>
          <?php foreach ($people as $p): ?><option value="<?php echo (int) $p['id']; ?>" <?php echo $staffFilter === (int) $p['id'] ? 'selected' : ''; ?>><?php echo $h($p['username']); ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="col-12 col-md-3 d-flex gap-2">
        <button class="btn btn-sm btn-primary flex-fill"><i class="fas fa-filter me-1"></i>Show</button>
        <a class="btn btn-sm btn-success" href="<?php echo $h($qs(['excel' => 1])); ?>" title="Download Excel"><i class="fas fa-file-excel me-1"></i>Excel</a>
        <a class="btn btn-sm btn-danger" href="<?php echo $h($qs(['pdf' => 1])); ?>" title="Download PDF"><i class="fas fa-file-pdf me-1"></i>PDF</a>
      </div>
    </form>
    <div class="small text-muted mt-2"><?php echo $h($label); ?> · <?php echo count($sales); ?> sale<?php echo count($sales) === 1 ? '' : 's'; ?> · <strong><?php echo $money($grand); ?></strong> sold<?php echo $everyone ? '' : ' by you'; ?></div>
  </div>
</div>

<?php if ($sessions): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
  <div class="card-body p-4">
    <h2 class="h6 fw-bold mb-3">Opening &amp; closing<?php echo $everyone ? ' — every person' : ''; ?></h2>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr class="text-muted small text-uppercase"><th>Date</th><th>Person</th><th>Opened</th><th class="text-end">Opening</th><th>Closed</th><th class="text-end">Closing</th></tr></thead>
      <tbody>
      <?php foreach ($sessions as $s): ?>
        <tr>
          <td class="small"><?php echo date('D j M', strtotime($s['day'])); ?></td>
          <td class="small fw-semibold"><?php echo $h($s['username'] ?? '—'); ?></td>
          <td class="small text-muted"><?php echo date('g:i a', strtotime($s['opened_at'])); ?></td>
          <td class="small text-end"><?php echo $money($s['opening_sales']); ?></td>
          <td class="small"><?php echo $s['closed_at'] ? date('g:i a', strtotime($s['closed_at'])) : '<span class="badge bg-success-subtle text-success">Open</span>'; ?></td>
          <td class="small text-end fw-semibold"><?php echo $money($s['closing_sales'] ?? $s['live_closing']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php endif; ?>

<div class="row g-4 mb-4">
  <?php if ($everyone): ?>
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3"><i class="fas fa-user-tag me-2 text-primary"></i>Sales per staff</h2>
      <?php if (!$byStaff): ?><div class="text-muted small">No sales in this period.</div><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr class="text-muted small text-uppercase"><th>Person</th><th class="text-end">Sales</th><th class="text-end">Items</th><th class="text-end">Total</th></tr></thead>
        <tbody>
        <?php foreach ($byStaff as $r): ?>
          <tr>
            <td class="small fw-semibold"><a href="<?php echo $h($qs(['staff' => $r['user_id']])); ?>"><?php echo $h($r['staff']); ?></a></td>
            <td class="small text-end"><?php echo (int) $r['count']; ?></td>
            <td class="small text-end"><?php echo $qty($r['items']); ?></td>
            <td class="small text-end fw-semibold"><?php echo $money($r['total']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div></div>
  </div>
  <?php endif; ?>
  <div class="col-12 <?php echo $everyone ? 'col-lg-7' : ''; ?>">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3"><i class="fas fa-box me-2 text-primary"></i>Sales per product</h2>
      <?php if (!$byProduct): ?><div class="text-muted small">No products sold in this period.</div><?php else: ?>
      <div class="table-responsive" style="max-height:420px;"><table class="table table-sm align-middle mb-0">
        <thead class="sticky-top bg-white"><tr class="text-muted small text-uppercase"><th>Product</th><th class="text-end">Qty</th><th class="text-end">Amount</th><?php if ($everyone): ?><th>Sold by</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($byProduct as $r): ?>
          <tr>
            <td class="small fw-semibold"><?php echo $h($r['product']); ?></td>
            <td class="small text-end"><?php echo $qty($r['qty']); ?></td>
            <td class="small text-end"><?php echo $money($r['amount']); ?></td>
            <?php if ($everyone): ?><td class="small text-muted"><?php echo $h(implode(', ', array_map(fn($n, $v) => $n . ' (' . $qty($v) . ')', array_keys($r['staff']), $r['staff']))); ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<style>
.ds-tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
.ds-tiles>div{background:#f7f9f8;border-radius:10px;padding:12px;}
.ds-tiles span{display:block;font-size:.7rem;color:#8a8f96;text-transform:uppercase;letter-spacing:.04em;}
.ds-tiles strong{font-size:1.1rem;overflow-wrap:anywhere;}
@media (max-width:576px){ .ds-tiles{grid-template-columns:1fr 1fr;} }
</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/' . ($isStaff ? 'staff' : 'tenants') . '/layout.php';
