// Progressive enhancement only. Navigation remains visible without JavaScript.
(function () {
  var sidebar = document.getElementById('ui-sidebar');
  var toggle = document.getElementById('ui-nav-toggle');
  var backdrop = document.getElementById('ui-backdrop');
  var close = document.getElementById('ui-nav-close');
  var main = document.querySelector('.ui-main-shell');
  if (!sidebar || !toggle || !backdrop || !window.matchMedia) return;
  var media = window.matchMedia('(max-width: 760px)');
  var open = false;
  function update(next, focus) {
    open = !!next && media.matches;
    document.body.classList.toggle('ui-nav-open', open);
    sidebar.inert = media.matches && !open;
    if (main) main.inert = open;
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');
    backdrop.hidden = !open;
    if (focus) (open ? sidebar.querySelector('a, button') : toggle).focus();
  }
  document.body.classList.add('ui-enhanced');
  toggle.hidden = false;
  if (close) close.hidden = false;
  update(false, false);
  toggle.addEventListener('click', function () { update(!open, true); });
  backdrop.addEventListener('click', function () { update(false, true); });
  if (close) close.addEventListener('click', function () { update(false, true); });
  document.addEventListener('keydown', function (event) {
    if (!open) return;
    if (event.key === 'Escape') { event.preventDefault(); update(false, true); }
    if (event.key === 'Tab') {
      var items = sidebar.querySelectorAll('a[href], button:not([disabled])');
      var first = items[0], last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });
  function resize() { update(false, false); }
  if (media.addEventListener) media.addEventListener('change', resize);
  else if (media.addListener) media.addListener(resize);
})();
