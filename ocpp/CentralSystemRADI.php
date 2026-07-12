<?php
require_once __DIR__ . '/vendor/autoload.php';

use OCPP\Calls;
use OCPP\JsonSchemaValidator;
echo ">>> OVO JE PRAVI CENTRAL SYSTEM <<<\n";


// Log helper
function cpLog($msg) {
    $logFile = "/var/log/ocpp/chargepoint.log";
    $line = "[" . date("Y-m-d H:i:s") . "] " . $msg . "\n";
    file_put_contents($logFile, $line, FILE_APPEND);
}

// Citanje statusa iz filea
function getChargerStatus() {
    $statusFile = "/var/log/ocpp/charger_status.txt";
    if (file_exists($statusFile)) {
        return trim(file_get_contents($statusFile));
    }
    return null;
}

// Citanje state (spojen/odspojen)
function isChargerConnected() {
    $stateFile = "/var/log/ocpp/charger_state.txt";
    if (file_exists($stateFile)) {
        return trim(file_get_contents($stateFile)) === "1";
    }
    return false;
}

// Slanje StatusNotification
function sendStatusNotification($client, $status) {
    $sn = new Calls\StatusNotification();
    $sn->connectorId = 1;
    $sn->errorCode = "NoError";
    $sn->status = $status;
    $sn->timestamp = date("c");

    JsonSchemaValidator::validate($sn, 'v1.6');
    $client->text(json_encode($sn->toArray()));
    cpLog("StatusNotification sent: $status");
}

// Glavna petlja
function chargerPointCallback($client) {
    cpLog("Handshake complete, starting simulation...");

    // BootNotification
    $boot = new Calls\BootNotification();
    $boot->chargePointVendor = 'MyVendor';
    $boot->chargePointModel = 'MyModel';
    JsonSchemaValidator::validate($boot, 'v1.6');
    $client->text(json_encode($boot->toArray()));
    cpLog("BootNotification sent");

    $lastStatus = null;

    // Fork za paralelni rad
    $pid = pcntl_fork();
    if ($pid == -1) {
        die("Could not fork");
    } elseif ($pid) {
        // Parent proces ? Heartbeat
        while (true) {
            sleep(30);
            if (isChargerConnected()) {
                $heartbeat = new Calls\Heartbeat();
                JsonSchemaValidator::validate($heartbeat, 'v1.6');
                $client->text(json_encode($heartbeat->toArray()));
                cpLog("Heartbeat sent");
            }
        }
    } else {
        // Child proces ? StatusNotification
        while (true) {
            sleep(5);
            $status = getChargerStatus();
            if ($status && $status !== $lastStatus) {
                sendStatusNotification($client, $status);
                $lastStatus = $status;
            }
        }
    }
}

// Pokretanje klijenta
$client = new WebSocket\Client("ws://127.0.0.1:9001");
chargerPointCallback($client);
