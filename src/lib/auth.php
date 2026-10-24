<?php

// Temporary single-account authentication. Never expose this hash in client code.
const AUTH_ADMIN_USER = 'admin';
const AUTH_ADMIN_HASH = '$2y$12$2eJhY25bZZ7mFxkeRk5sL.JMG2pmX0kbIXrYGN8qlJNmqTIe5qTOy';
const AUTH_IDLE_SECONDS = 28800; // 8 hours without an authenticated request.
const AUTH_MAX_SECONDS = 86400; // 24 hours from login, even while active.

function auth_block_direct(string $file): void {
    if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath($file)) {
        http_response_code(404);
        exit;
    }
}
auth_block_direct(__FILE__);

function auth_base_path(): string {
    $file = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    $root = dirname(__DIR__) . '/';
    $name = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($file, $root) === 0) {
        $relative = '/' . substr($file, strlen($root));
        if (substr($name, -strlen($relative)) === $relative) {
            return rtrim(substr($name, 0, -strlen($relative)), '/');
        }
    }
    return '';
}

function auth_url(string $relative): string {
    return auth_base_path() . '/' . ltrim($relative, '/');
}

function auth_escape(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Session and throttle files must never live in a served or shared directory. */
function auth_private_directory(string $directory): string {
    $directory = rtrim($directory, '/');
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Authentication state directory is unavailable.');
    }
    $resolved = realpath($directory);
    $permissions = fileperms($directory);
    if ($resolved === false || is_link($directory) || $permissions === false
        || ($permissions & 0077) !== 0 || !is_writable($directory)) {
        throw new RuntimeException('Authentication state directory must be private and writable.');
    }
    foreach ([dirname(__DIR__), $_SERVER['DOCUMENT_ROOT'] ?? ''] as $root) {
        $root = $root !== '' ? realpath($root) : false;
        if ($root !== false && ($resolved === $root || strpos($resolved, rtrim($root, '/') . '/') === 0)) {
            throw new RuntimeException('Authentication state directory must be outside the document root.');
        }
    }
    return $resolved;
}

function auth_state_directory(): string {
    return auth_private_directory(getenv('RINCHEN_AUTH_STATE_DIR')
        ?: sys_get_temp_dir() . '/rinchen-auth-' . substr(hash('sha256', __DIR__), 0, 16));
}

function auth_start(): void {
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: SAMEORIGIN');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        try {
            // Isolate files from PHP's shared save_path and its potentially shorter
            // system cleanup policy. Application expiry checks remain authoritative.
            $directory = auth_private_directory(auth_state_directory() . '/sessions');
            foreach ([
                'session.save_handler' => 'files',
                'session.save_path' => $directory,
                'session.gc_maxlifetime' => (string) AUTH_MAX_SECONDS,
                'session.gc_probability' => '1',
                'session.gc_divisor' => '100',
            ] as $setting => $value) {
                if (ini_set($setting, $value) === false) {
                    throw new RuntimeException('Authentication session storage configuration is unavailable.');
                }
            }
        } catch (Throwable $exception) {
            error_log('Rinchen session: ' . $exception->getMessage());
            auth_fail(503, 'No pudimos iniciar la sesión. Inténtalo más tarde.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        // Do not trust client-supplied forwarded headers. A TLS proxy should set
        // HTTPS at the server or explicitly set RINCHEN_HTTPS=1 in the process.
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || getenv('RINCHEN_HTTPS') === '1';
        session_name('rinchen_session');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => auth_base_path() . '/',
            'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        if (!@session_start()) {
            error_log('Rinchen session: session_start failed.');
            auth_fail(503, 'No pudimos iniciar la sesión. Inténtalo más tarde.');
        }
    }
}

function auth_logged_in(): bool {
    if (($_SESSION['auth_user'] ?? null) !== AUTH_ADMIN_USER) {
        return false;
    }
    $now = time();
    if ($now - ($_SESSION['auth_seen'] ?? 0) >= AUTH_IDLE_SECONDS
        || $now - ($_SESSION['auth_created'] ?? 0) >= AUTH_MAX_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
        return false;
    }
    $_SESSION['auth_seen'] = $now;
    return true;
}

function auth_csrf_token(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function auth_csrf_field(): void {
    echo '<input type="hidden" name="csrf_token" value="' . auth_escape(auth_csrf_token()) . '">';
}

function auth_csrf_valid(): bool {
    $provided = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    return is_string($provided) && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $provided);
}

function auth_json_request(): bool {
    return ($_GET['format'] ?? '') === 'json'
        || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false
        || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
}

/**
 * Only permit local application destinations, not arbitrary redirects.
 * @param mixed $value
 */
function auth_safe_next($value): string {
    $fallback = 'templates/index.php';
    if (!is_string($value) || strlen($value) > 4096
        || preg_match('/[\x00-\x1f\x7f\\\\]/', rawurldecode($value))) {
        return $fallback;
    }
    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['fragment'])) {
        return $fallback;
    }
    $path = $parts['path'] ?? '';
    if (!preg_match('~^(?:scripts/setup\.php|index\.php|media\.php|generate_qr\.php|attendance_log\.php/(?:index\.php)?|templates/(?!login\.php|logout\.php|session\.php)[a-zA-Z0-9_/-]+\.php|static/qr/[a-f0-9]{64}\.jpg)$~D', $path)
        || strpos($path, '..') !== false) {
        return $fallback;
    }
    return $value;
}

function auth_current_next(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $prefix = auth_base_path() . '/';
    return auth_safe_next(strpos($uri, $prefix) === 0 ? substr($uri, strlen($prefix)) : '');
}

function auth_fail(int $status, string $message): void {
    http_response_code($status);
    if (auth_json_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Rinchen</title><p>'
            . auth_escape($message) . '</p><a href="' . auth_escape(auth_url('templates/index.php')) . '">Volver a mi comunidad</a></html>';
    }
    exit;
}

function auth_require(): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    auth_start();
    if (!auth_logged_in()) {
        if (auth_json_request()) {
            auth_fail(401, 'Inicia sesión para continuar.');
        }
        // Never replay a POST after login. Safe GET destinations (including QR
        // query strings) survive the redirect; no password appears in a URL.
        $next = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? auth_current_next() : 'templates/index.php';
        header('Location: ' . auth_url('templates/login.php') . '?next=' . rawurlencode($next), true, 303);
        exit;
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        header('Allow: GET, HEAD, POST');
        auth_fail(405, 'Método no permitido.');
    }
    if ($method === 'POST' && !auth_csrf_valid()) {
        auth_fail(403, 'La sesión del formulario caducó. Recarga la página e inténtalo de nuevo.');
    }
    $checked = true;
}

function auth_post_only(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        auth_fail(405, 'Esta acción requiere un formulario válido.');
    }
}

/** Old QR links remain valid, but a GET must never record attendance. */
function auth_confirm_action(string $title): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        return; // auth_require() has already verified the CSRF token.
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . auth_escape($title)
        . ' · Rinchen</title><link rel="stylesheet" href="' . auth_escape(auth_url('static/auth.css')) . '"></head><body><main class="auth-card"><p class="eyebrow">SAKYA RINCHEN LING</p><h1>' . auth_escape($title)
        . '</h1><p>Confirma esta acción para continuar.</p><form method="post" action="' . auth_escape(auth_url(auth_current_next())) . '">';
    auth_csrf_field();
    echo '<button type="submit">Confirmar y continuar</button></form><a href="' . auth_escape(auth_url('templates/index.php')) . '">Cancelar</a></main></body></html>';
    exit;
}

/** Shared, file-locked per-IP throttle survives new cookies. State is outside src/. */
function auth_attempt_login(string $username, string $password): bool {
    $directory = auth_state_directory();
    $path = $directory . '/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local') . '.json';
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Authentication throttle is unavailable.');
    }
    chmod($path, 0600);
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Authentication throttle is unavailable.');
    }
    try {
        $state = json_decode(stream_get_contents($handle), true);
        $now = time();
        $attempts = is_array($state) ? array_values(array_filter($state, function ($time) use ($now) {
            return is_int($time) && $time > $now - 900;
        })) : [];
        if (count($attempts) >= 5) {
            http_response_code(429);
            header('Retry-After: 900');
            return false;
        }
        $validPassword = password_verify($password, AUTH_ADMIN_HASH);
        $valid = hash_equals(AUTH_ADMIN_USER, $username) && $validPassword;
        $attempts = $valid ? [] : array_merge($attempts, [$now]);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($attempts));
        fflush($handle);
        return $valid;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
