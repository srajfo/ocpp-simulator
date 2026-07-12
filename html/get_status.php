<?php
require_once 'config.php';

$mysqli = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_system");
$mysqli->set_charset("utf8mb4");

$id = intval($_GET['id']);

$stmt = $mysqli->prepare("
    SELECT s.Naziv
    FROM Status_Punionice sp
    JOIN Status s ON s.ID = sp.ID_Status
    WHERE sp.ID_Punionice = ?
    ORDER BY sp.VrijemePostavljanja DESC
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

echo $row ? $row['Naziv'] : "Unavailable";
