<?php
$pageTitle = 'Material';
$activeMenu = 'materials';
require __DIR__ . '/includes/header.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_material') {
            require_module_access('kontak', $_POST['material_id'] ? 'can_edit' : 'can_create');
            $id = (int) ($_POST['material_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $unit = trim($_POST['unit'] ?? '') ?: 'pcs';
            $defaultCost = (float) ($_POST['default_cost'] ?? 0);
            $notes = trim($_POST['notes'] ?? '') ?: null;
            if ($name === '') throw new RuntimeException('Nama material wajib diisi.');
            if ($id > 0) {
                $pdo->prepare('UPDATE materials SET name=?, unit=?, default_cost=?, notes=? WHERE id=? AND organization_id=?')->execute([$name, $unit, $defaultCost, $notes, $id, $org['organization_id']]);
                $flash = ['ok', 'Material diperbarui.'];
            } else {
                $pdo->prepare('INSERT INTO materials (organization_id, name, unit, default_cost, notes) VALUES (?,?,?,?,?)')->execute([$org['organization_id'], $name, $unit, $defaultCost, $notes]);
                $flash = ['ok', 'Material ditambahkan.'];
            }
        } elseif ($action === 'delete_material') {
            require_module_access('kontak', 'can_delete');
            $id = (int) ($_POST['material_id'] ?? 0);
            $pdo->prepare('DELETE FROM materials WHERE id=? AND organization_id=?')->execute([$id, $org['organization_id']]);
            $flash = ['ok', 'Material dihapus.'];
        } elseif ($action === 'stock_opening') {
            require_module_access('kontak', 'can_edit');
            $materialId = (int) ($_POST['material_id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $qty = (float) ($_POST['qty'] ?? 0);
            $unitCost = (float) ($_POST['unit_cost'] ?? 0);
            if (!$materialId || !$warehouseId || $qty <= 0) throw new RuntimeException('Material, gudang, dan qty wajib diisi.');
            $check = $pdo->prepare('SELECT id FROM materials WHERE id=? AND organization_id=?');
            $check->execute([$materialId, $org['organization_id']]);
            if (!$check->fetch()) throw new RuntimeException('Material tidak ditemukan.');
            $pdo->prepare('INSERT INTO stock_ledger (organization_id, warehouse_id, material_id, direction, qty, qty_remaining, unit_cost, ref_type, ref_id) VALUES (?,?,?,"in",?,?,?,"opening_balance",0)')
                ->execute([$org['organization_id'], $warehouseId, $materialId, $qty, $qty, $unitCost]);
            $flash = ['ok', 'Stok awal material dicatat.'];
        }
    } catch (Throwable $e) {
        $flash = ['error', str_contains($e->getMessage(), 'foreign key') ? 'Material ini masih dipakai di transaksi, tidak bisa dihapus.' : $e->getMessage()];
    }
}

$materials = $pdo->prepare(
    "SELECT m.*, (SELECT COALESCE(SUM(qty_remaining),0) FROM stock_ledger sl WHERE sl.material_id=m.id AND sl.direction='in') AS stock_qty
     FROM materials m WHERE m.organization_id=? ORDER BY m.name"
);
$materials->execute([$org['organization_id']]);
$materials = $materials->fetchAll();

$warehouses = $pdo->prepare('SELECT id, name FROM warehouses WHERE organization_id=? ORDER BY is_default DESC, name');
$warehouses->execute([$org['organization_id']]);
$warehouses = $warehouses->fetchAll();
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:16px;">
  <?php if (has_access('kontak', 'can_edit')): ?>
    <button class="btn btn-sm btn-ghost" data-open-modal="opening-modal">+ Stok Awal</button>
  <?php endif; ?>
  <?php if (has_access('kontak', 'can_create')): ?>
    <button class="btn btn-sm" data-open-modal="material-modal" onclick="document.getElementById('material-form').reset(); document.getElementById('material_id').value='';">+ Material Baru</button>
  <?php endif; ?>
</div>

<div class="card">
  <table class="data-table">
    <thead><tr><th>Nama</th><th>Satuan</th><th class="num">Default Cost</th><th>Stok Tersedia</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($materials as $m): ?>
        <tr>
          <td>
            <?= htmlspecialchars($m['name']) ?>
            <?php if ($m['notes']): ?><div style="font-size:11.5px; color:var(--ink-muted); margin-top:2px;"><?= htmlspecialchars($m['notes']) ?></div><?php endif; ?>
          </td>
          <td><?= htmlspecialchars($m['unit']) ?></td>
          <td class="num">Rp <?= number_format((float) $m['default_cost'], 0, ',', '.') ?></td>
          <td><?= rtrim(rtrim(number_format((float) $m['stock_qty'], 2, ',', '.'), '0'), ',') ?></td>
          <td>
            <?php if (has_access('kontak', 'can_edit')): ?>
              <button class="btn btn-sm btn-ghost" type="button" onclick="document.getElementById('material_id').value='<?= $m['id'] ?>'; document.getElementById('material-form').name.value='<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>'; document.getElementById('material-form').unit.value='<?= htmlspecialchars($m['unit'], ENT_QUOTES) ?>'; document.getElementById('material-form').default_cost.value='<?= (float) $m['default_cost'] ?>'; document.getElementById('material-form').default_cost.dispatchEvent(new Event('input')); document.getElementById('material-form').notes.value=<?= json_encode($m['notes'] ?? '') ?>; document.getElementById('material-modal').classList.add('open');">Edit</button>
            <?php endif; ?>
            <?php if (has_access('kontak', 'can_delete')): ?>
              <button class="btn btn-sm btn-ghost" type="button" onclick="if(confirm('Hapus material ini?')) __submitDeleteForm('delete_material', {material_id: <?= $m['id'] ?>})">Hapus</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$materials): ?><tr><td colspan="5" style="text-align:center; color:var(--ink-muted);">Belum ada material.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="modal-scrim" id="material-modal">
  <div class="modal-card">
    <div class="modal-head"><h3>Material</h3><button class="modal-close" data-close-modal="material-modal">&times;</button></div>
    <form method="post" id="material-form">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_material">
        <input type="hidden" name="material_id" id="material_id">
        <div class="field"><label>Nama Material</label><input type="text" name="name" required></div>
        <div class="field"><label>Satuan</label><input type="text" name="unit" value="pcs"></div>
        <div class="field"><label>Default Cost (buat prefill harga di PO)</label><input type="text" inputmode="numeric" class="rupiah-input" name="default_cost" value="0"></div>
        <div class="field"><label>Notes (cth. "dibikin pas project Butik Hotel Ubud")</label><textarea name="notes" rows="2"></textarea></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" data-close-modal="material-modal">Batal</button>
        <button type="submit" class="btn">Simpan</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-scrim" id="opening-modal">
  <div class="modal-card">
    <div class="modal-head"><h3>Catat Stok Awal Material</h3><button class="modal-close" data-close-modal="opening-modal">&times;</button></div>
    <form method="post">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="stock_opening">
        <p style="font-size:12px; color:var(--ink-muted); margin-top:0;">Buat material yang udah kamu punya sebelum pakai sistem ini (bukan dari PO).</p>
        <div class="field">
          <label>Material</label>
          <select name="material_id" required>
            <?php foreach ($materials as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Gudang</label>
          <select name="warehouse_id" required>
            <?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Qty</label><input type="number" step="0.01" name="qty" required></div>
        <div class="field"><label>Cost/unit (buat basis HPP)</label><input type="text" inputmode="numeric" class="rupiah-input" name="unit_cost" required></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" data-close-modal="opening-modal">Batal</button>
        <button type="submit" class="btn">Simpan</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
