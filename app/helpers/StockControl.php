<?php
// app/helpers/StockControl.php
// The Stock Control package: a separate stock-management system for
// organisations that only manage stock (no selling). It lives on the same
// platform but is its own world — own login (with a required emailed code),
// own look, own pages under /stock/. Stock never leaves the system: it is
// received into a store, moved between stores, and corrected by counts.
//
//   Account  = the top-level tenant (the organisation), with STOCK_CONTROL on
//   Stores   = the account tenant + its child tenants (parent_tenant_id)
//   Items    = products rows of each store (quantity, cost = buying_price)
//   History  = stock_moves (every receipt, transfer and correction)
//   People   = owner (primary), administrators (tenant_owner), staff
//
// Rights: the owner and administrators can do everything (administrators
// can't manage other administrators). Staff can only do what the owner
// ticked for them, in their own store.

class StockControl
{
    const RIGHTS = [
        'stock.receive'  => 'Receive stock',
        'stock.transfer' => 'Transfer stock to other stores',
        'stock.count'    => 'Count and correct stock',
        'stock.items'    => 'Add and edit items',
        'stock.value'    => 'See stock value and costs',
        'stock.finance'  => 'See and record finances (income, expenses, profit)',
    ];
    const UNITS = ['piece', 'box', 'carton', 'pack', 'kg', 'g', 'litre', 'ml', 'metre', 'pair', 'set', 'roll', 'bag'];

    // ------------------------------------------------------------------ setup

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $db = Database::pdo();
        // Latest version: deliveries / suppliers on movements, warehouse or shop stores.
        try { $db->query('SELECT delivered_by FROM stock_moves LIMIT 0'); $db->query('SELECT store_kind FROM tenants LIMIT 0'); $db->query('SELECT 1 FROM stock_cash LIMIT 0'); return; } catch (\Throwable $e) { /* create / upgrade */ }
        $db->exec("CREATE TABLE IF NOT EXISTS stock_moves (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            tenant_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(160) NOT NULL,
            type ENUM('receive','transfer_out','transfer_in','correction','opening') NOT NULL,
            qty DECIMAL(12,2) NOT NULL,
            balance DECIMAL(12,2) NOT NULL,
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            ref VARCHAR(40) NULL,
            other_tenant_id INT NULL,
            note VARCHAR(255) NULL,
            user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sm_account (account_id, created_at),
            KEY idx_sm_store (tenant_id, created_at),
            KEY idx_sm_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS stock_cash (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            tenant_id INT NOT NULL,
            entry_date DATE NOT NULL,
            kind ENUM('income','expense') NOT NULL,
            category VARCHAR(80) NULL,
            description VARCHAR(255) NULL,
            amount DECIMAL(14,2) NOT NULL,
            method VARCHAR(20) NULL,
            ref VARCHAR(40) NULL,
            user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sc_account (account_id, entry_date),
            KEY idx_sc_store (tenant_id, entry_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            "ALTER TABLE stock_moves MODIFY type ENUM('receive','transfer_out','transfer_in','correction','opening','clear') NOT NULL",
            'ALTER TABLE stock_moves ADD COLUMN supplier VARCHAR(160) NULL',
            'ALTER TABLE stock_moves ADD COLUMN doc_no VARCHAR(60) NULL',
            'ALTER TABLE stock_moves ADD COLUMN delivered_by VARCHAR(120) NULL',
            'ALTER TABLE stock_moves ADD COLUMN vehicle VARCHAR(60) NULL',
            "ALTER TABLE tenants ADD COLUMN store_kind ENUM('warehouse','shop') NULL",
        ] as $sql) {
            try { $db->exec($sql); } catch (\Throwable $e) { /* already there */ }
        }
    }

    // ------------------------------------------------------------- who & where

    public static function account(): int
    {
        return Features::accountId((int) TenantContext::tenantId());
    }

    /** Owner or administrator (both have the tenant_owner role). */
    public static function isAdmin(): bool
    {
        return TenantContext::role() === 'tenant_owner';
    }

    /** The organisation's owner — the one account nobody can restrict. */
    public static function isOwner(): bool
    {
        $st = Database::pdo()->prepare('SELECT owner_user_id FROM tenants WHERE id = ?');
        $st->execute([self::account()]);
        return self::isAdmin() && (int) $st->fetchColumn() === (int) TenantContext::userId();
    }

    public static function can(string $right): bool
    {
        if (self::isAdmin()) return true;
        return in_array($right, $_SESSION['stock_rights'] ?? [], true);
    }

    /** A staff member's rights, read at sign-in. */
    public static function rightsOf(int $userId): array
    {
        $st = Database::pdo()->prepare("SELECT capability FROM user_permissions WHERE user_id = ? AND effect = 'grant' AND capability LIKE 'stock.%'");
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Stores of the organisation: id => row (main first). */
    public static function stores(?int $account = null, bool $activeOnly = true): array
    {
        $account ??= self::account();
        $st = Database::pdo()->prepare('SELECT * FROM tenants WHERE (id = ? OR parent_tenant_id = ?)' . ($activeOnly ? " AND status = 'active'" : '') . ' ORDER BY (id = ?) DESC, name');
        $st->execute([$account, $account, $account]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[(int) $r['id']] = $r;
        return $out;
    }

    /** Stores this person may work in: all for admins, their own for staff. */
    public static function myStores(): array
    {
        $all = self::stores();
        if (self::isAdmin()) return $all;
        $home = (int) ($_SESSION['stock_home'] ?? TenantContext::tenantId());
        return isset($all[$home]) ? [$home => $all[$home]] : [];
    }

    public static function canUseStore(int $storeId): bool
    {
        return isset(self::myStores()[$storeId]);
    }

    public static function createStore(string $name, string $location = '', string $kind = 'shop'): array
    {
        $name = trim($name);
        if ($name === '') return [false, 'Enter the store name.'];
        foreach (self::stores(null, false) as $s) {
            if (strcasecmp($s['name'], $name) === 0) return [false, 'You already have a store called "' . $name . '".'];
        }
        $db = Database::pdo();
        $acc = self::account();
        $T = new Models\TenantModel($db);
        $owner = (int) $db->query('SELECT owner_user_id FROM tenants WHERE id = ' . $acc)->fetchColumn();
        self::ensure();
        $db->prepare("INSERT INTO tenants (name, slug, owner_user_id, parent_tenant_id, status, address, store_kind) VALUES (?, ?, ?, ?, 'active', ?, ?)")
           ->execute([$name, $T->uniqueSlug($name), $owner ?: null, $acc, trim($location) ?: null, $kind === 'warehouse' ? 'warehouse' : 'shop']);
        return [true, (int) $db->lastInsertId()];
    }

    /** 'Warehouse' or 'Shop' (the main store counts as a warehouse until set). */
    public static function kindOf(array $store): string
    {
        $k = $store['store_kind'] ?? null;
        if (!$k) $k = empty($store['parent_tenant_id']) ? 'warehouse' : 'shop';
        return $k === 'warehouse' ? 'Warehouse' : 'Shop';
    }

    /** Suppliers typed before, for suggestions. */
    public static function suppliers(): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT DISTINCT supplier FROM stock_moves WHERE account_id = ? AND supplier IS NOT NULL ORDER BY supplier LIMIT 300');
        $st->execute([self::account()]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    // ------------------------------------------------------------- categories
    // Categories belong to the organisation: the same name in every store.

    /** name => number of items using it, across all stores. */
    public static function categoryList(): array
    {
        $ids = array_keys(self::stores(null, false));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("SELECT c.name, COUNT(p.id) n FROM categories c LEFT JOIN products p ON p.category_id = c.id AND p.status <> 'archived'
                                         WHERE c.tenant_id IN ($in) GROUP BY c.name ORDER BY c.name");
        $st->execute($ids);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function addCategory(string $name): array
    {
        $name = trim($name);
        if ($name === '') return [false, 'Enter the category name.'];
        if (isset(self::categoryList()[$name])) return [false, 'You already have a category called "' . $name . '".'];
        foreach (array_keys(self::stores(null, false)) as $sid) self::categoryId($sid, $name);
        return [true, null];
    }

    public static function renameCategory(string $old, string $new): array
    {
        $new = trim($new);
        if ($new === '') return [false, 'Enter the new name.'];
        $db = Database::pdo();
        foreach (array_keys(self::stores(null, false)) as $sid) {
            $o = $db->prepare('SELECT id FROM categories WHERE tenant_id = ? AND name = ? LIMIT 1'); $o->execute([$sid, $old]);
            $oldId = $o->fetchColumn();
            if (!$oldId) continue;
            $n = $db->prepare('SELECT id FROM categories WHERE tenant_id = ? AND name = ? LIMIT 1'); $n->execute([$sid, $new]);
            if ($newId = $n->fetchColumn()) { // the new name exists here: merge into it
                $db->prepare('UPDATE products SET category_id = ? WHERE category_id = ?')->execute([$newId, $oldId]);
                $db->prepare('DELETE FROM categories WHERE id = ?')->execute([$oldId]);
            } else {
                $db->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$new, $oldId]);
            }
        }
        return [true, null];
    }

    public static function deleteCategory(string $name): array
    {
        if ((int) (self::categoryList()[$name] ?? 0) > 0) return [false, 'Some items still use "' . $name . '". Rename it, or move those items to another category first.'];
        $ids = array_keys(self::stores(null, false));
        $in = implode(',', array_fill(0, count($ids), '?'));
        Database::pdo()->prepare("DELETE FROM categories WHERE name = ? AND tenant_id IN ($in)")->execute(array_merge([$name], $ids));
        return [true, null];
    }

    // ---------------------------------------------------------------- finance
    // Income and expenses are recorded by hand (or income from stock cleared with
    // a selling price). Stock purchases come from received stock that has a buying
    // price. Everything is optional: no prices, no figures.
    //   Money in   = recorded income
    //   Money out  = stock purchases + recorded expenses
    //   Cash flow  = money in − money out
    //   Profit     = income − cost of the stock that went out − expenses

    const EXPENSE_CATEGORIES = ['Transport', 'Rent', 'Salaries', 'Utilities', 'Packaging', 'Repairs', 'Fuel', 'Other'];

    private static function insertCash(int $storeId, string $kind, string $date, string $category, string $desc, float $amount, string $method, ?string $ref = null): void
    {
        self::ensure();
        Database::pdo()->prepare('INSERT INTO stock_cash (account_id, tenant_id, entry_date, kind, category, description, amount, method, ref, user_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([self::account(), $storeId, $date, $kind, mb_substr(trim($category), 0, 80) ?: null, mb_substr(trim($desc), 0, 255) ?: null,
                       $amount, mb_substr(trim($method), 0, 20) ?: null, $ref, TenantContext::userId()]);
    }

    public static function addCash(int $storeId, string $kind, string $date, string $category, string $desc, float $amount, string $method): array
    {
        if (!self::canUseStore($storeId)) return [false, 'Choose one of your stores.'];
        if (!in_array($kind, ['income', 'expense'], true)) return [false, 'Choose income or expense.'];
        if ($amount <= 0) return [false, 'Enter an amount greater than zero.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        self::insertCash($storeId, $kind, $date, $category, $desc, round($amount, 2), $method);
        return [true, null];
    }

    public static function deleteCash(int $id): void
    {
        self::ensure();
        Database::pdo()->prepare('DELETE FROM stock_cash WHERE id = ? AND account_id = ?')->execute([$id, self::account()]);
    }

    public static function cashEntries(array $storeIds, string $from, string $to, int $limit = 500): array
    {
        self::ensure();
        if (!$storeIds) return [];
        $in = implode(',', array_fill(0, count($storeIds), '?'));
        $st = Database::pdo()->prepare("SELECT c.*, t.name AS store_name, u.username FROM stock_cash c LEFT JOIN tenants t ON t.id = c.tenant_id LEFT JOIN users u ON u.id = c.user_id
                                         WHERE c.tenant_id IN ($in) AND c.entry_date BETWEEN ? AND ? ORDER BY c.entry_date DESC, c.id DESC LIMIT " . (int) $limit);
        $st->execute(array_merge(array_values($storeIds), [$from, $to]));
        return $st->fetchAll();
    }

    /** Totals for a period and a month-by-month breakdown. */
    public static function finance(array $storeIds, string $from, string $to): array
    {
        self::ensure();
        $out = ['income' => 0.0, 'expenses' => 0.0, 'purchases' => 0.0, 'cogs' => 0.0, 'months' => []];
        if (!$storeIds) return $out + ['money_out' => 0.0, 'cash_flow' => 0.0, 'profit' => 0.0];
        $db = Database::pdo();
        $in = implode(',', array_fill(0, count($storeIds), '?'));
        $ids = array_values($storeIds);
        $mo = function (string $m) use (&$out) { return $out['months'][$m] ??= ['income' => 0.0, 'expenses' => 0.0, 'purchases' => 0.0, 'cogs' => 0.0]; };
        $st = $db->prepare("SELECT DATE_FORMAT(entry_date, '%Y-%m') m, kind, SUM(amount) v FROM stock_cash WHERE tenant_id IN ($in) AND entry_date BETWEEN ? AND ? GROUP BY m, kind");
        $st->execute(array_merge($ids, [$from, $to]));
        foreach ($st->fetchAll() as $r) {
            $k = $r['kind'] === 'income' ? 'income' : 'expenses';
            $out[$k] += (float) $r['v']; $out['months'][$r['m']] = $mo($r['m']); $out['months'][$r['m']][$k] += (float) $r['v'];
        }
        $st = $db->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') m, type, SUM(ABS(qty) * unit_cost) v FROM stock_moves
                             WHERE tenant_id IN ($in) AND created_at >= ? AND created_at < ? AND type IN ('receive','clear') GROUP BY m, type");
        $st->execute(array_merge($ids, [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']));
        foreach ($st->fetchAll() as $r) {
            $k = $r['type'] === 'receive' ? 'purchases' : 'cogs';
            $out[$k] += (float) $r['v']; $out['months'][$r['m']] = $mo($r['m']); $out['months'][$r['m']][$k] += (float) $r['v'];
        }
        ksort($out['months']);
        $out['money_out'] = $out['expenses'] + $out['purchases'];
        $out['cash_flow'] = $out['income'] - $out['money_out'];
        $out['profit'] = $out['income'] - $out['cogs'] - $out['expenses'];
        return $out;
    }

    // ------------------------------------------------------------------ items

    public static function items(int $storeId, string $q = '', int $categoryId = 0): array
    {
        $sql = "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.tenant_id = ? AND p.status <> 'archived'";
        $params = [$storeId];
        if ($q !== '') { $sql .= ' AND (p.name LIKE ? OR p.barcode LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        if ($categoryId) { $sql .= ' AND p.category_id = ?'; $params[] = $categoryId; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY p.name');
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function item(int $storeId, int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? AND p.tenant_id = ?');
        $st->execute([$id, $storeId]);
        return $st->fetch() ?: null;
    }

    public static function categories(int $storeId): array
    {
        $st = Database::pdo()->prepare("SELECT id, name FROM categories WHERE tenant_id = ? ORDER BY name");
        $st->execute([$storeId]);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private static function categoryId(int $storeId, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;
        $db = Database::pdo();
        $st = $db->prepare('SELECT id FROM categories WHERE tenant_id = ? AND name = ? LIMIT 1');
        $st->execute([$storeId, $name]);
        if ($id = $st->fetchColumn()) return (int) $id;
        $db->prepare("INSERT INTO categories (tenant_id, name, type, status) VALUES (?, ?, 'subject', 'active')")->execute([$storeId, $name]);
        return (int) $db->lastInsertId();
    }

    /** Add or edit an item (not its quantity — that only changes through receipts, transfers and counts). */
    public static function saveItem(int $storeId, array $in, ?int $id = null): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') return [false, 'Enter the item name.'];
        $code = trim((string) ($in['code'] ?? ''));
        $db = Database::pdo();
        if ($code !== '') {
            $st = $db->prepare('SELECT id FROM products WHERE tenant_id = ? AND barcode = ? AND id <> ? LIMIT 1');
            $st->execute([$storeId, $code, (int) $id]);
            if ($st->fetchColumn()) return [false, 'Another item in this store already uses the code ' . $code . '.'];
        }
        $unit = in_array($in['unit'] ?? '', self::UNITS, true) ? $in['unit'] : 'piece';
        $vals = [
            'name' => mb_substr($name, 0, 160), 'barcode' => $code !== '' ? $code : null, 'unit' => $unit,
            'category_id' => self::categoryId($storeId, (string) ($in['category'] ?? '')),
            'low_stock_threshold' => max(0, (int) ($in['reorder'] ?? 0)),
            'description' => trim((string) ($in['description'] ?? '')) ?: null,
        ];
        // Cost price and selling price are both optional: left empty, nothing changes.
        if (self::can('stock.value') && isset($in['cost']) && $in['cost'] !== '') $vals['buying_price'] = max(0, (float) $in['cost']);
        if (isset($in['price']) && $in['price'] !== '') { $vals['retail_price'] = max(0, (float) $in['price']); $vals['selling_price'] = $vals['retail_price']; }
        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals)));
            $db->prepare("UPDATE products SET $sets WHERE id = ? AND tenant_id = ?")->execute(array_merge(array_values($vals), [$id, $storeId]));
            return [true, $id];
        }
        $vals += ['tenant_id' => $storeId, 'quantity' => 0, 'status' => 'active', 'buying_price' => $vals['buying_price'] ?? 0];
        $cols = array_keys($vals);
        $db->prepare('INSERT INTO products (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($vals));
        return [true, (int) $db->lastInsertId()];
    }

    // --------------------------------------------------------- stock movement

    private static function log(array $p, string $type, float $qty, float $balance, float $cost, ?string $ref, ?int $other, ?string $note, array $meta = []): void
    {
        self::ensure();
        $t = fn($k, $n) => isset($meta[$k]) && trim((string) $meta[$k]) !== '' ? mb_substr(trim((string) $meta[$k]), 0, $n) : null;
        Database::pdo()->prepare('INSERT INTO stock_moves (account_id, tenant_id, product_id, product_name, type, qty, balance, unit_cost, ref, other_tenant_id, note, user_id, supplier, doc_no, delivered_by, vehicle)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([self::account(), (int) $p['tenant_id'], (int) $p['id'], $p['name'], $type, $qty, $balance, $cost, $ref, $other,
                       $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null, TenantContext::userId(),
                       $t('supplier', 160), $t('doc_no', 60), $t('delivered_by', 120), $t('vehicle', 60)]);
    }

    private static function lock(int $storeId, int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM products WHERE id = ? AND tenant_id = ? FOR UPDATE');
        $st->execute([$id, $storeId]);
        return $st->fetch() ?: null;
    }

    private static function ref(string $prefix): string
    {
        return $prefix . '-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }

    /** Stock arriving: $lines = [[item_id, qty, cost], …]. Cost is averaged into the item's cost. */
    public static function receive(int $storeId, array $lines, string $from, string $note, array $meta = []): array
    {
        $lines = array_values(array_filter($lines, fn($l) => (int) ($l['item_id'] ?? 0) > 0 && (float) ($l['qty'] ?? 0) > 0));
        if (!$lines) return [false, 'Add at least one item with a quantity.'];
        $db = Database::pdo();
        $ref = self::ref('RCV');
        $meta['supplier'] = $meta['supplier'] ?? $from;
        self::ensure();
        $db->beginTransaction();
        try {
            foreach ($lines as $l) {
                $p = self::lock($storeId, (int) $l['item_id']);
                if (!$p) throw new RuntimeException('An item was not found in this store.');
                $qty = round((float) $l['qty'], 2);
                $cost = isset($l['cost']) && $l['cost'] !== '' ? max(0, (float) $l['cost']) : (float) $p['buying_price']; // buying price is optional
                $old = max(0, (float) $p['quantity']);
                $avg = ($old + $qty) > 0 ? ($old * (float) $p['buying_price'] + $qty * $cost) / ($old + $qty) : $cost;
                $db->prepare('UPDATE products SET quantity = quantity + ?, buying_price = ? WHERE id = ?')->execute([$qty, round($avg, 2), $p['id']]);
                self::log($p, 'receive', $qty, (float) $p['quantity'] + $qty, $cost, $ref, null, $note, $meta);
            }
            $db->commit();
            return [true, $ref];
        } catch (\Throwable $e) {
            $db->rollBack();
            return [false, $e->getMessage()];
        }
    }

    /**
     * Move stock to another store. The item is found there by its code, then
     * by its name — or created there (same name, code, unit, category, cost).
     */
    public static function transfer(int $fromId, int $toId, array $lines, string $note, array $meta = []): array
    {
        if ($fromId === $toId) return [false, 'Choose a different store to send to.'];
        $stores = self::stores();
        if (!isset($stores[$toId]) || !self::canUseStore($fromId)) return [false, 'Choose one of your stores.'];
        $lines = array_filter(array_map(fn($q) => round((float) $q, 2), $lines), fn($q) => $q > 0);
        if (!$lines) return [false, 'Enter how many of at least one item to send.'];
        $db = Database::pdo();
        $ref = self::ref('TRF');
        self::ensure();
        $db->beginTransaction();
        try {
            $findCode = $db->prepare('SELECT * FROM products WHERE tenant_id = ? AND barcode = ? AND status <> \'archived\' LIMIT 1 FOR UPDATE');
            $findName = $db->prepare('SELECT * FROM products WHERE tenant_id = ? AND LOWER(name) = LOWER(?) AND status <> \'archived\' ORDER BY id LIMIT 1 FOR UPDATE');
            foreach ($lines as $id => $qty) {
                $p = self::lock($fromId, (int) $id);
                if (!$p) throw new RuntimeException('An item was not found in this store.');
                if ($qty > (float) $p['quantity'] + 0.0001) {
                    throw new RuntimeException('Only ' . rtrim(rtrim(number_format((float) $p['quantity'], 2), '0'), '.') . ' × ' . $p['name'] . ' in this store.');
                }
                $db->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([$qty, $p['id']]);
                self::log($p, 'transfer_out', -$qty, (float) $p['quantity'] - $qty, (float) $p['buying_price'], $ref, $toId, $note, $meta);

                $dst = null;
                if (!empty($p['barcode'])) { $findCode->execute([$toId, $p['barcode']]); $dst = $findCode->fetch() ?: null; }
                if (!$dst) { $findName->execute([$toId, $p['name']]); $dst = $findName->fetch() ?: null; }
                if (!$dst) {
                    $db->prepare("INSERT INTO products (tenant_id, name, barcode, unit, category_id, buying_price, low_stock_threshold, description, quantity, status)
                                  VALUES (?,?,?,?,?,?,?,?,0,'active')")
                       ->execute([$toId, $p['name'], $p['barcode'], $p['unit'], self::categoryId($toId, (string) self::categoryName((int) $p['category_id'])),
                                  $p['buying_price'], $p['low_stock_threshold'], $p['description']]);
                    $dst = self::lock($toId, (int) $db->lastInsertId());
                }
                $old = max(0, (float) $dst['quantity']);
                $avg = ($old + $qty) > 0 ? ($old * (float) $dst['buying_price'] + $qty * (float) $p['buying_price']) / ($old + $qty) : (float) $p['buying_price'];
                $db->prepare('UPDATE products SET quantity = quantity + ?, buying_price = ? WHERE id = ?')->execute([$qty, round($avg, 2), $dst['id']]);
                self::log($dst, 'transfer_in', $qty, (float) $dst['quantity'] + $qty, (float) $p['buying_price'], $ref, $fromId, $note, $meta);
            }
            $db->commit();
            return [true, $ref];
        } catch (\Throwable $e) {
            $db->rollBack();
            return [false, $e->getMessage()];
        }
    }

    /**
     * Clear stock out of a store (issued, used, written off…): the quantity goes
     * down — to 0 with "all" — but the item stays, and the movement is kept.
     * $lines: item_id => qty or 'all'.
     */
    public static function clear(int $storeId, array $lines, string $reason, array $meta = [], array $prices = []): array
    {
        $reason = trim($reason);
        if ($reason === '') return [false, 'Say why the stock is cleared, or who it was issued to.'];
        $db = Database::pdo();
        $ref = self::ref('CLR');
        $n = 0;
        self::ensure();
        $db->beginTransaction();
        try {
            foreach ($lines as $id => $q) {
                if ($q === '' || $q === null) continue;
                $p = self::lock($storeId, (int) $id);
                if (!$p) continue;
                $have = (float) $p['quantity'];
                $qty = $q === 'all' ? $have : round((float) $q, 2);
                if ($qty <= 0) continue;
                if ($qty > $have + 0.0001) throw new RuntimeException('Only ' . rtrim(rtrim(number_format($have, 2), '0'), '.') . ' × ' . $p['name'] . ' in this store.');
                $db->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([$qty, $p['id']]);
                self::log($p, 'clear', -$qty, $have - $qty, (float) $p['buying_price'], $ref, null, $reason, $meta);
                // An optional selling price turns what left into recorded income.
                $price = isset($prices[$id]) && $prices[$id] !== '' ? max(0, (float) $prices[$id]) : 0.0;
                if ($price > 0 && self::can('stock.finance')) {
                    self::insertCash($storeId, 'income', date('Y-m-d'), 'Stock sales', $p['name'] . ' × ' . rtrim(rtrim(number_format($qty, 2), '0'), '.') . ' @ ' . number_format($price, 2), round($qty * $price, 2), '', $ref);
                }
                $n++;
            }
            if (!$n) throw new RuntimeException('Enter how much of at least one item to clear.');
            $db->commit();
            return [true, $ref];
        } catch (\Throwable $e) {
            $db->rollBack();
            return [false, $e->getMessage()];
        }
    }

    /** Deliveries: receipts and transfers grouped by reference, with who delivered them. */
    public static function deliveries(array $storeIds, int $limit = 200): array
    {
        self::ensure();
        if (!$storeIds) return [];
        $in = implode(',', array_fill(0, count($storeIds), '?'));
        $st = Database::pdo()->prepare("SELECT m.ref, MIN(m.created_at) at, MAX(m.type) type, MAX(m.supplier) supplier, MAX(m.doc_no) doc_no,
                       MAX(m.delivered_by) delivered_by, MAX(m.vehicle) vehicle, MAX(m.note) note, COUNT(*) n_lines, SUM(ABS(m.qty)) units,
                       SUM(ABS(m.qty) * m.unit_cost) value, MAX(t.name) store, MAX(o.name) other, MAX(u.username) username
                  FROM stock_moves m LEFT JOIN tenants t ON t.id = m.tenant_id LEFT JOIN tenants o ON o.id = m.other_tenant_id LEFT JOIN users u ON u.id = m.user_id
                 WHERE m.tenant_id IN ($in) AND m.type IN ('receive','transfer_out') GROUP BY m.ref ORDER BY at DESC LIMIT " . (int) $limit);
        $st->execute(array_values($storeIds));
        return $st->fetchAll();
    }

    /** A count: the item's quantity becomes what was counted; the difference is recorded with the reason. */
    public static function count(int $storeId, array $counted, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') return [false, 'Say why (e.g. "Monthly count", "Damaged", "Found in back room").'];
        $db = Database::pdo();
        $ref = self::ref('CNT');
        $changed = 0;
        self::ensure();
        $db->beginTransaction();
        try {
            foreach ($counted as $id => $qty) {
                if ($qty === '' || $qty === null) continue;
                $p = self::lock($storeId, (int) $id);
                if (!$p) continue;
                $new = max(0, round((float) $qty, 2));
                $diff = round($new - (float) $p['quantity'], 2);
                if (abs($diff) < 0.005) continue;
                $db->prepare('UPDATE products SET quantity = ? WHERE id = ?')->execute([$new, $p['id']]);
                self::log($p, 'correction', $diff, $new, (float) $p['buying_price'], $ref, null, $reason);
                $changed++;
            }
            $db->commit();
            return [true, $changed];
        } catch (\Throwable $e) {
            $db->rollBack();
            return [false, $e->getMessage()];
        }
    }

    private static function categoryName(int $id): ?string
    {
        if (!$id) return null;
        $st = Database::pdo()->prepare('SELECT name FROM categories WHERE id = ?');
        $st->execute([$id]);
        return $st->fetchColumn() ?: null;
    }

    // ---------------------------------------------------------------- reports

    /** Per store: items, units, value at cost, items at or below their reorder level. */
    public static function summary(array $storeIds): array
    {
        $out = [];
        if (!$storeIds) return $out;
        $in = implode(',', array_fill(0, count($storeIds), '?'));
        $st = Database::pdo()->prepare("SELECT tenant_id, COUNT(*) items, COALESCE(SUM(quantity),0) units, COALESCE(SUM(quantity * buying_price),0) value,
                                               SUM(low_stock_threshold > 0 AND quantity <= low_stock_threshold) low
                                          FROM products WHERE tenant_id IN ($in) AND status <> 'archived' GROUP BY tenant_id");
        $st->execute(array_values($storeIds));
        foreach ($st->fetchAll() as $r) $out[(int) $r['tenant_id']] = $r;
        foreach ($storeIds as $id) $out[$id] ??= ['tenant_id' => $id, 'items' => 0, 'units' => 0, 'value' => 0, 'low' => 0];
        return $out;
    }

    public static function lowStock(array $storeIds, int $limit = 12): array
    {
        if (!$storeIds) return [];
        $in = implode(',', array_fill(0, count($storeIds), '?'));
        $st = Database::pdo()->prepare("SELECT p.id, p.name, p.quantity, p.unit, p.low_stock_threshold, p.tenant_id FROM products p
                                         WHERE p.tenant_id IN ($in) AND p.status <> 'archived' AND p.low_stock_threshold > 0 AND p.quantity <= p.low_stock_threshold
                                      ORDER BY (p.quantity / p.low_stock_threshold), p.name LIMIT " . (int) $limit);
        $st->execute(array_values($storeIds));
        return $st->fetchAll();
    }

    /** Movement history with names. Filters: store, type, from, to, q. */
    public static function moves(array $storeIds, array $f = [], int $limit = 300): array
    {
        self::ensure();
        if (!$storeIds) return [];
        $in = implode(',', array_fill(0, count($storeIds), '?'));
        $sql = "SELECT m.*, u.username, t.name AS store_name, o.name AS other_store
                  FROM stock_moves m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN tenants t ON t.id = m.tenant_id LEFT JOIN tenants o ON o.id = m.other_tenant_id
                 WHERE m.tenant_id IN ($in)";
        $p = array_values($storeIds);
        if (!empty($f['type'])) { $sql .= ' AND m.type = ?'; $p[] = $f['type']; }
        if (!empty($f['from'])) { $sql .= ' AND m.created_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to'])) { $sql .= ' AND m.created_at < ?'; $p[] = date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'; }
        if (!empty($f['q'])) { $sql .= ' AND (m.product_name LIKE ? OR m.ref LIKE ? OR m.note LIKE ?)'; $p[] = "%{$f['q']}%"; $p[] = "%{$f['q']}%"; $p[] = "%{$f['q']}%"; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY m.id DESC LIMIT ' . (int) $limit);
        $st->execute($p);
        return $st->fetchAll();
    }

    public static function typeLabel(string $t): string
    {
        return ['receive' => 'Received', 'transfer_out' => 'Sent', 'transfer_in' => 'Arrived', 'correction' => 'Count correction', 'opening' => 'Opening stock', 'clear' => 'Cleared'][$t] ?? $t;
    }

    // ----------------------------------------------------------------- people

    public static function people(): array
    {
        $ids = array_keys(self::stores(null, false));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("SELECT u.id, u.username, u.email, u.is_active, u.tenant_id, r.role_name, t.name AS store_name
                                          FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN tenants t ON t.id = u.tenant_id
                                         WHERE u.tenant_id IN ($in) ORDER BY (r.role_name = 'tenant_owner') DESC, u.username");
        $st->execute($ids);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) $r['rights'] = $r['role_name'] === 'staff' ? self::rightsOf((int) $r['id']) : array_keys(self::RIGHTS);
        return $rows;
    }

    /**
     * Add a person: $role 'admin' or 'staff'. They get an email with the sign-in
     * address and a temporary password; their emailed code is required every time.
     */
    public static function addPerson(string $name, string $email, string $role, int $storeId, array $rights): array
    {
        $name = trim($name); $email = strtolower(trim($email));
        if ($name === '') return [false, 'Enter their name.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'Enter a valid email — their sign-in codes are sent there.'];
        if ($role === 'admin' && !self::isOwner()) return [false, 'Only the owner can add administrators.'];
        if (!isset(self::stores()[$storeId])) return [false, 'Choose a store.'];
        $db = Database::pdo();
        $chk = $db->prepare('SELECT 1 FROM users WHERE email = ?');
        $chk->execute([$email]);
        if ($chk->fetchColumn()) return [false, 'That email already has an account.'];
        $roleId = (int) $db->query("SELECT id FROM roles WHERE role_name = '" . ($role === 'admin' ? 'tenant_owner' : 'staff') . "' LIMIT 1")->fetchColumn();
        $username = preg_replace('/[^a-z0-9_.]+/', '', strtolower(str_replace(' ', '.', $name))) ?: 'user';
        $u = $db->prepare('SELECT 1 FROM users WHERE username = ? AND tenant_id = ?');
        $base = $username; $i = 1;
        while (true) { $u->execute([$username, $storeId]); if (!$u->fetchColumn()) break; $username = $base . ++$i; }
        $temp = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
        $db->prepare('INSERT INTO users (tenant_id, username, email, password_hash, role_id, is_active, email_verified, must_reset_password) VALUES (?,?,?,?,?,1,1,1)')
           ->execute([$storeId, $username, $email, password_hash($temp, PASSWORD_DEFAULT), $roleId]);
        $id = (int) $db->lastInsertId();
        if ($role === 'staff') self::setRights($id, $rights);
        $org = self::stores()[self::account()]['name'] ?? '';
        $link = EmailConfirm::absolute('stock/login.php');
        $sent = (new MailService())->send($email, 'Your Stock Control sign-in — ' . $org,
            '<p>Hello ' . htmlspecialchars($name) . ',</p><p>' . htmlspecialchars($org) . ' added you to its Stock Control system.</p>'
            . '<p><b>Sign in:</b> <a href="' . $link . '">' . $link . '</a><br><b>Email:</b> ' . htmlspecialchars($email) . '<br><b>Temporary password:</b> ' . $temp . '</p>'
            . '<p>Each time you sign in, a 6-digit code is emailed to you. You will be asked to choose your own password the first time.</p>');
        return [true, ['id' => $id, 'sent' => $sent, 'temp' => $temp, 'link' => $link]];
    }

    public static function setRights(int $userId, array $rights): void
    {
        $db = Database::pdo();
        $db->prepare("DELETE FROM user_permissions WHERE user_id = ? AND capability LIKE 'stock.%'")->execute([$userId]);
        $st = $db->prepare("INSERT INTO user_permissions (tenant_id, user_id, capability, effect) VALUES (?, ?, ?, 'grant')");
        $tid = (int) $db->query('SELECT tenant_id FROM users WHERE id = ' . (int) $userId)->fetchColumn();
        foreach (array_intersect($rights, array_keys(self::RIGHTS)) as $r) $st->execute([$tid, $userId, $r]);
    }

    /** Can the signed-in person manage this person? Owner: everyone else. Admin: staff only. */
    public static function canManage(array $person): bool
    {
        if ((int) $person['id'] === (int) TenantContext::userId()) return false;
        $st = Database::pdo()->prepare('SELECT owner_user_id FROM tenants WHERE id = ?');
        $st->execute([self::account()]);
        if ((int) $st->fetchColumn() === (int) $person['id']) return false;
        return self::isOwner() || (self::isAdmin() && $person['role_name'] === 'staff');
    }
}
