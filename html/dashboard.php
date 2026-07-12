<?php
// Jednostavni dashboard u jednom fileu – bez API-ja

$dsn = "mysql:host=localhost;dbname=ocpp_simulator;charset=utf8mb4";
$user = "srajf";
$pass = "Passw0rd";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Greška spajanja na bazu: " . $e->getMessage());
}

function q($pdo, $sql) {
    return $pdo->query($sql)->fetchAll();
}

$chargepoints = q($pdo, "
    SELECT chargepoint_id, status_id, last_seen
    FROM chargepoints
    ORDER BY chargepoint_id ASC
");

$connectors = q($pdo, "
    SELECT chargepoint_id, connector_id, status_id, error_code, last_update
    FROM connectors
    ORDER BY chargepoint_id, connector_id
");

$transactions = q($pdo, "
    SELECT id, chargepoint_id, connector_id, start_time, stop_time, meter_start, meter_stop, status
    FROM transactions
    ORDER BY start_time DESC
");

$events = q($pdo, "
    SELECT id, message_type, action, chargepoint_id, created_at
    FROM events
    ORDER BY created_at DESC
    LIMIT 100
");
?>
<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="UTF-8">
    <title>OCPP Dashboard – Jednostavni</title>
    <style>
        body { font-family: Arial; background:#f3f4f6; padding:20px; }
        h1 { margin-bottom:5px; }
        h2 { margin-top:40px; }
        table { width:100%; border-collapse:collapse; background:white; margin-top:10px; }
        th, td { padding:8px; border:1px solid #ddd; font-size:14px; }
        th { background:#e5e7eb; }
        .time { color:#666; font-size:12px; }
    </style>
</head>
<body>

<h1>OCPP Simple Dashboard</h1>
<div class="time">Zadnji refresh: <?= date("H:i:s") ?></div>

<h2>Chargepoints</h2>
<table>
    <thead>
        <tr><th>ID</th><th>Status</th><th>Last Seen</th></tr>
    </thead>
    <tbody>
        <?php foreach ($chargepoints as $cp): ?>
        <tr>
            <td><?= $cp["chargepoint_id"] ?></td>
            <td><?= $cp["status_id"] ?></td>
            <td><?= $cp["last_seen"] ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h2>Connectors</h2>
<table>
    <thead>
        <tr><th>CP</th><th>Connector</th><th>Status</th><th>Error</th><th>Last Update</th></tr>
    </thead>
    <tbody>
        <?php foreach ($connectors as $c): ?>
        <tr>
            <td><?= $c["chargepoint_id"] ?></td>
            <td><?= $c["connector_id"] ?></td>
            <td><?= $c["status_id"] ?></td>
            <td><?= $c["error_code"] ?></td>
            <td><?= $c["last_update"] ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h2>Transactions</h2>
<table>
    <thead>
        <tr>
            <th>ID</th><th>CP</th><th>Conn</th>
            <th>Start</th><th>Stop</th>
            <th>Meter Start</th><th>Meter Stop</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($transactions as $t): ?>
        <tr>
            <td><?= $t["id"] ?></td>
            <td><?= $t["chargepoint_id"] ?></td>
            <td><?= $t["connector_id"] ?></td>
            <td><?= $t["start_time"] ?></td>
            <td><?= $t["stop_time"] ?></td>
            <td><?= $t["meter_start"] ?></td>
            <td><?= $t["meter_stop"] ?></td>
            <td><?= $t["status"] ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h2>Events (zadnjih 100)</h2>
<table>
    <thead>
        <tr><th>ID</th><th>Type</th><th>Action</th><th>CP</th><th>Time</th></tr>
    </thead>
    <tbody>
        <?php foreach ($events as $e): ?>
        <tr>
            <td><?= $e["id"] ?></td>
            <td><?= $e["message_type"] ?></td>
            <td><?= $e["action"] ?></td>
            <td><?= $e["chargepoint_id"] ?></td>
            <td><?= $e["created_at"] ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
