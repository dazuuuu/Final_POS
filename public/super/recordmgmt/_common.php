<?php
// public/super/recordmgmt/_common.php — shared by the Record Management
// pages. Only reachable for accounts holding the Record Management package
// (see Features::gate). Staff (supervisor/cashier logins) reach the same
// files — the layout switches on role, exactly like the Services pages.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

$pdo = Database::pdo();
$RM = new Models\RecordMgmtModel($pdo);
$isOwner = TenantContext::isPrimaryOwner() || (TenantContext::role() === 'tenant_owner' && PageGuard::seesMoney());
$canManage = $isOwner || TenantContext::can(Capabilities::RM_MANAGE);
$canOperate = $isOwner || TenantContext::can(Capabilities::RM_OPERATIONS);
$canPayout = $isOwner || TenantContext::can(Capabilities::RM_PAYOUT);
$canExpenses = $isOwner || TenantContext::can(Capabilities::RM_EXPENSES);
$money = PageGuard::seesMoney();

function rm_require(bool $allowed): void
{
    if (!$allowed) {
        $_SESSION['flash']['error'] = 'You don\'t have access to that page.';
        header('Location: ' . public_url('super/recordmgmt/'));
        exit;
    }
}

function rm_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES);
}

function rm_money($n): string
{
    return 'KES ' . number_format((float) $n, 0);
}

function rm_name(array $e): string
{
    return trim($e['first_name'] . ' ' . ($e['last_name'] ?? ''));
}

/**
 * Groups $rows alphabetically by the first letter of $label($row) — rows
 * sharing a starting letter land under the same letter heading, in A→Z
 * order (non-letters grouped under "#"). Keeps each group in its incoming
 * order (callers should already SELECT ... ORDER BY the label).
 */
function rm_alpha_groups(array $rows, callable $label): array
{
    $groups = [];
    foreach ($rows as $row) {
        $l = strtoupper(substr(trim((string) $label($row)), 0, 1));
        $key = ($l !== '' && ctype_alpha($l)) ? $l : '#';
        $groups[$key][] = $row;
    }
    uksort($groups, function ($a, $b) {
        if ($a === '#') { return 1; }
        if ($b === '#') { return -1; }
        return $a <=> $b;
    });
    return $groups;
}

function rm_render(string $title, string $content): void
{
    $page_title = $title;
    $__layout = TenantContext::role() === 'staff' ? 'staff' : 'tenants';
    include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
}
