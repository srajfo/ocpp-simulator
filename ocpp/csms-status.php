<?php
echo "<pre>";
echo "[" . date("Y-m-d H:i:s") . "] CSMS status provjera\n\n";

// 1. Provjera da li port 9001 radi
$portInfo = shell_exec("ss -tuln | grep ':9001'");
if (!empty($portInfo)) {
    echo "? Port 9001 je aktivan\n";

    // 2. Pronadi PID procesa koji koristi port (iz ss izlaza)
   $pid = trim(shell_exec("ss -tulnp | grep ':9001' | awk '{print \$NF}' | cut -d',' -f2 | cut -d= -f2"));
    if ($pid) {
        echo "?? PID procesa: $pid\n";

        // 3. Broj konekcija
        $connCount = trim(shell_exec("lsof -i :9001 | grep ESTABLISHED | wc -l"));
        echo "?? Aktivnih konekcija: $connCount\n";
    } else {
        echo "?? Port radi, ali PID nije pronaden\n";
    }
} else {
    echo "? CSMS nije aktivan (port 9001 nije u upotrebi)\n";
}

// 4. Zadnjih 10 linija iz csms.log
$logFile = "/var/www/ocpp-php/src/Examples/v16/csms.log";
if (file_exists($logFile)) {
    echo "\n?? Zadnjih 20 linija iz csms.log:\n";
    $lastLines = shell_exec("tail -n 20 $logFile");
    echo $lastLines;
} else {
    echo "\n?? Log fajl nije pronaden: $logFile\n";
}

echo "</pre>";
?>
