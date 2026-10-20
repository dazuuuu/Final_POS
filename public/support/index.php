<?php
// public/support/index.php — Support console overview (dashboard).
//   KPI cards · traffic (requests per hour) · server load · busiest accounts ·
//   error log · most used package · signup switch. Technical figures only —
//   no client sales, money or licence amounts are shown here.
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
$active = $suspended = 0;
foreach ($accounts as $a) {
    $a['status'] === 'active' ? $active++ : $suspended++;
    foreach (Features::forAccount((int) $a['id']) as $k => $on) {
        if ($on) { $perPackage[$k]++; }
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

// Technical usage only (Telemetry): requests, data, CPU, response time and
// errors per account. No sales, amounts or customer data are read here —
// clients' business figures are theirs, not support's.
Telemetry::ensure($pdo);
$names = [0 => 'Signed-out visitors (login pages)'];
foreach ($accounts as $a) { $names[(int) $a['id']] = $a['name']; }

// Requests per hour, last 48 hours.
$hours = [];
for ($i = 47; $i >= 0; $i--) { $hours[date('Y-m-d H:00:00', strtotime("-$i hours"))] = 0; }
$st = $pdo->prepare('SELECT hour, SUM(requests) FROM sys_traffic WHERE hour >= ? GROUP BY hour');
$st->execute([array_key_first($hours)]);
foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $h => $n) { if (isset($hours[$h])) $hours[$h] = (int) $n; }
$maxHour = max(1, max($hours));
$peakHour = array_search(max($hours), $hours, true);
$avgHour = array_sum($hours) / count($hours);

// Last 24 hours, all accounts.
$day = $pdo->query(
    'SELECT COALESCE(SUM(requests),0) req, COALESCE(SUM(bytes_in),0) bin, COALESCE(SUM(bytes_out),0) bout,
            COALESCE(SUM(cpu_ms),0) cpu, COALESCE(SUM(wall_ms),0) wall, COALESCE(MAX(max_ms),0) slowest, COALESCE(SUM(errors),0) err
       FROM sys_traffic WHERE hour >= NOW() - INTERVAL 24 HOUR'
)->fetch(PDO::FETCH_ASSOC);
$avgMs = $day['req'] ? round($day['wall'] / $day['req']) : 0;

// Busiest accounts, last 7 days, with a 14-day requests sparkline.
$busy = $pdo->query(
    'SELECT account_id, SUM(requests) req, SUM(bytes_in) bin, SUM(bytes_out) bout, SUM(cpu_ms) cpu, SUM(wall_ms) wall, SUM(errors) err
       FROM sys_traffic WHERE hour >= NOW() - INTERVAL 7 DAY GROUP BY account_id ORDER BY req DESC LIMIT 8'
)->fetchAll(PDO::FETCH_ASSOC);
$acctDay = [];
foreach ($pdo->query('SELECT account_id, DATE(hour) d, SUM(requests) n FROM sys_traffic WHERE hour >= CURDATE() - INTERVAL 13 DAY GROUP BY account_id, d')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $acctDay[(int) $r['account_id']][$r['d']] = (int) $r['n'];
}

$errors = $pdo->query('SELECT created_at, account_id, method, path, status, message FROM sys_errors ORDER BY id DESC LIMIT 15')->fetchAll(PDO::FETCH_ASSOC);
$server = Telemetry::server();

$bytes = function ($b): string {
    $b = (float) $b;
    foreach (['B', 'KB', 'MB', 'GB'] as $u) { if ($b < 1024 || $u === 'GB') return ($u === 'B' ? (int) $b : number_format($b, 1)) . ' ' . $u; $b /= 1024; }
    return '';
};

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
        <div class="d-label">Requests (24 hours)</div>
        <div class="d-num"><?php echo number_format($day['req']); ?></div>
        <div class="d-delta"><a class="text-reset" href="<?php echo public_url('support/tickets/'); ?>"><b><?php echo $openTickets; ?></b> open tickets<?php echo $newTickets ? ' · ' . $newTickets . ' new' : ''; ?></a></div>
      </div>
      <?php echo $miniBars(array_slice($hours, -12)); ?>
    </div>
  </div>
</div>

<!-- Traffic chart + server load -->
<div class="row g-3 mb-3">
  <div class="col-12 col-xl-8">
    <div class="d-card">
      <div class="d-head"><h2>Traffic across all shops</h2><span class="d-pill">Requests per hour · 48 h</span></div>
      <div class="chart">
        <?php foreach ([1, .75, .5, .25] as $f): ?><span class="y" style="bottom:<?php echo $f * 100; ?>%"><?php echo round($maxHour * $f); ?></span><?php endforeach; ?>
        <span class="avg" style="bottom:<?php echo $avgHour / $maxHour * 100; ?>%" title="Hourly average"></span>
        <?php $i = 0; foreach ($hours as $h => $n): $i++; ?>
          <span class="bar <?php echo $h === $peakHour && $n ? 'peak' : ($i > 24 ? 'on' : ''); ?>" style="height:<?php echo max(1.5, $n / $maxHour * 100); ?>%"
                data-tip="<?php echo $n; ?> requests · <?php echo date('D H:00', strtotime($h)); ?>" title="<?php echo date('D j M, H:00', strtotime($h)) . ': ' . $n; ?> requests"></span>
        <?php endforeach; ?>
      </div>
      <div class="chart-x"><span>Yesterday</span><span>Today</span></div>
    </div>
  </div>
  <div class="col-12 col-xl-4">
    <div class="d-card soft">
      <div class="d-label">Server CPU load (1 min)</div>
      <div class="big-num"><?php echo $server['cpu_pct']; ?><small>%</small></div>
      <div class="mt-1 mb-2 small text-muted">Load <?php echo implode(' · ', array_map(fn($l) => number_format($l, 2), $server['load'])); ?> (1 / 5 / 15 min) on <?php echo $server['cores']; ?> core<?php echo $server['cores'] === 1 ? '' : 's'; ?></div>
      <?php if ($server['mem']): ?><div class="d-row py-1"><span class="ic"><i class="fas fa-memory"></i></span><div><div>Memory</div><small class="text-muted"><?php echo $bytes($server['mem']['used']) . ' of ' . $bytes($server['mem']['total']); ?></small></div></div><?php endif; ?>
      <?php if ($server['disk']): ?><div class="d-row py-1"><span class="ic"><i class="fas fa-hard-drive"></i></span><div><div>Disk</div><small class="text-muted"><?php echo $bytes($server['disk']['used']) . ' of ' . $bytes($server['disk']['total']); ?></small></div></div><?php endif; ?>
      <div class="d-row py-1"><span class="ic"><i class="fas fa-gauge-high"></i></span><div><div>Response time (24 h)</div><small class="text-muted">avg <?php echo $avgMs; ?> ms · slowest <?php echo number_format($day['slowest']); ?> ms · CPU <?php echo number_format($day['cpu'] / 1000, 1); ?> s</small></div></div>
      <div class="d-row py-1"><span class="ic"><i class="fas fa-arrow-right-arrow-left"></i></span><div><div>Data (24 h)</div><small class="text-muted">received <?php echo $bytes($day['bin']); ?> · sent <?php echo $bytes($day['bout']); ?></small></div></div>
      <div class="d-row py-1"><span class="ic"><i class="fas fa-triangle-exclamation"></i></span><div><div>Errors (24 h)</div><small class="<?php echo $day['err'] ? 'text-danger fw-semibold' : 'text-muted'; ?>"><?php echo number_format($day['err']); ?></small></div></div>
    </div>
  </div>
</div>

<!-- Accounts table + most used package -->
<div class="row g-3 mb-3">
  <div class="col-12 col-xl-8">
    <div class="d-card">
      <div class="d-head"><h2>Busiest accounts</h2><span class="d-pill">7 days</span></div>
      <?php if (!$busy): ?><p class="text-muted mb-0">No traffic recorded yet — figures appear as shops use the system.</p><?php else: ?>
      <div class="table-responsive">
      <table class="acct-table">
        <thead><tr><th>Account</th><th class="text-end">Requests</th><th>Trend (14 days)</th><th class="text-end">Received</th><th class="text-end">Sent</th><th class="text-end">CPU</th><th class="text-end">Avg ms</th><th class="text-end">Errors</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($busy as $b):
            $id = (int) $b['account_id'];
            $vals = [];
            for ($i = 13; $i >= 0; $i--) { $vals[] = $acctDay[$id][date('Y-m-d', strtotime("-$i days"))] ?? 0; }
            $up = array_sum(array_slice($vals, 7)) >= array_sum(array_slice($vals, 0, 7)); ?>
          <tr>
            <td class="fw-semibold"><?php echo htmlspecialchars($names[$id] ?? ('Account #' . $id)); ?></td>
            <td class="text-end"><?php echo number_format($b['req']); ?></td>
            <td><?php echo $spark($vals, $up); ?></td>
            <td class="text-end"><?php echo $bytes($b['bin']); ?></td>
            <td class="text-end"><?php echo $bytes($b['bout']); ?></td>
            <td class="text-end"><?php echo number_format($b['cpu'] / 1000, 1); ?> s</td>
            <td class="text-end"><?php echo $b['req'] ? round($b['wall'] / $b['req']) : 0; ?></td>
            <td class="text-end <?php echo $b['err'] ? 'text-danger fw-semibold' : 'text-muted'; ?>"><?php echo number_format($b['err']); ?></td>
            <td class="text-end"><?php if ($id): ?><a class="text-muted" href="<?php echo public_url('support/accounts/view.php?id=' . $id); ?>" aria-label="Open"><i class="fas fa-ellipsis-vertical"></i></a><?php endif; ?></td>
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
          <small style="color:#9fb3a6"><?php echo $topN; ?> account<?php echo $topN === 1 ? '' : 's'; ?></small></div>
        <div class="pct"><?php echo $total ? round($topN / $total * 100) : 0; ?>% <i class="fas fa-arrow-trend-up" style="font-size:.9rem"></i></div>
      </div>
      <?php $maxPk = max(1, max($perPackage)); foreach ($perPackage as $k => $n): ?>
        <div class="pop-row"><span class="nm"><?php echo htmlspecialchars($catalog[$k][0]); ?></span>
          <span class="tr"><i style="width:<?php echo $n / $maxPk * 100; ?>%"></i></span><span class="ct"><?php echo $n; ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Error log -->
<div class="d-card mb-3">
  <div class="d-head"><h2>Error log</h2><span class="d-pill">Latest 15 · kept 30 days</span></div>
  <?php if (!$errors): ?><p class="text-muted mb-0"><i class="fas fa-circle-check text-success me-1"></i>No errors recorded.</p><?php else: ?>
  <div class="table-responsive">
  <table class="acct-table">
    <thead><tr><th>When</th><th>Account</th><th>Page</th><th>Status</th><th>Error</th></tr></thead>
    <tbody>
    <?php foreach ($errors as $e): ?>
      <tr>
        <td class="text-nowrap"><?php echo date('j M H:i', strtotime($e['created_at'])); ?></td>
        <td><?php echo htmlspecialchars($names[(int) $e['account_id']] ?? ('Account #' . (int) $e['account_id'])); ?></td>
        <td class="font-monospace small"><?php echo htmlspecialchars($e['method'] . ' ' . $e['path']); ?></td>
        <td><span class="badge bg-danger-subtle text-danger"><?php echo (int) $e['status']; ?></span></td>
        <td class="small" style="max-width:420px;word-break:break-word"><?php echo htmlspecialchars($e['message']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
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
