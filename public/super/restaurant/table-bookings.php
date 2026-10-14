<?php
// public/super/restaurant/table-bookings.php — families / groups book one or
// more tables for a date and time and set their menu in advance: dishes from
// the menu (12 ugali, 3 sukuma…), family platters, or anything typed in, with
// free items and drinks marked. Optional deposit. Opens the booking's invoice.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::TABLE_BOOKINGS);
require_once __DIR__ . '/_hospitality.php';

$self = public_url('super/restaurant/table-bookings.php');
$dishes = $pdo->prepare('SELECT id, name, price, category FROM rest_dishes WHERE tenant_id = ? AND is_active = 1 ORDER BY name');
$dishes->execute([$tid]);
$dishes = array_column($dishes->fetchAll(PDO::FETCH_ASSOC), null, 'id');
$platters = array_column(platter_list($pdo, $tid), null, 'id');
$error = '';
$in = $_POST + ['party_size' => 4, 'booking_at' => date('Y-m-d\T19:00'), 'lines' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $name = trim((string) ($_POST['customer_name'] ?? ''));
    $when = strtotime((string) ($_POST['booking_at'] ?? ''));
    $email = trim((string) ($_POST['customer_email'] ?? ''));
    $lines = [];
    foreach ((array) ($_POST['lines'] ?? []) as $l) {
        $qty = round((float) ($l['quantity'] ?? 0), 3);
        $ref = (string) ($l['ref'] ?? '');
        if ($qty <= 0) { continue; }
        $free = !empty($l['is_free']) ? 1 : 0;
        $drink = !empty($l['is_drink']) ? 1 : 0;
        if (preg_match('/^d(\d+)$/', $ref, $m) && isset($dishes[(int) $m[1]])) {
            $d = $dishes[(int) $m[1]];
            $lines[] = ['dish', (int) $d['id'], $d['name'], $qty, (float) $d['price'], $free, $drink, null];
        } elseif (preg_match('/^p(\d+)$/', $ref, $m) && isset($platters[(int) $m[1]])) {
            $p = $platters[(int) $m[1]];
            $lines[] = ['platter', (int) $p['id'], $p['name'], $qty, (float) $p['price'], $free, $drink, platter_contents($p['items'])];
        } elseif ($ref === 'custom' && trim((string) ($l['name'] ?? '')) !== '') {
            $lines[] = ['custom', null, mb_substr(trim($l['name']), 0, 160), $qty, max(0, round((float) ($l['price'] ?? 0), 2)), $free, $drink, null];
        }
    }
    $deposit = max(0, round((float) ($_POST['deposit'] ?? 0), 2));
    $subtotal = round(array_sum(array_map(fn($l) => $l[5] ? 0 : $l[3] * $l[4], $lines)), 2);
    $discount = min($subtotal, max(0, round((float) ($_POST['discount'] ?? 0), 2)));
    $total = round($subtotal - $discount, 2);

    if ($name === '') {
        $error = 'Enter the name the booking is under.';
    } elseif (!$when) {
        $error = 'Pick the date and time of the booking.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address doesn\'t look right.';
    } elseif ($deposit > $total && $total > 0) {
        $error = 'The deposit is more than the total.';
    } else {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO rest_table_bookings (tenant_id, customer_name, customer_phone, customer_email, party_size, tables_label, booking_at, notes,
                                                        subtotal, discount, total, paid_amount, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tid, mb_substr($name, 0, 150), trim((string) ($_POST['customer_phone'] ?? '')) ?: null, $email ?: null,
                       max(1, (int) ($_POST['party_size'] ?? 1)), trim((string) ($_POST['tables_label'] ?? '')) ?: null, date('Y-m-d H:i:s', $when),
                       trim((string) ($_POST['notes'] ?? '')) ?: null, $subtotal, $discount, $total, $deposit, TenantContext::userId()]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE rest_table_bookings SET booking_no = ? WHERE id = ?')->execute(['TB-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT), $id]);
        $ins = $pdo->prepare('INSERT INTO rest_table_booking_items (booking_id, kind, ref_id, name, quantity, unit_price, line_total, is_free, is_drink, contents) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($lines as [$kind, $ref, $iname, $qty, $price, $free, $drink, $contents]) {
            $ins->execute([$id, $kind, $ref, $iname, $qty, $price, $free ? 0 : round($qty * $price, 2), $free, $drink, $contents]);
        }
        if ($deposit > 0) {
            $method = array_key_exists($_POST['deposit_method'] ?? '', REST_PAY_METHODS) ? $_POST['deposit_method'] : 'cash';
            $pdo->prepare('INSERT INTO rest_table_booking_payments (tenant_id, booking_id, amount, method, reference, user_id) VALUES (?,?,?,?,?,?)')
                ->execute([$tid, $id, $deposit, $method, trim((string) ($_POST['deposit_ref'] ?? '')) ?: null, TenantContext::userId()]);
        }
        $pdo->commit();
        rest_flash('Table booked for ' . $name . ' on ' . date('D j M, g:i a', $when) . '.');
        header('Location: ' . public_url('super/restaurant/table-booking.php?id=' . $id));
        exit;
    }
}

$list = $pdo->prepare("SELECT * FROM rest_table_bookings WHERE tenant_id = ? AND (status IN ('booked','arrived') OR booking_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) ORDER BY status IN ('completed','cancelled'), booking_at LIMIT 100");
$list->execute([$tid]);
$list = $list->fetchAll(PDO::FETCH_ASSOC);
$h = fn($k) => hosp_h($in[$k] ?? '');

ob_start();
?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo hosp_h($error); ?></div><?php endif; ?>
<div class="row g-4">
  <div class="col-12 col-xl-7">
    <form method="post" class="card border-0 shadow-sm" style="border-radius:12px;"><div class="card-body p-4">
      <?php echo csrf_field(); ?>
      <h2 class="h6 fw-bold mb-3">New table booking</h2>
      <div class="row g-2 mb-3">
        <div class="col-md-6"><label class="form-label small mb-1">Booking name (family / group)</label><input name="customer_name" class="form-control" value="<?php echo $h('customer_name'); ?>" placeholder="e.g. Otieno family" required></div>
        <div class="col-md-3 col-6"><label class="form-label small mb-1">Phone</label><input name="customer_phone" class="form-control" inputmode="tel" value="<?php echo $h('customer_phone'); ?>"></div>
        <div class="col-md-3 col-6"><label class="form-label small mb-1">People</label><input name="party_size" type="number" min="1" class="form-control" value="<?php echo (int) $in['party_size']; ?>"></div>
        <div class="col-md-5"><label class="form-label small mb-1">Date &amp; time</label><input name="booking_at" type="datetime-local" class="form-control" value="<?php echo $h('booking_at'); ?>" required></div>
        <div class="col-md-3"><label class="form-label small mb-1">Table(s)</label><input name="tables_label" class="form-control" value="<?php echo $h('tables_label'); ?>" placeholder="e.g. T4, T5"></div>
        <div class="col-md-4"><label class="form-label small mb-1">Email</label><input name="customer_email" type="email" class="form-control" value="<?php echo $h('customer_email'); ?>"></div>
      </div>

      <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="h6 fw-bold mb-0">Their menu</h3>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addLine"><i class="fas fa-plus me-1"></i>Add</button>
      </div>
      <div id="lines"></div>
      <?php if (!$dishes && !$platters): ?><div class="small text-muted mb-2">Your menu is empty — type items in, or add dishes under Menu and platters under Family platters.</div><?php endif; ?>

      <div class="row g-2 mt-2">
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Discount</label><input name="discount" id="discount" type="number" step="0.01" min="0" class="form-control" value="<?php echo $h('discount'); ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Deposit now</label><input name="deposit" type="number" step="0.01" min="0" class="form-control" value="<?php echo $h('deposit'); ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Paid by</label><select name="deposit_method" class="form-select"><?php foreach (REST_PAY_METHODS as $k => $l): ?><option value="<?php echo $k; ?>"><?php echo $l; ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">M-Pesa code</label><input name="deposit_ref" class="form-control" value="<?php echo $h('deposit_ref'); ?>"></div>
      </div>
      <input name="notes" class="form-control mt-2" placeholder="Notes, e.g. birthday cake at 8pm, no pork" value="<?php echo $h('notes'); ?>">
      <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="fw-bold fs-5">Total <span id="total"><?php echo hosp_h($currency); ?> 0</span></div>
        <button class="btn btn-primary"><i class="fas fa-calendar-check me-1"></i>Book &amp; create invoice</button>
      </div>
    </div></form>
  </div>
  <div class="col-12 col-xl-5">
    <div class="card border-0 shadow-sm" style="border-radius:12px;"><div class="card-body p-3">
      <h2 class="h6 fw-bold mb-2">Bookings</h2>
      <?php if (!$list): ?><div class="small text-muted">No bookings yet.</div><?php endif; ?>
      <?php foreach ($list as $b): ?>
        <a href="<?php echo public_url('super/restaurant/table-booking.php?id=' . (int) $b['id']); ?>" class="d-flex justify-content-between border-bottom py-2 small text-decoration-none text-reset">
          <span><strong><?php echo hosp_h($b['customer_name']); ?></strong> · <?php echo (int) $b['party_size']; ?> people<?php echo $b['tables_label'] ? ' · ' . hosp_h($b['tables_label']) : ''; ?>
            <div class="text-muted"><?php echo date('D j M, g:i a', strtotime($b['booking_at'])); ?></div></span>
          <span class="text-end"><?php echo hosp_badge(TB_STATUS, $b['status']); ?><div><?php echo hosp_money($b['total']); ?></div></span>
        </a>
      <?php endforeach; ?>
    </div></div>
  </div>
</div>
<template id="lineTpl">
  <div class="border rounded-3 p-2 mb-2 line">
    <div class="d-flex gap-2 mb-1">
      <select class="form-select form-select-sm" data-f="ref">
        <option value="custom" data-price="">Type an item…</option>
        <?php if ($platters): ?><optgroup label="Family platters"><?php foreach ($platters as $p): ?>
          <option value="p<?php echo (int) $p['id']; ?>" data-price="<?php echo (float) $p['price']; ?>" data-info="<?php echo hosp_h(platter_contents($p['items'])); ?>"><?php echo hosp_h($p['name']); ?> — <?php echo number_format((float) $p['price']); ?></option>
        <?php endforeach; ?></optgroup><?php endif; ?>
        <?php if ($dishes): ?><optgroup label="Menu"><?php foreach ($dishes as $d): ?>
          <option value="d<?php echo (int) $d['id']; ?>" data-price="<?php echo (float) $d['price']; ?>"><?php echo hosp_h($d['name']); ?> — <?php echo number_format((float) $d['price']); ?></option>
        <?php endforeach; ?></optgroup><?php endif; ?>
      </select>
      <input type="number" step="0.001" min="0" class="form-control form-control-sm" style="max-width:80px" data-f="quantity" value="1" title="Quantity">
      <button type="button" class="btn btn-sm btn-link text-danger rm"><i class="fas fa-trash"></i></button>
    </div>
    <div class="d-flex gap-2 mb-1 custom">
      <input class="form-control form-control-sm" data-f="name" placeholder="Item, e.g. 5kg Mbuzi">
      <input type="number" step="0.01" min="0" class="form-control form-control-sm" style="max-width:110px" data-f="price" placeholder="Price each">
    </div>
    <div class="small text-muted info mb-1"></div>
    <div class="d-flex align-items-center gap-3">
      <label class="small"><input type="checkbox" class="form-check-input me-1" data-f="is_free" value="1">Free</label>
      <label class="small"><input type="checkbox" class="form-check-input me-1" data-f="is_drink" value="1">Drink</label>
      <span class="ms-auto small fw-semibold lt"></span>
    </div>
  </div>
</template>
<script>
(function () {
  var box = document.getElementById('lines'), tpl = document.getElementById('lineTpl'), n = 0, CUR = <?php echo json_encode($currency); ?>;
  var fmt = function (v) { return CUR + ' ' + v.toLocaleString(undefined, {maximumFractionDigits: 2}); };
  function total() {
    var t = 0;
    box.querySelectorAll('.line').forEach(function (el) {
      var s = el.querySelector('[data-f=ref]'), q = parseFloat(el.querySelector('[data-f=quantity]').value) || 0;
      var p = s.value === 'custom' ? (parseFloat(el.querySelector('[data-f=price]').value) || 0) : (parseFloat(s.options[s.selectedIndex].dataset.price) || 0);
      var free = el.querySelector('[data-f=is_free]').checked, lt = free ? 0 : p * q;
      el.querySelector('.lt').textContent = free ? 'FREE' : fmt(lt);
      t += lt;
    });
    t = Math.max(0, t - (parseFloat(document.getElementById('discount').value) || 0));
    document.getElementById('total').textContent = fmt(t);
  }
  function add(d) {
    d = d || {};
    var el = tpl.content.firstElementChild.cloneNode(true), i = n++;
    el.querySelectorAll('[data-f]').forEach(function (f) {
      f.name = 'lines[' + i + '][' + f.dataset.f + ']';
      var v = d[f.dataset.f];
      if (f.type === 'checkbox') f.checked = v == 1; else if (v !== undefined && v !== null && v !== '') f.value = v;
    });
    var s = el.querySelector('[data-f=ref]');
    var sync = function () {
      el.querySelector('.custom').style.display = s.value === 'custom' ? '' : 'none';
      var o = s.options[s.selectedIndex]; el.querySelector('.info').textContent = o.dataset.info ? 'Includes: ' + o.dataset.info : '';
      total();
    };
    s.addEventListener('change', sync); el.addEventListener('input', total); el.addEventListener('change', total);
    el.querySelector('.rm').onclick = function () { el.remove(); total(); };
    box.appendChild(el); sync();
  }
  document.getElementById('addLine').onclick = function () { add(); };
  document.getElementById('discount').addEventListener('input', total);
  var start = <?php echo json_encode(array_values((array) $in['lines'])); ?>;
  (start.length ? start : [{}]).forEach(add);
})();
</script>
<?php
rest_render('Table bookings', ob_get_clean());
