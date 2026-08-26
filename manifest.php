<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
headers(false);

$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base  = rtrim(BASE_PATH, '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base)) ?: '/';
}
$parts = array_values(array_filter(explode('/', trim($path, '/')), fn($x) => $x !== ''));

// ?category= (set by htaccess) wins; fall back to path segment; then first category
$slug = (string) ($_GET['category'] ?? ($parts[0] ?? ''));
$c    = ($slug ? cat_by_slug($slug) : null) ?? (data()['categories'][0] ?? null);

if (!$c) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Category not found']);
    exit;
}

$catUrl = base_url($c['slug'] . '/');

header('Content-Type: application/manifest+json');
// BUG FIX: 'id' should be a stable identifier; use abs_url so it includes origin
echo json_encode([
    'id'               => abs_url($catUrl),
    'name'             => APP_NAME . ' ' . $c['name'],
    'short_name'       => $c['name'],
    'start_url'        => $catUrl,
    'scope'            => $catUrl,
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'background_color' => '#0a0d14',
    'theme_color'      => '#08795f',
    'description'      => $c['description'],
    // BUG FIX: 'purpose' should be separate per icon; 'any maskable' (two values in one string) is invalid
    'icons' => [
        ['src' => abs_url(base_url('assets/icon-192.png')), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => abs_url(base_url('assets/icon-192.png')), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => abs_url(base_url('assets/icon-512.png')), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => abs_url(base_url('assets/icon-512.png')), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'categories'       => ['utilities'],
    'lang'             => 'en',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
