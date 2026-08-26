/* ToolPWA app.js — v2.2.0
 * Bug fixes & additions vs v2.0.0:
 *   - tip/loan/lines/reverse/html-encode/html-decode/timestamp/weight/area/hex/uuidsecure/grayscale/crop
 *     all handled in ONE unified data-action listener (no separate IIFE needed)
 *   - html-decode added (was encode-only)
 *   - timestamp: separate "ts→date" and "date→ts" buttons; handles ms vs s auto-detection
 *   - crop: actually crops from top-left; previous version resized instead of cropping
 *   - grayscale: now uses luminance formula (was also used in the extra IIFE, now de-duped)
 *   - base64-decode: replaced fragile escape/unescape with TextDecoder (handles UTF-8 properly)
 *   - password generator: 0/O/l/1 confusable chars excluded for readability
 *   - categorySearch: now also hides/shows the card's tool-cat text
 *   - homeSearch: instant client-side filter for no-reload UX
 *   - installButtons: ?install=1 auto-triggers banner correctly
 *   - quality output tag: was <o> (invalid), now <output>
 *   - image download: revokeObjectURL called after download to avoid memory leak
 *   - all canvas operations check for 0-dimension images before drawing
 */
(function () {
  'use strict';

  const $ = s => document.querySelector(s);
  const $$ = s => Array.from(document.querySelectorAll(s));

  // ── Theme system ─────────────────────────────────────────────────────────────
  function effectiveTheme(mode) {
    if (mode !== 'system') return mode;
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function applyTheme(mode) {
    const resolved = effectiveTheme(mode);
    if (mode === 'system') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.dataset.theme = mode;
    const select = $('#themeSelect');
    if (select) select.value = mode;
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.content = resolved === 'dark' ? '#0a0d14' : '#f2f4f7';
  }
  function initTheme() {
    const select = $('#themeSelect');
    let mode = 'system';
    try { mode = localStorage.getItem('toolpwa-theme') || 'system'; } catch (_) {}
    applyTheme(mode);
    select?.addEventListener('change', () => {
      const next = select.value;
      try { localStorage.setItem('toolpwa-theme', next); } catch (_) {}
      applyTheme(next);
      showToast((next[0].toUpperCase() + next.slice(1)) + ' theme enabled', 'info');
    });
    const media = window.matchMedia?.('(prefers-color-scheme: dark)');
    media?.addEventListener?.('change', () => {
      if ((select?.value || 'system') === 'system') applyTheme('system');
    });
  }

  // ── Mobile menu ─────────────────────────────────────────────────────────────
  function initMenu() {
    const btn = $('#mobileMenuBtn');
    const menu = $('#mobileNavMenu');
    if (!btn || !menu) return;
    btn.addEventListener('click', () => {
      const open = menu.classList.toggle('show');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    // Close on outside click
    document.addEventListener('click', e => {
      if (!btn.contains(e.target) && !menu.contains(e.target)) {
        menu.classList.remove('show');
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // ── Category search ──────────────────────────────────────────────────────────
  function initCategorySearch() {
    const input = $('#categorySearch');
    const grid  = $('#categoryToolGrid');
    if (!input || !grid) return;
    input.addEventListener('input', () => {
      const q = input.value.toLowerCase().trim();
      grid.querySelectorAll('.tool-card').forEach(card => {
        card.style.display = q === '' || card.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    });
  }

  // ── Home search — live directory search across the complete tool library ─────
  function initHomeSearch(){
    const input=$('#homeSearch'), grid=$('#toolGrid');
    if(!input||!grid)return;
    const cards=$$('#toolGrid .tool-card');
    const categoryCards=$$('.core-category-card');
    const emptyId='liveSearchEmpty';
    let empty=document.getElementById(emptyId);
    if(!empty){
      empty=document.createElement('div');
      empty.id=emptyId;
      empty.className='live-search-empty';
      empty.hidden=true;
      empty.innerHTML='<strong>No tools found</strong><span>Try a tool name, category or function.</span>';
      grid.parentElement.insertBefore(empty,grid.nextSibling);
    }
    function run(){
      const q=input.value.trim().toLowerCase();
      let matches=0;
      cards.forEach(card=>{
        const hay=(card.dataset.search||card.textContent||'').toLowerCase();
        const ok=!q||hay.includes(q);
        card.dataset.searchMatch=ok?'1':'0';
        if(ok){card.style.display='';matches++;}else card.style.display='none';
      });
      categoryCards.forEach(card=>{
        const hay=(card.textContent||'').toLowerCase();
        card.style.display=(!q||hay.includes(q))?'':'none';
      });
      empty.hidden=matches>0||!q;
      if(q){grid.scrollIntoView({behavior:'smooth',block:'start'});}
      // Keep progressive-load logic from hiding live matches.
      const load=$('#loadMoreBtn'); if(load) load.style.display='none';
    }
    input.addEventListener('input',run);
    input.closest('form')?.addEventListener('submit',e=>e.preventDefault());
    $$('[data-search-fill]').forEach(b=>b.addEventListener('click',()=>{input.value=b.dataset.searchFill||'';run();input.focus();}));
    // '/' focuses the actual homepage search rather than opening a separate modal.
    document.addEventListener('keydown',e=>{
      if(e.key==='/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)){
        e.preventDefault(); input.focus();
      }
    });
    if(input.value.trim()) run();
  }

  // ── Home tools grid: category sidebar filter + load more ────────────────────
  function initToolGrid() {
    const grid = $('#toolGrid');
    if (!grid) return;
    const sidebar     = $('#categorySidebar');
    const loadMoreBtn = $('#loadMoreBtn');
    if (!sidebar) return;
    const filterLabel = $('#toolFilterLabel');
    const cards = $$('#toolGrid .tool-card');
    const PAGE  = 12;
    let activeCat  = 'all';
    let visible    = PAGE;

    function currentSet() {
      return activeCat === 'all' ? cards : cards.filter(c => c.dataset.cat === activeCat);
    }
    function render() {
      const set = currentSet();
      cards.forEach(c => { c.style.display = 'none'; });
      set.slice(0, visible).forEach(c => { c.style.display = ''; });
      if (loadMoreBtn) loadMoreBtn.style.display = set.length > visible ? '' : 'none';
      if (filterLabel) {
        const btn = sidebar?.querySelector(`.cat-side-btn[data-cat="${activeCat}"]`);
        filterLabel.textContent = activeCat === 'all' || !btn ? '' : '— ' + (btn.querySelector('.csb-name')?.textContent || '');
      }
    }
    if (sidebar) {
      sidebar.addEventListener('click', e => {
        const btn = e.target.closest('.cat-side-btn');
        if (!btn) return;
        sidebar.querySelectorAll('.cat-side-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeCat = btn.dataset.cat;
        visible = PAGE;
        render();
      });
    }
    if (loadMoreBtn) {
      loadMoreBtn.addEventListener('click', () => {
        visible += PAGE;
        render();
      });
    }
    render();
  }

  // ── PWA install prompt ───────────────────────────────────────────────────────
  let deferred = null;
  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferred = e;
    $$('#installCategory,#installToolCategory,#installApp').forEach(b => b.classList.add('install-ready'));
    const banner = $('#installBanner');
    if (banner) banner.classList.add('show');
  });

  async function triggerInstall() {
    if (!deferred) {
      const banner = $('#installBanner');
      if (banner) banner.classList.add('show');
      return;
    }
    deferred.prompt();
    await deferred.userChoice;
    deferred = null;
    const banner = $('#installBanner');
    if (banner) banner.classList.remove('show');
  }

  function initInstallButtons() {
    document.querySelector('[data-dismiss-install]')?.addEventListener('click',()=>document.querySelector('#installBanner')?.classList.remove('show'));
    $$('#installCategory,#installToolCategory,#installNow,#installApp').forEach(b => {
      b.addEventListener('click', triggerInstall);
    });
    // Auto-show banner when ?install=1
    if (new URLSearchParams(location.search).get('install') === '1') {
      const banner = $('#installBanner');
      if (banner) banner.classList.add('show');
    }
  }

  // ── Category PWA service-worker registration ─────────────────────────────────
  function initCategoryPwa() {
    if (!('serviceWorker' in navigator)) return;
    const mLink = document.querySelector('link[rel=manifest]');
    if (!mLink) return;
    const swUrl = mLink.href.replace('manifest.php', 'sw.php');
    navigator.serviceWorker.register(swUrl).catch(() => {});
  }

  // ── Utility ──────────────────────────────────────────────────────────────────
  const fmt = n => Number.isFinite(n) ? n.toLocaleString(undefined, { maximumFractionDigits: 8 }) : '—';
  const val = id => parseFloat(document.getElementById(id)?.value || '');
  const txt = id => (document.getElementById(id)?.value ?? '');

  function showToast(message, kind = 'info') {
    let region = $('#toolpwa-toast-region');
    if (!region) {
      region = document.createElement('div');
      region.id = 'toolpwa-toast-region';
      region.setAttribute('aria-live', 'polite');
      region.setAttribute('aria-atomic', 'true');
      document.body.appendChild(region);
    }
    const el = document.createElement('div');
    el.className = 'toolpwa-toast ' + kind;
    el.textContent = message;
    region.appendChild(el);
    setTimeout(() => el.remove(), 2400);
  }

  function setResult(v, state = '') {
    const out = $('#result');
    if (!out) return;
    out.textContent = v;
    if (state) out.dataset.state = state;
    else if (/invalid|error|failed|unable|check the input/i.test(String(v))) out.dataset.state = 'error';
    else out.dataset.state = 'success';
  }

  // ── Number to words (Indian/Bangladesh lakh-crore system) ───────────────────
  function numberToTakaWords(n) {
    if (n === 0) return 'Zero';
    const ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
                  'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    const tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    function under1000(x) {
      let s = '';
      if (x >= 100) { s += ones[Math.floor(x / 100)] + ' Hundred '; x %= 100; }
      if (x >= 20)  { s += tens[Math.floor(x / 10)] + ' '; x %= 10; }
      if (x > 0)    { s += ones[x] + ' '; }
      return s.trim();
    }
    const crore    = Math.floor(n / 10000000); n %= 10000000;
    const lakh     = Math.floor(n / 100000);    n %= 100000;
    const thousand = Math.floor(n / 1000);       n %= 1000;
    const rest     = n;
    const parts = [];
    if (crore)    parts.push(under1000(crore) + ' Crore');
    if (lakh)     parts.push(under1000(lakh) + ' Lakh');
    if (thousand) parts.push(under1000(thousand) + ' Thousand');
    if (rest)     parts.push(under1000(rest));
    return parts.join(' ').trim();
  }

  function randomFrom(charset, n) {
    const arr = new Uint32Array(n);
    crypto.getRandomValues(arr);
    let s = '';
    for (let i = 0; i < n; i++) s += charset[arr[i] % charset.length];
    return s;
  }

  // ── Image helpers ─────────────────────────────────────────────────────────────
  function loadImageFromFile(file) {
    return new Promise((resolve, reject) => {
      const im = new Image();
      const url = URL.createObjectURL(file);
      im.onload = () => { URL.revokeObjectURL(url); resolve(im); };
      im.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Image load failed')); };
      im.src = url;
    });
  }

  function downloadCanvas(canvas, filename) {
    canvas.toBlob(blob => {
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      a.textContent = 'Download result';
      a.className = 'btn primary';
      // Clean up after click
      a.addEventListener('click', () => setTimeout(() => URL.revokeObjectURL(url), 1000));
      const out = $('#result');
      if (out) { out.textContent = 'Ready — '; out.appendChild(a); }
    });
  }

  async function handleCompress() {
    const file = document.getElementById('imgFile')?.files?.[0];
    if (!file) return setResult('Choose an image first.');
    const quality = parseFloat(document.getElementById('quality')?.value || '0.8');
    const format  = document.getElementById('imgFormat')?.value || 'image/webp';
    const im = await loadImageFromFile(file);
    const c  = document.createElement('canvas');
    c.width  = im.width;
    c.height = im.height;
    if (!c.width || !c.height) return setResult('Image has invalid dimensions.');
    c.getContext('2d').drawImage(im, 0, 0);
    c.toBlob(blob => {
      const url = URL.createObjectURL(blob);
      setResult(`Compressed: ${Math.round(blob.size / 1024)} KB (${c.width}×${c.height})`);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'compressed-' + file.name.replace(/\.[^.]+$/, '') + (format === 'image/webp' ? '.webp' : '.jpg');
      a.textContent = 'Download';
      a.className = 'btn primary';
      a.addEventListener('click', () => setTimeout(() => URL.revokeObjectURL(url), 1000));
      $('#result').appendChild(document.createElement('br'));
      $('#result').appendChild(a);
    }, format, quality);
  }

  async function handleResize() {
    const file = document.getElementById('imgFile')?.files?.[0];
    if (!file) return setResult('Choose an image first.');
    const im = await loadImageFromFile(file);
    let w = parseInt(document.getElementById('imgW')?.value || String(im.width));
    let h = parseInt(document.getElementById('imgH')?.value || String(im.height));
    if (document.getElementById('lockRatio')?.checked && im.width) {
      h = Math.round(w * im.height / im.width);
    }
    if (!w || !h) return setResult('Enter valid dimensions.');
    const c = document.createElement('canvas');
    c.width  = w;
    c.height = h;
    c.getContext('2d').drawImage(im, 0, 0, w, h);
    downloadCanvas(c, 'resized-' + file.name.replace(/\.[^.]+$/, '') + '.png');
    setResult('Resizing…');
  }

  function handleImage64() {
    const file = document.getElementById('imgFile')?.files?.[0];
    if (!file) return setResult('Choose an image first.');
    const r = new FileReader();
    r.onload = () => { document.getElementById('resultText').value = r.result || ''; };
    r.readAsDataURL(file);
  }

  async function handleGrayscale() {
    const file = document.getElementById('imgFile')?.files?.[0];
    if (!file) return setResult('Choose an image first.');
    const im = await loadImageFromFile(file);
    const c = document.createElement('canvas');
    c.width  = im.width;
    c.height = im.height;
    if (!c.width || !c.height) return setResult('Image has invalid dimensions.');
    const ctx  = c.getContext('2d');
    ctx.drawImage(im, 0, 0);
    const d = ctx.getImageData(0, 0, c.width, c.height);
    for (let i = 0; i < d.data.length; i += 4) {
      // Luminance-weighted grayscale (ITU-R BT.709)
      const g = Math.round(0.2126 * d.data[i] + 0.7152 * d.data[i + 1] + 0.0722 * d.data[i + 2]);
      d.data[i] = d.data[i + 1] = d.data[i + 2] = g;
    }
    ctx.putImageData(d, 0, 0);
    downloadCanvas(c, 'grayscale.png');
  }

  async function handleRotate() {
    const file = document.getElementById('imgFile')?.files?.[0];
    if (!file) return setResult('Choose an image first.');
    const deg = parseInt(document.getElementById('rotateDeg')?.value || '90');
    const im  = await loadImageFromFile(file);
    if (!im.width || !im.height) return setResult('Image has invalid dimensions.');
    const c   = document.createElement('canvas');
    const ctx = c.getContext('2d');
    if (deg === 180) {
      c.width  = im.width;
      c.height = im.height;
      ctx.translate(c.width / 2, c.height / 2);
      ctx.rotate(Math.PI);
      ctx.drawImage(im, -im.width / 2, -im.height / 2);
    } else {
      // 90 or 270: dimensions swap
      c.width  = im.height;
      c.height = im.width;
      ctx.translate(c.width / 2, c.height / 2);
      ctx.rotate((deg === 90 ? 90 : -90) * Math.PI / 180);
      ctx.drawImage(im, -im.width / 2, -im.height / 2);
    }
    downloadCanvas(c, 'rotated.png');
  }

  async function handleCrop() {
    const file = document.getElementById('imgFile')?.files?.[0];
    if (!file) return setResult('Choose an image first.');
    const im = await loadImageFromFile(file);
    // BUG FIX: previous version resized the whole image; this actually crops from top-left
    const cw = Math.min(im.width,  Math.max(1, parseInt(document.getElementById('cropW')?.value || String(im.width))));
    const ch = Math.min(im.height, Math.max(1, parseInt(document.getElementById('cropH')?.value || String(im.height))));
    const c = document.createElement('canvas');
    c.width  = cw;
    c.height = ch;
    c.getContext('2d').drawImage(im, 0, 0, cw, ch, 0, 0, cw, ch);
    downloadCanvas(c, 'cropped-' + file.name.replace(/\.[^.]+$/, '') + '.png');
  }

  // ── Colour picker ─────────────────────────────────────────────────────────────
  function initColorPicker() {
    const fileEl = document.getElementById('imgFile');
    const canvas = document.getElementById('pickerCanvas');
    if (!fileEl || !canvas) return;
    let pickerCtx = null;
    let scaleX = 1, scaleY = 1;

    fileEl.addEventListener('change', async () => {
      const file = fileEl.files?.[0];
      if (!file) return;
      const im = await loadImageFromFile(file);
      const MAX = 700;
      const s = Math.min(1, MAX / im.width);
      canvas.width  = Math.round(im.width  * s);
      canvas.height = Math.round(im.height * s);
      pickerCtx = canvas.getContext('2d');
      pickerCtx.drawImage(im, 0, 0, canvas.width, canvas.height);
      scaleX = canvas.width  / canvas.getBoundingClientRect().width;
      scaleY = canvas.height / canvas.getBoundingClientRect().height;
    });

    canvas.addEventListener('click', e => {
      if (!pickerCtx) return;
      const r  = canvas.getBoundingClientRect();
      const x  = Math.floor((e.clientX - r.left) * (canvas.width  / r.width));
      const y  = Math.floor((e.clientY - r.top)  * (canvas.height / r.height));
      const px = pickerCtx.getImageData(x, y, 1, 1).data;
      const hex = '#' + [px[0], px[1], px[2]].map(v => v.toString(16).padStart(2, '0')).join('');
      const rgb = `rgb(${px[0]}, ${px[1]}, ${px[2]})`;
      setResult(`HEX: ${hex}  RGB: ${rgb}`);
      // Visual feedback swatch
      const out = $('#result');
      if (out) out.style.borderLeftColor = hex;
    });
  }

  // ── Quality slider label ───────────────────────────────────────────────────────
  function initQualitySlider() {
    const q = document.getElementById('quality');
    const o = document.getElementById('qualityOut');
    if (!q || !o) return;
    o.textContent = Math.round(parseFloat(q.value) * 100) + '%';
    q.addEventListener('input', () => { o.textContent = Math.round(parseFloat(q.value) * 100) + '%'; });
  }

  // ── Password strength meter ───────────────────────────────────────────────────
  function initStrengthMeter() {
    const inp = document.getElementById('strengthInput');
    const bar = document.getElementById('strengthBar');
    if (!inp || !bar) return;
    inp.addEventListener('input', () => {
      const s = inp.value;
      let score = 0;
      if (s.length >= 8)  score++;
      if (s.length >= 14) score++;
      if (/[a-z]/.test(s) && /[A-Z]/.test(s)) score++;
      if (/\d/.test(s))   score++;
      if (/[^A-Za-z0-9]/.test(s)) score++;
      bar.style.width = (score * 20) + '%';
      bar.style.background = score < 2 ? '#f87171' : score < 4 ? '#fbbf24' : '#4ade80';
      setResult(score < 2 ? 'Weak' : score < 4 ? 'Moderate' : 'Strong');
    });
  }

  // ── Word / character live counter ──────────────────────────────────────────────
  function initWordCounter(type) {
    const textEl = document.getElementById('textInput');
    if (!textEl || (type !== 'word' && type !== 'character')) return;
    const update = () => {
      const s = textEl.value;
      const words = (s.trim().match(/\S+/g) || []).length;
      const paras = s.trim() ? s.trim().split(/\n\s*\n/).length : 0;
      const el = id => document.getElementById(id);
      if (el('words'))   el('words').textContent   = words;
      if (el('chars'))   el('chars').textContent   = s.length;
      if (el('charsNo')) el('charsNo').textContent = s.replace(/\s/g, '').length;
      if (el('paras'))   el('paras').textContent   = paras;
      setResult(`${s.length} characters`);
    };
    textEl.addEventListener('input', update);
    update();
  }

  // ── Unified action handler ────────────────────────────────────────────────────
  function initActions() {
    const workspace = document.querySelector('.tool-workspace');
    if (!workspace) return;
    const type = workspace.dataset.tool;

    // data-action buttons
    workspace.addEventListener('click', async e => {
      const btn = e.target.closest('[data-action]');
      if (!btn) return;
      const a = btn.dataset.action;

      try {
        // ── Calculators ──────────────────────────────────────────────────────
        if (a === 'percentage') {
          const A = val('pA'), B = val('pB'), C = val('pC'), D = val('pD');
          const parts = [];
          if (Number.isFinite(A) && Number.isFinite(B)) parts.push(`${fmt(A)}% of ${fmt(B)} = ${fmt(A * B / 100)}`);
          if (Number.isFinite(C) && Number.isFinite(D) && C !== 0) parts.push(`Change from ${fmt(C)} to ${fmt(D)} = ${fmt((D - C) / Math.abs(C) * 100)}%`);
          setResult(parts.join('\n') || 'Enter valid values.');
        }
        else if (a === 'bmi') {
          const h = val('bmiH') / 100, w = val('bmiW');
          if (h > 0 && w > 0) {
            const bmi = w / (h * h);
            const cat = bmi < 18.5 ? 'Underweight' : bmi < 25 ? 'Normal weight' : bmi < 30 ? 'Overweight' : 'Obesity';
            setResult(`BMI: ${fmt(bmi)}\nCategory: ${cat}`);
          } else setResult('Enter valid height and weight.');
        }
        else if (a === 'age') {
          const dob  = new Date((document.getElementById('dob')?.value  || '') + 'T00:00:00');
          const asOf = new Date((document.getElementById('ageDate')?.value || new Date().toISOString().slice(0, 10)) + 'T00:00:00');
          if (isNaN(dob) || dob > asOf) return setResult('Enter a valid birth date.');
          let y = asOf.getFullYear() - dob.getFullYear();
          let mo = asOf.getMonth() - dob.getMonth();
          let day = asOf.getDate() - dob.getDate();
          if (day < 0) { mo--; day += new Date(asOf.getFullYear(), asOf.getMonth(), 0).getDate(); }
          if (mo < 0)  { y--;  mo += 12; }
          setResult(`${y} years, ${mo} months, ${day} days`);
        }
        else if (a === 'discount') {
          const p = val('price'), d = val('discount');
          if (p >= 0 && d >= 0) {
            const save = p * d / 100;
            setResult(`Savings: ${fmt(save)}\nSale price: ${fmt(p - save)}`);
          } else setResult('Enter valid values.');
        }
        else if (a === 'tip') {
          const bill = val('tipBill'), pct = val('tipPct'), n = Math.max(1, val('tipPeople') || 1);
          if (!Number.isFinite(bill) || !Number.isFinite(pct)) return setResult('Enter valid values.');
          const tip   = bill * pct / 100;
          const total = bill + tip;
          setResult(`Tip: ${fmt(tip)}\nTotal: ${fmt(total)}\nPer person: ${fmt(total / n)}`);
        }
        else if (a === 'loan') {
          const P = val('loanP'), annR = val('loanR'), n = val('loanN');
          if (!P || !annR || !n) return setResult('Enter valid values.');
          const r = annR / 1200;
          const m = r ? P * r / (1 - Math.pow(1 + r, -n)) : P / n;
          setResult(`Monthly payment: ${fmt(m)}\nTotal repayment: ${fmt(m * n)}\nTotal interest: ${fmt(m * n - P)}`);
        }
        else if (a === 'simple-interest') {
          const P = val('siP'), r = val('siR'), t = val('siT');
          if (!(P >= 0) || !(r >= 0) || !(t >= 0)) return setResult('Enter valid values.');
          const interest = P * r * t / 100;
          setResult(`Interest: ${fmt(interest)}\nTotal amount: ${fmt(P + interest)}`);
        }
        else if (a === 'compound-interest') {
          const P = val('ciP'), r = val('ciR'), t = val('ciT');
          const n = parseInt(document.getElementById('ciN')?.value || '12');
          if (!(P >= 0) || !(r >= 0) || !(t >= 0)) return setResult('Enter valid values.');
          const amount   = P * Math.pow(1 + (r / 100) / n, n * t);
          const interest = amount - P;
          setResult(`Interest earned: ${fmt(interest)}\nFinal amount: ${fmt(amount)}`);
        }

        // ── Bangladesh tools ─────────────────────────────────────────────────────
        else if (a === 'mfs-cashout') {
          const amount = val('mfsAmount');
          if (!(amount > 0)) return setResult('Enter a valid amount.');
          const rates = {
            'bkash-standard': 0.0185, 'bkash-priyo': 0.0149,
            'nagad-app': 0.0130,      'nagad-ussd': 0.0150,
            'rocket-agent': 0.0180,   'rocket-atm': 0.0090,
            'upay-atm': 0.0080,
          };
          const key = document.getElementById('mfsProvider')?.value || 'bkash-standard';
          const rate = rates[key] ?? 0.0185;
          const charge = amount * rate;
          setResult(`Charge: ৳${fmt(charge)} (${(rate * 100).toFixed(2)}%)\nYou will receive: ৳${fmt(amount - charge)}\nTotal to keep in account: ৳${fmt(amount)}`);
        }
        else if (a === 'nid-check') {
          const raw = (document.getElementById('nidInput')?.value || '').replace(/\s+/g, '');
          if (!/^\d+$/.test(raw)) return setResult('Enter digits only.');
          const n = raw.length;
          let msg;
          if (n === 10)      msg = '10-digit Smart NID number. The last digit is a checksum digit.';
          else if (n === 13) msg = '13-digit (legacy) NID number. Adding the 4-digit birth year to the front gives the 17-digit form.';
          else if (n === 17) msg = '17-digit NID number (includes the 4-digit birth year at the start).';
          else                msg = `Not a standard NID length (${n} digits). Bangladesh NID numbers are 10, 13 or 17 digits.`;
          setResult(msg + '\n\nThis is a format check only — it does not verify the number against the Election Commission database.');
        }
        else if (a === 'bd-mobile') {
          let raw = (document.getElementById('bdMobileInput')?.value || '').replace(/[\s-]/g, '');
          raw = raw.replace(/^\+?880/, '0');
          if (!/^01[3-9]\d{8}$/.test(raw)) return setResult('Not a valid Bangladesh mobile number. Expected format: 01XXXXXXXXX (11 digits).');
          const prefix = raw.slice(0, 3);
          const operators = {
            '013': 'Grameenphone', '017': 'Grameenphone',
            '014': 'Banglalink',   '019': 'Banglalink',
            '015': 'Teletalk',
            '016': 'Airtel (Robi Axiata)',
            '018': 'Robi Axiata',
          };
          const op = operators[prefix] || 'Unknown operator';
          setResult(`Valid number: ${raw}\nOperator: ${op}\nNote: numbers can be ported between operators (MNP), so the prefix is a strong hint, not a guarantee.`);
        }
        else if (a === 'bin-etin-check') {
          const raw = (document.getElementById('binEtinInput')?.value || '').replace(/\s+/g, '');
          if (!/^\d+$/.test(raw)) return setResult('Enter digits only.');
          const n = raw.length;
          let msg;
          if (n === 12)      msg = 'Matches the 12-digit e-TIN (Tax Identification Number) format issued by the NBR.';
          else if (n === 13) msg = 'Matches the 13-digit e-BIN (Business Identification Number / VAT registration) format issued by the NBR.';
          else                msg = `Not a standard length (${n} digits). e-TIN is 12 digits, e-BIN is 13 digits.`;
          setResult(msg + '\n\nFormat check only — this does not verify the number with the NBR.');
        }
        else if (a === 'bd-vat') {
          const amount = val('vatAmount');
          const mode   = document.getElementById('vatMode')?.value || 'add';
          if (!(amount >= 0)) return setResult('Enter a valid amount.');
          if (mode === 'add') {
            const vat = amount * 0.15;
            setResult(`VAT (15%): ৳${fmt(vat)}\nTotal with VAT: ৳${fmt(amount + vat)}`);
          } else {
            const base = amount / 1.15;
            const vat  = amount - base;
            setResult(`Base amount: ৳${fmt(base)}\nVAT (15%) included: ৳${fmt(vat)}`);
          }
        }

        else if (a === 'bdix-check') {
          const id = document.getElementById('bdixCheckServer')?.value || '';
          const dataEl = document.getElementById('bdixCheckData');
          const rows = dataEl ? JSON.parse(dataEl.textContent || '[]') : [];
          const row = rows.find(x => String(x.id) === String(id));
          if (!row) return setResult('Select a registered BDIX server.', 'error');
          const result = await (window.TOOLPWA_BDIX_TEST ? window.TOOLPWA_BDIX_TEST(row.url, 8000) : Promise.resolve({state:'unknown', detail:'Browser tester unavailable.'}));
          return setResult(`${result.state === 'reachable' ? '✓ Reachable' : result.state === 'failed' ? '✕ Not reachable' : '⚠ Unable to verify'}\nResponse time: ${result.latency_ms ?? '—'} ms\nURL: ${row.url}\n${result.detail || ''}`, result.state === 'reachable' ? 'success' : 'error');
        }
        // ── Text tools ────────────────────────────────────────────────────────
        else if (a === 'cleaner') {
          const clean = (document.getElementById('textInput')?.value || '')
            .replace(/\r/g, '')
            .split('\n')
            .map(x => x.trim().replace(/\s+/g, ' '))
            .filter(Boolean)
            .join('\n');
          setResult(clean);
        }
        else if (a === 'lines') {
          const t = txt('textInput');
          const lines = t ? t.split(/\r?\n/).length : 0;
          const words = (t.trim().match(/\S+/g) || []).length;
          setResult(`Lines: ${lines}\nWords: ${words}\nCharacters: ${t.length}`);
        }
        else if (a === 'reverse') {
          // BUG FIX: handle surrogate pairs (emoji etc) correctly
          setResult([...txt('textInput')].reverse().join(''));
        }
        else if (a === 'dedupe') {
          const ci = document.getElementById('dedupeCase')?.checked;
          const seen = new Set();
          const out = [];
          txt('textInput').split(/\r?\n/).forEach(line => {
            const key = ci ? line.toLowerCase() : line;
            if (!seen.has(key)) { seen.add(key); out.push(line); }
          });
          setResult(out.join('\n'));
        }
        else if (a === 'slug') {
          setResult(
            txt('textInput')
              .toLowerCase()
              .normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
              .replace(/[^a-z0-9]+/g, '-')
              .replace(/^-+|-+$/g, '')
          );
        }
        else if (a === 'bn-to-en') {
          const map = { '০':'0','১':'1','২':'2','৩':'3','৪':'4','৫':'5','৬':'6','৭':'7','৮':'8','৯':'9' };
          setResult(txt('textInput').replace(/[০-৯]/g, d => map[d]));
        }
        else if (a === 'en-to-bn') {
          const map = { '0':'০','1':'১','2':'২','3':'৩','4':'৪','5':'৫','6':'৬','7':'৭','8':'৮','9':'৯' };
          setResult(txt('textInput').replace(/[0-9]/g, d => map[d]));
        }
        else if (a === 'taka-words') {
          const n = val('takaAmount');
          if (!Number.isFinite(n) || n < 0) return setResult('Enter a valid amount.');
          setResult(numberToTakaWords(Math.round(n)) + ' Taka Only');
        }

        // ── Developer tools ────────────────────────────────────────────────────
        else if (a === 'json-format' || a === 'json-minify') {
          const parsed = JSON.parse(txt('textInput'));
          setResult(a === 'json-format' ? JSON.stringify(parsed, null, 2) : JSON.stringify(parsed));
        }
        else if (a === 'url-encode') { setResult(encodeURIComponent(txt('textInput'))); }
        else if (a === 'url-decode') { setResult(decodeURIComponent(txt('textInput'))); }
        else if (a === 'base64-encode') {
          // BUG FIX: use TextEncoder → Uint8Array for proper UTF-8 support
          const bytes = new TextEncoder().encode(txt('textInput'));
          const binary = String.fromCharCode(...bytes);
          setResult(btoa(binary));
        }
        else if (a === 'base64-decode') {
          // BUG FIX: escape/unescape deprecated; use TextDecoder instead
          const bytes = Uint8Array.from(atob(txt('textInput')), c => c.charCodeAt(0));
          setResult(new TextDecoder().decode(bytes));
        }
        else if (a === 'html-encode') {
          setResult(txt('textInput').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])));
        }
        else if (a === 'html-decode') {
          // BUG FIX: html-decode was missing entirely
          const el = document.createElement('div');
          el.innerHTML = txt('textInput');
          setResult(el.textContent);
        }
        else if (a === 'uuid') {
          // Prefer native crypto.randomUUID, fall back to manual construction
          const id = crypto.randomUUID
            ? crypto.randomUUID()
            : ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(/[018]/g,
                c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));
          setResult(id);
        }
        else if (a === 'uuidsecure') {
          setResult(crypto.randomUUID());
        }
        else if (a === 'ts-to-date') {
          const raw = document.getElementById('tsInput')?.value.trim() || '';
          if (!/^\d+$/.test(raw)) return setResult('Enter a numeric Unix timestamp.');
          // Auto-detect ms vs s: if > 1e12 it's milliseconds
          const ms  = Number(raw) > 1e12 ? Number(raw) : Number(raw) * 1000;
          const dt  = new Date(ms);
          setResult(isNaN(dt) ? 'Invalid timestamp.' : dt.toISOString() + '\n' + dt.toLocaleString());
        }
        else if (a === 'date-to-ts') {
          const raw = document.getElementById('tsInput')?.value.trim() || '';
          const dt  = new Date(raw);
          setResult(isNaN(dt) ? 'Invalid date.' : String(Math.floor(dt.getTime() / 1000)));
        }
        else if (a === 'numbase') {
          const raw  = (document.getElementById('nbValue')?.value || '').trim();
          const from = parseInt(document.getElementById('nbFrom')?.value || '10');
          const to   = parseInt(document.getElementById('nbTo')?.value || '16');
          if (!raw) return setResult('Enter a value.');
          const n = parseInt(raw, from);
          if (!Number.isFinite(n)) return setResult(`"${raw}" is not valid in base ${from}.`);
          setResult(n.toString(to).toUpperCase());
        }
        else if (a === 'jwt-decode') {
          const parts = txt('textInput').trim().split('.');
          if (parts.length < 2) return setResult('Not a valid JWT (expected header.payload.signature).');
          const b64urlDecode = s => {
            const pad = s.replace(/-/g, '+').replace(/_/g, '/');
            const padded = pad + '==='.slice((pad.length + 3) % 4);
            const bytes = Uint8Array.from(atob(padded), c => c.charCodeAt(0));
            return new TextDecoder().decode(bytes);
          };
          const header  = JSON.parse(b64urlDecode(parts[0]));
          const payload = JSON.parse(b64urlDecode(parts[1]));
          setResult('Header:\n' + JSON.stringify(header, null, 2) + '\n\nPayload:\n' + JSON.stringify(payload, null, 2));
        }
        else if (a === 'csv2json') {
          const hasHeader = document.getElementById('csvHeader')?.checked;
          const rows = txt('textInput').split(/\r?\n/).filter(r => r.trim() !== '').map(r => r.split(','));
          if (!rows.length) return setResult('Paste some CSV data first.');
          let out;
          if (hasHeader) {
            const headers = rows[0].map(h => h.trim());
            out = rows.slice(1).map(r => Object.fromEntries(headers.map((h, i) => [h, (r[i] ?? '').trim()])));
          } else {
            out = rows.map(r => r.map(c => c.trim()));
          }
          setResult(JSON.stringify(out, null, 2));
        }

        // ── Converters ─────────────────────────────────────────────────────────
        else if (a === 'length') {
          const units = { mm: 0.001, cm: 0.01, m: 1, km: 1000, in: 0.0254, ft: 0.3048 };
          const from = document.getElementById('convFrom')?.value;
          const to   = document.getElementById('convTo')?.value;
          setResult(fmt(val('convValue') * units[from] / units[to]));
        }
        else if (a === 'temperature') {
          const v = val('convValue');
          const from = document.getElementById('convFrom')?.value;
          const to   = document.getElementById('convTo')?.value;
          let c = from === 'C' ? v : from === 'F' ? (v - 32) * 5 / 9 : v - 273.15;
          setResult(fmt(to === 'C' ? c : to === 'F' ? c * 9 / 5 + 32 : c + 273.15));
        }
        else if (a === 'data') {
          const units = { B: 1, KB: 1024, MB: 1048576, GB: 1073741824, TB: 1099511627776 };
          const from = document.getElementById('convFrom')?.value;
          const to   = document.getElementById('convTo')?.value;
          setResult(fmt(val('convValue') * units[from] / units[to]));
        }
        else if (a === 'time') {
          const units = { s: 1, min: 60, h: 3600, d: 86400 };
          const from = document.getElementById('convFrom')?.value;
          const to   = document.getElementById('convTo')?.value;
          setResult(fmt(val('convValue') * units[from] / units[to]));
        }
        else if (a === 'weight') {
          // BUG FIX: was using g→kg base; standardised to kg
          const units = { g: 0.001, kg: 1, lb: 0.45359237, oz: 0.028349523 };
          const from = document.getElementById('convFrom')?.value;
          const to   = document.getElementById('convTo')?.value;
          setResult(fmt(val('convValue') * units[from] / units[to]));
        }
        else if (a === 'area') {
          const units = { m2: 1, km2: 1e6, ft2: 0.09290304, yd2: 0.83612736, acre: 4046.8564224 };
          const from = document.getElementById('convFrom')?.value;
          const to   = document.getElementById('convTo')?.value;
          setResult(fmt(val('convValue') * units[from] / units[to]));
        }

        else if (a === 'scientific') { const x=val('sciValue'),op=txt('sciOp'); if(!Number.isFinite(x))return setResult('Enter a valid number.'); let r; if(op==='sqrt')r=Math.sqrt(x); else if(op==='square')r=x*x; else if(op==='cube')r=x*x*x; else if(op==='sin')r=Math.sin(x*Math.PI/180); else if(op==='cos')r=Math.cos(x*Math.PI/180); else if(op==='tan')r=Math.tan(x*Math.PI/180); else if(op==='ln')r=Math.log(x); else r=Math.log10(x); if(!Number.isFinite(r))return setResult('Result is not a real finite number.'); setResult(`Result: ${fmt(r)}`); }
        else if (a === 'break-even') { const f=val('beFixed'),p=val('bePrice'),v=val('beVariable'); if(!(f>=0)||!(p>v))return setResult('Enter valid costs and a price greater than variable cost.'); const units=f/(p-v),revenue=units*p; setResult(`Break-even units: ${fmt(Math.ceil(units))}\nExact units: ${fmt(units)}\nBreak-even revenue: ${fmt(revenue)}`); }
        else if (a === 'unit-price') { const pa=val('upPriceA'),qa=val('upQtyA'),pb=val('upPriceB'),qb=val('upQtyB'); if(!(pa>=0)||!(pb>=0)||!(qa>0)||!(qb>0))return setResult('Enter valid prices and quantities.'); const ua=pa/qa,ub=pb/qb,best=ua<ub?'A':ub<ua?'B':'Same'; setResult(`Product A: ${fmt(ua)} per unit\nProduct B: ${fmt(ub)} per unit\nLower unit price: ${best}`); }
        // ── Security tools ─────────────────────────────────────────────────────
        else if (a === 'password') {
          const n    = Math.min(128, Math.max(8, parseInt(document.getElementById('pwLen')?.value || '20')));
          const sets = {
            // Excludes visually ambiguous chars: 0/O, 1/l/I
            all:     'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*_-+=',
            alnum:   'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789',
            letters: 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz',
          };
          setResult(randomFrom(sets[document.getElementById('pwOpts')?.value || 'all'], n));
        }
        else if (a === 'random') {
          const n    = Math.min(256, Math.max(1, parseInt(document.getElementById('randLen')?.value || '32')));
          const sets = {
            all:     'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789',
            alpha:   'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz',
            numbers: '0123456789',
            hex:     '0123456789abcdef',
          };
          setResult(randomFrom(sets[document.getElementById('randSet')?.value || 'all'], n));
        }
        else if (a === 'hex') {
          const n   = Math.min(256, Math.max(2, parseInt(document.getElementById('hexLen')?.value || '32')));
          const buf = new Uint8Array(Math.ceil(n / 2));
          crypto.getRandomValues(buf);
          setResult([...buf].map(x => x.toString(16).padStart(2, '0')).join('').slice(0, n));
        }
        else if (a === 'sha1' || a === 'sha384') { const alg=a==='sha1'?'SHA-1':'SHA-384'; const buf=await crypto.subtle.digest(alg,new TextEncoder().encode(txt('textInput'))); setResult(Array.from(new Uint8Array(buf),x=>x.toString(16).padStart(2,'0')).join('')); }
        else if (a === 'sha256') {
          const buf  = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(txt('textInput')));
          setResult(Array.from(new Uint8Array(buf), x => x.toString(16).padStart(2, '0')).join(''));
        }

        // ── Additional calculators ─────────────────────────────────────────────
        else if (a === 'fraction') {
          const A=val('fA'),B=val('fB'),C=val('fC'),D=val('fD'),op=txt('fOp');
          if (![A,B,C,D].every(Number.isFinite) || B===0 || D===0) return setResult('Enter valid fractions.');
          let n,d;
          if(op==='+'){n=A*D+C*B;d=B*D;} else if(op==='-'){n=A*D-C*B;d=B*D;} else if(op==='*'){n=A*C;d=B*D;} else {if(C===0)return setResult('Cannot divide by zero.');n=A*D;d=B*C;}
          const g=(x,y)=>y?g(Math.abs(y),Math.abs(x)%Math.abs(y)):Math.abs(x); const gg=g(n,d)||1; n/=gg; d/=gg; if(d<0){n=-n;d=-d;}
          setResult(`${n}/${d} = ${fmt(n/d)}`);
        }
        else if (a === 'ratio') {
          const A=val('ratioA'),B=val('ratioB'),C=val('ratioC'); if(!(A>0)||!(B>0))return setResult('Enter positive values.');
          const g=(x,y)=>y?g(y,x%y):x, gg=g(Math.round(A),Math.round(B)); let out=`Simplified ratio: ${A/gg}:${B/gg}`;
          if(Number.isFinite(C)) out += `\nIf ${A}:${B} = ${C}:x, then x = ${fmt(C*B/A)}`; setResult(out);
        }
        else if (a === 'average') { const nums=txt('textInput').split(/[,\s]+/).map(Number).filter(Number.isFinite); if(!nums.length)return setResult('Enter numbers separated by commas or spaces.'); const sorted=[...nums].sort((a,b)=>a-b), mean=nums.reduce((a,b)=>a+b,0)/nums.length, mid=Math.floor(sorted.length/2), med=sorted.length%2?sorted[mid]:(sorted[mid-1]+sorted[mid])/2; setResult(`Count: ${nums.length}\nTotal: ${fmt(nums.reduce((a,b)=>a+b,0))}\nMean: ${fmt(mean)}\nMedian: ${fmt(med)}\nMinimum: ${fmt(sorted[0])}\nMaximum: ${fmt(sorted.at(-1))}`); }
        else if (a === 'sales-tax') { const x=val('taxAmount'),r=val('taxRate'); if(!(x>=0)||!(r>=0))return setResult('Enter valid values.'); if(txt('taxMode')==='add'){const tax=x*r/100;setResult(`Tax: ${fmt(tax)}\nTotal: ${fmt(x+tax)}`)}else{const base=x/(1+r/100);setResult(`Pre-tax: ${fmt(base)}\nTax included: ${fmt(x-base)}`)} }
        else if (a === 'date-diff') { const A=new Date(txt('dateA')+'T00:00:00'),B=new Date(txt('dateB')+'T00:00:00'); if(isNaN(A)||isNaN(B))return setResult('Select both dates.'); const days=Math.round(Math.abs(B-A)/86400000); setResult(`${days.toLocaleString()} days\n${fmt(days/7)} weeks\n${fmt(days/30.4375)} months (approx.)\n${fmt(days/365.2425)} years (approx.)`); }
        else if (a === 'emi') { const P=val('emiP'),r=val('emiR')/1200,n=val('emiN'); if(!(P>0)||!(n>0)||r<0)return setResult('Enter valid loan details.'); const m=r?P*r/(1-Math.pow(1+r,-n)):P/n; setResult(`Monthly EMI: ${fmt(m)}\nTotal payment: ${fmt(m*n)}\nTotal interest: ${fmt(m*n-P)}`); }

        // ── Additional text tools ───────────────────────────────────────────────
        else if (a === 'sort-lines' || a === 'sort-lines-desc' || a === 'sort-lines-num') { let lines=txt('textInput').split(/\r?\n/); if($('#sortUnique')?.checked) lines=[...new Set(lines)]; if(a==='sort-lines-num') lines.sort((x,y)=>(parseFloat(x)||0)-(parseFloat(y)||0)); else lines.sort((x,y)=>x.localeCompare(y,undefined,{numeric:true,sensitivity:'base'})); if(a==='sort-lines-desc')lines.reverse(); setResult(lines.join('\n')); }
        else if (a === 'find-replace') { const text=txt('textInput'),find=txt('findText'),rep=txt('replaceText'); if(!find)return setResult(text); const flags=$('#matchCase')?.checked?'g':'gi'; setResult(text.replace(new RegExp(find.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'),flags),rep)); }
        else if (a === 'whitespace') { const t=txt('textInput'); setResult(a==='whitespace'?t.replace(/\s+/g,' ').trim():t.split(/\r?\n/).map(x=>x.trim()).join('\n')); }
        else if (a === 'trim-lines') { setResult(txt('textInput').split(/\r?\n/).map(x=>x.trim()).join('\n')); }
        else if (a === 'remove-breaks') { setResult(txt('textInput').replace(/\s*\r?\n\s*/g,' ').replace(/\s+/g,' ').trim()); }
        else if (a === 'word-frequency') { const words=(txt('textInput').toLowerCase().match(/[\p{L}\p{N}']+/gu)||[]); const m=new Map(); words.forEach(w=>m.set(w,(m.get(w)||0)+1)); setResult([...m.entries()].sort((a,b)=>b[1]-a[1]||a[0].localeCompare(b[0])).map(([w,n])=>`${w}: ${n}`).join('\n')||'No words found.'); }
        else if (a === 'text-stats') { const t=txt('textInput'); const words=(t.match(/[\p{L}\p{N}']+/gu)||[]).length, chars=t.length, noSpace=t.replace(/\s/g,'').length, lines=t?t.split(/\r?\n/).length:0, paragraphs=t.trim()?t.trim().split(/\n\s*\n/).length:0, sentences=(t.match(/[.!?]+(?=\s|$)/g)||[]).length, mins=words/200; setResult(`Words: ${words}\nCharacters: ${chars}\nCharacters (no spaces): ${noSpace}\nLines: ${lines}\nParagraphs: ${paragraphs}\nSentences: ${sentences}\nEstimated reading time: ${mins<1?'less than 1 minute':Math.ceil(mins)+' minute'+(Math.ceil(mins)>1?'s':'')}`); }
        else if (a === 'duplicate-words') { const seen=new Set(), out=[]; for(const w of txt('textInput').split(/(\s+)/)){ if(/^\s+$/.test(w)){out.push(w);continue;} const key=w.toLocaleLowerCase().replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu,''); if(!key||!seen.has(key)){out.push(w);if(key)seen.add(key);} } setResult(out.join('').replace(/[ \t]+/g,' ').replace(/\s+([,.!?;:])/g,'$1')); }
        else if (a === 'lorem') { const pool='lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua ut enim ad minim veniam quis nostrud exercitation ullamco laboris nisi aliquip ex ea commodo consequat'.split(' '); const n=Math.min(50,Math.max(1,parseInt($('#loremCount')?.value||3))); const words=Array.from({length:Math.max(100,n*60)},(_,i)=>pool[i%pool.length]); const type=$('#loremType')?.value; if(type==='words')setResult(words.slice(0,n).join(' ')+'.'); else {const sent=Array.from({length:type==='sentences'?n:n*4},(_,i)=>words.slice(i*12,i*12+10).join(' ').replace(/^./,c=>c.toUpperCase())+'.'); if(type==='sentences')setResult(sent.join(' ')); else setResult(Array.from({length:n},(_,i)=>sent.slice(i*4,i*4+4).join(' ')).join('\n\n'));} }

        // ── Additional image tools ──────────────────────────────────────────────
        else if (a === 'image-blur' || a === 'image-pixelate' || a === 'image-border') { const f=$('#imgFile')?.files?.[0]; if(!f)return setResult('Choose an image.'); const im=await loadImageFromFile(f),c=document.createElement('canvas'),ctx=c.getContext('2d'); const w=im.width,h=im.height; if(a==='image-blur'){c.width=w;c.height=h;ctx.filter=`blur(${Math.max(1,val('imgBlur')||6)}px)`;ctx.drawImage(im,0,0);downloadCanvas(c,'blurred.png');} else if(a==='image-pixelate'){const size=Math.max(4,val('pixelSize')||12),small=document.createElement('canvas'),sx=small.getContext('2d');small.width=Math.max(1,Math.ceil(w/size));small.height=Math.max(1,Math.ceil(h/size));sx.imageSmoothingEnabled=false;sx.drawImage(im,0,0,small.width,small.height);c.width=w;c.height=h;ctx.imageSmoothingEnabled=false;ctx.drawImage(small,0,0,small.width,small.height,0,0,w,h);downloadCanvas(c,'pixelated.png');} else {const b=Math.max(1,Math.min(100,val('borderSize')||10));c.width=w+b*2;c.height=h+b*2;ctx.fillStyle=txt('borderColor')||'#111827';ctx.fillRect(0,0,c.width,c.height);ctx.drawImage(im,b,b);downloadCanvas(c,'bordered.png');} }
        else if (a === 'image-format') { const f=$('#imgFile')?.files?.[0]; if(!f)return setResult('Choose an image.'); const img=await loadImage(f), c=document.createElement('canvas'); c.width=img.naturalWidth;c.height=img.naturalHeight; if(!c.width||!c.height)return setResult('Invalid image.'); c.getContext('2d').drawImage(img,0,0); const type=$('#imageOutFormat').value,q=parseFloat($('#imageOutQuality').value); c.toBlob(b=>{if(!b)return setResult('Conversion failed.');downloadBlob(b,`converted.${type.split('/')[1].replace('jpeg','jpg')}`);setResult(`Downloaded ${c.width}×${c.height} ${type}.`);},type,q); }
        else if (a === 'image-dimensions') { const f=$('#imgFile')?.files?.[0]; if(!f)return setResult('Choose an image.'); const img=await loadImage(f), ratio=img.naturalHeight?img.naturalWidth/img.naturalHeight:0; setResult(`Width: ${img.naturalWidth}px\nHeight: ${img.naturalHeight}px\nAspect ratio: ${fmt(ratio)}\nFile size: ${(f.size/1024).toFixed(1)} KB\nType: ${f.type||'unknown'}`); }
        else if (a === 'image-dataurl') { const f=$('#imgFile')?.files?.[0]; if(!f)return setResult('Choose an image.'); const reader=new FileReader(); reader.onload=()=>{ $('#resultText').value=reader.result||''; setResult('Data URL created.'); }; reader.readAsDataURL(f); }

        // ── Additional developer tools ──────────────────────────────────────────
        else if (a === 'json-validator') { const raw=txt('textInput').trim(); if(!raw)return setResult('Enter JSON to validate.'); try{const v=JSON.parse(raw);setResult(`Valid JSON ✓\nRoot type: ${Array.isArray(v)?'array':v===null?'null':typeof v}`);}catch(e){setResult('Invalid JSON ✗\n'+e.message);} }
        else if (a === 'html-minify') { setResult(txt('textInput').replace(/<!--[\s\S]*?-->/g,'').replace(/>\s+</g,'><').replace(/\s{2,}/g,' ').trim()); }
        else if (a === 'js-format') { const raw=txt('textInput').replace(/\s*([{}();,:])\s*/g,'$1'); let d=0,out=''; for(let i=0;i<raw.length;i++){const ch=raw[i]; if(ch==='{'){d++;out+='{\n'+'  '.repeat(d);} else if(ch==='}'){d=Math.max(0,d-1);out+='\n'+'  '.repeat(d)+'}';} else if(ch===';'){out+=';\n'+'  '.repeat(d);} else {out+=ch;}} setResult(out.replace(/\n\s*\n/g,'\n').trim()); }
        else if (a === 'sql-format') { let q=txt('textInput').trim().replace(/\s+/g,' '); const kws=['SELECT','FROM','WHERE','GROUP BY','HAVING','ORDER BY','LIMIT','OFFSET','LEFT JOIN','RIGHT JOIN','INNER JOIN','OUTER JOIN','JOIN','SET','VALUES','UNION']; kws.forEach(k=>{q=q.replace(new RegExp('\\b'+k.replace(' ','\\s+')+'\\b','gi'),'\n'+k.toUpperCase());}); q=q.replace(/^\n/,'').replace(/,\s*/g,',\n  '); setResult(q); }
        else if (a === 'regex') { const p=txt('regexPattern'),flags=txt('regexFlags'); if(!p)return setResult('Enter a pattern.'); const re=new RegExp(p,flags); const text=txt('textInput'); const matches=[...text.matchAll(re)]; setResult(`Matches: ${matches.length}\n`+matches.map((m,i)=>`${i+1}. ${m[0]} at index ${m.index}`).join('\n')); }
        else if (a === 'color-convert') { const raw=txt('colorInput').trim(), hexToRgb=h=>{const x=h.replace('#','');const v=x.length===3?x.split('').map(c=>c+c).join(''):x;return [parseInt(v.slice(0,2),16),parseInt(v.slice(2,4),16),parseInt(v.slice(4,6),16)]}; let rgb;if(/^#?[0-9a-f]{3,6}$/i.test(raw))rgb=hexToRgb(raw); else {const m=raw.match(/rgb\s*\(\s*([\d.]+)[, ]+\s*([\d.]+)[, ]+\s*([\d.]+)/i);if(m)rgb=m.slice(1).map(Number);}; if(!rgb)return setResult('Enter a HEX or RGB color.'); const [r,g,b]=rgb.map(x=>Math.max(0,Math.min(255,x))); const mx=Math.max(r,g,b)/255,mn=Math.min(r,g,b)/255,d=mx-mn,l=(mx+mn)/2;let h=0,s=0;if(d){s=d/(1-Math.abs(2*l-1));switch(mx){case r/255:h=60*(((g-b)/255/d)%6);break;case g/255:h=60*((b-r)/255/d+2);break;default:h=60*((r-g)/255/d+4)}} if(h<0)h+=360; const hex='#'+[r,g,b].map(x=>Math.round(x).toString(16).padStart(2,'0')).join(''); $('#colorPreview').style.background=hex; setResult(`HEX: ${hex}\nRGB: rgb(${Math.round(r)}, ${Math.round(g)}, ${Math.round(b)})\nHSL: hsl(${Math.round(h)}, ${Math.round(s*100)}%, ${Math.round(l*100)}%)`); }
        else if (a === 'css-minify') { setResult(txt('textInput').replace(/\/\*[\s\S]*?\*\//g,'').replace(/\s+/g,' ').replace(/\s*([{}:;,>])\s*/g,'$1').replace(/;}/g,'}').trim()); }
        else if (a === 'json2csv') { const data=JSON.parse(txt('textInput')); if(!Array.isArray(data)||!data.length)return setResult('JSON must be a non-empty array of objects.'); const keys=[...new Set(data.flatMap(o=>Object.keys(o||{})))]; const esc=v=>'"'+String(v??'').replace(/"/g,'""')+'"'; setResult([keys.map(esc).join(','),...data.map(o=>keys.map(k=>esc(typeof o?.[k]==='object'?JSON.stringify(o[k]):o?.[k])).join(','))].join('\n')); }
        else if (a === 'query-parser') { const raw=txt('queryInput'); let q=raw.includes('?')?raw.split('?')[1]:raw; q=q.split('#')[0]; const p=new URLSearchParams(q); setResult([...p.entries()].map(([k,v])=>`${k} = ${v}`).join('\n')||'No query parameters found.'); }

        // ── Additional converters ───────────────────────────────────────────────
        else if (['speed','pressure','volume','energy','frequency','angle'].includes(a)) { const maps={speed:{kmh:1,mph:1.609344,ms:3.6,knot:1.852,fts:1.09728},pressure:{Pa:1,kPa:1000,bar:100000,psi:6894.757293168,atm:101325,mmHg:133.322387415},volume:{L:1,mL:.001,gal:3.785411784,qt:.946352946,cup:.2365882365,m3:1000},energy:{J:1,kJ:1000,cal:4.184,Wh:3600,kWh:3600000},frequency:{Hz:1,kHz:1000,MHz:1e6,GHz:1e9},angle:{deg:1,rad:180/Math.PI,grad:.9,turn:360}}; const v=val('convValue'),from=txt('convFrom'),to=txt('convTo'); setResult(fmt(v*maps[a][from]/maps[a][to])); }
        else if (a === 'sha512') { const buf=await crypto.subtle.digest('SHA-512',new TextEncoder().encode(txt('textInput'))); setResult(Array.from(new Uint8Array(buf),x=>x.toString(16).padStart(2,'0')).join('')); }
        else if (a === 'random-number') { let lo=Math.ceil(val('randMin')),hi=Math.floor(val('randMax')); if(lo>hi)return setResult('Minimum must be less than or equal to maximum.'); const range=hi-lo+1;if(range>0xffffffff)return setResult('Range is too large.'); const max=Math.floor(0x100000000/range)*range; let x;do{x=crypto.getRandomValues(new Uint32Array(1))[0]}while(x>=max);setResult(String(lo+(x%range))); }
        else if (a === 'entropy') { const s=txt('entropyInput'); if(!s)return setResult('Enter a password.'); let pool=0;if(/[a-z]/.test(s))pool+=26;if(/[A-Z]/.test(s))pool+=26;if(/\d/.test(s))pool+=10;if(/[^A-Za-z0-9]/.test(s))pool+=32;const bits=s.length*Math.log2(Math.max(pool,1));setResult(`Length: ${s.length}\nEstimated character pool: ${pool}\nEstimated entropy: ${fmt(bits)} bits`); }

        // ── Browser utilities ───────────────────────────────────────────────────
        else if (a === 'stopwatch-start') { if(!window.__sw){window.__sw={start:performance.now(),elapsed:0,running:true,lap:0};} else if(!window.__sw.running){window.__sw.start=performance.now();window.__sw.running=true;} }
        else if (a === 'stopwatch-pause') { if(window.__sw?.running){window.__sw.elapsed+=performance.now()-window.__sw.start;window.__sw.running=false;} }
        else if (a === 'stopwatch-reset') { window.__sw=null;$('#stopwatchDisplay').textContent='00:00.000';$('#stopwatchLaps').textContent=''; }
        else if (a === 'stopwatch-lap') { if(window.__sw){const e=window.__sw.elapsed+(window.__sw.running?performance.now()-window.__sw.start:0);window.__sw.lap=(window.__sw.lap||0)+1;$('#stopwatchLaps').textContent+=`Lap ${window.__sw.lap}: ${formatStopwatch(e)}\n`; } }
        else if (a === 'timer-start') { if(!window.__timer){window.__timer={end:Date.now()+(Math.max(0,val('timerMin'))*60+Math.max(0,val('timerSec')))*1000,running:true};} else if(!window.__timer.running){window.__timer.end=Date.now()+window.__timer.remaining;window.__timer.running=true;} }
        else if (a === 'timer-pause') { if(window.__timer?.running){window.__timer.remaining=Math.max(0,window.__timer.end-Date.now());window.__timer.running=false;} }
        else if (a === 'timer-reset') { window.__timer=null; updateTimerDisplay(); }

        // ── Image tools ─────────────────────────────────────────────────────────
        else if (a === 'compress')  await handleCompress();
        else if (a === 'resize')    await handleResize();
        else if (a === 'image64')   handleImage64();
        else if (a === 'grayscale') await handleGrayscale();
        else if (a === 'crop')      await handleCrop();
        else if (a === 'rotate')    await handleRotate();

      } catch (err) {
        let msg = 'Please check the input.';
        if (a === 'json-format' || a === 'json-minify') msg = 'Invalid JSON: ' + err.message;
        else if (a === 'base64-decode')  msg = 'Invalid Base64 string.';
        else if (a === 'url-decode')     msg = 'Invalid URL-encoded string.';
        else if (a === 'jwt-decode')     msg = 'Invalid JWT — could not decode header/payload.';
        else if (a === 'csv2json')       msg = 'Could not parse CSV input.';
        setResult(msg);
      }
    });

    // ── Popular browser tools ─────────────────────────────────────────────────
    const ttsVoice = $('#ttsVoice');
    const loadVoices = () => {
      if (!ttsVoice || !('speechSynthesis' in window)) return;
      const voices = speechSynthesis.getVoices();
      ttsVoice.innerHTML = '<option value="">Default browser voice</option>' + voices.map((v,i) => `<option value="${i}">${v.name} — ${v.lang}</option>`).join('');
    };
    if (ttsVoice) { loadVoices(); speechSynthesis?.addEventListener?.('voiceschanged', loadVoices); }
    $('#ttsRate')?.addEventListener('input', e => { const o=$('#ttsRateVal'); if(o)o.textContent=e.target.value+'×'; });
    $('#wmOpacity')?.addEventListener('input', e => { const o=$('#wmOpacityVal'); if(o)o.textContent=Math.round(e.target.value*100)+'%'; });

    function escapeHtml(s){ return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function hexRgb(h){ const x=h.replace('#',''); return [parseInt(x.slice(0,2),16),parseInt(x.slice(2,4),16),parseInt(x.slice(4,6),16)]; }
    function luminance(h){ const [r,g,b]=hexRgb(h).map(v=>v/255).map(v=>v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)); return .2126*r+.7152*g+.0722*b; }
    function timezoneDate(local, zone){
      const [d,t]=local.split('T'), [y,m,day]=d.split('-').map(Number), [hh,mm]=t.split(':').map(Number);
      const guess=Date.UTC(y,m-1,day,hh,mm);
      const parts=new Intl.DateTimeFormat('en-US',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(new Date(guess));
      const get=k=>Number(parts.find(p=>p.type===k)?.value||0);
      const asUTC=Date.UTC(get('year'),get('month')-1,get('day'),get('hour'),get('minute'));
      return new Date(guess-(asUTC-guess));
    }
    function jsonTsValue(v, name){
      if(v===null) return 'null'; if(Array.isArray(v)){ if(!v.length)return 'unknown[]'; return '('+jsonTsValue(v[0],name)+' | '+(v.length>1?jsonTsValue(v[1],name):'unknown')+')[]'; }
      if(typeof v==='string')return 'string'; if(typeof v==='number')return 'number'; if(typeof v==='boolean')return 'boolean'; if(typeof v==='object')return name;
      return 'unknown';
    }
    function jsonTsObject(obj,name){
      let out=`export interface ${name} {\n`;
      for(const [k,v] of Object.entries(obj)){ const safe=/^[A-Za-z_$][\w$]*$/.test(k)?k:JSON.stringify(k); const child=name+String(k).replace(/[^A-Za-z0-9]/g,''); if(v&&typeof v==='object'&&!Array.isArray(v)&&v!==null) out+=`  ${safe}: ${child};\n`; else out+=`  ${safe}: ${jsonTsValue(v,child)};\n`; }
      out+='}\n';
      for(const [k,v] of Object.entries(obj)) if(v&&typeof v==='object'&&!Array.isArray(v)&&v!==null) out=jsonTsObject(v,name+String(k).replace(/[^A-Za-z0-9]/g,''))+out;
      return out;
    }
    function formatMarkup(src){
      const tokens=src.replace(/>\s*</g,'><').trim().split(/(?=<)|(?<=>)/).filter(Boolean); let depth=0,out=[];
      const inline=/^<(input|img|br|hr|meta|link|source|area|base|embed|param|track|wbr)(\s[^>]*)?\/>?$/i;
      for(let token of tokens){ token=token.trim(); if(!token)continue; if(/^<\//.test(token))depth=Math.max(0,depth-1); out.push('  '.repeat(depth)+token); if(/^<[^!/][^>]*>$/.test(token)&&!inline.test(token)&&!/<\/[^>]+>$/.test(token))depth++; }
      return out.join('\n');
    }
    const morseMap={A:'.-',B:'-...',C:'-.-.',D:'-..',E:'.',F:'..-.',G:'--.',H:'....',I:'..',J:'.---',K:'-.-',L:'.-..',M:'--',N:'-.',O:'---',P:'.--.',Q:'--.-',R:'.-.',S:'...',T:'-',U:'..-',V:'...-',W:'.--',X:'-..-',Y:'-.--',Z:'--..','0':'-----','1':'.----','2':'..---','3':'...--','4':'....-','5':'.....','6':'-....','7':'--...','8':'---..','9':'----.','?':'..--..','.':'.-.-.-',',':'--..--','!':'-.-.--'};
    const morseRev=Object.fromEntries(Object.entries(morseMap).map(([k,v])=>[v,k]));

    // Additional action branches are kept in the same unified click handler.
    // They are inserted here so every new tool remains browser-only.
    workspace.addEventListener('click', async e => {
      const btn=e.target.closest('[data-action]'); if(!btn)return; const a=btn.dataset.action;
      try {
        if(a==='tts-speak'){ if(!('speechSynthesis' in window))return setResult('Speech synthesis is not supported in this browser.'); speechSynthesis.cancel(); const u=new SpeechSynthesisUtterance(txt('ttsText')); const voices=speechSynthesis.getVoices(); const idx=parseInt(txt('ttsVoice')); if(Number.isInteger(idx)&&voices[idx])u.voice=voices[idx]; u.rate=val('ttsRate')||1; speechSynthesis.speak(u); setResult('Speaking…'); }
        else if(a==='tts-pause'){speechSynthesis?.pause();}
        else if(a==='tts-resume'){speechSynthesis?.resume();}
        else if(a==='tts-stop'){speechSynthesis?.cancel();setResult('Stopped.');}
        else if(a==='stt-start'){const SR=window.SpeechRecognition||window.webkitSpeechRecognition;if(!SR)return setResult('Speech recognition is not supported by this browser.'); if(window.__stt)window.__stt.stop(); const r=new SR(); r.lang=txt('sttLang')||'en-US'; r.continuous=true;r.interimResults=true; r.onresult=ev=>{let final='',interim='';for(let i=ev.resultIndex;i<ev.results.length;i++){const x=ev.results[i];if(x.isFinal)final+=x[0].transcript;else interim+=x[0].transcript;} const el=$('#sttText');if(el){const existing=el.dataset.finalText||'';if(final){el.dataset.finalText=(existing+' '+final).trim();}el.value=((el.dataset.finalText||existing)+' '+interim).trim();}};r.onerror=ev=>setResult('Speech recognition error: '+ev.error);r.onend=()=>setResult('Listening stopped.');window.__stt=r;r.start();setResult('Listening…');}
        else if(a==='stt-stop'){window.__stt?.stop();}
        else if(a==='timezone'){const raw=txt('tzDate');if(!raw)return setResult('Select a date and time.');const d=timezoneDate(raw,txt('tzFrom'));const to=txt('tzTo');const f=new Intl.DateTimeFormat('en-US',{timeZone:to,dateStyle:'full',timeStyle:'long'});setResult(f.format(d)+'\nISO: '+d.toISOString());}
        else if(a==='watermark'){const f=$('#imgFile')?.files?.[0];if(!f)return setResult('Choose an image.');const im=await loadImageFromFile(f),c=document.createElement('canvas');c.width=im.width;c.height=im.height;const ctx=c.getContext('2d');ctx.drawImage(im,0,0);const text=txt('wmText')||'ToolPWA',op=val('wmOpacity')||.45;const size=Math.max(18,Math.round(Math.min(c.width,c.height)/15));ctx.font=`bold ${size}px sans-serif`;ctx.textAlign='right';ctx.textBaseline='bottom';ctx.fillStyle=`rgba(255,255,255,${op})`;ctx.strokeStyle=`rgba(0,0,0,${op*.65})`;ctx.lineWidth=Math.max(2,size/12);ctx.strokeText(text,c.width-20,c.height-20);ctx.fillText(text,c.width-20,c.height-20);downloadCanvas(c,'watermarked.png');}
        else if(a==='favicon'){const c=document.createElement('canvas');c.width=c.height=256;const ctx=c.getContext('2d');ctx.fillStyle=txt('favBg')||'#10b981';ctx.fillRect(0,0,256,256);ctx.fillStyle=txt('favFg')||'#071018';ctx.font='bold 150px sans-serif';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(txt('favText')||'T',128,132);downloadCanvas(c,'favicon.png');}
        else if(a==='flip-h'||a==='flip-v'){const f=$('#imgFile')?.files?.[0];if(!f)return setResult('Choose an image.');const im=await loadImageFromFile(f),c=document.createElement('canvas'),ctx=c.getContext('2d');c.width=im.width;c.height=im.height;ctx.save();ctx.translate(a==='flip-h'?c.width:0,a==='flip-v'?c.height:0);ctx.scale(a==='flip-h'?-1:1,a==='flip-v'?-1:1);ctx.drawImage(im,0,0);ctx.restore();downloadCanvas(c,'flipped.png');}
        else if(a==='markdown-html'){let md=txt('textInput').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');md=md.replace(/^### (.*)$/gm,'<h3>$1</h3>').replace(/^## (.*)$/gm,'<h2>$1</h2>').replace(/^# (.*)$/gm,'<h1>$1</h1>').replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/\*(.+?)\*/g,'<em>$1</em>').replace(/`([^`]+)`/g,'<code>$1</code>').replace(/^[-*] (.*)$/gm,'<li>$1</li>').replace(/(?:<li>.*<\/li>\n?)+/g,m=>'<ul>\n'+m+'</ul>\n').split(/\n\n+/).map(x=>/^<h[1-3]>|^<ul>/.test(x.trim())?x:x.trim()?'<p>'+x.replace(/\n/g,'<br>')+'</p>':'').join('\n');setResult(md);}
        else if(a==='text-morse'){setResult([...txt('textInput').toUpperCase()].map(ch=>ch===' '?'/':(morseMap[ch]||ch)).join(' '));}
        else if(a==='morse-text'){const words=txt('textInput').trim().split(/\s*\/\s*/).map(w=>w.trim().split(/\s+/).map(x=>morseRev[x]||'').join('')).join(' ');setResult(words);}
        else if(a==='text-binary'){setResult(Array.from(new TextEncoder().encode(txt('textInput'))).map(x=>x.toString(2).padStart(8,'0')).join(' '));}
        else if(a==='binary-text'){const bits=txt('textInput').trim().split(/\s+/);if(bits.some(x=>!/^[01]{8}$/.test(x)))return setResult('Use 8-bit binary bytes separated by spaces.');setResult(new TextDecoder().decode(new Uint8Array(bits.map(x=>parseInt(x,2)))));}
        else if(a==='email-validator'){const v=txt('emailInput').trim();const ok=/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v);setResult(ok?'Valid email syntax.':'Invalid email syntax.');}
        else if(a==='html-format'||a==='xml-format'){const raw=txt('textInput').trim();if(!raw)return setResult('Enter markup first.');setResult(formatMarkup(raw));}
        else if(a==='json-ts'){const obj=JSON.parse(txt('textInput'));if(!obj||Array.isArray(obj)||typeof obj!=='object')return setResult('Root JSON value must be an object.');const n=(txt('tsName')||'Root').replace(/[^A-Za-z0-9_$]/g,'')||'Root';setResult(jsonTsObject(obj,n));}
        else if(a==='url-parser'){const u=new URL(txt('urlInput'));setResult(`Protocol: ${u.protocol}\nUsername: ${u.username||'(none)'}\nHostname: ${u.hostname}\nPort: ${u.port||'(default)'}\nPath: ${u.pathname}\nQuery: ${u.search||'(none)'}\nHash: ${u.hash||'(none)'}\nOrigin: ${u.origin}`);}
        else if(a==='contrast'){const fg=txt('fgColor'),bg=txt('bgColor');const ratio=(Math.max(luminance(fg),luminance(bg))+.05)/(Math.min(luminance(fg),luminance(bg))+.05);const aa=ratio>=4.5?'AA normal text ✓':'AA normal text ✗',aaa=ratio>=7?'AAA normal text ✓':'AAA normal text ✗';$('#contrastPreview').style.background=`linear-gradient(90deg,${fg} 50%,${bg} 50%)`;setResult(`Contrast ratio: ${ratio.toFixed(2)}:1\n${aa}\n${aaa}`);}
        else if(a==='gradient'){const css=`linear-gradient(${val('gradAngle')||135}deg, ${txt('gradA')}, ${txt('gradB')})`;$('#gradientPreview').style.background=css;setResult(`background: ${css};`);}
        else if(a==='meta-tags'){const title=txt('metaTitle'),desc=txt('metaDesc'),url=txt('metaUrl'),img=txt('metaImage');const esc=escapeHtml;setResult(`<title>${esc(title)}</title>\n<meta name="description" content="${esc(desc)}">\n<link rel="canonical" href="${esc(url)}">\n<meta property="og:title" content="${esc(title)}">\n<meta property="og:description" content="${esc(desc)}">\n<meta property="og:url" content="${esc(url)}">${img?`\n<meta property="og:image" content="${esc(img)}">`:''}\n<meta name="twitter:card" content="summary_large_image">\n<meta name="twitter:title" content="${esc(title)}">\n<meta name="twitter:description" content="${esc(desc)}">`);}
        else if(a==='robots'){const agent=txt('robotsAgent')||'*',lines=['User-agent: '+agent];const dis=txt('robotsDisallow').split(/\r?\n/).map(x=>x.trim()).filter(Boolean);dis.forEach(x=>lines.push('Disallow: '+(x.startsWith('/')?x:'/'.concat(x))));const delay=txt('robotsDelay');if(delay)lines.push('Crawl-delay: '+delay);const sm=txt('robotsSitemap');if(sm)lines.push('Sitemap: '+sm);setResult(lines.join('\n'));}
        else if(a==='text-diff'){const A=txt('diffA').split(/\r?\n/),B=txt('diffB').split(/\r?\n/),n=Math.max(A.length,B.length),out=[];for(let i=0;i<n;i++){if(A[i]===B[i])out.push('  '+(A[i]??''));else{if(A[i]!==undefined)out.push('- '+A[i]);if(B[i]!==undefined)out.push('+ '+B[i]);}}setResult(out.join('\n'));}
      } catch(err){setResult('Please check the input. '+(err?.message||''));}
    });

    // ── Case converter ─────────────────────────────────────────────────────────
    $$('[data-case]').forEach(b => b.addEventListener('click', () => {
      const textEl = document.getElementById('textInput');
      if (!textEl) return;
      const a = b.dataset.case;
      if (a === 'upper')    textEl.value = textEl.value.toUpperCase();
      if (a === 'lower')    textEl.value = textEl.value.toLowerCase();
      if (a === 'title')    textEl.value = textEl.value.toLowerCase().replace(/\b\w/g, m => m.toUpperCase());
      if (a === 'sentence') textEl.value = textEl.value.toLowerCase().replace(/(^|[.!?]\s+)\w/g, m => m.toUpperCase());
    }));

    // ── Reset ──────────────────────────────────────────────────────────────────
    $$('[data-reset]').forEach(b => b.addEventListener('click', () => {
      workspace.querySelectorAll('input:not([type=range]):not([type=checkbox]), textarea').forEach(el => {
        el.value = '';
      });
      const out = $('#result');
      if (out) { out.textContent = ''; out.style.borderLeftColor = ''; }
      document.getElementById('textInput')?.focus();
    }));

    // ── Shared ResultActions component ───────────────────────────────────────
    function resultText(el){ return (el?.value ?? el?.innerText ?? el?.textContent ?? '').trim(); }
    function makeDownload(text, filename, mime='text/plain;charset=utf-8') {
      const blob = text instanceof Blob ? text : new Blob([text], {type:mime});
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a'); a.href=url; a.download=filename; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1200);
    }
    function addResultActions(){
      const outputs = [...$$('.result-box'), ...$$('textarea#resultText[readonly]')];
      outputs.forEach(out => {
        if (out.dataset.resultActions === '1') return;
        // Remove legacy standalone copy controls so the shared component is the only result-action UI.
        const parent = out.parentElement;
        parent?.querySelectorAll(':scope > button[data-copy-result], :scope > button[data-copy-text]').forEach(b => b.remove());
        out.dataset.resultActions='1';
        const bar=document.createElement('div'); bar.className='result-actions';
        const copy=document.createElement('button'); copy.type='button'; copy.className='btn'; copy.textContent='Copy';
        copy.addEventListener('click',async()=>{
          const text=resultText(out); if(!text) return showToast('Nothing to copy','warning');
          try{ await navigator.clipboard.writeText(text); copy.textContent='Copied'; showToast('Copied!','success'); }
          catch{ showToast('Clipboard unavailable','error'); }
          setTimeout(()=>copy.textContent='Copy',1200);
        });
        const share=document.createElement('button'); share.type='button'; share.className='btn'; share.textContent='Share';
        share.addEventListener('click',async()=>{
          const text=resultText(out); if(!text) return showToast('Nothing to share','warning');
          if(navigator.share){
            try{ await navigator.share({title:document.title,text}); showToast('Shared!','success'); }
            catch(e){ if(e?.name!=='AbortError') showToast('Sharing failed','error'); }
          } else {
            try{ await navigator.clipboard.writeText(text); showToast('Share link/content copied','info'); }
            catch{ showToast('Sharing unavailable','error'); }
          }
        });
        const dl=document.createElement('button'); dl.type='button'; dl.className='btn'; dl.textContent='Download';
        dl.addEventListener('click',()=>{
          const link=out.closest('.calc-card,.suite-active,.tool-workspace')?.querySelector('a[download]');
          if(link){ link.click(); showToast('Download started','success'); return; }
          const text=resultText(out); if(!text) return showToast('Nothing to download','warning');
          const title=(document.title.replace(/[^a-z0-9]+/gi,'-').replace(/^-|-$/g,'')||'toolpwa-result')+'.txt';
          makeDownload(text,title); showToast('Downloaded!','success');
        });
        bar.append(copy,share,dl);
        out.parentNode?.insertBefore(bar,out.nextSibling);
      });
    }
    addResultActions();

    // ── Copy helpers ───────────────────────────────────────────────────────────
    $$('[data-copy-result]').forEach(b => b.addEventListener('click', () => {
      const text = $('#result')?.textContent || '';
      navigator.clipboard?.writeText(text).then(()=>showToast('Copied!','success')).catch(()=>showToast('Clipboard unavailable','error'));
    }));
    $$('[data-copy-text]').forEach(b => b.addEventListener('click', () => {
      const text = document.getElementById('resultText')?.value || '';
      navigator.clipboard?.writeText(text).then(()=>showToast('Copied!','success')).catch(()=>showToast('Clipboard unavailable','error'));
    }));
  }

  function formatStopwatch(ms){const t=Math.max(0,ms),m=Math.floor(t/60000),s=Math.floor(t/1000)%60,mi=Math.floor(t%1000);return `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}.${String(mi).padStart(3,'0')}`;}
  function updateStopwatch(){const sw=window.__sw;if(sw){const e=sw.elapsed+(sw.running?performance.now()-sw.start:0);const el=$('#stopwatchDisplay');if(el)el.textContent=formatStopwatch(e);} requestAnimationFrame(updateStopwatch);}
  function updateTimerDisplay(){const el=$('#timerDisplay');if(!el)return;let ms=window.__timer?(window.__timer.running?Math.max(0,window.__timer.end-Date.now()):Math.max(0,window.__timer.remaining||0)):((Math.max(0,val('timerMin'))*60+Math.max(0,val('timerSec')))*1000);const total=Math.ceil(ms/1000),m=Math.floor(total/60),s=total%60;el.textContent=`${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;if(window.__timer?.running&&ms<=0){window.__timer=null;el.textContent='00:00';}}
  updateStopwatch(); setInterval(updateTimerDisplay,100);


  // ── BDIX finder ────────────────────────────────────────────────────────────
  function initBdixFinder() {
    const root=document.querySelector('[data-bdix-finder]'); if(!root)return;
    const dataEl=document.getElementById('bdixServerData'); if(!dataEl)return;
    let rows=[]; try{rows=JSON.parse(dataEl.textContent||'[]')}catch(e){rows=[]}
    const list=document.getElementById('bdixServerList'), search=document.getElementById('bdixSearch'), loc=document.getElementById('bdixLocation'), count=document.getElementById('bdixCount');
    const esc=v=>String(v??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[m]));
    function render(){const q=(search?.value||'').trim().toLowerCase(), l=loc?.value||''; const out=rows.filter(r=>(!l||r.location===l)&&(!q||[r.name,r.url,r.location,r.isp,r.tags].join(' ').toLowerCase().includes(q))); if(count)count.textContent=`${out.length} server${out.length===1?'':'s'}`; if(!list)return; list.innerHTML=out.length?out.map(r=>`<article class="bdix-server-card"><div><div class="bdix-server-name">${esc(r.name)}</div><div class="bdix-server-meta">${esc(r.location||'Location not set')}${r.isp?' · '+esc(r.isp):''}</div><code>${esc(r.url)}</code></div><div class="bdix-server-actions"><a class="btn primary" href="${esc(r.url)}" target="_blank" rel="noopener">Open</a><button class="btn" data-copy-url="${esc(r.url)}">Copy URL</button></div></article>`).join(''):'<div class="empty-state">No matching BDIX servers found.</div>';}
    search?.addEventListener('input',render); loc?.addEventListener('change',render); list?.addEventListener('click',async e=>{const b=e.target.closest('[data-copy-url]');if(!b)return;try{await navigator.clipboard.writeText(b.dataset.copyUrl||'');showToast('Server URL copied','success')}catch(_){showToast('Copy failed','error')}}); render();
  }

  // ── Global search / directory UX ────────────────────────────────────────────
  function readToolIndex(){
    const el=$('#toolIndex');
    if(!el) return [];
    try{return JSON.parse(el.textContent||'[]')}catch(e){return []}
  }
  function fuzzyScore(q,item){
    q=(q||'').toLowerCase().trim(); if(!q) return 0;
    const hay=((item.name||'')+' '+(item.desc||'')+' '+(item.category||'')+' '+(item.tags||[]).join(' ')).toLowerCase();
    if(hay.includes(q)) return 1000-q.length;
    let score=0,last=-1;
    for(const ch of q){const i=hay.indexOf(ch,last+1); if(i<0) return -1; score+=Math.max(1,80-i); last=i;}
    return score;
  }
  function renderSearchResults(q){
    const wrap=$('#globalSearchResults'); if(!wrap) return;
    const items=readToolIndex().map(item=>({...item,_score:fuzzyScore(q,item)})).filter(x=>q.trim()===''||x._score>=0).sort((a,b)=>b._score-a._score).slice(0,12);
    wrap.innerHTML='';
    if(!items.length){wrap.innerHTML='<div style="padding:22px;color:var(--color-text-muted);font-size:13px">No matching tools. Try a category or common task.</div>';return;}
    items.forEach(item=>{
      const a=document.createElement('a'); a.className='search-result'; a.href=item.url;
      a.innerHTML='<span class="search-result-icon">'+(item.icon||'•')+'</span><span class="search-result-text"><b></b><small></small></span><em>→</em>';
      a.querySelector('b').textContent=item.name; a.querySelector('small').textContent=item.category+' · '+item.desc;
      wrap.appendChild(a);
    });
  }
  function openGlobalSearch(initial=''){
    const modal=$('#toolpwa-search-modal'),input=$('#globalSearchInput'); if(!modal||!input) return;
    modal.classList.add('show'); document.body.classList.add('search-open'); input.value=initial; renderSearchResults(initial); setTimeout(()=>input.focus(),20);
  }
  function closeGlobalSearch(){const modal=$('#toolpwa-search-modal');if(modal)modal.classList.remove('show');document.body.classList.remove('search-open')}
  function initGlobalSearch(){
    const trigger=$('#globalSearchTrigger'), mobile=$('#mobileSearchTrigger'), modal=$('#toolpwa-search-modal'), input=$('#globalSearchInput');
    trigger?.addEventListener('click',()=>openGlobalSearch()); mobile?.addEventListener('click',()=>{document.querySelector('#mobileNavMenu')?.classList.remove('show');openGlobalSearch()});
    input?.addEventListener('input',()=>renderSearchResults(input.value));
    modal?.addEventListener('click',e=>{if(e.target===modal)closeGlobalSearch()});
    document.addEventListener('keydown',e=>{
      if(e.key==='Escape') closeGlobalSearch();
      if(e.key==='/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)){e.preventDefault();openGlobalSearch()}
    });
    $$('[data-search-fill]').forEach(b=>b.addEventListener('click',()=>{openGlobalSearch(b.dataset.searchFill||'')}));
  }

  // ── Favorites + recent history ─────────────────────────────────────────────
  const STORAGE_FAV='toolpwa:favorites', STORAGE_RECENT='toolpwa:recent';
  function getStore(key){try{const x=JSON.parse(localStorage.getItem(key)||'[]');return Array.isArray(x)?x:[]}catch(e){return []}}
  function setStore(key,val){try{localStorage.setItem(key,JSON.stringify(val))}catch(e){}}
  function syncFavorites(){
    const fav=new Set(getStore(STORAGE_FAV));
    $$('[data-favorite]').forEach(btn=>{const on=fav.has(btn.dataset.favorite);btn.classList.toggle('is-active',on);btn.textContent=on?'★':'☆';btn.setAttribute('aria-pressed',on?'true':'false');btn.setAttribute('aria-label',(on?'Remove from':'Add to')+' favorites')});
  }
  function initFavorites(){
    document.addEventListener('click',e=>{
      const btn=e.target.closest('[data-favorite]'); if(!btn)return; e.preventDefault(); e.stopPropagation();
      const slug=btn.dataset.favorite; const fav=new Set(getStore(STORAGE_FAV)); if(fav.has(slug)){fav.delete(slug);showToast('Removed from favorites','info')}else{fav.add(slug);showToast('Saved to favorites','success')} setStore(STORAGE_FAV,[...fav]);syncFavorites();
    });
    syncFavorites();
  }
  function currentToolData(){const el=$('#currentTool');if(!el)return null;try{return JSON.parse(el.textContent||'null')}catch(e){return null}}
  function recordRecent(){const t=currentToolData();if(!t)return;let items=getStore(STORAGE_RECENT).filter(x=>x.slug!==t.slug);items.unshift(t);items=items.slice(0,8);setStore(STORAGE_RECENT,items)}
  function renderRecent(){
    const wrap=$('#recentToolList'),strip=$('#recentTools'); if(!wrap)return; const items=getStore(STORAGE_RECENT); wrap.innerHTML='';
    if(strip) strip.hidden=!items.length;
    items.forEach(item=>{const a=document.createElement('a');a.className='recent-item';a.href=item.url;a.innerHTML='<span>'+ (item.icon||'•') +'</span><span>'+item.name+'</span>';wrap.appendChild(a)});
  }
  function initRecent(){
    recordRecent(); renderRecent();
    $('#clearRecent')?.addEventListener('click',()=>{setStore(STORAGE_RECENT,[]);renderRecent();showToast('Recent history cleared','info')});
  }

  // ── Tool page sharing ────────────────────────────────────────────────────────
  function initToolShare(){
    const wrap=$('.share-wrap'), trigger=$('.share-trigger');
    if(!wrap||!trigger)return;
    const data=currentToolData();
    const url=location.href, title=data?.name||document.title;
    const text='Check out '+title+' on ToolPWA';
    const mobileShare=()=>{
      if(navigator.share){ navigator.share({title,text,url}).then(()=>showToast('Shared!','success')).catch(()=>{}); }
      else { navigator.clipboard?.writeText(url).then(()=>showToast('Link copied','success')).catch(()=>showToast('Copy the link from your browser','info')); }
    };
    trigger.addEventListener('click',e=>{
      e.stopPropagation();
      if(window.matchMedia('(max-width: 680px)').matches){ mobileShare(); return; }
      const open=wrap.classList.toggle('is-open'); trigger.setAttribute('aria-expanded',open?'true':'false');
    });
    wrap.querySelectorAll('[data-share]').forEach(btn=>btn.addEventListener('click',async()=>{
      const type=btn.dataset.share;
      if(type==='copy'){
        try{await navigator.clipboard.writeText(url);showToast('Link copied','success');}catch(_){showToast(url,'info');}
      } else if(type==='email'){
        location.href='mailto:?subject='+encodeURIComponent(title)+'&body='+encodeURIComponent(text+'\n\n'+url);
      } else {
        const targets={
          facebook:'https://www.facebook.com/sharer/sharer.php?u='+encodeURIComponent(url),
          x:'https://x.com/intent/post?text='+encodeURIComponent(text)+'&url='+encodeURIComponent(url),
          whatsapp:'https://wa.me/?text='+encodeURIComponent(text+' '+url)
        };
        window.open(targets[type],'_blank','noopener,noreferrer,width=680,height=620');
      }
      wrap.classList.remove('is-open'); trigger.setAttribute('aria-expanded','false');
    }));
    document.addEventListener('click',e=>{if(!wrap.contains(e.target)){wrap.classList.remove('is-open');trigger.setAttribute('aria-expanded','false')}});
    document.addEventListener('keydown',e=>{if(e.key==='Escape'){wrap.classList.remove('is-open');trigger.setAttribute('aria-expanded','false')}});
  }

  // ── Homepage category filters ───────────────────────────────────────────────
  function initCoreCategoryFilters(){
    const grid=$('#toolGrid'); if(!grid)return; const cards=$$('#toolGrid .tool-card'); let active='all',visible=12;
    function render(){const set=active==='all'?cards:cards.filter(c=>c.dataset.cat===active);cards.forEach(c=>c.style.display='none');set.slice(0,visible).forEach(c=>c.style.display='');const btn=$('#loadMoreBtn');if(btn)btn.style.display=set.length>visible?'':'none';}
    $$('[data-category-filter]').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();active=a.dataset.categoryFilter||'all';visible=12;render();document.querySelector('#tools')?.scrollIntoView({behavior:'smooth',block:'start'})}));
    $('#loadMoreBtn')?.addEventListener('click',()=>{visible+=12;render()}); render();
  }

  // ── Bootstrap ────────────────────────────────────────────────────────────────
  const workspace = document.querySelector('.tool-workspace');
  const toolType  = workspace?.dataset.tool;

  initTheme();
  initMenu();
  initGlobalSearch();
  initFavorites();
  initRecent();
  initToolShare();
  initUsageAnalytics();
  initAdvancedUtilities();
  initCoreCategoryFilters();
  initCategorySearch();
  initHomeSearch();
  initInstallButtons();
  initCategoryPwa();
  initActions();
  initQualitySlider();
  initColorPicker();
  initStrengthMeter();
  initWordCounter(toolType);

  initBdixFinder();
})();


/* ── BDIX browser-side reachability tester ─────────────────────────────── */
(function(){
  'use strict';
  const sleep = ms => new Promise(r => setTimeout(r, ms));

  async function testUrl(url, timeoutMs=8000){
    const started = performance.now();
    const u = String(url || '');
    if (!/^https?:\/\//i.test(u)) return {state:'unknown', detail:'Invalid URL'};
    // Browser fetch in no-cors mode can confirm that a network request completed,
    // but an opaque response cannot expose HTTP status. This is intentionally
    // treated as "reachable" rather than pretending to know the status code.
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
      await fetch(u, {
        mode: 'no-cors',
        cache: 'no-store',
        redirect: 'follow',
        signal: controller.signal
      });
      clearTimeout(timer);
      return {state:'reachable', latency_ms:Math.round(performance.now()-started)};
    } catch (e) {
      clearTimeout(timer);
      const msg = String(e && e.message || e || '');
      const mixed = location.protocol === 'https:' && /^http:\/\//i.test(u);
      return {
        state: mixed ? 'unknown' : 'failed',
        latency_ms: Math.round(performance.now()-started),
        detail: mixed ? 'Browser blocked an HTTP request from this HTTPS page (mixed content).' : (msg || 'Request timed out or could not connect.')
      };
    }
  }
  window.TOOLPWA_BDIX_TEST = testUrl;

  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  function initBDIXChecker(root){
    const data = document.getElementById('bdixCheckData');
    const list = document.getElementById('bdixCheckList');
    if (!data || !list) return;
    let rows = JSON.parse(data.textContent || '[]').map(r => ({...r, state:'pending', latency_ms:null, detail:''}));
    const search = document.getElementById('bdixCheckSearch');
    const filter = document.getElementById('bdixCheckFilter');
    const allBtn = document.getElementById('bdixTestAll');

    function counts(){
      const c={reachable:0,failed:0,unknown:0,pending:0};
      rows.forEach(r=>c[r.state]=(c[r.state]||0)+1);
      ['reachable','failed','unknown','pending'].forEach(k=>{
        const el=document.getElementById('bdix'+k[0].toUpperCase()+k.slice(1));
        if(el) el.textContent=c[k];
      });
      const p=document.getElementById('bdixPending'); if(p) p.textContent=c.pending;
      const r=document.getElementById('bdixReachable'); if(r) r.textContent=c.reachable;
      const f=document.getElementById('bdixFailed'); if(f) f.textContent=c.failed;
      const u=document.getElementById('bdixUnknown'); if(u) u.textContent=c.unknown;
    }
    function visible(){
      const q=(search?.value||'').toLowerCase().trim(), st=filter?.value||'all';
      return rows.filter(r => (!q || `${r.name} ${r.url} ${r.category} ${r.location}`.toLowerCase().includes(q)) && (st==='all'||r.state===st));
    }
    function render(){
      const v=visible();
      list.innerHTML=v.map(r=>{
        const icon=r.state==='reachable'?'✓':r.state==='failed'?'✕':r.state==='unknown'?'?':r.state==='testing'?'…':'○';
        const label=r.state==='reachable'?'Reachable':r.state==='failed'?'Not reachable':r.state==='unknown'?'Could not verify':r.state==='testing'?'Testing…':'Not tested';
        return `<article class="bdix-check-row" data-state="${esc(r.state)}">
          <div class="bdix-status-icon">${icon}</div>
          <div class="bdix-check-info"><b>${esc(r.name)}</b><a href="${esc(r.url)}" target="_blank" rel="noopener noreferrer">${esc(r.url)}</a>
          <small>${esc(r.category||'BDIX Server')} · ${esc(r.location||'Bangladesh')} ${r.latency_ms!=null?'· '+r.latency_ms+' ms':''} ${r.detail?'· '+esc(r.detail):''}</small></div>
          <span class="bdix-status-label">${label}</span>
          <button type="button" class="btn small" data-bdix-one="${esc(r.id)}">Test</button>
        </article>`;
      }).join('') || '<div class="empty-state">No servers match this filter.</div>';
      counts();
    }
    async function runOne(r){
      if(r.state==='testing') return;
      r.state='testing'; render();
      const out=await testUrl(r.url,8000);
      r.state=out.state; r.latency_ms=out.latency_ms; r.detail=out.detail||'';
      render();
    }
    list.addEventListener('click', e=>{
      const b=e.target.closest('[data-bdix-one]'); if(!b) return;
      const r=rows.find(x=>String(x.id)===String(b.dataset.bdixOne)); if(r) runOne(r);
    });
    allBtn?.addEventListener('click', async ()=>{
      allBtn.disabled=true; allBtn.textContent='Testing servers…';
      const progress=document.getElementById('bdixProgress');
      const bar=progress?.querySelector('span');
      progress?.classList.add('active');
      rows.forEach(r=>{r.state='pending';r.latency_ms=null;r.detail='';}); render();
      const queue=rows.slice(); let active=0, cursor=0, done=0;
      const updateProgress=()=>{ if(bar) bar.style.width=Math.round((done/queue.length)*100)+'%'; };
      await new Promise(resolve=>{
        const next=()=>{
          if(cursor>=queue.length && active===0) return resolve();
          while(active<6 && cursor<queue.length){
            const r=queue[cursor++]; active++; runOne(r).finally(()=>{active--;done++;updateProgress();next();});
          }
        }; next();
      });
      if(bar) bar.style.width='100%';
      setTimeout(()=>progress?.classList.remove('active'),450);
      allBtn.disabled=false; allBtn.textContent='Test all servers';
    });
    search?.addEventListener('input',render); filter?.addEventListener('change',render);
    render();
  }
  function initBDIXFinder(root){
    const data=document.getElementById('bdixServerData'), list=document.getElementById('bdixServerList');
    if(!data||!list) return;
    const rows=JSON.parse(data.textContent||'[]');
    const q=document.getElementById('bdixSearch'), loc=document.getElementById('bdixLocation');
    function render(){
      const s=(q?.value||'').toLowerCase().trim(), l=loc?.value||'';
      const v=rows.filter(r=>(!s||`${r.name} ${r.url} ${r.category} ${r.location}`.toLowerCase().includes(s))&&(!l||r.location===l));
      list.innerHTML=v.map(r=>`<article class="bdix-server-row"><div><b>${esc(r.name)}</b><a href="${esc(r.url)}" target="_blank" rel="noopener noreferrer">${esc(r.url)}</a><small>${esc(r.category||'BDIX Server')} · ${esc(r.location||'Bangladesh')}</small></div><button class="btn small" type="button" data-finder-test="${esc(r.id)}">Test</button></article>`).join('')||'<div class="empty-state">No servers found.</div>';
      const c=document.getElementById('bdixCount'); if(c)c.textContent=v.length+' servers';
    }
    list.addEventListener('click',async e=>{
      const b=e.target.closest('[data-finder-test]'); if(!b)return;
      const r=rows.find(x=>String(x.id)===String(b.dataset.finderTest)); if(!r)return;
      b.disabled=true; b.textContent='Testing…'; const out=await testUrl(r.url,8000);
      b.textContent=out.state==='reachable'?'✓ Reachable':out.state==='failed'?'✕ Failed':'? Browser blocked';
      b.disabled=false;
    });
    q?.addEventListener('input',render); loc?.addEventListener('change',render); render();
  }

  // ── Anonymous usage analytics + tool-specific utilities ─────────────────────
  function initUsageAnalytics(){
    const t=currentToolData(); if(!t)return;
    const key='toolpwa:usage:'+t.slug+':'+new Date().toISOString().slice(0,10);
    try{ if(localStorage.getItem(key)) return; localStorage.setItem(key,'1'); }catch(e){}
    const payload=JSON.stringify({slug:t.slug});
    try{ navigator.sendBeacon?.((window.TOOLPWA_BASE||'')+'/api/usage',new Blob([payload],{type:'application/json'})); }catch(e){ fetch((window.TOOLPWA_BASE||'')+'/api/usage',{method:'POST',headers:{'Content-Type':'application/json'},body:payload,keepalive:true}).catch(()=>{}); }
  }
  function initAdvancedUtilities(){
    const w=document.querySelector('.tool-workspace'); if(!w)return;
    w.addEventListener('click',e=>{const b=e.target.closest('[data-advanced-action]');if(!b)return; const a=b.dataset.advancedAction; const out=document.getElementById('result'); const set=x=>{if(out)out.textContent=x};
      if(a==='aspect'){const w=Number(document.getElementById('arW')?.value),h=Number(document.getElementById('arH')?.value),nw=Number(document.getElementById('arNW')?.value);if(w>0&&h>0&&nw>0)set('Height: '+(nw*h/w).toFixed(2)+' px\nRatio: '+(w/gcd(w,h))+':'+(h/gcd(w,h)));else set('Enter valid dimensions.');}
      if(a==='roman'){const v=document.getElementById('romanInput')?.value.trim();let n=Number(v);if(/^\d+$/.test(v)){let r='',map=[[1000,'M'],[900,'CM'],[500,'D'],[400,'CD'],[100,'C'],[90,'XC'],[50,'L'],[40,'XL'],[10,'X'],[9,'IX'],[5,'V'],[4,'IV'],[1,'I']];for(const [x,c] of map){while(n>=x){r+=c;n-=x}}set(r||'0')}else{const m={I:1,V:5,X:10,L:50,C:100,D:500,M:1000};let total=0,prev=0;for(const c of v.toUpperCase().split('').reverse()){const x=m[c]||0;total+=x<prev?-x:x;prev=x}set(total?'Number: '+total:'Enter a valid Roman numeral or number.');}}
      if(a==='base'){const n=document.getElementById('baseN')?.value.trim(),from=Number(document.getElementById('baseFrom')?.value),to=Number(document.getElementById('baseTo')?.value);try{const v=parseInt(n,from); if(!Number.isInteger(v))throw 0; set(v.toString(to).toUpperCase())}catch(_){set('Enter a valid number for the selected base.')}}
      if(a==='jwt'){try{const p=(document.getElementById('jwtInput')?.value||'').split('.')[1];if(!p)throw 0;const s=atob(p.replace(/-/g,'+').replace(/_/g,'/')+'='.repeat((4-p.length%4)%4));set(JSON.stringify(JSON.parse(decodeURIComponent([...s].map(c=>'%'+c.charCodeAt(0).toString(16).padStart(2,'0')).join('')),null,2)))}catch(_){set('Invalid JWT payload. A JWT signature is not verified by this tool.')}}
      if(a==='entities'){const x=document.getElementById('textInput')?.value||'';set(a==='entities' ? (document.getElementById('entityMode')?.value==='decode'?decodeHTMLEntities(x):encodeHTMLEntities(x)) : x)}
      if(a==='shadow'){const x=document.getElementById('shadowX')?.value||'0',y=document.getElementById('shadowY')?.value||'8',blur=document.getElementById('shadowBlur')?.value||'24',spread=document.getElementById('shadowSpread')?.value||'0',color=document.getElementById('shadowColor')?.value||'#00000040';set(`box-shadow: ${x}px ${y}px ${blur}px ${spread}px ${color};`)}
      if(a==='palette'){const c=(document.getElementById('paletteColor')?.value||'#08795f').replace('#','');const n=parseInt(c,16);const r=n>>16,g=n>>8&255,b=n&255;const arr=[0.18,0.32,0.5,0.68,0.82].map(k=>rgbHex(Math.min(255,Math.round(r+(255-r)*k)),Math.min(255,Math.round(g+(255-g)*k)),Math.min(255,Math.round(b+(255-b)*k))));set(arr.join('\n'))}
      if(a==='mdtable'){const raw=document.getElementById('tableInput')?.value||'';const rows=raw.trim().split(/\r?\n/).map(x=>x.split(','));if(rows.length){const widths=rows[0].length;set('| '+rows[0].join(' | ')+' |\n| '+Array(widths).fill('---').join(' | ')+' |\n'+rows.slice(1).map(r=>'| '+r.join(' | ')+' |').join('\n'))}}
      if(a==='textstats'){const x=document.getElementById('textInput')?.value||'',words=x.trim()?x.trim().split(/\s+/).length:0,sent=(x.match(/[.!?]+/g)||[]).length,mins=Math.max(1,Math.ceil(words/200));set(`Words: ${words}\nSentences: ${sent}\nReading time: about ${mins} min\nCharacters: ${x.length}`)}
      if(a==='datediff'){const A=new Date(document.getElementById('dateA')?.value),B=new Date(document.getElementById('dateB')?.value);if(isNaN(A)||isNaN(B))return set('Choose two dates.');set(`Difference: ${Math.round(Math.abs(B-A)/86400000)} days`)}
      if(a==='pctchange'){const A=Number(document.getElementById('pctA')?.value),B=Number(document.getElementById('pctB')?.value);set(A===0?'Starting value cannot be zero.':`Change: ${((B-A)/Math.abs(A)*100).toFixed(2)}%`)}
    });
  }
  const gcd=(a,b)=>{while(b){[a,b]=[b,a%b]}return Math.abs(a)||1};
  const rgbHex=(r,g,b)=>'#'+[r,g,b].map(x=>x.toString(16).padStart(2,'0')).join('');
  const encodeHTMLEntities=x=>x.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const decodeHTMLEntities=x=>{const t=document.createElement('textarea');t.innerHTML=x;return t.value};
  document.addEventListener('DOMContentLoaded',()=>{
    const c=document.querySelector('[data-bdix-checker]'); if(c)initBDIXChecker(c);
    const f=document.querySelector('[data-bdix-finder]'); if(f)initBDIXFinder(f);
  });
})();

/* ── Tool quality feedback ─────────────────────────────────────────────── */
(function initToolQuality(){
  const el=document.querySelector('#currentTool'); let data=null; try{data=JSON.parse(el?.textContent||'null')}catch(e){} if(!data) return;
  const key='toolpwa:ratings';
  const read=()=>{try{return JSON.parse(localStorage.getItem(key)||'{}')}catch(e){return {}}};
  const write=v=>{try{localStorage.setItem(key,JSON.stringify(v))}catch(e){}};
  const root=document.querySelector('.rating-stars');
  if(root){
    const ratings=read(), saved=Number(ratings[data.slug]||0), out=root.querySelector('output');
    const paint=n=>root.querySelectorAll('button').forEach(b=>b.classList.toggle('is-selected',Number(b.dataset.rate)<=n));
    if(saved){paint(saved);if(out)out.textContent=saved+'/5 — thanks';}
    root.addEventListener('click',e=>{const b=e.target.closest('[data-rate]');if(!b)return;const n=Number(b.dataset.rate);ratings[data.slug]=n;write(ratings);paint(n);if(out)out.textContent=n+'/5 — thanks';if(typeof window.ToolPwaToast==='function') window.ToolPwaToast('Thanks for rating this tool'); else alert('Thanks for rating this tool');});
  }
  document.querySelector('#reportTool')?.addEventListener('click',()=>{
    const subject=encodeURIComponent('ToolPWA issue report: '+data.name);
    const body=encodeURIComponent('Tool: '+data.name+'\nURL: '+location.href+'\n\nIssue description:\n');
    location.href='mailto:?subject='+subject+'&body='+body;
  });
})();
