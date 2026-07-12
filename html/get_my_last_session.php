<?php
require_once 'config.php';
require_once 'helper_functions.php';
requireLogin();

$mysqli = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_system");
$sim    = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_simulator");

$mysqli->set_charset("utf8mb4");
$sim->set_charset("utf8mb4");

$uid = intval($_SESSION['user_id'] ?? 0);

header('Content-Type: application/json');

if ($uid === 0) {
    echo json_encode([
        'ok' => false,
        'message' => 'Niste prijavljeni.'
    ]);
    exit;
}

// 1) Zadnja transakcija za ovog korisnika
$stmtTx = $sim->prepare("
    SELECT t.id, t.chargepoint_id, t.start_time, t.stop_time, p.SerijskiBroj
    FROM transactions t
    LEFT JOIN ocpp_system.Punionica p ON p.ID = t.chargepoint_id
    WHERE t.user_id = ?
    ORDER BY t.id DESC
    LIMIT 1
");
$stmtTx->bind_param("i", $uid);
$stmtTx->execute();
$tx = $stmtTx->get_result()->fetch_assoc();

if (!$tx) {
    echo json_encode([
        'ok' => false,
        'message' => 'Nemate nijedno punjenje.'
    ]);
    exit;
}

$txId   = intval($tx['id']);
$cpId   = intval($tx['chargepoint_id']);
$start  = $tx['start_time'];
$stop   = $tx['stop_time'] ?? date('Y-m-d H:i:s');
$cpName = $tx['SerijskiBroj'] ?? ('ID ' . $cpId);

// 2) Statusi tijekom tog punjenja
$stmtStatus = $mysqli->prepare("
    SELECT sp.VrijemePostavljanja, s.Naziv
    FROM Status_Punionice sp
    JOIN Status s ON s.ID = sp.ID_Status
    WHERE sp.ID_Punionice = ?
      AND sp.VrijemePostavljanja BETWEEN ? AND ?
    ORDER BY sp.VrijemePostavljanja ASC
");
$stmtStatus->bind_param("iss", $cpId, $start, $stop);
$stmtStatus->execute();
$statusRows = $stmtStatus->get_result()->fetch_all(MYSQLI_ASSOC);

// 3) SoC krivulja
$stmtGraph = $sim->prepare("
    SELECT timestamp, soc
    FROM charging_curve
    WHERE transaction_id = ?
    ORDER BY timestamp ASC
");
$stmtGraph->bind_param("i", $txId);
$stmtGraph->execute();
$curveRows = $stmtGraph->get_result()->fetch_all(MYSQLI_ASSOC);

$labels = [];
$values = [];

foreach ($curveRows as $r) {
    $labels[] = $r['timestamp'];
    $values[] = intval($r['soc']);
}

echo json_encode([
    'ok' => true,
    'chargepoint' => $cpName,
    'start_time' => $start,
    'stop_time' => $tx['stop_time'],
    'statuses' => array_map(fn($s) => [
        'time' => $s['VrijemePostavljanja'],
        'status' => $s['Naziv']
    ], $statusRows),
    'graph' => [
        'labels' => $labels,
        'values' => $values
    ]
]);
