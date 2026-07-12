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
$status   = "Faulted";
$statusId = 7;

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

cpLog("status_Faulted.php pokrenut (cp_id={$cpId}, user_id=" . ($userId ?? 'NULL') . ")", $status, $cpId);

// -----------------------------
// 5) OCPP StatusNotification
// -----------------------------
try {
    $wsUrl = "ws://127.0.0.1:9001/" . $cpId;
    $client = new Client($wsUrl);

    $sn = new StatusNotification();
    $sn->connectorId = 1;
    $sn->errorCode   = "InternalError";
    $sn->status      = $status;
    $sn->timestamp   = date("c");

    JsonSchemaValidator::validate($sn, 'v1.6');

    $payload = $sn->toArray();
    $payload['userId'] = $userId;

    $messageId = "cp_" . uniqid();
    $frame     = [2, $messageId, "StatusNotification", $payload];

    $client->text(json_encode($frame));
    cpLog("OCPP Faulted frame poslan na $wsUrl", $status, $cpId);

} catch (Exception $e) {
    cpLog("WebSocket Error: " . $e->getMessage(), $status, $cpId);
}

// -----------------------------
// 6) SIMULATOR DB
// -----------------------------
$pdo = new PDO(
    "mysql:host=localhost;dbname=ocpp_simulator;charset=utf8mb4",
    "srajf",
    "Passw0rd",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// oznaci aktivnu transakciju kao faulted
$pdo->prepare("
    UPDATE transactions
    SET status = 'faulted'
    WHERE stop_time IS NULL AND chargepoint_id = ?
")->execute([$cpId]);

// ažuriraj konektor
$pdo->prepare("
    UPDATE connectors
    SET status_id = ?, error_code = 'InternalError', last_update = NOW()
    WHERE chargepoint_id = ? AND connector_id = 1
")->execute([$statusId, $cpId]);

// -----------------------------
// 7) SISTEM BAZA
// -----------------------------
$pdoSystem = new PDO(
    "mysql:host=localhost;dbname=ocpp_system;charset=utf8mb4",
    "srajf",
    "Passw0rd",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdoSystem->prepare("
    INSERT INTO Status_Punionice (ID_Punionice, ID_Status, VrijemePostavljanja, ID_Korisnika)
    VALUES (?, ?, NOW(), ?)
")->execute([$cpId, $statusId, $userId]);

cpLog("Upisan novi status 'Faulted' u Status_Punionice", $status, $cpId);

// -----------------------------
// 8) EVENTS
// -----------------------------
$eventPayload = json_encode([
    "status" => $status,
    "userId" => $userId
]);

$pdo->prepare("
    INSERT INTO events (message_type, action, payload, chargepoint_id, created_at)
    VALUES ('StatusNotification', ?, ?, ?, NOW())
")->execute([$status, $eventPayload, $cpId]);

cpLog("status_Faulted.php završen", $status, $cpId);
