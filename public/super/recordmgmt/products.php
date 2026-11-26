<?php
// public/super/recordmgmt/products.php — products bought by the company
// (e.g. gel, shaving cream). Used against a client's bill on Operations;
// low_stock_level is per product and owner-adjustable (feeds the dashboard).
require_once __DIR__ . '/_common.php';
rm_require($canManage);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $res = $RM->saveProduct($_POST, (int) ($_POST['id'] ?? 0) ?: null);
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Product saved.' : $res['error'];
    } elseif ($action === 'deactivate') {
        $RM->setProductActive((int) ($_POST['id'] ?? 0), false);
        $_SESSION['flash']['success'] = 'Product removed.';
    } elseif ($action === 'activate') {
        $RM->setProductActive((int) ($_POST['id'] ?? 0), true);
        $_SESSION['flash']['success'] = 'Product restored.';
    }
    header('Location: ' . public_url('super/recordmgmt/products.php'));
    exit;
}

$products = $RM->products(false);

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Add product</h2>
      <form method="post">
        <input type="hidden" name="action" value="save">
        <label class="form-label small">Product name</label>
        <input name="name" class="form-control" required>
        <div class="row g-2 mt-1">
          <div class="col-4"><label class="form-label small">Quantity</label><input type="number" step="0.01" name="quantity" class="form-control" value="0"></div>
          <div class="col-4"><label class="form-label small">Cost (each)</label><input type="number" step="0.01" name="cost" class="form-control" value="0"></div>
          <div class="col-4"><label class="form-label small">Low-stock level</label><input type="number" step="0.01" name="low_stock_level" class="form-control" value="5"></div>
        </div>
        <button class="btn btn-primary w-100 mt-3">Save product</button>
      </form>
    </div></div>
  </div>
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Products (<?php echo count($products); ?>)</h2>
      <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Name</th><th>Qty</th><th>Cost</th><th>Low-stock at</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
          <tr class="<?php echo $p['active'] ? '' : 'text-muted'; ?>">
            <td><?php echo rm_h($p['name']); ?></td>
            <td class="<?php echo $p['quantity'] <= $p['low_stock_level'] ? 'text-danger fw-bold' : ''; ?>"><?php echo rm_h($p['quantity']); ?></td>
            <td><?php echo rm_money($p['cost']); ?></td>
            <td><?php echo rm_h($p['low_stock_level']); ?></td>
            <td class="text-end">
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="<?php echo $p['active'] ? 'deactivate' : 'activate'; ?>">
                <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                <button class="btn btn-sm btn-link text-decoration-none"><?php echo $p['active'] ? 'Remove' : 'Restore'; ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="5" class="text-center text-muted py-3">No products yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
rm_render('Products', $content);
