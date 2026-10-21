<?php
// app/helpers/SupportAccount.php
// The Support console login (platform vendor, not a shop user). Written by the
// setup wizard to app/config/support.php — a file, not a DB row, so support can
// still sign in (and fix the database settings) while the database is down.
//
// That login is the main support admin ("owner"). The owner can add support
// team members ("agents", table support_agents). Agents can do everything in
// the console except: open a client's POS as one of its users (preview), and
// manage the support team (they can't add other support logins).

class SupportAccount
{
    /** Path of the support login file (DAZU_SUPPORT_CONFIG env var overrides, for tests). */
    public static function configPath(): string
    {
        return getenv('DAZU_SUPPORT_CONFIG') ?: ROOT_PATH . '/app/config/support.php';
    }

    /** ['email' => ..., 'password_hash' => ...] or null when setup hasn't created it yet. */
    public static function config(): ?array
    {
        $file = self::configPath();
        if (!is_file($file)) {
            return null;
        }
        $cfg = include $file;
        return is_array($cfg) && !empty($cfg['email']) && !empty($cfg['password_hash']) ? $cfg : null;
    }

    public static function exists(): bool
    {
        return self::config() !== null;
    }

    public static function email(): string
    {
        return self::config()['email'] ?? '';
    }

    public static function verify(string $email, string $password): bool
    {
        $cfg = self::config();
        return $cfg !== null
            && hash_equals(strtolower($cfg['email']), strtolower(trim($email)))
            && password_verify($password, $cfg['password_hash']);
    }

    public static function save(string $email, string $password): bool
    {
        $clean = [
            'email'         => strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ];
        $php = "<?php\n// app/config/support.php\n// Support console login. Written by the setup wizard on " . date('Y-m-d H:i')
             . " — rerun public/setup/account.php to change it.\n\nreturn " . var_export($clean, true) . ";\n";
        $ok = @file_put_contents(self::configPath(), $php, LOCK_EX) !== false;
        if ($ok && function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::configPath(), true);
        }
        return $ok;
    }

    // ---------------------------------------------------------------- team

    /** Result of the last confirm email sent by addAgent() — ['sent', 'link']. */
    public static ?array $lastConfirm = null;

    private static function ensureTable(PDO $pdo): void
    {
        static $done = false;
        if ($done) { return; }
        $pdo->exec("CREATE TABLE IF NOT EXISTS support_agents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at DATETIME NULL,
            UNIQUE KEY uq_support_agent_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    /**
     * Check a sign-in: the main admin (config file) first, then active team
     * members. Returns ['role' => 'owner'|'agent', 'id' => int, 'email', 'name'] or null.
     */
    public static function authenticate(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        if (self::verify($email, $password)) {
            return ['role' => 'owner', 'id' => 0, 'email' => self::email(), 'name' => 'Main admin'];
        }
        try {
            $pdo = Database::pdo();
            self::ensureTable($pdo);
            EmailConfirm::ensure(); // adds support_agents.email_verified
            $st = $pdo->prepare('SELECT * FROM support_agents WHERE email = ? AND is_active = 1');
            $st->execute([$email]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if ($a && password_verify($password, $a['password_hash'])) {
                if (isset($a['email_verified']) && !(int) $a['email_verified']) {
                    self::$unconfirmed = true;
                    return null;
                }
                $pdo->prepare('UPDATE support_agents SET last_login_at = NOW() WHERE id = ?')->execute([$a['id']]);
                return ['role' => 'agent', 'id' => (int) $a['id'], 'email' => $a['email'], 'name' => $a['name']];
            }
        } catch (Throwable $e) {
            error_log('SupportAccount::authenticate: ' . $e->getMessage());
        }
        return null;
    }

    /** Set when a sign-in failed only because the email isn't confirmed yet. */
    public static bool $unconfirmed = false;

    /** Put a successful sign-in into the session. */
    public static function signIn(array $who): void
    {
        session_regenerate_id(true);
        $_SESSION['support_auth']  = true;
        $_SESSION['support_role']  = $who['role'];
        $_SESSION['support_agent'] = $who['id'];
        $_SESSION['support_email'] = $who['email'];
        $_SESSION['support_name']  = $who['name'];
    }

    /** Is the signed-in support user the main admin? (Sessions from before teams existed were the admin.) */
    public static function isOwner(): bool
    {
        return !empty($_SESSION['support_auth']) && ($_SESSION['support_role'] ?? 'owner') === 'owner';
    }

    /** Email of whoever is signed in to the console. */
    public static function currentEmail(): string
    {
        return (string) ($_SESSION['support_email'] ?? self::email());
    }

    /** A team member who was switched off or removed is signed out on their next click. */
    public static function agentStillActive(): bool
    {
        if (self::isOwner()) { return true; }
        try {
            $pdo = Database::pdo();
            self::ensureTable($pdo);
            $st = $pdo->prepare('SELECT is_active FROM support_agents WHERE id = ?');
            $st->execute([(int) ($_SESSION['support_agent'] ?? 0)]);
            return (int) $st->fetchColumn() === 1;
        } catch (Throwable $e) {
            return true; // a database hiccup must not sign people out
        }
    }

    public static function agents(): array
    {
        $pdo = Database::pdo();
        self::ensureTable($pdo);
        return $pdo->query('SELECT id, name, email, is_active, created_at, last_login_at FROM support_agents ORDER BY is_active DESC, name')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Add a team member. Returns an error message, or null when done. */
    public static function addAgent(string $name, string $email, string $password): ?string
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '') { return 'Enter their name.'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return 'Enter a valid email address.'; }
        if (strlen($password) < 8) { return 'The password must be at least 8 characters.'; }
        if ($email === strtolower(self::email())) { return 'That email is the main admin login.'; }
        $pdo = Database::pdo();
        self::ensureTable($pdo);
        $chk = $pdo->prepare('SELECT COUNT(*) FROM support_agents WHERE email = ?');
        $chk->execute([$email]);
        if ($chk->fetchColumn()) { return 'A team member with that email already exists.'; }
        $pdo->prepare('INSERT INTO support_agents (name, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        // They confirm their email before they can sign in; the login details follow.
        $cf = EmailConfirm::start('agent', (int) $pdo->lastInsertId(), $email, $name, $password, EmailConfirm::absolute('support/login.php'));
        self::$lastConfirm = $cf;
        return null;
    }

    public static function setAgentActive(int $id, bool $on): void
    {
        $pdo = Database::pdo();
        self::ensureTable($pdo);
        $pdo->prepare('UPDATE support_agents SET is_active = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    }

    public static function setAgentPassword(int $id, string $password): ?string
    {
        if (strlen($password) < 8) { return 'The password must be at least 8 characters.'; }
        $pdo = Database::pdo();
        self::ensureTable($pdo);
        $pdo->prepare('UPDATE support_agents SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        return null;
    }

    public static function deleteAgent(int $id): void
    {
        $pdo = Database::pdo();
        self::ensureTable($pdo);
        $pdo->prepare('DELETE FROM support_agents WHERE id = ?')->execute([$id]);
    }
}
