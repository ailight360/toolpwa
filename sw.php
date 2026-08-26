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

$slug = (string) ($_GET['category'] ?? ($parts[0] ?? ''));
$c    = ($slug ? cat_by_slug($slug) : null) ?? (data()['categories'][0] ?? null);

if (!$c) {
    header('Content-Type: application/javascript');
    echo "// No categories configured\n";
    exit;
}

$files = [
    base_url($c['slug'] . '/'),
    base_url('assets/app.css'),
    base_url('assets/app.js'),
    base_url('assets/icon-192.png'),
    base_url('assets/icon-512.png'),
    base_url($c['slug'] . '/manifest.php'),
    base_url($c['slug'] . '/sw.php'),
];
foreach (tools_for($c['slug']) as $t) {
    $files[] = base_url($c['slug'] . '/' . $t['slug'] . '/');
}
$files     = array_values(array_unique($files));
$filesJson = json_encode($files, JSON_UNESCAPED_SLASHES);
$cacheKey  = json_encode('toolpwa-' . $c['slug'] . '-v' . APP_VERSION);
$catHome   = json_encode(base_url($c['slug'] . '/'), JSON_UNESCAPED_SLASHES);

header('Content-Type: application/javascript');
echo "'use strict';\n";
echo "// Category-scoped offline cache. User files are never added to this cache by the worker.\n";
echo "const CACHE    = $cacheKey;\n";
echo "const CORE     = $filesJson;\n";
echo "const CAT_HOME = $catHome;\n";
echo <<<'JS'

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE)
      .then(c => c.addAll(CORE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(k => k !== CACHE && k.startsWith('toolpwa-')).map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  const u = new URL(e.request.url);
  if (u.origin !== location.origin) return;

  if (e.request.mode === 'navigate') {
    e.respondWith(
      fetch(e.request)
        .then(r => {
          const copy = r.clone();
          caches.open(CACHE).then(c => c.put(e.request, copy));
          return r;
        })
        .catch(() => caches.match(e.request).then(r => r || caches.match(CAT_HOME)))
    );
  } else {
    e.respondWith(
      caches.match(e.request).then(cached => {
        if (cached) return cached;
        return fetch(e.request).then(r => {
          if (r.ok) {
            const copy = r.clone();
            caches.open(CACHE).then(c => c.put(e.request, copy));
          }
          return r;
        });
      })
    );
  }
});
JS;
