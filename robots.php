<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
headers(false);

header('Content-Type: text/plain; charset=UTF-8');
echo "User-agent: *\n"
   . "Allow: /\n"
   . "Disallow: /admin.php\n"
   . "Disallow: /storage/\n"
   . "\n"
   . "Sitemap: " . abs_url('sitemap.xml') . "\n";
