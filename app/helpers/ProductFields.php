<?php
// app/helpers/ProductFields.php
// Optional product fields an account uses on top of the standard ones
// (name, category, supplier, prices…): subcategory, grade, publisher,
// author, edition, brand. Each shop's old POS used a different mix, so the
// set is per account (tenants.product_fields JSON on the top-level tenant).
//
// ShopImport switches on every field the imported data actually uses, so a
// bookshop gets Grades/Publishers and a hardware shop gets Brands without
// anyone configuring it. The owner can also turn fields on/off on
// super/fields/. An enabled field adds its own menu link (to manage its
// values) and an input on Record stock and Edit product.

class ProductFields
{
    /** key => [label, plural label, icon, example] */
    const FIELDS = [
        'subcategory' => ['Subcategory', 'Subcategories', 'fa-sitemap', 'e.g. Exercise books'],
        'brand'       => ['Brand', 'Brands', 'fa-copyright', 'e.g. Samsung'],
        'grade'       => ['Grade / class', 'Grades', 'fa-graduation-cap', 'e.g. Grade 4'],
        'publisher'   => ['Publisher', 'Publishers', 'fa-building-columns', 'e.g. Longhorn'],
        'author'      => ['Author', 'Authors', 'fa-feather', 'e.g. Ngugi wa Thiong\'o'],
        'edition'     => ['Edition', 'Editions', 'fa-layer-group', 'e.g. 2nd edition'],
    ];

    private static array $cache = [];

    /** Enabled field keys for the signed-in user's account, in FIELDS order. */
    public static function enabled(?int $tenantId = null): array
    {
        $tenantId ??= TenantContext::tenantId();
        if ($tenantId === null) {
            return [];
        }
        $account = Features::accountId($tenantId);
        if (!isset(self::$cache[$account])) {
            try {
                self::ensureColumn();
                $st = Database::pdo()->prepare('SELECT product_fields FROM tenants WHERE id = ?');
                $st->execute([$account]);
                $on = json_decode((string) $st->fetchColumn(), true) ?: [];
            } catch (Throwable $e) {
                $on = [];
            }
            self::$cache[$account] = array_values(array_intersect(array_keys(self::FIELDS), $on));
        }
        return self::$cache[$account];
    }

    public static function on(string $key, ?int $tenantId = null): bool
    {
        return in_array($key, self::enabled($tenantId), true);
    }

    /** Replace the account's field set. */
    public static function set(int $accountId, array $keys): void
    {
        self::ensureColumn();
        $keys = array_values(array_intersect(array_keys(self::FIELDS), $keys));
        Database::pdo()->prepare('UPDATE tenants SET product_fields = ? WHERE id = ?')
            ->execute([json_encode($keys), $accountId]);
        unset(self::$cache[$accountId]);
    }

    /** Turn fields on without turning any off. */
    public static function add(int $accountId, array $keys): void
    {
        self::set($accountId, array_merge(self::enabled($accountId), $keys));
    }

    /**
     * Fields a parsed dump (ShopImport::parse) uses for one source shop:
     * a field counts when its lookup table has rows or products point at it.
     */
    public static function detect(array $tables, int $srcTenant): array
    {
        $mine = function (string $t) use ($tables, $srcTenant): array {
            return array_filter($tables[$t]['rows'] ?? [],
                fn($r) => !isset($r['tenant_id']) || (int) $r['tenant_id'] === $srcTenant);
        };
        $found = [];
        foreach ($mine('book_attributes') as $r) {
            if (isset(self::FIELDS[$r['type'] ?? ''])) $found[$r['type']] = true;
        }
        $cats = array_flip(array_map(fn($c) => (int) $c['id'], $mine('categories')));
        foreach ($mine('subcategories') as $s) {
            if (!isset($s['category_id']) || isset($cats[(int) $s['category_id']])) $found['subcategory'] = true;
        }
        foreach ($mine('products') as $p) {
            foreach (array_keys(self::FIELDS) as $k) {
                if (!empty($p[$k . '_id'])) $found[$k] = true;
            }
        }
        return array_values(array_intersect(array_keys(self::FIELDS), array_keys($found)));
    }

    /**
     * Product columns from a form's typed names ($in = ['brand' => 'Samsung', …]).
     * Enabled fields are found or created by name; fields that are off keep
     * $existing's value so editing a product never wipes them.
     */
    public static function resolve(array $in, int $categoryId, array $existing = []): array
    {
        $pdo = Database::pdo();
        $attr = new Models\BookAttributeModel($pdo);
        $out = [];
        foreach (array_keys(self::FIELDS) as $k) {
            $col = $k . '_id';
            if (!self::on($k)) {
                $out[$col] = (int) ($existing[$col] ?? 0);
                continue;
            }
            $name = trim((string) ($in[$k] ?? ''));
            if ($k !== 'subcategory') {
                $out[$col] = (int) $attr->findOrCreate($k, $name);
                continue;
            }
            $out[$col] = 0;
            if ($name !== '' && $categoryId > 0) {
                $st = $pdo->prepare('SELECT id FROM subcategories WHERE category_id = ? AND name = ? LIMIT 1');
                $st->execute([$categoryId, $name]);
                $id = $st->fetchColumn();
                if ($id === false) {
                    $res = (new Models\SubcategoryModel($pdo))->create($categoryId, $name);
                    $id = $res['id'];
                }
                $out[$col] = (int) $id;
            }
        }
        return $out;
    }

    private static function ensureColumn(): void
    {
        static $done = false;
        if ($done) return;
        $chk = Database::pdo()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tenants' AND column_name = 'product_fields'");
        if (!(int) $chk->fetchColumn()) {
            Database::pdo()->exec('ALTER TABLE tenants ADD COLUMN product_fields TEXT NULL');
        }
        $done = true;
    }
}
