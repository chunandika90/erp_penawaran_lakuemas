<?php
/**
 * PO emas — header + baris produk yang mau dibeli dari vendor. PO ini cuma
 * "rencana/komitmen beli", barang fisik aktual (kode sertifikat, berat, PLU)
 * dicatat pas Penerimaan Barang — yang bisa nempel balik ke PO ini (opsional).
 */
$pageTitle = 'PO';
$activeMenu = 'po_gold';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_po') {
            require_module_access('kontak', 'can_create');
            $vendorId = (int) ($_POST['vendor_id'] ?? 0);
            $projectId = (int) ($_POST['project_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes'] ?? '') ?: null;
            $lineProductIds = $_POST['line_product_id'] ?? [];
            $lineQtys = $_POST['line_qty'] ?? [];
            $lineCosts = $_POST['line_unit_cost'] ?? [];

            if (!$vendorId) throw new RuntimeException('Vendor wajib dipilih.');
            $rows = [];
            foreach ($lineProductIds as $i => $pid) {
                $pid = (int) $pid;
                if (!$pid) continue;
                $rows[] = ['product_id' => $pid, 'qty' => (float) ($lineQtys[$i] ?? 1), 'unit_cost' => (float) ($lineCosts[$i] ?? 0)];
            }
            if (!$rows) throw new RuntimeException('Minimal 1 baris produk harus diisi.');

            $pdo->beginTransaction();
            $docNumber = next_doc_number($org['organization_id'], 'PO-EMAS');
            $pdo->prepare('INSERT INTO purchase_orders_gold (organization_id, doc_number, vendor_id, project_id, notes, created_by) VALUES (?,?,?,?,?,?)')
                ->execute([$org['organization_id'], $docNumber, $vendorId, $projectId, $notes, $user['id']]);
            $poId = (int) $pdo->lastInsertId();
            $insLine = $pdo->prepare('INSERT INTO purchase_order_gold_lines (po_id, product_id, qty, unit_cost) VALUES (?,?,?,?)');
            foreach ($rows as $r) $insLine->execute([$poId, $r['product_id'], $r['qty'], $r['unit_cost']]);
            $pdo->commit();
            $flash = ['ok', "PO $docNumber tersimpan."];
        } elseif ($action === 'set_status') {
            require_module_access('kontak', 'can_edit');
            $poId = (int) ($_POST['po_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (!in_array($status, ['sent', 'void'], true)) throw new RuntimeException('Status tidak valid.');
            $pdo->prepare('UPDATE purchase_orders_gold SET status=? WHERE id=? AND organization_id=?')->execute([$status, $poId, $org['organization_id']]);
            $flash = ['ok', 'Status PO diperbarui.'];
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

$products = $pdo->prepare("SELECT p.id, p.name, cat.name AS category_name FROM products p LEFT JOIN product_categories cat ON cat.id = p.category_id WHERE p.organization_id=? AND p.is_active=1 AND p.category_id IS NOT NULL ORDER BY p.name");
$products->execute([$org['organization_id']]);
$products = $products->fetchAll();

$pos = $pdo->prepare(
    "SELECT po.*, v.name AS vendor_name,
       (SELECT COUNT(*) FROM purchase_order_gold_lines l WHERE l.po_id = po.id) AS line_count,
       (SELECT COALESCE(SUM(qty * unit_cost),0) FROM purchase_order_gold_lines l WHERE l.po_id = po.id) AS total,
       (SELECT COUNT(*) FROM gold_goods_receipts gr WHERE gr.po_id = po.id) AS receipt_count
     FROM purchase_orders_gold po
     LEFT JOIN contacts v ON v.id = po.vendor_id
     WHERE po.organization_id=? ORDER BY po.id DESC LIMIT 30"
);
$pos->execute([$org['organization_id']]);
$pos = $pos->fetchAll();
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if (!$products): ?>
  <div class="card txn-empty">Belum ada produk aktif. Bikin dulu di <a href="product-master.php">Master Produk</a>.</div>
<?php elseif (!$vendors): ?>
  <div class="card txn-empty">Belum ada kontak vendor. Bikin dulu di <a href="contacts.php">Kontak</a>.</div>
<?php else: ?>

<div class="card txn-form-page" style="margin-bottom:20px;">
  <h3 style="margin-top:0;">PO Baru</h3>
  <form method="post" id="po-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_po">
    <div class="field-row">
      <div class="field">
        <label>Vendor</label>
        <select name="vendor_id" required>
          <option value="">— pilih —</option>
          <?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
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

    <table class="data-table" style="margin-bottom:10px;">
      <thead><tr><th style="width:36%;">Produk</th><th style="width:110px;">Qty</th><th style="width:160px;">Harga Beli/Satuan</th><th></th></tr></thead>
      <tbody id="po-lines-body"></tbody>
    </table>
    <button type="button" class="btn btn-sm btn-ghost" onclick="addPoLine()">+ Tambah Produk</button>

    <div class="modal-foot" style="border-top:1px solid var(--border); margin-top:18px; padding-top:14px; justify-content:flex-end;">
      <button type="submit" class="btn">Simpan PO</button>
    </div>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0;">PO Terakhir</h3>
  <table class="data-table">
    <thead><tr><th>No. Dokumen</th><th>Vendor</th><th class="num">Item</th><th class="num">Total</th><th>Status</th><th>Penerimaan</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($pos as $p): ?>
        <tr>
          <td><?= htmlspecialchars($p['doc_number']) ?></td>
          <td><?= htmlspecialchars($p['vendor_name'] ?? '-') ?></td>
          <td class="num"><?= (int) $p['line_count'] ?></td>
          <td class="num">Rp <?= number_format((float) $p['total'], 0, ',', '.') ?></td>
          <td><span class="pill <?= $p['status'] === 'received' ? 'pill-received' : ($p['status'] === 'void' ? 'pill-void' : ($p['status'] === 'sent' ? 'pill-sent' : 'pill-draft')) ?>"><?= htmlspecialchars(ucfirst($p['status'])) ?></span></td>
          <td><?= (int) $p['receipt_count'] > 0 ? (int) $p['receipt_count'] . ' dokumen' : '-' ?></td>
          <td>
            <?php if ($p['status'] === 'draft' && has_access('kontak', 'can_edit')): ?>
              <button class="btn btn-sm" type="button" onclick="__submitDeleteForm('set_status', {po_id: <?= $p['id'] ?>, status: 'sent'})">Kirim</button>
              <button class="btn btn-sm btn-ghost" type="button" onclick="if(confirm('Batalin PO ini?')) __submitDeleteForm('set_status', {po_id: <?= $p['id'] ?>, status: 'void'})">Batal</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$pos): ?><tr><td colspan="7" style="text-align:center; color:var(--ink-muted);">Belum ada PO.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<script>
var PO_PRODUCTS = <?= json_encode(array_map(fn($p) => ['id' => (int) $p['id'], 'label' => $p['name'] . ($p['category_name'] ? ' (' . $p['category_name'] . ')' : '')], $products)) ?>;
function addPoLine() {
  var tr = document.createElement('tr');
  var opts = '<option value="">— pilih —</option>' + PO_PRODUCTS.map(function (p) { return '<option value="' + p.id + '">' + p.label.replace(/</g, '&lt;') + '</option>'; }).join('');
  tr.innerHTML =
    '<td><select name="line_product_id[]" required>' + opts + '</select></td>' +
    '<td><input type="text" name="line_qty[]" value="1" inputmode="decimal"></td>' +
    '<td><input type="text" name="line_unit_cost[]" inputmode="decimal" value="0"></td>' +
    '<td><button type="button" class="btn btn-sm btn-ghost" onclick="this.closest(\'tr\').remove()">Hapus</button></td>';
  document.getElementById('po-lines-body').appendChild(tr);
}
addPoLine();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
