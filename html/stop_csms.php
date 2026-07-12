<?php
exec("sudo pkill -9 -f '/var/www/ocpp-php/src/Examples/v16/csms-react.php'");
header("Location: index.php");
?>