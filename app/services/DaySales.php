<?php
// app/services/DaySales.php
// Opening & closing sales, by business day (the owner's opening and closing
// time — see window()). Each person has their own day, which they can close
// themselves; the shop has its combined day (user_id 0). At closing time
// every open day closes by itself.
//
// Also the one place that gathers sales from every package (shop sales and
// tabs, restaurant orders, paid service tickets) per person and per product,
// for the tracking page and its Excel / PDF downloads.

class DaySales
{
    public static function ensure(?PDO $db = null): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $db ??= Database::pdo();
        // Check the table itself (a near-free query), never only a marker file:
        // a marker copied from another computer must not skip creating it.
        try { $db->query('SELECT 1 FROM day_sessions LIMIT 0'); return; } catch (\Throwable $e) { /* create it below */ }
        $db->exec("CREATE TABLE IF NOT EXISTS day_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            user_id INT NOT NULL,
            day DATE NOT NULL,
            opened_at DATETIME NOT NULL,
            opening_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
            closed_at DATETIME NULL,
            closing_sales DECIMAL(12,2) NULL,
            closing_count INT NULL,
            UNIQUE KEY uq_day_user (tenant_id, user_id, day),
            KEY idx_day (tenant_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Speeds up the per-day / per-person lookups this page and the dashboards do.
        foreach ([
            'sales'  => ['idx_sales_day', '(tenant_id, created_at)'],
            'orders' => ['idx_orders_paid', '(tenant_id, status, paid_at)'],
        ] as $table => [$name, $cols]) {
            try { $db->exec("ALTER TABLE `$table` ADD INDEX `$name` $cols"); } catch (\Throwable $e) { /* exists */ }
        }
    }

    // ---- Business hours (set by the account's primary owner) ----------------
    //
    // Business day D runs from the previous day's closing time to D's closing
    // time, so no sale is ever lost between days:
    //   opening sales = sold after last night's closing, before D's opening time
    //   closing sales = everything sold in day D (frozen at closing time)
    // A closing time at or before the opening time (e.g. 06:00 → 00:00, or a
    // late bar 10:00 → 02:00) closes on the next calendar date.

    const DEFAULT_OPEN = '06:00';
    const DEFAULT_CLOSE = '00:00';

    private static array $hours = [];

    /** ['open' => 'HH:MM', 'close' => 'HH:MM'] for a shop (its account's setting). */
    public static function hours(?int $tenantId = null): array
    {
        $tenantId ??= (int) TenantContext::tenantId();
        $acc = Features::accountId($tenantId);
        if (isset(self::$hours[$acc])) return self::$hours[$acc];
        $db = Database::pdo();
        self::ensureHours($db);
        $st = $db->prepare('SELECT day_open, day_close FROM tenants WHERE id = ?');
        $st->execute([$acc]);
        $r = $st->fetch() ?: [];
        return self::$hours[$acc] = [
            'open'  => substr((string) ($r['day_open'] ?? '') ?: self::DEFAULT_OPEN, 0, 5),
            'close' => substr((string) ($r['day_close'] ?? '') ?: self::DEFAULT_CLOSE, 0, 5),
        ];
    }

    /** Owner only: set the account's opening and closing time (every shop, admin and staff). */
    public static function setHours(int $tenantId, string $open, string $close): bool
    {
        $ok = fn($t) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
        if (!$ok($open) || !$ok($close)) return false;
        $acc = Features::accountId($tenantId);
        $db = Database::pdo();
        self::ensureHours($db);
        $db->prepare('UPDATE tenants SET day_open = ?, day_close = ? WHERE id = ?')->execute([$open, $close, $acc]);
        unset(self::$hours[$acc]);
        return true;
    }

    private static function ensureHours(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try { $db->query('SELECT day_open, day_close FROM tenants LIMIT 0'); return; } catch (\Throwable $e) { /* add them below */ }
        foreach (["ADD COLUMN day_open TIME NULL", "ADD COLUMN day_close TIME NULL"] as $alter) {
            try { $db->exec("ALTER TABLE tenants $alter"); } catch (\Throwable $e) { /* exists */ }
        }
    }

    /** Opening / closing moments and the counting range of business day $day. */
    public static function window(int $tenantId, string $day): array
    {
        $h = self::hours($tenantId);
        $closeOf = function (string $d) use ($h): string {
            $next = $h['close'] <= $h['open'] ? ' +1 day' : '';
            return date('Y-m-d', strtotime($d . $next)) . ' ' . $h['close'] . ':00';
        };
        return [
            'day'   => $day,
            'from'  => $closeOf(date('Y-m-d', strtotime($day . ' -1 day'))), // last night's closing
            'open'  => $day . ' ' . $h['open'] . ':00',
            'close' => $closeOf($day),
        ];
    }

    /** The business day running now (after closing time: the next day's, which is collecting its opening sales). */
    public static function today(?int $tenantId = null): string
    {
        $tenantId ??= (int) TenantContext::tenantId();
        $now = date('Y-m-d H:i:s');
        foreach ([-1, 0, 1] as $off) {
            $d = date('Y-m-d', strtotime("$off day"));
            if (self::window($tenantId, $d)['close'] > $now) return $d;
        }
        return date('Y-m-d', strtotime('+1 day'));
    }

    /** Is the shop inside its opening hours right now? */
    public static function isOpen(int $tenantId): bool
    {
        $w = self::window($tenantId, self::today($tenantId));
        $now = date('Y-m-d H:i:s');
        return $now >= $w['open'] && $now < $w['close'];
    }

    // ---- Opening / closing records -------------------------------------------
    // user_id 0 is the shop's own combined day (everyone's sales together).

    /** Record this business day's opening for a user (once per day). Returns the session row. */
    public static function open(PDO $db, int $tenantId, int $userId): array
    {
        self::ensure($db);
        self::autoClose($db, $tenantId);
        $day = self::today($tenantId);
        foreach ($userId ? [0, $userId] : [0] as $uid) {
            if (!self::session($db, $tenantId, $uid, $day)) {
                $w = self::window($tenantId, $day);
                $opening = self::sumBetween($db, $tenantId, $w['from'], min($w['open'], date('Y-m-d H:i:s')), $uid ?: null)['total'];
                $db->prepare('INSERT IGNORE INTO day_sessions (tenant_id, user_id, day, opened_at, opening_sales) VALUES (?,?,?,?,?)')
                   ->execute([$tenantId, $uid, $day, date('Y-m-d H:i:s'), $opening]);
            }
        }
        return self::session($db, $tenantId, $userId, $day) ?: [];
    }

    /** A staff member / admin closes their own day: their figures freeze, the shop's day carries on. */
    public static function close(PDO $db, int $tenantId, int $userId): void
    {
        $s = self::open($db, $tenantId, $userId);
        if (!$s || $s['closed_at'] !== null) return;
        $w = self::window($tenantId, $s['day']);
        $t = self::sumBetween($db, $tenantId, $w['from'], date('Y-m-d H:i:s'), $userId ?: null);
        $db->prepare('UPDATE day_sessions SET closed_at = ?, closing_sales = ?, closing_count = ? WHERE id = ?')
           ->execute([date('Y-m-d H:i:s'), $t['total'], $t['count'], $s['id']]);
    }

    /** Closing time has passed: freeze every open day (people and the shop) at the closing figures. */
    public static function autoClose(PDO $db, int $tenantId): void
    {
        static $done = [];
        if (isset($done[$tenantId])) return;
        $done[$tenantId] = true;
        self::ensure($db);
        $st = $db->prepare('SELECT * FROM day_sessions WHERE tenant_id = ? AND closed_at IS NULL AND day <= ?');
        $st->execute([$tenantId, date('Y-m-d')]);
        $now = date('Y-m-d H:i:s');
        $up = $db->prepare('UPDATE day_sessions SET closed_at = ?, closing_sales = ?, closing_count = ? WHERE id = ? AND closed_at IS NULL');
        foreach ($st->fetchAll() as $s) {
            $w = self::window($tenantId, $s['day']);
            if ($w['close'] > $now) continue;
            $t = self::sumBetween($db, $tenantId, $w['from'], $w['close'], (int) $s['user_id'] ?: null);
            $up->execute([$w['close'], $t['total'], $t['count'], $s['id']]);
        }
    }

    public static function session(PDO $db, int $tenantId, int $userId, string $day): ?array
    {
        self::ensure($db);
        $st = $db->prepare('SELECT * FROM day_sessions WHERE tenant_id = ? AND user_id = ? AND day = ? LIMIT 1');
        $st->execute([$tenantId, $userId, $day]);
        return $st->fetch() ?: null;
    }

    /** Every opening/closing row for a shop and range of business days, with names (the shop's own row first). */
    public static function sessions(PDO $db, int $tenantId, string $from, string $to): array
    {
        self::ensure($db);
        self::autoClose($db, $tenantId);
        $st = $db->prepare("SELECT d.*, IF(d.user_id = 0, 'Whole shop', u.username) AS username FROM day_sessions d LEFT JOIN users u ON u.id = d.user_id
                             WHERE d.tenant_id = ? AND d.day BETWEEN ? AND ? ORDER BY d.day DESC, d.user_id = 0 DESC, u.username");
        $st->execute([$tenantId, $from, $to]);
        return $st->fetchAll();
    }

    /** Sales from the start of business day $from to the close of business day $to. */
    public static function totalFor(PDO $db, int $tenantId, string $from, string $to, ?int $userId = null): array
    {
        return self::sumBetween($db, $tenantId, self::window($tenantId, $from)['from'], self::window($tenantId, $to)['close'], $userId);
    }

    /** Fast total (one query per source) between two moments. */
    public static function sumBetween(PDO $db, int $tenantId, string $start, string $end, ?int $userId = null): array
    {
        $total = 0.0; $count = 0;
        foreach (self::sources($db) as $src) {
            $sql = "SELECT COUNT(*) c, COALESCE(SUM({$src['total']}),0) t FROM {$src['table']} x
                     WHERE x.tenant_id = ? AND {$src['where']} AND x.{$src['at']} >= ? AND x.{$src['at']} < ?";
            $p = [$tenantId, $start, $end];
            if ($userId !== null) { $sql .= " AND {$src['by']} = ?"; $p[] = $userId; }
            try {
                $st = $db->prepare($sql);
                $st->execute($p);
                $r = $st->fetch();
                $total += (float) $r['t']; $count += (int) $r['c'];
            } catch (\Throwable $e) { /* package table not installed */ }
        }
        return ['total' => round($total, 2), 'count' => $count];
    }

    /**
     * Every sale in the range with its lines:
     * [source, id, at, user_id, staff, customer, method, total, items => [[product_id, product, qty, amount]]]
     */
    public static function sales(PDO $db, int $tenantId, string $from, string $to, ?int $userId = null): array
    {
        $out = [];
        $range = [self::window($tenantId, $from)['from'], self::window($tenantId, $to)['close']];
        foreach (self::sources($db) as $key => $src) {
            $sql = "SELECT x.id, x.{$src['at']} AS at, {$src['by']} AS user_id, u.username AS staff,
                           {$src['customer']} AS customer, {$src['method']} AS method, {$src['total']} AS total
                      FROM {$src['table']} x LEFT JOIN users u ON u.id = {$src['by']}
                     WHERE x.tenant_id = ? AND {$src['where']} AND x.{$src['at']} >= ? AND x.{$src['at']} < ?";
            $p = array_merge([$tenantId], $range);
            if ($userId !== null) { $sql .= " AND {$src['by']} = ?"; $p[] = $userId; }
            try {
                $st = $db->prepare($sql . " ORDER BY x.{$src['at']}");
                $st->execute($p);
                $rows = $st->fetchAll();
            } catch (\Throwable $e) { continue; }
            if (!$rows) continue;

            $ids = array_map(fn($r) => (int) $r['id'], $rows);
            $items = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                try {
                    $st = $db->prepare(sprintf($src['items'], $in));
                    $st->execute($chunk);
                    foreach ($st->fetchAll() as $it) { $items[(int) $it['parent']][] = $it; }
                } catch (\Throwable $e) { /* no lines */ }
            }
            foreach ($rows as $r) {
                $r['source'] = $src['label'];
                $r['items'] = $items[(int) $r['id']] ?? [];
                $out[] = $r;
            }
        }
        usort($out, fn($a, $b) => strcmp($a['at'], $b['at']));
        return $out;
    }

    /** Per person: sales count, total, first and last sale. */
    public static function byStaff(array $sales): array
    {
        $out = [];
        foreach ($sales as $s) {
            $k = (int) $s['user_id'];
            $out[$k] ??= ['user_id' => $k, 'staff' => $s['staff'] ?: 'Unknown', 'count' => 0, 'items' => 0.0, 'total' => 0.0, 'first' => $s['at'], 'last' => $s['at']];
            $out[$k]['count']++;
            $out[$k]['total'] += (float) $s['total'];
            $out[$k]['last'] = $s['at'];
            foreach ($s['items'] as $i) { $out[$k]['items'] += (float) $i['qty']; }
        }
        usort($out, fn($a, $b) => $b['total'] <=> $a['total']);
        return $out;
    }

    /** Per product: quantity and amount sold, and who sold it. */
    public static function byProduct(array $sales): array
    {
        $out = [];
        foreach ($sales as $s) {
            foreach ($s['items'] as $i) {
                $k = mb_strtolower(trim($i['product']));
                $out[$k] ??= ['product' => $i['product'], 'qty' => 0.0, 'amount' => 0.0, 'sales' => 0, 'staff' => []];
                $out[$k]['qty'] += (float) $i['qty'];
                $out[$k]['amount'] += (float) $i['amount'];
                $out[$k]['sales']++;
                $name = $s['staff'] ?: 'Unknown';
                $out[$k]['staff'][$name] = ($out[$k]['staff'][$name] ?? 0) + (float) $i['qty'];
            }
        }
        usort($out, fn($a, $b) => $b['amount'] <=> $a['amount']);
        return array_values($out);
    }

    /** Workbook sheets shared by the Excel and PDF downloads. */
    public static function sheets(array $sales, array $sessions, bool $everyone): array
    {
        $q = fn($n) => (float) rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
        $sheets = [];
        if ($sessions) {
            $sheets['Opening & closing'] = [
                'headers' => ['Date', 'Person', 'Opened at', 'Opening sales', 'Closed at', 'Closing sales'],
                'rows' => array_map(fn($d) => [
                    $d['day'], $d['username'] ?? '', date('H:i', strtotime($d['opened_at'])), (float) $d['opening_sales'],
                    $d['closed_at'] ? date('H:i', strtotime($d['closed_at'])) : 'Open', (float) ($d['closing_sales'] ?? $d['live_closing'] ?? 0),
                ], $sessions),
                'money' => [3, 5],
            ];
        }
        if ($everyone) {
            $sheets['By staff'] = [
                'headers' => ['Person', 'Sales', 'Items sold', 'Total', 'First sale', 'Last sale'],
                'rows' => array_map(fn($r) => [$r['staff'], $r['count'], $q($r['items']), round($r['total'], 2), $r['first'], $r['last']], self::byStaff($sales)),
                'money' => [3], 'totals' => [1, 2, 3],
            ];
        }
        $sheets['By product'] = [
            'headers' => ['Product', 'Qty sold', 'Amount', 'Times sold', 'Sold by'],
            'rows' => array_map(fn($r) => [
                $r['product'], $q($r['qty']), round($r['amount'], 2), $r['sales'],
                implode(', ', array_map(fn($n, $v) => $n . ' (' . $q($v) . ')', array_keys($r['staff']), $r['staff'])),
            ], self::byProduct($sales)),
            'money' => [2], 'totals' => [1, 2],
        ];
        $sheets['Sales'] = [
            'headers' => ['Date & time', 'Type', 'Receipt', 'Person', 'Customer', 'Payment', 'Items', 'Total'],
            'rows' => array_map(fn($s) => [
                $s['at'], $s['source'], '#' . $s['id'], $s['staff'] ?? '', $s['customer'] ?? '', ucfirst((string) $s['method']),
                implode(', ', array_map(fn($i) => $q($i['qty']) . ' × ' . $i['product'], $s['items'])), round((float) $s['total'], 2),
            ], $sales),
            'money' => [7], 'totals' => [7],
        ];
        return $sheets;
    }

    /**
     * Where each package keeps its sales. `by` is the person credited with
     * the sale; `at` when the money came in.
     */
    private static function sources(PDO $db): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $exists = function (string $t) use ($db): bool {
            try { $db->query("SELECT 1 FROM `$t` LIMIT 0"); return true; } catch (\Throwable $e) { return false; }
        };
        $src = [
            'sale' => [
                'label' => 'Sale', 'table' => 'sales', 'at' => 'created_at', 'by' => 'x.staff_id', 'where' => "x.status = 'completed'",
                'total' => 'x.total', 'customer' => 'x.customer_name', 'method' => 'x.payment_method',
                'items' => 'SELECT sale_id AS parent, product_id, product_name AS product, quantity AS qty, line_total AS amount FROM sale_items WHERE sale_id IN (%s) AND quantity > 0',
            ],
            'order' => [
                'label' => 'Sale', 'table' => 'orders', 'at' => 'paid_at', 'by' => 'x.opened_by', 'where' => "x.status = 'paid'",
                'total' => 'x.total', 'customer' => 'x.table_name', 'method' => 'x.payment_method',
                'items' => 'SELECT order_id AS parent, product_id, product_name AS product, quantity AS qty, line_total AS amount FROM order_items WHERE order_id IN (%s) AND quantity > 0',
            ],
        ];
        if ($exists('rest_orders')) {
            $src['rest'] = [
                'label' => 'Restaurant', 'table' => 'rest_orders', 'at' => 'paid_at', 'by' => 'COALESCE(x.paid_by, x.user_id)', 'where' => "x.status = 'paid'",
                'total' => 'x.total', 'customer' => "COALESCE(x.customer_name, x.table_label)", 'method' => "'paid'",
                'items' => 'SELECT order_id AS parent, ref_id AS product_id, name AS product, quantity AS qty, line_total AS amount FROM rest_order_items WHERE order_id IN (%s)',
            ];
        }
        if ($exists('svc_tickets')) {
            $src['svc'] = [
                'label' => 'Services', 'table' => 'svc_tickets', 'at' => 'paid_at', 'by' => 'COALESCE(x.paid_by, x.created_by)', 'where' => "x.status = 'paid'",
                'total' => 'x.total', 'customer' => 'x.customer_name', 'method' => "COALESCE(x.payment_method, 'paid')",
                'items' => 'SELECT ticket_id AS parent, COALESCE(product_id, service_id) AS product_id, service_name AS product, quantity AS qty, price * quantity AS amount FROM svc_ticket_items WHERE ticket_id IN (%s)',
            ];
        }
        return $cache = $src;
    }
}
