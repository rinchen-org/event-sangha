<?php

function get_db() {
    return new SQLite3(__DIR__ . '/../db.sqlite');
}
