<?php
// app/helpers/Telemetry.php
// Technical usage per account, for the support console: how many requests
// each account makes, data received / sent, CPU and response time, and
// errors. It never reads business data (sales, amounts, customers) — only
// the size and timing of requests. Paths are stored without query strings.
//
//   sys_traffic  one row per account per hour: requests, bytes in/out,
//                cpu ms, response ms, errors
//   sys_errors   one row per failed request (5xx or a PHP error): time,
//                account, path, status, message
// Rows older than 30 days are pruned. Support pages themselves aren't counted.

class Telemetry
{
    private static float $t0 = 0.0;
    private static array $ru = [];
    private static int $out = 0;
    private static ?string $error = null;
    private static bool $on = false;

    /** Called at the very start of app.php for web requests. */
    public static function start(): void
    {
        if (PHP_SAPI === 'cli' || self::$on) { return; }
        if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/support/') !== false) { return; } // the console itself
        self::$on = true;
        self::$t0 = microtime(true);
        self::$ru = function_exists('getrusage') ? (getrusage() ?: []) : [];
        ob_start(function ($buf, $phase) {
            if (!($phase & PHP_OUTPUT_HANDLER_CLEAN)) { self::$out += strlen($buf); }
            return $buf;
        }, 65536);
        register_shutdown_function([self::class, 'finish']);
    }

    /** Record an error for this request (message only — no stack, no request data). */
    public static function error(string $message): void
    {
        self::$error = mb_substr($message, 0, 500);
    }

    public static function finish(): void
    {
        if (!self::$on) { return; }
        self::$on = false;
        while (ob_get_level() > 0 && @ob_end_flush()) {} // count the last of the output
        $last = error_get_last();
        if (!self::$error && $last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            self::$error = mb_substr($last['message'] . ' (' . basename($last['file']) . ':' . $last['line'] . ')', 0, 500);
        }
        $status = (int) (http_response_code() ?: 200);
        $isError = self::$error !== null || $status >= 500;
        $ms = (int) round((microtime(true) - self::$t0) * 1000);
        $cpu = 0;
        if (self::$ru && ($ru = getrusage())) {
            $tot = fn($r) => ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) * 1000 + ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1000;
            $cpu = (int) max(0, round($tot($ru) - $tot(self::$ru)));
        }
        $in = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) + strlen((string) ($_SERVER['REQUEST_URI'] ?? ''));
        try {
            $account = 0; // 0 = signed-out visitors (login pages, public pages)
            $tid = class_exists('TenantContext', false) ? TenantContext::tenantId() : null;
            if ($tid) { $account = Features::accountId((int) $tid); }
            $pdo = Database::pdo();
            self::ensure($pdo);
            $pdo->prepare(
                'INSERT INTO sys_traffic (hour, account_id, requests, bytes_in, bytes_out, cpu_ms, wall_ms, errors)
                 VALUES (?, ?, 1, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE requests = requests + 1, bytes_in = bytes_in + VALUES(bytes_in), bytes_out = bytes_out + VALUES(bytes_out),
                   cpu_ms = cpu_ms + VALUES(cpu_ms), wall_ms = wall_ms + VALUES(wall_ms), errors = errors + VALUES(errors),
                   max_ms = GREATEST(max_ms, VALUES(wall_ms))'
            )->execute([date('Y-m-d H:00:00'), $account, $in, self::$out, $cpu, $ms, $isError ? 1 : 0]);
            if ($isError) {
                $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
                $pdo->prepare('INSERT INTO sys_errors (account_id, method, path, status, message) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$account, substr($_SERVER['REQUEST_METHOD'] ?? 'GET', 0, 8), mb_substr($path, 0, 255), $status, self::$error ?? "HTTP $status"]);
            }
            // Server load history (one sample a minute) for the support console's CPU chart.
            if (function_exists('sys_getloadavg') && ($la = @sys_getloadavg())) {
                $pdo->prepare('INSERT IGNORE INTO sys_load (minute, load1, cores) VALUES (?, ?, ?)')
                    ->execute([date('Y-m-d H:i:00'), round((float) $la[0], 2), self::cores()]);
            }
            if (mt_rand(1, 500) === 1) { // occasional clean-up
                $pdo->exec('DELETE FROM sys_load WHERE minute < NOW() - INTERVAL 7 DAY');
                $pdo->exec('DELETE FROM sys_traffic WHERE hour < NOW() - INTERVAL 30 DAY');
                $pdo->exec('DELETE FROM sys_errors WHERE created_at < NOW() - INTERVAL 30 DAY');
            }
        } catch (Throwable $e) {
            // Monitoring must never break a page.
        }
    }

    public static function ensure(PDO $pdo): void
    {
        static $done = false;
        if ($done) { return; }
        $flag = Database::marker('telemetry_v2');
        if (!is_file($flag)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sys_traffic (
                hour DATETIME NOT NULL, account_id INT NOT NULL,
                requests INT NOT NULL DEFAULT 0, bytes_in BIGINT NOT NULL DEFAULT 0, bytes_out BIGINT NOT NULL DEFAULT 0,
                cpu_ms BIGINT NOT NULL DEFAULT 0, wall_ms BIGINT NOT NULL DEFAULT 0, max_ms INT NOT NULL DEFAULT 0, errors INT NOT NULL DEFAULT 0,
                PRIMARY KEY (hour, account_id), KEY idx_traffic_account (account_id, hour)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS sys_errors (
                id INT AUTO_INCREMENT PRIMARY KEY, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                account_id INT NOT NULL DEFAULT 0, method VARCHAR(8) NOT NULL, path VARCHAR(255) NOT NULL,
                status SMALLINT NOT NULL, message VARCHAR(500) NOT NULL, KEY idx_err_time (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS sys_load (
                minute DATETIME NOT NULL PRIMARY KEY, load1 DECIMAL(6,2) NOT NULL, cores SMALLINT NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            @file_put_contents($flag, date('c'));
        }
        $done = true;
    }

    /** CPU cores on this server (cached). */
    public static function cores(): int
    {
        static $n = null;
        if ($n !== null) { return $n; }
        $n = 1;
        if (@is_readable('/proc/cpuinfo')) {
            $n = max(1, substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor'));
        } elseif (PHP_OS_FAMILY === 'Darwin' && function_exists('shell_exec')) {
            $n = max(1, (int) @shell_exec('sysctl -n hw.ncpu'));
        }
        return $n;
    }

    /** CPU % per hour for the last $hours hours (average of the minute samples). */
    public static function loadHistory(PDO $pdo, int $hours = 24): array
    {
        self::ensure($pdo);
        $out = [];
        for ($i = $hours - 1; $i >= 0; $i--) { $out[date('Y-m-d H:00:00', strtotime("-$i hours"))] = null; }
        $st = $pdo->prepare("SELECT DATE_FORMAT(minute, '%Y-%m-%d %H:00:00') h, AVG(load1 / cores) * 100 pct FROM sys_load WHERE minute >= ? GROUP BY h");
        $st->execute([array_key_first($out)]);
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $h => $p) { if (array_key_exists($h, $out)) $out[$h] = min(100, round((float) $p, 1)); }
        return $out;
    }

    /** Live server figures: load average, CPU cores, memory and disk. */
    public static function server(): array
    {
        $load = function_exists('sys_getloadavg') ? (@sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];
        $cores = self::cores();
        $mem = null;
        if (@is_readable('/proc/meminfo') && preg_match_all('/^(MemTotal|MemAvailable):\s+(\d+)/m', (string) @file_get_contents('/proc/meminfo'), $m)) {
            $kv = array_combine($m[1], $m[2]);
            if (isset($kv['MemTotal'], $kv['MemAvailable'])) {
                $mem = ['used' => ($kv['MemTotal'] - $kv['MemAvailable']) * 1024, 'total' => $kv['MemTotal'] * 1024];
            }
        }
        // Shared hosts often switch some of these off (disable_functions) — never fail on that.
        $disk = function_exists('disk_total_space') ? (@disk_total_space(ROOT_PATH) ?: 0) : 0;
        return [
            'load' => $load, 'cores' => $cores,
            'cpu_pct' => min(100, round($load[0] / $cores * 100)),
            'mem' => $mem,
            'disk' => $disk && function_exists('disk_free_space') ? ['used' => $disk - (@disk_free_space(ROOT_PATH) ?: 0), 'total' => $disk] : null,
            'php_mem_peak' => memory_get_peak_usage(true),
        ];
    }
}
