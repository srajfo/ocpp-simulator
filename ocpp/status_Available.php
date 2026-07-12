<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use SolutionForest\OcppPhp\Ocpp\v16\Calls\StatusNotification;
use SolutionForest\OcppPhp\Ocpp\JsonSchemaValidator;
use WebSocket\Client;

// -----------------------------
// 1) DEFINICIJA STATUSA
// -----------------------------
$status   = "Available";
$statusId = 1;

// -----------------------------
// 2) ARGUMENTI
// -----------------------------
$cpId   = intval($argv[1] ?? 0);
$userId = intval($argv[2] ?? 0);

if ($cpId === 0) exit("Missing chargepoint ID\n");
if ($userId <= 0) $userId = null;

// -----------------------------
// 3) LOG DIREKTORIJ
// -----------------------------
if (!is_dir("/var/log/ocpp")) {
    mkdir("/var/log/ocpp", 0777, true);
}

function cpLog($msg, $status = "Available", $cpId = 0) {
    $logFile = "/var/log/ocpp/status_{$status}_{$cpId}.log";
    file_put_contents($logFile, "[" . date("Y-m-d H:i:s") . "] $msg\n", FILE_APPEND);
}

cpLog("status_Available.php pokrenut (cp_id={$cpId}, user_id=" . ($userId ?? 'NULL') . ")", $status, $cpId);

// -----------------------------
// 4) SPOJI SE NA BAZE
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

} catch (PDOException $e) {
    cpLog("Baza Error: " . $e->getMessage(), $status, $cpId);
    exit("Database connection failed\n");
}

// -----------------------------
// 5) UPIŠI Available U STATUS FILE
// -----------------------------
file_put_contents("/var/log/ocpp/charger_status_{$cpId}.txt", $status);

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
    $frame = [2, $messageId, "StatusNotification", $payload];

    $client->text(json_encode($frame));
    cpLog("StatusNotification Available poslan na $wsUrl", $status, $cpId);

} catch (Exception $e) {
    cpLog("WebSocket Error: " . $e->getMessage(), $status, $cpId);
}

// -----------------------------
// 7) ZATVORI AKTIVNE TRANSAKCIJE
// -----------------------------
$pdo->prepare("
    UPDATE transactions
    SET stop_time = NOW(), status = 'finished'
    WHERE stop_time IS NULL AND chargepoint_id = ?
")->execute([$cpId]);

cpLog("Aktivna transakcija zatvorena (ako je postojala)", $status, $cpId);

// -----------------------------
// 8) SISTEM BAZA – Status_Punionice
// -----------------------------
$pdoSystem->prepare("
    INSERT INTO Status_Punionice (ID_Punionice, ID_Status, VrijemePostavljanja, ID_Korisnika)
    VALUES (?, ?, NOW(), ?)
")->execute([$cpId, $statusId, $userId]);

cpLog("Upisan novi status Available u Status_Punionice", $status, $cpId);

// -----------------------------
// 9) EVENTS
// -----------------------------
$eventPayload = json_encode([
    "status" => $status,
    "userId" => $userId,
]);

$pdo->prepare("
    INSERT INTO events (message_type, action, payload, chargepoint_id, created_at)
    VALUES ('StatusNotification', ?, ?, ?, NOW())
")->execute([$status, $eventPayload, $cpId]);

cpLog("status_Available.php završen uspješno", $status, $cpId);
