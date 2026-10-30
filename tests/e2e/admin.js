// Browser-Test der Verwaltung: Anmeldung mit Zwei-Faktor, alle Seiten, Barrierefreiheit (axe-core),
// keine Konsolenfehler, kein seitliches Scrollen. Nur gegen eine lokale Test-Installation ausführen.
// Aufruf: BASE=http://localhost:8080 ADMIN_EMAIL=… ADMIN_PASSWORD=… ADMIN_TOTP_SECRET=… node tests/e2e/admin.js
const { chromium } = require('playwright');
const fs = require('fs');
const { execFileSync } = require('child_process');
const axeSource = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const BASE = process.env.BASE || 'http://localhost:8080';
const totp = () => execFileSync('php', ['-r', 'require "vendor/autoload.php"; echo OTPHP\\TOTP::createFromSecret($argv[1])->now();', process.env.ADMIN_TOTP_SECRET]).toString().trim();

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ bypassCSP: true, viewport: { width: 1366, height: 900 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', e => errors.push(e.message));

  await page.goto(BASE + '/verwaltung/anmelden/');
  await page.fill('#email', process.env.ADMIN_EMAIL);
  await page.fill('#password', process.env.ADMIN_PASSWORD);
  await page.click('form button[type=submit]');
  await page.waitForURL('**/anmelden/code/');
  await new Promise(r => setTimeout(r, (31 - (Math.floor(Date.now() / 1000) % 30)) * 1000)); // frischer Code
  await page.fill('#code', totp());
  await page.click('form button[type=submit]');
  await page.waitForURL(BASE + '/verwaltung/');

  const links = await page.$$eval('.adm-side nav a', as => as.map(a => a.getAttribute('href')));
  const pages = [...links, '/verwaltung/konto/', '/verwaltung/pruefungen/neu/', '/verwaltung/termine/neu/', '/verwaltung/kalender/?ansicht=woche'];
  let failed = 0;
  for (const vp of [{ width: 1366, height: 900 }, { width: 375, height: 800 }]) {
    await page.setViewportSize(vp);
    for (const path of pages) {
      for (const target of [path, 'detail']) {
        if (target === 'detail') {
          const a = await page.$('table.tbl tbody tr th a, .lib__item a');
          if (!a || path.includes('neu') || path.includes('kalender')) continue;
          await page.goto(BASE + await a.getAttribute('href'), { waitUntil: 'networkidle' });
        } else {
          errors.length = 0;
          const res = await page.goto(BASE + path, { waitUntil: 'networkidle' });
          if (res.status() !== 200) { failed++; console.log('✗', path, res.status()); continue; }
        }
        const problems = [];
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        if (overflow > 1) problems.push(`horizontal scroll ${overflow}px`);
        await page.addScriptTag({ content: axeSource });
        const result = await page.evaluate(() => axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } }));
        for (const v of result.violations) problems.push(`axe ${v.id} (${v.impact}): ${v.nodes.length}× ${v.nodes[0].target.join(' ')}`);
        problems.push(...errors.map(e => 'console: ' + e));
        const tag = `${vp.width}px ${page.url().replace(BASE, '')}`;
        if (problems.length) { failed++; console.log('✗', tag, '\n   ' + problems.join('\n   ')); } else console.log('✓', tag);
      }
    }
  }
  await browser.close();
  console.log(failed ? `${failed} Seite(n) mit Problemen` : 'Alle Verwaltungsseiten in Ordnung');
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
