// Rendered checks for the Knowledge navigation, run against a live agent-ui server.
// Usage: node knowledge-nav-check.mjs <baseUrl> <outDir>
// Measures what a DOM-only check cannot: focus-ring visibility, numeric contrast, zero-JS navigation,
// overflow at 320px, and the keyboard order inside the navigation. Prints a table; exits 1 on a failed hard check.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const [base, outDir] = process.argv.slice(2);
mkdirSync(outDir, { recursive: true });

const results = [];
const record = (name, ok, detail) => { results.push({ name, ok, detail }); };

function luminance([r, g, b]) {
  const c = [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
}
const ratio = (a, b) => { const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };

const browser = await chromium.launch();
for (const scheme of ['light', 'dark']) {
  for (const width of [320, 375, 1280]) {
    const tag = `${scheme}@${width}`;
    const ctx = await browser.newContext({ colorScheme: scheme, viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(`${base}/knowledge?tab=findings`);

    // Resolve any CSS colour to rgb through a canvas so rgb()/color-mix()/named all work.
    const probe = await page.evaluate(() => {
      const toRgb = (css) => { const c = document.createElement('canvas').getContext('2d'); c.fillStyle = '#000'; c.fillStyle = css; c.fillRect(0, 0, 1, 1); return [...c.getImageData(0, 0, 1, 1).data].slice(0, 3); };
      const solidBg = (el) => { for (let n = el; n; n = n.parentElement) { const bg = getComputedStyle(n).backgroundColor; if (bg && !/rgba\(.*,\s*0\)$/.test(bg) && bg !== 'transparent') return bg; } return 'rgb(255,255,255)'; };
      const nav = document.querySelector('.knowledge-nav');
      const links = [...nav.querySelectorAll('a')];
      return {
        overflowPage: document.documentElement.scrollWidth - window.innerWidth,
        overflowNav: nav.scrollWidth - nav.clientWidth,
        current: nav.querySelectorAll('[aria-current="page"]').length,
        linkCount: links.length,
        text: links.map((a) => { const s = getComputedStyle(a); return { label: a.textContent.trim().replace(/\s+/g, ' '), fg: toRgb(s.color), bg: toRgb(solidBg(a)) }; }),
        groupLabels: [...nav.querySelectorAll('.knowledge-nav__label')].map((p) => { const s = getComputedStyle(p); return { label: p.textContent.trim(), fg: toRgb(s.color), bg: toRgb(solidBg(p)) }; }),
      };
    });

    record(`${tag} no horizontal page overflow`, probe.overflowPage <= 0, `overflow=${probe.overflowPage}px`);
    record(`${tag} no navigation overflow`, probe.overflowNav <= 0, `overflow=${probe.overflowNav}px`);
    record(`${tag} exactly one aria-current`, probe.current === 1, `count=${probe.current}`);

    let worst = { r: 99, label: '' };
    for (const t of [...probe.text, ...probe.groupLabels]) {
      const r = ratio(t.fg, t.bg);
      if (r < worst.r) worst = { r, label: t.label };
    }
    record(`${tag} text contrast >= 4.5:1`, worst.r >= 4.5, `lowest=${worst.r.toFixed(2)} on "${worst.label}"`);

    // Keyboard: tab until we leave the navigation, recording order and whether each stop shows a focus indicator.
    // Identify each stop by its index among the navigation's links, count a link once, and stop when focus
    // wraps back to a link already seen - so a wrapped tab cycle or a non-link focusable cannot make up the count.
    const order = [];
    const seen = new Set();
    await page.locator('body').click({ position: { x: 1, y: 1 } });
    for (let i = 0; i < 60; i++) {
      await page.keyboard.press('Tab');
      const stop = await page.evaluate(() => {
        const el = document.activeElement; if (!el) return null;
        const index = [...document.querySelectorAll('.knowledge-nav a')].indexOf(el);
        const s = getComputedStyle(el);
        const outline = s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0;
        const shadow = s.boxShadow && s.boxShadow !== 'none';
        return { index, label: (el.textContent || el.getAttribute('aria-label') || el.tagName).trim().replace(/\s+/g, ' ').slice(0, 30), indicator: outline || shadow };
      });
      if (!stop || stop.index < 0) continue;
      if (seen.has(stop.index)) break;
      seen.add(stop.index);
      order.push(stop);
      if (order.length >= probe.linkCount) break;
    }
    record(`${tag} keyboard reaches every navigation link`, order.length === probe.linkCount, `reached=${order.length}/${probe.linkCount}: ${order.map((o) => o.label).join(' > ')}`);
    record(`${tag} every focused link shows a focus indicator`, order.length > 0 && order.every((o) => o.indicator), `without=${order.filter((o) => !o.indicator).map((o) => o.label).join(',') || 'none'}`);

    if (width !== 320 || scheme === 'light') {
      await page.locator('.knowledge-nav').screenshot({ path: `${outDir}/nav-${scheme}-${width}.png` });
    }
    await ctx.close();
  }
}

// Zero-JS: with scripts disabled every destination must still be a working link.
const noJs = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 375, height: 900 } });
const jsPage = await noJs.newPage();
await jsPage.goto(`${base}/knowledge`);
const hrefs = await jsPage.$$eval('.knowledge-nav a', (as) => as.map((a) => a.getAttribute('href')));
let reachable = 0;
for (const href of hrefs) { const r = await jsPage.goto(base + href); if (r && r.status() === 200) reachable++; }
record('zero-JS: every navigation destination answers 200', reachable === hrefs.length && hrefs.length > 0, `${reachable}/${hrefs.length}`);
await noJs.close();
await browser.close();

const width = Math.max(...results.map((r) => r.name.length));
for (const r of results) console.log(`${r.ok ? 'PASS' : 'FAIL'}  ${r.name.padEnd(width)}  ${r.detail}`);
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
