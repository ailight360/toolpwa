<?php
declare(strict_types=1);

// ─── Auto-detect BASE_PATH for cPanel shared hosting ───────────────────────
// Resolves the subfolder this script lives in relative to the docroot,
// so you never have to hard-code BASE_PATH when deploying to /public_html/toolpwa/
// or any other subdirectory.  Works on cPanel, Plesk, DirectAdmin, etc.
// When placed at the document root (/public_html/index.php) it resolves to ''.
function _detect_base_path(): string {
    // DOCUMENT_ROOT is set by Apache/LiteSpeed/Nginx on cPanel
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
    $script   = rtrim(dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__), '/\\');

    if ($doc_root !== '' && str_starts_with($script, $doc_root)) {
        $rel = substr($script, strlen($doc_root));
        return $rel === '' ? '' : '/' . trim($rel, '/');
    }
    return '';  // fall back to root
}

const APP_NAME    = 'ToolPWA';
const APP_VERSION = "4.8.0";
// Resolved once at boot; no hard-coded path needed.
define('BASE_PATH', _detect_base_path());

// Storage files — all relative to THIS file, safe regardless of BASE_PATH
const DATA_FILE = __DIR__ . '/storage/data.json';
const AUTH_FILE = __DIR__ . '/storage/auth.json';
const RATE_FILE = __DIR__ . '/storage/login-attempts.json';
const BDIX_FILE = __DIR__ . '/storage/bdix_servers.json';
const ANALYTICS_FILE = __DIR__ . '/storage/analytics.json';

// ─── Output helpers ──────────────────────────────────────────────────────────
function h(mixed $v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─── URL helpers ─────────────────────────────────────────────────────────────
function base_url(string $path = ''): string {
    $b = rtrim(BASE_PATH, '/');
    if ($path === '') return $b === '' ? '/' : $b . '/';
    return ($b === '' ? '' : $b) . '/' . ltrim($path, '/');
}

function origin(): string {
    // BUG FIX: trust X-Forwarded-Proto from cPanel reverse proxies
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ($_SERVER['SERVER_PORT'] ?? '') === '443'
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Sanitise host: allow IPv6 brackets, colons (port), dots, alphanum, hyphen
    $host  = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?: 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function abs_url(string $p = ''): string {
    $p = (string) $p;
    // BUG FIX: original double-prefixed BASE_PATH when $p already began with it
    $base = rtrim(BASE_PATH, '/');
    if ($base !== '' && str_starts_with($p, $base)) {
        return rtrim(origin(), '/') . $p;
    }
    return rtrim(origin(), '/') . base_url($p);
}

// ─── JSON storage ────────────────────────────────────────────────────────────
function read_json(string $file, array $fallback): array {
    if (!is_file($file)) return $fallback;
    $f = @fopen($file, 'rb');
    if (!$f) return $fallback;
    flock($f, LOCK_SH);
    $raw = stream_get_contents($f) ?: '';
    flock($f, LOCK_UN);
    fclose($f);
    $x = json_decode($raw, true);
    return is_array($x) ? $x : $fallback;
}

function record_tool_usage(string $slug): bool {
    if ($slug === '' || !preg_match('/^[a-z0-9-]{2,100}$/', $slug)) return false;
    $d = data();
    $exists = false; foreach (($d['tools'] ?? []) as $t) if (($t['slug'] ?? '') === $slug && !empty($t['active'])) { $exists = true; break; }
    if (!$exists) return false;
    $a = read_json(ANALYTICS_FILE, ['days'=>[]]);
    $day = gmdate('Y-m-d');
    if (!isset($a['days'][$day]) || !is_array($a['days'][$day])) $a['days'][$day] = [];
    $a['days'][$day][$slug] = (int)($a['days'][$day][$slug] ?? 0) + 1;
    // Retain only the last 90 days to keep storage small.
    foreach (array_keys($a['days']) as $k) if ($k < gmdate('Y-m-d', strtotime('-90 days'))) unset($a['days'][$k]);
    try { write_json(ANALYTICS_FILE, $a); return true; } catch (Throwable $e) { return false; }
}
function tool_usage_scores(): array {
    $a = read_json(ANALYTICS_FILE, ['days'=>[]]); $scores=[];
    foreach (($a['days'] ?? []) as $day=>$rows) foreach (($rows ?? []) as $slug=>$n) $scores[$slug]=(int)($scores[$slug]??0)+(int)$n;
    arsort($scores); return $scores;
}

function write_json(string $file, array $value): void {
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    // Atomic write via temp-file + rename
    $tmp  = $file . '.' . bin2hex(random_bytes(5)) . '.tmp';
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $f = fopen($tmp, 'x');
    if (!$f) throw new RuntimeException('Storage unavailable (cannot create temp file)');
    fwrite($f, $json);
    fflush($f);
    if (function_exists('fsync')) @fsync($f);
    fclose($f);
    chmod($tmp, 0640);
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Storage write failed (rename)');
    }
}

// ─── Default data ────────────────────────────────────────────────────────────
function default_data(): array {
    $cats = [
        ['slug' => 'calculators',    'name' => 'Calculators',    'icon' => '🧮', 'description' => 'Fast everyday calculators that run directly in your browser.'],
        ['slug' => 'text-tools',     'name' => 'Text Tools',     'icon' => '✍️',  'description' => 'Write, count, clean and transform text instantly.'],
        ['slug' => 'image-tools',    'name' => 'Image Tools',    'icon' => '🖼️',  'description' => 'Compress, resize and work with images without unnecessary uploads.'],
        ['slug' => 'developer-tools','name' => 'Developer Tools','icon' => '⚙️',  'description' => 'Useful browser-based tools for developers and technical workflows.'],
        ['slug' => 'converters',     'name' => 'Converters',     'icon' => '↔️',  'description' => 'Convert common units and values quickly and accurately.'],
        ['slug' => 'security-tools', 'name' => 'Security Tools', 'icon' => '🔐', 'description' => 'Generate secure random values and inspect security-related data locally.'],
        ['slug' => 'browser-utilities','name' => 'Browser Utilities','icon' => '🧰', 'description' => 'Useful everyday utilities that run entirely in your browser.'],
        ['slug' => 'bd-tools',       'name' => 'Bangladesh Tools','icon' => '🇧🇩', 'description' => 'Local utilities for Bangladesh — NID/TIN/BIN format checks, mobile operator lookup, MFS cash out fees, Bangla numerals and VAT.'],
    ];

    $base = [
        ['slug' => 'percentage-calculator',  'name' => 'Percentage Calculator',   'icon' => '%',  'type' => 'percentage', 'cat' => 'calculators',    'desc' => 'Calculate percentages, percentage changes and values instantly.'],
        ['slug' => 'bmi-calculator',         'name' => 'BMI Calculator',          'icon' => '⚖️',  'type' => 'bmi',        'cat' => 'calculators',    'desc' => 'Calculate Body Mass Index using height and weight.'],
        ['slug' => 'age-calculator',         'name' => 'Age Calculator',          'icon' => '🎂', 'type' => 'age',        'cat' => 'calculators',    'desc' => 'Calculate your exact age from date of birth.'],
        ['slug' => 'discount-calculator',    'name' => 'Discount Calculator',     'icon' => '🏷️',  'type' => 'discount',   'cat' => 'calculators',    'desc' => 'Calculate sale price and savings from a discount percentage.'],
        ['slug' => 'tip-calculator',         'name' => 'Tip Calculator',          'icon' => '💰', 'type' => 'tip',        'cat' => 'calculators',    'desc' => 'Calculate tip amount and split the bill between people.'],
        ['slug' => 'loan-payment-calculator','name' => 'Loan Payment Calculator', 'icon' => '🏦', 'type' => 'loan',       'cat' => 'calculators',    'desc' => 'Calculate monthly loan payments, total repayment and interest.'],
        ['slug' => 'word-counter',           'name' => 'Word Counter',            'icon' => '✍️',  'type' => 'word',       'cat' => 'text-tools',     'desc' => 'Count words, characters, sentences and paragraphs instantly.'],
        ['slug' => 'character-counter',      'name' => 'Character Counter',       'icon' => '🔢', 'type' => 'character',  'cat' => 'text-tools',     'desc' => 'Count characters with and without spaces in real time.'],
        ['slug' => 'case-converter',         'name' => 'Case Converter',          'icon' => 'Aa', 'type' => 'case',       'cat' => 'text-tools',     'desc' => 'Convert text to upper, lower, title and sentence case.'],
        ['slug' => 'text-cleaner',           'name' => 'Text Cleaner',            'icon' => '🧹', 'type' => 'cleaner',    'cat' => 'text-tools',     'desc' => 'Remove extra spaces, blank lines and unwanted formatting.'],
        ['slug' => 'line-counter',           'name' => 'Line Counter',            'icon' => '📄', 'type' => 'line',       'cat' => 'text-tools',     'desc' => 'Count lines, words and characters in any block of text.'],
        ['slug' => 'text-reverser',          'name' => 'Text Reverser',           'icon' => '🔁', 'type' => 'reverse',    'cat' => 'text-tools',     'desc' => 'Reverse any string of text instantly.'],
        ['slug' => 'image-compressor',       'name' => 'Image Compressor',        'icon' => '🖼️',  'type' => 'compressor', 'cat' => 'image-tools',    'desc' => 'Compress JPG, PNG and WebP images in your browser.'],
        ['slug' => 'image-resizer',          'name' => 'Image Resizer',           'icon' => '↔️',  'type' => 'resizer',    'cat' => 'image-tools',    'desc' => 'Resize images to exact dimensions directly in your browser.'],
        ['slug' => 'image-to-base64',        'name' => 'Image to Base64',         'icon' => '64', 'type' => 'image64',    'cat' => 'image-tools',    'desc' => 'Convert an image to a Base64 data URL locally.'],
        ['slug' => 'image-color-picker',     'name' => 'Image Color Picker',      'icon' => '🎨', 'type' => 'colorpicker','cat' => 'image-tools',    'desc' => 'Pick pixel colors from an image and copy the color value.'],
        ['slug' => 'image-grayscale',        'name' => 'Image Grayscale',         'icon' => '⬛', 'type' => 'grayscale',  'cat' => 'image-tools',    'desc' => 'Convert any image to grayscale entirely in your browser.'],
        ['slug' => 'image-cropper',          'name' => 'Image Cropper',           'icon' => '✂️',  'type' => 'crop',       'cat' => 'image-tools',    'desc' => 'Crop images to a specific width and height in your browser.'],
        ['slug' => 'json-formatter',         'name' => 'JSON Formatter',          'icon' => '{}', 'type' => 'json',       'cat' => 'developer-tools','desc' => 'Format, validate and minify JSON in your browser.'],
        ['slug' => 'url-encoder',            'name' => 'URL Encoder / Decoder',   'icon' => '🔗', 'type' => 'url',        'cat' => 'developer-tools','desc' => 'Encode and decode URL components instantly.'],
        ['slug' => 'base64',                 'name' => 'Base64 Encoder / Decoder','icon' => '64', 'type' => 'base64',     'cat' => 'developer-tools','desc' => 'Encode and decode Base64 text locally.'],
        ['slug' => 'timestamp-converter',    'name' => 'Timestamp Converter',     'icon' => '🕐', 'type' => 'timestamp',  'cat' => 'developer-tools','desc' => 'Convert Unix timestamps to human-readable dates and back.'],
        ['slug' => 'html-encoder',           'name' => 'HTML Encoder / Decoder',  'icon' => '🏷️',  'type' => 'html',       'cat' => 'developer-tools','desc' => 'Encode and decode HTML entities instantly.'],
        ['slug' => 'length-converter',       'name' => 'Length Converter',        'icon' => '📏', 'type' => 'length',     'cat' => 'converters',     'desc' => 'Convert between millimeters, centimeters, meters, kilometers, inches and feet.'],
        ['slug' => 'temperature-converter',  'name' => 'Temperature Converter',   'icon' => '🌡️',  'type' => 'temperature','cat' => 'converters',     'desc' => 'Convert Celsius, Fahrenheit and Kelvin instantly.'],
        ['slug' => 'data-size-converter',    'name' => 'Data Size Converter',     'icon' => '💾', 'type' => 'data',       'cat' => 'converters',     'desc' => 'Convert bytes, KB, MB, GB and TB using common standards.'],
        ['slug' => 'time-converter',         'name' => 'Time Converter',          'icon' => '⏱️',  'type' => 'time',       'cat' => 'converters',     'desc' => 'Convert seconds, minutes, hours and days.'],
        ['slug' => 'weight-converter',       'name' => 'Weight Converter',        'icon' => '⚖️',  'type' => 'weight',     'cat' => 'converters',     'desc' => 'Convert grams, kilograms, pounds and ounces.'],
        ['slug' => 'area-converter',         'name' => 'Area Converter',          'icon' => '📐', 'type' => 'area',       'cat' => 'converters',     'desc' => 'Convert square meters, feet, yards, acres and km².'],
        ['slug' => 'password-generator',     'name' => 'Password Generator',      'icon' => '🔐', 'type' => 'password',   'cat' => 'security-tools', 'desc' => 'Generate strong random passwords entirely in your browser.'],
        ['slug' => 'random-string-generator','name' => 'Random String Generator', 'icon' => '🎲', 'type' => 'random',     'cat' => 'security-tools', 'desc' => 'Generate random strings using a configurable character set.'],
        ['slug' => 'sha256-generator',       'name' => 'SHA-256 Generator',       'icon' => '#',  'type' => 'sha256',     'cat' => 'security-tools', 'desc' => 'Generate a SHA-256 hash for text using the Web Crypto API.'],
        ['slug' => 'password-strength',      'name' => 'Password Strength Checker','icon'=> '🛡️', 'type' => 'strength',   'cat' => 'security-tools', 'desc' => 'Check password length and composition locally without uploading it.'],
        ['slug' => 'uuid-secure-generator',  'name' => 'Secure UUID Generator',   'icon' => '🔑', 'type' => 'uuidsecure', 'cat' => 'security-tools', 'desc' => 'Generate cryptographically secure UUID v4 values via the Web Crypto API.'],
        ['slug' => 'hex-generator',          'name' => 'Hex String Generator',    'icon' => '🔣', 'type' => 'hex',        'cat' => 'security-tools', 'desc' => 'Generate random hex strings of any length using secure entropy.'],
        ['slug' => 'simple-interest-calculator',   'name' => 'Simple Interest Calculator',   'icon' => '➕', 'type' => 'simple-interest',   'cat' => 'calculators',    'desc' => 'Calculate simple interest and total repayment on a principal amount.'],
        ['slug' => 'compound-interest-calculator', 'name' => 'Compound Interest Calculator', 'icon' => '📈', 'type' => 'compound-interest', 'cat' => 'calculators',    'desc' => 'Calculate compound interest growth over time with configurable compounding frequency.'],
        ['slug' => 'duplicate-line-remover',       'name' => 'Duplicate Line Remover',       'icon' => '🧹', 'type' => 'dedupe',            'cat' => 'text-tools',     'desc' => 'Remove duplicate lines from a block of text instantly.'],
        ['slug' => 'slug-generator',               'name' => 'Slug Generator',                'icon' => '🔗', 'type' => 'slug',              'cat' => 'text-tools',     'desc' => 'Convert any text into a clean, URL-friendly slug.'],
        ['slug' => 'number-base-converter',        'name' => 'Number Base Converter',         'icon' => '🔢', 'type' => 'numbase',           'cat' => 'developer-tools','desc' => 'Convert numbers between binary, octal, decimal and hexadecimal.'],
        ['slug' => 'jwt-decoder',                  'name' => 'JWT Decoder',                   'icon' => '🪪', 'type' => 'jwt',               'cat' => 'developer-tools','desc' => 'Decode a JWT header and payload locally — no signature verification, no upload.'],
        ['slug' => 'csv-to-json',                  'name' => 'CSV to JSON Converter',         'icon' => '📊', 'type' => 'csv2json',          'cat' => 'developer-tools','desc' => 'Convert CSV data into formatted JSON directly in your browser.'],
        ['slug' => 'image-rotator',                'name' => 'Image Rotator',                 'icon' => '🔄', 'type' => 'rotate',            'cat' => 'image-tools',    'desc' => 'Rotate an image 90°, 180° or 270° and download the result.'],
        ['slug' => 'fraction-calculator', 'name' => 'Fraction Calculator', 'icon' => '½', 'type' => 'fraction', 'cat' => 'calculators', 'desc' => 'Add, subtract, multiply and divide fractions instantly in your browser.'],
        ['slug' => 'ratio-calculator', 'name' => 'Ratio Calculator', 'icon' => '⚖️', 'type' => 'ratio', 'cat' => 'calculators', 'desc' => 'Simplify ratios and calculate equivalent ratio values.'],
        ['slug' => 'average-calculator', 'name' => 'Average Calculator', 'icon' => '📊', 'type' => 'average', 'cat' => 'calculators', 'desc' => 'Calculate mean, median, minimum, maximum and total from a list of numbers.'],
        ['slug' => 'sales-tax-calculator', 'name' => 'Sales Tax Calculator', 'icon' => '🧾', 'type' => 'sales-tax', 'cat' => 'calculators', 'desc' => 'Calculate tax amount, total price and pre-tax price from a tax rate.'],
        ['slug' => 'date-difference-calculator', 'name' => 'Date Difference Calculator', 'icon' => '📅', 'type' => 'date-diff', 'cat' => 'calculators', 'desc' => 'Calculate the exact difference between two dates in days, weeks, months and years.'],
        ['slug' => 'emi-calculator', 'name' => 'EMI Calculator', 'icon' => '🏦', 'type' => 'emi', 'cat' => 'calculators', 'desc' => 'Calculate monthly EMI, total interest and total payment for a loan.'],
        ['slug' => 'sort-lines', 'name' => 'Sort Lines', 'icon' => '↕️', 'type' => 'sort-lines', 'cat' => 'text-tools', 'desc' => 'Sort text lines alphabetically or numerically and remove optional duplicates.'],
        ['slug' => 'find-and-replace', 'name' => 'Find & Replace', 'icon' => '🔎', 'type' => 'find-replace', 'cat' => 'text-tools', 'desc' => 'Find and replace text directly in your browser with optional case matching.'],
        ['slug' => 'whitespace-remover', 'name' => 'Whitespace Remover', 'icon' => '🧹', 'type' => 'whitespace', 'cat' => 'text-tools', 'desc' => 'Remove leading, trailing and repeated whitespace from text.'],
        ['slug' => 'remove-line-breaks', 'name' => 'Remove Line Breaks', 'icon' => '↔️', 'type' => 'remove-breaks', 'cat' => 'text-tools', 'desc' => 'Turn multi-line text into a clean single paragraph.'],
        ['slug' => 'word-frequency-counter', 'name' => 'Word Frequency Counter', 'icon' => '📈', 'type' => 'word-frequency', 'cat' => 'text-tools', 'desc' => 'Count how often each word appears in your text.'],
        ['slug' => 'lorem-ipsum-generator', 'name' => 'Lorem Ipsum Generator', 'icon' => '📝', 'type' => 'lorem', 'cat' => 'text-tools', 'desc' => 'Generate placeholder paragraphs, sentences or words locally.'],
        ['slug' => 'image-format-converter', 'name' => 'Image Format Converter', 'icon' => '🔄', 'type' => 'image-format', 'cat' => 'image-tools', 'desc' => 'Convert images to PNG, JPEG or WebP using your browser canvas.'],
        ['slug' => 'image-dimensions', 'name' => 'Image Dimensions', 'icon' => '📐', 'type' => 'image-dimensions', 'cat' => 'image-tools', 'desc' => 'Read image width, height, aspect ratio and file size locally.'],
        ['slug' => 'image-data-url', 'name' => 'Image Data URL Generator', 'icon' => '🔗', 'type' => 'image-dataurl', 'cat' => 'image-tools', 'desc' => 'Convert an image file to a Data URL entirely in your browser.'],
        ['slug' => 'regex-tester', 'name' => 'Regex Tester', 'icon' => '.*', 'type' => 'regex', 'cat' => 'developer-tools', 'desc' => 'Test JavaScript regular expressions against sample text locally.'],
        ['slug' => 'color-converter', 'name' => 'Color Converter', 'icon' => '🎨', 'type' => 'color-convert', 'cat' => 'developer-tools', 'desc' => 'Convert HEX, RGB and HSL color values in your browser.'],
        ['slug' => 'css-minifier', 'name' => 'CSS Minifier', 'icon' => '🎨', 'type' => 'css-minify', 'cat' => 'developer-tools', 'desc' => 'Minify CSS by removing comments and unnecessary whitespace locally.'],
        ['slug' => 'json-to-csv', 'name' => 'JSON to CSV Converter', 'icon' => '📋', 'type' => 'json2csv', 'cat' => 'developer-tools', 'desc' => 'Convert an array of JSON objects into CSV in your browser.'],
        ['slug' => 'query-string-parser', 'name' => 'Query String Parser', 'icon' => '🔗', 'type' => 'query-parser', 'cat' => 'developer-tools', 'desc' => 'Parse URL query parameters into a readable key-value list.'],
        ['slug' => 'speed-converter', 'name' => 'Speed Converter', 'icon' => '🏎️', 'type' => 'speed', 'cat' => 'converters', 'desc' => 'Convert km/h, mph, m/s, knots and ft/s.'],
        ['slug' => 'pressure-converter', 'name' => 'Pressure Converter', 'icon' => '🧯', 'type' => 'pressure', 'cat' => 'converters', 'desc' => 'Convert Pa, kPa, bar, psi, atm and mmHg.'],
        ['slug' => 'volume-converter', 'name' => 'Volume Converter', 'icon' => '🧪', 'type' => 'volume', 'cat' => 'converters', 'desc' => 'Convert liters, milliliters, gallons, quarts, cups and cubic meters.'],
        ['slug' => 'energy-converter', 'name' => 'Energy Converter', 'icon' => '⚡', 'type' => 'energy', 'cat' => 'converters', 'desc' => 'Convert joules, kilojoules, calories, watt-hours and kilowatt-hours.'],
        ['slug' => 'frequency-converter', 'name' => 'Frequency Converter', 'icon' => '〰️', 'type' => 'frequency', 'cat' => 'converters', 'desc' => 'Convert Hz, kHz, MHz and GHz.'],
        ['slug' => 'angle-converter', 'name' => 'Angle Converter', 'icon' => '📐', 'type' => 'angle', 'cat' => 'converters', 'desc' => 'Convert degrees, radians, gradians and turns.'],
        ['slug' => 'sha512-generator', 'name' => 'SHA-512 Generator', 'icon' => '🔒', 'type' => 'sha512', 'cat' => 'security-tools', 'desc' => 'Generate a SHA-512 hash from text using the browser Web Crypto API.'],
        ['slug' => 'random-number-generator', 'name' => 'Random Number Generator', 'icon' => '🎯', 'type' => 'random-number', 'cat' => 'security-tools', 'desc' => 'Generate secure random integers within a chosen range.'],
        ['slug' => 'password-entropy-checker', 'name' => 'Password Entropy Checker', 'icon' => '🛡️', 'type' => 'entropy', 'cat' => 'security-tools', 'desc' => 'Estimate password entropy locally from length and character variety.'],
        ['slug' => 'stopwatch', 'name' => 'Online Stopwatch', 'icon' => '⏱️', 'type' => 'stopwatch', 'cat' => 'browser-utilities', 'desc' => 'A browser-based stopwatch with start, pause, reset and lap controls.'],
        ['slug' => 'countdown-timer', 'name' => 'Countdown Timer', 'icon' => '⏳', 'type' => 'countdown', 'cat' => 'browser-utilities', 'desc' => 'Run a countdown timer directly in your browser with no account.'],
        ['slug' => 'text-to-speech', 'name' => 'Text to Speech', 'icon' => '🔊', 'type' => 'tts', 'cat' => 'browser-utilities', 'desc' => 'Read text aloud using your browser speech engine with voice and speed controls.'],
        ['slug' => 'speech-to-text', 'name' => 'Speech to Text', 'icon' => '🎙️', 'type' => 'stt', 'cat' => 'browser-utilities', 'desc' => 'Transcribe speech into text using the browser Web Speech API where supported.'],
        ['slug' => 'timezone-converter', 'name' => 'Time Zone Converter', 'icon' => '🌍', 'type' => 'timezone', 'cat' => 'browser-utilities', 'desc' => 'Convert a date and time between popular world time zones locally.'],
        ['slug' => 'image-watermark', 'name' => 'Image Watermark', 'icon' => '©️', 'type' => 'watermark', 'cat' => 'image-tools', 'desc' => 'Add a text watermark to an image and download the result in your browser.'],
        ['slug' => 'favicon-generator', 'name' => 'Favicon Generator', 'icon' => '⭐', 'type' => 'favicon', 'cat' => 'image-tools', 'desc' => 'Create a simple PNG favicon from text, emoji or a chosen background color locally.'],
        ['slug' => 'image-flipper', 'name' => 'Image Flip Tool', 'icon' => '↔️', 'type' => 'flip-image', 'cat' => 'image-tools', 'desc' => 'Flip images horizontally or vertically using the browser canvas.'],
        ['slug' => 'markdown-to-html', 'name' => 'Markdown to HTML', 'icon' => 'M↓', 'type' => 'markdown-html', 'cat' => 'text-tools', 'desc' => 'Convert common Markdown syntax to clean HTML directly in your browser.'],
        ['slug' => 'morse-code-converter', 'name' => 'Morse Code Converter', 'icon' => '·−', 'type' => 'morse', 'cat' => 'text-tools', 'desc' => 'Convert text to Morse code and Morse code back to text.'],
        ['slug' => 'text-binary-converter', 'name' => 'Text to Binary Converter', 'icon' => '0101', 'type' => 'binary-text', 'cat' => 'text-tools', 'desc' => 'Convert text to UTF-8 binary and binary back to text locally.'],
        ['slug' => 'email-validator', 'name' => 'Email Validator', 'icon' => '✉️', 'type' => 'email-validator', 'cat' => 'text-tools', 'desc' => 'Check basic email address syntax without sending or uploading the address.'],
        ['slug' => 'html-formatter', 'name' => 'HTML Formatter', 'icon' => '⟨/⟩', 'type' => 'html-format', 'cat' => 'developer-tools', 'desc' => 'Beautify HTML with readable indentation directly in your browser.'],
        ['slug' => 'xml-formatter', 'name' => 'XML Formatter', 'icon' => 'XML', 'type' => 'xml-format', 'cat' => 'developer-tools', 'desc' => 'Format XML with indentation and validate basic XML structure locally.'],
        ['slug' => 'json-to-typescript', 'name' => 'JSON to TypeScript', 'icon' => 'TS', 'type' => 'json-ts', 'cat' => 'developer-tools', 'desc' => 'Generate TypeScript interfaces from JSON data in your browser.'],
        ['slug' => 'url-parser', 'name' => 'URL Parser', 'icon' => '🔍', 'type' => 'url-parser', 'cat' => 'developer-tools', 'desc' => 'Inspect protocol, host, port, path, query and hash components of a URL.'],
        ['slug' => 'color-contrast-checker', 'name' => 'Color Contrast Checker', 'icon' => '◐', 'type' => 'contrast', 'cat' => 'developer-tools', 'desc' => 'Check WCAG-style contrast ratio between foreground and background colors.'],
        ['slug' => 'gradient-generator', 'name' => 'CSS Gradient Generator', 'icon' => '🌈', 'type' => 'gradient', 'cat' => 'developer-tools', 'desc' => 'Create CSS linear gradients and copy the generated CSS.'],
        ['slug' => 'meta-tag-generator', 'name' => 'Meta Tag Generator', 'icon' => '🏷️', 'type' => 'meta-tags', 'cat' => 'developer-tools', 'desc' => 'Generate SEO title, description, Open Graph and Twitter meta tags.'],
        ['slug' => 'robots-txt-generator', 'name' => 'Robots.txt Generator', 'icon' => '🤖', 'type' => 'robots', 'cat' => 'developer-tools', 'desc' => 'Generate a robots.txt file for common crawl rules and sitemap settings.'],
        ['slug' => 'text-diff', 'name' => 'Text Diff Checker', 'icon' => '⇄', 'type' => 'text-diff', 'cat' => 'developer-tools', 'desc' => 'Compare two text blocks line by line without uploading your content.'],
        ['slug' => 'mfs-cashout-calculator',   'name' => 'Mobile Banking Cash Out Calculator', 'icon' => '📱', 'type' => 'mfs-cashout',   'cat' => 'bd-tools', 'desc' => 'Estimate bKash, Nagad, Rocket and Upay cash out charges and net amount received.'],
        ['slug' => 'nid-format-checker',       'name' => 'NID Format Checker',                  'icon' => '🪪', 'type' => 'nid-check',     'cat' => 'bd-tools', 'desc' => 'Check whether a Bangladesh National ID number matches the 10, 13 or 17-digit format.'],
        ['slug' => 'bd-mobile-number-checker', 'name' => 'BD Mobile Number Checker',            'icon' => '☎️', 'type' => 'bd-mobile',     'cat' => 'bd-tools', 'desc' => 'Validate a Bangladesh mobile number and identify its operator from the prefix.'],
        ['slug' => 'bangla-number-converter',  'name' => 'Bangla Number Converter',             'icon' => '০৯', 'type' => 'bn-digits',     'cat' => 'bd-tools', 'desc' => 'Convert digits between Bangla (০-৯) and English (0-9) numerals.'],
        ['slug' => 'taka-amount-to-words',     'name' => 'Taka Amount to Words',                'icon' => '৳',  'type' => 'taka-words',    'cat' => 'bd-tools', 'desc' => 'Convert a Taka amount into words using the lakh/crore numbering system — handy for cheques.'],
        ['slug' => 'tin-bin-format-checker',   'name' => 'e-TIN / e-BIN Format Checker',        'icon' => '🧾', 'type' => 'bin-etin-check','cat' => 'bd-tools', 'desc' => 'Check whether a number matches the 12-digit e-TIN or 13-digit e-BIN format.'],
        ['slug' => 'bd-vat-calculator',        'name' => 'Bangladesh VAT Calculator',           'icon' => '➗', 'type' => 'bd-vat',        'cat' => 'bd-tools', 'desc' => 'Add or extract the standard 15% VAT from a price.'],
    ];

    $featured = ['percentage-calculator','word-counter','image-compressor','json-formatter','password-generator'];

    foreach ($base as &$t) {
        $t['id']               = crc32($t['slug']);
        $t['meta_title']       = $t['name'] . ' - Free Online Tool | ToolPWA';
        $t['meta_description'] = $t['desc'] . ' Free, fast and browser-based with no account required.';
        $t['article_html']     = '<h2>About ' . $t['name'] . '</h2><p>' . $t['desc'] . ' This ToolPWA tool is designed to work quickly in modern browsers without requiring a user account.</p><h2>How to use</h2><ol><li>Enter or select your values.</li><li>Use the tool controls to process the information.</li><li>Copy, download or reset the result when available.</li></ol><h2>Privacy-friendly processing</h2><p>Where technically practical, processing happens directly in your browser so your input does not need to be sent to a server.</p>';
        $t['faqs']             = [
            ['Do I need an account?', 'No. Public ToolPWA tools do not require registration or a user account.'],
            ['Does the tool work on mobile?', 'Yes. The interface is responsive and designed for touch screens.'],
            ['Is my input uploaded?', 'Browser-side tools process input locally whenever practical.'],
        ];
        $t['active']    = 1;
        $t['featured']  = in_array($t['slug'], $featured, true) ? 1 : 0;
        $t['views']     = 0;
        $t['uses']      = 0;
        $t['updated_at'] = date('c');
    }
    unset($t);

    $ad_slots = ['header','home-after-tools','category-top','tool-top','tool-bottom','footer'];
    return [
        'site'       => ['name' => 'ToolPWA', 'tagline' => 'Every Tool. Your Own App.', 'description' => 'Fast, free browser-based tools for everyday tasks. No account required.'],
        'categories' => $cats,
        'tools'      => $base,
        'ads'        => array_fill_keys($ad_slots, ['code' => '', 'active' => 0]),
    ];
}

// ─── Data access (cached in static) ─────────────────────────────────────────
function data(): array {
    static $d = null;
    if ($d !== null) return $d;
    $fallback = default_data();
    if (!is_file(DATA_FILE)) { write_json(DATA_FILE, $fallback); return $d = $fallback; }
    $d = read_json(DATA_FILE, $fallback);
    // BUG FIX: migrate missing 'ads' key without full reset
    if (!isset($d['site'], $d['categories'], $d['tools'])) {
        $d = $fallback;
        write_json(DATA_FILE, $d);
    } else {
        $changed = false;
        // Merge newly shipped categories/tools into an existing installation without
        // overwriting admin data, views, usage counts or ad settings.
        $existingCats = array_column($d['categories'], 'slug');
        foreach ($fallback['categories'] as $fc) {
            if (!in_array($fc['slug'], $existingCats, true)) { $d['categories'][] = $fc; $changed = true; }
        }
        $existingTools = array_column($d['tools'], 'slug');
        foreach ($fallback['tools'] as $ft) {
            if (!in_array($ft['slug'], $existingTools, true)) { $d['tools'][] = $ft; $changed = true; }
        }
        if (!isset($d['ads'])) { $d['ads'] = $fallback['ads']; $changed = true; }
        if ($changed) write_json(DATA_FILE, $d);
    }
    // Hide orphaned tool records that have neither a real directory nor a suite parent.
    $parents = []; foreach ($d['tools'] as $pt) foreach (($pt['suite_tools'] ?? []) as $tab) $parents[$tab['slug'] ?? ''] = true;
    $before = count($d['tools']);
    $d['tools'] = array_values(array_filter($d['tools'], function($t) use ($parents) {
        if (($t['type'] ?? '') === 'suite') return true;
        $dir = __DIR__ . '/tools/' . ($t['cat'] ?? '') . '/' . ($t['slug'] ?? '');
        return is_dir($dir) || isset($parents[$t['slug'] ?? '']);
    }));
    if ($before !== count($d['tools'])) write_json(DATA_FILE, $d);
    return $d;
}

function save_data(array $d): void { write_json(DATA_FILE, $d); }

// ─── Lookup helpers ──────────────────────────────────────────────────────────
function cat_by_slug(string $slug): ?array {
    foreach (data()['categories'] as $c) if ($c['slug'] === $slug) return $c;
    return null;
}

function tools_for(string $cat): array {
    return array_values(array_filter(data()['tools'], fn($t) => $t['cat'] === $cat && !empty($t['active'])));
}

function find_tool(string $cat, string $slug): ?array {
    // Strict slug format validation
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) return null;
    foreach (data()['tools'] as $t)
        if ($t['cat'] === $cat && $t['slug'] === $slug && !empty($t['active'])) return $t;
    return null;
}

function ad(string $slot): string {
    $a = data()['ads'][$slot] ?? null;
    return ($a && !empty($a['active'])) ? (string) $a['code'] : '';
}

// ─── HTML sanitiser ──────────────────────────────────────────────────────────
function safe_html(string $html): string {
    $allowed = '<h2><h3><h4><p><ul><ol><li><strong><em><b><i><code><pre><blockquote><a><br><table><thead><tbody><tr><th><td>';
    $html = strip_tags($html, $allowed);
    return preg_replace_callback('/<([a-z0-9]+)(\s[^>]*)?>/i', function ($m) {
        $tag = strtolower($m[1]);
        if ($tag === 'a' && preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $m[2] ?? '', $x)) {
            $u = trim($x[1] ?? ($x[2] ?? ($x[3] ?? '')));
            if ($u !== '' && !preg_match('/^(?:javascript|data|vbscript):/i', $u))
                return '<a href="' . h($u) . '">';
            return '<a>';
        }
        return '<' . $tag . '>';
    }, $html) ?? $html;
}

// ─── Security headers ────────────────────────────────────────────────────────
function headers(bool $html = true): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(),microphone=(),camera=()');
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    if ($html) header('Cache-Control: public,max-age=300,must-revalidate');
}

// ─── Static information pages ────────────────────────────────────────────────
function render_info_page(string $slug): void {
    $map = [
        'privacy' => ['Privacy Policy', 'ToolPWA is designed to keep browser-based processing on the device whenever technically practical.', '<p>ToolPWA does not require a public account for its tools. Browser tools are designed to process local input in the browser whenever technically practical. Category PWAs cache site resources required for offline operation; the service worker is not designed to collect or upload user files.</p><p>Third-party services may be present only where a specific feature requires them. Review individual tool descriptions for processing details.</p>'],
        'terms' => ['Terms of Use', 'General terms for using ToolPWA browser tools.', '<p>ToolPWA provides general-purpose browser utilities for convenience. You are responsible for checking generated results before using them in consequential work.</p><p>Do not use a ToolPWA result as a substitute for professional advice where specialist review is required.</p>'],
        'contact' => ['Contact', 'Contact information for ToolPWA.', '<p>Direct contact details have not been configured in this build. The page is provided as the central contact destination so a site owner can add the preferred email address, form or support channel without changing the navigation system.</p>'],
    ];
    [$title, $desc, $body] = $map[$slug];
    page_head($title . ' | ToolPWA', $desc, abs_url(base_url($slug . '/')));
    header_html('');
    echo '<main><section><div class="container"><article class="tool-article"><div class="tool-breadcrumb"><a href="' . h(base_url('/')) . '">ToolPWA</a> / ' . h($title) . '</div><h1>' . h($title) . '</h1><p class="hero-sub">' . h($desc) . '</p><div class="category-seo">' . $body . '</div></article></div></section></main>';
    page_foot();
}

// ─── Page chrome ─────────────────────────────────────────────────────────────
function render_404(): void {
    http_response_code(404);
    $title = 'Page Not Found | ToolPWA';
    $desc  = 'The requested ToolPWA page could not be found.';
    page_head($title, $desc, abs_url('/'));
    echo '<main class="container" style="padding:100px 0;text-align:center"><div class="tool-hero-icon">404</div><h1>Page not found</h1><p class="hero-sub">The page you requested does not exist.</p><a class="btn primary" href="' . h(base_url('/')) . '">Back to ToolPWA</a></main>';
    page_foot();
}

function page_head(string $title, string $desc, string $canonical, string $manifest = '', string $keywords = '', string $image = ''): void {
    $d = data();
    $graph = ['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'WebSite',  'name' => $d['site']['name'],  'url' => abs_url('/'), 'description' => $d['site']['description']],
        ['@type' => 'WebPage',  'name' => $title,              'url' => $canonical,   'description' => $desc],
    ]];
    $v      = APP_VERSION;
    $ogImg  = $image !== '' ? $image : abs_url('assets/icon-512.png');
    echo '<!doctype html><html lang="en"><head>'
       . '<meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
       . '<meta name="theme-color" content="#f4f1e8">'
       . '<meta name="color-scheme" content="dark light">'
       . '<meta name="mobile-web-app-capable" content="yes">'
       . '<meta name="apple-mobile-web-app-capable" content="yes">'
       . '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">'
       . '<title>' . h($title) . '</title>'
       . '<meta name="description" content="' . h($desc) . '"/>'
       . '<meta name="robots" content="index,follow,max-image-preview:large"/>';
    if ($keywords !== '') echo '<meta name="keywords" content="' . h($keywords) . '"/>';
    echo '<link rel="canonical" href="' . h($canonical) . '"/>';
    if ($manifest) echo '<link rel="manifest" href="' . h($manifest) . '"/>';
    echo '<link rel="icon" href="' . h(base_url('assets/icon-192.png')) . '"/>'
       // ── Open Graph ──────────────────────────────────────────────────────────
       . '<meta property="og:site_name" content="' . h($d['site']['name']) . '"/>'
       . '<meta property="og:type" content="website"/>'
       . '<meta property="og:title" content="' . h($title) . '"/>'
       . '<meta property="og:description" content="' . h($desc) . '"/>'
       . '<meta property="og:url" content="' . h($canonical) . '"/>'
       . '<meta property="og:image" content="' . h($ogImg) . '"/>'
       . '<meta property="og:locale" content="en_US"/>'
       // ── Twitter Card ────────────────────────────────────────────────────────
       . '<meta name="twitter:card" content="summary_large_image"/>'
       . '<meta name="twitter:title" content="' . h($title) . '"/>'
       . '<meta name="twitter:description" content="' . h($desc) . '"/>'
       . '<meta name="twitter:image" content="' . h($ogImg) . '"/>'
       . '<link rel="stylesheet" href="' . h(base_url('assets/app.css')) . '?v=' . $v . '"/>'
       // Resolve system/manual theme before paint.
       . '<script>window.TOOLPWA_BASE=' . json_encode(rtrim(BASE_PATH,'/')) . ';try{const t=localStorage.getItem("toolpwa-theme")||"system";if(t!=="system")document.documentElement.dataset.theme=t;}catch(e){}</script>'
       . '<script type="application/ld+json">' . json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>'
       . '</head><body>';
}

function header_html(string $active = ''): void {
    $searchIndex=[]; foreach(data()['tools'] as $t){ if(empty($t['active'])) continue; $cc=cat_by_slug($t['cat']); if(!$cc) continue; $searchIndex[]=['slug'=>$t['slug'],'name'=>$t['name'],'desc'=>$t['desc'],'category'=>$cc['name'],'icon'=>$t['icon'],'url'=>tool_url($cc,$t)]; }
    $searchJson=json_encode($searchIndex, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    echo '<header class="site-header"><div class="container nav nav-inner">'
       . '<a class="logo brand" href="' . h(base_url('/')) . '" aria-label="ToolPWA home"><span class="logo-g">Tool</span><span class="logo-accent">PWA</span><span class="logo-dot"></span></a>'
       . '<nav class="navlinks primary-nav" aria-label="Primary navigation">'
       .   '<a class="' . ($active === 'home' ? 'active' : '') . '" href="' . h(base_url('/')) . '">Home</a>'
       .   '<a href="' . h(base_url('/') . '#categories') . '">Categories</a>'
       .   '<a href="' . h(base_url('/') . '#tools') . '">Popular Tools</a>'
       . '</nav>'
       . '<div class="nav-right header-actions">'
       .   '<button class="icon-btn header-search-link" id="globalSearchTrigger" type="button">⌕ Search <kbd>/</kbd></button>'
       .   '<a class="icon-btn header-favorites-link" href="' . h(base_url('/') . '#tools') . '">☆ Favorites</a>'
       .   '<a class="btn primary header-install" id="installApp" href="' . h(base_url('/') . '#categories') . '">Install</a>'
       .   '<label class="theme-select-wrap"><span class="sr-only">Theme</span><select id="themeSelect" class="theme-select" aria-label="Theme"><option value="system">System</option><option value="dark">Dark</option><option value="light">Light</option></select></label>'
       . '</div>'
       . '<button class="icon-btn mobile-menu-btn" id="mobileMenuBtn" type="button" aria-label="Open menu" aria-expanded="false">☰</button>'
       . '</div>'
       . '<nav class="mobile-nav-menu mobile-primary-nav" id="mobileNavMenu" aria-label="Mobile navigation">'
       .   '<button type="button" class="mobile-nav-search" id="mobileSearchTrigger">⌕ Search tools <kbd>/</kbd></button>'
       .   '<a href="' . h(base_url('/')) . '">Home</a>'
       .   '<a href="' . h(base_url('/') . '#categories') . '">Categories</a>'
       .   '<a href="' . h(base_url('/') . '#tools') . '">Popular Tools</a>'
       .   '<a href="' . h(base_url('/') . '#about') . '">About</a>'
       . '</nav>'
       . '<script id="toolIndex" type="application/json">' . $searchJson . '</script>'
       . '<div id="toolpwa-search-modal" role="dialog" aria-modal="true" aria-label="Search tools"><div class="search-modal-box"><div class="search-modal-top"><span>⌕</span><input id="globalSearchInput" type="search" placeholder="Search tools, categories or functions…" autocomplete="off"><kbd>Esc</kbd></div><div class="search-modal-results" id="globalSearchResults"></div></div></div>'
       . '</header>';
}
function page_foot(): void {
    echo '<div id="toolpwa-toast-region" aria-live="polite" aria-atomic="true"></div>'
       . '<div class="pwa-install-banner" id="installBanner" role="dialog" aria-label="Install ToolPWA"><div><strong>Install ToolPWA</strong><p>Keep your favorite utilities one tap away with an app-like experience.</p></div><div class="action-row"><button class="btn" type="button" data-dismiss-install>Later</button><button class="btn primary" type="button" id="installNow">Install app</button></div></div>'
       . '<footer class="tool-page-footer"><div class="container"><div class="footer-grid">'
       . '<div class="footer-brand"><div class="logo"><span class="logo-g">Tool</span><span class="logo-accent">PWA</span><span class="logo-dot"></span></div>'
       .   '<p>Every Tool. Your Own App.</p><small>© ' . date('Y') . ' ToolPWA.</small>'
       . '</div>'
       . '<div class="footer-right"><nav class="footer-menu" aria-label="Footer navigation">'
       .   '<a href="' . h(base_url('/')) . '">Home</a>'
       .   '<a href="' . h(base_url('/') . '#categories') . '">Categories</a>'
       .   '<a href="' . h(base_url('/') . '#tools') . '">Popular Tools</a>'
       .   '<a href="' . h(base_url('/') . '#about') . '">About</a>'
       .   '<a href="' . h(base_url('/privacy/')) . '">Privacy</a>'
       .   '<a href="' . h(base_url('/terms/')) . '">Terms</a>'
       .   '<a href="' . h(base_url('/contact/')) . '">Contact</a>'
       . '</nav></div>'
       . '</div></div></footer>'
       . '<script src="' . h(base_url('assets/app.js')) . '?v=' . APP_VERSION . '" defer></script>'
       . '</body></html>';
}
