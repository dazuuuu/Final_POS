<?php
// public/super/restaurant/stays.php — reception: every room at a glance (free,
// booked, occupied — and who's overdue), guests in house, upcoming bookings
// and recent check-outs.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::ROOMS_DESK);
require_once __DIR__ . '/_hospitality.php';

$rooms = room_list($pdo, $tid);
$feePerHour = (float) room_settings($pdo, $tid)['late_fee_per_hour'];
$q = trim((string) ($_GET['q'] ?? ''));

$active = $pdo->prepare("SELECT * FROM room_stays WHERE tenant_id = ? AND status IN ('booked','checked_in') ORDER BY check_in_at");
$active->execute([$tid]);
$active = $active->fetchAll(PDO::FETCH_ASSOC);
$byRoom = [];
foreach ($active as $s) {
    if ($s['status'] === 'checked_in' || strtotime($s['check_in_at']) <= time()) { $byRoom[(int) $s['room_id']] ??= $s; }
}
$inHouse = array_values(array_filter($active, fn($s) => $s['status'] === 'checked_in'));
$upcoming = array_values(array_filter($active, fn($s) => $s['status'] === 'booked'));

$sql = "SELECT * FROM room_stays WHERE tenant_id = ? AND status IN ('checked_out','cancelled')";
$args = [$tid];
if ($q !== '') { $sql .= ' AND (guest_name LIKE ? OR guest_phone LIKE ? OR stay_no LIKE ? OR guest_id_number LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
$past = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 30');
$past->execute($args);
$past = $past->fetchAll(PDO::FETCH_ASSOC);
$stayUrl = fn($s) => public_url('super/restaurant/stay.php?id=' . (int) $s['id']);

ob_start();
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div class="small text-muted"><?php echo count($inHouse); ?> in house · <?php echo count($upcoming); ?> upcoming · <?php echo count($rooms) - count($byRoom); ?> of <?php echo count($rooms); ?> rooms free now</div>
  <a href="<?php echo public_url('super/restaurant/stay-new.php'); ?>" class="btn btn-primary"><i class="fas fa-user-plus me-1"></i>New guest</a>
</div>

<?php if (!$rooms): ?>
  <div class="alert alert-info">No rooms yet. <?php if (TenantContext::can(Capabilities::ROOMS_MANAGE)): ?><a href="<?php echo public_url('super/restaurant/rooms.php'); ?>">Add your rooms</a>.<?php endif; ?></div>
<?php endif; ?>
<div class="row g-3 mb-4">
  <?php foreach ($rooms as $rm): $s = $byRoom[(int) $rm['id']] ?? null; [$lh] = $s ? stay_late($s, max($feePerHour, 0.01)) : [0]; ?>
  <div class="col-6 col-md-4 col-xl-3">
    <a href="<?php echo $s ? $stayUrl($s) : public_url('super/restaurant/stay-new.php?room=' . (int) $rm['id']); ?>" class="d-block text-decoration-none text-reset h-100">
      <div class="card border-0 shadow-sm h-100" style="border-radius:12px;border-left:5px solid <?php echo $rm['status'] === 'maintenance' ? '#94a3b8' : ($s ? ($lh ? '#dc2626' : '#f59e0b') : '#16a34a'); ?> !important;">
        <div class="card-body p-3">
          <div class="d-flex justify-content-between"><strong><?php echo hosp_h($rm['name']); ?></strong><span class="small text-muted"><?php echo hosp_h($rm['floor']); ?></span></div>
          <?php if ($rm['status'] === 'maintenance'): ?>
            <div class="small text-muted mt-1">Maintenance</div>
          <?php elseif ($s): ?>
            <div class="small mt-1"><i class="fas fa-user me-1 text-muted"></i><?php echo hosp_h($s['guest_name']); ?></div>
            <div class="small <?php echo $lh ? 'text-danger fw-semibold' : 'text-muted'; ?>"><?php echo $lh ? 'Overdue ' . $lh . ' hr' : 'Out ' . date('D j M, g:i a', strtotime($s['due_out_at'])); ?></div>
          <?php else: ?>
            <div class="small text-success mt-1 fw-semibold">Free — tap to book</div>
            <div class="small text-muted"><?php echo $rm['price_night'] !== null ? hosp_money($rm['price_night']) . '/night' : ''; ?></div>
          <?php endif; ?>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <?php foreach (['In house' => $inHouse, 'Upcoming bookings' => $upcoming] as $title => $list): ?>
  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;"><div class="card-body p-3">
      <h2 class="h6 fw-bold mb-2"><?php echo $title; ?> <span class="badge bg-light text-dark border"><?php echo count($list); ?></span></h2>
      <?php if (!$list): ?><div class="small text-muted">None.</div><?php endif; ?>
      <?php foreach ($list as $s): ?>
        <a href="<?php echo $stayUrl($s); ?>" class="d-flex justify-content-between border-bottom py-2 small text-decoration-none text-reset">
          <span><strong><?php echo hosp_h($s['guest_name']); ?></strong> · <?php echo hosp_h($s['room_name']); ?><div class="text-muted"><?php echo date('D j M', strtotime($s['check_in_at'])); ?> → <?php echo date('D j M, g:i a', strtotime($s['due_out_at'])); ?></div></span>
          <span class="text-end"><?php echo hosp_money($s['total']); ?><div class="text-muted"><?php echo (float) $s['paid_amount'] > 0 ? 'paid ' . hosp_money($s['paid_amount']) : 'unpaid'; ?></div></span>
        </a>
      <?php endforeach; ?>
    </div></div>
  </div>
  <?php endforeach; ?>
  <div class="col-12">
    <div class="card border-0 shadow-sm" style="border-radius:12px;"><div class="card-body p-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 class="h6 fw-bold mb-0">Past stays</h2>
        <form class="d-flex gap-2"><input name="q" value="<?php echo hosp_h($q); ?>" class="form-control form-control-sm" placeholder="Name, phone, ID or RM-number"><button class="btn btn-sm btn-outline-secondary">Search</button></form>
      </div>
      <?php if (!$past): ?><div class="small text-muted">None yet.</div><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0 small">
        <thead><tr class="text-muted text-uppercase"><th>Stay</th><th>Guest</th><th>Room</th><th>Out</th><th class="text-end">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($past as $s): ?>
          <tr><td><a href="<?php echo $stayUrl($s); ?>"><?php echo hosp_h($s['stay_no']); ?></a></td><td><?php echo hosp_h($s['guest_name']); ?></td><td><?php echo hosp_h($s['room_name']); ?></td>
            <td><?php echo $s['checked_out_at'] ? date('j M, g:i a', strtotime($s['checked_out_at'])) : '—'; ?></td><td class="text-end"><?php echo hosp_money($s['total']); ?></td><td><?php echo hosp_badge(ROOM_STATUS, $s['status']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php
rest_render('Reception', ob_get_clean());
