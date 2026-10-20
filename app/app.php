<?php
// app/app.php
// Single front bootstrap for the SaaS pages. Supersedes the duplicated
// init.php / bootstrap.php autoloaders. Every page does:
//     require_once __DIR__ . '/<relative>/app/app.php';

require_once __DIR__ . '/helpers/PathConfig.php';

// The shop is in Kenya — fix this explicitly rather than trusting the host's
// OS timezone (which PHP defaults to UTC from anyway when unset). Offers,
// attendance day-boundaries, and every other "is this timestamp now/today"
// check compare a PHP time() against a MySQL DATETIME that was entered in
// the browser as local wall-clock time, so PHP and MySQL must agree — see
// Database::pdo()'s matching `SET time_zone`.
date_default_timezone_set('Africa/Nairobi');

// Never show PHP errors, warnings or stack traces to users on the live site —
// they go to the error log. A page that crashes shows the calm "Reconnecting…"
// screen (or a JSON error for api/) instead of code. Localhost keeps showing
// errors for development.
if (PHP_SAPI !== 'cli') {
    $__devHost = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
    if (!$__devHost) {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
    }
    ini_set('log_errors', '1');
    // Every crash goes to app/storage/logs/errors.log (open it from the hosting
    // file manager). The page shows the calm "Reconnecting…" screen; signed-in
    // Support (the developer) also sees the exact error on it, nobody else does.
    $__failPage = function (string $detail = '') {
        if ($detail !== '') {
            $dir = ROOT_PATH . '/app/storage/logs';
            if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
            @file_put_contents($dir . '/errors.log', '[' . date('Y-m-d H:i:s') . '] ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' '
                . (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '') . ' — ' . $detail . "\n", FILE_APPEND | LOCK_EX);
            if (class_exists('Telemetry', false)) { Telemetry::error($detail); }
        }
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) { http_response_code(503); header('Retry-After: 5'); }
        if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
            if (!headers_sent()) { header('Content-Type: application/json'); }
            echo json_encode(['ok' => false, 'error' => 'Something went wrong. Please try again.']);
            return;
        }
        $__errorDetail = !empty($_SESSION['support_auth']) ? $detail : '';
        include ROOT_PATH . '/public/components/db_down.php';
    };
    if (!$__devHost) {
        set_exception_handler(function (Throwable $e) use ($__failPage) {
            $msg = get_class($e) . ': ' . $e->getMessage() . ' in ' . str_replace(ROOT_PATH . '/', '', $e->getFile()) . ':' . $e->getLine();
            error_log('Uncaught ' . $msg);
            $__failPage($msg);
        });
        register_shutdown_function(function () use ($__failPage) {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
                $__failPage($err['message'] . ' in ' . str_replace(ROOT_PATH . '/', '', $err['file']) . ':' . $err['line']);
            }
        });
    }
    unset($__devHost);
}

// Composer (PHPMailer, etc.)
if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require_once APP_ROOT . '/vendor/autoload.php';
}

spl_autoload_register(function ($class) {
    // Namespaced: Models\X, Controllers\X
    foreach (['Models\\' => '/app/models/', 'Controllers\\' => '/app/controllers/'] as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $file = ROOT_PATH . $dir . substr($class, strlen($prefix)) . '.php';
            if (is_file($file)) { require_once $file; return; }
        }
    }
    // Global infrastructure classes live in helpers/ or services/.
    foreach (['/app/helpers/', '/app/services/'] as $dir) {
        $file = ROOT_PATH . $dir . $class . '.php';
        if (is_file($file)) { require_once $file; return; }
    }
});

// Technical usage per account for the support console (requests, data, CPU, errors — never business data).
Telemetry::start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database check before anything touches it. The setup wizard only opens when
// there is nothing usable to connect to (no config, wrong credentials, empty
// database). A stopped MySQL server gets a "start your database" page instead
// — the wizard can't fix that. Setup pages and the CLI (cron) are exempt.
if (PHP_SAPI !== 'cli' && strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/setup/') === false) {
    $__db = Database::status();
    if ($__db['state'] === 'needs_setup') {
        header('Location: ' . public_url('setup/'));
        exit;
    }
    if ($__db['state'] === 'server_down') {
        http_response_code(503);
        header('Retry-After: 10');
        if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'The database server is not running.']);
            exit;
        }
        include ROOT_PATH . '/public/components/db_down.php';
        exit;
    }
    Database::ensureCollation(); // once per database: one collation for every table
    unset($__db);
}

// Rehydrate the current tenant/user/capabilities for this request.
TenantContext::boot();

// Owner accounts: one device at a time (signing in elsewhere signs this one out).
SingleSession::enforce();

// Landing-page demo shops: time limit, closed pages.
Demo::enforce();

// Per-shop addresses (domain/<slug>/super/…): validate the shop and keep
// signed-in users on their own shop's address.
ShopUrl::enforce();

// Activation key: an account past its 2-hour grace period without a key is
// locked (owner, staff, every shop) to auth/activate-account.php.
License::gate();

// Licensed packages: pages of a switched-off package redirect to the dashboard.
Features::gate();
