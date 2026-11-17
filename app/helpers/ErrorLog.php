<?php
// app/helpers/ErrorLog.php — one place where every problem is written down.
//   * PHP warnings / notices / deprecations (not only crashes)
//   * browser-side failures (a request that failed, a script error, a receipt that did not open)
// Each goes to the sys_errors table (Support → Errors) and to app/storage/logs/errors.log
// (or the system temp folder when that folder can't be written). Never throws.

class ErrorLog
{
    private static int $perRequest = 0;

    /** Write one problem. $source: php | js | fetch | page */
    public static function record(string $source, string $message, string $path = '', int $status = 0): void
    {
        if (self::$perRequest >= 8) { return; } // a broken loop must not flood the log
        self::$perRequest++;
        $message = mb_substr(trim($message), 0, 460);
        if ($message === '') { return; }
        $path = mb_substr($path !== '' ? $path : (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), 0, 255);
        $line = '[' . date('Y-m-d H:i:s') . '] [' . $source . '] ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $path . ' — ' . $message . "\n";
        $dir = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/app/storage/logs';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        if (@file_put_contents($dir . '/errors.log', $line, FILE_APPEND | LOCK_EX) === false) {
            @file_put_contents(rtrim(sys_get_temp_dir(), '/\\') . '/dazu-errors.log', $line, FILE_APPEND | LOCK_EX);
        }
        try {
            $account = 0;
            $tid = class_exists('TenantContext', false) ? TenantContext::tenantId() : null;
            if ($tid) { $account = Features::accountId((int) $tid); }
            $pdo = Database::pdo();
            Telemetry::ensure($pdo);
            $pdo->prepare('INSERT INTO sys_errors (account_id, method, path, status, message) VALUES (?, ?, ?, ?, ?)')
                ->execute([$account, substr($_SERVER['REQUEST_METHOD'] ?? 'GET', 0, 8), $path, $status, '[' . $source . '] ' . $message]);
        } catch (\Throwable $e) { /* the file copy above is enough */ }
    }

    /** Turn PHP warnings and notices into log entries (PHP's own handling carries on). */
    public static function install(): void
    {
        set_error_handler(function (int $no, string $str, string $file, int $line) {
            if (!(error_reporting() & $no)) { return false; }
            $names = [E_WARNING => 'Warning', E_NOTICE => 'Notice', E_USER_WARNING => 'Warning', E_USER_NOTICE => 'Notice',
                      E_DEPRECATED => 'Deprecated', E_USER_DEPRECATED => 'Deprecated', E_USER_ERROR => 'Error', E_RECOVERABLE_ERROR => 'Error'];
            $root = defined('ROOT_PATH') ? ROOT_PATH . '/' : '';
            self::record('php', ($names[$no] ?? 'PHP') . ': ' . $str . ' in ' . str_replace($root, '', $file) . ':' . $line);
            return false;
        });
    }

    /** Last lines of the log files, newest first (for Support → Errors). */
    public static function tail(int $lines = 150): array
    {
        $files = [
            (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/app/storage/logs/errors.log',
            (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/app/storage/logs/php-errors.log',
            rtrim(sys_get_temp_dir(), '/\\') . '/dazu-errors.log',
            rtrim(sys_get_temp_dir(), '/\\') . '/dazu-php-errors.log',
        ];
        $out = [];
        foreach ($files as $f) {
            if (!is_file($f) || !is_readable($f)) { continue; }
            $size = filesize($f);
            $fh = @fopen($f, 'rb');
            if (!$fh) { continue; }
            if ($size > 200000) { fseek($fh, -200000, SEEK_END); }
            $chunk = (string) stream_get_contents($fh);
            fclose($fh);
            $rows = array_values(array_filter(explode("\n", $chunk), 'strlen'));
            $out[basename($f)] = array_reverse(array_slice($rows, -$lines));
        }
        return $out;
    }

    public static function clearLogs(): void
    {
        $dir = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/app/storage/logs';
        foreach (['errors.log', 'php-errors.log'] as $f) { if (is_file("$dir/$f")) { @file_put_contents("$dir/$f", ''); } }
        foreach (['dazu-errors.log', 'dazu-php-errors.log'] as $f) { $p = rtrim(sys_get_temp_dir(), '/\\') . '/' . $f; if (is_file($p)) { @unlink($p); } }
    }
}
