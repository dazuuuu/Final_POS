<?php
// public/super/restaurant/dashboard.php — home of a restaurant-only account:
// open orders, the kitchen queue, finished dishes, low kitchen stock, pending
// kitchen requests and (owners only) today's takings.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::SALES_RECORD);
require_once __DIR__ . '/_common.php';

$one = function (string $sql) use ($pdo, $tid) {
    $st = $pdo->prepare($sql);
    $st->execute([$tid]);
    return $st->fetchColumn();
};
$open = (int) $one("SELECT COUNT(*) FROM rest_orders WHERE tenant_id = ? AND status IN ('open', 'held')");
$queue = (int) $one("SELECT COUNT(DISTINCT o.id) FROM rest_orders o JOIN rest_order_items i ON i.order_id = o.id AND i.item_type = 'dish'
                      WHERE o.tenant_id = ? AND o.status IN ('open', 'paid') AND o.created_at >= NOW() - INTERVAL 12 HOUR AND COALESCE(i.kitchen_status, 'new') IN ('new', 'preparing')");
$ready = (int) $one("SELECT COUNT(DISTINCT o.id) FROM rest_orders o JOIN rest_order_items i ON i.order_id = o.id AND i.item_type = 'dish'
                      WHERE o.tenant_id = ? AND o.status IN ('open', 'paid') AND o.created_at >= NOW() - INTERVAL 12 HOUR AND i.kitchen_status = 'ready'");
$requests = (int) $one("SELECT COUNT(*) FROM rest_kitchen_requests WHERE tenant_id = ? AND status = 'pending'");
$money = PageGuard::seesMoney();
$today = $money ? (float) $one("SELECT COALESCE(SUM(total), 0) FROM rest_orders WHERE tenant_id = ? AND status = 'paid' AND DATE(created_at) = CURDATE()") : 0;
$paidCount = (int) $one("SELECT COUNT(*) FROM rest_orders WHERE tenant_id = ? AND status = 'paid' AND DATE(created_at) = CURDATE()");

$st = $pdo->prepare('SELECT name FROM rest_dishes WHERE tenant_id = ? AND is_active = 1 AND sold_out = 1 ORDER BY name');
$st->execute([$tid]);
$soldOut = $st->fetchAll(PDO::FETCH_COLUMN);
$st = $pdo->prepare('SELECT i.name, i.quantity, i.unit FROM rest_ingredients i WHERE i.tenant_id = ?
                       AND (i.quantity <= 0 OR i.quantity < 5 * (SELECT MAX(di.quantity) FROM rest_dish_ingredients di WHERE di.ingredient_id = i.id))
                     ORDER BY i.quantity LIMIT 12');
$st->execute([$tid]);
$low = $st->fetchAll(PDO::FETCH_ASSOC);

$u = fn($p) => public_url('super/restaurant/' . $p);
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
ob_start();
?>
<style>
.rd-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px;}
.rd-tile{background:#fff;border:1px solid #e4e7ec;border-radius:12px;padding:14px 16px;text-decoration:none;color:inherit;display:block;}
.rd-tile small{color:#71717a;font-weight:600;text-transform:uppercase;font-size:.72rem;letter-spacing:.05em;}
.rd-tile b{display:block;font-size:1.6rem;margin-top:2px;}
</style>
<div class="d-flex gap-2 flex-wrap mb-3">
  <a class="btn btn-success" href="<?php echo $u('pos.php'); ?>"><i class="fas fa-cash-register me-1"></i>New order</a>
  <a class="btn btn-outline-dark" href="<?php echo $u('kitchen.php'); ?>"><i class="fas fa-fire-burner me-1"></i>Kitchen</a>
  <a class="btn btn-outline-dark" href="<?php echo $u('orders.php'); ?>"><i class="fas fa-receipt me-1"></i>Orders</a>
</div>
<div class="rd-tiles">
  <a class="rd-tile" href="<?php echo $u('orders.php'); ?>"><small>Open orders</small><b><?php echo $open; ?></b></a>
  <a class="rd-tile" href="<?php echo $u('kitchen.php'); ?>"><small>In the kitchen</small><b><?php echo $queue; ?></b></a>
  <a class="rd-tile" href="<?php echo $u('kitchen.php'); ?>"><small>Ready to serve</small><b class="<?php echo $ready ? 'text-success' : ''; ?>"><?php echo $ready; ?></b></a>
  <a class="rd-tile" href="<?php echo $u('orders.php'); ?>"><small>Paid today</small><b><?php echo $paidCount; ?></b></a>
  <?php if ($money): ?><div class="rd-tile"><small>Today's takings</small><b><?php echo $h($currency) . ' ' . number_format($today, 2); ?></b></div><?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-md-4"><div class="card h-100"><div class="card-body p-3">
    <h2 class="h6 fw-bold">Finished dishes</h2>
    <?php if (!$soldOut): ?><p class="small text-muted mb-0">Everything on the menu is available.</p><?php endif; ?>
    <?php foreach ($soldOut as $d): ?><span class="badge bg-danger me-1 mb-1"><?php echo $h($d); ?></span><?php endforeach; ?>
  </div></div></div>
  <div class="col-md-4"><div class="card h-100"><div class="card-body p-3">
    <h2 class="h6 fw-bold">Low kitchen stock</h2>
    <?php if (!$low): ?><p class="small text-muted mb-0">Nothing running low.</p><?php endif; ?>
    <?php foreach ($low as $l): ?><div class="d-flex justify-content-between small border-top py-1"><span><?php echo $h($l['name']); ?></span><span class="<?php echo (float) $l['quantity'] <= 0 ? 'text-danger fw-bold' : ''; ?>"><?php echo rest_qty((float) $l['quantity']) . ' ' . $h($l['unit']); ?></span></div><?php endforeach; ?>
  </div></div></div>
  <div class="col-md-4"><div class="card h-100"><div class="card-body p-3">
    <h2 class="h6 fw-bold">Kitchen requests</h2>
    <p class="small mb-2"><?php echo $requests ? '<b>' . $requests . '</b> waiting for stock from the store.' : 'No requests waiting.'; ?></p>
    <a class="btn btn-sm btn-outline-dark" href="<?php echo $u('transfer.php'); ?>">Open transfers</a>
  </div></div></div>
</div>
<?php
rest_render('Restaurant', ob_get_clean());
