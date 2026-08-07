<?php
/**
 * Retur supplier — nempel ke Penerimaan Barang asal, cuma barang yang masih
 * in_stock dari GR itu yang boleh diretur. Status barang jadi 'returned'.
 */
$pageTitle = 'Retur Supplier';
$activeMenu = 'supplier_return';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_return') {
    require_csrf();
    require_module_access('kontak', 'can_create');
    try {
        $grId = (int) ($_POST['goods_receipt_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '') ?: null;
        $itemIds = array_map('intval', $_POST['item_ids'] ?? []);
        if (!$grId) throw new RuntimeException('Penerimaan Barang asal wajib dipilih.');
        if (!$itemIds) throw new RuntimeException('Pilih minimal 1 barang buat diretur.');

        $pdo->beginTransaction();
        $gr = $pdo->prepare('SELECT * FROM gold_goods_receipts WHERE id=? AND organization_id=?');
        $gr->execute([$grId, $org['organization_id']]);
        $gr = $gr->fetch();
        if (!$gr) throw new RuntimeException('Penerimaan Barang tidak ditemukan.');

        $check = $pdo->prepare("SELECT id FROM inventory_items WHERE id=? AND organization_id=? AND source_type='goods_receipt' AND source_id=? AND status='in_stock' FOR UPDATE");
        foreach ($itemIds as $iid) {
            $check->execute([$iid, $org['organization_id'], $grId]);
            if (!$check->fetch()) throw new RuntimeException("Barang #$iid bukan dari Penerimaan ini atau udah gak in-stock.");
        }

        $docNumber = next_doc_number($org['organization_id'], 'RETUR');
        $pdo->prepare('INSERT INTO supplier_returns (organization_id, doc_number, goods_receipt_id, vendor_id, notes, returned_by) VALUES (?,?,?,?,?,?)')
            ->execute([$org['organization_id'], $docNumber, $grId, $gr['vendor_id'], $notes, $user['id']]);
        $returnId = (int) $pdo->lastInsertId();

        $insLine = $pdo->prepare('INSERT INTO supplier_return_lines (supplier_return_id, inventory_item_id) VALUES (?,?)');
        $markReturned = $pdo->prepare("UPDATE inventory_items SET status='returned' WHERE id=?");
        foreach ($itemIds as $iid) {
            $insLine->execute([$returnId, $iid]);
            $markReturned->execute([$iid]);
        }
        $pdo->commit();
        $flash = ['ok', "Retur $docNumber tersimpan, " . count($itemIds) . ' barang dikembalikan ke supplier.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['error', $e->getMessage()];
    }
}

$receipts = $pdo->prepare(
    "SELECT gr.id, gr.doc_number, v.name AS vendor_name,
       (SELECT COUNT(*) FROM inventory_items ii WHERE ii.source_type='goods_receipt' AND ii.source_id=gr.id AND ii.status='in_stock') AS available_count
     FROM gold_goods_receipts gr
     LEFT JOIN contacts v ON v.id = gr.vendor_id
     WHERE gr.organization_id=?
     HAVING available_count > 0
     ORDER BY gr.id DESC"
);
$receipts->execute([$org['organization_id']]);
$receipts = $receipts->fetchAll();

$itemsByGr = [];
if ($receipts) {
    $grIds = array_column($receipts, 'id');
    $placeholders = implode(',', array_fill(0, count($grIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT ii.id, ii.source_id AS goods_receipt_id, ii.plu_code, ii.certificate_code, ii.weight, p.name AS product_name
         FROM inventory_items ii JOIN products p ON p.id = ii.product_id
         WHERE ii.organization_id=? AND ii.source_type='goods_receipt' AND ii.status='in_stock' AND ii.source_id IN ($placeholders)
         ORDER BY p.name"
    );
    $stmt->execute(array_merge([$org['organization_id']], $grIds));
    foreach ($stmt->fetchAll() as $row) {
        $itemsByGr[$row['goods_receipt_id']][] = $row;
    }
}

$returns = $pdo->prepare(
    "SELECT sr.*, gr.doc_number AS gr_doc_number, v.name AS vendor_name,
       (SELECT COUNT(*) FROM supplier_return_lines l WHERE l.supplier_return_id = sr.id) AS item_count
     FROM supplier_returns sr
     LEFT JOIN gold_goods_receipts gr ON gr.id = sr.goods_receipt_id
     LEFT JOIN contacts v ON v.id = sr.vendor_id
     WHERE sr.organization_id=? ORDER BY sr.id DESC LIMIT 20"
);
$returns->execute([$org['organization_id']]);
$returns = $returns->fetchAll();
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if (!$receipts): ?>
  <div class="card txn-empty">Gak ada Penerimaan Barang yang masih punya stock in-stock buat diretur.</div>
<?php else: ?>

<div class="card txn-form-page" style="margin-bottom:20px;">
  <h3 style="margin-top:0;">Retur ke Supplier</h3>
  <form method="post" id="return-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_return">
    <div class="field">
      <label>Penerimaan Barang Asal</label>
      <select name="goods_receipt_id" id="return_gr_id" required onchange="renderReturnItems()">
        <option value="">— pilih —</option>
        <?php foreach ($receipts as $r): ?>
          <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['doc_number']) ?><?= $r['vendor_name'] ? ' — ' . $r['vendor_name'] : '' ?> (<?= (int) $r['available_count'] ?> barang tersedia)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Barang yang diretur</label>
      <div id="return-item-list" style="border:1px solid var(--border-strong); border-radius:8px; max-height:240px; overflow-y:auto; padding:14px; text-align:center; color:var(--ink-muted); font-size:13px;">
        Pilih Penerimaan Barang dulu.
      </div>
    </div>
    <div class="field"><label>Catatan (opsional)</label><textarea name="notes" rows="2"></textarea></div>
    <div class="modal-foot" style="border-top:1px solid var(--border); margin-top:8px; padding-top:14px; justify-content:flex-end;">
      <button type="submit" class="btn">Simpan Retur</button>
    </div>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0;">Riwayat Retur</h3>
  <table class="data-table">
    <thead><tr><th>No. Dokumen</th><th>Penerimaan Asal</th><th>Vendor</th><th class="num">Jumlah Barang</th><th>Tanggal</th></tr></thead>
    <tbody>
      <?php foreach ($returns as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['doc_number']) ?></td>
          <td><?= htmlspecialchars($r['gr_doc_number'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['vendor_name'] ?? '-') ?></td>
          <td class="num"><?= (int) $r['item_count'] ?></td>
          <td><?= htmlspecialchars(date('d M Y H:i', strtotime($r['returned_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$returns): ?><tr><td colspan="5" style="text-align:center; color:var(--ink-muted);">Belum ada retur.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<script>
var RETURN_ITEMS_BY_GR = <?= json_encode(array_map(fn($items) => array_map(fn($it) => [
    'id' => (int) $it['id'], 'product_name' => $it['product_name'], 'plu_code' => $it['plu_code'],
    'certificate_code' => $it['certificate_code'], 'weight' => $it['weight'],
], $items), $itemsByGr)) ?>;

function renderReturnItems() {
  var grId = document.getElementById('return_gr_id').value;
  var wrap = document.getElementById('return-item-list');
  var items = RETURN_ITEMS_BY_GR[grId] || [];
  if (!grId) {
    wrap.style.textAlign = 'center'; wrap.style.color = 'var(--ink-muted)';
    wrap.innerHTML = 'Pilih Penerimaan Barang dulu.';
    return;
  }
  if (!items.length) {
    wrap.style.textAlign = 'center'; wrap.style.color = 'var(--ink-muted)';
    wrap.innerHTML = 'Gak ada barang tersedia dari penerimaan ini.';
    return;
  }
  wrap.style.textAlign = ''; wrap.style.color = '';
  wrap.innerHTML = items.map(function (it) {
    return '<label style="display:flex; align-items:center; gap:10px; padding:8px 12px; border-bottom:1px solid var(--border-hair); font-size:13px;">' +
      '<input type="checkbox" name="item_ids[]" value="' + it.id + '" style="width:auto;">' +
      '<span style="font-weight:600;">' + it.product_name.replace(/</g, '&lt;') + '</span>' +
      '<span style="color:var(--ink-muted);">PLU ' + it.plu_code + (it.certificate_code ? ' · Sert. ' + it.certificate_code : '') + (it.weight ? ' · ' + parseFloat(it.weight).toFixed(2) + ' gr' : '') + '</span>' +
      '</label>';
  }).join('');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
