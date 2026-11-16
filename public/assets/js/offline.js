/* Wartungsseite: Video erscheint nach 5 Sekunden automatisch und startet ohne Ton. */
(() => {
  const box = document.querySelector('[data-offline-video]');
  const video = box && box.querySelector('video');
  if (!video) return;
  const d = box.dataset;
  // Hochformat (Handy) bekommt die 9:16-Fassung, sonst 16:9; fehlt eine Fassung, gilt die andere
  const portrait = window.matchMedia('(max-aspect-ratio: 1/1)').matches;
  const src = (portrait ? d.srcMobile || d.srcDesktop : d.srcDesktop || d.srcMobile) || '';
  if (!src) return;
  box.classList.toggle('offline__video--portrait', src === d.srcMobile && src !== d.srcDesktop);
  const close = () => { video.pause(); box.hidden = true; };
  box.querySelector('[data-offline-video-close]').addEventListener('click', close);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !box.hidden) close(); });
  box.addEventListener('click', (e) => { if (e.target === box) close(); });
  window.setTimeout(() => {
    video.src = src;
    box.hidden = false;
    // Ohne Ton starten, damit der Browser das automatische Abspielen erlaubt; bei Bewegungsreduzierung nicht automatisch
    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) video.play().catch(() => {});
    box.querySelector('[data-offline-video-close]').focus();
  }, 5000);
})();
