<?php
// Landing header: brand, section links and "Sign in" (owner login or the staff PIN terminal).
?>
<header class="lp-head">
  <div class="wrap">
    <a class="brand" href="<?php echo $h($lpUrl); ?>"><img src="<?php echo $h($brand['logo']); ?>" alt=""><span class="nm"><?php echo $h($brand['name']); ?></span></a>
    <ul class="nav">
      <li><a href="#features">Features</a></li>
      <li><a href="#tour">How it looks</a></li>
      <li><a href="#packages">Packages</a></li>
      <li><a href="#demo">Live demo</a></li>
      <li><a href="#start">Get started</a></li>
    </ul>
    <div class="signin">
      <button type="button" aria-haspopup="true"><i class="fas fa-right-to-bracket"></i> Sign in <i class="fas fa-chevron-down" style="font-size:.7rem"></i></button>
      <div class="signin-menu" role="menu">
        <a href="<?php echo $h(public_url('admin/')); ?>" role="menuitem">
          <i class="fas fa-user-tie" style="background:var(--g-100);color:var(--g-700)"></i>
          <span><b>Owner / admin</b><small>Dashboard, stock, reports and settings — sign in with your email.</small></span></a>
        <a href="<?php echo $h(public_url('terminal/')); ?>" role="menuitem">
          <i class="fas fa-delete-left" style="background:#fff3e0;color:#a35f00"></i>
          <span><b>Staff terminal</b><small>Clock in and sell with your PIN.</small></span></a>
      </div>
    </div>
    <button class="menu-btn" type="button" aria-label="Menu"><i class="fas fa-bars"></i></button>
  </div>
</header>
