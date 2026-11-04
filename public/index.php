<?php
// public/index.php — the staff terminal. Type your PIN, then Login, Clock In
// or Clock Out. Owners go to /admin instead.
// domain/<shop-slug>/<page> lands here (no such file) — run that page.
require_once __DIR__ . '/../app/helpers/ShopUrl.php';
if ($__shopPage = ShopUrl::dispatch()) {
    require $__shopPage;
    exit;
}
require_once __DIR__ . '/../app/app.php';

// Already fully logged in? Skip straight to the dashboard.
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['otp_verified'])) {
    $dest = ($_SESSION['role'] ?? '') === 'staff' ? public_url('staff/dashboard/') : public_url('super/dashboard/');
    header('Location: ' . $dest);
    exit;
}

$error = '';
$notice = '';

// Single-tenant deployment: there's only ever one shop, so its branding can
// be shown before anyone logs in. No database / no tables yet (fresh install
// or just reset) → send them to the setup wizard instead of an error page.
// Opened at domain/<slug>/ → that shop's terminal (its name, logo and staff
// only). Opened at the plain domain → the shared terminal with the platform
// brand, open to every shop's staff.
$shopSlug = ShopUrl::requestSlug();

// The plain domain is the public landing page (product, packages, demo, quote).
// Staff terminals: domain/terminal/ (shared) and domain/<slug>/ (a shop's own).
if ($shopSlug === null && !defined('DAZU_TERMINAL')) {
    require __DIR__ . '/components/landing/page.php';
    exit;
}
try {
    $pdo = Database::pdo();
    $shop = $shopSlug !== null ? (new Models\TenantModel($pdo))->primary() : null;
    if ($shopSlug === null) {
        $pdo->query('SELECT 1 FROM tenants LIMIT 1'); // no tables yet → setup wizard
    }
} catch (Throwable $e) {
    header('Location: ' . public_url('setup/'));
    exit;
}
if ($shop) {
    $shopName = $shop['name'];
    $shopLogo = Branding::shopLogo($shop);
} else {
    $__brand = Branding::platform();
    $shopName = $__brand['name'] ?: 'Dazu Modern POS';
    $shopLogo = $__brand['logo'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin    = trim($_POST['pin'] ?? '');
    $action = in_array($_POST['action'] ?? '', ['login', 'clock_in', 'clock_out'], true) ? $_POST['action'] : 'login';

    $stmt = $pdo->prepare(
        "SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id
           JOIN tenants t ON t.id = u.tenant_id
          WHERE r.role_name = 'staff' AND u.is_active = 1 AND u.pin_hash IS NOT NULL AND t.status = 'active'"
        // Opened through a shop's address: only that shop's (and its branches') staff.
        . (!empty($shop['id'])
            ? ' AND (t.id = ' . (int) $shop['id'] . ' OR t.parent_tenant_id = ' . (int) $shop['id'] . ')' : '')
    );
    $stmt->execute();
    $user = null;
    foreach ($stmt->fetchAll() as $row) {
        if (password_verify($pin, $row['pin_hash'])) {
            $user = $row;
            break;
        }
    }

    if (!$user) {
        $error = 'Wrong PIN. Try again.';
    } elseif ($action === 'clock_in') {
        $res = (new Models\TimeLogModel($pdo))->clockIn((int) $user['tenant_id'], (int) $user['id']);
        if ($res['ok']) { $notice = htmlspecialchars($user['username']) . ' clocked in at ' . $res['at'] . '.'; }
        else { $error = $res['error']; }
    } elseif ($action === 'clock_out') {
        $res = (new Models\TimeLogModel($pdo))->clockOut((int) $user['tenant_id'], (int) $user['id']);
        if ($res['ok']) { $notice = htmlspecialchars($user['username']) . ' clocked out at ' . $res['at'] . '.'; }
        else { $error = $res['error']; }
    } elseif (!(new Models\TimeLogModel($pdo))->hasClockedInToday((int) $user['tenant_id'], (int) $user['id'])) {
        $error = 'Clock in first — tap Clock In above before logging in.';
    } else { // login
        session_regenerate_id(true);
        TenantContext::establish($pdo, $user);
        UserSessions::start();
        $_SESSION['username']     = $user['username'];
        $_SESSION['logged_in']    = true;
        $_SESSION['otp_verified'] = true;
        $_SESSION['must_reset']   = false;
        header('Location: ' . public_url('staff/dashboard/'));
        exit;
    }
}
// Same split screen as the owner sign-in, with the Staff tab open.
$ownerUrl = $shop ? rtrim((string) BASE_URL, '/') . '/' . $shopSlug . '/admin/' : public_url('admin/');
$__pinAction = ''; // post back to this terminal (shared or the shop's own address)
$page_title = $shopName . ' — Staff terminal';
ob_start();
?>
<style>
.who{display:grid;grid-template-columns:1fr 1fr;border:1.5px solid var(--pos-green);border-radius:2px;margin-bottom:22px;}
.who a,.who span{padding:10px;font:600 .9rem Archivo,system-ui,sans-serif;text-align:center;text-decoration:none;}
.who a{color:var(--pos-green);background:#fff;}
.who span{background:var(--pos-green);color:#fff;}
</style>
<div class="auth-title">Sign in</div>
<div class="auth-sub">Staff: enter your PIN to sell, clock in or clock out.</div>
<div class="who"><a href="<?php echo htmlspecialchars($ownerUrl); ?>"><i class="fas fa-user-tie me-1"></i>Owner</a><span><i class="fas fa-users me-1"></i>Staff</span></div>
<?php if ($error): ?><div class="auth-alert err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($notice): ?><div class="auth-alert ok"><?php echo $notice; ?></div><?php endif; ?>
<?php include __DIR__ . '/components/staff_pin.php'; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/templates/auth/layout.php';
