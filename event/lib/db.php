<?php

function get_db(): SQLite3 {
    return new SQLite3(dirname(__DIR__) . '/db.sqlite');
}
