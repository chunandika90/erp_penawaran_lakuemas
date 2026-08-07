<?php
/**
 * Master tipe stock (flat, gak tree) — "Ready Stock (Jual)", "Resell",
 * "Gold Priority", dst. is_sellable nandain apakah tipe ini defaultnya
 * boleh dijual atau enggak. Nonaktifin = soft delete.
 */
$pageTitle = 'Tipe Stock';
$activeMenu = 'stock_types';
require __DIR__ . '/includes/header.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_type') {
            require_module_access('kontak', $_POST['type_id'] ? 'can_edit' : 'can_create');
            $id = (int) ($_POST['type_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $isSellable = isset($_POST['is_sellable']) ? 1 : 0;
            if ($name === '') throw new RuntimeException('Nama tipe stock wajib diisi.');
            if ($id > 0) {
                $pdo->prepare('UPDATE stock_types SET name=?, is_sellable=? WHERE id=? AND organization_id=?')
                    ->execute([$name, $isSellable, $id, $org['organization_id']]);
                $flash = ['ok', 'Tipe stock diperbarui.'];
            } else {
                $pdo->prepare('INSERT INTO stock_types (organization_id, name, is_sellable) VALUES (?,?,?)')
                    ->execute([$org['organization_id'], $name, $isSellable]);
                $flash = ['ok', 'Tipe stock ditambahkan.'];
            }
        } elseif ($action === 'toggle_active') {
            require_module_access('kontak', 'can_edit');
            $id = (int) ($_POST['type_id'] ?? 0);
            $pdo->prepare('UPDATE stock_types SET is_active = NOT is_active WHERE id=? AND organization_id=?')
                ->execute([$id, $org['organization_id']]);
            $flash = ['ok', 'Status tipe stock diperbarui.'];
        }
    } catch (Throwable $e) {
        $flash = ['error', str_contains($e->getMessage(), 'foreign key') ? 'Tipe stock ini masih dipakai, tidak bisa diubah.' : $e->getMessage()];
    }
}

$types = $pdo->prepare('SELECT * FROM stock_types WHERE organization_id=? ORDER BY sort_order, name');
$types->execute([$org['organization_id']]);
$types = $types->fetchAll();
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<p style="color:var(--ink-muted); font-size:13px; margin-top:-8px;">
  Tipe stock dipakai buat nandain status jual barang (ready stock, resell, gold priority, dll). "Bisa dijual" nentuin default tampil/enggak di transaksi penjualan.
</p>

<div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
  <?php if (has_access('kontak', 'can_create')): ?>
    <button class="btn btn-sm" type="button" onclick="openTypeModal(0, '', true)">+ Tipe Stock Baru</button>
  <?php endif; ?>
</div>

<div class="card">
  <table class="data-table">
    <thead><tr><th>Nama</th><th>Bisa Dijual</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($types as $t): ?>
        <tr>
          <td><?= htmlspecialchars($t['name']) ?></td>
          <td><?= $t['is_sellable'] ? 'Ya' : 'Tidak' ?></td>
          <td><span class="pill <?= $t['is_active'] ? 'active' : '' ?>"><?= $t['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td>
            <?php if (has_access('kontak', 'can_edit')): ?>
              <button class="btn btn-sm btn-ghost" type="button" onclick="openTypeModal(<?= $t['id'] ?>, <?= json_encode($t['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= $t['is_sellable'] ? 'true' : 'false' ?>)">Edit</button>
              <button class="btn btn-sm btn-ghost" type="button" onclick="if(confirm('<?= $t['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?> tipe stock ini?')) __submitDeleteForm('toggle_active', {type_id: <?= $t['id'] ?>})"><?= $t['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$types): ?><tr><td colspan="4" style="text-align:center; color:var(--ink-muted);">Belum ada tipe stock.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="modal-scrim" id="type-modal">
  <div class="modal-card">
    <div class="modal-head"><h3 id="type-modal-title">Tipe Stock</h3><button class="modal-close" data-close-modal="type-modal">&times;</button></div>
    <form method="post" id="type-form">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_type">
        <input type="hidden" name="type_id" id="type_id">
        <div class="field"><label>Nama Tipe Stock</label><input type="text" name="name" id="type_name" required></div>
        <div class="field"><label><input type="checkbox" name="is_sellable" id="type_is_sellable" style="width:auto; margin-right:6px;">Bisa dijual (default)</label></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" data-close-modal="type-modal">Batal</button>
        <button type="submit" class="btn">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
function openTypeModal(id, name, isSellable) {
  document.getElementById('type_id').value = id || '';
  document.getElementById('type_name').value = name || '';
  document.getElementById('type_is_sellable').checked = !!isSellable;
  document.getElementById('type-modal-title').textContent = id ? 'Edit Tipe Stock' : 'Tipe Stock Baru';
  document.getElementById('type-modal').classList.add('open');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
