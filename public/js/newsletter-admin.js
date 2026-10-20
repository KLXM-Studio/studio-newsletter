/*
 * Newsletter (Erweiterung) – Verwaltung: Live-Vorschau neben Editoren mit [data-nl-live] (Ausgabe, Design).
 * Bei jeder Änderung (verzögert) wird das Formular an die Vorschau-Adresse geschickt und das Ergebnis in den Rahmen geschrieben –
 * nichts wird gespeichert. Umschalter Desktop/Handy (Breite) und Dunkel (Darstellung der E-Mail-Programme im Dunkelmodus).
 * Vorschaubilder der Galerie ([.nl-tpl__thumb iframe]) werden auf die Kachelbreite skaliert.
 */
(() => {
  const d = document;
  // Desktop: Rahmen in echter Mail-Breite (680 px, sonst greift die Handy-Darstellung der Mail) und auf die Spalte verkleinert;
  // Handy: 390 px, nur verkleinert, wenn die Spalte schmaler ist
  const scale = frame => {
    const stage = frame.closest('.nl-preview__stage'), prev = frame.closest('.nl-preview');
    if (!stage) return;
    const w = prev?.classList.contains('is-mobile') ? 390 : 680;
    let h = 900;
    try { h = Math.max(480, frame.contentDocument.documentElement.scrollHeight); } catch {}
    const avail = stage.clientWidth - 24;
    const k = Math.min(1, avail / w);
    frame.style.width = w + 'px';
    frame.style.height = h + 'px';
    frame.style.transform = k < 1 ? `scale(${k})` : '';
    const box = frame.parentElement;
    if (box && box.classList.contains('nl-preview__box')) { box.style.width = Math.round(w * k) + 'px'; box.style.height = Math.round(h * k) + 'px'; }
  };
  addEventListener('resize', () => d.querySelectorAll('.nl-preview__frame').forEach(scale));
  const t = (s, p) => (window.CMSAdmin?.t ? window.CMSAdmin.t(s, p) : s);
  d.querySelectorAll('form[data-nl-live]').forEach(form => {
    const frame = form.querySelector('[data-nl-frame]');
    const state = form.querySelector('[data-nl-state]');
    const darkBtn = form.querySelector('[data-nl-dark]');
    if (!frame) return;
    let timer = 0, ctrl = null, dark = false, last = '';
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
        const y = frame.contentWindow?.scrollY || 0;
        frame.srcdoc = html;
        frame.addEventListener('load', () => { try { frame.contentWindow.scrollTo(0, y); fit(); } catch {} }, { once: true });
        if (state) state.textContent = r.ok ? '' : t('Vorschau nicht möglich');
      } catch (e) {
        if (e.name !== 'AbortError' && state) state.textContent = t('Vorschau nicht möglich');
      }
    };
    const soon = () => { clearTimeout(timer); timer = setTimeout(run, 500); };
    const fit = () => scale(frame);
    form.addEventListener('input', soon);
    form.addEventListener('change', soon);
    form.addEventListener('click', e => { if (e.target.closest('[data-blk]')) soon(); });
    // Medien- und Linkauswahl setzen Werte per Skript – regelmäßig vergleichen, wenn der Editor sichtbar ist
    setInterval(() => { if (!d.hidden) run(); }, 2500);
    form.querySelectorAll('[data-nl-device]').forEach(b => b.addEventListener('click', () => {
      form.querySelectorAll('[data-nl-device]').forEach(x => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-pressed', String(x === b)); });
      frame.closest('.nl-preview')?.classList.toggle('is-mobile', b.dataset.nlDevice === 'mobile');
      fit();
    }));
    darkBtn?.addEventListener('click', () => { dark = !dark; darkBtn.classList.toggle('is-on', dark); darkBtn.setAttribute('aria-pressed', String(dark)); last = ''; run(); });
    run();
  });
  // Bericht/gesperrte Ausgabe: Vorschau mit src – Höhe anpassen
  d.querySelectorAll('.nl-preview__frame[src]').forEach(f => { f.addEventListener('load', () => scale(f)); scale(f); });
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
