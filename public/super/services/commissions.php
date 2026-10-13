<?php
// public/super/services/commissions.php — owner: commission rules and what
// each staff member has earned. A rule can be for one service or all, one or
// more weekdays or every day, one staff member or everyone; the most specific
// rule that matches wins, otherwise the service's own default %.
require_once __DIR__ . '/_common.php';
svc_require($isOwner);

$self = public_url('super/services/commissions.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'delete') {
        $SD->deleteRule((int) ($_POST['id'] ?? 0));
        $_SESSION['flash']['success'] = 'Rule removed. Visits paid from now on use the new rates.';
    } else {
        $res = $SD->saveRule($_POST);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Commission rule added.' : $res['error'];
    }
    header('Location: ' . $self);
    exit;
}

$services = $SD->services(true);
$people = [];
foreach (ShopContext::shops(true) ?: [['id' => TenantContext::tenantId(), 'name' => '']] as $sh) {
    foreach ($SD->staffForShop((int) $sh['id']) as $u) { $people[(int) $u['id']] = $u['username'] . ($sh['name'] && count(ShopContext::shops(true)) > 1 ? ' · ' . $sh['name'] : ''); }
}
$rules = $SD->rules();

$period = $_GET['period'] ?? 'month';
[$from, $to] = match ($period) {
    'week'      => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
    'lastmonth' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    'custom'    => [($_GET['from'] ?? '') ?: date('Y-m-01'), ($_GET['to'] ?? '') ?: date('Y-m-d')],
    default     => [date('Y-m-01'), date('Y-m-d')],
};
$report = $SD->commissionReport(array_column(ShopContext::shops() ?: [['id' => TenantContext::tenantId()]], 'id'), $from, $to);
$dayNames = Models\ServiceDeskModel::WEEKDAYS;

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Add a commission rate</h2>
      <p class="small text-muted mb-3">E.g. <em>All services · Sat + Sun · everyone · 50%</em>. The most specific rate wins; without one, the service's default % (set on <a href="<?php echo public_url('super/services/catalog.php'); ?>">Services &amp; prices</a>) applies.</p>
      <form method="post">
        <label class="form-label small mb-1">Service</label>
        <select name="service_id" class="form-select mb-2">
          <option value="0">All services</option>
          <?php foreach ($services as $s): ?><option value="<?php echo (int) $s['id']; ?>"><?php echo svc_h($s['name']); ?></option><?php endforeach; ?>
        </select>
        <label class="form-label small mb-1">Staff</label>
        <select name="staff_id" class="form-select mb-2">
          <option value="0">Everyone</option>
          <?php foreach ($people as $pid => $label): ?><option value="<?php echo $pid; ?>"><?php echo svc_h($label); ?></option><?php endforeach; ?>
        </select>
        <label class="form-label small mb-1">Days <span class="text-muted">(none ticked = every day)</span></label>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <?php foreach ($dayNames as $n => $d): ?>
            <label class="border rounded px-2 py-1 small"><input type="checkbox" class="form-check-input me-1" name="weekdays[]" value="<?php echo $n; ?>"><?php echo substr($d, 0, 3); ?></label>
          <?php endforeach; ?>
        </div>
        <label class="form-label small mb-1">Staff share</label>
        <div class="input-group mb-3"><input type="number" step="0.5" min="0" max="100" name="pct" class="form-control" required placeholder="e.g. 50"><span class="input-group-text">% of the service</span></div>
        <button class="btn btn-primary w-100">Add rate</button>
      </form>
    </div></div>

    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Current rates</h2>
      <?php if (!$rules): ?><div class="small text-muted">No special rates — services use their default %.</div><?php endif; ?>
      <?php foreach ($rules as $r): ?>
        <div class="d-flex justify-content-between align-items-center border-bottom py-2 small">
          <div><strong><?php echo rtrim(rtrim((string) $r['pct'], '0'), '.'); ?>%</strong> ·
            <?php echo svc_h($r['service_name'] ?? 'All services'); ?> ·
            <?php echo $r['weekday'] ? $dayNames[(int) $r['weekday']] : 'every day'; ?> ·
            <?php echo svc_h($r['staff_name'] ?? 'everyone'); ?></div>
          <form method="post" onsubmit="return confirm('Remove this rate?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>"><button class="btn btn-sm btn-link text-muted p-0"><i class="fas fa-trash"></i></button></form>
        </div>
      <?php endforeach; ?>
    </div></div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h2 class="h6 fw-bold mb-0">Staff earnings <span class="text-muted fw-normal small"><?php echo date('j M', strtotime($from)); ?> – <?php echo date('j M Y', strtotime($to)); ?></span></h2>
        <form method="get" class="d-flex gap-2 flex-wrap">
          <select name="period" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach (['week' => 'This week', 'month' => 'This month', 'lastmonth' => 'Last month', 'custom' => 'Custom…'] as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $period === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?>
          </select>
          <?php if ($period === 'custom'): ?>
            <input type="date" name="from" value="<?php echo svc_h($from); ?>" class="form-control form-control-sm"><input type="date" name="to" value="<?php echo svc_h($to); ?>" class="form-control form-control-sm"><button class="btn btn-sm btn-primary">Go</button>
          <?php endif; ?>
        </form>
      </div>
      <?php if (!$report): ?>
        <div class="text-muted small">No paid services with staff in this period.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="small text-muted text-uppercase"><th>Staff</th><th class="text-center">Services</th><th class="text-end">Revenue</th><th class="text-end">Commission</th></tr></thead>
          <tbody>
          <?php foreach ($report as $r): ?>
            <tr class="small"><td><strong><?php echo svc_h($r['username'] ?? '?'); ?></strong><?php if (count(ShopContext::shops()) > 1): ?><div class="text-muted"><?php echo svc_h($r['shop_name']); ?></div><?php endif; ?></td>
              <td class="text-center"><?php echo (int) $r['services']; ?></td><td class="text-end"><?php echo svc_money($r['revenue']); ?></td><td class="text-end fw-bold text-success"><?php echo svc_money($r['commission']); ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr class="small fw-bold"><td>Total</td><td class="text-center"><?php echo array_sum(array_column($report, 'services')); ?></td><td class="text-end"><?php echo svc_money(array_sum(array_column($report, 'revenue'))); ?></td><td class="text-end"><?php echo svc_money(array_sum(array_column($report, 'commission'))); ?></td></tr></tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php
svc_render('Commissions', ob_get_clean());
