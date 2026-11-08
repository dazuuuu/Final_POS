<?php
// public/stock/_boot.php — start of every Stock Control page.
// stock_guard() lets in only people of a Stock Control organisation who
// passed the emailed sign-in code, and keeps them inside /stock/.
require_once __DIR__ . '/../../app/app.php';

const STOCK_NAME = 'Stock Control';

function sh($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
/**
 * The organisation whose address this is (domain/<slug>/…), or the signed-in
 * person's organisation. Its name and logo brand the sign-in page.
 */
function stock_org(): ?array
{
    static $org = false;
    if ($org !== false) return $org;
    $org = null;
    $slug = ShopUrl::requestSlug();
    if ($slug !== null) {
        $row = Signup::findBySlug($slug);
        if ($row && Features::isStockControl((int) $row['id'])) $org = $row;
    } elseif (!empty($_SESSION['stock_slug'])) {
        $org = Signup::findBySlug((string) $_SESSION['stock_slug']);
    }
    return $org;
}

/** Links stay on the organisation's own address: domain/<slug>/stock/… */
function stock_url(string $path = ''): string
{
    $org = stock_org();
    if ($org) return rtrim((string) BASE_URL, '/') . '/' . $org['slug'] . '/stock/' . ltrim($path, '/');
    return public_url('stock/' . ltrim($path, '/'));
}
function stock_qty($q): string { return rtrim(rtrim(number_format((float) $q, 2), '0'), '.'); }
function stock_money($v): string
{
    static $cur = null;
    if ($cur === null) {
        $st = Database::pdo()->prepare('SELECT currency FROM tenants WHERE id = ?');
        $st->execute([StockControl::account()]);
        $cur = $st->fetchColumn() ?: 'KES';
    }
    return $cur . ' ' . number_format((float) $v, 2);
}

function stock_csrf(): string
{
    $_SESSION['stock_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['stock_csrf'];
}
function stock_csrf_field(): string { return '<input type="hidden" name="_t" value="' . stock_csrf() . '">'; }
function stock_csrf_ok(): bool { return hash_equals(stock_csrf(), (string) ($_POST['_t'] ?? '')); }

function stock_flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['stock_flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['stock_flash'] ?? null;
    unset($_SESSION['stock_flash']);
    return $f;
}
function stock_go(string $path, ?string $msg = null, string $type = 'ok'): void
{
    if ($msg !== null) stock_flash($msg, $type);
    header('Location: ' . stock_url($path));
    exit;
}

/** Signed in with the emailed code, in a Stock Control organisation. */
function stock_signed_in(): bool
{
    return !empty($_SESSION['logged_in']) && !empty($_SESSION['stock_verified']) && TenantContext::userId()
        && TenantContext::tenantId() && Features::isStockControl();
}

/** Every page (except sign-in pages). $right: a staff right the page needs. */
function stock_guard(?string $right = null, bool $adminOnly = false): void
{
    if (!stock_signed_in()) {
        unset($_SESSION['stock_verified']);
        stock_go('login.php');
    }
    if (AccountLock::isLocked(StockControl::account())) {
        foreach (array_keys($_SESSION) as $k) unset($_SESSION[$k]);
        stock_go('login.php', 'This organisation\'s account is locked. Please contact support.', 'err');
    }
    if (!empty($_SESSION['must_reset']) && basename($_SERVER['SCRIPT_NAME']) !== 'password.php') {
        stock_go('password.php');
    }
    if (($adminOnly && !StockControl::isAdmin()) || ($right !== null && !StockControl::can($right))) {
        stock_go('', 'You don\'t have access to that page.', 'err');
    }
    StockControl::ensure();
}

/** The store being worked in. Admins switch with ?store=ID; staff stay in their own. */
function stock_store(): int
{
    $mine = StockControl::myStores();
    if (isset($_GET['store']) && isset($mine[(int) $_GET['store']])) {
        $_SESSION['tenant_id'] = (int) $_GET['store'];
        TenantContext::boot();
    }
    $cur = (int) TenantContext::tenantId();
    if (!isset($mine[$cur]) && $mine) {
        $_SESSION['tenant_id'] = $cur = (int) array_key_first($mine);
        TenantContext::boot();
    }
    return $cur;
}
