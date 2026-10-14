<?php
// public/super/restaurant/stay.php?id=N — one guest's stay. Shows the invoice
// (a receipt once paid), and lets reception check the guest in, add room
// service / extras, and check them out: late hours past 24 h × nights are
// charged at the shop's fee, the balance is paid and the receipt prints.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::ROOMS_DESK);
require_once __DIR__ . '/_hospitality.php';

$id = (int) ($_GET['id'] ?? 0);
$stay = stay_find($pdo, $tid, $id);
if (!$stay) {
    rest_flash('Stay not found.', 'error');
    header('Location: ' . public_url('super/restaurant/stays.php'));
    exit;
}
$self = public_url('super/restaurant/stay.php?id=' . $id);
$room = room_find($pdo, $tid, (int) $stay['room_id']);
$feePerHour = (float) room_settings($pdo, $tid)['late_fee_per_hour'];
$open = in_array($stay['status'], ['booked', 'checked_in'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null) && $open) {
    $action = $_POST['action'] ?? '';
    $uid = TenantContext::userId();
    if ($action === 'checkin' && $stay['status'] === 'booked') {
        $pdo->prepare("UPDATE room_stays SET status = 'checked_in', checked_in_at = NOW() WHERE id = ?")->execute([$id]);
        rest_flash($stay['guest_name'] . ' checked in.');
    } elseif ($action === 'charge') {
        $desc = trim((string) ($_POST['description'] ?? ''));
        $amt = round((float) ($_POST['amount'] ?? 0), 2);
        if ($desc === '' || $amt <= 0) {
            rest_flash('Say what the charge is for and how much.', 'error');
        } else {
            $pdo->prepare('INSERT INTO room_stay_charges (tenant_id, stay_id, description, amount, user_id) VALUES (?,?,?,?,?)')
                ->execute([$tid, $id, mb_substr($desc, 0, 150), $amt, $uid]);
            stay_recalc($pdo, $id);
            rest_flash('Added ' . $desc . ' — ' . hosp_money($amt) . '.');
        }
    } elseif ($action === 'remove_charge') {
        $pdo->prepare('DELETE FROM room_stay_charges WHERE id = ? AND stay_id = ?')->execute([(int) ($_POST['charge_id'] ?? 0), $id]);
        stay_recalc($pdo, $id);
        rest_flash('Charge removed.');
    } elseif ($action === 'cancel') {
        $pdo->prepare("UPDATE room_stays SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        rest_flash('Booking cancelled.' . ((float) $stay['paid_amount'] > 0 ? ' Remember to refund the deposit of ' . hosp_money($stay['paid_amount']) . ' if due.' : ''));
    } elseif ($action === 'checkout') {
        [$lateHours, $lateFee] = stay_late($stay, $feePerHour);
        if (!empty($_POST['waive_late'])) { [$lateHours, $lateFee] = [0, 0.0]; }
        $discount = max(0, round((float) ($_POST['discount'] ?? 0), 2));
        $total = max(0, round((float) $stay['room_amount'] + (float) $stay['extras_amount'] + $lateFee - $discount, 2));
        $balance = round($total - (float) $stay['paid_amount'], 2);
        $method = array_key_exists($_POST['method'] ?? '', REST_PAY_METHODS) ? $_POST['method'] : 'cash';
        $given = round((float) ($_POST['amount_given'] ?? 0), 2);
        if ($balance > 0 && $given + 0.0001 < $balance) {
            rest_flash('The amount paid is less than the balance of ' . hosp_money($balance) . '.', 'error');
        } else {
            $pdo->beginTransaction();
            if ($balance > 0) {
                $pdo->prepare('INSERT INTO room_stay_payments (tenant_id, stay_id, amount, method, reference, user_id) VALUES (?,?,?,?,?,?)')
                    ->execute([$tid, $id, $balance, $method, trim((string) ($_POST['reference'] ?? '')) ?: null, $uid]);
            }
            $pdo->prepare("UPDATE room_stays SET status = 'checked_out', checked_out_at = NOW(), checked_out_by = ?, late_hours = ?, late_fee = ?,
                                  discount = ?, total = ?, paid_amount = paid_amount + ? WHERE id = ?")
                ->execute([$uid, $lateHours, $lateFee, $discount, $total, max(0, $balance), $id]);
            $pdo->commit();
            $change = $balance > 0 && $method === 'cash' ? round($given - $balance, 2) : 0;
            rest_flash('Checked out. ' . ($change > 0 ? 'Give change: ' . hosp_money($change) . '. ' : '') . 'Receipt printed — ready for the next guest.');
            header('Location: ' . $self . '&new=1');
            exit;
        }
    }
    header('Location: ' . $self);
    exit;
}

$paid = $stay['status'] === 'checked_out';
[$lateHours, $lateFee] = $paid ? [(int) $stay['late_hours'], (float) $stay['late_fee']] : stay_late($stay, $feePerHour);
$total = $paid ? (float) $stay['total'] : round((float) $stay['room_amount'] + (float) $stay['extras_amount'] + $lateFee - (float) $stay['discount'], 2);
$balance = round($total - (float) $stay['paid_amount'], 2);
$plan = ROOM_PLANS[$stay['rate_plan']][0] ?? $stay['rate_plan'];
$row = fn($l, $r, $b = false) => '<tr' . ($b ? ' style="font-weight:700;"' : '') . '><td style="padding:2px 0;">' . $l . '</td><td style="text-align:right;padding:2px 0;white-space:nowrap;">' . $r . '</td></tr>';

ob_start();
hosp_print_head(!empty($_GET['new']) && $paid);
?>
<div class="row g-4">
  <div class="col-12 col-lg-6">
    <div class="sheet">
      <?php echo hosp_sheet_header($paid ? 'RECEIPT' : 'INVOICE', $stay['stay_no'], date('j M Y, g:i a', strtotime($paid ? $stay['checked_out_at'] : $stay['created_at']))); ?>
      <div style="font-size:12px;margin-bottom:8px;">
        <div><strong>Guest:</strong> <?php echo hosp_h($stay['guest_name']); ?><?php echo $stay['guest_phone'] ? ' · ' . hosp_h($stay['guest_phone']) : ''; ?></div>
        <div>ID: <?php echo hosp_h($stay['guest_id_number']); ?></div>
        <?php foreach ($stay['companions'] as $c): ?>
          <div>+ <?php echo hosp_h($c['name']); ?><?php echo $c['relationship'] ? ' (' . hosp_h($c['relationship']) . ')' : ''; ?><?php echo $c['adult'] ? ($c['id_number'] ? ' · ID ' . hosp_h($c['id_number']) : '') : ' · child'; ?></div>
        <?php endforeach; ?>
        <div><strong>Room:</strong> <?php echo hosp_h($stay['room_name']); ?><?php echo $room && $room['floor'] ? ' · ' . hosp_h($room['floor']) . ' floor' : ''; ?></div>
        <div>In: <?php echo date('D j M Y, g:i a', strtotime($stay['checked_in_at'] ?: $stay['check_in_at'])); ?></div>
        <div>Due out: <?php echo date('D j M Y, g:i a', strtotime($stay['due_out_at'])); ?></div>
        <?php if ($paid): ?><div>Checked out: <?php echo date('D j M Y, g:i a', strtotime($stay['checked_out_at'])); ?></div><?php endif; ?>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:13px;border-top:1px solid #cbd5e1;">
        <?php echo $row(hosp_h($plan) . ' · ' . (int) $stay['nights'] . ' night' . ((int) $stay['nights'] === 1 ? '' : 's'), number_format((float) $stay['room_amount'], 2)); ?>
        <?php foreach ($stay['charges'] as $c) { echo $row(hosp_h($c['description']), number_format((float) $c['amount'], 2)); } ?>
        <?php if ($lateFee > 0) { echo $row('Late check-out · ' . $lateHours . ' hr' . ($lateHours === 1 ? '' : 's') . ($paid ? '' : ' (so far)'), number_format($lateFee, 2)); } ?>
        <?php if ((float) $stay['discount'] > 0) { echo $row('Discount', '-' . number_format((float) $stay['discount'], 2)); } ?>
      </table>
      <table style="width:100%;border-collapse:collapse;font-size:14px;border-top:2px dashed #cbd5e1;margin-top:6px;">
        <?php echo $row('TOTAL', hosp_money($total), true); ?>
        <?php foreach ($stay['payments'] as $p) { echo $row('Paid · ' . (REST_PAY_METHODS[$p['method']] ?? $p['method']) . ($p['reference'] ? ' ' . hosp_h($p['reference']) : ''), hosp_money($p['amount'])); } ?>
        <?php echo $row($balance > 0 ? 'BALANCE DUE' : 'Balance', hosp_money(max(0, $balance)), true); ?>
      </table>
      <?php if ($room && $room['rules'] && !$paid): ?><div style="font-size:11px;margin-top:8px;"><strong>House rules:</strong> <?php echo nl2br(hosp_h($room['rules'])); ?></div><?php endif; ?>
      <p style="text-align:center;font-size:12px;margin-top:12px;"><?php echo hosp_h(($__tenant['receipt_footer'] ?? '') ?: 'Thank you for staying with us.'); ?></p>
    </div>
    <div class="d-flex gap-2 justify-content-center mt-3" style="max-width:400px;margin:0 auto;">
      <button type="button" class="btn btn-primary flex-fill" onclick="TasoPrinter.printClick()"><i class="fas fa-print me-1"></i>Print <?php echo $paid ? 'receipt' : 'invoice'; ?></button>
      <a href="<?php echo public_url('super/restaurant/stays.php'); ?>" class="btn btn-outline-secondary flex-fill">Reception</a>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="d-flex align-items-center gap-2 mb-3"><h2 class="h5 mb-0"><?php echo hosp_h($stay['guest_name']); ?></h2><?php echo hosp_badge(ROOM_STATUS, $stay['status']); ?></div>
    <?php if ($paid): ?>
      <a href="<?php echo public_url('super/restaurant/stay-new.php'); ?>" class="btn btn-success w-100 py-2 mb-3"><i class="fas fa-user-plus me-1"></i>Next guest</a>
    <?php elseif ($open): ?>
      <?php if ($stay['status'] === 'booked'): ?>
        <form method="post" class="mb-3"><?php echo csrf_field(); ?><input type="hidden" name="action" value="checkin">
          <button class="btn btn-warning w-100"><i class="fas fa-key me-1"></i>Guest arrived — check in</button></form>
      <?php endif; ?>

      <div class="card border-0 shadow-sm mb-3" style="border-radius:12px;"><div class="card-body p-3">
        <h3 class="h6 fw-bold mb-2">Room service &amp; extras</h3>
        <?php if ($room && $room['room_service'] === 'paid' && (float) $room['room_service_price'] > 0): ?>
          <form method="post" class="mb-2"><?php echo csrf_field(); ?><input type="hidden" name="action" value="charge"><input type="hidden" name="description" value="Room service"><input type="hidden" name="amount" value="<?php echo (float) $room['room_service_price']; ?>">
            <button class="btn btn-sm btn-outline-primary w-100"><i class="fas fa-bell-concierge me-1"></i>Add room service (<?php echo hosp_money($room['room_service_price']); ?>)</button></form>
        <?php elseif ($room && $room['room_service'] === 'free'): ?>
          <div class="small text-muted mb-2"><i class="fas fa-bell-concierge me-1"></i>Room service is free for this room.</div>
        <?php endif; ?>
        <form method="post" class="d-flex gap-2"><?php echo csrf_field(); ?><input type="hidden" name="action" value="charge">
          <input name="description" class="form-control form-control-sm" placeholder="e.g. Dinner to room, laundry" required>
          <input name="amount" type="number" step="0.01" min="0" class="form-control form-control-sm" style="max-width:110px" placeholder="Amount" required>
          <button class="btn btn-sm btn-primary">Add</button></form>
        <?php foreach ($stay['charges'] as $c): ?>
          <form method="post" class="d-flex justify-content-between align-items-center small border-bottom py-1 mt-1"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="remove_charge"><input type="hidden" name="charge_id" value="<?php echo (int) $c['id']; ?>">
            <span><?php echo hosp_h($c['description']); ?> · <?php echo hosp_money($c['amount']); ?></span><button class="btn btn-sm btn-link text-muted p-0" onclick="return confirm('Remove this charge?')"><i class="fas fa-xmark"></i></button></form>
        <?php endforeach; ?>
      </div></div>

      <div class="card border-0 shadow-sm mb-3" style="border-radius:12px;"><div class="card-body p-3">
        <h3 class="h6 fw-bold mb-2">Check out &amp; pay</h3>
        <?php if ($lateFee > 0): ?>
          <div class="alert alert-warning py-2 small mb-2"><i class="fas fa-clock me-1"></i>Late by <?php echo $lateHours; ?> hour(s) past <?php echo date('g:i a, D j M', strtotime($stay['due_out_at'])); ?> — late fee <?php echo hosp_money($lateFee); ?> (<?php echo hosp_money($feePerHour); ?>/hr).</div>
        <?php elseif ($feePerHour > 0): ?>
          <div class="small text-muted mb-2">Due out <?php echo date('g:i a, D j M', strtotime($stay['due_out_at'])); ?>. After that, <?php echo hosp_money($feePerHour); ?> per hour is added.</div>
        <?php endif; ?>
        <form method="post" id="coForm"><?php echo csrf_field(); ?><input type="hidden" name="action" value="checkout">
          <?php if ($lateFee > 0): ?><label class="form-check small mb-2"><input type="checkbox" class="form-check-input" name="waive_late" value="1" id="waive"> Waive the late fee</label><?php endif; ?>
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label small mb-1">Discount</label><input type="number" step="0.01" min="0" name="discount" id="disc" class="form-control" value="0"></div>
            <div class="col-6"><label class="form-label small mb-1">Paid by</label><select name="method" class="form-select"><?php foreach (REST_PAY_METHODS as $k => $l): ?><option value="<?php echo $k; ?>"><?php echo $l; ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label small mb-1">Amount given</label><input type="number" step="0.01" min="0" name="amount_given" id="given" class="form-control"></div>
            <div class="col-6"><label class="form-label small mb-1">M-Pesa code</label><input name="reference" class="form-control"></div>
          </div>
          <div class="d-flex justify-content-between fw-bold mb-2"><span>To pay now</span><span id="due"></span></div>
          <button class="btn btn-success w-100 py-2" onclick="return confirm('Check out <?php echo hosp_h($stay['guest_name']); ?> and print the receipt?')"><i class="fas fa-door-open me-1"></i>Check out &amp; print receipt</button>
        </form>
      </div></div>

      <form method="post" onsubmit="return confirm('Cancel this booking?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel">
        <button class="btn btn-sm btn-outline-danger">Cancel booking</button></form>
      <script>
      (function () {
        var base = <?php echo json_encode(round((float) $stay['room_amount'] + (float) $stay['extras_amount'] - (float) $stay['paid_amount'], 2)); ?>,
            late = <?php echo json_encode($lateFee); ?>, CUR = <?php echo json_encode($currency); ?>;
        var disc = document.getElementById('disc'), waive = document.getElementById('waive'), given = document.getElementById('given'), due = document.getElementById('due');
        function upd() {
          var d = Math.max(0, base + (waive && waive.checked ? 0 : late) - (parseFloat(disc.value) || 0));
          due.textContent = CUR + ' ' + d.toLocaleString(undefined, {minimumFractionDigits: 2});
          if (!given.dataset.touched) given.value = d.toFixed(2);
        }
        given.addEventListener('input', function () { given.dataset.touched = 1; });
        [disc, waive].forEach(function (el) { if (el) el.addEventListener('input', upd); if (el) el.addEventListener('change', upd); });
        upd();
      })();
      </script>
    <?php endif; ?>

    <div class="card border-0 shadow-sm" style="border-radius:12px;"><div class="card-body p-3 small">
      <div><strong>Email:</strong> <?php echo hosp_h($stay['guest_email'] ?: '—'); ?></div>
      <div><strong>Address:</strong> <?php echo hosp_h($stay['guest_address'] ?: '—'); ?></div>
      <?php if ($stay['notes']): ?><div><strong>Notes:</strong> <?php echo hosp_h($stay['notes']); ?></div><?php endif; ?>
      <div class="text-muted mt-1">Booked by <?php echo hosp_h($stay['created_by_name'] ?? '—'); ?> on <?php echo date('j M Y, g:i a', strtotime($stay['created_at'])); ?><?php echo $stay['checked_out_by_name'] ? ' · checked out by ' . hosp_h($stay['checked_out_by_name']) : ''; ?></div>
    </div></div>
  </div>
</div>
<?php
rest_render('Stay ' . $stay['stay_no'], ob_get_clean());
