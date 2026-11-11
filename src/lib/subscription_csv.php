<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);
require_once __DIR__ . '/person.php';

/** Read-only CSV adapter: no database, QR requests, or email. */
class SubscriptionCsv {
    const MAX_BYTES = 5242880;

    /** @return array<string, mixed> */
    public static function read(string $file): array {
        if (!is_file($file) || !is_readable($file) || filesize($file) > self::MAX_BYTES) {
            throw new DomainException('No se puede leer el CSV o supera el límite de 5 MB.');
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new DomainException('No se pudo leer el archivo CSV.');
        }
        return self::readContents($contents);
    }

    private static function header(string $value): string {
        return strtoupper(strtr(trim($value), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]));
    }

    /** @return array<string, mixed> */
    public static function readContents(string $contents): array {
        if (strlen($contents) > self::MAX_BYTES || !preg_match('//u', $contents)) {
            throw new DomainException('El CSV debe usar UTF-8 y no superar 5 MB.');
        }
        if (substr($contents, 0, 3) === "\xEF\xBB\xBF") {
            $contents = substr($contents, 3);
        }
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el CSV para revisión.');
        }
        fwrite($handle, $contents);
        rewind($handle);
        $result = ['profile' => '', 'label' => '', 'rows' => [], 'errors' => [], 'warnings' => [], 'blank_rows' => 0];
        $header = null;
        $rules = null;
        $line = 1;
        try {
            while (!feof($handle)) {
                $offset = ftell($handle);
                if ($offset === false) {
                    throw new RuntimeException('No se pudo leer la posición del CSV.');
                }
                // Explicit empty escape uses standard CSV double-quote escaping, also on PHP 8.4+.
                $row = fgetcsv($handle, 0, ',', '"', '');
                if ($row === false) {
                    break;
                }
                $rowLine = $line;
                $end = ftell($handle);
                if ($end === false) {
                    throw new RuntimeException('No se pudo leer la posición del CSV.');
                }
                $newlines = preg_match_all('/\r\n|\n|\r/', substr($contents, $offset, $end - $offset));
                if ($newlines === false) {
                    throw new RuntimeException('No se pudieron contar las filas del CSV.');
                }
                $line += $newlines;
                $row = array_map(function ($cell) {
                    return preg_replace('/^\s+|\s+$/u', '', $cell ?? '') ?? '';
                }, $row);
                if (!array_filter($row, function ($cell) { return $cell !== ''; })) {
                    $result['blank_rows']++;
                    continue;
                }
                if ($header === null) {
                    $header = array_map([self::class, 'header'], $row);
                    $named = array_values(array_filter($header, function ($cell) { return $cell !== ''; }));
                    if (count($named) !== count(array_unique($named))) {
                        throw new DomainException('El CSV tiene encabezados duplicados. Corrige las columnas antes de importar.');
                    }
                    $matches = [];
                    foreach (['legacy', '2026'] as $profile) {
                        $candidate = require __DIR__ . '/csv_rules/' . $profile . '.php';
                        if (!array_diff(array_map([self::class, 'header'], $candidate['required']), $header)) {
                            $matches[$profile] = $candidate;
                        }
                    }
                    if (count($matches) !== 1) {
                        throw new DomainException('Columnas CSV no reconocidas o ambiguas. Usa Google Forms histórico o Participantes 2026 (No., NOMBRE, APELLIDOS, CORREO ELECTRONICO, No. CELULAR, CIUDAD).');
                    }
                    $result['profile'] = (string) array_key_first($matches);
                    $rules = reset($matches);
                    if ($rules === false) {
                        throw new RuntimeException('No se pudieron leer las reglas del CSV.');
                    }
                    $result['label'] = $rules['label'];
                    continue;
                }
                if (count($row) !== count($header)) {
                    $result['errors'][] = ['row' => $rowLine, 'message' => 'La cantidad de columnas no coincide con el encabezado.'];
                    continue;
                }
                if ($rules === null) {
                    throw new RuntimeException('No se pudieron leer las reglas del CSV.');
                }
                $values = array_combine($header, $row);
                $data = [];
                foreach ($rules['fields'] as $field => $column) {
                    $data[$field] = $values[self::header($column)];
                }
                $originalPhone = $data['phone'];
                if ($rules['normalize_phone']) {
                    // Normalize only recognizable numbers; preserve uncertain originals for review.
                    $phone = preg_replace('/[\s().-]/u', '', $data['phone']);
                    if ($phone === null) {
                        throw new RuntimeException('No se pudo normalizar el celular.');
                    }
                    $data['phone'] = $phone;
                }
                $person = new Person(trim($data['firstname'] . ' ' . $data['lastname']), $data['email'], $data['phone']);
                try {
                    $person->validateIdentity();
                } catch (Exception $exception) {
                    $messages = [
                        'Fullname is required.' => 'Falta el nombre del participante.',
                        'Email is required.' => 'Falta el correo electrónico.',
                        'Email is invalid.' => 'El correo electrónico no es válido.',
                    ];
                    $result['errors'][] = ['row' => $rowLine, 'message' => $messages[$exception->getMessage()] ?? 'Datos del participante no válidos.'];
                    continue;
                }
                $warnings = [];
                if ($originalPhone === '') {
                    $warnings[] = ['code' => 'phone_missing',
                        'message' => 'No se indicó un número de celular. Puedes cancelar o continuar de todos modos sin celular.'];
                } elseif (!preg_match('/^\+?[0-9]+$/D', str_replace(' ', '', $person->phone))) {
                    $person->phone = $originalPhone;
                    $warnings[] = ['code' => 'phone_unverified',
                        'message' => 'El celular no tiene un formato reconocido; el signo + solo puede ir al inicio. Se conservará el valor original sin adivinar dígitos ni prefijos. Puedes cancelar o continuar de todos modos.'];
                }
                if ($rules['payment_column'] !== null && $values[self::header($rules['payment_column'])] != 1) {
                    $warnings[] = ['code' => 'payment_unverified',
                        'message' => 'El registro histórico no está marcado como válido (valido=1); el aporte puede estar pendiente. Puedes cancelar o continuar de todos modos.'];
                }
                foreach ($warnings as $warning) {
                    $result['warnings'][] = ['row' => $rowLine, 'message' => $warning['message']];
                }
                $result['rows'][] = ['row' => $rowLine, 'fullname' => $person->fullname,
                    'email' => $person->email, 'phone' => $person->phone, 'warnings' => $warnings];
            }
            if ($header === null) {
                throw new DomainException('El CSV está vacío o solo contiene filas vacías.');
            }
            return $result;
        } finally {
            fclose($handle);
        }
    }
}
