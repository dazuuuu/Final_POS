<?php
// app/models/ElectronicsModel.php
// Electronics package: every unit of a "tracked" product carries its own
// serial number / IMEI. Serials are recorded at stock intake, in the
// warehouse, or later on the Electronics page; a sale must name the serial of
// each unit sold (registering it on the spot if it was never recorded), which
// starts that unit's warranty. Warranty claims take a device back in for
// repair — free while under warranty, a repair fee otherwise — and count how
// many times each device has come back. Serials never print on receipts.
//
// Serials are unique per ACCOUNT (the owner's top-level tenant), so a phone
// keeps its identity when it moves between the warehouse and branch shops.
namespace Models;

class ElectronicsModel
{
    public const CLAIM_STATUSES = ['received' => 'Received', 'repairing' => 'Repairing', 'ready' => 'Ready for pickup', 'collected' => 'Collected'];

    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? \Database::pdo();
        self::ensureSchema($this->db);
    }

    public static function enabled(?int $tenantId = null): bool
    {
        return \Features::enabled(\Features::ELECTRONICS, $tenantId);
    }

    public static function ensureSchema(\PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $db->exec(
            "CREATE TABLE IF NOT EXISTS device_serials (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                tenant_id INT NOT NULL,
                product_id INT NULL,
                warehouse_item_id INT NULL,
                serial VARCHAR(64) NOT NULL,
                serial2 VARCHAR(64) NULL,
                color VARCHAR(40) NULL,
                status ENUM('warehouse','in_stock','sold','in_repair') NOT NULL DEFAULT 'in_stock',
                warranty_months INT NULL,
                order_id INT NULL,
                order_item_id INT NULL,
                sold_at DATETIME NULL,
                warranty_ends_at DATE NULL,
                customer_name VARCHAR(150) NULL,
                customer_phone VARCHAR(40) NULL,
                return_count INT NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_device_serial (account_id, serial),
                KEY idx_device_serial2 (account_id, serial2),
                KEY idx_device_product (tenant_id, product_id, status),
                KEY idx_device_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS warranty_claims (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                tenant_id INT NOT NULL,
                serial_id INT NOT NULL,
                issue TEXT NOT NULL,
                under_warranty TINYINT(1) NOT NULL DEFAULT 0,
                covered TINYINT(1) NOT NULL DEFAULT 0,
                charge_reason VARCHAR(255) NULL,
                repair_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
                fee_paid TINYINT(1) NOT NULL DEFAULT 0,
                payment_method VARCHAR(10) NULL,
                status ENUM('received','repairing','ready','collected') NOT NULL DEFAULT 'received',
                resolution VARCHAR(255) NULL,
                received_by INT NULL,
                received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                collected_by INT NULL,
                collected_at DATETIME NULL,
                KEY idx_claim_serial (serial_id),
                KEY idx_claim_tenant (tenant_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        foreach ([
            'device_serials' => ['color' => 'VARCHAR(40) NULL'],
            'products' => ['is_serialized' => 'TINYINT(1) NOT NULL DEFAULT 0', 'warranty_months' => 'INT NULL'],
            'warehouse_items' => ['is_serialized' => 'TINYINT(1) NOT NULL DEFAULT 0', 'warranty_months' => 'INT NULL'],
        ] as $table => $cols) {
            foreach ($cols as $col => $def) {
                $chk = $db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
                $chk->execute([$table, $col]);
                if (!$chk->fetchColumn()) {
                    try { $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def"); } catch (\PDOException $e) { /* table missing */ }
                }
            }
        }
        // under_warranty = the warranty was still running when the device came in;
        // covered = this repair is free. A device under warranty can still have
        // an issue the warranty doesn't cover (water, cracked screen…), charged.
        $chk = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'warranty_claims' AND column_name = 'covered'");
        if (!(int) $chk->fetchColumn()) {
            $db->exec('ALTER TABLE warranty_claims ADD COLUMN covered TINYINT(1) NOT NULL DEFAULT 0 AFTER under_warranty, ADD COLUMN charge_reason VARCHAR(255) NULL AFTER covered');
            $db->exec('UPDATE warranty_claims SET covered = under_warranty');
        }
        $done = true;
    }

    private function accountId(?int $tenantId = null): int
    {
        return \Features::accountId($tenantId ?? (int) \TenantContext::tenantId());
    }

    /** Normalise what staff type or scan: trim, collapse spaces, upper-case. */
    public static function clean(string $serial): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($serial)));
    }

    /** Split a textarea (one per line, or comma-separated) into unique serials. */
    public static function parseList(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,;]+/', $text) as $s) {
            $s = self::clean($s);
            if ($s !== '') { $out[$s] = true; }
        }
        return array_keys($out);
    }

    /** 14 → "1 year 2 months", 0 → "No warranty". */
    public static function warrantyLabel(?int $months): string
    {
        $months = max(0, (int) $months);
        if ($months === 0) { return 'No warranty'; }
        $y = intdiv($months, 12);
        $m = $months % 12;
        $parts = [];
        if ($y) { $parts[] = $y . ' year' . ($y === 1 ? '' : 's'); }
        if ($m) { $parts[] = $m . ' month' . ($m === 1 ? '' : 's'); }
        return implode(' ', $parts);
    }

    /** Total months from a "years + months" pair of inputs (owner sets any length). */
    public static function warrantyFromInput($years, $months): int
    {
        return max(0, min(1200, (int) $years * 12 + (int) $months));
    }

    /**
     * The years + months inputs, named {$prefix}[warranty_years] / [warranty_months]
     * (or plain warranty_years / warranty_months when $prefix is '').
     */
    public static function warrantyInputs(string $prefix, int $months, string $size = 'sm'): string
    {
        $n = fn($f) => $prefix === '' ? $f : $prefix . '[' . $f . ']';
        $y = intdiv(max(0, $months), 12);
        $m = max(0, $months) % 12;
        $cls = 'form-control form-control-' . $size;
        return '<div class="d-flex align-items-center gap-1 flex-wrap"><span class="small text-muted me-1">Warranty</span>'
            . '<input type="number" min="0" max="50" step="1" name="' . $n('warranty_years') . '" value="' . $y . '" class="' . $cls . '" style="width:70px;"><span class="small">yr</span>'
            . '<input type="number" min="0" max="11" step="1" name="' . $n('warranty_months') . '" value="' . $m . '" class="' . $cls . '" style="width:70px;"><span class="small">mo</span></div>';
    }

    /** Colours list typed as "Black, Blue, Gold" → ['Black','Blue','Gold']. */
    public static function parseColors(string $text): array
    {
        $out = [];
        foreach (preg_split('/[,\n;\/]+/', $text) as $c) {
            $c = mb_substr(trim($c), 0, 40);
            if ($c !== '') { $out[mb_strtolower($c)] = mb_convert_case($c, MB_CASE_TITLE); }
        }
        return array_values($out);
    }

    // ---- products -------------------------------------------------------

    public function setProductTracking(int $productId, bool $on, int $months, ?array $colors = null): void
    {
        $this->db->prepare('UPDATE products SET is_serialized = ?, warranty_months = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$on ? 1 : 0, $months > 0 ? $months : null, $productId, \TenantContext::tenantId()]);
        if ($colors !== null) {
            $this->db->prepare('UPDATE products SET colors = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$colors ? json_encode(array_values($colors)) : null, $productId, \TenantContext::tenantId()]);
        }
    }

    /** product_id => ['warranty_months' => int, 'name' => string] for the tracked products of this shop. */
    public function trackedProducts(): array
    {
        $stmt = $this->db->prepare('SELECT id, name, warranty_months, colors FROM products WHERE tenant_id = ? AND is_serialized = 1');
        $stmt->execute([\TenantContext::tenantId()]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = [
                'warranty_months' => (int) $r['warranty_months'],
                'name' => $r['name'],
                'colors' => $r['colors'] ? (json_decode($r['colors'], true) ?: []) : [],
            ];
        }
        return $out;
    }

    /** product_id => [serials in stock] for this shop, for the POS picker. */
    public function inStockByProduct(): array
    {
        $stmt = $this->db->prepare("SELECT product_id, serial FROM device_serials WHERE tenant_id = ? AND status = 'in_stock' AND product_id IS NOT NULL ORDER BY created_at ASC, id ASC");
        $stmt->execute([\TenantContext::tenantId()]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['product_id']][] = $r['serial'];
        }
        return $out;
    }

    /**
     * Register serials for units already in a shop's stock. You can't record
     * more serials than there are unregistered units of that product.
     */
    public function addSerials(int $productId, array $serials, ?int $warrantyMonths = null, ?string $serial2 = null): array
    {
        $tid = (int) \TenantContext::tenantId();
        $p = $this->db->prepare('SELECT id, name, quantity FROM products WHERE id = ? AND tenant_id = ?');
        $p->execute([$productId, $tid]);
        $product = $p->fetch();
        if (!$product) { return ['ok' => false, 'error' => 'Product not found.', 'added' => 0]; }
        $serials = array_values(array_unique(array_filter(array_map([self::class, 'clean'], $serials))));
        if (!$serials) { return ['ok' => false, 'error' => 'Enter at least one serial / IMEI.', 'added' => 0]; }

        $cnt = $this->db->prepare("SELECT COUNT(*) FROM device_serials WHERE tenant_id = ? AND product_id = ? AND status = 'in_stock'");
        $cnt->execute([$tid, $productId]);
        $room = (int) floor((float) $product['quantity']) - (int) $cnt->fetchColumn();
        if (count($serials) > $room) {
            return ['ok' => false, 'added' => 0, 'error' => $room <= 0
                ? "Every unit of {$product['name']} in stock already has a serial. Record new stock first."
                : "Only $room unit(s) of {$product['name']} in stock still need a serial — you entered " . count($serials) . '.'];
        }
        $taken = $this->taken($serials);
        if ($taken) { return ['ok' => false, 'added' => 0, 'error' => 'Already recorded: ' . implode(', ', $taken) . '.']; }

        $ins = $this->db->prepare(
            "INSERT INTO device_serials (account_id, tenant_id, product_id, serial, serial2, status, warranty_months, created_by) VALUES (?,?,?,?,?, 'in_stock', ?, ?)"
        );
        $s2 = $serial2 !== null && count($serials) === 1 ? (self::clean($serial2) ?: null) : null;
        foreach ($serials as $s) {
            $ins->execute([$this->accountId(), $tid, $productId, $s, $s2, $warrantyMonths, \TenantContext::userId()]);
        }
        return ['ok' => true, 'error' => null, 'added' => count($serials)];
    }

    /** Serials (of the given list) that this account already has, in either IMEI slot. */
    public function taken(array $serials): array
    {
        if (!$serials) { return []; }
        $in = implode(',', array_fill(0, count($serials), '?'));
        $stmt = $this->db->prepare("SELECT serial FROM device_serials WHERE account_id = ? AND (serial IN ($in) OR serial2 IN ($in))");
        $stmt->execute(array_merge([$this->accountId()], $serials, $serials));
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function deleteInStock(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM device_serials WHERE id = ? AND tenant_id = ? AND status IN ('in_stock','warehouse')");
        $stmt->execute([$id, \TenantContext::tenantId()]);
        return $stmt->rowCount() === 1;
    }

    // ---- selling --------------------------------------------------------

    /**
     * Inside OrderModel::open()'s transaction: every tracked product on the
     * sale must come with one serial per unit. A serial already recorded must
     * be in this shop's stock for that product; one never recorded is
     * registered now. Each unit's warranty starts today.
     * $serialsByProduct: product_id => [serial, ...]
     */
    public function attachToSale(int $tid, int $orderId, array $items, array $serialsByProduct, string $customerName, ?string $customerPhone): array
    {
        $tracked = $this->trackedProducts();
        $account = $this->accountId($tid);
        $seen = [];
        $plan = [];
        foreach ($items as $it) {
            $pid = (int) $it['product_id'];
            if (!isset($tracked[$pid])) { continue; }
            $qty = (float) $it['quantity'];
            $name = $tracked[$pid]['name'];
            if (abs($qty - round($qty)) > 0.0001) {
                return ['ok' => false, 'error' => "$name is sold per unit — use a whole number."];
            }
            // Each unit is either a plain serial or {serial, color}.
            $list = [];
            $colors = [];
            foreach ((array) ($serialsByProduct[$pid] ?? $serialsByProduct[(string) $pid] ?? []) as $u) {
                $sn = self::clean(is_array($u) ? (string) ($u['serial'] ?? '') : (string) $u);
                if ($sn === '') { continue; }
                $list[] = $sn;
                $colors[$sn] = is_array($u) && trim((string) ($u['color'] ?? '')) !== '' ? mb_substr(trim($u['color']), 0, 40) : null;
            }
            if (count($list) !== (int) round($qty)) {
                return ['ok' => false, 'error' => "Enter the serial / IMEI of each $name sold (" . (int) round($qty) . ' needed, ' . count($list) . ' given).'];
            }
            foreach ($list as $s) {
                if (isset($seen[$s])) { return ['ok' => false, 'error' => "Serial $s was entered twice."]; }
                $seen[$s] = true;
                $plan[] = [$pid, $s, $tracked[$pid]['warranty_months'], $colors[$s] ?? null];
            }
        }
        if (!$plan) { return ['ok' => true, 'error' => null]; }

        $find = $this->db->prepare('SELECT * FROM device_serials WHERE account_id = ? AND (serial = ? OR serial2 = ?) FOR UPDATE');
        $itemId = $this->db->prepare('SELECT id FROM order_items WHERE order_id = ? AND product_id = ? ORDER BY id ASC LIMIT 1');
        $sell = $this->db->prepare(
            "UPDATE device_serials SET status = 'sold', order_id = ?, order_item_id = ?, sold_at = NOW(), warranty_ends_at = ?,
                    customer_name = ?, customer_phone = ?, warranty_months = ?, color = COALESCE(?, color) WHERE id = ?"
        );
        $ins = $this->db->prepare(
            "INSERT INTO device_serials (account_id, tenant_id, product_id, serial, color, status, warranty_months, order_id, order_item_id, sold_at, warranty_ends_at, customer_name, customer_phone, created_by)
             VALUES (?,?,?,?,?, 'sold', ?,?,?, NOW(), ?,?,?,?)"
        );
        foreach ($plan as [$pid, $s, $productMonths, $color]) {
            $itemId->execute([$orderId, $pid]);
            $oi = (int) $itemId->fetchColumn() ?: null;
            $find->execute([$account, $s, $s]);
            $row = $find->fetch();
            if ($row) {
                if ($row['status'] !== 'in_stock' || (int) $row['tenant_id'] !== $tid || (int) $row['product_id'] !== $pid) {
                    $why = $row['status'] === 'sold' || $row['status'] === 'in_repair' ? 'was already sold' : 'is not in this shop\'s stock for that product';
                    return ['ok' => false, 'error' => "Serial $s $why."];
                }
                $months = $row['warranty_months'] !== null ? (int) $row['warranty_months'] : $productMonths;
                $sell->execute([$orderId, $oi, self::warrantyEnd($months), $customerName, $customerPhone, $months, $color, $row['id']]);
            } else {
                $ins->execute([$account, $tid, $pid, $s, $color, $productMonths, $orderId, $oi, self::warrantyEnd($productMonths), $customerName, $customerPhone, \TenantContext::userId()]);
            }
        }
        return ['ok' => true, 'error' => null];
    }

    private static function warrantyEnd(?int $months): ?string
    {
        return $months > 0 ? date('Y-m-d', strtotime('+' . (int) $months . ' months')) : null;
    }

    /** Serials sold on an order, grouped by order_item_id (for the refund screen). */
    public function soldOnOrder(int $orderId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM device_serials WHERE order_id = ? AND tenant_id = ? AND status = 'sold' ORDER BY id");
        $stmt->execute([$orderId, \TenantContext::tenantId()]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) { $out[(int) $r['order_item_id']][] = $r; }
        return $out;
    }

    /**
     * Devices sold on each of these orders: order_id => [{serial, serial2, color, product_name}].
     * Refunded units drop off (their order link is cleared). No TenantContext
     * needed, so the daily report cron can use it too.
     */
    public function serialsForOrders(array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
        if (!$orderIds) { return []; }
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT d.order_id, d.serial, d.serial2, d.color, p.name AS product_name
               FROM device_serials d LEFT JOIN products p ON p.id = d.product_id
              WHERE d.order_id IN ($in) ORDER BY d.id"
        );
        $stmt->execute($orderIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) { $out[(int) $r['order_id']][] = $r; }
        return $out;
    }

    /** Small serial chips for a sales table cell. */
    public static function serialsHtml(array $devices): string
    {
        if (!$devices) { return ''; }
        $html = '<div class="d-flex flex-wrap gap-1 mt-1">';
        foreach ($devices as $d) {
            $html .= '<span class="badge bg-light text-dark border font-monospace fw-normal" title="' . htmlspecialchars($d['product_name'] ?? '', ENT_QUOTES) . '">'
                . '<i class="fas fa-mobile-screen me-1 text-success"></i>' . htmlspecialchars($d['serial'])
                . (!empty($d['color']) ? ' · ' . htmlspecialchars($d['color']) : '') . '</span>';
        }
        return $html . '</div>';
    }

    /** "IMEI001 (Blue), NEW777 (Gold)" for PDFs, emails and search. */
    public static function serialsText(array $devices): string
    {
        return implode(', ', array_map(fn($d) => $d['serial'] . (!empty($d['color']) ? ' (' . $d['color'] . ')' : ''), $devices));
    }

    /** A refunded unit goes back on the shelf (clean, sellable again). */
    public function restockFromRefund(array $serialIds, int $orderId): void
    {
        if (!$serialIds) { return; }
        $stmt = $this->db->prepare(
            "UPDATE device_serials SET status = 'in_stock', order_id = NULL, order_item_id = NULL, sold_at = NULL, warranty_ends_at = NULL,
                    customer_name = NULL, customer_phone = NULL WHERE id = ? AND order_id = ? AND tenant_id = ? AND status = 'sold'"
        );
        foreach ($serialIds as $id) { $stmt->execute([(int) $id, $orderId, \TenantContext::tenantId()]); }
    }

    // ---- lookup & warranty claims -------------------------------------------

    /** One device by serial / IMEI (either slot), anywhere in this account. */
    public function lookup(string $serial): ?array
    {
        $s = self::clean($serial);
        if ($s === '') { return null; }
        $stmt = $this->db->prepare(
            "SELECT d.*, p.name AS product_name, t.name AS shop_name, o.receipt_number
               FROM device_serials d
          LEFT JOIN products p ON p.id = d.product_id
          LEFT JOIN tenants t ON t.id = d.tenant_id
          LEFT JOIN orders o ON o.id = d.order_id
              WHERE d.account_id = ? AND (d.serial = ? OR d.serial2 = ?) LIMIT 1"
        );
        $stmt->execute([$this->accountId(), $s, $s]);
        $row = $stmt->fetch();
        if (!$row) { return null; }
        $row['under_warranty'] = self::underWarranty($row);
        $row['claims'] = $this->claimsFor((int) $row['id']);
        return $row;
    }

    public static function underWarranty(array $device): bool
    {
        return !empty($device['warranty_ends_at']) && $device['warranty_ends_at'] >= date('Y-m-d');
    }

    public function claimsFor(int $serialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, u.username AS received_by_name FROM warranty_claims c LEFT JOIN users u ON u.id = c.received_by
              WHERE c.serial_id = ? ORDER BY c.received_at DESC, c.id DESC'
        );
        $stmt->execute([$serialId]);
        return $stmt->fetchAll();
    }

    /**
     * The customer brings a sold device back. Under warranty → repaired free;
     * otherwise a repair fee is charged. The device is held (in_repair) until
     * it's collected, and its return count goes up.
     */
    /**
     * $notCovered: the warranty is running but this issue isn't covered by it
     * (e.g. water or screen damage) — it's charged like an expired one.
     * The charge can be left blank at intake (quoted after diagnosis) but must
     * be set before the device is collected.
     */
    public function receiveClaim(int $serialId, string $issue, float $fee, bool $notCovered = false, ?string $chargeReason = null): array
    {
        $issue = trim($issue);
        if ($issue === '') { return ['ok' => false, 'error' => 'Describe the problem with the device.']; }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM device_serials WHERE id = ? AND account_id = ? FOR UPDATE');
            $stmt->execute([$serialId, $this->accountId()]);
            $d = $stmt->fetch();
            if (!$d) { $this->db->rollBack(); return ['ok' => false, 'error' => 'Device not found.']; }
            if ($d['status'] === 'in_repair') { $this->db->rollBack(); return ['ok' => false, 'error' => 'This device is already in for repair.']; }
            if ($d['status'] !== 'sold') { $this->db->rollBack(); return ['ok' => false, 'error' => 'This device was never sold — it is still in stock.']; }
            $under = self::underWarranty($d);
            $covered = $under && !$notCovered;
            $fee = $covered ? 0.0 : max(0, round($fee, 2));
            $reason = $covered ? null : (trim((string) $chargeReason) !== '' ? mb_substr(trim($chargeReason), 0, 255) : null);
            if ($under && $notCovered && $reason === null) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Say why this issue isn\'t covered by the warranty (e.g. water damage).'];
            }

            $this->db->prepare(
                'INSERT INTO warranty_claims (account_id, tenant_id, serial_id, issue, under_warranty, covered, charge_reason, repair_fee, received_by) VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$this->accountId(), \TenantContext::tenantId(), $serialId, mb_substr($issue, 0, 2000), $under ? 1 : 0, $covered ? 1 : 0, $reason, $fee, \TenantContext::userId()]);
            $claimId = (int) $this->db->lastInsertId();
            $this->db->prepare("UPDATE device_serials SET status = 'in_repair', return_count = return_count + 1 WHERE id = ?")->execute([$serialId]);
            $this->db->commit();
            return ['ok' => true, 'error' => null, 'claim_id' => $claimId, 'under_warranty' => $under, 'covered' => $covered, 'fee' => $fee];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('ElectronicsModel::receiveClaim failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record this return. Please try again.'];
        }
    }

    /** Move a claim along: received → repairing → ready → collected. Collecting hands the device back. */
    /**
     * $fee: the charge for a repair that isn't free (set or corrected until it's
     * collected). $markNotCovered: a free warranty repair turns out to have an
     * uncovered issue — it becomes charged ($reason says why).
     */
    public function updateClaim(int $claimId, string $status, ?string $resolution, ?string $paymentMethod, ?float $fee = null, bool $markNotCovered = false, ?string $reason = null): array
    {
        if (!isset(self::CLAIM_STATUSES[$status])) { return ['ok' => false, 'error' => 'Unknown status.']; }
        $stmt = $this->db->prepare('SELECT * FROM warranty_claims WHERE id = ? AND account_id = ?');
        $stmt->execute([$claimId, $this->accountId()]);
        $c = $stmt->fetch();
        if (!$c) { return ['ok' => false, 'error' => 'Repair not found.']; }
        if ($c['status'] === 'collected') { return ['ok' => false, 'error' => 'This device was already collected.']; }
        $covered = (bool) $c['covered'];
        $chargeReason = $c['charge_reason'];
        if ($covered && $markNotCovered) {
            $reason = trim((string) $reason);
            if ($reason === '') { return ['ok' => false, 'error' => 'Say why this issue isn\'t covered by the warranty.']; }
            $covered = false;
            $chargeReason = mb_substr($reason, 0, 255);
        }
        $charge = $covered ? 0.0 : ($fee !== null ? max(0, round($fee, 2)) : (float) $c['repair_fee']);
        $paid = $c['fee_paid'];
        $method = $c['payment_method'];
        if ($status === 'collected' && !$covered) {
            if ($charge <= 0) {
                return ['ok' => false, 'error' => 'Enter the repair charge before the customer collects the device.'];
            }
            if (!in_array($paymentMethod, ['cash', 'mpesa'], true)) {
                return ['ok' => false, 'error' => 'Choose how the customer paid the repair charge.'];
            }
            $paid = 1;
            $method = $paymentMethod;
        }
        $this->db->prepare(
            'UPDATE warranty_claims SET status = ?, resolution = COALESCE(?, resolution), covered = ?, charge_reason = ?, repair_fee = ?, fee_paid = ?, payment_method = ?,
                    collected_at = IF(? = \'collected\', NOW(), collected_at), collected_by = IF(? = \'collected\', ?, collected_by) WHERE id = ?'
        )->execute([$status, trim((string) $resolution) !== '' ? mb_substr(trim($resolution), 0, 255) : null, $covered ? 1 : 0, $chargeReason, $charge, $paid, $method,
                    $status, $status, \TenantContext::userId(), $claimId]);
        if ($status === 'collected') {
            $this->db->prepare("UPDATE device_serials SET status = 'sold' WHERE id = ? AND status = 'in_repair'")->execute([$c['serial_id']]);
        }
        return ['ok' => true, 'error' => null];
    }

    /**
     * Repair charges actually paid (on collection) — extra earnings for the
     * shop. Optional date range on collected_at (Y-m-d, inclusive).
     */
    public function repairIncome(int $tenantId, ?string $from = null, ?string $to = null): array
    {
        $sql = "SELECT COALESCE(SUM(repair_fee),0) total,
                       COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN repair_fee ELSE 0 END),0) cash,
                       COALESCE(SUM(CASE WHEN payment_method = 'mpesa' THEN repair_fee ELSE 0 END),0) mpesa,
                       COUNT(*) n
                  FROM warranty_claims WHERE tenant_id = ? AND fee_paid = 1 AND repair_fee > 0";
        $args = [$tenantId];
        if ($from !== null) { $sql .= ' AND collected_at >= ?'; $args[] = $from . ' 00:00:00'; }
        if ($to !== null) { $sql .= ' AND collected_at <= ?'; $args[] = $to . ' 23:59:59'; }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        $r = $stmt->fetch();
        return ['total' => round((float) $r['total'], 2), 'cash' => round((float) $r['cash'], 2), 'mpesa' => round((float) $r['mpesa'], 2), 'count' => (int) $r['n']];
    }

    /** Repairs collected (and paid) on one day, for the daily report. */
    public function repairsCollectedOn(int $tenantId, string $date): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, d.serial, d.customer_name, p.name AS product_name
               FROM warranty_claims c JOIN device_serials d ON d.id = c.serial_id LEFT JOIN products p ON p.id = d.product_id
              WHERE c.tenant_id = ? AND c.status = 'collected' AND DATE(c.collected_at) = ? ORDER BY c.collected_at"
        );
        $stmt->execute([$tenantId, $date]);
        return $stmt->fetchAll();
    }

    /** Open repairs (not yet collected) for this shop, plus recently collected ones. */
    public function claims(bool $openOnly = true, int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, d.serial, d.serial2, d.customer_name, d.customer_phone, d.return_count, d.warranty_ends_at, p.name AS product_name
               FROM warranty_claims c
               JOIN device_serials d ON d.id = c.serial_id
          LEFT JOIN products p ON p.id = d.product_id
              WHERE c.tenant_id = ? " . ($openOnly ? "AND c.status <> 'collected'" : "AND c.status = 'collected'") . "
           ORDER BY c.received_at DESC, c.id DESC LIMIT " . (int) $limit
        );
        $stmt->execute([\TenantContext::tenantId()]);
        return $stmt->fetchAll();
    }

    /** Devices of this shop, filtered by status and/or a search on serial / product / customer. */
    public function devices(string $status = '', string $q = '', int $limit = 300): array
    {
        $sql = "SELECT d.*, p.name AS product_name FROM device_serials d LEFT JOIN products p ON p.id = d.product_id WHERE d.tenant_id = ?";
        $args = [\TenantContext::tenantId()];
        if (in_array($status, ['in_stock', 'sold', 'in_repair'], true)) { $sql .= ' AND d.status = ?'; $args[] = $status; }
        if ($status === 'returned') { $sql .= ' AND d.return_count > 0'; }
        if (trim($q) !== '') {
            $sql .= ' AND (d.serial LIKE ? OR d.serial2 LIKE ? OR p.name LIKE ? OR d.customer_name LIKE ? OR d.customer_phone LIKE ?)';
            $like = '%' . trim($q) . '%';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY d.created_at DESC, d.id DESC LIMIT ' . (int) $limit);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public function counts(): array
    {
        $stmt = $this->db->prepare("SELECT status, COUNT(*) n FROM device_serials WHERE tenant_id = ? GROUP BY status");
        $stmt->execute([\TenantContext::tenantId()]);
        $c = array_merge(['in_stock' => 0, 'sold' => 0, 'in_repair' => 0], $stmt->fetchAll(\PDO::FETCH_KEY_PAIR));
        $r = $this->db->prepare('SELECT COUNT(*) FROM device_serials WHERE tenant_id = ? AND return_count > 0');
        $r->execute([\TenantContext::tenantId()]);
        $c['returned'] = (int) $r->fetchColumn();
        return $c;
    }

    /** Tracked products in this shop with how many in-stock units still have no serial. */
    public function productsNeedingSerials(): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.id, p.name, p.quantity, p.warranty_months,
                    (SELECT COUNT(*) FROM device_serials d WHERE d.product_id = p.id AND d.tenant_id = p.tenant_id AND d.status = 'in_stock') AS registered
               FROM products p WHERE p.tenant_id = ? AND p.is_serialized = 1 AND p.status <> 'archived' ORDER BY p.name"
        );
        $stmt->execute([\TenantContext::tenantId()]);
        return $stmt->fetchAll();
    }

    // ---- warehouse ------------------------------------------------------

    public function addWarehouseSerials(int $homeId, int $itemId, array $serials): array
    {
        $serials = array_values(array_unique(array_filter(array_map([self::class, 'clean'], $serials))));
        if (!$serials) { return ['ok' => true, 'error' => null, 'added' => 0]; }
        $item = $this->db->prepare('SELECT quantity FROM warehouse_items WHERE id = ? AND tenant_id = ?');
        $item->execute([$itemId, $homeId]);
        $qty = $item->fetchColumn();
        if ($qty === false) { return ['ok' => false, 'error' => 'Warehouse item not found.', 'added' => 0]; }
        $have = count($this->warehouseSerials($homeId, $itemId));
        if ($have + count($serials) > (int) floor((float) $qty)) {
            return ['ok' => false, 'added' => 0, 'error' => 'More serials than units in the warehouse (' . (int) floor((float) $qty) . ' units, ' . $have . ' already have serials).'];
        }
        $taken = $this->taken($serials);
        if ($taken) { return ['ok' => false, 'added' => 0, 'error' => 'Already recorded: ' . implode(', ', $taken) . '.']; }
        $ins = $this->db->prepare("INSERT INTO device_serials (account_id, tenant_id, warehouse_item_id, serial, status, created_by) VALUES (?,?,?,?, 'warehouse', ?)");
        foreach ($serials as $s) { $ins->execute([$this->accountId($homeId), $homeId, $itemId, $s, \TenantContext::userId()]); }
        $this->db->prepare('UPDATE warehouse_items SET is_serialized = 1 WHERE id = ?')->execute([$itemId]);
        return ['ok' => true, 'error' => null, 'added' => count($serials)];
    }

    public function warehouseSerials(int $homeId, int $itemId): array
    {
        $stmt = $this->db->prepare("SELECT id, serial FROM device_serials WHERE tenant_id = ? AND warehouse_item_id = ? AND status = 'warehouse' ORDER BY id");
        $stmt->execute([$homeId, $itemId]);
        return $stmt->fetchAll();
    }

    /** Inside WarehouseModel::transfer(): the chosen serials move to the shop's product. */
    public function moveWarehouseSerials(int $homeId, int $itemId, array $serialIds, int $targetTenantId, int $productId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE device_serials SET tenant_id = ?, product_id = ?, warehouse_item_id = NULL, status = 'in_stock'
              WHERE id = ? AND tenant_id = ? AND warehouse_item_id = ? AND status = 'warehouse'"
        );
        foreach ($serialIds as $id) { $stmt->execute([$targetTenantId, $productId, (int) $id, $homeId, $itemId]); }
    }
}
