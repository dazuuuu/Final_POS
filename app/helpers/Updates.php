<?php
// app/helpers/Updates.php
// In-app updates. Each update is a versioned step with plain-language notes
// for the owner and a run() that prepares the database for it. The owner
// applies pending updates from Admin portal → Updates; every step is safe to
// run more than once. To ship a new update, add an entry to all() below.

class Updates
{
    /** Newest last. 'run' must be idempotent. */
    public static function all(): array
    {
        return [
            [
                'version' => '2.0.0',
                'date'    => '2026-10-08',
                'title'   => 'Multiple shops, warehouse, credit book & expenses',
                'notes'   => [
                    'Open extra shops, each with its own name, staff, stock, sales, credit and catalogue. You manage them all from one login.',
                    'Your original shop is now the Admin portal. The warehouse and the overview of every shop live there.',
                    'Warehouse: record stock at buying price only, then move it into any shop and set its retail and/or wholesale price at that moment.',
                    'Credit book: record that a customer owes money without listing products, and receive part or full payments.',
                    'Expenses: record name, description, amount and a photo of the receipt. The dashboard shows today\'s expenses and net profit per shop and for all shops together.',
                    'New staff permissions: Credit sales, Accept credit payments and Record expenses. Staff who could already sell on credit keep that ability.',
                    'Phones and tablets get an app-style bottom menu: Dashboard, POS, Shops, Inventory, Finance and More.',
                ],
                'run' => function (PDO $db) {
                    ShopContext::ensureSchema($db);
                    new Models\FinanceModel($db);   // expense description + receipt columns
                    new Models\CategoryModel($db);  // category types
                },
            ],
            [
                'version' => '2.1.0',
                'date'    => '2026-10-08',
                'title'   => 'One category list for every shop',
                'notes'   => [
                    'The warehouse and all your shops now share the same categories.',
                    'Adding, renaming or deleting a category in one place updates every shop.',
                    'Stock moved from the warehouse lands in the same category in the shop.',
                ],
                'run' => function (PDO $db) {
                    ShopContext::ensureSchema($db);
                    $home = ShopContext::homeTenantId();
                    if ($home !== null) { ShopContext::syncCategories($home); }
                },
            ],
            [
                'version' => '2.2.0',
                'date'    => '2026-10-08',
                'title'   => 'Admin portal, updates and the user guide',
                'notes'   => [
                    'The main shop is labelled "Admin portal" everywhere, and the warehouse can only be opened from there.',
                    'This Updates page: new features arrive with notes explaining them, and you apply them with one tap.',
                    'A Guide page (Help → Guide) explains how shops, the warehouse, staff, credit and expenses fit together.',
                ],
                'run' => function (PDO $db) {
                    self::ensureTable($db);
                },
            ],
        ];
    }

    public static function latestVersion(): string
    {
        $all = self::all();
        return end($all)['version'];
    }

    private static function ensureTable(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS app_updates (
            version    VARCHAR(20) PRIMARY KEY,
            applied_by INT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** version => applied_at */
    public static function applied(?PDO $db = null): array
    {
        $db = $db ?? Database::pdo();
        self::ensureTable($db);
        $out = [];
        foreach ($db->query('SELECT version, applied_at FROM app_updates')->fetchAll() as $r) {
            $out[$r['version']] = $r['applied_at'];
        }
        return $out;
    }

    public static function pending(?PDO $db = null): array
    {
        $done = self::applied($db);
        return array_values(array_filter(self::all(), fn($u) => !isset($done[$u['version']])));
    }

    /** Run every pending update in order. Stops at the first failure. */
    public static function runPending(?PDO $db = null): array
    {
        $db = $db ?? Database::pdo();
        $ran = [];
        foreach (self::pending($db) as $u) {
            try {
                ($u['run'])($db);
            } catch (\Throwable $e) {
                return ['ok' => false, 'ran' => $ran, 'error' => 'Update ' . $u['version'] . ' failed: ' . $e->getMessage()];
            }
            $db->prepare('INSERT IGNORE INTO app_updates (version, applied_by) VALUES (?, ?)')
               ->execute([$u['version'], TenantContext::userId()]);
            $ran[] = $u['version'];
        }
        return ['ok' => true, 'ran' => $ran, 'error' => null];
    }
}
