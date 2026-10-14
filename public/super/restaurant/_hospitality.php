<?php
// public/super/restaurant/_hospitality.php — shared by the Restaurant + Rooms
// package pages (rooms, guest stays, table bookings, family platters). Pages
// define REST_CAP (the capability they need) before including this.
//
//   room_rooms            the rooms: floor, what's inside, room service,
//                         rules and prices (night / 3 days / week / rental)
//   room_settings         per shop: late check-out fee per hour (0 = off)
//   room_stays            a guest's booking: guest + companions, the agreed
//                         price, booked → checked_in → checked_out
//   room_stay_charges     room service and other extras added during a stay
//   room_stay_payments    deposit at booking and the payment at check-out
//   rest_platters / rest_platter_items
//                         family platters: several meals for one price, with
//                         free items and drinks marked
//   rest_table_bookings / rest_table_booking_items / rest_table_booking_payments
//                         group & family table bookings with a pre-set menu
require_once __DIR__ . '/../../../app/app.php';
require_once __DIR__ . '/_common.php';

if (!Features::enabled(Features::RESTAURANT_ROOMS)) {
    $_SESSION['flash']['error'] = 'Rooms and table bookings come with the Restaurant + Rooms package.';
    header('Location: ' . public_url(TenantContext::role() === 'staff' ? 'staff/dashboard/' : 'super/dashboard/'));
    exit;
}

const ROOM_PLANS = [
    'night'  => ['Per night', 1],
    '3days'  => ['3-day package', 3],
    'week'   => ['Per week', 7],
    'rental' => ['Rental (agreed)', 30],
];
const ROOM_SERVICE = ['none' => 'No room service', 'free' => 'Room service — free', 'paid' => 'Room service — charged'];
const ROOM_STATUS = [
    'booked'      => ['Booked', 'bg-info text-dark'],
    'checked_in'  => ['In house', 'bg-warning text-dark'],
    'checked_out' => ['Checked out', 'bg-success'],
    'cancelled'   => ['Cancelled', 'bg-secondary'],
];
const TB_STATUS = [
    'booked'    => ['Booked', 'bg-info text-dark'],
    'arrived'   => ['Arrived', 'bg-warning text-dark'],
    'completed' => ['Paid', 'bg-success'],
    'cancelled' => ['Cancelled', 'bg-secondary'],
];

function hosp_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) { return; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS room_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        name VARCHAR(80) NOT NULL,
        floor VARCHAR(40) NULL,
        description TEXT NULL,
        beds VARCHAR(120) NULL,
        bathroom VARCHAR(120) NULL,
        toilet VARCHAR(120) NULL,
        sitting VARCHAR(120) NULL,
        amenities TEXT NULL,
        food_included VARCHAR(255) NULL,
        room_service ENUM('none','free','paid') NOT NULL DEFAULT 'none',
        room_service_price DECIMAL(12,2) NULL,
        rules TEXT NULL,
        price_night DECIMAL(12,2) NULL,
        price_3days DECIMAL(12,2) NULL,
        price_week DECIMAL(12,2) NULL,
        price_rental DECIMAL(12,2) NULL,
        rental_terms VARCHAR(255) NULL,
        status ENUM('available','maintenance') NOT NULL DEFAULT 'available',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_room_tenant (tenant_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS room_settings (
        tenant_id INT NOT NULL PRIMARY KEY,
        late_fee_per_hour DECIMAL(12,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS room_stays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        stay_no VARCHAR(20) NOT NULL DEFAULT '',
        room_id INT NOT NULL,
        room_name VARCHAR(80) NOT NULL,
        status ENUM('booked','checked_in','checked_out','cancelled') NOT NULL DEFAULT 'booked',
        guest_name VARCHAR(150) NOT NULL,
        guest_phone VARCHAR(40) NULL,
        guest_email VARCHAR(150) NULL,
        guest_id_number VARCHAR(60) NULL,
        guest_address VARCHAR(255) NULL,
        companions JSON NULL,
        rate_plan VARCHAR(10) NOT NULL DEFAULT 'night',
        nights INT NOT NULL DEFAULT 1,
        room_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        extras_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        late_hours INT NOT NULL DEFAULT 0,
        late_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        check_in_at DATETIME NOT NULL,
        due_out_at DATETIME NOT NULL,
        checked_in_at DATETIME NULL,
        checked_out_at DATETIME NULL,
        notes VARCHAR(255) NULL,
        created_by INT NULL,
        checked_out_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_stay_tenant (tenant_id, status, check_in_at),
        KEY idx_stay_room (room_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS room_stay_charges (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        stay_id INT NOT NULL,
        description VARCHAR(150) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rsc_stay (stay_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS room_stay_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        stay_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        method VARCHAR(20) NOT NULL,
        reference VARCHAR(60) NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rsp_stay (stay_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_platters (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(255) NULL,
        serves VARCHAR(40) NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_platter_tenant (tenant_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_platter_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platter_id INT NOT NULL,
        dish_id INT NULL,
        name VARCHAR(120) NOT NULL,
        quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
        is_free TINYINT(1) NOT NULL DEFAULT 0,
        is_drink TINYINT(1) NOT NULL DEFAULT 0,
        KEY idx_pi_platter (platter_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_table_bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        booking_no VARCHAR(20) NOT NULL DEFAULT '',
        customer_name VARCHAR(150) NOT NULL,
        customer_phone VARCHAR(40) NULL,
        customer_email VARCHAR(150) NULL,
        party_size INT NOT NULL DEFAULT 1,
        tables_label VARCHAR(120) NULL,
        booking_at DATETIME NOT NULL,
        notes VARCHAR(255) NULL,
        status ENUM('booked','arrived','completed','cancelled') NOT NULL DEFAULT 'booked',
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_by INT NULL,
        paid_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tb_tenant (tenant_id, status, booking_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_table_booking_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        kind ENUM('dish','platter','custom') NOT NULL,
        ref_id INT NULL,
        name VARCHAR(160) NOT NULL,
        quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        is_free TINYINT(1) NOT NULL DEFAULT 0,
        is_drink TINYINT(1) NOT NULL DEFAULT 0,
        contents TEXT NULL,
        KEY idx_tbi_booking (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_table_booking_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        booking_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        method VARCHAR(20) NOT NULL,
        reference VARCHAR(60) NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tbp_booking (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}
hosp_schema($pdo);

function hosp_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES);
}

function hosp_money($n): string
{
    global $currency;
    return $currency . ' ' . number_format((float) $n, 2);
}

function hosp_badge(array $map, string $status): string
{
    [$l, $c] = $map[$status] ?? [$status, 'bg-light text-dark'];
    return '<span class="badge ' . $c . '">' . $l . '</span>';
}

function room_settings(PDO $pdo, int $tid): array
{
    $stmt = $pdo->prepare('SELECT * FROM room_settings WHERE tenant_id = ?');
    $stmt->execute([$tid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['tenant_id' => $tid, 'late_fee_per_hour' => 0];
}

function room_find(PDO $pdo, int $tid, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM room_rooms WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function room_list(PDO $pdo, int $tid): array
{
    $stmt = $pdo->prepare('SELECT * FROM room_rooms WHERE tenant_id = ? AND is_active = 1 ORDER BY floor, name');
    $stmt->execute([$tid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function stay_find(PDO $pdo, int $tid, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT s.*, u.username AS created_by_name, o.username AS checked_out_by_name FROM room_stays s
                        LEFT JOIN users u ON u.id = s.created_by LEFT JOIN users o ON o.id = s.checked_out_by
                            WHERE s.id = ? AND s.tenant_id = ?');
    $stmt->execute([$id, $tid]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) { return null; }
    $s['companions'] = json_decode((string) $s['companions'], true) ?: [];
    $c = $pdo->prepare('SELECT * FROM room_stay_charges WHERE stay_id = ? ORDER BY id');
    $c->execute([$id]);
    $s['charges'] = $c->fetchAll(PDO::FETCH_ASSOC);
    $p = $pdo->prepare('SELECT * FROM room_stay_payments WHERE stay_id = ? ORDER BY id');
    $p->execute([$id]);
    $s['payments'] = $p->fetchAll(PDO::FETCH_ASSOC);
    return $s;
}

/**
 * Late check-out: each night is 24 hours from check-in. Every started hour
 * past the due time is charged at the shop's fee (0 = no penalty).
 * Returns [hours, fee].
 */
function stay_late(array $stay, float $feePerHour, ?int $now = null): array
{
    $now ??= time();
    $over = $now - strtotime($stay['due_out_at']);
    if ($over <= 0 || $feePerHour <= 0) { return [0, 0.0]; }
    $hours = (int) ceil($over / 3600);
    return [$hours, round($hours * $feePerHour, 2)];
}

/** Recompute a stay's extras / total from its charges. */
function stay_recalc(PDO $pdo, int $stayId): void
{
    $pdo->prepare('UPDATE room_stays s SET extras_amount = (SELECT COALESCE(SUM(amount),0) FROM room_stay_charges c WHERE c.stay_id = s.id),
                          total = GREATEST(0, room_amount + extras_amount + late_fee - discount) WHERE s.id = ?')->execute([$stayId]);
}

/** Is the room free between two times (ignoring one stay)? */
function room_is_free(PDO $pdo, int $roomId, string $from, string $to, int $ignoreStay = 0): ?array
{
    // A guest still checked in holds the room until they actually leave, even past their due time.
    $stmt = $pdo->prepare("SELECT * FROM room_stays WHERE room_id = ? AND id <> ? AND status IN ('booked','checked_in')
                              AND check_in_at < ? AND IF(status = 'checked_in', GREATEST(due_out_at, NOW()), due_out_at) > ? LIMIT 1");
    $stmt->execute([$roomId, $ignoreStay, $to, $from]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function tb_find(PDO $pdo, int $tid, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT b.*, u.username AS created_by_name FROM rest_table_bookings b LEFT JOIN users u ON u.id = b.created_by WHERE b.id = ? AND b.tenant_id = ?');
    $stmt->execute([$id, $tid]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) { return null; }
    $i = $pdo->prepare('SELECT * FROM rest_table_booking_items WHERE booking_id = ? ORDER BY is_drink, id');
    $i->execute([$id]);
    $b['items'] = $i->fetchAll(PDO::FETCH_ASSOC);
    $p = $pdo->prepare('SELECT * FROM rest_table_booking_payments WHERE booking_id = ? ORDER BY id');
    $p->execute([$id]);
    $b['payments'] = $p->fetchAll(PDO::FETCH_ASSOC);
    return $b;
}

function platter_list(PDO $pdo, int $tid, bool $activeOnly = true): array
{
    $stmt = $pdo->prepare('SELECT * FROM rest_platters WHERE tenant_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY name');
    $stmt->execute([$tid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $it = $pdo->prepare('SELECT * FROM rest_platter_items WHERE platter_id = ? ORDER BY is_drink, is_free, id');
    foreach ($rows as &$r) {
        $it->execute([$r['id']]);
        $r['items'] = $it->fetchAll(PDO::FETCH_ASSOC);
    }
    return $rows;
}

/** "12 × Ugali, 3 × Sukuma (FREE), 2 × Soda [drink]" — snapshot of a platter's contents. */
function platter_contents(array $items): string
{
    return implode(', ', array_map(fn($i) => rest_qty((float) $i['quantity']) . ' × ' . $i['name']
        . ($i['is_free'] ? ' (FREE)' : '') . ($i['is_drink'] ? ' [drink]' : ''), $items));
}

/** Print-friendly sheet styles + printer driver (auto-prints when $autoPrint). */
function hosp_print_head(bool $autoPrint = false): void
{
    include PUBLIC_ROOT . '/components/printer_script.php'; ?>
<style>
  .sheet{background:#fff;max-width:400px;margin:0 auto;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.1);padding:22px;font-size:13px;color:#0f172a;}
  @media print { body *{visibility:hidden !important;} .sheet,.sheet *{visibility:visible !important;} .sheet{position:absolute;left:0;top:0;box-shadow:none;max-width:none;width:100%;} }
</style>
<?php
}

/** Business header for invoices/receipts. */
function hosp_sheet_header(string $docTitle, string $number, string $date): string
{
    global $__tenant;
    $logo = Branding::tenantLogo($__tenant);
    $h = '<div style="text-align:center;border-bottom:2px dashed #cbd5e1;padding-bottom:10px;margin-bottom:10px;">';
    if ($logo) { $h .= '<img src="' . hosp_h($logo) . '" alt="" style="max-height:44px;max-width:160px;object-fit:contain;margin-bottom:6px;">'; }
    $h .= '<div style="font-size:16px;font-weight:700;">' . hosp_h($__tenant['name'] ?? '') . '</div>';
    if (!empty($__tenant['phone'])) { $h .= '<div style="font-size:12px;">TEL: ' . hosp_h($__tenant['phone']) . '</div>'; }
    if (!empty($__tenant['address'])) { $h .= '<div style="font-size:12px;">' . hosp_h($__tenant['address']) . '</div>'; }
    if (!empty($__tenant['kra_pin'])) { $h .= '<div style="font-size:12px;">PIN: ' . hosp_h($__tenant['kra_pin']) . '</div>'; }
    $h .= '<div style="font-size:14px;font-weight:700;margin-top:6px;">' . hosp_h($docTitle) . ' ' . hosp_h($number) . '</div>';
    $h .= '<div style="font-size:12px;">DATE: ' . hosp_h($date) . '</div></div>';
    return $h;
}

