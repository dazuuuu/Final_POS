<?php
// index.php — front controller at the project root. Every request comes here
// (Apache: the root .htaccess; PHP's server: `php -S localhost:8002 index.php`),
// so the site runs from the domain itself — no /public in any URL — even when
// a host (e.g. a Hostinger subdomain) points the domain at this folder:
//
//   /                       → public/index.php (the POS / staff PIN screen)
//   /auth/login.php         → public/auth/login.php
//   /super/dashboard/       → public/super/dashboard/index.php
//   /assets/…               → static file from public/assets/…
//   /<shop-slug>/…          → public/index.php, which runs that shop's page (ShopUrl)
//   /public/…               → 301 to the same address without /public (old links)
//   /app, /db_sample, /vendor, /.git, *.sql, … → never served: back to the POS home
//
// Only files inside public/ are ever served or run.

$root = str_replace('\\', '/', __DIR__);
$public = $root . '/public';

// URL prefix when the project sits in a sub-folder (e.g. localhost/Taso), else ''.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
if (PHP_SAPI === 'cli-server') {
    $base = ''; // `php -S … index.php`: the project folder is the server root
}
if (!defined('BASE_URL')) {
    define('BASE_URL', $base);
}

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
$qs = (string) parse_url($uri, PHP_URL_QUERY);
$qs = $qs !== '' ? '?' . $qs : '';
if ($base !== '' && strpos($path, $base . '/') === 0) {
    $path = substr($path, strlen($base));
} elseif ($base !== '' && $path === $base) {
    $path = '/';
}
$rel = ltrim($path, '/');

$home = function () use ($base): void {
    header('Location: ' . $base . '/', true, 302);
    exit;
};

// Never: traversal, NUL bytes, dot-files (.git, .env, .htaccess), dumps, or
// anything outside public/ (app code, config, vendor, samples).
if (strpos($rel, '..') !== false || strpos($rel, "\0") !== false
    || preg_match('#(^|/)\.#', $rel)
    || preg_match('#\.(sql|sqlite|log|bak|env|ini|lock|md|json\.lock)$#i', $rel)
    || preg_match('#^(app|db_sample|vendor|node_modules|storage|databases|tests?|config|cron|tools)(/|$)#i', $rel)
    || in_array($rel, ['composer.json', 'composer.lock', 'package.json'], true)) {
    $home();
}

// Old /public/… links → the same page without /public.
if ($rel === 'public' || strpos($rel, 'public/') === 0) {
    header('Location: ' . $base . '/' . substr($rel, strlen('public/')) . $qs, true, 301);
    exit;
}

$file = $public . '/' . $rel;
if ($rel !== '' && is_dir($file)) {
    if (substr($rel, -1) !== '/') { // folder without slash: add it so relative links work
        header('Location: ' . $base . '/' . $rel . '/' . $qs, true, 301);
        exit;
    }
    $file = rtrim($file, '/') . '/index.php';
}
if ($rel === '' || $rel === 'index.php' || !is_file($file)) {
    $file = $public . '/index.php'; // POS home, or a shop address (/<slug>/…) — ShopUrl::dispatch() takes it from here
    $rel = 'index.php';
}

// The service worker / manifest are only ever fetched by the browser in the
// background (that fetch carries "Service-Worker: script" or isn't a page
// load). Opened as a PAGE — e.g. an installed app reopening the last address
// it had — they'd show raw code, so send that person to the POS instead.
if (in_array($rel, ['sw.js', 'manifest.webmanifest'], true) && empty($_SERVER['HTTP_SERVICE_WORKER'])
    && (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'document'
        || (!isset($_SERVER['HTTP_SEC_FETCH_DEST']) && strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false))) {
    header('Cache-Control: no-store');
    header('Location: ' . $base . '/terminal/', true, 302);
    exit;
}

// Static files: served from public/ with a long cache (they're versioned by name).
if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
    $types = [
        'css' => 'text/css', 'js' => 'application/javascript', 'mjs' => 'application/javascript', 'json' => 'application/json',
        'webmanifest' => 'application/manifest+json', 'map' => 'application/json', 'txt' => 'text/plain', 'xml' => 'application/xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'avif' => 'image/avif',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
        'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!isset($types[$ext])) {
        $home();
    }
    $mtime = filemtime($file);
    $etag = '"' . dechex($mtime) . '-' . dechex(filesize($file)) . '"';
    header('Content-Type: ' . $types[$ext]);
    header('X-Content-Type-Options: nosniff');
    // The service worker and app manifest must always be re-checked, or a fixed
    // version would take up to a week to reach installed apps.
    $isSw = in_array(basename($file), ['sw.js', 'manifest.webmanifest'], true);
    header('Cache-Control: ' . ($isSw ? 'no-cache' : 'public, max-age=604800'));
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// PHP page: run it as if it had been requested directly. Pages are HTML and
// never stored by the browser, so a reopened app always loads a fresh login /
// dashboard (a page that sends a file or JSON overrides these headers itself).
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
$script = $base . '/' . ltrim(substr($file, strlen($public)), '/');
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $script;
$_SERVER['SCRIPT_FILENAME'] = $file;
chdir(dirname($file));
$__frontFile = $file;
unset($root, $public, $uri, $path, $qs, $rel, $home, $script, $file, $base);
require $__frontFile;
