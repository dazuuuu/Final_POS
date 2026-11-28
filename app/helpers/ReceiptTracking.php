<?php
// app/helpers/ReceiptTracking.php
// Print + submit tracking for receipts (sales, orders, service tickets),
// shown on the Receipts page (POS menu). Deliberately its own small tables
// rather than new columns on sales/orders — one entity_type+entity_id key
// works the same way for every receipt-producing feature in the app.
//
//   receipt_status   one row per receipt: print_count, last printed
//                     at/by, submitted at/by/to
//   receipt_prints    one row per print (the history: "printed twice", when)

class ReceiptTracking
{
    const TYPES = ['sale' => 'Sale', 'order' => 'Order', 'service' => 'Service'];

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $pdo = Database::pdo();
        $flag = Database::marker('receipt_tracking_v1');
        if (!is_file($flag)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS receipt_status (
                tenant_id INT NOT NULL,
                entity_type VARCHAR(20) NOT NULL,
                entity_id INT NOT NULL,
                receipt_number VARCHAR(40) NULL,
                print_count INT NOT NULL DEFAULT 0,
                last_printed_at DATETIME NULL,
                last_printed_by INT NULL,
                submitted_at DATETIME NULL,
                submitted_by INT NULL,
                submitted_to VARCHAR(120) NULL,
                PRIMARY KEY (entity_type, entity_id),
                KEY idx_receipt_status_tenant (tenant_id, entity_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS receipt_prints (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                entity_type VARCHAR(20) NOT NULL,
                entity_id INT NOT NULL,
                printed_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_receipt_prints (tenant_id, entity_type, entity_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            @file_put_contents($flag, date('c'));
        }
        $done = true;
    }

    /** "sale:123" -> ['sale', 123], or null if malformed / not a known type. */
    public static function parseEntity(string $raw): ?array
    {
        $parts = explode(':', $raw, 2);
        if (count($parts) !== 2 || !isset(self::TYPES[$parts[0]])) return null;
        $id = (int) $parts[1];
        return $id > 0 ? [$parts[0], $id] : null;
    }

    /** Record one print (called from printer.js on every print, including reprints). */
    public static function recordPrint(string $type, int $id, ?string $receiptNumber = null): void
    {
        self::ensure();
        $pdo = Database::pdo();
        $tid = (int) TenantContext::tenantId();
        $uid = TenantContext::userId();
        $pdo->prepare('INSERT INTO receipt_prints (tenant_id, entity_type, entity_id, printed_by) VALUES (?, ?, ?, ?)')
            ->execute([$tid, $type, $id, $uid]);
        $pdo->prepare(
            'INSERT INTO receipt_status (tenant_id, entity_type, entity_id, receipt_number, print_count, last_printed_at, last_printed_by)
             VALUES (?, ?, ?, ?, 1, NOW(), ?)
             ON DUPLICATE KEY UPDATE print_count = print_count + 1, last_printed_at = NOW(), last_printed_by = VALUES(last_printed_by),
                                     receipt_number = COALESCE(VALUES(receipt_number), receipt_number)'
        )->execute([$tid, $type, $id, $receiptNumber, $uid]);
    }

    /** Mark a receipt as handed over / submitted to the owner (or whoever). */
    public static function markSubmitted(string $type, int $id, string $submittedTo): void
    {
        self::ensure();
        $pdo = Database::pdo();
        $tid = (int) TenantContext::tenantId();
        $uid = TenantContext::userId();
        $pdo->prepare(
            'INSERT INTO receipt_status (tenant_id, entity_type, entity_id, submitted_at, submitted_by, submitted_to)
             VALUES (?, ?, ?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE submitted_at = NOW(), submitted_by = VALUES(submitted_by), submitted_to = VALUES(submitted_to)'
        )->execute([$tid, $type, $id, $uid, mb_substr($submittedTo, 0, 120) ?: null]);
    }

    public static function status(string $type, int $id): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM receipt_status WHERE entity_type = ? AND entity_id = ? AND tenant_id = ?');
        $st->execute([$type, $id, (int) TenantContext::tenantId()]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['print_count' => 0, 'last_printed_at' => null, 'last_printed_by' => null,
            'submitted_at' => null, 'submitted_by' => null, 'submitted_to' => null];
    }

    /** All receipt_status rows for this tenant, newest print first — the Receipts page's base list. */
    public static function allStatuses(int $tenantId): array
    {
        self::ensure();
        $st = Database::pdo()->prepare(
            "SELECT rs.*, u1.username AS printed_by_name, u2.username AS submitted_by_name
               FROM receipt_status rs
               LEFT JOIN users u1 ON u1.id = rs.last_printed_by
               LEFT JOIN users u2 ON u2.id = rs.submitted_by
              WHERE rs.tenant_id = ?
              ORDER BY rs.last_printed_at DESC"
        );
        $st->execute([$tenantId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $byKey = [];
        foreach ($rows as $r) { $byKey[$r['entity_type'] . ':' . $r['entity_id']] = $r; }
        return $byKey;
    }
}
