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
    die("Database connection failed");
}

$conn->set_charset("utf8mb4");
$tz = APP_TIMEZONE;
$stmt = $conn->prepare("SET time_zone = ?");
$stmt->bind_param("s", $tz);
$stmt->execute();
$stmt->close();

date_default_timezone_set(TIMEZONE);