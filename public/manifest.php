<?php
// public/manifest.php — install manifest for one shop, so the installed app
// opens that shop's address (domain/<slug>) with its name.
require_once __DIR__ . '/../app/app.php';

$slug = (string) ($_GET['s'] ?? ($_SESSION['shop_slug'] ?? ''));
$acct = $slug !== '' ? Signup::findBySlug($slug) : null;
$start = $acct ? parse_url(Signup::shopUrl($acct['slug']), PHP_URL_PATH) : public_url('terminal/');
$name = $acct['name'] ?? 'Dazu Modern POS';
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name' => $name,
    'short_name' => mb_substr($name, 0, 12),
    'description' => $name . ' — point of sale',
    'id' => $start,
    'start_url' => $start,
    'scope' => '/',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#ffffff',
    'theme_color' => '#0f766e',
    'icons' => [
        ['src' => public_url('assets/icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => public_url('assets/icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => public_url('assets/icons/icon-512-maskable.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES);
