<?php
/*
    ===============================================================
        Micrologs
        File : authorization/config.php
        Desc : Database connection
    ===============================================================
*/

include_once __DIR__ . "/env.php";

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    sendResponse(false, "Database connection failed", null, 500);
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '" . APP_TIMEZONE . "'");

date_default_timezone_set(TIMEZONE);