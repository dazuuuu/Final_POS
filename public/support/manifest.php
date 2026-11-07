<?php
// public/support/manifest.php — install manifest for the support console, so
// it installs as its own app ("<brand> Support") that opens the support dashboard.
require_once __DIR__ . '/../../app/app.php';

$brand = Branding::platform();
$start = public_url('support/');
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name' => $brand['name'] . ' Support',
    'short_name' => 'Support',
    'description' => $brand['name'] . ' — support console: accounts, packages and tickets.',
    'id' => $start,
    'start_url' => $start,
    'scope' => $start,
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#0c1711',
    'theme_color' => '#0c1711',
    'icons' => [
        ['src' => public_url('assets/icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => public_url('assets/icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => public_url('assets/icons/icon-512-maskable.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES);
