<?php
// public/support/index.php — Support console overview.
require_once __DIR__ . '/_boot.php';

$pdo = Database::pdo();

if (support_posted() && ($_POST['action'] ?? '') === 'signup_toggle') {
    $on = !empty($_POST['enabled']);
    if (Signup::setEnabled($on)) {
        support_flash($on ? 'Signup page is ON — "Create your account" now shows on the owner login.' : 'Signup page is OFF.');
    } else {
        support_flash('Could not write app/config/signup.php — check the folder permissions.', 'danger');
    }
    header('Location: ' . public_url('support/'));
    exit;
}
$signupOn = Signup::enabled();

$accounts = support_accounts($pdo);
$catalog = Features::catalog();
$perPackage = array_fill_keys(array_keys($catalog), 0);
$active = $suspended = $licensed = 0;
foreach ($accounts as $a) {
    $a['status'] === 'active' ? $active++ : $suspended++;
    foreach (Features::forAccount((int) $a['id']) as $k => $on) {
        if ($on) { $perPackage[$k]++; $licensed += $catalog[$k][1]; }
    }
}

$openTickets = $newTickets = 0;
try {
    [$openTickets, $newTickets] = array_map('intval', $pdo->query(
        "SELECT SUM(status IN ('open','in_progress','waiting')), SUM(unread_by_support = 1) FROM support_tickets"
    )->fetch(PDO::FETCH_NUM));
} catch (\Throwable $e) { /* tickets table not installed yet */ }

$page_title = 'Overview';
$nav = 'overview';
ob_start();
?>
<h1 class="h4 mb-4">Overview</h1>
<div class="s-card mb-4">
  <form method="post" class="d-flex flex-wrap align-items-center gap-3">
    <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="signup_toggle">
    <input type="hidden" name="enabled" value="<?php echo $signupOn ? '' : '1'; ?>">
    <div class="form-check form-switch m-0" style="font-size:1.25rem">
      <input class="form-check-input" type="checkbox" role="switch" id="signupSwitch" <?php echo $signupOn ? 'checked' : ''; ?> onchange="this.form.submit()">
    </div>
    <div class="flex-grow-1">
      <label for="signupSwitch" class="fw-semibold d-block mb-0">Signup page <span class="badge <?php echo $signupOn ? 'bg-success' : 'bg-secondary'; ?> align-middle"><?php echo $signupOn ? 'ON' : 'OFF'; ?></span></label>
      <small class="text-muted"><?php echo $signupOn
          ? 'Owners see "Create your account" on the login page. They confirm the business name you created, import their data, and go straight in — no activation.'
          : 'Turn on to show "Create your account" on the owner login page.'; ?></small>
    </div>
    <?php if ($signupOn): ?>
    <div class="input-group input-group-sm" style="max-width:360px">
      <input class="form-control" id="signupLink" value="<?php echo htmlspecialchars(Signup::signupUrl()); ?>" readonly onclick="this.select()">
      <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('signupLink').value);this.textContent='Copied'">Copy</button>
    </div>
    <?php endif; ?>
  </form>
</div>
<div class="row g-3 mb-4">
  <?php foreach ([
      ['Accounts', count($accounts)], ['Active', $active], ['Suspended / cancelled', $suspended],
      ['Licensed value', 'KES ' . number_format($licensed)],
      ['Open tickets', '<a class="text-reset text-decoration-none" href="' . public_url('support/tickets/') . '">' . $openTickets . ($newTickets ? ' <small class="badge bg-danger align-middle">' . $newTickets . ' new</small>' : '') . '</a>'],
  ] as [$l, $n]): ?>
  <div class="col-6 col-lg"><div class="s-card s-stat"><div class="l"><?php echo $l; ?></div><div class="n"><?php echo $n; ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="s-card h-100">
      <h2 class="h6 mb-3">Packages in use</h2>
      <?php foreach ($catalog as $k => [$label, $price, , $icon]): ?>
        <div class="d-flex justify-content-between border-bottom py-2">
          <span><i class="fas <?php echo $icon; ?> fa-fw me-2 text-primary"></i><?php echo $label; ?> <small class="text-muted">KES <?php echo number_format($price / 1000); ?>K</small></span>
          <strong><?php echo $perPackage[$k]; ?> accounts</strong>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="s-card h-100">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Latest accounts</h2>
        <a class="btn btn-sm btn-primary" href="<?php echo public_url('support/accounts/?new=1'); ?>"><i class="fas fa-plus me-1"></i>New account</a>
      </div>
      <?php if (!$accounts): ?><p class="text-muted mb-0">No accounts yet.</p><?php endif; ?>
      <?php foreach (array_slice($accounts, 0, 6) as $a): ?>
        <a class="d-flex justify-content-between align-items-center border-bottom py-2 text-decoration-none text-reset" href="<?php echo public_url('support/accounts/view.php?id=' . (int) $a['id']); ?>">
          <span><strong><?php echo htmlspecialchars($a['name']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($a['owner_email'] ?? '—'); ?></small></span>
          <span class="text-end"><?php echo support_package_badges((int) $a['id']); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
