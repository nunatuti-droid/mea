// MEA Seitenbaukasten – visueller Editor.
// Datenmodell: eine Liste von Knoten {id, type, f: Felder, s: {d|t|m: Stil}, c: Kinder, off, name}.
// Der Server prüft jeden Aufbau (TreeValidator) und stellt ihn mit denselben Templates dar wie die Website.
(() => {
  'use strict';

  const cfg = JSON.parse(document.getElementById('bld-config').textContent);
  const T = cfg.blocks.types;
  const DEF = cfg.blocks.defaults;
  const PROPS = cfg.style.props;
  const ROOT_OK = ['section', 'component', 'hero_home', 'page_hero', 'cta', 'partners'];
  const IMPORTANT = ['section', 'columns', 'group', 'hero_home', 'page_hero', 'contact_form', 'exam_list', 'component'];
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const state = {
    tree: cfg.tree, sel: null, rev: cfg.rev, dirty: false, hasDraft: cfg.has_draft,
    past: [], future: [], lastKey: null, lastAt: 0,
    device: 'd', subtab: 'content', errors: [], drag: null, editing: null,
    components: [], media: new Map(), links: [],
  };

  // ---------- Hilfen ----------
  // append/replaceChildren überspringen in diesem Editor leere Werte (null, false), damit Bausteine bedingt eingefügt werden können
  const cleanKids = (k) => k.flat(Infinity).filter((x) => x !== null && x !== undefined && x !== false);
  for (const proto of [Element.prototype, DocumentFragment.prototype]) {
    const a = proto.append, r = proto.replaceChildren;
    proto.append = function (...k) { return a.apply(this, cleanKids(k)); };
    proto.replaceChildren = function (...k) { return r.apply(this, cleanKids(k)); };
  }
  const h = (tag, attrs = {}, ...kids) => {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else el.setAttribute(k, v === true ? '' : v);
    }
    for (const kid of kids.flat(Infinity)) if (kid !== null && kid !== undefined && kid !== false) el.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
    return el;
  };
  const svg = (name) => {
    const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('class', 'icon'); s.setAttribute('aria-hidden', 'true');
    const u = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    u.setAttribute('href', '#i-' + name); s.append(u);
    return s;
  };
  const uid = () => Array.from(crypto.getRandomValues(new Uint8Array(4)), (b) => b.toString(16).padStart(2, '0')).join('');
  const clone = (o) => JSON.parse(JSON.stringify(o));
  const strip = (s) => String(s || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  const reId = (n) => { n.id = uid(); (n.c || []).forEach(reId); return n; };

  const toastEl = $('[data-toast]');
  let toastTimer;
  const toast = (msg, error = false) => {
    toastEl.textContent = msg;
    toastEl.className = 'bld-toast' + (error ? ' bld-toast--error' : '');
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toastEl.hidden = true; }, error ? 6000 : 2600);
  };

  const post = async (url, data) => {
    const fd = new FormData();
    fd.append('_csrf', cfg.csrf);
    for (const [k, v] of Object.entries(data || {})) fd.append(k, v);
    const r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    let json = null;
    try { json = await r.json(); } catch { json = { ok: false, errors: [{ id: '', msg: 'Antwort des Servers nicht lesbar (Status ' + r.status + '). Bitte neu anmelden oder die Seite neu laden.' }] }; }
    return { status: r.status, json };
  };

  // ---------- Baum ----------
  const find = (id, list = state.tree, parent = null) => {
    for (let i = 0; i < list.length; i++) {
      const n = list[i];
      if (n.id === id) return { node: n, list, index: i, parent };
      if (n.c && n.c.length) { const r = find(id, n.c, n); if (r) return r; }
    }
    return null;
  };
  const ancestors = (id) => {
    const out = [];
    let r = find(id);
    while (r && r.parent) { out.unshift(r.parent); r = find(r.parent.id); }
    return out;
  };
  const contains = (node, id) => node.id === id || (node.c || []).some((c) => contains(c, id));
  const countType = (type, list = state.tree) => list.reduce((n, x) => n + (x.type === type ? 1 : 0) + countType(type, x.c || []), 0);
  const allowed = (child, parent) => {
    if (!parent) return !['column', 'button'].includes(child);
    const rule = T[parent]?.rule;
    if (!rule) return false;
    if (rule.only && !rule.only.includes(child)) return false;
    if (rule.except && rule.except.includes(child)) return false;
    const oi = T[child]?.only_in;
    return !(oi && !oi.includes(parent));
  };
  const colCount = (layout) => String(layout || '1-1').split('-').length;
  const newNode = (type, extra = {}) => {
    const n = { id: uid(), type, f: clone(DEF[type] || {}), s: {}, c: [] , ...extra };
    if (type === 'columns') for (let i = 0; i < colCount(n.f.layout); i++) n.c.push(newNode('column'));
    if (type === 'buttons') n.c.push(newNode('button'));
    return n;
  };
  const label = (n) => {
    if (n.type === 'component') {
      const c = state.components.find((x) => x.id === Number(n.f?.ref));
      return 'Global: ' + (c ? c.name : 'Baustein');
    }
    return n.name || T[n.type]?.label || n.type;
  };
  const snippet = (n) => {
    const f = n.f || {};
    const s = strip(f.text || f.title || f.label || f.html || f.eyebrow || '');
    return s.length > 34 ? s.slice(0, 33) + '…' : s;
  };
  const getPath = (obj, path) => path.split('.').reduce((o, k) => (o == null ? o : o[k]), obj);
  const setPath = (obj, path, v) => {
    const keys = path.split('.');
    let o = obj;
    keys.slice(0, -1).forEach((k) => { if (o[k] == null) o[k] = {}; o = o[k]; });
    o[keys[keys.length - 1]] = v;
  };

  // ---------- Verlauf und Änderungen ----------
  const snapshot = () => JSON.stringify({ tree: state.tree, sel: state.sel });
  const change = (fn, opts = {}) => {
    const now = Date.now();
    if (!(opts.key && opts.key === state.lastKey && now - state.lastAt < 1500)) {
      state.past.push(snapshot());
      if (state.past.length > 100) state.past.shift();
      state.future = [];
    }
    state.lastKey = opts.key || null;
    state.lastAt = now;
    fn();
    setDirty(true);
    if (!opts.noRender) scheduleRender(opts.now ? 0 : 250);
    renderTree();
    if (!opts.keepInspector) renderInspector();
    updateHistoryButtons();
  };
  const restore = (snap) => {
    const s = JSON.parse(snap);
    state.tree = s.tree; state.sel = s.sel && find(s.sel) ? s.sel : null;
    state.lastKey = null;
    setDirty(true); scheduleRender(0); renderTree(); renderInspector(); updateHistoryButtons();
  };
  const undo = () => { if (!state.past.length) return; state.future.push(snapshot()); restore(state.past.pop()); };
  const redo = () => { if (!state.future.length) return; state.past.push(snapshot()); restore(state.future.pop()); };
  const updateHistoryButtons = () => { $('[data-undo]').disabled = !state.past.length; $('[data-redo]').disabled = !state.future.length; };

  const stateEl = $('[data-state]');
  const setDirty = (d, text) => {
    state.dirty = d;
    stateEl.classList.toggle('is-dirty', d);
    stateEl.textContent = d ? 'Ungespeicherte Änderungen' : (text || (state.hasDraft ? 'Entwurf gespeichert, nicht veröffentlicht' : 'Veröffentlicht, keine offenen Änderungen'));
  };
  window.addEventListener('beforeunload', (e) => { if (state.dirty) { e.preventDefault(); e.returnValue = ''; } });

  // ---------- Operationen ----------
  const insert = (node, parentId, index) => {
    const list = parentId ? find(parentId).node.c : state.tree;
    list.splice(index, 0, node);
  };
  const removeNode = (id) => { const r = find(id); if (r) r.list.splice(r.index, 1); return r ? r.node : null; };

  /** Platz für ein neues Element: im ausgewählten Container, sonst dahinter, sonst am Ende der Seite. */
  const placeFor = (type) => {
    let r = state.sel ? find(state.sel) : null;
    if (r && T[r.node.type]?.children && allowed(type, r.node.type) && r.node.type !== 'columns') return { parent: r.node.id, index: r.node.c.length };
    if (r && r.node.type === 'columns' && allowed(type, 'column')) return { parent: r.node.c[0].id, index: r.node.c[0].c.length };
    while (r) {
      const pType = r.parent ? r.parent.type : null;
      if (allowed(type, pType) && (pType || ROOT_OK.includes(type))) return { parent: r.parent ? r.parent.id : null, index: r.index + 1 };
      r = r.parent ? find(r.parent.id) : null;
    }
    return { parent: null, index: state.tree.length };
  };
  /** Fügt ein; Inhalte direkt auf Seitenebene kommen automatisch in einen Abschnitt. */
  const place = (node, parent, index) => {
    if (!parent && !ROOT_OK.includes(node.type)) {
      const sec = newNode('section');
      sec.c.push(node);
      insert(sec, null, index);
    } else insert(node, parent, index);
  };
  const addType = (type, at) => {
    if (T[type]?.single && countType(type) > 0) { toast('„' + T[type].label + '“ gibt es auf einer Seite nur einmal.', true); return; }
    const node = type === 'component' ? newNode('component') : newNode(type);
    const p = at || placeFor(type);
    change(() => { place(node, p.parent, p.index); state.sel = node.id; }, { now: true });
    showTab('props');
  };
  const addComponent = (ref, at) => {
    if (cfg.kind === 'component') { toast('Globale Bausteine können nicht verschachtelt werden.', true); return; }
    const node = newNode('component');
    node.f.ref = ref;
    const p = at || placeFor('component');
    change(() => { place(node, p.parent, p.index); state.sel = node.id; }, { now: true });
  };
  const move = (id, dir) => {
    const r = find(id);
    if (!r) return;
    const j = r.index + dir;
    if (j < 0 || j >= r.list.length) return;
    change(() => { r.list.splice(r.index, 1); r.list.splice(j, 0, r.node); }, { now: true });
  };
  const duplicate = (id) => {
    const r = find(id);
    if (!r) return;
    if (r.node.type === 'column') { toast('Spalten bitte über „Aufteilung“ der Spalten ändern.', true); return; }
    if (T[r.node.type]?.single) { toast('Dieses Element gibt es nur einmal pro Seite.', true); return; }
    const copy = reId(clone(r.node));
    change(() => { r.list.splice(r.index + 1, 0, copy); state.sel = copy.id; }, { now: true });
  };
  const toggleOff = (id) => {
    const r = find(id);
    if (r) change(() => { if (r.node.off) delete r.node.off; else r.node.off = true; }, { now: true });
  };
  const remove = async (id) => {
    const r = find(id);
    if (!r) return;
    if (r.node.type === 'column') { toast('Spalten bitte über „Aufteilung“ der Spalten ändern.', true); return; }
    if ((r.node.c && r.node.c.some((c) => c.type !== 'column' || c.c.length)) || IMPORTANT.includes(r.node.type)) {
      const ok = await confirmBox('Element löschen?', '„' + label(r.node) + '“' + (r.node.c?.length ? ' samt allen enthaltenen Elementen' : '') + ' wird aus der Seite entfernt. Mit „Rückgängig“ lässt sich das bis zum Verlassen des Editors zurückholen.', 'Löschen', true);
      if (!ok) return;
    }
    change(() => { removeNode(id); state.sel = r.parent ? r.parent.id : null; }, { now: true });
  };
  const setColumns = (node, layout) => {
    const want = colCount(layout);
    while (node.c.length < want) node.c.push(newNode('column'));
    while (node.c.length > want) {
      const last = node.c.pop();
      node.c[node.c.length - 1].c.push(...last.c);
    }
  };

  // ---------- Darstellung im Arbeitsbereich ----------
  const frame = $('[data-frame]');
  let doc = null, sheet = null, renderTimer = null, renderSeq = 0, ov = {};
  const scheduleRender = (ms) => { clearTimeout(renderTimer); renderTimer = setTimeout(renderCanvas, ms); };
  const renderCanvas = async () => {
    if (!doc || state.editing) return;
    const seq = ++renderSeq;
    const { json } = await post(cfg.base + 'darstellen/', { tree: JSON.stringify(state.tree) });
    if (seq !== renderSeq || !doc) return;
    if (!json || !json.ok) { showErrors(json?.errors || []); return; }
    const main = doc.getElementById('inhalt');
    const y = doc.defaultView.scrollY;
    main.innerHTML = json.html;
    try {
      if (!sheet) { sheet = new doc.defaultView.CSSStyleSheet(); doc.adoptedStyleSheets = [...doc.adoptedStyleSheets, sheet]; }
      sheet.replaceSync(json.css);
    } catch (e) { console.warn('Stil konnte nicht angewendet werden', e); }
    doc.defaultView.scrollTo(0, y);
    showErrors(json.errors || []);
    positionOverlays();
  };
  const showErrors = (errors) => {
    state.errors = errors;
    const box = $('[data-errors]');
    box.replaceChildren();
    box.hidden = !errors.length;
    if (errors.length) {
      box.append(h('strong', {}, 'Bitte prüfen:'), h('ul', {}, errors.slice(0, 8).map((e) => h('li', {}, e.id && find(e.id) ? h('button', { type: 'button', onclick: () => select(e.id, true) }, label(find(e.id).node)) : null, e.id && find(e.id) ? ': ' : '', e.msg))));
    }
    if (state.sel) renderInspector();
  };

  const elFor = (id) => doc && doc.querySelector('[data-b="' + CSS.escape(id) + '"]');
  const rectOf = (el) => {
    let r = el.getBoundingClientRect();
    if (!r.width && !r.height && el.children.length) {
      const rs = Array.from(el.children).map((c) => rectOf(c));
      const top = Math.min(...rs.map((x) => x.top)), left = Math.min(...rs.map((x) => x.left));
      r = { top, left, width: Math.max(...rs.map((x) => x.right)) - left, height: Math.max(...rs.map((x) => x.bottom)) - top, right: Math.max(...rs.map((x) => x.right)), bottom: Math.max(...rs.map((x) => x.bottom)) };
    }
    return r;
  };
  const putBox = (box, el) => {
    if (!el) { box.hidden = true; return; }
    const r = rectOf(el), w = doc.defaultView;
    box.hidden = false;
    Object.assign(box.style, { top: (r.top + w.scrollY) + 'px', left: (r.left + w.scrollX) + 'px', width: r.width + 'px', height: r.height + 'px' });
  };
  const positionOverlays = () => {
    if (!doc || !ov.sel) return;
    const el = state.sel ? elFor(state.sel) : null;
    putBox(ov.sel, el);
    if (el) {
      const n = find(state.sel)?.node;
      ov.sel.classList.toggle('is-global', n?.type === 'component');
      ov.name.textContent = n ? label(n) : '';
      ov.tag.classList.toggle('is-below', rectOf(el).top + doc.defaultView.scrollY < 28);
    }
  };
  /** Nächstes auswählbares Element (Elemente in globalen Bausteinen wählen den Baustein selbst). */
  const pick = (target) => {
    let el = target?.closest?.('[data-b]');
    while (el && !find(el.dataset.b)) el = el.parentElement?.closest('[data-b]');
    return el;
  };

  const setupFrame = () => {
    doc = frame.contentDocument;
    sheet = null;
    if (!doc || !doc.getElementById('inhalt')) return;
    const mk = (cls) => { const d = doc.createElement('div'); d.className = 'bld-ov ' + cls; d.hidden = true; doc.body.append(d); return d; };
    ov.hover = mk('bld-ov--hover');
    ov.drop = mk('bld-ov--drop');
    ov.sel = mk('bld-ov--sel');
    ov.note = mk('bld-ov--note');
    const tag = doc.createElement('div');
    tag.className = 'bld-ov__tag';
    ov.name = doc.createElement('span');
    tag.append(ov.name);
    const tb = (txt, title, fn, drag) => {
      const b = doc.createElement('button');
      b.type = 'button'; b.textContent = txt; b.title = title; b.setAttribute('aria-label', title);
      if (drag) { b.draggable = true; b.addEventListener('dragstart', (e) => { state.drag = { kind: 'move', id: state.sel }; e.dataTransfer.setData('text/plain', 'move'); e.dataTransfer.effectAllowed = 'move'; }); }
      else b.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); fn(); });
      tag.append(b);
    };
    tb('⠿', 'Ziehen zum Verschieben', null, true);
    tb('⤒', 'Übergeordnetes Element wählen', () => { const a = ancestors(state.sel); if (a.length) select(a[a.length - 1].id); });
    tb('↑', 'Nach oben', () => move(state.sel, -1));
    tb('↓', 'Nach unten', () => move(state.sel, 1));
    tb('⧉', 'Duplizieren', () => duplicate(state.sel));
    tb('◐', 'Ein-/Ausblenden', () => toggleOff(state.sel));
    tb('✕', 'Löschen', () => remove(state.sel));
    ov.sel.append(tag);
    ov.tag = tag;

    doc.addEventListener('mousemove', (e) => {
      if (state.drag) return;
      const el = pick(e.target);
      putBox(ov.hover, el && el.dataset.b !== state.sel ? el : null);
      ov.hover.classList.toggle('is-global', !!el && find(el.dataset.b)?.node.type === 'component');
    });
    doc.addEventListener('mouseleave', () => { ov.hover.hidden = true; });
    doc.addEventListener('click', (e) => {
      if (e.target.closest('.bld-ov')) return;
      const inMain = e.target.closest('#inhalt');
      const editable = e.target.closest('[data-f]');
      if (state.editing && editable === state.editing.el) return;
      if (!e.target.closest('summary, [role=tab]')) e.preventDefault();
      if (!inMain) { globalNote(e); return; }
      ov.note.hidden = true;
      const el = pick(e.target);
      if (!el) { select(null); return; }
      const id = el.dataset.b;
      if (editable && state.sel === id && pick(editable) === el) startInline(editable, id);
      else select(id);
    }, true);
    doc.addEventListener('dblclick', (e) => {
      const editable = e.target.closest('#inhalt [data-f]');
      const el = editable && pick(editable);
      if (el) { e.preventDefault(); select(el.dataset.b); startInline(editable, el.dataset.b); }
    });
    doc.addEventListener('submit', (e) => e.preventDefault(), true);
    doc.addEventListener('keydown', keys);
    doc.addEventListener('dragover', canvasDragOver);
    doc.addEventListener('dragleave', (e) => { if (!e.relatedTarget) ov.drop.hidden = true; });
    doc.addEventListener('drop', canvasDrop);
    doc.addEventListener('dragend', endDrag);
    doc.defaultView.addEventListener('scroll', positionOverlays, { passive: true });
    doc.defaultView.addEventListener('resize', positionOverlays);
    renderCanvas();
  };
  frame.addEventListener('load', setupFrame);
  if (frame.contentDocument?.readyState === 'complete') setupFrame();

  const globalNote = (e) => {
    const r = e.target.getBoundingClientRect(), w = doc.defaultView;
    ov.note.textContent = 'Kopf- und Fußbereich gelten für alle Seiten. Bearbeiten in der Seitenleiste unter „Design“ → Bereiche auf allen Seiten.';
    Object.assign(ov.note.style, { top: (e.clientY + w.scrollY + 12) + 'px', left: Math.min(e.clientX + w.scrollX, w.innerWidth - 340) + 'px', width: 'auto', height: 'auto' });
    ov.note.hidden = false;
    setTimeout(() => { ov.note.hidden = true; }, 4000);
    void r;
  };

  // ---------- Direkt im Arbeitsbereich schreiben ----------
  const startInline = (el, id) => {
    if (state.editing) return;
    const field = el.dataset.f, kind = el.dataset.fk || 'text';
    state.editing = { el, id, field, kind };
    el.contentEditable = kind === 'rich' ? 'true' : 'plaintext-only';
    if (el.contentEditable !== 'plaintext-only' && kind !== 'rich') el.contentEditable = 'true';
    el.focus();
    const range = doc.createRange(); range.selectNodeContents(el); range.collapse(false);
    const s = doc.getSelection(); s.removeAllRanges(); s.addRange(range);
    ov.sel.hidden = true;
    const read = () => (kind === 'rich' ? el.innerHTML : (kind === 'lines' ? el.innerText.replace(/\n{2,}/g, '\n') : el.innerText.replace(/\s*\n\s*/g, ' ')));
    const onInput = () => {
      const n = find(id)?.node;
      if (!n) return;
      change(() => { n.f = n.f || {}; setPath(n.f, field, read()); }, { key: 'inline:' + id + field, noRender: true, keepInspector: true });
    };
    const onKey = (e) => {
      if (e.key === 'Escape' || (e.key === 'Enter' && kind === 'text')) { e.preventDefault(); el.blur(); }
    };
    const onPaste = (e) => {
      if (kind === 'rich') return;
      e.preventDefault();
      doc.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain'));
    };
    const onBlur = () => {
      el.removeEventListener('input', onInput); el.removeEventListener('keydown', onKey); el.removeEventListener('blur', onBlur); el.removeEventListener('paste', onPaste);
      el.removeAttribute('contenteditable');
      state.editing = null;
      state.lastKey = null;
      renderInspector();
      scheduleRender(0);
    };
    el.addEventListener('input', onInput); el.addEventListener('keydown', onKey); el.addEventListener('blur', onBlur); el.addEventListener('paste', onPaste);
  };

  // ---------- Ziehen und Ablegen ----------
  const dragType = () => {
    const d = state.drag;
    if (!d) return null;
    if (d.kind === 'move') return find(d.id)?.node.type;
    return d.kind === 'component' ? 'component' : d.type;
  };
  const horizontal = (parent) => parent && (parent.type === 'buttons' || parent.type === 'columns' || (parent.type === 'group' && parent.f?.direction === 'row'));
  /** Ermittelt aus der Mausposition, wo das gezogene Element landen würde. */
  const dropTarget = (x, y) => {
    const type = dragType();
    if (!type) return null;
    const movingId = state.drag.kind === 'move' ? state.drag.id : null;
    let el = pick(doc.elementFromPoint(x, y));
    if (el && movingId) { const m = find(movingId)?.node; while (el && m && contains(m, el.dataset.b)) el = pick(el.parentElement); }
    if (!el) {
      // Unterhalb der letzten Abschnitte oder auf leerer Fläche: ans Seitenende
      const last = state.tree[state.tree.length - 1];
      return { parent: null, index: state.tree.length, ref: last ? elFor(last.id) : null, pos: 'after' };
    }
    let r = find(el.dataset.b);
    // Container: in das Element hinein (an die passende Stelle zwischen die Kinder)
    if (T[r.node.type]?.children && allowed(type, r.node.type)) {
      const kids = r.node.c.filter((c) => c.id !== movingId);
      let idx = 0;
      const horiz = horizontal(r.node);
      kids.forEach((c, i) => {
        const ce = elFor(c.id);
        if (!ce) return;
        const cr = rectOf(ce);
        if (horiz ? x > cr.left + cr.width / 2 : (y > cr.top + cr.height / 2)) idx = i + 1;
      });
      if (!kids.length) return { parent: r.node.id, index: 0, ref: el, pos: 'inside' };
      const refNode = kids[Math.min(idx, kids.length - 1)];
      return { parent: r.node.id, index: r.node.c.indexOf(refNode) + (idx >= kids.length ? 1 : 0), ref: elFor(refNode.id), pos: idx >= kids.length ? 'after' : 'before', horiz };
    }
    // Sonst davor oder dahinter, notfalls eine Ebene höher
    while (r) {
      const pType = r.parent ? r.parent.type : null;
      const e2 = elFor(r.node.id);
      if (allowed(type, pType) && e2) {
        const rr = rectOf(e2), horiz = horizontal(r.parent);
        const after = horiz ? x > rr.left + rr.width / 2 : y > rr.top + rr.height / 2;
        return { parent: r.parent ? r.parent.id : null, index: r.index + (after ? 1 : 0), ref: e2, pos: after ? 'after' : 'before', horiz };
      }
      r = r.parent ? find(r.parent.id) : null;
    }
    return null;
  };
  const canvasDragOver = (e) => {
    if (!state.drag) return;
    e.preventDefault();
    const t = dropTarget(e.clientX, e.clientY);
    state.dropAt = t;
    if (!t || !t.ref) { ov.drop.hidden = true; return; }
    const r = rectOf(t.ref), w = doc.defaultView;
    ov.drop.hidden = false;
    ov.drop.classList.toggle('is-inside', t.pos === 'inside');
    if (t.pos === 'inside') Object.assign(ov.drop.style, { top: (r.top + w.scrollY) + 'px', left: (r.left + w.scrollX) + 'px', width: r.width + 'px', height: r.height + 'px' });
    else if (t.horiz) Object.assign(ov.drop.style, { top: (r.top + w.scrollY) + 'px', left: ((t.pos === 'after' ? r.right : r.left) + w.scrollX - 2) + 'px', width: '4px', height: r.height + 'px' });
    else Object.assign(ov.drop.style, { top: ((t.pos === 'after' ? r.bottom : r.top) + w.scrollY - 2) + 'px', left: (r.left + w.scrollX) + 'px', width: r.width + 'px', height: '4px' });
  };
  const applyDrop = (t) => {
    const d = state.drag;
    if (!t || !d) return;
    if (d.kind === 'move') {
      const r = find(d.id);
      if (!r) return;
      if (r.node.type === 'column' && t.parent !== r.parent?.id) { toast('Spalten können nur innerhalb ihrer Spaltengruppe verschoben werden.', true); return; }
      change(() => {
        let index = t.index;
        if ((r.parent ? r.parent.id : null) === t.parent && r.index < index) index--;
        r.list.splice(r.index, 1);
        place(r.node, t.parent, index);
        state.sel = r.node.id;
      }, { now: true });
    } else if (d.kind === 'component') addComponent(d.ref, t);
    else addType(d.type, t);
  };
  const canvasDrop = (e) => {
    if (!state.drag) return;
    e.preventDefault();
    applyDrop(dropTarget(e.clientX, e.clientY));
    endDrag();
  };
  const endDrag = () => {
    state.drag = null; state.dropAt = null;
    if (ov.drop) ov.drop.hidden = true;
    $$('.drop-before, .drop-after, .drop-inside').forEach((x) => x.classList.remove('drop-before', 'drop-after', 'drop-inside'));
  };
  document.addEventListener('dragend', endDrag);

  // ---------- Auswahl ----------
  const select = (id, scroll = false) => {
    state.sel = id && find(id) ? id : null;
    positionOverlays();
    if (ov.hover) ov.hover.hidden = true;
    if (scroll && state.sel) elFor(state.sel)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    renderTree();
    renderInspector();
    if (state.sel) showTab('props', false);
  };

  // ---------- Seitenleiste: Reiter ----------
  const tabs = $$('.bld-tabs [role=tab]');
  const showTab = (name, focus = false) => {
    tabs.forEach((t) => {
      const on = t.id === 't-' + name;
      t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1;
      $('#' + t.getAttribute('aria-controls')).hidden = !on;
      if (on && focus) t.focus();
    });
  };
  tabs.forEach((t, i) => {
    t.addEventListener('click', () => showTab(t.id.slice(2)));
    t.addEventListener('keydown', (e) => {
      if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
      const n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
      showTab(n.id.slice(2), true);
    });
  });

  // ---------- Elemente ----------
  const renderLibrary = () => {
    const q = $('[data-lib-search]').value.trim().toLowerCase();
    const box = $('[data-library]');
    box.replaceChildren();
    const item = (type, t, extra = {}) => h('button', {
      type: 'button', class: 'bld-lib__item' + (extra.global ? ' bld-lib__item--global' : ''), draggable: 'true', title: t.desc || t.label,
      ondragstart: (e) => { state.drag = extra.global ? { kind: 'component', ref: extra.ref } : { kind: 'new', type }; e.dataTransfer.setData('text/plain', type); e.dataTransfer.effectAllowed = 'copyMove'; },
      onclick: () => (extra.global ? addComponent(extra.ref) : addType(type)),
    }, svg(t.icon || 'box'), t.label);
    for (const [cat, catLabel] of Object.entries(cfg.blocks.categories)) {
      const list = Object.entries(T).filter(([k, t]) => t.cat === cat && !t.hidden && (!q || (t.label + ' ' + (t.desc || '')).toLowerCase().includes(q)));
      if (!list.length) continue;
      box.append(h('h3', { class: 'bld-lib__cat' }, catLabel), h('div', { class: 'bld-lib__grid' }, list.map(([k, t]) => item(k, t))));
    }
    if (cfg.kind === 'page') {
      const comps = state.components.filter((c) => !q || c.name.toLowerCase().includes(q));
      box.append(h('h3', { class: 'bld-lib__cat' }, 'Globale Bausteine'));
      box.append(comps.length ? h('div', { class: 'bld-lib__grid' }, comps.map((c) => item('component', { label: c.name, icon: 'layers', desc: 'Globaler Baustein: Änderungen wirken auf allen Seiten' }, { global: true, ref: c.id })))
        : h('p', { class: 'bld-hint' }, 'Noch keine. Ein Element auswählen und unter „Einstellungen → Erweitert“ als globalen Baustein speichern.'));
    }
  };
  $('[data-lib-search]').addEventListener('input', renderLibrary);

  // ---------- Struktur ----------
  const renderTree = () => {
    const box = $('[data-tree]');
    const build = (list) => h('ul', { class: 'bld-tree', role: 'group' }, list.map((n) => {
      const row = h('div', {
        class: 'bld-tree__row' + (n.id === state.sel ? ' is-sel' : '') + (n.off ? ' is-off' : '') + (n.type === 'component' ? ' is-global' : ''),
        draggable: 'true', tabindex: '0', role: 'treeitem', 'aria-selected': String(n.id === state.sel), 'data-id': n.id,
        onclick: () => select(n.id, true),
        onkeydown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(n.id, true); } },
        ondragstart: (e) => { state.drag = { kind: 'move', id: n.id }; e.dataTransfer.setData('text/plain', 'move'); e.dataTransfer.effectAllowed = 'move'; },
        ondragover: (e) => treeDragOver(e, n, row),
        ondragleave: () => row.classList.remove('drop-before', 'drop-after', 'drop-inside'),
      }, svg(n.type === 'component' ? 'layers' : (T[n.type]?.icon || 'box')),
      h('span', { class: 'bld-tree__label' }, label(n), snippet(n) && n.type !== 'component' ? h('small', {}, ' · ' + snippet(n)) : null),
      n.off ? svg('eye-off') : null);
      return h('li', {}, row, n.c && n.c.length ? build(n.c) : null);
    }));
    box.replaceChildren(state.tree.length ? build(state.tree) : h('p', { class: 'bld-empty' }, 'Die Seite ist leer. Elemente aus „Elemente“ hineinziehen.'));
  };
  const treeDragOver = (e, n, row) => {
    const d = state.drag;
    if (!d) return;
    const type = dragType();
    if (d.kind === 'move' && contains(find(d.id).node, n.id)) return;
    const r = row.getBoundingClientRect();
    const rel = (e.clientY - r.top) / r.height;
    const info = find(n.id);
    let t = null;
    if (T[n.type]?.children && allowed(type, n.type) && rel > 0.3 && rel < 0.7) t = { parent: n.id, index: n.c.length, pos: 'inside' };
    else if (allowed(type, info.parent ? info.parent.type : null) || (!info.parent && type !== 'column')) t = { parent: info.parent ? info.parent.id : null, index: info.index + (rel >= 0.5 ? 1 : 0), pos: rel >= 0.5 ? 'after' : 'before' };
    row.classList.remove('drop-before', 'drop-after', 'drop-inside');
    row._drop = t;
    if (!t) return;
    e.preventDefault();
    row.classList.add('drop-' + t.pos);
  };
  // Ablegen in der Struktur nutzt dieselbe Logik wie im Arbeitsbereich
  $('[data-tree]').addEventListener('drop', (e) => {
    const row = e.target.closest('.bld-tree__row');
    if (!row || !row._drop) return;
    e.preventDefault(); e.stopPropagation();
    applyDrop(row._drop);
    endDrag();
  }, true);

  // ---------- Einstellungen ----------
  const help = (t) => (t ? h('p', { class: 'bld-field__help' }, t) : null);
  const fieldId = (() => { let i = 0; return () => 'bf' + (++i); })();

  /** Ein Eingabefeld für eine Feldbeschreibung aus BlockTypes. get/set greifen auf den Wert zu. */
  const fieldControl = (fd, get, set, keyBase) => {
    const id = fieldId();
    const wrap = h('div', { class: 'bld-field' });
    const lab = h('label', { for: id }, fd.label + (fd.required ? ' *' : ''));
    const val = get();
    const key = keyBase + ':' + fd.name;
    switch (fd.type) {
      case 'text':
      case 'url': {
        const inp = h('input', { id, type: 'text', value: val ?? '', maxlength: fd.max || (fd.type === 'url' ? 500 : 300), list: fd.type === 'url' ? 'bld-links' : null, placeholder: fd.type === 'url' ? '/seite/ oder https://…' : null, spellcheck: fd.type === 'url' ? 'false' : null });
        inp.addEventListener('input', () => set(inp.value, key));
        wrap.append(lab, inp, help(fd.help || (fd.type === 'url' ? 'Interne Adresse (z. B. /kontakt/), https://…, mailto: oder tel:' : null)));
        break;
      }
      case 'textarea':
      case 'lines': {
        const ta = h('textarea', { id, rows: fd.type === 'lines' ? 5 : 3 });
        ta.value = val ?? '';
        ta.addEventListener('input', () => set(ta.value, key));
        wrap.append(lab, ta, help(fd.help));
        break;
      }
      case 'rich': wrap.append(h('div', { class: 'bld-field__label', id }, fd.label), richEditor(val ?? '', (v) => set(v, key), id), help(fd.help || 'Tipp: Text lässt sich auch direkt in der Seite bearbeiten (doppelklicken).')); break;
      case 'select': {
        const sel = h('select', { id }, Object.entries(fd.options).map(([k, l]) => h('option', { value: k, selected: String(val) === String(k) }, l)));
        sel.addEventListener('change', () => set(sel.value, null));
        wrap.append(lab, sel, help(fd.help));
        break;
      }
      case 'bool': {
        const cb = h('input', { id, type: 'checkbox', checked: !!val });
        cb.addEventListener('change', () => set(cb.checked, null));
        wrap.append(h('label', { class: 'bld-check', for: id }, cb, fd.label), help(fd.help));
        break;
      }
      case 'number': {
        const inp = h('input', { id, type: 'number', value: val ?? '', min: fd.min, max: fd.max });
        inp.addEventListener('input', () => { if (inp.value !== '') set(Number(inp.value), key); });
        wrap.append(lab, inp, help(fd.help));
        break;
      }
      case 'icon': {
        const grid = h('div', { class: 'bld-icons', role: 'group', 'aria-label': fd.label });
        const opts = (fd.default === '' ? [''] : []).concat(cfg.blocks.icons);
        opts.forEach((ic) => grid.append(h('button', { type: 'button', title: ic || 'kein Symbol', 'aria-label': ic || 'kein Symbol', 'aria-pressed': String((val || '') === ic),
          onclick: () => { set(ic, null); $$('button', grid).forEach((b) => b.setAttribute('aria-pressed', String(b.title === (ic || 'kein Symbol')))); } }, ic ? svg(ic) : '—')));
        wrap.append(h('div', { class: 'bld-field__label' }, fd.label), grid);
        break;
      }
      case 'media': {
        const thumb = h('img', { class: 'bld-media__thumb', alt: '' });
        const showThumb = (v) => { const m = state.media.get(Number(v)); thumb.hidden = !v; if (m) thumb.src = m.thumb; };
        showThumb(val);
        wrap.append(h('div', { class: 'bld-field__label' }, fd.label), h('div', { class: 'bld-media' }, thumb,
          h('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: async () => { const r = await mediaPicker({ multi: false, current: val }); if (r && r.length) { set(r[0], null); } } }, svg('image'), val ? 'Ändern' : 'Bild wählen'),
          val && !fd.required ? h('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: () => set(null, null) }, svg('x'), 'Entfernen') : null));
        break;
      }
      case 'media_list': {
        const list = Array.isArray(val) ? val : [];
        const grid = h('div', { class: 'bld-media__list' }, list.map((mid, i) => {
          const m = state.media.get(Number(mid));
          return h('figure', {}, h('img', { src: m ? m.thumb : '', alt: m ? m.alt : '' }),
            h('button', { type: 'button', title: 'Entfernen', 'aria-label': 'Bild entfernen', onclick: () => set(list.filter((_, j) => j !== i), null) }, svg('x')));
        }));
        wrap.append(h('div', { class: 'bld-field__label' }, fd.label + ' (' + list.length + ')'), grid,
          h('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: async () => { const r = await mediaPicker({ multi: true }); if (r && r.length) set(list.concat(r).slice(0, fd.max_items || 40), null); } }, svg('plus'), 'Bilder hinzufügen'));
        break;
      }
      case 'component': {
        const sel = h('select', { id }, h('option', { value: '' }, '– bitte wählen –'), state.components.map((c) => h('option', { value: c.id, selected: Number(val) === c.id }, c.name)));
        sel.addEventListener('change', () => set(sel.value ? Number(sel.value) : null, null));
        wrap.append(lab, sel);
        break;
      }
      case 'items': {
        const items = Array.isArray(val) ? val : [];
        const box = h('div', { class: 'bld-items' });
        items.forEach((it, i) => {
          const title = strip(it[fd.item_title || 'title'] || it.label || it.title || it.quote || '') || ('Eintrag ' + (i + 1));
          const body = h('div', { class: 'bld-items__body' });
          fd.fields.forEach((sub) => body.append(fieldControl(sub, () => it[sub.name], (v, k) => set(items.map((x, j) => (j === i ? { ...x, [sub.name]: v } : x)), k), keyBase + ':' + fd.name + i)));
          const det = h('details', { class: 'bld-items__item', open: state.openItem === keyBase + fd.name + i },
            h('summary', { onclick: () => { state.openItem = det.open ? null : keyBase + fd.name + i; } }, h('span', {}, title),
              h('button', { type: 'button', title: 'Nach oben', 'aria-label': 'Eintrag nach oben', disabled: i === 0, onclick: (e) => { e.preventDefault(); const a = items.slice(); [a[i - 1], a[i]] = [a[i], a[i - 1]]; set(a, null); } }, svg('arrow-up')),
              h('button', { type: 'button', title: 'Nach unten', 'aria-label': 'Eintrag nach unten', disabled: i === items.length - 1, onclick: (e) => { e.preventDefault(); const a = items.slice(); [a[i + 1], a[i]] = [a[i], a[i + 1]]; set(a, null); } }, svg('arrow-down')),
              h('button', { type: 'button', title: 'Duplizieren', 'aria-label': 'Eintrag duplizieren', onclick: (e) => { e.preventDefault(); const a = items.slice(); a.splice(i + 1, 0, clone(it)); set(a, null); } }, svg('copy')),
              h('button', { type: 'button', title: 'Entfernen', 'aria-label': 'Eintrag entfernen', onclick: (e) => { e.preventDefault(); set(items.filter((_, j) => j !== i), null); } }, svg('trash-2'))),
            body);
          box.append(det);
        });
        const fresh = () => Object.fromEntries(fd.fields.map((s) => [s.name, clone(s.default ?? (s.type === 'bool' ? false : ''))]));
        wrap.append(h('div', { class: 'bld-field__label' }, fd.label + ' (' + items.length + ')'), box,
          items.length < (fd.max_items || 24) ? h('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: () => { state.openItem = keyBase + fd.name + items.length; set(items.concat([fresh()]), null); } }, svg('plus'), 'Eintrag hinzufügen') : null);
        break;
      }
      default: wrap.append(h('p', { class: 'bld-field__help' }, fd.label + ': (nicht bearbeitbar)'));
    }
    return wrap;
  };

  /** Kleiner Texteditor (fett, kursiv, Link, Listen, Zwischenüberschrift). Der Server bereinigt das Ergebnis. */
  const richEditor = (html, onChange, labelledby) => {
    const area = h('div', { class: 'bld-rich__area', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-labelledby': labelledby });
    area.innerHTML = html;
    const cmd = (c, v = null) => { area.focus(); document.execCommand(c, false, v); onChange(area.innerHTML); };
    const linkRow = h('div', { class: 'bld-sprop__row', hidden: true });
    const linkInput = h('input', { type: 'text', list: 'bld-links', placeholder: '/kontakt/ oder https://…' });
    let saved = null;
    linkRow.append(linkInput, h('button', { type: 'button', class: 'btn btn--primary btn--sm', onclick: () => {
      const sel = document.getSelection();
      if (saved) { sel.removeAllRanges(); sel.addRange(saved); }
      if (linkInput.value.trim()) cmd('createLink', linkInput.value.trim()); else cmd('unlink');
      linkRow.hidden = true;
    } }, 'Übernehmen'));
    const b = (txt, title, fn) => h('button', { type: 'button', title, 'aria-label': title, onmousedown: (e) => e.preventDefault(), onclick: fn }, txt);
    const bar = h('div', { class: 'bld-rich__bar' },
      b('B', 'Fett', () => cmd('bold')), b('I', 'Kursiv', () => cmd('italic')),
      b('🔗', 'Link', () => { const s = document.getSelection(); saved = s.rangeCount ? s.getRangeAt(0).cloneRange() : null; linkRow.hidden = false; linkInput.focus(); }),
      b('• Liste', 'Aufzählung', () => cmd('insertUnorderedList')), b('1. Liste', 'Nummerierte Liste', () => cmd('insertOrderedList')),
      b('H3', 'Zwischenüberschrift', () => cmd('formatBlock', 'h3')), b('¶', 'Normaler Absatz', () => cmd('formatBlock', 'p')));
    area.addEventListener('input', () => onChange(area.innerHTML));
    area.addEventListener('paste', (e) => { e.preventDefault(); document.execCommand('insertText', false, e.clipboardData.getData('text/plain')); });
    return h('div', { class: 'bld-rich' }, bar, linkRow, area);
  };

  const renderInspector = () => {
    const box = $('[data-inspector]');
    if (state.editing) return;
    const r = state.sel ? find(state.sel) : null;
    if (!r) { box.replaceChildren(h('p', { class: 'bld-empty' }, 'Bitte ein Element in der Seite oder in der Struktur auswählen.')); return; }
    const n = r.node, t = T[n.type] || { label: n.type, fields: [] };
    const active = document.activeElement;
    const keepFocus = active && box.contains(active) && active.id ? active.id : null;
    const crumbs = h('nav', { class: 'bld-crumbs', 'aria-label': 'Position' }, h('span', {}, 'Seite ›'), ancestors(n.id).map((a) => [h('button', { type: 'button', onclick: () => select(a.id, true) }, label(a)), h('span', {}, '›')]));
    const errs = state.errors.filter((e) => e.id === n.id);
    const actions = h('div', { class: 'bld-actions' },
      h('button', { type: 'button', class: 'btn btn--ghost btn--sm', title: 'Nach oben', onclick: () => move(n.id, -1), disabled: r.index === 0 }, svg('arrow-up'), h('span', { class: 'visually-hidden' }, 'Nach oben')),
      h('button', { type: 'button', class: 'btn btn--ghost btn--sm', title: 'Nach unten', onclick: () => move(n.id, 1), disabled: r.index === r.list.length - 1 }, svg('arrow-down'), h('span', { class: 'visually-hidden' }, 'Nach unten')),
      h('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: () => duplicate(n.id) }, svg('copy'), 'Duplizieren'),
      h('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: () => toggleOff(n.id) }, svg(n.off ? 'eye' : 'eye-off'), n.off ? 'Einblenden' : 'Ausblenden'),
      h('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: () => remove(n.id) }, svg('trash-2'), 'Löschen'));
    const sub = h('div', { class: 'bld-subtabs', role: 'tablist', 'aria-label': 'Einstellungen' }, [['content', 'Inhalt'], ['style', 'Stil'], ['adv', 'Erweitert']].map(([k, l]) =>
      h('button', { type: 'button', role: 'tab', 'aria-selected': String(state.subtab === k), onclick: () => { state.subtab = k; renderInspector(); } }, l)));
    const body = h('div', { class: 'bld-insp__body' });
    const keyBase = n.id;
    const setF = (name) => (v, key) => {
      change(() => {
        n.f = n.f || {};
        n.f[name] = v;
        if (n.type === 'columns' && name === 'layout') setColumns(n, v);
      }, { key, keepInspector: !!key });
    };
    if (state.subtab === 'content') {
      if (n.type === 'component') body.append(componentPanel(n));
      else if (!t.fields.length) body.append(h('p', { class: 'bld-hint' }, n.type === 'column' ? 'Eine Spalte nimmt beliebige Elemente auf. Breite und Anzahl der Spalten stellt das übergeordnete Element „Spalten“ ein.' : 'Dieses Element hat keine Inhaltsfelder. Aussehen unter „Stil“.'));
      t.fields.forEach((fd) => body.append(fieldControl(fd, () => (n.f || {})[fd.name] ?? DEF[n.type]?.[fd.name], setF(fd.name), keyBase)));
    } else if (state.subtab === 'style') body.append(stylePanel(n));
    else body.append(advancedPanel(n));
    box.replaceChildren(
      h('div', { class: 'bld-insp__head' }, svg(n.type === 'component' ? 'layers' : (t.icon || 'box')), h('h2', {}, label(n)), n.type === 'component' ? h('span', { class: 'badge badge--neutral' }, 'Global') : h('span', { class: 'badge badge--muted' }, 'Lokal')),
      crumbs,
      errs.length ? h('div', { class: 'alert alert--error' }, h('p', {}, errs.map((e) => e.msg).join(' '))) : null,
      actions, sub, body);
    if (keepFocus) document.getElementById(keepFocus)?.focus();
  };

  const componentPanel = (n) => {
    const c = state.components.find((x) => x.id === Number(n.f?.ref));
    return h('div', {},
      h('p', { class: 'bld-hint' }, 'Dieser Bereich ist ein globaler Baustein. Er wird auf allen Seiten, die ihn verwenden, gleich angezeigt. Änderungen am Inhalt erfolgen im Baustein selbst und wirken nach dem Veröffentlichen überall.'),
      fieldControl({ name: 'ref', label: 'Baustein', type: 'component' }, () => n.f?.ref, (v) => change(() => { n.f.ref = v; }, {}), n.id),
      c && cfg.can_components ? h('p', {}, h('a', { class: 'btn btn--primary btn--sm', href: '/verwaltung/bausteine/' + c.id + '/editor/', target: '_blank', rel: 'noopener' }, svg('pencil'), 'Globalen Baustein bearbeiten')) : null,
      c ? h('p', {}, h('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: () => detach(n, c) }, svg('copy'), 'In lokale Kopie umwandeln')) : null,
      h('p', { class: 'bld-field__help' }, 'Lokale Kopie: Die Seite erhält eine eigene, unabhängige Kopie. Spätere Änderungen am globalen Baustein wirken dann hier nicht mehr.'));
  };
  const detach = async (n, c) => {
    if (!await confirmBox('Verknüpfung lösen?', 'Die Seite erhält eine eigene Kopie von „' + c.name + '“. Spätere Änderungen am globalen Baustein erscheinen hier dann nicht mehr.', 'Lokale Kopie erstellen')) return;
    const r = find(n.id);
    const copies = clone(c.tree).map(reId);
    change(() => { r.list.splice(r.index, 1, ...copies); state.sel = copies[0]?.id || null; }, { now: true });
  };

  // Stil je Gerät; leere Werte kommen aus dem Designsystem (global)
  const stylePanel = (n) => {
    const dev = state.device;
    const s = n.s || {};
    const cur = s[dev] || {};
    const inherit = (k) => (dev === 'm' ? (s.t?.[k] ?? s.d?.[k]) : dev === 't' ? s.d?.[k] : undefined);
    const setS = (k, v, key) => change(() => {
      n.s = n.s || {};
      n.s[dev] = n.s[dev] || {};
      if (v === '' || v === null || v === undefined || v === false) delete n.s[dev][k]; else n.s[dev][k] = v;
      if (!Object.keys(n.s[dev]).length) delete n.s[dev];
    }, { key, keepInspector: !!key });
    const wrap = h('div', {});
    wrap.append(h('div', { class: 'bld-devices', role: 'group', 'aria-label': 'Gerät für Stil' }, Object.entries(cfg.style.devices).map(([k, l]) =>
      h('button', { type: 'button', 'aria-pressed': String(dev === k), onclick: () => setDevice(k) }, svg({ d: 'monitor', t: 'tablet', m: 'smartphone' }[k]), l, Object.keys(s[k] || {}).length ? h('span', { class: 'count' }, Object.keys(s[k]).length) : null))));
    wrap.append(h('p', { class: 'bld-hint' }, dev === 'd' ? 'Leere Felder nutzen das Designsystem (global). Eigene Werte gelten nur für dieses Element.' : 'Werte für ' + cfg.style.devices[dev] + ' überschreiben die größeren Geräte. Leere Felder übernehmen den Wert darüber.'));
    for (const [g, gl] of Object.entries(cfg.style.groups)) {
      const props = Object.entries(PROPS).filter(([, p]) => p.group === g && (!p.only || p.only.includes(n.type)));
      if (!props.length) continue;
      const setCount = props.filter(([k]) => cur[k] !== undefined).length;
      const det = h('details', { class: 'bld-sgroup', open: setCount > 0 || state.openGroup === g, ontoggle: () => { if (det.open) state.openGroup = g; } },
        h('summary', {}, gl, setCount ? h('span', { class: 'badge badge--success' }, setCount + ' eigene') : null));
      const grid = h('div', { class: 'bld-sgroup__body' });
      props.forEach(([k, p]) => grid.append(styleControl(k, p, cur[k], inherit(k), (v, key) => setS(k, v, key), n.id)));
      det.append(grid);
      wrap.append(det);
    }
    if (Object.keys(cur).length) wrap.append(h('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: () => change(() => { delete n.s[dev]; }, { now: true }) }, svg('rotate-ccw'), 'Alle eigenen Werte (' + cfg.style.devices[dev] + ') zurücksetzen'));
    return wrap;
  };
  const tokenLabel = (v) => {
    if (typeof v !== 'string') return v;
    if (v.startsWith('var:')) { const t = cfg.style.tokens.find((x) => x.key === v.slice(4)); return t ? t.label : v; }
    return v;
  };
  const styleControl = (k, p, val, inh, set, nid) => {
    const id = fieldId();
    const isSet = val !== undefined;
    const wide = ['color', 'font', 'bool'].includes(p.type) || ['bc', 'shadow', 'mx'].includes(k);
    const wrap = h('div', { class: 'bld-field bld-sprop' + (isSet ? ' is-set' : (inh !== undefined ? ' is-inherited' : '')) + (wide ? ' bld-field--wide' : '') });
    const key = 'style:' + nid + ':' + k + state.device;
    const reset = isSet ? h('button', { type: 'button', class: 'bld-sprop__reset', title: 'Zurücksetzen (Wert aus dem Designsystem)', 'aria-label': p.label + ' zurücksetzen', onclick: () => set(null, null) }, svg('rotate-ccw')) : null;
    const ph = inh !== undefined ? String(tokenLabel(inh)) : 'Design';
    let ctl;
    switch (p.type) {
      case 'px':
      case 'number':
        ctl = h('input', { id, type: 'number', value: val ?? '', min: p.min, max: p.max, step: p.step || 1, placeholder: ph });
        ctl.addEventListener('input', () => set(ctl.value === '' ? null : Number(ctl.value), key));
        break;
      case 'select':
        ctl = h('select', { id }, h('option', { value: '' }, inh !== undefined ? 'wie darüber (' + (p.options[inh] || inh) + ')' : '– Design –'), Object.entries(p.options).map(([o, l]) => h('option', { value: o, selected: String(val) === o }, l)));
        ctl.addEventListener('change', () => set(ctl.value, null));
        break;
      case 'font':
        ctl = h('select', { id }, h('option', { value: '' }, '– Design –'), cfg.style.fonts.map((f) => h('option', { value: f.id, selected: val === f.id }, f.label)));
        ctl.addEventListener('change', () => set(ctl.value, null));
        break;
      case 'bool':
        ctl = h('input', { id, type: 'checkbox', checked: !!val });
        ctl.addEventListener('change', () => set(ctl.checked, null));
        return h('div', { class: 'bld-field bld-field--wide' }, h('label', { class: 'bld-check', for: id }, ctl, p.label + ' (' + cfg.style.devices[state.device] + ')'),
          h('p', { class: 'bld-field__help' }, 'Im Editor bleibt das Element sichtbar, damit es weiter bearbeitet werden kann.'));
      case 'color': {
        const sel = h('select', { id }, h('option', { value: '' }, '– Design –'),
          h('optgroup', { label: 'Farben aus dem Designsystem' }, cfg.style.tokens.map((t) => h('option', { value: 'var:' + t.key, selected: val === 'var:' + t.key }, t.label + ' (' + t.value + ')'))),
          h('option', { value: 'custom', selected: typeof val === 'string' && val.startsWith('#') }, 'Eigene Farbe …'));
        const pickr = h('input', { type: 'color', value: typeof val === 'string' && val.startsWith('#') ? val : '#317566', 'aria-label': p.label + ': eigene Farbe' });
        pickr.hidden = !(typeof val === 'string' && val.startsWith('#'));
        sel.addEventListener('change', () => { if (sel.value === 'custom') { pickr.hidden = false; set(pickr.value, null); } else set(sel.value, null); });
        pickr.addEventListener('input', () => set(pickr.value, key));
        ctl = h('div', { class: 'bld-sprop__row' }, sel, pickr);
        wrap.append(h('label', { for: id }, p.label), h('div', { class: 'bld-sprop__row' }, ctl, reset));
        return wrap;
      }
      default:
        ctl = h('input', { id, type: 'text', value: val ?? '' });
        ctl.addEventListener('input', () => set(ctl.value, key));
    }
    wrap.append(h('label', { for: id }, p.label), h('div', { class: 'bld-sprop__row' }, ctl, reset));
    return wrap;
  };

  const advancedPanel = (n) => {
    const idName = fieldId(), idOff = fieldId();
    const name = h('input', { id: idName, type: 'text', value: n.name || '', maxlength: 60, placeholder: T[n.type]?.label });
    name.addEventListener('input', () => change(() => { if (name.value.trim()) n.name = name.value.slice(0, 60); else delete n.name; }, { key: 'name:' + n.id, keepInspector: true, noRender: true }));
    const off = h('input', { id: idOff, type: 'checkbox', checked: !!n.off });
    off.addEventListener('change', () => toggleOff(n.id));
    const canGlobal = cfg.kind === 'page' && cfg.can_components && n.type !== 'component' && n.type !== 'column' && !JSON.stringify(n).includes('"type":"component"');
    return h('div', {},
      h('div', { class: 'bld-field' }, h('label', { for: idName }, 'Name in der Struktur'), name, help('Nur für die Übersicht im Editor, erscheint nicht auf der Website.')),
      h('div', { class: 'bld-field' }, h('label', { class: 'bld-check', for: idOff }, off, 'Auf der Website ausblenden'), help('Bleibt gespeichert und im Editor halbtransparent sichtbar.')),
      h('div', { class: 'bld-field' }, h('div', { class: 'bld-field__label' }, 'Technische Kennung'), h('code', {}, 'b-' + n.id)),
      canGlobal ? h('div', { class: 'bld-field' }, h('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: () => saveAsGlobal(n) }, svg('layers'), 'Als globalen Baustein speichern'),
        help('Macht dieses Element wiederverwendbar. Es wird hier durch den globalen Baustein ersetzt.')) : null);
  };

  const saveAsGlobal = async (n) => {
    const inp = h('input', { type: 'text', maxlength: 80, value: label(n), id: 'bld-gname' });
    const v = await dialog('Als globalen Baustein speichern', h('div', { class: 'bld-field' }, h('label', { for: 'bld-gname' }, 'Name des Bausteins'), inp,
      help('Der Baustein erscheint danach unter „Elemente → Globale Bausteine“ und lässt sich auf jeder Seite einfügen.')), [{ label: 'Abbrechen', value: 'cancel' }, { label: 'Speichern', value: 'ok', primary: true }], inp);
    if (v !== 'ok' || !inp.value.trim()) return;
    const { json } = await post('/verwaltung/bausteine/neu/', { name: inp.value.trim(), tree: JSON.stringify([n]) });
    if (!json.ok) { toast((json.errors || []).map((e) => e.msg).join(' ') || 'Speichern nicht möglich.', true); return; }
    await loadComponents();
    const r = find(n.id);
    const node = newNode('component');
    node.f.ref = json.id;
    change(() => { r.list.splice(r.index, 1, node); state.sel = node.id; }, { now: true });
    renderLibrary();
    toast('Globaler Baustein „' + inp.value.trim() + '“ angelegt.');
  };

  // ---------- Design ----------
  const renderDesign = () => {
    const box = $('[data-design]');
    box.replaceChildren(
      h('p', { class: 'bld-hint' }, 'Farben, Schriften, Abstände und Umbruchpunkte kommen aus dem Designsystem und gelten für die ganze Website. Unter „Einstellungen → Stil“ lassen sich einzelne Elemente abweichend gestalten; solche Werte sind dort als „eigener Wert“ markiert und mit einem Klick zurückzusetzen.'),
      h('h3', { class: 'bld-lib__cat' }, 'Farben (global)'),
      h('div', { class: 'bld-tokens' }, cfg.style.tokens.map((t) => {
        const sw = h('span', { class: 'bld-swatch' });
        sw.style.background = t.value;
        return h('div', {}, sw, t.label, h('code', {}, t.value));
      })),
      h('h3', { class: 'bld-lib__cat' }, 'Umbruchpunkte'),
      h('p', { class: 'bld-hint' }, 'Tablet bis ' + cfg.style.breakpoints.tablet + ' px Breite, Handy bis ' + cfg.style.breakpoints.mobile + ' px.'),
      h('p', {}, h('a', { class: 'btn btn--ghost btn--sm', href: '/verwaltung/designsystem/', target: '_blank', rel: 'noopener' }, svg('palette'), 'Designsystem öffnen')),
      h('h3', { class: 'bld-lib__cat' }, 'Bereiche auf allen Seiten'),
      h('ul', { class: 'bld-hint' },
        h('li', {}, h('a', { href: '/verwaltung/menues/', target: '_blank', rel: 'noopener' }, 'Navigation: Menüpunkte, Untermenüs, Reihenfolge')),
        h('li', {}, h('a', { href: '/verwaltung/vorlagentexte/', target: '_blank', rel: 'noopener' }, 'Kopf- und Fußbereich: Texte und Aufrufe')),
        h('li', {}, h('a', { href: '/verwaltung/einstellungen/', target: '_blank', rel: 'noopener' }, 'Logo, Firmendaten, Telefon, E-Mail')),
        h('li', {}, h('a', { href: '/verwaltung/bausteine/', target: '_blank', rel: 'noopener' }, 'Globale Bausteine verwalten'))));
  };

  // ---------- Geräte ----------
  const setDevice = (d) => {
    state.device = d;
    $('[data-frame-wrap]').dataset.dev = d;
    $$('[data-device]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.device === d)));
    renderInspector();
    setTimeout(() => { positionOverlays(); if (state.sel) elFor(state.sel)?.scrollIntoView({ block: 'center' }); }, 300);
  };
  $$('[data-device]').forEach((b) => b.addEventListener('click', () => setDevice(b.dataset.device)));

  // ---------- Dialoge ----------
  const dlg = $('[data-dialog]');
  const dialog = (title, body, buttons, focusEl) => new Promise((resolve) => {
    $('[data-dialog-title]').textContent = title;
    $('[data-dialog-body]').replaceChildren(body);
    $('[data-dialog-foot]').replaceChildren(...buttons.map((b) => h('button', { type: 'submit', value: b.value, class: 'btn ' + (b.primary ? 'btn--primary' : b.danger ? 'btn--danger' : 'btn--ghost') }, b.label)));
    dlg.returnValue = '';
    const done = () => { dlg.removeEventListener('close', done); resolve(dlg.returnValue || 'cancel'); };
    dlg.addEventListener('close', done);
    dlg.showModal();
    (focusEl || $('[data-dialog-foot] .btn--primary, [data-dialog-foot] .btn--danger'))?.focus();
  });
  const confirmBox = async (title, text, okLabel, danger = false) => (await dialog(title, h('p', {}, text), [{ label: 'Abbrechen', value: 'cancel' }, { label: okLabel, value: 'ok', primary: !danger, danger }])) === 'ok';

  // Bildauswahl mit Suche, Hochladen und Alternativtext
  const mediaPicker = ({ multi }) => new Promise((resolve) => {
    let chosen = [];
    const grid = h('div', { class: 'bld-picker__grid', role: 'group', 'aria-label': 'Bilder' });
    const detail = h('div', { class: 'bld-picker__sel', hidden: true });
    const q = h('input', { type: 'search', placeholder: 'Bilder suchen (Alternativtext, Dateiname) …', 'aria-label': 'Bilder suchen' });
    const file = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', multiple: true, hidden: true });
    const altNew = h('input', { type: 'text', placeholder: 'Alternativtext für neue Bilder (Pflicht)', 'aria-label': 'Alternativtext für neue Bilder', maxlength: 300 });
    const showDetail = (m) => {
      if (!m) { detail.hidden = true; return; }
      const alt = h('input', { type: 'text', value: m.alt, maxlength: 300, id: 'bld-alt' });
      detail.hidden = false;
      detail.replaceChildren(h('img', { src: m.thumb, alt: '' }), h('div', {},
        h('p', { class: 'text-sm' }, m.name || ''),
        h('div', { class: 'bld-field' }, h('label', { for: 'bld-alt' }, 'Alternativtext'), alt, help('Beschreibt das Bild für blinde Menschen und Suchmaschinen. Leer lassen nur bei rein dekorativen Bildern.')),
        cfg.can_media ? h('button', { type: 'button', class: 'btn btn--ghost btn--sm', onclick: async () => {
          const { json } = await post('/verwaltung/medien/' + m.id + '/alt.json', { alt_text: alt.value });
          if (json.ok) { m.alt = json.alt; state.media.set(m.id, m); toast('Alternativtext gespeichert.'); load(); }
        } }, 'Alternativtext speichern') : null));
    };
    const load = async () => {
      const r = await fetch('/verwaltung/medien/auswahl.json?q=' + encodeURIComponent(q.value), { credentials: 'same-origin' });
      const list = await r.json();
      list.forEach((m) => state.media.set(m.id, m));
      grid.replaceChildren(...(list.length ? list.map((m) => h('button', { type: 'button', 'aria-pressed': String(chosen.includes(m.id)), title: m.alt || m.name,
        onclick: (e) => {
          if (multi) chosen = chosen.includes(m.id) ? chosen.filter((x) => x !== m.id) : chosen.concat(m.id); else chosen = [m.id];
          $$('button', grid).forEach((b) => b.setAttribute('aria-pressed', String(chosen.includes(Number(b.dataset.id)))));
          showDetail(m);
          void e;
        }, 'data-id': m.id }, h('img', { src: m.thumb, alt: '', loading: 'lazy' }), h('span', { class: m.alt ? '' : 'no-alt' }, m.alt || 'ohne Alternativtext'))) : [h('p', { class: 'text-muted' }, 'Keine Bilder gefunden.')]));
    };
    let t;
    q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(load, 250); });
    file.addEventListener('change', async () => {
      if (!file.files.length) return;
      if (!altNew.value.trim()) { toast('Bitte zuerst einen Alternativtext für die neuen Bilder eingeben.', true); altNew.focus(); file.value = ''; return; }
      const fd = new FormData();
      fd.append('_csrf', cfg.csrf); fd.append('json', '1'); fd.append('alt_text', altNew.value.trim());
      Array.from(file.files).forEach((f) => fd.append('files[]', f));
      toast('Wird hochgeladen …');
      const r = await fetch('/verwaltung/medien/hochladen/', { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await r.json().catch(() => ({ ok: false, errors: ['Hochladen fehlgeschlagen.'] }));
      if (json.errors?.length) toast(json.errors.join(' '), true); else toast('Hochgeladen.');
      file.value = '';
      chosen = multi ? chosen.concat(json.ids || []) : (json.ids || []).slice(0, 1);
      q.value = '';
      await load();
    });
    const body = h('div', {},
      h('div', { class: 'bld-picker__bar' }, q, cfg.can_media ? altNew : null, cfg.can_media ? h('button', { type: 'button', class: 'btn btn--ghost', onclick: () => file.click() }, svg('upload'), 'Hochladen') : null, file),
      h('p', { class: 'bld-hint' }, multi ? 'Mehrere Bilder anklicken, dann „Übernehmen“.' : 'Bild anklicken, dann „Übernehmen“.' + ' JPG, PNG oder WebP bis 15 MB.'),
      grid, detail);
    load();
    dialog(multi ? 'Bilder auswählen' : 'Bild auswählen', body, [{ label: 'Abbrechen', value: 'cancel' }, { label: 'Übernehmen', value: 'ok', primary: true }], q).then((v) => resolve(v === 'ok' ? chosen : null));
  });

  // ---------- Speichern, Veröffentlichen, Versionen ----------
  const conflict = async (json, publish) => {
    const v = await dialog('Seite wurde inzwischen geändert', h('p', {}, (json.by || 'Jemand anderes') + ' hat diese Seite gespeichert, während Sie gearbeitet haben. Sie können die Seite neu laden (Ihre Änderungen gehen verloren) oder Ihren Stand trotzdem speichern (die andere Änderung bleibt unter „Versionen“ erhalten).'),
      [{ label: 'Abbrechen', value: 'cancel' }, { label: 'Neu laden', value: 'reload' }, { label: 'Meinen Stand speichern', value: 'force', danger: true }]);
    if (v === 'reload') { state.dirty = false; location.reload(); }
    if (v === 'force') return save(publish, true);
    return false;
  };
  const save = async (publish = false, force = false) => {
    if (state.editing) state.editing.el.blur();
    const btn = $(publish ? '[data-publish]' : '[data-save]');
    btn.disabled = true;
    try {
      const { status, json } = await post(cfg.base + (publish ? 'veroeffentlichen/' : 'speichern/'), { tree: JSON.stringify(state.tree), rev: state.rev, force: force ? '1' : '0' });
      if (status === 409) return conflict(json, publish);
      if (json.rev) state.rev = json.rev;
      if (!json.ok) {
        showErrors(json.errors || []);
        if (json.saved) { state.hasDraft = true; setDirty(false); }
        toast(json.message || 'Nicht gespeichert. Bitte die markierten Punkte prüfen.', true);
        return false;
      }
      const selected = state.sel;
      state.tree = json.tree;
      state.sel = selected && find(selected) ? selected : null;
      state.hasDraft = !json.published;
      setDirty(false, json.published ? 'Veröffentlicht um ' + new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : 'Entwurf gespeichert um ' + new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }));
      showErrors(json.errors || []);
      renderTree(); renderInspector(); scheduleRender(0);
      toast(json.published ? 'Veröffentlicht. Besucher sehen jetzt diesen Stand.' : (json.errors?.length ? 'Entwurf gespeichert, mit Hinweisen.' : 'Entwurf gespeichert.'), !!json.errors?.length);
      return true;
    } finally { btn.disabled = false; }
  };
  $('[data-save]').addEventListener('click', () => save(false));
  $('[data-publish]')?.addEventListener('click', async () => {
    if (await confirmBox('Veröffentlichen?', 'Der aktuelle Stand wird gespeichert und ist danach für alle Besucher sichtbar. Frühere Stände bleiben unter „Versionen“ erhalten.', 'Veröffentlichen')) save(true);
  });
  $('[data-preview]').addEventListener('click', async () => {
    const win = window.open('about:blank', '_blank');
    if (state.dirty && !(await save(false))) { win?.close(); return; }
    if (win) win.location = cfg.base + 'entwurf-ansehen/'; else location.href = cfg.base + 'entwurf-ansehen/';
  });
  $('[data-undo]').addEventListener('click', undo);
  $('[data-redo]').addEventListener('click', redo);

  $('[data-versions]').addEventListener('click', async () => {
    const r = await fetch(cfg.base + 'versionen.json', { credentials: 'same-origin' });
    const list = await r.json();
    const ul = h('ul', { class: 'bld-versions' }, list.length ? list.map((v) => h('li', {},
      h('span', {}, h('strong', {}, v.kind === 'published' ? 'Veröffentlicht' : 'Entwurf'), ' · ' + new Date(v.at.replace(' ', 'T') + 'Z').toLocaleString('de-DE'), v.user ? ' · ' + v.user : ''),
      h('button', { type: 'submit', value: 'v' + v.id, class: 'btn btn--ghost btn--sm' }, 'In den Editor laden'))) : h('li', {}, 'Noch keine Versionen gespeichert.'));
    const extra = h('div', {});
    if (state.hasDraft && !state.dirty) {
      extra.append(h('p', { class: 'bld-hint' }, 'Es gibt einen gespeicherten, unveröffentlichten Entwurf.'),
        h('button', { type: 'submit', value: 'discard', class: 'btn btn--ghost btn--sm' }, svg('trash-2'), 'Entwurf verwerfen (zurück zum veröffentlichten Stand)'));
    }
    const v = await dialog('Versionen', h('div', {}, h('p', { class: 'bld-hint' }, 'Eine Version wird in den Editor geladen. Erst „Speichern“ oder „Veröffentlichen“ übernimmt sie; „Rückgängig“ holt den vorherigen Stand zurück.'), ul, extra), [{ label: 'Schließen', value: 'cancel' }]);
    if (v === 'discard') {
      if (!await confirmBox('Entwurf verwerfen?', 'Alle gespeicherten, unveröffentlichten Änderungen werden verworfen. Der Entwurf bleibt unter „Versionen“ abrufbar.', 'Verwerfen', true)) return;
      const f = h('form', { method: 'post', action: cfg.base + 'verwerfen/' }, h('input', { type: 'hidden', name: '_csrf', value: cfg.csrf }));
      document.body.append(f);
      state.dirty = false;
      f.submit();
    } else if (v.startsWith('v')) {
      const rr = await fetch(cfg.base + 'versionen/' + v.slice(1) + '.json', { credentials: 'same-origin' });
      const data = await rr.json();
      change(() => { state.tree = data.tree; state.sel = null; }, { now: true });
      toast('Version geladen. Mit „Speichern“ als Entwurf übernehmen.');
    }
  });

  // Seite & SEO
  $('[data-settings]')?.addEventListener('click', async () => {
    const p = cfg.page;
    const f = (name, lab, val, o = {}) => {
      const id = 'bld-s-' + name;
      const el = o.area ? h('textarea', { id, rows: 3, maxlength: o.max }) : h('input', { id, type: 'text', value: val || '', maxlength: o.max, disabled: o.disabled });
      if (o.area) el.value = val || '';
      const counter = o.max ? h('small', { class: 'text-muted' }, String((val || '').length) + ' / ' + o.max) : null;
      if (counter) el.addEventListener('input', () => { counter.textContent = el.value.length + ' / ' + o.max; });
      return [h('div', { class: 'bld-field' }, h('label', { for: id }, lab, counter), el, help(o.help)), el];
    };
    const [fTitle, iTitle] = f('title', 'Titel der Seite', p.title, { max: 200 });
    const [fSlug, iSlug] = f('slug', 'Adresse', cfg.is_home ? '/' : p.slug, { max: 120, disabled: cfg.is_system || cfg.is_home, help: cfg.is_home ? 'Die Startseite ist immer unter / erreichbar.' : (cfg.is_system ? 'Feste Seite der Website: Die Adresse ist geschützt.' : 'Nur Kleinbuchstaben, Ziffern, Bindestriche. Ergibt /adresse/.') });
    const [fSt, iSt] = f('seo_title', 'Titel in Suchergebnissen (SEO)', p.seo_title, { max: 70, help: 'Leer: „Titel – Firmenname“.' });
    const [fSd, iSd] = f('seo_description', 'Beschreibung in Suchergebnissen', p.seo_description, { max: 170, area: true, help: 'Ein bis zwei Sätze, 120 bis 160 Zeichen.' });
    const errs = h('div', {});
    const v = await dialog('Seite & SEO', h('div', {}, errs, fTitle, fSlug, fSt, fSd, h('p', { class: 'bld-hint' }, 'Diese Angaben werden sofort gespeichert und gelten unabhängig vom Entwurf.')), [{ label: 'Abbrechen', value: 'cancel' }, { label: 'Speichern', value: 'ok', primary: true }], iTitle);
    if (v !== 'ok') return;
    const data = { title: iTitle.value, seo_title: iSt.value, seo_description: iSd.value };
    if (!cfg.is_system && !cfg.is_home) data.slug = iSlug.value;
    const { json } = await post(cfg.base + 'einstellungen/', data);
    if (!json.ok) { toast(Object.values(json.errors || {}).join(' ') || 'Nicht gespeichert.', true); return; }
    Object.assign(cfg.page, data);
    const opt = $('[data-page-select] option[value="' + cfg.id + '"]');
    if (opt) opt.textContent = (cfg.is_home ? '⌂ ' : '') + data.title;
    toast('Seiteneinstellungen gespeichert.');
  });
  $('[data-page-select]')?.addEventListener('change', (e) => { location.href = '/verwaltung/seiten/' + e.target.value + '/editor/'; });

  // ---------- Tastatur ----------
  const typing = (e) => { const t = e.target; return t && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName)); };
  function keys(e) {
    const mod = e.ctrlKey || e.metaKey;
    if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); save(false); return; }
    if (typing(e)) return;
    if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) redo(); else undo(); return; }
    if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); return; }
    if (!state.sel) return;
    if (mod && e.key.toLowerCase() === 'd') { e.preventDefault(); duplicate(state.sel); }
    else if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); remove(state.sel); }
    else if (e.key === 'Escape') { const a = ancestors(state.sel); select(a.length ? a[a.length - 1].id : null); }
    else if (e.altKey && e.key === 'ArrowUp') { e.preventDefault(); move(state.sel, -1); }
    else if (e.altKey && e.key === 'ArrowDown') { e.preventDefault(); move(state.sel, 1); }
  }
  document.addEventListener('keydown', keys);

  // ---------- Start ----------
  const loadComponents = async () => {
    if (cfg.kind !== 'page') return;
    try {
      const r = await fetch('/verwaltung/bausteine/liste.json', { credentials: 'same-origin' });
      state.components = (await r.json()).map((c) => ({ ...c, id: Number(c.id) }));
    } catch { state.components = []; }
  };
  const loadLinks = async () => {
    try {
      const r = await fetch('/verwaltung/links.json', { credentials: 'same-origin' });
      const list = await r.json();
      document.body.append(h('datalist', { id: 'bld-links' }, h('option', { value: '/' }, 'Startseite'), list.map((l) => h('option', { value: l.url }, l.label))));
    } catch { /* Vorschläge sind optional */ }
  };
  const loadMedia = async () => {
    try {
      const r = await fetch('/verwaltung/medien/auswahl.json', { credentials: 'same-origin' });
      (await r.json()).forEach((m) => state.media.set(m.id, m));
    } catch { /* Vorschaubilder sind optional */ }
  };
  setDirty(false);
  renderTree(); renderLibrary(); renderDesign(); updateHistoryButtons();
  Promise.all([loadComponents(), loadLinks(), loadMedia()]).then(() => { renderLibrary(); renderTree(); renderInspector(); });
})();
