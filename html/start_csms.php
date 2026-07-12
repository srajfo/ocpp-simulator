php
<?php
$running = trim(shell_exec("ss -tulnp | grep ':9001'")) !== '';
if (!$running) {
    exec("nohup php /var/www/ocpp-php/src/Examples/v16/csms-react.php > /var/log/ocpp/csms.log 2>&1 &");
}
header("Location: index.php");
?>