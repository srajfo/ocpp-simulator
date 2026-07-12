<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'helper_functions.php';
requireLogin();

// PRAVA BAZA ZA SISTEM
$mysqli = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_system");
$mysqli->set_charset("utf8mb4");

// PRAVA BAZA ZA SIMULATOR
$sim = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_simulator");
$sim->set_charset("utf8mb4");

// -----------------------------
// FILTRIRANJE PUNIONICE PREKO ?id=
// -----------------------------
$id = intval($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = $mysqli->prepare("
        SELECT p.ID, p.SerijskiBroj, p.MaxJacinaPunjenja, p.Aktivno, u.Naziv
        FROM Punionica p
        JOIN Ustanova u ON u.ID = p.ID_Ustanove
        WHERE p.ID = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) die("Punionica ne postoji.");

    $punionice = [$row];

} else {
    $stmt = $mysqli->prepare("
        SELECT p.ID, p.SerijskiBroj, p.MaxJacinaPunjenja, p.Aktivno, u.Naziv
        FROM Punionica p
        JOIN Ustanova u ON u.ID = p.ID_Ustanove
        ORDER BY p.ID ASC
    ");
    $stmt->execute();
    $punionice = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// -----------------------------
// FUNKCIJE
// -----------------------------
function getCurrentStatus(mysqli $mysqli, string $idPunionice): string {
    $stmt = $mysqli->prepare("
        SELECT s.Naziv
        FROM Status_Punionice sp
        JOIN Status s ON s.ID = sp.ID_Status
        WHERE sp.ID_Punionice = ?
        ORDER BY sp.VrijemePostavljanja DESC
        LIMIT 1
    "); 
    $stmt->bind_param('s', $idPunionice);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ? $result['Naziv'] : 'Unavailable';
}

function mapStatusToUiState(string $status): string {
    return match($status) {
        'Charging' => 'active',
        'SuspendedEV', 'SuspendedEVSE', 'Preparing' => 'paused',
        default => 'stopped',
    };
}

function getStatusBadgeHtml(string $status): string {
    return match($status) {
        'Charging' => '<span class="badge">Punjenje</span>',
        'Preparing' => '<span class="badge">Priprema</span>',
        'Finishing' => '<span class="badge">Zavrsavanje</span>',
        'SuspendedEV', 'SuspendedEVSE' => '<span class="badge">Pauzirano</span>',
        'Faulted' => '<span class="badge">Kvar</span>',
        'Unavailable' => '<span class="badge">Nedostupno</span>',
        default => '<span class="badge">Dostupno</span>',
    };
}

function getActiveTransaction(mysqli $sim, int $idPunionice): ?array {
    $stmt = $sim->prepare("
        SELECT id, start_time, status
        FROM transactions
        WHERE chargepoint_id = ?
        ORDER BY start_time DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $idPunionice);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

?>
<!doctype html>
<html lang="hr" class="home-page">
<head>
    <meta charset="utf-8">
    <title>OCPP Portal</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="style.css">

<style>
button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>

</head>

<body class="home-page">
<div class="container">

<div class="header">
    <div class="brand">
        <div class="logo">O</div>
        <h1>OCPP Portal</h1>
    </div>
    <div class="nav">
        <span class="small">Dobrodosli, <?= esc($_SESSION['username']) ?></span>
        <a class="nav-btn" href="select_charger.php">Natrag</a>
        <a class="nav-btn" href="logout.php">Odjava</a>
    </div>
</div>

<div class="title-card">Punionica</div>

<?php if (!$punionice): ?>
    <div class="no-data">Nema dostupnih punionica.</div>
<?php else: ?>

<?php foreach ($punionice as $p):

    $transaction = getActiveTransaction($sim, $p['ID']);
    $status = getCurrentStatus($mysqli, $p['ID']);
    $hwOk = intval($p['Aktivno']) === 1;

    // Plug stanje
    $stmtPlug = $sim->prepare("
        SELECT plugged_in 
        FROM connector_state 
        WHERE chargepoint_id = ? AND connector_id = 1
        ORDER BY last_update DESC, id DESC
        LIMIT 1
    ");
    $stmtPlug->bind_param("i", $p['ID']);
    $stmtPlug->execute();
    $plugRow = $stmtPlug->get_result()->fetch_assoc();
    $pluggedIn = $plugRow ? intval($plugRow['plugged_in']) : 0;

    // SOC
    $soc = null;
    if ($transaction && isset($transaction['id'])) {
        $transactionId = intval($transaction['id']);

        $stmtSoc = $sim->prepare("
            SELECT soc 
            FROM charging_curve
            WHERE transaction_id = ?
            ORDER BY timestamp DESC, id DESC
            LIMIT 1
        ");
        $stmtSoc->bind_param("i", $transactionId);
        $stmtSoc->execute();
        $socRow = $stmtSoc->get_result()->fetch_assoc();

        if ($socRow) $soc = intval($socRow['soc']);
    }

    // ENABLE/DISABLE GUMBA
    $btnStartDisabled = "disabled";
    $btnPauseDisabled = "disabled";
    $btnStopDisabled  = "disabled";

    switch ($status) {
        case "Preparing":
            $btnStartDisabled = "";
            break;
        case "Charging":
            $btnPauseDisabled = "";
            $btnStopDisabled  = "";
            break;
        case "SuspendedEV":
        case "SuspendedEVSE":
            $btnStartDisabled = "";
            $btnStopDisabled  = "";
            break;
    }

?>

<div class="charger" data-id="<?= esc($p['ID']) ?>">

    <div class="charger-left">
        <div class="charger-box">
            <img src="charger_placeholder.png" alt="Punionica">
        </div>
        <div class="charger-name"><?= esc($p['SerijskiBroj']) ?></div>
    </div>

    <div class="charger-right">

        <div class="top-section">
            <div class="status status-badge" id="statusBadge_<?= $p['ID'] ?>">
                <?= getStatusBadgeHtml($status) ?>
            </div>

            <div class="meta">
                Ustanova: <?= esc($p['Naziv']) ?><br>
                Max snaga: <?= esc($p['MaxJacinaPunjenja']) ?> kW<br>
                HW stanje:
                <?= $hwOk ? '<span class="badge hw">Aktivno</span>' : '<span class="badge hw">Neaktivno</span>' ?>

                <?php if ($transaction): ?>
                    <br>Pocetak punjenja: <?= esc($transaction['start_time']) ?>
                <?php endif; ?>
            </div>
        </div>

        <form method="post" action="punionica_action.php" class="buttons-row">
            <input type="hidden" name="id" value="<?= esc($p['ID']) ?>">

            <button type="submit" class="btn" name="action" value="start" <?= $btnStartDisabled ?>>Pokreni</button>
            <button type="submit" class="btn" name="action" value="pause" <?= $btnPauseDisabled ?>>Pauziraj</button>
            <button type="submit" class="btn" name="action" value="stop"  <?= $btnStopDisabled ?>>Zaustavi</button>
        </form>

        <div class="soc-box">
            Baterija: <span id="socValue_<?= $p['ID'] ?>"><?= $soc ?></span>%
        </div>

        <div class="soc-card">
            <canvas id="socChart_<?= $p['ID'] ?>"></canvas>
        </div>

    </div>
</div>

<?php endforeach; ?>
<?php endif; ?>

<div class="footer">© 2026 OCPP Portal</div>

</div>

<script>
let lastStatus = "<?= $status ?>";

function checkStatus() {
    fetch("get_status.php?id=<?= $p['ID'] ?>&t=" + Date.now())
        .then(r => r.text())
        .then(newStatus => {
            newStatus = newStatus.trim();
            if (newStatus !== "" && newStatus !== lastStatus) {
                location.reload();
            }
        });
}

setInterval(checkStatus, 1000);
</script>

<script>
const charts = {};
const lastTx = {};

function loadSocGraph(id) {
    fetch("get_soc_data.php?id=" + id)
        .then(r => r.json())
        .then(data => {
            const canvas = document.getElementById("socChart_" + id);
            if (!canvas || !data.tx) return;

            const socSpan = document.getElementById("socValue_" + id);
            if (socSpan && data.values.length > 0) {
                socSpan.textContent = data.values[data.values.length - 1];
            }

            if (lastTx[id] !== data.tx) {
                if (charts[id]) charts[id].destroy();
                lastTx[id] = data.tx;
            }

            const ctx = canvas.getContext("2d");

            if (!charts[id]) {
                charts[id] = new Chart(ctx, {
                    type: "line",
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: "SoC (%)",
                            data: data.values,
                            borderColor: "#00ff88",
                            backgroundColor: "rgba(0,255,136,0.15)",
                            tension: 0.3,
                            pointRadius: 0,
                            borderWidth: 2,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: "index",
                            intersect: false
                        },
                        scales: {
                            y: { min: 0, max: 100 },
                            x: { ticks: { maxTicksLimit: 3 } }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                enabled: true,
                                mode: "index",
                                intersect: false,
                                callbacks: {
                                    label: function(ctx) {
                                        return "SoC: " + ctx.raw + "%";
                                    },
                                    title: function(ctx) {
                                        return "Vrijeme: " + ctx[0].label;
                                    }
                                }
                            }
                        }
                    }
                });

            } else {
                charts[id].data.labels = data.labels;
                charts[id].data.datasets[0].data = data.values;
                charts[id].update();
            }
        });
}

document.querySelectorAll(".charger").forEach(box => {
    loadSocGraph(box.dataset.id);
});

setInterval(() => {
    document.querySelectorAll(".charger").forEach(box => {
        loadSocGraph(box.dataset.id);
    });
}, 3000);
</script>

</body>
</html>
