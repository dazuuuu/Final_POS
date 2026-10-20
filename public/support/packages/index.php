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
// One line under each package name (the reference card's "For early-stage founders").
$taglines = [
    Features::SHOP_BASIC     => 'For a single small shop',
    Features::SERVICES_BASIC => 'For a small salon or barber',
    Features::SHOP_POS       => 'For growing shops and branches',
    Features::RESTAURANT_POS => 'For restaurants and bars',
    Features::RESTAURANT_ROOMS => 'For hotels with a restaurant',
    Features::SERVICES       => 'For salons, spas and garages',
    Features::ELECTRONICS    => 'For phone and electronics shops',
];
$counts = array_map('count', $holders);
$popular = max($counts) > 0 ? array_search(max($counts), $counts, true) : null;

$page_title = 'Packages';
$nav = 'packages';
ob_start();
?>
<style>
/* Pricing-card look: soft grey cards, dark icon tile, big figure, black pill button, badge checks. */
.pkx{position:relative;background:#f7f7f7;border-radius:22px;padding:26px 24px 24px;height:100%;display:flex;flex-direction:column;
  box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 10px 30px -12px rgba(0,0,0,.14);}
.pkx-ic{width:44px;height:44px;border-radius:10px;background:#262626;color:#fff;display:grid;place-items:center;font-size:1rem;
  box-shadow:0 6px 14px -6px rgba(0,0,0,.5);}
.pkx h3{font-size:1.45rem;font-weight:500;letter-spacing:-.02em;margin:16px 0 4px;color:#111;}
.pkx .tag{color:#3f3f46;font-size:.92rem;}
.pkx .fig{display:flex;align-items:baseline;gap:10px;margin:14px 0 18px;}
.pkx .fig b{font-size:2.3rem;font-weight:500;letter-spacing:-.04em;color:#111;line-height:1;}
.pkx .fig span{color:#71717a;font-size:.95rem;}
.pkx .go{display:block;text-align:center;background:linear-gradient(#2b2b2b,#141414);color:#fff;border-radius:12px;padding:13px;font-weight:500;
  text-decoration:none;box-shadow:0 10px 18px -8px rgba(0,0,0,.55),0 1px 0 rgba(255,255,255,.12) inset;}
.pkx .go:hover{color:#fff;filter:brightness(1.15);}
.pkx ul{list-style:none;padding:0;margin:20px 0 0;}
.pkx li{display:flex;gap:10px;align-items:flex-start;font-size:.93rem;color:#18181b;margin-bottom:12px;}
.pkx li i{color:#18181b;font-size:1rem;margin-top:2px;}
.pkx.hot li i{color:#4f46e5;}
.pkx-pop{position:absolute;top:-12px;right:-6px;background:#4f46e5;color:#fff;font-size:.82rem;font-weight:500;border-radius:8px;padding:4px 12px;
  transform:rotate(9deg);box-shadow:0 8px 18px -6px rgba(79,70,229,.7);}
.pkx .who{margin-top:auto;padding-top:14px;border-top:1px solid #e9e9e9;}
.pkx .who a{display:flex;justify-content:space-between;align-items:center;padding:6px 0;text-decoration:none;color:#18181b;font-size:.84rem;}
.pkx .who small{color:#a1a1aa;}
.pkx-sum{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:22px;}
.pkx-sum div{background:#f7f7f7;border-radius:14px;padding:12px 18px;min-width:150px;}
.pkx-sum small{display:block;color:#71717a;font-size:.78rem;}
.pkx-sum b{font-size:1.4rem;font-weight:500;}
</style>

<div class="pkx-sum">
  <div><small>Packages</small><b><?php echo count(Features::catalog()); ?></b></div>
  <div><small>Licences issued</small><b><?php echo array_sum($counts); ?></b></div>
  <div><small>Accounts</small><b><?php echo count($accounts); ?></b></div>
</div>

<div class="row g-4">
<?php foreach (Features::catalog() as $k => [$label, $price, $desc, $icon]):
    $n = $counts[$k];
    $body = trim(preg_replace('/\([^)]*\)/', '', $desc));
    $features = [];
    if (stripos($body, 'Everything in the') === 0) { $features[] = preg_match('/Everything in the ([^,]+?)(,| plus)/i', $body, $em) ? 'Everything in ' . trim($em[1]) : 'Everything in Shop POS'; }
    $list = strpos($body, ':') !== false ? substr($body, strrpos($body, ':') + 1) : $body;
    $list = preg_split('/\.\s/', $list)[0];
    foreach (preg_split('/,|\band\b/', rtrim($list, '. ')) as $f) {
        $f = trim($f);
        $f = preg_replace('/^plus\s+/i', '', $f);
        if ($f !== '' && stripos($f, 'Everything in') !== 0) { $features[] = ucfirst($f); }
    }
    $features = array_slice(array_unique($features), 0, 7); ?>
  <div class="col-md-6 col-xl-4">
    <div class="pkx <?php echo $k === $popular ? 'hot' : ''; ?>">
      <?php if ($k === $popular): ?><span class="pkx-pop">Popular</span><?php endif; ?>
      <div class="pkx-ic"><i class="fas <?php echo htmlspecialchars($icon); ?>"></i></div>
      <h3><?php echo htmlspecialchars($label); ?></h3>
      <div class="tag"><?php echo htmlspecialchars($taglines[$k] ?? 'Package'); ?></div>
      <div class="fig"><b><?php echo $n; ?></b><span>/ account<?php echo $n === 1 ? '' : 's'; ?> · <?php echo round($n / $total * 100); ?>%</span></div>
      <a class="go" href="<?php echo public_url('support/accounts/' . ($n ? '?q=' . urlencode($label) : '')); ?>"><?php echo $n ? 'View accounts' : 'Add to an account'; ?></a>
      <ul>
        <?php foreach ($features as $f): ?><li><i class="fas fa-circle-check"></i><span><?php echo htmlspecialchars($f); ?></span></li><?php endforeach; ?>
      </ul>
      <?php if ($n): ?>
        <div class="who">
          <?php foreach (array_slice($holders[$k], 0, 4) as $a): ?>
            <a href="<?php echo public_url('support/accounts/view.php?id=' . (int) $a['id']); ?>"><span><?php echo htmlspecialchars($a['name']); ?> <small><?php echo htmlspecialchars($a['owner_email'] ?? ''); ?></small></span><i class="fas fa-chevron-right small text-muted"></i></a>
          <?php endforeach; ?>
          <?php if ($n > 4): ?><a href="<?php echo public_url('support/accounts/?q=' . urlencode($label)); ?>"><span class="text-muted">+ <?php echo $n - 4; ?> more</span></a><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
