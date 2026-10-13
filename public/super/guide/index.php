<?php
// public/super/guide/index.php — plain-language guide to how the system fits
// together. Owners see everything; staff (staff/guide/) see the parts they use.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

$isStaffViewer = TenantContext::role() === 'staff';
$u = fn(string $owner, string $staff = '') => public_url($isStaffViewer && $staff !== '' ? $staff : $owner);

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
<p>On a phone the menu sits at the bottom like an app. <strong>Shops</strong>, <strong>Inventory</strong> and <strong>Finance</strong> open a panel of related pages; <strong>More</strong> opens the full menu. You can also add the system to your home screen from the browser menu ("Add to Home screen") so it opens like an app.</p>'],

['updates', 'fa-download', 'Updates', true, '
<p>When new features arrive, <a href="' . $u('super/updates/') . '">Updates</a> (Admin portal) shows a badge. Open it, read what is new, and tap <strong>Install updates</strong>. Your records are never deleted by an update.</p>'],
];
if ($isStaffViewer) {
    $sections = array_values(array_filter($sections, fn($s) => !$s[3]));
}

$page_title = 'Guide';
ob_start();
?>
<div class="card mb-4"><div class="card-body p-4">
  <h2 class="h5 fw-bold mb-1"><?php echo $isStaffViewer ? 'How to use the system' : 'How your system works'; ?></h2>
  <p class="text-muted small mb-3">A short tour. Tap a topic to jump to it.</p>
  <div class="gd-toc">
    <?php foreach ($sections as $s): ?><a href="#<?php echo $s[0]; ?>"><i class="fas <?php echo $s[1]; ?>"></i><?php echo htmlspecialchars($s[2]); ?></a><?php endforeach; ?>
  </div>
</div></div>

<?php foreach ($sections as $i => $s): ?>
<div class="card mb-3" id="<?php echo $s[0]; ?>"><div class="card-body p-4 gd-body">
  <h3 class="h6 fw-bold mb-3"><span class="gd-num"><?php echo $i + 1; ?></span><i class="fas <?php echo $s[1]; ?> me-2 text-primary"></i><?php echo htmlspecialchars($s[2]); ?></h3>
  <?php echo $s[4]; ?>
</div></div>
<?php endforeach; ?>

<style>
.gd-toc{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;}
.gd-toc a{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f5f7f6;border-radius:10px;text-decoration:none;color:#333;font-size:.85rem;font-weight:600;}
.gd-toc i{color:var(--tk-green-dark);width:18px;text-align:center;}
.gd-body p,.gd-body li{font-size:.9rem;line-height:1.55;}
.gd-body li{margin-bottom:6px;}
.gd-num{display:inline-flex;width:24px;height:24px;border-radius:50%;background:var(--tk-green);color:#fff;font-size:.75rem;align-items:center;justify-content:center;margin-right:8px;}
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
html{scroll-behavior:smooth;}
.card[id]{scroll-margin-top:70px;}
</style>
<?php
$content = ob_get_clean();
$__layout = $isStaffViewer ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
