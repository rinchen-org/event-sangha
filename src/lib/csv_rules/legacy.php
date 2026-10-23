<?php
require_once dirname(__DIR__) . '/auth.php';
auth_block_direct(__FILE__);

// Historical Google Forms export. Keep this mapping independent of newer years.
return [
    'label' => 'Google Forms (histórico)',
    'fields' => [
        'firstname' => 'Nombre',
        'lastname' => 'Apellidos',
        'email' => 'Correo electrónico',
        'phone' => 'Número de celular',
    ],
    'required' => ['Nombre', 'Apellidos', 'Correo electrónico', 'Número de celular', 'valido'],
    'payment_column' => 'valido',
    'normalize_phone' => false,
];
