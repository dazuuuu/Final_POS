<?php
// public/super/fields/index.php — the account's extra product fields
// (ProductFields). Without ?type: switch fields on/off. With ?type=brand
// (grade, publisher, author, edition): add, rename and remove its values.
// Subcategories have their own page (super/subcategories/).
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::INVENTORY_EDIT);

$pdo = Database::pdo();
$A = new Models\BookAttributeModel($pdo);
$tid = (int) TenantContext::tenantId();
$account = Features::accountId($tid);
$base = public_url('super/fields/');
$type = $_GET['type'] ?? '';
if (!in_array($type, Models\BookAttributeModel::TYPES, true) || !isset(ProductFields::FIELDS[$type])) {
    $type = '';
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'fields' && TenantContext::role() === 'tenant_owner') {
        ProductFields::set($account, array_keys($_POST['fields'] ?? []));
        $_SESSION['flash']['success'] = 'Product fields saved.';
        header('Location: ' . $base);
        exit;
    }
    if ($type !== '') {
        $back = $base . '?type=' . $type;
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($act === 'create') {
            $res = $A->create($type, $name);
            if ($res['ok']) { $_SESSION['flash']['success'] = '"' . $name . '" added.'; header('Location: ' . $back); exit; }
            $error = $res['error'];
        } elseif ($act === 'rename' && $id && $A->belongsToTenant($id, $type)) {
            $dup = $A->findId($type, $name);
            if ($name === '' || ($dup !== null && $dup !== $id)) {
                $error = $name === '' ? 'Name is required.' : 'That name is already used.';
            } else {
                $pdo->prepare('UPDATE book_attributes SET name = ? WHERE id = ? AND tenant_id = ?')->execute([$name, $id, $tid]);
                header('Location: ' . $back); exit;
            }
        } elseif ($act === 'delete' && $id && $A->belongsToTenant($id, $type)) {
            // Products keep existing; they just lose this value.
            $pdo->prepare("UPDATE products SET {$type}_id = NULL WHERE {$type}_id = ? AND tenant_id = ?")->execute([$id, $tid]);
            $pdo->prepare('DELETE FROM book_attributes WHERE id = ? AND tenant_id = ?')->execute([$id, $tid]);
            $_SESSION['flash']['success'] = 'Removed.';
            header('Location: ' . $back); exit;
        }
    }
}

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$on = ProductFields::enabled();
$page_title = $type ? ProductFields::FIELDS[$type][1] : 'Product fields';
ob_start();
?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>

<?php if ($type === ''): ?>
<div class="card border-0 shadow-sm" style="border-radius:12px;max-width:640px">
  <div class="card-body p-4">
    <h2 class="h5 mb-1">Extra product fields</h2>
    <p class="text-muted small mb-3">Every product has a name, category, supplier and prices. Switch on the extra details your shop records —
      each one adds its own menu under Stock and a box on Record stock and Edit product. Importing an old shop switches on the ones it used.</p>
    <form method="post">
      <input type="hidden" name="action" value="fields">
      <?php foreach (ProductFields::FIELDS as $k => [$label, $plural, $icon]): ?>
        <label class="form-check d-flex align-items-center gap-2 py-2 border-bottom">
          <input class="form-check-input mt-0" type="checkbox" name="fields[<?php echo $k; ?>]" value="1" <?php echo in_array($k, $on, true) ? 'checked' : ''; ?> <?php echo TenantContext::role() === 'tenant_owner' ? '' : 'disabled'; ?>>
          <i class="fas <?php echo $icon; ?> text-muted" style="width:18px"></i>
          <span class="form-check-label flex-grow-1"><?php echo $h($plural); ?></span>
          <?php if (in_array($k, $on, true)): ?>
            <a class="small" href="<?php echo $k === 'subcategory' ? public_url('super/subcategories/') : $base . '?type=' . $k; ?>">Manage</a>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
      <?php if (TenantContext::role() === 'tenant_owner'): ?><button class="btn btn-primary mt-3">Save</button><?php endif; ?>
    </form>
  </div>
</div>

<?php else: [$label, $plural, $icon, $example] = ProductFields::FIELDS[$type]; $rows = $A->listWithCounts($type); ?>
<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Add a <?php echo $h(strtolower($label)); ?></h2>
        <p class="text-muted small mb-3">You can also type a new one straight into Record stock — it's added automatically.</p>
        <form method="post">
          <input type="hidden" name="action" value="create">
          <input name="name" class="form-control mb-3" placeholder="<?php echo $h($example); ?>" required maxlength="160">
          <button class="btn btn-primary"><i class="fas fa-plus me-1"></i>Add</button>
        </form>
        <a class="small d-inline-block mt-3" href="<?php echo $base; ?>"><i class="fas fa-sliders me-1"></i>Choose product fields</a>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-3"><i class="fas <?php echo $icon; ?> me-1 text-muted"></i><?php echo $h($plural); ?> <span class="badge bg-light text-dark"><?php echo count($rows); ?></span></h2>
        <?php if (!$rows): ?>
          <p class="text-muted small mb-0">None yet.</p>
        <?php else: ?>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Name</th><th class="text-center">Products</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>
                  <form method="post" class="d-flex gap-1">
                    <input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                    <input name="name" class="form-control form-control-sm" value="<?php echo $h($r['name']); ?>" maxlength="160">
                    <button class="btn btn-sm btn-outline-secondary" title="Rename"><i class="fas fa-check"></i></button>
                  </form>
                </td>
                <td class="text-center"><span class="badge bg-light text-dark"><?php echo (int) $r['book_count']; ?></span></td>
                <td class="text-end">
                  <form method="post" onsubmit="return confirm('Remove this? Products keep existing, they just lose this value.');">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$__layout = TenantContext::role() === 'staff' ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
