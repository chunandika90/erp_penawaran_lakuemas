<?php
$pageTitle = 'Produk & Tier';
$activeMenu = 'products';
require __DIR__ . '/includes/header.php';
require_module_access('kontak'); // master data produk digabung ke izin modul Kontak
require_once __DIR__ . '/../backoffice-shared/image_upload.php';

$pdo = db();
$flash = null;
const TIER_LEVELS = ['ekonomis' => 'Ekonomis', 'standard' => 'Standard', 'premium' => 'Premium', 'deluxe' => 'Deluxe', 'bespoke' => 'Bespoke'];
const DIMENSION_FIELDS = [
    'panjang' => 'Panjang', 'lebar' => 'Lebar', 'tinggi' => 'Tinggi',
    'tinggi_dudukan' => 'Tinggi Dudukan', 'tinggi_lengan' => 'Tinggi Lengan',
    'tinggi_sandaran' => 'Tinggi Sandaran', 'tinggi_kaki' => 'Tinggi Kaki',
];

function find_or_create_material(PDO $pdo, int $orgId, string $name, float $defaultCost = 0): int
{
    $name = trim($name);
    $stmt = $pdo->prepare('SELECT id FROM materials WHERE organization_id=? AND name=?');
    $stmt->execute([$orgId, $name]);
    $row = $stmt->fetch();
    if ($row) return (int) $row['id'];
    $pdo->prepare('INSERT INTO materials (organization_id, name, default_cost) VALUES (?,?,?)')->execute([$orgId, $name, $defaultCost]);
    return (int) $pdo->lastInsertId();
}

// Kalau user ngetik value baru di combobox Collection/Item/Finishing (bukan milih dari
// dropdown yang udah ada), otomatis nambahin ke master table biar muncul di pilihan berikutnya.
function find_or_create_characteristic(PDO $pdo, int $orgId, string $table, ?string $name): void
{
    $name = trim((string) $name);
    if ($name === '') return;
    $stmt = $pdo->prepare("SELECT id FROM $table WHERE organization_id=? AND name=?");
    $stmt->execute([$orgId, $name]);
    if ($stmt->fetch()) return;
    $pdo->prepare("INSERT INTO $table (organization_id, name) VALUES (?,?)")->execute([$orgId, $name]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_product') {
            require_module_access('kontak', $_POST['product_id'] ? 'can_edit' : 'can_create');
            $id = (int) ($_POST['product_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $unit = trim($_POST['unit'] ?? '') ?: 'pcs';
            $material = trim($_POST['material'] ?? '') ?: null;
            $itemType = trim($_POST['item_type'] ?? '') ?: null;
            $collection = trim($_POST['collection'] ?? '') ?: null;
            $finishing = trim($_POST['finishing'] ?? '') ?: null;
            $size = trim($_POST['size'] ?? '') ?: null;
            $dimensionFields = ['panjang', 'lebar', 'tinggi', 'tinggi_dudukan', 'tinggi_lengan', 'tinggi_sandaran', 'tinggi_kaki'];
            $dimensions = [];
            foreach ($dimensionFields as $df) {
                $v = trim($_POST[$df] ?? '');
                $dimensions[$df] = $v === '' ? null : (float) $v;
            }
            $extraLabels = $_POST['extra_label'] ?? [];
            $extraValues = $_POST['extra_value'] ?? [];
            $extraSpecs = [];
            foreach ($extraLabels as $i => $label) {
                $label = trim($label);
                if ($label === '') continue;
                $extraSpecs[] = ['label' => $label, 'value' => trim($extraValues[$i] ?? '')];
            }
            if ($name === '') throw new RuntimeException('Nama produk wajib diisi.');

            find_or_create_characteristic($pdo, $org['organization_id'], 'product_collections', $collection);
            find_or_create_characteristic($pdo, $org['organization_id'], 'product_item_types', $itemType);
            find_or_create_characteristic($pdo, $org['organization_id'], 'product_finishings', $finishing);
            if ($material !== null) find_or_create_material($pdo, $org['organization_id'], $material);

            if ($id > 0) {
                $pdo->prepare('UPDATE products SET name=?, unit=?, material=?, item_type=?, collection=?, finishing=?, size=?, panjang=?, lebar=?, tinggi=?, tinggi_dudukan=?, tinggi_lengan=?, tinggi_sandaran=?, tinggi_kaki=?, extra_specs=? WHERE id=? AND organization_id=?')
                    ->execute([$name, $unit, $material, $itemType, $collection, $finishing, $size,
                        $dimensions['panjang'], $dimensions['lebar'], $dimensions['tinggi'], $dimensions['tinggi_dudukan'], $dimensions['tinggi_lengan'], $dimensions['tinggi_sandaran'], $dimensions['tinggi_kaki'],
                        json_encode($extraSpecs), $id, $org['organization_id']]);
                $flash = ['ok', 'Produk diperbarui.'];
            } else {
                $pdo->prepare('INSERT INTO products (organization_id, name, unit, material, item_type, collection, finishing, size, panjang, lebar, tinggi, tinggi_dudukan, tinggi_lengan, tinggi_sandaran, tinggi_kaki, extra_specs) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$org['organization_id'], $name, $unit, $material, $itemType, $collection, $finishing, $size,
                        $dimensions['panjang'], $dimensions['lebar'], $dimensions['tinggi'], $dimensions['tinggi_dudukan'], $dimensions['tinggi_lengan'], $dimensions['tinggi_sandaran'], $dimensions['tinggi_kaki'],
                        json_encode($extraSpecs)]);
                $id = (int) $pdo->lastInsertId();
                $flash = ['ok', 'Produk ditambahkan.'];
            }

            if (!empty($_FILES['photo']['name'])) {
                $newPath = save_resized_product_photo($_FILES['photo']);
                $oldPhoto = $pdo->prepare('SELECT photo_path FROM products WHERE id=?');
                $oldPhoto->execute([$id]);
                $oldPath = $oldPhoto->fetchColumn();
                $pdo->prepare('UPDATE products SET photo_path=? WHERE id=? AND organization_id=?')->execute([$newPath, $id, $org['organization_id']]);
                delete_product_photo($oldPath ?: null);
            }

            header('Location: products.php?product_id=' . $id);
            exit;
        } elseif ($action === 'delete_product') {
            require_module_access('kontak', 'can_delete');
            $id = (int) ($_POST['product_id'] ?? 0);
            $photoStmt = $pdo->prepare('SELECT photo_path FROM products WHERE id=? AND organization_id=?');
            $photoStmt->execute([$id, $org['organization_id']]);
            $photoToDelete = $photoStmt->fetchColumn();
            $pdo->prepare('DELETE FROM products WHERE id=? AND organization_id=?')->execute([$id, $org['organization_id']]);
            delete_product_photo($photoToDelete ?: null);
            $flash = ['ok', 'Produk dihapus.'];
        } elseif ($action === 'remove_product_photo') {
            require_module_access('kontak', 'can_edit');
            $id = (int) ($_POST['product_id'] ?? 0);
            $photoStmt = $pdo->prepare('SELECT photo_path FROM products WHERE id=? AND organization_id=?');
            $photoStmt->execute([$id, $org['organization_id']]);
            $photoToDelete = $photoStmt->fetchColumn();
            $pdo->prepare('UPDATE products SET photo_path=NULL WHERE id=? AND organization_id=?')->execute([$id, $org['organization_id']]);
            delete_product_photo($photoToDelete ?: null);
            $flash = ['ok', 'Foto produk dihapus.'];
            header('Location: products.php?product_id=' . $id);
            exit;
        } elseif ($action === 'save_tier') {
            require_module_access('kontak', 'can_edit');
            $productId = (int) ($_POST['product_id'] ?? 0);
            $tierLevel = $_POST['tier_level'] ?? '';
            $price = (float) ($_POST['price'] ?? 0);
            if (!isset(TIER_LEVELS[$tierLevel])) throw new RuntimeException('Tier tidak valid.');

            $bomMaterialNames = $_POST['bom_material'] ?? [];
            $bomQtys = $_POST['bom_qty'] ?? [];
            $bomCosts = $_POST['bom_cost'] ?? [];
            $bom = [];
            foreach ($bomMaterialNames as $i => $matName) {
                $matName = trim($matName);
                if ($matName === '') continue;
                $bomCost = (float) ($bomCosts[$i] ?? 0);
                $materialId = find_or_create_material($pdo, $org['organization_id'], $matName, $bomCost);
                $bom[] = ['material_id' => $materialId, 'material_name' => $matName, 'qty' => (float) ($bomQtys[$i] ?? 0), 'cost' => $bomCost];
            }

            // Tier immutable begitu udah kepakai transaksi — kalau masih fresh, update in-place;
            // kalau udah kepakai, bikin version baru (dokumen lama tetap valid ke version lama).
            $check = $pdo->prepare('SELECT pt.id, pt.version FROM product_tiers pt WHERE pt.product_id=? AND pt.tier_level=? AND pt.is_active=1 ORDER BY pt.version DESC LIMIT 1');
            $check->execute([$productId, $tierLevel]);
            $current = $check->fetch();

            $isUsed = false;
            if ($current) {
                $usedCheck = $pdo->prepare('SELECT COUNT(*) c FROM quotation_lines WHERE tier_id=?');
                $usedCheck->execute([$current['id']]);
                $isUsed = (int) $usedCheck->fetch()['c'] > 0;
            }

            if ($current && !$isUsed) {
                $pdo->prepare('UPDATE product_tiers SET price=?, bom_json=? WHERE id=?')->execute([$price, json_encode($bom), $current['id']]);
                $flash = ['ok', 'Tier diperbarui.'];
            } else {
                $nextVersion = $current ? $current['version'] + 1 : 1;
                if ($current) $pdo->prepare('UPDATE product_tiers SET is_active=0 WHERE id=?')->execute([$current['id']]);
                $pdo->prepare('INSERT INTO product_tiers (product_id, tier_level, version, price, bom_json, is_active) VALUES (?,?,?,?,?,1)')
                    ->execute([$productId, $tierLevel, $nextVersion, $price, json_encode($bom)]);
                $flash = ['ok', $isUsed ? "Tier sudah dipakai transaksi — dibuat version baru (v$nextVersion)." : 'Tier dibuat.'];
            }
            header('Location: products.php?product_id=' . $productId);
            exit;
        }
    } catch (Throwable $e) {
        $flash = ['error', $e->getMessage()];
    }
}

$search = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM products WHERE organization_id=?';
$params = [$org['organization_id']];
if ($search !== '') { $sql .= ' AND name LIKE ?'; $params[] = '%' . $search . '%'; }
$sql .= ' ORDER BY name, id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$materialsList = $pdo->prepare('SELECT id, name, unit, default_cost FROM materials WHERE organization_id=? ORDER BY name');
$materialsList->execute([$org['organization_id']]);
$materialsList = $materialsList->fetchAll();

$collectionsList = $pdo->prepare('SELECT name FROM product_collections WHERE organization_id=? ORDER BY name');
$collectionsList->execute([$org['organization_id']]);
$collectionsList = array_column($collectionsList->fetchAll(), 'name');

$itemTypesList = $pdo->prepare('SELECT name FROM product_item_types WHERE organization_id=? ORDER BY name');
$itemTypesList->execute([$org['organization_id']]);
$itemTypesList = array_column($itemTypesList->fetchAll(), 'name');

$finishingsList = $pdo->prepare('SELECT name FROM product_finishings WHERE organization_id=? ORDER BY name');
$finishingsList->execute([$org['organization_id']]);
$finishingsList = array_column($finishingsList->fetchAll(), 'name');

$editingProductId = (int) ($_GET['product_id'] ?? 0);
$editingProduct = null;
$tiers = [];
if ($editingProductId) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id=? AND organization_id=?');
    $stmt->execute([$editingProductId, $org['organization_id']]);
    $editingProduct = $stmt->fetch() ?: null;
    if ($editingProduct) {
        $t = $pdo->prepare('SELECT * FROM product_tiers WHERE product_id=? AND is_active=1');
        $t->execute([$editingProductId]);
        foreach ($t->fetchAll() as $row) $tiers[$row['tier_level']] = $row;
    }
}
$extraSpecs = $editingProduct ? (json_decode($editingProduct['extra_specs'] ?? '[]', true) ?: []) : [];
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<div class="txn-shell">
  <div class="txn-rail">
    <div class="txn-rail-month">
      <form method="get" style="display:flex; gap:6px; flex:1;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari produk..." style="flex:1; padding:6px 8px; border:1px solid var(--border); border-radius:4px; font-size:12px;">
      </form>
    </div>
    <div class="txn-rail-list">
      <?php $lastName = null; foreach ($products as $p): ?>
        <a class="txn-rail-item <?= $editingProductId === (int) $p['id'] ? 'active' : '' ?>" href="products.php?product_id=<?= $p['id'] ?>" style="display:flex; gap:10px; align-items:center;">
          <?php if ($p['photo_path']): ?>
            <img src="<?= htmlspecialchars($p['photo_path']) ?>" alt="" style="width:36px; height:36px; object-fit:cover; border-radius:6px; flex-shrink:0;">
          <?php else: ?>
            <div style="width:36px; height:36px; border-radius:6px; background:oklch(0.94 0.003 90); flex-shrink:0;"></div>
          <?php endif; ?>
          <div style="min-width:0;">
            <div class="doc"><?= htmlspecialchars($p['name']) ?></div>
            <div class="sub"><?= htmlspecialchars(trim($p['material'] ?? '') ?: 'Belum ada spec') ?></div>
          </div>
        </a>
      <?php endforeach; ?>
      <?php if (!$products): ?><div style="padding:14px; font-size:12px; color:var(--ink-muted);">Belum ada produk.</div><?php endif; ?>
    </div>
  </div>

  <div class="txn-detail">
    <div class="txn-detail-header">
      <div></div>
      <?php if (has_access('kontak', 'can_create')): ?>
        <a class="btn btn-sm btn-ghost" href="products.php">+ Produk Baru</a>
      <?php endif; ?>
    </div>

    <?php if (!$editingProduct): ?>
      <div class="card txn-empty">Pilih produk di kiri, atau buat produk baru.</div>
      <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Produk Baru</h3>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_product">
          <div class="field"><label>Nama Produk</label><input type="text" name="name" required></div>
          <div class="field-row">
            <div class="field"><label>Material</label><input type="text" name="material" class="combo-material-spec" autocomplete="off"></div>
            <div class="field"><label>Item</label><input type="text" name="item_type" class="combo-item" autocomplete="off"></div>
          </div>
          <div class="field-row">
            <div class="field"><label>Collection</label><input type="text" name="collection" class="combo-collection" autocomplete="off"></div>
            <div class="field"><label>Finishing</label><input type="text" name="finishing" class="combo-finishing" autocomplete="off"></div>
          </div>
          <div class="field-row">
            <div class="field"><label>Ukuran (bebas, cth. "60x60x45cm")</label><input type="text" name="size"></div>
            <div class="field"><label>Satuan</label><input type="text" name="unit" value="pcs"></div>
          </div>
          <label style="display:block; font-size:12px; font-weight:600; margin:10px 0 6px;">Dimensi (mm)</label>
          <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:10px 12px; margin-bottom:14px;">
            <?php foreach (DIMENSION_FIELDS as $df => $dl): ?>
              <div class="field" style="margin-bottom:0;"><label style="font-size:12px; font-weight:500;"><?= htmlspecialchars($dl) ?></label><input type="number" step="0.01" name="<?= $df ?>"></div>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="btn">Simpan Produk</button>
        </form>
      </div>
    <?php else: ?>

      <div class="card" style="margin-bottom:16px;">
        <div class="txn-detail-header">
          <h2><?= htmlspecialchars($editingProduct['name']) ?></h2>
          <?php if (has_access('kontak', 'can_delete')): ?>
            <button class="btn btn-sm btn-ghost" type="button" onclick="if(confirm('Hapus produk ini beserta semua tier-nya?')) __submitDeleteForm('delete_product', {product_id: <?= $editingProduct['id'] ?>})">Hapus Produk</button>
          <?php endif; ?>
        </div>
        <?php if (has_access('kontak', 'can_edit')): ?>
        <form method="post" id="spec-form" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_product">
          <input type="hidden" name="product_id" value="<?= $editingProduct['id'] ?>">

          <div class="field">
            <label>Foto Produk</label>
            <div style="display:flex; align-items:center; gap:14px;">
              <?php if ($editingProduct['photo_path']): ?>
                <img src="<?= htmlspecialchars($editingProduct['photo_path']) ?>" alt="" style="width:90px; height:90px; object-fit:cover; border-radius:8px; border:1px solid var(--border);">
              <?php else: ?>
                <div style="width:90px; height:90px; border-radius:8px; border:1px dashed var(--border-strong); display:flex; align-items:center; justify-content:center; font-size:11px; color:var(--ink-muted); text-align:center;">Belum ada foto</div>
              <?php endif; ?>
              <div>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                <?php if ($editingProduct['photo_path']): ?>
                  <div style="margin-top:6px;"><button type="button" class="btn btn-sm btn-ghost" onclick="if(confirm('Hapus foto produk ini?')) __submitDeleteForm('remove_product_photo', {product_id: <?= $editingProduct['id'] ?>})">Hapus Foto</button></div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="field"><label>Nama Produk</label><input type="text" name="name" value="<?= htmlspecialchars($editingProduct['name']) ?>" required></div>
          <div class="field-row">
            <div class="field"><label>Material</label><input type="text" name="material" value="<?= htmlspecialchars($editingProduct['material'] ?? '') ?>" class="combo-material-spec" autocomplete="off"></div>
            <div class="field"><label>Item</label><input type="text" name="item_type" value="<?= htmlspecialchars($editingProduct['item_type'] ?? '') ?>" class="combo-item" autocomplete="off"></div>
          </div>
          <div class="field-row">
            <div class="field"><label>Collection</label><input type="text" name="collection" value="<?= htmlspecialchars($editingProduct['collection'] ?? '') ?>" class="combo-collection" autocomplete="off"></div>
            <div class="field"><label>Finishing</label><input type="text" name="finishing" value="<?= htmlspecialchars($editingProduct['finishing'] ?? '') ?>" class="combo-finishing" autocomplete="off"></div>
          </div>
          <div class="field-row">
            <div class="field"><label>Ukuran (bebas, cth. "60x60x45cm")</label><input type="text" name="size" value="<?= htmlspecialchars($editingProduct['size'] ?? '') ?>"></div>
            <div class="field" style="max-width:160px;"><label>Satuan</label><input type="text" name="unit" value="<?= htmlspecialchars($editingProduct['unit']) ?>"></div>
          </div>

          <label style="display:block; font-size:12px; font-weight:600; margin:10px 0 6px;">Dimensi (mm)</label>
          <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:10px 12px; margin-bottom:14px;">
            <?php foreach (DIMENSION_FIELDS as $df => $dl): ?>
              <div class="field" style="margin-bottom:0;"><label style="font-size:12px; font-weight:500;"><?= htmlspecialchars($dl) ?></label><input type="number" step="0.01" name="<?= $df ?>" value="<?= htmlspecialchars($editingProduct[$df] ?? '') ?>"></div>
            <?php endforeach; ?>
          </div>

          <label style="display:block; font-size:12px; font-weight:600; margin:10px 0 6px;">Spec Tambahan</label>
          <div id="extra-specs">
            <?php foreach ($extraSpecs as $es): ?>
              <div style="display:flex; gap:8px; margin-bottom:6px;">
                <input type="text" name="extra_label[]" placeholder="Label" value="<?= htmlspecialchars($es['label']) ?>" style="flex:1; padding:8px; border:1px solid var(--border); border-radius:4px;">
                <input type="text" name="extra_value[]" placeholder="Value" value="<?= htmlspecialchars($es['value']) ?>" style="flex:1; padding:8px; border:1px solid var(--border); border-radius:4px;">
                <button type="button" class="btn btn-sm btn-ghost" onclick="this.closest('div').remove()">✕</button>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-sm btn-ghost" onclick="var d=document.createElement('div'); d.style.cssText='display:flex;gap:8px;margin-bottom:6px;'; d.innerHTML='<input type=text name=extra_label[] placeholder=Label style=\'flex:1;padding:8px;border:1px solid var(--border);border-radius:4px;\'><input type=text name=extra_value[] placeholder=Value style=\'flex:1;padding:8px;border:1px solid var(--border);border-radius:4px;\'><button type=button class=\'btn btn-sm btn-ghost\' onclick=\'this.closest(&quot;div&quot;).remove()\'>✕</button>'; document.getElementById('extra-specs').appendChild(d);">+ Tambah Spec</button>

          <div style="margin-top:14px;"><button type="submit" class="btn">Simpan Spec Produk</button></div>
        </form>
        <?php else: ?>
          <div class="txn-info-strip">
            <div><span class="lbl">Material</span><?= htmlspecialchars($editingProduct['material'] ?: '—') ?></div>
            <div><span class="lbl">Item</span><?= htmlspecialchars($editingProduct['item_type'] ?: '—') ?></div>
            <div><span class="lbl">Collection</span><?= htmlspecialchars($editingProduct['collection'] ?: '—') ?></div>
            <div><span class="lbl">Finishing</span><?= htmlspecialchars($editingProduct['finishing'] ?: '—') ?></div>
            <div><span class="lbl">Ukuran</span><?= htmlspecialchars($editingProduct['size'] ?: '—') ?></div>
            <?php foreach (DIMENSION_FIELDS as $df => $dl): if ($editingProduct[$df] === null) continue; ?>
              <div><span class="lbl"><?= htmlspecialchars($dl) ?></span><?= rtrim(rtrim(number_format((float) $editingProduct[$df], 2, ',', '.'), '0'), ',') ?> mm</div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <h3>5 Tier</h3>
      <div style="display:flex; gap:14px; overflow-x:auto; padding-bottom:8px;">
        <?php foreach (TIER_LEVELS as $level => $label): $tier = $tiers[$level] ?? null; $bom = $tier ? (json_decode($tier['bom_json'] ?? '[]', true) ?: []) : []; $totalCost = 0; foreach ($bom as $b) $totalCost += ($b['qty'] ?? 0) * ($b['cost'] ?? 0); ?>
          <div class="card" style="width:260px; flex-shrink:0; display:flex; flex-direction:column;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <strong><?= htmlspecialchars($label) ?></strong>
              <?php if ($tier): ?>
                <?php
                $usedCheck2 = $pdo->prepare('SELECT COUNT(*) c FROM quotation_lines WHERE tier_id=?');
                $usedCheck2->execute([$tier['id']]);
                $usedNow = (int) $usedCheck2->fetch()['c'] > 0;
                ?>
                <?php if ($usedNow): ?><span class="pill pill-pending">🔒 Terpakai</span><?php endif; ?>
              <?php endif; ?>
            </div>
            <?php if (has_access('kontak', 'can_edit')): ?>
            <form method="post" style="display:flex; flex-direction:column; flex:1;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_tier">
              <input type="hidden" name="product_id" value="<?= $editingProduct['id'] ?>">
              <input type="hidden" name="tier_level" value="<?= $level ?>">
              <div class="field"><label>Harga Jual</label><input type="text" inputmode="numeric" class="rupiah-input" name="price" value="<?= $tier['price'] ?? 0 ?>"></div>
              <label style="display:block; font-size:11px; font-weight:600; margin-bottom:4px;">BOM Komponen</label>
              <div id="bom-list-<?= $level ?>" style="flex:1;">
                <?php foreach ($bom as $b): ?>
                  <div class="bom-row" style="border:1px solid var(--border); border-radius:4px; padding:6px; margin-bottom:6px;">
                    <input type="text" name="bom_material[]" placeholder="Material" value="<?= htmlspecialchars($b['material_name'] ?? '') ?>" class="combo-bom-material" autocomplete="off" style="width:100%; padding:5px; border:1px solid var(--border); border-radius:3px; margin-bottom:4px; font-size:12px;">
                    <div style="display:flex; gap:4px;">
                      <input type="number" step="0.01" name="bom_qty[]" placeholder="Qty" value="<?= $b['qty'] ?? '' ?>" style="width:50%; padding:5px; border:1px solid var(--border); border-radius:3px; font-size:12px;">
                      <input type="text" inputmode="numeric" class="rupiah-input" name="bom_cost[]" placeholder="Cost" value="<?= $b['cost'] ?? '' ?>" style="width:50%; padding:5px; border:1px solid var(--border); border-radius:3px; font-size:12px;">
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="btn btn-sm btn-ghost" onclick="addBomRow('<?= $level ?>')">+ Komponen</button>
              <div style="margin-top:auto; padding-top:10px; font-size:12px; color:var(--ink-muted);">Total Cost: Rp <?= number_format($totalCost, 0, ',', '.') ?></div>
              <button type="submit" class="btn btn-sm" style="margin-top:8px;">Simpan Tier</button>
            </form>
            <?php else: ?>
              <div style="font-size:13px; margin-bottom:6px;">Rp <?= number_format((float) ($tier['price'] ?? 0), 0, ',', '.') ?></div>
              <ul style="margin:0; padding-left:16px; font-size:12px; flex:1;">
                <?php foreach ($bom as $b): ?><li><?= htmlspecialchars($b['material_name'] ?? '') ?> — <?= $b['qty'] ?> × Rp <?= number_format((float) ($b['cost'] ?? 0), 0, ',', '.') ?></li><?php endforeach; ?>
              </ul>
              <div style="margin-top:auto; padding-top:10px; font-size:12px; color:var(--ink-muted);">Total Cost: Rp <?= number_format($totalCost, 0, ',', '.') ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>
  </div>
</div>

<script>
var COLLECTIONS_LIST = <?= json_encode($collectionsList) ?>;
var ITEM_TYPES_LIST = <?= json_encode($itemTypesList) ?>;
var FINISHINGS_LIST = <?= json_encode($finishingsList) ?>;
var MATERIALS_LIST = <?= json_encode(array_map(function ($m) { return ['label' => $m['name'], 'cost' => (float) $m['default_cost']]; }, $materialsList)) ?>;
var MATERIAL_NAMES = MATERIALS_LIST.map(function (m) { return m.label; });

function initBomCombobox(el) {
  initCombobox(el, MATERIALS_LIST, {
    onSelect: function (item) {
      var row = el.closest('.bom-row');
      var costInput = row.querySelector('[name="bom_cost[]"]');
      if (costInput && !costInput.value) {
        costInput.value = item.cost || 0;
        costInput.dispatchEvent(new Event('input'));
      }
    }
  });
}

function addBomRow(level) {
  var d = document.createElement('div');
  d.className = 'bom-row';
  d.style.cssText = 'border:1px solid var(--border);border-radius:4px;padding:6px;margin-bottom:6px;';
  d.innerHTML = '<input type="text" name="bom_material[]" placeholder="Material" autocomplete="off" style="width:100%;padding:5px;border:1px solid var(--border);border-radius:3px;margin-bottom:4px;font-size:12px;">'
    + '<div style="display:flex;gap:4px;"><input type="number" step="0.01" name="bom_qty[]" placeholder="Qty" style="width:50%;padding:5px;border:1px solid var(--border);border-radius:3px;font-size:12px;">'
    + '<input type="text" inputmode="numeric" class="rupiah-input" name="bom_cost[]" placeholder="Cost" style="width:50%;padding:5px;border:1px solid var(--border);border-radius:3px;font-size:12px;"></div>';
  document.getElementById('bom-list-' + level).appendChild(d);
  initBomCombobox(d.querySelector('input[name="bom_material[]"]'));
  initRupiahInput(d.querySelector('input[name="bom_cost[]"]'));
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.combo-collection').forEach(function (el) { initCombobox(el, COLLECTIONS_LIST); });
  document.querySelectorAll('.combo-item').forEach(function (el) { initCombobox(el, ITEM_TYPES_LIST); });
  document.querySelectorAll('.combo-finishing').forEach(function (el) { initCombobox(el, FINISHINGS_LIST); });
  document.querySelectorAll('.combo-material-spec').forEach(function (el) { initCombobox(el, MATERIAL_NAMES); });
  document.querySelectorAll('.combo-bom-material').forEach(initBomCombobox);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
