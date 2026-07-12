<?php
echo "<pre>";
echo "[" . date("Y-m-d H:i:s") . "] Zaustavljanje CSMS procesa\n\n";

// Pronadi PID iz ss izlaza
$pid = trim(shell_exec("ss -tulnp | grep ':9001' | grep -oP 'pid=\\K[0-9]+'"));

if ($pid) {
    shell_exec("kill -9 $pid");
    echo "? CSMS proces ($pid) je potpuno zaustavljen.\n";
} else {
    echo "? Nema aktivnog CSMS procesa na portu 9001.\n";
}
echo "</pre>";
?>
