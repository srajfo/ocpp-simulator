<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$transactionId = intval($argv[1] ?? 0);
$cpId          = intval($argv[2] ?? 0);

if ($transactionId <= 0 || $cpId <= 0) exit;

// DB
$pdo = new PDO(
    "mysql:host=localhost;dbname=ocpp_simulator;charset=utf8mb4",
    "srajf",
    "Passw0rd",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// status file za ovaj CP
function getCurrentStatus(int $cpId): string {
    $file = "/var/log/ocpp/charger_status_{$cpId}.txt";
    return file_exists($file) ? trim(file_get_contents($file)) : "Unknown";
}

function getCurrentSoC(PDO $pdo, int $txId): ?int {
    $stmt = $pdo->prepare("
        SELECT soc 
        FROM charging_curve 
        WHERE transaction_id = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([$txId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? intval($row['soc']) : null;
}

while (true) {

    $soc = getCurrentSoC($pdo, $transactionId);
    if ($soc === null) exit;

    // ako je 100% ? pokreni Finishing i prekini generator
    if ($soc >= 100) {

        // pokreni Finishing u pozadini
        exec("php /var/www/ocpp-php/src/Examples/v16/status_Finishing.php $cpId $transactionId > /dev/null 2>&1 &");

        exit;
    }
    if (getCurrentStatus($cpId) !== "Charging") {
        exit;
    }


    // realisticna krivulja
    if ($soc < 20)      $delay = 20;
    elseif ($soc < 60)  $delay = 5;
    elseif ($soc < 80)  $delay = 10;
    else                $delay = 25;

    sleep($delay);
    $soc++;

    $stmt = $pdo->prepare("
        INSERT INTO charging_curve (transaction_id, timestamp, soc)
        VALUES (?, NOW(), ?)
    ");
    $stmt->execute([$transactionId, $soc]);
}
