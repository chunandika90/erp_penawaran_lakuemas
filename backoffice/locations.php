<?php
/**
 * Master lokasi (tree 2 tingkat): Group Location > Location.
 * Nonaktifin = soft delete lewat is_active, bukan hapus baris.
 */
$pageTitle = 'Lokasi';
$activeMenu = 'locations';
require __DIR__ . '/includes/header.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_location') {
            require_module_access('kontak', $_POST['location_id'] ? 'can_edit' : 'can_create');
            $id = (int) ($_POST['location_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
            if ($name === '') throw new RuntimeException('Nama lokasi wajib diisi.');
            if ($parentId) {
                $parentCheck = $pdo->prepare('SELECT id FROM locations WHERE id=? AND organization_id=? AND parent_id IS NULL');
                $parentCheck->execute([$parentId, $org['organization_id']]);
                if (!$parentCheck->fetch()) throw new RuntimeException('Group Location tidak valid (cuma boleh 2 tingkat).');
            }
            if ($id > 0) {
                if ($parentId === $id) throw new RuntimeException('Lokasi tidak bisa jadi parent dirinya sendiri.');
                $pdo->prepare('UPDATE locations SET name=?, parent_id=? WHERE id=? AND organization_id=?')
                    ->execute([$name, $parentId, $id, $org['organization_id']]);
                $flash = ['ok', 'Lokasi diperbarui.'];
            } else {
                $pdo->prepare('INSERT INTO locations (organization_id, parent_id, name) VALUES (?,?,?)')
                    ->execute([$org['organization_id'], $parentId, $name]);
                $flash = ['ok', 'Lokasi ditambahkan.'];
            }
        } elseif ($action === 'toggle_active') {
            require_module_access('kontak', 'can_edit');
            $id = (int) ($_POST['location_id'] ?? 0);
            $pdo->prepare('UPDATE locations SET is_active = NOT is_active WHERE id=? AND organization_id=?')
                ->execute([$id, $org['organization_id']]);
            $flash = ['ok', 'Status lokasi diperbarui.'];
        }
    } catch (Throwable $e) {
        $flash = ['error', str_contains($e->getMessage(), 'foreign key') ? 'Lokasi ini masih dipakai, tidak bisa diubah.' : $e->getMessage()];
    }
}

$rows = $pdo->prepare('SELECT * FROM locations WHERE organization_id=? ORDER BY parent_id IS NOT NULL, sort_order, name');
$rows->execute([$org['organization_id']]);
$rows = $rows->fetchAll();

$byParent = [];
foreach ($rows as $r) $byParent[$r['parent_id'] ?? 0][] = $r;
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<p style="color:var(--ink-muted); font-size:13px; margin-top:-8px;">
  Tree lokasi: Group Location (misal "Toko Pusat", "Gudang Utama") → Location (misal "Etalase A", "Brankas 1"). Nonaktifin = soft delete.
</p>

<div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
  <?php if (has_access('kontak', 'can_create')): ?>
    <button class="btn btn-sm" type="button" onclick="openLocModal(0, 0, '')">+ Group Location Baru</button>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (!($byParent[0] ?? [])): ?>
    <div style="text-align:center; color:var(--ink-muted); padding:24px 0;">Belum ada Group Location.</div>
  <?php endif; ?>
  <?php foreach ($byParent[0] ?? [] as $root): ?>
    <div class="cat-row">
      <div class="cat-row-main">
        <span class="cat-name <?= $root['is_active'] ? '' : 'inactive' ?>"><?= htmlspecialchars($root['name']) ?></span>
        <?php if (!$root['is_active']): ?><span class="pill" style="margin-left:8px;">Nonaktif</span><?php endif; ?>
      </div>
      <div class="cat-row-actions">
        <?php if (has_access('kontak', 'can_create')): ?>
          <button type="button" class="btn btn-sm btn-ghost" onclick="openLocModal(0, <?= $root['id'] ?>, '')">+ Location</button>
        <?php endif; ?>
        <?php if (has_access('kontak', 'can_edit')): ?>
          <button type="button" class="btn btn-sm btn-ghost" onclick="openLocModal(<?= $root['id'] ?>, 0, <?= json_encode($root['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">Edit</button>
          <button type="button" class="btn btn-sm btn-ghost" onclick="if(confirm('<?= $root['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?> lokasi ini?')) __submitDeleteForm('toggle_active', {location_id: <?= $root['id'] ?>})"><?= $root['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
        <?php endif; ?>
      </div>
    </div>
    <?php foreach ($byParent[$root['id']] ?? [] as $child): ?>
      <div class="cat-row" style="padding-left:22px;">
        <div class="cat-row-main">
          <span class="cat-name <?= $child['is_active'] ? '' : 'inactive' ?>"><?= htmlspecialchars($child['name']) ?></span>
          <?php if (!$child['is_active']): ?><span class="pill" style="margin-left:8px;">Nonaktif</span><?php endif; ?>
        </div>
        <div class="cat-row-actions">
          <?php if (has_access('kontak', 'can_edit')): ?>
            <button type="button" class="btn btn-sm btn-ghost" onclick="openLocModal(<?= $child['id'] ?>, <?= $root['id'] ?>, <?= json_encode($child['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">Edit</button>
            <button type="button" class="btn btn-sm btn-ghost" onclick="if(confirm('<?= $child['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?> lokasi ini?')) __submitDeleteForm('toggle_active', {location_id: <?= $child['id'] ?>})"><?= $child['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>

<div class="modal-scrim" id="loc-modal">
  <div class="modal-card">
    <div class="modal-head"><h3 id="loc-modal-title">Lokasi</h3><button class="modal-close" data-close-modal="loc-modal">&times;</button></div>
    <form method="post" id="loc-form">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_location">
        <input type="hidden" name="location_id" id="loc_id">
        <input type="hidden" name="parent_id" id="loc_parent_id">
        <div class="field" id="loc_parent_label" style="display:none;">
          <label>Group Location</label>
          <input type="text" disabled id="loc_parent_name">
        </div>
        <div class="field"><label>Nama Lokasi</label><input type="text" name="name" id="loc_name" required></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" data-close-modal="loc-modal">Batal</button>
        <button type="submit" class="btn">Simpan</button>
      </div>
    </form>
  </div>
</div>

<style>
.cat-row { display:flex; align-items:center; justify-content:space-between; padding:9px 0; border-bottom:1px solid var(--border-hair); gap:12px; }
.cat-row:last-child { border-bottom:none; }
.cat-name { font-size:13.5px; font-weight:600; }
.cat-name.inactive { color: var(--ink-faint); text-decoration: line-through; }
.cat-row-actions { display:flex; gap:6px; flex-shrink:0; }
</style>

<script>
var LOC_NAMES = <?= json_encode(array_combine(array_map(fn($r) => $r['id'], $rows), array_map(fn($r) => $r['name'], $rows)), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function openLocModal(id, parentId, name) {
  document.getElementById('loc_id').value = id || '';
  document.getElementById('loc_parent_id').value = parentId || '';
  document.getElementById('loc_name').value = name || '';
  document.getElementById('loc-modal-title').textContent = id ? 'Edit Lokasi' : (parentId ? 'Tambah Location' : 'Tambah Group Location');
  var parentLabel = document.getElementById('loc_parent_label');
  if (parentId && LOC_NAMES[parentId]) {
    parentLabel.style.display = 'block';
    document.getElementById('loc_parent_name').value = LOC_NAMES[parentId];
  } else {
    parentLabel.style.display = 'none';
  }
  document.getElementById('loc-modal').classList.add('open');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
