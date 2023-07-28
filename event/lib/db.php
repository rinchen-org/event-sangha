<?php

function get_db() {
    return new SQLite3(dirname(__DIR__) . '/db.sqlite');
}
