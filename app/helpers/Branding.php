<?php
// app/helpers/Branding.php
// Logo display rules (single-shop deployment):
//  - Everywhere (login page, dashboards, receipts) -> the shop's uploaded
//    logo from Settings, falling back to the default Modern logo if none
//    has been uploaded yet.
// All paths are resolved through public_url() so they work regardless of
// whether the web server's document root points at the project root or
// straight at the public/ folder (see app/helpers/PathConfig.php).

class Branding
{
    // Default Modern logo, relative to the public web root.
    const DEFAULT_LOGO_REL = 'assets/images/logo/logo.png';

    /** The default Modern logo (used when no tenant logo has been uploaded). */
    public static function loginLogo(): string
    {
        return public_url(self::DEFAULT_LOGO_REL);
    }

    /** Tenant's own logo for internal pages, receipts, and the login screen; else the default. */
    public static function tenantLogo(?array $tenant): string
    {
        if ($tenant && !empty($tenant['logo_path'])) {
            return public_url(self::normalizeStoredPath($tenant['logo_path']));
        }
        return self::loginLogo();
    }

    /**
     * A shop's own logo for its public / per-shop pages (catalogue, its PIN
     * screen and login at domain/<slug>/). A branch without one uses its main
     * shop's. Never the system logo: '' when the shop has none, so the page
     * shows the shop's name with a store icon instead.
     */
    public static function shopLogo(?array $tenant): string
    {
        if (!$tenant) {
            return '';
        }
        if (empty($tenant['logo_path']) && !empty($tenant['parent_tenant_id'])) {
            try {
                $st = Database::pdo()->prepare('SELECT logo_path FROM tenants WHERE id = ?');
                $st->execute([(int) $tenant['parent_tenant_id']]);
                $tenant = ['logo_path' => $st->fetchColumn()];
            } catch (Throwable $e) { /* no logo */ }
        }
        return !empty($tenant['logo_path']) ? public_url(self::normalizeStoredPath($tenant['logo_path'])) : '';
    }

    /**
     * The platform's own brand (set in Support → Settings, saved to
     * app/config/platform.php). The login / signup screens are shared by every
     * shop, so they show this — never one client's logo.
     * Returns ['name' => string, 'logo' => url].
     */
    public static function platform(): array
    {
        $file = ROOT_PATH . '/app/config/platform.php';
        $cfg = is_file($file) ? (include $file) : [];
        $cfg = is_array($cfg) ? $cfg : [];
        return [
            'name' => ($cfg['name'] ?? '') !== '' ? $cfg['name'] : 'Dazu Modern POS',
            'logo' => !empty($cfg['logo']) ? public_url(self::normalizeStoredPath($cfg['logo'])) : self::loginLogo(),
            // Sales contacts for the landing page ("Talk to us", quote emails). Set in Support → Settings.
            'phone' => (string) ($cfg['phone'] ?? ''),
            'whatsapp' => (string) ($cfg['whatsapp'] ?? ''),
            'email' => (string) ($cfg['email'] ?? ''),
        ];
    }

    /**
     * Older rows (or a differently-configured server) may have a stored path
     * that's already absolute (leading '/', with or without a 'public/'
     * prefix). Strip that down to a path relative to the public root so it
     * can be safely passed through public_url().
     */
    private static function normalizeStoredPath(string $path): string
    {
        $path = ltrim($path, '/');
        if (strpos($path, 'public/') === 0) {
            $path = substr($path, strlen('public/'));
        }
        return $path;
    }
}
