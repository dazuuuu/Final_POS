<?php
// public/seo.php — serves /robots.txt and /sitemap.xml (routed from the root index.php).
// robots.txt tells search engines what to index and where the sitemap is; the sitemap lists
// the pages worth finding: the landing page, the owner / admin login, the staff terminal
// and (when open) sign-up. Addresses follow the site's own domain — nothing to edit.
require_once __DIR__ . '/../app/app.php';

$file = $__seoFile ?? 'robots.txt';
header('Cache-Control: public, max-age=3600');

if ($file === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    $urls = [['/', '1.0', 'weekly'], ['/admin/', '0.8', 'monthly'], ['/terminal/', '0.8', 'monthly']];
    if (Signup::enabled()) { $urls[] = ['/auth/signup.php', '0.7', 'monthly']; }
    $day = date('Y-m-d', (int) (@filemtime(__DIR__ . '/components/landing/page.php') ?: time()));
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    foreach ($urls as [$p, $prio, $freq]) {
        echo '  <url><loc>' . htmlspecialchars(Seo::abs($p), ENT_XML1) . '</loc><lastmod>' . $day . '</lastmod><changefreq>' . $freq . '</changefreq><priority>' . $prio . '</priority>';
        if ($p === '/') { echo '<image:image><image:loc>' . htmlspecialchars(Seo::image()[0], ENT_XML1) . '</image:loc></image:image>'; }
        echo "</url>\n";
    }
    echo "</urlset>\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n"
   . "Allow: /\$\nAllow: /admin/\$\nAllow: /terminal/\$\nAllow: /auth/signup.php\nAllow: /assets/\n"
   . "Disallow: /super/\nDisallow: /staff/\nDisallow: /support/\nDisallow: /api/\nDisallow: /stock/\nDisallow: /auth/\nDisallow: /import/\n\n"
   . 'Sitemap: ' . Seo::abs('sitemap.xml') . "\n";
