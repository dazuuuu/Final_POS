<?php
// public/super/electronics/_common.php — shared by the Electronics pages.
// Only reachable for accounts holding the Electronics package (see
// Features::gate). Each page sets ELEC_CAP to the capability it needs.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(defined('ELEC_CAP') ? ELEC_CAP : Capabilities::INVENTORY_VIEW);

$pdo = Database::pdo();
$EL = new Models\ElectronicsModel($pdo);
$isStaffViewer = TenantContext::role() === 'staff';

function elec_render(string $title, string $content): void
{
    global $isStaffViewer;
    $page_title = $title;
    $content = elec_tabs() . $content;
    $__layout = $isStaffViewer ? 'staff' : 'tenants';
    include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
}

function elec_tabs(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $tabs = [];
    if (TenantContext::can(Capabilities::INVENTORY_VIEW)) {
        $tabs[] = ['Devices & serials', 'fa-mobile-screen', 'super/electronics/', strpos($uri, '/repairs') === false];
    }
    if (TenantContext::can(Capabilities::SALES_RECORD)) {
        $tabs[] = ['Warranty & repairs', 'fa-screwdriver-wrench', 'super/electronics/repairs.php', strpos($uri, '/repairs') !== false];
    }
    $html = '<div class="d-flex gap-2 mb-3 flex-wrap">';
    foreach ($tabs as [$label, $icon, $url, $on]) {
        $html .= '<a class="btn btn-sm ' . ($on ? 'btn-success' : 'btn-outline-secondary') . '" href="' . public_url($url) . '"><i class="fas ' . $icon . ' me-1"></i>' . $label . '</a>';
    }
    return $html . '</div>';
}

function elec_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES);
}

function elec_status_badge(string $status): string
{
    $map = [
        'in_stock'  => ['In stock', 'bg-success'],
        'sold'      => ['Sold', 'bg-secondary'],
        'in_repair' => ['In repair', 'bg-warning text-dark'],
        'warehouse' => ['Warehouse', 'bg-info text-dark'],
    ];
    [$label, $cls] = $map[$status] ?? [$status, 'bg-light text-dark'];
    return '<span class="badge ' . $cls . '">' . $label . '</span>';
}
