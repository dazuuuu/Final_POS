<?php
// app/helpers/Features.php
// Packages each shop-owner ACCOUNT is licensed for, managed from the Support
// console (public/support/). An account is a top-level tenant; its branch
// shops (parent_tenant_id) inherit the account's packages. When a package is
// off, its pages redirect to the dashboard and vanish from the nav.
//
//   shop_pos_30k        — the full shop POS (sales, credit, inventory, staff,
//                         finance, reports, settings…). Dashboard stays.
//   restaurant_pos_30k  — the restaurant & bar POS (store → kitchen / counter, menu, orders, outlets).
//   electronics_30k     — an electronics shop: the full shop POS (the template
//                         every shop account starts from) plus serial/IMEI per
//                         unit, warranties and repair returns. It includes the
//                         shop POS, so ticking it alone gives the whole shop.
//   services_30k        — service businesses (salon, spa, garage, clinic…):
//                         a price list of services, walk-ins and bookings,
//                         assigning staff, invoices/receipts and commissions.
//                         Like electronics it includes the shop template.
//   shop_basic_20k /    — Basic tier of the shop / services POS: one shop
//   services_basic_20k    only, up to 3 staff, no admins, Bluetooth + USB printing only.
//                         Pro pages (PRO_PAGES) stay in the menu badged "Pro"
//                         and open super/upgrade.php. Any 30k shop package
//                         on the same account lifts these limits.
//
// Multi-shop is also per account (tenants.multi_shop on the top-level
// tenant, on by default): when support turns it off the owner can't open
// branch shops and runs everything from the one main shop.

class Features
{
    const SHOP_POS       = 'shop_pos_30k';
    const RESTAURANT_POS = 'restaurant_pos_30k';
    const ELECTRONICS    = 'electronics_30k';
    const SERVICES       = 'services_30k';
    const RESTAURANT_ROOMS = 'restaurant_rooms_40k';
    // Basic tier (20k): one shop, up to BASIC_STAFF_LIMIT staff, no admins,
    // Bluetooth + USB / device printing only. Pro features show in the menu with a "Pro"
    // badge and open the upgrade page (super/upgrade.php).
    const SHOP_BASIC     = 'shop_basic_20k';
    const SERVICES_BASIC = 'services_basic_20k';
    const BASIC_STAFF_LIMIT = 3;

    /** Pages that are Pro-only: path fragment => feature name shown on the upgrade page. */
    const PRO_PAGES = [
        '/super/shops/'     => 'Multiple shops',
        '/super/warehouse/' => 'Warehouse',
        '/super/transfer/'  => 'Transfers between shops',
        '/super/purchases/' => 'Purchases',
        '/super/admins/'    => 'Admins',
    ];

    /** key => [label, price, description, icon] */
    public static function catalog(): array
    {
        return [
            self::SHOP_BASIC => ['Shop POS Basic', 20000, 'One shop: sales, credit, held sales, payments, returns, inventory, stock, suppliers, expenses, finances, customers, reports and settings. Up to 3 staff, Bluetooth + USB printing. (Basic.)', 'fa-store'],
            self::SERVICES_BASIC => ['Services Basic', 20000, 'Shop POS Basic plus services: a price list, walk-ins and bookings, assigning staff, invoices, receipts and commissions. One shop, up to 3 staff, Bluetooth + USB printing. (Basic.)', 'fa-user-gear'],
            self::SHOP_POS => ['Shop POS', 30000, 'Full retail POS: sales, credit, held sales, payments, returns, inventory, stock, suppliers, staff, expenses, finances, customers, reports and settings.', 'fa-cash-register'],
            self::RESTAURANT_POS => ['Restaurant POS', 30000, 'Restaurant & bar POS: store stock, stock moved to the kitchen and to the counter or bar, menu with recipes, table and takeaway orders, bills and payments, online menu and deliveries, and several restaurants or bars each with its own prices. (Pro.)', 'fa-utensils'],
            self::RESTAURANT_ROOMS => ['Restaurant + Rooms', 40000, 'Everything in the Restaurant POS, plus Rooms & Accommodation (rooms, guest bookings, invoices, check-out with late fees, receipts) and table bookings with pre-set group menus and family platters.', 'fa-hotel'],
            self::SERVICES => ['Services', 30000, 'Everything in the Shop POS, plus for service businesses: a price list of services, walk-in clients and bookings, assigning staff, invoices and receipts, and staff commissions.', 'fa-user-gear'],
            self::ELECTRONICS => ['Electronics', 30000, 'Everything in the Shop POS, plus for phone & electronics shops: serial number / IMEI for every unit, warranties that start at sale, and warranty / repair returns.', 'fa-mobile-screen'],
        ];
    }

    /**
     * Accounts that existed before packages were introduced have no rows;
     * they keep the shop POS they were already using.
     */
    const LEGACY_DEFAULTS = [self::SHOP_POS => true];

    /**
     * Pages under /super/ and /staff/ that stay reachable with the shop POS
     * off: the dashboards and the user's own account pages.
     */
    const ALWAYS_ALLOWED = [
        '/super/dashboard/', '/staff/dashboard/', '/super/profile/',
        '/staff/reset-password.php', '/staff/change-pin.php',
        '/super/guide/', '/staff/guide/', '/super/help/', '/super/settings/menu.php',
        '/super/locked.php', // shown while support has locked the account
        '/super/alerts/',    // news & alerts from support
        '/super/api/', // Website API add-on: its own switch (ShopApi), any package
    ];

    /**
     * Pages every package includes, whatever else is on: staff (Basic keeps
     * its 3-staff limit), sales / orders, credit and held sales, reports,
     * printer setup and inventory / stock entry.
     */
    const COMMON_PAGES = [
        '/super/staff/', '/super/reports/', '/super/sales/', '/staff/sales/',
        '/super/orders/', '/staff/orders/', '/super/credits/', '/staff/credits/',
        '/super/printer/', '/staff/printer/', '/super/inventory/', '/staff/inventory/',
        '/super/stock/', '/staff/stock/',
        '/super/settings/', '/super/backup/', // shop details, navigation menu, export & import
    ];

    private static array $cache = [];

    /** Does the account have any package on (so the common pages apply)? */
    public static function anyPackage(?int $tenantId = null): bool
    {
        $tenantId ??= TenantContext::tenantId();
        if ($tenantId === null) { return false; }
        return (bool) array_filter(self::forAccount(self::accountId($tenantId)));
    }

    /** Is $key on for the signed-in user's account (or for $tenantId's account)? */
    public static function enabled(string $key, ?int $tenantId = null): bool
    {
        $tenantId ??= TenantContext::tenantId();
        if ($tenantId === null) {
            return false;
        }
        $state = self::forAccount(self::accountId($tenantId));
        if ($key === self::SHOP_POS && (!empty($state[self::ELECTRONICS]) || !empty($state[self::SERVICES])
            || !empty($state[self::SHOP_BASIC]) || !empty($state[self::SERVICES_BASIC]))) {
            return true; // these are the shop template plus their own features
        }
        if ($key === self::SERVICES && !empty($state[self::SERVICES_BASIC])) {
            return true;
        }
        if ($key === self::RESTAURANT_POS && !empty($state[self::RESTAURANT_ROOMS])) {
            return true; // the restaurant POS is part of Restaurant + Rooms
        }
        return $state[$key] ?? false;
    }

    /** key => bool for every package, for one account (top-level tenant id). */
    public static function forAccount(int $accountId): array
    {
        if (isset(self::$cache[$accountId])) {
            return self::$cache[$accountId];
        }
        $state = array_fill_keys(array_keys(self::catalog()), false);
        try {
            self::ensureTable();
            $stmt = Database::pdo()->prepare('SELECT package_key, enabled FROM tenant_packages WHERE tenant_id = ?');
            $stmt->execute([$accountId]);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!$rows) {
                $state = array_merge($state, self::LEGACY_DEFAULTS);
            }
            foreach ($rows as $k => $on) {
                if (array_key_exists($k, $state)) {
                    $state[$k] = (bool) $on;
                }
            }
        } catch (Throwable $e) {
            $state = array_merge($state, self::LEGACY_DEFAULTS); // no DB yet (setup wizard)
        }
        return self::$cache[$accountId] = $state;
    }

    /** Writes every package's state for an account, so legacy defaults stop applying. */
    public static function setForAccount(int $accountId, array $enabledKeys): void
    {
        self::ensureTable();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO tenant_packages (tenant_id, package_key, enabled) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = CURRENT_TIMESTAMP'
        );
        foreach (array_keys(self::catalog()) as $key) {
            $stmt->execute([$accountId, $key, in_array($key, $enabledKeys, true) ? 1 : 0]);
        }
        unset(self::$cache[$accountId]);
    }

    public static function set(int $accountId, string $key, bool $on): void
    {
        if (!array_key_exists($key, self::catalog())) {
            return;
        }
        $on_keys = array_keys(array_filter(self::forAccount($accountId)));
        $on_keys = array_diff($on_keys, [$key]);
        if ($on) {
            $on_keys[] = $key;
        }
        self::setForAccount($accountId, array_values($on_keys));
    }

    /**
     * Is this account on the Basic (20k) tier — a basic package and no Pro
     * shop package (Shop POS, Services or Electronics 30k)?
     */
    public static function isBasic(?int $tenantId = null): bool
    {
        $tenantId ??= TenantContext::tenantId();
        if ($tenantId === null) { return false; }
        $state = self::forAccount(self::accountId($tenantId));
        return (!empty($state[self::SHOP_BASIC]) || !empty($state[self::SERVICES_BASIC]))
            && empty($state[self::SHOP_POS]) && empty($state[self::SERVICES]) && empty($state[self::ELECTRONICS]);
    }

    /** Pro feature name if $path is a Pro-only page, else null. */
    public static function proPage(string $path): ?string
    {
        foreach (self::PRO_PAGES as $frag => $name) {
            if (strpos($path, $frag) !== false) { return $name; }
        }
        return null;
    }

    /** Can this account open branch shops? On unless support turned it off; never on Basic. */
    public static function multiShop(?int $tenantId = null): bool
    {
        $tenantId ??= TenantContext::tenantId();
        if ($tenantId === null) { return true; }
        if (self::isBasic($tenantId)) { return false; }
        try {
            self::ensureMultiShopColumn();
            $stmt = Database::pdo()->prepare('SELECT multi_shop FROM tenants WHERE id = ?');
            $stmt->execute([self::accountId($tenantId)]);
            $v = $stmt->fetchColumn();
            return $v === false || (int) $v === 1;
        } catch (Throwable $e) {
            return true;
        }
    }

    public static function setMultiShop(int $accountId, bool $on): void
    {
        self::ensureMultiShopColumn();
        Database::pdo()->prepare('UPDATE tenants SET multi_shop = ? WHERE id = ?')->execute([$on ? 1 : 0, $accountId]);
    }

    private static function ensureMultiShopColumn(): void
    {
        static $done = false;
        if ($done) { return; }
        $chk = Database::pdo()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tenants' AND column_name = 'multi_shop'");
        if (!(int) $chk->fetchColumn()) {
            Database::pdo()->exec('ALTER TABLE tenants ADD COLUMN multi_shop TINYINT(1) NOT NULL DEFAULT 1');
        }
        $done = true;
    }

    /** The top-level tenant (account) a shop belongs to. */
    public static function accountId(int $tenantId): int
    {
        $stmt = Database::pdo()->prepare('SELECT parent_tenant_id FROM tenants WHERE id = ? LIMIT 1');
        $stmt->execute([$tenantId]);
        $parent = $stmt->fetchColumn();
        return $parent ? (int) $parent : $tenantId;
    }

    private static function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS tenant_packages (
                tenant_id INT NOT NULL,
                package_key VARCHAR(64) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (tenant_id, package_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $done = true;
    }

    /**
     * Called from app/app.php on every request for shop pages: a suspended
     * account is signed out; a page whose package is off redirects to the
     * dashboard.
     */
    public static function gate(): void
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if (!preg_match('#/(super|staff)/#', $script, $m) || TenantContext::tenantId() === null) {
            return;
        }
        $account = self::accountId(TenantContext::tenantId());

        $stmt = Database::pdo()->prepare('SELECT status FROM tenants WHERE id = ? LIMIT 1');
        $stmt->execute([$account]);
        if ($stmt->fetchColumn() !== 'active') {
            unset($_SESSION['logged_in'], $_SESSION['otp_verified'], $_SESSION['user_id'],
                  $_SESSION['tenant_id'], $_SESSION['role'], $_SESSION['capabilities']);
            header('Location: ' . public_url('auth/login.php?locked=1'));
            exit;
        }

        if (self::isBasic($account) && ($pro = self::proPage($script)) !== null) {
            header('Location: ' . public_url('super/upgrade.php?f=' . urlencode($pro)));
            exit;
        }

        if (strpos($script, '/restaurant/') !== false) {
            if (self::enabled(self::RESTAURANT_POS, $account)) {
                return;
            }
        } elseif (strpos($script, '/services/') !== false) {
            if (self::enabled(self::SERVICES, $account)) {
                return;
            }
        } elseif (strpos($script, '/electronics/') !== false) {
            if (self::enabled(self::ELECTRONICS, $account)) {
                return;
            }
        } elseif (self::enabled(self::SHOP_POS, $account)) {
            return;
        }
        if (self::anyPackage($account)) {
            foreach (self::COMMON_PAGES as $ok) {
                if (strpos($script, $ok) !== false) {
                    return;
                }
            }
        }
        foreach (self::ALWAYS_ALLOWED as $ok) {
            if (strpos($script, $ok) !== false) {
                return;
            }
        }
        header('Location: ' . public_url($m[1] . '/dashboard/?locked=1'));
        exit;
    }
}
