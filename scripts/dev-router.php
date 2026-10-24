<?php
// php -S 127.0.0.1:8000 -t src scripts/dev-router.php
// Development only: mirror the Apache/Nginx private-file protections.
$root = realpath($_SERVER['DOCUMENT_ROOT']);
$uriPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($root === false || strpos($uriPath, "\0") !== false || strpos($uriPath, '\\') !== false
    || preg_match('~(^|/)\.|(^|/)(lib|migrations)/|\.(sqlite3?([-.].*)?|db([-.].*)?|sql|bak|ini|log|html?)$~i', $uriPath)) {
    http_response_code(404);
    return true;
}
if (preg_match('~^(.*)/static/qr/([a-f0-9]{64}\.jpg)$~D', $uriPath, $match)) {
    $controller = $match[1] . '/media.php';
    $_GET = ['file' => $match[2]];
    $_SERVER['SCRIPT_NAME'] = $controller;
    $_SERVER['SCRIPT_FILENAME'] = $root . $controller;
    require $root . $controller;
    return true;
}
// Protect the QR media directory, not public scripts such as qr-checkin.js.
if (preg_match('~/static/qr(?:/|$)~', $uriPath)) {
    http_response_code(404);
    return true;
}
$file = realpath($root . $uriPath);
if ($file === false || strpos($file, $root . '/') !== 0 && $file !== $root) {
    http_response_code(404);
    return true;
}
if (is_dir($file)) {
    if (substr($uriPath, -1) !== '/') {
        header('Location: ' . $uriPath . '/', true, 301);
        return true;
    }
    $file .= '/index.php';
    $uriPath .= 'index.php';
}
if (!is_file($file)) {
    http_response_code(404);
    return true;
}
if (substr($file, -4) === '.php') {
    $_SERVER['SCRIPT_NAME'] = $uriPath;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    require $file;
    return true;
}
return false;
