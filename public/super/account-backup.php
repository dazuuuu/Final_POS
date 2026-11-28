<?php
// public/super/account-backup.php — downloads the account's latest safety
// backup zip (owner, admin or staff can fetch it) and marks it seen for
// whoever downloaded it, so the banner stops nagging them specifically.
require_once __DIR__ . '/../../app/app.php';
PageGuard::auth();

$accountId = Features::accountId((int) TenantContext::tenantId());
$backup = AccountBackup::latestFor($accountId);
if (!$backup) {
    http_response_code(404);
    exit('No backup has been generated yet — check back in a little while.');
}
$path = AccountBackup::DIR . '/' . $accountId . '/' . $backup['file_path'];
if (!is_file($path)) {
    http_response_code(404);
    exit('That backup file is missing on the server.');
}
if (empty($_SESSION['support_preview'])) {
    AccountBackup::markSeen((int) $backup['id'], $accountId, (int) TenantContext::userId());
}

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
exit;
