<?php
$pageTitle = 'Kontak';
$activeMenu = 'kontak';
require __DIR__ . '/includes/header.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_contact') {
            require_module_access('kontak', $_POST['contact_id'] ? 'can_edit' : 'can_create');
            $id = (int) ($_POST['contact_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? 'customer';
            if ($name === '') throw new RuntimeException('Nama kontak wajib diisi.');
            if (!in_array($type, ['customer', 'vendor', 'both'], true)) $type = 'customer';
            $phone = trim($_POST['phone'] ?? '') ?: null;
            $email = trim($_POST['email'] ?? '') ?: null;
            $address = trim($_POST['address'] ?? '') ?: null;
            $npwp = trim($_POST['npwp'] ?? '') ?: null;

            if ($id > 0) {
                $pdo->prepare('UPDATE contacts SET name=?, type=?, phone=?, email=?, address=?, npwp=? WHERE id=? AND organization_id=?')
                    ->execute([$name, $type, $phone, $email, $address, $npwp, $id, $org['organization_id']]);
                $flash = ['ok', 'Kontak diperbarui.'];
            } else {
                $pdo->prepare('INSERT INTO contacts (organization_id, type, name, phone, email, address, npwp) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$org['organization_id'], $type, $name, $phone, $email, $address, $npwp]);
                $flash = ['ok', 'Kontak ditambahkan.'];
            }
        } elseif ($action === 'delete_contact') {
            require_module_access('kontak', 'can_delete');
            $id = (int) ($_POST['contact_id'] ?? 0);
            $pdo->prepare('DELETE FROM contacts WHERE id=? AND organization_id=?')->execute([$id, $org['organization_id']]);
            $flash = ['ok', 'Kontak dihapus.'];
        }
    } catch (Throwable $e) {
        $flash = ['error', str_contains($e->getMessage(), 'foreign key') ? 'Kontak ini masih dipakai di transaksi, tidak bisa dihapus.' : $e->getMessage()];
    }
}

$filter = $_GET['type'] ?? '';
$sql = 'SELECT * FROM contacts WHERE organization_id = ?';
$params = [$org['organization_id']];
if (in_array($filter, ['customer', 'vendor', 'both'], true)) {
    $sql .= ' AND type = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contacts = $stmt->fetchAll();

$editingContact = null;
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM contacts WHERE id=? AND organization_id=?');
    $stmt->execute([(int) $_GET['edit'], $org['organization_id']]);
    $editingContact = $stmt->fetch() ?: null;
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
  <div style="display:flex; gap:8px;">
    <a class="btn btn-sm <?= $filter === '' ? '' : 'btn-ghost' ?>" href="contacts.php">Semua</a>
    <a class="btn btn-sm <?= $filter === 'customer' ? '' : 'btn-ghost' ?>" href="contacts.php?type=customer">Customer</a>
    <a class="btn btn-sm <?= $filter === 'vendor' ? '' : 'btn-ghost' ?>" href="contacts.php?type=vendor">Vendor</a>
  </div>
  <?php if (has_access('kontak', 'can_create')): ?>
    <button class="btn btn-sm" data-open-modal="contact-modal" onclick="document.getElementById('contact-form').reset(); document.getElementById('contact_id').value=''; document.getElementById('contact-modal-title').textContent='Tambah Kontak';">+ Tambah Kontak</button>
  <?php endif; ?>
</div>

<div class="card">
  <table class="data-table">
    <thead><tr><th>Nama</th><th>Tipe</th><th>Telp</th><th>Email</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($contacts as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['name']) ?></td>
          <td><span class="pill"><?= strtoupper($c['type']) ?></span></td>
          <td><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
          <td><?= htmlspecialchars($c['email'] ?: '—') ?></td>
          <td>
            <?php if (has_access('kontak', 'can_edit')): ?>
              <a class="btn btn-sm btn-ghost" href="contacts.php?edit=<?= $c['id'] ?>#contact-modal">Edit</a>
            <?php endif; ?>
            <?php if (has_access('kontak', 'can_delete')): ?>
              <button class="btn btn-sm btn-ghost" type="button" onclick="if(confirm('Hapus kontak ini?')) __submitDeleteForm('delete_contact', {contact_id: <?= $c['id'] ?>})">Hapus</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$contacts): ?><tr><td colspan="5" style="text-align:center; color:var(--ink-muted);">Belum ada kontak.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="modal-scrim<?= $editingContact ? ' open' : '' ?>" id="contact-modal">
  <div class="modal-card">
    <div class="modal-head">
      <h3 id="contact-modal-title"><?= $editingContact ? 'Edit Kontak' : 'Tambah Kontak' ?></h3>
      <button class="modal-close" data-close-modal="contact-modal">&times;</button>
    </div>
    <form method="post" id="contact-form">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_contact">
        <input type="hidden" name="contact_id" id="contact_id" value="<?= $editingContact['id'] ?? '' ?>">
        <div class="field"><label>Nama</label><input type="text" name="name" required value="<?= htmlspecialchars($editingContact['name'] ?? '') ?>"></div>
        <div class="field">
          <label>Tipe</label>
          <select name="type">
            <option value="customer" <?= ($editingContact['type'] ?? '') === 'customer' ? 'selected' : '' ?>>Customer</option>
            <option value="vendor" <?= ($editingContact['type'] ?? '') === 'vendor' ? 'selected' : '' ?>>Vendor</option>
            <option value="both" <?= ($editingContact['type'] ?? '') === 'both' ? 'selected' : '' ?>>Customer &amp; Vendor</option>
          </select>
        </div>
        <div class="field"><label>Telepon</label><input type="text" name="phone" value="<?= htmlspecialchars($editingContact['phone'] ?? '') ?>"></div>
        <div class="field"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($editingContact['email'] ?? '') ?>"></div>
        <div class="field"><label>NPWP</label><input type="text" name="npwp" value="<?= htmlspecialchars($editingContact['npwp'] ?? '') ?>"></div>
        <div class="field"><label>Alamat</label><input type="text" name="address" value="<?= htmlspecialchars($editingContact['address'] ?? '') ?>"></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" data-close-modal="contact-modal">Batal</button>
        <button type="submit" class="btn">Simpan</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
