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
