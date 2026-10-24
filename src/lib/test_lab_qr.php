<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);

/** Only called by an explicit staff POST. The remote service receives an opaque test UUID URL. */
function test_lab_fetch_qr(string $url): string {
    $handle = curl_init('https://api.qrserver.com/v1/create-qr-code/?size=300x300&format=png&qzone=4&data=' . rawurlencode($url));
    if ($handle === false) { throw new RuntimeException('No se pudo iniciar la generación del QR.'); }
    $image = '';
    curl_setopt_array($handle, [CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$image): int {
            if (strlen($image) + strlen($chunk) > 262144) { return 0; }
            $image .= $chunk;
            return strlen($chunk);
        }]);
    try {
        if (!curl_exec($handle) || curl_getinfo($handle, CURLINFO_HTTP_CODE) !== 200) {
            throw new RuntimeException('El servicio de imágenes QR no respondió correctamente. Los participantes siguen guardados; no vuelvas a generarlos.');
        }
    } finally { curl_close($handle); }
    return $image;
}
