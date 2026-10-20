/*
 * Newsletter (Erweiterung) – Verwaltung: Live-Vorschau neben Editoren mit [data-nl-live] (Ausgabe, Design).
 *  - Jede Änderung (verzögert) geht an die Vorschau-Adresse, das Ergebnis landet im Rahmen (srcdoc) – nichts wird gespeichert.
 *  - Desktop: Rahmen in echter Mail-Breite (680 px) und auf die Spalte verkleinert; Handy: 390 px. Dunkel: Darstellung im Dunkelmodus.
 *  - Ein Scrollbereich: Der Rahmen scrollt nie selbst (Höhe = Inhalt, auch wenn Bilder nachladen), das Mausrad über der Mail
 *    scrollt die Vorschau.
 *  - Klick auf einen Baustein in der Vorschau öffnet ihn im Editor (tbody[data-nl-area][data-nl-i]).
 *  - Bausteine lassen sich zusätzlich zu ↑ ↓ per Ziehen ordnen (am Titel greifen).
 */
(() => {
  const d = document;
  const t = (s, p) => (window.CMSAdmin?.t ? window.CMSAdmin.t(s, p) : s);

  const scale = frame => {
    const stage = frame.closest('.nl-preview__stage'), prev = frame.closest('.nl-preview');
    if (!stage) return;
    const w = prev?.classList.contains('is-mobile') ? 390 : 680;
    let h = 600;
    try { h = Math.max(300, frame.contentDocument.body.scrollHeight, frame.contentDocument.documentElement.scrollHeight); } catch {}
    const k = Math.min(1, (stage.clientWidth - 24) / w);
    frame.style.width = w + 'px';
    frame.style.height = h + 'px';
    frame.style.transform = k < 1 ? `scale(${k})` : '';
    const box = frame.parentElement;
    if (box?.classList.contains('nl-preview__box')) { box.style.width = Math.round(w * k) + 'px'; box.style.height = Math.round(h * k) + 'px'; }
  };

  // Rahmen nach jedem Laden vorbereiten: nicht selbst scrollen, Höhe nachführen, Mausrad weiterreichen, Klick → Editor
  const prepare = (frame, onPick) => {
    let doc;
    try { doc = frame.contentDocument; } catch { return; }
    if (!doc?.documentElement || !doc.body) return;
    doc.documentElement.style.overflow = 'hidden';
    const stage = frame.closest('.nl-preview__stage');
    new ResizeObserver(() => scale(frame)).observe(doc.body);
    doc.querySelectorAll('img').forEach(img => img.complete || img.addEventListener('load', () => scale(frame), { once: true }));
    doc.addEventListener('wheel', e => { if (stage) { stage.scrollBy({ top: e.deltaY, left: e.deltaX }); e.preventDefault(); } }, { passive: false });
    if (onPick) {
      const st = doc.createElement('style');
      st.textContent = 'tbody[data-nl-i]{cursor:pointer}tbody[data-nl-i]:hover>tr>td{box-shadow:inset 0 0 0 2px rgba(37,99,235,.55)}a{pointer-events:none}';
      doc.head.appendChild(st);
      doc.addEventListener('click', e => {
        const tb = e.target.closest('tbody[data-nl-i]');
        if (tb) { e.preventDefault(); onPick(tb.dataset.nlArea || 'body', +tb.dataset.nlI); }
      });
    }
    scale(frame);
  };
  addEventListener('resize', () => d.querySelectorAll('.nl-preview__frame').forEach(scale));

  // Baustein im Editor öffnen und hinscrollen
  const pick = (form, area, i) => {
    const box = form.querySelector(`[data-blocks][data-nl-area="${area}"]`) || form.querySelector('[data-blocks]');
    const row = box?.querySelector('[data-blk-items]')?.children[i];
    if (!row) return;
    const tg = row.querySelector('[data-blk=toggle]');
    if (tg?.getAttribute('aria-expanded') !== 'true') tg?.click();
    row.scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    row.classList.add('nl-flash');
    setTimeout(() => row.classList.remove('nl-flash'), 1200);
    row.querySelector('.blk-fields input:not([type=hidden]), .blk-fields .rte-area, .blk-fields select, .blk-fields textarea')?.focus({ preventScroll: true });
  };

  // Ziehen zum Ordnen (am Titel des Bausteins greifen)
  const dragSort = form => {
    form.querySelectorAll('[data-blk-items]').forEach(items => {
      let drag = null;
      items.addEventListener('pointerdown', e => {
        const lg = e.target.closest('[data-blk-item] > legend');
        if (lg && lg.parentElement.parentElement === items) lg.parentElement.draggable = true;
      });
      items.addEventListener('dragstart', e => {
        drag = e.target.closest('[data-blk-item]');
        if (!drag || drag.parentElement !== items) { drag = null; return; }
        drag.classList.add('nl-dragging');
        e.dataTransfer.effectAllowed = 'move';
      });
      items.addEventListener('dragover', e => {
        if (!drag) return;
        e.preventDefault();
        const over = e.target.closest('[data-blk-item]');
        if (!over || over === drag || over.parentElement !== items) return;
        const r = over.getBoundingClientRect();
        items.insertBefore(drag, e.clientY < r.top + r.height / 2 ? over : over.nextElementSibling);
      });
      items.addEventListener('dragend', () => {
        if (!drag) return;
        drag.classList.remove('nl-dragging');
        drag.draggable = false;
        drag = null;
        items.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });
  };

  d.querySelectorAll('form[data-nl-live]').forEach(form => {
    const frame = form.querySelector('[data-nl-frame]');
    const state = form.querySelector('[data-nl-state]');
    const darkBtn = form.querySelector('[data-nl-dark]');
    if (!frame) return;
    dragSort(form);
    let timer = 0, ctrl = null, dark = false, last = '';
    frame.addEventListener('load', () => prepare(frame, (area, i) => pick(form, area, i)));
    const run = async () => {
      const fd = new FormData(form);
      fd.delete('_next'); fd.delete('_action');
      if (dark) fd.set('_dark', '1');
      const key = JSON.stringify([...fd.entries()].filter(([k]) => k !== '_token' && k !== '_csrf'));
      if (key === last) return;
      last = key;
      ctrl?.abort(); ctrl = new AbortController();
      if (state) state.textContent = t('Vorschau wird aktualisiert …');
      try {
        const r = await fetch(form.dataset.nlLive, { method: 'POST', body: fd, credentials: 'same-origin', signal: ctrl.signal,
          headers: { 'X-CSRF-Token': d.getElementById('adm-csrf')?.value || window.CMS_CSRF || '' } });
        const html = await r.text();
        const stage = frame.closest('.nl-preview__stage');
        const y = stage?.scrollTop || 0;
        frame.srcdoc = html;
        frame.addEventListener('load', () => { if (stage) stage.scrollTop = y; }, { once: true });
        if (state) state.textContent = r.ok ? '' : t('Vorschau nicht möglich');
      } catch (e) {
        if (e.name !== 'AbortError' && state) state.textContent = t('Vorschau nicht möglich');
      }
    };
    const soon = () => { clearTimeout(timer); timer = setTimeout(run, 500); };
    form.addEventListener('input', soon);
    form.addEventListener('change', soon);
    form.addEventListener('click', e => { if (e.target.closest('[data-blk]')) soon(); });
    // Medien- und Linkauswahl setzen Werte per Skript – regelmäßig vergleichen (ohne Änderung keine Anfrage)
    setInterval(() => { if (!d.hidden) run(); }, 2500);
    form.querySelectorAll('[data-nl-device]').forEach(b => b.addEventListener('click', () => {
      form.querySelectorAll('[data-nl-device]').forEach(x => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-pressed', String(x === b)); });
      frame.closest('.nl-preview')?.classList.toggle('is-mobile', b.dataset.nlDevice === 'mobile');
      scale(frame);
    }));
    darkBtn?.addEventListener('click', () => { dark = !dark; darkBtn.classList.toggle('is-on', dark); darkBtn.setAttribute('aria-pressed', String(dark)); last = ''; run(); });
    run();
  });

  // Bericht (versendete Ausgabe): Vorschau mit src
  d.querySelectorAll('.nl-preview__frame[src]').forEach(f => { f.addEventListener('load', () => prepare(f, null)); scale(f); });
  d.querySelectorAll('.nl-preview__bar').forEach(bar => {
    if (bar.closest('form[data-nl-live]')) return;
    const prev = bar.closest('.nl-preview');
    bar.querySelectorAll('[data-nl-device]').forEach(b => b.addEventListener('click', () => {
      bar.querySelectorAll('[data-nl-device]').forEach(x => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-pressed', String(x === b)); });
      prev.classList.toggle('is-mobile', b.dataset.nlDevice === 'mobile');
      prev.querySelectorAll('.nl-preview__frame').forEach(scale);
    }));
    bar.querySelector('[data-nl-dark]')?.remove();
  });
})();
