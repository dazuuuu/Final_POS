<?php
// app/helpers/StoreImport.php
// Brings products from an online-store database dump (the website's
// `products` + `categories` tables, e.g. u780468674_collections.sql) and its
// uploads.zip of photos into the signed-in shop.
//
//   category_keys[0] → category, category_keys[1] → subcategory
//   price × rate     → retail price (the store sells in USD, the POS in KES)
//   product_code     → barcode, so importing again updates instead of duplicating
//   images[0]        → product photo, copied out of uploads.zip (or added later
//                      with Import photos, which matches products.source_image)

class StoreImport
{
    /** Parse the dump: ['categories' => key => name, 'products' => [rows]]. */
    public static function parse(string $sql): array
    {
        $cats = [];
        foreach (self::rows($sql, 'categories') as $r) {
            if (!empty($r['category_key'])) $cats[$r['category_key']] = trim((string) ($r['name'] ?? $r['category_key']));
        }
        $products = [];
        foreach (self::rows($sql, 'products') as $r) {
            if (trim((string) ($r['name'] ?? '')) === '') continue;
            $keys = json_decode((string) ($r['category_keys'] ?? ''), true);
            $keys = is_array($keys) && $keys ? array_values(array_unique($keys)) : [(string) ($r['category_key'] ?? '')];
            $images = json_decode((string) ($r['images'] ?? ''), true) ?: [];
            $colors = array_map(fn($c) => is_array($c) ? ($c['name'] ?? '') : (string) $c, json_decode((string) ($r['colors'] ?? ''), true) ?: []);
            $products[] = [
                'code'        => trim((string) ($r['product_code'] ?? '')),
                'name'        => trim((string) $r['name']),
                'price'       => (float) ($r['price'] ?? 0),
                'category'    => $cats[$keys[0]] ?? self::humanize($keys[0]),
                'subcategory' => isset($keys[1]) ? ($cats[$keys[1]] ?? self::humanize($keys[1])) : '',
                'description' => trim((string) ($r['description'] ?? '') ?: (string) ($r['subtitle'] ?? '')),
                'quantity'    => (int) ($r['in_stock'] ?? 1) ? (float) ($r['quantity'] ?? 0) : 0.0,
                'sizes'       => array_values(array_filter(array_map('strval', json_decode((string) ($r['sizes'] ?? ''), true) ?: []))),
                'colors'      => array_values(array_filter($colors)),
                'image'       => $images ? basename((string) $images[0]) : '',
            ];
        }
        return ['categories' => $cats, 'products' => $products];
    }

    /**
     * Import into the signed-in shop. $zip: path to uploads.zip or null.
     * Returns ['created' => n, 'updated' => n, 'photos' => n, 'errors' => [...]].
     */
    public static function run(PDO $db, array $parsed, float $rate, ?string $zip): array
    {
        $tenantId = (int) TenantContext::tenantId();
        $P = new Models\ProductModel($db);
        $C = new Models\CategoryModel($db);
        $S = new Models\SubcategoryModel($db);
        $out = ['created' => 0, 'updated' => 0, 'photos' => 0, 'errors' => []];

        if (array_filter(array_column($parsed['products'], 'subcategory'))) {
            ProductFields::add(Features::accountId($tenantId), ['subcategory']);
        }

        $za = null;
        if ($zip && class_exists('ZipArchive')) {
            $za = new ZipArchive();
            if ($za->open($zip) !== true) { $za = null; $out['errors'][] = 'The photos zip could not be opened — products were imported without photos.'; }
        }
        $dir = ROOT_PATH . '/public/assets/uploads/products';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        PhotoImport::ensureColumn($db);
        $remember = $db->prepare('UPDATE products SET source_image = ? WHERE id = ? AND tenant_id = ?');
        $findByCode = $db->prepare('SELECT id, image_path FROM products WHERE tenant_id = ? AND barcode = ? LIMIT 1');
        $findSub = $db->prepare('SELECT id FROM subcategories WHERE tenant_id = ? AND category_id = ? AND name = ? LIMIT 1');

        foreach ($parsed['products'] as $p) {
            $catId = $p['category'] !== '' ? (int) $C->findOrCreate($p['category']) : 0;
            $subId = 0;
            if ($catId && $p['subcategory'] !== '' && $p['subcategory'] !== $p['category']) {
                $findSub->execute([$tenantId, $catId, $p['subcategory']]);
                $subId = (int) $findSub->fetchColumn() ?: (int) ($S->create($catId, $p['subcategory'])['id'] ?? 0);
            }

            $existing = null;
            if ($p['code'] !== '') { $findByCode->execute([$tenantId, $p['code']]); $existing = $findByCode->fetch() ?: null; }

            $image = $existing['image_path'] ?? '';
            if ($za && $p['image'] !== '' && $image === '') {
                $bytes = $za->getFromName('uploads/products/' . $p['image']);
                if ($bytes === false) $bytes = $za->getFromName('products/' . $p['image']);
                $ext = strtolower(pathinfo($p['image'], PATHINFO_EXTENSION));
                if ($bytes !== false && in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) && @getimagesizefromstring($bytes)) {
                    $name = 'store_' . $tenantId . '_' . preg_replace('/[^a-z0-9.]/i', '', $p['image']);
                    if (@file_put_contents($dir . '/' . $name, $bytes) !== false) {
                        $image = public_url('assets/uploads/products/' . $name);
                        $out['photos']++;
                    }
                }
            }

            $in = [
                'name'           => $p['name'],
                'category_id'    => $catId,
                'subcategory_id' => $subId,
                'barcode'        => $p['code'],
                'description'    => $p['description'],
                'quantity'       => $p['quantity'],
                'unit'           => 'piece',
                'buying_price'   => 0,
                'retail_price'   => round($p['price'] * $rate),
                'wholesale_price'=> 0,
                'sizes'          => $p['sizes'],
                'colors'         => $p['colors'],
                'image_path'     => $image,
                'status'         => 'active',
            ];
            try {
                if ($existing) {
                    $res = $P->edit((int) $existing['id'], $in);
                    $res['ok'] ? $out['updated']++ : $out['errors'][] = $p['name'] . ': ' . implode(' ', $res['errors']);
                    $pid = (int) $existing['id'];
                } else {
                    $res = $P->create($in);
                    $res['ok'] ? $out['created']++ : $out['errors'][] = $p['name'] . ': ' . implode(' ', $res['errors']);
                    $pid = (int) ($res['id'] ?? 0);
                }
                // Remember the store's photo file name: Import photos matches it later.
                if ($pid && $p['image'] !== '') { $remember->execute([$p['image'], $pid, $tenantId]); }
            } catch (\Throwable $e) {
                $out['errors'][] = $p['name'] . ': ' . $e->getMessage();
            }
        }
        if ($za) $za->close();
        return $out;
    }

    private static function humanize(string $key): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $key));
    }

    /** Rows of every `INSERT INTO `table` (cols) VALUES (...),(...);` statement, as column => value. */
    private static function rows(string $sql, string $table): array
    {
        $out = [];
        $re = '/INSERT INTO `' . preg_quote($table, '/') . '`\s*\(([^)]*)\)\s*VALUES\s*/i';
        if (!preg_match_all($re, $sql, $m, PREG_OFFSET_CAPTURE)) return [];
        foreach ($m[0] as $i => $hit) {
            $cols = array_map(fn($c) => trim($c, " `\n\r\t"), explode(',', $m[1][$i][0]));
            $pos = $hit[1] + strlen($hit[0]);
            foreach (self::tuples($sql, $pos) as $vals) {
                if (count($vals) === count($cols)) $out[] = array_combine($cols, $vals);
            }
        }
        return $out;
    }

    /** Read (v, v, …), (…) … ; starting at $pos — MySQL dump quoting and escapes. */
    private static function tuples(string $s, int $pos): array
    {
        $rows = []; $len = strlen($s); $row = null; $val = ''; $inStr = false; $quoted = false;
        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", 'Z' => "\x1a"];
        for ($i = $pos; $i < $len; $i++) {
            $c = $s[$i];
            if ($inStr) {
                if ($c === '\\' && $i + 1 < $len) { $n = $s[++$i]; $val .= $map[$n] ?? $n; continue; }
                if ($c === "'") {
                    if ($i + 1 < $len && $s[$i + 1] === "'") { $val .= "'"; $i++; continue; }
                    $inStr = false; continue;
                }
                $val .= $c; continue;
            }
            if ($row === null) {
                if ($c === '(') { $row = []; $val = ''; $quoted = false; continue; }
                if ($c === ';') break;
                continue;
            }
            if ($c === "'") { $inStr = true; $quoted = true; $val = ''; continue; }
            if ($c === ',' || $c === ')') {
                $v = $quoted ? $val : trim($val);
                $row[] = (!$quoted && strtoupper($v) === 'NULL') ? null : $v;
                $val = ''; $quoted = false;
                if ($c === ')') { $rows[] = $row; $row = null; }
                continue;
            }
            $val .= $c;
        }
        return $rows;
    }
}
