/* MEA Verwaltung: Navigation, Bestätigungen, Editor, Schutz vor Datenverlust. Ohne Abhängigkeiten. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  // Mobile Navigation
  const toggle = $('[data-nav-toggle]');
  const side = $('[data-nav]');
  if (toggle && side) {
    toggle.addEventListener('click', () => {
      const open = side.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', String(open));
      if (open) $('a', side)?.focus();
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && side.classList.contains('is-open')) { side.classList.remove('is-open'); toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); }
    });
  }

  // Menü-Akkordeon: immer nur eine Gruppe offen
  const groups = $$('[data-nav-group]');
  groups.forEach(g => g.addEventListener('toggle', () => {
    if (g.open) groups.forEach(o => { if (o !== g) o.open = false; });
  }));

  // Updatepaket in Teilen hochladen (umgeht die Upload-Grenzen des Servers)
  $$('form[data-chunk-upload]').forEach(form => {
    form.addEventListener('submit', async e => {
      const input = $('input[type=file]', form);
      const file = input && input.files && input.files[0];
      if (!file || !window.fetch || !window.crypto || !file.slice) return; // klassischer Upload
      e.preventDefault();
      const btn = $('button[type=submit]', form);
      const box = $('[data-chunk-progress]', form);
      const bar = $('progress', box);
      const msg = $('span', box);
      const size = Math.max(262144, parseInt(form.dataset.chunkSize, 10) || 1048576);
      const total = Math.max(1, Math.ceil(file.size / size));
      const id = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
      const csrf = $('input[name=_csrf]', form).value;
      btn.disabled = true; box.hidden = false;
      try {
        let next = null;
        for (let i = 0; i < total; i++) {
          const fd = new FormData();
          fd.append('_csrf', csrf); fd.append('upload_id', id); fd.append('index', String(i)); fd.append('total', String(total));
          fd.append('chunk', file.slice(i * size, (i + 1) * size), 'teil');
          let res = null, data = null;
          for (let attempt = 0; attempt < 3; attempt++) {
            try {
              res = await fetch(form.dataset.chunkUpload, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
              data = await res.json();
              if (res.ok || res.status < 500) break;
            } catch (err) { data = null; }
            await new Promise(r => setTimeout(r, 1500 * (attempt + 1)));
          }
          if (!data || !data.ok) throw new Error((data && data.error) || 'Der Upload wurde unterbrochen. Bitte erneut versuchen.');
          bar.value = Math.round(((i + 1) / total) * 100);
          msg.textContent = 'Hochgeladen: ' + bar.value + ' %';
          next = data.next || null;
        }
        msg.textContent = 'Paket wird geprüft …';
        window.location.href = next || '/verwaltung/updates/';
      } catch (err) {
        msg.textContent = err.message;
        btn.disabled = false;
      }
    });
  });

  // Fortschritt eines laufenden Updates
  const up = $('[data-update-progress]');
  if (up && ['queued', 'running'].includes(up.dataset.status)) {
    const state = $('[data-state]', up), log = $('[data-log]', up), message = $('[data-message]', up), done = $('[data-done]', up);
    const startedAt = Date.now();
    let triggered = false, finished = false;
    const trigger = () => {
      if (triggered) return;
      triggered = true;
      const fd = new FormData(); fd.append('_csrf', up.dataset.csrf); fd.append('token', up.dataset.token);
      fetch(up.dataset.runUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(() => {});
    };
    const render = d => {
      log.replaceChildren(...(d.log || []).map(t => { const li = document.createElement('li'); li.textContent = t; return li; }));
      if (d.status === 'done' || d.status === 'failed') {
        finished = true;
        state.textContent = d.status === 'done' ? '✓ ' + d.message : 'Das Update hat nicht geklappt. Die Website läuft weiter mit dem vorherigen Stand.';
        state.classList.add(d.status === 'done' ? 'is-ok' : 'is-error');
        if (d.status === 'failed') { message.textContent = d.message; message.hidden = false; }
        done.hidden = false;
      } else if (d.stale) {
        message.textContent = 'Seit über 15 Minuten keine Rückmeldung. Bitte den Entwickler informieren.';
        message.hidden = false;
      }
    };
    const poll = async () => {
      if (finished) return;
      try {
        const res = await fetch(up.dataset.statusUrl, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } });
        if (res.ok) {
          const d = await res.json();
          // Ohne Cron sofort im Hintergrund starten; mit Cron nur, falls er nach 75 Sekunden nicht angesprungen ist
          if (d.status === 'queued' && (up.dataset.cron !== '1' || Date.now() - startedAt > 75000)) trigger();
          render(d);
        }
      } catch (err) { /* während des Austauschs der Dateien kurz nicht erreichbar: weiter versuchen */ }
      if (!finished) setTimeout(poll, 2000);
    };
    poll();
  }

  // Fehlerübersicht fokussieren
  $('[data-focus]')?.focus();

  // Dialog-Helfer (statt window.confirm, barrierefrei)
  function dialog(title, bodyNode, okLabel, danger) {
    return new Promise(resolve => {
      const d = document.createElement('dialog');
      d.setAttribute('aria-labelledby', 'dlg-title');
      const h = document.createElement('h2'); h.id = 'dlg-title'; h.textContent = title; d.append(h);
      if (bodyNode) d.append(bodyNode);
      const actions = document.createElement('div'); actions.className = 'dlg-actions';
      const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'btn btn--ghost'; cancel.textContent = 'Abbrechen';
      const ok = document.createElement('button'); ok.type = 'button'; ok.className = 'btn ' + (danger ? 'btn--danger' : 'btn--primary'); ok.textContent = okLabel || 'OK';
      actions.append(cancel, ok); d.append(actions);
      document.body.append(d);
      const done = v => { d.close(); d.remove(); resolve(v); };
      cancel.addEventListener('click', () => done(false));
      ok.addEventListener('click', () => done(true));
      d.addEventListener('cancel', e => { e.preventDefault(); done(false); });
      d.showModal();
      (bodyNode && $('input,select,textarea', d)) ? $('input,select,textarea', d).focus() : ok.focus();
    });
  }

  // Bestätigung vor kritischen Aktionen
  $$('form[data-confirm]').forEach(f => {
    f.addEventListener('submit', async e => {
      if (f.dataset.confirmed) return;
      e.preventDefault();
      const danger = !!f.querySelector('.btn--danger');
      if (await dialog(f.dataset.confirm, null, 'Ja, fortfahren', danger)) { f.dataset.confirmed = '1'; dirty = false; f.requestSubmit ? f.requestSubmit() : f.submit(); }
    });
  });

  // Schutz vor ungespeicherten Änderungen
  let dirty = false;
  $$('form[data-dirty-guard]').forEach(f => {
    f.addEventListener('input', () => { dirty = true; });
    f.addEventListener('change', () => { dirty = true; });
    f.addEventListener('submit', () => { dirty = false; });
  });
  window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  // Slug aus Titel vorschlagen (nur solange leer bzw. unverändert)
  $$('input[data-slug-from]').forEach(slug => {
    const src = slug.dataset.slugFrom && document.getElementById('f-' + slug.dataset.slugFrom);
    if (!src) return;
    let touched = slug.value !== '';
    slug.addEventListener('input', () => { touched = true; });
    src.addEventListener('input', () => {
      if (touched) return;
      slug.value = src.value.toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
        .normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    });
  });

  // Zeichenzähler (SEO-Felder)
  $$('[data-counter]').forEach(inp => {
    const max = +inp.dataset.counter;
    const out = document.createElement('p'); out.className = 'field__help'; out.setAttribute('aria-live', 'polite');
    inp.closest('.field').append(out);
    const upd = () => { out.textContent = inp.value.length + ' von empfohlen höchstens ' + max + ' Zeichen'; };
    inp.addEventListener('input', upd); upd();
  });

  // Bildvorschau bei Medienauswahl
  $$('[data-media-pick]').forEach(w => {
    const sel = $('select', w), img = $('img', w);
    const upd = () => { const t = sel.selectedOptions[0]?.dataset.thumb; img.hidden = !t; if (t) img.src = t; };
    sel.addEventListener('change', upd); upd();
  });

  // Rich-Text-Editor
  let mediaCache = null;
  async function mediaList() {
    if (mediaCache) return mediaCache;
    const r = await fetch('/verwaltung/medien/auswahl.json', { credentials: 'same-origin' });
    mediaCache = r.ok ? await r.json() : [];
    return mediaCache;
  }
  $$('[data-editor]').forEach(ed => {
    const area = $('.editor__area', ed), src = $('.editor__source', ed);
    area.innerHTML = src.value;
    try { document.execCommand('styleWithCSS', false, false); document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (_) {}
    const sync = () => { if (!ed.classList.contains('is-source')) src.value = area.innerHTML; };
    area.addEventListener('input', () => { sync(); dirty = true; });
    ed.closest('form')?.addEventListener('submit', sync);
    let saved = null;
    const save = () => { const s = getSelection(); if (s.rangeCount && area.contains(s.anchorNode)) saved = s.getRangeAt(0).cloneRange(); };
    const restore = () => { area.focus(); if (saved) { const s = getSelection(); s.removeAllRanges(); s.addRange(saved); } };
    area.addEventListener('keyup', save); area.addEventListener('mouseup', save); area.addEventListener('blur', save);
    $$('.editor__bar button', ed).forEach(b => {
      b.addEventListener('mousedown', e => e.preventDefault());
      b.addEventListener('click', async () => {
        if (b.dataset.cmd) {
          restore();
          document.execCommand(b.dataset.cmd, false, b.dataset.arg ? '<' + b.dataset.arg + '>' : null);
          sync(); dirty = true; return;
        }
        const action = b.dataset.action;
        if (action === 'source') {
          const on = !ed.classList.contains('is-source');
          if (on) { src.value = area.innerHTML; } else { area.innerHTML = src.value; }
          ed.classList.toggle('is-source', on); b.setAttribute('aria-pressed', String(on));
          (on ? src : area).focus(); return;
        }
        if (action === 'link') {
          const wrap = document.createElement('div');
          wrap.innerHTML = '<div class="field"><label for="dlg-url">Adresse</label><input id="dlg-url" type="text" placeholder="/kontakt/ oder https://…" list="dlg-internal"><p class="field__help">Interne Seiten beginnen mit /. Leer lassen entfernt den Link.</p></div><datalist id="dlg-internal"></datalist>';
          const dl = $('datalist', wrap);
          fetch('/verwaltung/links.json', { credentials: 'same-origin' }).then(r => r.ok ? r.json() : []).then(list => {
            list.forEach(l => { const o = document.createElement('option'); o.value = l.url; o.label = l.label; dl.append(o); });
          });
          if (!await dialog('Link einfügen', wrap, 'Übernehmen')) return;
          const url = $('#dlg-url', wrap)?.value.trim() || '';
          restore();
          if (url === '') document.execCommand('unlink');
          else if (/^(\/|https?:\/\/|mailto:|tel:)/.test(url)) document.execCommand('createLink', false, url);
          sync(); dirty = true; return;
        }
        if (action === 'table') {
          const wrap = document.createElement('div');
          wrap.innerHTML = '<div class="grid-2"><div class="field"><label for="dlg-rows">Zeilen</label><input id="dlg-rows" type="number" min="1" max="30" value="3"></div><div class="field"><label for="dlg-cols">Spalten</label><input id="dlg-cols" type="number" min="1" max="8" value="3"></div></div><label class="check"><input type="checkbox" id="dlg-head" checked> <span>Erste Zeile als Überschrift</span></label>';
          if (!await dialog('Tabelle einfügen', wrap, 'Einfügen')) return;
          const rows = Math.min(30, Math.max(1, +$('#dlg-rows', wrap).value || 3)), cols = Math.min(8, Math.max(1, +$('#dlg-cols', wrap).value || 3));
          const head = $('#dlg-head', wrap).checked;
          let html = '<table>' + (head ? '<thead><tr>' + '<th scope="col">Überschrift</th>'.repeat(cols) + '</tr></thead>' : '') + '<tbody>';
          for (let r = 0; r < rows - (head ? 1 : 0); r++) html += '<tr>' + '<td>&nbsp;</td>'.repeat(cols) + '</tr>';
          html += '</tbody></table><p></p>';
          restore(); document.execCommand('insertHTML', false, html); sync(); dirty = true; return;
        }
        if (action === 'image') {
          const list = await mediaList();
          const wrap = document.createElement('div');
          const grid = document.createElement('div'); grid.className = 'media-grid';
          let chosen = null;
          list.forEach(m => {
            const btn = document.createElement('button'); btn.type = 'button'; btn.title = m.alt;
            const im = document.createElement('img'); im.src = m.thumb; im.alt = m.alt; btn.append(im);
            btn.addEventListener('click', () => { chosen = m; $$('button', grid).forEach(x => x.style.borderColor = ''); btn.style.borderColor = '#317566'; });
            grid.append(btn);
          });
          wrap.append(grid);
          if (!list.length) wrap.textContent = 'Die Mediathek ist leer. Laden Sie zuerst ein Bild hoch.';
          if (!await dialog('Bild aus der Mediathek', wrap, 'Einfügen') || !chosen) return;
          restore();
          const img = document.createElement('img'); img.src = chosen.src; img.alt = chosen.alt; img.width = chosen.width; img.height = chosen.height;
          document.execCommand('insertHTML', false, img.outerHTML); sync(); dirty = true;
        }
      });
    });
  });

  // Gebühren-/Feldzeilen hinzufügen
  $$('[data-repeat]').forEach(box => {
    const tpl = $('template', box), list = $('[data-repeat-list]', box);
    $('[data-repeat-add]', box)?.addEventListener('click', () => {
      const n = list.children.length;
      const frag = tpl.content.cloneNode(true);
      $$('[name]', frag).forEach(el => { el.name = el.name.replace('__i__', 'n' + n + Date.now()); });
      $$('[id]', frag).forEach(el => { el.id = el.id.replace('__i__', 'n' + n); });
      $$('label[for]', frag).forEach(el => { el.htmlFor = el.htmlFor.replace('__i__', 'n' + n); });
      list.append(frag);
      $('input,select', list.lastElementChild)?.focus();
      dirty = true;
    });
    box.addEventListener('click', e => {
      const rm = e.target.closest('[data-repeat-remove]');
      if (rm) { rm.closest('[data-repeat-item]').remove(); dirty = true; }
    });
  });
})();
