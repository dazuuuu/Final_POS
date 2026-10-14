<?php
// public/staff/dashboard/index.php — Home: the POS. Build a cart, then:
//   • Hold      — park it (no stock touched) and start the next customer;
//                 held carts sit in a strip above the cart, one tap resumes.
//   • Credit    — a named customer takes the goods now and pays later; an
//                 optional deposit is recorded straight away.
//   • Checkout  — paid in full now (cash / M-Pesa / split).
// Owners reach the same screen at super/pos/ (thin wrapper).
require_once __DIR__ . '/../../../app/app.php';
// Every staff member lands here after the PIN login, so this must open for
// anyone signed in — those without "Make sales" get a landing page below
// (or, with the Services package, their Services screen) instead of the POS.
PageGuard::auth();

if (!TenantContext::can(Capabilities::SALES_RECORD) && Features::enabled(Features::SERVICES)) {
    $desk = TenantContext::can(Capabilities::SERVICES_BOOK) || TenantContext::can(Capabilities::SERVICES_CASHIER);
    header('Location: ' . public_url($desk ? 'super/services/' : 'super/services/mine.php'));
    exit;
}

// Restaurant-only accounts: staff home is the restaurant order screen.
if (!Features::enabled(Features::SHOP_POS) && Features::enabled(Features::RESTAURANT_POS)) {
    header('Location: ' . public_url('super/restaurant/pos.php'));
    exit;
}

$pdo = Database::pdo();
$isStaffViewer = TenantContext::role() === 'staff';
$canSell = TenantContext::can(Capabilities::SALES_RECORD);
$canCredit = TenantContext::can(Capabilities::CREDIT_SALES);
$area = $isStaffViewer ? 'staff' : 'super';
$posUrl = public_url($isStaffViewer ? 'staff/dashboard/' : 'super/pos/');

if (!$canSell || !Features::enabled(Features::SHOP_POS)) {
    // No selling permission (or the shop POS package is off) — a light landing page instead of the POS screen.
    $__tenant = (new Models\TenantModel($pdo))->find(TenantContext::tenantId());
    $page_title = 'Home';
    $who = $_SESSION['username'] ?? 'there';
    ob_start();
    ?>
    <div class="card border-0 shadow-sm" style="border-radius:16px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Hi <?php echo htmlspecialchars($who); ?></h2>
        <p class="text-muted mb-0">
          You're signed in at <strong><?php echo htmlspecialchars($__tenant['name'] ?? 'your shop'); ?></strong>.
          <?php if (TenantContext::can(Capabilities::PAYMENTS_PROCESS)): ?>
            Use <a href="<?php echo public_url($isStaffViewer ? 'staff/payments/' : 'super/payments/'); ?>">Payments</a> to settle invoices.
          <?php endif; ?>
        </p>
      </div>
    </div>
    <?php
    $content = ob_get_clean();
    $__layout = $isStaffViewer ? 'staff' : 'tenants';
    include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
    exit;
}

$P  = new Models\ProductModel($pdo);
$C  = new Models\CategoryModel($pdo);
$HO = new Models\HeldOrderModel($pdo);
$OR = new Models\OrderModel($pdo);
$CU = new Models\CustomerModel($pdo);
$products   = $P->sellable();
// Electronics package: tracked products need a serial / IMEI per unit at checkout.
$EL = Models\ElectronicsModel::enabled() ? new Models\ElectronicsModel($pdo) : null;
$trackedProducts = $EL ? $EL->trackedProducts() : [];
$stockSerials = $EL ? $EL->inStockByProduct() : [];
$categories = $C->all(['type' => 'subject'], 'name ASC');
$byId = [];
foreach ($products as $p) { $byId[(int) $p['id']] = $p; }

const POS_WALKIN = 'Walk-in Customer';
$error = '';
$cartJson = '[]';
$customerName = POS_WALKIN;
$customerId = 0;
$saleType = 'retail';
$discountIn = 0.0;
$heldOrderId = 0;
$startMode = ($_GET['mode'] ?? '') === 'credit' && $canCredit ? 'credit' : '';
$_SESSION['sale_form_token'] ??= bin2hex(random_bytes(16));
$_SESSION['processed_sale_tokens'] ??= [];

$resumeId = (int) ($_GET['resume'] ?? 0);
if ($resumeId > 0) {
    $held = $HO->find($resumeId);
    if ($held) {
        $customerName = $held['customer_name'];
        $customerId   = (int) ($held['customer_id'] ?? 0);
        $saleType     = ($held['sale_type'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
        $discountIn   = (float) ($held['discount_amount'] ?? 0);
        $heldOrderId  = $resumeId;
        $cart = [];
        foreach ($HO->items($resumeId) as $it) {
            if ($it['product_id'] && isset($byId[(int) $it['product_id']])) {
                $cart[] = ['product_id' => (int) $it['product_id'], 'quantity' => (float) $it['quantity']];
            }
        }
        $cartJson = json_encode($cart);
    }
}

/** Server-side price of one unit, mirroring OrderModel::insertItems(). */
$unitPrice = function (array $prod, string $saleType): float {
    if ($saleType === 'wholesale' && (float) $prod['wholesale_price'] > 0) {
        return (float) $prod['wholesale_price'];
    }
    return (float) ($prod['retail_price'] ?: ($prod['selling_price'] ?: $prod['wholesale_price']));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'pay';
    $saleToken = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['sale_token'] ?? ''));
    if (in_array($action, ['pay', 'credit'], true) && $saleToken !== '' && isset($_SESSION['processed_sale_tokens'][$saleToken])) {
        $done = $_SESSION['processed_sale_tokens'][$saleToken];
        header('Location: ' . public_url($area . '/orders/receipt.php?id=' . (int) $done['id']));
        exit;
    }
    $cart = json_decode($_POST['cart'] ?? '[]', true);
    $cartJson = $_POST['cart'] ?? '[]';
    $customerName = trim($_POST['table_name'] ?? '') !== '' ? trim($_POST['table_name']) : POS_WALKIN;
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $heldOrderId = (int) ($_POST['held_order_id'] ?? 0);
    $saleType = in_array($_POST['sale_type'] ?? 'retail', ['retail', 'wholesale'], true) ? $_POST['sale_type'] : 'retail';
    $discountIn = round((float) ($_POST['discount_amount'] ?? 0), 2);
    if (!is_array($cart)) { $cart = []; }
    $items = [];
    foreach ($cart as $c) {
        $q = round((float) ($c['quantity'] ?? 0), 2);
        if ((int) ($c['product_id'] ?? 0) > 0 && $q > 0) {
            $items[] = ['product_id' => (int) $c['product_id'], 'quantity' => $q];
        }
    }
    // A picked customer must be one of ours; a stale id is just dropped.
    $customer = $customerId > 0 ? $CU->find($customerId) : null;
    $customerId = $customer ? (int) $customer['id'] : 0;

    if (in_array($action, ['hold', 'swap'], true)) {
        // 'swap' = park whatever is in the cart (if anything) and open a held one.
        $swapTo = (int) ($_POST['resume_id'] ?? 0);
        if ($items) {
            $res = $HO->hold([
                'customer_name' => $customerName, 'customer_id' => $customerId, 'staff_id' => TenantContext::userId(),
                'items' => $items, 'sale_type' => $saleType, 'discount_amount' => $discountIn,
            ]);
            if (!$res['ok']) {
                $error = $res['errors']['_'] ?? ($res['errors']['customer_name'] ?? 'Could not hold this sale.');
            } else {
                if ($heldOrderId > 0) { $HO->discard($heldOrderId); }
                $_SESSION['flash']['success'] = 'Sale for ' . $customerName . ' is on hold — resume it any time from "Held sales".';
            }
        } elseif ($action === 'hold') {
            $error = 'Add at least one item to hold this sale.';
        }
        if (!$error) {
            header('Location: ' . $posUrl . ($action === 'swap' && $swapTo > 0 ? '?resume=' . $swapTo : ''));
            exit;
        }

    } elseif ($action === 'discard_held') {
        $HO->discard((int) ($_POST['discard_id'] ?? 0));
        $_SESSION['flash']['success'] = 'Held sale discarded.';
        header('Location: ' . $posUrl);
        exit;

    } else { // pay (walk-in, paid in full) or credit (pay later, optional deposit)
        $subtotal = 0.0;
        foreach ($items as $it) {
            if (isset($byId[$it['product_id']])) {
                $subtotal += $unitPrice($byId[$it['product_id']], $saleType) * $it['quantity'];
            }
        }
        $subtotal = round($subtotal, 2);
        // Negotiated discount — clamp so a typo can't produce a negative total.
        $discount = min(max($discountIn, 0), $subtotal);
        $total = round($subtotal - $discount, 2);
        $method = $_POST['payment_method'] ?? '';
        $tendered = round((float) ($_POST['amount_tendered'] ?? 0), 2);
        $cashAmt  = round((float) ($_POST['cash_amount'] ?? 0), 2);
        $mpesaAmt = round((float) ($_POST['mpesa_amount'] ?? 0), 2);
        $deposit  = max(0, round((float) ($_POST['deposit_amount'] ?? 0), 2));
        $depositMethod = ($_POST['deposit_method'] ?? 'cash') === 'mpesa' ? 'mpesa' : 'cash';
        $phone = trim($_POST['customer_phone'] ?? '');
        $email = trim($_POST['customer_email'] ?? '');
        $isCredit = $action === 'credit';

        if ($isCredit && !$canCredit) {
            $error = 'You are not allowed to sell on credit. Ask the owner to grant "Credit sales".';
        } elseif (!$items) {
            $error = 'Add at least one item.';
        } elseif ($isCredit && (strcasecmp($customerName, POS_WALKIN) === 0 || $customerName === '')) {
            $error = 'A credit sale needs the customer\'s name — pick a customer or type their name.';
        } elseif ($isCredit && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That email address doesn\'t look right.';
        } elseif (!$isCredit && $method === 'cash' && $tendered + 0.0001 < $total) {
            $error = 'Cash given is less than the total.';
        } elseif (!$isCredit && $method === 'split' && ($cashAmt + $mpesaAmt) + 0.0001 < $total) {
            $error = 'Cash and M-Pesa amounts are less than the total.';
        } else {
            // Remember the customer (loyal customers list) when asked to, and
            // always for credit — you need to know who owes you.
            if (!$customer && strcasecmp($customerName, POS_WALKIN) !== 0 && ($isCredit || !empty($_POST['save_customer']))) {
                $customer = $CU->findOrCreateByContact($customerName, $email, $phone);
            }
            if ($customer && ($phone !== '' || $email !== '') && (empty($customer['phone']) || empty($customer['email']))) {
                $CU->save(array_merge($customer, [
                    'phone' => $customer['phone'] ?: $phone,
                    'email' => $customer['email'] ?: $email,
                ]));
            }
            $openRes = $OR->open([
                'table_name' => $customerName, 'opened_by' => TenantContext::userId(), 'items' => $items,
                'channel' => $isCredit ? 'tab' : 'walkin', 'sale_type' => $saleType, 'discount_amount' => $discount,
                'customer_id' => $customer['id'] ?? 0,
                'customer_phone' => $phone !== '' ? $phone : ($customer['phone'] ?? ''),
                'customer_email' => $email !== '' ? $email : ($customer['email'] ?? ''),
                'serials' => (array) (json_decode((string) ($_POST['serials'] ?? ''), true) ?: []),
            ]);
            if (!$openRes['ok']) {
                $error = $openRes['errors']['_'] ?? 'Could not record this sale.';
            } else {
                $orderId = (int) $openRes['order_id'];
                if ($isCredit) {
                    $payRes = ['ok' => true];
                    if ($deposit > 0) {
                        $payRes = $OR->recordPayment($orderId, [
                            'method' => $depositMethod, 'amount' => $deposit, 'amount_tendered' => $deposit,
                        ], TenantContext::userId());
                    }
                    $okMsg = 'Credit sale recorded for ' . $customerName . ' — ' . $openRes['receipt_number']
                        . ($deposit > 0 && $payRes['ok'] ? ' (deposit KES ' . number_format(min($deposit, $total), 0) . ')' : '') . '.';
                } else {
                    $payRes = $OR->markPaid($orderId, [
                        'method' => $method, 'cash_amount' => $cashAmt, 'mpesa_amount' => $mpesaAmt, 'amount_tendered' => $tendered,
                    ], TenantContext::userId());
                    $okMsg = 'Sale recorded — ' . $openRes['receipt_number'] . '.';
                }
                if ($payRes['ok']) {
                    if ($heldOrderId > 0) { $HO->discard($heldOrderId); }
                    if ($saleToken !== '') {
                        $_SESSION['processed_sale_tokens'][$saleToken] = ['source' => 'order', 'id' => $orderId];
                        $_SESSION['processed_sale_tokens'] = array_slice($_SESSION['processed_sale_tokens'], -30, null, true);
                    }
                    $_SESSION['sale_form_token'] = bin2hex(random_bytes(16));
                    $_SESSION['flash']['success'] = $okMsg;
                    header('Location: ' . public_url($area . '/orders/receipt.php?id=' . $orderId . '&new=1'));
                    exit;
                }
                // Rare: opened fine but settling failed. It's now a normal open
                // tab, recoverable from Credit sales / Payments — not lost.
                $error = ($payRes['error'] ?? 'Could not complete the payment.') . ' The sale was saved as an unpaid credit sale — settle it from Payments.';
            }
        }
        if ($error) { $startMode = $isCredit ? 'credit' : 'pay'; }
    }
}

$heldList = $HO->listForTenant();
if ($customerId > 0 && !isset($customer)) { $customer = $CU->find($customerId); }

$page_title = $isStaffViewer ? 'Home' : 'New sale';
ob_start();
?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<?php if ($heldList): ?>
<div class="pos-held" id="heldStrip">
  <div class="pos-held-title"><i class="fas fa-pause me-1"></i>Held sales <span class="badge bg-warning text-dark"><?php echo count($heldList); ?></span>
    <span class="text-muted small fw-normal ms-1">tap one to continue it — whatever is in the cart now is held for you</span></div>
  <div class="pos-held-row">
    <?php foreach ($heldList as $h): $isCurrent = (int) $h['id'] === $heldOrderId; ?>
      <div class="pos-held-chip<?php echo $isCurrent ? ' current' : ''; ?>">
        <button type="button" class="pos-held-open" data-resume="<?php echo (int) $h['id']; ?>" <?php echo $isCurrent ? 'disabled' : ''; ?>>
          <strong><?php echo htmlspecialchars($h['customer_name']); ?></strong>
          <span><?php echo (int) $h['item_count']; ?> item<?php echo (int) $h['item_count'] === 1 ? '' : 's'; ?> · KES <?php echo number_format((float) $h['total'], 0); ?> · <?php echo date('g:i a', strtotime($h['created_at'])); ?></span>
          <?php if ($isCurrent): ?><em>open now</em><?php endif; ?>
        </button>
        <button type="button" class="pos-held-x" data-discard="<?php echo (int) $h['id']; ?>" title="Discard"><i class="fas fa-xmark"></i></button>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!$products): ?>
  <div class="alert alert-warning">No products in stock to sell. Record stock first.</div>
<?php else: ?>
<form method="post" id="orderForm">
<input type="hidden" name="action" id="formAction" value="pay">
<input type="hidden" name="cart" id="cartInput" value="">
<input type="hidden" name="held_order_id" value="<?php echo (int) $heldOrderId; ?>">
<input type="hidden" name="resume_id" id="resumeId" value="">
<input type="hidden" name="discard_id" id="discardId" value="">
<input type="hidden" name="customer_id" id="customerId" value="<?php echo (int) $customerId; ?>">
<input type="hidden" name="payment_method" id="paymentMethod" value="cash">
<input type="hidden" name="sale_type" id="saleTypeInput" value="<?php echo $saleType; ?>">
<input type="hidden" name="amount_tendered" id="amountTendered" value="">
<input type="hidden" name="cash_amount" id="cashAmount" value="">
<input type="hidden" name="mpesa_amount" id="mpesaAmount" value="">
<input type="hidden" name="serials" id="serialsInput" value="">
<input type="hidden" name="sale_token" value="<?php echo htmlspecialchars($_SESSION['sale_form_token']); ?>">

<div class="pos-grid">
  <div class="pos-main">
    <div class="pos-search">
      <i class="fas fa-magnifying-glass"></i>
      <input type="text" id="search" placeholder="Search products…" autocomplete="off">
    </div>
    <div class="pos-search pos-scan">
      <i class="fas fa-barcode"></i>
      <input type="text" id="barcodeScan" placeholder="Scan a barcode to add it…" autocomplete="off">
    </div>
    <div id="scanMsg" class="small mb-2" style="display:none;"></div>

    <div class="pos-price-mode" id="priceMode">
      <button type="button" class="<?php echo $saleType === 'retail' ? 'active' : ''; ?>" data-sale-type="retail">Retail</button>
      <button type="button" class="<?php echo $saleType === 'wholesale' ? 'active' : ''; ?>" data-sale-type="wholesale">Wholesale</button>
    </div>

    <?php $offerCount = count(array_filter($products, fn($p) => !empty($p['on_offer']))); ?>
    <?php if ($offerCount > 0): ?>
      <button type="button" class="pos-offer-banner" id="offerBanner">
        <i class="fas fa-tag me-1"></i><?php echo $offerCount; ?> product<?php echo $offerCount === 1 ? '' : 's'; ?> on offer right now — tap to see them
      </button>
    <?php endif; ?>

    <div class="pos-dim-tabs" id="dimTabs">
      <button type="button" class="pos-dim active" data-dim="category">Categories</button>
      <button type="button" class="pos-dim" data-dim="offers">Offers</button>
      <button type="button" class="pos-dim" data-dim="archive">Archive</button>
    </div>

    <div class="pos-cats" id="catRow-category" data-dim-row="category">
      <button type="button" class="pos-cat active" data-cat="">
        <span class="pos-cat-img pos-cat-all"><i class="fas fa-border-all"></i></span>
        <span class="pos-cat-name">All</span>
      </button>
      <?php foreach ($categories as $c): ?>
        <button type="button" class="pos-cat" data-cat="<?php echo (int) $c['id']; ?>" title="<?php echo htmlspecialchars($c['name']); ?>">
          <span class="pos-cat-img">
            <?php if (!empty($c['image_path'])): ?>
              <img src="<?php echo htmlspecialchars($c['image_path']); ?>" alt="" onerror="this.outerHTML='<i class=&quot;fas fa-tag&quot;></i>'">
            <?php else: ?>
              <i class="fas fa-tag"></i>
            <?php endif; ?>
          </span>
          <span class="pos-cat-name"><?php echo htmlspecialchars($c['name']); ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <div class="pos-prod-grid" id="productList">
      <?php foreach ($products as $p):
          $price = $unitPrice($p, 'retail');
          $wholePrice = $unitPrice($p, 'wholesale');
          $sz = Models\ProductModel::sizeLabel($p);
          $sub = $sz;
          $label = $p['name'] . ($sub ? " ({$sub})" : '');
      ?>
        <div class="pos-card<?php echo !empty($p['is_archived']) ? ' pos-card-archived' : ''; ?>" data-id="<?php echo (int) $p['id']; ?>" data-name="<?php echo htmlspecialchars($label, ENT_QUOTES); ?>"
             data-price="<?php echo $price; ?>" data-wholesale-price="<?php echo $wholePrice; ?>" data-stock="<?php echo (float) $p['quantity']; ?>"
             data-category="<?php echo (int) ($p['category_id'] ?? 0); ?>"
             data-on-offer="<?php echo !empty($p['on_offer']) ? '1' : '0'; ?>"
             data-archived="<?php echo !empty($p['is_archived']) ? '1' : '0'; ?>"
             data-barcode="<?php echo htmlspecialchars($p['barcode'] ?? '', ENT_QUOTES); ?>">
          <?php if (!empty($p['on_offer'])): ?><span class="pos-ribbon">OFFER</span><?php endif; ?>
          <?php if (!empty($p['is_archived'])): ?><span class="pos-ribbon pos-ribbon-archive">ARCHIVE</span><?php endif; ?>
          <div class="pos-card-img">
            <?php if (!empty($p['image_path'])): ?><img src="<?php echo htmlspecialchars($p['image_path']); ?>" alt="" onerror="this.outerHTML='<i class=&quot;fas fa-box&quot;></i>'">
            <?php else: ?><i class="fas fa-box"></i><?php endif; ?>
          </div>
          <div class="pos-card-name"><?php echo htmlspecialchars($p['name']); ?><?php echo $sub ? '<br><small>' . htmlspecialchars($sub) . '</small>' : ''; ?></div>
          <div class="pos-card-price">
            <?php if (!empty($p['on_offer'])): ?>
              <span class="pos-card-regprice">KES <?php echo number_format((float) $p['regular_price'], 0); ?></span>
              KES <?php echo number_format($price, 0); ?>
            <?php else: ?>
              KES <?php echo number_format($price, 0); ?>
            <?php endif; ?>
          </div>
          <div class="pos-card-stock"><?php echo rtrim(rtrim(number_format((float) $p['quantity'], 2), '0'), '.'); ?> in stock</div>
          <button type="button" class="pos-add"><i class="fas fa-cart-plus me-1"></i>Add</button>
        </div>
      <?php endforeach; ?>
      <div id="noMatch" class="text-muted small text-center py-4" style="display:none;grid-column:1/-1;"><i class="fas fa-search me-1"></i>No products match.</div>
    </div>
  </div>

  <aside class="pos-side" id="posSide">
    <h2 class="pos-side-title">Order Details</h2>
    <div class="pos-customer-wrap">
      <div class="pos-customer">
        <div class="pos-customer-icon"><i class="fas fa-user"></i></div>
        <input type="text" name="table_name" id="customerName" class="pos-customer-input" value="<?php echo htmlspecialchars($customerName); ?>"
               placeholder="Search or type customer name" autocomplete="off">
        <button type="button" class="pos-customer-clear" id="customerClear" title="Back to walk-in"><i class="fas fa-xmark"></i></button>
      </div>
      <div class="pos-cust-menu" id="customerMenu"></div>
    </div>
    <div class="pos-cust-info small" id="customerInfo"></div>
    <div class="form-check small mt-1" id="saveCustomerRow" style="display:none;">
      <input class="form-check-input" type="checkbox" name="save_customer" value="1" id="saveCustomer">
      <label class="form-check-label" for="saveCustomer">Save as a loyal customer</label>
    </div>

    <div class="pos-cart" id="cartRows"><div class="text-muted small text-center py-4">Tap a product to add it.</div></div>

    <div class="pos-totals">
      <div class="d-flex justify-content-between"><span>Sub Total</span><span id="subtotalOut">KES 0</span></div>
      <div class="d-flex justify-content-between align-items-center py-1">
        <span>Discount <span class="text-muted small">(if they negotiate)</span></span>
        <input type="number" step="0.01" min="0" id="discountInput" name="discount_amount" class="form-control form-control-sm" style="width:100px;text-align:right;" placeholder="0" value="<?php echo $discountIn > 0 ? htmlspecialchars((string) $discountIn) : '0'; ?>">
      </div>
      <div class="d-flex justify-content-between pos-total-line"><span>Total</span><span id="totalOut">KES 0</span></div>
    </div>

    <div class="pos-actions <?php echo $canCredit ? 'pos-actions-3' : ''; ?>" id="cartButtons"<?php echo $canCredit ? '' : ' style="grid-template-columns:1fr 1fr;"'; ?>>
      <button type="button" class="pos-btn pos-btn-outline" id="holdBtn" disabled><i class="fas fa-pause me-1"></i>Hold</button>
      <button type="button" class="pos-btn pos-btn-credit" id="creditBtn" disabled<?php echo $canCredit ? '' : ' style="display:none;"'; ?>><i class="fas fa-hand-holding-dollar me-1"></i>Credit</button>
      <button type="button" class="pos-btn pos-btn-primary" id="checkoutBtn" disabled>Checkout</button>
    </div>

    <div id="payPanel" style="display:none;">
      <hr>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong class="small">Paid in full now</strong>
        <button type="button" class="btn btn-sm btn-link p-0 backBtn">Back</button>
      </div>
      <div class="btn-group w-100 mb-2" role="group">
        <input type="radio" class="btn-check" name="pm" id="pmCash" value="cash" checked>
        <label class="btn btn-outline-primary btn-sm" for="pmCash"><i class="fas fa-money-bill-wave me-1"></i>Cash</label>
        <input type="radio" class="btn-check" name="pm" id="pmMpesa" value="mpesa">
        <label class="btn btn-outline-success btn-sm" for="pmMpesa"><i class="fas fa-mobile-screen me-1"></i>M-Pesa</label>
        <input type="radio" class="btn-check" name="pm" id="pmSplit" value="split">
        <label class="btn btn-outline-secondary btn-sm" for="pmSplit"><i class="fas fa-divide me-1"></i>Split</label>
      </div>
      <div id="cashBox" class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small mb-1">Cash given</label><input type="number" step="0.01" min="0" id="cashGivenInput" class="form-control form-control-sm"></div>
      </div>
      <div id="splitBox" style="display:none;" class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small mb-1">Cash portion</label><input type="number" step="0.01" min="0" id="cashPortionInput" class="form-control form-control-sm"></div>
        <div class="col-6"><label class="form-label small mb-1">M-Pesa portion</label><input type="number" step="0.01" min="0" id="mpesaPortionInput" class="form-control form-control-sm"></div>
      </div>
      <div class="mb-2">
        <label class="form-label small mb-1">Balance</label>
        <div class="form-control form-control-sm bg-light fw-semibold" id="balanceOut">KES 0</div>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-2 mb-2">
        <span class="text-muted small">Total Payable</span>
        <span class="fw-bold fs-5" id="payableOut">KES 0</span>
      </div>
      <button type="submit" class="pos-btn pos-btn-primary w-100" id="payNowBtn">Pay Now</button>
    </div>

    <div id="creditPanel" style="display:none;">
      <hr>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong class="small"><i class="fas fa-hand-holding-dollar me-1 text-warning"></i>Credit sale — pay later</strong>
        <button type="button" class="btn btn-sm btn-link p-0 backBtn">Back</button>
      </div>
      <div class="small text-muted mb-2">The customer takes the goods now. Record any deposit they leave; the rest is tracked as their balance.</div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small mb-1">Phone</label><input type="text" name="customer_phone" id="creditPhone" class="form-control form-control-sm" placeholder="07…" value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"></div>
        <div class="col-6"><label class="form-label small mb-1">Email <span class="text-muted">(optional)</span></label><input type="email" name="customer_email" id="creditEmail" class="form-control form-control-sm" value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small mb-1">Deposit now <span class="text-muted">(optional)</span></label><input type="number" step="0.01" min="0" name="deposit_amount" id="depositInput" class="form-control form-control-sm" placeholder="0"></div>
        <div class="col-6"><label class="form-label small mb-1">Paid via</label>
          <select name="deposit_method" class="form-select form-select-sm"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option></select></div>
      </div>
      <div class="d-flex justify-content-between small"><span class="text-muted">Total</span><span id="creditTotalOut">KES 0</span></div>
      <div class="d-flex justify-content-between fw-bold mb-2"><span>Balance owed</span><span id="creditBalanceOut" class="text-danger">KES 0</span></div>
      <button type="submit" class="pos-btn pos-btn-credit w-100" id="creditSubmitBtn">Record credit sale</button>
    </div>
  </aside>
</div>
</form>

<!-- Phones: the cart's total + a jump to checkout, always in reach above the bottom menu. -->
<div class="pos-mbar" id="posMbar" style="display:none;">
  <div class="pos-mbar-sum"><strong id="mbCount">0</strong> item<span id="mbPlural">s</span> · <strong id="mbTotal">KES 0</strong></div>
  <button type="button" class="pos-mbar-btn" id="mbGo"><i class="fas fa-cart-shopping me-1"></i>Cart &amp; checkout</button>
</div>

<?php if ($trackedProducts): ?>
<div class="pos-sn-backdrop" id="unitModal" style="display:none;">
  <div class="pos-sn-box" role="dialog" aria-modal="true" aria-labelledby="unitTitle">
    <div class="d-flex justify-content-between align-items-center mb-1">
      <strong><i class="fas fa-mobile-screen me-1 text-success"></i><span id="unitTitle"></span></strong>
      <button type="button" class="btn btn-sm btn-link text-muted p-0" id="unitCancel">Cancel</button>
    </div>
    <div class="small text-muted mb-3">Warranty <strong id="unitWarranty"></strong> — starts when the sale is completed.</div>
    <label class="form-label small mb-1">Serial / IMEI</label>
    <input type="text" id="unitSerial" class="form-control mb-2 text-uppercase" list="unitSerialList" autocomplete="off" placeholder="Scan or type">
    <datalist id="unitSerialList"></datalist>
    <label class="form-label small mb-1">Colour</label>
    <select id="unitColor" class="form-select mb-2"></select>
    <input type="text" id="unitColorText" class="form-control mb-2" placeholder="e.g. Black" maxlength="40">
    <div class="row g-2 mb-2">
      <div class="col-7"><label class="form-label small mb-1">Buyer's name</label><input type="text" id="unitBuyer" class="form-control" maxlength="150"></div>
      <div class="col-5"><label class="form-label small mb-1">Phone</label><input type="tel" id="unitPhone" class="form-control" placeholder="07…" maxlength="40"></div>
    </div>
    <div class="text-danger small mb-2" id="unitErr"></div>
    <button type="button" class="pos-btn pos-btn-primary w-100" id="unitOk">Add to sale</button>
  </div>
</div>
<div class="pos-sn-backdrop" id="snModal" style="display:none;">
  <div class="pos-sn-box" role="dialog" aria-modal="true" aria-labelledby="snTitle">
    <div class="d-flex justify-content-between align-items-center mb-1">
      <strong id="snTitle"><i class="fas fa-mobile-screen me-1 text-success"></i>Serial / IMEI numbers</strong>
      <button type="button" class="btn btn-sm btn-link text-muted p-0" id="snCancel">Cancel</button>
    </div>
    <div class="small text-muted mb-3">Scan or type the serial / IMEI of each unit sold. The warranty starts today. These don't print on the receipt.</div>
    <div id="snRows"></div>
    <div class="text-danger small mb-2" id="snErr"></div>
    <button type="button" class="pos-btn pos-btn-primary w-100" id="snOk">Confirm &amp; complete sale</button>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<style>
.pos-sn-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:2000;display:flex;align-items:center;justify-content:center;padding:16px;}
.pos-sn-box{background:#fff;border-radius:16px;padding:20px;width:100%;max-width:440px;max-height:88svh;overflow-y:auto;box-shadow:0 20px 50px rgba(0,0,0,.25);}
.pos-cart-units{display:flex;flex-wrap:wrap;gap:4px;margin:3px 0;}
.pos-cart-units span{font-size:.68rem;background:#f0fdf4;border:1px solid #bbf0d1;color:#166534;border-radius:6px;padding:1px 6px;font-family:ui-monospace,monospace;}
.pos-sn-prod{font-weight:700;font-size:.88rem;margin:10px 0 6px;}
.pos-sn-prod span{font-weight:400;color:#7a7f8c;font-size:.78rem;}
.pos-sn-row{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
.pos-sn-row b{width:22px;color:#9aa0ac;font-size:.78rem;}
.pos-sn-row input{flex:1;min-width:0;border:1px solid #e2e4ea;border-radius:10px;padding:9px 12px;font-size:16px;text-transform:uppercase;}
.pos-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:20px;align-items:start;}
.pos-main,.pos-side{min-width:0;}
.pos-search{position:relative;margin-bottom:14px;}
.pos-search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#b7bac3;}
.pos-search input{width:100%;padding:12px 14px 12px 40px;border:1px solid #eef0f4;border-radius:12px;background:#fff;font-size:.92rem;}
.pos-search input:focus{outline:none;border-color:var(--pos-green);box-shadow:0 0 0 .2rem rgba(22,163,74,.1);}
.pos-scan input{border-color:var(--pos-green-light);background:var(--pos-green-light);}
.pos-scan i{color:var(--pos-green);}
.pos-dim-tabs{display:flex;gap:8px;margin-bottom:12px;overflow-x:auto;padding-bottom:2px;}
.pos-price-mode{display:inline-flex;gap:4px;border:1px solid #eef0f4;background:#fff;border-radius:999px;padding:4px;margin-bottom:12px;}
.pos-price-mode button{border:0;background:transparent;color:#5b6070;border-radius:999px;padding:8px 14px;font-size:.8rem;font-weight:700;min-height:38px;}
.pos-price-mode button.active{background:var(--pos-green);color:#fff;}
.pos-dim{border:1px solid #eef0f4;background:#fff;color:#5b6070;border-radius:999px;padding:8px 14px;font-size:.8rem;font-weight:600;min-height:38px;white-space:nowrap;}
.pos-dim.active{border-color:var(--pos-green);color:var(--pos-green);background:var(--pos-green-light);}
.pos-cats{display:flex;gap:10px;overflow-x:auto;padding-bottom:8px;margin-bottom:16px;}
.pos-cat{flex:0 0 auto;width:104px;min-height:104px;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:8px;border:1px solid #eef0f4;background:#fff;border-radius:14px;padding:12px 8px;font-size:.76rem;font-weight:600;color:#5b6070;}
/* Long category names wrap inside the box (2 lines max, then …) instead of running into the next one. */
.pos-cat-name{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;width:100%;text-align:center;line-height:1.25;word-break:break-word;white-space:normal;}
.pos-cat-img{width:44px;height:44px;border-radius:12px;background:#f7f7fb;display:flex;align-items:center;justify-content:center;overflow:hidden;color:#b7bac3;font-size:1.1rem;}
.pos-cat-img img{width:100%;height:100%;object-fit:cover;}
.pos-cat.active{border-color:var(--pos-green);color:var(--pos-green);background:var(--pos-green-light);}
.pos-cat.active .pos-cat-img, .pos-cat.active .pos-cat-all{background:#fff;color:var(--pos-green);}
.pos-prod-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;}
@media (min-width:520px){ .pos-prod-grid{grid-template-columns:repeat(3,1fr);} }
@media (min-width:900px){ .pos-prod-grid{grid-template-columns:repeat(4,1fr);} }
@media (min-width:1300px){ .pos-prod-grid{grid-template-columns:repeat(5,1fr);} }
.pos-card{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:14px;text-align:center;transition:box-shadow .15s;min-width:0;}
.pos-card:hover{box-shadow:0 4px 16px rgba(16,24,40,.08);}
.pos-card-img{height:64px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;}
.pos-card-img img{max-height:64px;max-width:100%;object-fit:contain;}
.pos-card-img i{font-size:1.8rem;color:#d7d9df;}
.pos-card-name{font-weight:600;font-size:.85rem;color:#1f2330;margin-bottom:4px;min-height:2.2em;}
.pos-card-name small{color:#9aa0ac;font-weight:400;}
.pos-card-price{color:var(--pos-green);font-weight:700;font-size:.85rem;margin-bottom:10px;}
.pos-card{position:relative;}
.pos-card-regprice{color:#9aa0ac;font-weight:400;text-decoration:line-through;margin-right:5px;font-size:.8em;}
.pos-ribbon{position:absolute;top:8px;left:8px;background:#f59e0b;color:#fff;font-size:.62rem;font-weight:800;letter-spacing:.03em;padding:2px 7px;border-radius:999px;z-index:2;}
.pos-ribbon-archive{left:auto;right:8px;background:#475569;}
.pos-card-archived{opacity:.9;}
.pos-offer-banner{display:block;width:100%;text-align:left;border:1px solid #fde68a;background:#fffbeb;color:#92400e;border-radius:12px;padding:10px 14px;font-size:.85rem;font-weight:600;margin-bottom:14px;cursor:pointer;}
.pos-offer-banner:hover{background:#fef3c7;}
.pos-add{width:100%;border:0;border-radius:10px;background:var(--pos-green);color:#fff;padding:10px 0;font-weight:600;font-size:.82rem;min-height:42px;}
.pos-add:hover{background:var(--pos-green-dark);}

.pos-side{background:#fff;border:1px solid #eef0f4;border-radius:16px;padding:20px;position:sticky;top:20px;max-height:calc(100vh - 40px);overflow-y:auto;}
.pos-side-title{font-size:1.1rem;font-weight:800;margin-bottom:14px;}
.pos-customer{display:flex;align-items:center;gap:10px;background:#f7f7fb;border-radius:12px;padding:10px 12px;margin-bottom:6px;}
.pos-customer-icon{width:34px;height:34px;border-radius:50%;background:var(--pos-green-light);color:var(--pos-green);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.pos-customer-input{border:0;background:transparent;flex:1;font-weight:600;font-size:.9rem;}
.pos-customer-input:focus{outline:none;}
.pos-cart{max-height:280px;overflow-y:auto;margin:14px 0;}
.pos-cart-line{display:flex;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #f3f4f7;min-width:0;}
.pos-cart-line img, .pos-cart-line .ph{width:38px;height:38px;border-radius:8px;object-fit:cover;background:#f3f4f7;display:flex;align-items:center;justify-content:center;color:#d7d9df;flex-shrink:0;}
.pos-cart-name{font-weight:600;font-size:.85rem;color:#1f2330;}
.pos-cart-price{color:#9aa0ac;font-size:.76rem;}
.pos-qty{display:flex;align-items:center;gap:6px;}
.pos-qty button{width:30px;height:30px;border-radius:8px;border:1px solid #eef0f4;background:#fff;font-weight:700;line-height:1;}
.pos-cart-del{color:#64748b;background:none;border:0;font-size:.9rem;width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;}
.pos-totals{border-top:1px dashed #eef0f4;padding-top:12px;font-size:.9rem;color:#5b6070;}
.pos-total-line{font-weight:800;font-size:1.05rem;color:#1f2330;margin-top:6px;}
.pos-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;}
.pos-btn{border-radius:12px;padding:12px 0;font-weight:700;font-size:.9rem;border:1px solid #eef0f4;min-height:48px;}
.pos-btn-outline{background:#fff;color:#5b6070;}
.pos-btn-primary{background:var(--pos-green);border-color:var(--pos-green);color:#fff;}
.pos-btn:disabled{opacity:.5;}
@media (max-width:900px){
  .pos-grid{grid-template-columns:1fr;}
  /* Cart stays put — pinned to the top of the screen, not lost below a long
     product list. It scrolls its own contents (bounded height) while the
     product grid scrolls with the page underneath it. */
  /* The cart sits at the top as an ordinary box — no longer pinned over the
     products (that's what made boxes pile on top of each other). The
     .pos-mbar bar below keeps the total and checkout one tap away. */
  .pos-side{position:static;order:-1;max-height:none;overflow:visible;z-index:auto;margin-bottom:4px;}
  .pos-cart{max-height:40vh;}
  .pos-mbar.show{display:flex!important;}
  .pos-main{padding-bottom:84px;}
}
@media (max-width:576px){
  .pos-grid{gap:14px;}
  .pos-main{padding-bottom:12px;}
  .pos-search{margin-bottom:10px;}
  .pos-search input{font-size:16px;min-height:48px;border-radius:14px;}
  .pos-price-mode{display:grid;grid-template-columns:1fr 1fr;width:100%;border-radius:14px;}
  .pos-price-mode button{border-radius:10px;font-size:.86rem;}
  .pos-dim-tabs{gap:7px;margin-left:-2px;margin-right:-2px;}
  .pos-dim{flex:1 0 auto;font-size:.82rem;}
  .pos-cats{gap:8px;margin-left:-2px;margin-right:-2px;}
  .pos-cat{width:88px;min-height:92px;border-radius:12px;font-size:.7rem;padding:10px 6px;}
  .pos-cat-img{width:38px;height:38px;border-radius:10px;}
  .pos-prod-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
  .pos-card{padding:10px;border-radius:12px;}
  .pos-card-img{height:54px;margin-bottom:8px;}
  .pos-card-img img{max-height:54px;}
  .pos-card-name{font-size:.78rem;min-height:2.5em;line-height:1.25;overflow-wrap:anywhere;}
  .pos-card-price{font-size:.8rem;margin-bottom:8px;}
  .pos-add{font-size:.78rem;}
  .pos-side{border-radius:14px;padding:14px;}
  .pos-side-title{font-size:1rem;margin-bottom:10px;}
  .pos-customer{padding:9px 10px;}
  .pos-customer-input{font-size:16px;}
  .pos-cart{max-height:40vh;margin:10px 0;}
  .pos-cart-line{gap:8px;padding:9px 0;}
  .pos-cart-line img,.pos-cart-line .ph{width:34px;height:34px;}
  .pos-cart-name{font-size:.78rem;line-height:1.25;overflow-wrap:anywhere;}
  .pos-cart-price{font-size:.72rem;}
  .pos-qty{gap:4px;}
  .pos-qty button{width:32px;height:32px;}
  .pos-totals{font-size:.84rem;}
  .pos-total-line{font-size:1rem;}
  .pos-actions{gap:8px;margin-top:12px;}
  #discountInput{width:86px!important;font-size:16px;}
  #payPanel .btn-group{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));}
  #payPanel .btn-group>.btn{font-size:.76rem;padding:9px 4px;border-radius:0;white-space:normal;}
  #cashBox .col-6,#splitBox .col-6{width:100%;}
  #payPanel input{font-size:16px;min-height:44px;}
}
@media (max-width:360px){
  .pos-prod-grid{gap:8px;}
  .pos-card{padding:8px;}
  .pos-card-name{font-size:.74rem;}
  .pos-add{font-size:.74rem;min-height:38px;}
}
.pos-mbar{position:fixed;left:10px;right:10px;bottom:calc(72px + env(safe-area-inset-bottom));z-index:40;align-items:center;justify-content:space-between;gap:10px;
          background:#1f2330;color:#fff;border-radius:14px;padding:10px 10px 10px 16px;box-shadow:0 8px 24px rgba(16,24,40,.25);font-size:.88rem;}
.pos-mbar-btn{border:0;border-radius:10px;background:var(--pos-green);color:#fff;font-weight:700;padding:10px 14px;font-size:.85rem;white-space:nowrap;}
@media (min-width:901px){ .pos-mbar{display:none!important;} }
</style>
<style>
.pos-actions-3{grid-template-columns:1fr 1fr 1fr;}
.pos-btn-credit{background:#f59e0b;border-color:#f59e0b;color:#fff;}
.pos-card-stock{font-size:.7rem;color:#9aa0ac;margin:-6px 0 8px;}
.pos-qty-input{width:56px;height:30px;border:1px solid #e2e5ec;border-radius:8px;text-align:center;font-weight:700;font-size:.85rem;padding:0 2px;-moz-appearance:textfield;}
.pos-qty-input::-webkit-outer-spin-button,.pos-qty-input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.pos-qty-input:focus{outline:none;border-color:var(--pos-green);}
.pos-cart-line .pos-line-total{font-size:.74rem;color:#1f2330;font-weight:700;}
.pos-customer-wrap{position:relative;}
.pos-customer-clear{border:0;background:none;color:#9aa0ac;width:28px;height:28px;flex-shrink:0;}
.pos-cust-menu{position:absolute;left:0;right:0;top:100%;z-index:50;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 24px rgba(15,23,42,.12);margin-top:4px;max-height:260px;overflow-y:auto;display:none;}
.pos-cust-menu.show{display:block;}
.pos-cust-menu button{display:block;width:100%;text-align:left;border:0;background:none;padding:9px 12px;font-size:.85rem;border-bottom:1px solid #f3f4f7;}
.pos-cust-menu button:hover,.pos-cust-menu button.active{background:#f7f7fb;}
.pos-cust-menu small{color:#9aa0ac;display:block;}
.pos-cust-menu .owe{color:#b91c1c;font-weight:700;}
.pos-cust-menu .empty{padding:9px 12px;font-size:.8rem;color:#9aa0ac;}
.pos-cust-info{color:#5b6070;min-height:0;margin-top:4px;}
.pos-cust-info .owe{color:#b91c1c;font-weight:700;}
.pos-held{background:#fffbeb;border:1px solid #fde68a;border-radius:14px;padding:10px 12px;margin-bottom:14px;}
.pos-held-title{font-weight:700;font-size:.85rem;color:#92400e;margin-bottom:8px;}
.pos-held-row{display:flex;gap:8px;overflow-x:auto;padding-bottom:2px;}
.pos-held-chip{display:flex;align-items:stretch;background:#fff;border:1px solid #fde68a;border-radius:12px;flex:0 0 auto;}
.pos-held-chip.current{border-color:var(--pos-green);background:var(--pos-green-light);}
.pos-held-open{border:0;background:none;text-align:left;padding:8px 10px;font-size:.8rem;line-height:1.25;min-height:44px;}
.pos-held-open span{display:block;color:#6b7280;font-size:.72rem;}
.pos-held-open em{display:block;color:var(--pos-green);font-style:normal;font-size:.7rem;font-weight:700;}
.pos-held-x{border:0;border-left:1px solid #fde68a;background:none;color:#9ca3af;width:36px;}
@media (max-width:576px){
  .pos-actions-3 .pos-btn{font-size:.8rem;}
  .pos-qty-input{width:50px;height:32px;font-size:16px;}
  #creditPanel input,#creditPanel select{font-size:16px;min-height:44px;}
}
</style>
<script>
(function () {
var form = document.getElementById('orderForm');
var heldStrip = document.getElementById('heldStrip');
var WALKIN = <?php echo json_encode(POS_WALKIN); ?>;

// Held-sales strip works even when there's nothing to sell right now.
if (heldStrip) {
    heldStrip.addEventListener('click', function (e) {
        var open = e.target.closest('[data-resume]'), x = e.target.closest('[data-discard]');
        if (!form) { if (open) { location.href = <?php echo json_encode($posUrl); ?> + '?resume=' + open.dataset.resume; } return; }
        if (open) {
            document.getElementById('formAction').value = 'swap';
            document.getElementById('resumeId').value = open.dataset.resume;
            form.dataset.submitting = '1';
            form.submit();
        } else if (x && confirm('Discard this held sale? Nothing was sold, so stock is not affected.')) {
            document.getElementById('formAction').value = 'discard_held';
            document.getElementById('discardId').value = x.dataset.discard;
            form.dataset.submitting = '1';
            form.submit();
        }
    });
}
if (!form) { return; }

var PRODUCTS = {};
var BARCODES = {};
var saleType = document.getElementById('saleTypeInput').value === 'wholesale' ? 'wholesale' : 'retail';
document.querySelectorAll('.pos-card').forEach(function (el) {
    var img = el.querySelector('.pos-card-img img');
    PRODUCTS[el.dataset.id] = {
        name: el.dataset.name,
        retailPrice: parseFloat(el.dataset.price),
        wholesalePrice: parseFloat(el.dataset.wholesalePrice || el.dataset.price),
        stock: parseFloat(el.dataset.stock),
        img: img ? img.getAttribute('src') : null
    };
    if (el.dataset.barcode) { BARCODES[el.dataset.barcode] = el.dataset.id; }
});
var cart = {};
// Electronics: tracked products are sold one unit at a time, each with its
// serial / IMEI, colour and the buyer's details (units[pid] = [{serial, color}]).
var TRACKED = <?php echo json_encode((object) array_map(fn($t) => Models\ElectronicsModel::warrantyLabel($t['warranty_months']), $trackedProducts)); ?>;
var TRACKED_COLORS = <?php echo json_encode((object) array_map(fn($t) => $t['colors'], $trackedProducts)); ?>;
var STOCK_SERIALS = <?php echo json_encode((object) $stockSerials); ?>;
var units = {};
function isTracked(id) { return TRACKED[id] !== undefined; }
try { (JSON.parse(<?php echo json_encode($cartJson); ?>) || []).forEach(function (c) { if (PRODUCTS[c.product_id]) { cart[c.product_id] = parseFloat(c.quantity); } }); } catch (e) {}
function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function money(n) { return 'KES ' + (Math.round(n * 100) / 100).toLocaleString('en-KE', {maximumFractionDigits: 2}); }
function fmtQty(n) { return String(Math.round(n * 100) / 100); }
function priceOf(p) { return saleType === 'wholesale' ? p.wholesalePrice : p.retailPrice; }

// Quantities can be typed (e.g. 200) — no more tapping + two hundred times.
function clampQty(id, val) {
    var p = PRODUCTS[id];
    val = Math.round((parseFloat(val) || 0) * 100) / 100;
    if (val > p.stock) { val = p.stock; flashScan('Only ' + fmtQty(p.stock) + ' × ' + p.name + ' in stock.', false); }
    return val;
}
function setQty(id, val) {
    if (!PRODUCTS[id]) return;
    val = clampQty(id, val);
    if (val <= 0) { delete cart[id]; } else { cart[id] = val; }
    render();
}
function add(id) {
    if (isTracked(id)) { openUnit(id); return; }
    setQty(id, (cart[id] || 0) + 1);
}
function dropUnit(id) {
    if (units[id] && units[id].length) { units[id].pop(); }
    if (units[id] && units[id].length) { cart[id] = units[id].length; } else { delete units[id]; delete cart[id]; }
    render();
}
function subtotal() { var t = 0; for (var id in cart) { t += priceOf(PRODUCTS[id]) * cart[id]; } return Math.round(t * 100) / 100; }
function total() {
    var sub = subtotal();
    var d = parseFloat(document.getElementById('discountInput').value) || 0;
    if (d < 0) d = 0;
    if (d > sub) d = sub;
    return sub - d;
}
function syncCartInput() {
    var ids = Object.keys(cart);
    document.getElementById('cartInput').value = JSON.stringify(ids.map(function (id) { return { product_id: parseInt(id, 10), quantity: cart[id] }; }));
    var si = document.getElementById('serialsInput');
    if (si && Object.keys(units).length) { si.value = JSON.stringify(units); }
    ['holdBtn', 'checkoutBtn', 'creditBtn'].forEach(function (b) { document.getElementById(b).disabled = ids.length === 0; });
}
function updateMobileBar() {
    var bar = document.getElementById('posMbar'); if (!bar) { return; }
    var n = 0; for (var id in cart) { n += cart[id]; }
    n = Math.round(n * 100) / 100;
    document.getElementById('mbCount').textContent = n;
    document.getElementById('mbPlural').textContent = n === 1 ? '' : 's';
    document.getElementById('mbTotal').textContent = money(total());
    // Only needed once the cart's own buttons are off screen — otherwise it
    // would sit on top of Hold / Credit / Checkout.
    var acts = document.querySelector('.pos-actions'), r = acts ? acts.getBoundingClientRect() : null;
    var buttonsOnScreen = r && r.bottom > 0 && r.top < window.innerHeight;
    bar.classList.toggle('show', Object.keys(cart).length > 0 && !buttonsOnScreen);
}
window.addEventListener('scroll', updateMobileBar, { passive: true });
window.addEventListener('resize', updateMobileBar);
function updateTotals() {
    updateMobileBar();
    syncCartInput();
    document.getElementById('subtotalOut').textContent = money(subtotal());
    document.getElementById('totalOut').textContent = money(total());
    document.getElementById('payableOut').textContent = money(total());
    document.querySelectorAll('.pos-cart-line').forEach(function (line) {
        var id = line.dataset.id, out = line.querySelector('.pos-line-total');
        if (out && PRODUCTS[id]) { out.textContent = money(priceOf(PRODUCTS[id]) * (cart[id] || 0)); }
    });
    updatePayFields();
    updateCredit();
}

function render() {
    var wrap = document.getElementById('cartRows'), ids = Object.keys(cart);
    wrap.innerHTML = ids.length ? '' : '<div class="text-muted small text-center py-4">Tap a product to add it.</div>';
    ids.forEach(function (id) {
        var p = PRODUCTS[id], qty = cart[id];
        var line = document.createElement('div');
        line.className = 'pos-cart-line';
        line.dataset.id = id;
        var unitList = isTracked(id) && units[id] ? '<div class="pos-cart-units">' + units[id].map(function (u) { return '<span>' + esc(u.serial) + (u.color ? ' · ' + esc(u.color) : '') + '</span>'; }).join('') + '</div>' : '';
        var qtyBox = isTracked(id) && units[id]
          ? '<span class="pos-qty-input d-inline-flex align-items-center justify-content-center">' + fmtQty(qty) + '</span>'
          : '<input type="number" inputmode="decimal" min="0" step="any" class="pos-qty-input" data-qty="' + id + '" value="' + fmtQty(qty) + '" aria-label="Quantity">';
        line.innerHTML = (p.img ? '<img src="' + esc(p.img) + '">' : '<div class="ph"><i class="fas fa-box"></i></div>')
          + '<div class="flex-grow-1" style="min-width:0;"><div class="pos-cart-name">' + esc(p.name) + '</div><div class="pos-cart-price">' + money(priceOf(p)) + ' · ' + (saleType === 'wholesale' ? 'Wholesale' : 'Retail') + '</div>' + unitList + '<div class="pos-line-total"></div></div>'
          + '<div class="pos-qty"><button type="button" data-dec="' + id + '" aria-label="One less">−</button>'
          + qtyBox
          + '<button type="button" data-inc="' + id + '" aria-label="One more">+</button></div>'
          + '<button type="button" class="pos-cart-del" data-del="' + id + '"><i class="fas fa-trash"></i></button>';
        wrap.appendChild(line);
    });
    updateTotals();
}
document.getElementById('discountInput').addEventListener('input', updateTotals);
document.querySelectorAll('#priceMode button').forEach(function (btn) {
    btn.addEventListener('click', function () {
        saleType = btn.dataset.saleType === 'wholesale' ? 'wholesale' : 'retail';
        document.getElementById('saleTypeInput').value = saleType;
        document.querySelectorAll('#priceMode button').forEach(function (b) { b.classList.toggle('active', b === btn); });
        document.querySelectorAll('.pos-card').forEach(function (card) {
            var p = PRODUCTS[card.dataset.id];
            var priceEl = card.querySelector('.pos-card-price');
            if (p && priceEl) { priceEl.textContent = money(priceOf(p)); }
        });
        render();
    });
});
if (saleType === 'wholesale') { document.querySelector('#priceMode button[data-sale-type="wholesale"]').click(); }

document.querySelectorAll('.pos-card .pos-add').forEach(function (b) { b.addEventListener('click', function () { add(b.closest('.pos-card').dataset.id); }); });
var cartRows = document.getElementById('cartRows');
cartRows.addEventListener('click', function (e) {
    var t = e.target.closest('button'); if (!t) return;
    if (t.dataset.inc) add(t.dataset.inc);
    else if (t.dataset.dec) { if (units[t.dataset.dec]) { dropUnit(t.dataset.dec); } else { setQty(t.dataset.dec, (cart[t.dataset.dec] || 0) - 1); } }
    else if (t.dataset.del) { delete cart[t.dataset.del]; delete units[t.dataset.del]; render(); }
});
// Typing: keep the field focused and just refresh totals; commit (clamp,
// drop zero lines) when they leave the field or press Enter.
cartRows.addEventListener('input', function (e) {
    var id = e.target.dataset.qty; if (!id) return;
    var v = parseFloat(e.target.value);
    if (v > 0) { cart[id] = Math.min(v, PRODUCTS[id].stock); updateTotals(); }
});
cartRows.addEventListener('change', function (e) {
    var id = e.target.dataset.qty; if (id) { setQty(id, e.target.value); }
});
cartRows.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.dataset.qty) { e.preventDefault(); e.target.blur(); }
});
cartRows.addEventListener('focusin', function (e) { if (e.target.dataset.qty) { e.target.select(); } });

var searchInput = document.getElementById('search');
var activeDim = 'category';
function activeCatFor(dim) {
    var row = document.querySelector('.pos-cats[data-dim-row="' + dim + '"]');
    var btn = row && row.querySelector('.pos-cat.active');
    return btn ? btn.dataset.cat : '';
}
function applyFilters() {
    var q = searchInput.value.toLowerCase().trim();
    var any = false;
    document.querySelectorAll('.pos-card').forEach(function (el) {
        var matchesText = q === '' || el.dataset.name.toLowerCase().indexOf(q) !== -1 || (el.dataset.barcode && el.dataset.barcode.indexOf(q) !== -1);
        var matchesDim;
        if (activeDim === 'offers') {
            matchesDim = el.dataset.onOffer === '1';
        } else if (activeDim === 'archive') {
            matchesDim = el.dataset.archived === '1';
        } else {
            var activeCat = activeCatFor(activeDim);
            matchesDim = el.dataset.archived !== '1' && (activeCat === '' || el.dataset[activeDim] === activeCat);
        }
        var show = matchesText && matchesDim;
        el.style.display = show ? '' : 'none';
        if (show) any = true;
    });
    document.getElementById('noMatch').style.display = any ? 'none' : 'block';
}
function selectDim(dim) {
    var tab = document.querySelector('.pos-dim[data-dim="' + dim + '"]');
    if (!tab) return;
    document.querySelectorAll('.pos-dim').forEach(function (x) { x.classList.remove('active'); });
    tab.classList.add('active');
    activeDim = dim;
    document.querySelectorAll('.pos-cats[data-dim-row]').forEach(function (row) {
        row.style.display = row.dataset.dimRow === activeDim ? 'flex' : 'none';
    });
    applyFilters();
}
searchInput.addEventListener('input', applyFilters);
document.querySelectorAll('.pos-cats[data-dim-row]').forEach(function (row) {
    row.addEventListener('click', function (e) {
        var b = e.target.closest('.pos-cat'); if (!b) return;
        row.querySelectorAll('.pos-cat').forEach(function (x) { x.classList.remove('active'); });
        b.classList.add('active');
        applyFilters();
    });
});
document.getElementById('dimTabs').addEventListener('click', function (e) {
    var b = e.target.closest('.pos-dim'); if (!b) return;
    selectDim(b.dataset.dim);
});
var offerBanner = document.getElementById('offerBanner');
if (offerBanner) { offerBanner.addEventListener('click', function () { selectDim('offers'); }); }

// ---- Customer search (loyal customers + earlier credit customers) ----
var custInput = document.getElementById('customerName');
var custId = document.getElementById('customerId');
var custMenu = document.getElementById('customerMenu');
var custInfo = document.getElementById('customerInfo');
var saveRow = document.getElementById('saveCustomerRow');
var custTimer = null, custItems = [], custActive = -1;
var CUSTOMER_API = <?php echo json_encode(public_url('api/customers/search.php')); ?>;
function isWalkin() { var v = custInput.value.trim(); return v === '' || v.toLowerCase() === WALKIN.toLowerCase(); }
function refreshCustInfo(owed, phone) {
    var parts = [];
    if (custId.value !== '0' && custId.value !== '') { parts.push('<i class="fas fa-star text-warning me-1"></i>Loyal customer' + (phone ? ' · ' + esc(phone) : '')); }
    if (owed > 0) { parts.push('<span class="owe">Owes ' + money(owed) + '</span>'); }
    custInfo.innerHTML = parts.join(' · ');
    saveRow.style.display = (!isWalkin() && (custId.value === '0' || custId.value === '')) ? 'block' : 'none';
}
function pickCustomer(c) {
    custInput.value = c.name;
    custId.value = c.saved ? c.id : 0;
    if (c.phone) { document.getElementById('creditPhone').value = c.phone; }
    if (c.email) { document.getElementById('creditEmail').value = c.email; }
    custMenu.classList.remove('show');
    refreshCustInfo(c.owed || 0, c.phone);
}
function showCustMenu() {
    var q = isWalkin() ? '' : custInput.value.trim();
    fetch(CUSTOMER_API + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        custItems = data.items || [];
        custActive = -1;
        if (!custItems.length) {
            custMenu.innerHTML = q ? '<div class="empty">No saved customer called "' + esc(q) + '" — keep typing to sell to a new one.</div>' : '<div class="empty">No saved customers yet.</div>';
        } else {
            custMenu.innerHTML = custItems.map(function (c, i) {
                return '<button type="button" data-i="' + i + '"><strong>' + esc(c.name) + '</strong>' + (c.saved ? ' <i class="fas fa-star text-warning"></i>' : '')
                  + '<small>' + esc([c.phone, c.company_name].filter(Boolean).join(' · ') || (c.saved ? 'Saved customer' : 'From earlier sales'))
                  + (c.owed > 0 ? ' · <span class="owe">owes ' + money(c.owed) + '</span>' : '') + '</small></button>';
            }).join('');
        }
        custMenu.classList.add('show');
      }).catch(function () {});
}
custInput.addEventListener('focus', function () { if (isWalkin()) { custInput.select(); } showCustMenu(); });
custInput.addEventListener('input', function () {
    custId.value = 0;
    refreshCustInfo(0);
    clearTimeout(custTimer);
    custTimer = setTimeout(showCustMenu, 200);
});
custInput.addEventListener('keydown', function (e) {
    var btns = custMenu.querySelectorAll('button');
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!btns.length) return;
        e.preventDefault();
        custActive = (custActive + (e.key === 'ArrowDown' ? 1 : -1) + btns.length) % btns.length;
        btns.forEach(function (b, i) { b.classList.toggle('active', i === custActive); });
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (custActive >= 0 && custItems[custActive]) { pickCustomer(custItems[custActive]); } else { custMenu.classList.remove('show'); }
    } else if (e.key === 'Escape') { custMenu.classList.remove('show'); }
});
custMenu.addEventListener('mousedown', function (e) {
    var b = e.target.closest('button[data-i]'); if (!b) return;
    e.preventDefault();
    pickCustomer(custItems[parseInt(b.dataset.i, 10)]);
});
custInput.addEventListener('blur', function () {
    setTimeout(function () { custMenu.classList.remove('show'); }, 150);
    if (custInput.value.trim() === '') { custInput.value = WALKIN; refreshCustInfo(0); }
});
document.getElementById('customerClear').addEventListener('click', function () {
    custInput.value = WALKIN; custId.value = 0; refreshCustInfo(0);
});
refreshCustInfo(0, <?php echo json_encode($customer['phone'] ?? ''); ?>);

// ---- Hold / Credit / Checkout ----
function showPanel(which) {
    document.getElementById('cartButtons').style.display = which ? 'none' : '';
    document.getElementById('payPanel').style.display = which === 'pay' ? 'block' : 'none';
    document.getElementById('creditPanel').style.display = which === 'credit' ? 'block' : 'none';
    document.getElementById('formAction').value = which === 'credit' ? 'credit' : 'pay';
    if (which === 'credit' && isWalkin()) { custInput.value = ''; custInput.focus(); }
    updateTotals();
}
document.querySelectorAll('.backBtn').forEach(function (b) { b.addEventListener('click', function () { showPanel(''); }); });
document.getElementById('holdBtn').addEventListener('click', function () {
    document.getElementById('formAction').value = 'hold';
    form.dataset.submitting = '1';
    form.submit();
});
document.getElementById('checkoutBtn').addEventListener('click', function () { showPanel('pay'); });
document.getElementById('creditBtn').addEventListener('click', function () { showPanel('credit'); });

function updateCredit() {
    var t = total();
    var dep = parseFloat(document.getElementById('depositInput').value) || 0;
    document.getElementById('creditTotalOut').textContent = money(t);
    document.getElementById('creditBalanceOut').textContent = money(Math.max(0, t - dep));
}
document.getElementById('depositInput').addEventListener('input', updateCredit);

function payMethod() { return document.querySelector('input[name=pm]:checked').value; }
function updatePayFields() {
    var m = payMethod(), t = total();
    document.getElementById('cashBox').style.display = m === 'cash' ? 'flex' : 'none';
    document.getElementById('splitBox').style.display = m === 'split' ? 'flex' : 'none';
    var cashPortion = parseFloat(document.getElementById('cashPortionInput').value) || 0;
    var mpesaPortion = parseFloat(document.getElementById('mpesaPortionInput').value) || 0;
    var given = m === 'split' ? cashPortion + mpesaPortion : (parseFloat(document.getElementById('cashGivenInput').value) || 0);
    var diff = given - t;
    document.getElementById('balanceOut').textContent = m === 'mpesa' ? 'KES 0' : (diff >= 0 ? money(diff) : ('short ' + money(Math.abs(diff))));

    document.getElementById('paymentMethod').value = m;
    if (m === 'cash') { document.getElementById('amountTendered').value = given; document.getElementById('cashAmount').value = ''; document.getElementById('mpesaAmount').value = ''; }
    else if (m === 'mpesa') { document.getElementById('amountTendered').value = ''; document.getElementById('cashAmount').value = ''; document.getElementById('mpesaAmount').value = ''; }
    else {
        document.getElementById('cashAmount').value = cashPortion;
        document.getElementById('mpesaAmount').value = mpesaPortion;
        document.getElementById('amountTendered').value = given;
    }
}
document.querySelectorAll('input[name=pm]').forEach(function (r) { r.addEventListener('change', updatePayFields); });
['cashGivenInput', 'cashPortionInput', 'mpesaPortionInput'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', updatePayFields);
});

form.addEventListener('submit', function (e) {
    if (this.dataset.submitting === '1') { e.preventDefault(); return; }
    var action = document.getElementById('formAction').value;
    // Commit any quantity still being typed.
    var focused = document.activeElement;
    if (focused && focused.dataset && focused.dataset.qty) { setQty(focused.dataset.qty, focused.value); }
    if (Object.keys(cart).length === 0) { e.preventDefault(); alert('Add at least one product.'); return; }
    if (action === 'pay') {
        var m = payMethod(), t = total();
        if (m === 'cash' && (parseFloat(document.getElementById('cashGivenInput').value) || 0) < t) { e.preventDefault(); alert('Cash given is less than the total.'); return; }
        if (m === 'split') {
            var cp = parseFloat(document.getElementById('cashPortionInput').value) || 0, mp = parseFloat(document.getElementById('mpesaPortionInput').value) || 0;
            if (cp + mp < t) { e.preventDefault(); alert('Cash and M-Pesa portions are less than the total.'); return; }
        }
    } else if (action === 'credit') {
        if (isWalkin()) { e.preventDefault(); alert('Enter the customer\'s name for a credit sale.'); custInput.focus(); return; }
    }
    if ((action === 'pay' || action === 'credit') && needsSerials()) { e.preventDefault(); openSerials(); return; }
    this.dataset.submitting = '1';
    this.querySelectorAll('button[type=submit]').forEach(function (btn) { btn.disabled = true; });
});

// ---- Electronics: serial / IMEI per tracked unit -------------------------
var serialsConfirmed = '';
function trackedInCart() { return Object.keys(cart).filter(function (id) { return TRACKED[id] !== undefined; }); }
function cartKey() { return trackedInCart().map(function (id) { return id + 'x' + cart[id]; }).join(','); }
function needsSerials() {
    var missing = trackedInCart().some(function (id) { return !units[id] || units[id].length !== cart[id]; });
    return missing && serialsConfirmed !== cartKey();
}
function openSerials() {
    var rows = document.getElementById('snRows'), html = '', bad = [];
    trackedInCart().forEach(function (id) {
        var qty = cart[id];
        if (qty !== Math.round(qty)) { bad.push(PRODUCTS[id].name); return; }
        var list = 'sn-dl-' + id;
        html += '<div class="pos-sn-prod">' + esc(PRODUCTS[id].name) + ' <span>× ' + qty + ' · warranty: ' + esc(TRACKED[id]) + '</span></div>'
             + '<datalist id="' + list + '">' + (STOCK_SERIALS[id] || []).map(function (s) { return '<option value="' + esc(s) + '">'; }).join('') + '</datalist>';
        for (var i = 0; i < qty; i++) {
            html += '<div class="pos-sn-row"><b>' + (i + 1) + '</b><input type="text" class="sn-in" data-pid="' + id + '" list="' + list + '" autocomplete="off" placeholder="Serial / IMEI"></div>';
        }
    });
    if (bad.length) { alert(bad.join(', ') + ' must be sold in whole units.'); return; }
    rows.innerHTML = html;
    document.getElementById('snErr').textContent = '';
    document.getElementById('snModal').style.display = 'flex';
    var first = rows.querySelector('.sn-in'); if (first) { first.focus(); }
}
var unitModal = document.getElementById('unitModal'), unitPid = null;
function usedSerials() { var u = {}; for (var k in units) { units[k].forEach(function (x) { u[x.serial] = true; }); } return u; }
function openUnit(id) {
    var p = PRODUCTS[id];
    if (p.stock <= (cart[id] || 0)) { flashScan('Only ' + fmtQty(p.stock) + ' × ' + p.name + ' in stock.', false); return; }
    unitPid = id;
    var used = usedSerials();
    document.getElementById('unitTitle').textContent = p.name;
    document.getElementById('unitWarranty').textContent = TRACKED[id];
    document.getElementById('unitSerialList').innerHTML = (STOCK_SERIALS[id] || []).filter(function (s) { return !used[s]; })
        .map(function (s) { return '<option value="' + esc(s) + '">'; }).join('');
    var colors = TRACKED_COLORS[id] || [], sel = document.getElementById('unitColor');
    sel.innerHTML = colors.length ? '<option value="">Choose colour…</option>' + colors.map(function (c) { return '<option>' + esc(c) + '</option>'; }).join('') : '';
    sel.style.display = colors.length ? '' : 'none';
    document.getElementById('unitColorText').style.display = colors.length ? 'none' : '';
    document.getElementById('unitColorText').value = '';
    document.getElementById('unitSerial').value = '';
    var nm = custInput.value.trim();
    document.getElementById('unitBuyer').value = nm.toLowerCase() === WALKIN.toLowerCase() ? '' : nm;
    document.getElementById('unitPhone').value = document.getElementById('creditPhone').value;
    document.getElementById('unitErr').textContent = '';
    unitModal.style.display = 'flex';
    document.getElementById('unitSerial').focus();
}
if (unitModal) {
    document.getElementById('unitCancel').addEventListener('click', function () { unitModal.style.display = 'none'; });
    document.getElementById('unitSerial').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); (document.getElementById('unitColor').style.display !== 'none' ? document.getElementById('unitColor') : document.getElementById('unitColorText')).focus(); }
    });
    document.getElementById('unitOk').addEventListener('click', function () {
        var id = unitPid, err = document.getElementById('unitErr');
        var serial = document.getElementById('unitSerial').value.replace(/\s+/g, '').toUpperCase();
        var sel = document.getElementById('unitColor');
        var color = sel.style.display !== 'none' ? sel.value : document.getElementById('unitColorText').value.trim();
        var buyer = document.getElementById('unitBuyer').value.trim(), phone = document.getElementById('unitPhone').value.trim();
        if (!serial) { err.textContent = 'Enter or scan the serial / IMEI.'; return; }
        if (usedSerials()[serial]) { err.textContent = serial + ' is already in this sale.'; return; }
        if (sel.style.display !== 'none' && !color) { err.textContent = 'Choose the colour.'; return; }
        if (!buyer) { err.textContent = 'Enter the buyer\'s name — it goes on the receipt and the warranty.'; return; }
        (units[id] = units[id] || []).push({ serial: serial, color: color });
        cart[id] = units[id].length;
        if (custInput.value.trim() !== buyer) { custInput.value = buyer; document.getElementById('customerId').value = '0'; }
        if (phone) { document.getElementById('creditPhone').value = phone; }
        unitModal.style.display = 'none';
        render();
    });
}
var snModal = document.getElementById('snModal');
if (snModal) {
    document.getElementById('snCancel').addEventListener('click', function () { snModal.style.display = 'none'; });
    document.getElementById('snRows').addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') { return; }
        e.preventDefault(); // scanners send Enter — jump to the next box
        var ins = Array.prototype.slice.call(document.querySelectorAll('#snRows .sn-in')), i = ins.indexOf(e.target);
        if (i >= 0 && i < ins.length - 1) { ins[i + 1].focus(); } else { document.getElementById('snOk').click(); }
    });
    document.getElementById('snOk').addEventListener('click', function () {
        var out = {}, seen = {}, err = '';
        document.querySelectorAll('#snRows .sn-in').forEach(function (inp) {
            var v = inp.value.replace(/\s+/g, '').toUpperCase();
            inp.style.borderColor = '';
            if (!v) { err = err || 'Fill in every serial / IMEI.'; inp.style.borderColor = '#dc2626'; return; }
            if (seen[v]) { err = err || v + ' is entered twice.'; inp.style.borderColor = '#dc2626'; return; }
            seen[v] = true;
            (out[inp.dataset.pid] = out[inp.dataset.pid] || []).push(v);
        });
        if (err) { document.getElementById('snErr').textContent = err; return; }
        document.getElementById('serialsInput').value = JSON.stringify(out);
        serialsConfirmed = cartKey();
        snModal.style.display = 'none';
        if (form.requestSubmit) { form.requestSubmit(); } else { form.dataset.submitting = '1'; form.submit(); }
    });
}

document.getElementById('mbGo').addEventListener('click', function () {
    document.getElementById('posSide').scrollIntoView({ behavior: 'smooth', block: 'start' });
});

var barcodeScan = document.getElementById('barcodeScan');
var scanMsg = document.getElementById('scanMsg');
function flashScan(text, ok) {
    scanMsg.textContent = text;
    scanMsg.style.display = 'block';
    scanMsg.style.color = ok ? 'var(--pos-green-dark, #15803d)' : '#b91c1c';
    setTimeout(function () { scanMsg.style.display = 'none'; }, 2200);
}
if (barcodeScan) {
    barcodeScan.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') { return; }
        e.preventDefault();
        var code = barcodeScan.value.trim();
        barcodeScan.value = '';
        if (!code) { return; }
        var id = BARCODES[code];
        if (!id) { flashScan('No product with that barcode.', false); return; }
        var p = PRODUCTS[id];
        if (p && p.stock <= (cart[id] || 0)) { flashScan(p.name + ' — no more in stock.', false); return; }
        add(id);
        flashScan((p ? p.name : 'Product') + ' added.', true);
    });
    // Keep the scanner's keystrokes landing here even after other clicks,
    // as long as no other field is being typed into.
    document.addEventListener('click', function (e) {
        if (e.target === barcodeScan || e.target.closest('input, textarea, button, select, label, .pos-cust-menu')) { return; }
        barcodeScan.focus();
    });
}

render();
<?php if ($startMode === 'credit'): ?>showPanel('credit');<?php elseif ($startMode === 'pay'): ?>showPanel('pay');<?php endif; ?>
})();
</script>
<?php
$content = ob_get_clean();
$__layout = $isStaffViewer ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
