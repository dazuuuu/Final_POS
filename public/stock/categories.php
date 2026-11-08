<?php
// public/stock/categories.php — the organisation's categories. One list for
// every store: add, rename (merges if the new name exists) and delete (only
// when no item uses it).
require_once __DIR__ . '/_boot.php';
stock_guard('stock.items');
$store = stock_store();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!stock_csrf_ok()) stock_go('categories.php', 'This page expired — try again.', 'err');
    $act = $_POST['act'] ?? '';
    if ($act === 'add') {
        [$ok, $e] = StockControl::addCategory((string) ($_POST['name'] ?? ''));
        stock_go('categories.php', $ok ? 'Category added.' : $e, $ok ? 'ok' : 'err');
    } elseif ($act === 'rename') {
        [$ok, $e] = StockControl::renameCategory((string) ($_POST['old'] ?? ''), (string) ($_POST['name'] ?? ''));
        stock_go('categories.php', $ok ? 'Category renamed.' : $e, $ok ? 'ok' : 'err');
    } elseif ($act === 'delete') {
        [$ok, $e] = StockControl::deleteCategory((string) ($_POST['old'] ?? ''));
        stock_go('categories.php', $ok ? 'Category deleted.' : $e, $ok ? 'ok' : 'err');
    }
}

$cats = StockControl::categoryList();
$title = 'Categories';
$tab = 'categories.php';
ob_start();
?>
<div class="sc-head"><div><h1>Categories</h1><p>Group your items. The same categories are used in every warehouse and shop.</p></div></div>
<div class="grid" style="grid-template-columns:1.6fr 1fr">
  <div class="panel">
    <h2>Your categories <span class="mute small"><?php echo count($cats); ?></span></h2>
    <?php if (!$cats): ?><div class="empty">No categories yet. Add your first one on the right — or just type one when you add an item.</div><?php else: ?>
    <div class="wrap-t"><table class="t">
      <thead><tr><th>Category</th><th class="r">Items</th><th></th></tr></thead>
      <tbody>
      <?php $i = 0; foreach ($cats as $name => $n): $i++; ?>
        <tr>
          <td style="font-weight:600"><a style="text-decoration:none" href="<?php echo stock_url('items.php?q=&cat_name=' . urlencode($name)); ?>"><?php echo sh($name); ?></a></td>
          <td class="r num"><?php echo (int) $n; ?></td>
          <td class="r" style="white-space:nowrap">
            <button class="btn btn-l btn-sm" type="button" onclick="var f=document.getElementById('c<?php echo $i; ?>');f.hidden=!f.hidden" title="Rename"><i class="fas fa-pen"></i></button>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this category?')"><?php echo stock_csrf_field(); ?><input type="hidden" name="act" value="delete"><input type="hidden" name="old" value="<?php echo sh($name); ?>">
              <button class="btn btn-l btn-sm" title="<?php echo $n ? 'Items still use it' : 'Delete'; ?>" <?php echo $n ? 'disabled style="opacity:.4"' : ''; ?>><i class="fas fa-trash"></i></button></form>
          </td>
        </tr>
        <tr id="c<?php echo $i; ?>" hidden><td colspan="3">
          <form method="post" style="display:flex;gap:8px;flex-wrap:wrap"><?php echo stock_csrf_field(); ?><input type="hidden" name="act" value="rename"><input type="hidden" name="old" value="<?php echo sh($name); ?>">
            <input class="in" name="name" value="<?php echo sh($name); ?>" style="max-width:260px" required><button class="btn btn-d btn-sm">Save name</button></form>
        </td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="panel">
    <h2>Add a category</h2>
    <form method="post"><?php echo stock_csrf_field(); ?><input type="hidden" name="act" value="add">
      <label class="f">Name</label><input class="in" name="name" required placeholder="e.g. Stationery, Building materials" style="margin-bottom:14px">
      <button class="btn btn-p"><i class="fas fa-plus"></i> Add category</button>
    </form>
    <p class="small mute" style="margin-bottom:0">Renaming to a name that already exists merges the two. A category can be deleted only when no item uses it.</p>
  </div>
</div>
<style>@media(max-width:900px){.grid[style*="1.6fr"]{grid-template-columns:1fr!important}}</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
