<?php
// Landing: how the POS looks and behaves — reports, the till with its receipt, the owner's phone.
?>
<section class="sec" id="tour" style="padding-top:20px">
  <div class="wrap">

    <!-- Reports -->
    <div class="split">
      <div class="split-text">
        <div class="badge-ic" style="background:#fdecee;color:var(--rose)"><i class="fas fa-chart-column"></i></div>
        <h2>See your numbers at a glance</h2>
        <p class="lead">Know what sold, what made money and who owes you — today, this week or any dates you pick. Download any report to Excel in one tap.</p>
        <ul class="ticks">
          <li><i class="fas fa-check"></i><b>Profit, not just sales</b><span>Buying price against selling price on every item, so you see what each sale really earned.</span></li>
          <li><i class="fas fa-check"></i><b>Every branch in one view</b><span>Compare shops side by side, or open one and see only its figures.</span></li>
        </ul>
      </div>
      <div class="visual">
        <span class="spark-dot" style="top:8%;left:16%"></span><span class="spark-dot" style="bottom:12%;right:12%;background:var(--amber)"></span>
        <div class="mock" style="max-width:420px;margin-left:auto">
          <div class="m-top"><b>Sales report</b><span style="margin-left:auto;color:var(--muted)">1 – 19 Oct</span></div>
          <div style="padding:14px">
            <div class="kpis" style="grid-template-columns:repeat(3,1fr)">
              <div class="kpi"><small>Sales</small><b>612,480</b><em><i class="fas fa-arrow-up"></i> 9%</em></div>
              <div class="kpi"><small>Profit</small><b>141,905</b><em><i class="fas fa-arrow-up"></i> 6%</em></div>
              <div class="kpi"><small>Credit</small><b>23,300</b><em class="dn">11 open</em></div>
            </div>
            <div class="panel">
              <div class="ph">Sales by day <span>KES</span></div>
              <svg viewBox="0 0 300 110" style="width:100%;height:110px" aria-hidden="true">
                <?php $bars = [42, 55, 48, 62, 70, 88, 64, 51, 58, 66, 74, 92, 69, 57, 61, 72, 80, 96, 71];
                foreach ($bars as $i => $v): $x = 4 + $i * 15.6; ?>
                  <rect x="<?php echo $x; ?>" y="<?php echo 104 - $v; ?>" width="10" height="<?php echo $v; ?>" rx="2.5" fill="<?php echo $v >= 88 ? '#0c3d26' : ($i > 12 ? '#1f9a5f' : '#bfe3cf'); ?>"/>
                <?php endforeach; ?>
              </svg>
            </div>
          </div>
        </div>
        <div class="float" style="display:block;position:absolute;left:0;bottom:-6px;width:190px">
          <div class="t">Payments <span style="color:var(--muted);font-weight:500">today</span></div>
          <div class="rows">
            <div class="r" style="display:block"><div style="display:flex;justify-content:space-between"><span>M-Pesa</span><b>58%</b></div><div class="bar-h"><i style="width:58%"></i></div></div>
            <div class="r" style="display:block"><div style="display:flex;justify-content:space-between"><span>Cash</span><b>34%</b></div><div class="bar-h"><i style="width:34%;background:var(--amber)"></i></div></div>
            <div class="r" style="display:block"><div style="display:flex;justify-content:space-between"><span>Credit</span><b>8%</b></div><div class="bar-h"><i style="width:8%;background:var(--rose)"></i></div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Till + receipt -->
    <div class="split rev" style="margin-top:110px">
      <div class="split-text">
        <div class="badge-ic" style="background:var(--g-100);color:var(--g-700)"><i class="fas fa-cash-register"></i></div>
        <h2>A till that keeps up with the queue</h2>
        <p class="lead">Big buttons, instant search and a running total. Hold a sale for a customer who stepped out, sell on credit to a regular, and print or send the receipt.</p>
        <div class="icon-list">
          <div class="it"><i class="fas fa-mobile-screen-button" style="background:#e9f7ee;color:#127a3c"></i><div><b>Cash, M-Pesa or split</b><span>Change worked out for you; M-Pesa codes saved with the sale.</span></div></div>
          <div class="it"><i class="fas fa-print" style="background:#fff3e0;color:#a35f00"></i><div><b>Prints by itself</b><span>Bluetooth, Wi-Fi or USB receipt printers — the receipt comes out after every checkout.</span></div></div>
          <div class="it"><i class="fas fa-rotate-left" style="background:#eef3ff;color:var(--blue)"></i><div><b>Returns without the headache</b><span>Take an item back and the stock and the day's totals fix themselves.</span></div></div>
        </div>
      </div>
      <div class="visual">
        <div class="mock" style="max-width:470px">
          <div class="m-top"><span class="dots"><i></i><i></i><i></i></span><b>New sale</b><span style="margin-left:auto;color:var(--muted)">Till 1 · Brian</span></div>
          <div class="till">
            <div class="till-items">
              <div class="till-search"><i class="fas fa-magnifying-glass me-1"></i> Search or scan…</div>
              <div class="till-grid">
                <?php foreach ([['Maize flour 2kg', 199, '#fff3e0', 'fa-wheat-awn', '#a35f00', 1], ['Soda 500ml', 70, '#fdecee', 'fa-bottle-water', '#e2566b', 1], ['Cooking oil 1L', 345, '#fff8e1', 'fa-droplet', '#d39b00', 0],
                                 ['Bread 400g', 65, '#f4ede4', 'fa-bread-slice', '#8a5a2b', 1], ['Sugar 1kg', 185, '#eef3ff', 'fa-cubes', '#2d6cdf', 0], ['Milk 500ml', 65, '#e6f4ec', 'fa-cow', '#167d4c', 0]] as [$n, $p, $bg, $ic, $c, $sel]): ?>
                  <div class="tile <?php echo $sel ? 'sel' : ''; ?>"><div class="ph2" style="background:<?php echo $bg; ?>;color:<?php echo $c; ?>"><i class="fas <?php echo $ic; ?>"></i></div><b><?php echo $n; ?></b><small>KES <?php echo $p; ?></small></div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="till-cart">
              <b style="font-size:12.5px;margin-bottom:4px">Current sale</b>
              <div class="ln"><span>2 × Maize flour 2kg</span><b>398</b></div>
              <div class="ln"><span>3 × Soda 500ml</span><b>210</b></div>
              <div class="ln"><span>1 × Bread 400g</span><b>65</b></div>
              <div class="tot"><span>Total</span><span>KES 673</span></div>
              <div class="pay"><span class="cash">Cash</span><span class="mp">M-Pesa</span></div>
            </div>
          </div>
        </div>
        <div style="position:absolute;right:-6px;bottom:-40px" class="receipt-wrap">
          <div class="receipt">
            <div class="c"><b>DEMO MINI MART</b><br>Ruiru · 0712 345 678</div><hr>
            <div class="rw"><span>Receipt</span><span>#00148</span></div><div class="rw"><span>19/10 08:42</span><span>Brian</span></div><hr>
            <div class="rw"><span>Maize flour x2</span><span>398</span></div><div class="rw"><span>Soda 500ml x3</span><span>210</span></div><div class="rw"><span>Bread 400g x1</span><span>65</span></div><hr>
            <div class="rw"><b>TOTAL</b><b>673.00</b></div><div class="rw"><span>M-Pesa</span><span>673.00</span></div><hr>
            <div class="c">Thank you, karibu tena!</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Phone -->
    <div class="split" style="margin-top:120px">
      <div class="split-text">
        <div class="badge-ic" style="background:#eef3ff;color:var(--blue)"><i class="fas fa-shield-halved"></i></div>
        <h2>Your shop in your pocket</h2>
        <p class="lead">Check sales from anywhere, see who clocked in, approve a re-clock and get the closing report — on your phone, installed like an app.</p>
        <div class="icon-list">
          <div class="it"><i class="fas fa-user-lock" style="background:var(--g-100);color:var(--g-700)"></i><div><b>Each person sees only their part</b><span>Owners see everything; staff see the till and what you give them.</span></div></div>
          <div class="it"><i class="fas fa-wifi" style="background:#fff3e0;color:#a35f00"></i><div><b>Works on what you have</b><span>Phone, tablet or computer — nothing to install on a server of your own.</span></div></div>
        </div>
      </div>
      <div class="visual" style="display:flex;justify-content:center;gap:24px;align-items:flex-end">
        <div class="phone">
          <div class="scr">
            <div class="ph-top"><small style="opacity:.8">Today · all shops</small><b>KES 58,920</b><small style="opacity:.8">212 receipts · 3 branches</small></div>
            <div class="ph-list">
              <div class="r"><span>Main shop</span><b>36,140</b></div>
              <div class="r"><span>Ruiru branch</span><b>14,300</b></div>
              <div class="r"><span>Juja branch</span><b>8,480</b></div>
              <div class="r" style="background:#fff7e8;border-color:#f6dfb2"><span><i class="fas fa-clock me-1" style="color:#a35f00"></i>Re-clock request</span><b style="color:#a35f00">Musa</b></div>
            </div>
          </div>
        </div>
        <div class="mock" style="width:230px;padding:14px;margin-bottom:40px">
          <b style="font-size:12.5px">Staff commissions</b><div style="color:var(--muted);font-size:10.5px;margin-bottom:8px">This week · salon</div>
          <?php foreach ([['Wanjiru', '#1f9a5f', 9600, 80], ['Achieng', '#e2566b', 7200, 60], ['Faith', '#7a5af0', 5100, 42]] as [$n, $c, $amt, $w]): ?>
            <div class="staff-row"><span class="av" style="background:<?php echo $c; ?>"><?php echo $n[0]; ?></span><div style="flex:1"><div style="display:flex;justify-content:space-between"><span><?php echo $n; ?></span><b><?php echo number_format($amt); ?></b></div><div class="bar-h"><i style="width:<?php echo $w; ?>%;background:<?php echo $c; ?>"></i></div></div></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Restaurant & bar -->
    <div class="split rev" style="margin-top:120px">
      <div class="split-text">
        <div class="badge-ic" style="background:#fff3e0;color:#a35f00"><i class="fas fa-utensils"></i></div>
        <h2>Store, kitchen and bar — always in step</h2>
        <p class="lead">Deliveries land in the store. Move what the cooks need to the kitchen and what you sell to the counter or bar — crates become bottles, sacks become kilos — and every plate and every drink takes its stock off.</p>
        <ul class="ticks">
          <li><i class="fas fa-check"></i><b>Kitchen tickets from order to ready</b><span>Table and takeaway orders go to the kitchen, are marked ready and paid at the desk with the receipt number.</span></li>
          <li><i class="fas fa-check"></i><b>Many restaurants and bars, each with its own prices</b><span>A rooftop bar can sell the same beer for more than the main restaurant — each outlet has its own store, shelf, menu and staff.</span></li>
          <li><i class="fas fa-check"></i><b>Rooms and group bookings</b><span>With Restaurant + Rooms: guest stays with late check-out fees, table bookings with set menus and family platters.</span></li>
        </ul>
      </div>
      <div class="visual">
        <div class="mock" style="max-width:470px;padding:14px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div class="panel" style="grid-column:1/3">
              <div class="ph">Store <span>received Mon</span></div>
              <div class="rows">
                <div class="r"><span><i class="fas fa-box me-1" style="color:var(--muted)"></i>Soda crate 500ml</span><b>4 crates</b></div>
                <div class="r"><span><i class="fas fa-box me-1" style="color:var(--muted)"></i>Rice (pishori)</span><b>38 kg</b></div>
                <div class="r"><span><i class="fas fa-box me-1" style="color:var(--muted)"></i>Beer carton (Tusker)</span><b>4 cartons</b></div>
              </div>
            </div>
            <div class="panel">
              <div class="ph"><span style="color:var(--ink);font-weight:700"><i class="fas fa-fire-burner me-1" style="color:var(--rose)"></i>Kitchen</span></div>
              <div class="rows"><div class="r"><span>Rice</span><b>12 kg</b></div><div class="r"><span>Beef</span><b>6 kg</b></div><div class="r"><span>Chicken</span><b>6 pcs</b></div></div>
            </div>
            <div class="panel">
              <div class="ph"><span style="color:var(--ink);font-weight:700"><i class="fas fa-martini-glass me-1" style="color:var(--blue)"></i>Bar shelf</span></div>
              <div class="rows"><div class="r"><span>Coca-Cola</span><b>24</b></div><div class="r"><span>Fanta</span><b>24</b></div><div class="r"><span>Tusker</span><b>24</b></div></div>
            </div>
          </div>
        </div>
        <div class="float" style="display:block;position:absolute;right:-10px;top:-24px;width:200px">
          <div class="t">Table 4 <span class="pill in">Ready</span></div>
          <div class="rows"><div class="r"><span>2 × Beef stew &amp; rice</span><b>960</b></div><div class="r"><span>2 × Coca-Cola</span><b>160</b></div></div>
        </div>
        <div class="float" style="display:block;position:absolute;left:0;bottom:-34px;width:220px">
          <div class="t">Tusker 500ml <span style="color:var(--muted);font-weight:500">by outlet</span></div>
          <div class="rows"><div class="r"><span>Main restaurant</span><b>KES 250</b></div><div class="r"><span>Rooftop Bar</span><b>KES 320</b></div></div>
        </div>
      </div>
    </div>

    <div class="stats" style="margin-top:100px">
      <div><b>Cash · M-Pesa</b><span>and split payments</span></div>
      <div><b>3 ways</b><span>to print: Bluetooth, Wi-Fi, USB</span></div>
      <div><b>Any device</b><span>phone, tablet or computer</span></div>
      <div><b>Branches</b><span>every shop in one account</span></div>
    </div>
  </div>
</section>
