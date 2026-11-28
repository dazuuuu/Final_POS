<?php
// app/cron/account_backups.php — runs every 2 hours (hosting cron): one zip
// backup per active account, so a copy can land on the owner's own device
// too, not just this server. See app/helpers/AccountBackup.php.
if (php_sapi_name() !== 'cli' && empty($_GET['_cron_web'])) {
    http_response_code(403);
    exit('CLI only. Use the web endpoint for HTTP access.');
}
require_once __DIR__ . '/../app.php';
$n = AccountBackup::generateAll();
echo "Backed up $n account(s) at " . date('c') . "\n";
