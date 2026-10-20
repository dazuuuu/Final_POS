<?php
// app/helpers/ShopApi.php
// Website API — a custom, premium add-on. Support switches it on for an
// account (after a quotation); the owner then creates API keys and connects
// their own website to their POS: live products (images, names, categories,
// stock, offers), comments, likes, views per product and — if they allow it —
// orders from the website. Every key belongs to ONE shop of ONE account and
// only ever sees that shop's inventory.
//
// Tables (each carries account_id / tenant_id, so deleting an account removes them):
//   shop_api_settings   per account: enabled (by support), comments / likes /
//                       orders switches, auto-approve comments
//   shop_api_keys       keys: shop, name, scopes, allowed websites, use counts.
//                       Looked up by SHA-256; also kept encrypted (AES-256-GCM,
//                       app/config/secret.php) so the owner can copy it again.
//   shop_api_rate       requests per key per minute (rate limit)
//   shop_api_views      product views per day
//   shop_api_likes      one like per visitor per product
//   shop_api_comments   comments, pending until approved (unless auto-approve)
//   shop_web_orders / shop_web_order_items   orders placed on the website

class ShopApi
{
    const SCOPES = [
        'products' => 'Read products, categories and shop details',
        'comments' => 'Read and post product comments',
        'likes'    => 'Like / unlike products',
        'orders'   => 'Place orders from the website',
        'insights' => 'Read views, likes and comment counts (keep this key on your server)',
    ];
    const RATE_PER_MINUTE = 300;
    const ORDER_STATUSES = ['new' => 'New', 'confirmed' => 'Confirmed', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];

    // ------------------------------------------------------------- schema

    public static function ensure(?PDO $pdo = null): void
    {
        static $done = false;
        if ($done) { return; }
        $pdo ??= Database::pdo();
        $flag = Database::marker('shop_api_v1');
        if (!is_file($flag)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS shop_api_settings (
                account_id INT NOT NULL PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                comments_on TINYINT(1) NOT NULL DEFAULT 1,
                comments_auto TINYINT(1) NOT NULL DEFAULT 0,
                likes_on TINYINT(1) NOT NULL DEFAULT 1,
                orders_on TINYINT(1) NOT NULL DEFAULT 0,
                order_note VARCHAR(255) NULL,
                enabled_at DATETIME NULL,
                requested_at DATETIME NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS shop_api_keys (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                tenant_id INT NOT NULL,
                name VARCHAR(80) NOT NULL,
                prefix VARCHAR(20) NOT NULL,
                key_hash CHAR(64) NOT NULL,
                scopes VARCHAR(120) NOT NULL,
                origins VARCHAR(500) NULL,
                requests INT NOT NULL DEFAULT 0,
                last_used_at DATETIME NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at DATETIME NULL,
                UNIQUE KEY uq_api_key_hash (key_hash), KEY idx_api_key_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS shop_api_rate (
                key_id INT NOT NULL, minute DATETIME NOT NULL, n INT NOT NULL DEFAULT 0, account_id INT NOT NULL DEFAULT 0,
                PRIMARY KEY (key_id, minute)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS shop_api_views (
                tenant_id INT NOT NULL, product_id INT NOT NULL, day DATE NOT NULL, views INT NOT NULL DEFAULT 0,
                PRIMARY KEY (tenant_id, product_id, day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS shop_api_likes (
                tenant_id INT NOT NULL, product_id INT NOT NULL, visitor CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (product_id, visitor), KEY idx_api_likes_tenant (tenant_id, product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS shop_api_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                product_id INT NOT NULL,
                name VARCHAR(80) NOT NULL,
                comment VARCHAR(1000) NOT NULL,
                rating TINYINT NULL,
                status ENUM('pending','approved','hidden') NOT NULL DEFAULT 'pending',
                ip VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_api_comments (tenant_id, product_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS shop_web_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                number VARCHAR(20) NOT NULL,
                customer_name VARCHAR(120) NOT NULL,
                phone VARCHAR(30) NOT NULL,
                address VARCHAR(255) NOT NULL,
                note VARCHAR(500) NULL,
                total DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'new',
                key_id INT NULL,
                ip VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_web_orders (tenant_id, status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS shop_web_order_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                order_id INT NOT NULL,
                product_id INT NULL,
                name VARCHAR(160) NOT NULL,
                unit_price DECIMAL(12,2) NOT NULL,
                quantity DECIMAL(12,2) NOT NULL,
                line_total DECIMAL(12,2) NOT NULL,
                KEY idx_web_order_items (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            @file_put_contents($flag, date('c'));
        }
        $flag2 = Database::marker('shop_api_v2');
        if (!is_file($flag2)) {
            $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shop_api_keys' AND column_name = 'key_enc'")->fetchColumn();
            if (!$has) $pdo->exec('ALTER TABLE shop_api_keys ADD COLUMN key_enc VARCHAR(255) NULL AFTER key_hash');
            @file_put_contents($flag2, date('c'));
        }
        $done = true;
    }

    // ------------------------------------------------- copyable keys

    /** This install's encryption secret (created once, kept in app/config/secret.php). */
    private static function secret(): string
    {
        static $k = null;
        if ($k !== null) return $k;
        $file = ROOT_PATH . '/app/config/secret.php';
        $cfg = is_file($file) ? (include $file) : null;
        if (!is_array($cfg) || empty($cfg['key'])) {
            $cfg = ['key' => base64_encode(random_bytes(32))];
            @file_put_contents($file, "<?php\n// app/config/secret.php — this install's encryption secret (API keys). Created automatically; never share or reuse it.\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX);
        }
        return $k = hash('sha256', 'dz-api|' . $cfg['key'], true);
    }

    private static function encrypt(string $plain): ?string
    {
        if (!function_exists('openssl_encrypt')) return null;
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::secret(), OPENSSL_RAW_DATA, $iv, $tag);
        return $ct === false ? null : base64_encode($iv . $tag . $ct);
    }

    /** The full key of a stored row, or null (created before keys were copyable, or the secret changed). */
    public static function reveal(array $row): ?string
    {
        if (empty($row['key_enc']) || !function_exists('openssl_decrypt')) return null;
        $raw = base64_decode((string) $row['key_enc'], true);
        if ($raw === false || strlen($raw) < 29) return null;
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::secret(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return is_string($plain) && hash_equals((string) $row['key_hash'], hash('sha256', $plain)) ? $plain : null;
    }

    // ------------------------------------------------------------ settings

    public static function settings(int $account): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM shop_api_settings WHERE account_id = ?');
        $st->execute([$account]);
        return ($st->fetch(PDO::FETCH_ASSOC) ?: []) + ['account_id' => $account, 'enabled' => 0, 'comments_on' => 1, 'comments_auto' => 0,
            'likes_on' => 1, 'orders_on' => 0, 'order_note' => null, 'enabled_at' => null, 'requested_at' => null];
    }

    public static function enabled(int $account): bool
    {
        return (int) self::settings($account)['enabled'] === 1;
    }

    public static function save(int $account, array $fields): void
    {
        self::ensure();
        $allowed = array_intersect_key($fields, array_flip(['enabled', 'comments_on', 'comments_auto', 'likes_on', 'orders_on', 'order_note', 'enabled_at', 'requested_at']));
        if (!$allowed) return;
        $cols = array_keys($allowed);
        Database::pdo()->prepare('INSERT INTO shop_api_settings (account_id, `' . implode('`,`', $cols) . '`) VALUES (?' . str_repeat(',?', count($cols)) . ')
                                  ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols)))
            ->execute(array_merge([$account], array_values($allowed)));
    }

    /** Support switches the add-on on / off for an account. Off revokes nothing: keys simply stop working. */
    public static function setEnabled(int $account, bool $on): void
    {
        self::save($account, ['enabled' => $on ? 1 : 0] + ($on ? ['enabled_at' => date('Y-m-d H:i:s')] : []));
    }

    // ---------------------------------------------------------------- keys

    /** Create a key. Returns [plain key (shown once), id]. */
    public static function createKey(int $account, int $tenant, string $name, array $scopes, string $origins, ?int $userId): array
    {
        self::ensure();
        $scopes = array_values(array_intersect(array_keys(self::SCOPES), $scopes)) ?: ['products'];
        $plain = 'dz_live_' . bin2hex(random_bytes(20));
        $clean = implode(',', array_filter(array_map(fn($o) => rtrim(strtolower(trim($o)), '/'), preg_split('/[\s,]+/', $origins))));
        Database::pdo()->prepare('INSERT INTO shop_api_keys (account_id, tenant_id, name, prefix, key_hash, key_enc, scopes, origins, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$account, $tenant, mb_substr(trim($name) ?: 'Website', 0, 80), substr($plain, 0, 14), hash('sha256', $plain), self::encrypt($plain),
                       implode(',', $scopes), $clean ?: null, $userId]);
        return [$plain, (int) Database::pdo()->lastInsertId()];
    }

    public static function keys(int $account): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT k.*, t.name AS shop_name FROM shop_api_keys k LEFT JOIN tenants t ON t.id = k.tenant_id
                                         WHERE k.account_id = ? ORDER BY k.revoked_at IS NOT NULL, k.id DESC');
        $st->execute([$account]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function revokeKey(int $account, int $id): void
    {
        self::ensure();
        Database::pdo()->prepare('UPDATE shop_api_keys SET revoked_at = NOW() WHERE id = ? AND account_id = ? AND revoked_at IS NULL')->execute([$id, $account]);
    }

    // ------------------------------------------------------- request side

    /** Find the key on this request (header X-Api-Key, "Authorization: Bearer", or ?key=). */
    public static function keyFromRequest(): string
    {
        $k = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if ($k === '' && preg_match('/^Bearer\s+(\S+)/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) $k = $m[1];
        if ($k === '') $k = (string) ($_GET['key'] ?? '');
        return trim($k);
    }

    /** Look a key up: the key row, or null when unknown / revoked / the add-on is off. */
    public static function findKey(string $plain): ?array
    {
        if (!preg_match('/^dz_live_[a-f0-9]{40}$/', $plain)) return null;
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM shop_api_keys WHERE key_hash = ? AND revoked_at IS NULL');
        $st->execute([hash('sha256', $plain)]);
        $k = $st->fetch(PDO::FETCH_ASSOC);
        if (!$k || !self::enabled((int) $k['account_id']) || AccountLock::isLocked((int) $k['account_id'])) return null;
        $k['scope_list'] = explode(',', $k['scopes']);
        return $k;
    }

    /** Count a request; false when the key is over its per-minute limit. */
    public static function hit(array $key): bool
    {
        $pdo = Database::pdo();
        $min = date('Y-m-d H:i:00');
        $pdo->prepare('INSERT INTO shop_api_rate (key_id, minute, n, account_id) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE n = n + 1')
            ->execute([(int) $key['id'], $min, (int) $key['account_id']]);
        $st = $pdo->prepare('SELECT n FROM shop_api_rate WHERE key_id = ? AND minute = ?');
        $st->execute([(int) $key['id'], $min]);
        $pdo->prepare('UPDATE shop_api_keys SET requests = requests + 1, last_used_at = NOW() WHERE id = ?')->execute([(int) $key['id']]);
        if (mt_rand(1, 200) === 1) $pdo->exec('DELETE FROM shop_api_rate WHERE minute < NOW() - INTERVAL 1 DAY');
        return (int) $st->fetchColumn() <= self::RATE_PER_MINUTE;
    }

    /** Is this browser origin allowed for the key? (No list = any website.) */
    public static function originAllowed(array $key, string $origin): bool
    {
        if (empty($key['origins']) || $origin === '') return true;
        return in_array(rtrim(strtolower($origin), '/'), explode(',', $key['origins']), true);
    }

    // ------------------------------------------------------------- output

    /** Full URL of a stored product image, or null. */
    public static function imageUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') return null;
        if (preg_match('#^https?://#i', $path)) return $path;
        $path = ltrim($path, '/');
        if (strpos($path, 'public/') === 0) $path = substr($path, 7);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
        $base = rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/');
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/' . $path;
    }

    /** A product as the website sees it — no cost prices or anything internal. */
    public static function product(array $p, array $stats = []): array
    {
        $eff = Models\ProductModel::effectivePrice($p);
        $qty = (float) $p['quantity'];
        return [
            'id'          => (int) $p['id'],
            'name'        => $p['name'],
            'description' => $p['description'] ?: null,
            'category'    => $p['category_id'] ? ['id' => (int) $p['category_id'], 'name' => $p['category_name'] ?? null] : null,
            'subcategory' => !empty($p['subcategory_id']) ? ['id' => (int) $p['subcategory_id'], 'name' => $p['subcategory_name'] ?? null] : null,
            'image'       => self::imageUrl($p['image_path'] ?? null),
            'barcode'     => $p['barcode'] ?? null,
            'unit'        => $p['unit'] ?? 'piece',
            'price'       => round($eff['price'], 2),
            'regular_price' => round($eff['regular_price'], 2),
            'on_offer'    => $eff['on_offer'],
            'offer_ends_at' => $eff['ends_at'],
            'in_stock'    => $qty > 0,
            'stock'       => $qty,
            'low_stock'   => $qty > 0 && $qty <= (int) ($p['low_stock_threshold'] ?? 0),
            'updated_at'  => $p['updated_at'] ?? null,
        ] + ($stats ? ['stats' => $stats] : []);
    }

    /** Views, likes and approved comments for a set of products of one shop. */
    public static function stats(int $tenant, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $out = array_fill_keys($ids, ['views' => 0, 'likes' => 0, 'comments' => 0]);
        if (!$ids) return $out;
        $pdo = Database::pdo();
        $in = implode(',', $ids);
        foreach ($pdo->query("SELECT product_id, SUM(views) FROM shop_api_views WHERE tenant_id = $tenant AND product_id IN ($in) GROUP BY product_id")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $n) $out[$id]['views'] = (int) $n;
        foreach ($pdo->query("SELECT product_id, COUNT(*) FROM shop_api_likes WHERE tenant_id = $tenant AND product_id IN ($in) GROUP BY product_id")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $n) $out[$id]['likes'] = (int) $n;
        foreach ($pdo->query("SELECT product_id, COUNT(*) FROM shop_api_comments WHERE tenant_id = $tenant AND status = 'approved' AND product_id IN ($in) GROUP BY product_id")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $n) $out[$id]['comments'] = (int) $n;
        return $out;
    }

    /** Hash of a website visitor id (the site sends any stable random string it keeps per visitor). */
    public static function visitor(string $id): string
    {
        $id = trim($id);
        return hash('sha256', ($id !== '' ? $id : (($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''))));
    }
}
