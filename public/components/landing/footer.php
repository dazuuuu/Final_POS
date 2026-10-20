<?php
// Landing footer: closing call to action and links.
?>
<div class="wrap" style="margin-top:90px">
  <div class="cta">
    <div><h2>See it working in your own demo shop</h2><p>Five minutes is enough to make a sale, print a receipt and read the report.</p></div>
    <a class="btn-a btn-amber" href="#demo">Start the live demo <i class="fas fa-arrow-right"></i></a>
  </div>
</div>
<footer class="lp-foot">
  <div class="wrap">
    <div class="cols">
      <div>
        <a class="brand" href="<?php echo $h($lpUrl); ?>"><img src="<?php echo $h($brand['logo']); ?>" alt=""><span><?php echo $h($brand['name']); ?></span></a>
        <p style="max-width:300px">Point of sale for shops, salons and electronics stores — sales, stock, credit, staff and receipts in one place.</p>
      </div>
      <div><h4>Product</h4><a href="#features">Features</a><a href="#tour">How it looks</a><a href="#packages">Packages</a><a href="#demo">Live demo</a></div>
      <div><h4>Sign in</h4><a href="<?php echo $h(public_url('admin/')); ?>">Owner / admin</a><a href="<?php echo $h(public_url('terminal/')); ?>">Staff terminal</a>
        <?php if (Signup::enabled()): ?><a href="<?php echo $h(Signup::signupUrl()); ?>">Create your account</a><?php endif; ?></div>
      <div><h4>Talk to us</h4>
        <?php if ($brand['phone'] !== ''): ?><a href="tel:<?php echo $h(preg_replace('/[^0-9+]/', '', $brand['phone'])); ?>"><i class="fas fa-phone me-1"></i> <?php echo $h($brand['phone']); ?></a><?php endif; ?>
        <?php if ($brand['whatsapp'] !== ''): ?><a href="https://wa.me/<?php echo $h(preg_replace('/\D/', '', $brand['whatsapp'])); ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp me-1"></i> WhatsApp</a><?php endif; ?>
        <?php if ($brand['email'] !== ''): ?><a href="mailto:<?php echo $h($brand['email']); ?>"><i class="fas fa-envelope me-1"></i> <?php echo $h($brand['email']); ?></a><?php endif; ?>
        <a href="#start">Request a quotation</a></div>
    </div>
    <div class="bottom"><span>© <?php echo date('Y'); ?> <?php echo $h($brand['name']); ?>. All rights reserved.</span><span>Made in Kenya</span></div>
  </div>
</footer>
