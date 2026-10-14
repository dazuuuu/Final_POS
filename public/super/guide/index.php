<?php
// public/super/guide/index.php — Help centre (owners; staff reach it at
// staff/guide/). Search, a "where to find things" map built from PosMenu,
// step-by-step guides per licensed package, an FAQ, and a link to raise a
// support ticket. Staff only see the topics that apply to them.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

$isStaffViewer = TenantContext::role() === 'staff';
$u = fn(string $owner, string $staff = '') => public_url($isStaffViewer && $staff !== '' ? $staff : $owner);
$ticketUrl = fn(string $subject = '', string $cat = 'general') => public_url('super/help/tickets.php?new=1&category=' . $cat . ($subject !== '' ? '&subject=' . rawurlencode($subject) : ''));

// ---- Shop POS guide (the original guide) ---------------------------------
// [id, icon, title, owner-only, html body]
$sections = [
['start', 'fa-flag-checkered', 'Start here: the big picture', true, '
<p>Think of your business like this:</p>
<div class="gd-map">
  <div class="gd-box gd-admin"><i class="fas fa-user-shield"></i><strong>Admin portal</strong><span>Your main shop + the warehouse + overview of all shops</span></div>
  <div class="gd-arrow"><i class="fas fa-arrow-down"></i> stock moves down</div>
  <div class="gd-shops">
    <div class="gd-box"><i class="fas fa-shop"></i><strong>Shop A</strong><span>own staff, stock, sales</span></div>
    <div class="gd-box"><i class="fas fa-shop"></i><strong>Shop B</strong><span>own staff, stock, sales</span></div>
  </div>
</div>
<ul>
  <li><strong>Admin portal</strong> is the shop you first signed up with. It is where you see every shop, run the <strong>warehouse</strong>, install <strong>updates</strong> and manage admins. It can also sell like a normal shop.</li>
  <li><strong>Every other shop is a separate business</strong>: its own name, staff, stock, sales, credit, expenses and public catalogue. What happens in one shop does not show in another.</li>
  <li>The <strong>shop switcher</strong> (top bar, the button with the shop icon) shows which shop you are working in. <strong>Every page you open applies to that shop.</strong> If something looks missing, check which shop you are in first.</li>
</ul>'],

['shops', 'fa-shop', 'Opening a shop and adding its staff', true, '
<ol>
  <li>Go to <a href="' . $u('super/shops/') . '">My shops</a> and tap <strong>Open a new shop</strong>. Give it a name (and phone / location if you like).</li>
  <li>Leave "Switch to it now" ticked. You are now inside the new shop.</li>
  <li>Open <a href="' . $u('super/staff/') . '">Staff</a> and add the people who work there. Each gets a <strong>PIN</strong> (4–6 digits). PINs are unique across all shops, so a person always lands in their own shop.</li>
  <li>Open <a href="' . $u('super/staff/permissions.php') . '">Permissions</a> to decide what each person may do (see "Staff permissions" below).</li>
  <li>Give the shop stock, either from the warehouse (recommended) or with <strong>Record stock</strong> while inside that shop.</li>
</ol>
<p>Staff log in at the staff terminal (the PIN screen on the home page). They <strong>clock in first</strong>, then log in.</p>
<p>To stop a shop from trading, use <strong>Close</strong> on My shops. Its staff can no longer log in; nothing is deleted and you can reopen it.</p>'],

['warehouse', 'fa-warehouse', 'The warehouse (Admin portal only)', true, '
<p>The warehouse is your central store. It only holds stock and what it <strong>cost</strong> you. It does not sell.</p>
<ol>
  <li>In the Admin portal open <a href="' . $u('super/warehouse/') . '">Warehouse</a>. Record the item, quantity, <strong>buying price</strong> and category. Recording the same name again adds to it (the cost becomes the average).</li>
  <li>When a shop needs it, tap <strong>Move to shop</strong>: choose the shop and the quantity, then set the selling price: <strong>retail</strong>, <strong>wholesale</strong>, or both. At least one is required.</li>
  <li>It immediately appears in that shop\'s POS, inventory and public catalogue at the price you set. If the shop already has an item with the same name, its stock is topped up and the price is updated.</li>
</ol>
<p class="gd-tip"><i class="fas fa-lightbulb"></i> Shops can still record stock themselves (for local suppliers). The warehouse is just the easy way to supply many shops from one place.</p>'],

['categories', 'fa-tags', 'Categories are shared', true, '
<p>There is <strong>one category list</strong> for the whole business. The warehouse and every shop use the same categories. Add, rename or delete a category anywhere and every shop follows. A category is only removed from a shop that has no products in it.</p>'],

['selling', 'fa-cash-register', 'Selling (POS)', false, '
<ul>
  <li>Open <a href="' . $u('super/pos/', 'staff/dashboard/') . '">POS</a>, tap products to add them to the cart, choose <strong>Retail</strong> or <strong>Wholesale</strong> pricing, then:</li>
  <li><strong>Checkout</strong>: paid now (cash, M-Pesa or both).</li>
  <li><strong>Hold</strong>: park the cart while you serve someone else; tap it later to resume.</li>
  <li><strong>Credit</strong>: the customer takes the goods and pays later (only for people allowed to sell on credit).</li>
</ul>'],

['credit', 'fa-hand-holding-dollar', 'Credit: two kinds', false, '
<table class="table table-sm gd-table">
  <thead><tr><th></th><th>Credit sales</th><th>Credit book</th></tr></thead>
  <tbody>
    <tr><td>Has products?</td><td>Yes, stock goes down</td><td>No, just a name and an amount</td></tr>
    <tr><td>Use it for</td><td>Goods taken now, paid later</td><td>Old debts, money lent, anything recorded by hand</td></tr>
    <tr><td>Where</td><td><a href="' . $u('super/orders/', 'staff/orders/') . '">Credit sales</a></td><td><a href="' . $u('super/credits/', 'staff/credits/') . '">Credit book</a></td></tr>
  </tbody>
</table>
<p>Payments can be partial. Whatever is still owed (from both) shows as <strong>Owed on credit</strong> on the dashboard.</p>'],

['expenses', 'fa-file-invoice', 'Expenses and profit', false, '
<p>Record money spent in <a href="' . $u('super/expenses/', 'staff/expenses/') . '">Expenses</a>: a name, an optional description, the amount and a photo of the receipt.</p>
<p>The dashboard shows, for today:</p>
<ul>
  <li><strong>Sales</strong>: everything sold and paid.</li>
  <li><strong>Gross profit</strong>: sales minus what the goods cost you (buying price).</li>
  <li><strong>Expenses</strong>: what you recorded today.</li>
  <li><strong>Net profit = gross profit − expenses</strong>. In the Admin portal you also see each shop\'s figures and the total for all shops.</li>
</ul>
<p class="gd-tip"><i class="fas fa-lightbulb"></i> Profit is only correct if items have a buying price, so always enter what you paid when recording stock.</p>'],

['staff', 'fa-key', 'Staff permissions', true, '
<p>Staff can <strong>make sales</strong> by default. Everything else you switch on per person in <a href="' . $u('super/staff/permissions.php') . '">Permissions</a> (inside the shop they work in):</p>
<ul>
  <li><strong>Credit sales</strong>: sell on credit and write in the credit book.</li>
  <li><strong>Accept credit payments</strong>: receive money owed and handle returns.</li>
  <li><strong>Record stock</strong>: log deliveries into their shop.</li>
  <li><strong>Edit inventory</strong>: change products, prices, offers and categories.</li>
  <li><strong>Record expenses</strong>: log expenses with receipts.</li>
</ul>
<p>Changes apply the next time the person logs in.</p>'],

['mobile', 'fa-mobile-screen', 'Using it on a phone', false, '
<p>On a phone the system works like an app. The bar at the bottom has <strong>Home</strong>, your main groups (e.g. <strong>Shop</strong> and <strong>Stock</strong>, which slide up their pages), the big green <strong>+</strong> button to start a sale straight away, and <strong>Menu</strong>, which opens every page grouped and searchable. Add it to your home screen from the browser menu ("Add to Home screen") so it opens full-screen like an app.</p>'],

['updates', 'fa-download', 'Updates', true, '
<p>When new features arrive, <a href="' . $u('super/updates/') . '">Updates</a> (Admin portal) shows a badge. Open it, read what is new, and tap <strong>Install updates</strong>. Your records are never deleted by an update.</p>'],
];
// Which tab each original topic belongs to.
$shopTopicTab = ['start' => 'start', 'mobile' => 'start', 'updates' => 'start'];

// ---- Topics for the other packages + getting started ----------------------
// [tab, id, icon, title, owner-only, html]
$extra = [
['start', 'menu', 'fa-compass', 'Finding your way around', false, '
<p>The menu is grouped by what you are doing:</p>
<ul>
  <li><strong>Home</strong>: your dashboard: today\'s sales, profit, what is owed and stock alerts.</li>
  <li><strong>Shop</strong>: everything at the till: <em>New sale</em>, <em>Credit sale</em>, held sales, the credit book, payments and returns.</li>
  <li><strong>Stock</strong>: inventory, recording deliveries, products, categories, suppliers, purchases and the warehouse.</li>
  <li><strong>Money</strong>: expenses, finances, sales history and reports.</li>
  <li><strong>People</strong>: customers, staff, permissions and attendance.</li>
  <li><strong>Business</strong>: your shops, admins, settings and updates.</li>
  <li><strong>Help</strong>: this help centre and your support tickets.</li>
</ul>
<p>On a computer the groups are in the left menu: tap a group name to open or close it. On a phone use the bar at the bottom: <strong>Home</strong>, the group tabs, the big <strong>+</strong> button (start a sale) and <strong>Menu</strong>, which shows every page and has a search box.</p>
<p class="gd-tip"><i class="fas fa-lightbulb"></i> Can\'t see a page? It is either for owners only, not switched on for you in Permissions, or part of a package your account doesn\'t have.</p>'],

['start', 'activation', 'fa-key', 'Activating a new account', true, '
<p>A brand-new account must be activated within <strong>2 hours</strong>. Support emails you an <strong>activation key</strong> (it looks like <code>ABCD-EFGH-JKLM-NPQR</code>).</p>
<ol>
  <li>Sign in. A yellow <strong>Activate your account</strong> box is at the top of every page.</li>
  <li>Type or paste the key and tap <strong>Activate</strong>. Capitals and dashes don\'t matter.</li>
</ol>
<p>If the 2 hours pass first, the account (owner, staff and every shop) is locked to the activation page until the key is entered. Nothing is lost. Once activated it never locks again. No key? <a href="' . $ticketUrl('Please send my activation key', 'billing') . '">Ask support</a>.</p>'],

['start', 'tickets', 'fa-ticket', 'Getting help from support', false, '
<ol>
  <li>Open <strong>Help → Support tickets</strong> and tap <strong>New ticket</strong>.</li>
  <li>Pick what it is about, give it a short title, say how urgent it is and describe what happened.</li>
  <li>Support replies inside the ticket. A red dot on <strong>Help</strong> means there is a new reply. You can reply back in the same ticket.</li>
</ol>'],

['restaurant', 'r-setup', 'fa-utensils', 'Setting up the restaurant', true, '
<ol>
  <li><a href="' . $u('super/restaurant/categories.php') . '">Menu categories</a>: add groups like Breakfast, Fast food, Drinks.</li>
  <li><a href="' . $u('super/restaurant/ingredients.php') . '">Ingredients</a>: what dishes are made of (bun, meat pie, onion…). "Pieces per unit" lets a recipe use ½ onion from stock kept in kg.</li>
  <li><a href="' . $u('super/restaurant/menu.php') . '">Menu</a>: each dish has a name, photo, price and its default ingredients.</li>
  <li><a href="' . $u('super/restaurant/stock.php') . '">Store stock</a>: record goods received (soda crates, flour, oil).</li>
  <li><a href="' . $u('super/restaurant/transfer.php') . '">Transfers</a>: move stock from the store to the <strong>Counter</strong> (to sell, e.g. 1 crate → Coke ×12, Fanta ×12) or the <strong>Kitchen</strong>.</li>
  <li><a href="' . $u('super/restaurant/staff.php') . '">Waiters &amp; staff</a>: add waiters with a PIN; tick "Can take payments" for cashiers.</li>
</ol>'],

['restaurant', 'r-order', 'fa-cash-register', 'Taking an order', false, '
<ol>
  <li>Open <a href="' . $u('super/restaurant/pos.php') . '">New order</a> and tap dishes. In the dish details you can untick ingredients ("without onions"), set the quantity and add a note.</li>
  <li>Counter items (sodas…) are sold by type and only up to what is at the counter.</li>
  <li><strong>Place order</strong> sends it to be paid and prints an order slip. <strong>Hold</strong> parks it for later.</li>
  <li>Dishes outside their serving hours (e.g. tea 6–11am) can\'t be added.</li>
</ol>'],

['restaurant', 'r-pay', 'fa-money-bill-wave', 'Payments, receipts and the QR menu', false, '
<ul>
  <li><a href="' . $u('super/restaurant/payments.php') . '">Payments</a>: find the order by its slip number or from the unpaid list, take cash, M-Pesa or card (or split), then print the receipt. Stock is deducted when an order is paid.</li>
  <li><a href="' . $u('super/restaurant/orders.php') . '">Orders</a>: history and reprints. Waiters see only their own orders.</li>
  <li><a href="' . $u('super/restaurant/share.php') . '">Share menu / QR</a> (owner): a public menu link, a QR code and per-table QR cards; customers can order online. Online orders show as a badge on Payments.</li>
</ul>'],

['services', 's-setup', 'fa-list', 'Setting up services', true, '
<ol>
  <li><a href="' . $u('super/services/catalog.php') . '">Services &amp; prices</a>: your price list, with duration, default commission % and which shops offer each service.</li>
  <li><a href="' . $u('super/services/commissions.php') . '">Commissions</a>: extra rules (one service or all, certain weekdays, one person or everyone). The most specific rule wins; otherwise the service\'s default % applies.</li>
  <li>In <a href="' . $u('super/staff/permissions.php') . '">Permissions</a> choose who can book clients (front desk) and who can take payments (cashier).</li>
</ol>'],

['services', 's-flow', 'fa-user-check', 'Serving a client', false, '
<ol>
  <li><a href="' . $u('super/services/new.php') . '">New client</a>: enter the name (phone/email optional), pick the services and, for each, who will do it. It can be a walk-in or a booking.</li>
  <li><a href="' . $u('super/services/') . '">Front desk</a> shows today\'s clients, bookings, visits in progress and paid ones. Check bookings in from here.</li>
  <li>Staff open <a href="' . $u('super/services/mine.php') . '">My clients</a> and mark each service <strong>done</strong>.</li>
  <li>The cashier opens the visit, takes payment and prints the receipt (or an invoice if unpaid).</li>
</ol>'],

['electronics', 'e-serial', 'fa-barcode', 'Serial numbers and IMEIs', false, '
<ol>
  <li>In <a href="' . $u('super/electronics/') . '">Devices &amp; serials</a> mark which products are serial-tracked and give them a warranty period.</li>
  <li>Register the serial / IMEI of units already on the shelf. New stock asks for serials as it is recorded.</li>
  <li>When you sell a tracked product, the POS asks which unit (serial) was sold. The warranty starts that day.</li>
  <li>Search any device by serial / IMEI, product or customer.</li>
</ol>'],

['electronics', 'e-repair', 'fa-screwdriver-wrench', 'Warranty claims and repairs', false, '
<ol>
  <li>Open <a href="' . $u('super/electronics/repairs.php') . '">Warranty &amp; repairs</a> and look the device up by serial / IMEI.</li>
  <li>Still under warranty: take it in for a free repair. Expired: take it in and charge a repair fee.</li>
  <li>The device is held until collected. Every return is counted against that serial, so you can spot problem units.</li>
</ol>'],
];

// ---- FAQ: [tab, question, answer, owner-only] -------------------------------
$faq = [
['start', 'I can\'t see a page that\'s mentioned here.', 'It is owner-only, not allowed for you in Permissions, or part of a package your account doesn\'t have. Ask the owner, or raise a ticket.', false],
['start', 'How do I put the system on my phone like an app?', 'Open it in Chrome (Android) or Safari (iPhone), open the browser menu and choose "Add to Home screen". It then opens full-screen like an app.', false],
['start', 'Which shop am I working in?', 'The shop switcher in the top bar shows it. Every page applies to the selected shop.', true],
['start', 'My account says it is locked.', 'It needs its activation key. Enter the key from support on the activation page. If you don\'t have it, raise a ticket or contact support.', false],
['start', 'I forgot my password or PIN.', 'Owners: use "Forgot password" on the login page. Staff: ask the owner to reset your PIN in Staff.', false],
['shop', 'What\'s the difference between Credit sale and Credit book?', 'A credit sale has products (stock goes down) and is paid later. The credit book is just a name and an amount: old debts, money lent, anything recorded by hand.', false],
['shop', 'A customer paid part of what they owe.', 'Open Payments (or the credit book entry) and record the amount. Part payments are fine; the balance stays on the dashboard as "Owed on credit".', false],
['shop', 'How do I take back goods?', 'Use Shop → Returns. Stock goes back up and the sale is adjusted.', false],
['shop', 'Why is my profit wrong or zero?', 'Profit needs each item\'s buying price. Enter what you paid when recording stock, or edit the product.', true],
['shop', 'How do I stock a branch shop?', 'From the Admin portal, record goods in the Warehouse, then "Move to shop" with the selling price. Or record stock while inside that shop.', true],
['shop', 'Staff can\'t sell on credit or record stock.', 'Switch it on for that person in People → Permissions (inside their shop). It applies at their next login.', true],
['restaurant', 'Why can\'t I add a dish?', 'It is outside its serving hours, or a counter item has run out at the counter. Transfer more from the store.', false],
['restaurant', 'When is stock deducted?', 'When the order is paid on the Payments page, not when it is placed.', false],
['restaurant', 'How do customers order from their table?', 'Print the table QR cards from Share menu / QR. The table number fills itself in.', true],
['services', 'How is commission worked out?', 'The most specific matching rule in Commissions wins (service + day + person). If none matches, the service\'s default % is used.', true],
['services', 'A staff member can\'t see the front desk.', 'Give them "book clients" or "cashier" access in Permissions. Otherwise they only see My clients.', true],
['electronics', 'The POS asks for a serial when I sell.', 'That product is serial-tracked. Pick the unit being sold so its warranty starts today.', false],
['electronics', 'A device came back after warranty.', 'Take it in on Warranty & repairs. It will show as expired and you can enter a repair fee.', false],
];

// ---- Assemble tabs for the packages this account holds --------------------
$tabs = [
    'start'       => ['Getting started', 'fa-flag-checkered', true],
    'shop'        => ['Shop POS', 'fa-cash-register', Features::enabled(Features::SHOP_POS)],
    'restaurant'  => ['Restaurant', 'fa-utensils', Features::enabled(Features::RESTAURANT_POS)],
    'services'    => ['Services', 'fa-user-gear', Features::enabled(Features::SERVICES)],
    'electronics' => ['Electronics', 'fa-mobile-screen', Features::enabled(Features::ELECTRONICS)],
];
$tabs = array_filter($tabs, fn($t) => $t[2]);

$topics = [];
foreach ($sections as [$id, $icon, $title, $ownerOnly, $html]) {
    $topics[] = [$shopTopicTab[$id] ?? 'shop', $id, $icon, $title, $ownerOnly, $html];
}
$topics = array_merge(array_slice($topics, 0, 1), array_slice($extra, 0, 3), array_slice($topics, 1), array_slice($extra, 3));
$visible = fn($row, $tabIdx, $ownerIdx) => isset($tabs[$row[$tabIdx]]) && !($isStaffViewer && $row[$ownerIdx]);
$topics = array_values(array_filter($topics, fn($t) => $visible($t, 0, 4)));
$faq = array_values(array_filter($faq, fn($f) => $visible($f, 0, 3)));

$groups = PosMenu::groups();

$page_title = 'Help centre';
ob_start();
?>
<div class="hc-hero">
  <div class="hc-hero-in">
    <span class="hc-kicker"><i class="fas fa-life-ring me-1"></i>Help centre</span>
    <h2>How can we help?</h2>
    <div class="hc-search"><i class="fas fa-magnifying-glass"></i><input type="search" id="hcFind" placeholder="Search guides and questions… e.g. credit, returns, stock" autocomplete="off"></div>
    <div class="hc-quick">
      <a href="#where"><i class="fas fa-map"></i>Where things are</a>
      <a href="#guides"><i class="fas fa-book-open"></i>Guides</a>
      <a href="#faq"><i class="fas fa-circle-question"></i>FAQ</a>
      <a href="<?php echo public_url('super/help/tickets.php'); ?>"><i class="fas fa-ticket"></i>My tickets</a>
    </div>
  </div>
</div>

<section id="where" class="hc-block" data-hc-block>
  <h3 class="hc-h"><i class="fas fa-map"></i>Where to find things</h3>
  <div class="hc-where">
    <?php foreach ($groups as $g): ?>
      <div class="hc-wcard" data-hc-item data-text="<?php echo htmlspecialchars(strtolower($g['label'] . ' ' . implode(' ', array_column($g['links'], 0)))); ?>">
        <div class="hc-wtitle"><span><i class="fas <?php echo $g['icon']; ?>"></i></span><?php echo htmlspecialchars($g['label']); ?></div>
        <?php foreach ($g['links'] as $l): ?>
          <a href="<?php echo public_url($l[2]); ?>"><i class="fas <?php echo $l[1]; ?>"></i><?php echo htmlspecialchars($l[0]); ?><i class="fas fa-chevron-right hc-go"></i></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section id="guides" class="hc-block" data-hc-block>
  <h3 class="hc-h"><i class="fas fa-book-open"></i>Guides</h3>
  <div class="hc-tabs" role="tablist">
    <?php $first = true; foreach ($tabs as $k => [$label, $icon]): ?>
      <button type="button" class="hc-tab <?php echo $first ? 'on' : ''; ?>" data-tab="<?php echo $k; ?>"><i class="fas <?php echo $icon; ?>"></i><?php echo $label; ?></button>
    <?php $first = false; endforeach; ?>
  </div>
  <?php $n = 0; foreach ($topics as [$tab, $id, $icon, $title, , $html]): ?>
    <details class="hc-topic" id="<?php echo $id; ?>" data-tabof="<?php echo $tab; ?>" data-hc-item data-text="<?php echo htmlspecialchars(strtolower($title . ' ' . strip_tags($html))); ?>">
      <summary><span class="hc-ic"><i class="fas <?php echo $icon; ?>"></i></span><span class="flex-grow-1"><?php echo htmlspecialchars($title); ?></span><i class="fas fa-chevron-down hc-chev"></i></summary>
      <div class="hc-body gd-body"><?php echo $html; ?></div>
    </details>
  <?php endforeach; ?>
</section>

<section id="faq" class="hc-block" data-hc-block>
  <h3 class="hc-h"><i class="fas fa-circle-question"></i>Frequently asked questions</h3>
  <?php foreach ($faq as [$tab, $q, $a]): ?>
    <details class="hc-faq" data-hc-item data-text="<?php echo htmlspecialchars(strtolower($q . ' ' . $a . ' ' . $tab)); ?>">
      <summary><span class="hc-tag"><?php echo htmlspecialchars($tabs[$tab][0]); ?></span><span class="flex-grow-1"><?php echo htmlspecialchars($q); ?></span><i class="fas fa-plus hc-chev"></i></summary>
      <p><?php echo htmlspecialchars($a); ?></p>
    </details>
  <?php endforeach; ?>
</section>

<p class="hc-none" id="hcNone">No guide matches that. <a href="#" id="hcAsk">Ask support instead</a>.</p>

<div class="hc-cta">
  <div><strong>Still stuck?</strong><span>Raise a ticket and support will reply inside the system.</span></div>
  <a class="btn" href="<?php echo $ticketUrl(); ?>"><i class="fas fa-ticket me-1"></i>Raise a ticket</a>
</div>

<style>
html{scroll-behavior:smooth;}
.hc-hero{background:linear-gradient(135deg,#16a34a 0%,#0d9488 60%,#0e7490 100%);border-radius:22px;color:#fff;padding:26px 22px;margin-bottom:18px;position:relative;overflow:hidden;}
.hc-hero::after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;background:rgba(255,255,255,.08);right:-70px;top:-90px;}
.hc-hero-in{position:relative;max-width:720px;}
.hc-kicker{font-size:.72rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.9;}
.hc-hero h2{font-size:1.6rem;font-weight:800;letter-spacing:-.02em;margin:6px 0 14px;}
.hc-search{position:relative;}
.hc-search i{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:#64748b;}
.hc-search input{width:100%;border:0;border-radius:16px;padding:14px 16px 14px 44px;font-size:16px;outline:none;box-shadow:0 10px 24px rgba(0,0,0,.15);}
.hc-quick{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;}
.hc-quick a{background:rgba(255,255,255,.18);color:#fff;text-decoration:none;border-radius:999px;padding:6px 12px;font-size:.8rem;font-weight:600;display:inline-flex;gap:6px;align-items:center;}
.hc-block{margin-bottom:22px;scroll-margin-top:80px;}
.hc-h{font-size:1rem;font-weight:800;margin:0 0 12px;display:flex;gap:8px;align-items:center;}
.hc-h i{color:var(--tk-green-dark,#15803d);}
.hc-where{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;}
.hc-wcard{background:#fff;border:1px solid #eef0f4;border-radius:16px;padding:14px;}
.hc-wtitle{font-weight:800;display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.hc-wtitle span{width:32px;height:32px;border-radius:10px;background:#f0fdf4;color:#15803d;display:grid;place-items:center;}
.hc-wcard a{display:flex;align-items:center;gap:10px;padding:7px 4px;border-radius:8px;color:#334155;text-decoration:none;font-size:.86rem;}
.hc-wcard a:hover{background:#f8fafc;}
.hc-wcard a i:first-child{width:16px;text-align:center;color:#94a3b8;}
.hc-go{margin-left:auto;font-size:.65rem;color:#cbd5e1;}
.hc-tabs{display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;margin-bottom:10px;}
.hc-tab{border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:8px 14px;font-size:.84rem;font-weight:700;color:#475569;white-space:nowrap;display:inline-flex;gap:8px;align-items:center;}
.hc-tab.on{background:#16a34a;border-color:#16a34a;color:#fff;}
.hc-topic,.hc-faq{background:#fff;border:1px solid #eef0f4;border-radius:16px;margin-bottom:10px;scroll-margin-top:80px;}
.hc-topic summary,.hc-faq summary{list-style:none;display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;font-weight:700;}
.hc-topic summary::-webkit-details-marker,.hc-faq summary::-webkit-details-marker{display:none;}
.hc-ic{width:36px;height:36px;border-radius:11px;background:#f0fdf4;color:#15803d;display:grid;place-items:center;flex-shrink:0;}
.hc-chev{color:#94a3b8;transition:transform .2s;font-size:.8rem;}
details[open] .hc-chev{transform:rotate(180deg);}
.hc-faq[open] .hc-chev{transform:rotate(45deg);}
.hc-body{padding:0 18px 16px 66px;}
@media (max-width:576px){.hc-body{padding:0 14px 14px;}}
.hc-faq p{padding:0 16px 14px;margin:0;color:#475569;font-size:.9rem;line-height:1.55;}
.hc-faq summary{font-weight:600;font-size:.92rem;}
.hc-tag{font-size:.64rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;background:#f1f5f9;color:#64748b;border-radius:6px;padding:2px 7px;flex-shrink:0;}
.hc-none{display:none;text-align:center;color:#64748b;padding:24px 0;}
.hc-cta{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;background:#0f172a;color:#fff;border-radius:18px;padding:18px 20px;margin-bottom:10px;}
.hc-cta strong{display:block;font-size:1rem;}
.hc-cta span{font-size:.84rem;opacity:.8;}
.hc-cta .btn{background:#16a34a;color:#fff;border-radius:12px;font-weight:700;}
.gd-body p,.gd-body li{font-size:.9rem;line-height:1.55;}
.gd-body li{margin-bottom:6px;}
.gd-tip{background:#fffbeb;border-left:3px solid #f59e0b;padding:10px 12px;border-radius:6px;}
.gd-tip i{color:#d97706;margin-right:6px;}
.gd-map{display:flex;flex-direction:column;align-items:center;gap:8px;margin:6px 0 16px;}
.gd-shops{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;}
.gd-box{border:1px solid #e5e7eb;border-radius:12px;padding:10px 14px;text-align:center;min-width:150px;background:#fff;}
.gd-box i{display:block;font-size:1.2rem;color:var(--tk-green-dark);margin-bottom:4px;}
.gd-box strong{display:block;font-size:.9rem;}
.gd-box span{font-size:.75rem;color:#8a8f96;}
.gd-admin{background:#e6f6ee;border-color:#bfe8d1;}
.gd-arrow{font-size:.75rem;color:#8a8f96;}
.gd-table{min-width:0 !important;font-size:.85rem;}
</style>
<script>
(function () {
  var tabs = document.querySelectorAll('.hc-tab'), find = document.getElementById('hcFind');
  var current = tabs.length ? tabs[0].getAttribute('data-tab') : 'start';
  function showTab() {
    document.querySelectorAll('.hc-topic').forEach(function (t) { t.style.display = t.getAttribute('data-tabof') === current ? '' : 'none'; });
  }
  tabs.forEach(function (b) {
    b.addEventListener('click', function () {
      tabs.forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on');
      current = b.getAttribute('data-tab'); find.value = ''; filter();
    });
  });
  function filter() {
    var q = find.value.trim().toLowerCase(), any = false;
    document.querySelector('.hc-tabs').style.display = q ? 'none' : '';
    if (!q) {
      document.querySelectorAll('[data-hc-item]').forEach(function (el) { el.style.display = ''; });
      document.querySelectorAll('[data-hc-block]').forEach(function (b) { b.style.display = ''; });
      showTab(); document.getElementById('hcNone').style.display = 'none'; return;
    }
    document.querySelectorAll('[data-hc-block]').forEach(function (b) {
      var hits = 0;
      b.querySelectorAll('[data-hc-item]').forEach(function (el) {
        var m = el.getAttribute('data-text').indexOf(q) !== -1;
        el.style.display = m ? '' : 'none';
        if (m) { hits++; if (el.tagName === 'DETAILS' && q.length > 2) el.open = true; }
      });
      b.style.display = hits ? '' : 'none'; if (hits) any = true;
    });
    document.getElementById('hcNone').style.display = any ? 'none' : 'block';
  }
  find.addEventListener('input', filter);
  document.getElementById('hcAsk').addEventListener('click', function (e) {
    e.preventDefault();
    location.href = <?php echo json_encode($ticketUrl()); ?> + '&subject=' + encodeURIComponent(find.value.trim());
  });
  // Deep link (#credit) opens that topic on its tab.
  var h = location.hash.slice(1), target = h && document.getElementById(h);
  if (target && target.classList.contains('hc-topic')) {
    current = target.getAttribute('data-tabof');
    tabs.forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-tab') === current); });
    target.open = true;
  }
  showTab();
})();
</script>
<?php
$content = ob_get_clean();
$__layout = $isStaffViewer ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
