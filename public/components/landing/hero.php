<?php
// Landing hero: headline, email → live demo, and the owner dashboard mock-up with floating cards.
// The mock-ups are built in HTML (no screenshots), styled like the real dashboard.
?>
<section class="hero">
  <div class="hero-bg"><span class="deco d1"></span><span class="deco d2"></span><span class="deco tri"></span></div>
  <div class="wrap">
    <div class="hero-in">
      <div class="kicker">Sell faster · Count stock right · Close the day on time</div>
      <h1>Run your shop faster &amp; smarter with <?php echo $h($brand['name']); ?></h1>
      <p class="sub">One point of sale for shops, salons and electronics stores — cash and M-Pesa, credit sales, stock, staff commissions and printed receipts, on any phone or computer.</p>
      <form class="hero-form" id="heroForm" method="get" action="<?php echo $h($lpUrl); ?>#demo">
        <i class="fas fa-envelope"></i>
        <input type="email" name="email" placeholder="Enter your email to try it" autocomplete="email" required>
        <button class="btn-a btn-amber" type="submit">Try the live demo <i class="fas fa-arrow-right"></i></button>
      </form>
      <div class="hero-note">Already using <?php echo $h($brand['name']); ?>? <a href="<?php echo $h(public_url('admin/')); ?>">Sign in</a></div>
    </div>

    <div class="stage">
      <div class="float f-left">
        <div class="t">On shift today <span style="color:var(--muted);font-weight:500">4</span></div>
        <?php foreach ([['Wanjiru', '#1f9a5f', 'in', '07:52'], ['Brian', '#2d6cdf', 'in', '08:05'], ['Achieng', '#e2566b', 'in', '08:11'], ['Musa', '#7a5af0', 'out', '—']] as [$n, $c, $s, $t]): ?>
          <div class="staff-row"><span class="av" style="background:<?php echo $c; ?>"><?php echo $n[0]; ?></span><span><?php echo $n; ?><br><small style="color:var(--muted)"><?php echo $t; ?></small></span>
            <span class="pill <?php echo $s; ?>"><?php echo $s === 'in' ? 'Clocked in' : 'Off'; ?></span></div>
        <?php endforeach; ?>
      </div>
      <div class="float f-right">
        <div class="t" style="justify-content:center">Today's target</div>
        <div class="ring">
          <svg viewBox="0 0 36 36" width="84" height="84"><circle cx="18" cy="18" r="15.5" fill="none" stroke="#e6f4ec" stroke-width="4"/>
            <circle cx="18" cy="18" r="15.5" fill="none" stroke="#1f9a5f" stroke-width="4" stroke-dasharray="70 100" stroke-linecap="round" transform="rotate(-90 18 18)"/>
            <circle cx="18" cy="18" r="15.5" fill="none" stroke="#f4a62a" stroke-width="4" stroke-dasharray="12 100" stroke-dashoffset="-72" stroke-linecap="round" transform="rotate(-90 18 18)"/></svg>
          <b>72%</b>
        </div>
        <small style="color:var(--muted)">KES 36,140 of 50,000</small>
      </div>
      <div class="float f-cal">
        <div class="t">Low stock <span class="pill out">3 items</span></div>
        <div class="rows">
          <div class="r"><span>Cooking oil 1L</span><b style="color:var(--rose)">4 left</b></div>
          <div class="r"><span>Sugar 1kg</span><b style="color:var(--rose)">6 left</b></div>
          <div class="r"><span>Bar soap 800g</span><b style="color:#a35f00">9 left</b></div>
        </div>
      </div>

      <div class="mock">
        <div class="m-top"><span class="dots"><i></i><i></i><i></i></span><b>Dashboard</b><span style="margin-left:auto;color:var(--muted)">Mon, 19 Oct · Main shop</span></div>
        <div class="app">
          <div class="app-side">
            <div class="lg"><span>D</span>Dazu POS</div>
            <a class="on"><i class="fas fa-gauge-high"></i>Dashboard</a>
            <a><i class="fas fa-cash-register"></i>New sale</a>
            <a><i class="fas fa-boxes-stacked"></i>Inventory</a>
            <a><i class="fas fa-hand-holding-dollar"></i>Credit book</a>
            <a><i class="fas fa-users"></i>Customers</a>
            <small>Manage</small>
            <a><i class="fas fa-chart-line"></i>Reports</a>
            <a><i class="fas fa-user-group"></i>Staff</a>
            <a><i class="fas fa-print"></i>Printer</a>
          </div>
          <div class="app-main">
            <h4>Good morning, Grace</h4>
            <div class="sub">Here's how the shop is doing today.</div>
            <div class="kpis">
              <div class="kpi"><small>Sales today</small><b>KES 36,140</b><em><i class="fas fa-arrow-up"></i> 12% vs Mon</em></div>
              <div class="kpi"><small>Receipts</small><b>148</b><em>avg KES 244</em></div>
              <div class="kpi"><small>Owed to you</small><b>KES 8,450</b><em class="dn">5 customers</em></div>
            </div>
            <div class="panels">
              <div class="panel">
                <div class="ph">Sales this week <span>Cash · M-Pesa</span></div>
                <svg class="chart-svg" viewBox="0 0 300 120" preserveAspectRatio="none" aria-hidden="true">
                  <g stroke="#eef2ef"><line x1="0" y1="30" x2="300" y2="30"/><line x1="0" y1="60" x2="300" y2="60"/><line x1="0" y1="90" x2="300" y2="90"/></g>
                  <path d="M0,85 C25,80 35,40 60,48 S95,95 120,78 S160,30 185,40 S225,80 250,52 S285,22 300,26" fill="none" stroke="#1f9a5f" stroke-width="3"/>
                  <path d="M0,100 C30,98 40,80 60,84 S100,104 120,96 S160,70 185,76 S225,96 250,86 S285,70 300,72" fill="none" stroke="#f4a62a" stroke-width="2.5" stroke-dasharray="5 4"/>
                  <circle cx="185" cy="40" r="4.5" fill="#fff" stroke="#1f9a5f" stroke-width="2.5"/>
                </svg>
                <div style="display:flex;justify-content:space-between;color:var(--muted);font-size:10px"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
              </div>
              <div class="panel">
                <div class="ph">Top sellers <span>today</span></div>
                <div class="rows">
                  <?php foreach ([['Maize flour 2kg', 46, 92], ['Soda 500ml', 38, 76], ['Bread 400g', 31, 62], ['Milk 500ml', 24, 48]] as [$n, $q, $w]): ?>
                    <div class="r" style="display:block"><div style="display:flex;justify-content:space-between"><span><?php echo $n; ?></span><b><?php echo $q; ?></b></div><div class="bar-h"><i style="width:<?php echo $w; ?>%"></i></div></div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
