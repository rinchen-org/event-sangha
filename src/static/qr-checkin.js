// PHP alone decides eligibility. This script submits a server-issued, single-use
// review once; it never chooses sessions, evaluates policy, or retries failures.
(function () {
  const form = document.getElementById('qr-checkin-form');
  if (!form || typeof form.requestSubmit !== 'function') return;
  const select = document.getElementById('qr-session');
  const reviewButton = form.querySelector('[data-review-session]');
  const autoButton = form.querySelector('[data-auto-submit]');
  const manualMessage = function () {
    const status = document.getElementById('qr-auto-status');
    if (status) status.textContent = 'Revisa la sesión y utiliza «Permitir participación». No se ha reenviado automáticamente.';
  };
  let stopped = false;

  form.addEventListener('submit', function () { stopped = true; });
  form.addEventListener('input', function () { stopped = true; manualMessage(); });
  window.addEventListener('pagehide', function () { stopped = true; });
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
    if (stopped || !form.isConnected || document.visibilityState !== 'visible') return;
    stopped = true;
    form.removeAttribute('data-auto-checkin');
    form.requestSubmit(autoButton);
  };
  document.addEventListener('visibilitychange', submitOnce);
  submitOnce();
})();
