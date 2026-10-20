<?php
// public/support/index.php — Support console overview (dashboard).
// Technical activity only — never a client's sales, money or licence amounts:
//   KPIs (accounts · users · online now · requests) · traffic this week vs
//   last week · server CPU load (24 h) · busiest accounts and the resources
//   they use · live server gauge · users online / offline · sign-in and
//   sign-out sessions · error log · packages in use · signup switch.
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

// ---------------------------------------------------------------- data
$accounts = support_accounts($pdo);
$catalog = Features::catalog();
$perPackage = array_fill_keys(array_keys($catalog), 0);
$active = 0;
$thisMonth = 0;
foreach ($accounts as $a) {
    if ($a['status'] === 'active') $active++;
    if (substr((string) $a['created_at'], 0, 7) === date('Y-m')) $thisMonth++;
    foreach (Features::forAccount((int) $a['id']) as $k => $on) { if ($on) $perPackage[$k]++; }
}
arsort($perPackage);
$total = count($accounts);

$names = [0 => 'Signed-out visitors'];
foreach ($accounts as $a) { $names[(int) $a['id']] = $a['name']; }
$accountOf = []; // any shop (branch) → its account
foreach ($pdo->query('SELECT id, COALESCE(parent_tenant_id, id) FROM tenants')->fetchAll(PDO::FETCH_KEY_PAIR) as $t => $acct) { $accountOf[(int) $t] = (int) $acct; }

$openTickets = $newTickets = 0;
try {
    [$openTickets, $newTickets] = array_map('intval', $pdo->query(
        "SELECT SUM(status IN ('open','in_progress','waiting')), SUM(unread_by_support = 1) FROM support_tickets"
    )->fetch(PDO::FETCH_NUM));
} catch (\Throwable $e) { /* tickets table not installed yet */ }

Telemetry::ensure($pdo);
UserSessions::ensure($pdo);

// Users: owners/admins and staff (demo logins excluded), and who's online now.
$userCounts = $pdo->query(
    "SELECT r.role_name, COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
      WHERE u.is_active = 1 AND u.email NOT LIKE '%@demo.local' AND r.role_name IN ('tenant_owner','staff') GROUP BY r.role_name"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$owners = (int) ($userCounts['tenant_owner'] ?? 0);
$staffN = (int) ($userCounts['staff'] ?? 0);
$online = UserSessions::onlineCount($pdo);

// Requests: last 24 h against the 24 h before.
$req24 = (int) $pdo->query('SELECT COALESCE(SUM(requests),0) FROM sys_traffic WHERE hour >= NOW() - INTERVAL 24 HOUR')->fetchColumn();
$reqPrev = (int) $pdo->query('SELECT COALESCE(SUM(requests),0) FROM sys_traffic WHERE hour >= NOW() - INTERVAL 48 HOUR AND hour < NOW() - INTERVAL 24 HOUR')->fetchColumn();
$reqChange = $reqPrev > 0 ? round(($req24 - $reqPrev) / $reqPrev * 100, 1) : null;

// Traffic: requests per day, this week against last week.
$perDay = $pdo->query('SELECT DATE(hour) d, SUM(requests) FROM sys_traffic WHERE hour >= CURDATE() - INTERVAL 13 DAY GROUP BY d')->fetchAll(PDO::FETCH_KEY_PAIR);
$week = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $week[$d] = [(int) ($perDay[$d] ?? 0), (int) ($perDay[date('Y-m-d', strtotime("-" . ($i + 7) . " days"))] ?? 0)];
}
$weekMax = max(1, max(array_map('max', $week)));
$today = (int) ($perDay[date('Y-m-d')] ?? 0);
$yesterday = (int) ($perDay[date('Y-m-d', strtotime('-1 day'))] ?? 0);

// Server: live gauge, CPU history and the day's response / data figures.
$server = Telemetry::server();
$cpuHist = Telemetry::loadHistory($pdo, 24);
$day = $pdo->query(
    'SELECT COALESCE(SUM(requests),0) req, COALESCE(SUM(bytes_in),0) bin, COALESCE(SUM(bytes_out),0) bout,
            COALESCE(SUM(cpu_ms),0) cpu, COALESCE(SUM(wall_ms),0) wall, COALESCE(MAX(max_ms),0) slowest, COALESCE(SUM(errors),0) err
       FROM sys_traffic WHERE hour >= NOW() - INTERVAL 24 HOUR'
)->fetch(PDO::FETCH_ASSOC);
$avgMs = $day['req'] ? round($day['wall'] / $day['req']) : 0;

// Busiest accounts (7 days): their share of traffic and of CPU, data, speed, errors and who's on.
$busy = $pdo->query(
    'SELECT account_id, SUM(requests) req, SUM(bytes_in) bin, SUM(bytes_out) bout, SUM(cpu_ms) cpu, SUM(wall_ms) wall, SUM(errors) err
       FROM sys_traffic WHERE hour >= NOW() - INTERVAL 7 DAY GROUP BY account_id ORDER BY req DESC LIMIT 8'
)->fetchAll(PDO::FETCH_ASSOC);
$busyTotalReq = max(1, array_sum(array_column($busy, 'req')));
$busyTotalCpu = max(1, array_sum(array_column($busy, 'cpu')));
$onlineByAccount = $pdo->query('SELECT account_id, COUNT(DISTINCT user_id) FROM user_sessions WHERE ended_at IS NULL AND last_seen_at >= NOW() - INTERVAL '
    . UserSessions::ONLINE_MINUTES . ' MINUTE GROUP BY account_id')->fetchAll(PDO::FETCH_KEY_PAIR);

$people = UserSessions::people($pdo, 60);
$sessions = UserSessions::recent($pdo, 20);
$errors = $pdo->query('SELECT created_at, account_id, method, path, status, message FROM sys_errors ORDER BY id DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------- helpers
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$bytes = function ($b): string {
    $b = (float) $b;
    foreach (['B', 'KB', 'MB', 'GB'] as $u) { if ($b < 1024 || $u === 'GB') return ($u === 'B' ? (int) $b : number_format($b, 1)) . ' ' . $u; $b /= 1024; }
    return '';
};
$ago = function (?string $t): string {
    if (!$t) return 'never';
    $s = time() - strtotime($t);
    if ($s < 60) return 'just now';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' h ago';
    return date('j M', strtotime($t));
};
$duration = function (string $from, ?string $to): string {
    $s = max(0, strtotime($to ?: 'now') - strtotime($from));
    return $s < 3600 ? max(1, floor($s / 60)) . ' min' : floor($s / 3600) . ' h ' . floor($s % 3600 / 60) . ' min';
};
$delta = function (?float $p): string {
    if ($p === null) return '';
    return '<span class="dl ' . ($p < 0 ? 'neg' : '') . '">' . ($p >= 0 ? '+' : '') . $p . '%</span>';
};
$acctName = fn(int $tid) => $names[$accountOf[$tid] ?? $tid] ?? ('Account #' . $tid);
$roleLabel = fn(string $r) => $r === 'staff' ? 'Staff' : 'Owner';
$reasonLabel = ['logout' => 'Signed out', 'replaced' => 'Signed in elsewhere', 'expired' => 'Expired'];

// CPU line (SVG points); hours with no samples are skipped.
$pts = [];
$i = 0;
$n = count($cpuHist) - 1;
foreach ($cpuHist as $hr => $v) {
    if ($v !== null) $pts[] = round($i / max(1, $n) * 600, 1) . ',' . round(170 - $v / 100 * 160, 1);
    $i++;
}
$cpuNow = $server['cpu_pct'];

$page_title = 'Dashboard';
$nav = 'overview';
ob_start();
?>
<style>
.db{--line:#e4e4e7;--mute:#71717a;--ink:#09090b;--soft:#f4f4f5;}
.db .c{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;height:100%;}
.db .lbl{font-size:.82rem;color:var(--mute);font-weight:500;}
.db .num{font-size:1.9rem;font-weight:600;letter-spacing:-.03em;line-height:1.15;margin-top:6px;}
.db .sub{font-size:.78rem;color:var(--mute);}
.db .dl{font-size:.74rem;font-weight:600;color:#16a34a;margin-left:6px;}
.db .dl.neg{color:#dc2626;}
.db .more{font-size:.8rem;font-weight:500;color:var(--ink);text-decoration:none;display:inline-flex;gap:6px;align-items:center;}
.db .hd{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:14px;}
.db .hd h2{font-size:.98rem;font-weight:600;margin:0;}
.db .btn-x{border:1px solid var(--line);background:#fff;border-radius:8px;font-size:.78rem;font-weight:500;padding:5px 10px;color:var(--ink);text-decoration:none;white-space:nowrap;}
.db .split2{display:flex;border:1px solid var(--line);border-radius:10px;overflow:hidden;}
.db .split2>div{padding:8px 14px;}
.db .split2>div+div{border-left:1px solid var(--line);}
.db .split2 small{display:block;font-size:.66rem;color:var(--mute);text-transform:uppercase;letter-spacing:.05em;}
.db .split2 b{font-size:1.15rem;font-weight:600;}
.db .bars{display:flex;align-items:flex-end;gap:14px;height:210px;padding-top:10px;}
.db .bars .g{flex:1;display:flex;flex-direction:column;align-items:center;gap:8px;height:100%;}
.db .bars .pair{flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center;gap:4px;}
.db .bars i{display:block;width:42%;max-width:26px;border-radius:5px 5px 2px 2px;background:#18181b;min-height:3px;}
.db .bars i.prev{background:#71717a;}
.db .bars span{font-size:.7rem;color:var(--mute);}
.db .legend{display:flex;gap:14px;font-size:.74rem;color:var(--mute);}
.db .legend i{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:5px;vertical-align:-1px;}
.db .rowbar{margin-bottom:14px;}
.db .rowbar .t{display:flex;justify-content:space-between;align-items:center;font-size:.86rem;}
.db .rowbar .t b{font-weight:500;}
.db .track{height:8px;border-radius:5px;background:var(--soft);margin-top:6px;overflow:hidden;}
.db .track i{display:block;height:100%;background:#18181b;border-radius:5px;}
.db .meta{font-size:.72rem;color:var(--mute);margin-top:4px;}
.db .gauge{display:grid;place-items:center;margin:4px 0 12px;}
.db .kv{display:flex;justify-content:space-between;gap:10px;font-size:.82rem;padding:7px 0;border-top:1px solid var(--soft);}
.db .kv span{color:var(--mute);}
.db .kv b{font-weight:500;text-align:right;}
.db .ppl{max-height:330px;overflow:auto;margin:0 -6px;}
.db .pp{display:flex;align-items:center;gap:10px;padding:8px 6px;}
.db .pp+.pp{border-top:1px solid var(--soft);}
.db .dot{width:8px;height:8px;border-radius:50%;flex:0 0 auto;border:1.5px solid #a1a1aa;}
.db .dot.on{background:#16a34a;border-color:#16a34a;}
.db .pp .nm{font-size:.86rem;font-weight:500;}
.db .pp .ac{font-size:.74rem;color:var(--mute);}
.db .pp .st{margin-left:auto;text-align:right;font-size:.74rem;color:var(--mute);white-space:nowrap;}
.db .tag{display:inline-block;border:1px solid var(--line);border-radius:6px;font-size:.68rem;font-weight:500;padding:1px 6px;color:#3f3f46;}
.db table{width:100%;font-size:.82rem;border-collapse:collapse;}
.db th{font-weight:500;color:var(--mute);font-size:.74rem;padding:8px 10px;border-bottom:1px solid var(--line);white-space:nowrap;text-align:left;}
.db td{padding:9px 10px;border-bottom:1px solid var(--soft);vertical-align:middle;}
.db tr:last-child td{border-bottom:0;}
.db .tr{text-align:right;}
.db .mono{font-family:ui-monospace,Menlo,monospace;font-size:.76rem;}
@media (max-width:575px){ .db .c{padding:16px} .db .num{font-size:1.6rem} .db .bars{gap:6px;height:170px} }
</style>

<div class="db">
<div class="d-flex justify-content-end gap-2 mb-3">
  <a class="btn-x" href="<?php echo public_url('support/tickets/'); ?>"><i class="fas fa-bell me-1"></i>Tickets<?php echo $newTickets ? ' · ' . $newTickets . ' new' : ''; ?></a>
  <a class="btn btn-sm btn-primary px-3" href="<?php echo public_url('support/accounts/?new=1'); ?>"><i class="fas fa-plus me-1"></i>New account</a>
</div>

<!-- KPIs -->
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3"><div class="c">
    <div class="lbl">Shop accounts<span class="dl">+<?php echo $thisMonth; ?></span></div>
    <div class="num"><?php echo number_format($total); ?></div>
    <div class="d-flex justify-content-between align-items-center mt-3"><span class="sub"><?php echo $active; ?> active</span><a class="more" href="<?php echo public_url('support/accounts/'); ?>">View <i class="fas fa-arrow-right"></i></a></div>
  </div></div>
  <div class="col-6 col-xl-3"><div class="c">
    <div class="lbl">Users</div>
    <div class="num"><?php echo number_format($owners + $staffN); ?></div>
    <div class="sub mt-3"><?php echo $owners; ?> owners / admins · <?php echo $staffN; ?> staff</div>
  </div></div>
  <div class="col-6 col-xl-3"><div class="c">
    <div class="lbl">Online now</div>
    <div class="num"><?php echo number_format($online); ?></div>
    <div class="d-flex justify-content-between align-items-center mt-3"><span class="sub">active in the last <?php echo UserSessions::ONLINE_MINUTES; ?> min</span><a class="more" href="#people">View <i class="fas fa-arrow-right"></i></a></div>
  </div></div>
  <div class="col-6 col-xl-3"><div class="c">
    <div class="lbl">Requests (24 h)<?php echo $delta($reqChange); ?></div>
    <div class="num"><?php echo number_format($req24); ?></div>
    <div class="sub mt-3"><?php echo number_format($day['err']); ?> errors · avg <?php echo $avgMs; ?> ms</div>
  </div></div>
</div>

<!-- Traffic + CPU -->
<div class="row g-3 mb-3">
  <div class="col-12 col-xl-6"><div class="c">
    <div class="hd">
      <div><h2>Traffic</h2><div class="sub">Requests per day, this week against last week</div></div>
      <div class="split2"><div><small>Today</small><b><?php echo number_format($today); ?></b></div><div><small>Yesterday</small><b><?php echo number_format($yesterday); ?></b></div></div>
    </div>
    <div class="bars">
      <?php foreach ($week as $d => [$now, $prev]): ?>
        <div class="g"><div class="pair">
          <i style="height:<?php echo max(1.5, $now / $weekMax * 100); ?>%" title="<?php echo $h(date('D j M', strtotime($d)) . ': ' . $now); ?> requests"></i>
          <i class="prev" style="height:<?php echo max(1.5, $prev / $weekMax * 100); ?>%" title="Week before: <?php echo $prev; ?>"></i>
        </div><span><?php echo date('D', strtotime($d)); ?></span></div>
      <?php endforeach; ?>
    </div>
    <div class="legend mt-2"><span><i style="background:#18181b"></i>This week</span><span><i style="background:#71717a"></i>Last week</span></div>
  </div></div>
  <div class="col-12 col-xl-6"><div class="c">
    <div class="hd">
      <div><h2>Server CPU load</h2><div class="num" style="font-size:1.6rem"><?php echo $cpuNow; ?>%<span class="sub ms-2" style="font-weight:400">now · <?php echo $server['cores']; ?> core<?php echo $server['cores'] === 1 ? '' : 's'; ?></span></div></div>
      <span class="btn-x">24 h</span>
    </div>
    <?php if (!$pts): ?>
      <p class="sub mb-0 mt-4">No load samples yet — one is recorded each minute while shops use the system<?php echo function_exists('sys_getloadavg') ? '' : ' (this server does not report its load)'; ?>.</p>
    <?php else: ?>
    <svg viewBox="0 0 600 180" style="width:100%;height:200px" preserveAspectRatio="none" aria-label="CPU load, last 24 hours">
      <?php foreach ([10, 50, 90, 130, 170] as $y): ?><line x1="0" x2="600" y1="<?php echo $y; ?>" y2="<?php echo $y; ?>" stroke="#f4f4f5"/><?php endforeach; ?>
      <polyline points="<?php echo implode(' ', $pts); ?>" fill="none" stroke="#18181b" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
    </svg>
    <div class="d-flex justify-content-between sub"><span><?php echo date('H:00', strtotime(array_key_first($cpuHist))); ?></span><span>12 h ago</span><span>now</span></div>
    <?php endif; ?>
  </div></div>
</div>

<!-- Busiest accounts · live server · people -->
<div class="row g-3 mb-3">
  <div class="col-12 col-xl-5"><div class="c">
    <div class="hd"><div><h2>Busiest accounts</h2><div class="sub">Share of traffic over the last 7 days, and what each uses</div></div></div>
    <?php if (!$busy): ?><p class="sub mb-0">No traffic recorded yet.</p><?php endif; ?>
    <?php foreach ($busy as $b): $id = (int) $b['account_id']; $share = round($b['req'] / $busyTotalReq * 100); ?>
      <div class="rowbar">
        <div class="t"><b><?php echo $h($names[$id] ?? ('Account #' . $id)); ?><?php if (!empty($onlineByAccount[$id])): ?> <span class="tag"><?php echo (int) $onlineByAccount[$id]; ?> online</span><?php endif; ?></b><span><?php echo $share; ?>%</span></div>
        <div class="track"><i style="width:<?php echo $share; ?>%"></i></div>
        <div class="meta"><?php echo number_format($b['req']); ?> requests · CPU <?php echo number_format($b['cpu'] / 1000, 1); ?> s (<?php echo round($b['cpu'] / $busyTotalCpu * 100); ?>%) · in <?php echo $bytes($b['bin']); ?> / out <?php echo $bytes($b['bout']); ?> · avg <?php echo $b['req'] ? round($b['wall'] / $b['req']) : 0; ?> ms<?php echo $b['err'] ? ' · ' . (int) $b['err'] . ' errors' : ''; ?></div>
      </div>
    <?php endforeach; ?>
  </div></div>

  <div class="col-12 col-md-5 col-xl-3"><div class="c">
    <div class="hd"><h2>Server now</h2></div>
    <div class="gauge">
      <svg width="150" height="150" viewBox="0 0 36 36" aria-hidden="true">
        <circle cx="18" cy="18" r="15" fill="none" stroke="#f4f4f5" stroke-width="3.2"/>
        <circle cx="18" cy="18" r="15" fill="none" stroke="#18181b" stroke-width="3.2" stroke-linecap="round" pathLength="100"
                stroke-dasharray="<?php echo max(.5, $cpuNow); ?> 100" transform="rotate(-90 18 18)"/>
        <text x="18" y="18" text-anchor="middle" font-size="6.5" font-weight="600" fill="#09090b"><?php echo $cpuNow; ?>%</text>
        <text x="18" y="23" text-anchor="middle" font-size="2.6" fill="#71717a">CPU</text>
      </svg>
    </div>
    <div class="kv"><span>Load (1 / 5 / 15 min)</span><b><?php echo implode(' · ', array_map(fn($l) => number_format($l, 2), $server['load'])); ?></b></div>
    <?php if ($server['mem']): ?><div class="kv"><span>Memory</span><b><?php echo $bytes($server['mem']['used']) . ' / ' . $bytes($server['mem']['total']); ?></b></div><?php endif; ?>
    <?php if ($server['disk']): ?><div class="kv"><span>Disk</span><b><?php echo $bytes($server['disk']['used']) . ' / ' . $bytes($server['disk']['total']); ?></b></div><?php endif; ?>
    <div class="kv"><span>Data (24 h)</span><b>in <?php echo $bytes($day['bin']); ?> · out <?php echo $bytes($day['bout']); ?></b></div>
    <div class="kv"><span>Slowest page (24 h)</span><b><?php echo number_format($day['slowest']); ?> ms</b></div>
  </div></div>

  <div class="col-12 col-md-7 col-xl-4" id="people"><div class="c">
    <div class="hd"><div><h2>Users</h2><div class="sub"><?php echo $online; ?> online · <?php echo max(0, count($people) - $online); ?> offline</div></div></div>
    <div class="ppl">
      <?php if (!$people): ?><p class="sub px-2">No users yet.</p><?php endif; ?>
      <?php foreach ($people as $p): $on = (int) $p['online'] === 1; ?>
        <div class="pp">
          <span class="dot <?php echo $on ? 'on' : ''; ?>" title="<?php echo $on ? 'Online' : 'Offline'; ?>"></span>
          <div style="min-width:0">
            <div class="nm"><?php echo $h($p['username']); ?> <span class="tag"><?php echo $roleLabel($p['role_name']); ?></span></div>
            <div class="ac text-truncate"><?php echo $h($acctName((int) $p['tenant_id'])); ?><?php echo $p['agent'] ? ' · ' . $h($p['agent']) : ''; ?></div>
          </div>
          <div class="st"><?php echo $on ? '<b style="color:#09090b;font-weight:500">Online</b><br>' . $ago($p['last_seen_at']) : ($p['last_seen_at'] ? 'Last seen<br>' . $ago($p['last_seen_at']) : 'Not signed in yet'); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div></div>
</div>

<!-- Sessions -->
<div class="c mb-3">
  <div class="hd"><div><h2>Sign-in sessions</h2><div class="sub">Latest sign-ins, when they signed out and how long they stayed</div></div></div>
  <?php if (!$sessions): ?><p class="sub mb-0">No sign-ins recorded yet — they appear as people sign in.</p><?php else: ?>
  <div class="table-responsive"><table>
    <thead><tr><th>User</th><th>Account</th><th>Device</th><th>Signed in</th><th>Signed out</th><th class="tr">Time on</th></tr></thead>
    <tbody>
    <?php foreach ($sessions as $s):
        $live = !$s['ended_at'] && strtotime($s['last_seen_at']) >= time() - UserSessions::ONLINE_MINUTES * 60; ?>
      <tr>
        <td class="text-nowrap"><span class="dot d-inline-block me-2 <?php echo $live ? 'on' : ''; ?>"></span><b style="font-weight:500"><?php echo $h($s['username'] ?? ('User #' . $s['user_id'])); ?></b> <span class="tag"><?php echo $roleLabel((string) $s['role']); ?></span></td>
        <td><?php echo $h($names[(int) $s['account_id']] ?? ('Account #' . (int) $s['account_id'])); ?></td>
        <td class="sub"><?php echo $h($s['agent'] ?: '—'); ?><?php echo $s['ip'] ? '<br><span class="mono">' . $h($s['ip']) . '</span>' : ''; ?></td>
        <td class="text-nowrap"><?php echo date('j M, H:i', strtotime($s['started_at'])); ?></td>
        <td class="text-nowrap"><?php
            if ($s['ended_at']) echo date('j M, H:i', strtotime($s['ended_at'])) . '<br><span class="sub">' . $h($reasonLabel[$s['end_reason']] ?? $s['end_reason']) . '</span>';
            elseif ($live) echo '<b style="font-weight:500">Still on</b>';
            else echo '<span class="sub">Left without signing out<br>last seen ' . date('H:i', strtotime($s['last_seen_at'])) . '</span>'; ?></td>
        <td class="tr text-nowrap"><?php echo $duration($s['started_at'], $s['ended_at'] ?: $s['last_seen_at']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<!-- Errors + packages -->
<div class="row g-3 mb-3">
  <div class="col-12 col-xl-8"><div class="c">
    <div class="hd"><div><h2>Error log</h2><div class="sub">Latest failed requests · kept 30 days</div></div></div>
    <?php if (!$errors): ?><p class="sub mb-0"><i class="fas fa-circle-check me-1"></i>No errors recorded.</p><?php else: ?>
    <div class="table-responsive"><table>
      <thead><tr><th>When</th><th>Account</th><th>Page</th><th>Status</th><th>Error</th></tr></thead>
      <tbody>
      <?php foreach ($errors as $e): ?>
        <tr>
          <td class="text-nowrap"><?php echo date('j M H:i', strtotime($e['created_at'])); ?></td>
          <td><?php echo $h($names[(int) $e['account_id']] ?? ('Account #' . (int) $e['account_id'])); ?></td>
          <td class="mono"><?php echo $h($e['method'] . ' ' . $e['path']); ?></td>
          <?php $first = trim(preg_split('/\s+Stack trace:|\s#0 /', (string) $e['message'])[0]);
                $first = preg_replace('#/[^\s:]*/(public|app)/#', '$1/', $first); // shorten server paths ?>
          <td><span class="tag"><?php echo (int) $e['status'] >= 400 ? (int) $e['status'] : 'Error'; ?></span></td>
          <td class="sub" style="max-width:420px;word-break:break-word" title="<?php echo $h($e['message']); ?>"><?php echo $h(mb_strimwidth($first, 0, 180, '…')); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div></div>
  <div class="col-12 col-xl-4"><div class="c">
    <div class="hd"><div><h2>Packages in use</h2><div class="sub">Accounts on each package</div></div><a class="btn-x" href="<?php echo public_url('support/packages/'); ?>">View all</a></div>
    <?php $maxPk = max(1, max($perPackage)); foreach ($perPackage as $k => $cnt): ?>
      <div class="rowbar" style="margin-bottom:10px">
        <div class="t"><span><?php echo $h($catalog[$k][0]); ?></span><span class="sub"><?php echo $cnt; ?></span></div>
        <div class="track" style="height:6px"><i style="width:<?php echo $cnt / $maxPk * 100; ?>%"></i></div>
      </div>
    <?php endforeach; ?>
  </div></div>
</div>

<!-- Signup switch -->
<div class="c">
  <form method="post" class="d-flex flex-wrap align-items-center gap-3">
    <?php echo support_csrf_field(); ?><input type="hidden" name="action" value="signup_toggle">
    <input type="hidden" name="enabled" value="<?php echo $signupOn ? '' : '1'; ?>">
    <div class="form-check form-switch m-0" style="font-size:1.2rem">
      <input class="form-check-input" type="checkbox" role="switch" id="signupSwitch" <?php echo $signupOn ? 'checked' : ''; ?> onchange="this.form.submit()">
    </div>
    <div class="flex-grow-1">
      <label for="signupSwitch" class="d-block mb-0" style="font-weight:600">Signup page <span class="tag ms-1"><?php echo $signupOn ? 'ON' : 'OFF'; ?></span></label>
      <small class="sub"><?php echo $signupOn
          ? 'Owners see "Create your account" on the login page. They confirm the business name you created, import their data, and go straight in — no activation.'
          : 'Turn on to show "Create your account" on the owner login page.'; ?></small>
    </div>
    <?php if ($signupOn): ?>
    <div class="input-group input-group-sm" style="max-width:360px">
      <input class="form-control" id="signupLink" value="<?php echo $h(Signup::signupUrl()); ?>" readonly onclick="this.select()">
      <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('signupLink').value);this.textContent='Copied'">Copy</button>
    </div>
    <?php endif; ?>
  </form>
</div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
