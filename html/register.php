<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$ime         = trim($_POST['ime'] ?? '');
$prezime     = trim($_POST['prezime'] ?? '');
$korisnicko  = trim($_POST['korisnicko'] ?? '');
$lozinka     = $_POST['lozinka'] ?? '';
$id_ustanove = intval($_POST['id_ustanove'] ?? 0);

// Helper za alert + povratak
function alertBack($msg) {
    echo "<script>alert('$msg'); window.history.back();</script>";
    exit;
}

// Provjera praznih polja
if ($ime === '' || $prezime === '' || $korisnicko === '' || $lozinka === '' || $id_ustanove === 0) {
    alertBack('Sva polja su obavezna.');
}

/* -----------------------------------------
   VALIDACIJA KORISNICKOG IMENA I LOZINKE
----------------------------------------- */

// korisnicko ime: 3–20 znakova
if (strlen($korisnicko) < 3 || strlen($korisnicko) > 20) {
    alertBack('Korisnicko ime mora imati izmedu 3 i 20 znakova.');
}

// lozinka: minimalno 4 znaka
if (strlen($lozinka) < 4) {
    alertBack('Lozinka mora imati barem 4 znaka.');
}

// Provjera ustanove
$stmt = $mysqli->prepare("SELECT ID FROM Ustanova WHERE ID = ? AND Aktivno = 1 LIMIT 1");
$stmt->bind_param('i', $id_ustanove);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    alertBack('Nevažeca ustanova.');
}

// Provjera korisnickog imena (jedinstvenost)
$stmt = $mysqli->prepare("SELECT 1 FROM Korisnik WHERE KorisnickoIme = ? LIMIT 1");
$stmt->bind_param('s', $korisnicko);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    alertBack('Korisnicko ime vec postoji.');
}

// Hash lozinke
$hash = password_hash($lozinka, PASSWORD_DEFAULT);

// Unos korisnika
$stmt = $mysqli->prepare("
    INSERT INTO Korisnik (Ime, Prezime, KorisnickoIme, Lozinka, ID_Ustanove, Aktivno, CreatedAt)
    VALUES (?, ?, ?, ?, ?, 1, NOW())
");
$stmt->bind_param('ssssi', $ime, $prezime, $korisnicko, $hash, $id_ustanove);

if (!$stmt->execute()) {
    alertBack('Greška pri unosu korisnika: ' . $stmt->error);
}

$newUserId = $mysqli->insert_id;

/* -----------------------------------------
   AUTOMATSKO DODJELJIVANJE PRAVA
----------------------------------------- */

$stmt = $mysqli->prepare("SELECT ID FROM Lista_Prava WHERE OpisPrava = 'Korisnik' LIMIT 1");
$stmt->execute();
$pravo = $stmt->get_result()->fetch_assoc();

if ($pravo) {
    $pid = $pravo['ID'];

    $sql = "INSERT INTO Lista_Prava_Korisnika (ID_Korisnika, ID_Pravo, Aktivno)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE Aktivno = 1";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        alertBack('Greška u pripremi SQL-a: ' . $mysqli->error);
    }

    $stmt->bind_param('ii', $newUserId, $pid);

    if (!$stmt->execute()) {
        alertBack('Greška pri dodjeli prava: ' . $stmt->error);
    }
}

// Sve OK ? redirect
header("Location: login_form.php?reg=ok");
exit;
?>
