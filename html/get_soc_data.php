<?php
require_once 'config.php';

$sim = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_simulator");
$sim->set_charset("utf8mb4");

$id = intval($_GET['id'] ?? 0);

// Zadnja transakcija punionice
$stmtTx = $sim->prepare("
    SELECT id
    FROM transactions
    WHERE chargepoint_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$stmtTx->bind_param("i", $id);
$stmtTx->execute();
$tx = $stmtTx->get_result()->fetch_assoc();

if (!$tx) {
    echo json_encode(["labels" => [], "values" => [], "tx" => null]);
    exit;
}

$txId = $tx['id'];

// Dohvati SoC krivulju
$stmt = $sim->prepare("
    SELECT timestamp, soc 
    FROM charging_curve
    WHERE transaction_id = ?
    ORDER BY id ASC
");
$stmt->bind_param("i", $txId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$labels = [];
$values = [];

foreach ($rows as $r) {
    $labels[] = $r['timestamp'];
    $values[] = intval($r['soc']);
}

echo json_encode([
    "labels" => $labels,
    "values" => $values,
    "tx" => $txId
]);
