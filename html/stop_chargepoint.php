<?php
exec("sudo pkill -9 -f '/var/www/ocpp-php/src/Examples/v16/ChargePoint.php'");
header("Location: index.php");
?>
