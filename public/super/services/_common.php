<?php
// public/super/services/_common.php — shared by the Services pages. Only
// reachable for accounts holding the Services package (see Features::gate).
//
// Who sees what:
//   owner              everything
//   services.book      front desk: new clients, bookings, assigning staff
//   services.cashier   take payment, print receipts
//   services.money     prices, amounts and their own commission
//   services.manage    add / edit services and prices (owner unless delegated)
//   any staff member   "My clients" — the services assigned to them
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

$pdo = Database::pdo();
$SD = new Models\ServiceDeskModel($pdo);
$isOwner = TenantContext::role() === 'tenant_owner';
$canBook = TenantContext::can(Capabilities::SERVICES_BOOK);
$canCash = TenantContext::can(Capabilities::SERVICES_CASHIER);
$canMoney = $isOwner || $canCash || TenantContext::can(Capabilities::SERVICES_MONEY);
$isDesk = $isOwner || $canBook || $canCash;
$canManage = $isOwner || TenantContext::can(Capabilities::SERVICES_MANAGE); // the price list

function svc_require(bool $allowed): void
{
    if (!$allowed) {
        $_SESSION['flash']['error'] = 'You don\'t have access to that page.';
        header('Location: ' . public_url('super/services/mine.php'));
        exit;
    }
}

function svc_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES);
}

function svc_money($n): string
{
    return 'KES ' . number_format((float) $n, 0);
}

function svc_status_badge(string $status): string
{
    $map = ['booked' => ['Booked', 'bg-info text-dark'], 'open' => ['In progress', 'bg-warning text-dark'],
            'paid' => ['Paid', 'bg-success'], 'cancelled' => ['Cancelled', 'bg-secondary']];
    [$l, $c] = $map[$status] ?? [$status, 'bg-light text-dark'];
    return '<span class="badge ' . $c . '">' . $l . '</span>';
}

function svc_render(string $title, string $content): void
{
    $page_title = $title;
    $__layout = TenantContext::role() === 'staff' ? 'staff' : 'tenants';
    include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
}
