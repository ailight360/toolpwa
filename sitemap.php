<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
headers(false);

$d = data();

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

function url_entry(string $loc, string $changefreq, string $priority, string $lastmod = ''): string {
    $xml = '  <url>' . "\n"
         . '    <loc>' . h($loc) . '</loc>' . "\n";
    if ($lastmod !== '') $xml .= '    <lastmod>' . h($lastmod) . '</lastmod>' . "\n";
    $xml .= '    <changefreq>' . $changefreq . '</changefreq>' . "\n"
          . '    <priority>' . $priority . '</priority>' . "\n"
          . '  </url>' . "\n";
    return $xml;
}

// Home page
echo url_entry(abs_url('/'), 'daily', '1.0');

// Core information pages
echo url_entry(abs_url(base_url('privacy/')), 'yearly', '0.3');
echo url_entry(abs_url(base_url('terms/')), 'yearly', '0.2');
echo url_entry(abs_url(base_url('contact/')), 'yearly', '0.2');

// Category pages
foreach ($d['categories'] as $c) {
    echo url_entry(abs_url(base_url($c['slug'] . '/')), 'weekly', '0.8');
}

// Tool pages
foreach ($d['tools'] as $t) {
    if (empty($t['active'])) continue;
    $lastmod = '';
    if (!empty($t['updated_at'])) {
        $ts = strtotime((string) $t['updated_at']);
        if ($ts) $lastmod = date('Y-m-d', $ts);
    }
    echo url_entry(abs_url(base_url($t['cat'] . '/' . $t['slug'] . '/')), 'monthly', '0.6', $lastmod);
}

echo '</urlset>';
