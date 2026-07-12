<?php
$logFile = "/var/log/ocpp/chargepoint.log";

// isprazni log
file_put_contents($logFile, "");

// preusmjeri natrag na index
header("Location: index.php");
exit;
