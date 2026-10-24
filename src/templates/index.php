<?php
require_once dirname(__DIR__) . '/lib/auth.php';
auth_require();
// Old bookmarks now open the only management interface, without a menu fallback.
if (isset($_GET['legacy'])) {
    header('Location: ' . auth_url('templates/index.php'), true, 303);
    exit;
}
$buildDirectory = dirname(__DIR__) . '/static/app';
$manifestPath = $buildDirectory . '/.vite/manifest.json';
$manifest = is_file($manifestPath) && is_readable($manifestPath)
    ? json_decode((string) file_get_contents($manifestPath), true) : null;
$entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
$scripts = is_array($entry) && is_string($entry['file'] ?? null) ? [$entry['file']] : [];
$styles = is_array($entry) ? ($entry['css'] ?? []) : [];
$buildRoot = realpath($buildDirectory);
$buildReady = $scripts !== [] && is_array($styles) && $buildRoot !== false;
if ($buildReady) {
    foreach (array_merge($scripts, $styles) as $asset) {
        $path = is_string($asset) && $asset !== '' ? realpath($buildDirectory . '/' . $asset) : false;
        if ($path === false || strpos($path, $buildRoot . '/') !== 0 || !is_file($path) || !is_readable($path)) {
            $buildReady = false;
            break;
        }
    }
}
if (!$buildReady) {
    auth_fail(503, 'La interfaz no está disponible: faltan archivos de la aplicación o la compilación no es válida. '
        . 'Ejecuta makim frontend.build y despliega src/static/app/ completo, incluido .vite/manifest.json.');
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
  <?php foreach ($styles as $css): ?>
    <link rel="stylesheet" href="<?php echo frontend_asset($css); ?>">
  <?php endforeach; ?>
</head>
<body>
  <div id="root"></div>
  <noscript>Activa JavaScript en tu navegador para usar la aplicación de Rinchen.</noscript>
  <script type="module" src="<?php echo frontend_asset($entry['file']); ?>"></script>
</body>
</html>
