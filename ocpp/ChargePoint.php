<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

require __DIR__ . '/../../../vendor/autoload.php';
use WebSocket\Client;

/* ---------------------------------------------------------
   CP-ID IZ ARGUMENTA
--------------------------------------------------------- */

if (!isset($argv[1])) {
    die("ERROR: ChargePoint ID nije proslijeden. Pokreni: php ChargePoint.php 1\n");
}

$CHARGE_POINT_ID = intval($argv[1]);

/* ---------------------------------------------------------
   FILE PATHS
--------------------------------------------------------- */

$STATUS_FILE = "/var/log/ocpp/charger_status_{$CHARGE_POINT_ID}.txt";
$LOG_FILE    = "/var/log/ocpp/chargepoint_{$CHARGE_POINT_ID}.log";

/* ---------------------------------------------------------
   GLOBAL STATE
--------------------------------------------------------- */

$LAST_KNOWN_STATUS = "Available";

/* ---------------------------------------------------------
   LOG
--------------------------------------------------------- */

function cpLog(string $msg): void {
    global $LOG_FILE, $CHARGE_POINT_ID;
    $line = "[" . date("Y-m-d H:i:s") . "][CP {$CHARGE_POINT_ID}] $msg\n";
    echo $line;
    file_put_contents($LOG_FILE, $line, FILE_APPEND);
}

cpLog("=== CP SIMULATOR START ===");

/* ---------------------------------------------------------
   STATUS FUNKCIJE
--------------------------------------------------------- */

function setChargerStatus(string $status): void {
    global $STATUS_FILE, $LAST_KNOWN_STATUS;
    $LAST_KNOWN_STATUS = $status;
    file_put_contents($STATUS_FILE, $status);
    cpLog("Local status set to: $status");
}

function getChargerStatus(): string {
    global $STATUS_FILE, $LAST_KNOWN_STATUS;

    if (!file_exists($STATUS_FILE)) {
        cpLog("STATUS_FILE ne postoji, koristim zadnji poznati status: $LAST_KNOWN_STATUS");
        return $LAST_KNOWN_STATUS;
    }

    $v = trim(@file_get_contents($STATUS_FILE));

    if ($v === "") {
        cpLog("STATUS_FILE prazan, koristim zadnji poznati status: $LAST_KNOWN_STATUS");
        return $LAST_KNOWN_STATUS;
    }

    if ($v !== $LAST_KNOWN_STATUS) {
        cpLog("STATUS_FILE promjena: $LAST_KNOWN_STATUS -> $v");
        $LAST_KNOWN_STATUS = $v;
    }

    return $v;
}

/* ---------------------------------------------------------
   OCPP 1.6 CALL FORMAT
--------------------------------------------------------- */

function sendOcppCall(Client $client, string $action, array $payload): void {
    $msgId = uniqid("cp_", true);
    $frame = [2, $msgId, $action, $payload];
    $json  = json_encode($frame);
    $client->text($json);
    cpLog("Sent OCPP [$action]: $json");
}

/* ---------------------------------------------------------
   OCPP PORUKE
--------------------------------------------------------- */

function sendStatusNotification(Client $client, string $status): void {
    $payload = [
        'connectorId' => 1,
        'errorCode'   => "NoError",
        'status'      => $status,
        'timestamp'   => date("c"),
    ];
    cpLog("Sending StatusNotification: status=$status");
    sendOcppCall($client, "StatusNotification", $payload);
}

function sendHeartbeat(Client $client): void {
    cpLog("Sending Heartbeat");
    sendOcppCall($client, "Heartbeat", []);
}

function sendBootNotification(Client $client): void {
    $payload = [
        'chargePointVendor' => 'MyVendor',
        'chargePointModel'  => 'MyModel',
    ];
    cpLog("Sending BootNotification");
    sendOcppCall($client, "BootNotification", $payload);
}

/* ---------------------------------------------------------
   OBRADA NAREDBI OD CSMS-a
--------------------------------------------------------- */

function handleIncomingCommand(Client $client, string $data): void {
    cpLog("Received raw from CSMS: $data");

    $json = json_decode($data, true);
    if (!$json) {
        cpLog("Invalid JSON: $data");
        return;
    }

    // CALLRESULT
    if (isset($json[0]) && $json[0] === 3) {
        cpLog("CALLRESULT received for msgId=" . ($json[1] ?? ''));
        return;
    }

    // CALL
    if (isset($json[0]) && $json[0] === 2) {
        $action  = $json[2] ?? null;
        $payload = $json[3] ?? [];
    } elseif (isset($json['action'])) {
        $action  = $json['action'];
        $payload = $json['payload'] ?? [];
    } else {
        cpLog("Unknown command format");
        return;
    }

    cpLog("Decoded command: action=$action");

    switch ($action) {
        case "RemoteStartTransaction":
            setChargerStatus("Charging");
            sendStatusNotification($client, "Charging");
            break;

        case "RemoteStopTransaction":
            setChargerStatus("Available");
            sendStatusNotification($client, "Available");
            break;

        case "PauseCharging":
            setChargerStatus("SuspendedEV");
            sendStatusNotification($client, "SuspendedEV");
            break;

        default:
            cpLog("Unknown command: $action");
    }
}

/* ---------------------------------------------------------
   GLAVNA PETLJA
--------------------------------------------------------- */

echo "# ChargePoint {$CHARGE_POINT_ID} starting...\n";

$uri = "ws://127.0.0.1:9001/{$CHARGE_POINT_ID}";

do {
    try {
        $client = new Client($uri);
        $client->addMiddleware(new \WebSocket\Middleware\CloseHandler());
        $client->addMiddleware(new \WebSocket\Middleware\PingResponder());

        cpLog("Connected to $uri");

        sendBootNotification($client);

        // Pošalji pocetni status samo ako nije Available
        $initialStatus = getChargerStatus();
        cpLog("Initial status: $initialStatus");
        if ($initialStatus !== "Available") {
            sendStatusNotification($client, $initialStatus);
        }

        $lastHeartbeat = time();
        $lastStatus    = $initialStatus;

        while (true) {

            // primi poruke (timeout 1s)
            $client->setTimeout(1);
            try {
                $message = $client->receive();
                if ($message !== null) {
                    $data = $message instanceof \WebSocket\Message\Text
                        ? $message->getContent()
                        : (string)$message;

                    handleIncomingCommand($client, $data);
                }
            } catch (\Throwable $e) {
                // timeout je normalan
            }

            // status promjena (na temelju file-a)
            $status = getChargerStatus();
            if ($status !== $lastStatus) {
                cpLog("Detected status change loop: $lastStatus -> $status");
                sendStatusNotification($client, $status);
                $lastStatus = $status;
            }

            // heartbeat svakih 10 sekundi
            if (time() - $lastHeartbeat >= 10) {
                sendHeartbeat($client);
                $lastHeartbeat = time();
            }
        }

    } catch (\Throwable $e) {
        cpLog("Fatal error: " . $e->getMessage());
        sleep(5);
    }

} while (true);
