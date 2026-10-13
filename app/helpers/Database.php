<?php
// app/helpers/Database.php
// Single source of the PDO connection. Replaces the database.php / db_connect.php
// duplication so there is exactly one place where the connection (and, through the
// base Model, the tenant scope) is established.

class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
        }
        return self::$pdo;
    }

    public static function reconnect(): PDO
    {
        self::$pdo = self::connect();
        return self::$pdo;
    }

    /** For tests: inject a pre-built PDO (e.g. a throwaway test database). */
    public static function setPdo(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /** Path of the single DB config file (DAZU_DB_CONFIG env var overrides, for tests). */
    public static function configPath(): string
    {
        $env = getenv('DAZU_DB_CONFIG');
        return $env ?: ROOT_PATH . '/app/config/database.php';
    }

    public static function config(): array
    {
        $file = self::configPath();
        $cfg = is_file($file) ? require $file : [];
        return is_array($cfg) ? $cfg : [];
    }

    /**
     * Try a set of credentials without touching the shared connection.
     * Returns null on success, or the error message.
     */
    public static function test(array $cfg): ?string
    {
        try {
            self::connectWith($cfg);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Rewrite app/config/database.php — used by the setup wizard so the owner
     * never has to edit files outside public/ on shared hosting.
     */
    public static function saveConfig(array $cfg): bool
    {
        $clean = [
            'host'     => (string) ($cfg['host'] ?? 'localhost'),
            'dbname'   => (string) ($cfg['dbname'] ?? ''),
            'username' => (string) ($cfg['username'] ?? ''),
            'password' => (string) ($cfg['password'] ?? ''),
            'charset'  => 'utf8mb4',
        ];
        $php = "<?php\n// app/config/database.php\n// The ONE database config file for the whole app. Written by the setup wizard\n// on " . date('Y-m-d H:i') . " — you can rerun public/setup/ to change it.\n\nreturn " . var_export($clean, true) . ";\n";
        $ok = @file_put_contents(self::configPath(), $php, LOCK_EX) !== false;
        if ($ok && function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::configPath(), true);
        }
        if ($ok) {
            self::$pdo = null;
        }
        return $ok;
    }

    private static function connect(): PDO
    {
        return self::connectWith(self::config());
    }

    private static function connectWith(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['dbname'],
            $cfg['charset'] ?? 'utf8mb4'
        );

        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // fail loudly
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,                 // real prepared stmts
        ]);
        // Match app.php's date_default_timezone_set('Africa/Nairobi') — a
        // fixed +03:00 offset (Kenya has no DST) so NOW()/CURDATE() in MySQL
        // always agree with PHP's time()/date().
        $pdo->exec("SET time_zone = '+03:00'");

        return $pdo;
    }
}
