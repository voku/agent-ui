// Contrast audit of every visible text element on the main pages, light and dark.
// Usage: node contrast-audit.mjs <baseUrl> [--strict]
// Reports failures grouped by (scheme, colour, background) with a sample, so a design token can be fixed once.
// WCAG AA: 4.5:1, or 3:1 for large text (>=24px, or >=18.66px bold). Elements on gradients/images are reported as "unknown", not judged.
import { chromium } from 'playwright';

const [base, flag] = process.argv.slice(2);
const strict = flag === '--strict';
const PAGES = ['/', '/board', '/task/APP-1', '/task/APP-1/learning', '/task/APP-1/context', '/task/APP-1/work', '/task/APP-1/history',
  '/knowledge', '/knowledge?tab=findings', '/knowledge?tab=analytics', '/knowledge/dream', '/map', '/prompts', '/commands', '/setup'];

const audit = () => {
  const parse = (css) => { const m = css.match(/rgba?\(([^)]+)\)/); if (!m) return null; const [r, g, b, a = 1] = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return { r, g, b, a }; };
  const over = (top, bottom) => ({ r: top.r * top.a + bottom.r * (1 - top.a), g: top.g * top.a + bottom.g * (1 - top.a), b: top.b * top.a + bottom.b * (1 - top.a), a: 1 });
  const lum = ({ r, g, b }) => { const c = [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
  const ratio = (a, b) => { const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };
  const background = (el) => {
    const layers = []; let unknown = false;
    for (let n = el; n; n = n.parentElement) {
      const s = getComputedStyle(n);
      if (s.backgroundImage && s.backgroundImage !== 'none') { unknown = true; break; }
      const c = parse(s.backgroundColor); if (c && c.a > 0) { layers.push(c); if (c.a === 1) break; }
    }
    let base = { r: 255, g: 255, b: 255, a: 1 };
    const root = parse(getComputedStyle(document.documentElement).backgroundColor) ?? parse(getComputedStyle(document.body).backgroundColor);
    if (root && root.a === 1) base = root;
    for (const c of layers.reverse()) base = over(c, base);
    return { bg: base, unknown };
  };
  const out = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  const seen = new Set();
  for (let t = walker.nextNode(); t; t = walker.nextNode()) {
    if (!t.textContent.trim()) continue;
    const el = t.parentElement; if (!el || seen.has(el)) continue; seen.add(el);
    const s = getComputedStyle(el); const r = el.getBoundingClientRect();
    if (s.visibility === 'hidden' || s.display === 'none' || r.width === 0 || r.height === 0 || ['SCRIPT', 'STYLE'].includes(el.tagName)) continue;
    let fg = parse(s.color); if (!fg) continue;
    const { bg, unknown } = background(el);
    fg = over({ ...fg, a: fg.a * parseFloat(s.opacity || 1) }, bg);
    const size = parseFloat(s.fontSize); const bold = parseInt(s.fontWeight, 10) >= 700;
    const large = size >= 24 || (size >= 18.66 && bold);
    const need = large ? 3 : 4.5;
    const cls = (el.className && typeof el.className === 'string') ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
    out.push({ sel: el.tagName.toLowerCase() + cls, text: t.textContent.trim().slice(0, 40), ratio: ratio(fg, bg), need, unknown, fg: `rgb(${Math.round(fg.r)},${Math.round(fg.g)},${Math.round(fg.b)})`, bgc: `rgb(${Math.round(bg.r)},${Math.round(bg.g)},${Math.round(bg.b)})`, size });
  }
  return out;
};

const browser = await chromium.launch();
const groups = new Map(); let checked = 0; let unknown = 0;
for (const scheme of ['light', 'dark']) {
  const ctx = await browser.newContext({ colorScheme: scheme, viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  for (const path of PAGES) {
    const res = await page.goto(base + path);
    if (!res || res.status() !== 200) { console.log(`SKIP  ${scheme} ${path} -> ${res?.status()}`); continue; }
    for (const r of await page.evaluate(audit)) {
      checked++;
      if (r.unknown) { unknown++; continue; }
      if (r.ratio >= r.need) continue;
      const key = `${scheme} fg=${r.fg} bg=${r.bgc} need=${r.need}`;
      const g = groups.get(key) ?? { count: 0, min: 99, pages: new Set(), samples: new Set() };
      g.count++; g.min = Math.min(g.min, r.ratio); g.pages.add(path); if (g.samples.size < 4) g.samples.add(`${r.sel} "${r.text}"`);
      groups.set(key, g);
    }
  }
  await ctx.close();
}
await browser.close();

console.log(`audited ${checked} text elements on ${PAGES.length} pages x 2 schemes (${unknown} on gradients/images not judged)`);
const sorted = [...groups.entries()].sort((a, b) => b[1].count - a[1].count);
for (const [key, g] of sorted) {
  console.log(`LOW  ${key}  lowest=${g.min.toFixed(2)}  elements=${g.count}  pages=${[...g.pages].slice(0, 5).join(' ')}${g.pages.size > 5 ? '...' : ''}`);
  for (const s of g.samples) console.log(`       e.g. ${s}`);
}
const total = sorted.reduce((n, [, g]) => n + g.count, 0);
console.log(`\n${total} low-contrast elements in ${sorted.length} colour pairs`);
process.exit(strict && total > 0 ? 1 : 0);
