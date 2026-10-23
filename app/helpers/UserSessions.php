<?php
// app/helpers/UserSessions.php
// Who is signed in, for the support console: one row per sign-in of a shop
// user (owner, admin or staff) with when they signed in, when they were last
// active and when / how they signed out. "Online" = active in the last
// ONLINE_MINUTES and not signed out. No business data is stored — only the
// user, their account, the device's IP and browser, and the times.
//
//   user_sessions  id, user_id, account_id, tenant_id, role, ip, agent,
//                  started_at, last_seen_at, ended_at, end_reason
//                  (logout | replaced = signed in on another device | expired)

class UserSessions
{
    const ONLINE_MINUTES = 5;
    const TOUCH_SECONDS = 60; // last_seen_at is refreshed at most once a minute per session

    public static function ensure(PDO $pdo): void
    {
        static $done = false;
        if ($done) { return; }
        $flag = Database::marker('user_sessions_v1');
        if (!is_file($flag)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                account_id INT NOT NULL DEFAULT 0,
                tenant_id INT NULL,
                role VARCHAR(30) NOT NULL DEFAULT '',
                ip VARCHAR(45) NULL,
                agent VARCHAR(160) NULL,
                started_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                ended_at DATETIME NULL,
                end_reason VARCHAR(20) NULL,
                KEY idx_us_seen (last_seen_at), KEY idx_us_user (user_id, started_at), KEY idx_us_account (account_id, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            @file_put_contents($flag, date('c'));
        }
        $done = true;
    }

    /** A real sign-in just happened (call after TenantContext::establish). */
    public static function start(): void
    {
        if (empty($_SESSION['user_id']) || !empty($_SESSION['support_preview']) || !empty($_SESSION['demo'])) { return; }
        try {
            $pdo = Database::pdo();
            self::ensure($pdo);
            $tid = isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : null;
            $pdo->prepare('INSERT INTO user_sessions (user_id, account_id, tenant_id, role, ip, agent, started_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())')
                ->execute([(int) $_SESSION['user_id'], $tid ? Features::accountId($tid) : 0, $tid, (string) ($_SESSION['role'] ?? ''),
                           substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), self::device((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))]);
            $_SESSION['usess'] = ['id' => (int) $pdo->lastInsertId(), 't' => time()];
        } catch (Throwable $e) {
            error_log('UserSessions::start: ' . $e->getMessage());
        }
    }

    /** Every request: keep "last seen" fresh (cheap — at most one UPDATE a minute). */
    public static function touch(): void
    {
        if (empty($_SESSION['logged_in'])) { return; }
        $s = $_SESSION['usess'] ?? null;
        if (!$s) { self::start(); return; } // signed in before tracking existed (or a new device session): pick it up
        if (time() - (int) $s['t'] < self::TOUCH_SECONDS) { return; }
        try {
            $pdo = Database::pdo();
            self::ensure($pdo);
            $pdo->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE id = ? AND ended_at IS NULL')->execute([(int) $s['id']]);
            $_SESSION['usess']['t'] = time();
        } catch (Throwable $e) {
            // never break a page over this
        }
    }

    /** The session ended: logout, replaced (signed in elsewhere) or expired. */
    public static function end(string $reason = 'logout'): void
    {
        $s = $_SESSION['usess'] ?? null;
        if (!$s) { return; }
        try {
            $pdo = Database::pdo();
            self::ensure($pdo);
            $pdo->prepare('UPDATE user_sessions SET ended_at = NOW(), last_seen_at = NOW(), end_reason = ? WHERE id = ? AND ended_at IS NULL')
                ->execute([substr($reason, 0, 20), (int) $s['id']]);
        } catch (Throwable $e) {
            error_log('UserSessions::end: ' . $e->getMessage());
        }
        unset($_SESSION['usess']);
    }

    /** "Chrome · Android" from a user agent — enough to tell devices apart. */
    private static function device(string $ua): string
    {
        $b = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/Chrome\//', $ua) ? 'Chrome'
            : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser'))));
        $o = preg_match('/Android/', $ua) ? 'Android' : (preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (preg_match('/Windows/', $ua) ? 'Windows'
            : (preg_match('/Mac OS X/', $ua) ? 'macOS' : (preg_match('/Linux/', $ua) ? 'Linux' : 'Other'))));
        return "$b · $o";
    }

    // ------------------------------------------------------------ reports

    /** Every shop user with their latest session and online state, online first. */
    /** $accounts: limit to these account ids (a support company's accounts); null = all. */
    public static function people(PDO $pdo, int $limit = 200, ?array $accounts = null): array
    {
        self::ensure($pdo);
        $sql = 'SELECT u.id, u.username, u.tenant_id, r.role_name, s.started_at, s.last_seen_at, s.ended_at, s.agent,
                       (s.id IS NOT NULL AND s.ended_at IS NULL AND s.last_seen_at >= NOW() - INTERVAL ' . self::ONLINE_MINUTES . ' MINUTE) AS online
                  FROM users u
                  JOIN roles r ON r.id = u.role_id AND r.role_name IN (\'tenant_owner\', \'staff\')
             LEFT JOIN user_sessions s ON s.id = (SELECT MAX(id) FROM user_sessions x WHERE x.user_id = u.id)
                 WHERE u.is_active = 1 AND u.email NOT LIKE \'%@demo.local\'' . ($accounts !== null ? ' AND COALESCE((SELECT COALESCE(t.parent_tenant_id, t.id) FROM tenants t WHERE t.id = u.tenant_id), 0) IN (' . implode(',', array_map('intval', $accounts ?: [-1])) . ')' : '') . '
              ORDER BY online DESC, s.last_seen_at IS NULL, s.last_seen_at DESC, u.username
                 LIMIT ' . (int) $limit;
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Latest sign-ins with their sign-out (or "still on"). */
    public static function recent(PDO $pdo, int $limit = 25, ?array $accounts = null): array
    {
        self::ensure($pdo);
        return $pdo->query(
            'SELECT s.*, u.username FROM user_sessions s LEFT JOIN users u ON u.id = s.user_id'
            . ($accounts !== null ? ' WHERE s.account_id IN (' . implode(',', array_map('intval', $accounts ?: [-1])) . ')' : '') . ' ORDER BY s.id DESC LIMIT ' . (int) $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function onlineCount(PDO $pdo, ?array $accounts = null): int
    {
        self::ensure($pdo);
        return (int) $pdo->query('SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE ended_at IS NULL AND last_seen_at >= NOW() - INTERVAL '
            . self::ONLINE_MINUTES . ' MINUTE'
            . ($accounts !== null ? ' AND account_id IN (' . implode(',', array_map('intval', $accounts ?: [-1])) . ')' : ''))->fetchColumn();
    }
}
