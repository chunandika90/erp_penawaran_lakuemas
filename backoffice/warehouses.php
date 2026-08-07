<?php
$pageTitle = 'Gudang';
$activeMenu = 'warehouses';
require __DIR__ . '/includes/header.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_warehouse') {
            require_module_access('kontak', 'can_create');
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException('Nama gudang wajib diisi.');
            $isDefault = !empty($_POST['is_default']) ? 1 : 0;
            if ($isDefault) {
                $pdo->prepare('UPDATE warehouses SET is_default=0 WHERE organization_id=?')->execute([$org['organization_id']]);
            }
            $pdo->prepare('INSERT INTO warehouses (organization_id, name, is_default) VALUES (?,?,?)')
                ->execute([$org['organization_id'], $name, $isDefault]);
            $flash = ['ok', 'Gudang ditambahkan.'];
        } elseif ($action === 'delete_warehouse') {
            require_module_access('kontak', 'can_delete');
            $id = (int) ($_POST['warehouse_id'] ?? 0);
            $pdo->prepare('DELETE FROM warehouses WHERE id=? AND organization_id=?')->execute([$id, $org['organization_id']]);
            $flash = ['ok', 'Gudang dihapus.'];
        }
    } catch (Throwable $e) {
        $flash = ['error', str_contains($e->getMessage(), 'foreign key') ? 'Gudang ini masih dipakai di transaksi, tidak bisa dihapus.' : $e->getMessage()];
    }
}

$warehouses = $pdo->prepare(
    'SELECT w.*, c.name AS vendor_name FROM warehouses w LEFT JOIN contacts c ON c.id=w.vendor_id
     WHERE w.organization_id=? ORDER BY w.is_default DESC, w.name'
);
$warehouses->execute([$org['organization_id']]);
$warehouses = $warehouses->fetchAll();
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if (has_access('kontak', 'can_create')): ?>
<div class="card" style="margin-bottom:20px;">
  <h3 style="margin-top:0;">Tambah Gudang</h3>
  <form method="post" style="display:flex; gap:10px; align-items:flex-end;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_warehouse">
    <div class="field" style="margin-bottom:0;"><label>Nama Gudang</label><input type="text" name="name" required></div>
    <label style="font-size:13px; display:flex; align-items:center; gap:6px;"><input type="checkbox" name="is_default"> Jadikan default</label>
    <button type="submit" class="btn">+ Tambah</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <table class="data-table">
    <thead><tr><th>Nama</th><th>Tipe</th><th>Default</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($warehouses as $w): ?>
        <tr>
          <td><?= htmlspecialchars($w['name']) ?></td>
          <td><?= $w['vendor_id'] ? '<span class="pill">VENDOR: ' . htmlspecialchars($w['vendor_name'] ?? '?') . '</span>' : '<span style="color:var(--ink-muted);">Gudang Sendiri</span>' ?></td>
          <td><?= $w['is_default'] ? '<span class="pill owner">DEFAULT</span>' : '—' ?></td>
          <td>
            <?php if (has_access('kontak', 'can_delete')): ?>
              <button class="btn btn-sm btn-ghost" type="button" onclick="if(confirm('Hapus gudang ini?')) __submitDeleteForm('delete_warehouse', {warehouse_id: <?= $w['id'] ?>})">Hapus</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$warehouses): ?><tr><td colspan="4" style="text-align:center; color:var(--ink-muted);">Belum ada gudang.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
