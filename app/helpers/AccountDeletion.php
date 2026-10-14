<?php
// app/helpers/AccountDeletion.php
// Permanently deletes a shop-owner account: the account, its branch shops,
// every login on them and every row of data they own. Used by Support
// (support/accounts/view.php → Delete account). Cannot be undone.
//
// Tables are found from the live schema, so tables added later are covered:
//   1. rows whose tenant_id or account_id is one of the account's shops
//   2. rows whose user_id points at one of the account's users (profiles,
//      carts, ticket messages…), and login attempts by their emails
//   3. support ticket messages of the account's tickets
//   4. the users, then the shops themselves
// Uploaded files (logo, product photos) under public/assets/uploads are removed.

class AccountDeletion
{
    /** Never touched even if they have a matching column. */
    const KEEP = ['roles', 'site_settings'];

    /** What would be deleted: ['shops' => [...names], 'users' => n, 'rows' => n]. */
    public static function preview(int $accountId): array
    {
        $pdo = Database::pdo();
        [$shopIds, $userIds] = self::ids($pdo, $accountId);
        $rows = 0;
        foreach (self::plan($pdo, $shopIds, $userIds) as [$sql, $params]) {
            $st = $pdo->prepare(preg_replace('/^DELETE FROM/', 'SELECT COUNT(*) FROM', $sql));
            $st->execute($params);
            $rows += (int) $st->fetchColumn();
        }
        $in = implode(',', array_fill(0, count($shopIds), '?'));
        $names = $pdo->prepare("SELECT name FROM tenants WHERE id IN ($in) ORDER BY parent_tenant_id IS NOT NULL, name");
        $names->execute($shopIds);
        return ['shops' => $names->fetchAll(PDO::FETCH_COLUMN), 'users' => count($userIds), 'rows' => $rows];
    }

    /** Delete it all. Returns rows deleted per table. Throws (and rolls back) on failure. */
    public static function delete(int $accountId): array
    {
        $pdo = Database::pdo();
        [$shopIds, $userIds] = self::ids($pdo, $accountId);
        $files = self::uploadedFiles($pdo, $shopIds);

        $done = [];
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->beginTransaction();
        try {
            foreach (self::plan($pdo, $shopIds, $userIds) as $table => [$sql, $params]) {
                $st = $pdo->prepare($sql);
                $st->execute($params);
                if ($st->rowCount()) $done[$table] = ($done[$table] ?? 0) + $st->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $root = realpath(ROOT_PATH . '/public/assets/uploads');
        foreach ($files as $f) {
            $pos = strpos($f, 'assets/uploads/');
            if ($pos === false) continue;
            $real = realpath(ROOT_PATH . '/public/' . substr($f, $pos));
            if ($root && $real && strpos($real, $root . DIRECTORY_SEPARATOR) === 0 && is_file($real)) @unlink($real);
        }
        return $done;
    }

    /** [shop ids (account + branches), user ids]. */
    private static function ids(PDO $pdo, int $accountId): array
    {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE id = ? OR parent_tenant_id = ?');
        $st->execute([$accountId, $accountId]);
        $shopIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$shopIds) throw new RuntimeException('Account not found.');
        $in = implode(',', array_fill(0, count($shopIds), '?'));
        $st = $pdo->prepare("SELECT id FROM users WHERE tenant_id IN ($in)");
        $st->execute($shopIds);
        return [$shopIds, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))];
    }

    /** table => [DELETE sql, params], in a safe order (data first, users and shops last). */
    private static function plan(PDO $pdo, array $shopIds, array $userIds): array
    {
        $cols = [];
        foreach ($pdo->query("SELECT table_name AS t, column_name AS c FROM information_schema.columns
                               WHERE table_schema = DATABASE() AND column_name IN ('tenant_id', 'account_id', 'user_id', 'ticket_id', 'email')")
                 ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[$r['t']][$r['c']] = true;
        }
        $shopIn = implode(',', array_fill(0, count($shopIds), '?'));
        $userIn = $userIds ? implode(',', array_fill(0, count($userIds), '?')) : null;
        $plan = [];

        // Messages hang off the account's support tickets.
        if (isset($cols['support_ticket_messages']['ticket_id'], $cols['support_tickets'])) {
            $plan['support_ticket_messages'] = [
                "DELETE FROM `support_ticket_messages` WHERE ticket_id IN (SELECT id FROM `support_tickets` WHERE "
                    . (isset($cols['support_tickets']['account_id']) ? "account_id IN ($shopIn) OR " : '') . "tenant_id IN ($shopIn))",
                isset($cols['support_tickets']['account_id']) ? array_merge($shopIds, $shopIds) : $shopIds,
            ];
        }
        if ($userIds) {
            $emails = $pdo->prepare("SELECT email FROM users WHERE id IN ($userIn)");
            $emails->execute($userIds);
            $emails = $emails->fetchAll(PDO::FETCH_COLUMN);
            if ($emails && isset($cols['login_attempts']['email'])) {
                $plan['login_attempts'] = ['DELETE FROM `login_attempts` WHERE email IN (' . implode(',', array_fill(0, count($emails), '?')) . ')', $emails];
            }
        }
        foreach ($cols as $t => $c) {
            if (in_array($t, self::KEEP, true) || $t === 'users' || $t === 'tenants') continue;
            $where = [];
            $params = [];
            foreach (['tenant_id', 'account_id'] as $k) {
                if (isset($c[$k])) { $where[] = "`$k` IN ($shopIn)"; $params = array_merge($params, $shopIds); }
            }
            if (isset($c['user_id']) && $userIds) { $where[] = "`user_id` IN ($userIn)"; $params = array_merge($params, $userIds); }
            if (!$where) continue;
            $key = isset($plan[$t]) ? $t . '#2' : $t;
            $plan[$key] = ["DELETE FROM `$t` WHERE " . implode(' OR ', $where), $params];
        }
        if ($userIds) $plan['users'] = ["DELETE FROM `users` WHERE id IN ($userIn)", $userIds];
        $plan['tenants'] = ["DELETE FROM `tenants` WHERE id IN ($shopIn)", $shopIds];
        return $plan;
    }

    /** Logo and product photo paths of these shops. */
    private static function uploadedFiles(PDO $pdo, array $shopIds): array
    {
        $in = implode(',', array_fill(0, count($shopIds), '?'));
        $files = [];
        foreach (["SELECT logo_path FROM tenants WHERE id IN ($in)", "SELECT image_path FROM products WHERE tenant_id IN ($in)"] as $sql) {
            try {
                $st = $pdo->prepare($sql);
                $st->execute($shopIds);
                $files = array_merge($files, array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
            } catch (PDOException $e) { /* column missing on an older schema */ }
        }
        return $files;
    }
}
