<?php
// get_status_text.php
$path = "/var/log/ocpp/charger_status.txt";
echo file_exists($path) ? trim(file_get_contents($path)) : "Unknown";