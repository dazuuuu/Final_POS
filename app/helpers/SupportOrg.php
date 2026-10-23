<?php
// app/helpers/SupportOrg.php
// Separate support companies (partners / investors) on one install. Each
// company has its own main admin, its own support team and its own shop
// accounts — and cannot see or open another company's accounts.
//   company #1  the original support login (app/config/support.php). Owns every
//               account that existed before, and the platform-wide settings
//               (email server, login branding, boot sound, public signup,
//               quotation requests from the public website).
//   company #2… registered through a one-time link that company #1's main
//               admin creates in Support → Settings.
//
//   support_orgs          id, name, email, password_hash
//   support_org_invites   one-time registration links
//   tenants.support_org   which company looks after the account (default 1)
//   support_agents.org_id which company a team member belongs to (default 1)

class SupportOrg
{
    const MAIN = 1;

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $pdo = Database::pdo();
        $flag = Database::marker('support_org_v1');
        if (!is_file($flag)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS support_orgs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(150) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_support_org_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=2");
            $pdo->exec("CREATE TABLE IF NOT EXISTS support_org_invites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token_hash CHAR(64) NOT NULL,
                note VARCHAR(120) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                org_id INT NULL,
                UNIQUE KEY uq_org_invite (token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $col = fn($t, $c) => (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$t' AND column_name = '$c'")->fetchColumn();
            if (!$col('tenants', 'support_org')) $pdo->exec('ALTER TABLE tenants ADD COLUMN support_org INT NOT NULL DEFAULT 1, ADD KEY idx_tenant_support_org (support_org)');
            $hasAgents = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'support_agents'")->fetchColumn();
            if ($hasAgents && !$col('support_agents', 'org_id')) $pdo->exec('ALTER TABLE support_agents ADD COLUMN org_id INT NOT NULL DEFAULT 1');
            @file_put_contents($flag, date('c'));
        }
        // v2: every support login is its own primary account — no "team members" under
        // anyone. Existing team members become independent support accounts (same
        // email and password), invisible to whoever created them.
        $flag2 = Database::marker('support_org_v2');
        if (!is_file($flag2)) {
            if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'support_orgs' AND column_name = 'email_verified'")->fetchColumn()) {
                $pdo->exec('ALTER TABLE support_orgs ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1');
            }
            $hasAgents = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'support_agents'")->fetchColumn();
            if ($hasAgents) {
                foreach ($pdo->query('SELECT * FROM support_agents')->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $dup = $pdo->prepare('SELECT 1 FROM support_orgs WHERE email = ?');
                    $dup->execute([$a['email']]);
                    if (!$dup->fetchColumn()) {
                        $pdo->prepare('INSERT INTO support_orgs (name, email, password_hash, is_active, email_verified) VALUES (?, ?, ?, ?, ?)')
                            ->execute([$a['name'], $a['email'], $a['password_hash'], (int) $a['is_active'], (int) ($a['email_verified'] ?? 1)]);
                    }
                }
                $pdo->exec('DELETE FROM support_agents');
            }
            @file_put_contents($flag2, date('c'));
        }
        $done = true;
    }

    /** The company of whoever is signed in to the support console. */
    public static function current(): int
    {
        return (int) ($_SESSION['support_org'] ?? self::MAIN) ?: self::MAIN;
    }

    /** Is the signed-in support user a company's main admin? Every company's main admin has the same powers. */
    public static function isPlatformAdmin(): bool
    {
        return SupportAccount::isOwner();
    }

    public static function name(int $org): string
    {
        if ($org === self::MAIN) return Branding::platform()['name'] . ' support';
        self::ensure();
        $st = Database::pdo()->prepare('SELECT name FROM support_orgs WHERE id = ?');
        $st->execute([$org]);
        return (string) ($st->fetchColumn() ?: 'Support company #' . $org);
    }

    /** Does this account belong to the signed-in company? */
    public static function owns(int $accountId): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT support_org FROM tenants WHERE id = ?');
        $st->execute([$accountId]);
        $org = $st->fetchColumn();
        return $org !== false && (int) $org === self::current();
    }

    /** Stop here unless the account belongs to the signed-in company. */
    public static function guard(int $accountId): void
    {
        if (!self::owns($accountId)) {
            support_flash('That account is looked after by another support company.', 'danger');
            header('Location: ' . public_url('support/accounts/'));
            exit;
        }
    }

    /** The company's account ids (main accounts), for filtering reports. Company #1 also sees signed-out visitors (0). */
    public static function accountIds(): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT id FROM tenants WHERE parent_tenant_id IS NULL AND support_org = ?');
        $st->execute([self::current()]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (self::current() === self::MAIN) $ids[] = 0;
        return $ids ?: [-1];
    }

    /** "IN (…)" list of the company's account ids, safe to put in SQL. */
    public static function inList(): string
    {
        return implode(',', array_map('intval', self::accountIds()));
    }

    /** A new account was created by the signed-in company. */
    public static function claim(int $accountId): void
    {
        self::ensure();
        Database::pdo()->prepare('UPDATE tenants SET support_org = ? WHERE id = ? OR parent_tenant_id = ?')->execute([self::current(), $accountId, $accountId]);
    }

    // ------------------------------------------------------------ companies

    /** Company #1's main admin creates a one-time registration link (valid 7 days). */
    public static function invite(string $note): string
    {
        self::ensure();
        $token = bin2hex(random_bytes(24));
        Database::pdo()->prepare('INSERT INTO support_org_invites (token_hash, note, expires_at) VALUES (?, ?, NOW() + INTERVAL 7 DAY)')
            ->execute([hash('sha256', $token), mb_substr(trim($note), 0, 120) ?: null]);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim((string) BASE_URL, '/') . '/support/register.php?t=' . $token;
    }

    public static function inviteValid(string $token): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT 1 FROM support_org_invites WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
        $st->execute([hash('sha256', $token)]);
        return (bool) $st->fetchColumn();
    }

    /**
     * A main admin creates a separate support company straight away (no link).
     * It is not listed anywhere; its owner signs in at the normal support login.
     * Returns [orgId, error].
     */
    public static function create(string $company, string $email, string $password): array
    {
        self::ensure();
        $token = bin2hex(random_bytes(24)); // a one-off invite, used at once
        Database::pdo()->prepare('INSERT INTO support_org_invites (token_hash, note, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)')
            ->execute([hash('sha256', $token), 'created directly']);
        [$org, $err] = self::register($token, $company, $email, $password);
        if ($org && class_exists('EmailConfirm')) { // confirm the email first; the login details follow
            self::$lastConfirm = EmailConfirm::start('org', $org, strtolower(trim($email)), trim($company), $password, EmailConfirm::absolute('support/login.php'));
        }
        return [$org, $err];
    }

    /** Result of the confirm email sent by create() — ['sent', 'link'], or null. */
    public static ?array $lastConfirm = null;

    /** Register a company with an invite. Returns [orgId, error]. */
    public static function register(string $token, string $company, string $email, string $password): array
    {
        self::ensure();
        $company = trim($company);
        $email = strtolower(trim($email));
        if (!self::inviteValid($token)) return [null, 'This registration link is not valid any more. Ask for a new one.'];
        if ($company === '') return [null, 'Enter the company name.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'Enter a valid email.'];
        if (strlen($password) < 8) return [null, 'The password must be at least 8 characters.'];
        $pdo = Database::pdo();
        $taken = $pdo->prepare('SELECT 1 FROM support_orgs WHERE email = ? UNION SELECT 1 FROM support_agents WHERE email = ?');
        $taken->execute([$email, $email]);
        if ($taken->fetchColumn() || $email === strtolower(SupportAccount::email())) return [null, 'That email already has a support login.'];
        $pdo->prepare('INSERT INTO support_orgs (name, email, password_hash) VALUES (?, ?, ?)')->execute([mb_substr($company, 0, 120), $email, password_hash($password, PASSWORD_DEFAULT)]);
        $org = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE support_org_invites SET used_at = NOW(), org_id = ? WHERE token_hash = ?')->execute([$org, hash('sha256', $token)]);
        return [$org, null];
    }

    /** Main admin of a registered company: ['role' => 'owner', 'org', …] or null. */
    public static function authenticate(string $email, string $password): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM support_orgs WHERE email = ? AND is_active = 1');
        $st->execute([strtolower(trim($email))]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o || !password_verify($password, $o['password_hash'])) return null;
        if (isset($o['email_verified']) && !(int) $o['email_verified']) { SupportAccount::$unconfirmed = true; return null; }
        return ['role' => 'owner', 'id' => 0, 'email' => $o['email'], 'name' => $o['name'], 'org' => (int) $o['id']];
    }

    public static function companies(): array
    {
        self::ensure();
        return Database::pdo()->query('SELECT o.*, (SELECT COUNT(*) FROM tenants t WHERE t.support_org = o.id AND t.parent_tenant_id IS NULL) AS accounts FROM support_orgs o ORDER BY o.id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function invites(): array
    {
        self::ensure();
        return Database::pdo()->query('SELECT * FROM support_org_invites ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    }
}
