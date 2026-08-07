<?php
$pageTitle = 'Karakteristik Produk';
$activeMenu = 'characteristics';
require __DIR__ . '/includes/header.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

const CHAR_TABLES = [
    'collection' => ['table' => 'product_collections', 'label' => 'Collection'],
    'item_type' => ['table' => 'product_item_types', 'label' => 'Item'],
    'finishing' => ['table' => 'product_finishings', 'label' => 'Finishing'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $entity = $_POST['entity'] ?? '';
    if (!isset(CHAR_TABLES[$entity])) {
        $flash = ['error', 'Jenis data tidak valid.'];
    } else {
        $table = CHAR_TABLES[$entity]['table'];
        try {
            if ($action === 'save_characteristic') {
                require_module_access('kontak', $_POST['item_id'] ? 'can_edit' : 'can_create');
                $id = (int) ($_POST['item_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                if ($name === '') throw new RuntimeException('Nama wajib diisi.');
                if ($id > 0) {
                    $pdo->prepare("UPDATE $table SET name=? WHERE id=? AND organization_id=?")->execute([$name, $id, $org['organization_id']]);
                    $flash = ['ok', CHAR_TABLES[$entity]['label'] . ' diperbarui.'];
                } else {
                    $pdo->prepare("INSERT INTO $table (organization_id, name) VALUES (?,?)")->execute([$org['organization_id'], $name]);
                    $flash = ['ok', CHAR_TABLES[$entity]['label'] . ' ditambahkan.'];
                }
            } elseif ($action === 'delete_characteristic') {
                require_module_access('kontak', 'can_delete');
                $id = (int) ($_POST['item_id'] ?? 0);
                $pdo->prepare("DELETE FROM $table WHERE id=? AND organization_id=?")->execute([$id, $org['organization_id']]);
                $flash = ['ok', CHAR_TABLES[$entity]['label'] . ' dihapus.'];
            }
        } catch (Throwable $e) {
            $flash = ['error', str_contains($e->getMessage(), 'Duplicate entry') ? 'Nama ini sudah ada.' : $e->getMessage()];
        }
    }
}

$lists = [];
foreach (CHAR_TABLES as $entity => $meta) {
    $stmt = $pdo->prepare("SELECT * FROM {$meta['table']} WHERE organization_id=? ORDER BY name");
    $stmt->execute([$org['organization_id']]);
    $lists[$entity] = $stmt->fetchAll();
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<p style="font-size:13px; color:var(--ink-muted); margin-top:-6px; margin-bottom:20px;">
  Master pilihan buat Collection, Item, dan Finishing yang muncul di dropdown form Produk. Material punya halaman sendiri di <a href="materials.php">Material</a>.
</p>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
  <?php foreach (CHAR_TABLES as $entity => $meta): ?>
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <h3 style="margin:0;"><?= htmlspecialchars($meta['label']) ?></h3>
        <?php if (has_access('kontak', 'can_create')): ?>
          <button class="btn btn-sm" type="button" data-open-modal="char-modal-<?= $entity ?>" onclick="document.getElementById('char-form-<?= $entity ?>').reset(); document.getElementById('char-item-id-<?= $entity ?>').value='';">+ Baru</button>
        <?php endif; ?>
      </div>
      <table class="data-table">
        <tbody>
          <?php foreach ($lists[$entity] as $it): ?>
            <tr>
              <td><?= htmlspecialchars($it['name']) ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if (has_access('kontak', 'can_edit')): ?>
                  <button class="btn btn-sm btn-ghost" type="button" onclick="document.getElementById('char-item-id-<?= $entity ?>').value='<?= $it['id'] ?>'; document.getElementById('char-form-<?= $entity ?>').name.value=<?= json_encode($it['name']) ?>; document.getElementById('char-modal-<?= $entity ?>').classList.add('open');">Edit</button>
                <?php endif; ?>
                <?php if (has_access('kontak', 'can_delete')): ?>
                  <button class="btn btn-sm btn-ghost" type="button" onclick="if(confirm('Hapus <?= htmlspecialchars($it['name'], ENT_QUOTES) ?>?')) __submitDeleteForm('delete_characteristic', {entity: '<?= $entity ?>', item_id: <?= $it['id'] ?>})">Hapus</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$lists[$entity]): ?><tr><td style="color:var(--ink-muted);">Belum ada data.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="modal-scrim" id="char-modal-<?= $entity ?>">
      <div class="modal-card">
        <div class="modal-head"><h3><?= htmlspecialchars($meta['label']) ?></h3><button class="modal-close" data-close-modal="char-modal-<?= $entity ?>">&times;</button></div>
        <form method="post" action="characteristics.php" id="char-form-<?= $entity ?>">
          <div class="modal-body">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_characteristic">
            <input type="hidden" name="entity" value="<?= $entity ?>">
            <input type="hidden" name="item_id" id="char-item-id-<?= $entity ?>">
            <div class="field"><label>Nama <?= htmlspecialchars($meta['label']) ?></label><input type="text" name="name" required></div>
          </div>
          <div class="modal-foot">
            <button type="button" class="btn btn-ghost" data-close-modal="char-modal-<?= $entity ?>">Batal</button>
            <button type="submit" class="btn">Simpan</button>
          </div>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
