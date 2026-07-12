<?php

require __DIR__ . '/../../../vendor/autoload.php';

use WebSocket\Server;

/* ---------------------------------------------------------
   BAZA – POMOĆNE FUNKCIJE
--------------------------------------------------------- */

function getPdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host=localhost;dbname=ocpp_system;charset=utf8mb4",
            "srajf",
            "Passw0rd",
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    }
    return $pdo;
}

/**
 * Vrati ID punionice na temelju chargePointId (SerijskiBroj u tablici Punionica).
 */
function findPunionicaIdByChargePointId(string $cpId): ?int {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT ID FROM Punionica WHERE SerijskiBroj = :sb LIMIT 1");
    $stmt->execute([':sb' => $cpId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['ID'] : null;
}

/**
 * Vrati ID statusa iz tablice Status prema nazivu (Charging, Available…).
 */
function findStatusIdByName(string $name): ?int {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT ID FROM Status WHERE Naziv = :n LIMIT 1");
    $stmt->execute([':n' => $name]);
    $row = $stmt->fetch();
    return $row ? (int)$row['ID'] : null;
}

/**
 * Dohvati zadnji status punionice.
 */
function getLastStatusIdForPunionica(int $idPunionice): ?int {
    $pdo = getPdo();
    $stmt = $pdo->prepare("
        SELECT ID_Status
        FROM Status_Punionice
        WHERE ID_Punionice = :pid
        ORDER BY VrijemePostavljanja DESC
        LIMIT 1
    ");
    $stmt->execute([':pid' => $idPunionice]);
    $row = $stmt->fetch();
    return $row ? (int)$row['ID_Status'] : null;
}

/**
 * Spremi status (iz Heartbeat ili StatusNotification) u Status_Punionice
 * samo ako se promijenio.
 */
function saveStatusToPortal(array $payload): void {
    $status = $payload['status']        ?? null;
    $cpId   = $payload['chargePointId'] ?? null;

    if (!$status || !$cpId) {
        return;
    }

    $idPunionice = findPunionicaIdByChargePointId($cpId);
    if (!$idPunionice) {
        return;
    }

    $idStatus = findStatusIdByName($status);
    if (!$idStatus) {
        return;
    }

    $lastStatusId = getLastStatusIdForPunionica($idPunionice);
    if ($lastStatusId !== null && $lastStatusId === $idStatus) {
        return; // nema promjene
    }

    $pdo = getPdo();
    $stmt = $pdo->prepare("
        INSERT INTO Status_Punionice (ID_Punionice, ID_Status, VrijemePostavljanja)
        VALUES (:pid, :sid, NOW())
    ");
    $stmt->execute([
        ':pid' => $idPunionice,
        ':sid' => $idStatus,
    ]);

    echo "[DEBUG] Status: $status, CPID: $cpId\n";
}

/**
 * Obrada pending naredbi iz tablice Naredbe.
 * Sada radi preko status_id (FK na StatusiNaredbe).
 */
function processPendingCommands(array $payload, $connection): void {
    $cpId = $payload['chargePointId'] ?? null;
    if (!$cpId) {
        return;
    }

    $idPunionice = findPunionicaIdByChargePointId($cpId);
    if (!$idPunionice) {
        return;
    }

    $pdo = getPdo();
    $stmt = $pdo->prepare("
        SELECT ID, Naredba, Parametar
        FROM Naredbe
        WHERE ID_Punionice = :pid AND Id_status = 1
        ORDER BY Vrijeme ASC
        LIMIT 1
    ");
    $stmt->execute([':pid' => $idPunionice]);
    $cmd = $stmt->fetch();

    if (!$cmd) {
        return; // nema naredbi
    }

    $messageId = uniqid('srv_', true);

    $out = [
        'messageId' => $messageId,
        'action'    => $cmd['Naredba'],
        'payload'   => [
            'chargePointId' => $cpId,
        ],
    ];

    echo "[DEBUG] Pending command: " . json_encode($cmd) . "\n";
    echo "[" . date("Y-m-d H:i:s") . "] Central System: Sending command to CP: " . json_encode($out) . "\n";

    $connection->send(new \WebSocket\Message\Text(json_encode($out)));

    // status_id = 2 → sent
    $stmt = $pdo->prepare("UPDATE Naredbe SET Id_status = 2 WHERE ID = :id");
    $stmt->execute([':id' => $cmd['ID']]);
}

/**
 * Log u tablicu logovi.
 */
function logEvent($eventType, $payload, $status) {
    try {
        $pdo = getPdo();
        $poruka = json_encode([
            'event'   => $eventType,
            'status'  => $status,
            'payload' => $payload
        ]);
        $sql = "INSERT INTO logovi (vrijeme, chargingStation, poruka)
                VALUES (NOW(), :chargingStation, :poruka)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':chargingStation' => $eventType,
            ':poruka'          => $poruka
        ]);
    } catch (Exception $e) {
        echo "[" . date("Y-m-d H:i:s") . "] DB error: " . $e->getMessage() . "\n";
    }
}

/* ---------------------------------------------------------
   CENTRAL CALLBACK – OBRADA PORUKA
--------------------------------------------------------- */

function centralCallBack($data, $connection) {
    echo "[" . date("Y-m-d H:i:s") . "] Central System: Received message\n";
    echo $data . "\n";

    $message = json_decode(trim($data), true);
    if ($message === null) {
        $connection->send(new \WebSocket\Message\Text('{"error": "Invalid JSON"}'));
        return;
    }

    $action    = $message['action']    ?? null;
    $payload   = $message['payload']   ?? [];
    $messageId = $message['messageId'] ?? null;

    if (!$action || !$messageId) {
        $connection->send(new \WebSocket\Message\Text('{"error": "Invalid message format"}'));
        return;
    }

    switch ($action) {
        case 'BootNotification':
            logEvent('BootNotification', $payload, 'OK');
            break;

        case 'Heartbeat':
            logEvent('Heartbeat', $payload, 'OK');
            saveStatusToPortal($payload);
            processPendingCommands($payload, $connection);
            break;

        case 'StatusNotification':
            logEvent('StatusNotification', $payload, 'OK');
            saveStatusToPortal($payload);
            break;

        default:
            logEvent('UnknownAction', $payload, 'IGNORED');
            break;
    }
}

/* ---------------------------------------------------------
   POKRETANJE SERVERA
--------------------------------------------------------- */

echo "# Echo server! [Central System (Server)]\n";

$options = array_merge([
    'port'  => 9001,
], getopt('', ['port:', 'ssl', 'timeout:', 'framesize:', 'connections:', 'debug']));

try {
    $server = new Server($options['port'], isset($options['ssl']));
    $server
        ->addMiddleware(new \WebSocket\Middleware\CloseHandler())
        ->addMiddleware(new \WebSocket\Middleware\PingResponder());

    if (isset($options['debug']) && class_exists('WebSocket\Test\EchoLog')) {
        $server->setLogger(new \WebSocket\Test\EchoLog());
        echo "# Using logger\n";
    }
    if (isset($options['timeout'])) {
        $server->setTimeout($options['timeout']);
        echo "# Set timeout: {$options['timeout']}\n";
    }
    if (isset($options['framesize'])) {
        $server->setFrameSize($options['framesize']);
        echo "# Set frame size: {$options['framesize']}\n";
    }
    if (isset($options['connections'])) {
        $server->setMaxConnections($options['connections']);
        echo "# Set max connections: {$options['connections']}\n";
    }

    echo "# Listening on port {$server->getPort()}\n";

    $server->onHandshake(function ($server, $connection, $request, $response) {
        echo "> [{$connection->getRemoteName()}] Client connected {$request->getUri()}\n";
    })->onDisconnect(function ($server, $connection) {
        echo "> [{$connection->getRemoteName()}] Client disconnected\n";
    })->onText(function ($server, $connection, $message) {
        echo "> [{$connection->getRemoteName()}] Received {$message->getContent()}\n";
        centralCallBack($message->getContent(), $connection);
    })->onError(function ($server, $connection, $exception) {
        echo "> Error: {$exception->getMessage()}\n";
    })->start();

} catch (\Throwable $e) {
    echo "# ERROR: {$e->getMessage()}\n";
}
