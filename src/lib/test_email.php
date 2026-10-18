<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);
require_once __DIR__ . '/email.php';

/**
 * Test the production mail helper without touching participants or the database.
 * @param (callable(string, array<string, string>, string, string, string, string, string): bool)|null $sender Test-only injection.
 * @return array{success: bool, message: string}
 */
function send_test_email(?callable $sender = null): array {
    if (get_env('SEND_EMAIL') === '0') {
        throw new DomainException('El envío está desactivado: SEND_EMAIL=0. Actívalo en src/.env para realizar la prueba.');
    }
    $raw = get_env('TEST_EMAIL_TO');
    if (trim($raw) === '') {
        throw new DomainException('Configura TEST_EMAIL_TO en src/.env con uno o más correos separados por comas.');
    }
    if (preg_match('/[\r\n\x00]/', $raw)) {
        throw new DomainException('TEST_EMAIL_TO contiene caracteres no válidos.');
    }
    $recipients = [];
    foreach (explode(',', $raw) as $address) {
        $address = trim($address);
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('TEST_EMAIL_TO contiene un correo no válido. Usa direcciones completas separadas por comas, sin elementos vacíos.');
        }
        $recipients[strtolower($address)] = $address;
    }
    $from = trim(get_env('EMAIL_FROM'));
    $reply = trim(get_env('EMAIL_REPLY_TO')) ?: $from;
    foreach (['EMAIL_FROM' => $from, 'EMAIL_REPLY_TO' => $reply] as $key => $address) {
        if (preg_match('/[\r\n\x00]/', $address) || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException("Configura $key con una dirección de correo válida, sin nombre de presentación.");
        }
    }
    $sender = $sender ?? 'send_email';
    // mail() warnings may otherwise leak paths or break the JSON response.
    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        $accepted = $sender(dirname(__DIR__) . '/templates/test-email.html', [],
            'Rinchen · Prueba de envío de correo', $from, implode(', ', array_values($recipients)), $reply, '');
    } finally {
        restore_error_handler();
    }
    if (!$accepted) {
        throw new RuntimeException('El servidor de correo rechazó el envío.');
    }
    return ['success' => true, 'message' => 'El servidor aceptó el correo de prueba para '
        . count($recipients) . ' destinatario(s). Revisa las bandejas de entrada y spam; esto no confirma la entrega.'];
}
