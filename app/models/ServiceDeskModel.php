<?php
// app/models/ServiceDeskModel.php
// Services package. The account keeps one price list of services; each
// service is offered in the branches the owner ticks. A client visit (a
// "ticket") holds the client's details and one line per service; each line can
// be assigned to a staff member, who sees it under "My clients" and marks it
// done. The front desk books visits for later; the cashier takes payment and
// prints the receipt. Staff earn commission per service line, worked out from
// the owner's rules (per service, per weekday, per staff — most specific wins).
//
//   svc_services          price list (account-wide)
//   svc_service_shops     which shop offers which service
//   svc_tickets           client visits: booked → open → paid (or cancelled)
//   svc_ticket_items      one line on a visit: a service (its staff, done or not,
//                         commission) or a product sold from the shop's stock
//                         (hair, gel…) — stock comes off when it's added and
//                         goes back if it's removed or the visit is cancelled
//   svc_commission_rules  % rules; NULL service/weekday/staff = "any"
//   svc_payouts           commission paid out to a staff member. Each paid
//                         line is stamped with its payout, so a staff
//                         member's balance (unstamped commission) goes back
//                         to zero when they're paid.
// Staff can also record a job they did themselves ("Record my job"): a paid
// visit with source = 'staff'. They tick whether it earns commission or the
// whole amount is theirs (staff_keeps_all → 100%).
namespace Models;

class ServiceDeskModel
{
    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? \Database::pdo();
        self::ensureSchema($this->db);
    }

    public static function ensureSchema(\PDO $db): void
    {
        static $done = false;
        if ($done) { return; }
        $db->exec("CREATE TABLE IF NOT EXISTS svc_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            description VARCHAR(255) NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            duration_min INT NULL,
            commission_pct DECIMAL(5,2) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_svc_account (account_id, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS svc_service_shops (
            service_id INT NOT NULL,
            tenant_id INT NOT NULL,
            PRIMARY KEY (service_id, tenant_id),
            KEY idx_svc_shop (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS svc_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            number VARCHAR(20) NOT NULL DEFAULT '',
            customer_id INT NULL,
            customer_name VARCHAR(150) NOT NULL,
            customer_phone VARCHAR(40) NULL,
            customer_email VARCHAR(150) NULL,
            status ENUM('booked','open','paid','cancelled') NOT NULL DEFAULT 'open',
            scheduled_at DATETIME NULL,
            notes VARCHAR(255) NULL,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(10) NULL,
            cash_amount DECIMAL(12,2) NULL,
            mpesa_amount DECIMAL(12,2) NULL,
            amount_tendered DECIMAL(12,2) NULL,
            change_due DECIMAL(12,2) NULL,
            created_by INT NULL,
            paid_by INT NULL,
            paid_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_svc_ticket_shop (tenant_id, status, created_at),
            KEY idx_svc_ticket_sched (tenant_id, scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS svc_ticket_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            tenant_id INT NOT NULL,
            service_id INT NULL,
            service_name VARCHAR(150) NOT NULL,
            kind ENUM('service','product') NOT NULL DEFAULT 'service',
            product_id INT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            staff_id INT NULL,
            status ENUM('pending','done') NOT NULL DEFAULT 'pending',
            done_at DATETIME NULL,
            commission_pct DECIMAL(5,2) NULL,
            commission_amount DECIMAL(12,2) NULL,
            KEY idx_svc_item_ticket (ticket_id),
            KEY idx_svc_item_staff (staff_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS svc_commission_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            service_id INT NULL,
            weekday TINYINT NULL,
            staff_id INT NULL,
            pct DECIMAL(5,2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_svc_rule_account (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $chk = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'svc_ticket_items' AND column_name = 'kind'");
        if (!(int) $chk->fetchColumn()) {
            $db->exec("ALTER TABLE svc_ticket_items ADD COLUMN kind ENUM('service','product') NOT NULL DEFAULT 'service' AFTER service_name,
                       ADD COLUMN product_id INT NULL AFTER kind, ADD COLUMN quantity DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER product_id");
        }
        $col = fn(string $t, string $c) => (int) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$t' AND column_name = '$c'")->fetchColumn();
        if (!$col('svc_ticket_items', 'payout_id')) {
            $db->exec('ALTER TABLE svc_ticket_items ADD COLUMN payout_id INT NULL, ADD COLUMN staff_keeps_all TINYINT(1) NOT NULL DEFAULT 0, ADD KEY idx_svc_item_payout (staff_id, payout_id)');
        }
        if (!$col('svc_tickets', 'source')) {
            $db->exec("ALTER TABLE svc_tickets ADD COLUMN source ENUM('desk','staff') NOT NULL DEFAULT 'desk'");
        }
        $db->exec("CREATE TABLE IF NOT EXISTS svc_payouts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            staff_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            items INT NOT NULL DEFAULT 0,
            method VARCHAR(10) NOT NULL DEFAULT 'cash',
            note VARCHAR(255) NULL,
            paid_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_svc_payout_staff (account_id, staff_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    private function account(?int $tid = null): int
    {
        return \Features::accountId($tid ?? (int) \TenantContext::tenantId());
    }

    private function tid(): int
    {
        return (int) \TenantContext::tenantId();
    }

    // ---- price list -------------------------------------------------------

    /** Every service of the account, with the shop ids that offer it. */
    public function services(bool $activeOnly = false): array
    {
        $stmt = $this->db->prepare('SELECT * FROM svc_services WHERE account_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY active DESC, name');
        $stmt->execute([$this->account()]);
        $rows = $stmt->fetchAll();
        $shops = $this->db->prepare('SELECT tenant_id FROM svc_service_shops WHERE service_id = ?');
        foreach ($rows as &$r) {
            $shops->execute([$r['id']]);
            $r['shop_ids'] = array_map('intval', $shops->fetchAll(\PDO::FETCH_COLUMN));
        }
        unset($r);
        return $rows;
    }

    /** Active services this shop offers. */
    public function servicesForShop(?int $tid = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.* FROM svc_services s JOIN svc_service_shops ss ON ss.service_id = s.id
              WHERE ss.tenant_id = ? AND s.active = 1 ORDER BY s.name'
        );
        $stmt->execute([$tid ?? $this->tid()]);
        return $stmt->fetchAll();
    }

    public function saveService(array $in, ?int $id = null): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') { return ['ok' => false, 'error' => 'Enter the service name.']; }
        $price = (float) ($in['price'] ?? -1);
        if (!is_numeric($in['price'] ?? null) || $price < 0) { return ['ok' => false, 'error' => 'Enter the price (0 or more).']; }
        $pct = ($in['commission_pct'] ?? '') === '' ? null : max(0, min(100, (float) $in['commission_pct']));
        $dur = (int) ($in['duration_min'] ?? 0) ?: null;
        $shopIds = array_values(array_filter(array_map('intval', (array) ($in['shops'] ?? [])), fn($t) => \ShopContext::canAccess($t)));
        if (!$shopIds) { $shopIds = [$this->tid()]; } // single shop: it's offered here
        $desc = trim((string) ($in['description'] ?? '')) ?: null;

        if ($id) {
            $own = $this->db->prepare('SELECT 1 FROM svc_services WHERE id = ? AND account_id = ?');
            $own->execute([$id, $this->account()]);
            if (!$own->fetchColumn()) { return ['ok' => false, 'error' => 'Service not found.']; }
            $this->db->prepare('UPDATE svc_services SET name = ?, description = ?, price = ?, duration_min = ?, commission_pct = ?, active = ? WHERE id = ?')
                ->execute([$name, $desc, round($price, 2), $dur, $pct, empty($in['inactive']) ? 1 : 0, $id]);
        } else {
            $this->db->prepare('INSERT INTO svc_services (account_id, name, description, price, duration_min, commission_pct) VALUES (?,?,?,?,?,?)')
                ->execute([$this->account(), $name, $desc, round($price, 2), $dur, $pct]);
            $id = (int) $this->db->lastInsertId();
        }
        $this->db->prepare('DELETE FROM svc_service_shops WHERE service_id = ?')->execute([$id]);
        $ins = $this->db->prepare('INSERT INTO svc_service_shops (service_id, tenant_id) VALUES (?,?)');
        foreach (array_unique($shopIds) as $t) { $ins->execute([$id, $t]); }
        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    // ---- people -----------------------------------------------------------

    /** Who can be assigned work in this shop: its active staff, plus the owner. */
    public function staffForShop(?int $tid = null): array
    {
        $tid ??= $this->tid();
        $stmt = $this->db->prepare(
            "SELECT u.id, u.username, u.position, r.role_name FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.is_active = 1 AND ((u.tenant_id = ? AND r.role_name = 'staff')
                    OR u.id = (SELECT owner_user_id FROM tenants WHERE id = ?))
           ORDER BY (r.role_name = 'tenant_owner') ASC, u.username"
        );
        $stmt->execute([$tid, $this->account($tid)]);
        return $stmt->fetchAll();
    }

    private function canBeAssigned(?int $staffId, int $tid): bool
    {
        if (!$staffId) { return true; }
        return in_array($staffId, array_map('intval', array_column($this->staffForShop($tid), 'id')), true);
    }

    // ---- visits -----------------------------------------------------------

    /**
     * New client visit. $in: customer_name (required), customer_phone, customer_email,
     * customer_id, scheduled_at (a booking — blank for a walk-in), notes,
     * lines: [{service_id, staff_id}], products: [{product_id, quantity}].
     */
    public function create(array $in): array
    {
        $tid = $this->tid();
        $name = trim((string) ($in['customer_name'] ?? ''));
        if ($name === '') { return ['ok' => false, 'error' => 'Enter the client\'s name.']; }
        $email = trim((string) ($in['customer_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { return ['ok' => false, 'error' => 'That email address doesn\'t look right.']; }
        $when = trim((string) ($in['scheduled_at'] ?? ''));
        $scheduled = null;
        if ($when !== '') {
            $ts = strtotime($when);
            if ($ts === false) { return ['ok' => false, 'error' => 'Enter a valid booking date and time.']; }
            $scheduled = date('Y-m-d H:i:s', $ts);
        }
        $offered = [];
        foreach ($this->servicesForShop($tid) as $s) { $offered[(int) $s['id']] = $s; }
        $lines = [];
        foreach ((array) ($in['lines'] ?? []) as $l) {
            $sid = (int) ($l['service_id'] ?? 0);
            if (!$sid) { continue; }
            if (!isset($offered[$sid])) { return ['ok' => false, 'error' => 'One of those services isn\'t offered in this shop.']; }
            $staff = (int) ($l['staff_id'] ?? 0) ?: null;
            if (!$this->canBeAssigned($staff, $tid)) { return ['ok' => false, 'error' => 'Pick a staff member of this shop.']; }
            $lines[] = [$offered[$sid], $staff];
        }
        $products = array_values(array_filter((array) ($in['products'] ?? []), fn($p) => (int) ($p['product_id'] ?? 0) > 0 && (float) ($p['quantity'] ?? 0) > 0));
        if (!$lines && !$products) { return ['ok' => false, 'error' => 'Add at least one service or product.']; }

        $this->db->beginTransaction();
        try {
            $subtotal = round(array_sum(array_map(fn($l) => (float) $l[0]['price'], $lines)), 2);
            $this->db->prepare(
                'INSERT INTO svc_tickets (tenant_id, customer_id, customer_name, customer_phone, customer_email, status, scheduled_at, notes, subtotal, total, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$tid, (int) ($in['customer_id'] ?? 0) ?: null, mb_substr($name, 0, 150), trim((string) ($in['customer_phone'] ?? '')) ?: null,
                        $email ?: null, $scheduled && strtotime($scheduled) > time() ? 'booked' : 'open', $scheduled,
                        trim((string) ($in['notes'] ?? '')) !== '' ? mb_substr(trim($in['notes']), 0, 255) : null, $subtotal, $subtotal, \TenantContext::userId()]);
            $id = (int) $this->db->lastInsertId();
            $number = 'SVC-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            $this->db->prepare('UPDATE svc_tickets SET number = ? WHERE id = ?')->execute([$number, $id]);
            $ins = $this->db->prepare('INSERT INTO svc_ticket_items (ticket_id, tenant_id, service_id, service_name, price, staff_id) VALUES (?,?,?,?,?,?)');
            foreach ($lines as [$svc, $staff]) {
                $ins->execute([$id, $tid, $svc['id'], $svc['name'], $svc['price'], $staff]);
            }
            foreach ($products as $p) {
                $err = $this->insertProduct($id, $tid, (int) $p['product_id'], (float) $p['quantity']);
                if ($err) { $this->db->rollBack(); return ['ok' => false, 'error' => $err]; }
            }
            $this->recalc($id);
            $this->db->commit();
            return ['ok' => true, 'error' => null, 'id' => $id, 'number' => $number];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('ServiceDeskModel::create failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save this client. Please try again.'];
        }
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, u.username AS created_by_name, pu.username AS paid_by_name FROM svc_tickets t
          LEFT JOIN users u ON u.id = t.created_by LEFT JOIN users pu ON pu.id = t.paid_by
              WHERE t.id = ? AND t.tenant_id = ?'
        );
        $stmt->execute([$id, $this->tid()]);
        $t = $stmt->fetch();
        if (!$t) { return null; }
        $t['items'] = $this->items($id);
        return $t;
    }

    public function items(int $ticketId): array
    {
        $stmt = $this->db->prepare(
            'SELECT i.*, u.username AS staff_name FROM svc_ticket_items i LEFT JOIN users u ON u.id = i.staff_id
              WHERE i.ticket_id = ? ORDER BY i.id'
        );
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    /** Visits of this shop. $view: today | booked | open | paid | all. */
    public function tickets(string $view = 'today', string $q = '', int $limit = 200): array
    {
        $sql = "SELECT t.*,
                       (SELECT COUNT(*) FROM svc_ticket_items i WHERE i.ticket_id = t.id AND i.kind = 'service') AS item_count,
                       (SELECT COUNT(*) FROM svc_ticket_items i WHERE i.ticket_id = t.id AND i.kind = 'service' AND i.status = 'done') AS done_count,
                       (SELECT GROUP_CONCAT(i.service_name SEPARATOR ', ') FROM svc_ticket_items i WHERE i.ticket_id = t.id AND i.kind = 'service') AS services,
                       (SELECT GROUP_CONCAT(CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM i.quantity)), ' × ', i.service_name) SEPARATOR ', ') FROM svc_ticket_items i WHERE i.ticket_id = t.id AND i.kind = 'product') AS products,
                       (SELECT GROUP_CONCAT(DISTINCT u.username SEPARATOR ', ') FROM svc_ticket_items i JOIN users u ON u.id = i.staff_id WHERE i.ticket_id = t.id) AS staff_names
                  FROM svc_tickets t WHERE t.tenant_id = ?";
        $args = [$this->tid()];
        if ($view === 'today') {
            $sql .= " AND t.status <> 'cancelled' AND (DATE(COALESCE(t.scheduled_at, t.created_at)) = CURDATE() OR (t.status IN ('open') ))";
        } elseif ($view === 'booked') {
            $sql .= " AND t.status = 'booked'";
        } elseif ($view === 'open') {
            $sql .= " AND t.status = 'open'";
        } elseif ($view === 'paid') {
            $sql .= " AND t.status = 'paid'";
        }
        if (trim($q) !== '') {
            $sql .= ' AND (t.customer_name LIKE ? OR t.customer_phone LIKE ? OR t.number LIKE ?)';
            $like = '%' . trim($q) . '%';
            array_push($args, $like, $like, $like);
        }
        $sql .= $view === 'booked' ? ' ORDER BY t.scheduled_at ASC' : ' ORDER BY COALESCE(t.scheduled_at, t.created_at) DESC, t.id DESC';
        $stmt = $this->db->prepare($sql . ' LIMIT ' . (int) $limit);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public function counts(): array
    {
        $stmt = $this->db->prepare("SELECT status, COUNT(*) n FROM svc_tickets WHERE tenant_id = ? GROUP BY status");
        $stmt->execute([$this->tid()]);
        return array_merge(['booked' => 0, 'open' => 0, 'paid' => 0, 'cancelled' => 0], $stmt->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    /** "My clients": service lines assigned to a staff member (pending first, then recent done). */
    public function assignedTo(int $userId, bool $includeDone = true): array
    {
        $stmt = $this->db->prepare(
            "SELECT i.*, t.number, t.customer_name, t.customer_phone, t.status AS ticket_status, t.scheduled_at, t.notes, t.created_at AS ticket_created
               FROM svc_ticket_items i JOIN svc_tickets t ON t.id = i.ticket_id
              WHERE i.staff_id = ? AND i.kind = 'service' AND t.status <> 'cancelled'" . ($includeDone ? '' : " AND i.status = 'pending'") . "
           ORDER BY (i.status = 'done') ASC, COALESCE(t.scheduled_at, t.created_at) ASC, i.id ASC LIMIT 200"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** The assigned staff member (or the front desk / owner) marks one service done. */
    public function markDone(int $itemId, int $userId, bool $isManager): array
    {
        $stmt = $this->db->prepare(
            'SELECT i.*, t.tenant_id AS ticket_tenant, t.status AS ticket_status FROM svc_ticket_items i JOIN svc_tickets t ON t.id = i.ticket_id WHERE i.id = ?'
        );
        $stmt->execute([$itemId]);
        $it = $stmt->fetch();
        if (!$it || (!$isManager && (int) $it['staff_id'] !== $userId) || ($isManager && (int) $it['ticket_tenant'] !== $this->tid() && (int) $it['staff_id'] !== $userId)) {
            return ['ok' => false, 'error' => 'That service isn\'t yours to mark.'];
        }
        if ($it['ticket_status'] === 'cancelled') { return ['ok' => false, 'error' => 'This visit was cancelled.']; }
        if ($it['status'] === 'done') { return ['ok' => true, 'error' => null]; }
        $this->db->prepare("UPDATE svc_ticket_items SET status = 'done', done_at = NOW() WHERE id = ?")->execute([$itemId]);
        // A booked client being served has clearly arrived.
        $this->db->prepare("UPDATE svc_tickets SET status = 'open' WHERE id = ? AND status = 'booked'")->execute([$it['ticket_id']]);
        return ['ok' => true, 'error' => null];
    }

    public function assign(int $itemId, ?int $staffId): array
    {
        $stmt = $this->db->prepare('SELECT i.*, t.status AS ticket_status, t.scheduled_at, t.created_at AS ticket_created, t.subtotal, t.total
                                      FROM svc_ticket_items i JOIN svc_tickets t ON t.id = i.ticket_id WHERE i.id = ? AND t.tenant_id = ?');
        $stmt->execute([$itemId, $this->tid()]);
        $it = $stmt->fetch();
        if (!$it) { return ['ok' => false, 'error' => 'Service not found.']; }
        if (!$this->canBeAssigned($staffId, $this->tid())) { return ['ok' => false, 'error' => 'Pick a staff member of this shop.']; }
        $this->db->prepare('UPDATE svc_ticket_items SET staff_id = ? WHERE id = ?')->execute([$staffId, $itemId]);
        if ($it['ticket_status'] === 'paid') { $this->computeCommissions((int) $it['ticket_id']); } // the right person earns it
        return ['ok' => true, 'error' => null];
    }

    public function checkIn(int $ticketId): void
    {
        $this->db->prepare("UPDATE svc_tickets SET status = 'open' WHERE id = ? AND tenant_id = ? AND status = 'booked'")->execute([$ticketId, $this->tid()]);
    }

    public function cancel(int $ticketId): array
    {
        $this->db->beginTransaction();
        $stmt = $this->db->prepare("UPDATE svc_tickets SET status = 'cancelled' WHERE id = ? AND tenant_id = ? AND status IN ('booked','open')");
        $stmt->execute([$ticketId, $this->tid()]);
        if (!$stmt->rowCount()) { $this->db->rollBack(); return ['ok' => false, 'error' => 'Only an unpaid visit can be cancelled.']; }
        // Products the client didn't take go back on the shelf.
        $this->db->prepare(
            "UPDATE products p JOIN svc_ticket_items i ON i.product_id = p.id
                SET p.quantity = p.quantity + i.quantity WHERE i.ticket_id = ? AND i.kind = 'product' AND p.tenant_id = ?"
        )->execute([$ticketId, $this->tid()]);
        $this->db->commit();
        return ['ok' => true, 'error' => null];
    }

    /** Products in stock in this shop that can go on a visit (not serial-tracked phones). */
    public function sellableProducts(): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.tenant_id = ? AND p.status IN ('active','archived') AND p.quantity > 0 ORDER BY p.name"
        );
        $stmt->execute([$this->tid()]);
        $out = [];
        foreach ($stmt->fetchAll() as $p) {
            if (!empty($p['is_serialized'])) { continue; }
            $p['sell_price'] = self::productPrice($p);
            $out[] = $p;
        }
        return $out;
    }

    private static function productPrice(array $p): float
    {
        if (!array_key_exists('offer_price', $p)) { $p += ['offer_price' => null, 'offer_starts_at' => null, 'offer_ends_at' => null]; }
        $price = ProductModel::effectivePrice($p)['price'];
        return (float) ($price > 0 ? $price : ($p['retail_price'] ?: ($p['selling_price'] ?: $p['wholesale_price'])));
    }

    /** Put a product on an open visit (in the caller's transaction). Returns an error message or null. */
    private function insertProduct(int $ticketId, int $tid, int $productId, float $qty): ?string
    {
        $qty = round($qty, 2);
        $sel = $this->db->prepare("SELECT * FROM products WHERE id = ? AND tenant_id = ? AND status IN ('active','archived') FOR UPDATE");
        $sel->execute([$productId, $tid]);
        $p = $sel->fetch();
        if (!$p) { return 'One of those products is no longer available.'; }
        if (!empty($p['is_serialized'])) { return $p['name'] . ' needs a serial / IMEI — sell it from the POS.'; }
        if ($qty <= 0) { return 'Enter how many ' . $p['name'] . '.'; }
        if ($qty > (float) $p['quantity'] + 0.0001) { return 'Only ' . rtrim(rtrim(number_format((float) $p['quantity'], 2), '0'), '.') . ' × ' . $p['name'] . ' in stock.'; }
        $dec = $this->db->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ? AND tenant_id = ? AND quantity >= ?');
        $dec->execute([$qty, $productId, $tid, $qty]);
        if ($dec->rowCount() !== 1) { return 'Stock for ' . $p['name'] . ' changed — try again.'; }
        $this->db->prepare(
            "INSERT INTO svc_ticket_items (ticket_id, tenant_id, service_name, kind, product_id, quantity, price, status, done_at) VALUES (?,?,?, 'product', ?,?,?, 'done', NOW())"
        )->execute([$ticketId, $tid, $p['name'], $productId, $qty, self::productPrice($p)]);
        return null;
    }

    private function recalc(int $ticketId): void
    {
        $this->db->prepare(
            "UPDATE svc_tickets t SET subtotal = (SELECT COALESCE(ROUND(SUM(i.price * i.quantity), 2), 0) FROM svc_ticket_items i WHERE i.ticket_id = t.id),
                    total = subtotal - LEAST(discount_amount, subtotal) WHERE t.id = ?"
        )->execute([$ticketId]);
    }

    /** Front desk adds a product to an unpaid visit. */
    public function addProduct(int $ticketId, int $productId, float $qty): array
    {
        $this->db->beginTransaction();
        try {
            $t = $this->db->prepare("SELECT status FROM svc_tickets WHERE id = ? AND tenant_id = ? FOR UPDATE");
            $t->execute([$ticketId, $this->tid()]);
            $status = $t->fetchColumn();
            if (!in_array($status, ['booked', 'open'], true)) { $this->db->rollBack(); return ['ok' => false, 'error' => 'Products can only be added before payment.']; }
            $err = $this->insertProduct($ticketId, $this->tid(), $productId, $qty);
            if ($err) { $this->db->rollBack(); return ['ok' => false, 'error' => $err]; }
            $this->recalc($ticketId);
            $this->db->commit();
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('ServiceDeskModel::addProduct failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not add that product.'];
        }
    }

    /** Take a line off an unpaid visit (a product goes back on the shelf). */
    public function removeItem(int $itemId): array
    {
        $this->db->beginTransaction();
        $stmt = $this->db->prepare("SELECT i.*, t.status AS ticket_status FROM svc_ticket_items i JOIN svc_tickets t ON t.id = i.ticket_id WHERE i.id = ? AND t.tenant_id = ? FOR UPDATE");
        $stmt->execute([$itemId, $this->tid()]);
        $it = $stmt->fetch();
        if (!$it || !in_array($it['ticket_status'], ['booked', 'open'], true)) { $this->db->rollBack(); return ['ok' => false, 'error' => 'Only lines on an unpaid visit can be removed.']; }
        $left = $this->db->prepare('SELECT COUNT(*) FROM svc_ticket_items WHERE ticket_id = ?');
        $left->execute([$it['ticket_id']]);
        if ((int) $left->fetchColumn() <= 1) { $this->db->rollBack(); return ['ok' => false, 'error' => 'A visit needs at least one line — cancel the visit instead.']; }
        if ($it['kind'] === 'product' && $it['product_id']) {
            $this->db->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ? AND tenant_id = ?')->execute([$it['quantity'], $it['product_id'], $this->tid()]);
        }
        $this->db->prepare('DELETE FROM svc_ticket_items WHERE id = ?')->execute([$itemId]);
        $this->recalc((int) $it['ticket_id']);
        $this->db->commit();
        return ['ok' => true, 'error' => null];
    }

    /**
     * Take payment. $p: method (cash|mpesa|split), discount_amount,
     * amount_tendered (cash), cash_amount / mpesa_amount (split).
     */
    public function pay(int $ticketId, array $p): array
    {
        $method = in_array($p['method'] ?? '', ['cash', 'mpesa', 'split'], true) ? $p['method'] : null;
        if (!$method) { return ['ok' => false, 'error' => 'Choose how the client paid.']; }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM svc_tickets WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $stmt->execute([$ticketId, $this->tid()]);
            $t = $stmt->fetch();
            if (!$t) { $this->db->rollBack(); return ['ok' => false, 'error' => 'Visit not found.']; }
            if ($t['status'] === 'paid') { $this->db->rollBack(); return ['ok' => false, 'error' => 'Already paid.']; }
            if ($t['status'] === 'cancelled') { $this->db->rollBack(); return ['ok' => false, 'error' => 'This visit was cancelled.']; }
            $subtotal = (float) $t['subtotal'];
            $discount = min(max(0, round((float) ($p['discount_amount'] ?? 0), 2)), $subtotal);
            $total = round($subtotal - $discount, 2);
            $cash = $mpesa = 0.0;
            $tendered = $change = null;
            if ($method === 'cash') {
                $tendered = round((float) ($p['amount_tendered'] ?? 0), 2) ?: $total;
                if ($tendered + 0.0001 < $total) { $this->db->rollBack(); return ['ok' => false, 'error' => 'Cash given is less than the total.']; }
                $cash = $total;
                $change = round($tendered - $total, 2);
            } elseif ($method === 'mpesa') {
                $mpesa = $total;
            } else {
                $c = max(0, round((float) ($p['cash_amount'] ?? 0), 2));
                $m = max(0, round((float) ($p['mpesa_amount'] ?? 0), 2));
                if ($c + $m + 0.0001 < $total) { $this->db->rollBack(); return ['ok' => false, 'error' => 'Cash and M-Pesa are less than the total.']; }
                $mpesa = min($m, $total);
                $cash = round($total - $mpesa, 2);
                $tendered = round($c + $m, 2);
                $change = round($c + $m - $total, 2);
            }
            $this->db->prepare(
                "UPDATE svc_tickets SET status = 'paid', discount_amount = ?, total = ?, payment_method = ?, cash_amount = ?, mpesa_amount = ?,
                        amount_tendered = ?, change_due = ?, paid_by = ?, paid_at = NOW() WHERE id = ?"
            )->execute([$discount, $total, $method, $cash ?: null, $mpesa ?: null, $tendered, $change, \TenantContext::userId(), $ticketId]);
            $this->db->commit();
            $this->computeCommissions($ticketId);
            return ['ok' => true, 'error' => null, 'total' => $total, 'change' => $change];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('ServiceDeskModel::pay failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record the payment. Please try again.'];
        }
    }

    // ---- commission -------------------------------------------------------

    /**
     * Commission on each staffed line of a paid visit: the line's share of the
     * (discounted) total × the % from the most specific matching rule, else
     * the service's default %. The day is the day the service was booked for
     * (or the visit day for a walk-in).
     */
    private function computeCommissions(int $ticketId): void
    {
        $t = $this->db->prepare('SELECT * FROM svc_tickets WHERE id = ?');
        $t->execute([$ticketId]);
        $ticket = $t->fetch();
        if (!$ticket || $ticket['status'] !== 'paid') { return; }
        $weekday = (int) date('N', strtotime($ticket['scheduled_at'] ?: $ticket['created_at']));
        $ratio = (float) $ticket['subtotal'] > 0 ? (float) $ticket['total'] / (float) $ticket['subtotal'] : 1.0;
        $account = $this->account((int) $ticket['tenant_id']);
        $rules = $this->rules($account);
        $upd = $this->db->prepare('UPDATE svc_ticket_items SET commission_pct = ?, commission_amount = ? WHERE id = ?');
        $svcDefault = $this->db->prepare('SELECT commission_pct FROM svc_services WHERE id = ?');
        foreach ($this->items($ticketId) as $it) {
            if (!$it['staff_id'] || $it['kind'] !== 'service') { $upd->execute([null, null, $it['id']]); continue; }
            if (!empty($it['staff_keeps_all'])) { $upd->execute([100, round((float) $it['price'] * $ratio, 2), $it['id']]); continue; }
            $svcDefault->execute([$it['service_id']]);
            $default = $svcDefault->fetchColumn();
            $pct = self::pctFor($rules, (int) $it['service_id'], $weekday, (int) $it['staff_id'], $default === false || $default === null ? null : (float) $default);
            $amount = $pct === null ? null : round((float) $it['price'] * $ratio * $pct / 100, 2);
            $upd->execute([$pct, $amount, $it['id']]);
        }
    }

    /** Most specific rule that matches wins (each of service / weekday / staff set counts); on a tie the higher % wins. */
    public static function pctFor(array $rules, int $serviceId, int $weekday, int $staffId, ?float $default): ?float
    {
        $best = null;
        $bestScore = -1;
        foreach ($rules as $r) {
            if ($r['service_id'] !== null && (int) $r['service_id'] !== $serviceId) { continue; }
            if ($r['weekday'] !== null && (int) $r['weekday'] !== $weekday) { continue; }
            if ($r['staff_id'] !== null && (int) $r['staff_id'] !== $staffId) { continue; }
            $score = ($r['service_id'] !== null ? 1 : 0) + ($r['weekday'] !== null ? 1 : 0) + ($r['staff_id'] !== null ? 1 : 0);
            if ($score > $bestScore || ($score === $bestScore && (float) $r['pct'] > $best)) { $best = (float) $r['pct']; $bestScore = $score; }
        }
        // A service's own default beats an "any service, any day, anyone" rule.
        if ($default !== null && $bestScore <= 0) { return $default; }
        return $best;
    }

    public function rules(?int $account = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, s.name AS service_name, u.username AS staff_name FROM svc_commission_rules r
          LEFT JOIN svc_services s ON s.id = r.service_id LEFT JOIN users u ON u.id = r.staff_id
              WHERE r.account_id = ? ORDER BY r.id'
        );
        $stmt->execute([$account ?? $this->account()]);
        return $stmt->fetchAll();
    }

    public function saveRule(array $in): array
    {
        $pct = $in['pct'] ?? '';
        if (!is_numeric($pct) || (float) $pct < 0 || (float) $pct > 100) { return ['ok' => false, 'error' => 'Enter a commission between 0 and 100%.']; }
        $sid = (int) ($in['service_id'] ?? 0) ?: null;
        $staff = (int) ($in['staff_id'] ?? 0) ?: null;
        $days = array_values(array_filter(array_map('intval', (array) ($in['weekdays'] ?? [])), fn($d) => $d >= 1 && $d <= 7));
        if (!$days) { $days = [null]; }
        $ins = $this->db->prepare('INSERT INTO svc_commission_rules (account_id, service_id, weekday, staff_id, pct) VALUES (?,?,?,?,?)');
        foreach ($days as $d) { $ins->execute([$this->account(), $sid, $d, $staff, round((float) $pct, 2)]); }
        return ['ok' => true, 'error' => null, 'added' => count($days)];
    }

    public function deleteRule(int $id): void
    {
        $this->db->prepare('DELETE FROM svc_commission_rules WHERE id = ? AND account_id = ?')->execute([$id, $this->account()]);
    }

    /** Staff earnings for paid visits between two dates (Y-m-d), for the given shops. */
    public function commissionReport(array $tenantIds, string $from, string $to): array
    {
        $tenantIds = array_values(array_filter(array_map('intval', $tenantIds)));
        if (!$tenantIds) { return []; }
        $in = implode(',', array_fill(0, count($tenantIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT i.staff_id, u.username, sh.name AS shop_name, COUNT(*) AS services, COALESCE(SUM(i.price),0) AS revenue, COALESCE(SUM(i.commission_amount),0) AS commission
               FROM svc_ticket_items i JOIN svc_tickets t ON t.id = i.ticket_id
          LEFT JOIN users u ON u.id = i.staff_id LEFT JOIN tenants sh ON sh.id = t.tenant_id
              WHERE t.tenant_id IN ($in) AND t.status = 'paid' AND i.kind = 'service' AND i.staff_id IS NOT NULL AND t.paid_at BETWEEN ? AND ?
           GROUP BY i.staff_id, u.username, sh.name ORDER BY commission DESC"
        );
        $stmt->execute(array_merge($tenantIds, [$from . ' 00:00:00', $to . ' 23:59:59']));
        return $stmt->fetchAll();
    }

    public function myEarnings(int $userId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) n, COALESCE(SUM(i.commission_amount),0) commission FROM svc_ticket_items i JOIN svc_tickets t ON t.id = i.ticket_id
              WHERE i.staff_id = ? AND t.status = 'paid' AND t.paid_at BETWEEN ? AND ?"
        );
        $stmt->execute([$userId, $from . ' 00:00:00', $to . ' 23:59:59']);
        $r = $stmt->fetch();
        return ['count' => (int) $r['n'], 'commission' => round((float) $r['commission'], 2)];
    }

    // ---- staff-recorded jobs & payouts ---------------------------------------

    /**
     * A staff member records a job they did and was paid for. $in: service_id
     * (optional, from the price list) or service_name, amount, customer_name,
     * customer_phone, method (cash|mpesa), keeps_all (whole amount is theirs), notes.
     */
    public function recordOwnJob(int $userId, array $in): array
    {
        $tid = $this->tid();
        $sid = (int) ($in['service_id'] ?? 0) ?: null;
        $name = trim((string) ($in['service_name'] ?? ''));
        if ($sid) {
            $offered = array_column($this->servicesForShop($tid), null, 'id');
            if (!isset($offered[$sid])) { return ['ok' => false, 'error' => 'That service isn\'t offered in this shop.']; }
            if ($name === '') { $name = $offered[$sid]['name']; }
        }
        if ($name === '') { return ['ok' => false, 'error' => 'Say what service you did.']; }
        $amount = round((float) ($in['amount'] ?? 0), 2);
        if ($amount <= 0) { return ['ok' => false, 'error' => 'Enter how much you charged.']; }
        $method = in_array($in['method'] ?? '', ['cash', 'mpesa'], true) ? $in['method'] : 'cash';
        $client = trim((string) ($in['customer_name'] ?? '')) ?: 'Walk-in client';
        $keepsAll = !empty($in['keeps_all']) ? 1 : 0;

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "INSERT INTO svc_tickets (tenant_id, customer_name, customer_phone, status, notes, subtotal, total, payment_method, cash_amount, mpesa_amount,
                                          created_by, paid_by, paid_at, source)
                 VALUES (?,?,?,'paid',?,?,?,?,?,?,?,?,NOW(),'staff')"
            )->execute([$tid, mb_substr($client, 0, 150), trim((string) ($in['customer_phone'] ?? '')) ?: null,
                        trim((string) ($in['notes'] ?? '')) !== '' ? mb_substr(trim($in['notes']), 0, 255) : null,
                        $amount, $amount, $method, $method === 'cash' ? $amount : null, $method === 'mpesa' ? $amount : null, $userId, $userId]);
            $id = (int) $this->db->lastInsertId();
            $number = 'SVC-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            $this->db->prepare('UPDATE svc_tickets SET number = ? WHERE id = ?')->execute([$number, $id]);
            $this->db->prepare(
                "INSERT INTO svc_ticket_items (ticket_id, tenant_id, service_id, service_name, price, staff_id, status, done_at, staff_keeps_all)
                 VALUES (?,?,?,?,?,?,'done',NOW(),?)"
            )->execute([$id, $tid, $sid, mb_substr($name, 0, 150), $amount, $userId, $keepsAll]);
            $this->db->commit();
            $this->computeCommissions($id);
            return ['ok' => true, 'error' => null, 'id' => $id, 'number' => $number];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('ServiceDeskModel::recordOwnJob failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the job. Please try again.'];
        }
    }

    /** Jobs a staff member recorded themselves, newest first. */
    public function ownJobs(int $userId, int $limit = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.number, t.customer_name, t.payment_method, t.paid_at, i.service_name, i.price, i.staff_keeps_all, i.commission_amount, i.payout_id
               FROM svc_ticket_items i JOIN svc_tickets t ON t.id = i.ticket_id
              WHERE i.staff_id = ? AND t.source = 'staff' ORDER BY t.id DESC LIMIT " . (int) $limit
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** Commission earned but not yet paid out — what the staff portal shows. */
    public function unpaidFor(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) n, COALESCE(SUM(i.commission_amount),0) amount FROM svc_ticket_items i JOIN svc_tickets t ON t.id = i.ticket_id
              WHERE i.staff_id = ? AND i.kind = 'service' AND t.status = 'paid' AND i.payout_id IS NULL AND i.commission_amount > 0"
        );
        $stmt->execute([$userId]);
        $r = $stmt->fetch();
        return ['count' => (int) $r['n'], 'amount' => round((float) $r['amount'], 2)];
    }

    /** Everyone with commission waiting to be paid, in the given shops. */
    public function unpaidBalances(array $tenantIds): array
    {
        $tenantIds = array_values(array_filter(array_map('intval', $tenantIds)));
        if (!$tenantIds) { return []; }
        $in = implode(',', array_fill(0, count($tenantIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT i.staff_id, u.username, COUNT(*) AS services, COALESCE(SUM(i.commission_amount),0) AS amount,
                    MIN(t.paid_at) AS since, SUM(t.source = 'staff') AS own_jobs
               FROM svc_ticket_items i JOIN svc_tickets t ON t.id = i.ticket_id LEFT JOIN users u ON u.id = i.staff_id
              WHERE t.tenant_id IN ($in) AND t.status = 'paid' AND i.kind = 'service' AND i.staff_id IS NOT NULL
                AND i.payout_id IS NULL AND i.commission_amount > 0
           GROUP BY i.staff_id, u.username ORDER BY amount DESC"
        );
        $stmt->execute($tenantIds);
        return $stmt->fetchAll();
    }

    /** Pay a staff member everything they're owed in the given shops; their balance resets to zero. */
    public function payStaff(int $staffId, array $tenantIds, string $method, string $note = ''): array
    {
        $tenantIds = array_values(array_filter(array_map('intval', $tenantIds)));
        if (!$staffId || !$tenantIds) { return ['ok' => false, 'error' => 'Pick a staff member.']; }
        $method = in_array($method, ['cash', 'mpesa', 'bank'], true) ? $method : 'cash';
        $in = implode(',', array_fill(0, count($tenantIds), '?'));
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT i.id, i.commission_amount FROM svc_ticket_items i JOIN svc_tickets t ON t.id = i.ticket_id
                  WHERE i.staff_id = ? AND t.tenant_id IN ($in) AND t.status = 'paid' AND i.kind = 'service'
                    AND i.payout_id IS NULL AND i.commission_amount > 0 FOR UPDATE"
            );
            $stmt->execute(array_merge([$staffId], $tenantIds));
            $rows = $stmt->fetchAll();
            $amount = round(array_sum(array_map(fn($r) => (float) $r['commission_amount'], $rows)), 2);
            if ($amount <= 0) { $this->db->rollBack(); return ['ok' => false, 'error' => 'Nothing to pay — their balance is already zero.']; }
            $this->db->prepare('INSERT INTO svc_payouts (account_id, staff_id, amount, items, method, note, paid_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$this->account(), $staffId, $amount, count($rows), $method, $note !== '' ? mb_substr($note, 0, 255) : null, \TenantContext::userId()]);
            $payout = (int) $this->db->lastInsertId();
            $ids = array_map(fn($r) => (int) $r['id'], $rows);
            $this->db->prepare('UPDATE svc_ticket_items SET payout_id = ? WHERE id IN (' . implode(',', $ids) . ')')->execute([$payout]);
            $this->db->commit();
            return ['ok' => true, 'error' => null, 'amount' => $amount, 'items' => count($rows)];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('ServiceDeskModel::payStaff failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record the payment. Please try again.'];
        }
    }

    /** Past payouts for the account (optionally one staff member). */
    public function payouts(?int $staffId = null, int $limit = 50): array
    {
        $sql = 'SELECT p.*, u.username AS staff_name, pb.username AS paid_by_name FROM svc_payouts p
             LEFT JOIN users u ON u.id = p.staff_id LEFT JOIN users pb ON pb.id = p.paid_by WHERE p.account_id = ?';
        $args = [$this->account()];
        if ($staffId) { $sql .= ' AND p.staff_id = ?'; $args[] = $staffId; }
        $stmt = $this->db->prepare($sql . ' ORDER BY p.id DESC LIMIT ' . (int) $limit);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    // ---- money ------------------------------------------------------------

    /** Paid service income (optionally for a date range, Y-m-d) — no TenantContext needed. */
    public function income(int $tenantId, ?string $from = null, ?string $to = null): array
    {
        $sql = "SELECT COUNT(*) n, COALESCE(SUM(total),0) total, COALESCE(SUM(cash_amount),0) cash, COALESCE(SUM(mpesa_amount),0) mpesa
                  FROM svc_tickets WHERE tenant_id = ? AND status = 'paid'";
        $args = [$tenantId];
        if ($from !== null) { $sql .= ' AND paid_at >= ?'; $args[] = $from . ' 00:00:00'; }
        if ($to !== null) { $sql .= ' AND paid_at <= ?'; $args[] = $to . ' 23:59:59'; }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        $r = $stmt->fetch();
        return ['count' => (int) $r['n'], 'total' => round((float) $r['total'], 2), 'cash' => round((float) $r['cash'], 2), 'mpesa' => round((float) $r['mpesa'], 2)];
    }

    /** Visits paid on one day, for the daily report. */
    public function paidOn(int $tenantId, string $date): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, (SELECT GROUP_CONCAT(CONCAT(IF(i.kind = 'product', CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM i.quantity)), ' × '), ''), i.service_name, IFNULL(CONCAT(' (', u.username, ')'), '')) SEPARATOR ', ')
                            FROM svc_ticket_items i LEFT JOIN users u ON u.id = i.staff_id WHERE i.ticket_id = t.id) AS services
               FROM svc_tickets t WHERE t.tenant_id = ? AND t.status = 'paid' AND DATE(t.paid_at) = ? ORDER BY t.paid_at"
        );
        $stmt->execute([$tenantId, $date]);
        return $stmt->fetchAll();
    }
}
