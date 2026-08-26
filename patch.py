from pathlib import Path
p=Path('/mnt/data/tpwa_upgrade/index.php')
s=p.read_text()
start=s.index('function render_tool(array $c, array $t): void {')
end=s.index('\n// ─── Combined tool suite UI', start)
new=r'''function tool_content_profile(array $t): array {
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
    $graph = ['@context'=>'https://schema.org','@type'=>'WebApplication','name'=>$t['name'],'url'=>$canonical,'applicationCategory'=>'UtilitiesApplication','operatingSystem'=>'Any','description'=>$desc,'offers'=>['@type'=>'Offer','price'=>'0','priceCurrency'=>'USD']];
    $core = core_for_tool($t) ?? ['name'=>$c['name'],'icon'=>$c['icon'],'cats'=>[$c['slug']],'slug'=>$c['slug']];
    $displayCategory = $core['name'];
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
       . '<section class="tool-example"><h2>Example</h2><p>' . h($profile['example']) . '</p></section>';
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
       . '<script type="application/ld+json">' . json_encode($graph, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP) . '</script>';
    page_foot();
}
'''
s=s[:start]+new+s[end:]
p.write_text(s)
