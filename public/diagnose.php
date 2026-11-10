<?php
// public/diagnose.php — "why does this page show 500?"
//
// A stand-alone page (it needs nothing from the app, so it still works when the
// app cannot start). It shows, for THIS server: the PHP version and settings,
// missing or half-uploaded files, PHP syntax errors, the database connection,
// the error logs — and it can run a page (e.g. /admin/) and show the exact
// error with file and line.
//
// Open:  https://YOUR-DOMAIN/diagnose.php?key=<the key below>
// Protected by that key, and it stops working 3 days after it was uploaded.
// DELETE public/diagnose.php and public/diagnose-files.php when you are done.
//
// Written for old PHP versions on purpose (no newer syntax), so it runs anywhere.

define('DIAG_KEY', '041bd2c77a8cba8292c0');
define('DIAG_DAYS', 3);

@ini_set('display_errors', '0');
error_reporting(E_ALL);
if (function_exists('set_time_limit')) { @set_time_limit(120); }

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function diag_rel($path, $root) { return ltrim(str_replace(str_replace('\\', '/', $root), '', str_replace('\\', '/', $path)), '/'); }
function diag_mask($text, $secrets) {
    foreach ($secrets as $s) { if (strlen((string) $s) >= 3) { $text = str_replace($s, '***', $text); } }
    return $text;
}

// ---------------------------------------------------------------- where is the app
$root = null;
foreach (array(dirname(__DIR__), __DIR__) as $c) {
    if (is_dir($c . '/app') && is_dir($c . '/public')) { $root = rtrim(str_replace('\\', '/', realpath($c)), '/'); break; }
}

// ---------------------------------------------------------------- access
$given = isset($_POST['key']) ? (string) $_POST['key'] : (isset($_GET['key']) ? (string) $_GET['key'] : (isset($_COOKIE['diag']) ? (string) $_COOKIE['diag'] : ''));
$expired = @filemtime(__FILE__) + DIAG_DAYS * 86400 < time();
$authed = DIAG_KEY !== 'REPLACE_WITH_KEY' && function_exists('hash_equals') && hash_equals(DIAG_KEY, $given);
if ($authed && (isset($_GET['key']) || isset($_POST['key']))) {
    @setcookie('diag', DIAG_KEY, time() + 3600, '/', '', !empty($_SERVER['HTTPS']), true);
}
function diag_head($title) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . h($title) . '</title><style>'
        . 'body{margin:0;font:14px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e2e8f0}'
        . '.w{max-width:1000px;margin:0 auto;padding:22px 16px 60px}h1{font-size:1.35rem;margin:0 0 4px}h2{font-size:1.02rem;margin:0 0 10px}'
        . '.card{background:#17213a;border:1px solid #26344f;border-radius:12px;padding:16px 18px;margin:0 0 16px}'
        . '.ok{color:#4ade80}.bad{color:#f87171;font-weight:600}.warn{color:#fbbf24}.mute{color:#94a3b8}'
        . 'table{width:100%;border-collapse:collapse}td,th{padding:5px 8px;border-bottom:1px solid #26344f;text-align:left;vertical-align:top}th{color:#94a3b8;font-weight:500;font-size:.78rem;text-transform:uppercase}'
        . 'pre{background:#0b1220;border:1px solid #26344f;border-radius:8px;padding:10px 12px;overflow:auto;font:12px/1.5 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-word;margin:6px 0 0}'
        . 'input,button,select{font:inherit}input[type=text],input[type=password]{background:#0b1220;color:#e2e8f0;border:1px solid #334155;border-radius:8px;padding:8px 10px;width:100%;box-sizing:border-box}'
        . 'button,.btn{background:#16a34a;color:#fff;border:0;border-radius:8px;padding:8px 14px;cursor:pointer;text-decoration:none;display:inline-block;margin:3px 4px 3px 0}.btn2{background:#334155}'
        . '.pill{display:inline-block;border-radius:999px;padding:1px 9px;font-size:.75rem;font-weight:600}.p-bad{background:#7f1d1d;color:#fecaca}.p-ok{background:#14532d;color:#bbf7d0}.p-warn{background:#78350f;color:#fde68a}'
        . '</style></head><body><div class="w">';
}
function diag_foot() { echo '</div></body></html>'; }

if (!$authed || $expired) {
    if (!$authed && $given !== '') { sleep(1); }
    http_response_code($authed ? 410 : 403);
    diag_head('Diagnose');
    echo '<div class="card"><h1>Diagnose</h1>';
    if ($expired) {
        echo '<p class="bad">This page has expired (it works for ' . DIAG_DAYS . ' days after upload). Upload a fresh copy, or delete it.</p>';
    } elseif (DIAG_KEY === 'REPLACE_WITH_KEY') {
        echo '<p class="bad">No access key has been set in this file.</p>';
    } else {
        echo '<p class="mute">Enter the access key.</p><form method="post"><input type="password" name="key" autofocus><button>Open</button></form>';
    }
    echo '</div>';
    diag_foot();
    exit;
}
if ($root === null) {
    diag_head('Diagnose');
    echo '<div class="card"><h1>Diagnose</h1><p class="bad">Could not find the app folder next to this file (expected app/ and public/). Upload this file into the public/ folder.</p></div>';
    diag_foot();
    exit;
}

$logDir = $root . '/app/storage/logs';
$self = (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/diagnose.php');

// ---------------------------------------------------------------- RUN a page and show the real error
if (isset($_GET['run'])) {
    $path = (string) $_GET['run'];
    $parts = parse_url($path === '' ? '/' : $path);
    $rel = ltrim(isset($parts['path']) ? rawurldecode($parts['path']) : '/', '/');
    $query = isset($parts['query']) ? $parts['query'] : '';
    $pub = $root . '/public';
    $file = $pub . '/' . $rel;
    if ($rel === '' || is_dir($file)) { $file = rtrim($file, '/') . '/index.php'; if ($rel === '') { $file = $pub . '/index.php'; } }
    $real = realpath($file);
    if (!$real || strpos(str_replace('\\', '/', $real), $pub . '/') !== 0 || substr($real, -4) !== '.php' || basename($real) === basename(__FILE__)) {
        diag_head('Diagnose · run');
        echo '<div class="card"><h2>Cannot run that address</h2><p class="bad">' . h($path) . ' does not match a PHP page in public/.</p><p><a class="btn btn2" href="' . h($self) . '">Back</a></p></div>';
        diag_foot();
        exit;
    }
    $real = str_replace('\\', '/', $real);
    $logFile = $logDir . '/errors.log';
    $logBefore = is_file($logFile) ? filesize($logFile) : 0;
    $phpLog = $logDir . '/php-errors.log';
    $phpBefore = is_file($phpLog) ? filesize($phpLog) : 0;
    $diag = array('warnings' => array(), 'exception' => null, 'fatal' => null, 'out' => '', 'done' => false);
    $baseUrl = rtrim(str_replace('\\', '/', dirname($self)), '/');
    if (!defined('BASE_URL')) { define('BASE_URL', $baseUrl === '/' ? '' : $baseUrl); }

    // Behave like a real request for that page
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/' . $rel . ($query !== '' ? '?' . $query : '');
    $_SERVER['QUERY_STRING'] = $query;
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . ltrim(substr($real, strlen($pub)), '/');
    $_SERVER['SCRIPT_FILENAME'] = $real;
    parse_str($query, $_GET);
    $_POST = array();
    // The app hides errors behind a calm screen unless it believes it is on a developer's
    // machine. For this one run, make it believe that, so the real error comes to us.
    $_SERVER['SERVER_NAME'] = 'localhost';

    ob_start(function ($buf) use (&$diag) { $diag['out'] .= $buf; return ''; });
    set_error_handler(function ($no, $str, $f, $l) use (&$diag, $root) {
        $diag['warnings'][] = array($no, $str, diag_rel($f, $root), $l);
        return true;
    });
    set_exception_handler(function ($e) use (&$diag) { $diag['exception'] = $e; });
    register_shutdown_function(function () use (&$diag, $root, $path, $self, $logFile, $logBefore, $phpLog, $phpBefore) {
        $diag['fatal'] = error_get_last();
        $code = http_response_code();
        $headers = headers_list();
        while (ob_get_level() > 0) { @ob_end_clean(); }
        // The app may have logged the failure itself
        $new = '';
        foreach (array(array($logFile, $logBefore), array($phpLog, $phpBefore)) as $pair) {
            if (is_file($pair[0]) && filesize($pair[0]) > $pair[1]) {
                $fh = @fopen($pair[0], 'rb');
                if ($fh) { fseek($fh, $pair[1]); $new .= '[' . basename($pair[0]) . "]\n" . stream_get_contents($fh, 20000) . "\n"; fclose($fh); }
            }
        }
        if (!headers_sent()) { header_remove(); header('Content-Type: text/html; charset=utf-8'); http_response_code(200); }
        diag_head('Diagnose · run ' . $path);
        $bad = $diag['exception'] || ($diag['fatal'] && in_array($diag['fatal']['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR), true));
        echo '<h1>Ran ' . h($path) . '</h1><p class="mute">Result: <span class="pill ' . ($bad ? 'p-bad">CRASHED' : 'p-ok">FINISHED') . '</span> · ' . ($bad ? 'A visitor would get <b class="bad">HTTP 500</b> (PHP stopped with an error).' : 'HTTP status the page set: <b>' . h($code ?: 200) . '</b>') . '</p>';
        if ($diag['exception']) {
            $e = $diag['exception'];
            echo '<div class="card"><h2 class="bad">Uncaught ' . h(get_class($e)) . '</h2><p><b>' . h($e->getMessage()) . '</b></p><p class="mute">in <b>' . h(diag_rel($e->getFile(), $root)) . '</b> on line <b>' . (int) $e->getLine() . '</b></p>'
                . '<pre>' . h(str_replace(str_replace('\\', '/', $root) . '/', '', str_replace('\\', '/', $e->getTraceAsString()))) . '</pre></div>';
        }
        if ($diag['fatal'] && in_array($diag['fatal']['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR), true)) {
            $f = $diag['fatal'];
            echo '<div class="card"><h2 class="bad">' . ($f['type'] === E_PARSE ? 'PHP syntax error' : 'Fatal error') . '</h2><p><b>' . h($f['message']) . '</b></p><p class="mute">in <b>' . h(diag_rel($f['file'], $root)) . '</b> on line <b>' . (int) $f['line'] . '</b></p></div>';
        }
        if ($diag['warnings']) {
            echo '<div class="card"><h2 class="warn">Warnings and notices (' . count($diag['warnings']) . ')</h2><table><tr><th>Message</th><th>Where</th></tr>';
            foreach (array_slice($diag['warnings'], 0, 40) as $w) { echo '<tr><td>' . h($w[1]) . '</td><td class="mute">' . h($w[2]) . ':' . (int) $w[3] . '</td></tr>'; }
            echo '</table></div>';
        }
        if ($new !== '') { echo '<div class="card"><h2>Logged during this run</h2><pre>' . h(str_replace(str_replace('\\', '/', $root) . '/', '', $new)) . '</pre></div>'; }
        echo '<div class="card"><h2>What the page sent</h2><p class="mute">Headers</p><pre>' . h(implode("\n", $headers) ?: '(none)') . '</pre>'
            . '<p class="mute">Body (' . strlen($diag['out']) . ' bytes' . (strlen($diag['out']) > 1500 ? ', first 1500' : '') . ')</p><pre>' . h(substr($diag['out'], 0, 1500) ?: '(empty)') . '</pre></div>';
        echo '<p><a class="btn btn2" href="' . h($self) . '">← Back to the checks</a> <a class="btn" href="' . h($self . '?run=' . urlencode($path)) . '">Run again</a></p>';
        diag_foot();
    });
    chdir(dirname($real));
    include $real;
    exit; // the shutdown function above prints the report
}

// ---------------------------------------------------------------- actions (POST)
$msg = '';
if (isset($_POST['act'])) {
    $act = (string) $_POST['act'];
    if ($act === 'opcache') {
        $msg = function_exists('opcache_reset') && @opcache_reset() ? 'OPcache cleared: PHP will read every file fresh.' : 'OPcache is not active on this server (nothing to clear).';
    } elseif ($act === 'mklogs') {
        if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
        $ok = is_dir($logDir) && @file_put_contents($logDir . '/php-errors.log', '[' . date('Y-m-d H:i:s') . '] diagnose: log test entry' . "\n", FILE_APPEND) !== false;
        $msg = $ok ? 'The log folder works: a test line was written to app/storage/logs/php-errors.log.' : 'Could not write to ' . diag_rel($logDir, $root) . ' — give that folder write permission (755 or 775) in the hosting file manager.';
    } elseif ($act === 'testerror') {
        @ini_set('log_errors', '1'); @ini_set('error_log', $logDir . '/php-errors.log');
        error_log('diagnose: test error entry ' . date('H:i:s'));
        $msg = 'A test error was sent to PHP\'s error log. Look below under "Logs" to see where it landed.';
    } elseif ($act === 'userini') {
        $txt = "; Written by diagnose.php: send every PHP error to one file the owner can open.\nlog_errors = 1\ndisplay_errors = 0\nerror_log = \"" . $logDir . "/php-errors.log\"\n";
        if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
        $a = @file_put_contents($root . '/.user.ini', $txt) !== false;
        $b = @file_put_contents($root . '/public/.user.ini', $txt) !== false;
        $msg = ($a || $b) ? 'Saved .user.ini. On most hosts it takes effect within 5 minutes; after that every PHP error — even ones that happen before the app starts — is written to app/storage/logs/php-errors.log.' : 'Could not write .user.ini (folder not writable).';
    } elseif ($act === 'rmuserini') {
        @unlink($root . '/.user.ini'); @unlink($root . '/public/.user.ini');
        $msg = 'Removed .user.ini.';
    }
}

// ---------------------------------------------------------------- the checks
$problems = array();
$notes = array();

// 1. PHP
$need = array('pdo_mysql', 'mbstring', 'zip', 'gd', 'curl', 'openssl', 'json', 'fileinfo', 'ctype', 'dom', 'session', 'tokenizer');
$missingExt = array();
foreach ($need as $e) { if (!extension_loaded($e)) { $missingExt[] = $e; } }
if (version_compare(PHP_VERSION, '8.0.0', '<')) { $problems[] = 'PHP ' . PHP_VERSION . ' is too old — the app needs PHP 8.0 or newer (set it in the hosting panel → PHP version).'; }
foreach ($missingExt as $e) {
    if (in_array($e, array('pdo_mysql', 'mbstring', 'json', 'session', 'ctype'), true)) { $problems[] = 'PHP extension "' . $e . '" is not enabled — the app cannot run without it.'; }
    else { $notes[] = 'PHP extension "' . $e . '" is not enabled — some features (' . ($e === 'zip' ? 'imports, photo zips' : ($e === 'gd' ? 'image handling' : 'parts of the app')) . ') will not work.'; }
}
$opc = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

// 2. Folders
$writeChecks = array(
    'app/storage' => $root . '/app/storage', 'app/storage/logs' => $logDir, 'app/storage/imports' => $root . '/app/storage/imports',
    'public/assets/uploads' => $root . '/public/assets/uploads',
);
$writeRows = array();
foreach ($writeChecks as $label => $dir) {
    $exists = is_dir($dir); $w = $exists && is_writable($dir);
    $writeRows[] = array($label, $exists, $w);
    if (!$w && $label !== 'app/storage/imports') { $problems[] = $label . ($exists ? ' is not writable' : ' does not exist') . ' — create it / set permissions 755 or 775. Without this the app cannot write logs or uploads.'; }
}
foreach (array('app/config/database.php', 'app/app.php', 'index.php', 'public/index.php') as $must) {
    if (!is_file($root . '/' . $must)) { $problems[] = 'Missing file: ' . $must; }
}

// 3. Files vs the expected version
$manifest = is_file(__DIR__ . '/diagnose-files.php') ? @include __DIR__ . '/diagnose-files.php' : null;
$missingFiles = array(); $changedFiles = array(); $checked = 0;
if (is_array($manifest) && isset($manifest['files'])) {
    foreach ($manifest['files'] as $rel => $sha) {
        $checked++;
        $p = $root . '/' . $rel;
        if (!is_file($p)) { $missingFiles[] = $rel; continue; }
        $c = @file_get_contents($p);
        if ($c === false || sha1(str_replace("\r", '', $c)) !== $sha) { $changedFiles[] = array($rel, filesize($p), date('Y-m-d H:i', filemtime($p))); }
    }
    if ($missingFiles) { $problems[] = count($missingFiles) . ' file(s) are MISSING on the server (upload them): ' . implode(', ', array_slice($missingFiles, 0, 6)) . (count($missingFiles) > 6 ? ' …' : ''); }
    if ($changedFiles) { $notes[] = count($changedFiles) . ' file(s) differ from the version this checker was made for (an older copy, a half-finished upload, or edited on the server) — listed below.'; }
}

// 4. PHP syntax, checked by THIS server's PHP version
$syntax = array(); $scanned = 0;
$dirs = array($root . '/app', $root . '/public');
foreach ($dirs as $dir) {
    if (!is_dir($dir)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (substr($p, -4) !== '.php' || strpos($p, '/vendor/') !== false || strpos($p, '/storage/') !== false || strpos($p, '/uploads/') !== false) { continue; }
        $scanned++;
        $src = @file_get_contents($p);
        if ($src === false) { $syntax[] = array(diag_rel($p, $root), 0, 'file could not be read (permissions?)'); continue; }
        try { token_get_all($src, TOKEN_PARSE); }
        catch (ParseError $e) { $syntax[] = array(diag_rel($p, $root), $e->getLine(), $e->getMessage()); }
        catch (Throwable $e) { /* not a syntax problem */ }
    }
}
if (is_file($root . '/index.php')) {
    $scanned++;
    try { token_get_all((string) file_get_contents($root . '/index.php'), TOKEN_PARSE); } catch (ParseError $e) { $syntax[] = array('index.php', $e->getLine(), $e->getMessage()); } catch (Throwable $e) {}
}
foreach ($syntax as $s) { $problems[] = 'PHP syntax error in ' . $s[0] . ($s[1] ? ' (line ' . $s[1] . ')' : '') . ': ' . $s[2]; }

// 5. Database
$db = array('ok' => false, 'msg' => '', 'version' => '', 'dbname' => '', 'missing' => array());
$cfgFile = $root . '/app/config/database.php';
$cfg = null;
if (is_file($cfgFile)) { try { $cfg = include $cfgFile; } catch (Throwable $e) { $db['msg'] = 'database.php could not be read: ' . $e->getMessage(); } }
if (is_array($cfg)) {
    $db['dbname'] = isset($cfg['dbname']) ? $cfg['dbname'] : '';
    try {
        $dsn = 'mysql:host=' . (isset($cfg['host']) ? $cfg['host'] : 'localhost') . ';dbname=' . $db['dbname'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, isset($cfg['username']) ? $cfg['username'] : '', isset($cfg['password']) ? $cfg['password'] : '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5));
        $db['ok'] = true;
        $db['version'] = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $have = array();
        foreach ($pdo->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_NUM) as $r) { $have[strtolower($r[0])][strtolower($r[1])] = true; }
        $expect = array(
            'users' => array('session_token', 'must_reset_password'), 'tenants' => array('parent_tenant_id', 'product_fields', 'day_open', 'day_close', 'store_kind', 'multi_shop'),
            'day_sessions' => array('final'), 'stock_moves' => array('delivered_by'), 'stock_cash' => array('amount'), 'products' => array('source_image', 'retail_price'),
        );
        foreach ($expect as $t => $cols) {
            if (!isset($have[$t])) { $db['missing'][] = 'table ' . $t; continue; }
            foreach ($cols as $c) { if (!isset($have[$t][$c])) { $db['missing'][] = $t . '.' . $c; } }
        }
        if (!isset($have['users']) || !isset($have['tenants'])) { $problems[] = 'The database "' . $db['dbname'] . '" has no users / tenants tables — the wrong database, or an empty one.'; }
        elseif ($db['missing']) { $notes[] = 'Newer database parts are not created yet (' . implode(', ', array_slice($db['missing'], 0, 8)) . '). The app creates most of them on first use; if a page crashes on one, open Business → Updates → apply the newest update.'; }
    } catch (Throwable $e) {
        $db['msg'] = diag_mask($e->getMessage(), array(isset($cfg['password']) ? $cfg['password'] : ''));
        $problems[] = 'Cannot connect to the database: ' . $db['msg'];
    }
} elseif ($db['msg'] === '') {
    $db['msg'] = 'app/config/database.php was not found or does not return settings.';
    $problems[] = $db['msg'];
}

// 6. Logs
$iniLog = (string) ini_get('error_log');
$candidates = array(
    $logDir . '/php-errors.log' => 'App PHP error log (all PHP errors since index.php was updated)',
    $logDir . '/errors.log' => 'App crash log (the "Reconnecting…" screen)',
);
if ($iniLog !== '') { $candidates[$iniLog] = 'PHP\'s configured error_log (server setting)'; }
foreach (array($root . '/error_log', $root . '/public/error_log', dirname($root) . '/error_log', $root . '/php_errorlog', dirname($root) . '/logs/error_log', dirname($root) . '/logs/php_error.log') as $c) { $candidates[$c] = 'Server error log (found by name)'; }
$tmp = rtrim(sys_get_temp_dir(), '/\\');
foreach (array($tmp . '/dazu-php-errors.log', $tmp . '/dazu-errors.log') as $c) { $candidates[$c] = 'Fallback log in the system temp folder (used when app/storage/logs is not writable)'; }
$logRows = array();
foreach ($candidates as $file => $label) {
    $file = str_replace('\\', '/', $file);
    if (!@is_file($file) || !@is_readable($file)) { continue; }
    $size = filesize($file);
    $fh = @fopen($file, 'rb'); $tail = '';
    if ($fh) { fseek($fh, max(0, $size - 14000)); $tail = stream_get_contents($fh); fclose($fh); }
    $lines = array_slice(explode("\n", trim($tail)), -60);
    $logRows[] = array($file, $label, $size, date('Y-m-d H:i', filemtime($file)), implode("\n", array_map(function ($l) { return strlen($l) > 700 ? substr($l, 0, 700) . ' …' : $l; }, $lines)));
}

// 7. A real request to the site (what a browser gets, including Apache errors)
$probePath = isset($_GET['probe']) ? (string) $_GET['probe'] : '/admin/';
$probe = null;
if (isset($_GET['probe'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $url = $scheme . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . $probePath;
    $probe = array('url' => $url, 'code' => 0, 'headers' => '', 'body' => '', 'err' => '', 'ms' => 0);
    $t = microtime(true);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'diagnose.php'));
        $raw = curl_exec($ch);
        if ($raw === false) { $probe['err'] = curl_error($ch); }
        else { $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $probe['headers'] = substr($raw, 0, $hs); $probe['body'] = substr($raw, $hs); $probe['code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); }
        curl_close($ch);
    } else { $probe['err'] = 'The curl extension is not available, so the live request cannot be tested from here.'; }
    $probe['ms'] = (int) ((microtime(true) - $t) * 1000);
}

// ---------------------------------------------------------------- render
diag_head('Diagnose');
echo '<h1>Diagnose</h1><p class="mute">' . h(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') . ' · PHP ' . h(PHP_VERSION) . ' · ' . h(PHP_SAPI) . ' · checked in ' . (int) ((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000) . ' ms</p>';
if ($msg !== '') { echo '<div class="card" style="border-color:#16a34a"><b class="ok">' . h($msg) . '</b></div>'; }

echo '<div class="card"><h2>' . ($problems ? '<span class="bad">Problems found (' . count($problems) . ')</span>' : '<span class="ok">No problems found in the checks below</span>') . '</h2>';
if ($problems) { echo '<ol>'; foreach ($problems as $p) { echo '<li class="bad" style="font-weight:500">' . h($p) . '</li>'; } echo '</ol>'; }
else { echo '<p class="mute">The files, PHP syntax, folders and database all look fine. If a page still shows 500, run it below to see the exact error, and check the logs.</p>'; }
if ($notes) { echo '<p class="warn" style="margin:10px 0 4px"><b>Worth knowing</b></p><ul>'; foreach ($notes as $n) { echo '<li class="warn">' . h($n) . '</li>'; } echo '</ul>'; }
echo '</div>';

// Run a page
echo '<div class="card"><h2>Run a page and show the exact error</h2><p class="mute">Runs the page on this server like a visitor would, in developer mode, and shows the error with its file and line.</p>'
    . '<form method="get" style="display:flex;gap:8px;flex-wrap:wrap"><input type="text" name="run" value="/admin/" style="max-width:320px">'
    . '<button>Run this page</button></form><p style="margin:8px 0 0">';
foreach (array('/admin/', '/terminal/', '/', '/super/dashboard/', '/staff/dashboard/', '/auth/login.php') as $q) { echo '<a class="btn btn2" href="' . h($self . '?run=' . urlencode($q)) . '">' . h($q) . '</a>'; }
echo '</p></div>';

// Live request
echo '<div class="card"><h2>Test the live address (what a browser really gets)</h2><form method="get" style="display:flex;gap:8px;flex-wrap:wrap"><input type="text" name="probe" value="' . h($probePath) . '" style="max-width:320px"><button>Request it</button></form>';
if ($probe) {
    echo '<p style="margin:10px 0 0">' . h($probe['url']) . ' → ';
    if ($probe['err'] !== '') { echo '<span class="bad">' . h($probe['err']) . '</span>'; }
    else {
        $cls = $probe['code'] >= 500 ? 'bad' : ($probe['code'] >= 400 ? 'warn' : 'ok');
        echo '<b class="' . $cls . '">HTTP ' . (int) $probe['code'] . '</b> <span class="mute">in ' . (int) $probe['ms'] . ' ms</span>';
        if ($probe['code'] >= 500) { echo '<br><span class="mute">A 500 with an empty or generic page means PHP (or Apache) stopped before the app could show anything. If "Run this page" above works but this does not, the problem is the web server setup (.htaccess, PHP version for this folder, or a file only the front controller loads). Check the logs below.</span>'; }
        echo '<pre>' . h(trim($probe['headers'])) . '</pre><p class="mute">Body (first 600 chars)</p><pre>' . h(substr(trim(strip_tags((string) $probe['body'])), 0, 600) ?: '(empty)') . '</pre>';
    }
    echo '</p>';
}
echo '</div>';

// Logs
echo '<div class="card"><h2>Logs</h2>';
if (!$logRows) { echo '<p class="warn">No error log was found. Either nothing has been logged yet, or PHP is logging somewhere this page cannot read (e.g. only in the hosting panel → Logs / Error log). Use the buttons under "Make errors visible" below, then reload the page that fails and come back.</p>'; }
foreach ($logRows as $r) {
    echo '<p style="margin:12px 0 0"><b>' . h(diag_rel($r[0], $root) ?: $r[0]) . '</b> <span class="mute">— ' . h($r[1]) . ' · ' . number_format($r[2]) . ' bytes · last change ' . h($r[3]) . '</span></p><pre>' . h(str_replace($root . '/', '', $r[4])) . '</pre>';
}
echo '<p class="mute" style="margin:12px 0 0">PHP error_log setting: <b>' . h($iniLog !== '' ? $iniLog : '(none — PHP uses the server\'s own log)') . '</b> · display_errors: <b>' . h(ini_get('display_errors') ?: 'off') . '</b> · log_errors: <b>' . h(ini_get('log_errors') ?: 'off') . '</b></p></div>';

// Make errors visible
echo '<div class="card"><h2>Make errors visible</h2><p class="mute">Do these once, then reload the failing page and look at "Logs" above.</p><form method="post" style="display:flex;flex-wrap:wrap;gap:4px">'
    . '<button name="act" value="mklogs">Test the log folder</button><button name="act" value="testerror" class="btn2">Write a test error</button>'
    . '<button name="act" value="userini">Send ALL PHP errors to app/storage/logs (.user.ini)</button><button name="act" value="rmuserini" class="btn2">Remove .user.ini</button>'
    . '<button name="act" value="opcache" class="btn2">Clear OPcache</button></form>'
    . '<p class="mute" style="margin:10px 0 0">".user.ini" makes PHP write every error — even ones that happen before the app starts — to <b>app/storage/logs/php-errors.log</b>. "Clear OPcache" forces PHP to read freshly uploaded files (old copies can linger in memory after an upload).</p></div>';

// Details
echo '<div class="card"><h2>PHP on this server</h2><table>'
    . '<tr><th>Version</th><td>' . h(PHP_VERSION) . ' (' . h(PHP_SAPI) . ')</td></tr>'
    . '<tr><th>Runs as</th><td>' . h(function_exists('get_current_user') ? get_current_user() : '?') . '</td></tr>'
    . '<tr><th>memory_limit / time</th><td>' . h(ini_get('memory_limit')) . ' / ' . h(ini_get('max_execution_time')) . ' s</td></tr>'
    . '<tr><th>Upload limits</th><td>upload_max_filesize ' . h(ini_get('upload_max_filesize')) . ' · post_max_size ' . h(ini_get('post_max_size')) . '</td></tr>'
    . '<tr><th>open_basedir</th><td>' . h(ini_get('open_basedir') ?: '(none)') . '</td></tr>'
    . '<tr><th>Disabled functions</th><td class="mute">' . h(ini_get('disable_functions') ?: '(none)') . '</td></tr>'
    . '<tr><th>OPcache</th><td>' . ($opc ? 'on · ' . (int) $opc['opcache_statistics']['num_cached_scripts'] . ' scripts cached' : 'off / not available') . '</td></tr>'
    . '<tr><th>App folder</th><td>' . h($root) . '</td></tr>'
    . '<tr><th>Extensions</th><td>' . implode(' ', array_map(function ($e) { return '<span class="pill ' . (extension_loaded($e) ? 'p-ok' : 'p-bad') . '">' . h($e) . '</span>'; }, $need)) . '</td></tr></table></div>';

echo '<div class="card"><h2>Folders</h2><table><tr><th>Folder</th><th>Exists</th><th>Writable</th></tr>';
foreach ($writeRows as $r) { echo '<tr><td>' . h($r[0]) . '</td><td class="' . ($r[1] ? 'ok' : 'bad') . '">' . ($r[1] ? 'yes' : 'NO') . '</td><td class="' . ($r[2] ? 'ok' : 'bad') . '">' . ($r[2] ? 'yes' : 'NO') . '</td></tr>'; }
echo '</table></div>';

echo '<div class="card"><h2>Database</h2>';
if ($db['ok']) { echo '<p class="ok">Connected to <b>' . h($db['dbname']) . '</b> · MySQL/MariaDB ' . h($db['version']) . '</p>'; if ($db['missing']) { echo '<p class="warn">Not created yet: ' . h(implode(', ', $db['missing'])) . '</p>'; } }
else { echo '<p class="bad">' . h($db['msg']) . '</p>'; }
echo '</div>';

echo '<div class="card"><h2>PHP syntax (checked by this server\'s PHP ' . h(PHP_VERSION) . ')</h2><p class="' . ($syntax ? 'bad' : 'ok') . '">' . ($syntax ? count($syntax) . ' file(s) with errors' : 'All ' . (int) $scanned . ' PHP files parse correctly') . '</p>';
if ($syntax) { echo '<table><tr><th>File</th><th>Line</th><th>Error</th></tr>'; foreach ($syntax as $s) { echo '<tr><td>' . h($s[0]) . '</td><td>' . (int) $s[1] . '</td><td class="bad">' . h($s[2]) . '</td></tr>'; } echo '</table>'; }
echo '</div>';

echo '<div class="card"><h2>Files compared with the expected version</h2>';
if (!is_array($manifest)) { echo '<p class="warn">diagnose-files.php was not uploaded next to this file, so files cannot be compared. Upload it too (public/diagnose-files.php).</p>'; }
else {
    echo '<p class="mute">Checked ' . (int) $checked . ' files against the list made on ' . h(isset($manifest['generated']) ? $manifest['generated'] : '?') . '.</p>';
    echo '<p class="' . ($missingFiles ? 'bad' : 'ok') . '">' . ($missingFiles ? count($missingFiles) . ' missing' : 'No missing files') . '</p>';
    if ($missingFiles) { echo '<pre>' . h(implode("\n", $missingFiles)) . '</pre>'; }
    echo '<p class="' . ($changedFiles ? 'warn' : 'ok') . '">' . ($changedFiles ? count($changedFiles) . ' differ from the expected version' : 'Every file matches') . '</p>';
    if ($changedFiles) { echo '<table><tr><th>File</th><th>Size</th><th>Changed on server</th></tr>'; foreach (array_slice($changedFiles, 0, 120) as $c) { echo '<tr><td>' . h($c[0]) . '</td><td>' . number_format($c[1]) . ' B</td><td class="mute">' . h($c[2]) . '</td></tr>'; } echo '</table>'; }
}
echo '</div>';
echo '<p class="mute">Delete <b>public/diagnose.php</b> and <b>public/diagnose-files.php</b> when you are finished.</p>';
diag_foot();
