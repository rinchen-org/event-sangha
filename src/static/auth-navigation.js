// Revalidate with the server when a protected page is restored from bfcache.
window.addEventListener('pageshow', function (event) {
  if (event.persisted) window.location.reload();
});
