<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Prijava</title>
<link rel="stylesheet" href="style.css">
</head>

<body class="form-page">
<div class="container">

  <div class="header">
    <div class="brand">
      <div class="logo">O</div>
      <div>
        <h1>OCPP Portal</h1>
        <div class="small">Prijava</div>
      </div>
    </div>
    <div class="nav"><a href="register_form.php">Registracija</a></div>
  </div>

  <div class="card" style="max-width:420px">
    <form method="post" action="login.php">
      <div class="form-row">
        <label>Korisničko ime</label>
        <input class="input" name="korisnicko" required>
      </div>

      <div class="form-row">
        <label>Lozinka</label>
        <input class="input" type="password" name="lozinka" required>
      </div>

      <div class="form-row">
        <button class="btn" type="submit">Prijavi se</button>
      </div>
    </form>
  </div>

  <div class="footer">
    © <?php echo date('Y'); ?> OCPP Portal
  </div>

</div>
</body>
</html>
