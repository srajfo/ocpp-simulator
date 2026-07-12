<?php
require_once 'config.php';
require_once 'helper_functions.php';

requireLogin();
$uid = intval($_SESSION['user_id'] ?? 0);

$mysqli->set_charset("utf8mb4");

$isSuperAdmin = isAdminSystem($uid);
$isAdminUstanove = hasRight($uid, 'Administrator ustanove');

$allowedIds = [];

if ($isSuperAdmin) {
    $q = $mysqli->query("SELECT ID FROM Punionica");
    while ($r = $q->fetch_assoc()) {
        $allowedIds[] = $r['ID'];
    }
}
elseif ($isAdminUstanove) {
    $stmt = $mysqli->prepare("
        SELECT p.ID
        FROM Punionica p
        JOIN Korisnik k ON k.ID_Ustanove = p.ID_Ustanove
        WHERE k.ID = ?
    ");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $allowedIds[] = $r['ID'];
    }
}
else {
    $stmt = $mysqli->prepare("
        SELECT ID_Punionice
        FROM Pristup_Punionici
        WHERE ID_Korisnika = ? AND ImaPravo = 1
    ");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $allowedIds[] = $r['ID_Punionice'];
    }
}

if (empty($allowedIds)) {
    echo json_encode([]);
    exit;
}

$in = implode(',', array_map('intval', $allowedIds));

$stmt = $mysqli->query("
    SELECT sp.ID_Punionice, s.Naziv
    FROM Status_Punionice sp
    JOIN Status s ON s.ID = sp.ID_Status
    WHERE sp.ID_Punionice IN ($in)
    AND sp.VrijemePostavljanja = (
        SELECT MAX(sp2.VrijemePostavljanja)
        FROM Status_Punionice sp2
        WHERE sp2.ID_Punionice = sp.ID_Punionice
    )
");

$statuses = [];
while ($row = $stmt->fetch_assoc()) {
    $statuses[$row['ID_Punionice']] = $row['Naziv'];
}

echo json_encode($statuses);
