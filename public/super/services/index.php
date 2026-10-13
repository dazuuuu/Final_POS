<?php
// public/super/services/index.php — front desk: today's clients, bookings,
// visits in progress and paid ones. Staff without front-desk or cashier
// access go to "My clients" instead.
require_once __DIR__ . '/_common.php';
if (!$isDesk) {
    header('Location: ' . public_url('super/services/mine.php'));
    exit;
}

$view = in_array($_GET['view'] ?? '', ['today', 'booked', 'open', 'paid', 'all'], true) ? $_GET['view'] : 'today';
$q = trim($_GET['q'] ?? '');
$tickets = $SD->tickets($view, $q);
$counts = $SD->counts();
$self = public_url('super/services/');
$today = $canMoney ? $SD->income((int) TenantContext::tenantId(), date('Y-m-d'), date('Y-m-d')) : null;

ob_start();
?>
<div class="row g-3 mb-3">
  <?php foreach ([['booked', 'Bookings', 'fa-calendar', 'tk-blue'], ['open', 'In progress', 'fa-hourglass-half', 'tk-orange']] as [$k, $label, $icon, $cls]): ?>
  <div class="col-6 col-lg-3"><a class="text-decoration-none text-reset" href="<?php echo $self . '?view=' . $k; ?>">
    <div class="tk-stat"><div class="tk-stat-icon <?php echo $cls; ?>"><i class="fas <?php echo $icon; ?>"></i></div>
      <div class="tk-stat-body"><div class="tk-stat-num"><?php echo (int) $counts[$k]; ?></div><div class="tk-stat-label"><?php echo $label; ?></div></div></div>
  </a></div>
  <?php endforeach; ?>
  <?php if ($today): ?>
  <div class="col-12 col-lg-6"><div class="tk-stat"><div class="tk-stat-icon tk-greenbg"><i class="fas fa-coins"></i></div>
    <div class="tk-stat-body"><div class="tk-stat-num"><?php echo svc_money($today['total']); ?></div><div class="tk-stat-label">Services paid today (<?php echo $today['count']; ?>)</div></div></div></div>
  <?php endif; ?>
</div>

<div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-3 p-md-4">
  <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <div class="btn-group btn-group-sm flex-wrap">
      <?php foreach (['today' => 'Today', 'booked' => 'Bookings', 'open' => 'In progress', 'paid' => 'Paid', 'all' => 'All'] as $k => $label): ?>
        <a class="btn <?php echo $view === $k ? 'btn-dark' : 'btn-outline-secondary'; ?>" href="<?php echo $self . '?view=' . $k; ?>"><?php echo $label; ?></a>
      <?php endforeach; ?>
    </div>
    <form method="get" class="d-flex gap-2">
      <input type="hidden" name="view" value="<?php echo svc_h($view); ?>">
      <input name="q" value="<?php echo svc_h($q); ?>" class="form-control form-control-sm" placeholder="Client, phone or SVC-…">
      <button class="btn btn-sm btn-primary"><i class="fas fa-magnifying-glass"></i></button>
    </form>
    <?php if ($canBook): ?>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-success" href="<?php echo public_url('super/services/new.php'); ?>"><i class="fas fa-user-plus me-1"></i>Walk-in</a>
        <a class="btn btn-sm btn-outline-success" href="<?php echo public_url('super/services/new.php?kind=booking'); ?>"><i class="fas fa-calendar-plus me-1"></i>Booking</a>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$tickets): ?>
    <div class="text-muted small py-4 text-center">No clients here yet.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr class="small text-muted text-uppercase"><th>Client</th><th>Services</th><th>Staff</th><th>When</th><th>Status</th><?php if ($canMoney): ?><th class="text-end">Total</th><?php endif; ?><th></th></tr></thead>
      <tbody>
      <?php foreach ($tickets as $t): ?>
        <tr class="small">
          <td><strong><?php echo svc_h($t['customer_name']); ?></strong><div class="text-muted"><?php echo svc_h($t['number']); ?><?php echo $t['customer_phone'] ? ' · ' . svc_h($t['customer_phone']) : ''; ?></div></td>
          <td><?php echo svc_h($t['services'] ?: '—'); ?><?php if ((int) $t['item_count']): ?><div class="text-muted"><?php echo (int) $t['done_count']; ?>/<?php echo (int) $t['item_count']; ?> done</div><?php endif; ?><?php if ($t['products']): ?><div class="text-primary"><i class="fas fa-bag-shopping me-1"></i><?php echo svc_h($t['products']); ?></div><?php endif; ?></td>
          <td><?php echo svc_h($t['staff_names'] ?: '—'); ?></td>
          <td class="text-nowrap"><?php echo $t['scheduled_at'] ? '<i class="fas fa-calendar me-1 text-info"></i>' . date('D j M, g:i a', strtotime($t['scheduled_at'])) : date('j M, g:i a', strtotime($t['created_at'])); ?></td>
          <td><?php echo svc_status_badge($t['status']); ?></td>
          <?php if ($canMoney): ?><td class="text-end fw-semibold"><?php echo svc_money($t['total']); ?></td><?php endif; ?>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?php echo public_url('super/services/view.php?id=' . (int) $t['id']); ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div></div>
<?php
svc_render('Front desk', ob_get_clean());
