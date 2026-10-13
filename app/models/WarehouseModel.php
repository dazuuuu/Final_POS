<?php
// app/models/WarehouseModel.php
// The owner's central store. Stock is recorded here at BUYING price only;
// it gets a selling price when it is moved ("migrated") into one of the
// owner's shops, where it becomes (or tops up) a normal product that the
// shop's POS and public catalogue pick up straight away.
namespace Models;

class WarehouseModel
{
    private \PDO $db;
    private int $homeId;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? \Database::pdo();
        \ShopContext::ensureSchema($this->db);
        $home = \ShopContext::homeTenantId();
        if ($home === null) {
            throw new \RuntimeException('No shop in context for the warehouse.');
        }
        $this->homeId = $home;
    }

    public function items(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM warehouse_items WHERE tenant_id = ? ORDER BY name ASC');
        $stmt->execute([$this->homeId]);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM warehouse_items WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$id, $this->homeId]);
        return $stmt->fetch() ?: null;
    }

    public function totals(): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS items, COALESCE(SUM(quantity),0) AS qty, COALESCE(SUM(quantity * buying_price),0) AS value
               FROM warehouse_items WHERE tenant_id = ?'
        );
        $stmt->execute([$this->homeId]);
        return $stmt->fetch() ?: ['items' => 0, 'qty' => 0, 'value' => 0];
    }

    /**
     * Record stock into the warehouse. Same name = restock of that item; the
     * buying price becomes the weighted average of old and new stock.
     */
    public function receive(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $qty = (float) ($in['quantity'] ?? 0);
        $buy = (float) ($in['buying_price'] ?? 0);
        $unit = in_array($in['unit'] ?? 'piece', ProductModel::UNITS, true) ? ($in['unit'] ?? 'piece') : 'piece';
        if ($name === '') { return ['ok' => false, 'error' => 'Enter the item name.']; }
        if ($qty <= 0) { return ['ok' => false, 'error' => 'Enter a quantity greater than zero.']; }
        if ($buy < 0) { return ['ok' => false, 'error' => 'Enter a valid buying price.']; }
        [$catId, $catName] = $this->resolveCategory($in);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM warehouse_items WHERE tenant_id = ? AND name = ? FOR UPDATE');
            $stmt->execute([$this->homeId, $name]);
            $item = $stmt->fetch();
            if ($item) {
                $oldQty = max(0, (float) $item['quantity']);
                $avg = ($oldQty + $qty) > 0 ? (($oldQty * (float) $item['buying_price']) + ($qty * $buy)) / ($oldQty + $qty) : $buy;
                $this->db->prepare('UPDATE warehouse_items SET quantity = quantity + ?, buying_price = ?, category_id = COALESCE(?, category_id), category = COALESCE(?, category) WHERE id = ?')
                    ->execute([$qty, round($avg, 2), $catId, $catName, $item['id']]);
                $itemId = (int) $item['id'];
            } else {
                $this->db->prepare('INSERT INTO warehouse_items (tenant_id, name, category_id, category, unit, quantity, buying_price, notes) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$this->homeId, $name, $catId, $catName, $unit, $qty, $buy, trim((string) ($in['notes'] ?? '')) ?: null]);
                $itemId = (int) $this->db->lastInsertId();
            }
            $this->log($itemId, $name, 'in', $qty, $buy, null, null, null, null, trim((string) ($in['notes'] ?? '')) ?: null);
            $this->db->commit();
            return ['ok' => true, 'error' => null, 'item_id' => $itemId];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => 'Could not record the stock: ' . $e->getMessage()];
        }
    }

    /**
     * Move stock from the warehouse into one of the owner's shops. At least
     * one of retail / wholesale price must be given; the shop gets a product
     * with that price (matched by name, or created).
     */
    public function transfer(int $itemId, int $targetTenantId, float $qty, $retail, $wholesale, array $serialIds = []): array
    {
        // Electronics: a serial-tracked item moves by the units picked (the
        // quantity follows). Built before the transaction — its schema check runs DDL.
        $serialIds = array_values(array_unique(array_map('intval', $serialIds)));
        $electronics = $serialIds ? new ElectronicsModel($this->db) : null;
        if ($electronics) {
            $valid = array_map('intval', array_column($electronics->warehouseSerials($this->homeId, $itemId), 'id'));
            $serialIds = array_values(array_intersect($serialIds, $valid));
            $qty = (float) count($serialIds);
        } elseif (ElectronicsModel::enabled($this->homeId)) {
            // Units without a serial can move by quantity, but never so many that
            // recorded serials would be left behind for units no longer here.
            $recorded = count((new ElectronicsModel($this->db))->warehouseSerials($this->homeId, $itemId));
            $have = $this->find($itemId);
            if ($recorded > 0 && $have && (float) $have['quantity'] - $qty < $recorded - 0.0001) {
                return ['ok' => false, 'error' => 'Tick which units are moving — this item has serial numbers recorded.'];
            }
        }
        $retail = ($retail === '' || $retail === null) ? 0.0 : (float) $retail;
        $wholesale = ($wholesale === '' || $wholesale === null) ? 0.0 : (float) $wholesale;
        if (!\ShopContext::canAccess($targetTenantId)) { return ['ok' => false, 'error' => 'Choose one of your shops.']; }
        if ($qty <= 0) { return ['ok' => false, 'error' => 'Enter a quantity greater than zero.']; }
        if ($retail < 0 || $wholesale < 0) { return ['ok' => false, 'error' => 'Prices can\'t be negative.']; }
        if ($retail <= 0 && $wholesale <= 0) { return ['ok' => false, 'error' => 'Enter a retail price, a wholesale price, or both.']; }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM warehouse_items WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $stmt->execute([$itemId, $this->homeId]);
            $item = $stmt->fetch();
            if (!$item) { $this->db->rollBack(); return ['ok' => false, 'error' => 'Warehouse item not found.']; }
            if ($qty > (float) $item['quantity'] + 0.0001) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Only ' . rtrim(rtrim(number_format((float) $item['quantity'], 2), '0'), '.') . ' in the warehouse.'];
            }

            $categoryId = $this->categoryFor($targetTenantId, $item);
            $stmt = $this->db->prepare('SELECT * FROM products WHERE tenant_id = ? AND LOWER(name) = LOWER(?) ORDER BY (status = \'active\') DESC, id ASC LIMIT 1 FOR UPDATE');
            $stmt->execute([$targetTenantId, $item['name']]);
            $product = $stmt->fetch();

            if ($product) {
                $newRetail = $retail > 0 ? $retail : (float) $product['retail_price'];
                $newWholesale = $wholesale > 0 ? $wholesale : (float) $product['wholesale_price'];
                $this->db->prepare(
                    "UPDATE products SET quantity = quantity + ?, buying_price = ?, retail_price = ?, wholesale_price = ?,
                            selling_price = ?, status = 'active', category_id = COALESCE(category_id, ?)
                      WHERE id = ? AND tenant_id = ?"
                )->execute([$qty, $item['buying_price'], $newRetail, $newWholesale, $newRetail > 0 ? $newRetail : $newWholesale,
                            $categoryId, $product['id'], $targetTenantId]);
                $productId = (int) $product['id'];
            } else {
                $this->db->prepare(
                    "INSERT INTO products (tenant_id, category_id, name, quantity, unit, buying_price, selling_price, wholesale_price, retail_price, status)
                     VALUES (?,?,?,?,?,?,?,?,?, 'active')"
                )->execute([$targetTenantId, $categoryId, $item['name'], $qty, $item['unit'], $item['buying_price'],
                            $retail > 0 ? $retail : $wholesale, $wholesale, $retail]);
                $productId = (int) $this->db->lastInsertId();
            }

            if ($electronics) {
                $electronics->moveWarehouseSerials($this->homeId, $itemId, $serialIds, $targetTenantId, $productId);
            }
            if (!empty($item['is_serialized'])) {
                $this->db->prepare('UPDATE products SET is_serialized = 1, warranty_months = COALESCE(?, warranty_months) WHERE id = ?')
                    ->execute([$item['warranty_months'] !== null ? (int) $item['warranty_months'] : null, $productId]);
            }
            $this->db->prepare('UPDATE warehouse_items SET quantity = quantity - ? WHERE id = ?')->execute([$qty, $itemId]);
            $this->log($itemId, $item['name'], 'transfer', $qty, (float) $item['buying_price'], $targetTenantId, $productId,
                       $retail > 0 ? $retail : null, $wholesale > 0 ? $wholesale : null, null);
            $this->db->commit();
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => 'Could not move the stock: ' . $e->getMessage()];
        }
    }

    public function deleteItem(int $id): array
    {
        $item = $this->find($id);
        if (!$item) { return ['ok' => false, 'error' => 'Item not found.']; }
        if ((float) $item['quantity'] > 0) { return ['ok' => false, 'error' => 'Move or use up the remaining stock before deleting this item.']; }
        $this->db->prepare('DELETE FROM warehouse_items WHERE id = ? AND tenant_id = ?')->execute([$id, $this->homeId]);
        return ['ok' => true, 'error' => null];
    }

    public function movements(int $limit = 60): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, t.name AS shop_name, u.username AS by_name
               FROM warehouse_movements m
          LEFT JOIN tenants t ON t.id = m.target_tenant_id
          LEFT JOIN users u ON u.id = m.created_by
              WHERE m.tenant_id = ?
           ORDER BY m.created_at DESC, m.id DESC
              LIMIT ' . (int) $limit
        );
        $stmt->execute([$this->homeId]);
        return $stmt->fetchAll();
    }

    /**
     * The business-wide categories (same list every shop uses), for the
     * warehouse's category picker. Lives on the main shop's rows.
     */
    public function categories(): array
    {
        \ShopContext::syncCategories($this->homeId);
        $stmt = $this->db->prepare("SELECT id, name, type FROM categories WHERE tenant_id = ? ORDER BY type ASC, name ASC");
        $stmt->execute([$this->homeId]);
        return $stmt->fetchAll();
    }

    /** category_id picked from the shared list, or a new name typed in → created for every shop. */
    private function resolveCategory(array $in): array
    {
        $id = (int) ($in['category_id'] ?? 0);
        if ($id > 0) {
            $stmt = $this->db->prepare('SELECT id, name FROM categories WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmt->execute([$id, $this->homeId]);
            if ($row = $stmt->fetch()) { return [(int) $row['id'], $row['name']]; }
        }
        $new = trim((string) ($in['new_category'] ?? ''));
        if ($new === '') { return [null, null]; }
        $stmt = $this->db->prepare("SELECT id, name FROM categories WHERE tenant_id = ? AND name = ? ORDER BY (type = 'subject') DESC LIMIT 1");
        $stmt->execute([$this->homeId, $new]);
        if ($row = $stmt->fetch()) { return [(int) $row['id'], $row['name']]; }
        $this->db->prepare("INSERT INTO categories (tenant_id, name, type, status) VALUES (?,?, 'subject', 'active')")->execute([$this->homeId, $new]);
        $newId = (int) $this->db->lastInsertId();
        \ShopContext::syncCategories($this->homeId);
        return [$newId, $new];
    }

    /** The same category (type + name) in the shop the stock is moving to. */
    private function categoryFor(int $tenantId, array $item): ?int
    {
        $name = trim((string) ($item['category'] ?? ''));
        if ($name === '') { return null; }
        $type = 'subject';
        if (!empty($item['category_id'])) {
            $stmt = $this->db->prepare('SELECT type, name FROM categories WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $item['category_id']]);
            if ($row = $stmt->fetch()) { $type = $row['type']; $name = $row['name']; }
        }
        $stmt = $this->db->prepare('SELECT id FROM categories WHERE tenant_id = ? AND type = ? AND name = ? LIMIT 1');
        $stmt->execute([$tenantId, $type, $name]);
        $id = $stmt->fetchColumn();
        if ($id) { return (int) $id; }
        $this->db->prepare("INSERT INTO categories (tenant_id, name, type, status) VALUES (?,?,?, 'active')")->execute([$tenantId, $name, $type]);
        return (int) $this->db->lastInsertId();
    }

    private function log(int $itemId, string $name, string $type, float $qty, float $buy, ?int $target, ?int $productId, ?float $retail, ?float $wholesale, ?string $notes): void
    {
        $this->db->prepare(
            'INSERT INTO warehouse_movements (tenant_id, item_id, item_name, type, quantity, buying_price, target_tenant_id, product_id, retail_price, wholesale_price, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$this->homeId, $itemId, $name, $type, $qty, $buy, $target, $productId, $retail, $wholesale, $notes, \TenantContext::userId()]);
    }
}
