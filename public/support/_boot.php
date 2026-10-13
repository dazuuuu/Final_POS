<?php
// public/support/_boot.php — shared by every Support console page (except login).
require_once __DIR__ . '/../../app/app.php';

if (empty($_SESSION['support_auth'])) {
    header('Location: ' . public_url('support/login.php'));
    exit;
}
$_SESSION['support_csrf'] ??= bin2hex(random_bytes(16));

function support_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . $_SESSION['support_csrf'] . '">';
}

function support_posted(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST'
        && hash_equals($_SESSION['support_csrf'], $_POST['csrf'] ?? '');
}

/** Flash message shown once on the next page. */
function support_flash(?string $msg = null, string $type = 'success'): ?array
{
    if ($msg !== null) {
        $_SESSION['support_flash'] = [$type, $msg];
        return null;
    }
    $f = $_SESSION['support_flash'] ?? null;
    unset($_SESSION['support_flash']);
    return $f;
}

/** All accounts (top-level tenants) with owner + branch count. */
function support_accounts(PDO $pdo): array
{
    return $pdo->query(
        'SELECT t.*, u.username AS owner_username, u.email AS owner_email, u.is_active AS owner_active,
                (SELECT COUNT(*) FROM tenants b WHERE b.parent_tenant_id = t.id) AS branch_count
           FROM tenants t
           LEFT JOIN users u ON u.id = t.owner_user_id
          WHERE t.parent_tenant_id IS NULL
          ORDER BY t.created_at DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

function support_package_badges(int $accountId): string
{
    $html = '';
    foreach (Features::forAccount($accountId) as $key => $on) {
        if ($on) {
            [$label, , , $icon] = Features::catalog()[$key];
            $html .= '<span class="badge rounded-pill pkg-on me-1"><i class="fas ' . $icon . ' me-1"></i>' . htmlspecialchars($label) . '</span>';
        }
    }
    return $html ?: '<span class="badge rounded-pill bg-light text-muted">No package</span>';
}

function support_status_badge(string $status): string
{
    $cls = ['active' => 'bg-success', 'suspended' => 'bg-warning text-dark', 'cancelled' => 'bg-secondary'][$status] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . htmlspecialchars(ucfirst($status)) . '</span>';
}
