<?php
/**
 * Penawaran emas — header + baris produk (bukan barang serialized spesifik,
 * karena di tahap penawaran barang fisiknya belum tentu ada/dipilih). Kalau
 * disetujui, lanjut manual ke Penjualan buat milih barang fisik aktualnya.
 */
$pageTitle = 'Penawaran';
$activeMenu = 'quotation_gold';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_quotation') {
            require_module_access('kontak', 'can_create');
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            $projectId = (int) ($_POST['project_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes'] ?? '') ?: null;
            $lineProductIds = $_POST['line_product_id'] ?? [];
            $lineQtys = $_POST['line_qty'] ?? [];
            $linePrices = $_POST['line_unit_price'] ?? [];

            if (!$contactId) throw new RuntimeException('Customer wajib dipilih.');
            $rows = [];
            foreach ($lineProductIds as $i => $pid) {
                $pid = (int) $pid;
                if (!$pid) continue;
                $rows[] = ['product_id' => $pid, 'qty' => (float) ($lineQtys[$i] ?? 1), 'unit_price' => (float) ($linePrices[$i] ?? 0)];
            }
            if (!$rows) throw new RuntimeException('Minimal 1 baris produk harus diisi.');

            $pdo->beginTransaction();
            $docNumber = next_doc_number($org['organization_id'], 'PENAWARAN-EMAS');
            $pdo->prepare('INSERT INTO quotations_gold (organization_id, doc_number, contact_id, project_id, notes, created_by) VALUES (?,?,?,?,?,?)')
                ->execute([$org['organization_id'], $docNumber, $contactId, $projectId, $notes, $user['id']]);
            $qId = (int) $pdo->lastInsertId();
            $insLine = $pdo->prepare('INSERT INTO quotation_gold_lines (quotation_id, product_id, qty, unit_price) VALUES (?,?,?,?)');
            foreach ($rows as $r) $insLine->execute([$qId, $r['product_id'], $r['qty'], $r['unit_price']]);
            $pdo->commit();
            $flash = ['ok', "Penawaran $docNumber tersimpan."];
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

$customers = $pdo->prepare("SELECT id, name FROM contacts WHERE organization_id=? AND type IN ('customer','both') ORDER BY name");
$customers->execute([$org['organization_id']]);
$customers = $customers->fetchAll();

$projects = $pdo->prepare('SELECT id, name FROM projects WHERE organization_id=? ORDER BY name');
$projects->execute([$org['organization_id']]);
$projects = $projects->fetchAll();

$products = $pdo->prepare("SELECT p.id, p.name, p.base_price, cat.name AS category_name FROM products p LEFT JOIN product_categories cat ON cat.id = p.category_id WHERE p.organization_id=? AND p.is_active=1 AND p.category_id IS NOT NULL ORDER BY p.name");
$products->execute([$org['organization_id']]);
$products = $products->fetchAll();

$quotations = $pdo->prepare(
    "SELECT qg.*, c.name AS customer_name,
       (SELECT COUNT(*) FROM quotation_gold_lines l WHERE l.quotation_id = qg.id) AS line_count,
       (SELECT COALESCE(SUM(qty * unit_price),0) FROM quotation_gold_lines l WHERE l.quotation_id = qg.id) AS total
     FROM quotations_gold qg
     LEFT JOIN contacts c ON c.id = qg.contact_id
     WHERE qg.organization_id=? ORDER BY qg.id DESC LIMIT 30"
);
$quotations->execute([$org['organization_id']]);
$quotations = $quotations->fetchAll();
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if (!$products): ?>
  <div class="card txn-empty">Belum ada produk aktif. Bikin dulu di <a href="product-master.php">Master Produk</a>.</div>
<?php elseif (!$customers): ?>
  <div class="card txn-empty">Belum ada kontak customer. Bikin dulu di <a href="contacts.php">Kontak</a>.</div>
<?php else: ?>

<div class="card txn-form-page" style="margin-bottom:20px;">
  <h3 style="margin-top:0;">Penawaran Baru</h3>
  <form method="post" id="quot-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_quotation">
    <div class="field-row">
      <div class="field">
        <label>Customer</label>
        <select name="contact_id" required>
          <option value="">— pilih —</option>
          <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
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
      <thead><tr><th style="width:36%;">Produk</th><th style="width:110px;">Qty</th><th style="width:160px;">Harga Satuan</th><th></th></tr></thead>
      <tbody id="quot-lines-body"></tbody>
    </table>
    <button type="button" class="btn btn-sm btn-ghost" onclick="addQuotLine()">+ Tambah Produk</button>

    <div class="modal-foot" style="border-top:1px solid var(--border); margin-top:18px; padding-top:14px; justify-content:flex-end;">
      <button type="submit" class="btn">Simpan Penawaran</button>
    </div>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0;">Penawaran Terakhir</h3>
  <table class="data-table">
    <thead><tr><th>No. Dokumen</th><th>Customer</th><th class="num">Item</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($quotations as $q): ?>
        <tr>
          <td><?= htmlspecialchars($q['doc_number']) ?></td>
          <td><?= htmlspecialchars($q['customer_name'] ?? '-') ?></td>
          <td class="num"><?= (int) $q['line_count'] ?></td>
          <td class="num">Rp <?= number_format((float) $q['total'], 0, ',', '.') ?></td>
          <td><span class="pill pill-<?= $q['status'] ?>"><?= htmlspecialchars(ucfirst($q['status'])) ?></span></td>
          <td>
            <?php if ($q['status'] === 'draft' && has_access('kontak', 'can_edit')): ?>
              <button class="btn btn-sm btn-ghost" type="button" onclick="__submitDeleteForm('set_status', {quotation_id: <?= $q['id'] ?>, status: 'sent'})">Kirim</button>
            <?php elseif ($q['status'] === 'sent' && has_access('kontak', 'can_edit')): ?>
              <button class="btn btn-sm" type="button" onclick="__submitDeleteForm('set_status', {quotation_id: <?= $q['id'] ?>, status: 'approved'})">Approve</button>
              <button class="btn btn-sm btn-ghost" type="button" onclick="__submitDeleteForm('set_status', {quotation_id: <?= $q['id'] ?>, status: 'rejected'})">Tolak</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$quotations): ?><tr><td colspan="6" style="text-align:center; color:var(--ink-muted);">Belum ada penawaran.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<script>
var QUOT_PRODUCTS = <?= json_encode(array_map(fn($p) => ['id' => (int) $p['id'], 'label' => $p['name'] . ($p['category_name'] ? ' (' . $p['category_name'] . ')' : ''), 'price' => (float) $p['base_price']], $products)) ?>;
var quotLineIndex = 0;
function addQuotLine() {
  var tr = document.createElement('tr');
  var opts = '<option value="">— pilih —</option>' + QUOT_PRODUCTS.map(function (p) { return '<option value="' + p.id + '" data-price="' + p.price + '">' + p.label.replace(/</g, '&lt;') + '</option>'; }).join('');
  tr.innerHTML =
    '<td><select name="line_product_id[]" required onchange="this.closest(\'tr\').querySelector(\'.qline-price\').value = this.selectedOptions[0].dataset.price || 0">' + opts + '</select></td>' +
    '<td><input type="text" name="line_qty[]" value="1" inputmode="decimal"></td>' +
    '<td><input type="text" name="line_unit_price[]" class="qline-price" inputmode="decimal" value="0"></td>' +
    '<td><button type="button" class="btn btn-sm btn-ghost" onclick="this.closest(\'tr\').remove()">Hapus</button></td>';
  document.getElementById('quot-lines-body').appendChild(tr);
}
addQuotLine();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
