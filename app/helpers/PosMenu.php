<?php
// app/helpers/PosMenu.php
// The ONE definition of the POS menu. The desktop sidebar, the phone bottom
// bar + "Menu" drawer and the Help Centre all render from groups(), so a page
// added here shows up everywhere. Every link is capability / package gated.
//
// Groups (only those with at least one visible link are returned):
//   home        Dashboard
//   shop        New sale, credit sales, held sales, payments, returns…
//   restaurant  (Restaurant POS package)
//   services    (Services package)
//   stock       Inventory, record stock, products, warehouse, devices…
//   money       Expenses, finances, sales, reports
//   people      Customers, staff, permissions, attendance
//   business    Shops, admins, settings, updates…
//   help        Help centre, support tickets
//
// Owners can arrange their own menu (super/settings/menu.php): the order of
// groups and of links inside each group, links pinned to a "Quick access"
// group under Home, and links hidden. Saved per owner in menu_prefs and
// applied last, so it only reorders what the owner may already see.

class PosMenu
{
    /** @return array<int, array{key:string,label:string,icon:string,links:array<int,array{0:string,1:string,2:string,3?:?string}>}> */
    public static function groups(bool $arranged = true): array
    {
        $staff = TenantContext::role() === 'staff';
        $owner = TenantContext::role() === 'tenant_owner';
        $can = fn(string $c) => TenantContext::can($c);
        $shop = Features::enabled(Features::SHOP_POS);
        $common = Features::anyPackage(); // staff, sales, credit/held, reports, printer, inventory: every package
        $main = $owner && ShopContext::isMainShop();
        $primary = $owner && self::isPrimaryOwner();
        $a = $staff ? 'staff' : 'super';
        $pay = $can(Capabilities::PAYMENTS_PROCESS);
        $credit = $can(Capabilities::CREDIT_SALES);
        $invEdit = $can(Capabilities::INVENTORY_EDIT);
        // Basic (20k) accounts still see Pro links, badged; the page gate sends them to the upgrade page.
        $pro = Features::isBasic() ? 'Pro' : null;

        // [label, icon, path, visible, badge?]
        $g = [];
        $g[] = ['home', 'Home', 'fa-house', [
            ['Dashboard', 'fa-gauge-high', $staff ? 'staff/dashboard/' : 'super/dashboard/', true],
        ]];

        $g[] = ['shop', 'Shop', 'fa-cash-register', !$common ? [] : [
            ['New sale', 'fa-cash-register', $staff ? 'staff/dashboard/' : 'super/pos/', $shop && $can(Capabilities::SALES_RECORD)],
            ['Credit sale', 'fa-hand-holding-dollar', "$a/orders/", $credit],
            ['Held sales', 'fa-pause', "$a/orders/held.php", $can(Capabilities::SALES_RECORD)],
            ['Credit book', 'fa-book', "$a/credits/", $staff ? ($credit || $pay) : $credit],
            ['Payments', 'fa-money-check-dollar', "$a/payments/", $shop && $pay],
            ['Returns', 'fa-rotate-left', "$a/orders/return.php", $shop && $pay],
            ['Documents', 'fa-file-invoice', 'super/documents/', $shop && !$staff],
            ['Share catalogue', 'fa-store', 'super/catalogue/', $shop && !$staff && $can(Capabilities::CATALOGUE_SEND)],
        ]];

        if (Features::enabled(Features::RESTAURANT_POS)) {
            $g[] = ['restaurant', 'Restaurant', 'fa-utensils', [
                ['New order', 'fa-cash-register', 'super/restaurant/pos.php', $staff || $can(Capabilities::SALES_RECORD)],
                [$staff ? 'My orders' : 'Orders', 'fa-receipt', 'super/restaurant/orders.php', $staff || $can(Capabilities::SALES_RECORD)],
                ['Payments', 'fa-money-bill-wave', 'super/restaurant/payments.php', $pay, $pay ? self::onlineOrders() : null],
                ['Share menu / QR', 'fa-qrcode', 'super/restaurant/share.php', !$staff && $can(Capabilities::SETTINGS_MANAGE)],
                ['Waiters & staff', 'fa-user-tie', 'super/restaurant/staff.php', !$staff && $can(Capabilities::STAFF_MANAGE)],
                ['Menu', 'fa-bowl-food', 'super/restaurant/menu.php', !$staff && $invEdit],
                ['Menu categories', 'fa-tags', 'super/restaurant/categories.php', !$staff && $invEdit],
                ['Ingredients', 'fa-carrot', 'super/restaurant/ingredients.php', !$staff && $invEdit],
                ['Store stock', 'fa-boxes-stacked', 'super/restaurant/stock.php', !$staff && $invEdit],
                ['Transfers', 'fa-right-left', 'super/restaurant/transfer.php', !$staff && $invEdit],
            ]];
        }

        if (Features::enabled(Features::RESTAURANT_ROOMS)) {
            $g[] = ['rooms', 'Rooms & bookings', 'fa-hotel', [
                ['Reception', 'fa-bell-concierge', 'super/restaurant/stays.php', $can(Capabilities::ROOMS_DESK)],
                ['New guest', 'fa-user-plus', 'super/restaurant/stay-new.php', $can(Capabilities::ROOMS_DESK)],
                ['Rooms', 'fa-bed', 'super/restaurant/rooms.php', $can(Capabilities::ROOMS_MANAGE)],
                ['Table bookings', 'fa-users-rectangle', 'super/restaurant/table-bookings.php', $can(Capabilities::TABLE_BOOKINGS)],
                ['Family platters', 'fa-layer-group', 'super/restaurant/platters.php', $can(Capabilities::TABLE_BOOKINGS)],
            ]];
        }

        if (Features::enabled(Features::SERVICES)) {
            $g[] = ['services', 'Services', 'fa-user-gear', [
                ['Front desk', 'fa-clipboard-list', 'super/services/', $owner || $can(Capabilities::SERVICES_BOOK) || $can(Capabilities::SERVICES_CASHIER)],
                ['New client', 'fa-user-plus', 'super/services/new.php', $can(Capabilities::SERVICES_BOOK)],
                ['My clients', 'fa-user-check', 'super/services/mine.php', true],
                ['Services & prices', 'fa-list', 'super/services/catalog.php', $can(Capabilities::SERVICES_MANAGE)],
                ['Commissions', 'fa-percent', 'super/services/commissions.php', $owner],
                ['Pay staff', 'fa-hand-holding-dollar', 'super/services/payouts.php', $can(Capabilities::SERVICES_PAYOUT)],
                ['Record my job', 'fa-pen-to-square', 'super/services/my-job.php', !$owner],
            ]];
        }

        $elec = Features::enabled(Features::ELECTRONICS);
        $g[] = ['stock', 'Stock', 'fa-boxes-stacked', [
            ['Inventory', 'fa-warehouse', "$a/inventory/", $common && ($can(Capabilities::INVENTORY_VIEW) || $invEdit)],
            ['Record stock', 'fa-truck-ramp-box', "$a/stock/new.php", $common && ($staff ? $can(Capabilities::STOCK_ENTER) : $invEdit)],
            ['Products', 'fa-box', 'super/products/', $shop && !$staff && $invEdit],
            ['Categories', 'fa-tags', 'super/categories/', $shop && !$staff && $invEdit],
            ['Suppliers', 'fa-truck-field', "$a/suppliers/", $shop && $invEdit],
            ...self::fieldLinks($shop && !$staff && $invEdit),
            ['Purchases', 'fa-cart-flatbed', 'super/purchases/', $shop && $owner, $pro],
            ['Warehouse', 'fa-building', 'super/warehouse/', $shop && $main, $pro],
            ['Transfer to shops', 'fa-right-left', 'super/transfer/', $shop && $main, $pro],
            ['Devices & serials', 'fa-mobile-screen', 'super/electronics/', $elec && $can(Capabilities::INVENTORY_VIEW)],
            ['Warranty & repairs', 'fa-screwdriver-wrench', 'super/electronics/repairs.php', $elec && $can(Capabilities::SALES_RECORD)],
        ]];

        $g[] = ['money', 'Money', 'fa-wallet', !$common ? [] : [
            ['Expenses', 'fa-file-invoice-dollar', "$a/expenses/", $shop && $can(Capabilities::EXPENSES_RECORD)],
            ['Finances', 'fa-scale-balanced', 'super/finances/', $shop && !$staff],
            [$staff ? 'My sales' : 'Sales', 'fa-receipt', "$a/sales/", $staff ? $can(Capabilities::SALES_VIEW) : $owner],
            ['Reports', 'fa-chart-line', 'super/reports/', !$staff && $can(Capabilities::REPORTS_VIEW)],
        ]];

        $g[] = ['people', 'People', 'fa-users', !$common || $staff ? [] : [
            ['Customers', 'fa-address-book', 'super/customers/', $shop && $can(Capabilities::CUSTOMERS_MANAGE)],
            ['Staff', 'fa-user-gear', 'super/staff/', $can(Capabilities::STAFF_MANAGE)],
            ['Permissions', 'fa-key', 'super/staff/permissions.php', $can(Capabilities::STAFF_MANAGE)],
            ['Attendance', 'fa-clock', 'super/staff/attendance.php', $can(Capabilities::STAFF_MANAGE)],
        ]];

        $updates = $shop && $main ? count(Updates::pending()) : 0;
        $g[] = ['business', $staff ? 'Account' : 'Business', 'fa-briefcase', [
            [ShopContext::isMainShop() ? 'My shops' : 'Shops', 'fa-shop', 'super/shops/', $shop && $owner, $pro],
            ['Admins', 'fa-user-shield', 'super/admins/', $shop && $primary, $pro],
            ['Updates', 'fa-download', 'super/updates/', $shop && $main, $updates ? $updates . ' new' : null],
            ['Cleanup', 'fa-broom', 'super/cleanup/', $shop && $primary],
            ['Printer setup', 'fa-print', 'staff/printer/', $staff && $common],
            ['Change PIN', 'fa-key', 'staff/change-pin.php', $staff],
        ]];

        // Settings: every package, owner side.
        $g[] = ['settings', 'Settings', 'fa-gear', $staff ? [] : [
            ['Shop details', 'fa-store', 'super/settings/', $common && $can(Capabilities::SETTINGS_MANAGE)],
            ['Printer setup', 'fa-print', 'super/printer/', $common],
            ['Navigation menu', 'fa-arrow-down-up-across-line', 'super/settings/menu.php', $owner],
            ['Export & import', 'fa-right-left', 'super/backup/', $common && $primary],
        ]];

        $replies = self::ticketReplies();
        $g[] = ['help', 'Help', 'fa-life-ring', [
            ['Help centre', 'fa-circle-question', "$a/guide/", true],
            ['Support tickets', 'fa-ticket', 'super/help/tickets.php', true, $replies ? $replies . ' new' : null],
        ]];

        $out = [];
        foreach ($g as [$key, $label, $icon, $links]) {
            $links = array_values(array_map(
                fn($l) => [$l[0], $l[1], $l[2], $l[4] ?? null],
                array_filter($links, fn($l) => $l[3])
            ));
            if ($links) {
                $out[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'links' => $links];
            }
        }
        return $arranged && $owner ? self::arrange($out, self::prefs()) : $out;
    }

    /** This owner's saved arrangement: [groups => keys[], links => [group => paths[]], pinned => paths[], hidden => paths[]]. */
    public static function prefs(): array
    {
        $empty = ['groups' => [], 'links' => [], 'pinned' => [], 'hidden' => []];
        try {
            self::ensurePrefsTable();
            $st = Database::pdo()->prepare('SELECT prefs FROM menu_prefs WHERE user_id = ?');
            $st->execute([(int) TenantContext::userId()]);
            $p = json_decode((string) $st->fetchColumn(), true);
            return is_array($p) ? array_merge($empty, array_intersect_key($p, $empty)) : $empty;
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    public static function savePrefs(array $prefs): void
    {
        self::ensurePrefsTable();
        $clean = [
            'groups' => array_values(array_filter((array) ($prefs['groups'] ?? []), 'is_string')),
            'links'  => array_map(fn($l) => array_values(array_filter((array) $l, 'is_string')), array_filter((array) ($prefs['links'] ?? []), 'is_array')),
            'pinned' => array_values(array_filter((array) ($prefs['pinned'] ?? []), 'is_string')),
            'hidden' => array_values(array_filter((array) ($prefs['hidden'] ?? []), 'is_string')),
        ];
        Database::pdo()->prepare('INSERT INTO menu_prefs (user_id, prefs) VALUES (?, ?) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)')
            ->execute([(int) TenantContext::userId(), json_encode($clean)]);
    }

    public static function resetPrefs(): void
    {
        self::ensurePrefsTable();
        Database::pdo()->prepare('DELETE FROM menu_prefs WHERE user_id = ?')->execute([(int) TenantContext::userId()]);
    }

    /** Apply an arrangement to groups(false). Home stays first; the Dashboard and "Arrange menu" can't be hidden. */
    public static function arrange(array $groups, array $prefs): array
    {
        $keep = ['super/dashboard/', 'super/settings/menu.php'];
        $hidden = array_diff($prefs['hidden'], $keep);
        $pinned = [];
        foreach ($groups as &$g) {
            $order = array_flip($prefs['links'][$g['key']] ?? []);
            $links = [];
            foreach ($g['links'] as $i => $l) {
                if (in_array($l[2], $hidden, true)) { continue; }
                if ($g['key'] !== 'home' && in_array($l[2], $prefs['pinned'], true)) { $pinned[$l[2]] = $l; continue; }
                $links[] = [$order[$l[2]] ?? 1000 + $i, $l];
            }
            usort($links, fn($a, $b) => $a[0] <=> $b[0]);
            $g['links'] = array_column($links, 1);
        }
        unset($g);
        $groups = array_values(array_filter($groups, fn($g) => $g['links']));

        $gOrder = array_flip($prefs['groups']);
        $home = array_values(array_filter($groups, fn($g) => $g['key'] === 'home'));
        $rest = array_values(array_filter($groups, fn($g) => $g['key'] !== 'home'));
        $idx = array_flip(array_column($rest, 'key'));
        usort($rest, fn($a, $b) => ($gOrder[$a['key']] ?? 1000 + $idx[$a['key']]) <=> ($gOrder[$b['key']] ?? 1000 + $idx[$b['key']]));

        if ($pinned) {
            $pOrder = array_flip($prefs['pinned']);
            uksort($pinned, fn($a, $b) => $pOrder[$a] <=> $pOrder[$b]);
            array_unshift($rest, ['key' => 'pinned', 'label' => 'Quick access', 'icon' => 'fa-star', 'links' => array_values($pinned)]);
        }
        return array_merge($home, $rest);
    }

    private static function ensurePrefsTable(): void
    {
        static $done = false;
        if ($done) { return; }
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS menu_prefs (
                user_id INT NOT NULL PRIMARY KEY,
                prefs TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $done = true;
    }

    /**
     * Path of the link matching the current page — the longest match wins, so
     * /orders/held.php highlights "Held sales", not "Credit sale".
     */
    public static function activePath(array $groups): ?string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $best = null;
        $bestLen = 0;
        foreach ($groups as $grp) {
            foreach ($grp['links'] as $l) {
                $p = '/' . rtrim(preg_replace('/index\.php$/', '', $l[2]), '/');
                if (strpos($uri, $p) !== false && strlen($p) > $bestLen) {
                    $best = $l[2];
                    $bestLen = strlen($p);
                }
            }
        }
        return $best;
    }

    /** Key of the group holding the active link. */
    public static function activeGroup(array $groups, ?string $active): ?string
    {
        foreach ($groups as $grp) {
            foreach ($grp['links'] as $l) {
                if ($l[2] === $active) {
                    return $grp['key'];
                }
            }
        }
        return null;
    }

    /** One link per extra product field the account uses (ProductFields), plus the chooser. */
    private static function fieldLinks(bool $show): array
    {
        $out = [];
        foreach (ProductFields::enabled() as $k) {
            [, $plural, $icon] = ProductFields::FIELDS[$k];
            $out[] = [$plural, $icon, $k === 'subcategory' ? 'super/subcategories/' : "super/fields/?type=$k", $show];
        }
        $out[] = ['Product fields', 'fa-sliders', 'super/fields/', $show && TenantContext::role() === 'tenant_owner'];
        return $out;
    }

    private static function isPrimaryOwner(): bool
    {
        $stmt = Database::pdo()->prepare('SELECT owner_user_id FROM tenants WHERE id = ? LIMIT 1');
        $stmt->execute([TenantContext::tenantId()]);
        return (int) $stmt->fetchColumn() === (int) TenantContext::userId();
    }

    private static function onlineOrders(): ?string
    {
        try {
            $st = Database::pdo()->prepare("SELECT COUNT(*) FROM rest_orders WHERE tenant_id = ? AND source = 'online' AND status = 'open'");
            $st->execute([TenantContext::tenantId()]);
            $n = (int) $st->fetchColumn();
            return $n ? $n . ' online' : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Tickets support has answered that this account hasn't seen yet. */
    private static function ticketReplies(): int
    {
        try {
            $st = Database::pdo()->prepare('SELECT COUNT(*) FROM support_tickets WHERE account_id = ? AND unread_by_user = 1');
            $st->execute([Features::accountId((int) TenantContext::tenantId())]);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
