<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);
require_once __DIR__ . '/version.php';

/** Presentation only: no database, configuration creation, or business actions. */
final class AppLayout {
    private static bool $compact = false;

    /** @return array{string, string} */
    public static function route(): array {
        $path = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
        $routes = [
            'event/form.php' => ['Evento', 'eventos'], 'event/list.php' => ['Eventos', 'eventos'],
            'event-session/form.php' => ['Sesión del evento', 'sesiones'], 'event-session/list.php' => ['Sesiones', 'sesiones'],
            'event/register.php' => ['Vincular inscripción', 'eventos'], 'event/email.php' => ['Correos del evento', 'eventos'],
            'subscription/upload.php' => ['Importar participantes', 'participantes'],
            'subscription/form.php' => ['Inscripción', 'participantes'], 'subscription/list.php' => ['Participantes', 'participantes'],
            'subscription/log_attendance.php' => ['Participantes', 'participantes'],
            'subscription/log_attendance_force.php' => ['Participantes', 'participantes'],
            'attendance/form.php' => ['Registrar asistencia', 'asistencia'], 'attendance/list.php' => ['Asistencia', 'asistencia'],
            'attendance/report.php' => ['Informe de asistencia', 'informes'],
        ];
        foreach ($routes as $suffix => $route) {
            if (str_ends_with($path, '/' . $suffix)) { return $route; }
        }
        return ['Mi comunidad', 'inicio'];
    }

    public static function icon(string $name): string {
        $paths = [
            'inicio' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'eventos' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2M14 15h2"/>',
            'sesiones' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'participantes' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M17 15a5 5 0 0 1 4 4v2"/>',
            'asistencia' => '<path d="m3 7 2 2 4-4m-6 12 2 2 4-4M13 7h8M13 17h8"/>',
            'informes' => '<path d="M3 3v18h18M8 16v-4m5 4V8m5 8V5"/>',
            'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
            'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'flower' => '<path d="M12 20C2 18 1 10 5 10c0-7 7-7 7 0 0-7 7-7 7 0 4 0 3 8-7 10Z"/>',
        ];
        return '<svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . ($paths[$name] ?? $paths['flower']) . '</svg>';
    }

    /** @param array<int, string> $styles
     * @param array<int, string> $scripts */
    public static function start(string $title, string $section = 'inicio', bool $compact = false, bool $public = false,
        bool $legacy = false, array $styles = [], array $scripts = []): void {
        if (!$public) { auth_require(); }
        self::$compact = $compact;
        $navigation = ['inicio' => 'Vista general', 'eventos' => 'Eventos', 'sesiones' => 'Sesiones',
            'participantes' => 'Participantes', 'asistencia' => 'Asistencia', 'informes' => 'Informes'];
        ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#79364b">
  <title><?php echo auth_escape($title); ?> · Rinchen</title>
  <?php if ($legacy): ?>
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.css">
  <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
  <?php endif; ?>
  <link rel="stylesheet" href="<?php echo auth_escape(auth_url('static/ui.css')); ?>">
  <?php foreach ($styles as $style): ?><link rel="stylesheet" href="<?php echo auth_escape(auth_url($style)); ?>"><?php endforeach; ?>
  <script defer src="<?php echo auth_escape(auth_url('static/auth-navigation.js')); ?>"></script>
  <script defer src="<?php echo auth_escape(auth_url('static/ui.js')); ?>"></script>
  <?php foreach ($scripts as $script): ?><script defer src="<?php echo auth_escape(auth_url($script)); ?>"></script><?php endforeach; ?>
</head>
<body class="rinchen-ui<?php echo $compact ? ' ui-compact' : ''; ?>">
<?php if ($compact): ?>
<main id="ui-main" class="auth-card" tabindex="-1">
<?php else: ?>
<a class="ui-skip" href="#ui-main">Saltar al contenido</a>
<div class="ui-shell">
  <aside id="ui-sidebar" class="ui-sidebar" aria-label="Navegación de la comunidad">
    <button type="button" id="ui-nav-close" class="ui-menu-button ui-sidebar-close" aria-label="Cerrar navegación" hidden>×</button>
    <a class="ui-brand" href="<?php echo auth_escape(auth_url('templates/index.php#inicio')); ?>"><img src="<?php echo auth_escape(auth_url('static/images/logo-head-square.png')); ?>" alt=""><span>rinchen<small>SAKYA RINCHEN LING</small></span></a>
    <div class="ui-workspace"><span><?php echo self::icon('flower'); ?></span><div>Espacio de comunidad<small>Gestión de encuentros</small></div><i></i></div>
    <p class="ui-nav-label">ORGANIZAR</p>
    <nav aria-label="Navegación principal">
      <?php foreach ($navigation as $key => $label): ?>
      <a class="ui-nav-link<?php echo $section === $key ? ' selected' : ''; ?>" <?php if ($section === $key): ?>aria-current="page"<?php endif; ?> href="<?php echo auth_escape(auth_url('templates/index.php#' . $key)); ?>"><?php echo self::icon($key); ?><span><?php echo $label; ?></span></a>
      <?php endforeach; ?>
      <?php require_once __DIR__ . '/test_mode.php'; if (test_mode_enabled()): ?>
      <a class="ui-nav-link<?php echo $section === 'pruebas' ? ' selected' : ''; ?>" href="<?php echo auth_escape(auth_url('templates/testing/index.php')); ?>"><?php echo self::icon('asistencia'); ?><span>Pruebas · Laboratorio QR</span></a>
      <?php endif; ?>
    </nav>
    <div class="ui-sidebar-bottom"><div class="ui-community-note"><?php echo self::icon('flower'); ?><p>Pequeños encuentros.<br><strong>Grandes conexiones.</strong></p><small>Cultivemos comunidad, juntos.</small></div>
      <form class="ui-logout" method="post" action="<?php echo auth_escape(auth_url('templates/logout.php')); ?>"><?php auth_csrf_field(); ?><button type="submit"><?php echo self::icon('logout'); ?> Cerrar sesión</button></form>
      <div class="ui-signature"><span>RL</span><div>Sakya Rinchen Ling<small>La Paz, Bolivia</small></div></div>
    </div>
  </aside>
  <button type="button" class="ui-backdrop" id="ui-backdrop" aria-label="Cerrar navegación" hidden></button>
  <div class="ui-main-shell">
    <header class="ui-topbar"><div class="ui-breadcrumb"><button type="button" id="ui-nav-toggle" class="ui-menu-button" aria-expanded="false" aria-controls="ui-sidebar" aria-label="Abrir menú" hidden><?php echo self::icon('menu'); ?></button><span>Mi comunidad</span><span aria-hidden="true">›</span><strong><?php echo auth_escape($navigation[$section] ?? $title); ?></strong></div><div class="ui-location"><i></i> La Paz, Bolivia <span class="ui-avatar">RL</span></div></header>
    <main id="ui-main" class="ui-main" tabindex="-1">
      <div class="ui-page-heading"><p class="eyebrow">UN ESPACIO PARA CONECTAR</p><p class="ui-page-title"><?php echo auth_escape($title); ?></p><p>Organiza, conecta y comparte con nuestra comunidad.</p></div>
      <div class="ui-panel">
<?php endif; ?>
<?php
    }

    public static function end(): void {
        if (!self::$compact) { echo '</div>'; }
        ?>
<footer class="<?php echo self::$compact ? 'auth-footnote' : 'ui-footer'; ?>"><span>Sakya Rinchen Ling · Bolivia</span><span>Versión <?php echo auth_escape(APP_VERSION); ?></span><span>Desarrollado por <a href="https://scistitch.com/" target="_blank" rel="noopener noreferrer">SciStitch</a></span></footer>
</main>
<?php if (!self::$compact): ?></div></div><?php endif; ?>
</body>
</html>
<?php
    }
}
