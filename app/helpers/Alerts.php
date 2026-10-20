<?php
// app/helpers/Alerts.php
// News & alerts from support to shop owners. Support writes one in
// Support → Alerts and sends it to every account or to one; it pops up on the
// owner's dashboard the first time they open the POS after it was sent, and
// stays in their News & alerts page.
//   kind notice    text (and an optional poster image) — "Got it" closes it
//   kind poll      a question with options — the owner votes
//   kind feedback  a question — the owner writes an answer
// "required" means the pop-up can't be closed until they vote / answer.
// Owners and admins (tenant_owner) see alerts; staff don't. Demo shops are skipped.
//
//   support_alerts            title, body, image, kind, options, required, audience, dates
//   support_alert_responses   per user: seen, choice, feedback, closed — one row each

class Alerts
{
    const KINDS = ['notice' => 'Notice', 'poll' => 'Vote', 'feedback' => 'Feedback'];

    public static function ensure(?PDO $pdo = null): void
    {
        static $done = false;
        if ($done) return;
        $pdo ??= Database::pdo();
        $flag = Database::marker('alerts_v1');
        if (!is_file($flag)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS support_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(160) NOT NULL,
                body TEXT NOT NULL,
                image_path VARCHAR(255) NULL,
                kind ENUM('notice','poll','feedback') NOT NULL DEFAULT 'notice',
                options TEXT NULL,
                required TINYINT(1) NOT NULL DEFAULT 0,
                button_label VARCHAR(40) NULL,
                target_account INT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                ends_at DATETIME NULL,
                created_by VARCHAR(150) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_alerts_active (active, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // account_id: deleting an account removes its answers too.
            $pdo->exec("CREATE TABLE IF NOT EXISTS support_alert_responses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                alert_id INT NOT NULL,
                account_id INT NOT NULL,
                user_id INT NOT NULL,
                choice VARCHAR(160) NULL,
                feedback VARCHAR(2000) NULL,
                seen_at DATETIME NULL,
                closed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_alert_user (alert_id, user_id), KEY idx_alert_resp (alert_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            @file_put_contents($flag, date('c'));
        }
        $done = true;
    }

    // ------------------------------------------------------------- support

    /** Create an alert. Returns its id. */
    public static function create(array $in, ?string $image, string $by): int
    {
        self::ensure();
        $kind = isset(self::KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'notice';
        $opts = $kind === 'poll' ? array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($in['options'] ?? ''))))) : [];
        $target = (int) ($in['target'] ?? 0) ?: null;
        $ends = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['ends_at'] ?? '')) ? $in['ends_at'] . ' 23:59:59' : null;
        Database::pdo()->prepare('INSERT INTO support_alerts (title, body, image_path, kind, options, required, button_label, target_account, ends_at, created_by)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([mb_substr(trim((string) $in['title']), 0, 160), trim((string) $in['body']), $image, $kind, $opts ? json_encode($opts) : null,
                       $kind !== 'notice' && !empty($in['required']) ? 1 : 0, mb_substr(trim((string) ($in['button_label'] ?? '')), 0, 40) ?: null,
                       $target, $ends, $by]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function all(): array
    {
        self::ensure();
        return Database::pdo()->query('SELECT a.*, t.name AS target_name,
                (SELECT COUNT(*) FROM support_alert_responses r WHERE r.alert_id = a.id AND r.seen_at IS NOT NULL) AS seen,
                (SELECT COUNT(*) FROM support_alert_responses r WHERE r.alert_id = a.id AND (r.choice IS NOT NULL OR r.feedback IS NOT NULL)) AS answered
              FROM support_alerts a LEFT JOIN tenants t ON t.id = a.target_account ORDER BY a.id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM support_alerts WHERE id = ?');
        $st->execute([$id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if ($a) $a['option_list'] = $a['options'] ? (json_decode($a['options'], true) ?: []) : [];
        return $a ?: null;
    }

    /** Votes per option and every written answer, with who gave them. */
    public static function results(int $id): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $votes = $pdo->prepare('SELECT choice, COUNT(*) FROM support_alert_responses WHERE alert_id = ? AND choice IS NOT NULL GROUP BY choice');
        $votes->execute([$id]);
        $rows = $pdo->prepare('SELECT r.*, u.username, t.name AS account FROM support_alert_responses r LEFT JOIN users u ON u.id = r.user_id
                                 LEFT JOIN tenants t ON t.id = r.account_id WHERE r.alert_id = ? ORDER BY r.id DESC');
        $rows->execute([$id]);
        return ['votes' => $votes->fetchAll(PDO::FETCH_KEY_PAIR), 'rows' => $rows->fetchAll(PDO::FETCH_ASSOC)];
    }

    public static function setActive(int $id, bool $on): void
    {
        self::ensure();
        Database::pdo()->prepare('UPDATE support_alerts SET active = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    }

    public static function delete(int $id): void
    {
        self::ensure();
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM support_alert_responses WHERE alert_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM support_alerts WHERE id = ?')->execute([$id]);
    }

    // --------------------------------------------------------------- owner

    /** Alerts this account can see (all live ones for everyone, plus its own), newest first. */
    public static function forAccount(int $account, int $userId): array
    {
        self::ensure();
        $st = Database::pdo()->prepare(
            'SELECT a.*, r.choice, r.feedback, r.closed_at, r.seen_at FROM support_alerts a
               LEFT JOIN support_alert_responses r ON r.alert_id = a.id AND r.user_id = ?
              WHERE a.active = 1 AND (a.target_account IS NULL OR a.target_account = ?) AND (a.ends_at IS NULL OR a.ends_at >= NOW())
              ORDER BY a.id DESC LIMIT 100'
        );
        $st->execute([$userId, $account]);
        return array_map(function ($a) { $a['option_list'] = $a['options'] ? (json_decode($a['options'], true) ?: []) : []; return $a; }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The oldest alert this user hasn't closed yet (the one to pop up), or null.
     * In a support preview, alerts closed during the preview (kept in the
     * session only) are skipped too — the owner's own answers are untouched.
     */
    public static function pending(int $account, int $userId): ?array
    {
        $previewClosed = !empty($_SESSION['support_preview']) ? ($_SESSION['preview_alerts_closed'] ?? []) : [];
        foreach (array_reverse(self::forAccount($account, $userId)) as $a) {
            if (empty($a['closed_at']) && !in_array((int) $a['id'], $previewClosed, true)) return $a;
        }
        return null;
    }

    public static function unreadCount(int $account, int $userId): int
    {
        return count(array_filter(self::forAccount($account, $userId), fn($a) => empty($a['closed_at'])));
    }

    /** The pop-up was shown. */
    public static function seen(int $alertId, int $account, int $userId): void
    {
        self::ensure();
        Database::pdo()->prepare('INSERT INTO support_alert_responses (alert_id, account_id, user_id, seen_at) VALUES (?, ?, ?, NOW())
                                  ON DUPLICATE KEY UPDATE seen_at = COALESCE(seen_at, NOW())')->execute([$alertId, $account, $userId]);
    }

    /** Close / vote / answer. Returns an error message, or null when done. */
    public static function respond(int $alertId, int $account, int $userId, array $in): ?string
    {
        $a = self::find($alertId);
        if (!$a || (int) $a['active'] !== 1 || ($a['target_account'] && (int) $a['target_account'] !== $account)) return 'This alert is no longer available.';
        $choice = null;
        $feedback = null;
        if ($a['kind'] === 'poll' && ($in['choice'] ?? '') !== '') {
            if (!in_array($in['choice'], $a['option_list'], true)) return 'Pick one of the options.';
            $choice = $in['choice'];
        }
        if ($a['kind'] === 'feedback' && trim((string) ($in['feedback'] ?? '')) !== '') {
            $feedback = mb_substr(trim((string) $in['feedback']), 0, 2000);
        }
        if ((int) $a['required'] === 1 && $choice === null && $feedback === null) {
            return $a['kind'] === 'poll' ? 'Please vote to continue.' : 'Please leave your feedback to continue.';
        }
        self::ensure();
        Database::pdo()->prepare(
            'INSERT INTO support_alert_responses (alert_id, account_id, user_id, choice, feedback, seen_at, closed_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE choice = COALESCE(VALUES(choice), choice), feedback = COALESCE(VALUES(feedback), feedback),
                                     seen_at = COALESCE(seen_at, NOW()), closed_at = NOW()'
        )->execute([$alertId, $account, $userId, $choice, $feedback]);
        return null;
    }

    /** Full URL path of a stored poster image. */
    public static function image(?string $path): ?string
    {
        return $path ? public_url(ltrim($path, '/')) : null;
    }
}
