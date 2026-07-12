<?php
// --- DEBUG MOD ---
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

$debugFile = __DIR__ . "/debug_action.txt";
file_put_contents($debugFile, "\n\n===== NOVI POZIV " . date("Y-m-d H:i:s") . " =====\n", FILE_APPEND);

ob_start();

// 1) BAZE
$mysqli = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_system");
$sim    = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_simulator");

$mysqli->set_charset("utf8mb4");
$sim->set_charset("utf8mb4");

// 2) FUNKCIJE
require_once 'config.php';
require_once 'helper_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    file_put_contents($debugFile, "POST:\n" . print_r($_POST, true) . "\n", FILE_APPEND);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header("Location: home.php");
        exit;
    }

    requireLogin();

    $uid    = intval($_SESSION['user_id'] ?? 0);
    $id     = intval($_POST['id'] ?? 0);      // ID punionice = chargepoint_id
    $action = trim($_POST['action'] ?? '');

    file_put_contents($debugFile, "ID=$id ACTION=$action UID=$uid\n", FILE_APPEND);

    // -----------------------------
    //  COOLDOWN (3 sekunde)
    // -----------------------------
    $cooldownFile = __DIR__ . "/cooldown_cp_{$id}.txt";
    $now = time();

    if (file_exists($cooldownFile)) {
        $last = intval(file_get_contents($cooldownFile));
        if ($now - $last < 3) {
            file_put_contents($debugFile, "COOLDOWN BLOKIRAO ZAHTJEV\n", FILE_APPEND);
            header("Location: home.php?id=" . $id);
            exit;
        }
    }

    file_put_contents($cooldownFile, $now);

    // -----------------------------
    //  VALIDACIJA AKCIJE
    // -----------------------------
    $allowedActions = ['start','pause','stop','plug_in','unplug'];
    if ($id === 0 || !in_array($action, $allowedActions, true)) {
        header("Location: home.php?id=" . $id);
        exit;
    }

    // --- PROVJERA PRAVA ---
    $allowed = false;

    if (isAdminSystem($uid)) {
        $allowed = true;
    } elseif (hasRight($uid, 'Administrator ustanove')) {
        $stmt = $mysqli->prepare("
            SELECT 1 FROM Punionica p
            JOIN Korisnik k ON k.ID_Ustanove = p.ID_Ustanove
            WHERE p.ID = ? AND k.ID = ? LIMIT 1
        ");
        $stmt->bind_param('ii', $id, $uid);
        $stmt->execute();
        $allowed = (bool)$stmt->get_result()->fetch_row();
    } else {
        $stmt = $mysqli->prepare("
            SELECT ImaPravo FROM Pristup_Punionici
            WHERE ID_Punionice = ? AND ID_Korisnika = ? LIMIT 1
        ");
        $stmt->bind_param('ii', $id, $uid);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $allowed = ($r && intval($r['ImaPravo']) === 1);
    }

    if (!$allowed) {
        header("Location: home.php?id=" . $id);
        exit;
    }

    // --- PLUG STATE ---
    $stmtPlug = $sim->prepare("
        SELECT plugged_in 
        FROM connector_state
        WHERE chargepoint_id = ? AND connector_id = 1
        ORDER BY last_update DESC, id DESC
        LIMIT 1
    ");
    $stmtPlug->bind_param('i', $id);
    $stmtPlug->execute();
    $plugState = $stmtPlug->get_result()->fetch_assoc();

    $isPlugged = $plugState ? intval($plugState['plugged_in']) : 0;

    // -----------------------------
    //  PLUG IN
    // -----------------------------
    if ($action === 'plug_in') {

        $stmtIns = $sim->prepare("
            INSERT INTO connector_state (chargepoint_id, connector_id, plugged_in, last_update)
            VALUES (?, 1, 1, NOW())
        ");
        $stmtIns->bind_param('i', $id);
        $stmtIns->execute();

        exec("php /var/www/ocpp-php/src/Examples/v16/status_Preparing.php $id $uid > /dev/null 2>&1 &");

        if (isset($_POST['from']) && $_POST['from'] === 'plug_buttons') {
            header("Location: plug_buttons.php?id=" . $id);
            exit;
        }

        echo "OK";
        exit;
    }

    // -----------------------------
    //  UNPLUG
    // -----------------------------
    if ($action === 'unplug') {

        $stmtIns = $sim->prepare("
            INSERT INTO connector_state (chargepoint_id, connector_id, plugged_in, last_update)
            VALUES (?, 1, 0, NOW())
        ");
        $stmtIns->bind_param('i', $id);
        $stmtIns->execute();

        exec("php /var/www/ocpp-php/src/Examples/v16/status_Available.php $id $uid > /dev/null 2>&1 &");

        if (isset($_POST['from']) && $_POST['from'] === 'plug_buttons') {
            header("Location: plug_buttons.php?id=" . $id);
            exit;
        }

        echo "OK";
        exit;
    }

    // -----------------------------
    //  START
    // -----------------------------
    if ($action === 'start') {

        if ($isPlugged === 0) {
            header("Location: home.php?id=" . $id);
            exit;
        }

        exec("php /var/www/ocpp-php/src/Examples/v16/status_Charging.php $id $uid > /dev/null 2>&1 &");

        header("Location: home.php?id=" . $id);
        exit;
    }

    // -----------------------------
    //  PAUSE
    // -----------------------------
    if ($action === 'pause') {

        exec("php /var/www/ocpp-php/src/Examples/v16/status_SuspendedEVSE.php $id $uid > /dev/null 2>&1 &");

        header("Location: home.php?id=" . $id);
        exit;
    }

    // -----------------------------
    //  STOP
    // -----------------------------
    if ($action === 'stop') {

        $stmtTx = $sim->prepare("
            SELECT id 
            FROM transactions
            WHERE chargepoint_id = ? AND stop_time IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtTx->bind_param('i', $id);
        $stmtTx->execute();
        $tx = $stmtTx->get_result()->fetch_assoc();

        if ($tx) {
            $txId = intval($tx['id']);

            $stmtUpd = $sim->prepare("
                UPDATE transactions
                SET stop_time = NOW(), status = 'finished'
                WHERE id = ?
            ");
            $stmtUpd->bind_param('i', $txId);
            $stmtUpd->execute();

            exec("pkill -f 'soc_generator.php {$txId}'");

        } else {
            exec("pkill -f 'soc_generator.php'");
        }

        exec("php /var/www/ocpp-php/src/Examples/v16/status_Finishing.php $id $uid > /dev/null 2>&1 &");

        header("Location: home.php?id=" . $id);
        exit;
    }

} catch (Throwable $e) {

    file_put_contents($debugFile, "EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
    header("Location: home.php?id=" . ($id ?? 0));
    exit;
}
