<?php
// app/helpers/Seo.php
// Search-engine and link-preview tags for the public pages:
//   * <title>, meta description, canonical address, robots (index / noindex)
//   * Open Graph + Twitter card: what WhatsApp, Facebook, X, LinkedIn, Telegram
//     show when the link is shared — big preview image, name, description
//   * JSON-LD structured data Google reads: Organization (logo), WebSite,
//     SoftwareApplication (with the packages and their prices) and FAQPage
//
// Pages that are public and meant to be found: the landing page, the owner /
// admin login (/admin/), the staff terminal (/terminal/) and sign-up. Everything
// else (dashboards, shop addresses, password pages, support, Stock Control)
// is marked noindex. Wording and the share image can be changed in
// Support → Settings → Website sharing & SEO; without that, the defaults below apply.
//
// Nothing here is invented: no ratings or reviews are published, and the
// package prices come from the live package list (Features::publicCatalog()).

class Seo
{
    const IMAGE_REL = 'assets/seo/og-share.png'; // 1200 × 630, the built-in share picture
    const IMAGE_W = 1200;
    const IMAGE_H = 630;

    // ---------------------------------------------------------------- addresses

    /** https://your-domain — Support's "Website address" if set, else this request's own (checked) host. */
    public static function origin(): string
    {
        $cfg = Branding::platform()['seo_url'];
        if ($cfg !== '' && preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $cfg)) {
            return $cfg;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host)) {
            $host = 'localhost';
        }
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    /** Absolute address of a path on this site (no /public in it). */
    public static function abs(string $path = '/'): string
    {
        $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
        if (substr($base, -7) === '/public') {
            $base = substr($base, 0, -7);
        }
        return self::origin() . $base . '/' . ltrim($path, '/');
    }

    /** Make a site-relative URL (as public_url() gives) absolute. */
    public static function absUrl(string $url): string
    {
        return preg_match('#^https?://#i', $url) ? $url : self::origin() . '/' . ltrim($url, '/');
    }

    // ------------------------------------------------------------------ wording

    public static function title(): string
    {
        $b = Branding::platform();
        return $b['seo_title'] !== '' ? $b['seo_title'] : $b['name'] . ' — POS System for Shops, Salons & Restaurants in Kenya';
    }

    public static function description(): string
    {
        $b = Branding::platform();
        if ($b['seo_description'] !== '') {
            return $b['seo_description'];
        }
        return $b['name'] . ' is a point of sale for Kenyan shops, salons, restaurants and electronics stores: sales, M-Pesa, credit, stock, staff and receipts. Try a free live demo.';
    }

    /** [url, width, height] of the share picture: the one uploaded in Support, else the built-in one. */
    public static function image(): array
    {
        $b = Branding::platform();
        if ($b['seo_image'] !== '') {
            $file = PUBLIC_ROOT . '/' . ltrim(preg_replace('#^(https?://[^/]+)?/?(public/)?#', '', $b['seo_image']), '/');
            $size = is_file($file) ? @getimagesize($file) : false;
            return [self::absUrl($b['seo_image']), $size ? (int) $size[0] : 0, $size ? (int) $size[1] : 0];
        }
        return [self::abs(self::IMAGE_REL), self::IMAGE_W, self::IMAGE_H];
    }

    // --------------------------------------------------------------------- tags

    private static function e($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function ld(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return '<script type="application/ld+json">' . str_replace('</', '<\\/', (string) $json) . "</script>\n";
    }

    /**
     * The whole SEO block for a <head>.
     * $o: title, description, path (canonical), index (bool), type, image [url,w,h], imageAlt, jsonld [schema,…]
     */
    public static function tags(array $o): string
    {
        $b = Branding::platform();
        $title = (string) ($o['title'] ?? self::title());
        $out = '<title>' . self::e($title) . "</title>\n";
        $index = $o['index'] ?? true;
        if (!$index) {
            return $out . '<meta name="robots" content="noindex, nofollow">' . "\n";
        }
        $desc = (string) ($o['description'] ?? self::description());
        $url = self::abs((string) ($o['path'] ?? '/'));
        [$img, $iw, $ih] = $o['image'] ?? self::image();
        $alt = (string) ($o['imageAlt'] ?? $b['name'] . ' — point of sale for shops, salons, restaurants and electronics stores');
        $out .= '<meta name="description" content="' . self::e($desc) . '">' . "\n"
            . '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">' . "\n"
            . '<link rel="canonical" href="' . self::e($url) . '">' . "\n"
            . '<meta name="application-name" content="' . self::e($b['name']) . '">' . "\n"
            . '<meta property="og:type" content="' . self::e($o['type'] ?? 'website') . '">' . "\n"
            . '<meta property="og:site_name" content="' . self::e($b['name']) . '">' . "\n"
            . '<meta property="og:locale" content="en_KE">' . "\n"
            . '<meta property="og:title" content="' . self::e($title) . '">' . "\n"
            . '<meta property="og:description" content="' . self::e($desc) . '">' . "\n"
            . '<meta property="og:url" content="' . self::e($url) . '">' . "\n"
            . '<meta property="og:image" content="' . self::e($img) . '">' . "\n"
            . (strpos($img, 'https://') === 0 ? '<meta property="og:image:secure_url" content="' . self::e($img) . '">' . "\n" : '')
            . ($iw && $ih ? '<meta property="og:image:width" content="' . $iw . '"><meta property="og:image:height" content="' . $ih . '">' . "\n" : '')
            . '<meta property="og:image:alt" content="' . self::e($alt) . '">' . "\n"
            . '<meta name="twitter:card" content="summary_large_image">' . "\n"
            . '<meta name="twitter:title" content="' . self::e($title) . '">' . "\n"
            . '<meta name="twitter:description" content="' . self::e($desc) . '">' . "\n"
            . '<meta name="twitter:image" content="' . self::e($img) . '">' . "\n"
            . '<meta name="twitter:image:alt" content="' . self::e($alt) . '">' . "\n"
            . '<link rel="apple-touch-icon" href="' . self::e(self::abs('assets/icons/apple-touch-icon.png')) . '">' . "\n";
        foreach ($o['jsonld'] ?? [] as $schema) {
            $out .= self::ld($schema);
        }
        return $out;
    }

    // ------------------------------------------------------------ landing page

    /** Questions and answers shown on the landing page AND given to Google as FAQ data. Facts only. */
    public static function faq(array $brand, array $catalog): array
    {
        $n = $brand['name'];
        $prices = array_filter(array_map(fn($c) => (int) $c[1], $catalog), fn($p) => $p > 0);
        $from = $prices ? 'KES ' . number_format(min($prices)) : 'a one-time licence fee';
        return [
            ["What is $n?", "$n is a point of sale (POS) system for shops, salons, restaurants and electronics stores. It handles sales, stock, credit, staff and receipts in one place, on any phone or computer."],
            ['Can I take M-Pesa and cash payments?', 'Yes. Each sale can be paid in cash, M-Pesa or part of each, and credit sales go straight into the credit book so you always know who owes you.'],
            ['Which kinds of business is it for?', 'Retail shops and supermarkets, salons and barbershops (staff commissions are worked out for you), restaurants and bars (menu, tables, kitchen and store stock), hotels with rooms and table bookings, and phone and electronics shops (serial / IMEI numbers and warranties).'],
            ["How much does $n cost?", "Packages start from $from. You pay once for the licence — start on Basic and move up any time, and your data comes with you. The Packages section shows what each one includes."],
            ['Can I try it before I pay?', 'Yes. Enter your email to open a live demo shop — no card and no install. Five minutes is enough to make a sale, print a receipt and read the report.'],
            ['Does it work on a phone?', 'Yes. It runs in the browser on any phone, tablet or computer, and can be installed on the home screen like an app.'],
            ['Can I run more than one shop?', 'Yes. Every branch shows in one view so you can compare shops side by side, and stock can be moved from one shop to another.'],
            ['How do I and my staff sign in?', 'Owners and admins sign in with an email and password. Staff use a PIN at the staff terminal to clock in, sell and clock out, and only see what you allow them to.'],
            ['Can I print receipts?', 'Yes. Receipts print by themselves after a sale, and Bluetooth and USB printing are included from the Basic package.'],
            ['Can I get my reports in Excel or PDF?', 'Yes. Sales, stock and finance reports download as Excel or PDF.'],
        ];
    }

    /** Head block for the landing page. */
    public static function landing(array $brand, array $catalog, array $faq): string
    {
        $o = self::origin();
        $org = ['@type' => 'Organization', '@id' => $o . '/#organization', 'name' => $brand['name'], 'url' => $o . '/',
                'logo' => ['@type' => 'ImageObject', 'url' => self::absUrl($brand['logo'])], 'areaServed' => ['@type' => 'Country', 'name' => 'Kenya']];
        $contact = [];
        if ($brand['phone'] !== '') { $contact[] = ['@type' => 'ContactPoint', 'telephone' => $brand['phone'], 'contactType' => 'sales', 'areaServed' => 'KE', 'availableLanguage' => ['en', 'sw']]; }
        if ($brand['email'] !== '') { $contact[] = ['@type' => 'ContactPoint', 'email' => $brand['email'], 'contactType' => 'customer support', 'areaServed' => 'KE']; }
        if ($contact) { $org['contactPoint'] = $contact; }

        $offers = [];
        foreach ($catalog as $key => [$label, $price, $desc]) {
            if ((int) $price <= 0) { continue; }
            $offers[] = ['@type' => 'Offer', 'name' => $label, 'price' => (string) (int) $price, 'priceCurrency' => 'KES',
                         'description' => trim(preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $desc)), 'url' => $o . '/#packages', 'availability' => 'https://schema.org/InStock'];
        }
        $app = ['@type' => 'SoftwareApplication', '@id' => $o . '/#software', 'name' => $brand['name'], 'url' => $o . '/',
                'description' => self::description(), 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web browser, Android, iOS',
                'image' => self::image()[0], 'publisher' => ['@id' => $o . '/#organization'], 'inLanguage' => 'en-KE'];
        if ($offers) { $app['offers'] = $offers; }

        $faqLd = ['@type' => 'FAQPage', '@id' => $o . '/#faq', 'mainEntity' => array_map(fn($qa) => [
            '@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faq)];
        $site = ['@type' => 'WebSite', '@id' => $o . '/#website', 'url' => $o . '/', 'name' => $brand['name'], 'inLanguage' => 'en-KE', 'publisher' => ['@id' => $o . '/#organization']];

        return self::tags([
            'title' => self::title(), 'description' => self::description(), 'path' => '/',
            'jsonld' => [['@context' => 'https://schema.org'] + $org, ['@context' => 'https://schema.org'] + $site, ['@context' => 'https://schema.org'] + $app, ['@context' => 'https://schema.org'] + $faqLd],
        ]);
    }

    // ------------------------------------------------- owner login, terminal, …

    /**
     * Head block for the shared sign-in layout. The owner / admin login, the staff
     * terminal and sign-up are indexable; shop addresses (domain/<shop>/) and
     * every other sign-in page (password reset, activation…) are noindex.
     */
    public static function auth(string $pageTitle, string $shopName): string
    {
        $b = Branding::platform();
        $name = $b['name'];
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $own = $pageTitle . ' — ' . $shopName;
        if (class_exists('ShopUrl', false) && ShopUrl::requestSlug() !== null) {
            return self::tags(['title' => $own, 'index' => false]); // a shop's own address: private to that shop
        }
        if (preg_match('#/(admin/index|auth/login)\.php$#', $script)) {
            return self::tags([
                'title' => "Owner & Admin Login — $name", 'path' => '/admin/',
                'description' => "Sign in to $name to manage sales, stock, staff, credit and reports for your shop, salon, restaurant or electronics store. Owners and admins sign in with email and password.",
                'jsonld' => [self::pageLd('Owner & Admin Login', '/admin/', "Sign in to $name")],
            ]);
        }
        if (strpos($script, '/terminal/') !== false) {
            return self::tags([
                'title' => "Staff Terminal — Clock in, sell & print receipts — $name", 'path' => '/terminal/',
                'description' => "Staff sign in to $name with a PIN: clock in or out, ring up sales, take cash and M-Pesa payments and print receipts at the counter.",
                'jsonld' => [self::pageLd('Staff Terminal', '/terminal/', "Staff sign in to $name with a PIN")],
            ]);
        }
        if (preg_match('#/auth/signup\.php$#', $script) && class_exists('Signup') && Signup::enabled()) {
            return self::tags([
                'title' => "Create your account — $name POS", 'path' => '/auth/signup.php',
                'description' => "Create your $name account and start running your shop, salon, restaurant or electronics store: sales, stock, credit, staff and receipts in one place.",
            ]);
        }
        return self::tags(['title' => $own, 'index' => false]);
    }

    private static function pageLd(string $name, string $path, string $description): array
    {
        $o = self::origin();
        return ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $name, 'url' => self::abs($path), 'description' => $description,
                'isPartOf' => ['@type' => 'WebSite', 'name' => Branding::platform()['name'], 'url' => $o . '/'], 'inLanguage' => 'en-KE'];
    }
}
