<?php
$csmsRunning = trim(shell_exec("ss -tulnp | grep ':9001'")) !== '';
if ($csmsRunning) {
    exec("nohup php /var/www/ocpp-php/src/Examples/v16/ChargePoint.php > /var/log/ocpp/chargepoint.log 2>&1 &");
}
header("Location: index.php");
?>
