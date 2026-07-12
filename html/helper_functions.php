<?php
require_once 'config.php';

/* ============================
   SIGURNO ESCAPANJE
============================ */
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ============================
   DOHVAT KORISNIKA
============================ */
function getUserByUsername($username) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT * FROM Korisnik WHERE KorisnickoIme = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getUserById($id) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT * FROM Korisnik WHERE ID = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/* ============================
   DOHVAT PRAVA KORISNIKA (samo aktivna, vraća stringove)
============================ */
function getUserRights($userId) {
    global $mysqli;
    $stmt = $mysqli->prepare("
        SELECT LP.OpisPrava
        FROM Lista_Prava LP
        JOIN Lista_Prava_Korisnika LPK ON LPK.ID_Pravo = LP.ID
        WHERE LPK.ID_Korisnika = ?
          AND LPK.Aktivno = 1
          AND LP.Aktivno = 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return array_map(fn($r) => $r['OpisPrava'], $result);
}

/* ============================
   PROVJERA PRAVA
============================ */
function hasRight($userId, $rightDesc) {
    $rights = getUserRights($userId);
    return in_array($rightDesc, $rights, true);
}

/* ============================
   SUPERADMIN (Administrator sustava)
============================ */
function isAdminSystem($userId) {
    return hasRight($userId, 'Administrator sustava');
}

/* ============================
   LOGIN ZAŠTITA
============================ */
function requireLogin() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['user_id'])) {
        header('Location: login_form.php');
        exit;
    }
}

/* ============================
   FLASH PORUKE
============================ */
function flash($key, $msg = null) {
    if ($msg === null) {
        if (isset($_SESSION['flash'][$key])) {
            $m = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $m;
        }
        return null;
    } else {
        $_SESSION['flash'][$key] = $msg;
    }
}
?>
