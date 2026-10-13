<?php
// app/helpers/ShopContext.php
// Multi-shop support. The owner's original tenant is the MAIN shop; every
// extra shop they open is its own tenant row with parent_tenant_id pointing
// at the main shop. Because each shop is a real tenant, everything that is
// already tenant-scoped (POS, stock, catalogue, staff, credit, expenses)
// works per shop with no changes — the owner simply "switches into" a shop,
// which swaps the session's tenant_id. The warehouse always lives on the
// main shop (the "home" tenant).

class ShopContext
{
    private static bool $schemaReady = false;

    /** Create the multi-shop tables/columns if this install doesn't have them yet. */
    public static function ensureSchema(?PDO $db = null): void
    {
        if (self::$schemaReady) {
            return;
        }
        $db = $db ?? Database::pdo();
        self::$schemaReady = true;

        $firstRun = self::ensureColumn($db, 'tenants', 'parent_tenant_id',
            "ALTER TABLE tenants ADD COLUMN parent_tenant_id INT NULL AFTER owner_user_id, ADD KEY idx_tenant_parent (parent_tenant_id)");
        if ($firstRun) {
            // Selling on credit used to come with "Take orders". It is now its
            // own permission — keep it for staff who already had it.
            try {
                $db->exec("INSERT IGNORE INTO user_permissions (tenant_id, user_id, capability, effect)
                           SELECT u.tenant_id, u.id, 'credit.sales', 'grant'
                             FROM users u JOIN roles r ON r.id = u.role_id
                            WHERE r.role_name = 'staff' AND u.tenant_id IS NOT NULL
                              AND NOT EXISTS (SELECT 1 FROM user_permissions up
                                               WHERE up.user_id = u.id AND up.capability = 'sales.record' AND up.effect = 'revoke')");
            } catch (\PDOException $ignored) {}
        }

        $db->exec("CREATE TABLE IF NOT EXISTS warehouse_items (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id    INT NOT NULL,
            name         VARCHAR(160) NOT NULL,
            category     VARCHAR(120) NULL,
            unit         VARCHAR(20) NOT NULL DEFAULT 'piece',
            quantity     DECIMAL(12,2) NOT NULL DEFAULT 0,
            buying_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes        VARCHAR(255) NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wh_tenant_name (tenant_id, name),
            KEY idx_wh_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::ensureColumn($db, 'warehouse_items', 'category_id',
            "ALTER TABLE warehouse_items ADD COLUMN category_id INT NULL AFTER name");

        $db->exec("CREATE TABLE IF NOT EXISTS warehouse_movements (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id        INT NOT NULL,
            item_id          INT NOT NULL,
            item_name        VARCHAR(160) NOT NULL,
            type             ENUM('in','transfer') NOT NULL,
            quantity         DECIMAL(12,2) NOT NULL,
            buying_price     DECIMAL(12,2) NOT NULL DEFAULT 0,
            target_tenant_id INT NULL,
            product_id       INT NULL,
            retail_price     DECIMAL(12,2) NULL,
            wholesale_price  DECIMAL(12,2) NULL,
            notes            VARCHAR(255) NULL,
            created_by       INT NULL,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_whm_tenant (tenant_id, created_at),
            KEY idx_whm_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS manual_credits (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id     INT NOT NULL,
            customer_name VARCHAR(160) NOT NULL,
            phone         VARCHAR(30) NULL,
            amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
            amount_paid   DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes         VARCHAR(255) NULL,
            credit_date   DATE NOT NULL,
            status        ENUM('open','paid') NOT NULL DEFAULT 'open',
            created_by    INT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_mc_tenant (tenant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS manual_credit_payments (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id   INT NOT NULL,
            credit_id   INT NOT NULL,
            amount      DECIMAL(12,2) NOT NULL,
            method      ENUM('cash','mpesa','bank') NOT NULL DEFAULT 'cash',
            received_by INT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mcp_credit (credit_id),
            KEY idx_mcp_tenant (tenant_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function isOwner(): bool
    {
        return TenantContext::role() === 'tenant_owner';
    }

    /** The main shop's tenant id (where the owner account and the warehouse live). */
    public static function homeTenantId(): ?int
    {
        $current = TenantContext::tenantId();
        if ($current === null) {
            return null;
        }
        if (!self::isOwner()) {
            return $current;
        }
        if (!empty($_SESSION['home_tenant_id'])) {
            return (int) $_SESSION['home_tenant_id'];
        }
        self::ensureSchema();
        $stmt = Database::pdo()->prepare('SELECT parent_tenant_id FROM tenants WHERE id = ? LIMIT 1');
        $stmt->execute([$current]);
        $parent = $stmt->fetchColumn();
        $home = $parent ? (int) $parent : (int) $current;
        $_SESSION['home_tenant_id'] = $home;
        return $home;
    }

    public static function isMainShop(): bool
    {
        return TenantContext::tenantId() !== null && TenantContext::tenantId() === self::homeTenantId();
    }

    /** Main shop first, then the extra shops by name. Owners only. */
    public static function shops(bool $activeOnly = false): array
    {
        $home = self::homeTenantId();
        if ($home === null || !self::isOwner()) {
            return [];
        }
        self::ensureSchema();
        $sql = 'SELECT * FROM tenants WHERE (id = ? OR parent_tenant_id = ?)'
             . ($activeOnly ? " AND status = 'active'" : '')
             . ' ORDER BY (id = ?) DESC, name ASC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$home, $home, $home]);
        return $stmt->fetchAll();
    }

    public static function canAccess(int $tenantId): bool
    {
        foreach (self::shops() as $s) {
            if ((int) $s['id'] === $tenantId) {
                return true;
            }
        }
        return false;
    }

    /** Point the owner's session at another of their shops. */
    public static function switchTo(int $tenantId): bool
    {
        if (!self::isOwner() || !self::canAccess($tenantId)) {
            return false;
        }
        self::homeTenantId(); // pin the home shop before the tenant changes
        $_SESSION['tenant_id'] = $tenantId;
        TenantContext::boot();
        return true;
    }

    /** Open a new shop under the owner's main shop. */
    public static function createShop(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'id' => null, 'error' => 'Enter the shop name.'];
        }
        if (!self::isOwner() || ($home = self::homeTenantId()) === null) {
            return ['ok' => false, 'id' => null, 'error' => 'Only the owner can open shops.'];
        }
        if (!Features::multiShop($home)) {
            return ['ok' => false, 'id' => null, 'error' => 'This account runs a single shop. Contact support to enable more shops.'];
        }
        self::ensureSchema();
        $db = Database::pdo();
        foreach (self::shops() as $s) {
            if (strcasecmp($s['name'], $name) === 0) {
                return ['ok' => false, 'id' => null, 'error' => 'You already have a shop with that name.'];
            }
        }
        $T = new Models\TenantModel($db);
        $main = $T->find($home) ?: [];
        $stmt = $db->prepare(
            "INSERT INTO tenants (name, slug, owner_user_id, parent_tenant_id, status) VALUES (?,?,?,?, 'active')"
        );
        $stmt->execute([$name, $T->uniqueSlug($name), TenantContext::userId(), $home]);
        $id = (int) $db->lastInsertId();
        // Start from the main shop's branding; the owner can change it in Settings.
        $T->updateSettings($id, array_filter([
            'currency'       => $main['currency'] ?? null,
            'logo_path'      => $main['logo_path'] ?? null,
            'phone'          => trim((string) ($in['phone'] ?? '')) ?: ($main['phone'] ?? null),
            'address'        => trim((string) ($in['address'] ?? '')) ?: null,
            'receipt_footer' => $main['receipt_footer'] ?? null,
        ], fn($v) => $v !== null && $v !== ''));
        self::syncCategories($home); // the new shop starts with the same categories
        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    public static function updateShop(int $tenantId, array $in): array
    {
        if (!self::canAccess($tenantId)) {
            return ['ok' => false, 'error' => 'Shop not found.'];
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'Enter the shop name.'];
        }
        (new Models\TenantModel(Database::pdo()))->updateSettings($tenantId, [
            'name' => $name,
            'phone' => trim((string) ($in['phone'] ?? '')),
            'address' => trim((string) ($in['address'] ?? '')),
        ]);
        return ['ok' => true, 'error' => null];
    }

    /** Close/reopen an extra shop. The main shop can't be closed. */
    public static function setActive(int $tenantId, bool $active): array
    {
        if (!self::canAccess($tenantId) || $tenantId === self::homeTenantId()) {
            return ['ok' => false, 'error' => 'That shop can\'t be changed.'];
        }
        Database::pdo()->prepare('UPDATE tenants SET status = ? WHERE id = ?')
            ->execute([$active ? 'active' : 'suspended', $tenantId]);
        return ['ok' => true, 'error' => null];
    }

    /** Returns true when the column had to be added (i.e. first run). */
    /** Main shop id + every extra shop id for the family a tenant belongs to (any role). */
    public static function familyIds(int $tenantId): array
    {
        self::ensureSchema();
        $db = Database::pdo();
        $stmt = $db->prepare('SELECT parent_tenant_id FROM tenants WHERE id = ? LIMIT 1');
        $stmt->execute([$tenantId]);
        $home = (int) ($stmt->fetchColumn() ?: $tenantId);
        $stmt = $db->prepare('SELECT id FROM tenants WHERE id = ? OR parent_tenant_id = ?');
        $stmt->execute([$home, $home]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * One category list for the whole business: the warehouse and every shop
     * share the same categories. Any category that exists in one shop is
     * copied (by type + name) into the others that don't have it yet.
     */
    public static function syncCategories(?int $tenantId = null): void
    {
        $tenantId = $tenantId ?? TenantContext::tenantId();
        if ($tenantId === null) { return; }
        $ids = self::familyIds($tenantId);
        if (count($ids) < 2) { return; }
        $db = Database::pdo();
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $db->prepare("SELECT type, name, MAX(image_path) AS image_path FROM categories WHERE tenant_id IN ($in) GROUP BY type, name");
            $stmt->execute($ids);
            $all = $stmt->fetchAll();
            $ins = $db->prepare("INSERT IGNORE INTO categories (tenant_id, name, type, image_path, status) VALUES (?,?,?,?, 'active')");
            foreach ($ids as $tid) {
                foreach ($all as $c) {
                    $ins->execute([$tid, $c['name'], $c['type'], $c['image_path']]);
                }
            }
        } catch (\PDOException $ignored) {
            // Older schema without categories.type — nothing to sync safely.
        }
    }

    /** Carry a category rename over to the other shops and the warehouse. */
    public static function renameCategory(string $type, string $old, string $new, ?string $imagePath = null): void
    {
        $tid = TenantContext::tenantId();
        if ($tid === null || $old === $new && $imagePath === null) { return; }
        $ids = self::familyIds($tid);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db = Database::pdo();
        $db->prepare("UPDATE IGNORE categories SET name = ?" . ($imagePath !== null ? ', image_path = ?' : '') . " WHERE tenant_id IN ($in) AND type = ? AND name = ?")
           ->execute(array_merge([$new], $imagePath !== null ? [$imagePath] : [], $ids, [$type, $old]));
        $db->prepare("UPDATE warehouse_items SET category = ? WHERE tenant_id IN ($in) AND category = ?")
           ->execute(array_merge([$new], $ids, [$old]));
    }

    /** Remove a deleted category from the other shops too, wherever it is still unused. */
    public static function deleteCategory(string $type, string $name): void
    {
        $tid = TenantContext::tenantId();
        if ($tid === null) { return; }
        $ids = self::familyIds($tid);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db = Database::pdo();
        $db->prepare("DELETE c FROM categories c
                       WHERE c.tenant_id IN ($in) AND c.type = ? AND c.name = ?
                         AND NOT EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id)
                         AND NOT EXISTS (SELECT 1 FROM subcategories s WHERE s.category_id = c.id)")
           ->execute(array_merge($ids, [$type, $name]));
        // Warehouse items keep their stock but lose the category label.
        $stillThere = $db->prepare("SELECT COUNT(*) FROM categories WHERE tenant_id IN ($in) AND name = ?");
        $stillThere->execute(array_merge($ids, [$name]));
        if ((int) $stillThere->fetchColumn() === 0) {
            $db->prepare("UPDATE warehouse_items SET category = NULL, category_id = NULL WHERE tenant_id IN ($in) AND category = ?")
               ->execute(array_merge($ids, [$name]));
        }
    }

    private static function ensureColumn(PDO $db, string $table, string $column, string $sql): bool
    {
        try {
            $db->query("SELECT `{$column}` FROM `{$table}` LIMIT 1");
            return false;
        } catch (\PDOException $e) {
            try { $db->exec($sql); return true; } catch (\PDOException $ignored) { return false; }
        }
    }
}
