<?php
// app/helpers/Addons.php — optional extras an account switches on itself (Settings → Add-ons),
// as opposed to packages, which support assigns.
//   services_shop   the retail shop (POS, credit, payments, returns, products…) added to a Services account.
// Accounts that already had a Services package when this was introduced keep the shop (switched on once).

class Addons
{
    public const SERVICES_SHOP = 'services_shop';

    private static array $cache = [];

    public static function ensure(): void
    {
        static $done = false;
        if ($done) { return; }
        $db = Database::pdo();
        $flag = Database::marker('addons_v1');
        if (!is_file($flag)) {
            $db->exec('CREATE TABLE IF NOT EXISTS account_addons (
                account_id INT NOT NULL, addon_key VARCHAR(40) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (account_id, addon_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            try { // existing Services accounts keep the shop they were already using
                $db->exec("INSERT IGNORE INTO account_addons (account_id, addon_key, enabled)
                           SELECT DISTINCT tenant_id, 'services_shop', 1 FROM tenant_packages
                            WHERE enabled = 1 AND package_key IN ('" . Features::SERVICES . "', '" . Features::SERVICES_BASIC . "')");
            } catch (\Throwable $e) { /* no packages table yet */ }
            @file_put_contents($flag, date('c'));
        }
        $done = true;
    }

    public static function on(int $account, string $key): bool
    {
        if (!isset(self::$cache[$account])) {
            self::$cache[$account] = [];
            try {
                self::ensure();
                $st = Database::pdo()->prepare('SELECT addon_key, enabled FROM account_addons WHERE account_id = ?');
                $st->execute([$account]);
                self::$cache[$account] = array_map('boolval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
            } catch (\Throwable $e) { /* treated as off */ }
        }
        return !empty(self::$cache[$account][$key]);
    }

    public static function set(int $account, string $key, bool $on): void
    {
        self::ensure();
        Database::pdo()->prepare('INSERT INTO account_addons (account_id, addon_key, enabled) VALUES (?,?,?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)')
            ->execute([$account, $key, $on ? 1 : 0]);
        unset(self::$cache[$account]);
    }
}
