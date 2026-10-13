<?php
// app/helpers/SupportAccount.php
// The Support console login (platform vendor, not a shop user). Written by the
// setup wizard to app/config/support.php — a file, not a DB row, so support can
// still sign in (and fix the database settings) while the database is down.

class SupportAccount
{
    public static function configPath(): string
    {
        return ROOT_PATH . '/app/config/support.php';
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
}
