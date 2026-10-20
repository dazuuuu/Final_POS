<?php
// app/helpers/Database.php
// Single source of the PDO connection. Replaces the database.php / db_connect.php
// duplication so there is exactly one place where the connection (and, through the
// base Model, the tenant scope) is established.

class Database
{
    /** The collation every table, column and connection uses. */
    const COLLATION = 'utf8mb4_general_ci';

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

    /**
     * Path of a "this schema change is done" marker file for THIS database.
     * The database name is part of the file name, so a marker copied from
     * another install (e.g. app/storage uploaded from a laptop) never makes
     * the live database skip a change it hasn't had.
     */
    public static function marker(string $name): string
    {
        static $db = null;
        $db ??= preg_replace('/\W/', '_', (string) self::pdo()->query('SELECT DATABASE()')->fetchColumn());
        return ROOT_PATH . '/app/storage/' . $name . '_' . $db . '.ok';
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
     * Is the app ready to run? Returns ['state' => 'ok' | 'server_down' | 'needs_setup', 'error' => string].
     *   needs_setup  no config / no database name, wrong credentials, unknown
     *                database, or the tables aren't installed → setup wizard.
     *   server_down  config is fine but the MySQL server can't be reached
     *                (stopped, wrong socket/port) → setup can't fix that, so
     *                the app shows a "start your database" page instead.
     */
    public static function status(): array
    {
        $cfg = self::config();
        if (empty($cfg['host']) || empty($cfg['dbname'])) {
            return ['state' => 'needs_setup', 'error' => 'No database is configured yet.'];
        }
        // Only a database that is truly not set up goes to the setup wizard: wrong
        // login (1045), no such database (1049) or no tables. Anything else —
        // too many connections (1040 / 1203 on shared hosting), server gone away,
        // a timeout — is a passing hiccup: the user gets a calm "reconnecting"
        // page that retries by itself, never the wizard (which shows SQL).
        $attempt = function () {
            $n = (int) self::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('roles', 'users', 'tenants')"
            )->fetchColumn();
            return $n === 3 ? ['state' => 'ok', 'error' => ''] : ['state' => 'needs_setup', 'error' => 'The database has no tables yet.'];
        };
        for ($try = 1; ; $try++) {
            try {
                return $attempt();
            } catch (\Throwable $e) {
                self::$pdo = null; // drop a half-open connection before retrying
                $code = preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) ? (int) $m[1] : (int) $e->getCode();
                if (in_array($code, [1045, 1049], true)) {
                    return ['state' => 'needs_setup', 'error' => $e->getMessage()];
                }
                if ($try >= 3) {
                    error_log('Database::status: ' . $e->getMessage());
                    return ['state' => 'server_down', 'error' => $e->getMessage()];
                }
                usleep(300000 * $try); // brief wait: connection limits usually clear in well under a second
            }
        }
    }

    /**
     * Convert the database default and every table / text column that isn't on
     * COLLATION. Tables the app creates at runtime (CREATE TABLE IF NOT EXISTS)
     * take the server's default, which differs between hosts. Returns the
     * converted table names. Safe to re-run.
     */
    public static function normalizeCollation(?PDO $pdo = null): array
    {
        $pdo ??= self::pdo();
        $c = self::COLLATION;
        $tables = $pdo->query(
            "SELECT DISTINCT t.table_name FROM information_schema.tables t
               LEFT JOIN information_schema.columns c
                      ON c.table_schema = t.table_schema AND c.table_name = t.table_name
                     AND c.collation_name IS NOT NULL AND c.collation_name <> '$c'
              WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
                AND (t.table_collation <> '$c' OR c.column_name IS NOT NULL)"
        )->fetchAll(PDO::FETCH_COLUMN);
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->exec("ALTER DATABASE `$db` CHARACTER SET utf8mb4 COLLATE $c"); // new runtime tables follow
            foreach ($tables as $t) {
                $pdo->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE $c");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        return $tables;
    }

    /**
     * Run normalizeCollation() once per database (a marker file in
     * app/storage remembers it). Called from app.php; errors are logged, never shown.
     */
    public static function ensureCollation(): void
    {
        try {
            $db = (string) self::pdo()->query('SELECT DATABASE()')->fetchColumn();
            $flag = ROOT_PATH . '/app/storage/collation_' . preg_replace('/\W/', '_', $db) . '.ok';
            if (is_file($flag)) {
                return;
            }
            self::normalizeCollation();
            @file_put_contents($flag, date('c'));
        } catch (\Throwable $e) {
            error_log('Database::ensureCollation: ' . $e->getMessage());
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
        // One collation everywhere (full_schema.sql's). Without this, text from a
        // table the server created with its own default (unicode_ci, 0900_ai_ci…)
        // can't be compared with ours: "Illegal mix of collations".
        $pdo->exec("SET NAMES utf8mb4 COLLATE " . self::COLLATION);

        return $pdo;
    }
}
