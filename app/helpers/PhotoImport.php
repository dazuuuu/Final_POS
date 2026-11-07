<?php
// app/helpers/PhotoImport.php
// Adds product photos in bulk: a zip (any folders inside) or many image
// files at once. Each photo finds its product by itself, in this order:
//   1. the photo file the product had in the online store (products.source_image,
//      remembered by StoreImport)       e.g. 6702746b6a436e2b93f1.jpg
//   2. the product's barcode / code      e.g. pentagon-001.jpg, 6161101234567.png
//   3. the product's name                e.g. "Beaded maasai Necklaces.jpg"
// Products that already have a photo keep it unless $replace is set.

class PhotoImport
{
    const EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    const MAX_BYTES = 8 * 1024 * 1024; // per photo

    public static function ensureColumn(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try { $db->query('SELECT source_image FROM products LIMIT 0'); return; } catch (\Throwable $e) { /* add it */ }
        try { $db->exec('ALTER TABLE products ADD COLUMN source_image VARCHAR(255) NULL'); } catch (\Throwable $e) { /* exists */ }
    }

    /**
     * $files: [[name => original file name, bytes => string], …] — read lazily
     * through $each(callable) so a big zip is never held in memory at once.
     */
    public static function run(PDO $db, callable $each, bool $replace = false): array
    {
        self::ensureColumn($db);
        $tid = (int) TenantContext::tenantId();
        $out = ['matched' => 0, 'skipped_has_photo' => 0, 'unmatched' => [], 'bad' => []];

        // Every product of this shop, indexed three ways.
        $st = $db->prepare('SELECT id, name, barcode, source_image, image_path FROM products WHERE tenant_id = ?');
        $st->execute([$tid]);
        $bySource = $byCode = $byName = [];
        foreach ($st->fetchAll() as $p) {
            if ($p['source_image']) $bySource[strtolower($p['source_image'])][] = $p;
            if ($p['barcode']) $byCode[self::key($p['barcode'])][] = $p;
            $byName[self::key($p['name'])][] = $p;
        }

        $dir = ROOT_PATH . '/public/assets/uploads/products';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $set = $db->prepare('UPDATE products SET image_path = ? WHERE id = ? AND tenant_id = ?');
        $done = [];

        $each(function (string $name, string $bytes) use (&$out, &$done, $bySource, $byCode, $byName, $replace, $dir, $set, $tid) {
            $base = basename(str_replace('\\', '/', $name));
            if ($base === '' || $base[0] === '.' || strpos($name, '__MACOSX') !== false) return;
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (!in_array($ext, self::EXT, true)) return; // not a photo: ignore quietly
            if (strlen($bytes) > self::MAX_BYTES || !@getimagesizefromstring($bytes)) { $out['bad'][] = $base; return; }

            $stem = self::key(pathinfo($base, PATHINFO_FILENAME));
            $products = $bySource[strtolower($base)] ?? $byCode[$stem] ?? $byName[$stem] ?? [];
            if (!$products) { $out['unmatched'][] = $base; return; }

            $file = 'p' . $tid . '_' . substr(sha1($bytes), 0, 16) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            $saved = is_file($dir . '/' . $file) || @file_put_contents($dir . '/' . $file, $bytes) !== false;
            if (!$saved) { $out['bad'][] = $base; return; }
            $url = public_url('assets/uploads/products/' . $file);
            foreach ($products as $p) {
                if (isset($done[$p['id']])) continue;
                if (!$replace && !empty($p['image_path'])) { $out['skipped_has_photo']++; $done[$p['id']] = true; continue; }
                $set->execute([$url, (int) $p['id'], $tid]);
                $done[$p['id']] = true;
                $out['matched']++;
            }
        });

        $st = $db->prepare("SELECT COUNT(*) FROM products WHERE tenant_id = ? AND (image_path IS NULL OR image_path = '') AND status <> 'archived'");
        $st->execute([$tid]);
        $out['still_missing'] = (int) $st->fetchColumn();
        return $out;
    }

    /** Feed run() from a zip file. */
    public static function fromZip(string $path): callable
    {
        return function (callable $take) use ($path) {
            $z = new ZipArchive();
            if ($z->open($path) !== true) throw new RuntimeException('That zip file could not be opened.');
            for ($i = 0; $i < $z->numFiles; $i++) {
                $st = $z->statIndex($i);
                if (!$st || substr($st['name'], -1) === '/' || $st['size'] > self::MAX_BYTES) continue;
                $take($st['name'], (string) $z->getFromIndex($i));
            }
            $z->close();
        };
    }

    /** Feed run() from a multi-file upload field ($_FILES['photos']). */
    public static function fromUploads(array $f): callable
    {
        return function (callable $take) use ($f) {
            foreach ((array) ($f['name'] ?? []) as $i => $name) {
                if (($f['error'][$i] ?? 1) === UPLOAD_ERR_OK) $take((string) $name, (string) file_get_contents($f['tmp_name'][$i]));
            }
        };
    }

    /** Comparable form of a code or name: lower case, letters and digits only. */
    private static function key(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($s));
    }
}
