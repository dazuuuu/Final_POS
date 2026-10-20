<?php
// Landing: the packages we offer (Features::catalog()). Each card: try it in the demo, or get it.
?>
<section class="sec band" id="packages">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">Packages</span>
      <h2>Pick the package that fits your business</h2>
      <p class="lead">Pay once for the licence. Start on Basic and move up any time — your data comes with you.</p>
    </div>
    <div class="pk-grid">
      <?php foreach ($catalog as $k => [$label, $price, $desc, $icon]):
          $soon = !isset($demoPackages[$k]); ?>
        <div class="pk <?php echo $k === Features::SHOP_POS ? 'hot' : ''; ?> <?php echo $soon ? 'soon' : ''; ?>">
          <div class="pk-ic"><i class="fas <?php echo $h($icon); ?>"></i></div>
          <span class="tier"><?php echo $h($tierOf($desc)); ?></span>
          <h3><?php echo $h($label); ?></h3>
          <div class="price">KES <?php echo number_format($price); ?> <small>/ licence</small></div>
          <ul>
            <?php foreach ($bullets($desc) as $b): ?><li><i class="fas fa-check"></i><?php echo $h($b); ?></li><?php endforeach; ?>
          </ul>
          <div class="acts">
            <?php if ($soon): ?>
              <span class="btn-a btn-ghost" style="cursor:default">Demo coming soon</span>
            <?php else: ?>
              <a class="btn-a btn-green" href="?pkg=<?php echo $h($k); ?>#demo" data-pick="<?php echo $h($k); ?>" onclick="location.hash='demo';return false;"><i class="fas fa-play"></i> Try it free</a>
            <?php endif; ?>
            <a class="btn-a btn-ghost" href="?pkg=<?php echo $h($k); ?>#start" data-pick="<?php echo $h($k); ?>" onclick="location.hash='start';return false;">Get this package</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
