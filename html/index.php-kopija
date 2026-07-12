<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'helper_functions.php';
requireLogin();

// Anti-cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// ---------------------------------------------
// DATABASE CONNECTIONS
// ---------------------------------------------
try {
    $pdoSystem = new PDO("mysql:host=localhost;dbname=ocpp_system;charset=utf8mb4", "srajf", "Passw0rd", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdoSim = new PDO("mysql:host=localhost;dbname=ocpp_simulator;charset=utf8mb4", "srajf", "Passw0rd", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    die("Greška u bazi: " . $e->getMessage());
}

// ---------------------------------------------
// DOHVAT CP IZ SYSTEM BAZE — SADA PO ID-u
// ---------------------------------------------
$stmt = $pdoSystem->query("SELECT ID FROM Punionica ORDER BY ID ASC");
$allCPs = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!$allCPs) die("Nema punionica u bazi!");

// Odabrani CP (default na prvu iz baze)
$cpId = isset($_GET['cp']) ? intval($_GET['cp']) : intval($allCPs[0]);

// ---------------------------------------------
// FILE PATHS (PER CP)
// ---------------------------------------------
$stateFile  = "/var/log/ocpp/charger_state_{$cpId}.txt";
$statusFile = "/var/log/ocpp/charger_status_{$cpId}.txt";
$logFile    = "/var/log/ocpp/chargepoint_{$cpId}.log";

// ---------------------------------------------
// PROCESS MANAGEMENT
// ---------------------------------------------
function getPIDsForCP($cpId) {
    $pattern = "ChargePoint.php " . $cpId;
    $cmd = "ps aux | grep " . escapeshellarg($pattern) . " | grep -v grep | awk '{print $2}'";
    $output = shell_exec($cmd);
    if (!$output) return [];
    return explode("\n", trim($output));
}

function countProcessesForCP($cpId) {
    return count(getPIDsForCP($cpId));
}

function killProcessesForCP($cpId) {
    $pids = getPIDsForCP($cpId);
    foreach ($pids as $pid) {
        if (!empty($pid)) shell_exec("kill -9 " . (int)$pid);
    }
    return count($pids);
}

// ---------------------------------------------
// HANDLE POST ACTIONS
// ---------------------------------------------
$warnings = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Spajanje/Odspajanje
    if (isset($_POST['connect'])) file_put_contents($stateFile, "1");
    if (isset($_POST['disconnect'])) file_put_contents($stateFile, "0");

    // Slanje Status Notificationa
    if (isset($_POST['status'])) {
        $status = $_POST['status'];
        $script = "/var/www/ocpp-php/src/Examples/v16/status_{$status}.php";

        if (file_exists($script)) {
            exec("php " . escapeshellarg($script) . " " . escapeshellarg($cpId) . " >> /var/log/ocpp/index_debug.log 2>&1 &");
            $warnings[] = "Poslan status $status za CP $cpId.";
        } else {
            $warnings[] = "Skripta nije pronadena: $script";
        }
    }

    // Pokretanje ChargePoint procesa
    if (isset($_POST['start_cp'])) {
        if (countProcessesForCP($cpId) === 0) {
            exec("nohup php /var/www/ocpp-php/src/Examples/v16/ChargePoint.php " . escapeshellarg($cpId) . " > {$logFile} 2>&1 &");
            $warnings[] = "ChargePoint $cpId pokrenut.";
        } else {
            $warnings[] = "ChargePoint $cpId je vec pokrenut.";
        }
    }

    // Zaustavljanje ChargePoint procesa
    if (isset($_POST['stop_cp'])) {
        $killed = killProcessesForCP($cpId);
        $warnings[] = "Ugašeno $killed procesa za CP $cpId.";
    }
}

// ---------------------------------------------
// TRENUTNO STANJE
// ---------------------------------------------
$connected = file_exists($stateFile) && trim(file_get_contents($stateFile)) === "1";
$currentStatus = file_exists($statusFile) ? trim(file_get_contents($statusFile)) : "Nepoznato";
$cpCount = countProcessesForCP($cpId);

// ---------------------------------------------
// AKTIVNA TRANSAKCIJA
// ---------------------------------------------
$stmt = $pdoSim->prepare("
    SELECT id, connector_id, start_time 
    FROM transactions 
    WHERE stop_time IS NULL AND chargepoint_id = ? 
    ORDER BY id DESC LIMIT 1
");
$stmt->execute([$cpId]);
$activeTx = $stmt->fetch(PDO::FETCH_ASSOC);

// ---------------------------------------------
// SOC PODACI
// ---------------------------------------------
$socData = [];
$timeData = [];

if ($activeTx) {
    $stmtSoc = $pdoSim->prepare("
        SELECT timestamp, soc 
        FROM charging_curve 
        WHERE transaction_id = ? 
        ORDER BY id ASC
    ");
    $stmtSoc->execute([$activeTx['id']]);

    foreach ($stmtSoc->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $timeData[] = date("H:i:s", strtotime($r['timestamp']));
        $socData[] = (int)$r['soc'];
    }
}
?>
<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="UTF-8">
    <title>OCPP Simulator - CP <?= htmlspecialchars($cpId) ?></title>
    <style>
        body { font-family: 'Segoe UI', Arial; padding: 20px; background: #f4f4f4; }
        .card { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .btn { padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; margin: 5px; font-weight: bold; }
        .btn-blue { background: #3498db; color: white; }
        .btn-red { background: #e74c3c; color: white; }
        .btn-green { background: #2ecc71; color: white; }
        .status { padding: 5px 10px; border-radius: 4px; font-weight: bold; }
        .ok { background: #dff0d8; color: #3c763d; }
        .bad { background: #f2dede; color: #a94442; }
        .warn { background: #fff3cd; padding: 10px; border-radius: 4px; }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<div class="card">
    <h1>OCPP Simulator - Kontrola</h1>
    <form method="get">
        <label>Odaberi punionicu:</label>
        <select name="cp" onchange="this.form.submit()">
            <?php foreach ($allCPs as $cp): ?>
                <option value="<?= $cp ?>" <?= $cp == $cpId ? 'selected' : '' ?>>
                    Punionica <?= $cp ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php foreach ($warnings as $w): ?>
    <div class="warn"><?= $w ?></div>
<?php endforeach; ?>

<div class="card">
    <h2>Upravljanje procesom</h2>
    <p>Proces:
        <span class="status <?= $cpCount > 0 ? 'ok' : 'bad' ?>">
            <?= $cpCount > 0 ? "AKTIVAN ($cpCount)" : "NIJE POKRENUT" ?>
        </span>
    </p>

    <form method="post">
        <button class="btn btn-green" name="start_cp">Pokreni CP</button>
        <button class="btn btn-red" name="stop_cp">Zaustavi CP</button>
    </form>

    <hr>

    <form method="post">
        <p>Veza (WebSocket):
            <?php if ($connected): ?>
                <span class="status ok">Spojeno</span>
                <button class="btn btn-red" name="disconnect">Odspoji</button>
            <?php else: ?>
                <span class="status bad">Odspojeno</span>
                <button class="btn btn-blue" name="connect">Spoji</button>
            <?php endif; ?>
        </p>
    </form>
</div>

<div class="card">
    <h2>StatusNotification</h2>
    <div style="display:flex; flex-wrap:wrap;">
        <?php foreach (["Available","Charging","Preparing","Finishing","SuspendedEVSE","SuspendedEV","Faulted"] as $s): ?>
            <form method="post">
                <input type="hidden" name="status" value="<?= $s ?>">
                <button class="btn <?= ($currentStatus == $s) ? 'btn-green' : 'btn-blue' ?>">
                    <?= $s ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
    <p>Zadnji status:
        <span class="status ok"><?= $currentStatus ?></span>
    </p>
</div>

<div class="card">
    <h2>Aktivna transakcija</h2>
    <?php if ($activeTx): ?>
        <p>ID: <b><?= $activeTx['id'] ?></b> | Konektor: <?= $activeTx['connector_id'] ?></p>
        <p>Pocetak: <?= $activeTx['start_time'] ?></p>
        <canvas id="socChart" width="600" height="200"></canvas>
    <?php else: ?>
        <p class="status bad">Nema aktivnog punjenja.</p>
    <?php endif; ?>
</div>

<?php if ($activeTx): ?>
<script>
new Chart(document.getElementById('socChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($timeData) ?>,
        datasets: [{
            label: 'SoC (%)',
            data: <?= json_encode($socData) ?>,
            borderColor: '#2ecc71',
            backgroundColor: 'rgba(46,204,113,0.2)',
            tension: 0.4,
            fill: true
        }]
    },
    options: { scales: { y: { min: 0, max: 100 } } }
});
</script>
<?php endif; ?>

<div class="card">
    <a href="logovi_punionica.php?cp=<?= urlencode($cpId) ?>" class="btn btn-blue">
        Pregledaj logove za CP <?= $cpId ?>
    </a>
</div>

</body>
</html>
