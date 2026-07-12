<?php
require_once 'config.php';
require_once 'helper_functions.php';
requireLogin();

$mysqli = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_system");
$sim    = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_simulator");

$mysqli->set_charset("utf8mb4");
$sim->set_charset("utf8mb4");

$uid = $_SESSION['user_id'];

/* -----------------------------
   ADMIN PRAVA
------------------------------ */
$rights = getUserRights($uid);
$hasAdminRights = false;

foreach ($rights as $r) {
    if (is_array($r) && isset($r['OpisPrava'])) {
        $r = $r['OpisPrava'];
    }
    if (!is_string($r)) continue;
    if (trim($r) !== 'Korisnik') {
        $hasAdminRights = true;
        break;
    }
}

/* -----------------------------
   DOHVAT SVIH PUNIONICA
------------------------------ */
$stmt = $mysqli->prepare("
    SELECT p.ID, p.SerijskiBroj, u.Naziv AS Ustanova
    FROM Punionica p
    LEFT JOIN Ustanova u ON u.ID = p.ID_Ustanove
    ORDER BY p.ID ASC
");
$stmt->execute();
$chargers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* -----------------------------
   STATUS PUNIONICE
------------------------------ */
function getCurrentStatus($mysqli, $id) {
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
    $r = $stmt->get_result()->fetch_assoc();
    return $r ? $r['Naziv'] : null;
}

/* -----------------------------
   AKTIVNI KORISNIK
------------------------------ */
function getActiveUser($sim, $id) {
    $stmt = $sim->prepare("
        SELECT user_id
        FROM transactions
        WHERE chargepoint_id = ? AND stop_time IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    return $r ? intval($r['user_id']) : null;
}
?>
<!doctype html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <title>Odabir punionice</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<div class="scp">

    <div class="nav-buttons">
        <a href="#" class="logout-btn" onclick="openMyLastSession(); return false;">Moja punjenja</a>

        <?php if ($hasAdminRights): ?>
            <a href="admin.php" class="logout-btn">Administracija</a>
        <?php endif; ?>

        <a href="logout.php" class="logout-btn">Odjava</a>
    </div>

    <h2>Odaberite punionicu</h2>

    <table>
        <thead>
            <tr>
                <th>Punionica</th>
                <th>Ustanova</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($chargers as $c):

            $status = getCurrentStatus($mysqli, $c['ID']) ?? 'Unavailable';
            $activeUser = getActiveUser($sim, $c['ID']);

            /* -----------------------------
               NOVO: mapiranje statusa
            ------------------------------ */
            $displayStatus = ($status === 'Available') ? 'Dostupno' : 'Nedostupno';

            $canEnter =
                isAdminSystem($uid) ||
                hasRight($uid, 'Administrator ustanove') ||
                $status === 'Available' ||
                ($status === 'Charging' && $activeUser === $uid);

            $link = $canEnter ? "home.php?id=".$c['ID'] : "#";
        ?>
            <tr onclick="window.location='<?= $link ?>'">
                <td><?= esc($c['SerijskiBroj']) ?></td>
                <td><?= esc($c['Ustanova']) ?></td>

                <td>
                    <?php if ($displayStatus === 'Dostupno'): ?>
                        <span class="scp-status-free">Slobodno</span>
                    <?php else: ?>
                        <span class="scp-status-busy">Zauzeto</span>
                    <?php endif; ?>
                </td>

                <td></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

</div>

<!-- ================= MODAL ================= -->
<div id="mySessionModal" class="modal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeMyLastSession()">&times;</span>

        <h3>Moje zadnje punjenje</h3>

        <div id="mySessionInfo" class="session-info"></div>

        <div class="popup-soc-card">
            <div class="popup-soc-card-title">SoC tijekom punjenja</div>
            <canvas id="mySessionChart"></canvas>
        </div>

        <h4>Statusi tijekom punjenja</h4>
        <div id="mySessionStatuses">Ucitavanje...</div>
    </div>
</div>

<script>
let mySessionChart = null;

function openMyLastSession() {
    document.getElementById('mySessionModal').style.display = 'flex';

    fetch('get_my_last_session.php')
        .then(r => r.json())
        .then(data => {
            if (!data || !data.ok) return;

            document.getElementById('mySessionInfo').innerHTML = `
                <div><strong>Punionica:</strong> ${data.chargepoint}</div>
                <div><strong>Pocetak:</strong> ${data.start_time}</div>
                <div><strong>Kraj:</strong> ${data.stop_time ?? 'u tijeku'}</div>
            `;

            if (data.statuses?.length) {
                let html = '<table class="details-table"><tr><th>Vrijeme</th><th>Status</th></tr>';
                data.statuses.forEach(s =>
                    html += `<tr><td>${s.time}</td><td>${s.status}</td></tr>`
                );
                html += '</table>';
                document.getElementById('mySessionStatuses').innerHTML = html;
            }

            const ctx = document.getElementById('mySessionChart').getContext('2d');
            if (mySessionChart) mySessionChart.destroy();

            mySessionChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.graph.labels,
                    datasets: [{
                        data: data.graph.values,
                        borderColor: '#00cc88',
                        backgroundColor: 'rgba(0,200,120,0.2)',
                        tension: 0.3,
                        pointRadius: 0,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,

                    scales: {
                        x: {
                            ticks: {
                                maxRotation: 0,
                                minRotation: 0,
                                autoSkip: false,
                                callback: function(value, index, ticks) {
                                    if (index === 0 || index === ticks.length - 1) {
                                        let label = this.getLabelForValue(value);
                                        if (label.includes(' ')) {
                                            label = label.split(' ')[1];
                                        }
                                        return label.substring(0, 8);
                                    }
                                    return '';
                                }
                            },
                            grid: { display: false }
                        },
                        y: {
                            min: 0,
                            max: 100,
                            ticks: { callback: v => v + '%' }
                        }
                    },

                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                title: function(ctx) {
                                    let label = ctx[0].label;
                                    if (label.includes(' ')) {
                                        label = label.split(' ')[1];
                                    }
                                    return 'Vrijeme: ' + label.substring(0, 8);
                                },
                                label: function(ctx) {
                                    return 'SoC: ' + ctx.raw + ' %';
                                }
                            }
                        }
                    }
                }
            });
        });
}

function closeMyLastSession() {
    document.getElementById('mySessionModal').style.display = 'none';
    if (mySessionChart) {
        mySessionChart.destroy();
        mySessionChart = null;
    }
}
</script>

</body>
</html>
