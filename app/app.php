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

// Per-shop addresses (domain/<slug>/super/…): validate the shop and keep
// signed-in users on their own shop's address.
ShopUrl::enforce();

// Activation key: an account past its 2-hour grace period without a key is
// locked (owner, staff, every shop) to auth/activate-account.php.
License::gate();

// Licensed packages: pages of a switched-off package redirect to the dashboard.
Features::gate();
