<?php
// public/super/services/serving.php — Serving: the clients being served right now.
// For each client: their services (who is doing them, done or not), products and extra charges,
// and quick buttons to add another service, a product or a charge as the visit goes on.
// The owner and the front desk see every client; a staff member sees their own.
require_once __DIR__ . '/_common.php';

$me = (int) TenantContext::userId();
$canAssign = $isOwner || $canBook;
$all = $SD->tickets('open');
$cards = [];
foreach ($all as $t) {
    $items = $SD->items((int) $t['id']);
    $mine = (int) $t['created_by'] === $me || in_array($me, array_map('intval', array_column($items, 'staff_id')), true);
    if (!$isDesk && !$mine) { continue; }
    $cards[] = [$t, $items, $mine];
}
$services = $SD->servicesForShop();
$products = $SD->sellableProducts();
$staff = $canAssign ? $SD->staffForShop() : [];
$qf = fn($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.');
$view = public_url('super/services/view.php?id=');

ob_start();
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div><h1 class="h5 fw-bold mb-0">Serving</h1><div class="small text-muted"><?php echo count($cards); ?> client<?php echo count($cards) === 1 ? '' : 's'; ?> being served<?php echo $isDesk ? '' : ' — yours'; ?></div></div>
  <a class="btn btn-primary" href="<?php echo public_url('super/services/new.php'); ?>"><i class="fas fa-user-plus me-1"></i>New client</a>
</div>

<?php if (!$cards): ?>
  <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body text-center text-muted py-5">
    <i class="fas fa-hand-sparkles fa-2x mb-2"></i><div>No one is being served right now.</div>
    <a class="btn btn-outline-primary mt-3" href="<?php echo public_url('super/services/new.php'); ?>">Take in a client</a></div></div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($cards as [$t, $items, $mine]):
    $id = (int) $t['id'];
    $svc = array_filter($items, fn($i) => $i['kind'] === 'service');
    $prod = array_filter($items, fn($i) => $i['kind'] === 'product');
    $chg = array_filter($items, fn($i) => $i['kind'] === 'charge');
    $doneN = count(array_filter($svc, fn($i) => $i['status'] === 'done'));
    $mins = max(0, (int) round((time() - strtotime($t['created_at'])) / 60));
?>
  <div class="col-12 col-lg-6" id="t<?php echo $id; ?>">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px;"><div class="card-body p-3">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div><div class="fw-bold fs-5"><?php echo svc_h($t['customer_name']); ?></div>
          <div class="small text-muted"><?php echo svc_h($t['number']); ?> · <?php echo $mins < 60 ? $mins . ' min' : floor($mins / 60) . ' h ' . ($mins % 60) . ' min'; ?> ago<?php echo $t['customer_phone'] ? ' · ' . svc_h($t['customer_phone']) : ''; ?></div></div>
        <div class="text-end"><?php if ($canMoney): ?><div class="fw-bold"><?php echo svc_money($t['subtotal']); ?></div><?php endif; ?>
          <a class="small" href="<?php echo $view . $id; ?>"><?php echo $isOwner || $canCash ? 'Open & take payment' : 'Open'; ?></a></div>
      </div>
      <?php if ($t['notes']): ?><div class="small mt-1"><i class="fas fa-note-sticky text-warning me-1"></i><?php echo svc_h($t['notes']); ?></div><?php endif; ?>

      <div class="mt-2">
        <?php foreach ($svc as $it): $own = (int) $it['staff_id'] === $me; ?>
          <div class="d-flex justify-content-between align-items-center gap-2 border-top py-1 small">
            <div><span class="fw-semibold"><?php echo svc_h($it['service_name']); ?></span>
              <span class="text-muted">· <?php echo $it['staff_name'] ? svc_h($it['staff_name']) : 'not assigned'; ?></span></div>
            <?php if ($it['status'] === 'done'): ?><span class="badge bg-success">Done</span>
            <?php elseif ($own || $canAssign): ?>
              <form method="post" action="<?php echo $view . $id; ?>"><input type="hidden" name="return" value="serving"><input type="hidden" name="action" value="done"><input type="hidden" name="item_id" value="<?php echo (int) $it['id']; ?>"><button class="btn btn-sm btn-outline-success py-0"><i class="fas fa-check me-1"></i>Done</button></form>
            <?php else: ?><span class="badge bg-light text-dark border">Waiting</span><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php foreach ($prod as $it): ?>
          <div class="border-top py-1 small"><i class="fas fa-bag-shopping text-primary me-1"></i><?php echo $qf($it['quantity']); ?> × <?php echo svc_h($it['service_name']); ?><?php echo $canMoney ? ' <span class="text-muted">' . svc_money((float) $it['price'] * (float) $it['quantity']) . '</span>' : ''; ?></div>
        <?php endforeach; ?>
        <?php foreach ($chg as $it): ?>
          <div class="border-top py-1 small"><i class="fas fa-plus-circle text-muted me-1"></i><?php echo svc_h($it['service_name']); ?><?php echo $canMoney ? ' <span class="text-muted">' . svc_money($it['price']) . '</span>' : ''; ?></div>
        <?php endforeach; ?>
      </div>

      <details class="mt-2">
        <summary class="small fw-semibold text-primary" style="cursor:pointer"><i class="fas fa-plus me-1"></i>Add a service, product or charge</summary>
        <form method="post" action="<?php echo $view . $id; ?>" class="d-flex gap-2 mt-2 flex-wrap">
          <input type="hidden" name="return" value="serving"><input type="hidden" name="action" value="add_service">
          <select name="service_id" class="form-select form-select-sm" style="flex:1;min-width:150px" required><option value="">Another service…</option>
            <?php foreach ($services as $sv): ?><option value="<?php echo (int) $sv['id']; ?>"><?php echo svc_h($sv['name']); ?><?php echo $canMoney ? ' — ' . svc_money($sv['price']) : ''; ?></option><?php endforeach; ?></select>
          <?php if ($canAssign): ?><select name="staff_id" class="form-select form-select-sm" style="max-width:150px"><option value="0">Assign later</option>
            <?php foreach ($staff as $u): ?><option value="<?php echo (int) $u['id']; ?>" <?php echo (int) $u['id'] === $me ? 'selected' : ''; ?>><?php echo svc_h($u['username']); ?></option><?php endforeach; ?></select><?php endif; ?>
          <button class="btn btn-sm btn-outline-primary">Add</button>
        </form>
        <?php if ($products): ?>
        <form method="post" action="<?php echo $view . $id; ?>" class="d-flex gap-2 mt-2 flex-wrap">
          <input type="hidden" name="return" value="serving"><input type="hidden" name="action" value="add_product">
          <select name="product_id" class="form-select form-select-sm" style="flex:1;min-width:150px" required><option value="">A product (dye, braids…)</option>
            <?php foreach ($products as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo svc_h($p['name']); ?><?php echo $canMoney ? ' — ' . svc_money($p['sell_price']) : ''; ?> · <?php echo $qf($p['quantity']); ?> left</option><?php endforeach; ?></select>
          <input type="number" name="quantity" value="1" min="0.01" step="any" class="form-control form-control-sm" style="width:80px" aria-label="Quantity">
          <button class="btn btn-sm btn-outline-primary">Add</button>
        </form>
        <?php endif; ?>
        <form method="post" action="<?php echo $view . $id; ?>" class="d-flex gap-2 mt-2 flex-wrap">
          <input type="hidden" name="return" value="serving"><input type="hidden" name="action" value="add_charge">
          <input name="charge_name" class="form-control form-control-sm" style="flex:1;min-width:150px" maxlength="150" placeholder="Extra charge, e.g. Extra length" required>
          <input type="number" name="charge_amount" class="form-control form-control-sm" style="width:110px" min="0.01" step="any" placeholder="Amount" required>
          <button class="btn btn-sm btn-outline-secondary">Add</button>
        </form>
      </details>
    </div></div>
  </div>
<?php endforeach; ?>
</div>
<?php
svc_render('Serving', ob_get_clean());
