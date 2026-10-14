<?php
// app/helpers/ShopUrl.php
// Per-shop addresses: domain/<slug>/super/dashboard/, domain/<slug>/staff/…
// Every request for an address that isn't a real file falls through to
// public/index.php (PHP's built-in server does this by itself; Apache via
// .htaccess). index.php calls dispatch() first, which maps /<slug>/<path>
// onto the real page and returns it for index.php to run. public_url() then
// keeps the shop in every super/ and staff/ link, and enforce() keeps a
// signed-in user inside their own shop's address.

require_once __DIR__ . '/PathConfig.php';

class ShopUrl
{
    /** Areas whose links carry the shop slug. */
    const AREAS = '#^(super|staff|profile)(/|$)#';

    private static ?string $slug = null;      // slug in this request's URL
    private static bool $dispatched = false;
    private static $own = false;              // signed-in user's shop slug (cached)

    /**
     * Map /<slug>/<path> to a page under public/. Returns the PHP file to run,
     * or null when the request isn't a shop address. Static files are served here.
     */
    public static function dispatch(): ?string
    {
        if (self::$dispatched) {
            return null; // index.php re-entered for /<slug>/
        }
        self::$dispatched = true;
        $base = rtrim((string) BASE_URL, '/');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($base !== '' && strpos($path, $base . '/') === 0) {
            $path = substr($path, strlen($base));
        }
        if (!preg_match('#^/([a-z0-9-]+)(/.*)?$#', $path, $m) || file_exists(PUBLIC_ROOT . '/' . $m[1])) {
            return null;
        }
        $slug = $m[1];
        $rest = $m[2] ?? '';
        $qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
        if ($rest === '') {
            header('Location: ' . $base . '/' . $slug . '/' . $qs); // trailing slash keeps relative links right
            exit;
        }
        $rel = ltrim(rawurldecode($rest), '/');
        if (strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
            self::notFound();
        }
        $file = PUBLIC_ROOT . '/' . $rel;
        if ($rel === '') {
            $file = PUBLIC_ROOT . '/index.php';
        } elseif (is_dir($file)) {
            if (substr($rel, -1) !== '/') {
                header('Location: ' . $base . '/' . $slug . '/' . $rel . '/' . $qs);
                exit;
            }
            $file = rtrim($file, '/') . '/index.php';
        }
        if (!is_file($file)) {
            self::notFound();
        }
        self::$slug = $slug;

        if (substr($file, -4) !== '.php') {
            self::serveStatic($file);
        }
        $script = $base . '/' . ltrim(substr($file, strlen(PUBLIC_ROOT)), '/');
        $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $script;
        $_SERVER['SCRIPT_FILENAME'] = $file;
        chdir(dirname($file));
        return $file;
    }

    /**
     * Called from app.php once the session is up. Checks the shop exists,
     * keeps signed-in users on their own shop's address, and remembers the
     * shop on this device for the staff PIN screen.
     */
    public static function enforce(): void
    {
        $own = self::ownSlug();
        $isGet = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
        $base = rtrim((string) BASE_URL, '/');

        if (self::$slug !== null) {
            $acct = Signup::findBySlug(self::$slug);
            if (!$acct) {
                self::notFound();
            }
            if (!$acct['owner_user_id']) {
                header('Location: ' . public_url('auth/signup.php?shop=' . urlencode(self::$slug)));
                exit;
            }
            if ($own !== null && $own !== self::$slug) {
                // Someone else's shop address: back to your own.
                $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                $tail = substr($path, strpos($path, '/' . self::$slug . '/') + strlen(self::$slug) + 1);
                header('Location: ' . $base . '/' . $own . $tail);
                exit;
            }
            if ($own === null) {
                $_SESSION['shop_slug'] = self::$slug;
                setcookie('shop_slug', self::$slug, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
            }
            return;
        }

        // Old shared address while signed in → same page at the shop's address.
        if ($own !== null && $isGet) {
            $script = substr((string) ($_SERVER['SCRIPT_NAME'] ?? ''), strlen($base));
            if (preg_match(self::AREAS, ltrim($script, '/'))) {
                $uri = (string) $_SERVER['REQUEST_URI'];
                if ($base !== '' && strpos($uri, $base . '/') === 0) {
                    $uri = substr($uri, strlen($base));
                }
                header('Location: ' . $base . '/' . $own . $uri);
                exit;
            }
        }
    }

    /** Slug to put in links: this request's, else the signed-in user's shop. */
    public static function current(): ?string
    {
        return self::$slug ?? self::ownSlug();
    }

    /** public_url() hook: prefix super/, staff/ and profile/ paths with the shop. */
    public static function prefix(string $path): string
    {
        if (!preg_match(self::AREAS, $path) && !($path === '' && self::$slug !== null)) {
            return $path;
        }
        $slug = self::current();
        return $slug === null ? $path : $slug . '/' . $path;
    }

    private static function ownSlug(): ?string
    {
        if (self::$own !== false) {
            return self::$own;
        }
        // Not signed in (yet — login may happen later this request): don't cache.
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['logged_in']) || !class_exists('TenantContext')) {
            return null;
        }
        self::$own = null;
        $tenantId = TenantContext::tenantId();
        if ($tenantId === null) {
            return null;
        }
        try {
            $stmt = Database::pdo()->prepare('SELECT slug FROM tenants WHERE id = ?');
            $stmt->execute([Features::accountId((int) $tenantId)]);
            $slug = (string) $stmt->fetchColumn();
            self::$own = $slug !== '' ? $slug : null;
        } catch (Throwable $e) {
            self::$own = null;
        }
        return self::$own;
    }

    private static function serveStatic(string $file): void
    {
        $types = ['css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json', 'png' => 'image/png',
                  'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
                  'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'webmanifest' => 'application/manifest+json'];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset($types[$ext])) {
            self::notFound();
        }
        header('Content-Type: ' . $types[$ext]);
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    private static function notFound(): void
    {
        http_response_code(404);
        echo 'Page not found.';
        exit;
    }
}
