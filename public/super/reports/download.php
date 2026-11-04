<?php
// public/super/reports/download.php?date=YYYY-MM-DD — streams the daily report PDF
require_once __DIR__ . '/../../../app/app.php';
PageGuard::money(); // owners/admins only — staff never see finances, profit or day totals
if (!TenantContext::can(Capabilities::REPORTS_VIEW)) { header("Location: " . public_url("super/dashboard/")); exit; }

$date = preg_replace('/[^0-9-]/', '', $_GET['date'] ?? '') ?: date('Y-m-d');
$type = $_GET['type'] ?? 'general';
$type = in_array($type, ['general', 'sales', 'inventory', 'products', 'attendance', 'finance'], true) ? $type : 'general';
$data = SalesReport::data(Database::pdo(), TenantContext::tenantId(), $date, $type);

// Excel: the day's sales, per staff and per product (opening/closing included).
if (($_GET['format'] ?? '') === 'xlsx') {
    $pdo = Database::pdo();
    $tid = (int) TenantContext::tenantId();
    $sessions = DaySales::sessions($pdo, $tid, $date, $date);
    foreach ($sessions as &$s) {
        if ($s['closed_at'] === null) { $s['live_closing'] = DaySales::totalFor($pdo, $tid, $date, $date, (int) $s['user_id'] ?: null)['total']; }
    }
    unset($s);
    ExcelExport::download($type . '-report-' . $date . '.xlsx', DaySales::sheets(DaySales::sales($pdo, $tid, $date, $date), $sessions, true));
}
$pdf  = SalesReport::pdf($data);

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $type . '-report-' . $date . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
