<?php
// app/helpers/SingleSession.php
// An owner account is signed in on one device at a time. Each owner sign-in
// stamps a fresh token on the user row and in the session; every request
// compares the two. Signing in somewhere else replaces the token, so the
// older device is signed out on its next click with a clear message.
// The newest sign-in wins (rather than refusing it), so an owner whose phone
// was lost or whose browser closed while signed in is never locked out.
// Support previews never claim the token, so they don't sign the owner out.

class SingleSession
{
    /** Roles limited to one device at a time. */
    const ROLES = ['tenant_owner'];

    private static function ensureColumn(PDO $pdo): void
    {
        static $done = false;
        if ($done) { return; }
        $flag = Database::marker('single_session_col');
        if (!is_file($flag)) {
            $has = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'session_token'"
            )->fetchColumn();
            if (!$has) {
                $pdo->exec('ALTER TABLE users ADD COLUMN session_token CHAR(32) NULL');
            }
            @file_put_contents($flag, date('c'));
        }
        $done = true;
    }

    /** Call right after a real sign-in: this device becomes the only signed-in one. */
    public static function claim(PDO $pdo, array $user): void
    {
        if (!in_array($user['role_name'] ?? '', self::ROLES, true)) { return; }
        try {
            self::ensureColumn($pdo);
            $token = bin2hex(random_bytes(16));
            $pdo->prepare('UPDATE users SET session_token = ? WHERE id = ?')->execute([$token, (int) $user['id']]);
            $_SESSION['session_token'] = $token;
        } catch (Throwable $e) {
            error_log('SingleSession::claim: ' . $e->getMessage());
        }
    }

    /** Signing out on purpose frees the account (only if this device holds it). */
    public static function release(PDO $pdo): void
    {
        if (empty($_SESSION['session_token']) || empty($_SESSION['user_id'])) { return; }
        try {
            $pdo->prepare('UPDATE users SET session_token = NULL WHERE id = ? AND session_token = ?')
                ->execute([(int) $_SESSION['user_id'], $_SESSION['session_token']]);
        } catch (Throwable $e) {
            error_log('SingleSession::release: ' . $e->getMessage());
        }
    }

    /** Every request: sign this device out if the account was signed in elsewhere. */
    public static function enforce(): void
    {
        if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id']) || !empty($_SESSION['support_preview'])
            || !in_array($_SESSION['role'] ?? '', self::ROLES, true)) {
            return;
        }
        try {
            $pdo = Database::pdo();
            self::ensureColumn($pdo);
            $st = $pdo->prepare('SELECT session_token FROM users WHERE id = ?');
            $st->execute([(int) $_SESSION['user_id']]);
            $current = $st->fetchColumn();
        } catch (Throwable $e) {
            error_log('SingleSession::enforce: ' . $e->getMessage());
            return; // never lock people out because of a database hiccup
        }
        if ($current === null || $current === false) {
            // Signed in before this rule existed: adopt this device.
            if (empty($_SESSION['session_token'])) {
                self::claim($pdo, ['id' => $_SESSION['user_id'], 'role_name' => $_SESSION['role']]);
            }
            return;
        }
        if (hash_equals((string) $current, (string) ($_SESSION['session_token'] ?? ''))) { return; }

        // Signed in on another device: end this session.
        UserSessions::end('replaced');
        $_SESSION = [];
        session_regenerate_id(true);
        TenantContext::reset();
        if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'You were signed out because this account signed in on another device.']);
            exit;
        }
        header('Location: ' . public_url('auth/login.php?elsewhere=1'));
        exit;
    }
}
