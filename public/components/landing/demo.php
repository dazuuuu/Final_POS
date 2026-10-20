<?php
// Landing: live demo. Email + package → a private practice shop (Demo::start). Demo::MAX_PER_EMAIL per email.
$demoPick = isset($demoPackages[$selected]) ? $selected : array_key_first($demoPackages);
if (!empty($demoDone['email']) && empty($_GET['pkg'])) {
    $st = Database::pdo()->prepare('SELECT package_key FROM demo_sessions WHERE email = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$demoDone['email']]);
    $last = (string) $st->fetchColumn();
    if (isset($demoPackages[$last])) { $demoPick = $last; }
}
$isDemoFlash = $flash && ($flash[2] ?? '') === 'demo';
?>
<section class="sec" id="demo">
  <div class="wrap">
    <div class="demo-card">
      <div>
        <span class="eyebrow"><i class="fas fa-flask"></i> Live demo</span>
        <h2>Try the real POS — no card, no install</h2>
        <p class="lead">We open a private practice shop just for you, already stocked. Make sales, add stock, give credit, print a receipt. It's deleted when you finish.</p>
        <ol class="steps">
          <li><span>Enter your email and choose a package.</span></li>
          <li><span>You're signed in as the owner of your own demo shop for <?php echo Demo::MINUTES; ?> minutes.</span></li>
          <li><span>Click <b>End demo</b> (or sign out) when you're done. Each email gets <?php echo Demo::MAX_PER_EMAIL; ?> demos.</span></li>
        </ol>
      </div>
      <div>
        <?php if (($_GET['demo'] ?? '') !== '' && $demoDone): $left = (int) $demoDone['left']; ?>
          <div class="alert-l <?php echo $left ? 'ok' : 'info'; ?>"><i class="fas <?php echo $left ? 'fa-circle-check' : 'fa-circle-info'; ?> mt-1"></i><div>
            <b><?php echo ($_GET['demo'] ?? '') === 'ended' ? 'Your demo time is up.' : 'Your demo has ended.'; ?></b>
            <?php if ($left): ?> You have <b><?php echo $left; ?></b> demo<?php echo $left === 1 ? '' : 's'; ?> left on <?php echo $h($demoDone['email']); ?><?php echo $left === 1 ? ' — this is your last one' : ''; ?>.
            <?php else: ?> That was your last demo on <?php echo $h($demoDone['email']); ?>. Ready for the real thing? <a href="#start">Get your package</a>.<?php endif; ?>
          </div></div>
        <?php endif; ?>
        <?php if ($isDemoFlash): ?><div class="alert-l <?php echo $flash[0]; ?>"><i class="fas fa-circle-exclamation mt-1"></i><div><?php echo $h($flash[1]); ?></div></div><?php endif; ?>
        <form method="post" action="<?php echo $h($lpUrl); ?>">
          <?php echo csrf_field(); ?><input type="hidden" name="lp" value="demo">
          <label class="f-label" for="demoEmail">Your email</label>
          <input class="f-input mb" id="demoEmail" type="email" name="email" value="<?php echo $h($demoEmail); ?>" placeholder="you@example.com" autocomplete="email" required>
          <span class="f-label">Package to try</span>
          <div class="pick">
            <?php foreach ($demoPackages as $k => [$label, , , $icon]): ?>
              <label><input type="radio" name="package" value="<?php echo $h($k); ?>" <?php echo $k === $demoPick ? 'checked' : ''; ?>><i class="fas <?php echo $h($icon); ?>"></i><?php echo $h($label); ?></label>
            <?php endforeach; ?>
          </div>
          <button class="btn-a btn-amber" style="width:100%"><i class="fas fa-play"></i> Start my demo</button>
          <p class="note"><i class="fas fa-lock me-1"></i> Your email is only used to count your demos and, if you ask, to send a quote. Emails from inside the demo are not sent.</p>
        </form>
      </div>
    </div>
  </div>
</section>
