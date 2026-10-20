<?php
// public/super/alerts/respond.php — close, vote on or answer a support alert, then go back.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant();
require_once APP_ROOT . '/helpers/uploads.php';
$back = (string) ($_POST['back'] ?? '');
if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//')) $back = public_url('super/dashboard/'); // same-site only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $err = Alerts::respond((int) ($_POST['id'] ?? 0), Features::accountId((int) TenantContext::tenantId()), (int) TenantContext::userId(), $_POST);
    if ($err) $_SESSION['alert_error'] = $err;
    elseif (!empty($_POST['choice']) || !empty($_POST['feedback'])) $_SESSION['flash']['success'] = 'Thank you — your answer was sent to support.';
}
header('Location: ' . $back);
exit;
