<?php
// app/helpers/ShopExport.php
// Downloads one account's data (the main shop and its branches) as a .sql
// file in the layout ShopImport reads, so an owner can take their shop to
// another install and bring it back again with Import. Covers every table in
// ShopImport::PLAN: stock, suppliers, customers, staff (with their PINs and
// passwords), sales, orders, payments, credit book, expenses, attendance,
// services. Rows are written as plain INSERTs; nothing else is in the file.

class ShopExport
{
    /** Stream the export to the browser as a download. */
    public static function download(int $accountId): void
    {
        @set_time_limit(0);
        $pdo = Database::pdo();
        $slug = (string) $pdo->query('SELECT slug FROM tenants WHERE id = ' . $accountId)->fetchColumn();
        $name = ($slug !== '' ? $slug : 'shop') . '-export-' . date('Y-m-d-His') . '.sql';
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        self::write($accountId, fn(string $s) => print($s));
        exit;
    }

    /** Write the export through $out (one SQL chunk per call). */
    public static function write(int $accountId, callable $out): void
    {
        $pdo = Database::pdo();
        $cols = self::columns($pdo);
        $tenantIds = array_map('intval', $pdo->query(
            'SELECT id FROM tenants WHERE id = ' . $accountId . ' OR parent_tenant_id = ' . $accountId
        )->fetchAll(PDO::FETCH_COLUMN));

        $out("-- Shop export · account #$accountId · " . date('Y-m-d H:i:s') . "\n"
            . "-- Import it from Business → Backup & move on any Dazu POS account.\n\n");

        $exported = []; // table => ids written (for tables that hang off a parent)
        $dump = function (string $table, string $where) use ($pdo, $cols, $out, &$exported) {
            if (!isset($cols[$table])) {
                return;
            }
            $list = '`' . implode('`, `', $cols[$table]) . '`';
            $st = $pdo->query("SELECT $list FROM `$table` WHERE $where");
            $batch = [];
            $ids = [];
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                if (isset($r['id'])) { $ids[] = (int) $r['id']; }
                $batch[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $r)) . ')';
                if (count($batch) === 200) {
                    $out("INSERT INTO `$table` ($list) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                $out("INSERT INTO `$table` ($list) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $exported[$table] = $ids;
            $out("\n");
        };
        $in = fn(array $ids) => $ids ? implode(',', array_map('intval', $ids)) : '0';

        $dump('tenants', 'id IN (' . $in($tenantIds) . ')');
        $dump('roles', '1 = 1'); // so staff keep their role on import

        foreach (ShopImport::PLAN as $table => $links) {
            if (!isset($cols[$table])) {
                continue;
            }
            if (in_array('tenant_id', $cols[$table], true)) {
                $dump($table, 'tenant_id IN (' . $in($tenantIds) . ')');
                continue;
            }
            // No tenant column: keep the rows that belong to a parent we exported.
            $parents = $links + ($table === 'user_profiles' ? ['user_id' => 'users'] : []);
            foreach ($parents as $col => $parent) {
                if (isset($exported[$parent])) {
                    $dump($table, "`$col` IN (" . $in($exported[$parent]) . ')');
                    break;
                }
            }
        }
    }

    /** [table => [column, …]] for this database, in column order. */
    private static function columns(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT table_name AS t, column_name AS c FROM information_schema.columns
              WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position'
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['t']][] = $r['c'];
        }
        return $out;
    }
}
