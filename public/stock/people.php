<?php
// public/stock/people.php — administrators and staff. The owner adds
// administrators (they can do everything except manage other administrators);
// owner and administrators add staff and tick what each one may do.
require_once __DIR__ . '/_boot.php';
stock_guard(null, true);
$store = stock_store();
$stores = StockControl::stores();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!stock_csrf_ok()) stock_go('people.php', 'This page expired — try again.', 'err');
    $act = $_POST['act'] ?? '';
    if ($act === 'add') {
        [$ok, $res] = StockControl::addPerson((string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''),
            ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff', (int) ($_POST['store'] ?? 0), (array) ($_POST['rights'] ?? []));
        if (!$ok) stock_go('people.php', $res, 'err');
        stock_go('people.php', $res['sent']
            ? 'Added. Their sign-in details were emailed to them.'
            : 'Added, but the email could not be sent. Give them: ' . $res['link'] . ' — temporary password ' . $res['temp'], $res['sent'] ? 'ok' : 'err');
    }
    $person = null;
    foreach (StockControl::people() as $p) if ((int) $p['id'] === (int) ($_POST['id'] ?? 0)) $person = $p;
    if (!$person || !StockControl::canManage($person)) stock_go('people.php', 'You can\'t change that person.', 'err');
    $db = Database::pdo();
    if ($act === 'rights' && $person['role_name'] === 'staff') {
        StockControl::setRights((int) $person['id'], (array) ($_POST['rights'] ?? []));
        $sid = (int) ($_POST['store'] ?? 0);
        if (isset($stores[$sid])) $db->prepare('UPDATE users SET tenant_id = ? WHERE id = ?')->execute([$sid, (int) $person['id']]);
        stock_go('people.php', 'Saved. Changes apply the next time they sign in.');
    }
    if ($act === 'toggle') {
        $db->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([(int) $person['id']]);
        stock_go('people.php', $person['is_active'] ? 'Blocked — they can no longer sign in.' : 'Unblocked.');
    }
}

$people = StockControl::people();
$title = 'People';
$tab = 'people.php';
ob_start();
?>
<div class="sc-head"><div><h1>People</h1><p>Everyone signs in with their email, a password and a code emailed to them each time.</p></div></div>
<div class="grid" style="grid-template-columns:2fr 1fr">
  <div class="panel">
    <h2>Who has access</h2>
    <div class="wrap-t"><table class="t">
      <thead><tr><th>Name</th><th>Role</th><th>Store</th><th>Can</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($people as $p): $manage = StockControl::canManage($p);
        $role = $p['role_name'] === 'staff' ? 'Staff' : ((int) $p['id'] === (int) ($stores[StockControl::account()]['owner_user_id'] ?? 0) ? 'Owner' : 'Administrator'); ?>
        <tr style="<?php echo $p['is_active'] ? '' : 'opacity:.55'; ?>">
          <td><b><?php echo sh($p['username']); ?></b><br><span class="small mute"><?php echo sh($p['email']); ?></span></td>
          <td><span class="pill <?php echo $role === 'Staff' ? 'p-mute' : 'p-mv'; ?>"><?php echo $role; ?></span><?php echo $p['is_active'] ? '' : ' <span class="pill p-out">blocked</span>'; ?></td>
          <td class="small"><?php echo $role === 'Staff' ? sh($p['store_name']) : 'All stores'; ?></td>
          <td class="small mute"><?php echo $role === 'Staff' ? (sh(implode(', ', array_map(fn($r) => StockControl::RIGHTS[$r], $p['rights']))) ?: 'View only') : 'Everything'; ?></td>
          <td class="r" style="white-space:nowrap">
            <?php if ($manage): ?>
              <?php if ($role === 'Staff'): ?><button class="btn btn-l btn-sm" type="button" onclick="var f=document.getElementById('r<?php echo (int) $p['id']; ?>');f.hidden=!f.hidden"><i class="fas fa-sliders"></i></button><?php endif; ?>
              <form method="post" style="display:inline"><?php echo stock_csrf_field(); ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                <button class="btn btn-l btn-sm" title="<?php echo $p['is_active'] ? 'Block' : 'Unblock'; ?>"><i class="fas <?php echo $p['is_active'] ? 'fa-ban' : 'fa-unlock'; ?>"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($manage && $role === 'Staff'): ?>
        <tr id="r<?php echo (int) $p['id']; ?>" hidden><td colspan="5">
          <form method="post"><?php echo stock_csrf_field(); ?><input type="hidden" name="act" value="rights"><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
              <select class="in" name="store" style="max-width:200px"><?php foreach ($stores as $sid => $s): ?><option value="<?php echo $sid; ?>" <?php echo (int) $p['tenant_id'] === $sid ? 'selected' : ''; ?>><?php echo sh($s['name']); ?></option><?php endforeach; ?></select>
              <?php foreach (StockControl::RIGHTS as $k => $label): ?><label class="small"><input type="checkbox" name="rights[]" value="<?php echo $k; ?>" <?php echo in_array($k, $p['rights'], true) ? 'checked' : ''; ?>> <?php echo $label; ?></label><?php endforeach; ?>
            </div>
            <button class="btn btn-d btn-sm">Save</button>
          </form>
        </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="panel">
    <h2>Add a person</h2>
    <form method="post"><?php echo stock_csrf_field(); ?><input type="hidden" name="act" value="add">
      <label class="f">Name</label><input class="in" name="name" required style="margin-bottom:10px">
      <label class="f">Email</label><input class="in" type="email" name="email" required style="margin-bottom:10px">
      <label class="f">Role</label>
      <select class="in" name="role" id="role" style="margin-bottom:10px">
        <option value="staff">Staff — only what you tick, in one store</option>
        <?php if (StockControl::isOwner()): ?><option value="admin">Administrator — everything, all stores</option><?php endif; ?>
      </select>
      <div id="staffOnly">
        <label class="f">Store</label>
        <select class="in" name="store" style="margin-bottom:10px"><?php foreach ($stores as $sid => $s): ?><option value="<?php echo $sid; ?>" <?php echo $sid === $store ? 'selected' : ''; ?>><?php echo sh($s['name']); ?></option><?php endforeach; ?></select>
        <label class="f">They can</label>
        <?php foreach (StockControl::RIGHTS as $k => $label): ?><label class="small" style="display:block;margin-bottom:4px"><input type="checkbox" name="rights[]" value="<?php echo $k; ?>" <?php echo in_array($k, ['stock.receive', 'stock.count'], true) ? 'checked' : ''; ?>> <?php echo $label; ?></label><?php endforeach; ?>
      </div>
      <p class="small mute">They get an email with the sign-in address and a temporary password, and choose their own the first time.</p>
      <button class="btn btn-p"><i class="fas fa-user-plus"></i> Add person</button>
    </form>
  </div>
</div>
<script>
document.getElementById('role').addEventListener('change', function () { document.getElementById('staffOnly').style.display = this.value === 'staff' ? '' : 'none'; });
</script>
<style>@media(max-width:900px){.grid[style*="2fr"]{grid-template-columns:1fr!important}}</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
