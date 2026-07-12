<?php
$logFile = "/var/www/ocpp-php/src/Examples/v16/csms.log";
$scriptPath = "/var/www/ocpp-php/src/Examples/v16/csms-react.php";

// 1. Pronadi PID
$pid = trim(shell_exec("pgrep -f csms-react.php"));

if ($pid) {
    shell_exec("kill $pid");
    file_put_contents($logFile, "[" . date("Y-m-d H:i:s") . "] CSMS server ($pid) zaustavljen.\n", FILE_APPEND);
    sleep(1); // kratka pauza da se port oslobodi
} else {
    file_put_contents($logFile, "[" . date("Y-m-d H:i:s") . "] CSMS server nije bio aktivan.\n", FILE_APPEND);
}

// 2. Pokreni novu instancu
exec("nohup php $scriptPath > $logFile 2>&1 &");
file_put_contents($logFile, "[" . date("Y-m-d H:i:s") . "] CSMS server ponovno pokrenut.\n", FILE_APPEND);

// 3. Vrati se na dashboard
header("Location: index.php");
?>
