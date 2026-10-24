// PHP alone decides eligibility. This script submits a server-issued, single-use
// review once; it never chooses sessions, evaluates policy, or retries failures.
(function () {
  const form = document.getElementById('qr-checkin-form');
  if (!form) return;
  const select = document.getElementById('qr-session');
  const reviewButton = form.querySelector('[data-review-session]');
  const autoButton = form.querySelector('[data-auto-submit]');
  const fallback = document.getElementById('qr-auto-fallback');
  const manualMessage = function (message) {
    const status = document.getElementById('qr-auto-status');
    if (status) status.textContent = message || 'No se ha reenviado automáticamente. Utiliza «Registrar asistencia» si deseas continuar.';
    if (fallback) fallback.open = true;
  };
  let stopped = false;

  if (typeof form.requestSubmit !== 'function') {
    manualMessage('Este navegador no permite el registro automático. Utiliza «Registrar asistencia».');
    return;
  }

  form.addEventListener('submit', function () { stopped = true; });
  form.addEventListener('input', function () { stopped = true; manualMessage(); });
  window.addEventListener('pagehide', function () { stopped = true; });
  if (fallback) fallback.addEventListener('toggle', function () { if (fallback.open) stopped = true; });
  if (select && reviewButton) {
    select.addEventListener('change', function () {
      stopped = true;
      form.requestSubmit(reviewButton); // Read-only POST to refresh the warnings.
    });
  }

  if (form.dataset.autoCheckin !== '1' || !autoButton) return;
  const entries = window.performance?.getEntriesByType?.('navigation') || [];
  if (entries[0]?.type === 'reload' || entries[0]?.type === 'back_forward') { manualMessage(); return; }
  const submitOnce = function () {
    if (stopped || !form.isConnected || document.visibilityState !== 'visible' || fallback?.open) return;
    stopped = true;
    form.removeAttribute('data-auto-checkin');
    try { form.requestSubmit(autoButton); }
    catch {
      manualMessage('No se pudo iniciar el registro automático. No se ha confirmado la asistencia; utiliza «Registrar asistencia» para continuar.');
    }
  };
  document.addEventListener('visibilitychange', submitOnce);
  submitOnce();
})();
