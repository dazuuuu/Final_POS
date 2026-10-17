<?php
// public/components/shop_import.php — upload → preview → import a shop's old
// POS database. Included by super/import/ (owner) and support/accounts/import.php.
// Expects: $__impAccount (account id), $__impUrl (this page), $__impCsrf (token string).
// $__impSupport = true (support console only) offers "Take over the old logins".
// Handles its own POST and echoes the screen.

$__impKey = 'shop_import_' . $__impAccount;
$__impMsg = null;
$__impResult = null;
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$__labels = [
    'suppliers' => 'Suppliers', 'book_attributes' => 'Grades, publishers, authors & brands', 'categories' => 'Categories',
    'subcategories' => 'Subcategories', 'customers' => 'Customers', 'products' => 'Products (inventory & stock)',
    'users' => 'Staff & admins', 'user_permissions' => 'Staff permissions', 'user_profiles' => 'Staff profiles',
    'finance_expenses' => 'Expenses', 'purchases' => 'Purchases', 'purchase_items' => 'Purchase items',
    'stock_intakes' => 'Stock intakes', 'stock_intake_items' => 'Stock intake items', 'sales' => 'Sales',
    'sale_items' => 'Sale items', 'sale_payments' => 'Sale payments', 'orders' => 'Orders / receipts',
    'order_items' => 'Order items', 'order_payments' => 'Order payments', 'order_returns' => 'Returns',
    'order_return_items' => 'Returned items', 'held_orders' => 'Held sales', 'held_order_items' => 'Held sale items',
    'manual_credits' => 'Credit book', 'manual_credit_payments' => 'Credit payments', 'staff_time_logs' => 'Attendance (clock in/out)',
    'staff_reclock_authorizations' => 'Re-clock approvals', 'audit_log' => 'Audit log',
    'svc_services' => 'Services & prices', 'svc_tickets' => 'Service jobs / bookings', 'svc_ticket_items' => 'Services done (with staff & commission)',
    'svc_payouts' => 'Commission payouts',
    'device_serials' => 'Devices & serials / IMEI', 'warranty_claims' => 'Warranty & repairs',
    'rest_settings' => 'Restaurant settings', 'rest_categories' => 'Menu categories', 'rest_stock' => 'Store stock',
    'rest_stock_receipts' => 'Store stock received', 'rest_ingredients' => 'Ingredients', 'rest_outlet_items' => 'Counter & kitchen items',
    'rest_transfers' => 'Stock transfers', 'rest_dishes' => 'Menu dishes', 'rest_dish_ingredients' => 'Dish recipes',
    'rest_platters' => 'Family platters', 'rest_platter_items' => 'Platter items', 'rest_orders' => 'Restaurant orders',
    'rest_order_items' => 'Restaurant order items', 'rest_payments' => 'Restaurant payments', 'rest_table_bookings' => 'Table bookings',
    'rest_table_booking_items' => 'Table booking items', 'rest_table_booking_payments' => 'Table booking payments',
    'room_settings' => 'Rooms settings', 'room_rooms' => 'Rooms', 'room_stays' => 'Guest stays',
    'room_stay_charges' => 'Room service & extras', 'room_stay_payments' => 'Guest payments',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($__impCsrf, $_POST['csrf'] ?? '')) {
    try {
        $act = $_POST['imp'] ?? '';
        if ($act === 'upload') {
            if (!empty($_SESSION[$__impKey])) ShopImport::discard($_SESSION[$__impKey]);
            $_SESSION[$__impKey] = ShopImport::storeUpload($_FILES['dump'] ?? []);
        } elseif ($act === 'cancel') {
            ShopImport::discard($_SESSION[$__impKey] ?? '');
            unset($_SESSION[$__impKey]);
        } elseif ($act === 'run' && !empty($_SESSION[$__impKey])) {
            if (empty($_POST['confirm'])) throw new RuntimeException('Tick the box to confirm the import.');
            $tables = ShopImport::load($_SESSION[$__impKey]);
            $__impResult = ShopImport::run($tables, (int) $_POST['source'], $__impAccount, !empty($__impSupport) && !empty($_POST['take_over']));
            ShopImport::discard($_SESSION[$__impKey]);
            unset($_SESSION[$__impKey]);
        }
    } catch (Throwable $e) {
        $__impMsg = $e->getMessage();
    }
}

$__file = $_SESSION[$__impKey] ?? null;
$__tables = $__shops = $__src = $__prev = null;
if ($__file && !$__impResult) {
    try {
        $__tables = ShopImport::load($__file);
        $__shops = ShopImport::sourceShops($__tables);
        $__src = isset($_GET['source'], $__shops[(int) $_GET['source']]) ? (int) $_GET['source'] : (int) array_key_first($__shops ?: [0 => '']);
        $__prev = ShopImport::preview($__tables, $__src);
    } catch (Throwable $e) {
        $__impMsg = $e->getMessage();
        unset($_SESSION[$__impKey]);
        $__file = null;
    }
}
Signup::ensureColumns();
$__st = Database::pdo()->prepare('SELECT imported_at, owner_user_id FROM tenants WHERE id = ?');
$__st->execute([$__impAccount]);
$__acctRow = $__st->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<div style="max-width:760px">
  <?php if ($__impMsg): ?><div class="alert alert-danger"><?php echo $h($__impMsg); ?></div><?php endif; ?>

  <?php if ($__impResult): ?>
    <div class="alert alert-success"><i class="fas fa-circle-check me-1"></i><strong>Import finished.</strong> The shop's data is now in this account — carry on selling.</div>
    <div class="card mb-3"><div class="card-body">
      <table class="table table-sm mb-0">
        <?php foreach ($__impResult['counts'] as $t => $n): if (!$n) continue; ?>
          <tr><td><?php echo $h($__labels[$t] ?? $t); ?></td><td class="text-end fw-semibold"><?php echo number_format($n); ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php if ($__impResult['skipped_rows']): ?><p class="small text-muted mt-2 mb-0"><?php echo number_format($__impResult['skipped_rows']); ?> row(s) skipped (duplicates or missing links).</p><?php endif; ?>
      <?php if ($__impResult['notes']): ?><ul class="small text-muted mt-2 mb-0"><?php foreach ($__impResult['notes'] as $n): ?><li><?php echo $h($n); ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if ($__impResult['fields']): ?><p class="small mt-2 mb-0"><i class="fas fa-wand-magic-sparkles me-1"></i>Your old POS used these extra product fields, so they're now on in the menu and on the stock forms:
        <strong><?php echo $h(implode(', ', array_map(fn($k) => ProductFields::FIELDS[$k][1], $__impResult['fields']))); ?></strong>.</p><?php endif; ?>
      <p class="small mt-2 mb-0">Staff keep their old PINs and passwords.</p>
    </div></div>

  <?php elseif (!$__file): ?>
    <div class="card"><div class="card-body">
      <h2 class="h6">Upload the old shop's database</h2>
      <p class="small text-muted">Export the old POS database from phpMyAdmin (<em>Export → SQL</em>) and upload the <code>.sql</code> file.
        You'll see what will be imported before anything is saved.</p>
      <?php if (empty($__acctRow['owner_user_id'])): ?>
        <div class="alert alert-info small"><i class="fas fa-user-check me-1"></i>No owner yet — the old shop's owner becomes this account's owner,
          with the same email and password they used before. The account is activated automatically.</div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo $h($__impCsrf); ?>"><input type="hidden" name="imp" value="upload">
        <input class="form-control mb-3" type="file" name="dump" accept=".sql,text/plain" required>
        <button class="btn btn-primary"><i class="fas fa-upload me-1"></i>Upload &amp; preview</button>
      </form>
    </div></div>

  <?php else: ?>
    <div class="card"><div class="card-body">
      <h2 class="h6">Preview</h2>
      <?php if (count($__shops) > 1): ?>
        <form method="get" class="mb-3"><?php foreach ($_GET as $k => $v): if ($k !== 'source'): ?><input type="hidden" name="<?php echo $h($k); ?>" value="<?php echo $h($v); ?>"><?php endif; endforeach; ?>
          <label class="form-label small">The file has several shops — import which one?</label>
          <select class="form-select" name="source" onchange="this.form.submit()">
            <?php foreach ($__shops as $sid => $sn): ?><option value="<?php echo $sid; ?>" <?php echo $sid === $__src ? 'selected' : ''; ?>><?php echo $h($sn); ?> (#<?php echo $sid; ?>)</option><?php endforeach; ?>
          </select></form>
      <?php elseif ($__shops): ?>
        <p class="small mb-2">Shop in file: <strong><?php echo $h(reset($__shops)); ?></strong></p>
      <?php endif; ?>
      <?php if (empty($__acctRow['owner_user_id'])): $__so = ShopImport::sourceOwner($__tables, $__src); ?>
        <?php if ($__so): ?>
          <div class="alert alert-success small"><i class="fas fa-key me-1"></i>Owner login: <strong><?php echo $h($__so['email']); ?></strong>
            (username <?php echo $h($__so['username']); ?>). After the import, tell them to sign in at
            <code><?php echo $h(public_url('auth/login.php')); ?></code> with their old password. Their staff keep their PINs.</div>
        <?php else: ?>
          <div class="alert alert-danger small">No owner/admin user was found for this shop in the file, so nobody could sign in.
            Create the account as a <em>Full account</em> with an owner login instead, then import.</div>
        <?php endif; ?>
      <?php endif; ?>
      <table class="table table-sm">
        <?php $__total = 0; foreach ($__prev['counts'] as $t => $n): $__total += $n; ?>
          <tr class="<?php echo $n ? '' : 'text-muted'; ?>"><td><?php echo $h($__labels[$t] ?? $t); ?></td><td class="text-end"><?php echo number_format($n); ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php if ($__prev['fields']): ?><p class="small"><i class="fas fa-wand-magic-sparkles me-1"></i>Extra product fields found — these menus will be added:
        <strong><?php echo $h(implode(', ', array_map(fn($k) => ProductFields::FIELDS[$k][1], $__prev['fields']))); ?></strong>.</p><?php endif; ?>
      <?php if ($__prev['skipped']): ?><p class="small text-muted">Not imported (not part of the POS): <?php echo $h(implode(', ', $__prev['skipped'])); ?></p><?php endif; ?>
      <?php if (!empty($__acctRow['imported_at'])): ?>
        <div class="alert alert-warning small">This account already imported data on <?php echo date('d M Y H:i', strtotime($__acctRow['imported_at'])); ?>. Importing again adds the records a second time.</div>
      <?php endif; ?>
      <?php if (!$__total): ?>
        <div class="alert alert-warning small">Nothing to import was found in this file.</div>
      <?php else: ?>
      <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='Importing… please wait';">
        <input type="hidden" name="csrf" value="<?php echo $h($__impCsrf); ?>"><input type="hidden" name="imp" value="run">
        <input type="hidden" name="source" value="<?php echo (int) $__src; ?>">
        <?php if (!empty($__impSupport) && ($__so = ShopImport::sourceOwner($__tables, $__src))): ?>
        <label class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="take_over" value="1" <?php echo !empty($__acctRow['owner_user_id']) ? '' : 'checked'; ?>>
          <span class="form-check-label"><strong>Take over the old logins</strong> — make <?php echo $h($__so['email']); ?> this account's owner
            (replacing the current owner<?php echo !empty($__acctRow['owner_user_id']) ? '' : ', if any'; ?>), and free any of the file's emails/usernames that already
            exist on this system (e.g. from a test import) so the owner and staff sign in with their old details.</span></label>
        <?php endif; ?>
        <label class="form-check small mb-3"><input class="form-check-input" type="checkbox" name="confirm" value="1" required>
          <span class="form-check-label">Add these <?php echo number_format($__total); ?> records to this account.</span></label>
        <button class="btn btn-success"><i class="fas fa-file-import me-1"></i>Import now</button>
      </form>
      <?php endif; ?>
      <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?php echo $h($__impCsrf); ?>"><input type="hidden" name="imp" value="cancel">
        <button class="btn btn-link btn-sm p-0">Cancel and choose another file</button></form>
    </div></div>
  <?php endif; ?>
</div>
