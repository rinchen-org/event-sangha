<?php
// Keep the existing menu usable before building React, or as a fallback.
$manifestPath = dirname(__DIR__) . '/static/app/.vite/manifest.json';
$manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
$entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
if (isset($_GET['legacy']) || !is_array($entry) || empty($entry['file'])) {
    require __DIR__ . '/menu.php';
    return;
}
header('Cache-Control: private, no-store');
function frontend_asset(string $path): string {
    return htmlspecialchars('../static/app/' . $path, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#79364b">
  <title>Rinchen · Nuestra comunidad</title>
  <?php foreach (($entry['css'] ?? []) as $css): ?>
    <link rel="stylesheet" href="<?php echo frontend_asset($css); ?>">
  <?php endforeach; ?>
</head>
<body>
  <div id="root"></div>
  <noscript>Activa JavaScript para usar esta vista o <a href="?legacy=1">abre el menú clásico</a>.</noscript>
  <script type="module" src="<?php echo frontend_asset($entry['file']); ?>"></script>
</body>
</html>
