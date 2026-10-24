<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);

/** Additive schema shared by the CLI migration and authenticated setup page. */
function create_qr_admission_schema(SQLite3 $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS qr_admission_decision (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        person_id INTEGER NOT NULL REFERENCES person(id),
        event_session_id INTEGER NOT NULL REFERENCES event_session(id),
        decision TEXT NOT NULL CHECK (decision IN ('allow', 'deny')),
        warnings_json TEXT NOT NULL,
        actor TEXT NOT NULL,
        decided_at TEXT NOT NULL
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS qr_admission_lookup
        ON qr_admission_decision (person_id, event_session_id, id)');
}

/** Initialize current schema without running destructive historical migrations. */
function create_setup_schema(SQLite3 $db): void {
    $tables = [
        'person' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, fullname TEXT, email TEXT, phone TEXT, active INTEGER DEFAULT 1',
        'subscription' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INTEGER, datetime TEXT, qr TEXT, active INTEGER DEFAULT 1',
        'event' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, description TEXT, start_date TEXT NOT NULL, end_date TEXT NOT NULL',
        'event_session' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, event_id INTEGER NOT NULL, name TEXT NOT NULL, start_date TEXT NOT NULL, end_date TEXT NOT NULL',
        'attendance' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INTEGER, event_session_id INTEGER, log_time TEXT',
    ];
    $required = [
        'person' => ['id', 'fullname', 'email', 'phone'],
        'subscription' => ['id', 'person_id', 'datetime', 'qr'],
        'event' => ['id', 'name', 'description', 'start_date', 'end_date'],
        'event_session' => ['id', 'event_id', 'name', 'start_date', 'end_date'],
        'attendance' => ['id', 'person_id', 'event_session_id', 'log_time'],
    ];
    foreach ($tables as $table => $definition) {
        $db->exec("CREATE TABLE IF NOT EXISTS $table ($definition)");
        $columns = [];
        $result = $db->query("PRAGMA table_info($table)");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }
        $result->finalize();
        if (array_diff($required[$table], $columns)) {
            throw new DomainException('La base usa un esquema antiguo incompatible. No se modificaron los datos; se necesita una migración revisada por el administrador.');
        }
        if (in_array($table, ['person', 'subscription'], true) && !in_array('active', $columns, true)) {
            $db->exec("ALTER TABLE $table ADD active INTEGER DEFAULT 1");
        }
    }
    create_qr_admission_schema($db);
    $db->exec('CREATE TABLE IF NOT EXISTS app_setting (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS setup_event (setup_key TEXT PRIMARY KEY, event_id INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS setup_session (
        event_id INTEGER NOT NULL, slot TEXT NOT NULL, session_id INTEGER NOT NULL UNIQUE,
        PRIMARY KEY (event_id, slot))');
}

/**
 * Inspect the real schema without changing it. Derive the target from the same
 * additive initializer, in memory, rather than maintaining a second schema.
 * @return array<string, string> Pending step label => trusted SQL.
 */
function pending_setup_schema(?SQLite3 $db): array {
    $model = new SQLite3(':memory:');
    $model->enableExceptions(true);
    try {
        create_setup_schema($model);
        $pending = [];
        $objects = $model->query("SELECT type, name, tbl_name, sql FROM sqlite_master
            WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type DESC, name");
        while ($object = $objects->fetchArray(SQLITE3_ASSOC)) {
            $name = SQLite3::escapeString($object['name']);
            $actual = $db === null ? false : $db->querySingle(
                "SELECT type, tbl_name FROM sqlite_master WHERE name='$name'", true);
            if (!$actual) {
                $pending[($object['type'] === 'table' ? 'Crear tabla ' : 'Crear índice ') . $object['name']] = $object['sql'];
                continue;
            }
            if ($actual['type'] !== $object['type'] || $actual['tbl_name'] !== $object['tbl_name']) {
                throw new DomainException('El esquema existente no es compatible: ' . $object['name'] . '. Requiere revisión; no se modificaron datos.');
            }
            if ($object['type'] === 'index') {
                $columns = static function (SQLite3 $connection) use ($name): array {
                    $result = $connection->query("PRAGMA index_info('$name')");
                    $names = [];
                    while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $names[] = $row['name']; }
                    $result->finalize();
                    return $names;
                };
                if ($columns($db) !== $columns($model)) {
                    throw new DomainException('El índice ' . $object['name'] . ' no es compatible. Requiere revisión.');
                }
                continue;
            }
            $columns = [];
            $result = $db->query("PRAGMA table_info('$name')");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $columns[$row['name']] = $row; }
            $result->finalize();
            $expected = $model->query("PRAGMA table_info('$name')");
            while ($column = $expected->fetchArray(SQLITE3_ASSOC)) {
                if (!isset($columns[$column['name']]) && $column['name'] === 'active'
                    && in_array($object['name'], ['person', 'subscription'], true)) {
                    $pending['Añadir estado a ' . $object['name']] = "ALTER TABLE $name ADD active INTEGER DEFAULT 1";
                } elseif (!isset($columns[$column['name']]) || $columns[$column['name']]['pk'] !== $column['pk']
                    || ($column['name'] === 'id' && strtoupper($columns[$column['name']]['type']) !== 'INTEGER')) {
                    throw new DomainException('La tabla ' . $object['name'] . ' usa un esquema antiguo incompatible. Requiere revisión; no se modificaron datos.');
                }
            }
            $expected->finalize();
            // Mapping uniqueness is necessary to avoid adopting a session twice.
            if ($object['name'] === 'setup_session') {
                $uniqueSession = false;
                $indexes = $db->query("PRAGMA index_list('setup_session')");
                while ($index = $indexes->fetchArray(SQLITE3_ASSOC)) {
                    if (!$index['unique'] || $index['partial']) { continue; }
                    $info = $db->query("PRAGMA index_info('" . SQLite3::escapeString($index['name']) . "')");
                    $first = $info->fetchArray(SQLITE3_ASSOC);
                    if ($first && $first['name'] === 'session_id' && !$info->fetchArray(SQLITE3_ASSOC)) { $uniqueSession = true; }
                    $info->finalize();
                }
                $indexes->finalize();
                if (!$uniqueSession) { throw new DomainException('Los vínculos de sesiones requieren una revisión de integridad.'); }
            }
        }
        $objects->finalize();
        return $pending;
    } finally {
        $model->close();
    }
}
