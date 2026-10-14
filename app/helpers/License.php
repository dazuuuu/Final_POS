<?php
// app/helpers/License.php
// Account activation keys. Every new shop-owner account gets a key and a
// 2-hour grace period. Support emails the key; the owner (or any of their
// staff) enters it once and the account is activated for good. If the grace
// period runs out first, the whole account — owner, staff and every branch
// shop — is locked to the activation page until the key is entered.
//
// Columns on the account's (top-level) tenants row:
//   license_key           the key support sends (kept so it can be re-sent)
//   license_deadline      end of the grace period; NULL = no lock (accounts
//                         that existed before activation keys)
//   license_activated_at  set once the key is entered; never cleared

class License
{
    const GRACE_SECONDS = 7200;
    const MAX_ATTEMPTS  = 10;   // wrong keys per session before a 15-minute pause

    /** Pages a locked account can still open. */
    const ALLOWED = ['/auth/', '/setup/', '/support/'];
    /** Areas a locked account is kept out of. */
    const LOCKED_AREAS = ['/super/', '/staff/', '/api/', '/profile/'];

    /** @return array{status:string, deadline:?string, seconds_left:int, activated_at:?string, key:?string} */
    public static function state(int $accountId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT license_key, license_deadline, license_activated_at FROM tenants WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $deadline = $row['license_deadline'] ?? null;
        $left = $deadline ? max(0, strtotime($deadline) - time()) : 0;

        if (!empty($row['license_activated_at']) || !$deadline) {
            $status = 'active';
        } else {
            $status = $left > 0 ? 'grace' : 'locked';
        }
        return [
            'status'       => $status,
            'deadline'     => $deadline,
            'seconds_left' => $left,
            'activated_at' => $row['license_activated_at'] ?? null,
            'key'          => $row['license_key'] ?? null,
        ];
    }

    public static function forTenant(int $tenantId): array
    {
        return self::state(Features::accountId($tenantId));
    }

    /**
     * Give an unactivated account a fresh key. A new account's 2 hours start
     * now; an account already in its grace period keeps its deadline.
     */
    public static function issue(int $accountId): string
    {
        $key = self::generateKey();
        Database::pdo()->prepare(
            'UPDATE tenants
                SET license_key = ?,
                    license_deadline = COALESCE(license_deadline, DATE_ADD(NOW(), INTERVAL ' . self::GRACE_SECONDS . ' SECOND))
              WHERE id = ? AND license_activated_at IS NULL'
        )->execute([$key, $accountId]);
        return $key;
    }

    /** Enter a key. True when it matches (the account is then active for good). */
    public static function activate(int $accountId, string $key): bool
    {
        $state = self::state($accountId);
        if ($state['status'] === 'active') {
            return true;
        }
        if (!$state['key'] || !hash_equals(self::normalize($state['key']), self::normalize($key))) {
            return false;
        }
        Database::pdo()->prepare(
            'UPDATE tenants SET license_activated_at = NOW() WHERE id = ? AND license_activated_at IS NULL'
        )->execute([$accountId]);
        return true;
    }

    /**
     * True when the owner may press "Request activation key": the account was
     * claimed through a signup link, isn't activated, and hasn't requested yet.
     */
    public static function canRequestKey(int $accountId): bool
    {
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT signup_completed_at IS NOT NULL AND license_requested_at IS NULL AND license_activated_at IS NULL
                   FROM tenants WHERE id = ?'
            );
            $stmt->execute([$accountId]);
            return (bool) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            return false; // columns not added yet
        }
    }

    /**
     * One-time self-service key request: issue a key and email it to the
     * account owner. Returns null on success, else the reason it failed.
     */
    public static function requestKey(int $accountId): ?string
    {
        if (!self::canRequestKey($accountId)) {
            return 'An activation key has already been requested for this account.';
        }
        $stmt = Database::pdo()->prepare(
            'SELECT u.email FROM tenants t JOIN users u ON u.id = t.owner_user_id WHERE t.id = ?'
        );
        $stmt->execute([$accountId]);
        $email = (string) $stmt->fetchColumn();
        if ($email === '') {
            return 'This account has no owner email.';
        }
        self::issue($accountId);
        $err = self::emailKey($accountId, $email);
        if ($err === null) {
            Database::pdo()->prepare('UPDATE tenants SET license_requested_at = NOW() WHERE id = ?')->execute([$accountId]);
        }
        return $err;
    }

    /** Support override: activate without a key. */
    public static function forceActivate(int $accountId): void
    {
        Database::pdo()->prepare(
            'UPDATE tenants SET license_activated_at = NOW() WHERE id = ? AND license_activated_at IS NULL'
        )->execute([$accountId]);
    }

    /**
     * Support "waive activation": the account never locks (no key) and every
     * owner/staff login on it (main shop + branches) is switched on and marked
     * email-verified, so they can sign in straight away. Logins renamed by a
     * take-over (…_old<ID>) stay off. Returns how many logins were switched on.
     */
    public static function waive(int $accountId): int
    {
        self::forceActivate($accountId);
        $st = Database::pdo()->prepare(
            "UPDATE users u JOIN tenants t ON t.id = u.tenant_id
                SET u.is_active = 1, u.email_verified = 1, u.activated_at = COALESCE(u.activated_at, NOW()),
                    u.activation_token = NULL, u.activation_expires = NULL
              WHERE (t.id = ? OR t.parent_tenant_id = ?) AND (u.is_active = 0 OR u.email_verified = 0)
                AND u.username NOT REGEXP '_old[0-9]+$'"
        );
        $st->execute([$accountId, $accountId]);
        return $st->rowCount();
    }

    /** Switch one login on and mark it verified (support). */
    public static function activateUser(int $userId): void
    {
        Database::pdo()->prepare(
            'UPDATE users SET is_active = 1, email_verified = 1, activated_at = COALESCE(activated_at, NOW()),
                    activation_token = NULL, activation_expires = NULL WHERE id = ?'
        )->execute([$userId]);
    }

    /** Email the account's key. Returns null on success, else the reason it failed. */
    public static function emailKey(int $accountId, string $to): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT name, license_key FROM tenants WHERE id = ?');
        $stmt->execute([$accountId]);
        $acct = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acct || !$acct['license_key']) {
            return 'This account has no activation key yet.';
        }
        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $url = public_url('auth/activate-account.php');
        $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#18212f">'
            . '<h2 style="margin:0 0 12px">Activate ' . $h($acct['name']) . '</h2>'
            . '<p>Here is the activation key for your account:</p>'
            . '<p style="font-size:24px;font-weight:bold;letter-spacing:2px;background:#f1f5f9;padding:14px;text-align:center;border-radius:8px">'
            . $h($acct['license_key']) . '</p>'
            . '<p>Sign in and enter it on your dashboard (or at <a href="' . $h($url) . '">' . $h($url) . '</a>). '
            . 'You only need to do this once.</p></div>';
        $ok = (new MailService())->send($to, 'Your activation key — ' . $acct['name'], $html,
            "Your activation key: {$acct['license_key']}\nEnter it at $url");
        return $ok ? null : (MailService::lastError() ?: 'The email could not be sent.');
    }

    /**
     * Called from app.php on every request. Sends a locked account's users to
     * the activation page (or a JSON 423 for API/AJAX calls).
     */
    public static function gate(): void
    {
        $tenantId = TenantContext::tenantId();
        if ($tenantId === null || TenantContext::isPlatformAdmin()) {
            return;
        }
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        foreach (self::ALLOWED as $ok) {
            if (strpos($script, $ok) !== false) {
                return;
            }
        }
        $inArea = false;
        foreach (self::LOCKED_AREAS as $area) {
            if (strpos($script, $area) !== false) {
                $inArea = true;
                break;
            }
        }
        if (!$inArea) {
            return;
        }
        try {
            $state = self::forTenant($tenantId);
        } catch (\PDOException $e) {
            return; // schema not upgraded yet — setup's "Update database" adds the columns
        }
        if ($state['status'] !== 'locked') {
            return;
        }

        $wantsJson = strpos($script, '/api/') !== false
            || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
            || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
        if ($wantsJson) {
            http_response_code(423);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'locked' => true, 'message' => 'This account must be activated. Enter your activation key to continue.']);
            exit;
        }
        header('Location: ' . public_url('auth/activate-account.php'));
        exit;
    }

    /** Where to send the user once the account is active. */
    public static function homeUrl(): string
    {
        return public_url(TenantContext::role() === 'tenant_owner' ? 'super/dashboard/' : 'staff/dashboard/');
    }

    /** Wrong-key throttle (per session). Returns seconds to wait, 0 if allowed. */
    public static function throttle(bool $failed = false): int
    {
        $t = $_SESSION['license_attempts'] ?? ['n' => 0, 'until' => 0];
        if ($t['until'] && $t['until'] <= time()) {
            $t = ['n' => 0, 'until' => 0];
        }
        if ($failed && ++$t['n'] >= self::MAX_ATTEMPTS) {
            $t = ['n' => 0, 'until' => time() + 900];
        }
        $_SESSION['license_attempts'] = $t;
        return max(0, $t['until'] - time());
    }

    public static function normalize(string $key): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key));
    }

    private static function generateKey(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return implode('-', str_split($out, 4));
    }
}
