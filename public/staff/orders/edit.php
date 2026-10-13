<?php
// public/staff/orders/edit.php?id=N — edit an order/walk-in sale and regenerate receipt.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::SALES_RECORD);

$pdo = Database::pdo();
$OR = new Models\OrderModel($pdo);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isStaffViewer = TenantContext::role() === 'staff';
$onlyOpenedBy = $isStaffViewer ? (int) TenantContext::userId() : null;

$order = $id > 0 ? $OR->find($id) : null;
if (!$order || ($onlyOpenedBy !== null && (int) $order['opened_by'] !== $onlyOpenedBy)) {
    http_response_code(404);
    echo 'Sale not found.';
    exit;
}
$items = $OR->items($id);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cart = json_decode($_POST['cart'] ?? '[]', true);
    if (!is_array($cart)) { $cart = []; }
    $editItems = array_map(fn($c) => ['product_id' => (int) ($c['product_id'] ?? 0), 'quantity' => (float) ($c['quantity'] ?? 0)], $cart);
    $res = $OR->updateOrder($id, [
        'table_name' => $_POST['table_name'] ?? '',
        'sale_type' => $_POST['sale_type'] ?? 'retail',
        'discount_amount' => $_POST['discount_amount'] ?? 0,
        'customer_email' => $_POST['customer_email'] ?? '',
        'customer_phone' => $_POST['customer_phone'] ?? '',
        'payment_method' => $_POST['payment_method'] ?? ($order['payment_method'] ?? 'cash'),
        'amount_tendered' => $_POST['amount_tendered'] ?? 0,
        'cash_amount' => $_POST['cash_amount'] ?? 0,
        'mpesa_amount' => $_POST['mpesa_amount'] ?? 0,
        'items' => $editItems,
    ], (int) TenantContext::userId(), $onlyOpenedBy);
    if ($res['ok']) {
        $_SESSION['flash']['success'] = 'Sale updated. New receipt: ' . $res['receipt_number'] . '.';
        header('Location: ' . public_url(($isStaffViewer ? 'staff' : 'super') . '/orders/receipt.php?id=' . $id));
        exit;
    }
    $error = $res['errors']['_'] ?? implode(' ', array_filter($res['errors'] ?? [])) ?: 'Could not update this sale.';
    $order = $OR->find($id);
    $items = $OR->items($id);
}

$oldQty = [];
foreach ($items as $it) {
    if (!empty($it['product_id'])) {
        $oldQty[(int) $it['product_id']] = ($oldQty[(int) $it['product_id']] ?? 0) + (float) $it['quantity'];
    }
}
$existingIds = array_keys($oldQty);
$params = [TenantContext::tenantId()];
$idSql = '';
if ($existingIds) {
    $idSql = ' OR p.id IN (' . implode(',', array_fill(0, count($existingIds), '?')) . ')';
    $params = array_merge($params, $existingIds);
}
$stmt = $pdo->prepare(
    "SELECT p.id, p.name, p.selling_price, p.retail_price, p.wholesale_price, p.quantity, p.image_path, p.status
       FROM products p
      WHERE p.tenant_id = ? AND ((p.status IN ('active','archived') AND p.quantity > 0) {$idSql})
   ORDER BY p.name ASC"
);
$stmt->execute($params);
$products = $stmt->fetchAll();
$cartJson = json_encode(array_values(array_map(fn($it) => [
    'product_id' => (int) $it['product_id'],
    'quantity' => (float) $it['quantity'],
], array_filter($items, fn($it) => !empty($it['product_id'])))));

$page_title = 'Edit sale';
ob_start();
?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<form method="post" id="editForm">
  <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
  <input type="hidden" name="cart" id="cartInput">
  <input type="hidden" name="sale_type" id="saleTypeInput" value="<?php echo htmlspecialchars($order['sale_type'] ?? 'retail'); ?>">
  <div class="row g-3">
    <div class="col-12 col-xl-8">
      <div class="card border-0 shadow-sm"><div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
          <div><h2 class="h6 fw-bold mb-1"><?php echo htmlspecialchars($order['receipt_number']); ?></h2><div class="text-muted small">Edit products, quantities, customer and payment details.</div></div>
          <div class="btn-group" id="priceMode">
            <button type="button" data-sale-type="retail" class="btn btn-sm <?php echo ($order['sale_type'] ?? 'retail') === 'wholesale' ? 'btn-outline-primary' : 'btn-primary'; ?>">Retail</button>
            <button type="button" data-sale-type="wholesale" class="btn btn-sm <?php echo ($order['sale_type'] ?? 'retail') === 'wholesale' ? 'btn-primary' : 'btn-outline-primary'; ?>">Wholesale</button>
          </div>
        </div>
        <input type="text" id="productSearch" class="form-control mb-3" placeholder="Search products...">
        <div class="edit-products" id="productList">
          <?php foreach ($products as $p):
              $retail = (float) ($p['retail_price'] ?: $p['selling_price']);
              $whole = (float) ($p['wholesale_price'] ?: $retail);
              $available = (float) $p['quantity'] + (float) ($oldQty[(int) $p['id']] ?? 0);
          ?>
          <div class="edit-product" data-id="<?php echo (int) $p['id']; ?>" data-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>" data-retail="<?php echo $retail; ?>" data-wholesale="<?php echo $whole; ?>" data-stock="<?php echo $available; ?>">
            <div class="edit-product-img"><?php if (!empty($p['image_path'])): ?><img src="<?php echo htmlspecialchars($p['image_path']); ?>" alt=""><?php else: ?><i class="fas fa-box"></i><?php endif; ?></div>
            <div class="edit-product-name"><?php echo htmlspecialchars($p['name']); ?></div>
            <div class="edit-product-meta">Stock <?php echo rtrim(rtrim(number_format($available, 2), '0'), '.'); ?></div>
            <button type="button" class="btn btn-sm btn-primary w-100 edit-add">Add</button>
          </div>
          <?php endforeach; ?>
        </div>
      </div></div>
    </div>
    <div class="col-12 col-xl-4">
      <div class="card border-0 shadow-sm sticky-xl-top" style="top:20px;"><div class="card-body p-4">
        <label class="form-label small fw-semibold">Customer</label>
        <input name="table_name" class="form-control mb-2" value="<?php echo htmlspecialchars($order['table_name'] ?? ''); ?>" required>
        <div class="row g-2 mb-3">
          <div class="col-6"><input name="customer_phone" class="form-control" placeholder="Phone" value="<?php echo htmlspecialchars($order['customer_phone'] ?? ''); ?>"></div>
          <div class="col-6"><input name="customer_email" type="email" class="form-control" placeholder="Email" value="<?php echo htmlspecialchars($order['customer_email'] ?? ''); ?>"></div>
        </div>
        <div class="edit-cart mb-3" id="cartRows"></div>
        <div class="border-top pt-3">
          <div class="d-flex justify-content-between small mb-2"><span>Subtotal</span><strong id="subtotalOut">KES 0</strong></div>
          <div class="d-flex justify-content-between align-items-center mb-2"><span class="small">Discount</span><input type="number" step="0.01" min="0" name="discount_amount" id="discountInput" class="form-control form-control-sm" style="width:120px;text-align:right;" value="<?php echo htmlspecialchars($order['discount_amount'] ?? 0); ?>"></div>
          <div class="d-flex justify-content-between h6 mb-3"><span>Total</span><strong id="totalOut">KES 0</strong></div>
        </div>
        <?php if (($order['status'] ?? '') === 'paid'): ?>
          <label class="form-label small fw-semibold">Payment</label>
          <select name="payment_method" id="paymentMethod" class="form-select mb-3">
            <?php foreach (['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'split' => 'Split'] as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo ($order['payment_method'] ?? 'cash') === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?>
          </select>
          <div class="pay-fields" data-pay="cash"><label class="form-label small fw-semibold">Amount given</label><input type="number" step="0.01" min="0" name="amount_tendered" class="form-control" value="<?php echo htmlspecialchars($order['amount_tendered'] ?? $order['total']); ?>"></div>
          <div class="pay-fields row g-2" data-pay="split"><div class="col-6"><label class="form-label small fw-semibold">Cash</label><input type="number" step="0.01" min="0" name="cash_amount" class="form-control" value="<?php echo htmlspecialchars($order['cash_amount'] ?? 0); ?>"></div><div class="col-6"><label class="form-label small fw-semibold">M-Pesa</label><input type="number" step="0.01" min="0" name="mpesa_amount" class="form-control" value="<?php echo htmlspecialchars($order['mpesa_amount'] ?? 0); ?>"></div></div>
        <?php else: ?>
          <div class="alert alert-info small">This tab is still open, so payment can be edited when it is settled.</div>
        <?php endif; ?>
        <div class="d-flex gap-2 mt-4"><a class="btn btn-outline-secondary flex-fill" href="<?php echo public_url(($isStaffViewer ? 'staff' : 'super') . '/orders/receipt.php?id=' . $id); ?>">Cancel</a><button class="btn btn-primary flex-fill" id="saveBtn">Save & receipt</button></div>
      </div></div>
    </div>
  </div>
</form>
<style>
.edit-products{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}@media (min-width:768px){.edit-products{grid-template-columns:repeat(3,minmax(0,1fr));}}@media (min-width:1200px){.edit-products{grid-template-columns:repeat(4,minmax(0,1fr));}}
.edit-product{border:1px solid #eef0f4;background:#fff;border-radius:10px;padding:12px;text-align:center;min-width:0;}.edit-product-img{height:56px;display:flex;align-items:center;justify-content:center;margin-bottom:8px;color:#cbd5e1;}.edit-product-img img{max-height:56px;max-width:100%;object-fit:contain;}.edit-product-name{font-size:.85rem;font-weight:700;min-height:2.4em;overflow-wrap:anywhere;}.edit-product-meta{font-size:.75rem;color:#64748b;margin:4px 0 10px;}.edit-cart{max-height:320px;overflow:auto;border-block:1px solid #f1f5f9;}.cart-line{display:grid;grid-template-columns:1fr auto auto;gap:8px;align-items:center;padding:10px 0;border-bottom:1px solid #f8fafc;}.qty-box{display:flex;align-items:center;gap:4px;}.qty-box button{width:30px;height:30px;border:1px solid #e2e8f0;background:#fff;border-radius:8px;}
</style>
<script>
var PRODUCTS={}, saleType=<?php echo json_encode(($order['sale_type'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail'); ?>, cart={};
document.querySelectorAll('.edit-product').forEach(function(el){PRODUCTS[el.dataset.id]={name:el.dataset.name,retailPrice:parseFloat(el.dataset.retail||'0'),wholesalePrice:parseFloat(el.dataset.wholesale||el.dataset.retail||'0'),stock:parseFloat(el.dataset.stock||'0')};});
try{(JSON.parse(<?php echo json_encode($cartJson); ?>)||[]).forEach(function(c){cart[c.product_id]=c.quantity;});}catch(e){}
function money(n){return 'KES '+Math.round(n).toLocaleString('en-KE');} function priceOf(p){return saleType==='wholesale'?p.wholesalePrice:p.retailPrice;}
function setQty(id,val){var p=PRODUCTS[id];if(!p)return;val=Math.max(0,Math.round((parseFloat(val)||0)*100)/100);if(val<=0){delete cart[id];render();return;}if(val>p.stock)val=p.stock;cart[id]=val;render();} function add(id){setQty(id,(cart[id]||0)+1);}
function updateTotals(){var sub=0;Object.keys(cart).forEach(function(id){sub+=priceOf(PRODUCTS[id])*cart[id];});var d=parseFloat(document.getElementById('discountInput').value||'0')||0;if(d<0)d=0;if(d>sub)d=sub;document.getElementById('subtotalOut').textContent=money(sub);document.getElementById('totalOut').textContent=money(sub-d);}
function render(){var rows=document.getElementById('cartRows'),ids=Object.keys(cart);rows.innerHTML=ids.length?'':'<div class="text-muted small text-center py-4">No products selected.</div>';ids.forEach(function(id){var p=PRODUCTS[id],qty=cart[id],row=document.createElement('div');row.className='cart-line';row.innerHTML='<div><div class="fw-semibold small">'+p.name+'</div><div class="text-muted small">'+money(priceOf(p))+'</div></div><div class="qty-box"><button type="button" data-dec="'+id+'">-</button><input type="number" inputmode="decimal" min="0" step="any" data-qty="'+id+'" value="'+qty+'" style="width:64px;text-align:center;border:1px solid #e2e8f0;border-radius:6px;"><button type="button" data-inc="'+id+'">+</button></div><button type="button" class="btn btn-sm btn-link text-danger" data-del="'+id+'"><i class="fas fa-trash"></i></button>';rows.appendChild(row);});document.getElementById('cartInput').value=JSON.stringify(ids.map(function(id){return{product_id:parseInt(id,10),quantity:cart[id]};}));document.getElementById('saveBtn').disabled=ids.length===0;updateTotals();}
document.querySelectorAll('.edit-add').forEach(function(btn){btn.addEventListener('click',function(){add(btn.closest('.edit-product').dataset.id);});});
document.getElementById('cartRows').addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;if(b.dataset.inc)add(b.dataset.inc);if(b.dataset.dec)setQty(b.dataset.dec,(cart[b.dataset.dec]||0)-1);if(b.dataset.del){delete cart[b.dataset.del];render();}});
document.querySelectorAll('#priceMode button').forEach(function(btn){btn.addEventListener('click',function(){saleType=btn.dataset.saleType==='wholesale'?'wholesale':'retail';document.getElementById('saleTypeInput').value=saleType;document.querySelectorAll('#priceMode button').forEach(function(b){b.classList.toggle('btn-primary',b===btn);b.classList.toggle('btn-outline-primary',b!==btn);});render();});});
document.getElementById('discountInput').addEventListener('input',updateTotals);
var pm=document.getElementById('paymentMethod');if(pm){pm.addEventListener('change',function(){var m=this.value;document.querySelectorAll('.pay-fields').forEach(function(el){el.style.display=el.dataset.pay===m?'':'none';});});pm.dispatchEvent(new Event('change'));}
document.getElementById('productSearch').addEventListener('input',function(){var q=this.value.toLowerCase().trim();document.querySelectorAll('.edit-product').forEach(function(el){el.style.display=!q||el.dataset.name.toLowerCase().indexOf(q)!==-1?'':'none';});});
document.getElementById('editForm').addEventListener('submit',function(e){if(Object.keys(cart).length===0){e.preventDefault();alert('Add at least one product.');}});
render();
document.getElementById('cartRows').addEventListener('change',function(e){if(e.target.dataset.qty){setQty(e.target.dataset.qty,e.target.value);}});
document.getElementById('cartRows').addEventListener('keydown',function(e){if(e.key==='Enter'&&e.target.dataset.qty){e.preventDefault();e.target.blur();}});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/' . ($isStaffViewer ? 'staff' : 'tenants') . '/layout.php';
