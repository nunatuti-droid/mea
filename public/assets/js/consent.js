// Cookie-Einwilligung: nichts Optionales wird geladen, bevor zugestimmt wurde.
// Auswahl liegt im Cookie „mea_consent“ (12 Monate); jede Auswahl wird ohne IP-Adresse protokolliert.
(() => {
  'use strict';
  const cfgEl = document.getElementById('consent-config');
  if (!cfgEl) return;
  let cfg;
  try { cfg = JSON.parse(cfgEl.textContent); } catch (_) { return; }
  const banner = document.getElementById('consent-banner');
  const dialog = document.getElementById('consent-dialog');
  const NAME = 'mea_consent';

  const read = () => {
    const m = document.cookie.match(/(?:^|;\s*)mea_consent=([^;]+)/);
    if (!m) return null;
    try {
      const v = JSON.parse(decodeURIComponent(m[1]));
      return v && v.v === cfg.version && Array.isArray(v.c) ? v : null;
    } catch (_) { return null; }
  };
  const newId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');

  const write = (cats, action) => {
    const old = read();
    const v = { v: cfg.version, id: (old && old.id) || newId(), c: cats, t: Date.now() };
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${NAME}=${encodeURIComponent(JSON.stringify(v))}; Max-Age=${60 * 60 * 24 * 365}; Path=/; SameSite=Lax${secure}`;
    const body = new URLSearchParams({ id: v.id, action });
    cats.forEach((c) => body.append('categories[]', c));
    fetch('/einwilligung/', { method: 'POST', body, credentials: 'same-origin', keepalive: true }).catch(() => {});
    // Widerruf: bereits geladene Dienste lassen sich nur durch Neuladen entfernen
    const had = (old && old.c) || [];
    if (had.some((c) => c !== 'necessary' && !cats.includes(c))) { location.reload(); return; }
    apply(cats);
  };

  const loaded = {};
  const addScript = (src) => { const s = document.createElement('script'); s.src = src; s.async = true; document.head.appendChild(s); };
  const apply = (cats) => {
    window.meaConsent = cats.slice();
    if (cats.includes('statistics') && cfg.ga && !loaded.ga) {
      loaded.ga = true;
      window.dataLayer = window.dataLayer || [];
      window.gtag = function () { window.dataLayer.push(arguments); };
      window.gtag('js', new Date());
      window.gtag('config', cfg.ga, { anonymize_ip: true });
      addScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(cfg.ga));
    }
    if (cats.includes('marketing') && cfg.pixel && !loaded.pixel) {
      loaded.pixel = true;
      const f = window.fbq = function () { f.callMethod ? f.callMethod.apply(f, arguments) : f.queue.push(arguments); };
      f.push = f; f.loaded = true; f.version = '2.0'; f.queue = [];
      window.fbq('init', cfg.pixel);
      window.fbq('track', 'PageView');
      addScript('https://connect.facebook.net/en_US/fbevents.js');
    }
    document.dispatchEvent(new CustomEvent('mea:consent', { detail: cats }));
  };

  const optional = cfg.categories.filter((c) => c !== 'necessary');
  const decide = (kind) => {
    let cats = ['necessary'];
    if (kind === 'accept') cats = cfg.categories.slice();
    if (kind === 'save' && dialog) {
      cats = cats.concat(Array.from(dialog.querySelectorAll('input[name="cat"]:checked:not(:disabled)'), (i) => i.value));
    }
    write(cats, kind === 'accept' ? 'accept_all' : kind === 'reject' ? 'reject_all' : 'custom');
    if (banner) banner.hidden = true;
    if (dialog && dialog.open) dialog.close();
  };

  const openSettings = () => {
    if (!dialog) return;
    const cur = (read() || { c: ['necessary'] }).c;
    dialog.querySelectorAll('input[name="cat"]:not(:disabled)').forEach((i) => { i.checked = cur.includes(i.value); });
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
  };

  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-consent], [data-consent-open]');
    if (!b) return;
    if (b.hasAttribute('data-consent-open')) { e.preventDefault(); openSettings(); return; }
    decide(b.dataset.consent);
  });

  const current = read();
  if (current) apply(current.c);
  else if (banner && optional.length) banner.hidden = false;
  else if (!optional.length) apply(['necessary']);
})();
