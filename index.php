<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
headers();

// ─── Router ──────────────────────────────────────────────────────────────────
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base = rtrim(BASE_PATH, '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base)) ?: '/';
}
$parts = array_values(array_filter(explode('/', trim($path, '/')), fn($x) => $x !== ''));

// Anonymous aggregate usage endpoint. No IP, user ID or raw input is stored.
if (($parts[0] ?? '') === 'api' && ($parts[1] ?? '') === 'usage' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $ok = record_tool_usage((string)($payload['slug'] ?? ''));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>$ok]); exit;
}

if (!$parts)                  { render_home();         exit; }
if ($parts[0] === 'admin.php'){ require __DIR__ . '/admin.php'; exit; }
if (count($parts) === 2 && in_array($parts[1], ['manifest.php','sw.php'], true)) {
    $_GET['category'] = $parts[0];
    require __DIR__ . '/' . $parts[1];
    exit;
}
if (count($parts) === 1 && in_array($parts[0], ['privacy','terms','contact'], true)) { render_info_page($parts[0]); exit; }

// BDIX read-only API: checks only administrator-registered URLs.
if ($parts[0] === 'api' && ($parts[1] ?? '') === 'bdix' && ($parts[2] ?? '') === 'check') {
    bdix_check_endpoint();
    exit;
}

$cat = $parts[0];
$c   = cat_by_slug($cat);
if (!$c)                      { render_404(); exit; }
if (count($parts) === 1)      { render_category($c); exit; }
if (count($parts) === 2) {
    $t = find_tool($cat, $parts[1]);
    if (!$t) {
        // Legacy individual tool URLs now redirect into their category suite.
        $legacyPath = __DIR__ . '/storage/legacy_routes.json';
        if (is_file($legacyPath)) {
            $legacy = json_decode((string) file_get_contents($legacyPath), true) ?: [];
            if (isset($legacy[$parts[1]]) && $legacy[$parts[1]][0] === $cat) {
                $suite = find_tool($cat, $legacy[$parts[1]][1]);
                if ($suite) {
                    header('Location: ' . tool_url($c, $suite) . '?tab=' . rawurlencode($parts[1]), true, 301);
                    exit;
                }
            }
        }
        render_404(); exit;
    }
    render_tool($c, $t);
    exit;
}
render_404();

// ─── URL builders ─────────────────────────────────────────────────────────────
function tool_url(array $c, array $t): string {
    return base_url($c['slug'] . '/' . $t['slug'] . '/');
}
function category_url(array $c): string {
    return base_url($c['slug'] . '/');
}

// ─── Core six-category UX layer ──────────────────────────────────────────────
function core_categories(): array {
    return [
        ['slug'=>'bd-tools','name'=>'Bangladesh Utilities','icon'=>'🇧🇩','description'=>'Bangladesh-focused MFS, NID/TIN/BIN, Taka, Bangla number and VAT utilities.','cats'=>['bd-tools']],
        ['slug'=>'image-tools','name'=>'Image Tools','icon'=>'🖼️','description'=>'Compress, resize, crop, convert, inspect and transform images locally in your browser.','cats'=>['image-tools']],
        ['slug'=>'text-tools','name'=>'Text & String','icon'=>'✍️','description'=>'Count, clean, transform, validate and generate text without sending it to a server.','cats'=>['text-tools','browser-utilities']],
        ['slug'=>'calculators','name'=>'Fast Calculators','icon'=>'🧮','description'=>'Everyday maths, finance, date, unit and conversion utilities with instant results.','cats'=>['calculators','converters']],
        ['slug'=>'security-tools','name'=>'Security & Crypto','icon'=>'🛡️','description'=>'Passwords, entropy, hashes, UUIDs and secure random generators using browser crypto APIs.','cats'=>['security-tools']],
        ['slug'=>'developer-tools','name'=>'Developer & System','icon'=>'💻','description'=>'JSON, URL, HTML, regex, diff, timestamps, timezone and web-development utilities.','cats'=>['developer-tools','browser-utilities']],
    ];
}
function core_for_tool(array $t): ?array {
    foreach (core_categories() as $g) if (in_array($t['cat'] ?? '', $g['cats'], true)) return $g;
    return null;
}
function core_tools(array $g): array {
    return array_values(array_filter(data()['tools'], fn($t) => in_array($t['cat'] ?? '', $g['cats'], true) && !empty($t['active'])));
}

function core_category_url(array $g): string {
    $slug = (string)($g['cats'][0] ?? $g['slug']);
    $c = cat_by_slug($slug);
    return $c ? category_url($c) : base_url($g['slug'] . '/');
}

// ─── Canonical tool links ─────────────────────────────────────────────────────
function tool_link(array $t): ?string {
    $cat = cat_by_slug((string)($t['cat'] ?? ''));
    if (!$cat || empty($t['active'])) return null;
    if (($t['type'] ?? '') === 'suite') return tool_url($cat, $t);
    $dir = __DIR__ . '/tools/' . $cat['slug'] . '/' . $t['slug'];
    if (is_dir($dir)) return tool_url($cat, $t);
    foreach (data()['tools'] as $parent) {
        if (($parent['cat'] ?? '') !== $cat['slug'] || ($parent['type'] ?? '') !== 'suite' || empty($parent['active'])) continue;
        foreach (($parent['suite_tools'] ?? []) as $tab) {
            if (($tab['slug'] ?? '') === $t['slug']) return tool_url($cat, $parent) . '?tab=' . rawurlencode($t['slug']);
        }
    }
    return null;
}
function tool_is_routable(array $t): bool { return tool_link($t) !== null; }

// ─── Tool card ────────────────────────────────────────────────────────────────
function tool_card(array $c, array $t): string {
    $g = core_for_tool($t);
    $catSlug = $g['slug'] ?? ($c['slug'] ?? $t['cat'] ?? '');
    $catName = $g['name'] ?? ($c['name'] ?? 'Tool');
    $url = tool_link($t);
    if ($url === null) return '';
    return '<article class="tool-card" data-cat="' . h($catSlug) . '" data-search="' . h(strtolower(($t['name'] ?? '') . ' ' . ($t['desc'] ?? '') . ' ' . ($catName))) . '">'
         . '<div class="tool-card-top"><span class="tool-icon" aria-hidden="true">' . h($t['icon']) . '</span><button class="favorite-btn" type="button" data-favorite="' . h($t['slug']) . '" aria-label="Add ' . h($t['name']) . ' to favorites" aria-pressed="false">☆</button></div>'
         . '<a class="tool-card-link" href="' . h($url) . '"><div class="tool-info"><h3>' . h($t['name']) . '</h3><p>' . h($t['desc']) . '</p><span class="tool-cat">' . h($catName) . '</span></div><span class="tool-arrow">→</span></a></article>';
}

// ─── Home page ────────────────────────────────────────────────────────────────
function render_home(): void {
    $d = data();
    $groups = core_categories();
    $count = count($d['tools']);
    $title = 'ToolPWA — 130+ Fast Browser Tools | Calculators, Images, Text & Developer Utilities';
    $desc  = 'Fast, lightweight browser tools for Bangladesh utilities, images, text, calculators, security and developer workflows. No login required.';
    page_head($title, $desc, abs_url('/'));
    header_html('home');

    $all = $d['tools'];
    $jsonIndex = [];
    foreach ($all as $t) {
        $g = core_for_tool($t);
        if (!$g) continue;
        if (!tool_is_routable($t)) continue;
        $realCat = cat_by_slug($t['cat']) ?? ['slug'=>$t['cat'],'name'=>$g['name'],'icon'=>$g['icon'],'description'=>$g['description']];
        $jsonIndex[] = ['slug'=>$t['slug'],'name'=>$t['name'],'desc'=>$t['desc'],'category'=>$g['name'],'icon'=>$t['icon'],'url'=>tool_link($t),'tags'=>[$g['name'], $t['type'] ?? 'tool']];
    }

    echo '<main>'
       . '<section class="hero hero-directory">'
       . '<div class="container">'
       . '<div class="eyebrow"><span class="eyebrow-dot"></span>FAST · PRIVATE · BROWSER-BASED</div>'
       . '<h1>Everyday tools.<br><span>One fast workspace.</span></h1>'
       . '<p class="hero-sub">130+ lightweight utilities for calculations, images, text, Bangladesh workflows, security and developer tasks.</p>'
       . '<form class="directory-search" id="directorySearchForm" action="' . h(base_url('/')) . '" method="get">'
       . '<span class="search-icon" aria-hidden="true">⌕</span>'
       . '<input id="homeSearch" name="q" type="search" placeholder="Search tools, categories or functions…" autocomplete="off" value="' . h($_GET['q'] ?? '') . '" aria-label="Search ToolPWA">'
       . '<kbd>/</kbd>'
       . '</form>'
       . '<div class="search-hints"><span>Try</span><button type="button" data-search-fill="EMI">EMI</button><button type="button" data-search-fill="JSON">JSON</button><button type="button" data-search-fill="compress">compress</button><button type="button" data-search-fill="NID">NID</button></div>'
       . '<div class="hero-mini-stats"><span><b>' . $count . '</b> tools</span><span><b>6</b> core categories</span><span><b>0</b> logins</span></div>'
       . '</div></section>';

    $q = trim((string)($_GET['q'] ?? ''));
    echo '<section class="directory-section" id="categories"><div class="container">'
       . '<div class="section-head"><div class="section-head-left"><h2>Choose a category</h2><p>Six focused collections keep discovery simple even as the library grows.</p></div></div>'
       . '<div class="core-category-grid">';
    foreach ($groups as $g) {
        $tools = core_tools($g);
        $preview = array_slice($tools, 0, 5);
        echo '<article class="core-category-card" data-core-category="' . h($g['slug']) . '">'
           . '<div class="core-cat-head"><span class="core-cat-icon">' . h($g['icon']) . '</span><span class="core-cat-count">' . count($tools) . ' tools</span></div>'
           . '<h3>' . h($g['name']) . '</h3><p>' . h($g['description']) . '</p>'
           . '<div class="core-tool-preview">';
        foreach ($preview as $pt) { $pu = tool_link($pt); if ($pu) echo '<a href="' . h($pu) . '">' . h($pt['name']) . '</a>'; }
        echo '</div><a class="core-cat-link" href="' . h(core_category_url($g)) . '">Explore ' . h($g['name']) . ' <span>→</span></a>'
           . '</article>';
    }
    echo '</div></div></section>';

    echo '<section class="directory-section compact-section" id="tools"><div class="container">'
       . '<div class="section-head"><div class="section-head-left"><h2 id="toolFilterLabel">Popular & recently discoverable</h2><p>Save your favorites or jump back into recently used tools.</p></div><a class="section-link" href="#categories">All categories</a></div>'
       . '<div class="recent-strip" id="recentTools" hidden><div class="recent-strip-head"><b>Recently Used</b><button type="button" class="link-btn" id="clearRecent">Clear</button></div><div class="recent-list" id="recentToolList"></div></div>'
       . '<div class="tool-grid directory-tool-grid" id="toolGrid">';
    $featured = array_values(array_filter($all, 'tool_is_routable'));
    foreach ($featured as $t) {
        $tc = cat_by_slug($t['cat']); if ($tc) echo tool_card($tc, $t);
    }
    echo '</div><div class="load-more-wrap"><button class="btn" id="loadMoreBtn" type="button">Show more tools</button></div>';
    $scores = tool_usage_scores(); $trend = []; foreach (array_keys($scores) as $slug) { foreach ($all as $tt) if (($tt['slug'] ?? '') === $slug && tool_is_routable($tt)) { $trend[]=$tt; break; } if(count($trend)>=8) break; }
    if (!$trend) $trend = array_slice(array_values(array_filter($all,'tool_is_routable')),0,8);
    echo '<section class="trending-section"><div class="section-head"><div class="section-head-left"><h2>Trending from real tool usage</h2><p>Popular utilities are ranked from anonymous aggregate usage, with no account required.</p></div><span class="trend-live">● LIVE SIGNAL</span></div><div class="tool-grid">';
    foreach($trend as $tt){$tc=cat_by_slug($tt['cat']); if($tc) echo tool_card($tc,$tt);}
    echo '</div></section>'
       . '</div></section>';

    echo '<section class="directory-section why-section" id="about"><div class="container">'
       . '<div class="section-head"><div class="section-head-left"><h2>Built like a utility app, not a content maze</h2><p>Fast interaction first. Discovery, related tools and SEO content come after the task.</p></div></div>'
       . '<div class="features feature-grid-compact">'
       . '<div class="feature"><div class="feature-icon">⚡</div><b>Fast</b><p>Lightweight PHP, CSS and vanilla JS with local processing whenever practical.</p></div>'
       . '<div class="feature"><div class="feature-icon">🔒</div><b>Private</b><p>Files and text stay in the browser for tools designed for local processing.</p></div>'
       . '<div class="feature"><div class="feature-icon">📱</div><b>Installable</b><p>Category-based PWAs give frequent tool groups an app-like home screen.</p></div>'
       . '<div class="feature"><div class="feature-icon">↗</div><b>Discoverable</b><p>Search, favorites, recent history and related tools create natural next steps.</p></div>'
       . '</div></div></section>'
       . '<section class="directory-section"><div class="container"><div class="seo-directory"><h2>Free browser tools for everyday work</h2><p>ToolPWA brings practical utilities into one consistent interface: Bangladesh-specific calculators and checks, image processing, text and string utilities, fast calculators and converters, security and crypto helpers, and developer/system tools. Each tool is designed around a focused input-to-result workflow.</p><p>Use the global search to find a tool by name, category or common task. Save tools you use repeatedly with the star button, and your recent tools stay available on this device without an account.</p></div></div></section>'
       . '</main>';
    page_foot();
}

// ─── Category page ────────────────────────────────────────────────────────────
function render_category(array $c): void {
    $tools = tools_for($c['slug']);
    $group = null; foreach (core_categories() as $g) if ($g['slug'] === $c['slug'] || in_array($c['slug'], $g['cats'], true)) {$group=$g;break;}
    $pageName = $group['name'] ?? $c['name'];
    $pageDesc = $group['description'] ?? $c['description'];
    if ($group) $tools = core_tools($group);
    $title = $pageName . ' — Free Browser Tools | ToolPWA';
    $desc  = $pageDesc . ' Browse this ToolPWA collection without a login.';
    $keywords = strtolower($pageName) . ', free ' . strtolower($pageName) . ', ToolPWA';
    page_head($title, $desc, abs_url(category_url($c)), abs_url(category_url($c) . 'manifest.php'), $keywords);
    header_html('');
    echo '<main><section class="category-page-modern"><div class="container">'
       . '<div class="tool-breadcrumb"><a href="' . h(base_url('/')) . '">ToolPWA</a> / ' . h($pageName) . '</div>'
       . '<div class="category-hero-modern"><div><span class="eyebrow"><span class="eyebrow-dot"></span>CATEGORY WORKSPACE</span><h1>' . h($pageName) . '</h1><p>' . h($pageDesc) . '</p></div><div class="category-stat-modern"><b>' . count($tools) . '</b><span>tools</span><button class="btn primary" id="installCategory" type="button">Install Category</button></div></div>'
       . ad('category-top')
       . '<div class="category-discovery"><input id="categorySearch" type="search" placeholder="Search this category…" autocomplete="off"><span>Press / to focus global search</span></div>'
       . '<div class="tool-grid" id="categoryToolGrid">';
    foreach ($tools as $t) { $tc=cat_by_slug($t['cat']) ?? $c; echo tool_card($tc,$t); }
    echo '</div>'
       . '<section class="category-seo-modern"><h2>About ' . h($pageName) . '</h2><p>' . h($pageDesc) . ' Use the collection without an account, and use favorites or recent history to return to tools quickly.</p></section>'
       . '</div></section></main>';
    page_foot();
}

// ─── Tool page ────────────────────────────────────────────────────────────────
function tool_content_profile(array $t): array {
    $family = tool_ui_family((string)($t['type'] ?? ''));
    $name = (string)($t['name'] ?? 'Tool');
    $profiles = [
        'calculator' => [
            'features'=>['Instant calculations in your browser','Clear input and result separation','Responsive controls for desktop and mobile','Reset and repeat without reloading'],
            'steps'=>['Enter the values required by the calculator','Choose units or options when available','Run the calculation','Review, copy or reuse the result'],
            'tips'=>['Check the units before calculating.','Use realistic values and verify important results independently.']
        ],
        'text' => [
            'features'=>['Large editing workspace','Live or on-demand text analysis','One-click transformations','Local browser processing for supported operations'],
            'steps'=>['Paste or type your text','Choose the transformation or analysis you need','Review the result','Copy the output or clear the workspace'],
            'tips'=>['Keep the original text until you have verified the output.','For large text, work in smaller sections when your browser feels slow.']
        ],
        'image' => [
            'features'=>['Image-first workspace with preview controls','Browser-side processing for supported operations','Adjustable quality, size or visual settings','Download-ready output'],
            'steps'=>['Select or drop an image','Adjust the available settings','Run the image operation','Preview and download the result'],
            'tips'=>['Keep the original image if you may need to change settings later.','For best quality, avoid repeated lossy conversions.']
        ],
        'developer' => [
            'features'=>['Developer-focused input and output panels','Readable formatted results','Fast copy-ready output','Client-side processing where practical'],
            'steps'=>['Paste your source data or code','Select the operation and options','Run the tool','Review, copy or export the result'],
            'tips'=>['Validate generated output before using it in production.','For sensitive data, prefer tools that process entirely in the browser.']
        ],
        'security' => [
            'features'=>['Browser-native cryptography where supported','No account required','Copy-ready security output','Focused controls with readable results'],
            'steps'=>['Enter the value or choose generation options','Run the security operation','Review the generated result','Copy it only when you are ready to use it'],
            'tips'=>['Never reuse important passwords.','Do not paste secrets into tools unless the page clearly states how processing is performed.']
        ],
        'color' => [
            'features'=>['Visual color workspace','Instant color values and conversions','Copy-friendly output','Responsive preview controls'],
            'steps'=>['Choose or enter a color','Adjust the available color controls','Review the generated values or preview','Copy the value you need'],
            'tips'=>['Check contrast on the actual background where the color will be used.','Keep source color values when building a design system.']
        ],
        'time' => [
            'features'=>['Readable time-focused interface','Instant conversion or live timing','Simple start, stop or reset controls where applicable','Mobile-friendly controls'],
            'steps'=>['Enter a time value or start the timer','Choose the required unit or mode','Run the operation','Read or copy the result'],
            'tips'=>['Keep a consistent timezone when comparing timestamps.','For critical scheduling, confirm the result against your calendar or source system.']
        ],
        'bangladesh' => [
            'features'=>['Bangladesh-focused workflows','Local number, currency and service conventions','Fast browser-side calculations','Simple copy-ready results'],
            'steps'=>['Enter the Bangladesh-specific value or identifier','Choose the relevant option','Run the check or calculation','Review and copy the result'],
            'tips'=>['Use official government or service sources for final verification.','Do not treat a browser utility as an official government decision or record.']
        ],
        'bdix' => [
            'features'=>['Bangladesh-focused server discovery','Search and filter controls','Browser/network-specific checks','Clear reachability status'],
            'steps'=>['Search or choose a server','Run the browser-side check when available','Review status and latency','Open the destination only if you trust it'],
            'tips'=>['Results can vary by ISP and network.','Do not enter credentials on unfamiliar destinations.']
        ],
        'audio' => [
            'features'=>['Media-focused controls','Clear input and output states','Browser capability-aware workflow','Simple repeatable actions'],
            'steps'=>['Choose the input or voice action','Adjust available settings','Run the operation','Review or save the output'],
            'tips'=>['Browser support can vary by device.','Use headphones and check permissions before recording or playback.']
        ],
    ];
    $base=$profiles[$family] ?? [
        'features'=>['Focused single-purpose workflow','Responsive desktop and mobile interface','No account required','Fast copy-ready results'],
        'steps'=>['Enter your input','Choose the available options','Run the tool','Review and reuse the result'],
        'tips'=>['Check the result before using it in an important workflow.','Keep source data available when you may need to repeat the operation.']
    ];
    $base['example'] = 'Use '.$name.' to complete a focused task without leaving your browser.';
    return $base;
}

function render_tool(array $c, array $t): void {
    $title     = $t['meta_title'];
    $desc      = $t['meta_description'];
    $canonical = abs_url(tool_url($c, $t));
    $keywords  = strtolower($t['name']) . ', online ' . strtolower($t['name']) . ', free ' . strtolower($t['name']) . ', ToolPWA';
    page_head($title, $desc, $canonical, abs_url(category_url($c) . 'manifest.php'), $keywords);
    header_html('');
    $faq = $t['faqs'] ?? [];
    $profile = tool_content_profile($t);
    $family = tool_ui_family((string)($t['type'] ?? ''));
    $core = core_for_tool($t) ?? ['name'=>$c['name'],'icon'=>$c['icon'],'cats'=>[$c['slug']],'slug'=>$c['slug']];
    $displayCategory = $core['name'];
    $graph = ['@context'=>'https://schema.org','@type'=>'SoftwareApplication','name'=>$t['name'],'url'=>$canonical,'applicationCategory'=>'UtilitiesApplication','operatingSystem'=>'Any','description'=>$desc,'offers'=>['@type'=>'Offer','price'=>'0','priceCurrency'=>'USD'],'featureList'=>$profile['features'],'applicationSubCategory'=>$displayCategory];
    $breadcrumb=['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>[['@type'=>'ListItem','position'=>1,'name'=>'ToolPWA','item'=>abs_url('/')],['@type'=>'ListItem','position'=>2,'name'=>$c['name'],'item'=>abs_url(category_url($c))],['@type'=>'ListItem','position'=>3,'name'=>$t['name'],'item'=>$canonical]]];
    $faqGraph=$faq ? ['@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>array_map(fn($f)=>['@type'=>'Question','name'=>$f[0],'acceptedAnswer'=>['@type'=>'Answer','text'=>strip_tags($f[1])]],$faq)] : null;
    $related = array_values(array_filter(core_tools($core), fn($x) => $x['slug'] !== $t['slug']));
    $isNew = !empty($t['updated_at']) && strtotime((string)$t['updated_at']) >= strtotime('-30 days');
    echo '<main><section class="tool-page-modern"><div class="container">'
       . '<div class="tool-breadcrumb"><a href="' . h(base_url('/')) . '">ToolPWA</a> / <a href="' . h(category_url($c)) . '">' . h($c['name']) . '</a> / ' . h($t['name']) . '</div>'
       . '<div class="tool-titlebar"><div><span class="tool-category-chip">' . h($core['icon']) . ' ' . h($displayCategory) . '</span><div class="tool-badges">' . ($isNew ? '<span class="tool-badge new">NEW</span>' : '') . '<span class="tool-badge">' . h(ucfirst($family)) . '</span></div><h1>' . h($t['name']) . '</h1><p>' . h($t['desc']) . '</p></div><div class="tool-page-actions"><button class="icon-action favorite-btn large" type="button" data-favorite="' . h($t['slug']) . '" aria-label="Favorite ' . h($t['name']) . '" aria-pressed="false">☆</button><div class="share-wrap"><button class="icon-action share-trigger" type="button" aria-label="Share ' . h($t['name']) . '" aria-expanded="false" aria-controls="shareMenu">↗</button><div class="share-menu" id="shareMenu" role="menu"><button type="button" data-share="facebook">Facebook</button><button type="button" data-share="x">X</button><button type="button" data-share="whatsapp">WhatsApp</button><button type="button" data-share="copy">Copy link</button><button type="button" data-share="email">Email</button></div></div></div></div>'
       . ad('tool-top')
       . '<div class="tool-workspace-full"><div class="tool-workspace tool-workspace--' . h($family) . '" data-tool="' . h($t['type']) . '"><div class="workspace-head"><span class="state-dot"></span><span>WORKSPACE</span><span class="workspace-local">' . (($t['browser_based'] ?? true) ? 'Processed locally when supported' : '') . '</span></div><div class="workspace-content">' . ($t['type'] === 'suite' ? suite_ui($t) : tool_ui($t['type'])) . '</div></div></div>'
       . '<div class="tool-recent-under tool-recent-full"><div class="recent-strip-head"><b>Recently Used Apps</b><button type="button" class="link-btn" id="clearRecent">Clear</button></div><div class="recent-list" id="recentToolList"></div></div>'
       . '<div class="tool-insight-grid"><section class="tool-insight-card"><span class="eyebrow">KEY FEATURES</span><ul class="feature-list">';
    foreach ($profile['features'] as $x) echo '<li><span>✓</span>' . h($x) . '</li>';
    echo '</ul></section><section class="tool-insight-card"><span class="eyebrow">HOW TO USE</span><ol class="steps-list">';
    foreach ($profile['steps'] as $x) echo '<li><b>' . h($x) . '</b></li>';
    echo '</ol></section></div>'
       . '<div class="master-tool-layout">'
       . '<aside class="micro-nav"><div class="micro-nav-title">Categories</div>';
    foreach (core_categories() as $g) echo '<a class="micro-nav-item" href="' . h(base_url('/#categories')) . '" data-core-nav="' . h($g['slug']) . '"><span>' . h($g['icon']) . '</span><b>' . h($g['name']) . '</b></a>';
    echo '</aside>'
       . '<section class="tool-content-column"><article class="tool-article tool-article-main"><span class="eyebrow">ABOUT THIS TOOL</span>' . safe_html($t['article_html'])
       . '<section class="tool-tips"><h2>Tips</h2><ul>'; foreach($profile['tips'] as $tip) echo '<li>' . h($tip) . '</li>'; echo '</ul></section>'
       . '<section class="tool-example"><h2>Example</h2><p>' . h($profile['example']) . '</p></section>'
       . '<section class="tool-use-cases"><h2>Common use cases</h2><ul>'; foreach (($t['use_cases'] ?? []) as $u) echo '<li>' . h($u) . '</li>'; echo '</ul></section>'
       . '<section class="tool-examples"><h2>Try a quick example</h2><div class="example-presets">'; foreach (($t['examples'] ?? []) as $ex) echo '<span>' . h($ex) . '</span>'; echo '</div></section>';
    if ($faq) { echo '<h2>Frequently Asked Questions</h2><div class="faq-wrap">'; foreach($faq as $f) echo '<details><summary>' . h($f[0]) . '</summary><p>' . h($f[1]) . '</p></details>'; echo '</div>'; }
    echo '</article></section>'
       . '<aside class="tool-context"><div class="context-block"><div class="context-label">ADVERTISEMENT</div>' . ad('tool-context') . '</div>'
       . '<div class="context-block"><div class="context-head"><b>Related Tools</b></div><div class="context-related">';
    foreach (array_slice($related,0,7) as $x) { $xu = tool_link($x); if (!$xu) continue; echo '<a href="' . h($xu) . '"><span>' . h($x['icon']) . '</span><span><b>' . h($x['name']) . '</b><small>' . h($x['desc']) . '</small></span><em>→</em></a>'; }
    echo '</div></div></aside></div>'
       . '<div class="tool-quality-grid"><section class="tool-quality-card"><div><span class="eyebrow">WAS THIS TOOL USEFUL?</span><p>Rate this tool on this device.</p></div><div class="rating-stars" data-rating-slug="' . h($t['slug']) . '" role="group" aria-label="Rate this tool"><button data-rate="1">★</button><button data-rate="2">★</button><button data-rate="3">★</button><button data-rate="4">★</button><button data-rate="5">★</button><output>Not rated</output></div></section><section class="tool-quality-card report-card"><div><span class="eyebrow">FOUND A PROBLEM?</span><p>Report a broken workflow, confusing result or display issue.</p></div><button class="btn" id="reportTool" type="button">Report issue</button></section></div>'
       . '<div class="related-tools-full"><div class="section-head"><div class="section-head-left"><h2>More tools you may like</h2><p>Keep the workflow moving with another focused utility.</p></div></div><div class="tool-grid">';
    foreach(array_slice($related,0,6) as $x) echo tool_card($c,$x);
    echo '</div></div></div></section></main>';
    $current=['slug'=>$t['slug'],'name'=>$t['name'],'icon'=>$t['icon'] ?? '🧰','url'=>tool_url($c,$t),'category'=>$c['name']];
    echo '<script id="currentTool" type="application/json">' . json_encode($current, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . '</script>'
       . '<script type="application/ld+json">' . json_encode($graph, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP) . '</script>'
       . '<script type="application/ld+json">' . json_encode($breadcrumb, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP) . '</script>'
       . ($faqGraph ? '<script type="application/ld+json">'.json_encode($faqGraph,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP).'</script>' : '');
    page_foot();
}

// ─── Combined tool suite UI ───────────────────────────────────────────────────
function suite_ui(array $t): string {
    $tabs = $t['suite_tools'] ?? [];
    if (!$tabs) return '<div class="calc-card">No tools configured.</div>';
    $requested = trim((string)($_GET['tab'] ?? ''));
    $active = $tabs[0];
    foreach ($tabs as $tab) if ($requested !== '' && ($tab['slug'] ?? '') === $requested) { $active = $tab; break; }
    $cat = cat_by_slug($t['cat']);
    $suiteUrl = tool_url($cat, $t);
    $html = '<div class="suite-shell">'
          . '<div class="suite-mobile-select"><label for="suiteFunctionSelect">Function</label><select id="suiteFunctionSelect" onchange="if(this.value) location.href=this.value">';
    foreach ($tabs as $tab) {
        $is = ($tab['slug'] ?? '') === ($active['slug'] ?? '');
        $url = $suiteUrl . '?tab=' . rawurlencode($tab['slug']);
        $html .= '<option value="' . h($url) . '"' . ($is ? ' selected' : '') . '>' . h(($tab['icon'] ?? '') . ' ' . ($tab['name'] ?? '')) . '</option>';
    }
    $html .= '</select></div><div class="suite-tabs" role="tablist" aria-label="' . h($t['name']) . ' functions">';
    foreach ($tabs as $tab) {
        $is = ($tab['slug'] ?? '') === ($active['slug'] ?? '');
        $url = $suiteUrl . '?tab=' . rawurlencode($tab['slug']);
        $html .= '<a class="suite-tab' . ($is ? ' active' : '') . '" href="' . h($url) . '" role="tab" aria-selected="' . ($is ? 'true' : 'false') . '"><span class="suite-tab-icon">' . h($tab['icon'] ?? '') . '</span><span>' . h($tab['name'] ?? '') . '</span></a>';
    }
    $html .= '</div><div class="suite-active">' . tool_ui((string)$active['type']) . '</div></div>';
    return $html;
}

// ─── BDIX directory helpers ─────────────────────────────────────────────────
function bdix_servers(): array {
    $d = read_json(BDIX_FILE, ['servers'=>[]]);
    $rows = is_array($d['servers'] ?? null) ? $d['servers'] : [];
    return array_values(array_filter($rows, fn($x) => !empty($x['active']) && !empty($x['url']) && !empty($x['name'])));
}
function bdix_finder_ui(): string {
    $rows = bdix_servers();
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    $html = '<div class="bdix-tool bdix-finder-modern" data-bdix-finder><div class="bdix-intro"><div class="bdix-intro-icon">◉</div><div><h2>Find a server you can reach</h2><p>Browse the directory, then test any server directly from your current internet connection. Results are specific to your browser and network.</p></div></div><div class="bdix-toolbar"><label class="bdix-search-field"><span>Search servers</span><input id="bdixSearch" type="search" placeholder="Search by name, ISP, location or URL…" autocomplete="off"></label><label><span>Location</span><select id="bdixLocation"><option value="">All locations</option>';
    $locs=[]; foreach($rows as $r) if(!empty($r['location'])) $locs[$r['location']]=1; ksort($locs);
    foreach(array_keys($locs) as $loc) $html.='<option value="'.h($loc).'">'.h($loc).'</option>';
    $html .= '</select></label></div><div class="bdix-results-head"><div><b id="bdixCount">'.count($rows).' servers</b><span> in the directory</span></div><span class="bdix-trust">✓ Admin managed · Browser tested</span></div><div class="bdix-server-list" id="bdixServerList"></div><script type="application/json" id="bdixServerData">'.$json.'</script></div>';
    return $html;
}
function bdix_checker_ui(): string {
    $rows = bdix_servers();
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    $html = '<div class="bdix-tool bdix-checker-modern" data-bdix-checker>';
    $html .= '<div class="bdix-intro bdix-intro-check"><div class="bdix-intro-icon">✓</div><div><h2>Check from your own connection</h2><p>Run the test in your browser to see which registered servers are reachable from your current ISP/network. We never claim a server is globally online from this test.</p></div><button class="btn primary bdix-main-action" id="bdixTestAll" type="button">Test all servers</button></div>';
    $html .= '<div class="bdix-check-summary"><div class="bdix-stat ok"><b id="bdixReachable">0</b><span>Reachable</span></div><div class="bdix-stat bad"><b id="bdixFailed">0</b><span>Not reachable</span></div><div class="bdix-stat warn"><b id="bdixUnknown">0</b><span>Could not verify</span></div><div class="bdix-stat"><b id="bdixPending">'.count($rows).'</b><span>Not tested</span></div></div><div class="bdix-progress" id="bdixProgress"><span></span></div>';
    $html .= '<div class="bdix-filter-row"><input id="bdixCheckSearch" type="search" placeholder="Filter servers…" autocomplete="off"><select id="bdixCheckFilter"><option value="all">All</option><option value="reachable">Reachable</option><option value="failed">Not reachable</option><option value="unknown">Unable to verify</option><option value="pending">Not tested</option></select></div>';
    $html .= '<div class="bdix-check-list" id="bdixCheckList"></div><script type="application/json" id="bdixCheckData">'.$json.'</script>';
    $html .= '<div class="bdix-help"><details><summary>How does this test work?</summary><p>Your browser sends a lightweight request to each registered URL. A successful network response is shown as <b>Reachable</b>. Browser restrictions such as HTTPS→HTTP mixed content are shown as <b>Could not verify</b> instead of being falsely reported as offline.</p></details></div><p class="privacy-note">No login is required. The checker only tests URLs published in the ToolPWA directory.</p></div>';
    return $html;
}
function bdix_check_endpoint(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $id=(int)($_GET['id'] ?? 0); $rows=bdix_servers(); $row=null;
    foreach($rows as $r) if((int)($r['id']??0)===$id){$row=$r;break;}
    if(!$row){ http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Server not found']); return; }
    $url=(string)$row['url']; $u=parse_url($url);
    if(!$u || !in_array(strtolower($u['scheme']??''),['http','https'],true) || empty($u['host'])){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid registered URL']); return; }
    $host=$u['host'];
    $ip=filter_var($host,FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if($ip && (filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'The registered host resolves to a private or reserved address']); return; }
    $start=microtime(true); $status=0; $error=''; $final=$url;
    if(function_exists('curl_init')){
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_NOBODY=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERAGENT=>'ToolPWA-BDIX-Checker/1.0',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]); curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $final=(string)(curl_getinfo($ch,CURLINFO_EFFECTIVE_URL)?:$url); $error=(string)curl_error($ch); curl_close($ch);
    } else { $headers=@get_headers($url,true); if(is_array($headers)){ $first=(string)($headers[0]??''); if(preg_match('/\s(\d{3})\b/',$first,$m))$status=(int)$m[1]; } else $error='Unable to connect.'; }
    $ms=round((microtime(true)-$start)*1000); $ok=$status>=200 && $status<500;
    echo json_encode(['ok'=>$ok,'status'=>$status,'latency_ms'=>$ms,'name'=>$row['name'],'url'=>$url,'final_url'=>$final,'error'=>$error?:null],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

// ─── Tool UI (inline HTML per tool type) ─────────────────────────────────────
function tool_ui_family(string $type): string {
    $families = [
        'calculator' => ['aspect','roman','pct-change','percentage','bmi','age','discount','tip','loan','length','temperature','data','time','weight','area','simple-interest','compound-interest','fraction','ratio','average','sales-tax','emi','speed','pressure','volume','energy','frequency','angle','scientific','break-even','unit-price','numbase','date-diff'],
        'text' => ['md-table','word','character','case','cleaner','line','reverse','sort-lines','find-replace','whitespace','remove-breaks','word-frequency','lorem','morse','binary-text','text-diff','text-stats','duplicate-words'],
        'image' => ['compressor','resizer','image64','colorpicker','grayscale','crop','image-format','image-dimensions','image-dataurl','rotate','watermark','favicon','flip-image','image-blur','image-pixelate','image-border','contrast'],
        'developer' => ['base','html-entities','box-shadow','palette','json','url','html','regex','css-minify','json2csv','query-parser','jwt','csv2json','markdown-html','html-format','xml-format','json-ts','url-parser','meta-tags','robots','json-validator','html-minify','js-format','sql-format'],
        'security' => ['password','random','sha256','sha512','sha1','sha384','strength','uuidsecure','hex','random-number','entropy','email-validator'],
        'color' => ['color-convert','gradient'],
        'time' => ['timestamp','stopwatch','countdown','timezone'],
        'audio' => ['tts','stt'],
        'bangladesh' => ['mfs-cashout','nid-check','bd-mobile','bn-digits','taka-words','bin-etin-check','bd-vat'],
        'bdix' => ['bdix-finder','bdix-checker'],
    ];
    foreach ($families as $family => $types) {
        if (in_array($type, $types, true)) return $family;
    }
    return 'utility';
}

function tool_ui(string $type): string {
    $ui = match ($type) {
        'aspect' => '<div class="calc-card"><div class="input-grid"><label>Original width<input id="arW" type="number" value="1920"></label><label>Original height<input id="arH" type="number" value="1080"></label><label>New width<input id="arNW" type="number" value="1280"></label></div><button class="btn primary" data-advanced-action="aspect">Calculate</button><div class="result-box" id="result">Enter dimensions.</div></div>',
        'roman' => '<div class="calc-card"><label>Number or Roman numeral<input id="romanInput" placeholder="2026 or MMXXVI"></label><button class="btn primary" data-advanced-action="roman">Convert</button><div class="result-box" id="result">Result appears here.</div></div>',
        'base' => '<div class="calc-card"><div class="input-grid"><label>Number<input id="baseN" value="255"></label><label>From base<select id="baseFrom"><option>2</option><option selected>10</option><option>8</option><option>16</option></select></label><label>To base<select id="baseTo"><option>2</option><option>8</option><option selected>16</option><option>10</option></select></label></div><button class="btn primary" data-advanced-action="base">Convert</button><div class="result-box" id="result">FF</div></div>',
        'csv2json' => '<div class="calc-card"><label>CSV data<textarea id="textInput" rows="12" placeholder="name,email\nSufi,sufi@example.com"></textarea></label><button class="btn primary" data-action="csv2json">Convert to JSON</button><div class="result-box result-text" id="result"></div></div>',
        'jwt' => '<div class="calc-card"><label>JWT<textarea id="jwtInput" rows="8" placeholder="eyJhbGciOi..."></textarea></label><button class="btn primary" data-advanced-action="jwt">Decode payload</button><div class="result-box result-text" id="result">The signature is not verified.</div></div>',
        'html-entities' => '<div class="calc-card"><label>Text<textarea id="textInput" rows="9" placeholder="<h1>Hello & welcome</h1>"></textarea></label><select id="entityMode"><option value="encode">Encode</option><option value="decode">Decode</option></select><button class="btn primary" data-advanced-action="entities">Convert</button><div class="result-box result-text" id="result"></div></div>',
        'box-shadow' => '<div class="calc-card"><div class="input-grid"><label>X<input id="shadowX" type="number" value="0"></label><label>Y<input id="shadowY" type="number" value="8"></label><label>Blur<input id="shadowBlur" type="number" value="24"></label><label>Spread<input id="shadowSpread" type="number" value="0"></label><label>Color<input id="shadowColor" type="text" value="#00000040"></label></div><button class="btn primary" data-advanced-action="shadow">Generate CSS</button><div class="result-box result-text" id="result"></div></div>',
        'palette' => '<div class="calc-card"><label>Base color<input id="paletteColor" type="color" value="#08795f"></label><button class="btn primary" data-advanced-action="palette">Generate palette</button><div class="result-box result-text" id="result"></div></div>',
        'md-table' => '<div class="calc-card"><label>Comma-separated rows<textarea id="tableInput" rows="10" placeholder="Name,Role\nAlice,Designer\nBob,Developer"></textarea></label><button class="btn primary" data-advanced-action="mdtable">Generate Markdown</button><div class="result-box result-text" id="result"></div></div>',
        'text-stats' => '<div class="calc-card"><label>Text<textarea id="textInput" rows="12" placeholder="Paste an article or draft here…"></textarea></label><button class="btn primary" data-advanced-action="textstats">Analyze</button><div class="result-box result-text" id="result">Live analysis on demand.</div></div>',
        'date-diff' => '<div class="calc-card"><div class="input-grid"><label>Start date<input id="dateA" type="date"></label><label>End date<input id="dateB" type="date"></label></div><button class="btn primary" data-advanced-action="datediff">Calculate difference</button><div class="result-box" id="result">Choose dates.</div></div>',
        'pct-change' => '<div class="calc-card"><div class="input-grid"><label>Starting value<input id="pctA" type="number" value="100"></label><label>New value<input id="pctB" type="number" value="125"></label></div><button class="btn primary" data-advanced-action="pctchange">Calculate change</button><div class="result-box" id="result">25% increase</div></div>',

        'bdix-finder' => bdix_finder_ui(),

        'bdix-checker' => bdix_checker_ui(),

        'percentage' =>
            '<div class="calc-card">'
          . '<div class="input-grid">'
          .   '<label>What is <input id="pA" type="number" inputmode="decimal" placeholder="25">% of <input id="pB" type="number" inputmode="decimal" placeholder="200"></label>'
          .   '<label>Percentage change from <input id="pC" type="number" inputmode="decimal" placeholder="100"> to <input id="pD" type="number" inputmode="decimal" placeholder="125"></label>'
          . '</div>'
          . '<button class="btn primary" data-action="percentage">Calculate</button>'
          . '<div class="result-box" id="result">Enter values to calculate.</div>'
          . '<button class="btn" data-reset>Reset</button></div>',

        'bmi' =>
            '<div class="calc-card">'
          . '<div class="input-grid">'
          .   '<label>Height (cm)<input id="bmiH" type="number" inputmode="decimal" placeholder="170"></label>'
          .   '<label>Weight (kg)<input id="bmiW" type="number" inputmode="decimal" placeholder="70"></label>'
          . '</div>'
          . '<button class="btn primary" data-action="bmi">Calculate BMI</button>'
          . '<div class="result-box" id="result">Your BMI will appear here.</div>'
          . '<button class="btn" data-reset>Reset</button></div>',

        'age' =>
            '<div class="calc-card">'
          . '<div class="input-grid">'
          .   '<label>Date of birth<input id="dob" type="date"></label>'
          .   '<label>Calculate as of<input id="ageDate" type="date"></label>'
          . '</div>'
          . '<button class="btn primary" data-action="age">Calculate Age</button>'
          . '<div class="result-box" id="result">Enter your birth date.</div>'
          . '<button class="btn" data-reset>Reset</button></div>',

        'discount' =>
            '<div class="calc-card">'
          . '<div class="input-grid">'
          .   '<label>Original price<input id="price" type="number" inputmode="decimal" placeholder="1000"></label>'
          .   '<label>Discount (%)<input id="discount" type="number" inputmode="decimal" placeholder="15"></label>'
          . '</div>'
          . '<button class="btn primary" data-action="discount">Calculate</button>'
          . '<div class="result-box" id="result">Your sale price will appear here.</div>'
          . '<button class="btn" data-reset>Reset</button></div>',

        'tip' =>
            '<div class="calc-card">'
          . '<div class="input-grid">'
          .   '<label>Bill amount<input id="tipBill" type="number" inputmode="decimal" placeholder="1000"></label>'
          .   '<label>Tip %<input id="tipPct" type="number" inputmode="decimal" placeholder="10"></label>'
          .   '<label>Number of people<input id="tipPeople" type="number" inputmode="numeric" placeholder="2" min="1"></label>'
          . '</div>'
          . '<button class="btn primary" data-action="tip">Calculate Tip</button>'
          . '<div class="result-box" id="result">Result will appear here.</div>'
          . '<button class="btn" data-reset>Reset</button></div>',

        'loan' =>
            '<div class="calc-card">'
          . '<div class="input-grid">'
          .   '<label>Principal<input id="loanP" type="number" inputmode="decimal" placeholder="100000"></label>'
          .   '<label>Annual interest %<input id="loanR" type="number" inputmode="decimal" placeholder="10"></label>'
          .   '<label>Term (months)<input id="loanN" type="number" inputmode="numeric" placeholder="12" min="1"></label>'
          . '</div>'
          . '<button class="btn primary" data-action="loan">Calculate Payment</button>'
          . '<div class="result-box" id="result">Result will appear here.</div>'
          . '<button class="btn" data-reset>Reset</button></div>',

        'word' =>
            '<div class="calc-card">'
          . '<label>Paste or type your text<textarea id="textInput" rows="10" placeholder="Start typing…"></textarea></label>'
          . '<div class="live-stats">'
          .   '<span><b id="words">0</b> Words</span>'
          .   '<span><b id="chars">0</b> Characters</span>'
          .   '<span><b id="charsNo">0</b> No spaces</span>'
          .   '<span><b id="paras">0</b> Paragraphs</span>'
          . '</div>'
          . '<button class="btn" data-reset>Clear</button></div>',

        'character' =>
            '<div class="calc-card">'
          . '<label>Your text<textarea id="textInput" rows="10" placeholder="Type or paste text…"></textarea></label>'
          . '<div class="result-box" id="result">0 characters</div>'
          . '<button class="btn" data-reset>Clear</button></div>',

        'case' =>
            '<div class="calc-card">'
          . '<label>Text<textarea id="textInput" rows="9" placeholder="Type text here…"></textarea></label>'
          . '<div class="action-row">'
          .   '<button class="btn" data-case="upper">UPPERCASE</button>'
          .   '<button class="btn" data-case="lower">lowercase</button>'
          .   '<button class="btn" data-case="title">Title Case</button>'
          .   '<button class="btn" data-case="sentence">Sentence case</button>'
          . '</div>'
          . '<button class="btn" data-reset>Clear</button></div>',

        'cleaner' =>
            '<div class="calc-card">'
          . '<label>Text<textarea id="textInput" rows="10" placeholder="Paste messy text…"></textarea></label>'
          . '<button class="btn primary" data-action="cleaner">Clean Text</button>'
          . '<div class="result-box result-text" id="result"></div>'
          . '<button class="btn" data-copy-result>Copy Result</button></div>',

        'line' =>
            '<div class="calc-card">'
          . '<label>Text<textarea id="textInput" rows="10" placeholder="Paste text here…"></textarea></label>'
          . '<button class="btn primary" data-action="lines">Count Lines</button>'
          . '<div class="result-box" id="result">Result will appear here.</div>'
          . '<button class="btn" data-reset>Clear</button></div>',

        'reverse' =>
            '<div class="calc-card">'
          . '<label>Text<textarea id="textInput" rows="10" placeholder="Text to reverse…"></textarea></label>'
          . '<button class="btn primary" data-action="reverse">Reverse Text</button>'
          . '<div class="result-box result-text" id="result"></div>'
          . '<button class="btn" data-reset>Clear</button></div>',

        'compressor' =>
            '<div class="calc-card">'
          . '<label>Select image<input id="imgFile" type="file" accept="image/*"></label>'
          . '<div class="input-grid">'
          .   '<label>Quality<input id="quality" type="range" min="0.2" max="1" step="0.05" value="0.8"><output id="qualityOut">80%</output></label>'
          .   '<label>Format<select id="imgFormat"><option value="image/webp">WebP</option><option value="image/jpeg">JPEG</option></select></label>'
          . '</div>'
          . '<button class="btn primary" data-action="compress">Compress Image</button>'
          . '<div class="result-box" id="result">Your compressed image stays in your browser.</div></div>',

        'resizer' =>
            '<div class="calc-card">'
          . '<label>Select image<input id="imgFile" type="file" accept="image/*"></label>'
          . '<div class="input-grid">'
          .   '<label>Width<input id="imgW" type="number" inputmode="numeric"></label>'
          .   '<label>Height<input id="imgH" type="number" inputmode="numeric"></label>'
          . '</div>'
          . '<label><input id="lockRatio" type="checkbox" checked> Keep aspect ratio</label>'
          . '<button class="btn primary" data-action="resize">Resize Image</button>'
          . '<div class="result-box" id="result">Choose an image.</div></div>',

        'image64' =>
            '<div class="calc-card">'
          . '<label>Select image<input id="imgFile" type="file" accept="image/*"></label>'
          . '<button class="btn primary" data-action="image64">Convert to Base64</button>'
          . '<textarea id="resultText" rows="8" readonly placeholder="Base64 result…"></textarea>'
          . '<button class="btn" data-copy-text>Copy Result</button></div>',

        'colorpicker' =>
            '<div class="calc-card">'
          . '<label>Select image<input id="imgFile" type="file" accept="image/*"></label>'
          . '<canvas id="pickerCanvas" class="picker-canvas"></canvas>'
          . '<p>Click the image to pick a pixel color.</p>'
          . '<div class="result-box" id="result">Color: —</div></div>',

        'grayscale' =>
            '<div class="calc-card">'
          . '<label>Select image<input id="imgFile" type="file" accept="image/*"></label>'
          . '<button class="btn primary" data-action="grayscale">Create Grayscale</button>'
          . '<div class="result-box" id="result">Choose an image.</div></div>',

        'crop' =>
            '<div class="calc-card">'
          . '<label>Select image<input id="imgFile" type="file" accept="image/*"></label>'
          . '<div class="input-grid">'
          .   '<label>Crop width<input id="cropW" type="number" inputmode="numeric" placeholder="500"></label>'
          .   '<label>Crop height<input id="cropH" type="number" inputmode="numeric" placeholder="500"></label>'
          . '</div>'
          . '<button class="btn primary" data-action="crop">Crop Image</button>'
          . '<div class="result-box" id="result">Choose an image.</div></div>',

        'json' =>
            '<div class="calc-card">'
          . '<label>JSON<textarea id="textInput" rows="14" placeholder=\'{"name":"ToolPWA"}\'></textarea></label>'
          . '<div class="action-row">'
          .   '<button class="btn primary" data-action="json-format">Format</button>'
          .   '<button class="btn" data-action="json-minify">Minify</button>'
          .   '<button class="btn" data-copy-result>Copy</button>'
          . '</div>'
          . '<div class="result-box result-text" id="result"></div></div>',

        'url' =>
            '<div class="calc-card">'
          . '<label>Text / URL<textarea id="textInput" rows="8" placeholder="https://example.com/?hello world"></textarea></label>'
          . '<div class="action-row">'
          .   '<button class="btn primary" data-action="url-encode">Encode</button>'
          .   '<button class="btn" data-action="url-decode">Decode</button>'
          .   '<button class="btn" data-copy-result>Copy</button>'
          . '</div>'
          . '<div class="result-box result-text" id="result"></div></div>',

        'base64' =>
            '<div class="calc-card">'
          . '<label>Text<textarea id="textInput" rows="8" placeholder="Enter text…"></textarea></label>'
          . '<div class="action-row">'
          .   '<button class="btn primary" data-action="base64-encode">Encode</button>'
          .   '<button class="btn" data-action="base64-decode">Decode</button>'
          .   '<button class="btn" data-copy-result>Copy</button>'
          . '</div>'
          . '<div class="result-box result-text" id="result"></div></div>',

        'uuid' =>
            '<div class="calc-card">'
          . '<div class="result-box result-text" id="result">Click generate.</div>'
          . '<div class="action-row">'
          .   '<button class="btn primary" data-action="uuid">Generate UUID</button>'
          .   '<button class="btn" data-copy-result>Copy</button>'
          . '</div></div>',

        'timestamp' =>
            '<div class="calc-card">'
          . '<label>Unix timestamp or date string<input id="tsInput" type="text" placeholder="1700000000 or 2024-01-01T00:00:00"></label>'
          . '<div class="action-row">'
          .   '<button class="btn primary" data-action="ts-to-date">Timestamp → Date</button>'
          .   '<button class="btn" data-action="date-to-ts">Date → Timestamp</button>'
          . '</div>'
          . '<div class="result-box" id="result">Enter a value above.</div>'
          . '<button class="btn" data-reset>Reset</button></div>',

        'html' =>
            '<div class="calc-card">'
          . '<label>Text<textarea id="textInput" rows="10" placeholder="<h1>Hello &amp; world</h1>"></textarea></label>'
          . '<div class="action-row">'
          .   '<button class="btn primary" data-action="html-encode">Encode</button>'
          .   '<button class="btn" data-action="html-decode">Decode</button>'
          .   '<button class="btn" data-copy-result>Copy</button>'
          . '</div>'
          . '<div class="result-box result-text" id="result"></div></div>',

        'length' =>
            '<div class="calc-card"><div class="input-grid">'
          . '<label>Value<input id="convValue" type="number" inputmode="decimal" placeholder="1"></label>'
          . '<label>From<select id="convFrom"><option value="mm">Millimeter</option><option value="cm">Centimeter</option><option value="m" selected>Meter</option><option value="km">Kilometer</option><option value="in">Inch</option><option value="ft">Foot</option></select></label>'
          . '<label>To<select id="convTo"><option value="mm">Millimeter</option><option value="cm">Centimeter</option><option value="m">Meter</option><option value="km">Kilometer</option><option value="in">Inch</option><option value="ft" selected>Foot</option></select></label>'
          . '</div><button class="btn primary" data-action="length">Convert</button>'
          . '<div class="result-box" id="result">Result</div></div>',

        'temperature' =>
            '<div class="calc-card"><div class="input-grid">'
          . '<label>Value<input id="convValue" type="number" inputmode="decimal" placeholder="25"></label>'
          . '<label>From<select id="convFrom"><option value="C">Celsius (°C)</option><option value="F">Fahrenheit (°F)</option><option value="K">Kelvin (K)</option></select></label>'
          . '<label>To<select id="convTo"><option value="F">Fahrenheit (°F)</option><option value="C">Celsius (°C)</option><option value="K">Kelvin (K)</option></select></label>'
          . '</div><button class="btn primary" data-action="temperature">Convert</button>'
          . '<div class="result-box" id="result">Result</div></div>',

        'data' =>
            '<div class="calc-card"><div class="input-grid">'
          . '<label>Value<input id="convValue" type="number" inputmode="decimal" placeholder="1"></label>'
          . '<label>From<select id="convFrom"><option value="B">Bytes</option><option value="KB">KB</option><option value="MB" selected>MB</option><option value="GB">GB</option><option value="TB">TB</option></select></label>'
          . '<label>To<select id="convTo"><option value="B">Bytes</option><option value="KB">KB</option><option value="MB">MB</option><option value="GB" selected>GB</option><option value="TB">TB</option></select></label>'
          . '</div><button class="btn primary" data-action="data">Convert</button>'
          . '<div class="result-box" id="result">Result</div></div>',

        'time' =>
            '<div class="calc-card"><div class="input-grid">'
          . '<label>Value<input id="convValue" type="number" inputmode="decimal" placeholder="60"></label>'
          . '<label>From<select id="convFrom"><option value="s">Seconds</option><option value="min" selected>Minutes</option><option value="h">Hours</option><option value="d">Days</option></select></label>'
          . '<label>To<select id="convTo"><option value="s">Seconds</option><option value="min">Minutes</option><option value="h" selected>Hours</option><option value="d">Days</option></select></label>'
          . '</div><button class="btn primary" data-action="time">Convert</button>'
          . '<div class="result-box" id="result">Result</div></div>',

        'weight' =>
            '<div class="calc-card"><div class="input-grid">'
          . '<label>Value<input id="convValue" type="number" inputmode="decimal" placeholder="1"></label>'
          . '<label>From<select id="convFrom"><option value="kg" selected>Kilogram</option><option value="g">Gram</option><option value="lb">Pound</option><option value="oz">Ounce</option></select></label>'
          . '<label>To<select id="convTo"><option value="kg">Kilogram</option><option value="g">Gram</option><option value="lb" selected>Pound</option><option value="oz">Ounce</option></select></label>'
          . '</div><button class="btn primary" data-action="weight">Convert</button>'
          . '<div class="result-box" id="result">Result</div></div>',

        'area' =>
            '<div class="calc-card"><div class="input-grid">'
          . '<label>Value<input id="convValue" type="number" inputmode="decimal" placeholder="1"></label>'
          . '<label>From<select id="convFrom"><option value="m2" selected>m²</option><option value="km2">km²</option><option value="ft2">ft²</option><option value="yd2">yd²</option><option value="acre">acre</option></select></label>'
          . '<label>To<select id="convTo"><option value="m2">m²</option><option value="km2">km²</option><option value="ft2" selected>ft²</option><option value="yd2">yd²</option><option value="acre">acre</option></select></label>'
          . '</div><button class="btn primary" data-action="area">Convert</button>'
          . '<div class="result-box" id="result">Result</div></div>',

        'password' =>
            '<div class="calc-card"><div class="input-grid">'
          . '<label>Length<input id="pwLen" type="number" min="8" max="128" value="20"></label>'
          . '<label>Options<select id="pwOpts"><option value="all">Letters + numbers + symbols</option><option value="alnum">Letters + numbers</option><option value="letters">Letters only</option></select></label>'
          . '</div>'
          . '<button class="btn primary" data-action="password">Generate Password</button>'
          . '<div class="result-box result-text" id="result"></div>'
          . '<button class="btn" data-copy-result>Copy</button></div>',

        'random' =>
            '<div class="calc-card"><div class="input-grid">'
          . '<label>Length<input id="randLen" type="number" min="1" max="256" value="32"></label>'
          . '<label>Character set<select id="randSet"><option value="all">Letters + numbers</option><option value="alpha">Letters</option><option value="numbers">Numbers</option><option value="hex">Hex</option></select></label>'
          . '</div>'
          . '<button class="btn primary" data-action="random">Generate</button>'
          . '<div class="result-box result-text" id="result"></div>'
          . '<button class="btn" data-copy-result>Copy</button></div>',

        'sha256' =>
            '<div class="calc-card">'
          . '<label>Text to hash<textarea id="textInput" rows="8" placeholder="Text to hash…"></textarea></label>'
          . '<button class="btn primary" data-action="sha256">Generate SHA-256</button>'
          . '<div class="result-box result-text" id="result"></div>'
          . '<button class="btn" data-copy-result>Copy</button></div>',

        'strength' =>
            '<div class="calc-card">'
          . '<label>Password<input id="strengthInput" type="password" autocomplete="off" placeholder="Type a password to check"></label>'
          . '<div class="strength-meter"><span id="strengthBar"></span></div>'
          . '<div class="result-box" id="result">Enter a password to check it locally.</div>'
          . '<p class="privacy-note">Your password is not sent anywhere.</p></div>',

        'uuidsecure' =>
            '<div class="calc-card">'
          . '<button class="btn primary" data-action="uuidsecure">Generate Secure UUID</button>'
          . '<div class="result-box result-text" id="result">Click generate.</div>'
          . '<button class="btn" data-copy-result>Copy</button></div>',

        'hex' =>
            '<div class="calc-card">'
          . '<label>Length (hex chars)<input id="hexLen" type="number" value="32" min="2" max="256"></label>'
          . '<button class="btn primary" data-action="hex">Generate Hex</button>'
          . '<div class="result-box result-text" id="result"></div>'
          . '<button class="btn" data-copy-result>Copy</button></div>',

        'simple-interest' =>
            '<div class="calc-card">'
          . '<div class="input-grid">'
          .   '<label>Principal<input id="siP" type="number" inputmode="decimal" placeholder="10000"></label>'
          .   '<label>Annual rate (%)<input id="siR" type="number" inputmode="decimal" placeholder="8"></label>'
          .   '<label>Time (years)<input id="siT" type="number" inputmode="decimal" placeholder="3"></label>'
          . '</div>'
          . '<button class="btn primary" data-action="simple-interest">Calculate</button>'
          . '<div class="result-box" id="result">Result will appear here.</div>'
          . '<button class="btn" data-reset>Reset</button></div>',

        'compound-interest' =>
            '<div class="calc-card">'
          . '<div class="input-grid">'
          .   '<label>Principal<input id="ciP" type="number" inputmode="decimal" placeholder="10000"></label>'
          .   '<label>Annual rate (%)<input id="ciR" type="number" inputmode="decimal" placeholder="8"></label>'
          .   '<label>Time (years)<input id="ciT" type="number" inputmode="decimal" placeholder="3"></label>'
          .   '<label>Compounds per year<select id="ciN"><option value="1">Annually</option><option value="2">Semi-annually</option><option value="4">Quarterly</option><option value="12" selected>Monthly</option><option value="365">Daily</option></select></label>'
          . '</div>'
          . '<button class="btn primary" data-action="compound-interest">Calculate</button>'
          . '<div class="result-box" id="result">Result will appear here.</div>'
          . '<button class="btn" data-reset>Reset</button></div>',

        'dedupe' =>
            '<div class="calc-card">'
          . '<label>Text<textarea id="textInput" rows="10" placeholder="Paste lines here…"></textarea></label>'
          . '<label><input id="dedupeCase" type="checkbox"> Case-insensitive</label>'
          . '<button class="btn primary" data-action="dedupe">Remove Duplicates</button>'
          . '<div class="result-box result-text" id="result"></div>'
          . '<button class="btn" data-copy-result>Copy Result</button></div>',

        'slug' =>
            '<div class="calc-card">'
          . '<label>Text<textarea id="textInput" rows="6" placeholder="My Blog Post Title!"></textarea></label>'
          . '<button class="btn primary" data-action="slug">Generate Slug</button>'
          . '<div class="result-box result-text" id="result"></div>'
          . '<button class="btn" data-copy-result>Copy</button></div>',

        'numbase' =>
            '<div class="calc-card"><div class="input-grid">'
          . '<label>Value<input id="nbValue" type="text" inputmode="text" placeholder="255"></label>'
          . '<label>From<select id="nbFrom"><option value="2">Binary</option><option value="8">Octal</option><option value="10" selected>Decimal</option><option value="16">Hexadecimal</option></select></label>'
          . '<label>To<select id="nbTo"><option value="2">Binary</option><option value="8">Octal</option><option value="10">Decimal</option><option value="16" selected>Hexadecimal</option></select></label>'
          . '</div><button class="btn primary" data-action="numbase">Convert</button>'
          . '<div class="result-box" id="result">Result</div></div>',

        'jwt' =>
            '<div class="calc-card">'
          . '<label>JWT<textarea id="textInput" rows="6" placeholder="eyJhbGciOi...header.eyJzdWIiOi...payload.signature"></textarea></label>'
          . '<button class="btn primary" data-action="jwt-decode">Decode</button>'
          . '<div class="result-box result-text" id="result"></div>'
          . '<p class="privacy-note">Decoded locally. This does not verify the signature.</p></div>',

        'csv2json' =>
            '<div class="calc-card">'
          . '<label>CSV<textarea id="textInput" rows="10" placeholder="name,age' . "\\n" . 'Alice,30' . "\\n" . 'Bob,25"></textarea></label>'
          . '<label><input id="csvHeader" type="checkbox" checked> First row is header</label>'
          . '<button class="btn primary" data-action="csv2json">Convert</button>'
          . '<div class="result-box result-text" id="result"></div>'
          . '<button class="btn" data-copy-result>Copy</button></div>',

        'rotate' =>
            '<div class="calc-card">'
          . '<label>Select image<input id="imgFile" type="file" accept="image/*"></label>'
          . '<label>Rotation<select id="rotateDeg"><option value="90">90° clockwise</option><option value="180">180°</option><option value="270">270° clockwise</option></select></label>'
          . '<button class="btn primary" data-action="rotate">Rotate &amp; Download</button>'
          . '<div class="result-box" id="result">Choose an image.</div></div>',

        'mfs-cashout' =>
            '<div class="calc-card"><div class="input-grid">'
          . '<label>Amount (৳)<input id="mfsAmount" type="number" inputmode="decimal" placeholder="5000"></label>'
          . '<label>Provider<select id="mfsProvider">'
          .   '<option value="bkash-standard">bKash — Agent (1.85%)</option>'
          .   '<option value="bkash-priyo">bKash — Priyo Agent / ATM (1.49%)</option>'
          .   '<option value="nagad-app">Nagad — App (1.30%)</option>'
          .   '<option value="nagad-ussd">Nagad — USSD (1.50%)</option>'
          .   '<option value="rocket-agent">Rocket — Agent (1.80%)</option>'
          .   '<option value="rocket-atm">Rocket — DBBL ATM (0.90%)</option>'
          .   '<option value="upay-atm">Upay — UCB ATM (0.80%)</option>'
          . '</select></label>'
          . '</div>'
          . '<button class="btn primary" data-action="mfs-cashout">Calculate</button>'
          . '<div class="result-box" id="result">Result will appear here.</div>'
          . '<p class="privacy-note">Rates are approximate and change over time — always confirm with the provider before relying on this for a real transaction.</p></div>',

        'nid-check' =>
            '<div class="calc-card">'
          . '<label>NID number<input id="nidInput" type="text" inputmode="numeric" placeholder="1234567890123"></label>'
          . '<button class="btn primary" data-action="nid-check">Check Format</button>'
          . '<div class="result-box" id="result">Enter a number to check its format.</div>'
          . '<p class="privacy-note">This only checks digit length/format locally — it does not verify the number against the Election Commission database.</p></div>',

        'bd-mobile' =>
            '<div class="calc-card">'
          . '<label>Mobile number<input id="bdMobileInput" type="text" inputmode="tel" placeholder="01712345678 or +8801712345678"></label>'
          . '<button class="btn primary" data-action="bd-mobile">Check Number</button>'
          . '<div class="result-box" id="result">Enter a BD mobile number.</div></div>',

        'bn-digits' =>
            '<div class="calc-card">'
          . '<label>Text with digits<textarea id="textInput" rows="6" placeholder="2026 সালে ১২৩ টাকা"></textarea></label>'
          . '<div class="action-row">'
          .   '<button class="btn primary" data-action="bn-to-en">Bangla → English</button>'
          .   '<button class="btn" data-action="en-to-bn">English → Bangla</button>'
          .   '<button class="btn" data-copy-result>Copy</button>'
          . '</div>'
          . '<div class="result-box result-text" id="result"></div></div>',

        'taka-words' =>
            '<div class="calc-card">'
          . '<label>Amount (৳)<input id="takaAmount" type="number" inputmode="decimal" placeholder="123456"></label>'
          . '<button class="btn primary" data-action="taka-words">Convert to Words</button>'
          . '<div class="result-box result-text" id="result"></div>'
          . '<button class="btn" data-copy-result>Copy</button></div>',

        'bin-etin-check' =>
            '<div class="calc-card">'
          . '<label>Number<input id="binEtinInput" type="text" inputmode="numeric" placeholder="123456789012"></label>'
          . '<button class="btn primary" data-action="bin-etin-check">Check Format</button>'
          . '<div class="result-box" id="result">Enter a 12 or 13-digit number.</div>'
          . '<p class="privacy-note">Format check only — this does not verify the number with the NBR.</p></div>',

        'bd-vat' =>
            '<div class="calc-card"><div class="input-grid">'
          . '<label>Amount (৳)<input id="vatAmount" type="number" inputmode="decimal" placeholder="1000"></label>'
          . '<label>Mode<select id="vatMode"><option value="add">Add 15% VAT to amount</option><option value="extract">Extract 15% VAT already included</option></select></label>'
          . '</div>'
          . '<button class="btn primary" data-action="bd-vat">Calculate</button>'
          . '<div class="result-box" id="result">Result will appear here.</div></div>',

        'fraction' =>
            '<div class="calc-card"><div class="input-grid">'
          . '<label>First numerator<input id="fA" type="number" placeholder="1"></label><label>First denominator<input id="fB" type="number" placeholder="2"></label>'
          . '<label>Second numerator<input id="fC" type="number" placeholder="1"></label><label>Second denominator<input id="fD" type="number" placeholder="4"></label>'
          . '<label>Operation<select id="fOp"><option value="+">Add (+)</option><option value="-">Subtract (−)</option><option value="*">Multiply (×)</option><option value="/">Divide (÷)</option></select></label>'
          . '</div><button class="btn primary" data-action="fraction">Calculate</button><div class="result-box" id="result">Result</div><button class="btn" data-reset>Reset</button></div>',
        'ratio' =>
            '<div class="calc-card"><div class="input-grid"><label>First value<input id="ratioA" type="number" placeholder="2"></label><label>Second value<input id="ratioB" type="number" placeholder="3"></label><label>Third value (optional)<input id="ratioC" type="number" placeholder="10"></label></div><button class="btn primary" data-action="ratio">Calculate Ratio</button><div class="result-box" id="result">Result</div></div>',
        'average' =>
            '<div class="calc-card"><label>Numbers<textarea id="textInput" rows="8" placeholder="10, 20, 30, 40"></textarea></label><button class="btn primary" data-action="average">Calculate Statistics</button><div class="result-box result-text" id="result">Result</div></div>',
        'sales-tax' =>
            '<div class="calc-card"><div class="input-grid"><label>Amount<input id="taxAmount" type="number" placeholder="1000"></label><label>Tax %<input id="taxRate" type="number" placeholder="15"></label><label>Mode<select id="taxMode"><option value="add">Add tax</option><option value="extract">Extract tax included</option></select></label></div><button class="btn primary" data-action="sales-tax">Calculate</button><div class="result-box" id="result">Result</div></div>',
        'date-diff' =>
            '<div class="calc-card"><div class="input-grid"><label>Start date<input id="dateA" type="date"></label><label>End date<input id="dateB" type="date"></label></div><button class="btn primary" data-action="date-diff">Calculate Difference</button><div class="result-box" id="result">Select two dates.</div></div>',
        'emi' =>
            '<div class="calc-card"><div class="input-grid"><label>Loan amount<input id="emiP" type="number" placeholder="500000"></label><label>Annual interest %<input id="emiR" type="number" placeholder="10"></label><label>Tenure (months)<input id="emiN" type="number" placeholder="60"></label></div><button class="btn primary" data-action="emi">Calculate EMI</button><div class="result-box" id="result">Result</div></div>',
        'sort-lines' =>
            '<div class="calc-card"><label>Text<textarea id="textInput" rows="10" placeholder="Banana\nApple\nOrange"></textarea></label><div class="action-row"><button class="btn primary" data-action="sort-lines">A → Z</button><button class="btn" data-action="sort-lines-desc">Z → A</button><button class="btn" data-action="sort-lines-num">Numeric</button></div><label><input id="sortUnique" type="checkbox"> Remove duplicates</label><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'find-replace' =>
            '<div class="calc-card"><label>Text<textarea id="textInput" rows="8"></textarea></label><div class="input-grid"><label>Find<input id="findText"></label><label>Replace with<input id="replaceText"></label></div><label><input id="matchCase" type="checkbox"> Match case</label><button class="btn primary" data-action="find-replace">Replace All</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'whitespace' =>
            '<div class="calc-card"><label>Text<textarea id="textInput" rows="10"></textarea></label><div class="action-row"><button class="btn primary" data-action="whitespace">Clean Whitespace</button><button class="btn" data-action="trim-lines">Trim Lines</button></div><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'remove-breaks' =>
            '<div class="calc-card"><label>Text<textarea id="textInput" rows="10"></textarea></label><button class="btn primary" data-action="remove-breaks">Remove Line Breaks</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'word-frequency' =>
            '<div class="calc-card"><label>Text<textarea id="textInput" rows="10"></textarea></label><button class="btn primary" data-action="word-frequency">Count Words</button><div class="result-box result-text" id="result"></div></div>',
        'lorem' =>
            '<div class="calc-card"><div class="input-grid"><label>Type<select id="loremType"><option value="paragraphs">Paragraphs</option><option value="sentences">Sentences</option><option value="words">Words</option></select></label><label>Amount<input id="loremCount" type="number" min="1" max="50" value="3"></label></div><button class="btn primary" data-action="lorem">Generate</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'image-format' =>
            '<div class="calc-card"><label>Select image<input id="imgFile" type="file" accept="image/*"></label><div class="input-grid"><label>Format<select id="imageOutFormat"><option value="image/png">PNG</option><option value="image/jpeg">JPEG</option><option value="image/webp">WebP</option></select></label><label>Quality<input id="imageOutQuality" type="range" min="0.1" max="1" step="0.05" value="0.9"><output id="imageOutQualityVal">90%</output></label></div><button class="btn primary" data-action="image-format">Convert & Download</button><div class="result-box" id="result">Choose an image.</div></div>',
        'image-dimensions' =>
            '<div class="calc-card"><label>Select image<input id="imgFile" type="file" accept="image/*"></label><button class="btn primary" data-action="image-dimensions">Read Dimensions</button><div class="result-box result-text" id="result">Choose an image.</div></div>',
        'image-dataurl' =>
            '<div class="calc-card"><label>Select image<input id="imgFile" type="file" accept="image/*"></label><button class="btn primary" data-action="image-dataurl">Create Data URL</button><textarea id="resultText" rows="8" readonly></textarea><button class="btn" data-copy-text>Copy</button></div>',
        'regex' =>
            '<div class="calc-card"><div class="input-grid"><label>Regular expression<input id="regexPattern" placeholder="\\b\w+\\b"></label><label>Flags<input id="regexFlags" value="gi"></label></div><label>Test text<textarea id="textInput" rows="8"></textarea></label><button class="btn primary" data-action="regex">Test Regex</button><div class="result-box result-text" id="result">Result</div></div>',
        'color-convert' =>
            '<div class="calc-card"><label>Color value<input id="colorInput" placeholder="#00aaff or rgb(0,170,255) or hsl(200,100%,50%)"></label><button class="btn primary" data-action="color-convert">Convert</button><div class="color-preview" id="colorPreview"></div><div class="result-box result-text" id="result">Result</div><button class="btn" data-copy-result>Copy</button></div>',
        'css-minify' =>
            '<div class="calc-card"><label>CSS<textarea id="textInput" rows="12" placeholder=".box { color: red; margin: 0 10px; }"></textarea></label><button class="btn primary" data-action="css-minify">Minify CSS</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'json2csv' =>
            '<div class="calc-card"><label>JSON array<textarea id="textInput" rows="12" placeholder="[{&quot;name&quot;:&quot;Alice&quot;,&quot;age&quot;:30}]"></textarea></label><button class="btn primary" data-action="json2csv">Convert to CSV</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'query-parser' =>
            '<div class="calc-card"><label>URL or query string<input id="queryInput" placeholder="https://example.com/?name=John&city=Dhaka"></label><button class="btn primary" data-action="query-parser">Parse Query</button><div class="result-box result-text" id="result"></div></div>',
        'speed' => '<div class="calc-card"><div class="input-grid"><label>Value<input id="convValue" type="number" placeholder="100"></label><label>From<select id="convFrom"><option value="kmh">km/h</option><option value="mph">mph</option><option value="ms">m/s</option><option value="knot">knot</option><option value="fts">ft/s</option></select></label><label>To<select id="convTo"><option value="kmh">km/h</option><option value="mph">mph</option><option value="ms">m/s</option><option value="knot">knot</option><option value="fts">ft/s</option></select></label></div><button class="btn primary" data-action="speed">Convert</button><div class="result-box" id="result">Result</div></div>',
        'pressure' => '<div class="calc-card"><div class="input-grid"><label>Value<input id="convValue" type="number" placeholder="1"></label><label>From<select id="convFrom"><option value="Pa">Pa</option><option value="kPa">kPa</option><option value="bar">bar</option><option value="psi">psi</option><option value="atm">atm</option><option value="mmHg">mmHg</option></select></label><label>To<select id="convTo"><option value="Pa">Pa</option><option value="kPa">kPa</option><option value="bar">bar</option><option value="psi">psi</option><option value="atm">atm</option><option value="mmHg">mmHg</option></select></label></div><button class="btn primary" data-action="pressure">Convert</button><div class="result-box" id="result">Result</div></div>',
        'volume' => '<div class="calc-card"><div class="input-grid"><label>Value<input id="convValue" type="number" placeholder="1"></label><label>From<select id="convFrom"><option value="L">Liter</option><option value="mL">Milliliter</option><option value="gal">US gallon</option><option value="qt">US quart</option><option value="cup">US cup</option><option value="m3">m³</option></select></label><label>To<select id="convTo"><option value="L">Liter</option><option value="mL">Milliliter</option><option value="gal">US gallon</option><option value="qt">US quart</option><option value="cup">US cup</option><option value="m3">m³</option></select></label></div><button class="btn primary" data-action="volume">Convert</button><div class="result-box" id="result">Result</div></div>',
        'energy' => '<div class="calc-card"><div class="input-grid"><label>Value<input id="convValue" type="number" placeholder="1"></label><label>From<select id="convFrom"><option value="J">Joule</option><option value="kJ">Kilojoule</option><option value="cal">calorie</option><option value="Wh">Wh</option><option value="kWh">kWh</option></select></label><label>To<select id="convTo"><option value="J">Joule</option><option value="kJ">Kilojoule</option><option value="cal">calorie</option><option value="Wh">Wh</option><option value="kWh">kWh</option></select></label></div><button class="btn primary" data-action="energy">Convert</button><div class="result-box" id="result">Result</div></div>',
        'frequency' => '<div class="calc-card"><div class="input-grid"><label>Value<input id="convValue" type="number" placeholder="1"></label><label>From<select id="convFrom"><option value="Hz">Hz</option><option value="kHz">kHz</option><option value="MHz">MHz</option><option value="GHz">GHz</option></select></label><label>To<select id="convTo"><option value="Hz">Hz</option><option value="kHz">kHz</option><option value="MHz">MHz</option><option value="GHz">GHz</option></select></label></div><button class="btn primary" data-action="frequency">Convert</button><div class="result-box" id="result">Result</div></div>',
        'angle' => '<div class="calc-card"><div class="input-grid"><label>Value<input id="convValue" type="number" placeholder="90"></label><label>From<select id="convFrom"><option value="deg">Degrees</option><option value="rad">Radians</option><option value="grad">Gradians</option><option value="turn">Turns</option></select></label><label>To<select id="convTo"><option value="deg">Degrees</option><option value="rad">Radians</option><option value="grad">Gradians</option><option value="turn">Turns</option></select></label></div><button class="btn primary" data-action="angle">Convert</button><div class="result-box" id="result">Result</div></div>',
        'sha512' => '<div class="calc-card"><label>Text to hash<textarea id="textInput" rows="8"></textarea></label><button class="btn primary" data-action="sha512">Generate SHA-512</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'random-number' => '<div class="calc-card"><div class="input-grid"><label>Minimum<input id="randMin" type="number" value="1"></label><label>Maximum<input id="randMax" type="number" value="100"></label></div><button class="btn primary" data-action="random-number">Generate Secure Number</button><div class="result-box" id="result">Result</div></div>',
        'entropy' => '<div class="calc-card"><label>Password<input id="entropyInput" type="password" autocomplete="off"></label><button class="btn primary" data-action="entropy">Estimate Entropy</button><div class="result-box" id="result">Enter a password.</div></div>',
        'stopwatch' => '<div class="calc-card"><div class="result-box result-text" id="stopwatchDisplay">00:00.000</div><div class="action-row"><button class="btn primary" data-action="stopwatch-start">Start</button><button class="btn" data-action="stopwatch-pause">Pause</button><button class="btn" data-action="stopwatch-reset">Reset</button><button class="btn" data-action="stopwatch-lap">Lap</button></div><div class="result-box result-text" id="stopwatchLaps"></div></div>',
        'countdown' => '<div class="calc-card"><div class="input-grid"><label>Minutes<input id="timerMin" type="number" min="0" value="5"></label><label>Seconds<input id="timerSec" type="number" min="0" max="59" value="0"></label></div><div class="result-box" id="timerDisplay">05:00</div><div class="action-row"><button class="btn primary" data-action="timer-start">Start</button><button class="btn" data-action="timer-pause">Pause</button><button class="btn" data-action="timer-reset">Reset</button></div></div>',
                'tts' => '<div class="calc-card"><label>Text<textarea id="ttsText" rows="9" placeholder="Type something to hear it aloud…">Hello from ToolPWA.</textarea></label><div class="input-grid"><label>Voice<select id="ttsVoice"><option value="">Default browser voice</option></select></label><label>Speed<input id="ttsRate" type="range" min="0.5" max="2" step="0.1" value="1"><output id="ttsRateVal">1×</output></label></div><div class="action-row"><button class="btn primary" data-action="tts-speak">Speak</button><button class="btn" data-action="tts-pause">Pause</button><button class="btn" data-action="tts-resume">Resume</button><button class="btn" data-action="tts-stop">Stop</button></div><div class="result-box" id="result">Uses your browser\'s built-in speech synthesis.</div></div>',
        'stt' => '<div class="calc-card"><label>Language<select id="sttLang"><option value="en-US">English (US)</option><option value="en-GB">English (UK)</option><option value="bn-BD">বাংলা (Bangladesh)</option><option value="hi-IN">Hindi</option></select></label><label>Transcript<textarea id="sttText" rows="10" placeholder="Press Start and speak…"></textarea></label><div class="action-row"><button class="btn primary" data-action="stt-start">Start Listening</button><button class="btn" data-action="stt-stop">Stop</button><button class="btn" data-copy-result>Copy</button></div><div class="result-box" id="result">Browser support varies by device.</div></div>',
        'timezone' => '<div class="calc-card"><div class="input-grid"><label>Date & time<input id="tzDate" type="datetime-local"></label><label>From<select id="tzFrom"><option value="UTC">UTC</option><option value="Asia/Dhaka">Dhaka</option><option value="Asia/Kolkata">Kolkata</option><option value="Asia/Dubai">Dubai</option><option value="Asia/Singapore">Singapore</option><option value="Europe/London">London</option><option value="Europe/Paris">Paris</option><option value="America/New_York">New York</option><option value="America/Los_Angeles">Los Angeles</option><option value="Australia/Sydney">Sydney</option></select></label><label>To<select id="tzTo"><option value="Asia/Dhaka">Dhaka</option><option value="UTC">UTC</option><option value="Asia/Kolkata">Kolkata</option><option value="Asia/Dubai">Dubai</option><option value="Asia/Singapore">Singapore</option><option value="Europe/London">London</option><option value="Europe/Paris">Paris</option><option value="America/New_York">New York</option><option value="America/Los_Angeles">Los Angeles</option><option value="Australia/Sydney">Sydney</option></select></label></div><button class="btn primary" data-action="timezone">Convert Time</button><div class="result-box result-text" id="result">Select a date and time.</div></div>',
        'watermark' => '<div class="calc-card"><label>Select image<input id="imgFile" type="file" accept="image/*"></label><div class="input-grid"><label>Watermark text<input id="wmText" value="ToolPWA" placeholder="Your text"></label><label>Opacity<input id="wmOpacity" type="range" min="0.1" max="1" step="0.05" value="0.45"><output id="wmOpacityVal">45%</output></label></div><button class="btn primary" data-action="watermark">Add Watermark &amp; Download</button><div class="result-box" id="result">Choose an image.</div></div>',
        'favicon' => '<div class="calc-card"><div class="input-grid"><label>Text / Emoji<input id="favText" maxlength="3" value="T" placeholder="T"></label><label>Background<input id="favBg" type="color" value="#10b981"></label><label>Text color<input id="favFg" type="color" value="#071018"></label></div><button class="btn primary" data-action="favicon">Generate Favicon</button><div class="result-box" id="result">Creates a 256×256 PNG favicon.</div></div>',
        'flip-image' => '<div class="calc-card"><label>Select image<input id="imgFile" type="file" accept="image/*"></label><div class="action-row"><button class="btn primary" data-action="flip-h">Flip Horizontal</button><button class="btn" data-action="flip-v">Flip Vertical</button></div><div class="result-box" id="result">Choose an image.</div></div>',
        'markdown-html' => '<div class="calc-card"><label>Markdown<textarea id="textInput" rows="12" placeholder="# Hello

**Bold** and *italic*

- One
- Two"></textarea></label><button class="btn primary" data-action="markdown-html">Convert to HTML</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy HTML</button></div>',
        'morse' => '<div class="calc-card"><label>Text / Morse<textarea id="textInput" rows="9" placeholder="SOS or ... --- ..."></textarea></label><div class="action-row"><button class="btn primary" data-action="text-morse">Text → Morse</button><button class="btn" data-action="morse-text">Morse → Text</button><button class="btn" data-copy-result>Copy</button></div><div class="result-box result-text" id="result"></div></div>',
        'binary-text' => '<div class="calc-card"><label>Text / Binary<textarea id="textInput" rows="9" placeholder="Hello or 01001000 01100101 01101100 01101100 01101111"></textarea></label><div class="action-row"><button class="btn primary" data-action="text-binary">Text → Binary</button><button class="btn" data-action="binary-text">Binary → Text</button><button class="btn" data-copy-result>Copy</button></div><div class="result-box result-text" id="result"></div></div>',
        'email-validator' => '<div class="calc-card"><label>Email address<input id="emailInput" type="email" placeholder="name@example.com"></label><button class="btn primary" data-action="email-validator">Validate Email</button><div class="result-box" id="result">Enter an email address.</div><p class="privacy-note">Syntax check only. No email is sent.</p></div>',
        'html-format' => '<div class="calc-card"><label>HTML<textarea id="textInput" rows="14" placeholder="<div><h1>Hello</h1><p>World</p></div>"></textarea></label><button class="btn primary" data-action="html-format">Format HTML</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'xml-format' => '<div class="calc-card"><label>XML<textarea id="textInput" rows="14" placeholder="<root><item>Hello</item></root>"></textarea></label><button class="btn primary" data-action="xml-format">Format XML</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'json-ts' => '<div class="calc-card"><label>JSON<textarea id="textInput" rows="14" placeholder="{&quot;name&quot;:&quot;Alice&quot;,&quot;age&quot;:30,&quot;active&quot;:true}"></textarea></label><div class="input-grid"><label>Interface name<input id="tsName" value="Root"></label></div><button class="btn primary" data-action="json-ts">Generate TypeScript</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'url-parser' => '<div class="calc-card"><label>URL<input id="urlInput" placeholder="https://example.com:8080/path?a=1#section"></label><button class="btn primary" data-action="url-parser">Parse URL</button><div class="result-box result-text" id="result">Enter a URL.</div></div>',
        'contrast' => '<div class="calc-card"><div class="input-grid"><label>Foreground<input id="fgColor" type="color" value="#111827"></label><label>Background<input id="bgColor" type="color" value="#ffffff"></label></div><button class="btn primary" data-action="contrast">Check Contrast</button><div class="result-box result-text" id="result">Result</div><div id="contrastPreview" class="color-preview"></div></div>',
        'gradient' => '<div class="calc-card"><div class="input-grid"><label>Start<input id="gradA" type="color" value="#10b981"></label><label>End<input id="gradB" type="color" value="#6366f1"></label><label>Angle<input id="gradAngle" type="number" value="135"></label></div><div id="gradientPreview" class="gradient-preview" style="min-height:140px;border-radius:14px;margin:15px 0"></div><button class="btn primary" data-action="gradient">Generate CSS</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy CSS</button></div>',
        'meta-tags' => '<div class="calc-card"><div class="input-grid"><label>Title<input id="metaTitle" maxlength="60" placeholder="My Website"></label><label>Canonical URL<input id="metaUrl" placeholder="https://example.com/"></label></div><label>Description<textarea id="metaDesc" rows="5" maxlength="160" placeholder="A short search-engine description…"></textarea></label><label>Image URL<input id="metaImage" placeholder="https://example.com/og.jpg"></label><button class="btn primary" data-action="meta-tags">Generate Meta Tags</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'robots' => '<div class="calc-card"><div class="input-grid"><label>User-agent<select id="robotsAgent"><option value="*">All bots (*)</option><option value="Googlebot">Googlebot</option><option value="Bingbot">Bingbot</option></select></label><label>Crawl delay<input id="robotsDelay" type="number" min="0" placeholder="Optional"></label></div><label>Disallow paths<textarea id="robotsDisallow" rows="5" placeholder="/admin/
/private/"></textarea></label><label>Sitemap URL<input id="robotsSitemap" placeholder="https://example.com/sitemap.xml"></label><button class="btn primary" data-action="robots">Generate robots.txt</button><div class="result-box result-text" id="result"></div><button class="btn" data-copy-result>Copy</button></div>',
        'text-diff' => '<div class="calc-card"><div class="input-grid"><label>Original<textarea id="diffA" rows="12"></textarea></label><label>Modified<textarea id="diffB" rows="12"></textarea></label></div><button class="btn primary" data-action="text-diff">Compare Lines</button><div class="result-box result-text" id="result"></div></div>',
        'scientific' => '<div class="calc-card"><div class="input-grid"><label>Function<select id="sciOp"><option value="sqrt">√ Square root</option><option value="square">x² Square</option><option value="cube">x³ Cube</option><option value="sin">sin</option><option value="cos">cos</option><option value="tan">tan</option><option value="ln">ln</option><option value="log">log₁₀</option></select></label><label>Value<input id="sciValue" type="number" inputmode="decimal" value="25"></label></div><button class="btn primary" data-action="scientific">Calculate</button><div class="result-box result-text" id="result">Result</div></div>',
        'break-even' => '<div class="calc-card"><div class="input-grid"><label>Fixed costs<input id="beFixed" type="number" value="10000"></label><label>Price per unit<input id="bePrice" type="number" value="100"></label><label>Variable cost per unit<input id="beVariable" type="number" value="60"></label></div><button class="btn primary" data-action="break-even">Calculate Break-Even</button><div class="result-box result-text" id="result">Result</div></div>',
        'unit-price' => '<div class="calc-card"><div class="input-grid"><label>Price A<input id="upPriceA" type="number" value="250"></label><label>Quantity A<input id="upQtyA" type="number" value="5"></label><label>Price B<input id="upPriceB" type="number" value="400"></label><label>Quantity B<input id="upQtyB" type="number" value="10"></label></div><button class="btn primary" data-action="unit-price">Compare Unit Prices</button><div class="result-box result-text" id="result">Result</div></div>',
        'text-stats' => '<div class="calc-card"><label>Text<textarea id="textInput" rows="12" placeholder="Paste or type text here…"></textarea></label><button class="btn primary" data-action="text-stats">Analyze Text</button><div class="result-box result-text" id="result"></div></div>',
        'duplicate-words' => '<div class="calc-card"><label>Text<textarea id="textInput" rows="10" placeholder="This is is a sample sample text."></textarea></label><button class="btn primary" data-action="duplicate-words">Remove Duplicate Words</button><div class="result-box result-text" id="result"></div></div>',
        'image-blur' => '<div class="calc-card"><label>Select image<input id="imgFile" type="file" accept="image/*"></label><label>Blur amount<input id="imgBlur" type="range" min="1" max="20" value="6"><output id="imgBlurVal">6px</output></label><button class="btn primary" data-action="image-blur">Blur &amp; Download</button><div class="result-box" id="result">Choose an image.</div></div>',
        'image-pixelate' => '<div class="calc-card"><label>Select image<input id="imgFile" type="file" accept="image/*"></label><label>Pixel size<input id="pixelSize" type="range" min="4" max="50" value="12"><output id="pixelSizeVal">12px</output></label><button class="btn primary" data-action="image-pixelate">Pixelate &amp; Download</button><div class="result-box" id="result">Choose an image.</div></div>',
        'image-border' => '<div class="calc-card"><label>Select image<input id="imgFile" type="file" accept="image/*"></label><div class="input-grid"><label>Border size<input id="borderSize" type="number" min="1" max="100" value="10"></label><label>Border color<input id="borderColor" type="color" value="#111827"></label></div><button class="btn primary" data-action="image-border">Add Border &amp; Download</button><div class="result-box" id="result">Choose an image.</div></div>',
        'json-validator' => '<div class="calc-card"><label>JSON<textarea id="textInput" rows="14" placeholder="{&quot;name&quot;:&quot;Alice&quot;}"></textarea></label><button class="btn primary" data-action="json-validator">Validate JSON</button><div class="result-box result-text" id="result">Enter JSON to validate.</div></div>',
        'html-minify' => '<div class="calc-card"><label>HTML<textarea id="textInput" rows="14" placeholder="<div>  <h1>Hello</h1> </div>"></textarea></label><button class="btn primary" data-action="html-minify">Minify HTML</button><div class="result-box result-text" id="result"></div></div>',
        'js-format' => '<div class="calc-card"><label>JavaScript<textarea id="textInput" rows="14" placeholder="function hello(){console.log(\"Hi\");}"></textarea></label><button class="btn primary" data-action="js-format">Format JavaScript</button><div class="result-box result-text" id="result"></div></div>',
        'sql-format' => '<div class="calc-card"><label>SQL<textarea id="textInput" rows="12" placeholder="SELECT id,name FROM users WHERE active=1 ORDER BY name;"></textarea></label><button class="btn primary" data-action="sql-format">Format SQL</button><div class="result-box result-text" id="result"></div></div>',
        'sha1' => '<div class="calc-card"><label>Text to hash<textarea id="textInput" rows="8"></textarea></label><button class="btn primary" data-action="sha1">Generate SHA-1</button><div class="result-box result-text" id="result"></div></div>',
        'sha384' => '<div class="calc-card"><label>Text to hash<textarea id="textInput" rows="8"></textarea></label><button class="btn primary" data-action="sha384">Generate SHA-384</button><div class="result-box result-text" id="result"></div></div>',
        default =>
            '<div class="calc-card"><p>Tool interface is ready.</p></div>',

    };
    $safeType = preg_replace('/[^a-z0-9-]+/i', '-', strtolower($type));
    $family = tool_ui_family($type);
    return '<div class="tool-ui tool-ui--' . h($safeType) . ' tool-family--' . h($family) . '" data-tool-type="' . h($safeType) . '" data-tool-family="' . h($family) . '">' . $ui . '</div>';
}
