<?php
/**
 * Master kategori produk (tree, kedalaman bebas): Group Product > Brand/Jenis
 * Item > Pecahan (LM), atau Group Product > Jenis Item aja (PG/Jewellery).
 * Nonaktifin = soft delete lewat is_active, bukan hapus baris.
 */
$pageTitle = 'Kategori Produk';
$activeMenu = 'product_categories';
require __DIR__ . '/includes/header.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_category') {
            require_module_access('kontak', $_POST['category_id'] ? 'can_edit' : 'can_create');
            $id = (int) ($_POST['category_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
            if ($name === '') throw new RuntimeException('Nama kategori wajib diisi.');
            if ($parentId) {
                $parentCheck = $pdo->prepare('SELECT id FROM product_categories WHERE id=? AND organization_id=?');
                $parentCheck->execute([$parentId, $org['organization_id']]);
                if (!$parentCheck->fetch()) throw new RuntimeException('Parent kategori tidak valid.');
            }
            if ($id > 0) {
                if ($parentId === $id) throw new RuntimeException('Kategori tidak bisa jadi parent dirinya sendiri.');
                $pdo->prepare('UPDATE product_categories SET name=?, parent_id=? WHERE id=? AND organization_id=?')
                    ->execute([$name, $parentId, $id, $org['organization_id']]);
                $flash = ['ok', 'Kategori diperbarui.'];
            } else {
                $pdo->prepare('INSERT INTO product_categories (organization_id, parent_id, name) VALUES (?,?,?)')
                    ->execute([$org['organization_id'], $parentId, $name]);
                $flash = ['ok', 'Kategori ditambahkan.'];
            }
        } elseif ($action === 'toggle_active') {
            require_module_access('kontak', 'can_edit');
            $id = (int) ($_POST['category_id'] ?? 0);
            $pdo->prepare('UPDATE product_categories SET is_active = NOT is_active WHERE id=? AND organization_id=?')
                ->execute([$id, $org['organization_id']]);
            $flash = ['ok', 'Status kategori diperbarui.'];
        }
    } catch (Throwable $e) {
        $flash = ['error', str_contains($e->getMessage(), 'foreign key') ? 'Kategori ini masih dipakai, tidak bisa diubah.' : $e->getMessage()];
    }
}

$rows = $pdo->prepare('SELECT * FROM product_categories WHERE organization_id=? ORDER BY parent_id IS NOT NULL, sort_order, name');
$rows->execute([$org['organization_id']]);
$rows = $rows->fetchAll();

$byParent = [];
foreach ($rows as $r) {
    $byParent[$r['parent_id'] ?? 0][] = $r;
}
$byId = [];
foreach ($rows as $r) $byId[$r['id']] = $r;

function render_cat_node(array $node, array $byParent, int $depth): void
{
    $children = $byParent[$node['id']] ?? [];
    $indent = $depth * 22;
    ?>
    <div class="cat-row" style="padding-left:<?= $indent ?>px;">
      <div class="cat-row-main">
        <span class="cat-name <?= $node['is_active'] ? '' : 'inactive' ?>"><?= htmlspecialchars($node['name']) ?></span>
        <?php if (!$node['is_active']): ?><span class="pill" style="margin-left:8px;">Nonaktif</span><?php endif; ?>
      </div>
      <div class="cat-row-actions">
        <?php if (has_access('kontak', 'can_create')): ?>
          <button type="button" class="btn btn-sm btn-ghost" onclick="openCatModal(0, '<?= $node['id'] ?>', '')">+ Sub</button>
        <?php endif; ?>
        <?php if (has_access('kontak', 'can_edit')): ?>
          <button type="button" class="btn btn-sm btn-ghost" onclick="openCatModal(<?= $node['id'] ?>, '<?= $node['parent_id'] ?? 0 ?>', <?= json_encode($node['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">Edit</button>
          <button type="button" class="btn btn-sm btn-ghost" onclick="if(confirm('<?= $node['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?> kategori ini?')) __submitDeleteForm('toggle_active', {category_id: <?= $node['id'] ?>})"><?= $node['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
        <?php endif; ?>
      </div>
    </div>
    <?php
    foreach ($children as $child) {
        render_cat_node($child, $byParent, $depth + 1);
    }
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<p style="color:var(--ink-muted); font-size:13px; margin-top:-8px;">
  Tree kategori produk: Group Product (level 1) → Brand / Jenis Item (level 2) → Pecahan, kalau perlu (level 3).
  Nonaktifin kategori = soft delete, data lama tetap aman.
</p>

<div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
  <?php if (has_access('kontak', 'can_create')): ?>
    <button class="btn btn-sm" type="button" onclick="openCatModal(0, 0, '')">+ Group Product Baru</button>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (!($byParent[0] ?? [])): ?>
    <div style="text-align:center; color:var(--ink-muted); padding:24px 0;">Belum ada Group Product.</div>
  <?php endif; ?>
  <?php foreach ($byParent[0] ?? [] as $root): render_cat_node($root, $byParent, 0); endforeach; ?>
</div>

<div class="modal-scrim" id="cat-modal">
  <div class="modal-card">
    <div class="modal-head"><h3 id="cat-modal-title">Kategori</h3><button class="modal-close" data-close-modal="cat-modal">&times;</button></div>
    <form method="post" id="cat-form">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_category">
        <input type="hidden" name="category_id" id="cat_id">
        <input type="hidden" name="parent_id" id="cat_parent_id">
        <div class="field" id="cat_parent_label" style="display:none;">
          <label>Parent</label>
          <input type="text" disabled id="cat_parent_name">
        </div>
        <div class="field"><label>Nama Kategori</label><input type="text" name="name" id="cat_name" required></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" data-close-modal="cat-modal">Batal</button>
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
var CAT_NAMES = <?= json_encode(array_combine(array_map(fn($r) => $r['id'], $rows), array_map(fn($r) => $r['name'], $rows)), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function openCatModal(id, parentId, name) {
  document.getElementById('cat_id').value = id || '';
  document.getElementById('cat_parent_id').value = parentId || '';
  document.getElementById('cat_name').value = name || '';
  document.getElementById('cat-modal-title').textContent = id ? 'Edit Kategori' : (parentId ? 'Tambah Sub-Kategori' : 'Tambah Group Product');
  var parentLabel = document.getElementById('cat_parent_label');
  if (parentId && CAT_NAMES[parentId]) {
    parentLabel.style.display = 'block';
    document.getElementById('cat_parent_name').value = CAT_NAMES[parentId];
  } else {
    parentLabel.style.display = 'none';
  }
  document.getElementById('cat-modal').classList.add('open');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
