<?php
// public/support/packages/index.php — the package catalog and which accounts hold each.
require_once __DIR__ . '/../_boot.php';

$pdo = Database::pdo();
$accounts = support_accounts($pdo);
$holders = array_fill_keys(array_keys(Features::catalog()), []);
foreach ($accounts as $a) {
    foreach (Features::forAccount((int) $a['id']) as $k => $on) {
        if ($on) $holders[$k][] = $a;
    }
}
$total = max(1, count($accounts));
// Per-package brand colours [from, to].
$themes = [
    Features::SHOP_BASIC     => ['#64748b', '#334155', 'Basic'],
    Features::SERVICES_BASIC => ['#a78bfa', '#6d28d9', 'Basic'],
    Features::SHOP_POS       => ['#2563eb', '#4f46e5', 'Core'],
    Features::RESTAURANT_POS => ['#f97316', '#e11d48', 'Hospitality'],
    Features::ELECTRONICS    => ['#0891b2', '#0d9488', 'Add-on'],
    Features::SERVICES       => ['#7c3aed', '#c026d3', 'Add-on'],
];

$page_title = 'Packages';
$nav = 'packages';
ob_start();
?>
<style>
.pk{position:relative;background:#fff;border:1px solid #e6e9f0;border-radius:22px;overflow:hidden;height:100%;display:flex;flex-direction:column;
  box-shadow:0 1px 2px rgba(15,23,42,.04);transition:transform .2s,box-shadow .2s;}
.pk:hover{transform:translateY(-3px);box-shadow:0 16px 36px rgba(15,23,42,.10);}
.pk-hero{position:relative;padding:20px 20px 18px;color:#fff;background:linear-gradient(135deg,var(--a),var(--b));overflow:hidden;}
.pk-hero::before,.pk-hero::after{content:"";position:absolute;border-radius:50%;background:rgba(255,255,255,.12);}
.pk-hero::before{width:180px;height:180px;right:-50px;top:-70px;}
.pk-hero::after{width:110px;height:110px;right:60px;bottom:-60px;background:rgba(255,255,255,.08);}
.pk-ic{position:relative;width:48px;height:48px;border-radius:15px;background:rgba(255,255,255,.2);display:grid;place-items:center;font-size:1.3rem;
  border:1px solid rgba(255,255,255,.35);backdrop-filter:blur(4px);}
.pk-tier{position:relative;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;background:rgba(255,255,255,.22);
  padding:3px 9px;border-radius:999px;}
.pk-name{position:relative;font-size:1.2rem;font-weight:800;letter-spacing:-.02em;margin:14px 0 2px;}
.pk-price{position:relative;display:flex;align-items:baseline;gap:6px;}
.pk-price b{font-size:1.9rem;font-weight:800;letter-spacing:-.03em;}
.pk-price span{opacity:.85;font-size:.8rem;}
.pk-body{padding:16px 20px 20px;flex:1;display:flex;flex-direction:column;}
.pk-feat{list-style:none;padding:0;margin:0 0 16px;display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;}
.pk-feat li{font-size:.8rem;color:#334155;display:flex;gap:7px;align-items:flex-start;}
.pk-feat li.all{grid-column:1/-1;font-weight:700;color:#0f172a;}
.pk-feat li i{color:var(--a);margin-top:3px;font-size:.7rem;}
.pk-meter{margin-top:auto;}
.pk-meter .bar{height:8px;border-radius:99px;background:#eef1f6;overflow:hidden;}
.pk-meter .bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--a),var(--b));}
.pk-meter .lbl{display:flex;justify-content:space-between;font-size:.74rem;color:#64748b;font-weight:600;margin-bottom:6px;}
.pk-holders{display:flex;align-items:center;justify-content:space-between;margin-top:14px;gap:10px;}
.av-stack{display:flex;}
.av-stack a,.av-stack span{width:32px;height:32px;border-radius:50%;border:2px solid #fff;margin-left:-8px;display:grid;place-items:center;
  font-size:.72rem;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--a),var(--b));text-decoration:none;}
.av-stack :first-child{margin-left:0;}
.av-stack span.more{background:#e2e8f0;color:#475569;}
.pk-list{border-top:1px dashed #e2e8f0;margin-top:14px;padding-top:10px;}
.pk-list a{display:flex;justify-content:space-between;align-items:center;padding:7px 0;text-decoration:none;color:#0f172a;font-size:.84rem;}
.pk-list a small{color:#94a3b8;}
.pk-sum{display:flex;gap:10px;overflow-x:auto;margin-bottom:16px;padding-bottom:2px;}
.pk-sum .s-card{flex:1;min-width:150px;padding:14px 16px;}
</style>

<?php
$value = 0;
foreach (Features::catalog() as $k => [, $price]) { $value += $price * count($holders[$k]); }
?>
<div class="pk-sum">
  <div class="s-card s-stat"><div class="l">Packages</div><div class="n"><?php echo count(Features::catalog()); ?></div></div>
  <div class="s-card s-stat"><div class="l">Licences issued</div><div class="n"><?php echo array_sum(array_map('count', $holders)); ?></div></div>
  <div class="s-card s-stat"><div class="l">Licensed value</div><div class="n">KES <?php echo number_format($value); ?></div></div>
</div>

<div class="row g-3">
<?php foreach (Features::catalog() as $k => [$label, $price, $desc, $icon]):
    [$from, $to, $tier] = $themes[$k] ?? ['#334155', '#0f172a', 'Package'];
    $n = count($holders[$k]);
    $pct = (int) round($n / $total * 100);
    // "Prefix: a, b and c. (Note.)" -> bullet list; a parenthetical note becomes the tier label.
    if (preg_match('/\(([^)]*)\)/', $desc, $note)) { $tier = rtrim($note[1], '.'); }
    $body = trim(preg_replace('/\([^)]*\)/', '', $desc));
    $features = [];
    if (stripos($body, 'Everything in the Shop POS') === 0) { $features[] = 'Everything in Shop POS'; }
    $list = strpos($body, ':') !== false ? substr($body, strrpos($body, ':') + 1) : $body;
    foreach (preg_split('/,|\band\b/', rtrim($list, '. ')) as $f) {
        $f = trim($f);
        if ($f !== '') { $features[] = ucfirst($f); }
    }
    $features = array_slice($features, 0, 10); ?>
  <div class="col-md-6 col-xl-3">
    <div class="pk" style="--a:<?php echo $from; ?>;--b:<?php echo $to; ?>;">
      <div class="pk-hero">
        <div class="d-flex justify-content-between align-items-start">
          <div class="pk-ic"><i class="fas <?php echo $icon; ?>"></i></div>
          <span class="pk-tier"><?php echo $tier; ?></span>
        </div>
        <div class="pk-name"><?php echo htmlspecialchars($label); ?></div>
        <div class="pk-price"><b>KES <?php echo number_format($price); ?></b><span>/ licence</span></div>
      </div>
      <div class="pk-body">
        <ul class="pk-feat">
          <?php foreach ($features as $f): ?><li<?php echo $f === 'Everything in Shop POS' ? ' class="all"' : ''; ?>><i class="fas fa-circle-check"></i><?php echo htmlspecialchars($f); ?></li><?php endforeach; ?>
        </ul>
        <div class="pk-meter">
          <div class="lbl"><span>Adoption</span><span><?php echo $pct; ?>% of accounts</span></div>
          <div class="bar"><i style="width:<?php echo max($pct, $n ? 4 : 0); ?>%"></i></div>
        </div>
        <div class="pk-holders">
          <div class="av-stack">
            <?php foreach (array_slice($holders[$k], 0, 4) as $a): ?>
              <a href="<?php echo public_url('support/accounts/view.php?id=' . (int) $a['id']); ?>" title="<?php echo htmlspecialchars($a['name']); ?>"><?php echo htmlspecialchars(strtoupper(mb_substr($a['name'], 0, 1))); ?></a>
            <?php endforeach; ?>
            <?php if ($n > 4): ?><span class="more">+<?php echo $n - 4; ?></span><?php endif; ?>
            <?php if (!$n): ?><span class="more"><i class="fas fa-minus"></i></span><?php endif; ?>
          </div>
          <strong class="small"><?php echo $n; ?> account<?php echo $n === 1 ? '' : 's'; ?></strong>
        </div>
        <?php if ($n): ?>
          <div class="pk-list">
            <?php foreach (array_slice($holders[$k], 0, 5) as $a): ?>
              <a href="<?php echo public_url('support/accounts/view.php?id=' . (int) $a['id']); ?>">
                <span><?php echo htmlspecialchars($a['name']); ?><br><small><?php echo htmlspecialchars($a['owner_email'] ?? ''); ?></small></span>
                <i class="fas fa-chevron-right text-muted small"></i>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
