/*
 * Newsletter-Anmeldung (Erweiterung newsletter): frisches Prüf-Token beim Laden (Core\SpamGuard, gecachte Seiten),
 * Rechenaufgabe falls auf der Website eingeschaltet, Absenden per fetch mit Meldung im Formular. Ohne Skript: normales Formular.
 */
(() => {
  const enc = new TextEncoder();
  const solve = async (token, bits) => {
    if (!bits || !crypto.subtle) return '';
    const full = bits >> 3, mask = 0xff << (8 - (bits & 7)) & 0xff;
    for (let n = 0; ; n += 256) {
      const res = await Promise.all(Array.from({ length: 256 }, (_, i) => crypto.subtle.digest('SHA-256', enc.encode(token + ':' + (n + i)))));
      for (let i = 0; i < 256; i++) {
        const b = new Uint8Array(res[i]);
        if (b.subarray(0, full).every(x => !x) && !(b[full] & mask)) return String(n + i);
      }
    }
  };
  document.querySelectorAll('form[data-nl]').forEach(form => {
    if (form.dataset.ready) return;
    form.dataset.ready = '1';
    const msg = form.querySelector('[data-nl-msg]');
    const btn = form.querySelector('button[type=submit]');
    const tokenEl = form.querySelector('[data-nl-token]'), powEl = form.querySelector('[data-nl-pow]');
    const fresh = () => fetch(form.dataset.tokenUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => r.ok ? r.json() : null)
      .then(async d => { if (d && d.token) { tokenEl.value = d.token; powEl.value = await solve(d.token, +d.difficulty || 0); } })
      .catch(() => {});
    let ready = fresh();
    const show = (text, ok) => { msg.textContent = text; msg.hidden = false; msg.classList.toggle('is-error', !ok); };
    form.addEventListener('submit', async e => {
      e.preventDefault();
      form.querySelectorAll('[aria-invalid]').forEach(el => el.removeAttribute('aria-invalid'));
      const email = form.elements.email, consent = form.elements.consent;
      if (!email.value.trim() || !email.checkValidity()) { email.setAttribute('aria-invalid', 'true'); email.focus(); return email.reportValidity(); }
      if (!consent.checked) { consent.setAttribute('aria-invalid', 'true'); consent.focus(); return consent.reportValidity(); }
      btn.disabled = true;
      try {
        await ready;
        const r = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const d = await r.json().catch(() => ({}));
        if (d.ok) { show(d.message, true); form.reset(); form.querySelectorAll('.nl-field,.nl-lists,.nl-consent,.nl-submit').forEach(el => { el.hidden = true; }); }
        else {
          show(d.error || form.dataset.error, false);
          const f = d.field && form.elements[d.field];
          if (f) { f.setAttribute('aria-invalid', 'true'); f.focus(); }
          ready = fresh();   // Token ist verbraucht
        }
      } catch {
        show(form.dataset.errorNetwork, false);
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
