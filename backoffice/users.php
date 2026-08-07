<?php
$pageTitle = 'Admin User';
$activeMenu = 'users';
require __DIR__ . '/includes/header.php';

$pdo = db();
$isOwner = $org['role_name'] === 'Owner';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$isOwner) {
        $flash = ['error', 'Cuma Owner yang bisa mengelola anggota organisasi.'];
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_member') {
                $email = trim($_POST['email'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $roleId = (int) ($_POST['role_id'] ?? 0);
                $entityType = ($_POST['entity_type'] ?? '') === 'badan' ? 'badan' : 'perorangan';
                $subjectToPph = !empty($_POST['subject_to_pph']) ? 1 : 0;
                if ($email === '' || $roleId === 0) {
                    throw new RuntimeException('Email dan Role wajib diisi.');
                }

                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                $stmt->execute([$email]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $userId = (int) $existing['id'];
                } else {
                    if ($name === '') throw new RuntimeException('Nama wajib diisi untuk user baru.');
                    $tempPassword = bin2hex(random_bytes(5));
                    $pdo->prepare('INSERT INTO users (name, email, password_hash, entity_type, subject_to_pph) VALUES (?,?,?,?,?)')
                        ->execute([$name, $email, password_hash($tempPassword, PASSWORD_DEFAULT), $entityType, $subjectToPph]);
                    $userId = (int) $pdo->lastInsertId();
                    $flash = ['ok', "User baru dibuat. Password sementara: $tempPassword (kirim manual ke user, minta ganti setelah login pertama)."];
                }

                $check = $pdo->prepare('SELECT id FROM user_organization_roles WHERE user_id=? AND organization_id=?');
                $check->execute([$userId, $org['organization_id']]);
                if ($check->fetch()) {
                    throw new RuntimeException('User ini sudah jadi anggota organisasi.');
                }

                $pdo->prepare('INSERT INTO user_organization_roles (user_id, organization_id, role_id) VALUES (?,?,?)')
                    ->execute([$userId, $org['organization_id'], $roleId]);
                if (!$flash) $flash = ['ok', 'Anggota ditambahkan.'];
            } elseif ($action === 'update_role') {
                $membershipId = (int) ($_POST['membership_id'] ?? 0);
                $roleId = (int) ($_POST['role_id'] ?? 0);
                $pdo->prepare('UPDATE user_organization_roles SET role_id=? WHERE id=? AND organization_id=?')
                    ->execute([$roleId, $membershipId, $org['organization_id']]);
                $flash = ['ok', 'Role anggota diperbarui.'];
            } elseif ($action === 'toggle_status') {
                $membershipId = (int) ($_POST['membership_id'] ?? 0);
                $status = $_POST['status'] ?? 'active';
                $pdo->prepare('UPDATE user_organization_roles SET status=? WHERE id=? AND organization_id=?')
                    ->execute([$status, $membershipId, $org['organization_id']]);
                $flash = ['ok', 'Status anggota diperbarui.'];
            } elseif ($action === 'update_tax_info') {
                // entity_type/subject_to_pph nempel di users (global), tapi cuma boleh
                // diubah lewat halaman ini kalau user-nya emang anggota organisasi aktif.
                $userId = (int) ($_POST['user_id'] ?? 0);
                $membershipCheck = $pdo->prepare('SELECT id FROM user_organization_roles WHERE user_id=? AND organization_id=?');
                $membershipCheck->execute([$userId, $org['organization_id']]);
                if (!$membershipCheck->fetch()) throw new RuntimeException('User bukan anggota organisasi ini.');
                $entityType = ($_POST['entity_type'] ?? '') === 'badan' ? 'badan' : 'perorangan';
                $subjectToPph = !empty($_POST['subject_to_pph']) ? 1 : 0;
                $pdo->prepare('UPDATE users SET entity_type=?, subject_to_pph=? WHERE id=?')
                    ->execute([$entityType, $subjectToPph, $userId]);
                $flash = ['ok', 'Data pajak associate diperbarui.'];
            }
        } catch (Throwable $e) {
            $flash = ['error', $e->getMessage()];
        }
    }
}

$members = $pdo->prepare(
    'SELECT uor.id AS membership_id, uor.status, uor.role_id, u.id AS user_id, u.name, u.email, r.name AS role_name,
       u.entity_type, u.subject_to_pph
     FROM user_organization_roles uor
     JOIN users u ON u.id = uor.user_id
     JOIN roles r ON r.id = uor.role_id
     WHERE uor.organization_id = ?
     ORDER BY u.name'
);
$members->execute([$org['organization_id']]);
$members = $members->fetchAll();

$roles = $pdo->prepare('SELECT id, name FROM roles WHERE organization_id = ? ORDER BY name');
$roles->execute([$org['organization_id']]);
$roles = $roles->fetchAll();
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if ($isOwner): ?>
<div class="card" style="margin-bottom:20px;">
  <h3 style="margin-top:0;">Tambah Anggota</h3>
  <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_member">
    <div class="field" style="min-width:180px;">
      <label>Email</label>
      <input type="email" name="email" required>
    </div>
    <div class="field" style="min-width:160px;">
      <label>Nama (kalau user baru)</label>
      <input type="text" name="name">
    </div>
    <div class="field" style="min-width:160px;">
      <label>Role</label>
      <select name="role_id" required>
        <?php foreach ($roles as $r): ?>
          <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="min-width:140px;">
      <label>Tipe (buat komisi)</label>
      <select name="entity_type">
        <option value="perorangan">Perorangan</option>
        <option value="badan">Badan</option>
      </select>
    </div>
    <label style="font-size:12.5px; display:flex; align-items:center; gap:6px; margin-bottom:9px;">
      <input type="checkbox" name="subject_to_pph" value="1" checked> Kena PPh 23
    </label>
    <button type="submit" class="btn">+ Tambah</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <table class="data-table">
    <thead><tr><th>Nama</th><th>Email</th><th>Role</th><th>Tipe (Komisi)</th><th>Status</th><?php if ($isOwner): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php foreach ($members as $m): ?>
        <tr>
          <td><?= htmlspecialchars($m['name']) ?></td>
          <td><?= htmlspecialchars($m['email']) ?></td>
          <td>
            <?php if ($isOwner): ?>
              <form method="post" onchange="this.submit();">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_role">
                <input type="hidden" name="membership_id" value="<?= $m['membership_id'] ?>">
                <select name="role_id">
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $r['id'] == $m['role_id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php else: ?>
              <span class="pill <?= $m['role_name'] === 'Owner' ? 'owner' : '' ?>"><?= htmlspecialchars($m['role_name']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($isOwner): ?>
              <form method="post" onchange="this.submit();" style="display:flex; gap:8px; align-items:center;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_tax_info">
                <input type="hidden" name="user_id" value="<?= $m['user_id'] ?>">
                <select name="entity_type" style="padding:4px 6px; border:1px solid var(--border); border-radius:4px; font-size:12px;">
                  <option value="perorangan" <?= $m['entity_type'] === 'perorangan' ? 'selected' : '' ?>>Perorangan</option>
                  <option value="badan" <?= $m['entity_type'] === 'badan' ? 'selected' : '' ?>>Badan</option>
                </select>
                <label style="font-size:11px; display:flex; align-items:center; gap:4px; white-space:nowrap;">
                  <input type="checkbox" name="subject_to_pph" value="1" <?= $m['subject_to_pph'] ? 'checked' : '' ?>> PPh 23
                </label>
              </form>
            <?php else: ?>
              <span class="pill"><?= $m['entity_type'] === 'badan' ? 'BADAN' : 'PERORANGAN' ?></span>
              <?php if ($m['subject_to_pph']): ?><span class="pill">PPh 23</span><?php endif; ?>
            <?php endif; ?>
          </td>
          <td><span class="pill"><?= strtoupper($m['status']) ?></span></td>
          <?php if ($isOwner): ?>
          <td>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_status">
              <input type="hidden" name="membership_id" value="<?= $m['membership_id'] ?>">
              <input type="hidden" name="status" value="<?= $m['status'] === 'active' ? 'inactive' : 'active' ?>">
              <button type="submit" class="btn btn-sm btn-ghost"><?= $m['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
