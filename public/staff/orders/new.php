<?php
// public/staff/orders/new.php — credit sales are now made on the main selling
// screen (Credit button), so this old separate screen just sends you there.
// Kept so bookmarks and old links still work.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::CREDIT_SALES);

$pos = public_url(TenantContext::role() === 'staff' ? 'staff/dashboard/' : 'super/pos/');
$resume = (int) ($_GET['resume'] ?? 0);
header('Location: ' . $pos . ($resume > 0 ? '?resume=' . $resume : '?mode=credit'));
exit;
