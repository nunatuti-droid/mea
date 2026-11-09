// Browser-Rauchtest mit Barrierefreiheitsprüfung (axe-core, WCAG 2.2 AA).
// Aufruf: BASE_URL=http://127.0.0.1:8080 node tests/e2e/smoke.js
const { chromium } = require('playwright');
const fs = require('fs');
const axeSource = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const PAGES = ['/', '/leistungen/', '/avgs/', '/avgs/mea-plus/', '/telc-pruefungszentrum/', '/pruefungen/telc-deutsch-b1/',
  '/preise/', '/standorte/', '/standorte/marl/', '/kontakt/', '/faq/', '/ueber-uns/', '/impressum/'];
const VIEWPORTS = [{ width: 1366, height: 900 }, { width: 375, height: 800 }];

(async () => {
  const browser = await chromium.launch();
  let failed = 0;
  for (const vp of VIEWPORTS) {
    const page = await browser.newPage({ viewport: vp, bypassCSP: true });
    const errors = [];
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', e => errors.push(e.message));
    for (const path of PAGES) {
      errors.length = 0;
      const res = await page.goto(BASE + path, { waitUntil: 'networkidle' });
      const problems = [];
      if (res.status() !== 200) problems.push('Status ' + res.status());
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      if (overflow > 1) problems.push(`horizontal scroll ${overflow}px`);
      await page.addScriptTag({ content: axeSource });
      const result = await page.evaluate(() => axe.run(document, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
      }));
      for (const v of result.violations) {
        problems.push(`axe ${v.id} (${v.impact}): ${v.nodes.length}× ${v.nodes[0].target.join(' ')}`);
      }
      problems.push(...errors.map(e => 'console: ' + e));
      const tag = `${vp.width}px ${path}`;
      if (problems.length) { failed++; console.log('✗', tag, '\n   ' + problems.join('\n   ')); }
      else console.log('✓', tag);
    }
    await page.close();
  }

  // Ablauf: Prüfung finden → Termin wählen → Buchungshinweis
  const page = await browser.newPage();
  await page.goto(BASE + '/telc-pruefungszentrum/');
  await page.click('a[href="/pruefungen/telc-deutsch-b1/"]');
  const book = page.locator('a[href*="/buchen/"]').first();
  if (await book.count()) {
    await book.click();
    const ok = (await page.locator('h1').innerText()).length > 0;
    console.log(ok ? '✓ Ablauf Prüfung → Termin → Buchung' : '✗ Ablauf Buchung');
    if (!ok) failed++;
  } else console.log('– kein buchbarer Termin (ohne Beispieldaten erwartet)');

  // Mobilmenü öffnen
  const m = await browser.newPage({ viewport: { width: 375, height: 800 } });
  await m.goto(BASE + '/');
  await m.click('[aria-controls="site-nav"]');
  const expanded = await m.getAttribute('[aria-controls="site-nav"]', 'aria-expanded');
  console.log(expanded === 'true' ? '✓ Mobilmenü' : '✗ Mobilmenü'); if (expanded !== 'true') failed++;

  await browser.close();
  process.exit(failed ? 1 : 0);
})();
