<?php
// public/api/errors/report.php — the browser reports a failure it saw (a request that failed,
// a script error, a receipt that did not open). Kept short; written by ErrorLog.
require_once __DIR__ . '/../../../app/app.php';
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
$d = json_decode((string) file_get_contents('php://input', false, null, 0, 4000), true);
if (!is_array($d)) { echo '{"ok":false}'; exit; }
$_SESSION['err_reports'] = (int) ($_SESSION['err_reports'] ?? 0) + 1;
if ($_SESSION['err_reports'] > 60) { echo '{"ok":false}'; exit; } // per sign-in, so it can't be abused
$src = in_array($d['source'] ?? '', ['js', 'fetch', 'page'], true) ? $d['source'] : 'js';
$page = (string) (parse_url((string) ($d['page'] ?? ''), PHP_URL_PATH) ?: '');
$msg = trim((string) ($d['message'] ?? '')) . (empty($d['url']) ? '' : ' → ' . (parse_url((string) $d['url'], PHP_URL_PATH) ?: ''));
session_write_close();
ErrorLog::record($src, $msg, $page, (int) ($d['status'] ?? 0));
echo '{"ok":true}';
