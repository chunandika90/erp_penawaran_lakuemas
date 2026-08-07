<?php
/**
 * Lebur barang — pilih item in_stock buat dilebur (status jadi 'melted'),
 * hasilnya jadi 1 inventory_item baru (produk & lokasi output bebas dipilih,
 * berat default = total berat item yang dilebur, bisa dikoreksi manual kalau
 * ada susut pas proses lebur).
 */
$pageTitle = 'Lebur Barang';
$activeMenu = 'melting';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_melting') {
    require_csrf();
    require_module_access('kontak', 'can_create');
    try {
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $projectId = (int) ($_POST['project_id'] ?? 0) ?: null;
        $outputProductId = (int) ($_POST['output_product_id'] ?? 0);
        $outputWeight = (float) ($_POST['output_weight'] ?? 0);
        $outputCert = trim($_POST['output_certificate_code'] ?? '') ?: null;
        $outputStockTypeId = (int) ($_POST['output_stock_type_id'] ?? 0) ?: null;
        $notes = trim($_POST['notes'] ?? '') ?: null;
        $itemIds = array_map('intval', $_POST['item_ids'] ?? []);

        if (!$locationId) throw new RuntimeException('Lokasi output wajib dipilih.');
        if (!$outputProductId) throw new RuntimeException('Produk hasil lebur wajib dipilih.');
        if ($outputWeight <= 0) throw new RuntimeException('Berat hasil lebur harus lebih dari 0.');
        if (!$itemIds) throw new RuntimeException('Pilih minimal 1 barang buat dilebur.');

        $pdo->beginTransaction();
        $check = $pdo->prepare("SELECT id FROM inventory_items WHERE id=? AND organization_id=? AND status='in_stock' FOR UPDATE");
        foreach ($itemIds as $iid) {
            $check->execute([$iid, $org['organization_id']]);
            if (!$check->fetch()) throw new RuntimeException("Barang #$iid udah gak tersedia buat dilebur.");
        }
        $prodCheck = $pdo->prepare('SELECT id FROM products WHERE id=? AND organization_id=?');
        $prodCheck->execute([$outputProductId, $org['organization_id']]);
        if (!$prodCheck->fetch()) throw new RuntimeException('Produk hasil lebur tidak valid.');

        $docNumber = next_doc_number($org['organization_id'], 'LEBUR');
        $outputPlu = next_doc_number($org['organization_id'], 'PLU');
        $pdo->prepare('INSERT INTO inventory_items (organization_id, product_id, location_id, stock_type_id, certificate_code, plu_code, weight, project_id, source_type) VALUES (?,?,?,?,?,?,?,?,\'melting_output\')')
            ->execute([$org['organization_id'], $outputProductId, $locationId, $outputStockTypeId, $outputCert, $outputPlu, $outputWeight, $projectId]);
        $outputItemId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO melting_batches (organization_id, doc_number, location_id, project_id, output_inventory_item_id, notes, melted_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$org['organization_id'], $docNumber, $locationId, $projectId, $outputItemId, $notes, $user['id']]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE inventory_items SET source_id=? WHERE id=?')->execute([$batchId, $outputItemId]);

        $insLine = $pdo->prepare('INSERT INTO melting_batch_lines (melting_batch_id, inventory_item_id) VALUES (?,?)');
        $markMelted = $pdo->prepare("UPDATE inventory_items SET status='melted' WHERE id=?");
        foreach ($itemIds as $iid) {
            $insLine->execute([$batchId, $iid]);
            $markMelted->execute([$iid]);
        }
        $pdo->commit();
        $flash = ['ok', "Lebur $docNumber tersimpan, " . count($itemIds) . " barang dilebur jadi 1 item baru (PLU $outputPlu)."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['error', $e->getMessage()];
    }
}

$locations = $pdo->prepare("SELECT l.id, l.name, g.name AS group_name FROM locations l JOIN locations g ON g.id = l.parent_id WHERE l.organization_id=? AND l.is_active=1 ORDER BY g.name, l.name");
$locations->execute([$org['organization_id']]);
$locations = $locations->fetchAll();

$projects = $pdo->prepare('SELECT id, name FROM projects WHERE organization_id=? ORDER BY name');
$projects->execute([$org['organization_id']]);
$projects = $projects->fetchAll();

$products = $pdo->prepare("SELECT p.id, p.name, cat.name AS category_name FROM products p LEFT JOIN product_categories cat ON cat.id = p.category_id WHERE p.organization_id=? AND p.is_active=1 AND p.category_id IS NOT NULL ORDER BY p.name");
$products->execute([$org['organization_id']]);
$products = $products->fetchAll();

$stockTypes = $pdo->prepare('SELECT id, name FROM stock_types WHERE organization_id=? AND is_active=1 ORDER BY sort_order, name');
$stockTypes->execute([$org['organization_id']]);
$stockTypes = $stockTypes->fetchAll();

$availableItems = $pdo->prepare(
    "SELECT ii.id, ii.plu_code, ii.certificate_code, ii.weight, p.name AS product_name
     FROM inventory_items ii JOIN products p ON p.id = ii.product_id
     WHERE ii.organization_id=? AND ii.status='in_stock' ORDER BY p.name, ii.plu_code"
);
$availableItems->execute([$org['organization_id']]);
$availableItems = $availableItems->fetchAll();

$batches = $pdo->prepare(
    "SELECT mb.*, l.name AS location_name, oi.plu_code AS output_plu, oi.weight AS output_weight,
       (SELECT COUNT(*) FROM melting_batch_lines ml WHERE ml.melting_batch_id = mb.id) AS item_count
     FROM melting_batches mb
     LEFT JOIN locations l ON l.id = mb.location_id
     LEFT JOIN inventory_items oi ON oi.id = mb.output_inventory_item_id
     WHERE mb.organization_id=? ORDER BY mb.id DESC LIMIT 20"
);
$batches->execute([$org['organization_id']]);
$batches = $batches->fetchAll();
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if (!$availableItems): ?>
  <div class="card txn-empty">Belum ada barang in-stock buat dilebur.</div>
<?php else: ?>

<div class="card txn-form-page" style="margin-bottom:20px;">
  <h3 style="margin-top:0;">Lebur Barang</h3>
  <form method="post" id="melt-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_melting">

    <div class="field">
      <label>Barang yang dilebur</label>
      <div style="border:1px solid var(--border-strong); border-radius:8px; max-height:220px; overflow-y:auto;">
        <?php foreach ($availableItems as $it): ?>
          <label style="display:flex; align-items:center; gap:10px; padding:8px 12px; border-bottom:1px solid var(--border-hair); font-size:13px;">
            <input type="checkbox" name="item_ids[]" value="<?= $it['id'] ?>" class="melt-item-cb" data-weight="<?= (float) $it['weight'] ?>" onchange="recalcMeltWeight()" style="width:auto;">
            <span style="font-weight:600;"><?= htmlspecialchars($it['product_name']) ?></span>
            <span style="color:var(--ink-muted);">PLU <?= htmlspecialchars($it['plu_code']) ?> · <?= $it['weight'] !== null ? number_format((float) $it['weight'], 2, ',', '.') . ' gr' : 'berat blm dicatat' ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div style="font-size:12px; color:var(--ink-muted); margin-top:6px;">Total berat dipilih: <b id="melt-input-total">0</b> gr</div>
    </div>

    <div class="field-row">
      <div class="field">
        <label>Lokasi Output</label>
        <select name="location_id" required>
          <option value="">— pilih —</option>
          <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['group_name'] . ' › ' . $l['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Produk Hasil Lebur</label>
        <select name="output_product_id" required>
          <option value="">— pilih —</option>
          <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name'] . ($p['category_name'] ? ' (' . $p['category_name'] . ')' : '')) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field-row">
      <div class="field"><label>Berat Hasil Lebur (gr)</label><input type="text" name="output_weight" id="melt-output-weight" inputmode="decimal" required></div>
      <div class="field"><label>Tipe Stock Output</label>
        <select name="output_stock_type_id">
          <option value="">—</option>
          <?php foreach ($stockTypes as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Project (opsional)</label>
        <select name="project_id">
          <option value="">— tanpa project —</option>
          <?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field"><label>Kode Sertifikat Output (opsional)</label><input type="text" name="output_certificate_code"></div>
    <div class="field"><label>Catatan (opsional)</label><textarea name="notes" rows="2"></textarea></div>

    <div class="modal-foot" style="border-top:1px solid var(--border); margin-top:8px; padding-top:14px; justify-content:flex-end;">
      <button type="submit" class="btn">Simpan Lebur</button>
    </div>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0;">Riwayat Lebur</h3>
  <table class="data-table">
    <thead><tr><th>No. Dokumen</th><th>Lokasi</th><th class="num">Barang Dilebur</th><th>PLU Output</th><th class="num">Berat Output</th><th>Tanggal</th></tr></thead>
    <tbody>
      <?php foreach ($batches as $b): ?>
        <tr>
          <td><?= htmlspecialchars($b['doc_number']) ?></td>
          <td><?= htmlspecialchars($b['location_name'] ?? '-') ?></td>
          <td class="num"><?= (int) $b['item_count'] ?></td>
          <td><?= htmlspecialchars($b['output_plu'] ?? '-') ?></td>
          <td class="num"><?= $b['output_weight'] !== null ? number_format((float) $b['output_weight'], 2, ',', '.') . ' gr' : '-' ?></td>
          <td><?= htmlspecialchars(date('d M Y H:i', strtotime($b['melted_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$batches): ?><tr><td colspan="6" style="text-align:center; color:var(--ink-muted);">Belum ada proses lebur.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<script>
function recalcMeltWeight() {
  var total = 0;
  document.querySelectorAll('.melt-item-cb:checked').forEach(function (cb) { total += parseFloat(cb.dataset.weight) || 0; });
  document.getElementById('melt-input-total').textContent = total.toFixed(2);
  var outputField = document.getElementById('melt-output-weight');
  if (!outputField.dataset.touched) outputField.value = total.toFixed(2);
}
document.getElementById('melt-output-weight')?.addEventListener('input', function () { this.dataset.touched = '1'; });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
