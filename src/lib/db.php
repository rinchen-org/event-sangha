<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);

function get_db(): SQLite3 {
    return new SQLite3(dirname(__DIR__) . '/db.sqlite');
}
