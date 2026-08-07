<?php
require_once __DIR__ . '/../backoffice-shared/auth.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $orgName = trim($_POST['org_name'] ?? '');

    if ($name === '' || $email === '' || $password === '' || $orgName === '') {
        $error = 'Semua field wajib diisi.';
    } elseif (strlen($password) < 8) {
        $error = 'Password minimal 8 karakter.';
    } else {
        try {
            register_user_and_org($name, $email, $password, $orgName);
            header('Location: dashboard.php');
            exit;
        } catch (Throwable $e) {
            $error = str_contains($e->getMessage(), 'Duplicate')
                ? 'Email ini sudah terdaftar. Silakan login.'
                : 'Gagal mendaftar. Coba lagi.';
            if (DEBUG_MODE) {
                $error .= ' [DEBUG: ' . $e->getMessage() . ']';
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daftar — Wujud ERP</title>
<link rel="apple-touch-icon" sizes="180x180" href="assets/img/favicons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicons/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/img/favicons/favicon-16x16.png">
<link rel="shortcut icon" type="image/x-icon" href="assets/img/favicons/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="auth-shell">
  <div class="auth-card">
    <h1>Buat Organisasi Baru</h1>
    <p class="sub">Daftar akun sekaligus buat organisasi pertama kamu. Kamu otomatis jadi Owner.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <div class="field">
        <label>Nama Lengkap</label>
        <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Email</label>
        <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Password (min. 8 karakter)</label>
        <input type="password" name="password" required minlength="8">
      </div>
      <div class="field">
        <label>Nama Organisasi / Perusahaan</label>
        <input type="text" name="org_name" required value="<?= htmlspecialchars($_POST['org_name'] ?? '') ?>">
      </div>
      <button type="submit" class="btn">Daftar & Buat Organisasi</button>
    </form>
    <div class="foot">Sudah punya akun? <a href="login.php">Login di sini</a></div>
  </div>
</div>
</body>
</html>
