// WPIFY renderer: loads a published Canva site in headless Chrome and returns a layout snapshot.
// Run:  npm install && node server.js     (needs Node 18+)
const http = require('http');
const puppeteer = require('puppeteer');

const PORT = process.env.PORT || 3000;
const VIEW_W = 1440;

const allowed = (u) => {
  try {
    const p = new URL(u);
    if (!/^https?:$/.test(p.protocol)) return false;
    return ['canva.site', 'canva.com', 'canva.link'].some((d) => p.hostname === d || p.hostname.endsWith('.' + d));
  } catch (e) { return false; }
};

let browser = null;
async function getBrowser() {
  if (!browser || !browser.connected) {
    browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'] });
  }
  return browser;
}

let chain = Promise.resolve();
const queue = (fn) => { const p = chain.then(fn); chain = p.catch(() => {}); return p; };

// Runs inside the page.
function extract() {
  const vw = window.innerWidth;
  const docH = Math.max(document.documentElement.scrollHeight, document.body.scrollHeight);
  const sx = window.scrollX, sy = window.scrollY;
  const blocks = [], bands = [];
  let shapes = [];
  let order = 0;
  const inlineTags = new Set(['SPAN', 'A', 'B', 'I', 'EM', 'STRONG', 'U', 'BR', 'SUP', 'SUB', 'SMALL', 'MARK']);
  const skipTags = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'SVG', 'TEMPLATE', 'HEAD', 'NAV', 'VIDEO', 'CANVAS']);
  const cs = (e) => getComputedStyle(e);
  const rect = (e) => { const r = e.getBoundingClientRect(); return { x: r.left + sx, y: r.top + sy, w: r.width, h: r.height }; };
  const px = (v) => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
  const raw = (c) => (!c || c === 'transparent' || /rgba\(\s*\d+,\s*\d+,\s*\d+,\s*0\s*\)/.test(c)) ? '' : c;
  const hex = (c) => {
    const m = c && c.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/);
    if (!m || (m[4] !== undefined && parseFloat(m[4]) === 0)) return '';
    return '#' + [m[1], m[2], m[3]].map((n) => (+n).toString(16).padStart(2, '0')).join('');
  };
  const bgUrl = (s) => { const m = s.backgroundImage && s.backgroundImage.match(/url\(["']?([^"')]+)["']?\)/); return m ? m[1] : ''; };
  const radius = (s) => [s.borderTopLeftRadius, s.borderTopRightRadius, s.borderBottomRightRadius, s.borderBottomLeftRadius].join(' ');
  const hasBlockTextChild = (e) => {
    for (const c of e.children) {
      if (inlineTags.has(c.tagName)) continue;
      if (skipTags.has(c.tagName.toUpperCase())) continue;
      const s = cs(c);
      if (s.display === 'none') continue;
      if ((s.display === 'inline' || s.display === 'inline-block') && !c.querySelector('img')) continue;
      if ((c.innerText || '').trim()) return true;
    }
    return false;
  };
  const rb = document.documentElement, bd = document.body;
  let pageBg = raw(cs(bd).backgroundColor) || raw(cs(rb).backgroundColor) || '';

  function visit(e, depth) {
    if (e.nodeType !== 1) return;
    if (skipTags.has(e.tagName.toUpperCase())) return;
    const s = cs(e);
    if (s.display === 'none' || s.visibility === 'hidden') return;
    const r = rect(e);

    if (e.tagName === 'IMG') {
      const src = e.currentSrc || e.src;
      if (src && !src.startsWith('data:') && r.w > 8 && r.h > 8) {
        const a = e.closest('a');
        blocks.push(Object.assign({ type: 'image', z: order++, src, alt: e.alt || '', href: a ? a.href : '', radius: radius(s), opacity: parseFloat(s.opacity) }, r));
      }
      return;
    }

    const bg = bgUrl(s), bc = hex(s.backgroundColor), bcRaw = raw(s.backgroundColor);
    const bw = px(s.borderTopWidth);
    const hasBorder = bw > 0 && s.borderTopStyle !== 'none' && raw(s.borderTopColor);
    const shadow = s.boxShadow && s.boxShadow !== 'none' ? s.boxShadow : '';
    const grad = /gradient/.test(s.backgroundImage) ? s.backgroundImage : '';
    const shape = () => Object.assign({ z: order++, bg: bcRaw, grad, radius: radius(s), bw: hasBorder ? bw : 0, bstyle: s.borderTopStyle, bcolor: raw(s.borderTopColor), shadow, opacity: parseFloat(s.opacity) }, r);

    const isPage = r.w >= vw * 0.98 && r.h >= docH * 0.85;
    if (depth > 0 && isPage && bcRaw && !pageBg) pageBg = bcRaw;
    if (depth > 0 && !isPage && r.w >= vw * 0.9 && r.h >= 60 && (bc || bg) && r.h < docH * 0.85) {
      bands.push({ y: r.y, h: r.h, color: bc, colorRaw: bcRaw, image: bg });
      shapes.push(Object.assign(shape(), { el: e }));
    } else if (bg && r.w > 8 && r.h > 8 && !bg.startsWith('data:')) {
      blocks.push(Object.assign({ type: 'image', z: order++, src: bg, alt: '', href: '', radius: radius(s), opacity: parseFloat(s.opacity) }, r));
    } else if (depth > 0 && !isPage && r.w > 4 && r.h > 4 && (bcRaw || hasBorder || shadow || grad)) {
      shapes.push(Object.assign(shape(), { el: e }));
    }

    const txt = (e.innerText || '').trim();
    if (txt && !hasBlockTextChild(e) && !e.querySelector('img')) {
      const a = e.closest('a');
      let btnBg = '', btnRaw = '', node = e, br = r, bs = s;
      for (let i = 0; i < 4 && node && node !== document.body; i++, node = node.parentElement) {
        const ns = cs(node), c = hex(ns.backgroundColor), rr = rect(node);
        if (c && rr.w < vw * 0.6 && rr.h < 160 && (parseFloat(ns.borderTopLeftRadius) > 0 || node.tagName === 'A' || node.tagName === 'BUTTON')) { btnBg = c; btnRaw = raw(ns.backgroundColor); br = rr; bs = ns; break; }
      }
      if (btnBg) { const bn = node; shapes = shapes.filter((x) => x.el !== bn); }
      const scale = Math.min(5, Math.max(0.1, r.w / (e.offsetWidth || r.w || 1)));
      const fs = parseFloat(s.fontSize) * scale;
      const base = {
        z: order++, text: txt, html: e.innerHTML, color: hex(s.color), colorRaw: raw(s.color), size: fs,
        weight: parseInt(s.fontWeight, 10) || 400,
        align: ['left', 'center', 'right', 'justify'].includes(s.textAlign) ? s.textAlign : '',
        href: a ? a.href : '',
        font: (s.fontFamily.split(',')[0] || '').replace(/["']/g, '').trim(),
        fstyle: s.fontStyle, transform: s.textTransform,
        lh: s.lineHeight === 'normal' ? fs * 1.2 : parseFloat(s.lineHeight) * scale,
        ls: s.letterSpacing === 'normal' ? 0 : parseFloat(s.letterSpacing) * scale,
      };
      if (btnBg || e.tagName === 'BUTTON') {
        blocks.push(Object.assign({ type: 'button', bg: btnBg, bgRaw: btnRaw, radius: radius(bs), bw: px(bs.borderTopWidth), bcolor: raw(bs.borderTopColor) }, base, br));
      } else {
        blocks.push(Object.assign({ type: 'text' }, base, r));
      }
      return;
    }
    for (const c of e.children) visit(c, depth + 1);
  }

  visit(document.body, 0);
  const links = [...document.querySelectorAll('a[href]')].map((a) => ({ url: a.href, text: (a.innerText || '').trim() }));
  shapes = shapes.map((x) => { const o = Object.assign({}, x); delete o.el; return o; });
  return { title: document.title, vw, docH, pageBg, links, bands, shapes, blocks };
}

async function collectFonts(page, usedFamilies) {
  const ua = await page.browser().userAgent();
  const sheets = await page.evaluate(() => {
    const out = [];
    document.querySelectorAll('style').forEach((s) => out.push({ text: s.textContent, base: document.baseURI }));
    document.querySelectorAll('link[rel=stylesheet]').forEach((l) => out.push({ href: l.href }));
    return out;
  });
  const faces = [];
  const parse = (css, base) => {
    const re = /@font-face\s*\{([^}]*)\}/gi; let m;
    while ((m = re.exec(css))) {
      const d = {};
      m[1].split(';').forEach((x) => { const i = x.indexOf(':'); if (i > 0) d[x.slice(0, i).trim().toLowerCase()] = x.slice(i + 1).trim(); });
      const fam = (d['font-family'] || '').replace(/["']/g, '').trim();
      if (!fam || !usedFamilies.has(fam.toLowerCase())) continue;
      const urls = []; const ur = /url\(\s*["']?([^"')]+)["']?\s*\)/g; let u;
      while ((u = ur.exec(d.src || ''))) { try { urls.push(new URL(u[1], base).href); } catch (e) {} }
      const pick = urls.find((x) => /\.woff2(\?|$)/i.test(x)) || urls.find((x) => /\.woff(\?|$)/i.test(x)) || urls.find((x) => /\.(ttf|otf)(\?|$)/i.test(x));
      if (pick) faces.push({ family: fam, weight: d['font-weight'] || '400', style: d['font-style'] || 'normal', range: d['unicode-range'] || '', url: pick });
    }
  };
  for (const s of sheets) {
    try {
      if (s.text) parse(s.text, s.base);
      else if (s.href) {
        const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 10000);
        const r = await fetch(s.href, { headers: { 'User-Agent': ua }, signal: ctl.signal }); clearTimeout(t);
        if (r.ok) parse(await r.text(), s.href);
      }
    } catch (e) {}
  }
  return faces.slice(0, 80);
}

async function snapshot(url) {
  const b = await getBrowser();
  const page = await b.newPage();
  try {
    await page.setViewport({ width: VIEW_W, height: 900 });
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });
    await page.evaluate(async () => {
      await new Promise((res) => {
        let y = 0;
        const t = setInterval(() => {
          window.scrollBy(0, 600); y += 600;
          if (y >= document.body.scrollHeight + 600) { clearInterval(t); window.scrollTo(0, 0); res(); }
        }, 150);
      });
    });
    await page.evaluate(() => document.fonts && document.fonts.ready);
    await new Promise((r) => setTimeout(r, 1500));
    const snap = await page.evaluate(extract);
    const used = new Set(snap.blocks.filter((x) => x.font).map((x) => x.font.toLowerCase()));
    snap.fonts = await collectFonts(page, used);
    return snap;
  } finally {
    await page.close().catch(() => {});
  }
}

const send = (res, code, obj) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(obj)); };

http.createServer((req, res) => {
  if (req.url === '/health') return send(res, 200, { ok: true });
  if (req.method !== 'POST' || req.url !== '/snapshot') return send(res, 404, { error: 'Not found' });
  // Auth removed — TOKEN is no longer read or checked.
  let body = '';
  req.on('data', (c) => { body += c; if (body.length > 1e5) req.destroy(); });
  req.on('end', async () => {
    let url = '';
    try { url = JSON.parse(body).url; } catch (e) { return send(res, 400, { error: 'Bad JSON' }); }
    if (!allowed(url)) return send(res, 400, { error: 'Only Canva URLs are allowed' });
    try { send(res, 200, await queue(() => snapshot(url))); }
    catch (e) { send(res, 500, { error: String(e.message || e) }); }
  });
}).listen(PORT, () => console.log('WPIFY renderer on :' + PORT));