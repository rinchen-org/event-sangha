<?php
require_once dirname(__DIR__) . '/auth.php';
auth_block_direct(__FILE__);

return [
    'label' => 'Participantes 2026',
    'fields' => [
        'firstname' => 'NOMBRE',
        'lastname' => 'APELLIDOS',
        'email' => 'CORREO ELECTRONICO',
        'phone' => 'No. CELULAR',
    ],
    'required' => ['No.', 'NOMBRE', 'APELLIDOS', 'CORREO ELECTRONICO', 'No. CELULAR', 'CIUDAD'],
    // This roster contains no payment/eligibility marker. Do not invent one.
    'payment_column' => null,
    'normalize_phone' => true,
];
