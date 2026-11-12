<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);
require_once __DIR__ . '/dotenv.php';

/** Explicit per-installation configuration; never infer an origin from request headers. */
function app_base_url(): string {
    $base = rtrim(trim(load_env(false)['HOST_ADDRESS'] ?? ''), '/');
    $parts = parse_url($base);
    if ($base === '' || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $base)
        || !filter_var($base, FILTER_VALIDATE_URL) || $parts === false
        || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', rawurldecode($parts['path'] ?? ''))) {
        throw new DomainException('Configura HOST_ADDRESS en src/.env con la URL completa de esta instalación (http o https), incluido su subdirectorio, sin consulta ni credenciales.');
    }
    return $base;
}

/** $path is an application-relative path, not an arbitrary destination. */
function app_url(string $path): string {
    if (preg_match('/[\s\x00-\x1f\x7f\\\\]/', $path) || str_starts_with($path, '//')
        || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)
        || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', rawurldecode(explode('?', $path, 2)[0]))) {
        throw new InvalidArgumentException('La ruta debe pertenecer a la aplicación.');
    }
    return app_base_url() . '/' . ltrim($path, '/');
}
