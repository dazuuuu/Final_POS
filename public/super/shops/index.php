<?php
// public/super/shops/index.php — the owner's shops: open new shops (each one
// a separate business with its own name, staff, stock and catalogue), see how
// each is doing, and jump into one to manage it.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant(); // owner only

$pdo = Database::pdo();
ShopContext::ensureSchema($pdo);
$selfUrl = public_url('super/shops/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'create') {
        $res = ShopContext::createShop($_POST);
        if ($res['ok'] && !empty($_POST['open_now'])) {
            ShopContext::switchTo((int) $res['id']);
            $_SESSION['flash']['success'] = 'Shop "' . trim($_POST['name']) . '" is open. You are now managing it — add its staff and stock.';
            header('Location: ' . public_url('super/staff/'));
            exit;
        }
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Shop created.' : $res['error'];
    } elseif ($action === 'update') {
        $res = ShopContext::updateShop($id, $_POST);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Shop details saved.' : $res['error'];
    } elseif ($action === 'close' || $action === 'reopen') {
        $res = ShopContext::setActive($id, $action === 'reopen');
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok']
            ? ($action === 'close' ? 'Shop closed — its staff can no longer log in.' : 'Shop reopened.')
            : $res['error'];
    }
    header('Location: ' . $selfUrl);
    exit;
}

$period = in_array($_GET['period'] ?? 'today', ['today', 'week', 'month'], true) ? ($_GET['period'] ?? 'today') : 'today';
$to = date('Y-m-d');
$from = match ($period) { 'week' => date('Y-m-d', strtotime('-6 days')), 'month' => date('Y-m-d', strtotime('-29 days')), default => $to };
$periodLabel = ['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days'][$period];

$shops = ShopContext::shops();
$R = new ShopReport($pdo);
$cmp = $R->compare($shops, $from, $to);
$homeId = ShopContext::homeTenantId();
$currentId = (int) TenantContext::tenantId();
$__tenant = (new Models\TenantModel($pdo))->find($currentId);
$currency = $__tenant['currency'] ?? 'KES';
$money = fn($n) => htmlspecialchars($currency) . ' ' . number_format((float) $n, 0);
$catUrl = fn($s) => public_url('catalogue.php?shop=' . urlencode($s['slug']));

$page_title = 'My shops';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div class="btn-group btn-group-sm">
    <?php foreach (['today' => 'Today', 'week' => '7 days', 'month' => '30 days'] as $k => $lbl): ?>
      <a class="btn <?php echo $period === $k ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?period=<?php echo $k; ?>"><?php echo $lbl; ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (Features::multiShop()): ?>
  <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newShop"><i class="fas fa-plus me-1"></i>Open a new shop</button>
  <?php endif; ?>
</div>

<?php if (!Features::multiShop()): ?>
<div class="alert alert-info small">This account runs a single shop — staff, stock and sales all live in your main shop. Contact support if you need more shops.</div>
<?php else: ?>
<div class="collapse mb-4" id="newShop">
  <div class="card"><div class="card-body p-4">
    <h2 class="h6 fw-bold mb-1">Open a new shop</h2>
    <p class="text-muted small mb-3">Each shop is its own business: its own name, staff, stock, sales, credit and public catalogue. You manage all of them from this account, and you can move stock into any of them from the warehouse.</p>
    <form method="post" class="row g-2">
      <input type="hidden" name="action" value="create">
      <div class="col-12 col-md-4"><input name="name" class="form-control" placeholder="Shop name, e.g. Lucsela Town Branch" required></div>
      <div class="col-6 col-md-3"><input name="phone" class="form-control" placeholder="Phone (optional)"></div>
      <div class="col-6 col-md-3"><input name="address" class="form-control" placeholder="Location (optional)"></div>
      <div class="col-12 col-md-2"><button class="btn btn-primary w-100">Create</button></div>
      <div class="col-12"><label class="small text-muted"><input type="checkbox" name="open_now" value="1" checked class="form-check-input me-1">Switch to it now so I can add its staff</label></div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ([
      ['Sales · ' . $periodLabel, $cmp['total']['revenue'], 'fa-sack-dollar', 'tk-red'],
      ['Gross profit', $cmp['total']['gross'], 'fa-chart-line', 'tk-blue'],
      ['Expenses', $cmp['total']['expenses'], 'fa-file-invoice', 'tk-orange'],
      ['Net profit (all shops)', $cmp['total']['net'], 'fa-scale-balanced', 'tk-greenbg'],
  ] as $t): ?>
  <div class="col-6 col-lg-3">
    <div class="tk-stat">
      <div class="tk-stat-icon <?php echo $t[3]; ?>"><i class="fas <?php echo $t[2]; ?>"></i></div>
      <div class="tk-stat-body"><div class="tk-stat-num <?php echo $t[1] < 0 ? 'text-danger' : ''; ?>"><?php echo $money($t[1]); ?></div><div class="tk-stat-label"><?php echo htmlspecialchars($t[0]); ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="d-lg-none mb-4">
  <div class="sh-quick">
    <a href="<?php echo public_url('super/orders/'); ?>"><i class="fas fa-hand-holding-dollar"></i><span>Credit sales</span></a>
    <a href="<?php echo public_url('super/credits/'); ?>"><i class="fas fa-book"></i><span>Credit book</span></a>
    <a href="<?php echo public_url('super/payments/'); ?>"><i class="fas fa-money-check-dollar"></i><span>Payments</span></a>
    <a href="<?php echo public_url(ShopContext::isMainShop() ? 'super/warehouse/' : 'super/guide/'); ?>"><i class="fas <?php echo ShopContext::isMainShop() ? 'fa-warehouse' : 'fa-circle-question'; ?>"></i><span><?php echo ShopContext::isMainShop() ? 'Warehouse' : 'Guide'; ?></span></a>
  </div>
</div>

<div class="row g-3">
  <?php foreach ($cmp['rows'] as $r):
    $s = $r['shop'];
    $sid = (int) $s['id'];
    $isMain = $sid === $homeId;
    $closed = ($s['status'] ?? 'active') !== 'active';
    $share = $cmp['total']['revenue'] > 0 ? (int) round($r['revenue'] / $cmp['total']['revenue'] * 100) : 0;
  ?>
  <div class="col-12 col-md-6 col-xl-4">
    <div class="card h-100 <?php echo $closed ? 'opacity-75' : ''; ?>">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
          <div class="min-w-0">
            <div class="fw-bold text-truncate"><?php echo htmlspecialchars($s['name']); ?></div>
            <div class="small text-muted text-truncate"><?php echo htmlspecialchars($s['address'] ?: ($s['phone'] ?: '—')); ?></div>
          </div>
          <div class="text-end">
            <?php if ($isMain): ?><span class="badge bg-dark">Admin portal</span><?php endif; ?>
            <?php if ($closed): ?><span class="badge bg-secondary">Closed</span><?php endif; ?>
            <?php if ($sid === $currentId): ?><span class="badge bg-success">Managing</span><?php endif; ?>
          </div>
        </div>

        <div class="sh-grid my-3">
          <div><span>Sales</span><strong><?php echo $money($r['revenue']); ?></strong></div>
          <div><span>Gross profit</span><strong><?php echo $money($r['gross']); ?></strong></div>
          <div><span>Expenses</span><strong class="text-warning"><?php echo $money($r['expenses']); ?></strong></div>
          <div><span>Net profit</span><strong class="<?php echo $r['net'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($r['net']); ?></strong></div>
          <div><span>Owed to shop</span><strong><?php echo $money($r['owed']); ?></strong></div>
          <div><span>Stock value</span><strong><?php echo $money($R->stockValue($sid)); ?></strong></div>
        </div>
        <div class="small text-muted mb-1"><?php echo (int) $r['count']; ?> sale<?php echo $r['count'] === 1 ? '' : 's'; ?> · <?php echo $R->staffCount($sid); ?> staff · <?php echo $share; ?>% of all sales</div>
        <div class="progress mb-3" style="height:6px;"><div class="progress-bar bg-success" style="width:<?php echo $share; ?>%"></div></div>

        <div class="d-flex flex-wrap gap-2">
          <?php if ($sid !== $currentId && !$closed): ?>
          <form method="post" action="<?php echo public_url('super/shops/switch.php'); ?>">
            <input type="hidden" name="id" value="<?php echo $sid; ?>">
            <button class="btn btn-sm btn-primary"><i class="fas fa-right-to-bracket me-1"></i>Manage</button>
          </form>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?php echo $catUrl($s); ?>"><i class="fas fa-store me-1"></i>Catalogue</a>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#edit<?php echo $sid; ?>"><i class="fas fa-pen"></i></button>
          <?php if (!$isMain): ?>
          <form method="post" onsubmit="return confirm('<?php echo $closed ? 'Reopen' : 'Close'; ?> this shop?');">
            <input type="hidden" name="action" value="<?php echo $closed ? 'reopen' : 'close'; ?>">
            <input type="hidden" name="id" value="<?php echo $sid; ?>">
            <button class="btn btn-sm btn-outline-<?php echo $closed ? 'success' : 'danger'; ?>"><?php echo $closed ? 'Reopen' : 'Close'; ?></button>
          </form>
          <?php endif; ?>
        </div>

        <form method="post" class="collapse mt-3" id="edit<?php echo $sid; ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?php echo $sid; ?>">
          <input name="name" class="form-control form-control-sm mb-2" value="<?php echo htmlspecialchars($s['name']); ?>" required>
          <input name="phone" class="form-control form-control-sm mb-2" placeholder="Phone" value="<?php echo htmlspecialchars($s['phone'] ?? ''); ?>">
          <input name="address" class="form-control form-control-sm mb-2" placeholder="Location" value="<?php echo htmlspecialchars($s['address'] ?? ''); ?>">
          <button class="btn btn-sm btn-primary w-100">Save</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<style>
.sh-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;}
.sh-grid span{display:block;font-size:.7rem;color:#8a8f96;text-transform:uppercase;letter-spacing:.04em;}
.sh-grid strong{font-size:.98rem;}
.sh-quick{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
.sh-quick a{background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.1);border-radius:12px;padding:12px 4px;text-align:center;text-decoration:none;color:#333;font-size:.72rem;font-weight:600;}
.sh-quick i{display:block;font-size:1.15rem;color:var(--tk-green);margin-bottom:6px;}
</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
