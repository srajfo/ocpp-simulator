<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use SolutionForest\OcppPhp\Ocpp\v16\Calls\StatusNotification;
use SolutionForest\OcppPhp\Ocpp\JsonSchemaValidator;
use WebSocket\Client;

// -----------------------------
// 1) ARGUMENTI
// -----------------------------
$cpId   = intval($argv[1] ?? 0);
$userId = intval($argv[2] ?? 0);

if ($cpId === 0) exit("Missing CP ID\n");
if ($userId <= 0) $userId = null;

// -----------------------------
// 2) STATUS
// -----------------------------
$status   = "Charging";
$statusId = 3;

// -----------------------------
// 3) STATUS FILE
// -----------------------------
if (!is_dir("/var/log/ocpp")) {
    mkdir("/var/log/ocpp", 0777, true);
}

file_put_contents("/var/log/ocpp/charger_status_{$cpId}.txt", $status);

// -----------------------------
// 4) LOG
// -----------------------------
function cpLog($msg, $status, $cpId) {
    $logFile = "/var/log/ocpp/status_{$status}_{$cpId}.log";
    file_put_contents($logFile, "[" . date("Y-m-d H:i:s") . "] $msg\n", FILE_APPEND);
}

cpLog("status_Charging.php pokrenut (cp_id={$cpId}, user_id=" . ($userId ?? 'NULL') . ")", $status, $cpId);

// -----------------------------
// 5) DB SPOJEVI
// -----------------------------
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=ocpp_simulator;charset=utf8mb4",
        "srajf",
        "Passw0rd",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdoSystem = new PDO(
        "mysql:host=localhost;dbname=ocpp_system;charset=utf8mb4",
        "srajf",
        "Passw0rd",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

} catch (Exception $e) {
    cpLog("SQL ERROR: " . $e->getMessage(), $status, $cpId);
    exit("DB Connection failed\n");
}

// -----------------------------
// 6) OCPP StatusNotification
// -----------------------------
try {
    $wsUrl = "ws://127.0.0.1:9001/" . $cpId;
    $client = new Client($wsUrl);

    $sn = new StatusNotification();
    $sn->connectorId = 1;
    $sn->errorCode   = "NoError";
    $sn->status      = $status;
    $sn->timestamp   = date("c");

    JsonSchemaValidator::validate($sn, 'v1.6');

    $payload = $sn->toArray();
    $payload['userId'] = $userId;

    $messageId = "cp_" . uniqid();
    $frame     = [2, $messageId, "StatusNotification", $payload];

    $client->text(json_encode($frame));
    cpLog("OCPP Charging frame poslan na $wsUrl", $status, $cpId);

} catch (Exception $e) {
    cpLog("WS ERROR: " . $e->getMessage(), $status, $cpId);
}

// -----------------------------
// 7) SIMULATOR DB – TRANSAKCIJE
// -----------------------------

// helper za SoC
function getSoc(PDO $pdo, int $txId): int {
    $stmt = $pdo->prepare("
        SELECT soc FROM charging_curve
        WHERE transaction_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$txId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? intval($row['soc']) : 0;
}

// 7.1 – suspended?
$stmt = $pdo->prepare("
    SELECT * FROM transactions
    WHERE stop_time IS NULL
      AND chargepoint_id = ?
      AND status = 'suspended_evse'
    ORDER BY id DESC
    LIMIT 1
");
$stmt->execute([$cpId]);
$suspendedTx = $stmt->fetch(PDO::FETCH_ASSOC);

if ($suspendedTx) {
    $txId = intval($suspendedTx['id']);
    $soc  = getSoc($pdo, $txId);

    $pdo->prepare("
        UPDATE transactions
        SET status = 'active', user_id = ?
        WHERE id = ?
    ")->execute([$userId, $txId]);

    cpLog("Resume suspended tx $txId", $status, $cpId);

    if ($soc < 100) {
        exec("php /var/www/ocpp-php/src/Examples/v16/soc_generator.php $txId $cpId > /dev/null 2>&1 &");
        cpLog("Pokrenut SoC generator za suspended tx $txId", $status, $cpId);
    }

} else {

    // 7.2 – aktivna?
    $stmt = $pdo->prepare("
        SELECT * FROM transactions
        WHERE stop_time IS NULL AND chargepoint_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$cpId]);
    $activeTx = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activeTx) {
        $txId = intval($activeTx['id']);
        $soc  = getSoc($pdo, $txId);

        if ($soc < 100) {
            exec("php /var/www/ocpp-php/src/Examples/v16/soc_generator.php $txId $cpId > /dev/null 2>&1 &");
            cpLog("Pokrenut SoC generator za aktivnu tx $txId", $status, $cpId);
        }

    } else {

        // 7.3 – kreiraj novu
        $pdo->prepare("
            INSERT INTO transactions (chargepoint_id, connector_id, start_time, status, user_id)
            VALUES (?, 1, NOW(), 'active', ?)
        ")->execute([$cpId, $userId]);

        $txId = intval($pdo->lastInsertId());
        cpLog("Kreirana nova tx $txId", $status, $cpId);

        $initialSoC = rand(10, 40);

        $pdo->prepare("
            INSERT INTO charging_curve (transaction_id, timestamp, soc)
            VALUES (?, NOW(), ?)
        ")->execute([$txId, $initialSoC]);

        cpLog("Initial SoC $initialSoC% za tx $txId", $status, $cpId);

        exec("php /var/www/ocpp-php/src/Examples/v16/soc_generator.php $txId $cpId > /dev/null 2>&1 &");
        cpLog("Pokrenut SoC generator za novu tx $txId", $status, $cpId);
    }
}

// -----------------------------
// 8) connectors
// -----------------------------
$pdo->prepare("
    UPDATE connectors
    SET status_id = ?, error_code = 'NoError', last_update = NOW()
    WHERE chargepoint_id = ? AND connector_id = 1
")->execute([$statusId, $cpId]);

// -----------------------------
// 9) SISTEM BAZA – Status_Punionice
// -----------------------------
$pdoSystem->prepare("
    INSERT INTO Status_Punionice (ID_Punionice, ID_Status, VrijemePostavljanja, ID_Korisnika)
    VALUES (?, ?, NOW(), ?)
")->execute([$cpId, $statusId, $userId]);

cpLog("Upisan novi status 'Charging' u Status_Punionice", $status, $cpId);

// -----------------------------
// 10) EVENTS
// -----------------------------
$eventPayload = json_encode(["status" => $status, "userId" => $userId]);

$pdo->prepare("
    INSERT INTO events (message_type, action, payload, chargepoint_id, created_at)
    VALUES ('StatusNotification', ?, ?, ?, NOW())
")->execute([$status, $eventPayload, $cpId]);

cpLog("status_Charging.php završen", $status, $cpId);
