// MEA Website – kleine Verbesserungen. Die Seite funktioniert auch ohne JavaScript.
(() => {
  'use strict';
  document.documentElement.classList.add('js');

  // Mobiles Menü
  const nav = document.getElementById('site-nav');
  const openBtn = document.querySelector('[data-nav-open]');
  const closeBtn = document.querySelector('[data-nav-close]');
  let backdrop = null;
  const setNav = (open) => {
    if (!nav || !openBtn) return;
    nav.classList.toggle('is-open', open);
    openBtn.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('nav-open', open);
    if (open) {
      backdrop = document.createElement('div');
      backdrop.className = 'nav-backdrop';
      backdrop.addEventListener('click', () => setNav(false));
      document.body.appendChild(backdrop);
      (closeBtn || nav).focus();
    } else {
      backdrop?.remove();
      backdrop = null;
      openBtn.focus();
    }
  };
  openBtn?.addEventListener('click', () => setNav(true));
  closeBtn?.addEventListener('click', () => setNav(false));
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (nav?.classList.contains('is-open')) setNav(false);
    document.querySelectorAll('.nav__item.is-open').forEach((i) => toggleItem(i, false));
  });

  // Untermenüs
  const toggleItem = (item, open) => {
    item.classList.toggle('is-open', open);
    item.querySelector('.nav__toggle')?.setAttribute('aria-expanded', String(open));
  };
  document.querySelectorAll('.nav__toggle').forEach((btn) => {
    btn.addEventListener('click', () => {
      const item = btn.closest('.nav__item');
      const open = !item.classList.contains('is-open');
      document.querySelectorAll('.nav__item.is-open').forEach((i) => i !== item && toggleItem(i, false));
      toggleItem(item, open);
    });
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.nav__item')) {
      document.querySelectorAll('.nav__item.is-open').forEach((i) => toggleItem(i, false));
    }
  });

  // Sanftes Einblenden
  const items = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) {
          en.target.classList.add('is-visible');
          io.unobserve(en.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px' });
    items.forEach((el) => io.observe(el));
  } else {
    items.forEach((el) => el.classList.add('is-visible'));
  }

  // Karte erst nach Klick oder mit Einwilligung „Externe Medien“ laden (Datenschutz)
  const loadMap = (box) => {
    const src = box?.dataset.mapSrc;
    if (!box || !src || box.querySelector('iframe')) return;
    if (!src.startsWith('https://www.google.com/maps')) return;
    const frame = document.createElement('iframe');
    frame.src = src;
    frame.title = box.dataset.mapTitle || 'Karte';
    frame.loading = 'lazy';
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    box.replaceChildren(frame);
  };
  document.querySelectorAll('[data-map-load]').forEach((btn) => {
    btn.addEventListener('click', () => loadMap(btn.closest('.map')));
  });
  document.addEventListener('mea:consent', (e) => {
    if (Array.isArray(e.detail) && e.detail.includes('media')) document.querySelectorAll('.map[data-map-src]').forEach(loadMap);
  });

  // E-Mail-Adresse erst bei Klick zusammensetzen (Schutz vor einfachem Auslesen)
  document.querySelectorAll('[data-m]').forEach((a) => {
    a.addEventListener('click', (e) => {
      try {
        const addr = atob(a.dataset.m).split('').reverse().join('');
        if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(addr)) return;
        e.preventDefault();
        window.location.href = 'mailto:' + addr;
      } catch (_) { /* Fallback: Link zum Kontaktformular */ }
    });
  });
})();
