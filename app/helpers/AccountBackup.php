<?php
// app/helpers/AccountBackup.php
// Every 2 hours (cron, app/cron/account_backups.php), each active account gets
// its own zipped backup — its shop data via ShopExport, plus its Record
// Management data if it has any. Whoever is signed in (owner, admin or
// staff) gets a banner the next time they load a page until they download
// it, so a copy also lives on their own device — not just this server.
//
//   account_backups   one row per generated zip: account_id, file_path, size,
//                     created_at
//   account_backup_seen   per user: the newest backup id they've downloaded,
//                     so the banner only nags once per new backup

class AccountBackup
{
    const DIR = ROOT_PATH . '/app/storage/account_backups';
    const KEEP = 3; // zips kept per account — older ones are deleted

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $pdo = Database::pdo();
        $flag = Database::marker('account_backup_v1');
        if (!is_file($flag)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS account_backups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                size_bytes BIGINT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_backup_account (account_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS account_backup_seen (
                user_id INT NOT NULL,
                account_id INT NOT NULL,
                last_backup_id INT NOT NULL,
                seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            @file_put_contents($flag, date('c'));
        }
        $flag2 = Database::marker('account_backup_v2_tour');
        if (!is_file($flag2)) {
            if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'backup_tour_seen'")->fetchColumn()) {
                $pdo->exec('ALTER TABLE users ADD COLUMN backup_tour_seen TINYINT(1) NOT NULL DEFAULT 0');
            }
            @file_put_contents($flag2, date('c'));
        }
        $done = true;
    }

    public static function tourSeen(int $userId): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT backup_tour_seen FROM users WHERE id = ?');
        $st->execute([$userId]);
        return (bool) $st->fetchColumn();
    }

    public static function markTourSeen(int $userId): void
    {
        Database::pdo()->prepare('UPDATE users SET backup_tour_seen = 1 WHERE id = ?')->execute([$userId]);
    }

    /** Builds one zip for an account: its shop export, plus rm_* tables if it has Record Management data. */
    private static function buildZip(int $accountId): ?string
    {
        $dir = self::DIR . '/' . $accountId;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $sqlPath = $dir . '/tmp_' . bin2hex(random_bytes(6)) . '.sql';
        $fh = fopen($sqlPath, 'w');
        if (!$fh) return null;
        ShopExport::write($accountId, function (string $s) use ($fh) { fwrite($fh, $s); });

        // Record Management tables aren't part of ShopExport (it only covers shop
        // tables) — append them here too, since this is meant to protect everything.
        $pdo = Database::pdo();
        foreach (['rm_employees', 'rm_services', 'rm_products', 'rm_expense_categories', 'rm_expenses', 'rm_operations', 'rm_operation_products', 'rm_payments', 'rm_roles'] as $t) {
            try {
                $st = $pdo->query("SELECT * FROM `$t` WHERE account_id = $accountId");
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) continue;
                $cols = array_keys($rows[0]);
                $list = '`' . implode('`, `', $cols) . '`';
                fwrite($fh, "INSERT INTO `$t` ($list) VALUES\n");
                $lines = [];
                foreach ($rows as $r) {
                    $lines[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $r)) . ')';
                }
                fwrite($fh, implode(",\n", $lines) . ";\n\n");
            } catch (\Throwable $e) { /* table not present on this install */ }
        }
        fclose($fh);

        $zipPath = $dir . '/backup-' . date('Y-m-d-His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) { @unlink($sqlPath); return null; }
        $slug = (string) $pdo->query('SELECT slug FROM tenants WHERE id = ' . $accountId)->fetchColumn();
        $zip->addFile($sqlPath, ($slug ?: 'shop') . '-backup-' . date('Y-m-d-His') . '.sql');
        $zip->close();
        @unlink($sqlPath);
        return is_file($zipPath) ? $zipPath : null;
    }

    /** Runs from cron, every 2 hours: one zip per active account. */
    public static function generateAll(): int
    {
        self::ensure();
        $pdo = Database::pdo();
        $accounts = $pdo->query("SELECT id FROM tenants WHERE parent_tenant_id IS NULL AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        $made = 0;
        foreach ($accounts as $accountId) {
            $accountId = (int) $accountId;
            try {
                $path = self::buildZip($accountId);
                if (!$path) continue;
                $pdo->prepare('INSERT INTO account_backups (account_id, file_path, size_bytes) VALUES (?, ?, ?)')
                    ->execute([$accountId, basename($path), @filesize($path) ?: 0]);
                $made++;
                self::prune($accountId);
            } catch (\Throwable $e) {
                error_log('AccountBackup::generateAll account ' . $accountId . ': ' . $e->getMessage());
            }
        }
        return $made;
    }

    private static function prune(int $accountId): void
    {
        $pdo = Database::pdo();
        $rows = $pdo->prepare('SELECT id, file_path FROM account_backups WHERE account_id = ? ORDER BY id DESC');
        $rows->execute([$accountId]);
        $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
        foreach (array_slice($rows, self::KEEP) as $old) {
            @unlink(self::DIR . '/' . $accountId . '/' . $old['file_path']);
            $pdo->prepare('DELETE FROM account_backups WHERE id = ?')->execute([$old['id']]);
        }
    }

    public static function latestFor(int $accountId): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM account_backups WHERE account_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$accountId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Has this user NOT yet downloaded the newest backup for this account? */
    public static function pendingFor(int $accountId, int $userId): ?array
    {
        $latest = self::latestFor($accountId);
        if (!$latest) return null;
        $st = Database::pdo()->prepare('SELECT last_backup_id FROM account_backup_seen WHERE user_id = ? AND account_id = ?');
        $st->execute([$userId, $accountId]);
        $seen = (int) $st->fetchColumn();
        return $seen >= (int) $latest['id'] ? null : $latest;
    }

    public static function markSeen(int $backupId, int $accountId, int $userId): void
    {
        Database::pdo()->prepare(
            'INSERT INTO account_backup_seen (user_id, account_id, last_backup_id, seen_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_backup_id = VALUES(last_backup_id), seen_at = NOW()'
        )->execute([$userId, $accountId, $backupId]);
    }
}
