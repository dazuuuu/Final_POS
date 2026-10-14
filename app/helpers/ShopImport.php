<?php
// app/helpers/ShopImport.php
// Imports a shop from another POS install's database export (a phpMyAdmin /
// mysqldump .sql file with the same table layout as ours). The dump is parsed
// in PHP — nothing in it is executed — and only the rows of the chosen source
// shop are copied into this account, with every id remapped:
// stock (categories, products, suppliers…), staff (with their PINs and
// passwords), sales, orders, payments, stock intakes, attendance, audit log.
//
// Columns are matched by name, so a dump with extra or missing columns still
// imports; tables we don't have (blogs, invoices…) are skipped and listed.

class ShopImport
{
    /**
     * Import order. table => [column => table its id points at].
     * 'users' links fall back to the account owner when the user isn't found.
     */
    const PLAN = [
        'suppliers'                    => [],
        'book_attributes'              => [],
        'categories'                   => [],
        'subcategories'                => ['category_id' => 'categories'],
        'customers'                    => [],
        'products'                     => ['category_id' => 'categories', 'subcategory_id' => 'subcategories',
                                           'grade_id' => 'book_attributes', 'publisher_id' => 'book_attributes',
                                           'author_id' => 'book_attributes', 'edition_id' => 'book_attributes',
                                           'brand_id' => 'book_attributes', 'supplier_id' => 'suppliers'],
        'users'                        => [], // special-cased
        'user_permissions'             => ['user_id' => 'users'],
        'user_profiles'                => ['user_id' => 'users'],
        'finance_expenses'             => ['created_by' => 'users'],
        'purchases'                    => ['expense_id' => 'finance_expenses', 'created_by' => 'users'],
        'purchase_items'               => ['purchase_id' => 'purchases'],
        'stock_intakes'                => ['supplier_id' => 'suppliers', 'staff_id' => 'users'],
        'stock_intake_items'           => ['stock_intake_id' => 'stock_intakes', 'product_id' => 'products'],
        'sales'                        => ['staff_id' => 'users', 'customer_id' => 'customers'],
        'sale_items'                   => ['sale_id' => 'sales', 'product_id' => 'products'],
        'sale_payments'                => ['sale_id' => 'sales', 'staff_id' => 'users'],
        'orders'                       => ['customer_id' => 'customers', 'opened_by' => 'users', 'paid_by' => 'users'],
        'order_items'                  => ['order_id' => 'orders', 'product_id' => 'products', 'added_by' => 'users'],
        'order_payments'               => ['order_id' => 'orders'],
        'order_returns'                => ['order_id' => 'orders'],
        'order_return_items'           => ['return_id' => 'order_returns', 'order_item_id' => 'order_items', 'product_id' => 'products'],
        'held_orders'                  => ['customer_id' => 'customers', 'staff_id' => 'users'],
        'held_order_items'             => ['held_order_id' => 'held_orders', 'product_id' => 'products'],
        'manual_credits'               => ['created_by' => 'users'],
        'manual_credit_payments'       => ['credit_id' => 'manual_credits'],
        'staff_time_logs'              => ['user_id' => 'users'],
        'staff_reclock_authorizations' => ['user_id' => 'users', 'authorized_by' => 'users'],
        'audit_log'                    => ['user_id' => 'users'],
        // Services package (also filled from other service POS layouts by adapt()).
        'svc_services'                 => [],
        'svc_payouts'                  => ['staff_id' => 'users', 'paid_by' => 'users'],
        'svc_tickets'                  => ['customer_id' => 'customers', 'created_by' => 'users', 'paid_by' => 'users'],
        'svc_ticket_items'             => ['ticket_id' => 'svc_tickets', 'service_id' => 'svc_services', 'product_id' => 'products',
                                           'staff_id' => 'users', 'payout_id' => 'svc_payouts'],
    ];

    /** Parent links a row can't exist without (row is skipped when the parent is missing). */
    const REQUIRED = ['category_id@subcategories', 'sale_id', 'order_id', 'stock_intake_id', 'held_order_id',
                      'return_id', 'credit_id', 'purchase_id', 'ticket_id'];

    // ------------------------------------------------------------------ parse

    /**
     * Parse a dump into ['table' => ['cols' => [...], 'rows' => [[...], ...]]].
     * Only CREATE TABLE (for column order) and INSERT statements are read.
     */
    public static function parse(string $sql): array
    {
        $tables = [];
        foreach (self::statements($sql) as $st) {
            if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?\s*\((.*)\)/is', $st, $m)) {
                preg_match_all('/(?:^|,)\s*`(\w+)`\s+[a-z]/i', $m[2], $cm);
                $tables[$m[1]] = ['cols' => $cm[1], 'rows' => $tables[$m[1]]['rows'] ?? []];
            } elseif (preg_match('/^(?:INSERT|REPLACE)(?:\s+IGNORE)?\s+INTO\s+`?(\w+)`?\s*(\(([^)]*)\))?\s*VALUES\s*/is', $st, $m)) {
                $t = $m[1];
                $cols = !empty($m[3])
                    ? array_map(fn($c) => trim($c, " `\t\r\n"), explode(',', $m[3]))
                    : ($tables[$t]['cols'] ?? []);
                $tables[$t] ??= ['cols' => $cols, 'rows' => []];
                foreach (self::tuples(substr($st, strlen($m[0]))) as $vals) {
                    if (count($vals) === count($cols)) {
                        $tables[$t]['rows'][] = array_combine($cols, $vals);
                    }
                }
            }
        }
        return self::adapt($tables);
    }

    /**
     * Translate other POS layouts into ours, so their data lands in the right
     * tables. Today: the commission-based services POS (barbershop / salon):
     *   tenant_services    → svc_services   (price list, commission %)
     *   commission_sales   → svc_tickets + svc_ticket_items (each job: staff, price, commission, payout)
     *   commission_payouts → svc_payouts
     *   appointments       → svc_tickets (booked)
     *   staff_attendance   → staff_time_logs (clock in/out, late flag)
     * plus column renames: users.login_pin_hash → pin_hash, products.selling_price → retail_price.
     * Rows keep their source ids and tenant_id so run() remaps them like any table.
     */
    public static function adapt(array $tables): array
    {
        $rows = fn(string $t) => $tables[$t]['rows'] ?? [];
        $add = function (string $t, array $r) use (&$tables) {
            $tables[$t] ??= ['cols' => array_keys($r), 'rows' => []];
            $tables[$t]['rows'][] = $r;
        };

        foreach (array_keys($tables['users']['rows'] ?? []) as $i) {
            $u = &$tables['users']['rows'][$i];
            if (empty($u['pin_hash']) && !empty($u['login_pin_hash'])) $u['pin_hash'] = $u['login_pin_hash'];
            unset($u);
        }
        foreach (array_keys($tables['products']['rows'] ?? []) as $i) {
            $p = &$tables['products']['rows'][$i];
            if (!isset($p['retail_price']) && isset($p['selling_price'])) $p['retail_price'] = $p['selling_price'];
            unset($p);
        }

        if (!isset($tables['svc_services'])) {
            foreach ($rows('tenant_services') as $r) {
                $add('svc_services', [
                    'id' => $r['id'], 'tenant_id' => $r['tenant_id'] ?? null, 'name' => $r['name'], 'description' => $r['description'] ?? null,
                    'price' => $r['charge_amount'] ?? 0, 'commission_pct' => ($r['commission_type'] ?? 'percent') === 'percent' ? ($r['commission_value'] ?? null) : null,
                    'active' => ($r['status'] ?? 'active') === 'active' ? 1 : 0, 'created_at' => $r['created_at'] ?? null,
                ]);
            }
        }
        if (!isset($tables['svc_payouts'])) {
            foreach ($rows('commission_payouts') as $r) {
                $add('svc_payouts', [
                    'id' => $r['id'], 'tenant_id' => $r['tenant_id'] ?? null, 'staff_id' => $r['agent_user_id'], 'amount' => $r['amount_paid'] ?? 0,
                    'items' => $r['sales_count'] ?? null, 'method' => 'cash', 'note' => $r['notes'] ?? null, 'paid_by' => $r['paid_by'] ?? null,
                    'created_at' => $r['paid_at'] ?? null,
                ]);
            }
        }
        if (!isset($tables['svc_tickets'])) {
            $svcByName = [];
            foreach ($rows('tenant_services') as $r) $svcByName[($r['tenant_id'] ?? '') . '|' . mb_strtolower(trim($r['name']))] = $r['id'];
            foreach ($rows('commission_sales') as $r) {
                if (empty($r['service_id']) && ($r['item_type'] ?? 'service') === 'service') { // link the job to its service by name
                    $r['service_id'] = $svcByName[($r['tenant_id'] ?? '') . '|' . mb_strtolower(trim((string) $r['item_name']))] ?? null;
                }
                $paid = ($r['payment_status'] ?? 'paid') === 'paid';
                $amount = (float) ($r['charged_amount'] ?? 0);
                $qty = max(1.0, (float) ($r['quantity'] ?? 1));
                $method = in_array($r['payment_method'] ?? '', ['cash', 'mpesa'], true) ? $r['payment_method'] : 'cash';
                $add('svc_tickets', [
                    'id' => $r['id'], 'tenant_id' => $r['tenant_id'] ?? null, 'number' => $r['receipt_number'] ?? null,
                    'customer_id' => $r['customer_id'] ?? null, 'customer_name' => $r['customer_name'] ?: 'Walk-in', 'customer_phone' => $r['customer_phone'] ?? null,
                    'status' => $paid ? 'paid' : 'open', 'notes' => $r['notes'] ?? null,
                    'subtotal' => $amount, 'discount_amount' => 0, 'total' => $amount,
                    'payment_method' => $paid ? $method : null, 'cash_amount' => $paid && $method === 'cash' ? $amount : null,
                    'mpesa_amount' => $paid && $method === 'mpesa' ? $amount : null,
                    'created_by' => $r['recorded_by_user_id'] ?? null, 'paid_by' => $paid ? ($r['recorded_by_user_id'] ?? null) : null,
                    'paid_at' => $paid ? ($r['paid_at'] ?: $r['created_at']) : null, 'created_at' => $r['created_at'] ?? null, 'source' => 'desk',
                ]);
                $add('svc_ticket_items', [
                    'id' => $r['id'], 'tenant_id' => $r['tenant_id'] ?? null, 'ticket_id' => $r['id'],
                    'service_id' => $r['service_id'] ?? null, 'service_name' => $r['item_name'] ?: 'Service',
                    'kind' => ($r['item_type'] ?? 'service') === 'product' ? 'product' : 'service', 'product_id' => $r['product_id'] ?? null,
                    'quantity' => $qty, 'price' => round($amount / $qty, 2), 'staff_id' => $r['agent_user_id'] ?? null,
                    'status' => 'done', 'done_at' => $r['created_at'] ?? null,
                    'commission_pct' => $amount > 0 ? round((float) ($r['total_commission'] ?? 0) / $amount * 100, 2) : null,
                    'commission_amount' => $r['total_commission'] ?? 0, 'payout_id' => $r['payout_id'] ?? null,
                ]);
            }
            foreach ($rows('appointments') as $r) {
                $id = 1000000000 + (int) $r['id']; // keep clear of commission_sales ids
                $add('svc_tickets', [
                    'id' => $id, 'tenant_id' => $r['tenant_id'] ?? null, 'customer_id' => $r['customer_id'] ?? null,
                    'customer_name' => $r['customer_name'] ?: 'Client', 'customer_phone' => $r['customer_phone'] ?? null,
                    'status' => ($r['status'] ?? '') === 'cancelled' || ($r['status'] ?? '') === 'no_show' ? 'cancelled' : 'booked',
                    'scheduled_at' => $r['scheduled_at'] ?? null, 'notes' => $r['notes'] ?? null,
                    'created_by' => $r['created_by'] ?? null, 'created_at' => $r['created_at'] ?? null, 'source' => 'desk',
                ]);
                $add('svc_ticket_items', [
                    'id' => $id, 'tenant_id' => $r['tenant_id'] ?? null, 'ticket_id' => $id, 'service_id' => $r['service_id'] ?? null,
                    'service_name' => $r['service_name'] ?: 'Service', 'kind' => 'service', 'quantity' => 1, 'price' => 0,
                    'staff_id' => $r['agent_user_id'] ?? null, 'status' => 'pending',
                ]);
            }
        }
        if (!isset($tables['staff_time_logs'])) {
            foreach ($rows('staff_attendance') as $r) {
                if (empty($r['login_at'])) continue;
                $add('staff_time_logs', [
                    'id' => $r['id'], 'tenant_id' => $r['tenant_id'] ?? null, 'user_id' => $r['user_id'],
                    'clock_in_at' => $r['login_at'], 'clock_out_at' => $r['logout_at'] ?? null,
                    'auto_closed' => 0, 'late_clock_in' => ($r['login_status'] ?? '') === 'late' ? 1 : 0, 'created_at' => $r['created_at'] ?? null,
                ]);
            }
        }
        return $tables;
    }

    /** Split on ; outside quotes and comments. */
    private static function statements(string $sql): \Generator
    {
        $n = strlen($sql);
        $start = 0;
        $q = null;
        for ($i = 0; $i < $n; $i++) {
            $c = $sql[$i];
            // A new INSERT / CREATE TABLE / section comment at the start of a line
            // always begins a new statement. Dumps never put these at a line start
            // inside a value, so this recovers from a damaged row (stray quote,
            // cut-off line, missing ';') instead of swallowing the rest of the file.
            if ($c === "\n" && preg_match('/\G\n(?:INSERT INTO |REPLACE INTO |CREATE TABLE |-- -{10})/A', $sql, $mm, 0, $i)) {
                $st = trim(substr($sql, $start, $i - $start));
                if ($st !== '') { yield $st; }
                $start = $i + 1;
                $q = null;
                continue;
            }
            if ($q !== null) {
                if ($c === '\\') { $i++; continue; }
                if ($c === $q) {
                    if ($i + 1 < $n && $sql[$i + 1] === $q) { $i++; continue; }
                    $q = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') { $q = $c; continue; }
            if (($c === '-' && ($sql[$i + 1] ?? '') === '-' && ctype_space($sql[$i + 2] ?? "\n")) || $c === '#') {
                $e = strpos($sql, "\n", $i);
                $e = $e === false ? $n : $e;
                if (trim(substr($sql, $start, $i - $start)) === '') { $start = $e + 1; }
                $i = $e;
                continue;
            }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $e = strpos($sql, '*/', $i + 2);
                $e = $e === false ? $n : $e + 1;
                if (trim(substr($sql, $start, $i - $start)) === '') { $start = $e + 1; }
                $i = $e;
                continue;
            }
            if ($c === ';') {
                $st = trim(substr($sql, $start, $i - $start));
                if ($st !== '') { yield $st; }
                $start = $i + 1;
            }
        }
        $st = trim(substr($sql, $start));
        if ($st !== '') { yield $st; }
    }

    /** Parse "(...),(...)" into arrays of PHP values (strings / null). */
    private static function tuples(string $s): \Generator
    {
        static $esc = ['0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1a"];
        $n = strlen($s);
        $i = 0;
        while ($i < $n) {
            while ($i < $n && $s[$i] !== '(') { $i++; }
            if ($i >= $n) { return; }
            $i++;
            $row = [];
            while ($i < $n) {
                while ($i < $n && ctype_space($s[$i])) { $i++; }
                if (($s[$i] ?? '') === "'") {
                    $v = '';
                    for ($i++; $i < $n; $i++) {
                        $c = $s[$i];
                        if ($c === '\\' && $i + 1 < $n) { $d = $s[++$i]; $v .= $esc[$d] ?? $d; continue; }
                        if ($c === "'") {
                            if (($s[$i + 1] ?? '') === "'") { $v .= "'"; $i++; continue; }
                            $i++;
                            break;
                        }
                        $v .= $c;
                    }
                    $row[] = $v;
                } else {
                    $depth = 0;
                    $b = $i;
                    while ($i < $n && ($depth > 0 || ($s[$i] !== ',' && $s[$i] !== ')'))) {
                        if ($s[$i] === '(') $depth++;
                        if ($s[$i] === ')') $depth--;
                        $i++;
                    }
                    $tok = trim(substr($s, $b, $i - $b));
                    $row[] = strcasecmp($tok, 'NULL') === 0 ? null : $tok;
                }
                while ($i < $n && ctype_space($s[$i])) { $i++; }
                if (($s[$i] ?? '') === ',') { $i++; continue; }
                $i++; // ')'
                break;
            }
            yield $row;
        }
    }

    // ---------------------------------------------------------------- preview

    /** Shops (source tenants) found in the dump: [id => name]. */
    public static function sourceShops(array $tables): array
    {
        $out = [];
        foreach ($tables['tenants']['rows'] ?? [] as $r) {
            $out[(int) $r['id']] = $r['name'] ?? ('Shop #' . $r['id']);
        }
        if (!$out) { // no tenants table: collect tenant ids used by products
            foreach ($tables['products']['rows'] ?? [] as $r) {
                if (isset($r['tenant_id'])) $out[(int) $r['tenant_id']] = 'Shop #' . $r['tenant_id'];
            }
        }
        return $out;
    }

    /** Row counts per importable table for one source shop, plus skipped tables. */
    public static function preview(array $tables, int $srcTenant): array
    {
        $live = self::liveTables();
        $counts = [];
        foreach (array_keys(self::PLAN) as $t) {
            if (!isset($tables[$t]) || !isset($live[$t])) continue;
            $counts[$t] = count(self::rowsFor($tables, $t, $srcTenant));
        }
        $skipped = array_values(array_diff(array_keys($tables), array_keys(self::PLAN), ['tenants', 'roles']));
        return ['counts' => $counts, 'skipped' => $skipped, 'fields' => ProductFields::detect($tables, $srcTenant)];
    }

    private static function rowsFor(array $tables, string $t, int $src): array
    {
        $rows = $tables[$t]['rows'] ?? [];
        if ($rows && array_key_exists('tenant_id', $rows[0])) {
            return array_values(array_filter($rows, fn($r) => (int) $r['tenant_id'] === $src));
        }
        if ($t === 'user_profiles') { // no tenant column — keep profiles of this shop's users
            $ids = array_flip(array_map(fn($u) => (int) $u['id'], self::rowsFor($tables, 'users', $src)));
            return array_values(array_filter($rows, fn($r) => isset($ids[(int) $r['user_id']])));
        }
        return $rows;
    }

    // ----------------------------------------------------------------- import

    /**
     * Copy one source shop into $accountId. Returns ['counts' => [table => n],
     * 'skipped_rows' => n, 'notes' => [...]]. Throws on a fatal error (rolled back).
     */
    public static function run(array $tables, int $srcTenant, int $accountId, bool $takeOver = false): array
    {
        @set_time_limit(0);
        $pdo = Database::pdo();
        Signup::ensureColumns();
        if (isset($tables['svc_tickets']) || isset($tables['svc_services'])) {
            try { new Models\ServiceDeskModel($pdo); } catch (Throwable $e) { /* creates the svc_* tables when missing */ }
        }
        $live = self::liveTables();
        $owner = (int) $pdo->query('SELECT owner_user_id FROM tenants WHERE id = ' . (int) $accountId)->fetchColumn();
        $srcOwnerRow = self::sourceOwner($tables, $srcTenant);
        $srcOwner = (int) ($srcOwnerRow['id'] ?? 0);
        if (!$owner && !$srcOwnerRow) {
            throw new RuntimeException('No owner was found in this file and the account has no owner yet. Create a full account (with an owner login) and import again.');
        }
        foreach ($tables['tenants']['rows'] ?? [] as $r) {
            if ((int) $r['id'] === $srcTenant) $srcShop = $r;
        }

        $map = ['users' => []];
        $counts = [];
        $skipped = 0;
        $notes = [];

        $oldMode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'"); // lenient: bad enum/dates coerce instead of failing
        $pdo->beginTransaction();
        try {
            // Support-led move: the account has no owner yet, so the old shop's
            // owner becomes it — same username, email and password, so they just
            // sign in with what they used before.
            // $takeOver: the old owner replaces this account's current owner, and
            // logins from the file that already exist elsewhere are freed first.
            if ($srcOwnerRow && (!$owner || $takeOver)) {
                $prev = $owner;
                $owner = self::createOwner($pdo, $srcOwnerRow, $accountId, $takeOver, $notes);
                if ($prev && $prev !== $owner) {
                    $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$prev]);
                    $notes[] = 'The previous owner login was switched off (its records stay).';
                }
                $notes[] = "Owner login: {$srcOwnerRow['email']} — they sign in with their old password.";
            }
            // Business details the new account hasn't filled in yet.
            if (!empty($srcShop)) {
                foreach (['phone', 'address', 'receipt_footer', 'kra_pin', 'currency'] as $c) {
                    if (isset($live['tenants'][$c]) && ($srcShop[$c] ?? '') !== '') {
                        $pdo->prepare("UPDATE tenants SET `$c` = ? WHERE id = ? AND (`$c` IS NULL OR `$c` = '' OR ? = 'currency')")
                            ->execute([$srcShop[$c], $accountId, $c]);
                    }
                }
            }

            foreach (self::PLAN as $t => $fks) {
                if (!isset($tables[$t], $live[$t])) continue;
                $map[$t] ??= [];
                $n = 0;
                foreach (self::rowsFor($tables, $t, $srcTenant) as $row) {
                    $oldId = isset($row['id']) ? (int) $row['id'] : null;
                    if ($t === 'users') {
                        $new = self::importUser($pdo, $tables, $row, $accountId, $owner, $srcOwner, $notes, $takeOver);
                        $map['users'][$oldId] = $new;
                        $n += $new !== $owner ? 1 : 0;
                        continue;
                    }
                    $data = [];
                    foreach ($row as $col => $val) {
                        if (!isset($live[$t][$col])) continue;
                        if ($col === 'id' && $live[$t]['id']['auto']) continue;
                        if ($val === null && !$live[$t][$col]['null']) {
                            // Blank in a required column (e.g. wholesale_price): use the column's
                            // default, or an empty value of the right kind.
                            if ($live[$t][$col]['def']) continue;
                            $val = $live[$t][$col]['num'] ? 0 : '';
                        }
                        $data[$col] = $val;
                    }
                    foreach (['tenant_id', 'account_id'] as $c) {
                        if (isset($live[$t][$c])) $data[$c] = $accountId;
                    }
                    $drop = false;
                    foreach ($fks as $col => $target) {
                        if (!array_key_exists($col, $data) || $data[$col] === null || $data[$col] === '') continue;
                        $mapped = $map[$target][(int) $data[$col]] ?? null;
                        if ($mapped === null && $target === 'users') $mapped = $owner;
                        if ($mapped === null && (in_array($col, self::REQUIRED, true) || in_array("$col@$t", self::REQUIRED, true))) {
                            $drop = true;
                            break;
                        }
                        $data[$col] = $mapped;
                    }
                    if ($t === 'user_profiles' && ($data['user_id'] ?? null) === $owner) $drop = true;
                    if ($drop) { $skipped++; continue; }

                    $cols = array_keys($data);
                    $sql = "INSERT INTO `$t` (`" . implode('`,`', $cols) . '`) VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')';
                    try {
                        $pdo->prepare($sql)->execute(array_values($data));
                        if ($oldId !== null) $map[$t][$oldId] = (int) $pdo->lastInsertId();
                        $n++;
                    } catch (PDOException $e) {
                        $skipped++;
                        if (count($notes) < 15) $notes[] = "$t #$oldId skipped: " . $e->getMessage();
                    }
                }
                $counts[$t] = $n;
            }
            $pdo->prepare('UPDATE tenants SET imported_at = NOW() WHERE id = ?')->execute([$accountId]);
            $pdo->commit();
            // The old POS's extra product fields (brands, grades…) get their menus and inputs here.
            $fields = ProductFields::detect($tables, $srcTenant);
            if ($fields) ProductFields::add($accountId, $fields);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            $pdo->prepare('SET SESSION sql_mode = ?')->execute([$oldMode]);
        }
        return ['counts' => $counts, 'skipped_rows' => $skipped, 'notes' => $notes, 'fields' => $fields ?? []];
    }

    /**
     * The source shop's owner row from the dump: tenants.owner_user_id, else
     * the shop's first user with an owner/admin role. Null when there is none.
     */
    public static function sourceOwner(array $tables, int $srcTenant): ?array
    {
        $users = self::rowsFor($tables, 'users', $srcTenant);
        $byId = [];
        foreach ($users as $u) $byId[(int) $u['id']] = $u;
        foreach ($tables['tenants']['rows'] ?? [] as $r) {
            if ((int) $r['id'] === $srcTenant && isset($byId[(int) ($r['owner_user_id'] ?? 0)])) {
                return $byId[(int) $r['owner_user_id']];
            }
        }
        $roles = [];
        foreach ($tables['roles']['rows'] ?? [] as $r) $roles[(int) $r['id']] = $r['role_name'] ?? '';
        foreach ($users as $u) {
            if (in_array($roles[(int) ($u['role_id'] ?? 0)] ?? '', ['tenant_owner', 'admin'], true)) return $u;
        }
        return null;
    }

    /** Create the account owner from the dump's owner row and activate the account. */
    private static function createOwner(PDO $pdo, array $u, int $accountId, bool $takeOver, array &$notes): int
    {
        $email = strtolower(trim((string) ($u['email'] ?? '')));
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'tenant_owner'")->fetchColumn();
        if ($takeOver) {
            $mine = self::freeLogin($pdo, $email, (string) $u['username'], $accountId, $notes);
            if ($mine) { // already a user of this account (an earlier import): make it the owner with the old login
                $pdo->prepare('UPDATE users SET username = ?, email = ?, password_hash = ?, role_id = ?, is_active = 1, must_reset_password = 0 WHERE id = ?')
                    ->execute([$u['username'], $email, $u['password_hash'], $roleId, $mine]);
                $pdo->prepare('UPDATE tenants SET owner_user_id = ?, signup_completed_at = COALESCE(signup_completed_at, NOW()) WHERE id = ?')->execute([$mine, $accountId]);
                License::forceActivate($accountId);
                return $mine;
            }
        }
        $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? OR username = ?');
        $chk->execute([$email, (string) $u['username']]);
        if ($chk->fetchColumn()) {
            throw new RuntimeException("The old owner's login ({$u['username']}, $email) is already used on this system. "
                . 'Tick "Take over the old logins" and import again.');
        }
        $pdo->prepare(
            'INSERT INTO users (tenant_id, username, email, password_hash, pin_hash, position, must_reset_password, role_id, is_active, email_verified, activated_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, 1, 1, NOW())'
        )->execute([$accountId, $u['username'], $email, $u['password_hash'], $u['pin_hash'] ?? null, $u['position'] ?? null, $roleId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE tenants SET owner_user_id = ?, signup_completed_at = NOW() WHERE id = ?')->execute([$id, $accountId]);
        License::forceActivate($accountId); // support set this account up — no activation key needed
        return $id;
    }

    /**
     * Free an email/username for this account. A matching user that already
     * belongs to this account (main shop or branch) is returned for reuse;
     * one on another account is switched off and renamed (…_old<ID>) so the
     * login can be recreated here. Returns the reusable user id, or null.
     */
    public static function freeLogin(PDO $pdo, string $email, string $username, int $accountId, array &$notes): ?int
    {
        $st = $pdo->prepare(
            'SELECT u.id, u.username, u.email, t.id AS tid, t.parent_tenant_id AS parent, t.name AS shop
               FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id
              WHERE u.email = ? OR u.username = ?'
        );
        $st->execute([$email, $username]);
        $mine = null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $hit) {
            if ((int) $hit['tid'] === $accountId || (int) $hit['parent'] === $accountId) {
                $mine ??= (int) $hit['id'];
                continue;
            }
            $tag = '_old' . $hit['id'];
            $pdo->prepare('UPDATE users SET username = ?, email = ?, is_active = 0 WHERE id = ?')->execute([
                substr($hit['username'], 0, 50 - strlen($tag)) . $tag,
                substr('old' . $hit['id'] . '.' . $hit['email'], 0, 100),
                $hit['id'],
            ]);
            $notes[] = "Login {$hit['username']} ({$hit['email']}) was taken over from \"" . ($hit['shop'] ?? 'another account') . '" — that copy is switched off.';
        }
        return $mine;
    }

    /** Create (or match) one source user. Returns the new user id. */
    private static function importUser(PDO $pdo, array $tables, array $u, int $accountId, int $owner, int $srcOwner, array &$notes, bool $takeOver = false): int
    {
        if ((int) $u['id'] === $srcOwner) return $owner;
        $roleName = 'staff';
        foreach ($tables['roles']['rows'] ?? [] as $r) {
            if ((int) $r['id'] === (int) ($u['role_id'] ?? 0)) $roleName = $r['role_name'];
        }
        if (in_array($roleName, ['superadmin', 'platform_admin'], true)) return $owner;
        if (!in_array($roleName, ['tenant_owner', 'staff'], true)) $roleName = $roleName === 'admin' ? 'tenant_owner' : 'staff';
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = " . $pdo->quote($roleName))->fetchColumn();

        $email = strtolower(trim((string) ($u['email'] ?? '')));
        if ($takeOver && ($mine = self::freeLogin($pdo, $email, (string) $u['username'], $accountId, $notes))) {
            return $mine; // already in this account (an earlier import)
        }
        $chk = $pdo->prepare('SELECT id, tenant_id FROM users WHERE email = ? LIMIT 1');
        $chk->execute([$email]);
        if ($hit = $chk->fetch(PDO::FETCH_ASSOC)) {
            if ((int) $hit['tenant_id'] === $accountId) return (int) $hit['id']; // already imported
            $notes[] = "User {$u['username']} ($email) already exists on another account — their records are linked to the owner.";
            return $owner;
        }
        $username = (string) $u['username'];
        $base = $username;
        for ($k = 2; ; $k++) {
            $c = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $c->execute([$username]);
            if (!$c->fetchColumn()) break;
            $username = substr($base, 0, 44) . '_' . $k;
        }
        if ($username !== $base) $notes[] = "Username {$base} was taken — imported as {$username}.";

        $pdo->prepare(
            'INSERT INTO users (tenant_id, username, email, password_hash, pin_hash, position, must_reset_password, role_id, is_active, email_verified, activated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())'
        )->execute([
            $accountId, $username, $email, $u['password_hash'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
            $u['pin_hash'] ?? null, $u['position'] ?? null, (int) ($u['must_reset_password'] ?? 0), $roleId,
            (int) ($u['is_active'] ?? 1),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** [table => [column => ['auto' => bool]]] for this database. */
    private static function liveTables(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $rows = Database::pdo()->query(
            'SELECT table_name AS t, column_name AS c, extra AS x, is_nullable AS n, column_default AS d, data_type AS ty
               FROM information_schema.columns WHERE table_schema = DATABASE()'
        )->fetchAll(PDO::FETCH_ASSOC);
        $cache = [];
        foreach ($rows as $r) {
            $cache[$r['t']][$r['c']] = [
                'auto' => stripos((string) $r['x'], 'auto_increment') !== false,
                'null' => $r['n'] === 'YES',
                'def'  => $r['d'] !== null || stripos((string) $r['x'], 'DEFAULT_GENERATED') !== false,
                'num'  => in_array(strtolower($r['ty']), ['int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'float', 'double'], true),
            ];
        }
        return $cache;
    }

    // ------------------------------------------------------------ upload temp

    public static function storeUpload(array $file): string
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Choose the .sql file to upload.');
        }
        $dir = ROOT_PATH . '/app/storage/imports';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $name = bin2hex(random_bytes(12)) . '.sql';
        if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
            throw new RuntimeException('Could not save the uploaded file.');
        }
        return $name;
    }

    public static function load(string $name): array
    {
        $path = ROOT_PATH . '/app/storage/imports/' . basename($name);
        if (!preg_match('/^[a-f0-9]{24}\.sql$/', $name) || !is_file($path)) {
            throw new RuntimeException('The uploaded file has expired. Upload it again.');
        }
        return self::parse((string) file_get_contents($path));
    }

    public static function discard(string $name): void
    {
        if (preg_match('/^[a-f0-9]{24}\.sql$/', $name)) @unlink(ROOT_PATH . '/app/storage/imports/' . $name);
    }
}
