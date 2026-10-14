<?php
// app/helpers/Capabilities.php
// The single source of truth for what actions exist in the system, and the logic
// that resolves a user's *effective* capabilities (role defaults + per-user grants
// - per-user revokes).

class Capabilities
{
    // Tenant-scoped capabilities (what a shop owner / staff can do inside a shop).
    const INVENTORY_VIEW   = 'inventory.view';
    const INVENTORY_EDIT   = 'inventory.edit';
    const STOCK_ENTER      = 'stock.enter';
    const SALES_RECORD     = 'sales.record';
    const SALES_VIEW       = 'sales.view';
    const PAYMENTS_PROCESS = 'payments.process';
    const CUSTOMERS_MANAGE = 'customers.manage';
    const CATALOGUE_SEND   = 'catalogue.send';
    const REPORTS_VIEW     = 'reports.view';
    const STAFF_MANAGE     = 'staff.manage';
    const SETTINGS_MANAGE  = 'settings.manage';
    const BILLING_MANAGE   = 'billing.manage';
    const CREDIT_SALES     = 'credit.sales';     // sell on credit + keep the manual credit book
    const EXPENSES_RECORD  = 'expenses.record';  // record shop expenses (with receipt photo)
    // Services package. Every staff member sees the clients assigned to them
    // (no capability needed); these add the front-desk, cashier and money views.
    const SERVICES_BOOK    = 'services.book';    // front desk: add walk-in clients and bookings, assign staff
    const SERVICES_CASHIER = 'services.cashier'; // take payment for services and print receipts
    const SERVICES_MONEY   = 'services.money';   // see service prices/amounts and their own commission
    const SERVICES_MANAGE  = 'services.manage';  // add / edit services and their prices (owner unless delegated)
    const SERVICES_PAYOUT  = 'services.payout';  // pay staff their commission (resets their balance to zero)
    const ROOMS_MANAGE     = 'rooms.manage';     // add / edit rooms, prices and the late-checkout fee
    const ROOMS_DESK       = 'rooms.desk';       // book guests in, add room service, check out and take payment
    const TABLE_BOOKINGS   = 'tables.book';      // group / family table bookings with pre-set menus and platters

    // Platform-scoped capabilities (what you, the SaaS owner, can do).
    const PLATFORM_TENANTS = 'platform.tenants.manage';
    const PLATFORM_PLANS   = 'platform.plans.manage';
    const PLATFORM_BILLING = 'platform.billing.view';

    /** Wildcard held by platform_admin → passes every can() check. */
    const ALL = '*';

    public static function allTenantCaps(): array
    {
        return [
            self::INVENTORY_VIEW, self::INVENTORY_EDIT, self::STOCK_ENTER,
            self::SALES_RECORD, self::SALES_VIEW, self::PAYMENTS_PROCESS, self::CUSTOMERS_MANAGE,
            self::CATALOGUE_SEND, self::REPORTS_VIEW, self::STAFF_MANAGE,
            self::SETTINGS_MANAGE, self::BILLING_MANAGE,
            self::CREDIT_SALES, self::EXPENSES_RECORD,
            self::SERVICES_BOOK, self::SERVICES_CASHIER, self::SERVICES_MONEY, self::SERVICES_MANAGE,
            self::SERVICES_PAYOUT, self::ROOMS_MANAGE, self::ROOMS_DESK, self::TABLE_BOOKINGS,
        ];
    }

    /**
     * Resolve a user's effective capability list.
     * Starts from the role's default set (from roles.capabilities JSON), then
     * applies per-user grants/revokes from user_permissions.
     *
     * Returns ['*'] for platform admins (all access).
     */
    public static function effective(PDO $db, int $userId, int $roleId): array
    {
        // Role defaults
        $stmt = $db->prepare('SELECT capabilities FROM roles WHERE id = ?');
        $stmt->execute([$roleId]);
        $json = $stmt->fetchColumn();
        $caps = $json ? (json_decode($json, true) ?: []) : [];

        if (in_array(self::ALL, $caps, true)) {
            return [self::ALL]; // platform admin short-circuit
        }

        $caps = array_fill_keys($caps, true);

        // Shop owners always hold every tenant capability, so capabilities
        // added after the roles row was seeded never lock the owner out.
        $stmt = $db->prepare('SELECT role_name FROM roles WHERE id = ?');
        $stmt->execute([$roleId]);
        if ($stmt->fetchColumn() === 'tenant_owner') {
            $caps += array_fill_keys(self::allTenantCaps(), true);
        }

        // Per-user overrides
        $stmt = $db->prepare('SELECT capability, effect FROM user_permissions WHERE user_id = ?');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['effect'] === 'grant') {
                $caps[$row['capability']] = true;
            } else { // revoke
                unset($caps[$row['capability']]);
            }
        }

        return array_keys($caps);
    }
}