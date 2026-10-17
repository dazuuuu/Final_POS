<?php
// public/super/restaurant/pos.php — the restaurant order screen (owner + waiters).
//   • Tap a dish → order details: untick ingredients ("without onions"), qty, note.
//     Dishes outside their availability window (e.g. tea 6–11am) can't be added.
//   • Counter items (sodas…) are sold by type, limited to what's at the counter.
//   • Place order — sends it (status 'open', awaiting payment) and prints the
//                   order slip; its receipt number is used on the Payments page.
//   • Hold        — parks the cart (status 'held') to finish later.
// Placed and held orders can be resumed to add or remove items until paid.
// Waiters only see and resume their own orders; the owner sees everyone's.
// Prices always come from the database; the cart only sends ids + quantities.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::SALES_RECORD);
require_once __DIR__ . '/_common.php';

$self = public_url('super/restaurant/pos.php');

/** Active dishes with their recipe and how many portions the stock allows. */
function pos_dishes(PDO $pdo, int $tid): array
{
    $stmt = $pdo->prepare('SELECT d.*, c.name AS category_name FROM rest_dishes d LEFT JOIN rest_categories c ON c.id = d.category_id
                            WHERE d.tenant_id = ? AND d.is_active = 1 ORDER BY c.sort_order IS NULL, c.sort_order, c.name, d.name');
    $stmt->execute([$tid]);
    $dishes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $dishes[(int) $d['id']] = $d + ['ings' => []];
    }
    if ($dishes) {
        $ids = implode(',', array_keys($dishes));
        foreach ($pdo->query("SELECT di.dish_id, di.ingredient_id, di.quantity, di.measure, COALESCE(di.base_quantity, di.quantity) AS base,
                                     i.name, i.unit, i.quantity AS stock
                                FROM rest_dish_ingredients di JOIN rest_ingredients i ON i.id = di.ingredient_id
                               WHERE di.dish_id IN ($ids) ORDER BY i.name") as $r) {
            $dishes[(int) $r['dish_id']]['ings'][] = [
                'id' => (int) $r['ingredient_id'], 'name' => $r['name'], 'base' => (float) $r['base'], 'stock' => (float) $r['stock'],
                'unit' => $r['unit'], 'label' => rest_qty((float) $r['quantity']) . ' ' . (in_array($r['measure'], ['pc', ''], true) ? 'pcs' : $r['measure']),
            ];
        }
    }
    return $dishes;
}

function pos_counter(PDO $pdo, int $tid): array
{
    $stmt = $pdo->prepare("SELECT o.*, s.name AS stock_name, s.image_path FROM rest_outlet_items o JOIN rest_stock s ON s.id = o.stock_id
                            WHERE o.tenant_id = ? AND o.outlet = 'counter' AND o.selling_price IS NOT NULL ORDER BY s.name, o.type_name");
    $stmt->execute([$tid]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $o['label'] = $o['stock_name'] . ($o['type_name'] !== '' ? ' · ' . $o['type_name'] : '');
        $out[(int) $o['id']] = $o;
    }
    return $out;
}

function pos_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** An unpaid order the current user may change (owner: any; waiter: own). */
function pos_editable(PDO $pdo, int $tid, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT o.*, u.username FROM rest_orders o LEFT JOIN users u ON u.id = o.user_id
                            WHERE o.id = ? AND o.tenant_id = ? AND o.status IN ('held','open')");
    $stmt->execute([$id, $tid]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($o && !rest_sees_all() && (int) $o['user_id'] !== (int) TenantContext::userId()) {
        return null;
    }
    return $o;
}

// ---------------------------------------------------------------- API (JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!csrf_verify($in['csrf'] ?? null)) {
        pos_json(['ok' => false, 'error' => 'Your session expired. Reload the page.'], 403);
    }
    $action = $in['action'] ?? '';
    $orderId = (int) ($in['order_id'] ?? 0);
    $existing = null;
    if ($orderId && !($existing = pos_editable($pdo, $tid, $orderId))) {
        pos_json(['ok' => false, 'error' => 'That order is no longer open (it may have been paid).']);
    }

    if ($action === 'cancel') {
        if ($existing) {
            $pdo->prepare("UPDATE rest_orders SET status = 'cancelled' WHERE id = ?")->execute([$orderId]);
        }
        pos_json(['ok' => true]);
    }
    if (!in_array($action, ['hold', 'place'], true)) {
        pos_json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }

    // Dishes already on this order stay allowed even if their window has
    // since closed (e.g. breakfast ordered at 10:55, edited at 11:05).
    $already = [];
    if ($existing) {
        $stmt = $pdo->prepare("SELECT DISTINCT ref_id FROM rest_order_items WHERE order_id = ? AND item_type = 'dish'");
        $stmt->execute([$orderId]);
        $already = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // Rebuild every line from the database.
    $dishes = pos_dishes($pdo, $tid);
    $counter = pos_counter($pdo, $tid);
    $lines = [];
    $needCounter = [];
    foreach ((array) ($in['items'] ?? []) as $it) {
        $qty = max(0, (float) ($it['qty'] ?? 0));
        $ref = (int) ($it['ref'] ?? 0);
        if ($qty <= 0) continue;
        if (($it['type'] ?? '') === 'dish' && isset($dishes[$ref])) {
            $d = $dishes[$ref];
            if (!rest_dish_available($d) && !in_array($ref, $already, true)) {
                pos_json(['ok' => false, 'error' => $d['name'] . ' is only available ' . rest_window_label($d) . '.']);
            }
            $ingIds = array_column($d['ings'], 'id');
            $removed = array_values(array_intersect(array_map('intval', (array) ($it['removed'] ?? [])), $ingIds));
            $removedNames = array_column(array_filter($d['ings'], fn($i) => in_array($i['id'], $removed, true)), 'name');
            $lines[] = ['type' => 'dish', 'ref' => $ref, 'qty' => $qty, 'price' => (float) $d['price'], 'removed' => $removed,
                        'name' => $d['name'] . ($removedNames ? ' — without ' . implode(', ', $removedNames) : ''),
                        'note' => mb_substr(trim((string) ($it['note'] ?? '')), 0, 255)];
        } elseif (($it['type'] ?? '') === 'counter' && isset($counter[$ref])) {
            $c = $counter[$ref];
            $lines[] = ['type' => 'counter', 'ref' => $ref, 'qty' => $qty, 'price' => (float) $c['selling_price'], 'removed' => [],
                        'name' => $c['label'], 'note' => mb_substr(trim((string) ($it['note'] ?? '')), 0, 255)];
            $needCounter[$ref] = ($needCounter[$ref] ?? 0) + $qty;
        }
    }
    if (!$lines) pos_json(['ok' => false, 'error' => 'The order is empty.']);
    foreach ($needCounter as $ref => $q) {
        if ($q > (float) $counter[$ref]['quantity']) {
            pos_json(['ok' => false, 'error' => 'Only ' . rest_qty((float) $counter[$ref]['quantity']) . ' ' . $counter[$ref]['label'] . ' left at the counter.']);
        }
    }

    $subtotal = round(array_sum(array_map(fn($l) => $l['price'] * $l['qty'], $lines)), 2);
    $discount = min($subtotal, max(0, round((float) ($in['discount'] ?? 0), 2)));
    $total = round($subtotal - $discount, 2);
    $type = in_array($in['order_type'] ?? '', ['takeaway', 'delivery'], true) ? $in['order_type'] : 'dine_in';
    $table = $type === 'dine_in' ? (mb_substr(trim((string) ($in['table'] ?? '')), 0, 40) ?: null) : null;
    $customer = mb_substr(trim((string) ($in['customer'] ?? '')), 0, 120) ?: null;
    // Delivery orders (phone-in): phone + address, then tracked on the Deliveries page.
    $phone = $type === 'delivery' ? (mb_substr(trim((string) ($in['phone'] ?? '')), 0, 30) ?: null) : null;
    $address = $type === 'delivery' ? (mb_substr(trim((string) ($in['address'] ?? '')), 0, 255) ?: null) : null;
    if ($type === 'delivery' && $action === 'place' && (!$phone || !$address)) {
        echo json_encode(['ok' => false, 'error' => 'Delivery orders need the customer\'s phone and delivery address.']);
        exit;
    }
    // Once placed, an order stays placed even if it's edited and held again.
    $status = $action === 'place' || ($existing['status'] ?? '') === 'open' ? 'open' : 'held';

    $pdo->beginTransaction();
    try {
        if ($existing) {
            $pdo->prepare('UPDATE rest_orders SET order_type=?, table_label=?, customer_name=?, customer_phone=?, delivery_address=?,
                                  delivery_status = IF(? = \'delivery\', COALESCE(delivery_status, \'new\'), NULL), subtotal=?, discount=?, total=?, status=? WHERE id=?')
                ->execute([$type, $table, $customer, $phone, $address, $type, $subtotal, $discount, $total, $status, $orderId]);
            $pdo->prepare('DELETE FROM rest_order_items WHERE order_id = ?')->execute([$orderId]);
            $orderNo = $existing['order_no'];
        } else {
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM rest_orders WHERE tenant_id = ? AND DATE(created_at) = CURDATE()');
            $cnt->execute([$tid]);
            $orderNo = date('ymd') . '-' . str_pad((string) ($cnt->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO rest_orders (tenant_id, order_no, order_type, table_label, customer_name, customer_phone, delivery_address, delivery_status, status, subtotal, discount, total, user_id)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tid, $orderNo, $type, $table, $customer, $phone, $address, $type === 'delivery' ? 'new' : null, $status, $subtotal, $discount, $total, TenantContext::userId()]);
            $orderId = (int) $pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO rest_order_items (order_id, item_type, ref_id, name, unit_price, quantity, removed, note, line_total) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($lines as $l) {
            $ins->execute([$orderId, $l['type'], $l['ref'], $l['name'], $l['price'], $l['qty'], $l['removed'] ? json_encode($l['removed']) : null, $l['note'] ?: null, round($l['price'] * $l['qty'], 2)]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        pos_json(['ok' => false, 'error' => 'Could not save the order: ' . $e->getMessage()]);
    }

    pos_json(['ok' => true, 'order_id' => $orderId, 'order_no' => $orderNo, 'status' => $status, 'total' => $total,
              'receipt' => public_url('super/restaurant/orders.php?receipt=' . $orderId . '&print=1')]);
}

// ---------------------------------------------------------------- Screen
$dishes = pos_dishes($pdo, $tid);
$counter = pos_counter($pdo, $tid);
$categories = rest_categories($pdo, $tid);

// Unpaid orders this user can pick back up (owner: everyone's).
$sql = "SELECT o.id, o.order_no, o.status, o.table_label, o.customer_name, o.total, u.username
          FROM rest_orders o LEFT JOIN users u ON u.id = o.user_id
         WHERE o.tenant_id = ? AND o.status IN ('held','open')";
$args = [$tid];
if (!rest_sees_all()) { $sql .= ' AND o.user_id = ?'; $args[] = TenantContext::userId(); }
$held = $pdo->prepare($sql . ' ORDER BY o.created_at');
$held->execute($args);
$held = $held->fetchAll(PDO::FETCH_ASSOC);

// Resume a held order into the cart.
$resume = null;
if ($rid = (int) ($_GET['order'] ?? 0)) {
    if ($o = pos_editable($pdo, $tid, $rid)) {
        $stmt = $pdo->prepare('SELECT * FROM rest_order_items WHERE order_id = ? ORDER BY id');
        $stmt->execute([$rid]);
        $resume = [
            'id' => (int) $o['id'], 'no' => $o['order_no'], 'status' => $o['status'], 'type' => $o['order_type'], 'table' => $o['table_label'] ?? '',
            'customer' => $o['customer_name'] ?? '', 'discount' => (float) $o['discount'],
            'phone' => $o['customer_phone'] ?? '', 'address' => $o['delivery_address'] ?? '',
            'items' => array_values(array_filter(array_map(fn($i) => [
                'type' => $i['item_type'], 'ref' => (int) $i['ref_id'], 'qty' => (float) $i['quantity'],
                'removed' => json_decode($i['removed'] ?? '[]', true) ?: [], 'note' => $i['note'] ?? '',
            ], $stmt->fetchAll(PDO::FETCH_ASSOC)), fn($i) => $i['type'] === 'dish' ? isset($dishes[$i['ref']]) : isset($counter[$i['ref']]))),
        ];
    }
}

$dishJs = [];
foreach ($dishes as $id => $d) {
    $portions = null;
    foreach ($d['ings'] as $i) {
        if ($i['base'] > 0) $portions = min($portions ?? PHP_INT_MAX, (int) floor($i['stock'] / $i['base']));
    }
    $dishJs[$id] = ['id' => $id, 'name' => $d['name'], 'price' => (float) $d['price'], 'cat' => (int) $d['category_id'],
                    'img' => settings_asset_url($d['image_path']), 'desc' => $d['description'], 'ings' => $d['ings'], 'portions' => $portions,
                    'avail' => rest_dish_available($d), 'window' => rest_window_label($d)];
}
$counterJs = [];
foreach ($counter as $id => $c) {
    $counterJs[$id] = ['id' => $id, 'name' => $c['label'], 'price' => (float) $c['selling_price'], 'left' => (float) $c['quantity'], 'img' => settings_asset_url($c['image_path'])];
}

ob_start();
?>
<?php if ($held): ?>
<div class="held-strip mb-3">
  <span class="small text-muted me-1"><i class="fas fa-pause me-1"></i><?php echo rest_sees_all() ? 'Unpaid orders:' : 'My unpaid orders:'; ?></span>
  <?php foreach ($held as $h): ?>
    <a class="held-chip <?php echo $h['status'] === 'open' ? 'placed' : ''; ?> <?php echo $resume && $resume['id'] === (int) $h['id'] ? 'on' : ''; ?>" title="<?php echo REST_STATUS[$h['status']][0] . (rest_sees_all() ? ' · ' . htmlspecialchars($h['username'] ?? '') : ''); ?>" href="<?php echo $self . '?order=' . (int) $h['id']; ?>">
      <strong><?php echo htmlspecialchars($h['table_label'] ? 'Table ' . $h['table_label'] : ($h['customer_name'] ?: '#' . $h['order_no'])); ?></strong>
      <span><?php echo $h['status'] === 'held' ? 'Held · ' : '#' . htmlspecialchars(substr($h['order_no'], -3)) . ' · '; ?><?php echo $currency . ' ' . number_format((float) $h['total']); ?><?php if (rest_sees_all()): ?> · <?php echo htmlspecialchars($h['username'] ?? ''); ?><?php endif; ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7 col-xl-8">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-3">
        <input type="search" class="form-control mb-2" id="search" placeholder="Search dishes and drinks…">
        <div class="cat-chips mb-3" id="cats">
          <button class="chip on" data-cat="all">All</button>
          <?php foreach ($categories as $c): ?><button class="chip" data-cat="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></button><?php endforeach; ?>
          <?php if ($counterJs): ?><button class="chip" data-cat="counter"><i class="fas fa-bottle-water me-1"></i>Counter</button><?php endif; ?>
        </div>
        <div class="row g-2" id="grid"></div>
        <?php if (!$dishJs && !$counterJs): ?>
          <div class="text-muted p-3">Nothing to sell yet. Add dishes on the <a href="<?php echo public_url('super/restaurant/menu.php'); ?>">Menu</a> page, or move drinks to the counter on <a href="<?php echo public_url('super/restaurant/transfer.php'); ?>">Transfers</a>.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5 col-xl-4">
    <div class="card border-0 shadow-sm cart-card" style="border-radius:12px;">
      <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 mb-0" id="cartTitle">New order</h2>
          <button class="btn btn-sm btn-link text-muted p-0" id="newOrder" title="Start a new order"><i class="fas fa-plus me-1"></i>New</button>
        </div>
        <div class="btn-group w-100 mb-2" role="group">
          <input type="radio" class="btn-check" name="otype" id="ot_dine" value="dine_in" checked><label class="btn btn-sm btn-outline-primary" for="ot_dine"><i class="fas fa-chair me-1"></i>Dine in</label>
          <input type="radio" class="btn-check" name="otype" id="ot_take" value="takeaway"><label class="btn btn-sm btn-outline-primary" for="ot_take"><i class="fas fa-bag-shopping me-1"></i>Takeaway</label>
          <input type="radio" class="btn-check" name="otype" id="ot_del" value="delivery"><label class="btn btn-sm btn-outline-primary" for="ot_del"><i class="fas fa-motorcycle me-1"></i>Delivery</label>
        </div>
        <div class="mb-2" id="delFields" style="display:none;">
          <input class="form-control form-control-sm mb-1" id="phone" placeholder="Customer phone" inputmode="tel">
          <input class="form-control form-control-sm" id="address" placeholder="Delivery address / location">
        </div>
        <div class="d-flex gap-2 mb-3">
          <input class="form-control form-control-sm" id="table" placeholder="Table" style="max-width:90px;">
          <input class="form-control form-control-sm" id="customer" placeholder="Customer name (optional)">
        </div>
        <div id="cartItems" class="mb-2"></div>
        <div class="d-flex justify-content-between small"><span>Subtotal</span><span id="subtotal"></span></div>
        <div class="d-flex justify-content-between align-items-center small my-1"><span>Discount</span>
          <input type="number" min="0" step="0.01" class="form-control form-control-sm text-end" id="discount" style="max-width:110px;" placeholder="0"></div>
        <div class="d-flex justify-content-between fw-bold fs-5 border-top pt-2"><span>Total</span><span id="total"></span></div>
        <div class="d-grid gap-2 mt-3">
          <button class="btn btn-success btn-lg" id="placeBtn"><i class="fas fa-paper-plane me-1"></i><span>Place order</span></button>
          <div class="d-flex gap-2">
            <button class="btn btn-outline-warning flex-fill" id="holdBtn"><i class="fas fa-pause me-1"></i>Hold</button>
            <button class="btn btn-outline-danger flex-fill" id="cancelBtn"><i class="fas fa-ban me-1"></i><span>Clear</span></button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Dish details -->
<div class="modal fade" id="dishModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content" style="border-radius:16px;">
    <div class="modal-header border-0 pb-0"><h5 class="modal-title" id="dmName"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <img id="dmImg" alt="" class="w-100 mb-2" style="max-height:180px;object-fit:cover;border-radius:12px;">
      <p class="small text-muted mb-2" id="dmDesc"></p>
      <div class="alert alert-warning small py-2" id="dmWarn" style="display:none;"></div>
      <h6 class="mb-2">Ingredients <span class="small text-muted fw-normal">— untick what the customer doesn't want</span></h6>
      <div id="dmIngs" class="mb-3"></div>
      <input class="form-control form-control-sm mb-3" id="dmNote" placeholder="Note for the kitchen (optional), e.g. well done">
      <div class="d-flex align-items-center justify-content-between">
        <div class="input-group" style="width:130px;">
          <button class="btn btn-outline-secondary" type="button" id="dmMinus">−</button>
          <input class="form-control text-center" id="dmQty" value="1" inputmode="numeric">
          <button class="btn btn-outline-secondary" type="button" id="dmPlus">+</button>
        </div>
        <button class="btn btn-primary" id="dmAdd"></button>
      </div>
      <div class="small text-muted mt-2" id="dmSummary"></div>
    </div>
  </div></div>
</div>

<!-- Done -->
<div class="modal fade" id="doneModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content text-center p-3" style="border-radius:16px;">
    <i class="fas fa-circle-check text-success" style="font-size:3rem;"></i>
    <h5 class="mt-2 mb-1" id="dnTitle"></h5>
    <div class="small text-muted mb-1">Amount due</div>
    <div class="fs-4 fw-bold mb-1" id="dnTotal"></div>
    <div class="small text-muted mb-3">The customer pays with this receipt number on the Payments page.</div>
    <a class="btn btn-outline-primary mb-2" id="dnReceipt" target="_blank"><i class="fas fa-print me-1"></i>Print order slip</a>
    <button class="btn btn-primary" id="dnNext">Next order</button>
  </div></div>
</div>

<style>
.held-strip{display:flex;gap:6px;overflow-x:auto;align-items:center;padding-bottom:2px;}
.held-chip{display:flex;flex-direction:column;flex:0 0 auto;padding:6px 12px;border-radius:10px;background:#fff7e6;border:1px solid #ffe2a8;color:#7a4b00;text-decoration:none;font-size:.8rem;line-height:1.2;}
.held-chip.placed{background:#e7f5ff;border-color:#a5d8ff;color:#0b4a6f;}
.held-chip.on{background:#ffc107;color:#000;}
.cat-chips{display:flex;gap:6px;overflow-x:auto;}
.chip{flex:0 0 auto;border:1px solid #dee2e6;background:#fff;border-radius:20px;padding:4px 14px;font-size:.85rem;font-weight:600;color:#495057;}
.chip.on{background:var(--tk-green,#0d6efd);border-color:var(--tk-green,#0d6efd);color:#fff;}
.pos-item{background:#fff;border:1px solid #e9ecef;border-radius:12px;overflow:hidden;cursor:pointer;height:100%;position:relative;transition:box-shadow .15s;}
.pos-item:hover{box-shadow:0 4px 14px rgba(0,0,0,.08);}
.pos-item .im{aspect-ratio:4/3;background:#f1f3f5;display:flex;align-items:center;justify-content:center;color:#adb5bd;font-size:1.6rem;}
.pos-item .im img{width:100%;height:100%;object-fit:cover;}
.pos-item .tag{position:absolute;top:6px;right:6px;font-size:.68rem;}
.pos-item.out{opacity:.5;cursor:not-allowed;}
.cart-card{position:sticky;top:80px;}
.cart-line{border-bottom:1px dashed #e9ecef;padding:8px 0;}
.cart-line .nm{font-weight:600;font-size:.9rem;}
.cart-line .sub{font-size:.75rem;color:#6c757d;}
.qbtn{width:28px;height:28px;padding:0;line-height:1;}
</style>
<script>
(function () {
  var CUR = <?php echo json_encode($currency); ?>, CSRF = <?php echo json_encode(csrf_token()); ?>, API = <?php echo json_encode($self); ?>;
  var DISH = <?php echo json_encode((object) $dishJs); ?>, CTR = <?php echo json_encode((object) $counterJs); ?>;
  var resume = <?php echo json_encode($resume); ?>;
  var cart = [], orderId = 0, orderNo = '', orderStatus = '', seq = 0;
  function money(n) { return CUR + ' ' + (Math.round(n * 100) / 100).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
  function $(id) { return document.getElementById(id); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  // ---------- grid ----------
  var cat = 'all';
  function render() {
    var q = $('search').value.trim().toLowerCase(), html = '';
    function card(kind, it, extra, out) {
      return '<div class="col-6 col-md-4 col-xl-3"><div class="pos-item' + (out ? ' out' : '') + '" data-kind="' + kind + '" data-id="' + it.id + '">'
        + '<div class="im">' + (it.img ? '<img src="' + esc(it.img) + '" alt="">' : '<i class="fas ' + (kind === 'dish' ? 'fa-utensils' : 'fa-bottle-water') + '"></i>') + '</div>'
        + extra + '<div class="p-2"><div class="fw-semibold small text-truncate">' + esc(it.name) + '</div><div class="text-primary fw-bold small">' + money(it.price) + '</div></div></div></div>';
    }
    if (cat !== 'counter') Object.values(DISH).forEach(function (d) {
      if (cat !== 'all' && String(d.cat) !== cat) return;
      if (q && d.name.toLowerCase().indexOf(q) === -1) return;
      if (!d.avail) { html += card('dish', d, '<span class="badge bg-dark tag"><i class="far fa-clock me-1"></i>' + esc(d.window) + '</span>', true); return; }
      var tag = d.portions === 0 ? '<span class="badge bg-danger tag">Out of stock</span>' : (d.portions !== null && d.portions <= 5 ? '<span class="badge bg-warning text-dark tag">' + d.portions + ' left</span>' : '');
      html += card('dish', d, tag, false);
    });
    if (cat === 'all' || cat === 'counter') Object.values(CTR).forEach(function (c) {
      if (q && c.name.toLowerCase().indexOf(q) === -1) return;
      var left = c.left - inCart('counter', c.id);
      html += card('counter', c, '<span class="badge ' + (left > 0 ? 'bg-light text-dark' : 'bg-danger') + ' tag">' + (left > 0 ? left + ' left' : 'Sold out') + '</span>', left <= 0);
    });
    $('grid').innerHTML = html || '<div class="text-muted small p-2">No matches.</div>';
    $('grid').querySelectorAll('.pos-item').forEach(function (el) {
      el.onclick = function () {
        if (el.dataset.kind === 'dish') {
          var d = DISH[el.dataset.id];
          if (!d.avail) { alert(d.name + ' is only available ' + d.window + '.'); return; }
          openDish(+el.dataset.id);
        } else addCounter(+el.dataset.id);
      };
    });
  }
  function inCart(type, ref) { return cart.reduce(function (s, l) { return s + (l.type === type && l.ref === ref ? l.qty : 0); }, 0); }
  $('search').oninput = render;
  $('cats').querySelectorAll('.chip').forEach(function (b) {
    b.onclick = function () { $('cats').querySelectorAll('.chip').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); cat = b.dataset.cat; render(); };
  });

  // ---------- dish modal ----------
  var dm, cur = null, editing = null;
  function openDish(id, line) {
    var d = DISH[id]; if (!d) return;
    cur = d; editing = line || null;
    $('dmName').textContent = d.name + ' · ' + money(d.price);
    $('dmDesc').textContent = d.desc || '';
    var img = $('dmImg'); img.style.display = d.img ? '' : 'none'; if (d.img) img.src = d.img;
    $('dmQty').value = line ? line.qty : 1;
    $('dmNote').value = line ? line.note : '';
    var box = $('dmIngs'); box.innerHTML = d.ings.length ? '' : '<div class="small text-muted">No ingredients recorded.</div>';
    d.ings.forEach(function (i) {
      var lab = document.createElement('label');
      lab.className = 'd-flex align-items-center gap-2 border rounded px-2 py-1 mb-1';
      var short = i.stock < i.base;
      lab.innerHTML = '<input type="checkbox" class="form-check-input m-0"><span class="flex-grow-1"></span><span class="small ' + (short ? 'text-danger' : 'text-muted') + '"></span>';
      var cb = lab.querySelector('input'); cb.value = i.id; cb.checked = !(line && line.removed.indexOf(i.id) !== -1);
      lab.querySelector('span').textContent = i.name;
      lab.querySelector('.small').textContent = i.label + (short ? ' · low stock' : '');
      cb.onchange = dishSummary;
      box.appendChild(lab);
    });
    $('dmAdd').textContent = line ? 'Update item' : 'Add to order';
    dishSummary();
    (dm = dm || new bootstrap.Modal($('dishModal'))).show();
  }
  function removedIds() { return [].map.call($('dmIngs').querySelectorAll('input:not(:checked)'), function (c) { return +c.value; }); }
  function dishLabel(d, removed) {
    var names = d.ings.filter(function (i) { return removed.indexOf(i.id) !== -1; }).map(function (i) { return i.name; });
    return d.name + (names.length ? ' — without ' + names.join(', ') : '');
  }
  function dishSummary() {
    var qty = Math.max(1, parseFloat($('dmQty').value) || 1), removed = removedIds();
    $('dmSummary').textContent = qty + ' × ' + dishLabel(cur, removed) + ' = ' + money(qty * cur.price);
    var short = cur.ings.filter(function (i) { return removed.indexOf(i.id) === -1 && i.stock < i.base * qty; }).map(function (i) { return i.name; });
    $('dmWarn').style.display = short.length ? '' : 'none';
    $('dmWarn').innerHTML = '<i class="fas fa-triangle-exclamation me-1"></i>Low or out of stock: ' + esc(short.join(', ')) + '. You can still add it.';
  }
  $('dmQty').oninput = dishSummary;
  $('dmMinus').onclick = function () { $('dmQty').value = Math.max(1, (parseFloat($('dmQty').value) || 1) - 1); dishSummary(); };
  $('dmPlus').onclick = function () { $('dmQty').value = (parseFloat($('dmQty').value) || 0) + 1; dishSummary(); };
  $('dmAdd').onclick = function () {
    var qty = Math.max(1, parseFloat($('dmQty').value) || 1), removed = removedIds().sort(), note = $('dmNote').value.trim();
    if (editing) { editing.qty = qty; editing.removed = removed; editing.note = note; }
    else {
      var same = cart.find(function (l) { return l.type === 'dish' && l.ref === cur.id && l.note === note && l.removed.join() === removed.join(); });
      same ? same.qty += qty : cart.push({key: ++seq, type: 'dish', ref: cur.id, qty: qty, removed: removed, note: note});
    }
    dm.hide(); drawCart();
  };

  function addCounter(id) {
    var c = CTR[id]; if (!c) return;
    if (inCart('counter', id) + 1 > c.left) { alert('Only ' + c.left + ' ' + c.name + ' left at the counter.'); return; }
    var l = cart.find(function (x) { return x.type === 'counter' && x.ref === id; });
    l ? l.qty++ : cart.push({key: ++seq, type: 'counter', ref: id, qty: 1, removed: [], note: ''});
    drawCart();
  }

  // ---------- cart ----------
  function lineInfo(l) {
    if (l.type === 'dish') { var d = DISH[l.ref]; return {name: dishLabel(d, l.removed), price: d.price}; }
    var c = CTR[l.ref]; return {name: c.name, price: c.price};
  }
  function totals() {
    var sub = cart.reduce(function (s, l) { return s + lineInfo(l).price * l.qty; }, 0);
    var disc = Math.min(sub, Math.max(0, parseFloat($('discount').value) || 0));
    return {sub: sub, disc: disc, total: Math.round((sub - disc) * 100) / 100};
  }
  function drawCart() {
    var box = $('cartItems');
    box.innerHTML = cart.length ? '' : '<div class="text-muted small text-center py-4"><i class="fas fa-utensils d-block mb-2 fs-4"></i>Tap a dish or drink to add it.</div>';
    cart.forEach(function (l) {
      var info = lineInfo(l), el = document.createElement('div');
      el.className = 'cart-line';
      el.innerHTML = '<div class="d-flex justify-content-between gap-2"><div class="flex-grow-1" style="min-width:0;"><div class="nm"></div><div class="sub"></div></div>'
        + '<div class="text-end text-nowrap fw-semibold small">' + money(info.price * l.qty) + '</div></div>'
        + '<div class="d-flex align-items-center gap-1 mt-1"><button class="btn btn-sm btn-outline-secondary qbtn mn">−</button><span class="px-2 small fw-bold">' + l.qty + '</span>'
        + '<button class="btn btn-sm btn-outline-secondary qbtn pl">+</button>'
        + (l.type === 'dish' ? '<button class="btn btn-sm btn-link small ed">Edit</button>' : '')
        + '<button class="btn btn-sm btn-link text-danger ms-auto rm"><i class="fas fa-trash"></i></button></div>';
      el.querySelector('.nm').textContent = info.name;
      el.querySelector('.sub').textContent = money(info.price) + ' each' + (l.note ? ' · ' + l.note : '');
      el.querySelector('.mn').onclick = function () { l.qty > 1 ? l.qty-- : cart.splice(cart.indexOf(l), 1); drawCart(); };
      el.querySelector('.pl').onclick = function () {
        if (l.type === 'counter' && inCart('counter', l.ref) + 1 > CTR[l.ref].left) { alert('No more ' + CTR[l.ref].name + ' at the counter.'); return; }
        l.qty++; drawCart();
      };
      el.querySelector('.rm').onclick = function () { cart.splice(cart.indexOf(l), 1); drawCart(); };
      var ed = el.querySelector('.ed'); if (ed) ed.onclick = function () { openDish(l.ref, l); };
      box.appendChild(el);
    });
    var t = totals();
    $('subtotal').textContent = money(t.sub); $('total').textContent = money(t.total);
    $('placeBtn').disabled = $('holdBtn').disabled = !cart.length;
    $('cartTitle').textContent = orderId ? 'Order #' + orderNo + (orderStatus === 'open' ? ' · placed' : ' · held') : 'New order';
    $('placeBtn').querySelector('span').textContent = orderStatus === 'open' ? 'Update order' : 'Place order';
    $('cancelBtn').querySelector('span').textContent = orderId ? 'Cancel order' : 'Clear';
    render();
  }
  $('discount').oninput = drawCart;

  function payload(action, extra) {
    var o = {action: action, csrf: CSRF, order_id: orderId, order_type: document.querySelector('input[name=otype]:checked').value,
             table: $('table').value, customer: $('customer').value, discount: $('discount').value,
             phone: $('phone').value, address: $('address').value,
             items: cart.map(function (l) { return {type: l.type, ref: l.ref, qty: l.qty, removed: l.removed, note: l.note}; })};
    for (var k in extra) o[k] = extra[k];
    return o;
  }
  function send(body) {
    return fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)})
      .then(function (r) { return r.json(); }).catch(function () { return {ok: false, error: 'Network error — check the connection and try again.'}; });
  }
  function resetOrder() { location.href = API; }

  $('holdBtn').onclick = function () {
    if (!$('table').value.trim() && !$('customer').value.trim()) {
      var who = prompt('Table number or customer name for this order:');
      if (!who) return;
      $('customer').value = who;
    }
    send(payload('hold')).then(function (r) { r.ok ? resetOrder() : alert(r.error); });
  };
  $('cancelBtn').onclick = function () {
    if (!orderId) { if (cart.length && confirm('Clear this order?')) { cart = []; drawCart(); } return; }
    if (!confirm('Cancel order #' + orderNo + '? It will be marked cancelled.')) return;
    send({action: 'cancel', csrf: CSRF, order_id: orderId}).then(function (r) { r.ok ? resetOrder() : alert(r.error); });
  };
  $('newOrder').onclick = function () { if (!cart.length || confirm('Start a new order? Unsaved changes here will be lost.')) resetOrder(); };

  // ---------- place ----------
  $('placeBtn').onclick = function () {
    var btn = this; btn.disabled = true;
    send(payload('place')).then(function (r) {
      btn.disabled = false;
      if (!r.ok) { alert(r.error); return; }
      $('dnTitle').textContent = 'Order #' + r.order_no + (orderStatus === 'open' ? ' updated' : ' placed');
      $('dnTotal').textContent = money(r.total);
      $('dnReceipt').href = r.receipt;
      new bootstrap.Modal($('doneModal'), {backdrop: 'static'}).show();
    });
  };
  $('dnNext').onclick = resetOrder;

  // ---------- resume a held order ----------
  if (resume) {
    orderId = resume.id; orderNo = resume.no; orderStatus = resume.status;
    $('table').value = resume.table; $('customer').value = resume.customer;
    $('phone').value = resume.phone || ''; $('address').value = resume.address || '';
    $('discount').value = resume.discount || '';
    document.querySelector('input[name=otype][value=' + resume.type + ']').checked = true;
    cart = resume.items.map(function (i) { i.key = ++seq; i.removed = i.removed.map(Number).sort(); return i; });
  }
  // Delivery: phone + address instead of a table.
  function syncType() {
    var del = document.querySelector('input[name=otype]:checked').value === 'delivery';
    $('delFields').style.display = del ? '' : 'none';
    $('table').style.display = document.querySelector('input[name=otype]:checked').value === 'dine_in' ? '' : 'none';
  }
  document.querySelectorAll('input[name=otype]').forEach(function (r) { r.addEventListener('change', syncType); });
  syncType();
  drawCart();
})();
</script>
<?php
rest_render('New order', ob_get_clean());
