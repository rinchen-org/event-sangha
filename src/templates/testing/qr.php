<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__, 2) . '/lib/test_lab.php';
test_mode_require();
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) { auth_fail(405, 'La imagen solo admite lectura.'); }
$db = null;
try {
    $db = test_lab_db();
    $lab = new TestLab($db);
    $uuid = $_GET['uuid'] ?? '';
    if (!is_string($uuid)) { throw new DomainException('Identificador no válido.'); }
    $row = $lab->attendee($uuid);
    $base = load_env(false)['HOST_ADDRESS'] ?? '';
    if ($row['qr_png'] === null || $row['qr_target'] !== TestLab::checkinUrl($base, $uuid)) { throw new DomainException('Genera primero la imagen QR para la dirección actual.'); }
    header('Content-Type: image/png');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') { echo $row['qr_png']; }
} catch (DomainException $e) { auth_fail(404, $e->getMessage()); }
catch (Throwable $e) { auth_fail(503, 'No se pudo cargar el QR de prueba.'); }
finally { if ($db !== null) { $db->close(); } }
