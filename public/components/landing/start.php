<?php
// Landing: three ways to get the selected package, and the quotation form (Quote::submit).
$isStartFlash = $flash && ($flash[2] ?? '') === 'start';
$qPkg = isset($catalog[$old['package'] ?? '']) ? $old['package'] : $selected;
[$qLabel, $qPrice, $qDesc] = $catalog[$qPkg];
$wa = preg_replace('/\D/', '', $brand['whatsapp']);
$tel = preg_replace('/[^0-9+]/', '', $brand['phone']);
$talk = $wa !== '' ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Hello, I am interested in ' . $qLabel . ' for my shop.')
      : ($tel !== '' ? 'tel:' . $tel : ($brand['email'] !== '' ? 'mailto:' . $brand['email'] : '#quoteForm'));
$signup = Signup::enabled();
?>
<section class="sec band" id="start">
  <div class="wrap">
    <div class="sec-head">
      <span class="eyebrow">Get started</span>
      <h2>Ready for <span data-get-label><?php echo $h($qLabel); ?></span>?</h2>
      <p class="lead">Choose how you'd like to go ahead. We set up your shop, bring in your old data if you have it, and show your team how to use it.</p>
    </div>

    <div class="opts">
      <a class="opt on" href="#quoteForm" data-open-quote>
        <i class="fas fa-file-signature" style="background:var(--g-100);color:var(--g-700)"></i>
        <b>Request a quotation</b><span>Tell us about your shop and we'll call you with a quote. Takes a minute.</span>
      </a>
      <a class="opt" href="<?php echo $h($talk); ?>" <?php echo $wa !== '' ? 'target="_blank" rel="noopener"' : ''; ?>>
        <i class="fab fa-whatsapp" style="background:#e9f7ee;color:#127a3c"></i>
        <b>Talk to us now</b><span><?php echo $wa !== '' ? 'Chat on WhatsApp' : ($tel !== '' ? 'Call ' . $h($brand['phone']) : 'Ask us anything'); ?> — we'll answer your questions and book a setup visit.</span>
      </a>
      <?php if ($signup): ?>
      <a class="opt" href="<?php echo $h(Signup::signupUrl()); ?>">
        <i class="fas fa-store" style="background:#fff3e0;color:#a35f00"></i>
        <b>Create your account</b><span>Already got your business name from us? Set up your owner login and go.</span>
      </a>
      <?php else: ?>
      <a class="opt" href="#demo">
        <i class="fas fa-play" style="background:#fff3e0;color:#a35f00"></i>
        <b>Try it once more</b><span>Not sure yet? Open another demo shop and try the parts you missed.</span>
      </a>
      <?php endif; ?>
    </div>

    <div class="quote" id="quoteForm">
      <div>
        <h3 style="font-size:1.35rem;font-weight:800;margin-bottom:8px">Request a quotation</h3>
        <p class="lead" style="font-size:.95rem">We'll email you to confirm we got it, then call you to talk through your shop.</p>
        <div class="pkg-desc">
          <span class="tier" style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Selected package</span>
          <b id="qName"><?php echo $h($qLabel); ?></b>
          <div class="p" id="qPrice">KES <?php echo number_format($qPrice); ?></div>
          <p id="qDesc"><?php echo $h(trim(preg_replace('/\s*\([^)]*\)/', '', $qDesc))); ?></p>
        </div>
      </div>
      <div>
        <?php if ($isStartFlash): ?><div class="alert-l <?php echo $flash[0]; ?>"><i class="fas <?php echo $flash[0] === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> mt-1"></i><div><?php echo $h($flash[1]); ?></div></div><?php endif; ?>
        <form method="post" action="<?php echo $h($lpUrl); ?>">
          <?php echo csrf_field(); ?><input type="hidden" name="lp" value="quote">
          <div class="grid2 mb">
            <div><label class="f-label">Your name</label><input class="f-input" name="name" value="<?php echo $h($old['name'] ?? ''); ?>" maxlength="120" required autocomplete="name"></div>
            <div><label class="f-label">Email</label><input class="f-input" type="email" name="email" value="<?php echo $h($old['email'] ?? $demoEmail); ?>" maxlength="150" required autocomplete="email"></div>
          </div>
          <div class="grid2 mb">
            <div><label class="f-label">Phone <span class="opt-tag">(optional)</span></label><input class="f-input" type="tel" name="phone" value="<?php echo $h($old['phone'] ?? ''); ?>" placeholder="0712 345 678" autocomplete="tel"></div>
            <div><label class="f-label">Package</label>
              <select class="f-select" name="package" id="qPackage">
                <?php foreach ($catalog as $k => [$label]): ?><option value="<?php echo $h($k); ?>" <?php echo $k === $qPkg ? 'selected' : ''; ?>><?php echo $h($label); ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <div class="mb"><label class="f-label">Shop name <span class="opt-tag">(optional)</span></label><input class="f-input" name="shop_name" value="<?php echo $h($old['shop_name'] ?? ''); ?>" maxlength="150" placeholder="e.g. Mama Njeri Supermarket"></div>
          <div class="mb"><label class="f-label">What does your business sell or do? <span class="opt-tag">(optional)</span></label>
            <textarea class="f-area" name="business" maxlength="500" placeholder="e.g. Groceries and household goods, 2 branches, 5 staff"><?php echo $h($old['business'] ?? ''); ?></textarea></div>
          <button class="btn-a btn-green" style="width:100%"><i class="fas fa-paper-plane"></i> Send my request</button>
        </form>
      </div>
    </div>
  </div>
</section>
