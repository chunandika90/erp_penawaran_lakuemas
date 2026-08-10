<?php
/**
 * Penawaran emas — ini PENAWARAN DARI VENDOR (mereka nawarin barang buat kita
 * beli), bukan penawaran ke customer. Bagian awal alur pengadaan: Penawaran
 * (vendor) -> PO -> Penerimaan Barang. Kalau disetujui, baris-barisnya bisa
 * dirujuk jadi sumber baris PO (1 Penawaran bisa dipecah ke beberapa PO).
 *
 * Begitu ada baris yang udah dirujuk PO, seluruh Penawaran dikunci dari edit
 * (quotation_is_locked) — biar gak ada PO yang datanya (misal harga) jadi
 * gak sinkron sama sumbernya lagi.
 */
$pageTitle = 'Penawaran';
$activeMenu = 'quotation_gold';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

function quotation_is_locked(PDO $pdo, int $quotationId): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM purchase_order_gold_lines pgl
         JOIN quotation_gold_lines qgl ON qgl.id = pgl.quotation_line_id
         WHERE qgl.quotation_id = ?'
    );
    $stmt->execute([$quotationId]);
    return (int) $stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_quotation') {
            $id = (int) ($_POST['quotation_id'] ?? 0);
            require_module_access('kontak', $id ? 'can_edit' : 'can_create');
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            $projectId = (int) ($_POST['project_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes'] ?? '') ?: null;
            $lineProductIds = $_POST['line_product_id'] ?? [];
            $lineQtys = $_POST['line_qty'] ?? [];
            $linePrices = $_POST['line_unit_price'] ?? [];

            if (!$contactId) throw new RuntimeException('Vendor wajib dipilih.');
            $rows = [];
            foreach ($lineProductIds as $i => $pid) {
                $pid = (int) $pid;
                if (!$pid) continue;
                $rows[] = ['product_id' => $pid, 'qty' => (float) ($lineQtys[$i] ?? 1), 'unit_price' => (float) ($linePrices[$i] ?? 0)];
            }
            if (!$rows) throw new RuntimeException('Minimal 1 baris produk harus diisi.');

            if ($id > 0) {
                $own = $pdo->prepare('SELECT id FROM quotations_gold WHERE id=? AND organization_id=?');
                $own->execute([$id, $org['organization_id']]);
                if (!$own->fetch()) throw new RuntimeException('Penawaran tidak ditemukan.');
                if (quotation_is_locked($pdo, $id)) {
                    throw new RuntimeException('Penawaran ini udah dirujuk sama PO, gak bisa diedit lagi.');
                }
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE quotations_gold SET contact_id=?, project_id=?, notes=? WHERE id=? AND organization_id=?')
                    ->execute([$contactId, $projectId, $notes, $id, $org['organization_id']]);
                $pdo->prepare('DELETE FROM quotation_gold_lines WHERE quotation_id=?')->execute([$id]);
                $insLine = $pdo->prepare('INSERT INTO quotation_gold_lines (quotation_id, product_id, qty, unit_price) VALUES (?,?,?,?)');
                foreach ($rows as $r) $insLine->execute([$id, $r['product_id'], $r['qty'], $r['unit_price']]);
                $pdo->commit();
                $flash = ['ok', 'Penawaran diperbarui.'];
            } else {
                $pdo->beginTransaction();
                $docNumber = next_doc_number($org['organization_id'], 'PENAWARAN-EMAS');
                $pdo->prepare('INSERT INTO quotations_gold (organization_id, doc_number, contact_id, project_id, notes, created_by) VALUES (?,?,?,?,?,?)')
                    ->execute([$org['organization_id'], $docNumber, $contactId, $projectId, $notes, $user['id']]);
                $qId = (int) $pdo->lastInsertId();
                $insLine = $pdo->prepare('INSERT INTO quotation_gold_lines (quotation_id, product_id, qty, unit_price) VALUES (?,?,?,?)');
                foreach ($rows as $r) $insLine->execute([$qId, $r['product_id'], $r['qty'], $r['unit_price']]);
                $pdo->commit();
                $flash = ['ok', "Penawaran $docNumber tersimpan."];
            }
        } elseif ($action === 'set_status') {
            require_module_access('kontak', 'can_edit');
            $qId = (int) ($_POST['quotation_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (!in_array($status, ['sent', 'approved', 'rejected'], true)) throw new RuntimeException('Status tidak valid.');
            $pdo->prepare('UPDATE quotations_gold SET status=? WHERE id=? AND organization_id=?')->execute([$status, $qId, $org['organization_id']]);
            $flash = ['ok', 'Status penawaran diperbarui.'];
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['error', $e->getMessage()];
    }
}

$vendors = $pdo->prepare("SELECT id, name FROM contacts WHERE organization_id=? AND type IN ('vendor','both') ORDER BY name");
$vendors->execute([$org['organization_id']]);
$vendors = $vendors->fetchAll();

$projects = $pdo->prepare('SELECT id, name FROM projects WHERE organization_id=? ORDER BY name');
$projects->execute([$org['organization_id']]);
$projects = $projects->fetchAll();

$products = $pdo->prepare("SELECT p.id, p.name, p.base_price, cat.name AS category_name FROM products p LEFT JOIN product_categories cat ON cat.id = p.category_id WHERE p.organization_id=? AND p.is_active=1 AND p.category_id IS NOT NULL ORDER BY p.name");
$products->execute([$org['organization_id']]);
$products = $products->fetchAll();

$quotations = $pdo->prepare(
    "SELECT qg.*, c.name AS vendor_name,
       (SELECT COUNT(*) FROM quotation_gold_lines l WHERE l.quotation_id = qg.id) AS line_count,
       (SELECT COALESCE(SUM(qty * unit_price),0) FROM quotation_gold_lines l WHERE l.quotation_id = qg.id) AS total,
       (SELECT COUNT(*) FROM purchase_order_gold_lines pgl JOIN quotation_gold_lines qgl ON qgl.id=pgl.quotation_line_id WHERE qgl.quotation_id=qg.id) AS used_in_po_count,
       (SELECT COALESCE(SUM(qty),0) FROM quotation_gold_lines l WHERE l.quotation_id = qg.id) AS qty_offered,
       (SELECT COALESCE(SUM(pgl.qty),0) FROM purchase_order_gold_lines pgl JOIN quotation_gold_lines qgl ON qgl.id=pgl.quotation_line_id WHERE qgl.quotation_id=qg.id) AS qty_po_d,
       (SELECT COUNT(*) FROM inventory_items ii JOIN purchase_order_gold_lines pgl ON pgl.id=ii.po_line_id JOIN quotation_gold_lines qgl ON qgl.id=pgl.quotation_line_id WHERE qgl.quotation_id=qg.id) AS qty_received
     FROM quotations_gold qg
     LEFT JOIN contacts c ON c.id = qg.contact_id
     WHERE qg.organization_id=? ORDER BY qg.id DESC LIMIT 30"
);
$quotations->execute([$org['organization_id']]);
$quotations = $quotations->fetchAll();

$editingQuotation = null;
$editingLines = [];
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM quotations_gold WHERE id=? AND organization_id=?');
    $stmt->execute([(int) $_GET['edit'], $org['organization_id']]);
    $editingQuotation = $stmt->fetch() ?: null;
    if ($editingQuotation) {
        $lStmt = $pdo->prepare('SELECT * FROM quotation_gold_lines WHERE quotation_id=?');
        $lStmt->execute([$editingQuotation['id']]);
        $editingLines = $lStmt->fetchAll();
    }
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if (!$products): ?>
  <div class="card txn-empty">Belum ada produk aktif. Bikin dulu di <a href="product-master.php">Master Produk</a>.</div>
<?php elseif (!$vendors): ?>
  <div class="card txn-empty">Belum ada kontak vendor. Bikin dulu di <a href="contacts.php">Kontak</a>.</div>
<?php else: ?>

<div class="card txn-form-page" style="margin-bottom:20px;">
  <h3 style="margin-top:0;"><?= $editingQuotation ? 'Edit Penawaran ' . htmlspecialchars($editingQuotation['doc_number']) : 'Penawaran Baru' ?></h3>
  <form method="post" id="quot-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_quotation">
    <input type="hidden" name="quotation_id" value="<?= $editingQuotation['id'] ?? '' ?>">
    <div class="field-row">
      <div class="field">
        <label>Vendor</label>
        <select name="contact_id" required>
          <option value="">— pilih —</option>
          <?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>" <?= ($editingQuotation['contact_id'] ?? 0) == $v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Project (opsional)</label>
        <select name="project_id">
          <option value="">— tanpa project —</option>
          <?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" <?= ($editingQuotation['project_id'] ?? 0) == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field"><label>Catatan (opsional)</label><textarea name="notes" rows="2"><?= htmlspecialchars($editingQuotation['notes'] ?? '') ?></textarea></div>

    <table class="data-table" style="margin-bottom:10px;">
      <thead><tr><th style="width:36%;">Produk</th><th style="width:110px;">Qty</th><th style="width:160px;">Harga Satuan</th><th></th></tr></thead>
      <tbody id="quot-lines-body"></tbody>
    </table>
    <button type="button" class="btn btn-sm btn-ghost" onclick="addQuotLine()">+ Tambah Produk</button>

    <div class="txn-totals">
      <div class="row grand"><span>Total</span><span id="quot-grand-total">Rp 0</span></div>
    </div>

    <div class="modal-foot" style="border-top:1px solid var(--border); margin-top:18px; padding-top:14px; justify-content:flex-end;">
      <?php if ($editingQuotation): ?><a class="btn btn-ghost" href="quotation-gold.php">Batal Edit</a><?php endif; ?>
      <button type="submit" class="btn"><?= $editingQuotation ? 'Simpan Perubahan' : 'Simpan Penawaran' ?></button>
    </div>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0;">Penawaran Terakhir</h3>
  <table class="data-table">
    <thead><tr><th>No. Dokumen</th><th>Vendor</th><th class="num">Item</th><th class="num">Total</th><th>Status</th><th style="width:170px;">Pemenuhan (Diterima)</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($quotations as $q):
        $qtyOffered = (float) $q['qty_offered'];
        $qtyReceived = (int) $q['qty_received'];
        $pct = $qtyOffered > 0 ? min(100, round($qtyReceived / $qtyOffered * 100)) : 0;
      ?>
        <tr>
          <td><?= htmlspecialchars($q['doc_number']) ?></td>
          <td><?= htmlspecialchars($q['vendor_name'] ?? '-') ?></td>
          <td class="num"><?= (int) $q['line_count'] ?></td>
          <td class="num">Rp <?= number_format((float) $q['total'], 0, ',', '.') ?></td>
          <td><span class="pill pill-<?= $q['status'] ?>"><?= htmlspecialchars(ucfirst($q['status'])) ?></span></td>
          <td>
            <div class="fulfill-wrap">
              <div class="fulfill-bar"><div class="fulfill-bar-fill<?= $pct >= 100 ? ' full' : '' ?>" style="width:<?= $pct ?>%;"></div></div>
              <span class="fulfill-label"><?= $qtyReceived ?>/<?= (int) $qtyOffered ?></span>
            </div>
          </td>
          <td>
            <?php if ((int) $q['used_in_po_count'] > 0): ?>
              <span style="font-size:11.5px; color:var(--ink-muted);">Terkunci (dipakai PO)</span>
            <?php else: ?>
              <?php if (has_access('kontak', 'can_edit')): ?>
                <a class="btn btn-sm btn-ghost" href="quotation-gold.php?edit=<?= $q['id'] ?>">Edit</a>
              <?php endif; ?>
              <?php if ($q['status'] === 'draft' && has_access('kontak', 'can_edit')): ?>
                <button class="btn btn-sm btn-ghost" type="button" onclick="__submitDeleteForm('set_status', {quotation_id: <?= $q['id'] ?>, status: 'sent'})">Kirim</button>
              <?php elseif ($q['status'] === 'sent' && has_access('kontak', 'can_edit')): ?>
                <button class="btn btn-sm" type="button" onclick="__submitDeleteForm('set_status', {quotation_id: <?= $q['id'] ?>, status: 'approved'})">Approve</button>
                <button class="btn btn-sm btn-ghost" type="button" onclick="__submitDeleteForm('set_status', {quotation_id: <?= $q['id'] ?>, status: 'rejected'})">Tolak</button>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$quotations): ?><tr><td colspan="7" style="text-align:center; color:var(--ink-muted);">Belum ada penawaran.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<script>
var QUOT_PRODUCTS = <?= json_encode(array_map(fn($p) => ['id' => (int) $p['id'], 'label' => $p['name'] . ($p['category_name'] ? ' (' . $p['category_name'] . ')' : ''), 'price' => (float) $p['base_price']], $products)) ?>;
var QUOT_EDIT_LINES = <?= json_encode(array_map(fn($l) => ['product_id' => (int) $l['product_id'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price']], $editingLines)) ?>;
var quotLineIndex = 0;
function addQuotLine(prefill) {
  var tr = document.createElement('tr');
  var opts = '<option value="">— pilih —</option>' + QUOT_PRODUCTS.map(function (p) { return '<option value="' + p.id + '" data-price="' + p.price + '">' + p.label.replace(/</g, '&lt;') + '</option>'; }).join('');
  tr.innerHTML =
    '<td><select name="line_product_id[]" required onchange="quotLineProductChanged(this)">' + opts + '</select></td>' +
    '<td><input type="text" name="line_qty[]" value="1" inputmode="decimal" oninput="recalcQuotTotal()"></td>' +
    '<td><input type="text" name="line_unit_price[]" class="qline-price" inputmode="decimal" value="0" oninput="recalcQuotTotal()"></td>' +
    '<td><button type="button" class="btn btn-sm btn-ghost" onclick="this.closest(\'tr\').remove(); recalcQuotTotal();">Hapus</button></td>';
  document.getElementById('quot-lines-body').appendChild(tr);
  if (prefill) {
    tr.querySelector('select[name="line_product_id[]"]').value = prefill.product_id;
    tr.querySelector('input[name="line_qty[]"]').value = prefill.qty;
    tr.querySelector('input[name="line_unit_price[]"]').value = prefill.unit_price;
  }
  recalcQuotTotal();
}
function quotLineProductChanged(sel) {
  var price = sel.selectedOptions[0] ? (sel.selectedOptions[0].dataset.price || 0) : 0;
  sel.closest('tr').querySelector('.qline-price').value = price;
  recalcQuotTotal();
}
function recalcQuotTotal() {
  var total = 0;
  document.querySelectorAll('#quot-lines-body tr').forEach(function (tr) {
    var qty = parseFloat(tr.querySelector('input[name="line_qty[]"]').value) || 0;
    var price = parseFloat(tr.querySelector('input[name="line_unit_price[]"]').value) || 0;
    total += qty * price;
  });
  document.getElementById('quot-grand-total').textContent = 'Rp ' + total.toLocaleString('id-ID');
}
if (QUOT_EDIT_LINES.length) {
  QUOT_EDIT_LINES.forEach(function (l) { addQuotLine(l); });
} else {
  addQuotLine();
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
