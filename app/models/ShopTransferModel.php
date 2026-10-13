<?php
// app/models/ShopTransferModel.php
// Move products from one of the owner's shops into another (usually from the
// Admin portal / main shop out to the branches). Each line takes stock off
// the source product and adds it to the same product in the target shop —
// matched by name, or created there as a copy (same prices, photo, colours…).
// Serial-tracked units move by serial / IMEI. Every transfer is logged.
namespace Models;

class ShopTransferModel
{
    private \PDO $db;

    /** Product columns that point at the SOURCE shop's own rows — not copied. */
    private const SHOP_SCOPED = ['id', 'tenant_id', 'category_id', 'subcategory_id', 'grade_id', 'publisher_id', 'author_id',
                                'edition_id', 'brand_id', 'supplier_id', 'quantity', 'low_stock_notified_at', 'created_at', 'updated_at'];

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? \Database::pdo();
        self::ensureSchema($this->db);
    }

    public static function ensureSchema(\PDO $db): void
    {
        static $done = false;
        if ($done) { return; }
        $db->exec(
            "CREATE TABLE IF NOT EXISTS shop_transfers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                from_tenant_id INT NOT NULL,
                to_tenant_id INT NOT NULL,
                notes VARCHAR(255) NULL,
                item_count INT NOT NULL DEFAULT 0,
                total_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_value DECIMAL(14,2) NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_shop_transfer_from (from_tenant_id, created_at),
                KEY idx_shop_transfer_to (to_tenant_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS shop_transfer_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transfer_id INT NOT NULL,
                from_product_id INT NOT NULL,
                to_product_id INT NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                quantity DECIMAL(12,2) NOT NULL,
                buying_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                serials TEXT NULL,
                KEY idx_shop_transfer_item (transfer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $done = true;
    }

    /**
     * $lines: product_id => quantity. $serialIds: product_id => [device_serials.id]
     * — for a serial-tracked product the units picked ARE the quantity.
     */
    public function transfer(int $fromTid, int $toTid, array $lines, array $serialIds = [], string $notes = ''): array
    {
        if (!\ShopContext::canAccess($fromTid) || !\ShopContext::canAccess($toTid)) {
            return ['ok' => false, 'error' => 'Choose one of your shops.'];
        }
        if ($fromTid === $toTid) {
            return ['ok' => false, 'error' => 'Pick a different shop to send to.'];
        }
        $electronics = ElectronicsModel::enabled($fromTid) ? new ElectronicsModel($this->db) : null; // before the transaction (DDL)
        $plan = [];
        foreach (array_unique(array_merge(array_keys($lines), array_keys($serialIds))) as $pid) {
            $qty = $lines[$pid] ?? 0;
            $pid = (int) $pid;
            $picked = array_values(array_unique(array_map('intval', (array) ($serialIds[$pid] ?? []))));
            $qty = $picked ? (float) count($picked) : round((float) $qty, 2);
            if ($pid > 0 && $qty > 0) { $plan[$pid] = [$qty, $picked]; }
        }
        if (!$plan) {
            return ['ok' => false, 'error' => 'Enter how many of at least one product to send.'];
        }

        $db = $this->db;
        try {
            $db->beginTransaction();
            $sel = $db->prepare('SELECT * FROM products WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $match = $db->prepare("SELECT * FROM products WHERE tenant_id = ? AND LOWER(name) = LOWER(?) ORDER BY (status = 'active') DESC, id ASC LIMIT 1 FOR UPDATE");
            $dec = $db->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ? AND tenant_id = ? AND quantity >= ?');
            $db->prepare('INSERT INTO shop_transfers (from_tenant_id, to_tenant_id, notes, created_by) VALUES (?,?,?,?)')
               ->execute([$fromTid, $toTid, trim($notes) !== '' ? mb_substr(trim($notes), 0, 255) : null, \TenantContext::userId()]);
            $transferId = (int) $db->lastInsertId();
            $insLine = $db->prepare('INSERT INTO shop_transfer_items (transfer_id, from_product_id, to_product_id, product_name, quantity, buying_price, serials) VALUES (?,?,?,?,?,?,?)');
            $totQty = 0.0;
            $totVal = 0.0;

            foreach ($plan as $pid => [$qty, $picked]) {
                $sel->execute([$pid, $fromTid]);
                $src = $sel->fetch();
                if (!$src) { $db->rollBack(); return ['ok' => false, 'error' => 'One of the products is no longer in this shop.']; }
                if ($qty > (float) $src['quantity'] + 0.0001) {
                    $db->rollBack();
                    return ['ok' => false, 'error' => "Only " . rtrim(rtrim(number_format((float) $src['quantity'], 2), '0'), '.') . " × {$src['name']} in stock."];
                }

                // Serials: the units picked must be this product's, on the shelf here.
                $serialList = [];
                if ($picked) {
                    $in = implode(',', array_fill(0, count($picked), '?'));
                    $chk = $db->prepare("SELECT id, serial FROM device_serials WHERE id IN ($in) AND tenant_id = ? AND product_id = ? AND status = 'in_stock' FOR UPDATE");
                    $chk->execute(array_merge($picked, [$fromTid, $pid]));
                    $rows = $chk->fetchAll();
                    if (count($rows) !== count($picked)) {
                        $db->rollBack();
                        return ['ok' => false, 'error' => "Some of the {$src['name']} units picked are no longer in stock here."];
                    }
                    $serialList = array_column($rows, 'serial');
                } elseif ($electronics && !empty($src['is_serialized'])) {
                    // Units without a serial may move by quantity, but never leave serials behind for units that left.
                    $cnt = $db->prepare("SELECT COUNT(*) FROM device_serials WHERE tenant_id = ? AND product_id = ? AND status = 'in_stock'");
                    $cnt->execute([$fromTid, $pid]);
                    if ((float) $src['quantity'] - $qty < (int) $cnt->fetchColumn() - 0.0001) {
                        $db->rollBack();
                        return ['ok' => false, 'error' => "Tick which {$src['name']} units are going — they have serial numbers."];
                    }
                }

                $dec->execute([$qty, $pid, $fromTid, $qty]);
                if ($dec->rowCount() !== 1) { $db->rollBack(); return ['ok' => false, 'error' => "Stock for {$src['name']} changed — try again."]; }

                $match->execute([$toTid, $src['name']]);
                $dst = $match->fetch();
                if ($dst) {
                    // Restock: weighted-average buying price; the shop's own selling prices stay.
                    $oldQty = max(0, (float) $dst['quantity']);
                    $buy = ($oldQty + $qty) > 0 ? (($oldQty * (float) $dst['buying_price']) + ($qty * (float) $src['buying_price'])) / ($oldQty + $qty) : (float) $src['buying_price'];
                    $db->prepare("UPDATE products SET quantity = quantity + ?, buying_price = ?, status = IF(status = 'draft', status, 'active') WHERE id = ?")
                       ->execute([$qty, round($buy, 2), $dst['id']]);
                    $toPid = (int) $dst['id'];
                    if (!empty($src['is_serialized']) && empty($dst['is_serialized'])) {
                        $db->prepare('UPDATE products SET is_serialized = 1, warranty_months = COALESCE(warranty_months, ?) WHERE id = ?')->execute([$src['warranty_months'], $toPid]);
                    }
                } else {
                    $toPid = $this->copyProduct($src, $toTid, $qty);
                }

                if ($picked) {
                    $db->prepare("UPDATE device_serials SET tenant_id = ?, product_id = ? WHERE id IN (" . implode(',', array_fill(0, count($picked), '?')) . ")")
                       ->execute(array_merge([$toTid, $toPid], $picked));
                }
                $insLine->execute([$transferId, $pid, $toPid, $src['name'], $qty, (float) $src['buying_price'], $serialList ? implode(', ', $serialList) : null]);
                $totQty += $qty;
                $totVal += $qty * (float) $src['buying_price'];
            }

            $db->prepare('UPDATE shop_transfers SET item_count = ?, total_qty = ?, total_value = ? WHERE id = ?')
               ->execute([count($plan), round($totQty, 2), round($totVal, 2), $transferId]);
            $db->commit();
            return ['ok' => true, 'error' => null, 'transfer_id' => $transferId, 'items' => count($plan), 'qty' => $totQty];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('ShopTransferModel::transfer failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not complete the transfer. Please try again.'];
        }
    }

    /** New product in the target shop: same details and prices, its own category row, no shop-specific links. */
    private function copyProduct(array $src, int $toTid, float $qty): int
    {
        $row = array_diff_key($src, array_flip(self::SHOP_SCOPED));
        $row['tenant_id'] = $toTid;
        $row['quantity'] = $qty;
        if (($row['status'] ?? 'active') !== 'draft') { $row['status'] = 'active'; } // it's arriving to be sold
        $row['category_id'] = $this->categoryFor($toTid, isset($src['category_id']) ? (int) $src['category_id'] : 0);
        if (!empty($row['barcode'])) {
            $b = $this->db->prepare('SELECT 1 FROM products WHERE tenant_id = ? AND barcode = ? LIMIT 1');
            $b->execute([$toTid, $row['barcode']]);
            if ($b->fetchColumn()) { $row['barcode'] = null; } // the target already uses that code elsewhere
        }
        $cols = array_keys($row);
        $this->db->prepare('INSERT INTO products (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                 ->execute(array_values($row));
        return (int) $this->db->lastInsertId();
    }

    /** The same category (type + name) in the target shop, created if it's missing. */
    private function categoryFor(int $tenantId, int $sourceCategoryId): ?int
    {
        if ($sourceCategoryId <= 0) { return null; }
        $stmt = $this->db->prepare('SELECT name, type FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$sourceCategoryId]);
        $c = $stmt->fetch();
        if (!$c) { return null; }
        $stmt = $this->db->prepare('SELECT id FROM categories WHERE tenant_id = ? AND type = ? AND name = ? LIMIT 1');
        $stmt->execute([$tenantId, $c['type'], $c['name']]);
        $id = $stmt->fetchColumn();
        if ($id) { return (int) $id; }
        $this->db->prepare("INSERT INTO categories (tenant_id, name, type, status) VALUES (?,?,?, 'active')")->execute([$tenantId, $c['name'], $c['type']]);
        return (int) $this->db->lastInsertId();
    }

    /** Recent transfers out of (or into) the owner's shops, newest first, with their lines. */
    public function recent(array $tenantIds, int $limit = 30): array
    {
        $tenantIds = array_values(array_filter(array_map('intval', $tenantIds)));
        if (!$tenantIds) { return []; }
        $in = implode(',', array_fill(0, count($tenantIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT t.*, f.name AS from_name, d.name AS to_name, u.username AS by_name
               FROM shop_transfers t
          LEFT JOIN tenants f ON f.id = t.from_tenant_id
          LEFT JOIN tenants d ON d.id = t.to_tenant_id
          LEFT JOIN users u ON u.id = t.created_by
              WHERE t.from_tenant_id IN ($in) OR t.to_tenant_id IN ($in)
           ORDER BY t.created_at DESC, t.id DESC LIMIT " . (int) $limit
        );
        $stmt->execute(array_merge($tenantIds, $tenantIds));
        $rows = $stmt->fetchAll();
        if (!$rows) { return []; }
        $ids = array_column($rows, 'id');
        $li = $this->db->prepare('SELECT * FROM shop_transfer_items WHERE transfer_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $li->execute($ids);
        $lines = [];
        foreach ($li->fetchAll() as $l) { $lines[(int) $l['transfer_id']][] = $l; }
        foreach ($rows as &$r) { $r['lines'] = $lines[(int) $r['id']] ?? []; }
        unset($r);
        return $rows;
    }
}
