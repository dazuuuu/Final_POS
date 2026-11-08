<?php
// public/stock/index.php — Home: the overview dashboard. Three figure cards
// with the change vs last month, the stock activity heat-map (time of day ×
// day of month, inflow or outflow), stores overview and what is running low.
require_once __DIR__ . '/_boot.php';
stock_guard();
$store = stock_store();
$stores = StockControl::myStores();
$ids = array_keys($stores);
$value = StockControl::can('stock.value');
$db = Database::pdo();

// Month shown (?m=YYYY-MM), default this month.
$m = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$mStart = $m . '-01';
$mEnd = date('Y-m-t', strtotime($mStart));
$pStart = date('Y-m-01', strtotime($mStart . ' -1 month'));
$days = (int) date('t', strtotime($mStart));

if (!empty($_GET['export'])) {
    header('Location: ' . stock_url('history.php?excel=1&scope=all&from=' . $mStart . '&to=' . $mEnd));
    exit;
}

$in = implode(',', array_fill(0, count($ids), '?'));
// Totals now
$sum = StockControl::summary($ids);
$now = ['units' => 0.0, 'value' => 0.0, 'items' => 0, 'low' => 0];
foreach ($sum as $r) { $now['units'] += $r['units']; $now['value'] += $r['value']; $now['items'] += $r['items']; $now['low'] += $r['low']; }

/** Units and value in (+) and out (−) between two dates, all stores in view. */
$flow = function (string $from, string $to) use ($db, $in, $ids): array {
    $st = $db->prepare("SELECT COALESCE(SUM(CASE WHEN qty > 0 THEN qty END),0) uin, COALESCE(SUM(CASE WHEN qty < 0 THEN -qty END),0) uout,
                               COALESCE(SUM(qty * unit_cost),0) vnet, COUNT(*) n
                          FROM stock_moves WHERE tenant_id IN ($in) AND created_at >= ? AND created_at < ?");
    $st->execute(array_merge($ids, [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']));
    return $st->fetch();
};
$thisM = $flow($mStart, $mEnd);
$lastM = $flow($pStart, date('Y-m-t', strtotime($pStart)));
// Stock value at the start of the month = now − everything that moved since then.
$since = $flow($mStart, date('Y-m-d'));
$valueStart = $now['value'] - (float) $since['vnet'];
$pct = function ($a, $b): array {
    if ((float) $b == 0.0) return (float) $a > 0 ? ['up', 'new'] : ['eq', '0%'];
    $p = ((float) $a - (float) $b) / abs((float) $b) * 100;
    return [$p > 0.05 ? 'up' : ($p < -0.05 ? 'dn' : 'eq'), ($p > 0 ? '↑ ' : ($p < 0 ? '↓ ' : '')) . round(abs($p), 1) . '%'];
};

// Heat-map: units moved per day and time-of-day band.
$mode = ($_GET['flow'] ?? 'in') === 'out' ? 'out' : 'in';
$st = $db->prepare("SELECT DAY(created_at) d, FLOOR(HOUR(created_at) / 6) b, SUM(ABS(qty)) u FROM stock_moves
                     WHERE tenant_id IN ($in) AND created_at >= ? AND created_at < ? AND qty " . ($mode === 'in' ? '> 0' : '< 0') . " GROUP BY d, b");
$st->execute(array_merge($ids, [$mStart . ' 00:00:00', date('Y-m-d', strtotime($mEnd . ' +1 day')) . ' 00:00:00']));
$heat = []; $max = 0;
foreach ($st->fetchAll() as $r) { $heat[(int) $r['b']][(int) $r['d']] = (float) $r['u']; $max = max($max, (float) $r['u']); }
$level = fn($u) => $u <= 0 ? 0 : min(4, (int) ceil($u / max(1, $max) * 4));
$legend = $max > 0 ? array_map(fn($i) => stock_qty($max / 4 * $i), [1, 2, 3, 4]) : ['1', '2', '3', '4'];

$low = StockControl::lowStock($ids, 8);
$months = [];
for ($i = 0; $i < 12; $i++) { $k = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $months[$k] = date('F Y', strtotime($k . '-01')); }
$qs = fn(array $x) => '?' . http_build_query(['m' => $m, 'flow' => $mode] + $x);

$title = 'Home';
$tab = '';
ob_start();
?>
<?php if ($now['low']): ?>
<div class="banner warn" id="lowBanner"><i class="fas fa-triangle-exclamation" style="color:#d97706"></i>
  <?php echo (int) $now['low']; ?> item<?php echo $now['low'] == 1 ? ' is' : 's are'; ?> at or below the reorder level.
  <a class="btn btn-l btn-sm" href="#low">Review</a></div>
<?php else: ?>
<div class="banner" id="welcome"><i class="fas fa-circle-check" style="color:var(--g)"></i> Everything is above its reorder level.
  <button class="x" type="button" onclick="this.parentNode.remove()" aria-label="Close">&times;</button></div>
<?php endif; ?>

<div class="sc-head">
  <div><h1>Overview dashboard</h1><p><i class="far fa-calendar"></i> <?php echo date('j F Y', strtotime($mStart)); ?> – <?php echo date('j F Y', strtotime($mEnd)); ?></p></div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form class="search" action="<?php echo stock_url('items.php'); ?>" method="get"><i class="fas fa-magnifying-glass"></i><input class="in" name="q" placeholder="Search items"><kbd>/</kbd></form>
    <form method="get"><input type="hidden" name="flow" value="<?php echo $mode; ?>">
      <select class="in" name="m" onchange="this.form.submit()" style="width:auto"><?php foreach ($months as $k => $lbl): ?><option value="<?php echo $k; ?>" <?php echo $k === $m ? 'selected' : ''; ?>><?php echo $lbl; ?></option><?php endforeach; ?></select></form>
    <a class="btn btn-p" href="<?php echo sh($qs(['export' => 1])); ?>"><i class="fas fa-file-export"></i> Export</a>
  </div>
</div>

<div class="grid g3" style="margin-bottom:18px">
  <?php if ($value): [$c, $t] = $pct($now['value'], $valueStart); ?>
  <div class="kpi"><div class="hd">Stock value <i class="fas fa-ellipsis"></i></div><b><?php echo stock_money($now['value']); ?></b>
    <span class="trend <?php echo $c; ?>"><?php echo $t; ?></span><span class="vs">vs start of month</span></div>
  <?php else: ?>
  <div class="kpi"><div class="hd">Units in stock <i class="fas fa-ellipsis"></i></div><b><?php echo stock_qty($now['units']); ?></b>
    <span class="trend eq"><?php echo number_format($now['items']); ?> items</span><span class="vs">in <?php echo count($stores); ?> store<?php echo count($stores) === 1 ? '' : 's'; ?></span></div>
  <?php endif; [$c, $t] = $pct($thisM['uin'], $lastM['uin']); ?>
  <div class="kpi"><div class="hd">Stock in <i class="fas fa-ellipsis"></i></div><b><?php echo stock_qty($thisM['uin']); ?> <small style="font-size:.9rem;color:var(--mute);font-weight:500">units</small></b>
    <span class="trend <?php echo $c; ?>"><?php echo $t; ?></span><span class="vs">vs last month</span></div>
  <?php [$c, $t] = $pct($thisM['uout'], $lastM['uout']); $c = $c === 'up' ? 'dn' : ($c === 'dn' ? 'up' : $c); ?>
  <div class="kpi"><div class="hd">Stock out <i class="fas fa-ellipsis"></i></div><b><?php echo stock_qty($thisM['uout']); ?> <small style="font-size:.9rem;color:var(--mute);font-weight:500">units</small></b>
    <span class="trend <?php echo $c; ?>"><?php echo $t; ?></span><span class="vs">vs last month</span></div>
</div>

<div class="panel">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
    <h2 style="margin:0">Stock activity summary <i class="fas fa-circle-info i" title="Units moved per day and time of day"></i></h2>
    <div class="seg"><a class="<?php echo $mode === 'in' ? 'on' : ''; ?>" href="<?php echo sh($qs(['flow' => 'in'])); ?>">Inflow</a><a class="<?php echo $mode === 'out' ? 'on' : ''; ?>" href="<?php echo sh($qs(['flow' => 'out'])); ?>">Outflow</a></div>
  </div>
  <div class="legend"><?php foreach ($legend as $i => $lbl): ?><span><i class="l<?php echo $i + 1; ?>"></i><?php echo $lbl; ?> units</span><?php endforeach; ?></div>
  <div class="heat-wrap"><table class="heat">
    <?php foreach ([3 => '18-24h', 2 => '12-18h', 1 => '6-12h', 0 => '0-6h'] as $b => $lbl): ?>
      <tr><th><?php echo $lbl; ?></th><?php for ($d = 1; $d <= $days; $d++): $u = $heat[$b][$d] ?? 0; ?><td><i class="l<?php echo $level($u); ?>" title="<?php echo $d . ' ' . date('M', strtotime($mStart)) . ', ' . $lbl . ': ' . stock_qty($u) . ' units'; ?>"></i></td><?php endfor; ?></tr>
    <?php endforeach; ?>
    <tr class="days"><th></th><?php for ($d = 1; $d <= $days; $d++): ?><td><?php echo $d; ?></td><?php endfor; ?></tr>
  </table></div>
</div>

<div class="grid" style="grid-template-columns:1fr 1.5fr">
  <div class="panel">
    <h2>Stores overview <i class="fas fa-circle-info i" title="<?php echo $value ? 'Share of stock value' : 'Share of units'; ?>"></i></h2>
    <?php $tot = max(0.0001, $value ? $now['value'] : $now['units']); $colors = ['#16a34a', '#f59e0b', '#2563eb', '#db2777', '#0891b2', '#7c3aed'];
    $i = 0; foreach ($stores as $sid => $s): $v = $value ? (float) $sum[$sid]['value'] : (float) $sum[$sid]['units']; ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;font-weight:500"><span><?php echo sh($s['name']); ?></span><span class="mute small"><?php echo round($v / $tot * 100, 1); ?>%</span></div>
        <div class="small mute" style="margin:2px 0 6px"><?php echo $value ? stock_money($v) : stock_qty($v) . ' units'; ?> · <?php echo (int) $sum[$sid]['items']; ?> items</div>
        <div class="bar"><i style="width:<?php echo max(2, $v / $tot * 100); ?>%;background:<?php echo $colors[$i++ % count($colors)]; ?>"></i></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="panel" id="low">
    <h2>Running low <i class="fas fa-circle-info i" title="At or below the reorder level"></i></h2>
    <?php if (!$low): ?><div class="empty"><i class="fas fa-circle-check" style="color:var(--g)"></i> Nothing at or below its reorder level.</div><?php else: ?>
    <div class="wrap-t"><table class="t">
      <thead><tr><th>Item</th><th>Store</th><th class="r">Left</th><th class="r">Reorder at</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($low as $l): $out = (float) $l['quantity'] <= 0; ?>
        <tr><td style="font-weight:500"><?php echo sh($l['name']); ?></td><td class="small mute"><?php echo sh($stores[(int) $l['tenant_id']]['name'] ?? ''); ?></td>
          <td class="r num"><?php echo stock_qty($l['quantity']) . ' ' . sh($l['unit']); ?></td><td class="r num mute"><?php echo (int) $l['low_stock_threshold']; ?></td>
          <td><span class="pill <?php echo $out ? 'p-out' : 'p-cnt'; ?>"><?php echo $out ? 'Out of stock' : 'Low'; ?></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<style>
.seg{display:flex;background:#f3f4f6;border:1px solid var(--line);border-radius:10px;padding:3px;}
.seg a{padding:6px 16px;border-radius:8px;text-decoration:none;color:var(--mute);font-weight:500;font-size:.85rem;}
.seg a.on{background:#fff;color:var(--ink);box-shadow:0 1px 2px rgba(0,0,0,.08);}
.legend{display:flex;gap:18px;flex-wrap:wrap;font-size:.76rem;color:var(--ink2);margin-bottom:12px;}
.legend span{display:flex;align-items:center;gap:6px;} .legend i{width:9px;height:9px;border-radius:2px;display:inline-block;}
.heat-wrap{overflow-x:auto;}
table.heat{border-collapse:separate;border-spacing:5px;width:100%;}
.heat th{font-weight:400;color:var(--mute);font-size:.72rem;text-align:left;white-space:nowrap;padding-right:6px;}
.heat td{padding:0;} .heat td i{display:block;min-width:16px;height:22px;border-radius:5px;}
.heat .days td{text-align:center;color:var(--mute);font-size:.7rem;}
.l0{background:#f3f4f6;} .l1{background:#dcfce7;} .l2{background:#a7f3c0;} .l3{background:#4ade80;} .l4{background:#16a34a;}
.bar{height:12px;background:#f1f3f5;border-radius:4px;overflow:hidden;} .bar i{display:block;height:100%;border-radius:4px;}
@media(max-width:900px){.grid[style*="1.5fr"]{grid-template-columns:1fr!important;}}
</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
