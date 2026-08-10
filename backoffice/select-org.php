<?php
require_once __DIR__ . '/../backoffice-shared/auth.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $orgId = (int) ($_POST['organization_id'] ?? 0);
    if (switch_org($user['id'], $orgId)) {
        header('Location: dashboard.php');
        exit;
    }
}

$memberships = list_memberships($user['id']);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pilih Organisasi — Lakuemas ERP</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon-lakuemas.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="auth-shell">
  <div class="auth-card">
    <h1>Pilih Organisasi</h1>
    <p class="sub">Halo <?= htmlspecialchars($user['name']) ?>, kamu anggota di beberapa organisasi. Pilih salah satu.</p>
    <?php if (!$memberships): ?>
      <p>Kamu belum jadi anggota organisasi manapun. <a href="register.php">Buat organisasi baru</a>.</p>
    <?php else: ?>
      <div class="org-pick-list">
        <?php foreach ($memberships as $m): ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="organization_id" value="<?= $m['organization_id'] ?>">
            <button type="submit" class="org-pick-item">
              <div class="name"><?= htmlspecialchars($m['legal_name']) ?></div>
              <div class="role"><?= htmlspecialchars($m['role_name']) ?></div>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="foot"><a href="logout.php">Logout</a></div>
  </div>
</div>
</body>
</html>
