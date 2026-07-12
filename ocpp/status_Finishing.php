<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use SolutionForest\OcppPhp\Ocpp\v16\Calls\StatusNotification;
use SolutionForest\OcppPhp\Ocpp\JsonSchemaValidator;
use WebSocket\Client;

// -----------------------------
// 1) ARGUMENTI (ID-only)
// -----------------------------
$cpId   = intval($argv[1] ?? 0);
$userId = intval($argv[2] ?? 0);

if ($cpId === 0) exit("Missing CP ID\n");
if ($userId <= 0) $userId = null;

// -----------------------------
// 2) STATUS DEFINICIJA
// -----------------------------
$status   = "Finishing";
$statusId = 6;

// -----------------------------
// 3) LOG DIREKTORIJ + STATUS FILE PER CP
// -----------------------------
if (!is_dir("/var/log/ocpp")) {
    mkdir("/var/log/ocpp", 0777, true);
}

// per-CP status file
file_put_contents("/var/log/ocpp/charger_status_{$cpId}.txt", $status);

// -----------------------------
// 4) LOG FUNKCIJA (per CP)
// -----------------------------
function cpLog($msg, $status, $cpId) {
    $logFile = "/var/log/ocpp/status_{$status}_{$cpId}.log";
    file_put_contents($logFile, "[" . date("Y-m-d H:i:s") . "] $msg\n", FILE_APPEND);
}

cpLog("status_Finishing.php pokrenut (cp_id={$cpId}, user_id=" . ($userId ?? 'NULL') . ")", $status, $cpId);

// -----------------------------
// 5) SPAJANJE NA BAZE
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
    cpLog("PRE-WS SQL ERROR: " . $e->getMessage(), $status, $cpId);
    exit("DB Connection failed\n");
}

// -----------------------------
// 6) OCPP StatusNotification (ID kao identitet)
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
    cpLog("OCPP Finishing frame poslan na $wsUrl", $status, $cpId);

} catch (Exception $e) {
    cpLog("WS ERROR: " . $e->getMessage(), $status, $cpId);
}

// -----------------------------
// 7) SQL — ocpp_simulator (Ažuriranje stanja)
// -----------------------------
try {
    // Ažuriraj transakciju u 'finishing'
    $pdo->prepare("
        UPDATE transactions
        SET status = 'finishing'
        WHERE stop_time IS NULL AND chargepoint_id = ?
    ")->execute([$cpId]);

    // Ažuriraj konektor
    $pdo->prepare("
        UPDATE connectors
        SET status_id = ?, error_code = 'NoError', last_update = NOW()
        WHERE chargepoint_id = ? AND connector_id = 1
    ")->execute([$statusId, $cpId]);

    // -----------------------------
    // 8) SISTEM BAZA — ocpp_system
    // -----------------------------
    $pdoSystem->prepare("
        INSERT INTO Status_Punionice (ID_Punionice, ID_Status, VrijemePostavljanja, ID_Korisnika)
        VALUES (?, ?, NOW(), ?)
    ")->execute([$cpId, $statusId, $userId]);

    cpLog("Upisan novi status 'Finishing' u Status_Punionice", $status, $cpId);

    $pdoSystem->prepare("
        UPDATE Status_Punionice
        SET ID_Korisnika = ?
        WHERE ID_Punionice = ?
        ORDER BY ID DESC
        LIMIT 1
    ")->execute([$userId, $cpId]);

    // -----------------------------
    // 9) EVENTS
    // -----------------------------
    $eventPayload = json_encode(["status" => $status, "userId" => $userId]);
    $pdo->prepare("
        INSERT INTO events (message_type, action, payload, chargepoint_id, created_at)
        VALUES ('StatusNotification', ?, ?, ?, NOW())
    ")->execute([$status, $eventPayload, $cpId]);

    cpLog("SQL dio odraden", $status, $cpId);

} catch (Exception $e) {
    cpLog("SQL ERROR: " . $e->getMessage(), $status, $cpId);
}

// -----------------------------
// 10) Pokreni SuspendedEV nakon 3 sekunde
// -----------------------------
cpLog("Pokrecem SuspendedEV za 3 sekunde", $status, $cpId);

exec("(sleep 3; /usr/bin/php /var/www/ocpp-php/src/Examples/v16/status_SuspendedEV.php $cpId $userId) > /var/log/ocpp/finishing_exec_{$cpId}.log 2>&1 &");

cpLog("POSLAN EXEC ZA SuspendedEV", $status, $cpId);
