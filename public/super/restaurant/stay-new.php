<?php
// public/super/restaurant/stay-new.php — book a guest into a room: the guest's
// full details, anyone staying with them (adults give an ID; each with their
// relationship), how many nights and the price plan. The room's price is
// filled in but reception can adjust the agreed amount. Creates the stay and
// opens its invoice.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::ROOMS_DESK);
require_once __DIR__ . '/_hospitality.php';

$rooms = array_values(array_filter(room_list($pdo, $tid), fn($r) => $r['status'] === 'available'));
$error = '';
$in = $_POST + ['room_id' => (int) ($_GET['room'] ?? 0), 'nights' => 1, 'rate_plan' => 'night', 'check_in_at' => date('Y-m-d\TH:i'), 'companions' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $room = room_find($pdo, $tid, (int) ($_POST['room_id'] ?? 0));
    $name = trim((string) ($_POST['guest_name'] ?? ''));
    $nights = max(1, min(366, (int) ($_POST['nights'] ?? 1)));
    $plan = array_key_exists($_POST['rate_plan'] ?? '', ROOM_PLANS) ? $_POST['rate_plan'] : 'night';
    $amount = round((float) ($_POST['room_amount'] ?? 0), 2);
    $checkIn = strtotime((string) ($_POST['check_in_at'] ?? '')) ?: time();
    $email = trim((string) ($_POST['guest_email'] ?? ''));
    $companions = [];
    foreach ((array) ($_POST['companions'] ?? []) as $c) {
        $cn = trim((string) ($c['name'] ?? ''));
        if ($cn === '') { continue; }
        $adult = !empty($c['adult']);
        $companions[] = ['name' => mb_substr($cn, 0, 150), 'phone' => mb_substr(trim((string) ($c['phone'] ?? '')), 0, 40),
                         'adult' => $adult, 'id_number' => $adult ? mb_substr(trim((string) ($c['id_number'] ?? '')), 0, 60) : '',
                         'relationship' => mb_substr(trim((string) ($c['relationship'] ?? '')), 0, 60)];
    }
    $deposit = max(0, round((float) ($_POST['deposit'] ?? 0), 2));
    $method = array_key_exists($_POST['deposit_method'] ?? '', REST_PAY_METHODS) ? $_POST['deposit_method'] : 'cash';

    if (!$room || !$room['is_active']) {
        $error = 'Choose a room.';
    } elseif ($room['status'] === 'maintenance') {
        $error = 'That room is closed for maintenance.';
    } elseif ($name === '') {
        $error = 'Enter the guest\'s full name.';
    } elseif (trim((string) ($_POST['guest_id_number'] ?? '')) === '') {
        $error = 'Enter the guest\'s ID / passport number.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address doesn\'t look right.';
    } elseif ($missing = array_filter($companions, fn($c) => $c['adult'] && $c['id_number'] === '')) {
        $error = 'Enter the ID number for ' . reset($missing)['name'] . ' (adults need an ID).';
    } elseif ($amount <= 0) {
        $error = 'Enter the agreed amount for the room.';
    } elseif ($deposit > $amount) {
        $error = 'The deposit is more than the room amount.';
    } else {
        $from = date('Y-m-d H:i:s', $checkIn);
        $to = date('Y-m-d H:i:s', $checkIn + $nights * 86400);
        if ($clash = room_is_free($pdo, (int) $room['id'], $from, $to)) {
            $due = strtotime($clash['due_out_at']);
            $error = $room['name'] . ($clash['status'] === 'checked_in' && $due < time()
                    ? ' still has ' . $clash['guest_name'] . ' in it (was due out ' . date('D j M, g:i a', $due) . ') — check them out first'
                    : ' is taken by ' . $clash['guest_name'] . ' until ' . date('D j M, g:i a', $due))
                . '. Pick other dates or another room.';
        } else {
            $status = $checkIn <= time() + 900 ? 'checked_in' : 'booked';
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO room_stays (tenant_id, room_id, room_name, status, guest_name, guest_phone, guest_email, guest_id_number, guest_address, companions,
                                         rate_plan, nights, room_amount, total, paid_amount, check_in_at, due_out_at, checked_in_at, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$tid, $room['id'], $room['name'], $status, mb_substr($name, 0, 150), trim((string) ($_POST['guest_phone'] ?? '')) ?: null, $email ?: null,
                        mb_substr(trim((string) $_POST['guest_id_number']), 0, 60), trim((string) ($_POST['guest_address'] ?? '')) ?: null,
                        $companions ? json_encode($companions) : null, $plan, $nights, $amount, $amount, $deposit, $from, $to,
                        $status === 'checked_in' ? date('Y-m-d H:i:s') : null, trim((string) ($_POST['notes'] ?? '')) ?: null, TenantContext::userId()]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE room_stays SET stay_no = ? WHERE id = ?')->execute(['RM-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT), $id]);
            if ($deposit > 0) {
                $pdo->prepare('INSERT INTO room_stay_payments (tenant_id, stay_id, amount, method, reference, user_id) VALUES (?,?,?,?,?,?)')
                    ->execute([$tid, $id, $deposit, $method, trim((string) ($_POST['deposit_ref'] ?? '')) ?: null, TenantContext::userId()]);
            }
            $pdo->commit();
            rest_flash($status === 'checked_in' ? 'Guest checked in to ' . $room['name'] . '.' : 'Room booked for ' . date('D j M', $checkIn) . '.');
            header('Location: ' . public_url('super/restaurant/stay.php?id=' . $id));
            exit;
        }
    }
    $in['companions'] = $companions;
}
$h = fn($k) => hosp_h($in[$k] ?? '');

ob_start();
?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo hosp_h($error); ?></div><?php endif; ?>
<?php if (!$rooms): ?>
  <div class="alert alert-info">No rooms are available to book. <?php if (TenantContext::can(Capabilities::ROOMS_MANAGE)): ?><a href="<?php echo public_url('super/restaurant/rooms.php'); ?>">Add your rooms first</a>.<?php endif; ?></div>
<?php else: ?>
<form method="post" id="stayForm">
<?php echo csrf_field(); ?>
<div class="row g-4">
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm mb-3" style="border-radius:12px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Guest</h2>
      <div class="row g-2">
        <div class="col-md-7"><label class="form-label small mb-1">Full name</label><input name="guest_name" class="form-control" value="<?php echo $h('guest_name'); ?>" required></div>
        <div class="col-md-5"><label class="form-label small mb-1">ID / passport no.</label><input name="guest_id_number" class="form-control" value="<?php echo $h('guest_id_number'); ?>" required></div>
        <div class="col-md-5"><label class="form-label small mb-1">Phone</label><input name="guest_phone" class="form-control" inputmode="tel" value="<?php echo $h('guest_phone'); ?>"></div>
        <div class="col-md-7"><label class="form-label small mb-1">Email</label><input name="guest_email" type="email" class="form-control" value="<?php echo $h('guest_email'); ?>"></div>
        <div class="col-12"><label class="form-label small mb-1">Home address</label><input name="guest_address" class="form-control" value="<?php echo $h('guest_address'); ?>"></div>
      </div>
    </div></div>

    <div class="card border-0 shadow-sm mb-3" style="border-radius:12px;"><div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 fw-bold mb-0">Staying with them <span class="text-muted fw-normal small">(partner, family…)</span></h2>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addComp"><i class="fas fa-plus me-1"></i>Add person</button>
      </div>
      <div id="comps"></div>
      <div class="small text-muted" id="noComps">Nobody else — tap <em>Add person</em> if they have a partner or family with them.</div>
    </div></div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:12px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Room &amp; stay</h2>
      <label class="form-label small mb-1">Room</label>
      <select name="room_id" id="room" class="form-select mb-2" required>
        <?php foreach ($rooms as $rm): ?>
          <option value="<?php echo (int) $rm['id']; ?>" <?php echo (int) $in['room_id'] === (int) $rm['id'] ? 'selected' : ''; ?>
            data-prices='<?php echo hosp_h(json_encode(['night' => $rm['price_night'], '3days' => $rm['price_3days'], 'week' => $rm['price_week'], 'rental' => $rm['price_rental']])); ?>'>
            <?php echo hosp_h($rm['name'] . ($rm['floor'] ? ' · ' . $rm['floor'] . ' floor' : '')); ?></option>
        <?php endforeach; ?>
      </select>
      <div class="row g-2 mb-2">
        <div class="col-7"><label class="form-label small mb-1">Check-in</label><input type="datetime-local" name="check_in_at" class="form-control" value="<?php echo $h('check_in_at'); ?>"></div>
        <div class="col-5"><label class="form-label small mb-1">Nights</label><input type="number" name="nights" id="nights" min="1" max="366" class="form-control" value="<?php echo (int) $in['nights']; ?>"></div>
      </div>
      <label class="form-label small mb-1">Price plan</label>
      <select name="rate_plan" id="plan" class="form-select mb-2">
        <?php foreach (ROOM_PLANS as $k => [$l]): ?><option value="<?php echo $k; ?>" <?php echo $in['rate_plan'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?>
      </select>
      <div class="small text-muted mb-1" id="suggest"></div>
      <label class="form-label small mb-1">Agreed amount for the room</label>
      <div class="input-group mb-1"><span class="input-group-text"><?php echo hosp_h($currency); ?></span><input type="number" step="0.01" min="0" name="room_amount" id="amount" class="form-control fw-bold" value="<?php echo $h('room_amount'); ?>" required></div>
      <div class="form-text mb-3">Filled from the room's price — change it if you agreed a different amount.</div>
      <label class="form-label small mb-1">Deposit paid now <span class="text-muted">(optional)</span></label>
      <div class="d-flex gap-2 mb-2">
        <input type="number" step="0.01" min="0" name="deposit" class="form-control" value="<?php echo $h('deposit'); ?>" placeholder="0">
        <select name="deposit_method" class="form-select" style="max-width:120px"><?php foreach (REST_PAY_METHODS as $k => $l): ?><option value="<?php echo $k; ?>"><?php echo $l; ?></option><?php endforeach; ?></select>
      </div>
      <input name="deposit_ref" class="form-control mb-2" placeholder="M-Pesa code (optional)" value="<?php echo $h('deposit_ref'); ?>">
      <input name="notes" class="form-control mb-3" placeholder="Notes (optional)" value="<?php echo $h('notes'); ?>">
      <button class="btn btn-primary w-100 py-2"><i class="fas fa-file-invoice me-1"></i>Book &amp; create invoice</button>
    </div></div>
  </div>
</div>
</form>

<template id="compTpl">
  <div class="border rounded-3 p-2 mb-2 comp">
    <div class="row g-2">
      <div class="col-md-5"><input class="form-control form-control-sm" data-f="name" placeholder="Full name"></div>
      <div class="col-md-4"><input class="form-control form-control-sm" data-f="relationship" placeholder="Relationship (wife, son…)"></div>
      <div class="col-md-3"><input class="form-control form-control-sm" data-f="phone" placeholder="Phone"></div>
      <div class="col-md-5 d-flex align-items-center gap-3">
        <label class="small"><input type="checkbox" class="form-check-input me-1" data-f="adult" value="1" checked>Adult</label>
        <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-auto rm"><i class="fas fa-trash"></i></button>
      </div>
      <div class="col-md-7 idw"><input class="form-control form-control-sm" data-f="id_number" placeholder="ID / passport no. (adults)"></div>
    </div>
  </div>
</template>
<script>
(function () {
  var comps = document.getElementById('comps'), tpl = document.getElementById('compTpl'), n = 0;
  function add(d) {
    d = d || {};
    var el = tpl.content.firstElementChild.cloneNode(true), i = n++;
    el.querySelectorAll('[data-f]').forEach(function (f) {
      f.name = 'companions[' + i + '][' + f.dataset.f + ']';
      if (f.type === 'checkbox') f.checked = d.adult === undefined ? true : !!d.adult; else f.value = d[f.dataset.f] || '';
    });
    var adult = el.querySelector('[data-f=adult]'), idw = el.querySelector('.idw');
    var sync = function () { idw.style.visibility = adult.checked ? 'visible' : 'hidden'; };
    adult.addEventListener('change', sync); sync();
    el.querySelector('.rm').onclick = function () { el.remove(); toggle(); };
    comps.appendChild(el); toggle();
  }
  function toggle() { document.getElementById('noComps').style.display = comps.children.length ? 'none' : ''; }
  document.getElementById('addComp').onclick = function () { add(); };
  (<?php echo json_encode(array_values($in['companions'])); ?>).forEach(add);

  // Suggested amount from the room's price for the chosen plan; reception can override.
  var room = document.getElementById('room'), plan = document.getElementById('plan'), nights = document.getElementById('nights'),
      amount = document.getElementById('amount'), sug = document.getElementById('suggest'), touched = amount.value !== '';
  var DAYS = <?php echo json_encode(array_map(fn($p) => $p[1], ROOM_PLANS)); ?>, CUR = <?php echo json_encode($currency); ?>;
  amount.addEventListener('input', function () { touched = true; });
  function suggest(force) {
    var prices = JSON.parse(room.options[room.selectedIndex].dataset.prices), p = parseFloat(prices[plan.value]);
    var n = Math.max(1, parseInt(nights.value, 10) || 1);
    if (!p) { sug.textContent = 'This room has no ' + plan.options[plan.selectedIndex].text.toLowerCase() + ' price — enter the agreed amount.'; return; }
    var units = n / DAYS[plan.value], total = Math.round(p * Math.max(1, units) * 100) / 100;
    sug.textContent = CUR + ' ' + p.toLocaleString() + ' × ' + (Math.round(Math.max(1, units) * 100) / 100) + ' = ' + CUR + ' ' + total.toLocaleString();
    if (force || !touched) { amount.value = total; touched = false; }
  }
  [room, plan, nights].forEach(function (el) { el.addEventListener('change', function () { suggest(true); }); });
  nights.addEventListener('input', function () { suggest(true); });
  suggest(false);
})();
</script>
<?php endif; ?>
<?php
rest_render('New guest', ob_get_clean());
