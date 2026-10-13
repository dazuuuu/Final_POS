<?php
// public/super/restaurant/_common.php — shared by the Restaurant POS pages.
// Only reachable for accounts holding the Restaurant POS package (see
// Features::gate). Data is per shop (tenant_id = the current shop).
//
//   rest_ingredients       recipe ingredients (onion, bun, meat pie…)
//   rest_stock             goods received into the store (soda crates, flour…)
//   rest_stock_receipts    every delivery into the store (initial + restocks)
//   rest_outlet_items      stock moved out of the store to the Counter (to sell)
//                          or the Kitchen, one row per type (Coke, Fanta…)
//   rest_transfers         log of each move out of the store
//   rest_dishes            menu items (name, image, price)
//   rest_dish_ingredients  a dish's default ingredients — what an order uses
//                          unless the customer removes some ("without onions")
//   rest_categories        menu categories
//   rest_orders / rest_order_items / rest_payments
//                          orders: 'held' (parked), 'open' (placed, awaiting
//                          payment on the Payments page), 'paid', 'cancelled'.
//                          Stock is deducted when an order is paid.
require_once __DIR__ . '/../../../app/app.php';
require_once APP_ROOT . '/helpers/uploads.php';
// Setup pages need inventory rights; the order screen sets REST_CAP to sales.record.
// The public customer menu (public/m/) defines REST_PUBLIC: no login, and it
// resolves its restaurant from the share code instead of the session.
if (!defined('REST_PUBLIC')) {
    PageGuard::capability(defined('REST_CAP') ? REST_CAP : Capabilities::INVENTORY_EDIT);
}

const REST_UNITS = ['pcs' => 'pcs', 'kg' => 'kg', 'g' => 'g', 'l' => 'litres', 'ml' => 'ml'];
const REST_STOCK_UNITS = ['pcs' => 'pcs', 'pairs' => 'pairs', 'sets' => 'sets', 'crates' => 'crates', 'cartons' => 'cartons', 'packets' => 'packets', 'bags' => 'bags', 'boxes' => 'boxes', 'kg' => 'kg', 'g' => 'g', 'l' => 'litres', 'ml' => 'ml'];
/** Stock units that hold several pieces — these ask "how many inside one?". */
const REST_CONTAINER_UNITS = ['crates', 'cartons', 'packets', 'bags', 'boxes'];
/** Weight/volume units and their size in the family's smallest unit. */
const REST_FACTORS = ['kg' => ['mass', 1000], 'g' => ['mass', 1], 'l' => ['vol', 1000], 'ml' => ['vol', 1]];
const REST_PAY_METHODS = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'card' => 'Card'];
const REST_OUTLETS = ['counter' => 'Counter (sell)', 'kitchen' => 'Kitchen'];

function rest_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_ingredients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
        unit VARCHAR(10) NOT NULL DEFAULT 'pcs',
        cost_price DECIMAL(12,2) NULL,
        charge_price DECIMAL(12,2) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_ri_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_stock (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        unit VARCHAR(10) NOT NULL DEFAULT 'pcs',
        quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
        supplier_id INT NULL,
        image_path VARCHAR(255) NULL,
        cost_price DECIMAL(12,2) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_rs_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_stock_receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        stock_id INT NOT NULL,
        quantity DECIMAL(12,3) NOT NULL,
        supplier_id INT NULL,
        cost_price DECIMAL(12,2) NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rsr_stock (tenant_id, stock_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_outlet_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        outlet ENUM('counter','kitchen') NOT NULL,
        stock_id INT NOT NULL,
        type_name VARCHAR(120) NOT NULL DEFAULT '',
        quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
        selling_price DECIMAL(12,2) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_roi (tenant_id, outlet, stock_id, type_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        stock_id INT NOT NULL,
        outlet ENUM('counter','kitchen') NOT NULL,
        store_quantity DECIMAL(12,3) NOT NULL,
        breakdown JSON NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rt_tenant (tenant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_dishes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        category VARCHAR(80) NULL,
        description VARCHAR(255) NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        image_path VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_rd_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_dish_ingredients (
        dish_id INT NOT NULL,
        ingredient_id INT NOT NULL,
        quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
        PRIMARY KEY (dish_id, ingredient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        name VARCHAR(80) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rc (tenant_id, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        order_no VARCHAR(20) NOT NULL,
        order_type ENUM('dine_in','takeaway') NOT NULL DEFAULT 'dine_in',
        table_label VARCHAR(40) NULL,
        customer_name VARCHAR(120) NULL,
        status ENUM('open','paid','cancelled') NOT NULL DEFAULT 'open',
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        change_due DECIMAL(12,2) NOT NULL DEFAULT 0,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        paid_at DATETIME NULL,
        KEY idx_ro_tenant (tenant_id, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        item_type ENUM('dish','counter') NOT NULL,
        ref_id INT NOT NULL,
        name VARCHAR(160) NOT NULL,
        unit_price DECIMAL(12,2) NOT NULL,
        quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
        removed JSON NULL,
        note VARCHAR(255) NULL,
        line_total DECIMAL(12,2) NOT NULL,
        KEY idx_roi_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        order_id INT NOT NULL,
        method VARCHAR(20) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        reference VARCHAR(60) NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rp_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    rest_column($pdo, 'rest_ingredients', 'pieces_per_unit', 'DECIMAL(12,3) NULL AFTER unit');
    rest_column($pdo, 'rest_stock', 'pieces_per_unit', 'DECIMAL(12,3) NULL AFTER unit');
    rest_column($pdo, 'rest_dishes', 'category_id', 'INT NULL AFTER name');
    rest_column($pdo, 'rest_dish_ingredients', 'measure', "VARCHAR(10) NOT NULL DEFAULT '' AFTER quantity");
    rest_column($pdo, 'rest_dish_ingredients', 'base_quantity', 'DECIMAL(12,4) NULL AFTER measure');
    rest_column($pdo, 'rest_dish_ingredients', 'line_price', 'DECIMAL(12,2) NULL AFTER base_quantity');
    rest_column($pdo, 'rest_dishes', 'available_from', 'TIME NULL AFTER price');
    rest_column($pdo, 'rest_dishes', 'available_to', 'TIME NULL AFTER available_from');
    rest_column($pdo, 'rest_orders', 'paid_by', 'INT NULL AFTER paid_at');
    rest_column($pdo, 'rest_orders', 'source', "ENUM('pos','online') NOT NULL DEFAULT 'pos' AFTER order_no");
    rest_column($pdo, 'rest_orders', 'customer_phone', 'VARCHAR(30) NULL AFTER customer_name');
    rest_column($pdo, 'rest_orders', 'delivery_address', 'VARCHAR(255) NULL AFTER customer_phone');
    rest_column($pdo, 'rest_orders', 'client_ip', 'VARCHAR(45) NULL AFTER delivery_address');
    $otype = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rest_orders' AND COLUMN_NAME = 'order_type'")->fetchColumn();
    if (strpos((string) $otype, "'delivery'") === false) {
        $pdo->exec("ALTER TABLE rest_orders MODIFY order_type ENUM('dine_in','takeaway','delivery') NOT NULL DEFAULT 'dine_in'");
    }
    // Public menu / online ordering settings, one row per shop.
    $pdo->exec("CREATE TABLE IF NOT EXISTS rest_settings (
        tenant_id INT NOT NULL PRIMARY KEY,
        public_token VARCHAR(32) NOT NULL,
        online_enabled TINYINT(1) NOT NULL DEFAULT 1,
        allow_dine_in TINYINT(1) NOT NULL DEFAULT 1,
        allow_takeaway TINYINT(1) NOT NULL DEFAULT 1,
        allow_delivery TINYINT(1) NOT NULL DEFAULT 1,
        notice VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rs_token (public_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 'held' = parked cart, 'open' = placed and awaiting payment.
    $enum = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rest_orders' AND COLUMN_NAME = 'status'")->fetchColumn();
    if (strpos((string) $enum, "'held'") === false) {
        $pdo->exec("ALTER TABLE rest_orders MODIFY status ENUM('held','open','paid','cancelled') NOT NULL DEFAULT 'open'");
    }
    $done = true;
}

function rest_column(PDO $pdo, string $table, string $column, string $ddl): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    if (!$stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $ddl");
    }
}

/**
 * Convert a recipe quantity into the ingredient's own unit.
 * $measure is 'pc' (pieces, needs pieces_per_unit), a weight/volume unit in
 * the same family as the ingredient's unit, or '' / the unit itself.
 */
function rest_to_base(float $qty, string $measure, array $ingredient): float
{
    $unit = $ingredient['unit'];
    if ($measure === 'pc' && $unit !== 'pcs') {
        $ppu = (float) ($ingredient['pieces_per_unit'] ?? 0);
        return $ppu > 0 ? $qty / $ppu : $qty;
    }
    if (isset(REST_FACTORS[$measure], REST_FACTORS[$unit]) && REST_FACTORS[$measure][0] === REST_FACTORS[$unit][0]) {
        return $qty * REST_FACTORS[$measure][1] / REST_FACTORS[$unit][1];
    }
    return $qty;
}

/** Measures a recipe line may use for this ingredient. */
function rest_measures(array $ingredient): array
{
    $unit = $ingredient['unit'];
    $out = [];
    if ($unit === 'pcs' || (float) ($ingredient['pieces_per_unit'] ?? 0) > 0) {
        $out['pc'] = 'pcs';
    }
    if (isset(REST_FACTORS[$unit])) {
        foreach (REST_FACTORS as $u => [$fam]) {
            if ($fam === REST_FACTORS[$unit][0]) $out[$u] = $u;
        }
    }
    return $out ?: [$unit => $unit];
}

const REST_ORDER_TYPES = ['dine_in' => 'Dine in', 'takeaway' => 'Takeaway', 'delivery' => 'Delivery'];

/** This shop's public-menu settings (created with a fresh share code on first use). */
function rest_settings(PDO $pdo, int $tid): array
{
    $stmt = $pdo->prepare('SELECT * FROM rest_settings WHERE tenant_id = ?');
    $stmt->execute([$tid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->prepare('INSERT INTO rest_settings (tenant_id, public_token) VALUES (?, ?)')->execute([$tid, bin2hex(random_bytes(6))]);
        return rest_settings($pdo, $tid);
    }
    return $row;
}

function rest_public_url(string $token, ?string $table = null): string
{
    return app_url('m/?r=' . $token . ($table !== null && $table !== '' ? '&t=' . rawurlencode($table) : ''));
}

function rest_categories(PDO $pdo, int $tid): array
{
    $stmt = $pdo->prepare('SELECT * FROM rest_categories WHERE tenant_id = ? ORDER BY sort_order, name');
    $stmt->execute([$tid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pdo = Database::pdo();
rest_schema($pdo);
$tid = defined('REST_PUBLIC') ? 0 : (int) TenantContext::tenantId();
$__tenant = $tid ? (new Models\TenantModel($pdo))->find($tid) : null;
$currency = $__tenant['currency'] ?? 'KES';

/** Optional money input: '' → null. */
function rest_money(?string $v): ?float
{
    $v = trim((string) $v);
    return $v === '' ? null : max(0, round((float) $v, 2));
}

function rest_qty(float $q): string
{
    return rtrim(rtrim(number_format($q, 3, '.', ','), '0'), '.');
}

function rest_price(?float $v, string $currency): string
{
    return $v === null ? '<span class="text-muted">—</span>' : $currency . ' ' . number_format($v, 2);
}

/** Save an optional uploaded image for this shop; returns [path|null, error|null]. */
function rest_image(string $field, int $tid): array
{
    if (empty($_FILES[$field]['name'])) {
        return [null, null];
    }
    $up = upload_image($_FILES[$field], PUBLIC_ROOT . '/assets/uploads/restaurant/' . $tid,
                       'assets/uploads/restaurant/' . $tid, ['jpg', 'jpeg', 'png', 'webp']);
    return $up['ok'] ? [$up['path'], null] : [null, $up['error']];
}

function rest_flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][$type] = $msg;
}

function rest_render(string $title, string $content): void
{
    global $__tenant;
    $page_title = $title;
    include PUBLIC_ROOT . '/templates/' . (TenantContext::role() === 'staff' ? 'staff' : 'tenants') . '/layout.php';
}

const REST_STATUS = [
    'held'      => ['Held', 'bg-warning text-dark'],
    'open'      => ['Awaiting payment', 'bg-info text-dark'],
    'paid'      => ['Paid', 'bg-success'],
    'cancelled' => ['Cancelled', 'bg-secondary'],
];

/** Owners see every order; staff only the orders they took. */
function rest_sees_all(): bool
{
    return TenantContext::role() === 'tenant_owner';
}

/**
 * Is a dish orderable at $now? available_from/to are optional times of day;
 * a window like 22:00–02:00 wraps past midnight.
 */
function rest_dish_available(array $dish, ?int $now = null): bool
{
    if (empty($dish['available_from']) || empty($dish['available_to'])) {
        return true;
    }
    $t = date('H:i:s', $now ?? time());
    $from = $dish['available_from'];
    $to = $dish['available_to'];
    return $from <= $to ? ($t >= $from && $t < $to) : ($t >= $from || $t < $to);
}

function rest_window_label(array $dish): string
{
    if (empty($dish['available_from']) || empty($dish['available_to'])) {
        return '';
    }
    return date('g:ia', strtotime($dish['available_from'])) . '–' . date('g:ia', strtotime($dish['available_to']));
}

/**
 * Take payment for an unpaid order: check counter stock, deduct stock
 * (counter items + the ingredients of each dish the customer kept), record
 * the payments and mark the order paid. Cash over-tender becomes change;
 * M-Pesa/card can't exceed what's due.
 * @return array ['ok'=>bool, 'error'=>?string, 'change'=>float]
 */
function rest_pay_order(PDO $pdo, int $tid, int $orderId, array $rawPayments): array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM rest_orders WHERE id = ? AND tenant_id = ? AND status IN ('held','open') FOR UPDATE");
        $stmt->execute([$orderId, $tid]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new RuntimeException('That order is already paid or cancelled.');
        }
        if (!rest_sees_all() && (int) $order['user_id'] !== (int) TenantContext::userId() && !TenantContext::can(Capabilities::PAYMENTS_PROCESS)) {
            throw new RuntimeException('You can only take payment for your own orders.');
        }
        $total = (float) $order['total'];

        $payments = [];
        foreach ($rawPayments as $p) {
            $amt = round((float) ($p['amount'] ?? 0), 2);
            $method = isset(REST_PAY_METHODS[$p['method'] ?? '']) ? $p['method'] : null;
            if ($amt > 0 && $method) {
                $payments[] = ['method' => $method, 'amount' => $amt, 'ref' => mb_substr(trim((string) ($p['ref'] ?? '')), 0, 60) ?: null];
            }
        }
        $paid = array_sum(array_column($payments, 'amount'));
        $nonCash = array_sum(array_column(array_filter($payments, fn($p) => $p['method'] !== 'cash'), 'amount'));
        if ($paid + 0.001 < $total) {
            throw new RuntimeException('Payments (' . number_format($paid, 2) . ') are less than the total (' . number_format($total, 2) . ').');
        }
        if ($nonCash > $total + 0.001) {
            throw new RuntimeException('M-Pesa/card payments can\'t be more than the total.');
        }

        $items = $pdo->prepare('SELECT * FROM rest_order_items WHERE order_id = ?');
        $items->execute([$orderId]);
        $items = $items->fetchAll(PDO::FETCH_ASSOC);

        $decCounter = $pdo->prepare('UPDATE rest_outlet_items SET quantity = quantity - ? WHERE id = ? AND tenant_id = ? AND quantity >= ?');
        $recipe = $pdo->prepare('SELECT ingredient_id, COALESCE(base_quantity, quantity) AS base FROM rest_dish_ingredients WHERE dish_id = ?');
        // Ingredients are tracked loosely: never below zero, never block a sale.
        $decIng = $pdo->prepare('UPDATE rest_ingredients SET quantity = GREATEST(0, quantity - ?) WHERE id = ? AND tenant_id = ?');
        foreach ($items as $it) {
            $qty = (float) $it['quantity'];
            if ($it['item_type'] === 'counter') {
                $decCounter->execute([$qty, $it['ref_id'], $tid, $qty]);
                if (!$decCounter->rowCount()) {
                    throw new RuntimeException('Not enough ' . $it['name'] . ' at the counter. Edit the order first.');
                }
            } else {
                $removed = json_decode($it['removed'] ?? '[]', true) ?: [];
                $recipe->execute([$it['ref_id']]);
                foreach ($recipe->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (!in_array((int) $r['ingredient_id'], $removed, true)) {
                        $decIng->execute([(float) $r['base'] * $qty, $r['ingredient_id'], $tid]);
                    }
                }
            }
        }

        $ins = $pdo->prepare('INSERT INTO rest_payments (tenant_id, order_id, method, amount, reference, user_id) VALUES (?,?,?,?,?,?)');
        foreach ($payments as $p) {
            $ins->execute([$tid, $orderId, $p['method'], $p['amount'], $p['ref'], TenantContext::userId()]);
        }
        $change = round($paid - $total, 2);
        $pdo->prepare("UPDATE rest_orders SET status = 'paid', paid_amount = ?, change_due = ?, paid_at = NOW(), paid_by = ? WHERE id = ?")
            ->execute([$paid, $change, TenantContext::userId(), $orderId]);
        $pdo->commit();
        return ['ok' => true, 'error' => null, 'change' => $change];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not take payment: ' . $e->getMessage(), 'change' => 0];
    }
}
