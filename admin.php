<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// Session must be started before headers() sends Cache-Control
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('tpwa_admin');
    session_set_cookie_params([
        'httponly'  => true,
        'secure'    => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'samesite'  => 'Lax',
        // BUG FIX: BASE_PATH may be empty string; cookie path must be '/' in that case
        'path'      => BASE_PATH !== '' ? BASE_PATH : '/',
    ]);
    session_start();
}

headers();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ── Auth helpers ──────────────────────────────────────────────────────────────
function auth_data(): array {
    static $a = null;
    if ($a !== null) return $a;
    $fallback = [
        'username'      => 'admin',
        'password_hash' => password_hash('ChangeMe123!', PASSWORD_DEFAULT),
        'must_change'   => true,
    ];
    if (!is_file(AUTH_FILE)) { write_json(AUTH_FILE, $fallback); return $a = $fallback; }
    $a = read_json(AUTH_FILE, $fallback);
    if (!isset($a['username'], $a['password_hash'])) { $a = $fallback; write_json(AUTH_FILE, $a); }
    return $a;
}

// ── DEV MODE ─────────────────────────────────────────────────────────────────
// Set to false (or delete this line) before going live to re-enable the login
// screen and password check. While true, admin.php skips auth entirely.
const DEV_NO_AUTH = false;

function logged(): bool {
    if (DEV_NO_AUTH) return true;
    return !empty($_SESSION['admin_ok']);
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function check_csrf(): void {
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Invalid CSRF token.');
    }
}

function redirect_admin(string $q = ''): never {
    header('Location: ' . base_url('admin.php') . $q);
    exit;
}

// ── Rate limiting for login ───────────────────────────────────────────────────
function check_rate_limit(): bool {
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $data    = read_json(RATE_FILE, []);
    $key     = hash('sha256', $ip);   // don't store raw IPs
    $entry   = $data[$key] ?? ['count' => 0, 'until' => 0];
    if ($entry['until'] > time()) return false;   // locked out
    return true;
}

function record_failed_login(): void {
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $data  = read_json(RATE_FILE, []);
    $key   = hash('sha256', $ip);
    $entry = $data[$key] ?? ['count' => 0, 'until' => 0];
    $entry['count']++;
    // After 5 failures: lock for 15 minutes
    if ($entry['count'] >= 5) { $entry['until'] = time() + 900; $entry['count'] = 0; }
    $data[$key] = $entry;
    // Prune old entries
    $data = array_filter($data, fn($e) => ($e['until'] ?? 0) > time() || ($e['count'] ?? 0) > 0);
    try { write_json(RATE_FILE, $data); } catch (Throwable) {}
}

function clear_rate_limit(): void {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $data = read_json(RATE_FILE, []);
    $key  = hash('sha256', $ip);
    unset($data[$key]);
    try { write_json(RATE_FILE, $data); } catch (Throwable) {}
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
$auth  = auth_data();
$error = '';

// Logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout' && logged()) {
    check_csrf();
    session_destroy();
    redirect_admin();
}

// Login attempt
if (!logged() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!check_rate_limit()) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } elseif (
        !hash_equals((string) $auth['username'], (string) ($_POST['username'] ?? '')) ||
        !password_verify((string) ($_POST['password'] ?? ''), (string) $auth['password_hash'])
    ) {
        record_failed_login();
        // BUG FIX: same error message for both wrong user and wrong pass (prevent enumeration)
        $error = 'Invalid credentials.';
    } else {
        clear_rate_limit();
        session_regenerate_id(true);
        $_SESSION['admin_ok'] = 1;
        $_SESSION['csrf']     = bin2hex(random_bytes(32));
        redirect_admin();
    }
}

// Login page
if (!logged()) {
    page_head('Admin Login — ToolPWA', 'Admin login', abs_url('admin.php'));
    echo '<main class="container admin-page"><div class="admin-login">'
       . '<div class="logo"><span class="logo-g">Tool</span><span class="logo-accent">PWA</span><span class="logo-dot"></span></div>'
       . '<h1>Admin Login</h1>'
       . '<p>Manage categories, tools, SEO and advertisements.</p>'
       . ($error ? '<div class="error-box">' . h($error) . '</div>' : '')
       . '<form method="post" class="calc-card">'
       .   '<input type="hidden" name="action" value="login">'
       .   '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'
       .   '<label>Username<input name="username" autocomplete="username" required></label>'
       .   '<label>Password<input type="password" name="password" autocomplete="current-password" required></label>'
       .   '<button class="btn primary" type="submit">Sign in</button>'
       . '</form>'
       . '<p class="privacy-note">Default first-install login: admin / ChangeMe123! — change it immediately.</p>'
       . '</div></main>';
    page_foot();
    exit;
}

// POST actions (requires login + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $d = data();
        $a = (string) ($_POST['action'] ?? '');

        // ── Save category ────────────────────────────────────────────────────
        if ($a === 'save_category') {
            $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim((string) ($_POST['slug'] ?? ''))));
            $slug = trim($slug, '-');
            $name = trim((string) ($_POST['name'] ?? ''));
            if (!$slug || !$name) throw new RuntimeException('Category name and slug are required.');
            $found = false;
            foreach ($d['categories'] as &$c) {
                if ($c['slug'] === $slug) {
                    $c['name']        = $name;
                    $c['icon']        = trim((string) ($_POST['icon'] ?? '')) ?: '🧰';
                    $c['description'] = trim((string) ($_POST['description'] ?? ''));
                    $found = true;
                    break;
                }
            }
            unset($c);
            if (!$found) $d['categories'][] = [
                'slug'        => $slug,
                'name'        => $name,
                'icon'        => trim((string) ($_POST['icon'] ?? '')) ?: '🧰',
                'description' => trim((string) ($_POST['description'] ?? '')),
            ];
            save_data($d);
            redirect_admin('?tab=categories&ok=Category+saved');
        }

        // ── Save tool ────────────────────────────────────────────────────────
        if ($a === 'save_tool') {
            $id   = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim((string) ($_POST['slug'] ?? ''))));
            $slug = trim($slug, '-');
            $cat  = (string) ($_POST['cat'] ?? '');
            $type = (string) ($_POST['type'] ?? '');
            if (!$name || !$slug || !cat_by_slug($cat))
                throw new RuntimeException('Valid name, slug and category are required.');
            // Duplicate slug check (exclude current tool if editing)
            foreach ($d['tools'] as $x) {
                if ($x['slug'] === $slug && ($id === 0 || (int) $x['id'] !== $id))
                    throw new RuntimeException('A tool with that slug already exists.');
            }
            // Build FAQ list
            $faqs = [];
            $qs   = $_POST['faq_q'] ?? [];
            $as   = $_POST['faq_a'] ?? [];
            if (is_array($qs)) {
                foreach ($qs as $i => $q) {
                    $q  = trim((string) $q);
                    $aa = trim((string) ($as[$i] ?? ''));
                    if ($q && $aa) $faqs[] = [$q, $aa];
                }
            }
            $item = [
                'id'               => $id ?: random_int(100000, 999999),
                'slug'             => $slug,
                'name'             => $name,
                'icon'             => trim((string) ($_POST['icon'] ?? '')) ?: '🧰',
                'type'             => $type,
                'cat'              => $cat,
                'desc'             => trim((string) ($_POST['desc'] ?? '')),
                'meta_title'       => trim((string) ($_POST['meta_title'] ?? ''))       ?: $name . ' - Free Online Tool | ToolPWA',
                'meta_description' => trim((string) ($_POST['meta_description'] ?? '')) ?: trim((string) ($_POST['desc'] ?? '')),
                'article_html'     => safe_html((string) ($_POST['article_html'] ?? '')),
                'faqs'             => $faqs,
                'active'           => isset($_POST['active'])   ? 1 : 0,
                'featured'         => isset($_POST['featured']) ? 1 : 0,
                'views'            => 0,
                'uses'             => 0,
                'updated_at'       => date('c'),
            ];
            $found = false;
            foreach ($d['tools'] as $i => $x) {
                if ((int) $x['id'] === $id && $id) {
                    // Preserve view/use counts when editing
                    $item['views'] = $x['views'] ?? 0;
                    $item['uses']  = $x['uses']  ?? 0;
                    $d['tools'][$i] = $item;
                    $found = true;
                    break;
                }
            }
            if (!$found) $d['tools'][] = $item;
            save_data($d);
            redirect_admin('?tab=tools&ok=Tool+saved');
        }

        // ── Delete tool ──────────────────────────────────────────────────────
        if ($a === 'delete_tool') {
            $id = (int) ($_POST['id'] ?? 0);
            $d['tools'] = array_values(array_filter($d['tools'], fn($x) => (int) $x['id'] !== $id));
            save_data($d);
            redirect_admin('?tab=tools&ok=Tool+deleted');
        }

        // ── BDIX server directory ───────────────────────────────────────────
        if (in_array($a, ['save_bdix','delete_bdix','toggle_bdix'], true)) {
            $bd = read_json(BDIX_FILE, ['servers'=>[]]); $rows = $bd['servers'] ?? [];
            if ($a === 'delete_bdix') {
                $id=(int)($_POST['id']??0); $rows=array_values(array_filter($rows,fn($x)=>(int)($x['id']??0)!==$id));
                write_json(BDIX_FILE,['servers'=>$rows]); redirect_admin('?tab=bdix&ok=BDIX+server+deleted');
            }
            if ($a === 'toggle_bdix') {
                $id=(int)($_POST['id']??0); foreach($rows as &$r) if((int)($r['id']??0)===$id) $r['active']=empty($r['active'])?1:0; unset($r);
                write_json(BDIX_FILE,['servers'=>$rows]); redirect_admin('?tab=bdix&ok=BDIX+server+status+updated');
            }
            $id=(int)($_POST['id']??0); $name=trim((string)($_POST['name']??'')); $url=trim((string)($_POST['url']??'')); $location=trim((string)($_POST['location']??'')); $isp=trim((string)($_POST['isp']??'')); $tags=trim((string)($_POST['tags']??''));
            if(!$name || !$url) throw new RuntimeException('Server name and URL are required.');
            $u=parse_url($url); if(!$u || !in_array(strtolower($u['scheme']??''),['http','https'],true) || empty($u['host'])) throw new RuntimeException('Use a valid http:// or https:// URL.');
            $item=['id'=>$id?:random_int(100000,999999),'name'=>$name,'url'=>$url,'host'=>$u['host'],'location'=>$location,'isp'=>$isp,'tags'=>$tags,'active'=>isset($_POST['active'])?1:0,'updated_at'=>date('c')];
            $found=false; foreach($rows as $i=>$r){ if((int)($r['id']??0)===$id && $id){$rows[$i]=$item;$found=true;break;} }
            if(!$found)$rows[]=$item; write_json(BDIX_FILE,['servers'=>array_values($rows)]); redirect_admin('?tab=bdix&ok=BDIX+server+saved');
        }

        // ── Bulk import BDIX servers ────────────────────────────────────────
        if ($a === 'bulk_bdix') {
            $raw = (string)($_POST['urls'] ?? '');
            $lines = preg_split('/\R+/', $raw) ?: [];
            $bd = read_json(BDIX_FILE, ['servers'=>[]]); $rows = $bd['servers'] ?? [];
            $existing = [];
            foreach ($rows as $r) $existing[strtolower(rtrim((string)($r['url']??''), '/'))] = true;
            $added = 0; $skipped = 0;
            foreach ($lines as $line) {
                $url = trim($line);
                if ($url === '') continue;
                $u = parse_url($url);
                if (!$u || !in_array(strtolower($u['scheme']??''), ['http','https'], true) || empty($u['host'])) { $skipped++; continue; }
                $key = strtolower(rtrim($url, '/'));
                if (isset($existing[$key])) { $skipped++; continue; }
                $host = (string)$u['host'];
                $name = $host . (!empty($u['port']) ? ':' . $u['port'] : '');
                $path = trim((string)($u['path'] ?? ''), '/');
                if ($path !== '' && $path !== 'index.php' && $path !== 'index.html') $name .= ' / ' . explode('/', $path)[0];
                $low = strtolower($url);
                $category = (str_contains($low,'ftp') || str_contains($low,'file') || str_contains($low,'fs.')) ? 'FTP / File'
                    : ((str_contains($low,'movie') || str_contains($low,'flix') || str_contains($low,'media') || str_contains($low,'cinema')) ? 'Media' : 'BDIX Server');
                $rows[] = ['id'=>random_int(100000,999999999),'name'=>$name,'url'=>$url,'host'=>$host,'location'=>'Bangladesh','isp'=>'','tags'=>'BDIX, Bangladesh, '.$category,'active'=>1,'updated_at'=>date('c')];
                $existing[$key]=true; $added++;
            }
            write_json(BDIX_FILE, ['servers'=>array_values($rows)]);
            redirect_admin('?tab=bdix&ok='.rawurlencode("Imported $added servers; skipped $skipped duplicate/invalid lines"));
        }

        // ── Save ads ─────────────────────────────────────────────────────────
        if ($a === 'save_ads') {
            foreach ($d['ads'] as $slot => $v) {
                $code = (string) ($_POST['ad_code'][$slot] ?? '');
                if (strlen($code) > 20000) throw new RuntimeException('Ad code too large.');
                $d['ads'][$slot] = ['code' => $code, 'active' => isset($_POST['ad_active'][$slot]) ? 1 : 0];
            }
            save_data($d);
            redirect_admin('?tab=ads&ok=Ads+saved');
        }

        // ── Change password ──────────────────────────────────────────────────
        if ($a === 'password') {
            $np = (string) ($_POST['new_password'] ?? '');
            $cp = (string) ($_POST['confirm_password'] ?? '');
            if (strlen($np) < 12 || strlen($np) > 200)
                throw new RuntimeException('Password must be 12–200 characters.');
            if ($np !== $cp)
                throw new RuntimeException('Passwords do not match.');
            $auth['password_hash'] = password_hash($np, PASSWORD_DEFAULT);
            $auth['must_change']   = false;
            write_json(AUTH_FILE, $auth);
            redirect_admin('?tab=settings&ok=Password+changed');
        }

        if ($a === 'logout') { session_destroy(); redirect_admin(); }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Render admin UI ────────────────────────────────────────────────────────────
$d    = data();
$tab  = (string) ($_GET['tab'] ?? 'dashboard');
$edit = null;
if (isset($_GET['edit'])) {
    foreach ($d['tools'] as $t) if ((int) $t['id'] === (int) $_GET['edit']) { $edit = $t; break; }
}

page_head('ToolPWA Admin', 'Admin management', abs_url('admin.php'));

echo '<main class="container admin-page">'
   . '<div class="admin-top">'
   .   '<div><div class="logo"><span class="logo-g">Tool</span><span class="logo-accent">PWA</span><span class="logo-dot"></span></div><h1>ToolPWA Admin</h1></div>'
   .   '<div>'
   .     '<a class="btn" href="' . h(base_url('/')) . '">View Website</a> '
   .     '<form class="inline" method="post">'
   .       '<input type="hidden" name="action" value="logout">'
   .       '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'
   .       '<button class="btn" type="submit">Logout</button>'
   .     '</form>'
   .   '</div>'
   . '</div>'
   . ($error ? '<div class="error-box">' . h($error) . '</div>' : '')
   . (isset($_GET['ok']) ? '<div class="privacy-note">✓ ' . h($_GET['ok']) . '</div>' : '')
   . '<nav class="admin-nav">'
   .   '<a class="btn" href="?tab=dashboard">Dashboard</a>'
   .   '<a class="btn" href="?tab=categories">Categories</a>'
   .   '<a class="btn" href="?tab=tools">Tools</a>'
   .   '<a class="btn" href="?tab=ads">Ads</a>'
   .   '<a class="btn" href="?tab=settings">Settings</a>'
   . '</nav>';

if ($auth['must_change'] ?? false) {
    echo '<div class="error-box">⚠️ You are using the default password. <a href="?tab=settings">Change it now.</a></div>';
}

// ── Dashboard ──────────────────────────────────────────────────────────────────
if ($tab === 'dashboard') {
    $active_tools = count(array_filter($d['tools'], fn($t) => !empty($t['active'])));
    echo '<div class="stats-grid">'
       . '<div class="stat-card"><div class="stat-num">' . count($d['tools'])      . '</div><div class="stat-label">Total Tools</div></div>'
       . '<div class="stat-card"><div class="stat-num">' . $active_tools           . '</div><div class="stat-label">Published</div></div>'
       . '<div class="stat-card"><div class="stat-num">' . count($d['categories']) . '</div><div class="stat-label">Categories</div></div>'
       . '<div class="stat-card"><div class="stat-num">PWA</div><div class="stat-label">Category Apps</div></div>'
       . '</div>'
       . '<div class="admin-panel"><h2>Deployment info</h2>'
       .   '<p>Detected install path: <code>' . h(BASE_PATH !== '' ? BASE_PATH : '/ (document root)') . '</code></p>'
       .   '<p>Data file: <code>' . h(DATA_FILE) . '</code></p>'
       .   '<p>PHP version: <code>' . PHP_VERSION . '</code></p>'
       . '</div>';

// ── Categories ─────────────────────────────────────────────────────────────────
} elseif ($tab === 'categories') {
    echo '<div class="admin-panel"><h2>Add / Edit Category</h2>'
       . '<form method="post" class="admin-grid">'
       .   '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'
       .   '<input type="hidden" name="action" value="save_category">'
       .   '<label>Slug<input name="slug" required placeholder="calculators"></label>'
       .   '<label>Name<input name="name" required placeholder="Calculators"></label>'
       .   '<label>Icon<input name="icon" maxlength="8" placeholder="🧮"></label>'
       .   '<label>Description<input name="description"></label>'
       .   '<div class="full"><button class="btn primary">Save Category</button></div>'
       . '</form>'
       . '<table class="admin-table"><thead><tr><th>Icon</th><th>Category</th><th>Tools</th><th></th></tr></thead><tbody>';
    foreach ($d['categories'] as $c) {
        echo '<tr>'
           . '<td>' . h($c['icon']) . '</td>'
           . '<td><strong>' . h($c['name']) . '</strong><br><small>' . h($c['slug']) . '</small></td>'
           . '<td>' . count(tools_for($c['slug'])) . '</td>'
           . '<td><a href="' . h(base_url($c['slug'] . '/')) . '" target="_blank">Open</a></td>'
           . '</tr>';
    }
    echo '</tbody></table></div>';

// ── Tools list ─────────────────────────────────────────────────────────────────
} elseif ($tab === 'tools') {
    echo '<div class="admin-panel">'
       . '<div class="admin-row"><h2>Tools</h2><a class="btn primary" href="?tab=edit">+ Add Tool</a></div>'
       . '<table class="admin-table"><thead><tr><th>Tool</th><th>Category</th><th>Type</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($d['tools'] as $t) {
        $tc = cat_by_slug($t['cat']);
        echo '<tr>'
           . '<td>' . h($t['icon'] . ' ' . $t['name']) . '</td>'
           . '<td>' . h($tc['name'] ?? $t['cat']) . '</td>'
           . '<td><code>' . h($t['type']) . '</code></td>'
           . '<td>' . (!empty($t['active']) ? '<span style="color:#4ade80">Published</span>' : 'Draft') . '</td>'
           . '<td>'
           .   '<a href="?tab=edit&edit=' . urlencode((string) $t['id']) . '">Edit</a> '
           .   '<form class="inline" method="post" onsubmit="return confirm(\'Delete this tool?\')">'
           .     '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'
           .     '<input type="hidden" name="action" value="delete_tool">'
           .     '<input type="hidden" name="id" value="' . h((string) $t['id']) . '">'
           .     '<button class="danger">Delete</button>'
           .   '</form>'
           . '</td></tr>';
    }
    echo '</tbody></table></div>';

// ── Tool edit form ─────────────────────────────────────────────────────────────
} elseif ($tab === 'edit') {
    $f = $edit ?: [
        'id' => 0, 'name' => '', 'slug' => '', 'icon' => '🧰',
        'type' => 'bmi', 'cat' => 'calculators', 'desc' => '',
        'meta_title' => '', 'meta_description' => '', 'article_html' => '',
        'faqs' => [], 'active' => 1, 'featured' => 0,
    ];
    // All known types for the dropdown
    $all_types = ['percentage','bmi','age','discount','tip','loan','word','character','case','cleaner','line',
                  'reverse','compressor','resizer','image64','colorpicker','grayscale','crop','json','url',
                  'base64','uuid','timestamp','html','length','temperature','data','time','weight','area',
                  'password','random','sha256','strength','uuidsecure','hex'];
    echo '<form method="post">'
       . '<input type="hidden" name="csrf"   value="' . h(csrf()) . '">'
       . '<input type="hidden" name="action" value="save_tool">'
       . '<input type="hidden" name="id"     value="' . h((string) $f['id']) . '">'
       . '<div class="admin-panel"><h2>' . ($f['id'] ? 'Edit' : 'Add') . ' Tool</h2>'
       .   '<div class="admin-grid">'
       .     '<label>Name<input name="name" value="' . h($f['name']) . '" required maxlength="160"></label>'
       .     '<label>Slug<input name="slug" value="' . h($f['slug']) . '" required maxlength="120" pattern="[a-z0-9-]+" title="Lowercase letters, numbers and hyphens only"></label>'
       .     '<label>Category<select name="cat">';
    foreach ($d['categories'] as $c)
        echo '<option value="' . h($c['slug']) . '" ' . ($f['cat'] === $c['slug'] ? 'selected' : '') . '>' . h($c['name']) . '</option>';
    echo       '</select></label>'
       .     '<label>Tool Type<select name="type">';
    foreach ($all_types as $x)
        echo '<option value="' . h($x) . '" ' . ($f['type'] === $x ? 'selected' : '') . '>' . h($x) . '</option>';
    echo       '</select></label>'
       .     '<label>Icon<input name="icon" value="' . h($f['icon']) . '" maxlength="8"></label>'
       .     '<label>Short description<input name="desc" value="' . h($f['desc']) . '" required></label>'
       .     '<label>SEO title<input name="meta_title"       value="' . h($f['meta_title']) . '"></label>'
       .     '<label>SEO description<input name="meta_description" value="' . h($f['meta_description']) . '"></label>'
       .     '<label class="full">Article HTML<textarea name="article_html" rows="12">' . h($f['article_html']) . '</textarea></label>'
       .     '<label>Published  <input type="checkbox" name="active"   ' . ($f['active']   ? 'checked' : '') . '></label>'
       .     '<label>Featured   <input type="checkbox" name="featured" ' . ($f['featured'] ? 'checked' : '') . '></label>'
       .   '</div>'
       . '</div>'
       . '<div class="admin-panel"><h2>FAQs</h2><div id="faqFields">';
    foreach ($f['faqs'] as $faq) {
        echo '<div class="admin-grid">'
           . '<label>Question<input name="faq_q[]" value="' . h($faq[0]) . '"></label>'
           . '<label>Answer<textarea name="faq_a[]">' . h($faq[1]) . '</textarea></label>'
           . '</div>';
    }
    echo   '</div>'
       .   '<button type="button" class="btn" onclick="addFaq()">+ Add FAQ</button>'
       . '</div>'
       . '<button class="btn primary">Save Tool</button>'
       . '</form>';

// ── Ads ────────────────────────────────────────────────────────────────────────
 } elseif ($tab === 'bdix') {
    $bd = read_json(BDIX_FILE, ['servers'=>[]]); $rows=$bd['servers']??[]; $be=null;
    if(isset($_GET['edit_bdix'])) foreach($rows as $r) if((int)($r['id']??0)===(int)$_GET['edit_bdix']){$be=$r;break;}
    $be=$be?:['id'=>0,'name'=>'','url'=>'','location'=>'','isp'=>'','tags'=>'','active'=>1];
    echo '<div class="admin-panel"><h2>'.($be['id']?'Edit':'Add').' BDIX Server</h2><p>Add only URLs you are authorized to publish/check. The public checker only tests registered URLs.</p><form method="post" class="admin-grid">'
       .'<input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="save_bdix"><input type="hidden" name="id" value="'.h((string)$be['id']).'">'
       .'<label>Server name<input name="name" value="'.h($be['name']).'" required placeholder="Example BDIX Server"></label>'
       .'<label>Server URL<input name="url" type="url" value="'.h($be['url']).'" required placeholder="https://example.com"></label>'
       .'<label>Location<input name="location" value="'.h($be['location']).'" placeholder="Dhaka"></label>'
       .'<label>ISP / Network<input name="isp" value="'.h($be['isp']).'" placeholder="ISP name"></label>'
       .'<label class="full">Tags<input name="tags" value="'.h($be['tags']).'" placeholder="movies, ftp, mirror"></label>'
       .'<label>Published <input type="checkbox" name="active" '.(!empty($be['active'])?'checked':'').'></label><div class="full"><button class="btn primary">Save BDIX Server</button> '.($be['id']?'<a class="btn" href="?tab=bdix">Cancel</a>':'').'</div></form></div>'
       .'<div class="admin-panel"><h2>Bulk Add BDIX Servers</h2><p>Paste one HTTP/HTTPS URL per line. Existing URLs are skipped automatically.</p><form method="post"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="bulk_bdix"><textarea name="urls" rows="8" placeholder="https://server.example&#10;http://10.10.10.10"></textarea><br><button class="btn primary">Import Server URLs</button></form></div>'
       .'<div class="admin-panel"><div class="admin-row"><h2>BDIX Server Directory</h2><span>'.count($rows).' entries</span></div><table class="admin-table"><thead><tr><th>Server</th><th>URL</th><th>Location</th><th>ISP</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach($rows as $r){ echo '<tr><td><strong>'.h($r['name']).'</strong><br><small>'.h($r['tags']??'').'</small></td><td><a href="'.h($r['url']).'" target="_blank" rel="noopener">'.h($r['url']).'</a></td><td>'.h($r['location']??'').'</td><td>'.h($r['isp']??'').'</td><td>'.(!empty($r['active'])?'<span style="color:#4ade80">Published</span>':'Draft').'</td><td><a href="?tab=bdix&edit_bdix='.urlencode((string)$r['id']).'">Edit</a> <form class="inline" method="post"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="toggle_bdix"><input type="hidden" name="id" value="'.h((string)$r['id']).'"><button class="btn">'.(!empty($r['active'])?'Hide':'Publish').'</button></form> <form class="inline" method="post" onsubmit="return confirm(\'Delete this BDIX server?\')"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="delete_bdix"><input type="hidden" name="id" value="'.h((string)$r['id']).'"><button class="danger">Delete</button></form></td></tr>'; }
    echo '</tbody></table></div>';

} elseif ($tab === 'ads') {
    echo '<form method="post">'
       . '<input type="hidden" name="csrf"   value="' . h(csrf()) . '">'
       . '<input type="hidden" name="action" value="save_ads">'
       . '<div class="admin-panel"><h2>Advertisements</h2>'
       .   '<p>Only trusted admins should paste ad HTML/script code here.</p>';
    foreach ($d['ads'] as $slot => $ad) {
        echo '<label class="ad-editor">' . h($slot)
           . '<textarea name="ad_code[' . h($slot) . ']" rows="6" maxlength="20000">' . h($ad['code']) . '</textarea>'
           . '<span><input type="checkbox" name="ad_active[' . h($slot) . ']" ' . ($ad['active'] ? 'checked' : '') . '> Active</span>'
           . '</label>';
    }
    echo '</div><button class="btn primary">Save Ads</button></form>';

// ── Settings ────────────────────────────────────────────────────────────────────
} else {
    echo '<div class="admin-panel"><h2>Settings</h2>'
       . '<p>Admin username: <strong>' . h($auth['username']) . '</strong></p>'
       . '<form method="post" class="admin-grid">'
       .   '<input type="hidden" name="csrf"   value="' . h(csrf()) . '">'
       .   '<input type="hidden" name="action" value="password">'
       .   '<label>New password<input type="password" name="new_password"    minlength="12" required autocomplete="new-password"></label>'
       .   '<label>Confirm     <input type="password" name="confirm_password" minlength="12" required autocomplete="new-password"></label>'
       .   '<div class="full"><button class="btn primary">Change Password</button></div>'
       . '</form></div>'
       . '<div class="admin-panel"><h2>Deployment</h2>'
       .   '<p>Detected subfolder: <code>' . h(BASE_PATH !== '' ? BASE_PATH : '/ (document root)') . '</code></p>'
       .   '<p>Data: <code>storage/data.json</code>. Keep the storage folder blocked from public web access.</p>'
       .   '<p>To move to a subdomain, point the subdomain document root at this folder — BASE_PATH auto-detects to empty string.</p>'
       . '</div>';
}

echo '</main>'
   . '<script>function addFaq(){'
   .   'const r=document.createElement("div");'
   .   'r.className="admin-grid";'
   .   'r.innerHTML=\'<label>Question<input name="faq_q[]"></label><label>Answer<textarea name="faq_a[]"></textarea></label>\';'
   .   'document.getElementById("faqFields").appendChild(r);'
   . '}</script>';

page_foot();
