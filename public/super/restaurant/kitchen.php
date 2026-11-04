<?php
// public/super/restaurant/kitchen.php — the kitchen portal (staff whose position
// is Chef / Kitchen, and owners).
//   Tickets          every live order with dishes to make, oldest first, in three
//                    columns: New → Preparing → Ready (→ Served). Refreshes by
//                    itself and beeps when a new order comes in.
//   Menu             mark a dish Finished (sold out) — it leaves the order screen
//                    and the online menu — or Available again.
//   Kitchen stock    what is left of each ingredient in the kitchen, and
//                    "Request from store": the request shows on Transfers for
//                    whoever moves stock out of the store.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::SALES_RECORD);
require_once __DIR__ . '/_common.php';

if (!rest_is_kitchen()) {
    $_SESSION['flash']['error'] = 'The kitchen portal is for kitchen staff (position Chef or Kitchen).';
    header('Location: ' . public_url('super/restaurant/pos.php'));
    exit;
}
$self = public_url('super/restaurant/kitchen.php');
$tab = in_array($_GET['tab'] ?? '', ['tickets', 'menu', 'stock'], true) ? $_GET['tab'] : 'tickets';
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $act = $_POST['act'] ?? '';
    if ($act === 'ticket') {
        // Move every dish line of one order to the next kitchen step.
        $oid = (int) ($_POST['order_id'] ?? 0);
        $to = (string) ($_POST['to'] ?? '');
        $from = ['preparing' => ['new'], 'ready' => ['new', 'preparing'], 'served' => ['ready', 'preparing', 'new']][$to] ?? null;
        if ($from) {
            $in = "'" . implode("','", $from) . "'";
            $extra = $to === 'preparing' ? ', kitchen_started_at = NOW()' : ($to === 'ready' ? ', kitchen_ready_at = NOW(), kitchen_started_at = COALESCE(kitchen_started_at, NOW())' : '');
            $pdo->prepare("UPDATE rest_order_items i JOIN rest_orders o ON o.id = i.order_id
                              SET i.kitchen_status = ? $extra
                            WHERE o.id = ? AND o.tenant_id = ? AND i.item_type = 'dish' AND COALESCE(i.kitchen_status, 'new') IN ($in)")
                ->execute([$to, $oid, $tid]);
        }
        header('Location: ' . $self);
        exit;
    }
    if ($act === 'soldout') {
        $pdo->prepare('UPDATE rest_dishes SET sold_out = ? WHERE id = ? AND tenant_id = ?')->execute([!empty($_POST['on']) ? 1 : 0, (int) $_POST['dish'], $tid]);
        rest_flash(!empty($_POST['on']) ? 'Marked finished — it is off the order screen and the online menu.' : 'Available again.');
        header('Location: ' . $self . '?tab=menu');
        exit;
    }
    if ($act === 'request') {
        $st = $pdo->prepare('SELECT * FROM rest_ingredients WHERE id = ? AND tenant_id = ?');
        $st->execute([(int) ($_POST['ingredient'] ?? 0), $tid]);
        $ing = $st->fetch(PDO::FETCH_ASSOC);
        $qty = (float) ($_POST['quantity'] ?? 0);
        if (!$ing) {
            rest_flash('Choose what you need.', 'error');
        } else {
            $pdo->prepare('INSERT INTO rest_kitchen_requests (tenant_id, ingredient_id, stock_id, name, quantity, unit, note, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$tid, (int) $ing['id'], $ing['stock_id'], $ing['name'], $qty > 0 ? $qty : null, $ing['unit'],
                           mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null, TenantContext::userId()]);
            rest_flash('Request sent to the store — it shows on Transfers.');
        }
        header('Location: ' . $self . '?tab=stock');
        exit;
    }
}

// ---------------------------------------------------------------- tickets
$lines = $pdo->prepare(
    "SELECT o.id AS order_id, o.order_no, o.order_type, o.table_label, o.customer_name, o.source, o.status AS order_status, o.created_at,
            i.id, i.name, i.quantity, i.removed, i.note, COALESCE(i.kitchen_status, 'new') AS ks, i.kitchen_started_at, i.kitchen_ready_at
       FROM rest_orders o JOIN rest_order_items i ON i.order_id = o.id AND i.item_type = 'dish'
      WHERE o.tenant_id = ? AND o.status IN ('open', 'paid') AND o.created_at >= NOW() - INTERVAL 12 HOUR
        AND COALESCE(i.kitchen_status, 'new') <> 'served'
      ORDER BY o.created_at, i.id"
);
$lines->execute([$tid]);
$tickets = [];
foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $t = &$tickets[(int) $l['order_id']];
    $t ??= ['order' => $l, 'lines' => [], 'rank' => 3];
    $t['lines'][] = $l;
    $t['rank'] = min($t['rank'], ['new' => 0, 'preparing' => 1, 'ready' => 2][$l['ks']] ?? 3); // the ticket sits with its least-advanced dish
    unset($t);
}
$cols = ['new' => [], 'preparing' => [], 'ready' => []];
foreach ($tickets as $t) $cols[['new', 'preparing', 'ready'][$t['rank']] ?? 'ready'][] = $t;
$waiting = count($cols['new']);

$ago = function (string $t): string {
    $m = max(0, (int) floor((time() - strtotime($t)) / 60));
    return $m < 60 ? $m . ' min' : floor($m / 60) . ' h ' . ($m % 60) . ' min';
};

ob_start();
?>
<style>
.k-tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap;}
.k-tabs a{padding:8px 14px;border-radius:999px;border:1px solid #e4e7ec;text-decoration:none;color:#3f3f46;font-weight:600;font-size:.88rem;background:#fff;}
.k-tabs a.on{background:#1f9a5f;border-color:#1f9a5f;color:#fff;}
.k-board{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;align-items:start;}
.k-col h2{font-size:.9rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#52525b;margin:0 0 10px;display:flex;justify-content:space-between;}
.k-col h2 span{background:#f4f4f5;border-radius:999px;padding:0 9px;font-size:.78rem;}
.k-ticket{background:#fff;border:1px solid #e4e7ec;border-left:5px solid #a1a1aa;border-radius:10px;padding:12px 14px;margin-bottom:12px;}
.k-ticket.new{border-left-color:#f59e0b;} .k-ticket.preparing{border-left-color:#2563eb;} .k-ticket.ready{border-left-color:#16a34a;}
.k-ticket.late{box-shadow:0 0 0 2px #fecaca;}
.k-head{display:flex;justify-content:space-between;gap:8px;font-weight:700;}
.k-meta{font-size:.78rem;color:#71717a;margin-bottom:8px;}
.k-line{font-size:1rem;padding:4px 0;border-top:1px dashed #f0f0f2;}
.k-line b{font-size:1.05rem;}
.k-line .x{display:block;font-size:.8rem;color:#b91c1c;}
.k-line .n{display:block;font-size:.8rem;color:#a16207;}
.k-btn{width:100%;margin-top:10px;font-weight:700;padding:10px;border-radius:8px;border:0;color:#fff;}
.k-btn.start{background:#2563eb;} .k-btn.ready{background:#16a34a;} .k-btn.served{background:#3f3f46;}
@media (max-width:991px){.k-board{grid-template-columns:1fr;}}
</style>

<div class="k-tabs">
  <a class="<?php echo $tab === 'tickets' ? 'on' : ''; ?>" href="<?php echo $self; ?>"><i class="fas fa-fire-burner me-1"></i>Tickets<?php echo $waiting ? ' (' . $waiting . ' new)' : ''; ?></a>
  <a class="<?php echo $tab === 'menu' ? 'on' : ''; ?>" href="<?php echo $self; ?>?tab=menu"><i class="fas fa-bowl-food me-1"></i>Menu availability</a>
  <a class="<?php echo $tab === 'stock' ? 'on' : ''; ?>" href="<?php echo $self; ?>?tab=stock"><i class="fas fa-carrot me-1"></i>Kitchen stock</a>
</div>

<?php if ($tab === 'tickets'): ?>
  <div class="k-board">
    <?php foreach (['new' => 'New', 'preparing' => 'Preparing', 'ready' => 'Ready'] as $k => $label): ?>
      <div class="k-col">
        <h2><?php echo $label; ?> <span><?php echo count($cols[$k]); ?></span></h2>
        <?php if (!$cols[$k]): ?><div class="text-muted small">Nothing here.</div><?php endif; ?>
        <?php foreach ($cols[$k] as $t): $o = $t['order']; $late = $k !== 'ready' && strtotime($o['created_at']) < time() - 25 * 60; ?>
          <div class="k-ticket <?php echo $k . ($late ? ' late' : ''); ?>">
            <div class="k-head"><span>#<?php echo $h($o['order_no']); ?> · <?php echo $o['order_type'] === 'dine_in' ? $h($o['table_label'] ?: 'Table') : ($o['source'] === 'online' ? 'Online' : 'Takeaway'); ?></span><span class="<?php echo $late ? 'text-danger' : ''; ?>"><?php echo $ago($o['created_at']); ?></span></div>
            <div class="k-meta"><?php echo $h($o['customer_name'] ?: ''); ?><?php echo $o['order_status'] === 'paid' ? ' · paid' : ''; ?></div>
            <?php foreach ($t['lines'] as $l): $rem = $l['removed'] ? (json_decode($l['removed'], true) ?: []) : []; ?>
              <div class="k-line"><b><?php echo rest_qty((float) $l['quantity']); ?> ×</b> <?php echo $h($l['name']); ?>
                <?php if ($l['ks'] !== $k): ?><small class="text-muted">(<?php echo $h($l['ks']); ?>)</small><?php endif; ?>
                <?php if ($rem): ?><span class="x">without <?php echo $h(implode(', ', array_map(fn($r) => is_array($r) ? ($r['name'] ?? '') : $r, $rem))); ?></span><?php endif; ?>
                <?php if ($l['note']): ?><span class="n"><i class="fas fa-comment-dots me-1"></i><?php echo $h($l['note']); ?></span><?php endif; ?>
              </div>
            <?php endforeach; ?>
            <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="act" value="ticket"><input type="hidden" name="order_id" value="<?php echo (int) $o['order_id']; ?>">
              <?php if ($k === 'new'): ?><button class="k-btn start" name="to" value="preparing"><i class="fas fa-fire me-1"></i>Start cooking</button>
              <?php elseif ($k === 'preparing'): ?><button class="k-btn ready" name="to" value="ready"><i class="fas fa-bell me-1"></i>Ready</button>
              <?php else: ?><button class="k-btn served" name="to" value="served"><i class="fas fa-check me-1"></i>Served / picked up</button><?php endif; ?>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <script>
  (function () { // refresh every 15 s; beep when new tickets arrive
    var n = <?php echo (int) $waiting; ?>, key = 'dzKitchenNew', before = parseInt(sessionStorage.getItem(key) || '-1', 10);
    sessionStorage.setItem(key, String(n));
    if (before >= 0 && n > before) {
      try { var c = new (window.AudioContext || window.webkitAudioContext)(), o = c.createOscillator(), g = c.createGain();
        o.frequency.value = 880; g.gain.value = .15; o.connect(g); g.connect(c.destination); o.start(); o.stop(c.currentTime + .35); } catch (e) {}
    }
    setTimeout(function () { if (!document.querySelector('input:focus,textarea:focus')) location.reload(); }, 15000);
  })();
  </script>

<?php elseif ($tab === 'menu'):
    $dishes = $pdo->prepare('SELECT d.id, d.name, d.price, d.sold_out, c.name AS cat FROM rest_dishes d LEFT JOIN rest_categories c ON c.id = d.category_id
                               WHERE d.tenant_id = ? AND d.is_active = 1 ORDER BY d.sold_out DESC, c.sort_order, c.name, d.name');
    $dishes->execute([$tid]); ?>
  <div class="card"><div class="card-body p-3">
    <p class="small text-muted mb-2">When a dish runs out, mark it <b>Finished</b> — waiters can't order it and it leaves the online menu. Make it <b>Available</b> again once you can cook it.</p>
    <?php foreach ($dishes->fetchAll(PDO::FETCH_ASSOC) as $d): ?>
      <div class="d-flex justify-content-between align-items-center border-top py-2">
        <div><b><?php echo $h($d['name']); ?></b> <span class="small text-muted"><?php echo $h($d['cat'] ?? ''); ?></span>
          <?php if ((int) $d['sold_out']): ?><span class="badge bg-danger ms-1">Finished</span><?php endif; ?></div>
        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="act" value="soldout"><input type="hidden" name="dish" value="<?php echo (int) $d['id']; ?>"><input type="hidden" name="on" value="<?php echo (int) $d['sold_out'] ? '' : '1'; ?>">
          <button class="btn btn-sm <?php echo (int) $d['sold_out'] ? 'btn-success' : 'btn-outline-danger'; ?>"><?php echo (int) $d['sold_out'] ? 'Available again' : 'Mark finished'; ?></button></form>
      </div>
    <?php endforeach; ?>
  </div></div>

<?php else:
    $ings = $pdo->prepare('SELECT i.*, (SELECT MAX(di.quantity) FROM rest_dish_ingredients di WHERE di.ingredient_id = i.id) AS per_dish
                             FROM rest_ingredients i WHERE i.tenant_id = ? ORDER BY i.quantity <= 0 DESC, i.name');
    $ings->execute([$tid]);
    $ings = $ings->fetchAll(PDO::FETCH_ASSOC);
    $reqs = $pdo->prepare('SELECT r.*, u.username FROM rest_kitchen_requests r LEFT JOIN users u ON u.id = r.requested_by WHERE r.tenant_id = ? ORDER BY r.id DESC LIMIT 20');
    $reqs->execute([$tid]); ?>
  <div class="row g-3">
    <div class="col-lg-7"><div class="card"><div class="card-body p-3">
      <h2 class="h6 fw-bold">What's left in the kitchen</h2>
      <?php if (!$ings): ?><p class="small text-muted mb-0">No kitchen stock yet — move stock from the store to the kitchen on Transfers.</p><?php endif; ?>
      <?php foreach ($ings as $i): $q = (float) $i['quantity']; $per = (float) ($i['per_dish'] ?? 0); $low = $q <= 0 ? 'out' : ($per > 0 && $q < $per * 5 ? 'low' : ''); ?>
        <div class="d-flex justify-content-between border-top py-2">
          <span><b><?php echo $h($i['name']); ?></b><?php if ($low === 'out'): ?> <span class="badge bg-danger">Out</span><?php elseif ($low === 'low'): ?> <span class="badge bg-warning text-dark">Running low</span><?php endif; ?>
            <?php if ($per > 0 && $q > 0): ?><br><small class="text-muted">enough for about <?php echo (int) floor($q / $per); ?> more</small><?php endif; ?></span>
          <span class="fw-semibold <?php echo $low === 'out' ? 'text-danger' : ''; ?>"><?php echo rest_qty($q) . ' ' . $h($i['unit']); ?></span>
        </div>
      <?php endforeach; ?>
    </div></div></div>
    <div class="col-lg-5">
      <div class="card mb-3"><div class="card-body p-3">
        <h2 class="h6 fw-bold">Request from store</h2>
        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="act" value="request">
          <select class="form-select mb-2" name="ingredient" required><option value="">What do you need?</option>
            <?php foreach ($ings as $i): ?><option value="<?php echo (int) $i['id']; ?>"><?php echo $h($i['name']); ?> (<?php echo $h($i['unit']); ?>)</option><?php endforeach; ?></select>
          <input class="form-control mb-2" type="number" step="0.001" min="0" name="quantity" placeholder="How much (optional)">
          <input class="form-control mb-2" name="note" maxlength="255" placeholder="Note (optional) — e.g. needed before lunch">
          <button class="btn btn-success w-100">Send request</button>
        </form>
      </div></div>
      <div class="card"><div class="card-body p-3">
        <h2 class="h6 fw-bold">Requests</h2>
        <?php $rs = $reqs->fetchAll(PDO::FETCH_ASSOC); if (!$rs): ?><p class="small text-muted mb-0">No requests yet.</p><?php endif; ?>
        <?php foreach ($rs as $r): ?>
          <div class="small border-top py-2"><b><?php echo $h($r['name']); ?></b><?php echo $r['quantity'] !== null ? ' · ' . rest_qty((float) $r['quantity']) . ' ' . $h($r['unit']) : ''; ?>
            <span class="badge <?php echo ['pending' => 'bg-warning text-dark', 'done' => 'bg-success', 'declined' => 'bg-secondary'][$r['status']]; ?> ms-1"><?php echo $r['status'] === 'done' ? 'Sent to kitchen' : ucfirst($r['status']); ?></span>
            <div class="text-muted"><?php echo date('j M H:i', strtotime($r['created_at'])); ?><?php echo $r['username'] ? ' · ' . $h($r['username']) : ''; ?><?php echo $r['note'] ? ' · ' . $h($r['note']) : ''; ?></div></div>
        <?php endforeach; ?>
      </div></div>
    </div>
  </div>
<?php endif; ?>
<?php
rest_render('Kitchen', ob_get_clean());
