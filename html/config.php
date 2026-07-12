<?php
session_start();

$db_host = '127.0.0.1';
$db_user = 'srajf';
$db_pass = 'Passw0rd';
$db_name = 'ocpp_system';

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($mysqli->connect_errno) {
    error_log("DB connect failed: " . $mysqli->connect_error);
    http_response_code(500);
    exit;
}

$mysqli->set_charset('utf8mb4');
