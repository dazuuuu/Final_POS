<?php
// app/helpers/AccountTime.php — each account's own time zone.
// "Today", opening / closing sales, the morning summary, attendance and every
// timestamp follow the account's zone. Until the owner chooses one the app
// keeps using Africa/Nairobi (what every account has run on so far) and the
// owner is asked to confirm it before using the back office.
class AccountTime
{
    const DEFAULT_ZONE = 'Africa/Nairobi';
    private const REFRESH = 600; // seconds a signed-in person keeps the zone they loaded with

    private static function ensureColumn(PDO $db): void
    {
        Database::schemaOnce(static::class, __FILE__, function () use ($db) {
            try { $db->query('SELECT timezone FROM tenants LIMIT 0'); }
            catch (\Throwable $e) { try { $db->exec('ALTER TABLE tenants ADD COLUMN timezone VARCHAR(64) NULL'); } catch (\Throwable $e2) { /* exists */ } }
        });
    }

    /** ['zone' => name, 'set' => has the owner chosen one?] — cached in the session. */
    public static function info(): array
    {
        $tid = TenantContext::tenantId();
        if ($tid === null || session_status() !== PHP_SESSION_ACTIVE) {
            return ['zone' => self::DEFAULT_ZONE, 'set' => true];
        }
        $c = $_SESSION['acct_tz'] ?? null;
        if (is_array($c) && ($c['tid'] ?? 0) === (int) $tid && time() - (int) ($c['at'] ?? 0) < self::REFRESH) {
            return $c;
        }
        $zone = null;
        try {
            $db = Database::pdo();
            self::ensureColumn($db);
            $st = $db->prepare('SELECT timezone FROM tenants WHERE id = ?');
            $st->execute([Features::accountId((int) $tid)]);
            $zone = $st->fetchColumn() ?: null;
        } catch (\Throwable $e) { /* keep the default */ }
        $valid = $zone && in_array($zone, DateTimeZone::listIdentifiers(), true);
        return $_SESSION['acct_tz'] = ['tid' => (int) $tid, 'at' => time(), 'zone' => $valid ? $zone : self::DEFAULT_ZONE, 'set' => (bool) $valid];
    }

    /** Make PHP and MySQL both use the account's zone for this request. */
    public static function apply(): void
    {
        if (empty($_SESSION['logged_in'])) { return; }
        $zone = self::info()['zone'];
        if ($zone === self::DEFAULT_ZONE) { return; } // already how app.php and Database start
        date_default_timezone_set($zone);
        try {
            Database::pdo()->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone($zone)))->format('P') . "'");
        } catch (\Throwable $e) { /* the page still works on the default */ }
    }

    public static function save(int $tenantId, string $zone): bool
    {
        if (!in_array($zone, DateTimeZone::listIdentifiers(), true)) { return false; }
        $db = Database::pdo();
        self::ensureColumn($db);
        $db->prepare('UPDATE tenants SET timezone = ? WHERE id = ?')->execute([$zone, Features::accountId($tenantId)]);
        unset($_SESSION['acct_tz']);
        return true;
    }

    /** Owner signed in, zone never chosen: send them to confirm it first (back-office pages only). */
    public static function gate(): void
    {
        if (empty($_SESSION['logged_in']) || empty($_SESSION['otp_verified']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { return; }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (strpos($script, '/super/') === false || strpos($script, '/super/api/') !== false || strpos($script, '/timezone/') !== false) { return; }
        if (!TenantContext::isPrimaryOwner() || self::info()['set']) { return; }
        header('Location: ' . public_url('super/timezone/') . '?next=' . urlencode((string) ($_SERVER['REQUEST_URI'] ?? '')));
        exit;
    }

    /** [zone => "Region — City (UTC+03:00)"] grouped by region, for the picker. */
    public static function options(): array
    {
        $out = [];
        $now = new DateTime('now', new DateTimeZone('UTC'));
        foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $id) {
            if (strpos($id, '/') === false) { continue; }
            [$region, $city] = explode('/', $id, 2);
            $out[$region][$id] = str_replace('_', ' ', $city) . ' (UTC' . (new DateTime('now', new DateTimeZone($id)))->format('P') . ')';
        }
        return $out;
    }
}
