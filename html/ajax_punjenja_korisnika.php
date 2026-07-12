<?php
require_once 'config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$sim = new mysqli('127.0.0.1', 'srajf', 'Passw0rd', 'ocpp_simulator');
$sim->set_charset('utf8mb4');

$userId = intval($_GET['user'] ?? 0);
if ($userId <= 0) {
    exit("<div class='error-text'>Nevažeci korisnik.</div>");
}

$stmt = $sim->prepare("
    SELECT 
        t.start_time,
        t.stop_time,
        p.SerijskiBroj
    FROM transactions t
    LEFT JOIN ocpp_system.Punionica p ON p.ID = t.chargepoint_id
    WHERE t.user_id = ?
      AND MONTH(t.start_time) = MONTH(CURRENT_DATE())
      AND YEAR(t.start_time) = YEAR(CURRENT_DATE())
    ORDER BY t.start_time DESC
");

$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$punjenja = $result->fetch_all(MYSQLI_ASSOC);

if (empty($punjenja)) {
    echo "<div class='loading-text'>Nema punjenja za ovog korisnika u tekucem mjesecu.</div>";
    exit;
}
?>

<table class="ev-table" style="min-width: 100%; margin-top: 0;">
    <thead>
        <tr>
            <th>Punionica</th>
            <th>Pocetak</th>
            <th>Kraj</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($punjenja as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['SerijskiBroj'] ?? 'Nepoznato') ?></td>
                <td><?= date('d.m.Y. H:i', strtotime($p['start_time'])) ?></td>
                <td>
                    <?= $p['stop_time']
                        ? date('d.m.Y. H:i', strtotime($p['stop_time']))
                        : "<span class='badge'>U tijeku</span>" ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
