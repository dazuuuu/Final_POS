<?php
// public/super/restaurant/categories.php — menu categories (Breakfast, Fast food,
// Drinks…). The order screen filters dishes by these, in sort order.
require_once __DIR__ . '/_common.php';

$self = public_url('super/restaurant/categories.php');

// One-time: dishes saved before categories existed carry a free-text category.
$legacy = $pdo->prepare("SELECT DISTINCT category FROM rest_dishes WHERE tenant_id = ? AND category_id IS NULL AND category IS NOT NULL AND category <> ''");
$legacy->execute([$tid]);
foreach ($legacy->fetchAll(PDO::FETCH_COLUMN) as $name) {
    $pdo->prepare('INSERT IGNORE INTO rest_categories (tenant_id, name) VALUES (?, ?)')->execute([$tid, $name]);
    $pdo->prepare('UPDATE rest_dishes d JOIN rest_categories c ON c.tenant_id = d.tenant_id AND c.name = d.category SET d.category_id = c.id WHERE d.tenant_id = ? AND d.category = ?')
        ->execute([$tid, $name]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? 'create';
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $sort = (int) ($_POST['sort_order'] ?? 0);

    if ($action === 'delete') {
        $pdo->prepare('UPDATE rest_dishes SET category_id = NULL, category = NULL WHERE category_id = ? AND tenant_id = ?')->execute([$id, $tid]);
        $pdo->prepare('DELETE FROM rest_categories WHERE id = ? AND tenant_id = ?')->execute([$id, $tid]);
        rest_flash('Category deleted. Its dishes are now uncategorised.');
    } elseif ($name === '') {
        rest_flash('Category name is required.', 'error');
    } else {
        try {
            if ($action === 'update') {
                $pdo->prepare('UPDATE rest_categories SET name = ?, sort_order = ? WHERE id = ? AND tenant_id = ?')->execute([$name, $sort, $id, $tid]);
                rest_flash('Category saved.');
            } else {
                $pdo->prepare('INSERT INTO rest_categories (tenant_id, name, sort_order) VALUES (?,?,?)')->execute([$tid, $name, $sort]);
                rest_flash('Category "' . $name . '" added.');
            }
        } catch (PDOException $e) {
            rest_flash('You already have a category called "' . $name . '".', 'error');
        }
    }
    header('Location: ' . $self);
    exit;
}

$stmt = $pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM rest_dishes d WHERE d.category_id = c.id) AS dish_count FROM rest_categories c WHERE c.tenant_id = ? ORDER BY c.sort_order, c.name');
$stmt->execute([$tid]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-1">Add a category</h2>
        <p class="text-muted small mb-3">Group your menu, e.g. Breakfast, Fast food, Main meals, Drinks.</p>
        <form method="post">
          <?php echo csrf_field(); ?>
          <div class="mb-3"><label class="form-label">Name</label><input name="name" class="form-control" placeholder="e.g. Fast food" required></div>
          <div class="mb-3"><label class="form-label">Position <span class="text-muted">(optional)</span></label><input name="sort_order" type="number" class="form-control" value="0"><div class="form-text">Lower numbers show first on the order screen.</div></div>
          <button class="btn btn-primary">Add category</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Categories <span class="badge bg-light text-dark"><?php echo count($categories); ?></span></h2>
        <?php if (!$categories): ?><div class="text-muted">No categories yet.</div><?php endif; ?>
        <?php foreach ($categories as $c): ?>
          <div class="d-flex flex-wrap align-items-center gap-2 border-bottom py-2">
            <form method="post" class="d-flex flex-grow-1 gap-2 align-items-center">
              <?php echo csrf_field(); ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
              <input name="name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($c['name']); ?>" required>
              <input name="sort_order" type="number" class="form-control form-control-sm" style="width:80px;" value="<?php echo (int) $c['sort_order']; ?>" title="Position">
              <span class="badge bg-light text-dark text-nowrap"><?php echo (int) $c['dish_count']; ?> dishes</span>
              <button class="btn btn-sm btn-outline-secondary" title="Save"><i class="fas fa-check"></i></button>
            </form>
            <form method="post" onsubmit="return confirm('Delete this category? Its dishes stay on the menu, uncategorised.');">
              <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php
rest_render('Categories', ob_get_clean());
