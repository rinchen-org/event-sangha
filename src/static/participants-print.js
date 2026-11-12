/* Readiness and native printing only. Never submits forms or requests replacement QR codes. */
(() => {
  'use strict';
  const button = document.getElementById('participants-print-button');
  const status = document.getElementById('participants-print-status');
  if (!button || !status) return;
  const images = Array.from(document.querySelectorAll('[data-participant-qr]'));
  let pending = images.length;
  let unavailable = document.querySelectorAll('[data-qr-missing]').length;
  const update = () => {
    button.disabled = pending > 0;
    status.setAttribute('role', unavailable ? 'alert' : 'status');
    status.textContent = pending
      ? `Cargando códigos QR: ${pending} pendiente(s). Espera y revisa la lista antes de imprimir.`
      : unavailable
        ? `${unavailable} código(s) QR no disponible(s). Las incidencias aparecerán en la impresión; revísalas antes de continuar.`
        : 'Vista lista para imprimir. Se incluirá la lista completa con los códigos cargados.';
  };
  button.hidden = false;
  button.addEventListener('click', () => { if (!button.disabled) window.print(); });
  images.forEach(image => {
    let settled = false;
    let failed = false;
    let timer = null;
    const fallback = image.parentElement.querySelector('[data-qr-fallback]');
    const finish = okay => {
      if (!settled) { settled = true; pending -= 1; if (timer !== null) clearTimeout(timer); }
      if (failed && okay) unavailable -= 1;
      if (!failed && !okay) unavailable += 1;
      failed = !okay;
      image.hidden = !okay;
      if (fallback) fallback.hidden = okay;
      update();
    };
    image.addEventListener('load', () => finish(image.naturalWidth > 0), { once:true });
    image.addEventListener('error', () => finish(false), { once:true });
    if (image.complete) finish(image.naturalWidth > 0);
    else timer = setTimeout(() => finish(false), 30000);
  });
  update();
})();
