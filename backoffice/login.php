<?php
require_once __DIR__ . '/../backoffice-shared/auth.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if (attempt_login($email, $password)) {
        $memberships = list_memberships(current_user()['id']);
        if (count($memberships) === 1) {
            switch_org(current_user()['id'], (int) $memberships[0]['organization_id']);
            header('Location: dashboard.php');
        } else {
            header('Location: select-org.php');
        }
        exit;
    }
    $error = 'Email atau password salah.';
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — Lakuemas ERP</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon-lakuemas.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="auth-shell">
  <div class="auth-card">
    <h1>Login</h1>
    <p class="sub">Masuk ke Backoffice Wujud ERP.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <div class="field">
        <label>Email</label>
        <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
      <button type="submit" class="btn">Login</button>
    </form>
    <div class="foot">Belum punya organisasi? <a href="register.php">Daftar di sini</a></div>
  </div>
</div>
</body>
</html>
