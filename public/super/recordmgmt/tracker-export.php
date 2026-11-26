<?php
// public/super/recordmgmt/tracker-export.php — the tracker table as a real .xlsx.
require_once __DIR__ . '/_common.php';
rm_require($money);
require_once APP_ROOT . '/helpers/ExcelExport.php';

$range = in_array($_GET['range'] ?? 'month', ['week', 'month'], true) ? $_GET['range'] : 'month';
$to = date('Y-m-d');
$from = $range === 'week' ? date('Y-m-d', strtotime('-6 days')) : date('Y-m-01');
$t = $RM->trackerTotals($from, $to);

$rows = array_map(fn($r) => [
    $r['name'], $r['role'], (int) $r['jobs'], (float) $r['revenue'], (float) $r['commission'],
    $r['revenue'] >= $t['average'] ? 'Above average' : 'Below average',
], $t['rows']);

ExcelExport::download('employee-tracker-' . $from . '-to-' . $to . '.xlsx', [
    'Tracker' => ['headers' => ['Employee', 'Role', 'Jobs', 'Revenue', 'Commission', 'Vs. average'], 'rows' => $rows],
]);
