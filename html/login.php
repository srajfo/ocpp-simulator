<?php
require_once 'config.php';
require_once 'helper_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login_form.php');
    exit;
}

// helper za alert
function alertBack($msg) {
    echo "<script>alert('$msg'); window.history.back();</script>";
    exit;
}

$korisnicko = trim($_POST['korisnicko'] ?? '');
$lozinka = $_POST['lozinka'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

$user = getUserByUsername($korisnicko);
$time = date('Y-m-d H:i:s');
$userId = $user ? $user['ID'] : null;
$usp = 0;

if ($user && $user['Aktivno'] == 1 && password_verify($lozinka, $user['Lozinka'])) {

    // login OK
    $_SESSION['user_id'] = $user['ID'];
    $_SESSION['username'] = $user['KorisnickoIme'];
    $usp = 1;

    // svi idu na select_charger.php
    $redirect = 'select_charger.php';

    // log uspješne prijave
    $stmt = $mysqli->prepare("
        INSERT INTO Prijavljivanje (ID_Korisnik, VrijemePrijave, IP, Uspjesno)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('issi', $userId, $time, $ip, $usp);
    $stmt->execute();

    header("Location: $redirect");
    exit;

} else {

    // log neuspješne prijave
    $stmt = $mysqli->prepare("
        INSERT INTO Prijavljivanje (ID_Korisnik, VrijemePrijave, IP, Uspjesno)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('issi', $userId, $time, $ip, $usp);
    $stmt->execute();

    alertBack('Pogresno korisnicko ime ili lozinka.');
}
?>
