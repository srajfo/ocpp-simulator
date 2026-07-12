<?php
$canAdd = false;

if ($action === null) {

    // KORISNICI
    if ($tab === 'korisnici' &&
        (isAdminSystem($userId) ||
         hasRight($userId, 'Upravljanje korisnicima - dodavanje') ||
         hasRight($userId, 'Administrator ustanove'))) {
        $canAdd = true;
    }

    // PUNIONICE
    if ($tab === 'punionice' &&
        (isAdminSystem($userId) ||
         hasRight($userId, 'Upravljanje punionicama - dodavanje') ||
         hasRight($userId, 'Administrator ustanove'))) {
        $canAdd = true;
    }

    // USTANOVE (samo superadmin)
    if ($tab === 'ustanove' && isAdminSystem($userId)) {
        $canAdd = true;
    }

    // PRAVA ? nikad nema Dodaj (ni superadmin, ni admin ustanove)
    if ($tab === 'prava') {
        $canAdd = false;
    }
}

if ($canAdd): ?>
    <form method="get" class="add-form">
        <input type="hidden" name="tab" value="<?= $tab ?>">
        <input type="hidden" name="action" value="add">
        <button class="btn add-btn">Dodaj</button>
    </form>
<?php endif; ?>

<?php
// Loader za ADD i EDIT (ali NE za edit_all — to je u admin.php)
if ($action === 'add' || $action === 'edit') {
    echo '<div class="card">';
    require "views/form_$tab.php";
    echo '</div>';
    return;
}

// Ucitaj tablicu
$viewFile = "views/table_$tab.php";
if (file_exists($viewFile)) {
    require $viewFile;
}
