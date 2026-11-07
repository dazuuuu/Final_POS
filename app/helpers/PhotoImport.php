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
        $out = self::emptyResult();
        $done = [];
        $each(self::matcher($db, $replace, $out, $done));
        $out['still_missing'] = self::stillMissing($db);
        return $out;
    }

    /**
     * Big imports, a little at a time: match photos $offset … $offset+$limit-1
     * of the zip (or a single image file), carrying the running result and the
     * products already done in $state between requests. Returns
     * ['next' => offset or null when finished, 'total' => entries].
     */
    public static function runBatch(PDO $db, string $path, bool $isZip, int $offset, int $limit, bool $replace, array &$state): array
    {
        $state['out'] ??= self::emptyResult();
        $done = array_fill_keys($state['done'] ?? [], true);
        $take = self::matcher($db, $replace, $state['out'], $done);
        if (!$isZip) {
            $take((string) ($state['name'] ?? basename($path)), (string) file_get_contents($path));
            $state['done'] = array_keys($done);
            return ['next' => null, 'total' => 1];
        }
        $z = new ZipArchive();
        if ($z->open($path) !== true) throw new RuntimeException('That zip file could not be opened — it may not have finished uploading.');
        $total = $z->numFiles;
        $started = microtime(true);
        $i = $offset;
        for (; $i < $total && $i < $offset + $limit; $i++) {
            $st = $z->statIndex($i);
            if (!$st || substr($st['name'], -1) === '/' || $st['size'] > self::MAX_BYTES) continue;
            $take($st['name'], (string) $z->getFromIndex($i));
            if (microtime(true) - $started > 8) { $i++; break; } // stay well inside the server's time limit
        }
        $z->close();
        $state['done'] = array_keys($done);
        return ['next' => $i < $total ? $i : null, 'total' => $total];
    }

    /**
     * Rebuild the photo ↔ product links from the online store's database
     * (StoreImport::parse of its .sql): each store product's photo file is
     * remembered on the matching POS product — found by its store code
     * (saved as the barcode on import), else by its name when only one
     * product has that name. Returns ['linked' => n, 'not_found' => [names]].
     */
    public static function linkFromStore(PDO $db, array $parsed): array
    {
        self::ensureColumn($db);
        $tid = (int) TenantContext::tenantId();
        $match = StoreImport::matchExisting($db, $tid, $parsed['products']);
        $set = $db->prepare("UPDATE products SET source_image = ?, barcode = IF(barcode IS NULL OR barcode = '', ?, barcode) WHERE id = ? AND tenant_id = ?");
        $out = ['linked' => 0, 'not_found' => []];
        foreach ($parsed['products'] as $i => $p) {
            if ($p['image'] === '') continue;
            if (!isset($match[$i])) { $out['not_found'][] = $p['name'] . ($p['code'] !== '' ? ' (' . $p['code'] . ')' : ''); continue; }
            $set->execute([$p['image'], $p['code'] !== '' ? $p['code'] : null, (int) $match[$i]['id'], $tid]);
            $out['linked']++;
        }
        return $out;
    }

    public static function emptyResult(): array
    {
        return ['matched' => 0, 'skipped_has_photo' => 0, 'unmatched' => [], 'bad' => []];
    }

    public static function stillMissing(PDO $db): int
    {
        $st = $db->prepare("SELECT COUNT(*) FROM products WHERE tenant_id = ? AND (image_path IS NULL OR image_path = '') AND status <> 'archived'");
        $st->execute([(int) TenantContext::tenantId()]);
        return (int) $st->fetchColumn();
    }

    /** The function that takes one photo (name, bytes) and puts it on its product(s). */
    private static function matcher(PDO $db, bool $replace, array &$out, array &$done): callable
    {
        self::ensureColumn($db);
        $tid = (int) TenantContext::tenantId();

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

        return function (string $name, string $bytes) use (&$out, &$done, $bySource, $byCode, $byName, $replace, $dir, $set, $tid) {
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
        };
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
