<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
// Keep the read-only JSON contract used by React and integrations.
if (($_GET['format'] ?? '') === 'json') {
    require_once dirname(__DIR__, 2) . '/lib/frontend.php';
    frontend_list_response('subscriptions');
    exit;
}
// One management list: old HTML bookmarks/forms go to the React event selector.
$target = 'templates/index.php#participantes';
$requestedEvent = $_POST['event_id'] ?? ($_GET['event_id'] ?? null);
if ($requestedEvent !== null) {
    require_once dirname(__DIR__, 2) . '/lib/event_registration.php';
    try { $target .= '/' . EventRegistration::id($requestedEvent); }
    catch (DomainException $error) { auth_fail(400, $error->getMessage()); }
}
header('Location: ' . auth_url($target), true, 303);
exit;
