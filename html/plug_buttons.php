<?php
require_once 'config.php';
require_once 'helper_functions.php';
requireLogin();

// Spoji se na simulator bazu
$sim = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_simulator");
$sim->set_charset("utf8mb4");

// Spoji se na glavnu bazu
$mysqli = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_system");
$mysqli->set_charset("utf8mb4");

// Dohvati sve punionice
$stmt = $mysqli->prepare("
    SELECT ID, SerijskiBroj
    FROM Punionica
    ORDER BY ID ASC
");
$stmt->execute();
$chargers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Funkcija za dohvat plug stanja
function getPlugState($sim, $id) {
    $stmt = $sim->prepare("
        SELECT plugged_in
        FROM connector_state
        WHERE chargepoint_id = ? AND connector_id = 1
        ORDER BY last_update DESC, id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? intval($row['plugged_in']) : 0;
}
?>

<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<title>Simulacija punionica</title>

<style>
/* GLOBAL */
body {
    background: #0f1115;
    color: #e8ecf2;
    font-family: "Inter", Arial, sans-serif;
    margin: 0;
    padding: 0;
}

/* CONTAINER */
.plug-container {
    max-width: 900px;
    margin: 60px auto;
    background: #181b20;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 0 20px rgba(0,0,0,0.4);
}

.plug-container h2 {
    text-align: center;
    margin-bottom: 25px;
    font-size: 26px;
    font-weight: 700;
    color: #00e676;
}

/* TABLE */
.plug-table {
    width: 100%;
    border-collapse: collapse;
}

.plug-table th,
.plug-table td {
    padding: 14px;
    border-bottom: 1px solid #2a2f36;
    font-size: 15px;
}

.plug-table th {
    background: #22262c;
    color: #00e676;
    font-weight: 700;
    text-align: left;
}

.plug-table tr:hover {
    background: rgba(0, 230, 118, 0.08);
}

/* STATUS COLORS */
.status-on {
    color: #00e676;
    font-weight: bold;
}

.status-off {
    color: #ff4444;
    font-weight: bold;
}

/* BUTTONS */
.plug-btn {
    padding: 10px 16px;
    background: #22262c;
    color: #e8ecf2;
    border: 1px solid rgba(255,255,255,0.05);
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.25s ease;
}

.plug-btn:hover {
    background: #00e676;
    color: #000;
    transform: translateY(-2px);
    box-shadow: 0 0 12px rgba(0, 230, 118, 0.4);
}

/* RESPONSIVE */
@media (max-width: 700px) {
    .plug-container {
        margin: 20px;
        padding: 20px;
    }

    .plug-table thead {
        display: none;
    }

    .plug-table tr {
        display: block;
        margin-bottom: 16px;
        background: #22262c;
        border-radius: 10px;
        padding: 10px;
    }

    .plug-table td {
        display: flex;
        justify-content: space-between;
        padding: 10px 6px;
        border-bottom: 1px solid #2f333a;
    }

    .plug-table td:last-child {
        border-bottom: none;
    }

    .plug-table td::before {
        content: attr(data-label);
        font-weight: 700;
        color: #00e676;
    }
}
</style>

</head>
<body>

<div class="plug-container">

<h2>Simulacija punionica</h2>

<table class="plug-table">
    <thead>
        <tr>
            <th>Punionica</th>
            <th>Stanje</th>
            <th>Akcija</th>
        </tr>
    </thead>

    <tbody>
    <?php foreach ($chargers as $c): 
        $pluggedIn = getPlugState($sim, $c['ID']);
    ?>
    <tr>
        <td data-label="Punionica">
            <?= htmlspecialchars($c['ID']) ?> - <?= htmlspecialchars($c['SerijskiBroj']) ?>
        </td>

        <td data-label="Stanje">
            <?= $pluggedIn 
                ? "<span class='status-on'>Ustekan</span>" 
                : "<span class='status-off'>Izstekan</span>" ?>
        </td>

        <td data-label="Akcija">
            <form method="post" action="punionica_action.php">
                <input type="hidden" name="id" value="<?= $c['ID'] ?>">
                <input type="hidden" name="from" value="plug_buttons">

                <?php if (!$pluggedIn): ?>
                    <button class="plug-btn" type="submit" name="action" value="plug_in">Ustekao sam auto</button>
                <?php else: ?>
                    <button class="plug-btn" type="submit" name="action" value="unplug">Izvadi kabel</button>
                <?php endif; ?>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

</div>

</body>
</html>
