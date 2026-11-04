<?php
// app/helpers/AccountLock.php
// Support can lock a shop owner's account with a reason. A locked account:
//   • owners / admins can still sign in, but only open the pages support left
//     open (by default only Export — so they can take their data) plus Help;
//     every other page shows the lock screen with support's reason
//   • staff are signed out and shown the reason
//   • its Website API keys stop working
// Unlocking puts everything back as it was. (Suspending an account — status —
// is separate: that blocks sign-in completely.)
//
// Columns on tenants (the account row): locked_at, lock_reason, lock_allow (page keys, comma separated).

class AccountLock
{
    /** Pages support can leave open: key => [label, path fragment]. */
    const PAGES = [
        'export'    => ['Export my data', '/super/backup/'],
        'dashboard' => ['Dashboard', '/super/dashboard/'],
        'inventory' => ['Inventory (view)', '/super/inventory/'],
        'sales'     => ['Sales', '/super/sales/'],
        'reports'   => ['Reports', '/super/reports/'],
        'customers' => ['Customers', '/super/customers/'],
        'settings'  => ['Shop details', '/super/settings/'],
    ];
    const DEFAULT_ALLOW = ['export'];
    /** Always open while locked: the lock screen, help & tickets (to reach support), profile, upgrade notes. */
    const ALWAYS = ['/super/locked.php', '/super/alerts/', '/super/help/', '/super/guide/', '/super/profile/'];

    private static array $cache = [];

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $pdo = Database::pdo();
        $flag = Database::marker('account_lock_v1');
        if (!is_file($flag)) {
            $have = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tenants'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('locked_at', $have, true))   $pdo->exec('ALTER TABLE tenants ADD COLUMN locked_at DATETIME NULL');
            if (!in_array('lock_reason', $have, true)) $pdo->exec('ALTER TABLE tenants ADD COLUMN lock_reason VARCHAR(1000) NULL');
            if (!in_array('lock_allow', $have, true))  $pdo->exec('ALTER TABLE tenants ADD COLUMN lock_allow VARCHAR(255) NULL');
            @file_put_contents($flag, date('c'));
        }
        $done = true;
    }

    /** ['locked' => bool, 'reason', 'at', 'allow' => [keys]] for an account. */
    public static function state(int $account): array
    {
        if (isset(self::$cache[$account])) return self::$cache[$account];
        self::ensure();
        $st = Database::pdo()->prepare('SELECT locked_at, lock_reason, lock_allow FROM tenants WHERE id = ?');
        $st->execute([$account]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return self::$cache[$account] = [
            'locked' => !empty($r['locked_at']),
            'at'     => $r['locked_at'] ?? null,
            'reason' => (string) ($r['lock_reason'] ?? ''),
            'allow'  => isset($r['lock_allow']) && $r['lock_allow'] !== null && $r['lock_allow'] !== ''
                ? array_values(array_intersect(array_keys(self::PAGES), explode(',', $r['lock_allow']))) : self::DEFAULT_ALLOW,
        ];
    }

    public static function isLocked(int $account): bool
    {
        try { return self::state($account)['locked']; } catch (Throwable $e) { return false; }
    }

    public static function lock(int $account, string $reason, array $allow): void
    {
        self::ensure();
        $allow = array_values(array_intersect(array_keys(self::PAGES), $allow));
        Database::pdo()->prepare('UPDATE tenants SET locked_at = NOW(), lock_reason = ?, lock_allow = ? WHERE id = ?')
            ->execute([mb_substr(trim($reason), 0, 1000), implode(',', $allow), $account]);
        unset(self::$cache[$account]);
    }

    public static function unlock(int $account): void
    {
        self::ensure();
        Database::pdo()->prepare('UPDATE tenants SET locked_at = NULL WHERE id = ?')->execute([$account]);
        unset(self::$cache[$account]);
    }

    /** Every request (app.php): keep a locked account's users on the pages left open. */
    public static function gate(): void
    {
        if (empty($_SESSION['logged_in']) || !empty($_SESSION['support_preview']) || TenantContext::tenantId() === null) return;
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!preg_match('#/(super|staff)/#', $script) || strpos($script, '/super/locked.php') !== false) return;
        try {
            $account = Features::accountId((int) TenantContext::tenantId());
            $st = self::state($account);
        } catch (Throwable $e) {
            return; // never lock people out over a database hiccup
        }
        if (!$st['locked']) return;
        if (TenantContext::role() !== 'staff') {
            foreach (self::ALWAYS as $ok) if (strpos($script, $ok) !== false) return;
            foreach ($st['allow'] as $k) if (strpos($script, self::PAGES[$k][1]) !== false) return;
        }
        header('Location: ' . public_url('super/locked.php'));
        exit;
    }

    /** Links to the pages that stay open, for the lock screen and banner. */
    public static function openLinks(int $account): array
    {
        $out = [];
        foreach (self::state($account)['allow'] as $k) $out[] = [self::PAGES[$k][0], ltrim(self::PAGES[$k][1], '/')];
        return $out;
    }
}
