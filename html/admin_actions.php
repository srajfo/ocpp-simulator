<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'helper_functions.php';

$mysqli->set_charset("utf8mb4");

/**
 * Spajanje na simulator bazu radi sinkronizacije podataka
 */
$sim = new mysqli("localhost", "srajf", "Passw0rd", "ocpp_simulator");
$sim->set_charset("utf8mb4");


$action = $_GET['action'] ?? $_POST['action'] ?? null;
$tab    = $_GET['tab'] ?? $_POST['tab'] ?? null;

// Ovdje osiguravamo da je ID broj za tvoju primarnu bazu
$editId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : null);

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    die("Nema korisnika u sesiji.");
}

$user = getUserById($userId);
if (!$user) {
    die("Korisnik ne postoji.");
}

$userUstanovaId = $user['ID_Ustanove'];

$isSuperAdmin    = isAdminSystem($userId);
$isAdminUstanove = hasRight($userId, 'Administrator ustanove');

/**
 * Funkcija za provjeru ovlasti - tvoja originalna logika s mapom prava
 */
function canUserPerform(int $userId, string $action, string $tab, ?int $targetUstanovaId): bool {

    // SUPERADMIN ? može sve
    if (isAdminSystem($userId)) {
        return true;
    }

    $u = getUserById($userId);
    if (!$u) return false;

    // ADMIN USTANOVE ? može sve u svojoj ustanovi
    if (hasRight($userId, 'Administrator ustanove')) {

        if ($targetUstanovaId === null) return false;

        // smije raditi samo unutar svoje ustanove
        if ((int)$u['ID_Ustanove'] !== (int)$targetUstanovaId) {
            return false;
        }

        // unutar svoje ustanove ima full kontrolu nad korisnicima, punionicama i pravima
        return true;
    }

    // MAPA PRAVA za obicne korisnike
    $rightMap = [
        'korisnici' => [
            'view'   => 'Upravljanje korisnicima - pregled',
            'add'    => 'Upravljanje korisnicima - dodavanje',
            'edit'   => 'Upravljanje korisnicima - izmjena',
            'delete' => 'Upravljanje korisnicima - brisanje'
        ],
        'punionice' => [
            'view'   => 'Upravljanje punionicama - pregled',
            'add'    => 'Upravljanje punionicama - dodavanje',
            'edit'   => 'Upravljanje punionicama - izmjena',
            'delete' => 'Upravljanje punionicama - brisanje',
            'start'  => 'Upravljanje punionicama - paljenje',
            'stop'   => 'Upravljanje punionicama - gašenje'
        ],
        'prava' => [
            'view'   => 'Upravljanje korisnicima - izmjena',
            'edit'   => 'Upravljanje korisnicima - izmjena',
            'delete' => 'Upravljanje korisnicima - izmjena'
        ]
    ];

    if (!isset($rightMap[$tab][$action])) return false;

    $requiredRight = $rightMap[$tab][$action];

    if (!hasRight($userId, $requiredRight)) return false;

    if ($targetUstanovaId === null) return false;

    // obican korisnik smije raditi samo unutar svoje ustanove
    return (int)$u['ID_Ustanove'] === (int)$targetUstanovaId;
}

/* ---------------------------------------------------------
    ADD (korisnici, ustanove, punionice)
--------------------------------------------------------- */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    /* --- KORISNICI --- */
    if ($tab === 'korisnici') {

        $targetUstanova = $isSuperAdmin ? (int)($_POST['ustanova'] ?? 0) : (int)$userUstanovaId;

        if (!canUserPerform($userId, 'add', 'korisnici', $targetUstanova)) {
            die("Nemate ovlasti.");
        }

        $ime         = $_POST['ime'] ?? '';
        $prezime     = $_POST['prezime'] ?? '';
        $korisnicko  = $_POST['korisnicko'] ?? '';
        $lozinkaRaw  = $_POST['lozinka'] ?? '';
        $lozinkaHash = password_hash($lozinkaRaw, PASSWORD_DEFAULT);
        $aktivno     = isset($_POST['aktivno']) ? 1 : 0;

        $stmt = $mysqli->prepare("
            INSERT INTO Korisnik (Ime, Prezime, KorisnickoIme, Lozinka, ID_Ustanove, Aktivno, CreatedAt)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        if (!$stmt) die("Greška u pripremi upita: " . $mysqli->error);

        $stmt->bind_param("sssiii", $ime, $prezime, $korisnicko, $lozinkaHash, $targetUstanova, $aktivno);
        $stmt->execute();
    }

    /* --- USTANOVE --- */
    if ($tab === 'ustanove') {

        if (!$isSuperAdmin) die("Nemate ovlasti.");

        $drzava    = $_POST['drzava'] ?? '';
        $grad      = $_POST['grad'] ?? '';
        $ulica     = $_POST['ulica'] ?? '';
        $kucniBroj = $_POST['kucni_broj'] ?? '';
        $naziv     = $_POST['naziv'] ?? '';
        $aktivno   = isset($_POST['aktivno']) ? 1 : 0;

        $stmt = $mysqli->prepare("
            INSERT INTO Ustanova (Drzava, Grad, Ulica, KucniBroj, Naziv, Aktivno)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) die("Greška u pripremi upita: " . $mysqli->error);

        $stmt->bind_param("sssssi", $drzava, $grad, $ulica, $kucniBroj, $naziv, $aktivno);
        $stmt->execute();
    }

    /* --- PUNIONICE --- */
    if ($tab === 'punionice') {

        $targetUstanova = $isSuperAdmin ? (int)($_POST['ustanova'] ?? 0) : (int)$userUstanovaId;

        if (!canUserPerform($userId, 'add', 'punionice', $targetUstanova)) {
            die("Nemate ovlasti.");
        }

        $serijski = $_POST['serijski'] ?? '';
        $snaga    = (int)($_POST['snaga'] ?? 0);
        $aktivno  = isset($_POST['aktivno']) ? 1 : 0;

        $stmt = $mysqli->prepare("
            INSERT INTO Punionica (SerijskiBroj, ID_Ustanove, MaxJacinaPunjenja, Aktivno)
            VALUES (?, ?, ?, ?)
        ");
        if (!$stmt) die("Greška u pripremi upita: " . $mysqli->error);

        $stmt->bind_param("siii", $serijski, $targetUstanova, $snaga, $aktivno);
        $stmt->execute();
    }

    header("Location: admin.php?tab=$tab");
    exit;
}

/* ---------------------------------------------------------
    DELETE (Popravljeno da briše po primarnom ID-u)
--------------------------------------------------------- */
if ($action === 'delete' && $editId) {

    $tableMap = [
        'korisnici' => 'Korisnik',
        'punionice' => 'Punionica',
        'ustanove'  => 'Ustanova'
    ];

    if (!isset($tableMap[$tab])) die("Neispravan tab.");

    $table = $tableMap[$tab];

    // Ucitaj zapis da znamo podatke prije brisanja
    if ($tab === 'korisnici') {
        $row = getUserById($editId);
    } elseif ($tab === 'punionice') {
        $stmt = $mysqli->prepare("SELECT * FROM Punionica WHERE ID = ?");
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    } elseif ($tab === 'ustanove') {
        if (!$isSuperAdmin) die("Nemate ovlasti.");
        $stmt = $mysqli->prepare("SELECT * FROM Ustanova WHERE ID = ?");
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    }

    if (!$row) die("Zapis ne postoji.");

    $targetUstanova = $row['ID_Ustanove'] ?? null;

    if ($tab !== 'ustanove') {
        if (!canUserPerform($userId, 'delete', $tab, $targetUstanova)) {
            die("Nemate ovlasti.");
        }
    }

    /* --- BRISANJE VEZANO UZ KORISNIKA --- */
    if ($tab === 'korisnici') {
        // Koristimo integer ID korisnika
        $sim->query("DELETE FROM charging_curve WHERE transaction_id IN (SELECT id FROM transactions WHERE user_id = $editId)");
        $sim->query("DELETE FROM transactions WHERE user_id = $editId");
        $mysqli->query("DELETE FROM Status_Punionice WHERE ID_Korisnika = $editId");
        $mysqli->query("DELETE FROM Lista_Prava_Korisnika WHERE ID_Korisnika = $editId");
    }

    /* --- BRISANJE VEZANO UZ PUNIONICU (Fix za tvoju grešku) --- */
    if ($tab === 'punionice') {
        $serijskiId = $row['SerijskiBroj']; // Uzimamo string npr. 'CP-001' za simulator
        
        // 1. Brišemo statuse u našoj bazi po numerièkom ID-u punionice
        $mysqli->query("DELETE FROM Status_Punionice WHERE ID_Punionice = $editId");

        // 2. Brišemo iz simulatora po serijskom broju (ovdje dodajemo navodnike)
        $sim->query("DELETE FROM connectors WHERE chargepoint_id = '$serijskiId'");
        $sim->query("DELETE FROM connector_state WHERE chargepoint_id = '$serijskiId'");
        $sim->query("DELETE FROM events WHERE chargepoint_id = '$serijskiId'");
    }

    /* --- BRISANJE GLAVNOG ZAPISA --- */
    $stmt = $mysqli->prepare("DELETE FROM $table WHERE ID = ?");
    $stmt->bind_param("i", $editId);
    $stmt->execute();

    header("Location: admin.php?tab=$tab");
    exit;
}

/* ---------------------------------------------------------
    EDIT LOAD
--------------------------------------------------------- */
$editData = null;

if ($action === 'edit' && $editId) {

    if ($tab === 'korisnici') {
        $editData = getUserById($editId);
        if (!$editData) die("Korisnik ne postoji.");

        if (!canUserPerform($userId, 'edit', 'korisnici', $editData['ID_Ustanove'])) {
            die("Nemate ovlasti.");
        }
    }

    if ($tab === 'ustanove') {
        if (!$isSuperAdmin) die("Nemate ovlasti.");
        $stmt = $mysqli->prepare("SELECT * FROM Ustanova WHERE ID=?");
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $editData = $stmt->get_result()->fetch_assoc();
    }

    if ($tab === 'punionice') {
        $stmt = $mysqli->prepare("SELECT * FROM Punionica WHERE ID=?");
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $editData = $stmt->get_result()->fetch_assoc();

        if (!$editData) die("Punionica ne postoji.");

        if (!canUserPerform($userId, 'edit', 'punionice', $editData['ID_Ustanove'])) {
            die("Nemate ovlasti.");
        }
    }
}

/* ---------------------------------------------------------
    EDIT ALL PRAVA
--------------------------------------------------------- */
if ($action === 'edit_all' && $tab === 'prava' && $editId) {

    $targetUser = getUserById($editId);
    if (!$targetUser) die("Korisnik ne postoji.");

    if (!canUserPerform($userId, 'edit', 'prava', $targetUser['ID_Ustanove'])) {
        die("Nemate ovlasti.");
    }

    return;
}

/* ---------------------------------------------------------
    SAVE
--------------------------------------------------------- */
if ($action === 'save' && $editId && $_SERVER['REQUEST_METHOD'] === 'POST') {

    /* --- KORISNICI --- */
    if ($tab === 'korisnici') {

        $editUser = getUserById($editId);
        if (!$editUser) die("Korisnik ne postoji.");

        if (!canUserPerform($userId, 'edit', 'korisnici', $editUser['ID_Ustanove'])) {
            die("Nemate ovlasti.");
        }

        $ime        = $_POST['ime'] ?? '';
        $prezime    = $_POST['prezime'] ?? '';
        $korisnicko = $_POST['korisnicko'] ?? '';
        $ustanova   = $isSuperAdmin ? (int)($_POST['ustanova'] ?? 0) : $userUstanovaId;
        $aktivno    = isset($_POST['aktivno']) ? 1 : 0;

        if (!empty($_POST['lozinka'])) {
            $lozinka = password_hash($_POST['lozinka'], PASSWORD_DEFAULT);

            $stmt = $mysqli->prepare("
                UPDATE Korisnik
                SET Ime=?, Prezime=?, KorisnickoIme=?, Lozinka=?, ID_Ustanove=?, Aktivno=?
                WHERE ID=?
            ");
            $stmt->bind_param("sssiiii", $ime, $prezime, $korisnicko, $lozinka, $ustanova, $aktivno, $editId);

        } else {
            $stmt = $mysqli->prepare("
                UPDATE Korisnik
                SET Ime=?, Prezime=?, KorisnickoIme=?, ID_Ustanove=?, Aktivno=?
                WHERE ID=?
            ");
            $stmt->bind_param("sssiii", $ime, $prezime, $korisnicko, $ustanova, $aktivno, $editId);
        }

        $stmt->execute();
    }

    /* --- USTANOVE --- */
    if ($tab === 'ustanove') {

        if (!$isSuperAdmin) die("Nemate ovlasti.");

        $drzava    = $_POST['drzava'] ?? '';
        $grad      = $_POST['grad'] ?? '';
        $ulica     = $_POST['ulica'] ?? '';
        $kucniBroj = $_POST['kucni_broj'] ?? '';
        $naziv     = $_POST['naziv'] ?? '';
        $aktivno   = isset($_POST['aktivno']) ? 1 : 0;

        $stmt = $mysqli->prepare("
            UPDATE Ustanova
            SET Drzava=?, Grad=?, Ulica=?, KucniBroj=?, Naziv=?, Aktivno=?
            WHERE ID=?
        ");
        $stmt->bind_param("sssssii", $drzava, $grad, $ulica, $kucniBroj, $naziv, $aktivno, $editId);
        $stmt->execute();
    }

    /* --- PUNIONICE --- */
    if ($tab === 'punionice') {

        $stmt = $mysqli->prepare("SELECT * FROM Punionica WHERE ID=?");
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $punionica = $stmt->get_result()->fetch_assoc();

        if (!$punionica) die("Punionica ne postoji.");

        if (!canUserPerform($userId, 'edit', 'punionice', $punionica['ID_Ustanove'])) {
            die("Nemate ovlasti.");
        }

        $serijski = $_POST['serijski'] ?? '';
        $snaga    = (int)($_POST['snaga'] ?? 0);
        $ustanova = $isSuperAdmin ? (int)($_POST['ustanova'] ?? 0) : $userUstanovaId;
        $aktivno  = isset($_POST['aktivno']) ? 1 : 0;

        $stmt = $mysqli->prepare("
            UPDATE Punionica
            SET SerijskiBroj=?, ID_Ustanove=?, MaxJacinaPunjenja=?, Aktivno=?
            WHERE ID=?
        ");
        $stmt->bind_param("siiii", $serijski, $ustanova, $snaga, $aktivno, $editId);
        $stmt->execute();
    }

    header("Location: admin.php?tab=$tab");
    exit;
}

/* ---------------------------------------------------------
    SAVE ALL PRAVA
--------------------------------------------------------- */
if ($action === 'save_all' && $tab === 'prava' && $editId) {

    $korisnikId = $editId;
    $targetUser = getUserById($korisnikId);
    if (!$targetUser) die("Korisnik ne postoji.");

    if (!canUserPerform($userId, 'edit', 'prava', $targetUser['ID_Ustanove'])) {
        die("Nemate ovlasti.");
    }

    $novaPrava = $_POST['prava'] ?? [];
    $novaPrava = array_map('intval', $novaPrava);

    $postojecaPravaRaw = $mysqli->query("
        SELECT ID_Pravo 
        FROM Lista_Prava_Korisnika 
        WHERE ID_Korisnika = $korisnikId
    ")->fetch_all(MYSQLI_ASSOC);

    $postojecaPrava = array_map('intval', array_column($postojecaPravaRaw, 'ID_Pravo'));

    if (!$isSuperAdmin) {
        if (in_array(1, $postojecaPrava, true) && !in_array(1, $novaPrava, true)) {
            die("Nemate ovlasti ukloniti pravo Administrator sustava.");
        }
        if (in_array(2, $postojecaPrava, true) && !in_array(2, $novaPrava, true)) {
            die("Nemate ovlasti ukloniti pravo Administrator ustanove.");
        }
        $novaPrava = array_filter($novaPrava, fn($p) => !in_array($p, [1,2], true));
    }

    $zaDodati = array_diff($novaPrava, $postojecaPrava);
    $zaObrisati = array_diff($postojecaPrava, $novaPrava);

    if (!$isSuperAdmin) {
        $zaObrisati = array_filter($zaObrisati, fn($p) => !in_array($p, [1,2], true));
    }

    if (!empty($zaObrisati)) {
        $ids = implode(',', $zaObrisati);
        $mysqli->query("DELETE FROM Lista_Prava_Korisnika WHERE ID_Korisnika = $korisnikId AND ID_Pravo IN ($ids)");
    }

    if (!empty($zaDodati)) {
        $stmt = $mysqli->prepare("INSERT INTO Lista_Prava_Korisnika (ID_Korisnika, ID_Pravo, Aktivno) VALUES (?, ?, 1)");
        foreach ($zaDodati as $pid) {
            $stmt->bind_param("ii", $korisnikId, $pid);
            $stmt->execute();
        }
    }

    header("Location: admin.php?tab=prava");
    exit;
}