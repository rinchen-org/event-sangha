<?php
require_once dirname(__DIR__) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__) . '/lib/layout.php';
include_once __DIR__ . '/config.php';
global $ENV_PROD;

[$layoutTitle, $layoutSection] = AppLayout::route();
AppLayout::start($layoutTitle, $layoutSection, legacy: true);
if ($ENV_PROD === false) { echo '<p class="alert alert-info">Entorno de desarrollo</p>'; }

$messageType = $_SESSION['message']['type'] ?? '';
$messageText = $_SESSION['message']['text'] ?? '';
$_SESSION['message'] = [];
if ($messageText !== '') {
    $alertType = in_array($messageType, ['success', 'warning', 'info'], true) ? $messageType : 'danger';
    echo '<div class="alert alert-' . $alertType . '" role="alert">' . auth_escape($messageText) . '</div>';
}
