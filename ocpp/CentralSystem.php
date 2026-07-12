<?php

require __DIR__ . '/../../../vendor/autoload.php';

use WebSocket\Server;
use WebSocket\Message\Text;

/* ---------------------------------------------------------
   BAZA – POMOCNE FUNKCIJE
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

function findPunionicaIdByCpId(int $cpId): ?int {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT ID FROM Punionica WHERE ID = :id LIMIT 1");
    $stmt->execute([':id' => $cpId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['ID'] : null;
}

function findStatusIdByName(string $name): ?int {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT ID FROM Status WHERE Naziv = :n LIMIT 1");
    $stmt->execute([':n' => $name]);
    $row = $stmt->fetch();
    return $row ? (int)$row['ID'] : null;
}

function saveStatusToPortal(int $cpId, string $status): void {
    $idPunionice = findPunionicaIdByCpId($cpId);
    if (!$idPunionice) return;

    $idStatus = findStatusIdByName($status);
    if (!$idStatus) return;

    $pdo = getPdo();

    // Izbjegni duplikate
    $stmtCheck = $pdo->prepare("
        SELECT ID_Status 
        FROM Status_Punionice 
        WHERE ID_Punionice = :pid 
        ORDER BY VrijemePostavljanja DESC 
        LIMIT 1
    ");
    $stmtCheck->execute([':pid' => $idPunionice]);
    $last = $stmtCheck->fetch();

    if ($last && (int)$last['ID_Status'] === $idStatus) return;

    $stmt = $pdo->prepare("
        INSERT INTO Status_Punionice (ID_Punionice, ID_Status, VrijemePostavljanja)
        VALUES (:pid, :sid, NOW())
    ");
    $stmt->execute([':pid' => $idPunionice, ':sid' => $idStatus]);

    echo "[DB-UPDATE] CP $cpId ? $status\n";
}

function logEvent(int $cpId, string $action, array $payload) {
    try {
        $pdo = getPdo();
        $stmt = $pdo->prepare("
            INSERT INTO logovi (vrijeme, chargingStation, poruka)
            VALUES (NOW(), :cp, :msg)
        ");
        $stmt->execute([
            ':cp'  => $cpId,
            ':msg' => json_encode(['action' => $action, 'payload' => $payload])
        ]);
    } catch (Exception $e) {
        echo "[LOG ERROR] " . $e->getMessage() . "\n";
    }
}

/* ---------------------------------------------------------
   OBRADA PORUKA (OCPP 1.6)
--------------------------------------------------------- */

function handleIncomingMessage($connection, string $data) {
    $msg = json_decode($data, true);
    if (!$msg || !is_array($msg)) return;

    $typeId    = $msg[0] ?? 0;
    $messageId = $msg[1] ?? '';
    $action    = $msg[2] ?? '';
    $payload   = $msg[3] ?? [];
    $cpId      = $connection->cpId;

    echo "[RECV][$cpId] $action\n";
    logEvent($cpId, $action, $payload);

    switch ($action) {

        case 'BootNotification':
            $response = [3, $messageId, [
                "status" => "Accepted",
                "currentTime" => date("c"),
                "interval" => 30
            ]];
            $connection->send(new Text(json_encode($response)));
            break;

        case 'Heartbeat':
            $response = [3, $messageId, ["currentTime" => date("c")]];
            $connection->send(new Text(json_encode($response)));
            checkForPendingCommands($connection);
            break;

        case 'StatusNotification':

            // ? NE upisuj Available ako status ne postoji
            if (!isset($payload['status'])) {
                echo "[WARN][$cpId] StatusNotification bez status polja — ignoriram\n";
                $connection->send(new Text(json_encode([3, $messageId, (object)[]])));
                break;
            }

            $status = $payload['status'];
            saveStatusToPortal($cpId, $status);

            $connection->send(new Text(json_encode([3, $messageId, (object)[]])));
            break;

        default:
            $connection->send(new Text(json_encode([3, $messageId, ["status" => "Accepted"]])));
            break;
    }
}

/* ---------------------------------------------------------
   SLANJE NAREDBI IZ PORTALA
--------------------------------------------------------- */

function checkForPendingCommands($connection) {
    $cpId = $connection->cpId;
    $idPunionice = findPunionicaIdByCpId($cpId);
    if (!$idPunionice) return;

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

    if ($cmd) {
        $out = [2, uniqid('srv_', true), $cmd['Naredba'], ["connectorId" => 1]];
        echo "[CMD][$cpId] Sending {$cmd['Naredba']}\n";
        $connection->send(new Text(json_encode($out)));

        $pdo->prepare("UPDATE Naredbe SET Id_status = 2 WHERE ID = :id")
            ->execute([':id' => $cmd['ID']]);
    }
}

/* ---------------------------------------------------------
   SERVER START
--------------------------------------------------------- */

echo "# Central System starting on port 9001...\n";

try {
    $server = new Server(9001);

    $server
        ->addMiddleware(new \WebSocket\Middleware\CloseHandler())
        ->addMiddleware(new \WebSocket\Middleware\PingResponder());

    $server->onHandshake(function ($server, $connection, $request, $response) {

        $path = $request->getUri()->getPath();   // npr. /1
        $cpId = intval(trim($path, '/'));        // ? 1

        if ($cpId <= 0) $cpId = 0;

        $connection->cpId = $cpId;

        echo "[CONNECT] CP $cpId connected\n";
    })

    ->onText(function ($server, $connection, $message) {
        handleIncomingMessage($connection, $message->getContent());
    })

    ->onDisconnect(function ($server, $connection) {
        echo "[DISCONNECT] CP {$connection->cpId} disconnected\n";
    })

    ->start();

} catch (\Throwable $e) {
    echo "# FATAL ERROR: {$e->getMessage()}\n";
}
