<?php
// app/models/PurchaseModel.php
// Owner's purchases: goods bought in packages (cartons, sacks, bales…) that
// are NOT on sale yet. Each line records the package type, how many packages,
// how many units are in one package and (optionally) the price per package.
// Stock is held internally in UNITS so the maths never drifts; it is shown
// back as "18 cartons + 5 units".
//
// Moving stock into a shop's inventory (this shop or any branch) asks two
// things: how much to move (packages or units) and how it will be SOLD —
// per package or per unit. Selling per package creates/tops up a product
// named "<name> (<Package> of <n>)" priced per package; selling per unit
// tops up "<name>" priced per unit. The buying price follows the same unit.
//
// Money: a purchase is the shop's capital going out — its cost is recorded
// as an expense (category "Purchases (capital)") so it comes off money at
// hand in Finances, and goods still in the purchases store count as stock
// (at cost) until they're moved into inventory.
namespace Models;

class PurchaseModel
{
    public const PACKAGES = ['carton', 'box', 'sack', 'bale', 'crate', 'bag', 'pack', 'dozen', 'roll', 'tin', 'bundle', 'piece'];

    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? \Database::pdo();
        self::ensureSchema($this->db);
    }

    public static function ensureSchema(\PDO $db): void
    {
        static $done = false;
        if ($done) { return; }
        $db->exec("CREATE TABLE IF NOT EXISTS purchases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            supplier VARCHAR(150) NULL,
            notes VARCHAR(255) NULL,
            total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(10) NULL,
            expense_id INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_purchase_tenant (tenant_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS purchase_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT NOT NULL,
            tenant_id INT NOT NULL,
            name VARCHAR(190) NOT NULL,
            category VARCHAR(100) NULL,
            package_type VARCHAR(30) NOT NULL DEFAULT 'carton',
            packages DECIMAL(12,2) NOT NULL,
            units_per_package INT NOT NULL DEFAULT 1,
            package_price DECIMAL(12,2) NULL,
            units_total DECIMAL(14,2) NOT NULL,
            units_remaining DECIMAL(14,2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_purchase_item (tenant_id, units_remaining),
            KEY idx_purchase_item_p (purchase_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS purchase_transfers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            tenant_id INT NOT NULL,
            target_tenant_id INT NOT NULL,
            move_mode ENUM('package','unit') NOT NULL,
            sell_mode ENUM('package','unit') NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            units DECIMAL(14,2) NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            retail_price DECIMAL(12,2) NULL,
            wholesale_price DECIMAL(12,2) NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_purchase_transfer (tenant_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['payment_method' => 'VARCHAR(10) NULL', 'expense_id' => 'INT NULL'] as $col => $def) {
            $chk = $db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'purchases' AND column_name = ?");
            $chk->execute([$col]);
            if (!(int) $chk->fetchColumn()) { $db->exec("ALTER TABLE purchases ADD COLUMN `$col` $def"); }
        }
        $done = true;
    }

    /** Value (at cost) of what's still in the purchases store — counted as stock in Finances. */
    public static function storeValue(\PDO $db, int $tenantId): float
    {
        try {
            $stmt = $db->prepare('SELECT COALESCE(SUM(units_remaining * package_price / GREATEST(units_per_package, 1)), 0) FROM purchase_items WHERE tenant_id = ? AND package_price IS NOT NULL');
            $stmt->execute([$tenantId]);
            return round((float) $stmt->fetchColumn(), 2);
        } catch (\PDOException $e) {
            return 0.0; // no purchases yet
        }
    }

    private function tid(): int { return (int) \TenantContext::tenantId(); }

    public static function packageLabel(string $type, float $n = 2): string
    {
        $t = ucfirst($type);
        if ($n == 1) { return $t; }
        return $type === 'box' ? 'Boxes' : ($type === 'dozen' ? 'Dozens' : $t . 's');
    }

    /** "18 cartons + 5 units" from a unit count. */
    public static function describe(float $units, int $perPackage, string $type): string
    {
        $units = round($units, 2);
        if ($perPackage <= 1) { return self::fmt($units) . ' ' . ($units == 1 ? 'unit' : 'units'); }
        $full = (int) floor($units / $perPackage + 0.00001);
        $loose = round($units - $full * $perPackage, 2);
        $parts = [];
        if ($full) { $parts[] = $full . ' ' . strtolower(self::packageLabel($type, $full)); }
        if ($loose > 0 || !$full) { $parts[] = self::fmt($loose) . ' ' . ($loose == 1 ? 'unit' : 'units'); }
        return implode(' + ', $parts);
    }

    public static function fmt($n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    }

    /** The product a move creates / tops up. */
    public static function productName(array $item, string $sellMode): string
    {
        return $sellMode === 'package' && (int) $item['units_per_package'] > 1
            ? $item['name'] . ' (' . ucfirst($item['package_type']) . ' of ' . (int) $item['units_per_package'] . ')'
            : $item['name'];
    }

    /**
     * Record a purchase. $items: [{name, category, package_type, packages,
     * units_per_package, package_price}].
     */
    public function record(array $header, array $items): array
    {
        $clean = [];
        foreach ($items as $i => $it) {
            $name = trim((string) ($it['name'] ?? ''));
            $pk = (float) ($it['packages'] ?? 0);
            if ($name === '' && $pk <= 0) { continue; }
            $n = $i + 1;
            if ($name === '') { return ['ok' => false, 'error' => "Line $n: enter the item name."]; }
            if ($pk <= 0) { return ['ok' => false, 'error' => "$name: enter how many packages."]; }
            $per = (int) ($it['units_per_package'] ?? 1);
            if ($per < 1) { return ['ok' => false, 'error' => "$name: enter how many units are in one package (1 or more)."]; }
            $type = in_array($it['package_type'] ?? '', self::PACKAGES, true) ? $it['package_type'] : 'carton';
            $price = ($it['package_price'] ?? '') === '' ? null : max(0, round((float) $it['package_price'], 2));
            $clean[] = [$name, trim((string) ($it['category'] ?? '')) ?: null, $type, $pk, $per, $price, round($pk * $per, 2)];
        }
        if (!$clean) { return ['ok' => false, 'error' => 'Add at least one item.']; }
        $method = in_array($header['payment_method'] ?? 'cash', ['cash', 'mpesa', 'bank'], true) ? $header['payment_method'] : 'cash';
        $finance = new FinanceModel($this->db); // before the transaction: its schema check runs DDL

        $this->db->beginTransaction();
        try {
            $total = round(array_sum(array_map(fn($c) => (float) ($c[5] ?? 0) * $c[3], $clean)), 2);
            $supplier = trim((string) ($header['supplier'] ?? ''));
            $this->db->prepare('INSERT INTO purchases (tenant_id, supplier, notes, total_cost, payment_method, created_by) VALUES (?,?,?,?,?,?)')
                ->execute([$this->tid(), $supplier ?: null, trim((string) ($header['notes'] ?? '')) ?: null, $total, $method, \TenantContext::userId()]);
            $pid = (int) $this->db->lastInsertId();
            if ($total > 0) {
                $what = implode(', ', array_map(fn($c) => self::fmt($c[3]) . ' ' . $c[2] . ' ' . $c[0], $clean));
                $res = $finance->recordExpense([
                    'title' => mb_substr('Purchase' . ($supplier !== '' ? ' — ' . $supplier : '') . ': ' . $what, 0, 160),
                    'category' => 'Purchases (capital)', 'amount' => $total, 'payment_method' => $method,
                    'notes' => trim((string) ($header['notes'] ?? '')) ?: null, 'description' => 'Recorded from Purchases (#' . $pid . ').',
                ]);
                if (!$res['ok']) { $this->db->rollBack(); return ['ok' => false, 'error' => $res['error']]; }
                $this->db->prepare('UPDATE purchases SET expense_id = ? WHERE id = ?')->execute([(int) $this->db->lastInsertId(), $pid]);
            }
            $ins = $this->db->prepare('INSERT INTO purchase_items (purchase_id, tenant_id, name, category, package_type, packages, units_per_package, package_price, units_total, units_remaining) VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach ($clean as [$name, $cat, $type, $pk, $per, $price, $units]) {
                $ins->execute([$pid, $this->tid(), mb_substr($name, 0, 190), $cat, $type, $pk, $per, $price, $units, $units]);
            }
            $this->db->commit();
            return ['ok' => true, 'error' => null, 'id' => $pid, 'items' => count($clean), 'total' => $total];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('PurchaseModel::record failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the purchase. Please try again.'];
        }
    }

    /** Items still waiting to go on sale (units left), oldest first. */
    public function inStore(): array
    {
        $stmt = $this->db->prepare(
            'SELECT i.*, p.supplier, p.created_at AS purchased_at FROM purchase_items i JOIN purchases p ON p.id = i.purchase_id
              WHERE i.tenant_id = ? AND i.units_remaining > 0 ORDER BY i.name, i.id'
        );
        $stmt->execute([$this->tid()]);
        return $stmt->fetchAll();
    }

    public function find(int $itemId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM purchase_items WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$itemId, $this->tid()]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Move stock from purchases into a shop's inventory.
     * $moveMode: 'package' | 'unit' — what $qty counts.
     * $sellMode: 'package' | 'unit' — how the shop will sell it (prices are per that).
     */
    public function moveToInventory(int $itemId, int $targetTid, string $moveMode, float $qty, string $sellMode, $retail, $wholesale): array
    {
        $moveMode = $moveMode === 'package' ? 'package' : 'unit';
        $sellMode = $sellMode === 'package' ? 'package' : 'unit';
        $retail = ($retail === '' || $retail === null) ? 0.0 : round((float) $retail, 2);
        $wholesale = ($wholesale === '' || $wholesale === null) ? 0.0 : round((float) $wholesale, 2);
        if (!\ShopContext::canAccess($targetTid)) { return ['ok' => false, 'error' => 'Choose one of your shops.']; }
        if ($qty <= 0) { return ['ok' => false, 'error' => 'Enter how much to move.']; }
        if ($retail < 0 || $wholesale < 0) { return ['ok' => false, 'error' => 'Prices can\'t be negative.']; }
        if ($retail <= 0 && $wholesale <= 0) { return ['ok' => false, 'error' => 'Enter a retail price, a wholesale price, or both.']; }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM purchase_items WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $stmt->execute([$itemId, $this->tid()]);
            $it = $stmt->fetch();
            if (!$it) { $this->db->rollBack(); return ['ok' => false, 'error' => 'Purchase item not found.']; }
            $per = max(1, (int) $it['units_per_package']);
            if ($sellMode === 'package' && $per === 1) { $sellMode = 'unit'; } // one unit per package: they're the same thing
            $units = $moveMode === 'package' ? round($qty * $per, 2) : round($qty, 2);
            $left = (float) $it['units_remaining'];
            if ($units > $left + 0.0001) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Only ' . self::describe($left, $per, $it['package_type']) . ' of ' . $it['name'] . ' left.'];
            }
            if ($sellMode === 'package' && abs($units / $per - round($units / $per)) > 0.0001) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'To sell by the ' . $it['package_type'] . ', move whole ' . strtolower(self::packageLabel($it['package_type'])) . ' (' . $per . ' units each).'];
            }
            // What lands on the shelf, counted in the unit it's sold in.
            $shelfQty = $sellMode === 'package' ? round($units / $per, 2) : $units;
            $pkgPrice = $it['package_price'] !== null ? (float) $it['package_price'] : 0.0;
            $buy = $sellMode === 'package' ? $pkgPrice : round($pkgPrice / $per, 2);
            $name = self::productName($it, $sellMode);
            $catId = $this->categoryFor($targetTid, $it['category']);

            $p = $this->db->prepare("SELECT * FROM products WHERE tenant_id = ? AND LOWER(name) = LOWER(?) ORDER BY (status = 'active') DESC, id ASC LIMIT 1 FOR UPDATE");
            $p->execute([$targetTid, $name]);
            $prod = $p->fetch();
            if ($prod) {
                $oldQty = max(0, (float) $prod['quantity']);
                $avg = $buy > 0 && ($oldQty + $shelfQty) > 0 ? (($oldQty * (float) $prod['buying_price']) + ($shelfQty * $buy)) / ($oldQty + $shelfQty) : (float) $prod['buying_price'];
                $newRetail = $retail > 0 ? $retail : (float) $prod['retail_price'];
                $newWholesale = $wholesale > 0 ? $wholesale : (float) $prod['wholesale_price'];
                $this->db->prepare(
                    "UPDATE products SET quantity = quantity + ?, buying_price = ?, retail_price = ?, wholesale_price = ?, selling_price = ?,
                            status = 'active', category_id = COALESCE(category_id, ?) WHERE id = ?"
                )->execute([$shelfQty, round($avg, 2), $newRetail, $newWholesale, $newRetail > 0 ? $newRetail : $newWholesale, $catId, $prod['id']]);
                $productId = (int) $prod['id'];
            } else {
                $this->db->prepare(
                    "INSERT INTO products (tenant_id, category_id, name, quantity, unit, buying_price, selling_price, wholesale_price, retail_price, status)
                     VALUES (?,?,?,?, 'piece', ?,?,?,?, 'active')"
                )->execute([$targetTid, $catId, $name, $shelfQty, $buy, $retail > 0 ? $retail : $wholesale, $wholesale, $retail]);
                $productId = (int) $this->db->lastInsertId();
            }

            $this->db->prepare('UPDATE purchase_items SET units_remaining = units_remaining - ? WHERE id = ?')->execute([$units, $itemId]);
            $this->db->prepare(
                'INSERT INTO purchase_transfers (item_id, tenant_id, target_tenant_id, move_mode, sell_mode, quantity, units, product_id, product_name, retail_price, wholesale_price, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$itemId, $this->tid(), $targetTid, $moveMode, $sellMode, $qty, $units, $productId, $name, $retail ?: null, $wholesale ?: null, \TenantContext::userId()]);
            $this->db->commit();
            return ['ok' => true, 'error' => null, 'product' => $name, 'shelf_qty' => $shelfQty, 'sell_mode' => $sellMode, 'units' => $units];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('PurchaseModel::moveToInventory failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not move the stock. Please try again.'];
        }
    }

    /** Category by name in the target shop (created when missing). */
    private function categoryFor(int $tenantId, ?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') { return null; }
        $stmt = $this->db->prepare("SELECT id FROM categories WHERE tenant_id = ? AND name = ? ORDER BY (type = 'subject') DESC LIMIT 1");
        $stmt->execute([$tenantId, $name]);
        $id = $stmt->fetchColumn();
        if ($id) { return (int) $id; }
        $this->db->prepare("INSERT INTO categories (tenant_id, name, type, status) VALUES (?,?, 'subject', 'active')")->execute([$tenantId, $name]);
        return (int) $this->db->lastInsertId();
    }

    public function recentPurchases(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, u.username AS by_name, (SELECT COUNT(*) FROM purchase_items i WHERE i.purchase_id = p.id) AS item_count,
                    (SELECT GROUP_CONCAT(CONCAT(TRIM(TRAILING \'.\' FROM TRIM(TRAILING \'0\' FROM i.packages)), \' \', i.package_type, \' \', i.name) SEPARATOR \', \')
                       FROM purchase_items i WHERE i.purchase_id = p.id) AS summary
               FROM purchases p LEFT JOIN users u ON u.id = p.created_by WHERE p.tenant_id = ? ORDER BY p.created_at DESC, p.id DESC LIMIT ' . (int) $limit
        );
        $stmt->execute([$this->tid()]);
        return $stmt->fetchAll();
    }

    public function recentTransfers(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, i.name, i.package_type, i.units_per_package, s.name AS shop_name, u.username AS by_name
               FROM purchase_transfers t JOIN purchase_items i ON i.id = t.item_id
          LEFT JOIN tenants s ON s.id = t.target_tenant_id LEFT JOIN users u ON u.id = t.created_by
              WHERE t.tenant_id = ? ORDER BY t.created_at DESC, t.id DESC LIMIT ' . (int) $limit
        );
        $stmt->execute([$this->tid()]);
        return $stmt->fetchAll();
    }
}
