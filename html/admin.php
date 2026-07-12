<?php
require_once 'config.php';
require_once 'helper_functions.php';
requireLogin();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


$userId = $_SESSION['user_id'];

$rights = getUserRights($userId);
$hasAdminRights = count(array_filter($rights, fn($r) => trim($r) !== 'Korisnik')) > 0;

if (!$hasAdminRights) {
    die("Nemate pristup administraciji.");
}

$tab    = $_GET['tab'] ?? $_POST['tab'] ?? 'korisnici';
$action = $_GET['action'] ?? null;
$editId = $_GET['id'] ?? null;

require_once 'admin_actions.php';
?>
<!doctype html>
<html lang="hr" class="home-page">
<head>
    <meta charset="utf-8">
    <title>Administracija</title>
    <link rel="stylesheet" href="style.css">
</head>

<body class="home-page">
<div class="container">

<!-- HEADER -->
<div class="header">
    <div class="brand">
        <div class="logo">A</div>
        <h1>Administracija</h1>
    </div>
    <div class="nav">
        <a class="nav-btn" href="select_charger.php">Natrag</a>
        <a class="nav-btn" href="logout.php">Odjava</a>
    </div>
</div>

<!-- TITLE -->
<div class="title-card">Administratorski panel</div>

<!-- TABOVI -->
<div class="tabs" style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap;">

    <!-- KORISNICI -->
    <?php if (isAdminSystem($userId) || hasRight($userId, 'Upravljanje korisnicima - pregled') || hasRight($userId, 'Administrator ustanove')): ?>
        <form method="get">
            <input type="hidden" name="tab" value="korisnici">
            <button class="btn <?= $tab=='korisnici'?'active-tab':'' ?>">Korisnici</button>
        </form>
    <?php endif; ?>

    <!-- USTANOVE (SAMO SUPERADMIN) -->
    <?php if (isAdminSystem($userId)): ?>
        <form method="get">
            <input type="hidden" name="tab" value="ustanove">
            <button class="btn <?= $tab=='ustanove'?'active-tab':'' ?>">Ustanove</button>
        </form>
    <?php endif; ?>

    <!-- PUNIONICE -->
    <?php if (isAdminSystem($userId) || hasRight($userId, 'Administrator ustanove') || hasRight($userId, 'Upravljanje punionicama - pregled')): ?>
        <form method="get">
            <input type="hidden" name="tab" value="punionice">
            <button class="btn <?= $tab=='punionice'?'active-tab':'' ?>">Punionice</button>
        </form>
    <?php endif; ?>
<!-- PUNJENJA -->
<?php if (isAdminSystem($userId) || hasRight($userId, 'Administrator ustanove')): ?>
    <form method="get">
        <input type="hidden" name="tab" value="punjenja">
        <button class="btn <?= $tab=='punjenja'?'active-tab':'' ?>">Punjenja</button>
    </form>
<?php endif; ?>



    <!-- PRAVA (SUPERADMIN + ADMIN USTANOVE) -->
    <?php if (isAdminSystem($userId) || hasRight($userId, 'Administrator ustanove')): ?>
        <form method="get">
            <input type="hidden" name="tab" value="prava">
            <button class="btn <?= $tab=='prava'?'active-tab':'' ?>">Prava</button>
        </form>
    <?php endif; ?>
	
	

</div>

<?php
/* ---------------------------------------------------------
   LOADER ZA UREÐIVANJE SVIH PRAVA KORISNIKA
--------------------------------------------------------- */
if ($tab === 'prava' && $action === 'edit_all' && $editId) {
    echo '<div class="card">';
    require "views/form_prava.php"; // checkbox forma
    echo '</div>';
    echo '</div></body></html>';
    return;
}
?>

<!-- SADRŽAJ -->
<?php require_once 'admin_views.php'; ?>

<div class="footer">© 2026 OCPP Portal</div>

</div>
</body>
</html>
