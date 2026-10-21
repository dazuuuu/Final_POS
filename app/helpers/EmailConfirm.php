<?php
// app/helpers/EmailConfirm.php
// "Confirm your email" for logins that support (or an owner) creates for
// someone else: shop owners, admins and support team members.
//   1. start()    — the login is held (can't sign in), a "Confirm your email"
//                   email goes out with a link (valid 7 days)
//   2. confirm()  — the link opens auth/confirm-email.php: the login is
//                   switched on and a second email brings the login details
//                   (email / username / password and where to sign in)
// The password typed by whoever created the login is kept only until then —
// encrypted (AES-256-GCM, app/config/secret.php) — and deleted on confirm.
//
//   email_confirmations   kind (user | agent), ref_id, email, name, token hash,
//                         encrypted password, login URL, expires / confirmed

class EmailConfirm
{
    const DAYS = 7;

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $pdo = Database::pdo();
        $flag = Database::marker('email_confirm_v1');
        if (!is_file($flag)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS email_confirmations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kind ENUM('user','agent') NOT NULL,
                ref_id INT NOT NULL,
                account_id INT NULL,
                email VARCHAR(150) NOT NULL,
                name VARCHAR(120) NULL,
                token_hash CHAR(64) NOT NULL,
                password_enc VARCHAR(500) NULL,
                login_url VARCHAR(255) NULL,
                sent_at DATETIME NULL,
                expires_at DATETIME NOT NULL,
                confirmed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_confirm_token (token_hash), KEY idx_confirm_ref (kind, ref_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'support_agents'")->fetchColumn();
            if ($has && !(int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'support_agents' AND column_name = 'email_verified'")->fetchColumn()) {
                $pdo->exec('ALTER TABLE support_agents ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1'); // existing members stay signed-in-able
            }
            @file_put_contents($flag, date('c'));
        }
        $done = true;
    }

    // ---------------------------------------------------------- crypto

    private static function key(): string
    {
        $file = ROOT_PATH . '/app/config/secret.php';
        $cfg = is_file($file) ? (include $file) : null;
        if (!is_array($cfg) || empty($cfg['key'])) {
            $cfg = ['key' => base64_encode(random_bytes(32))];
            @file_put_contents($file, "<?php\n// app/config/secret.php — this install's encryption secret. Created automatically; never share or reuse it.\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX);
        }
        return hash('sha256', 'dz-confirm|' . $cfg['key'], true);
    }

    private static function seal(string $plain): ?string
    {
        if (!function_exists('openssl_encrypt')) return null;
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $ct === false ? null : base64_encode($iv . $tag . $ct);
    }

    private static function open(?string $sealed): ?string
    {
        if (!$sealed || !function_exists('openssl_decrypt')) return null;
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < 29) return null;
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return is_string($p) ? $p : null;
    }

    // ---------------------------------------------------------- flow

    /**
     * Hold a login until its email is confirmed, and send "Confirm your email".
     * $kind 'user' (users.id) or 'agent' (support_agents.id). $password = the one that was typed (sent after confirm).
     * Returns ['sent' => bool, 'link' => confirm URL (so support can pass it on if email isn't set up)].
     */
    public static function start(string $kind, int $refId, string $email, string $name, ?string $password, string $loginUrl, ?int $account = null): array
    {
        self::ensure();
        $pdo = Database::pdo();
        if ($kind === 'user') {
            $pdo->prepare('UPDATE users SET email_verified = 0 WHERE id = ?')->execute([$refId]);
        } else {
            $pdo->prepare('UPDATE support_agents SET email_verified = 0 WHERE id = ?')->execute([$refId]);
        }
        $pdo->prepare('DELETE FROM email_confirmations WHERE kind = ? AND ref_id = ? AND confirmed_at IS NULL')->execute([$kind, $refId]);
        $token = bin2hex(random_bytes(24));
        $pdo->prepare('INSERT INTO email_confirmations (kind, ref_id, account_id, email, name, token_hash, password_enc, login_url, expires_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ' . self::DAYS . ' DAY)')
            ->execute([$kind, $refId, $account, strtolower($email), mb_substr($name, 0, 120), hash('sha256', $token),
                       $password !== null && $password !== '' ? self::seal($password) : null, $loginUrl]);
        $link = self::absolute('auth/confirm-email.php?t=' . $token);
        $sent = (new MailService())->send($email, 'Confirm your email — ' . Branding::platform()['name'], self::confirmEmail($name, $link),
            "Hi $name,\n\nPlease confirm your email address to switch on your login:\n$link\n\nThanks,\nThe " . Branding::platform()['name'] . ' team');
        if ($sent) $pdo->prepare('UPDATE email_confirmations SET sent_at = NOW() WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        return ['sent' => $sent, 'link' => $link];
    }

    /** The link was opened. Returns ['ok' => bool, 'reason', 'login_url', 'email'] and sends the login details. */
    public static function confirm(string $token): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM email_confirmations WHERE token_hash = ?');
        $st->execute([hash('sha256', $token)]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return ['ok' => false, 'reason' => 'invalid'];
        if ($c['confirmed_at']) return ['ok' => true, 'reason' => 'already', 'login_url' => $c['login_url'], 'email' => $c['email']];
        if (strtotime($c['expires_at']) < time()) return ['ok' => false, 'reason' => 'expired', 'email' => $c['email']];

        if ($c['kind'] === 'user') {
            $pdo->prepare('UPDATE users SET email_verified = 1, is_active = 1, activated_at = COALESCE(activated_at, NOW()) WHERE id = ?')->execute([(int) $c['ref_id']]);
            $u = $pdo->prepare('SELECT username, email FROM users WHERE id = ?');
        } else {
            $pdo->prepare('UPDATE support_agents SET email_verified = 1 WHERE id = ?')->execute([(int) $c['ref_id']]);
            $u = $pdo->prepare('SELECT email AS username, email FROM support_agents WHERE id = ?');
        }
        $u->execute([(int) $c['ref_id']]);
        $who = $u->fetch(PDO::FETCH_ASSOC) ?: ['username' => $c['email'], 'email' => $c['email']];
        $password = self::open($c['password_enc']);
        $pdo->prepare('UPDATE email_confirmations SET confirmed_at = NOW(), password_enc = NULL WHERE id = ?')->execute([(int) $c['id']]);

        (new MailService())->send($who['email'], 'Your login details — ' . Branding::platform()['name'],
            self::credentialsEmail((string) $c['name'], $who['username'], $who['email'], $password, (string) $c['login_url']),
            "Hi {$c['name']},\n\nYour email is confirmed. Sign in at {$c['login_url']}\nEmail: {$who['email']}\n"
            . ($who['username'] !== $who['email'] ? "Username: {$who['username']}\n" : '') . ($password ? "Password: $password\n" : '') . "\nChange your password after you sign in.");
        return ['ok' => true, 'reason' => 'confirmed', 'login_url' => $c['login_url'], 'email' => $who['email']];
    }

    /** Send the confirm email again for a login that hasn't confirmed yet (from the sign-in page). */
    public static function resend(string $email): bool
    {
        self::ensure();
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM email_confirmations WHERE email = ? AND confirmed_at IS NULL ORDER BY id DESC LIMIT 1');
        $st->execute([strtolower(trim($email))]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c || ($c['sent_at'] && strtotime($c['sent_at']) > time() - 120)) return false; // at most once every 2 minutes
        $token = bin2hex(random_bytes(24));
        $pdo->prepare('UPDATE email_confirmations SET token_hash = ?, expires_at = NOW() + INTERVAL ' . self::DAYS . ' DAY, sent_at = NOW() WHERE id = ?')
            ->execute([hash('sha256', $token), (int) $c['id']]);
        $link = self::absolute('auth/confirm-email.php?t=' . $token);
        return (new MailService())->send($c['email'], 'Confirm your email — ' . Branding::platform()['name'], self::confirmEmail((string) $c['name'], $link), "Confirm your email: $link");
    }

    /** Is a confirmation waiting for this login? (to explain why sign-in is refused) */
    public static function waiting(string $kind, int $refId): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT 1 FROM email_confirmations WHERE kind = ? AND ref_id = ? AND confirmed_at IS NULL LIMIT 1');
        $st->execute([$kind, $refId]);
        return (bool) $st->fetchColumn();
    }

    // ---------------------------------------------------------- templates

    public static function absolute(string $path): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
        $base = rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/');
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/' . ltrim($path, '/');
    }

    /** The shared email look: logo, picture, title, text, green button, sign-off, dark footer. */
    public static function layout(string $title, string $picture, string $bodyHtml, ?array $button = null, string $signoff = ''): string
    {
        $b = Branding::platform();
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $logo = preg_match('#^https?://#', $b['logo']) ? $b['logo'] : self::absolute($b['logo']);
        $img = self::absolute('assets/img/' . $picture);
        $btn = $button ? '<tr><td align="center" style="padding:30px 0 6px"><a href="' . $e($button[1]) . '" style="display:inline-block;background:#1f9a5f;color:#ffffff;text-decoration:none;font-weight:700;font-size:16px;padding:15px 30px;border-radius:10px">' . $e($button[0]) . '</a></td></tr>'
            . '<tr><td align="center" style="padding:10px 30px 0;font-size:12px;color:#8a8f98;line-height:1.5">Button not working? Paste this link into your browser:<br><a href="' . $e($button[1]) . '" style="color:#1f9a5f;word-break:break-all">' . $e($button[1]) . '</a></td></tr>' : '';
        return '<!doctype html><html><body style="margin:0;padding:0;background:#e7f3ec">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e7f3ec;font-family:Poppins,-apple-system,Segoe UI,Roboto,Arial,sans-serif"><tr><td align="center" style="padding:28px 14px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
            . '<tr><td style="background:#ffffff;border-radius:16px;padding:36px 28px 34px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td align="center"><img src="' . $e($logo) . '" alt="' . $e($b['name']) . '" height="48" style="height:48px;border:0;display:inline-block;vertical-align:middle"> <span style="font-size:18px;font-weight:700;color:#1f9a5f;vertical-align:middle;margin-left:6px">' . $e($b['name']) . '</span></td></tr>'
            . '<tr><td align="center" style="padding:30px 0 6px"><img src="' . $e($img) . '" alt="" width="210" style="width:210px;max-width:70%;height:auto;border:0"></td></tr>'
            . '<tr><td align="center" style="padding:18px 10px 0;font-size:34px;line-height:1.15;font-weight:700;color:#2b2b2b">' . $e($title) . '</td></tr>'
            . '<tr><td align="center" style="padding:24px 26px 0;font-size:16px;line-height:1.7;color:#333333">' . $bodyHtml . '</td></tr>'
            . $btn
            . ($signoff !== '' ? '<tr><td align="center" style="padding:30px 0 0;font-size:14px;color:#8a8f98;line-height:1.6">' . $signoff . '</td></tr>' : '')
            . '</table></td></tr>'
            . '<tr><td style="height:10px"></td></tr>'
            . '<tr><td style="background:#18191b;border-radius:16px;padding:22px 28px;color:#ffffff;font-size:15px;font-weight:600">' . $e($b['name'])
            . '<span style="float:right;font-weight:400;font-size:12px;color:#9aa0a6;line-height:22px">Point of sale for your business</span></td></tr>'
            . '<tr><td align="center" style="padding:16px 0 0;font-size:12px;color:#7d8a83">You get this email because an account was created for you.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function confirmEmail(string $name, string $link): string
    {
        $b = Branding::platform();
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        return self::layout('Confirm your email', 'email-confirm.png',
            '<div style="font-size:21px;font-weight:700;color:#2b2b2b;margin-bottom:10px">Hi ' . $e($name ?: 'there') . '!</div>'
            . 'An account was created for you on ' . $e($b['name']) . '.<br>Please confirm your email address by clicking the button below — then we send you your login details.',
            ['Confirm email address', $link], 'Thanks,<br>The ' . $e($b['name']) . ' Team');
    }

    private static function credentialsEmail(string $name, string $username, string $email, ?string $password, string $loginUrl): string
    {
        $b = Branding::platform();
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $row = fn($k, $v) => '<tr><td style="padding:9px 14px;color:#6b7280;font-size:14px;text-align:left;border-bottom:1px solid #eef0f2">' . $k . '</td><td style="padding:9px 14px;font-size:15px;font-weight:600;color:#111;text-align:left;border-bottom:1px solid #eef0f2;font-family:Menlo,Consolas,monospace">' . $e($v) . '</td></tr>';
        $table = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px auto 0;border:1px solid #e5e7eb;border-radius:10px;border-collapse:separate;min-width:280px">'
            . $row('Email', $email) . ($username !== $email ? $row('Username', $username) : '') . ($password ? $row('Password', $password) : '') . '</table>';
        return self::layout('You\'re all set!', 'email-done.png',
            '<div style="font-size:21px;font-weight:700;color:#2b2b2b;margin-bottom:10px">Hi ' . $e($name ?: 'there') . ',</div>'
            . 'Your email is confirmed and your login is ready. Here are your details:' . $table
            . ($password ? '<div style="font-size:13px;color:#8a8f98;margin-top:14px">For your safety, change this password after you sign in.</div>' : ''),
            ['Sign in', $loginUrl], 'Best regards,<br>The ' . $e($b['name']) . ' Team');
    }
}
