<?php
// public/support/index.php — Support console overview (dashboard).
//   KPI cards · platform sales activity (8 weeks) · money processed ·
//   accounts table with 14-day sparklines · most used package · signup switch.
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
arsort($perPackage);
$total = count($accounts);

$openTickets = $newTickets = 0;
try {
    [$openTickets, $newTickets] = array_map('intval', $pdo->query(
        "SELECT SUM(status IN ('open','in_progress','waiting')), SUM(unread_by_support = 1) FROM support_tickets"
    )->fetch(PDO::FETCH_NUM));
} catch (\Throwable $e) { /* tickets table not installed yet */ }

// New accounts per month, last 6 months (oldest first).
$months = [];
for ($i = 5; $i >= 0; $i--) { $months[date('Y-m', strtotime("first day of -$i month"))] = 0; }
foreach ($accounts as $a) {
    $m = substr((string) $a['created_at'], 0, 7);
    if (isset($months[$m])) $months[$m]++;
}
$thisMonth = end($months);

// Paid transactions across every package, last 56 days, per account.
$accountOf = [];
foreach ($pdo->query('SELECT id, COALESCE(parent_tenant_id, id) AS acct FROM tenants')->fetchAll(PDO::FETCH_KEY_PAIR) as $tid => $acct) {
    $accountOf[(int) $tid] = (int) $acct;
}
$since = date('Y-m-d', strtotime('-55 days'));
$sources = [
    "SELECT tenant_id, DATE(created_at) d, COUNT(*) n, SUM(total) v FROM orders WHERE status = 'paid' AND created_at >= ? GROUP BY tenant_id, d",
    "SELECT tenant_id, DATE(created_at) d, COUNT(*) n, SUM(total) v FROM sales WHERE COALESCE(status, '') NOT IN ('void','reversed','cancelled') AND created_at >= ? GROUP BY tenant_id, d",
    "SELECT tenant_id, DATE(created_at) d, COUNT(*) n, SUM(total) v FROM rest_orders WHERE status = 'paid' AND created_at >= ? GROUP BY tenant_id, d",
    "SELECT tenant_id, DATE(created_at) d, COUNT(*) n, SUM(total) v FROM svc_tickets WHERE status = 'paid' AND created_at >= ? GROUP BY tenant_id, d",
];
$byDay = [];      // Y-m-d => count
$acctDay = [];    // account => [Y-m-d => value]
$acctValue = [];  // account => value (56 days)
$value30 = 0.0;
$value30prev = 0.0;
foreach ($sources as $sql) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $acct = $accountOf[(int) $r['tenant_id']] ?? (int) $r['tenant_id'];
            $byDay[$r['d']] = ($byDay[$r['d']] ?? 0) + (int) $r['n'];
            $acctDay[$acct][$r['d']] = ($acctDay[$acct][$r['d']] ?? 0) + (float) $r['v'];
            $acctValue[$acct] = ($acctValue[$acct] ?? 0) + (float) $r['v'];
            if ($r['d'] >= date('Y-m-d', strtotime('-29 days'))) $value30 += (float) $r['v'];
            else $value30prev += (float) $r['v'];
        }
    } catch (\Throwable $e) { /* package tables not installed */ }
}
// Bars: one per day for 56 days, labelled by week.
$days = [];
for ($i = 55; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $days[$d] = $byDay[$d] ?? 0; }
$maxDay = max(1, max($days));
$peakDay = array_search(max($days), $days, true);
$avgDay = array_sum($days) / count($days);
$change = $value30prev > 0 ? ($value30 - $value30prev) / $value30prev * 100 : null;

/** Small bar group for the KPI cards. */
$miniBars = function (array $vals, string $on = 'var(--s-accent)', string $off = '#cfd8d2'): string {
    $max = max(1, max($vals));
    $html = '<span class="mini-bars">';
    $last = count($vals) - 1;
    foreach (array_values($vals) as $i => $v) {
        $html .= '<i style="height:' . (18 + round($v / $max * 82)) . '%;background:' . ($i === $last ? $on : $off) . '"></i>';
    }
    return $html . '</span>';
};
/** 14-day sparkline as inline SVG. */
$spark = function (array $vals, bool $up): string {
    $w = 110; $h = 30; $max = max(1, max($vals)); $n = count($vals) - 1;
    $pts = [];
    foreach (array_values($vals) as $i => $v) { $pts[] = round($i / max(1, $n) * $w, 1) . ',' . round($h - 3 - $v / $max * ($h - 6), 1); }
    $c = $up ? '#22a447' : '#e05a5a';
    $line = implode(' ', $pts);
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<polygon points="0,' . $h . ' ' . $line . ' ' . $w . ',' . $h . '" fill="' . $c . '" opacity=".14"/>'
        . '<polyline points="' . $line . '" fill="none" stroke="' . $c . '" stroke-width="1.8" stroke-linejoin="round"/></svg>';
};

$page_title = 'Dashboard';
$nav = 'overview';
ob_start();
?>
<style>
.d-card{background:#fff;border-radius:20px;padding:18px 20px;height:100%;border:1px solid var(--s-line);}
.d-card.dark{background:var(--s-dark);color:#fff;border-color:var(--s-dark);}
.d-card.soft{background:var(--s-green-soft);border-color:#cfe5d4;}
.d-label{font-size:.74rem;color:var(--s-mute);font-weight:500;}
.d-card.dark .d-label{color:#9fb3a6;}
.d-num{font-size:1.85rem;font-weight:500;letter-spacing:-.02em;line-height:1.15;}
.d-num small{font-size:1rem;color:var(--s-mute);}
.d-card.dark .d-num small{color:#9fb3a6;}
.d-delta{font-size:.72rem;color:var(--s-mute);}
.d-delta b{color:#22a447;font-weight:600;} .d-delta b.down{color:#e05a5a;}
.mini-bars{display:flex;align-items:flex-end;gap:5px;height:46px;}
.mini-bars i{display:block;width:9px;border-radius:2px;}
.d-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;}
.d-head h2{font-size:1.05rem;font-weight:600;margin:0;}
.d-pill{background:var(--s-dark);color:#fff;border-radius:10px;font-size:.72rem;padding:5px 10px;}
.chart{position:relative;display:flex;align-items:flex-end;gap:3px;height:200px;padding:0 0 0 28px;margin-top:34px;
  background:repeating-linear-gradient(to top,transparent 0,transparent 39px,#eef2ef 39px,#eef2ef 40px);}
.chart .bar{flex:1;border-radius:2px 2px 0 0;background:#d8e4db;min-height:3px;position:relative;}
.chart .bar.on{background:var(--s-accent);}
.chart .bar.peak{background:var(--s-dark);}
.chart .bar.peak::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);white-space:nowrap;
  background:var(--s-dark);color:#fff;font-size:.68rem;border-radius:6px;padding:3px 7px;}
.chart .avg{position:absolute;left:28px;right:0;border-top:1.5px dashed #6b7c72;}
.chart .y{position:absolute;left:0;font-size:.62rem;color:var(--s-mute);transform:translateY(50%);}
.chart-x{display:flex;padding-left:28px;margin-top:6px;}
.chart-x span{flex:1;text-align:center;font-size:.66rem;color:var(--s-mute);}
.big-num{font-size:2.6rem;font-weight:500;letter-spacing:-.03em;line-height:1;}
.big-num small{font-size:1rem;color:#4b6b55;letter-spacing:0;}
.d-row{display:flex;align-items:center;gap:12px;padding:10px 0;}
.d-row .ic{width:40px;height:40px;border-radius:50%;border:1.5px dashed #8fbf9b;display:grid;place-items:center;color:#15803d;flex:0 0 auto;}
.acct-table{width:100%;font-size:.82rem;}
.acct-table th{font-weight:500;color:var(--s-mute);font-size:.72rem;padding:6px 8px;border-bottom:1px solid var(--s-line);white-space:nowrap;}
.acct-table td{padding:9px 8px;border-bottom:1px solid #f0f3f1;vertical-align:middle;}
.acct-table tr:last-child td{border-bottom:0;}
.acct-av{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;font-weight:700;font-size:.78rem;background:var(--s-green-soft);color:#14532d;flex:0 0 auto;overflow:hidden;}
.acct-av img{width:100%;height:100%;object-fit:cover;}
.spark{width:110px;height:30px;display:block;}
.pkg-chip{display:inline-block;background:#fdf1df;color:#8a5a12;border-radius:8px;padding:3px 9px;font-size:.72rem;font-weight:600;margin:1px 2px 1px 0;white-space:nowrap;}
.pkg-chip.more{background:#eef2ef;color:var(--s-mute);}
.pop{background:var(--s-dark);color:#fff;border-radius:20px;padding:18px 20px;height:100%;position:relative;overflow:hidden;}
.pop::before{content:"";position:absolute;inset:0;background:radial-gradient(60% 70% at 85% 70%,rgba(34,164,71,.45),transparent 70%);}
.pop>*{position:relative;}
.pop-top{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);backdrop-filter:blur(6px);border-radius:14px;padding:12px 14px;margin:10px 0 14px;}
.pop-top .pct{font-size:1.5rem;font-weight:600;}
.pop-row{display:flex;align-items:center;gap:10px;margin-bottom:9px;font-size:.78rem;}
.pop-row .nm{width:42%;color:#cfe0d4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pop-row .tr{flex:1;height:8px;border-radius:5px;background:rgba(255,255,255,.08);overflow:hidden;}
.pop-row .tr i{display:block;height:100%;border-radius:5px;background:linear-gradient(90deg,#15803d,#4ade80);}
.pop-row .ct{width:28px;text-align:right;color:#9fb3a6;}
@media (min-width:992px){.d-topbtns{margin-top:-50px;position:relative;z-index:1021;}}
</style>

<div class="d-flex justify-content-end gap-2 mb-3 d-topbtns">
  <a class="btn btn-sm btn-light rounded-circle" style="width:36px;height:36px;display:grid;place-items:center;position:relative" href="<?php echo public_url('support/tickets/'); ?>" title="Support tickets">
    <i class="fas fa-bell"></i><?php if ($newTickets): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem"><?php echo $newTickets; ?></span><?php endif; ?></a>
  <a class="btn btn-sm btn-primary px-3" href="<?php echo public_url('support/accounts/?new=1'); ?>">New account</a>
</div>

<!-- KPI row -->
<div class="row g-3 mb-3">
  <div class="col-12 col-md-4">
    <div class="d-card dark d-flex justify-content-between align-items-end">
      <div>
        <div class="d-label">Shop accounts</div>
        <div class="d-num"><?php echo $total; ?> <small>accounts</small></div>
        <div class="d-delta"><b><i class="fas fa-arrow-trend-up"></i> +<?php echo $thisMonth; ?></b> this month</div>
      </div>
      <?php echo $miniBars($months, '#22a447', '#2f4a39'); ?>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="d-card d-flex justify-content-between align-items-end">
      <div>
        <div class="d-label">Active accounts</div>
        <div class="d-num"><?php echo $active; ?><small>/<?php echo $total; ?></small></div>
        <div class="d-delta"><b class="<?php echo $suspended ? 'down' : ''; ?>"><?php echo $suspended; ?></b> suspended / cancelled</div>
      </div>
      <?php echo $miniBars([$suspended, $active, $total], '#22a447', '#e05a5a'); ?>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="d-card d-flex justify-content-between align-items-end">
      <div>
        <div class="d-label">Licensed value</div>
        <div class="d-num">KES <?php echo number_format($licensed); ?></div>
        <div class="d-delta"><a class="text-reset" href="<?php echo public_url('support/tickets/'); ?>"><b><?php echo $openTickets; ?></b> open tickets<?php echo $newTickets ? ' · ' . $newTickets . ' new' : ''; ?></a></div>
      </div>
      <?php echo $miniBars(array_map(fn($k) => $perPackage[$k] * $catalog[$k][1], array_keys($catalog))); ?>
    </div>
  </div>
</div>

<!-- Activity chart + money processed -->
<div class="row g-3 mb-3">
  <div class="col-12 col-xl-8">
    <div class="d-card">
      <div class="d-head"><h2>Sales activity across all shops</h2><span class="d-pill">8 weeks</span></div>
      <div class="chart">
        <?php foreach ([1, .75, .5, .25] as $f): ?><span class="y" style="bottom:<?php echo $f * 100; ?>%"><?php echo round($maxDay * $f); ?></span><?php endforeach; ?>
        <span class="avg" style="bottom:<?php echo $avgDay / $maxDay * 100; ?>%" title="Daily average"></span>
        <?php $i = 0; foreach ($days as $d => $n): $i++; ?>
          <span class="bar <?php echo $d === $peakDay && $n ? 'peak' : ($i > 28 ? 'on' : ''); ?>" style="height:<?php echo max(1.5, $n / $maxDay * 100); ?>%"
                data-tip="<?php echo $n; ?> sales · <?php echo date('j M', strtotime($d)); ?>" title="<?php echo date('D j M', strtotime($d)) . ': ' . $n; ?> sales"></span>
        <?php endforeach; ?>
      </div>
      <div class="chart-x"><?php for ($w = 1; $w <= 8; $w++): ?><span>W<?php echo $w; ?></span><?php endfor; ?></div>
    </div>
  </div>
  <div class="col-12 col-xl-4">
    <div class="d-card soft">
      <div class="big-num"><?php echo $value30 >= 1e6 ? number_format($value30 / 1e6, 1) . 'm' : number_format($value30); ?> <small>KES</small></div>
      <div class="mt-2 mb-3" style="color:#15803d;font-weight:500;">
        <?php if ($change !== null): ?><i class="fas fa-arrow-trend-<?php echo $change >= 0 ? 'up' : 'down'; ?>"></i> <?php echo ($change >= 0 ? '+' : '') . round($change); ?>% vs previous 30 days
        <?php else: ?>processed in the last 30 days<?php endif; ?>
      </div>
      <div class="d-row"><span class="ic"><i class="fas fa-receipt"></i></span>
        <div><div style="font-size:1.05rem">Transactions</div><small class="text-muted"><?php echo number_format(array_sum(array_slice($days, -30))); ?> paid in 30 days</small></div></div>
      <div class="d-row"><span class="ic"><i class="fas fa-store"></i></span>
        <div><div style="font-size:1.05rem">Shops selling</div><small class="text-muted"><?php echo count(array_filter($acctValue)); ?> of <?php echo $total; ?> accounts in 8 weeks</small></div></div>
    </div>
  </div>
</div>

<!-- Accounts table + most used package -->
<div class="row g-3 mb-3">
  <div class="col-12 col-xl-8">
    <div class="d-card">
      <div class="d-head"><h2>Accounts</h2><a class="d-pill text-decoration-none" href="<?php echo public_url('support/accounts/'); ?>">All accounts <i class="fas fa-caret-down ms-1"></i></a></div>
      <?php if (!$accounts): ?><p class="text-muted mb-0">No accounts yet.</p><?php else: ?>
      <div class="table-responsive">
      <table class="acct-table">
        <thead><tr><th>Account</th><th>Branches</th><th>Status</th><th>Sales (14 days)</th><th>Packages</th><th class="text-end">8-week value</th><th></th></tr></thead>
        <tbody>
        <?php foreach (array_slice($accounts, 0, 6) as $a):
            $id = (int) $a['id'];
            $vals = [];
            for ($i = 13; $i >= 0; $i--) { $vals[] = $acctDay[$id][date('Y-m-d', strtotime("-$i days"))] ?? 0; }
            $up = array_sum(array_slice($vals, 7)) >= array_sum(array_slice($vals, 0, 7));
            $pk = array_keys(array_filter(Features::forAccount($id)));
            $logo = Branding::shopLogo($a); ?>
          <tr>
            <td><div class="d-flex align-items-center gap-2">
              <span class="acct-av"><?php if ($logo): ?><img src="<?php echo htmlspecialchars($logo); ?>" alt=""><?php else: echo htmlspecialchars(strtoupper(mb_substr($a['name'], 0, 1))); endif; ?></span>
              <span><span class="fw-semibold d-block"><?php echo htmlspecialchars($a['name']); ?></span><small class="text-muted"><?php echo htmlspecialchars($a['owner_email'] ?? '—'); ?></small></span>
            </div></td>
            <td><?php echo 1 + (int) $a['branch_count']; ?></td>
            <td><?php echo $a['status'] === 'active' ? '<span style="color:#15803d">Active</span>' : '<span style="color:#e05a5a">' . htmlspecialchars(ucfirst($a['status'])) . '</span>'; ?></td>
            <td><?php echo $spark($vals, $up); ?></td>
            <td><?php if (!$pk): ?><span class="pkg-chip more">None</span><?php else: ?>
              <span class="pkg-chip"><?php echo htmlspecialchars($catalog[$pk[0]][0]); ?></span><?php if (count($pk) > 1): ?><span class="pkg-chip more">+<?php echo count($pk) - 1; ?></span><?php endif; ?>
            <?php endif; ?></td>
            <td class="text-end">KES <?php echo number_format($acctValue[$id] ?? 0); ?></td>
            <td class="text-end"><a class="text-muted" href="<?php echo public_url('support/accounts/view.php?id=' . $id); ?>" aria-label="Open"><i class="fas fa-ellipsis-vertical"></i></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-12 col-xl-4">
    <div class="pop">
      <div class="d-flex justify-content-between align-items-center">
        <h2 class="h6 fw-semibold m-0">Most used package</h2>
        <a class="badge rounded-pill bg-light text-dark text-decoration-none" href="<?php echo public_url('support/packages/'); ?>">Packages <i class="fas fa-caret-right"></i></a>
      </div>
      <?php $topKey = array_key_first($perPackage); $topN = $perPackage[$topKey] ?? 0; ?>
      <div class="pop-top d-flex justify-content-between align-items-center">
        <div><div class="fw-semibold"><i class="fas <?php echo $catalog[$topKey][3]; ?> me-1"></i><?php echo htmlspecialchars($catalog[$topKey][0]); ?></div>
          <small style="color:#9fb3a6"><?php echo $topN; ?> account<?php echo $topN === 1 ? '' : 's'; ?> · KES <?php echo number_format($catalog[$topKey][1] / 1000); ?>K</small></div>
        <div class="pct"><?php echo $total ? round($topN / $total * 100) : 0; ?>% <i class="fas fa-arrow-trend-up" style="font-size:.9rem"></i></div>
      </div>
      <?php $maxPk = max(1, max($perPackage)); foreach ($perPackage as $k => $n): ?>
        <div class="pop-row"><span class="nm"><?php echo htmlspecialchars($catalog[$k][0]); ?></span>
          <span class="tr"><i style="width:<?php echo $n / $maxPk * 100; ?>%"></i></span><span class="ct"><?php echo $n; ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Signup switch -->
<div class="d-card">
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
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
