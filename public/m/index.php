<?php
// public/m/index.php — the PUBLIC customer menu (opened from the shared link /
// QR code: /m/?r=<share code>[&t=<table>]). No login and no access to anything
// else: customers browse what's available right now, place an order, and track
// it by receipt number. Orders land as 'open' (awaiting payment) with
// source = 'online', and are paid on the restaurant's Payments page.
define('REST_PUBLIC', true);
require_once __DIR__ . '/../super/restaurant/_common.php';

/** Resolve the restaurant from the share code; null if unknown / switched off. */
function m_shop(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{12}$/', $token)) return null;
    $stmt = $pdo->prepare("SELECT s.*, t.id AS tid, t.name, t.logo_path, t.currency, t.phone, t.address, t.status, t.parent_tenant_id
                             FROM rest_settings s JOIN tenants t ON t.id = s.tenant_id WHERE s.public_token = ?");
    $stmt->execute([$token]);
    $shop = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shop || $shop['status'] !== 'active' || !Features::enabled(Features::RESTAURANT_POS, (int) $shop['tid'])) return null;
    $acct = Features::accountId((int) $shop['tid']);
    $st = $pdo->prepare('SELECT status FROM tenants WHERE id = ?');
    $st->execute([$acct]);
    return $st->fetchColumn() === 'active' ? $shop : null;
}

/** Orderable dishes (active, in their time window) + counter items in stock. */
function m_menu(PDO $pdo, int $tid): array
{
    $stmt = $pdo->prepare('SELECT d.*, c.name AS category_name, c.sort_order FROM rest_dishes d LEFT JOIN rest_categories c ON c.id = d.category_id
                            WHERE d.tenant_id = ? AND d.is_active = 1 ORDER BY c.sort_order IS NULL, c.sort_order, c.name, d.name');
    $stmt->execute([$tid]);
    $dishes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $dishes[(int) $d['id']] = $d + ['ings' => []];
    }
    if ($dishes) {
        $ids = implode(',', array_keys($dishes));
        foreach ($pdo->query("SELECT di.dish_id, i.id, i.name FROM rest_dish_ingredients di JOIN rest_ingredients i ON i.id = di.ingredient_id
                               WHERE di.dish_id IN ($ids) ORDER BY i.name") as $r) {
            $dishes[(int) $r['dish_id']]['ings'][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
    }
    $stmt = $pdo->prepare("SELECT o.id, o.type_name, o.quantity, o.selling_price, s.name AS stock_name, s.image_path
                             FROM rest_outlet_items o JOIN rest_stock s ON s.id = o.stock_id
                            WHERE o.tenant_id = ? AND o.outlet = 'counter' AND o.selling_price IS NOT NULL AND o.quantity > 0 ORDER BY s.name, o.type_name");
    $stmt->execute([$tid]);
    $counter = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $c['label'] = $c['stock_name'] . ($c['type_name'] !== '' ? ' · ' . $c['type_name'] : '');
        $counter[(int) $c['id']] = $c;
    }
    return [$dishes, $counter];
}

function m_json(array $data): void
{
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex');
    echo json_encode($data);
    exit;
}

$token = (string) ($_GET['r'] ?? '');
$shop = m_shop($pdo, $token);
$table = mb_substr(trim((string) ($_GET['t'] ?? '')), 0, 20);

// ---------------------------------------------------------------- API (JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!$shop) m_json(['ok' => false, 'error' => 'This menu link is no longer active.']);
    if (!csrf_verify($in['csrf'] ?? null)) m_json(['ok' => false, 'error' => 'Your session expired. Please reload the page.']);
    $tid = (int) $shop['tid'];
    $action = $in['action'] ?? '';

    if ($action === 'track') {
        // Only online orders can be tracked, and only status + items are shown (no names, phones or addresses).
        $no = preg_replace('/[^0-9-]/', '', (string) ($in['receipt'] ?? ''));
        $stmt = $pdo->prepare("SELECT id, order_no, order_type, status, total, created_at, paid_at FROM rest_orders
                                WHERE tenant_id = ? AND order_no = ? AND source = 'online' LIMIT 1");
        $stmt->execute([$tid, $no]);
        $o = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$o) m_json(['ok' => false, 'error' => 'No order found with receipt number ' . $no . '. Check the number and try again.']);
        $items = $pdo->prepare('SELECT name, quantity, line_total FROM rest_order_items WHERE order_id = ? ORDER BY id');
        $items->execute([$o['id']]);
        $label = ['open' => 'Order received — awaiting payment', 'held' => 'Order received', 'paid' => 'Paid — thank you!', 'cancelled' => 'Cancelled'][$o['status']];
        m_json(['ok' => true, 'order' => [
            'no' => $o['order_no'], 'status' => $o['status'], 'label' => $label, 'type' => REST_ORDER_TYPES[$o['order_type']],
            'total' => (float) $o['total'], 'time' => date('d M, g:ia', strtotime($o['created_at'])),
            'items' => array_map(fn($i) => ['name' => $i['name'], 'qty' => rest_qty((float) $i['quantity']), 'total' => (float) $i['line_total']], $items->fetchAll(PDO::FETCH_ASSOC)),
        ]]);
    }

    if ($action !== 'place') m_json(['ok' => false, 'error' => 'Unknown request.']);
    if (!$shop['online_enabled']) m_json(['ok' => false, 'error' => 'Online ordering is closed right now.']);
    if (!empty($in['website'])) m_json(['ok' => false, 'error' => 'Could not place the order.']); // bot honeypot

    // Throttle: at most 3 online orders per device/IP every 10 minutes.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['m_orders'] = array_filter($_SESSION['m_orders'] ?? [], fn($t) => $t > time() - 600);
    $recent = $pdo->prepare("SELECT COUNT(*) FROM rest_orders WHERE tenant_id = ? AND source = 'online' AND client_ip = ? AND created_at > NOW() - INTERVAL 10 MINUTE");
    $recent->execute([$tid, $ip]);
    if (count($_SESSION['m_orders']) >= 3 || $recent->fetchColumn() >= 3) {
        m_json(['ok' => false, 'error' => 'You\'ve placed several orders just now. Please wait a few minutes or call us.']);
    }

    $type = $in['order_type'] ?? '';
    $allowed = array_filter(['dine_in' => $shop['allow_dine_in'], 'takeaway' => $shop['allow_takeaway'], 'delivery' => $shop['allow_delivery']]);
    if (!isset($allowed[$type])) m_json(['ok' => false, 'error' => 'Choose how you want your order.']);
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
    $phone = preg_replace('/[^0-9+]/', '', (string) ($in['phone'] ?? ''));
    $tableIn = mb_substr(trim((string) ($in['table'] ?? '')), 0, 20);
    $address = mb_substr(trim((string) ($in['address'] ?? '')), 0, 255);
    if (mb_strlen($name) < 2) m_json(['ok' => false, 'error' => 'Please enter your name.']);
    if (strlen($phone) < 9 || strlen($phone) > 15) m_json(['ok' => false, 'error' => 'Please enter a valid phone number.']);
    if ($type === 'dine_in' && $tableIn === '') m_json(['ok' => false, 'error' => 'Please enter your table number.']);
    if ($type === 'delivery' && mb_strlen($address) < 5) m_json(['ok' => false, 'error' => 'Please tell us where to deliver.']);

    [$dishes, $counter] = m_menu($pdo, $tid);
    $lines = [];
    $needCounter = [];
    foreach (array_slice((array) ($in['items'] ?? []), 0, 40) as $it) {
        $qty = (int) ($it['qty'] ?? 0);
        $ref = (int) ($it['ref'] ?? 0);
        if ($qty < 1 || $qty > 50) continue;
        $note = mb_substr(trim((string) ($it['note'] ?? '')), 0, 120);
        if (($it['type'] ?? '') === 'dish') {
            if (!isset($dishes[$ref])) m_json(['ok' => false, 'error' => 'An item in your order is no longer on the menu. Please refresh.']);
            $d = $dishes[$ref];
            if (!rest_dish_available($d)) m_json(['ok' => false, 'error' => $d['name'] . ' is only available ' . rest_window_label($d) . '. Please remove it.']);
            $removed = array_values(array_intersect(array_map('intval', (array) ($it['removed'] ?? [])), array_column($d['ings'], 'id')));
            $removedNames = array_column(array_filter($d['ings'], fn($i) => in_array($i['id'], $removed, true)), 'name');
            $lines[] = ['type' => 'dish', 'ref' => $ref, 'qty' => $qty, 'price' => (float) $d['price'], 'removed' => $removed, 'note' => $note,
                        'name' => $d['name'] . ($removedNames ? ' — without ' . implode(', ', $removedNames) : '')];
        } elseif (($it['type'] ?? '') === 'counter') {
            if (!isset($counter[$ref])) m_json(['ok' => false, 'error' => 'An item in your order just sold out. Please refresh.']);
            $c = $counter[$ref];
            $needCounter[$ref] = ($needCounter[$ref] ?? 0) + $qty;
            if ($needCounter[$ref] > (float) $c['quantity']) m_json(['ok' => false, 'error' => 'Only ' . rest_qty((float) $c['quantity']) . ' ' . $c['label'] . ' left.']);
            $lines[] = ['type' => 'counter', 'ref' => $ref, 'qty' => $qty, 'price' => (float) $c['selling_price'], 'removed' => [], 'note' => $note, 'name' => $c['label']];
        }
    }
    if (!$lines) m_json(['ok' => false, 'error' => 'Your order is empty.']);
    $total = round(array_sum(array_map(fn($l) => $l['price'] * $l['qty'], $lines)), 2);

    $pdo->beginTransaction();
    try {
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM rest_orders WHERE tenant_id = ? AND DATE(created_at) = CURDATE()');
        $cnt->execute([$tid]);
        $orderNo = date('ymd') . '-' . str_pad((string) ($cnt->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO rest_orders (tenant_id, order_no, source, order_type, table_label, customer_name, customer_phone, delivery_address, client_ip, status, subtotal, discount, total)
                       VALUES (?,?,?,?,?,?,?,?,?, 'open', ?, 0, ?)")
            ->execute([$tid, $orderNo, 'online', $type, $type === 'dine_in' ? $tableIn : null, $name, $phone, $type === 'delivery' ? $address : null, $ip, $total, $total]);
        $orderId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO rest_order_items (order_id, item_type, ref_id, name, unit_price, quantity, removed, note, line_total) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($lines as $l) {
            $ins->execute([$orderId, $l['type'], $l['ref'], $l['name'], $l['price'], $l['qty'], $l['removed'] ? json_encode($l['removed']) : null, $l['note'] ?: null, round($l['price'] * $l['qty'], 2)]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        m_json(['ok' => false, 'error' => 'Could not place your order. Please try again.']);
    }
    $_SESSION['m_orders'][] = time();
    m_json(['ok' => true, 'receipt' => $orderNo, 'total' => $total]);
}

// ---------------------------------------------------------------- Page
$menu = $shop ? m_menu($pdo, (int) $shop['tid']) : [[], []];
[$dishes, $counter] = $menu;
$cur = $shop['currency'] ?? 'KES';
$cats = [];
$dishJs = [];
foreach ($dishes as $id => $d) {
    $catKey = $d['category_id'] ? (string) $d['category_id'] : 'other';
    $cats[$catKey] ??= $d['category_name'] ?? 'Other';
    $dishJs[] = ['id' => $id, 'name' => $d['name'], 'price' => (float) $d['price'], 'desc' => $d['description'], 'cat' => $catKey,
                 'img' => settings_asset_url($d['image_path']), 'ings' => $d['ings'],
                 'avail' => rest_dish_available($d), 'window' => rest_window_label($d)];
}
$counterJs = array_values(array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['label'], 'price' => (float) $c['selling_price'],
                                               'left' => (float) $c['quantity'], 'img' => settings_asset_url($c['image_path'])], $counter));
$types = $shop ? array_keys(array_filter(['dine_in' => $shop['allow_dine_in'], 'takeaway' => $shop['allow_takeaway'], 'delivery' => $shop['allow_delivery']])) : [];
header('X-Robots-Tag: noindex');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?php echo htmlspecialchars($shop['name'] ?? 'Menu'); ?> · Menu</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--accent:#16a34a;--accent-d:#15803d;}
body{font-family:'Open Sans',sans-serif;background:#f6f7f9;padding-bottom:90px;}
.top{position:sticky;top:0;z-index:20;background:#fff;border-bottom:1px solid #eceef1;}
.top .in{max-width:860px;margin:0 auto;display:flex;align-items:center;gap:10px;padding:10px 16px;}
.top img{width:38px;height:38px;border-radius:10px;object-fit:cover;}
.top .nm{font-weight:700;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.track-btn{font-size:.78rem;border:1px solid #d0d5dd;border-radius:20px;padding:4px 10px;background:#fff;color:#344054;white-space:nowrap;}
.wrap{max-width:860px;margin:0 auto;padding:12px 16px;}
.chips{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px;position:sticky;top:59px;background:#f6f7f9;z-index:10;padding-top:6px;}
.chip{flex:0 0 auto;border:1px solid #d0d5dd;background:#fff;border-radius:20px;padding:5px 14px;font-size:.85rem;font-weight:600;color:#344054;}
.chip.on{background:var(--accent);border-color:var(--accent);color:#fff;}
.sec{font-weight:700;font-size:.95rem;margin:18px 0 8px;color:#101828;}
.item{display:flex;gap:12px;background:#fff;border-radius:14px;padding:10px;margin-bottom:10px;border:1px solid #eceef1;cursor:pointer;}
.item .im{width:84px;height:84px;border-radius:10px;background:#f2f4f7;flex:0 0 auto;display:flex;align-items:center;justify-content:center;color:#98a2b3;font-size:1.5rem;overflow:hidden;}
.item .im img{width:100%;height:100%;object-fit:cover;}
.item .bd{flex:1;min-width:0;}
.item .t{font-weight:600;} .item .d{font-size:.8rem;color:#667085;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.item .p{font-weight:700;color:var(--accent-d);margin-top:4px;}
.item.off{opacity:.55;cursor:default;}
.add{align-self:center;width:34px;height:34px;border-radius:50%;border:0;background:var(--accent);color:#fff;flex:0 0 auto;}
.bar{position:fixed;left:0;right:0;bottom:0;padding:12px 16px calc(12px + env(safe-area-inset-bottom));background:linear-gradient(transparent,#f6f7f9 30%);z-index:30;}
.bar button{max-width:828px;margin:0 auto;display:flex;width:100%;justify-content:space-between;align-items:center;border:0;border-radius:14px;background:var(--accent);color:#fff;font-weight:700;padding:14px 18px;font-size:1rem;box-shadow:0 8px 20px rgba(22,163,74,.35);}
.notice{background:#fff7e6;border:1px solid #ffe2a8;color:#7a4b00;border-radius:12px;padding:10px 12px;font-size:.85rem;}
.modal-content{border-radius:18px;}
.ln{border-bottom:1px solid #f0f1f3;padding:8px 0;}
.qb{width:30px;height:30px;padding:0;border-radius:50%;}
.big-no{font-size:2rem;font-weight:800;letter-spacing:1px;}
.stat{display:inline-block;border-radius:20px;padding:4px 12px;font-weight:700;font-size:.85rem;}
</style>
</head>
<body>
<?php if (!$shop): ?>
  <div class="wrap text-center" style="padding-top:80px;">
    <i class="fas fa-utensils fa-3x text-muted mb-3"></i>
    <h1 class="h5">This menu isn't available</h1>
    <p class="text-muted">The link may be old or the restaurant has paused online orders. Please ask staff for the current QR code.</p>
  </div>
</body></html>
<?php exit; endif; ?>

<header class="top"><div class="in">
  <img src="<?php echo htmlspecialchars(Branding::tenantLogo(['logo_path' => $shop['logo_path']])); ?>" alt="">
  <div class="nm"><?php echo htmlspecialchars($shop['name']); ?></div>
  <button class="track-btn" id="trackOpen"><i class="fas fa-location-crosshairs me-1"></i>Track order</button>
</div></header>

<div class="wrap">
  <?php if (!$shop['online_enabled']): ?><div class="notice mb-2"><i class="fas fa-circle-info me-1"></i>We're not taking online orders right now — you can still browse the menu.</div><?php endif; ?>
  <?php if ($shop['notice']): ?><div class="notice mb-2"><?php echo htmlspecialchars($shop['notice']); ?></div><?php endif; ?>
  <?php if ($table !== ''): ?><div class="small text-muted mb-1"><i class="fas fa-chair me-1"></i>Ordering for table <strong><?php echo htmlspecialchars($table); ?></strong></div><?php endif; ?>
  <input type="search" class="form-control mb-2" id="q" placeholder="Search the menu…">
  <div class="chips" id="chips"></div>
  <div id="list"></div>
</div>

<div class="bar" id="bar" style="display:none;"><button id="cartOpen"><span><i class="fas fa-bag-shopping me-2"></i><span id="barCount"></span></span><span id="barTotal"></span></button></div>

<!-- Item -->
<div class="modal fade" id="itemM" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down"><div class="modal-content">
  <div class="modal-header border-0 pb-0"><h5 class="modal-title" id="imName"></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <img id="imImg" class="w-100 mb-2" style="max-height:220px;object-fit:cover;border-radius:12px;" alt="">
    <p class="small text-muted" id="imDesc"></p>
    <div id="imIngWrap"><div class="fw-semibold small mb-1">Ingredients <span class="text-muted fw-normal">— untick anything you don't want</span></div><div id="imIngs" class="mb-3"></div></div>
    <input class="form-control form-control-sm mb-3" id="imNote" maxlength="120" placeholder="Special request (optional)">
    <div class="d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center gap-2"><button class="btn btn-outline-secondary qb" id="imMinus">−</button><strong id="imQty">1</strong><button class="btn btn-outline-secondary qb" id="imPlus">+</button></div>
      <button class="btn btn-success px-4" id="imAdd"></button>
    </div>
  </div>
</div></div></div>

<!-- Cart / checkout -->
<div class="modal fade" id="cartM" tabindex="-1"><div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Your order</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div id="cartLines"></div>
    <div class="d-flex justify-content-between fw-bold fs-5 my-3"><span>Total</span><span id="cartTotal"></span></div>
    <?php if ($shop['online_enabled']): ?>
    <div class="btn-group w-100 mb-3" role="group">
      <?php foreach ($types as $i => $t): ?>
        <input type="radio" class="btn-check" name="otype" id="ot_<?php echo $t; ?>" value="<?php echo $t; ?>" <?php echo ($table !== '' ? $t === 'dine_in' : $i === 0) ? 'checked' : ''; ?>>
        <label class="btn btn-outline-success btn-sm" for="ot_<?php echo $t; ?>"><?php echo REST_ORDER_TYPES[$t]; ?></label>
      <?php endforeach; ?>
    </div>
    <input class="form-control mb-2" id="cName" maxlength="80" placeholder="Your name" autocomplete="name">
    <input class="form-control mb-2" id="cPhone" type="tel" maxlength="16" placeholder="Phone number" autocomplete="tel">
    <input class="form-control mb-2" id="cTable" maxlength="20" placeholder="Table number" value="<?php echo htmlspecialchars($table); ?>">
    <textarea class="form-control mb-2" id="cAddr" rows="2" maxlength="255" placeholder="Delivery location (estate, building, landmark)" autocomplete="street-address"></textarea>
    <input type="text" id="cWeb" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
    <div class="alert alert-danger small py-2" id="cErr" style="display:none;"></div>
    <?php endif; ?>
  </div>
  <div class="modal-footer">
    <?php if ($shop['online_enabled']): ?>
      <button class="btn btn-success w-100 btn-lg" id="placeBtn"><i class="fas fa-paper-plane me-1"></i>Place order</button>
    <?php else: ?>
      <div class="text-muted small w-100 text-center">Online ordering is closed right now.</div>
    <?php endif; ?>
  </div>
</div></div></div>

<!-- Placed -->
<div class="modal fade" id="doneM" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content text-center p-4">
  <i class="fas fa-circle-check text-success" style="font-size:3rem;"></i>
  <h5 class="mt-2">Order placed!</h5>
  <div class="small text-muted">Your receipt number</div>
  <div class="big-no" id="dnNo"></div>
  <div class="mb-3">Amount to pay: <strong id="dnTotal"></strong></div>
  <p class="small text-muted">Keep this number — use <strong>Track order</strong> at the top to check your order, and give it to us when you pay.</p>
  <button class="btn btn-success" data-bs-dismiss="modal">Done</button>
</div></div></div>

<!-- Track -->
<div class="modal fade" id="trackM" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Track your order</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="input-group mb-2"><input class="form-control" id="tNo" inputmode="numeric" placeholder="Receipt number, e.g. 261009-004"><button class="btn btn-success" id="tGo">Track</button></div>
    <div id="tMine" class="mb-2"></div>
    <div class="alert alert-danger small py-2" id="tErr" style="display:none;"></div>
    <div id="tRes"></div>
  </div>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  var CUR = <?php echo json_encode($cur); ?>, CSRF = <?php echo json_encode(csrf_token()); ?>, API = location.pathname + location.search;
  var DISHES = <?php echo json_encode($dishJs); ?>, CTR = <?php echo json_encode($counterJs); ?>, CATS = <?php echo json_encode($cats); ?>;
  var KEY = 'm_' + <?php echo json_encode($token); ?>;
  var cart = [], cat = 'all';
  function $(id) { return document.getElementById(id); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function money(n) { return CUR + ' ' + (Math.round(n * 100) / 100).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2}); }
  function store(k, v) { try { if (v === undefined) return JSON.parse(localStorage.getItem(KEY + k) || 'null'); localStorage.setItem(KEY + k, JSON.stringify(v)); } catch (e) { return null; } }
  var dById = {}, cById = {};
  DISHES.forEach(function (d) { dById[d.id] = d; }); CTR.forEach(function (c) { cById[c.id] = c; });
  // Restore a saved cart, dropping anything no longer orderable.
  cart = (store('cart') || []).filter(function (l) { return l.type === 'dish' ? dById[l.ref] && dById[l.ref].avail : cById[l.ref]; });
  var me = store('me') || {}; if ($('cName')) { $('cName').value = me.name || ''; $('cPhone').value = me.phone || ''; $('cAddr').value = me.addr || ''; }

  // ---------- menu ----------
  var chips = '<button class="chip on" data-c="all">All</button>';
  Object.keys(CATS).forEach(function (k) { chips += '<button class="chip" data-c="' + k + '">' + esc(CATS[k]) + '</button>'; });
  if (CTR.length) chips += '<button class="chip" data-c="drinks">Drinks</button>';
  $('chips').innerHTML = chips;
  $('chips').querySelectorAll('.chip').forEach(function (b) { b.onclick = function () {
    $('chips').querySelectorAll('.chip').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); cat = b.dataset.c; draw();
  }; });
  $('q').oninput = draw;
  function row(kind, it, off, sub) {
    return '<div class="item' + (off ? ' off' : '') + '" data-k="' + kind + '" data-id="' + it.id + '"><div class="im">'
      + (it.img ? '<img src="' + esc(it.img) + '" alt="" loading="lazy">' : '<i class="fas ' + (kind === 'dish' ? 'fa-utensils' : 'fa-bottle-water') + '"></i>')
      + '</div><div class="bd"><div class="t">' + esc(it.name) + '</div>' + (sub ? '<div class="d">' + sub + '</div>' : '')
      + '<div class="p">' + money(it.price) + '</div></div>' + (off ? '' : '<button class="add" aria-label="Add"><i class="fas fa-plus"></i></button>') + '</div>';
  }
  function draw() {
    var q = $('q').value.trim().toLowerCase(), html = '', later = '';
    var groups = {};
    DISHES.forEach(function (d) {
      if (cat !== 'all' && cat !== d.cat) return;
      if (q && d.name.toLowerCase().indexOf(q) === -1) return;
      if (!d.avail) { later += row('dish', d, true, '<i class="far fa-clock me-1"></i>Available ' + esc(d.window)); return; }
      (groups[d.cat] = groups[d.cat] || []).push(row('dish', d, false, esc(d.desc || '')));
    });
    Object.keys(CATS).forEach(function (k) { if (groups[k]) html += '<div class="sec">' + esc(CATS[k]) + '</div>' + groups[k].join(''); });
    if (cat === 'all' || cat === 'drinks') {
      var dr = CTR.filter(function (c) { return !q || c.name.toLowerCase().indexOf(q) !== -1; });
      if (dr.length) html += '<div class="sec">Drinks</div>' + dr.map(function (c) { return row('counter', c, false, ''); }).join('');
    }
    if (later) html += '<div class="sec text-muted">Not available right now</div>' + later;
    $('list').innerHTML = html || '<div class="text-muted text-center py-5">Nothing matches.</div>';
    $('list').querySelectorAll('.item:not(.off)').forEach(function (el) {
      el.onclick = function () { el.dataset.k === 'dish' ? openDish(+el.dataset.id) : addDrink(+el.dataset.id); };
    });
  }

  // ---------- item ----------
  var im, cur = null, qty = 1;
  function openDish(id) {
    cur = dById[id]; qty = 1;
    $('imName').textContent = cur.name; $('imDesc').textContent = cur.desc || '';
    $('imImg').style.display = cur.img ? '' : 'none'; if (cur.img) $('imImg').src = cur.img;
    $('imIngWrap').style.display = cur.ings.length ? '' : 'none';
    $('imIngs').innerHTML = cur.ings.map(function (i) {
      return '<label class="d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1"><input type="checkbox" class="form-check-input m-0" value="' + i.id + '" checked><span>' + esc(i.name) + '</span></label>';
    }).join('');
    $('imNote').value = ''; sync();
    (im = im || new bootstrap.Modal($('itemM'))).show();
  }
  function sync() { $('imQty').textContent = qty; $('imAdd').textContent = 'Add · ' + money(qty * cur.price); }
  $('imMinus').onclick = function () { if (qty > 1) { qty--; sync(); } };
  $('imPlus').onclick = function () { if (qty < 50) { qty++; sync(); } };
  $('imAdd').onclick = function () {
    var removed = [].map.call($('imIngs').querySelectorAll('input:not(:checked)'), function (c) { return +c.value; }).sort();
    var note = $('imNote').value.trim();
    var same = cart.find(function (l) { return l.type === 'dish' && l.ref === cur.id && l.note === note && l.removed.join() === removed.join(); });
    same ? same.qty += qty : cart.push({type: 'dish', ref: cur.id, qty: qty, removed: removed, note: note});
    im.hide(); saveCart();
  };
  function inCart(ref) { return cart.reduce(function (s, l) { return s + (l.type === 'counter' && l.ref === ref ? l.qty : 0); }, 0); }
  function addDrink(id) {
    var c = cById[id]; if (inCart(id) + 1 > c.left) { alert('Sorry, only ' + c.left + ' left.'); return; }
    var l = cart.find(function (x) { return x.type === 'counter' && x.ref === id; });
    l ? l.qty++ : cart.push({type: 'counter', ref: id, qty: 1, removed: [], note: ''});
    saveCart();
  }

  // ---------- cart ----------
  function info(l) {
    if (l.type === 'counter') return {name: cById[l.ref].name, price: cById[l.ref].price};
    var d = dById[l.ref], off = d.ings.filter(function (i) { return l.removed.indexOf(i.id) !== -1; }).map(function (i) { return i.name; });
    return {name: d.name + (off.length ? ' — without ' + off.join(', ') : ''), price: d.price};
  }
  function total() { return cart.reduce(function (s, l) { return s + info(l).price * l.qty; }, 0); }
  function saveCart() {
    store('cart', cart);
    var n = cart.reduce(function (s, l) { return s + l.qty; }, 0);
    $('bar').style.display = n ? '' : 'none';
    $('barCount').textContent = n + (n === 1 ? ' item' : ' items') + ' · View order';
    $('barTotal').textContent = money(total());
    drawCart();
  }
  function drawCart() {
    $('cartLines').innerHTML = cart.length ? '' : '<div class="text-muted text-center py-4">Your order is empty.</div>';
    cart.forEach(function (l) {
      var i = info(l), el = document.createElement('div'); el.className = 'ln';
      el.innerHTML = '<div class="d-flex justify-content-between gap-2"><div><div class="fw-semibold small">' + esc(i.name) + '</div>' + (l.note ? '<div class="small text-muted">' + esc(l.note) + '</div>' : '') + '</div>'
        + '<div class="small fw-semibold text-nowrap">' + money(i.price * l.qty) + '</div></div>'
        + '<div class="d-flex align-items-center gap-2 mt-1"><button class="btn btn-outline-secondary qb m">−</button><span>' + l.qty + '</span><button class="btn btn-outline-secondary qb p">+</button>'
        + '<button class="btn btn-link btn-sm text-danger ms-auto r">Remove</button></div>';
      el.querySelector('.m').onclick = function () { l.qty > 1 ? l.qty-- : cart.splice(cart.indexOf(l), 1); saveCart(); };
      el.querySelector('.p').onclick = function () { if (l.type === 'counter' && inCart(l.ref) + 1 > cById[l.ref].left) return; if (l.qty < 50) l.qty++; saveCart(); };
      el.querySelector('.r').onclick = function () { cart.splice(cart.indexOf(l), 1); saveCart(); };
      $('cartLines').appendChild(el);
    });
    $('cartTotal').textContent = money(total());
  }
  var cm; $('cartOpen').onclick = function () { syncType(); (cm = cm || new bootstrap.Modal($('cartM'))).show(); };
  function otype() { var r = document.querySelector('input[name=otype]:checked'); return r ? r.value : ''; }
  function syncType() {
    if (!$('cTable')) return;
    $('cTable').style.display = otype() === 'dine_in' ? '' : 'none';
    $('cAddr').style.display = otype() === 'delivery' ? '' : 'none';
  }
  document.querySelectorAll('input[name=otype]').forEach(function (r) { r.onchange = syncType; });

  function post(body) {
    body.csrf = CSRF;
    return fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)})
      .then(function (r) { return r.json(); }).catch(function () { return {ok: false, error: 'No connection. Please try again.'}; });
  }
  if ($('placeBtn')) $('placeBtn').onclick = function () {
    var btn = this; $('cErr').style.display = 'none';
    if (!cart.length) return;
    btn.disabled = true;
    var body = {action: 'place', order_type: otype(), name: $('cName').value, phone: $('cPhone').value, table: $('cTable').value,
                address: $('cAddr').value, website: $('cWeb').value, items: cart};
    store('me', {name: body.name, phone: body.phone, addr: body.address});
    post(body).then(function (r) {
      btn.disabled = false;
      if (!r.ok) { $('cErr').textContent = r.error; $('cErr').style.display = ''; return; }
      var mine = store('orders') || []; mine.unshift(r.receipt); store('orders', mine.slice(0, 5));
      cart = []; saveCart(); cm.hide();
      $('dnNo').textContent = '#' + r.receipt; $('dnTotal').textContent = money(r.total);
      new bootstrap.Modal($('doneM')).show();
    });
  };

  // ---------- track ----------
  var tm;
  $('trackOpen').onclick = function () {
    var mine = store('orders') || [];
    $('tMine').innerHTML = mine.length ? '<div class="small text-muted mb-1">Your recent orders:</div>' + mine.map(function (n) { return '<button class="btn btn-sm btn-light border me-1 mb-1" data-n="' + esc(n) + '">#' + esc(n) + '</button>'; }).join('') : '';
    $('tMine').querySelectorAll('button').forEach(function (b) { b.onclick = function () { $('tNo').value = b.dataset.n; track(); }; });
    $('tRes').innerHTML = ''; $('tErr').style.display = 'none';
    (tm = tm || new bootstrap.Modal($('trackM'))).show();
    if (mine.length && !$('tNo').value) { $('tNo').value = mine[0]; track(); }
  };
  var COLORS = {open: '#e0f2fe;color:#075985', held: '#fef3c7;color:#92400e', paid: '#dcfce7;color:#166534', cancelled: '#f2f4f7;color:#475467'};
  function track() {
    var no = $('tNo').value.replace(/^#/, '').trim(); if (!no) return;
    $('tErr').style.display = 'none'; $('tRes').innerHTML = '<div class="text-muted small">Checking…</div>';
    post({action: 'track', receipt: no}).then(function (r) {
      if (!r.ok) { $('tRes').innerHTML = ''; $('tErr').textContent = r.error; $('tErr').style.display = ''; return; }
      var o = r.order;
      $('tRes').innerHTML = '<div class="border rounded-3 p-3"><div class="d-flex justify-content-between align-items-center mb-2"><strong>#' + esc(o.no) + '</strong>'
        + '<span class="stat" style="background:' + COLORS[o.status] + '">' + esc(o.label) + '</span></div>'
        + '<div class="small text-muted mb-2">' + esc(o.type) + ' · ' + esc(o.time) + '</div>'
        + o.items.map(function (i) { return '<div class="d-flex justify-content-between small py-1"><span>' + esc(i.qty) + ' × ' + esc(i.name) + '</span><span>' + money(i.total) + '</span></div>'; }).join('')
        + '<div class="d-flex justify-content-between fw-bold border-top pt-2 mt-1"><span>Total</span><span>' + money(o.total) + '</span></div></div>';
    });
  }
  $('tGo').onclick = track;
  $('tNo').onkeydown = function (e) { if (e.key === 'Enter') track(); };

  draw(); saveCart(); syncType();
})();
</script>
</body>
</html>
