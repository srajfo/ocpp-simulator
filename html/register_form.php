<?php
require_once 'config.php';
require_once 'helper_functions.php';

// Dohvati aktivne ustanove
$stmt = $mysqli->query("SELECT ID, Naziv FROM Ustanova WHERE Aktivno = 1 ORDER BY Naziv");
$ustanove = $stmt->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Registracija</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="form-page">


<div class="container">

    <div class="header">
        <div class="brand">
            <div class="logo">O</div>
            <div>
                <h1>OCPP Portal</h1>
                <div class="small">Registracija novog korisnika</div>
            </div>
        </div>
        <div class="nav">
            <a href="login_form.php">Prijava</a>
        </div>
    </div>

    <div class="card" style="max-width:520px">
        <form method="post" action="register.php">

            <div class="form-row">
                <label>Ime</label>
                <input class="input" name="ime" required>
            </div>

            <div class="form-row">
                <label>Prezime</label>
                <input class="input" name="prezime" required>
            </div>

            <div class="form-row">
                <label>Korisničko ime</label>
                <input class="input" name="korisnicko" required>
            </div>

            <div class="form-row">
                <label>Lozinka</label>
                <input class="input" type="password" name="lozinka" required>
            </div>

            <div class="form-row">
                <label>Ustanova</label>
                <select class="input" name="id_ustanove" required>
                    <option value="" disabled selected>-- odaberite ustanovu --</option>

                    <?php foreach ($ustanove as $u): ?>
                        <option value="<?php echo $u['ID']; ?>">
                            <?php echo esc($u['Naziv']); ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="form-row">
                <button class="btn" type="submit">Registriraj</button>
            </div>

        </form>
    </div>

    <div class="footer">
        © <?php echo date('Y'); ?> OCPP Portal
    </div>

</div>

</body>
</html>
