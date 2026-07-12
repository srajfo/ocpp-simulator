<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$cp = $_GET['cp'] ?? null;
if (!$cp) die("Nije odabrana punionica!");

// -----------------------------
// SIGURNA FUNKCIJA ZA OUTPUT
// -----------------------------
function safe($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// -----------------------------
// FUNKCIJE
// -----------------------------
function tailFile($file, $lines = 20) {
    if (!file_exists($file)) return "";
    return shell_exec("tail -n " . intval($lines) . " " . escapeshellarg($file)) ?? "";
}

function countLines($file) {
    if (!file_exists($file)) return 0;
    return intval(shell_exec("wc -l < " . escapeshellarg($file)) ?? 0);
}

function lastModified($file) {
    if (!file_exists($file)) return "N/A";
    return date("H:i:s", filemtime($file));
}

// -----------------------------
// PUTANJE LOGOVA — PO ID-u
// -----------------------------
$cpLogFile   = "/var/log/ocpp/chargepoint_{$cp}.log";
$csmsLogFile = "/var/www/ocpp-php/src/Examples/v16/csms.log";

// -----------------------------
// DOHVAT SADRŽAJA
// -----------------------------
$cpLog   = tailFile($cpLogFile);
$csmsLog = tailFile($csmsLogFile);

$cpLines   = countLines($cpLogFile);
$csmsLines = countLines($csmsLogFile);

$cpTime   = lastModified($cpLogFile);
$csmsTime = lastModified($csmsLogFile);

// HASH za auto-refresh
$hash = md5(($cpLog ?? '') . ($csmsLog ?? ''));

// -----------------------------
// AJAX REFRESH
// -----------------------------
if (isset($_GET['ajax'])) {
    echo json_encode([
        "hash"      => $hash,
        "cp"        => $cpLog ?? '',
        "csms"      => $csmsLog ?? '',
        "cpLines"   => $cpLines,
        "csmsLines" => $csmsLines,
        "cpTime"    => $cpTime,
        "csmsTime"  => $csmsTime
    ]);
    exit;
}

// -----------------------------
// BRISANJE LOGOVA
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['clear_cp'])) {
        exec("truncate -s 0 " . escapeshellarg($cpLogFile));
    }

    if (isset($_POST['clear_csms'])) {
        exec("truncate -s 0 " . escapeshellarg($csmsLogFile));
    }

    header("Location: logovi_punionica.php?cp=" . urlencode($cp));
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Logovi punionice <?= safe($cp) ?></title>
    <style>
        body { font-family: Arial; padding: 20px; }
        pre { background: #f4f4f4; padding: 10px; border: 1px solid #ccc; max-height: 500px; overflow-y: scroll; }
        .btn { padding: 10px 20px; margin: 5px; background: #3498db; color: white; border: none; cursor: pointer; text-decoration: none; }
        .info { color: #555; font-size: 14px; margin-bottom: 5px; }
    </style>
</head>
<body>

<h1>Logovi punionice ID <?= safe($cp) ?></h1>

<!-- ----------------------------- -->
<!-- CHARGEPOINT LOG -->
<!-- ----------------------------- -->
<h2>ChargePoint log (zadnjih 20 linija)</h2>

<p class="info">Zadnja promjena: <?= safe($cpTime) ?> | Ukupno linija: <?= safe($cpLines) ?></p>

<pre id="cpLog"><?= safe($cpLog) ?></pre>

<form method="post">
    <button class="btn" name="clear_cp">Obriši ChargePoint log</button>
</form>

<!-- ----------------------------- -->
<!-- CSMS LOG -->
<!-- ----------------------------- -->
<h2>CSMS log (zadnjih 20 linija)</h2>

<p class="info">Zadnja promjena: <?= safe($csmsTime) ?> | Ukupno linija: <?= safe($csmsLines) ?></p>

<pre id="csmsLog"><?= safe($csmsLog) ?></pre>

<form method="post">
    <button class="btn" name="clear_csms">Obriši CSMS log</button>
</form>

<p><a href="index.php?cp=<?= urlencode($cp) ?>" class="btn">Natrag</a></p>

<script>
let lastHash = "<?= $hash ?>";

function refreshLogs() {
    fetch("logovi_punionica.php?cp=<?= urlencode($cp) ?>&ajax=1")
        .then(r => r.json())
        .then(data => {
            if (data.hash !== lastHash) {
                document.getElementById("cpLog").textContent = data.cp;
                document.getElementById("csmsLog").textContent = data.csms;
                lastHash = data.hash;
            }
        });
}

setInterval(refreshLogs, 2000);
</script>

</body>
</html>
