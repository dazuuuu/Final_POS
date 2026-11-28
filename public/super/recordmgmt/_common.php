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

/** The account owner's own logo (never the platform's), for a printed report's letterhead. */
function rm_owner_logo(): string
{
    try {
        $tenant = (new \Models\TenantModel(\Database::pdo()))->primary();
        return \Branding::shopLogo($tenant);
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * A standalone, printable report page: black & white only, the owner's own
 * logo (never a platform brand mark), a plain letterhead, a few clean
 * columns, and a quiet footer line — no loud branding. Echoes the full page
 * and exits (it isn't wrapped in the app's normal sidebar layout).
 */
function rm_report_page(string $title, string $subtitle, string $bodyHtml): void
{
    $logo = rm_owner_logo();
    $shopName = (new \Models\TenantModel(\Database::pdo()))->primary()['name'] ?? '';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo rm_h($title); ?></title>
<style>
  *{box-sizing:border-box;}
  body{font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#000;background:#fff;margin:0;padding:28px;font-size:13px;}
  .rp-head{display:flex;align-items:center;gap:14px;border-bottom:2px solid #000;padding-bottom:14px;margin-bottom:18px;}
  .rp-head img{height:46px;width:auto;}
  .rp-head h1{font-size:1.15rem;margin:0;font-weight:700;}
  .rp-head .rp-sub{font-size:.8rem;color:#333;margin-top:2px;}
  .rp-title{text-align:right;margin-left:auto;}
  .rp-title b{display:block;font-size:1rem;}
  .rp-title span{font-size:.78rem;color:#333;}
  table{width:100%;border-collapse:collapse;margin-bottom:18px;}
  th,td{border:1px solid #000;padding:6px 8px;text-align:left;font-size:.82rem;}
  th{background:#f0f0f0;font-weight:700;}
  tfoot td{font-weight:700;background:#f7f7f7;}
  .rp-foot{margin-top:28px;padding-top:10px;border-top:1px solid #999;font-size:.72rem;color:#777;text-align:center;}
  .rp-actions{margin-bottom:16px;}
  @media print{.rp-actions{display:none;}body{padding:0;}}
</style>
</head>
<body>
  <div class="rp-actions"><button onclick="window.print()">Print / Save as PDF</button></div>
  <div class="rp-head">
    <?php if ($logo): ?><img src="<?php echo rm_h($logo); ?>" alt=""><?php endif; ?>
    <div><h1><?php echo rm_h($shopName); ?></h1></div>
    <div class="rp-title"><b><?php echo rm_h($title); ?></b><span><?php echo rm_h($subtitle); ?></span></div>
  </div>
  <?php echo $bodyHtml; ?>
  <div class="rp-foot">Generated from dazupos &middot; <?php echo date('j M Y, H:i'); ?></div>
</body>
</html>
<?php
    exit;
}
