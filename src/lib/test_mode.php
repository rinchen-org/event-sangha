<?php
require_once __DIR__ . '/dotenv.php';
auth_block_direct(__FILE__);

// Strict, server-side opt-in. Reading the flag never creates configuration/data.
function test_mode_enabled(): bool {
    return (load_env(false)['TEST_ENV'] ?? '0') === '1';
}

function test_mode_require(): void {
    auth_require();
    if (!test_mode_enabled()) { auth_fail(404, 'Las herramientas de prueba no están disponibles.'); }
}
