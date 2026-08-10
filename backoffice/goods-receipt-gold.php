<?php
/**
 * Penerimaan barang emas — tiap baris = 1 barang fisik (serialized), bukan
 * qty per SKU. Kode sertifikat diinput manual (dari supplier), kode PLU
 * di-generate otomatis (next_plu_code) pas disimpan.
 *
 * Provenance di level BARIS, bukan header: tiap baris bisa (opsional) milih
 * "PO Line" sumbernya sendiri-sendiri — jadi 1 dokumen Penerimaan bisa gabung
 * barang dari beberapa PO sekaligus (merge), gak wajib 1 PO per dokumen kayak
 * dulu. Header po_id/vendor_id tetap ada sebagai info ringkas kalau emang
 * single-sourced, tapi sumber kebenarannya di inventory_items.po_line_id.
 */
$pageTitle = 'Penerimaan Barang (Emas)';
$activeMenu = 'goods_receipt_gold';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'save_receipt') {
        require_module_access('kontak', 'can_create');
        try {
            $locationId = (int) ($_POST['location_id'] ?? 0);
            $vendorId = (int) ($_POST['vendor_id'] ?? 0) ?: null;
            $poId = (int) ($_POST['po_id'] ?? 0) ?: null;
            $projectId = (int) ($_POST['project_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes'] ?? '') ?: null;
            $lineProductIds = $_POST['line_product_id'] ?? [];
            $linePoLineIds = $_POST['line_po_line_id'] ?? [];
            $lineCerts = $_POST['line_certificate_code'] ?? [];
            $lineWeights = $_POST['line_weight'] ?? [];
            $lineTypes = $_POST['line_stock_type_id'] ?? [];

            if (!$locationId) throw new RuntimeException('Lokasi penerimaan wajib dipilih.');
            $rows = [];
            foreach ($lineProductIds as $i => $pid) {
                $pid = (int) $pid;
                if (!$pid) continue;
                $rows[] = [
                    'product_id' => $pid,
                    'po_line_id' => (int) ($linePoLineIds[$i] ?? 0) ?: null,
                    'certificate_code' => trim($lineCerts[$i] ?? '') ?: null,
                    'weight' => trim($lineWeights[$i] ?? '') !== '' ? (float) $lineWeights[$i] : null,
                    'stock_type_id' => (int) ($lineTypes[$i] ?? 0) ?: null,
                ];
            }
            if (!$rows) throw new RuntimeException('Minimal 1 barang harus diisi.');

            $locCheck = $pdo->prepare('SELECT id FROM locations WHERE id=? AND organization_id=?');
            $locCheck->execute([$locationId, $org['organization_id']]);
            if (!$locCheck->fetch()) throw new RuntimeException('Lokasi tidak valid.');

            if ($poId) {
                $poCheck = $pdo->prepare('SELECT id FROM purchase_orders_gold WHERE id=? AND organization_id=?');
                $poCheck->execute([$poId, $org['organization_id']]);
                if (!$poCheck->fetch()) throw new RuntimeException('PO tidak valid.');
            }

            $pdo->beginTransaction();
            $docNumber = next_doc_number($org['organization_id'], 'GR-EMAS');
            $pdo->prepare('INSERT INTO gold_goods_receipts (organization_id, doc_number, location_id, po_id, vendor_id, project_id, notes, received_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$org['organization_id'], $docNumber, $locationId, $poId, $vendorId, $projectId, $notes, $user['id']]);
            $grId = (int) $pdo->lastInsertId();

            $prodCheck = $pdo->prepare('SELECT id FROM products WHERE id=? AND organization_id=?');
            $poLineCheck = $pdo->prepare(
                'SELECT pgl.id, pgl.po_id FROM purchase_order_gold_lines pgl
                 JOIN purchase_orders_gold po ON po.id = pgl.po_id
                 WHERE pgl.id=? AND po.organization_id=?'
            );
            $insItem = $pdo->prepare('INSERT INTO inventory_items (organization_id, product_id, po_line_id, location_id, stock_type_id, certificate_code, plu_code, weight, project_id, source_type, source_id) VALUES (?,?,?,?,?,?,?,?,?,\'goods_receipt\',?)');
            $touchedPoIds = [];
            foreach ($rows as $r) {
                $prodCheck->execute([$r['product_id'], $org['organization_id']]);
                if (!$prodCheck->fetch()) throw new RuntimeException('Ada produk yang tidak valid di baris penerimaan.');
                if ($r['po_line_id']) {
                    $poLineCheck->execute([$r['po_line_id'], $org['organization_id']]);
                    $poLine = $poLineCheck->fetch();
                    if (!$poLine) throw new RuntimeException('Ada PO Line yang tidak valid di baris penerimaan.');
                    $touchedPoIds[(int) $poLine['po_id']] = true;
                }
                $pluCode = next_plu_code($org['organization_id']);
                $insItem->execute([$org['organization_id'], $r['product_id'], $r['po_line_id'], $locationId, $r['stock_type_id'], $r['certificate_code'], $pluCode, $r['weight'], $projectId, $grId]);
            }
            if ($poId) $touchedPoIds[$poId] = true;
            foreach (array_keys($touchedPoIds) as $tPoId) {
                $pdo->prepare("UPDATE purchase_orders_gold SET status='received' WHERE id=? AND organization_id=?")->execute([$tPoId, $org['organization_id']]);
            }
            $pdo->commit();
            $flash = ['ok', "Penerimaan $docNumber tersimpan, " . count($rows) . ' barang masuk stock.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flash = ['error', $e->getMessage()];
        }
    }
}

$locations = $pdo->prepare("SELECT l.id, l.name, g.name AS group_name FROM locations l JOIN locations g ON g.id = l.parent_id WHERE l.organization_id=? AND l.is_active=1 ORDER BY g.name, l.name");
$locations->execute([$org['organization_id']]);
$locations = $locations->fetchAll();

$vendors = $pdo->prepare("SELECT id, name FROM contacts WHERE organization_id=? AND type IN ('vendor','both') ORDER BY name");
$vendors->execute([$org['organization_id']]);
$vendors = $vendors->fetchAll();

$openPOs = $pdo->prepare("SELECT po.id, po.doc_number, v.name AS vendor_name FROM purchase_orders_gold po LEFT JOIN contacts v ON v.id = po.vendor_id WHERE po.organization_id=? AND po.status='sent' ORDER BY po.id DESC");
$openPOs->execute([$org['organization_id']]);
$openPOs = $openPOs->fetchAll();

// Baris PO terbuka (dari PO manapun yang status 'sent') — dipilih per baris
// barang, bukan per header, biar 1 Penerimaan bisa gabung dari beberapa PO
// sekaligus. Belum ada tracking received_qty per baris, jadi semua baris PO
// yang PO-nya masih 'sent' muncul di sini (simplifikasi yang disengaja).
$openPoLines = $pdo->prepare(
    "SELECT pgl.id, pgl.product_id, pgl.qty, po.doc_number, v.name AS vendor_name
     FROM purchase_order_gold_lines pgl
     JOIN purchase_orders_gold po ON po.id = pgl.po_id
     LEFT JOIN contacts v ON v.id = po.vendor_id
     WHERE po.organization_id=? AND po.status='sent' ORDER BY po.id DESC"
);
$openPoLines->execute([$org['organization_id']]);
$openPoLines = $openPoLines->fetchAll();

$projects = $pdo->prepare('SELECT id, name FROM projects WHERE organization_id=? ORDER BY name');
$projects->execute([$org['organization_id']]);
$projects = $projects->fetchAll();

$products = $pdo->prepare("SELECT p.id, p.name, p.unit, cat.name AS category_name FROM products p LEFT JOIN product_categories cat ON cat.id = p.category_id WHERE p.organization_id=? AND p.is_active=1 AND p.category_id IS NOT NULL ORDER BY p.name");
$products->execute([$org['organization_id']]);
$products = $products->fetchAll();

$stockTypes = $pdo->prepare('SELECT id, name FROM stock_types WHERE organization_id=? AND is_active=1 ORDER BY sort_order, name');
$stockTypes->execute([$org['organization_id']]);
$stockTypes = $stockTypes->fetchAll();

$recent = $pdo->prepare(
    "SELECT gr.*, l.name AS location_name, v.name AS vendor_name,
       (SELECT COUNT(*) FROM inventory_items ii WHERE ii.source_type='goods_receipt' AND ii.source_id=gr.id) AS item_count
     FROM gold_goods_receipts gr
     LEFT JOIN locations l ON l.id = gr.location_id
     LEFT JOIN contacts v ON v.id = gr.vendor_id
     WHERE gr.organization_id=? ORDER BY gr.id DESC LIMIT 20"
);
$recent->execute([$org['organization_id']]);
$recent = $recent->fetchAll();
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if (!$products): ?>
  <div class="card txn-empty">Belum ada produk aktif. Bikin dulu di <a href="product-master.php">Master Produk</a>.</div>
<?php elseif (!$locations): ?>
  <div class="card txn-empty">Belum ada lokasi (Group Location > Location). Bikin dulu di <a href="locations.php">Lokasi</a>.</div>
<?php else: ?>

<div class="card txn-form-page" style="margin-bottom:20px;">
  <h3 style="margin-top:0;">Terima Barang Baru</h3>
  <form method="post" id="gr-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_receipt">
    <div class="field-row">
      <div class="field">
        <label>Lokasi Penerimaan</label>
        <select name="location_id" required>
          <option value="">— pilih —</option>
          <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['group_name'] . ' › ' . $l['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Vendor / Supplier (opsional)</label>
        <select name="vendor_id">
          <option value="">—</option>
          <?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>PO Utama (opsional, cuma info)</label>
        <select name="po_id">
          <option value="">— tanpa PO —</option>
          <?php foreach ($openPOs as $po): ?><option value="<?= $po['id'] ?>"><?= htmlspecialchars($po['doc_number'] . ($po['vendor_name'] ? ' — ' . $po['vendor_name'] : '')) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Project (opsional)</label>
        <select name="project_id">
          <option value="">— tanpa project —</option>
          <?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field"><label>Catatan (opsional)</label><textarea name="notes" rows="2"></textarea></div>

    <p style="font-size:12px; color:var(--ink-muted); margin:0 0 8px;">Tiap baris barang bisa (opsional) dipilihin "PO Line" sumbernya sendiri — jadi 1 dokumen ini bisa gabung barang dari beberapa PO berbeda sekaligus.</p>
    <table class="data-table" id="gr-lines-table" style="margin-bottom:10px;">
      <thead><tr><th style="width:22%;">PO Line (opsional)</th><th style="width:22%;">Produk</th><th>Kode Sertifikat</th><th style="width:100px;">Berat (gr)</th><th style="width:160px;">Tipe Stock</th><th></th></tr></thead>
      <tbody id="gr-lines-body"></tbody>
    </table>
    <button type="button" class="btn btn-sm btn-ghost" onclick="addGrLine()">+ Tambah Barang</button>

    <div class="modal-foot" style="border-top:1px solid var(--border); margin-top:18px; padding-top:14px; justify-content:flex-end;">
      <button type="submit" class="btn">Simpan Penerimaan</button>
    </div>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0;">Penerimaan Terakhir</h3>
  <table class="data-table">
    <thead><tr><th>No. Dokumen</th><th>Lokasi</th><th>Vendor</th><th class="num">Jumlah Barang</th><th>Tanggal</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['doc_number']) ?></td>
          <td><?= htmlspecialchars($r['location_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['vendor_name'] ?? '-') ?></td>
          <td class="num"><?= (int) $r['item_count'] ?></td>
          <td><?= htmlspecialchars(date('d M Y H:i', strtotime($r['received_at']))) ?></td>
          <td><a class="btn btn-sm btn-ghost" href="goods-receipt-barcode-print.php?gr_id=<?= $r['id'] ?>" target="_blank">Cetak Barcode</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recent): ?><tr><td colspan="6" style="text-align:center; color:var(--ink-muted);">Belum ada penerimaan.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<script>
var GR_PRODUCTS = <?= json_encode(array_map(fn($p) => ['id' => (int) $p['id'], 'label' => $p['name'] . ($p['category_name'] ? ' (' . $p['category_name'] . ')' : '')], $products)) ?>;
var GR_STOCK_TYPES = <?= json_encode(array_map(fn($t) => ['id' => (int) $t['id'], 'name' => $t['name']], $stockTypes)) ?>;
var GR_PO_LINES = <?= json_encode(array_map(fn($l) => ['id' => (int) $l['id'], 'product_id' => (int) $l['product_id'], 'label' => $l['doc_number'] . ' — ' . $l['qty'] . 'x (' . ($l['vendor_name'] ?: '-') . ')'], $openPoLines)) ?>;
var grLineIndex = 0;
function addGrLine() {
  var i = grLineIndex++;
  var tr = document.createElement('tr');
  var poLineOpts = '<option value="">— tanpa PO —</option>' + GR_PO_LINES.map(function (l) { return '<option value="' + l.id + '" data-product-id="' + l.product_id + '">' + l.label.replace(/</g, '&lt;') + '</option>'; }).join('');
  var prodOpts = '<option value="">— pilih —</option>' + GR_PRODUCTS.map(function (p) { return '<option value="' + p.id + '">' + p.label.replace(/</g, '&lt;') + '</option>'; }).join('');
  var typeOpts = '<option value="">—</option>' + GR_STOCK_TYPES.map(function (t) { return '<option value="' + t.id + '">' + t.name.replace(/</g, '&lt;') + '</option>'; }).join('');
  tr.innerHTML =
    '<td><select name="line_po_line_id[]" onchange="grPoLineChanged(this)">' + poLineOpts + '</select></td>' +
    '<td><select name="line_product_id[]" required>' + prodOpts + '</select></td>' +
    '<td><input type="text" name="line_certificate_code[]" placeholder="cth. AC-2026-00123"></td>' +
    '<td><input type="text" name="line_weight[]" inputmode="decimal" placeholder="0.00"></td>' +
    '<td><select name="line_stock_type_id[]">' + typeOpts + '</select></td>' +
    '<td><button type="button" class="btn btn-sm btn-ghost" onclick="this.closest(\'tr\').remove()">Hapus</button></td>';
  document.getElementById('gr-lines-body').appendChild(tr);
}
function grPoLineChanged(sel) {
  var productId = sel.selectedOptions[0] ? sel.selectedOptions[0].getAttribute('data-product-id') : '';
  var prodSelect = sel.closest('tr').querySelector('select[name="line_product_id[]"]');
  if (productId) prodSelect.value = productId;
}
addGrLine();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
