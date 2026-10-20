<?php
// app/helpers/Signup.php
// Invite-by-business-name signup. Support creates an account with only the
// business name (no owner yet). The owner opens the ONE shared signup page
// (/auth/signup.php, or their shop URL domain/<slug>), types the business
// name, and if that shop exists and has no owner yet creates the owner
// login. One owner per shop: once claimed, the name can't be signed up again.
// Then they import their old data (optional), get their POS URL
// (domain/<slug>) and install it.
//
// Columns on tenants:
//   signup_completed_at   when the owner signed up
//   license_requested_at  when the owner requested their activation key

class Signup
{
    const MAX_ATTEMPTS = 8; // wrong business names per session before a 15-minute pause

    /** Paths that are real folders/pages, so a shop can't take them as its URL. */
    const RESERVED = ['admin', 'api', 'assets', 'auth', 'components', 'm', 'profile', 'setup', 'staff', 'super',
                      'support', 'templates', 'uploads', 'verification', 'public', 'signup', 'shop', 'index',
                      'manifest', 'sw', 'catalogue', 'login', 'app', 'terminal', 'demo', 'pricing'];

    /** Create an invited account (business name + packages, no owner). Returns the account id. */
    public static function invite(string $businessName, array $packages, string $phone = ''): int
    {
        $pdo = Database::pdo();
        $T = new Models\TenantModel($pdo);
        $slug = $T->uniqueSlug($businessName);
        if (in_array($slug, self::RESERVED, true)) {
            $slug = $T->uniqueSlug($slug . '-shop');
        }
        $id = $T->create($businessName, $slug);
        $pdo->prepare('UPDATE tenants SET phone = NULLIF(?, \'\') WHERE id = ?')->execute([$phone, $id]);
        Features::setForAccount($id, $packages);
        return $id;
    }

    /**
     * Databases created before signup/import existed lack these tenant
     * columns (setup's "Update database" adds them too) — add them on first use.
     */
    public static function ensureColumns(): void
    {
        static $done = false;
        if ($done) { return; }
        $pdo = Database::pdo();
        $have = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tenants'")
                    ->fetchAll(PDO::FETCH_COLUMN);
        foreach (['license_requested_at', 'signup_completed_at', 'imported_at'] as $col) {
            if (!in_array($col, $have, true)) {
                $pdo->exec("ALTER TABLE tenants ADD COLUMN `$col` DATETIME DEFAULT NULL");
            }
        }
        $done = true;
    }

    /** Is the public signup page open? Toggled from the support Overview. */
    public static function enabled(): bool
    {
        $file = ROOT_PATH . '/app/config/signup.php';
        $cfg = is_file($file) ? (include $file) : [];
        return !empty($cfg['enabled']);
    }

    /** Open/close the signup page. Returns false if the config file can't be written. */
    public static function setEnabled(bool $on): bool
    {
        $file = ROOT_PATH . '/app/config/signup.php';
        $php = "<?php\n// app/config/signup.php — public signup page on/off. Edited from Support → Overview on " . date('Y-m-d H:i') . ".\n\nreturn " . var_export(['enabled' => $on], true) . ";\n";
        if (@file_put_contents($file, $php, LOCK_EX) === false) {
            return false;
        }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
        return true;
    }

    /** Email the owner of a new shop their signup link. Returns null on success, else why it failed. */
    public static function emailInvite(int $accountId, string $to): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT name, slug FROM tenants WHERE id = ?');
        $stmt->execute([$accountId]);
        $acct = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acct) return 'Shop not found.';
        $link = self::signupUrl() . '?shop=' . rawurlencode($acct['slug']);
        $name = htmlspecialchars($acct['name']);
        $html = '<p>Hello,</p><p>Your shop <strong>' . $name . '</strong> is ready to set up.</p>'
              . '<p><a href="' . htmlspecialchars($link) . '" style="display:inline-block;padding:10px 18px;background:#16a34a;color:#fff;border-radius:8px;text-decoration:none">Create your account</a></p>'
              . '<p>Or open this link: ' . htmlspecialchars($link) . '</p>'
              . '<p>On the signup page, confirm your business name <strong>' . $name . '</strong>, create your login, then import your old data if you have any.</p>';
        $text = "Your shop {$acct['name']} is ready to set up.\nCreate your account: $link\nConfirm your business name, create your login, then import your old data if you have any.";
        return (new MailService())->send($to, 'Set up your shop — ' . $acct['name'], $html, $text)
            ? null : (MailService::lastError() ?: 'Could not send the email.');
    }

    /** True when another account already uses this business name. */
    public static function nameTaken(string $businessName): bool
    {
        return self::findByName($businessName, false) !== null;
    }

    /** The shared signup page. */
    public static function signupUrl(): string
    {
        return self::origin() . public_url('auth/signup.php');
    }

    /** A shop's own address: https://domain/<slug> (the POS, or its signup while unclaimed). */
    public static function shopUrl(string $slug): string
    {
        $base = rtrim(defined('BASE_URL') ? (string) BASE_URL : '', '/');
        if (substr($base, -7) === '/public') {
            $base = substr($base, 0, -7); // root .htaccess forwards /<slug> into public/
        }
        return self::origin() . $base . '/' . rawurlencode($slug);
    }

    private static function origin(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /**
     * Top-level account whose name matches (ignoring case/spacing/punctuation).
     * $unclaimedOnly: only accounts still waiting for their owner.
     */
    public static function findByName(string $typed, bool $unclaimedOnly = true): ?array
    {
        $rows = Database::pdo()->query(
            'SELECT * FROM tenants WHERE parent_tenant_id IS NULL' . ($unclaimedOnly ? ' AND owner_user_id IS NULL' : '')
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (self::nameMatches($r['name'], $typed)) {
                return $r;
            }
        }
        return null;
    }

    public static function findBySlug(string $slug): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM tenants WHERE slug = ? AND parent_tenant_id IS NULL LIMIT 1');
        $stmt->execute([$slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Business names match ignoring case, spacing and punctuation. */
    public static function nameMatches(string $registered, string $typed): bool
    {
        $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower($s));
        return $norm($typed) !== '' && hash_equals($norm($registered), $norm($typed));
    }

    /**
     * Create the owner for an invited account. Returns [userId, errors].
     * Self-signup needs no activation key: the account is active straight away.
     */
    public static function complete(int $accountId, string $username, string $email, string $password): array
    {
        $pdo = Database::pdo();
        self::ensureColumns();
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) $errors[] = 'Username must be 3–50 letters, numbers, dots, dashes or underscores.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if (!$errors) {
            $chk = $pdo->prepare('SELECT SUM(email = ?), SUM(username = ?) FROM users');
            $chk->execute([$email, $username]);
            [$emailTaken, $userTaken] = $chk->fetch(PDO::FETCH_NUM);
            if ($emailTaken) $errors[] = 'That email already has an account. Sign in instead.';
            if ($userTaken) $errors[] = 'That username is taken.';
        }
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'tenant_owner' LIMIT 1")->fetchColumn();
        if (!$roleId) $errors[] = 'The account cannot be set up right now. Please contact support.';
        if ($errors) {
            return [null, $errors];
        }

        $pdo->beginTransaction();
        try {
            // Lock the invite so two people can't claim it at once.
            $lock = $pdo->prepare('SELECT owner_user_id FROM tenants WHERE id = ? FOR UPDATE');
            $lock->execute([$accountId]);
            if ($lock->fetchColumn()) {
                throw new RuntimeException('This shop already has an owner account. Sign in instead.');
            }
            $pdo->prepare(
                'INSERT INTO users (tenant_id, username, email, password_hash, role_id, is_active, email_verified, activated_at)
                 VALUES (?, ?, ?, ?, ?, 1, 1, NOW())'
            )->execute([$accountId, $username, $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
            $userId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'UPDATE tenants
                    SET owner_user_id = ?, signup_completed_at = NOW(),
                        license_activated_at = COALESCE(license_activated_at, NOW())
                  WHERE id = ?'
            )->execute([$userId, $accountId]);
            $pdo->commit();
            return [$userId, []];
        } catch (Throwable $e) {
            $pdo->rollBack();
            return [null, [$e instanceof RuntimeException ? $e->getMessage() : 'Could not create your account: ' . $e->getMessage()]];
        }
    }

    /** Wrong-name throttle (per session). Returns seconds to wait, 0 if allowed. */
    public static function throttle(bool $failed = false): int
    {
        $t = $_SESSION['signup_attempts'] ?? ['n' => 0, 'until' => 0];
        if ($t['until'] && $t['until'] <= time()) {
            $t = ['n' => 0, 'until' => 0];
        }
        if ($failed && ++$t['n'] >= self::MAX_ATTEMPTS) {
            $t = ['n' => 0, 'until' => time() + 900];
        }
        $_SESSION['signup_attempts'] = $t;
        return max(0, $t['until'] - time());
    }
}
