<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);
require_once __DIR__ . '/urls.php';

const QR_BASE_WARNING = 'La URL guardada del QR usa una dirección base diferente de HOST_ADDRESS. La ruta de la imagen usa el HOST_ADDRESS actual, pero el enlace dentro del QR no se modificó. Verifica el código antes de usarlo o enviarlo. Puedes cancelar o continuar de todos modos.';

/** Use only the existing immutable filename, never fetch or reuse a saved hostname. */
function qr_image_filename(string $stored): string {
    $filename = basename(parse_url($stored, PHP_URL_PATH) ?: '');
    if (!preg_match('/^[a-f0-9]{64}\.jpg$/D', $filename)) {
        throw new DomainException('El archivo QR guardado no tiene un identificador válido. Revisa el registro; no se regeneró ninguna imagen.');
    }
    return $filename;
}

function qr_image_url(string $stored): string {
    return app_url('static/qr/' . qr_image_filename($stored));
}

/** This detects old URL metadata, not the contents of the bitmap. */
function qr_base_changed(string $stored): bool {
    $separator = strpos($stored, '/static/qr/');
    return $separator !== false && parse_url($stored, PHP_URL_HOST) !== null
        && rtrim(substr($stored, 0, $separator), '/') !== app_base_url();
}

/** Shared by legacy and event mail; no network, generation, or database writes.
 * @return array{data: string, mime: string} */
function qr_inline_image(string $stored): array {
    $filename = qr_image_filename($stored);
    $directory = dirname(__DIR__) . '/static/qr';
    $path = $directory . '/' . $filename;
    if (is_link($directory) || is_link($path) || !is_file($path)
        || ($stat = stat($path)) === false || $stat['nlink'] !== 1 || $stat['size'] > 2097152 || $stat['size'] < 1) {
        throw new DomainException('No se pudo verificar el QR local. Revisa el archivo antes de enviar; no se regeneró ni se envió nada.');
    }
    $data = file_get_contents($path, false, null, 0, 2097153);
    $info = $data === false || strlen($data) > 2097152 ? false : @getimagesizefromstring($data);
    if ($info === false || !in_array($info['mime'], ['image/png', 'image/jpeg'], true)) {
        throw new DomainException('El QR local no es una imagen PNG/JPEG válida de hasta 2 MB. No se puede enviar.');
    }
    return ['data' => $data, 'mime' => $info['mime']];
}
