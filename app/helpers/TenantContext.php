<?php
// app/helpers/TenantContext.php
// Holds the current request's identity: which tenant, which user, which
// capabilities. Populated once at login (into the session) and rehydrated from
// the session on every request via boot().
// Subscription-gating has been removed for single-tenant POS use.

class TenantContext
{
    private static bool $booted   = false;
    private static ?int $userId   = null;
    private static ?int $tenantId = null;
    private static ?string $role  = null;
    private static array $caps    = [];

    /** Rehydrate context from the session. Call once early in each request. */
    public static function boot(): void
    {
        self::$userId   = $_SESSION['user_id']      ?? null;
        self::$tenantId = $_SESSION['tenant_id']    ?? null;
        self::$role     = $_SESSION['role']         ?? null;
        self::$caps     = $_SESSION['capabilities'] ?? [];
        self::$booted   = true;
        self::refreshAdminCaps();
    }

    /**
     * Admins (secondary owners) pick up the owner's permission changes within
     * a minute instead of at their next login.
     */
    private static function refreshAdminCaps(): void
    {
        if (self::$role !== 'tenant_owner' || !self::$userId || !self::$tenantId) {
            return;
        }
        if (isset($_SESSION['is_primary']) && $_SESSION['is_primary']) {
            return;
        }
        if (isset($_SESSION['is_primary']) && time() - (int) ($_SESSION['caps_at'] ?? 0) < 60) {
            return;
        }
        try {
            $db = Database::pdo();
            $home = (int) ($_SESSION['home_tenant_id'] ?? self::$tenantId);
            $primary = Capabilities::isPrimaryOwner($db, self::$userId, $home)
                || Capabilities::isPrimaryOwner($db, self::$userId, self::$tenantId);
            $_SESSION['is_primary'] = $primary;
            if (!$primary) {
                $roleId = (int) ($_SESSION['cap_role_id'] ?? 0);
                if (!$roleId) {
                    $roleId = (int) $db->query("SELECT id FROM roles WHERE role_name = 'tenant_owner' LIMIT 1")->fetchColumn();
                    $_SESSION['cap_role_id'] = $roleId;
                }
                $_SESSION['capabilities'] = Capabilities::effective($db, self::$userId, $roleId, $home);
                self::$caps = $_SESSION['capabilities'];
            }
            $_SESSION['caps_at'] = time();
        } catch (\Throwable $e) {
            // DB hiccup: keep the capabilities already in the session.
        }
    }

    /** The shop's primary owner — the one account nobody can restrict. */
    public static function isPrimaryOwner(): bool
    {
        self::ensure();
        return self::$role === 'tenant_owner' && !empty($_SESSION['is_primary']);
    }

    /**
     * Populate context at login time and persist it to the session.
     * Computes the effective capability set from the DB.
     */
    public static function establish(PDO $db, array $user): void
    {
        $tid  = isset($user['tenant_id']) && $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
        $caps = Capabilities::effective($db, (int) $user['id'], (int) $user['role_id'], $tid);

        $_SESSION['user_id']      = (int) $user['id'];
        $_SESSION['tenant_id']    = isset($user['tenant_id']) ? ($user['tenant_id'] !== null ? (int) $user['tenant_id'] : null) : null;
        $_SESSION['role']         = $user['role_name'] ?? null;
        $_SESSION['capabilities'] = $caps;
        $_SESSION['cap_role_id']  = (int) $user['role_id'];
        $_SESSION['is_primary']   = ($user['role_name'] ?? null) === 'tenant_owner'
            && Capabilities::isPrimaryOwner($db, (int) $user['id'], $tid);
        $_SESSION['caps_at']      = time();
        unset($_SESSION['home_tenant_id']); // re-derived by ShopContext for this login

        self::boot();
    }

    public static function check(): bool    { self::ensure(); return self::$userId !== null; }
    public static function userId(): ?int   { self::ensure(); return self::$userId; }
    public static function tenantId(): ?int { self::ensure(); return self::$tenantId; }
    public static function role(): ?string  { self::ensure(); return self::$role; }

    public static function isPlatformAdmin(): bool
    {
        self::ensure();
        return in_array(Capabilities::ALL, self::$caps, true);
    }

    /** Does the current user hold a capability? Platform admins hold all. */
    public static function can(string $cap): bool
    {
        self::ensure();
        return in_array(Capabilities::ALL, self::$caps, true)
            || in_array($cap, self::$caps, true)
            // The primary owner holds every tenant capability (covers sessions
            // started before a capability existed). Admins only hold what the
            // owner left switched on.
            || (self::isPrimaryOwner() && in_array($cap, Capabilities::allTenantCaps(), true));
    }

    private static function ensure(): void
    {
        if (!self::$booted) {
            self::boot();
        }
    }

    /** Test/maintenance helper — reset between requests in a long-running process. */
    public static function reset(): void
    {
        self::$booted   = false;
        self::$userId   = self::$tenantId = null;
        self::$role     = null;
        self::$caps     = [];
    }
}