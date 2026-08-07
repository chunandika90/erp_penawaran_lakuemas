<?php
/**
 * Penjualan emas — pilih barang serialized yang in_stock, jual ke customer,
 * status barang jadi 'sold'. Ini yang nutup siklus stock: masuk (GR) -> pindah
 * (transfer) -> keluar (jual / lebur / retur).
 */
$pageTitle = 'Penjualan';
$activeMenu = 'sale_gold';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_sale') {
    require_csrf();
    require_module_access('kontak', 'can_create');
    try {
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $projectId = (int) ($_POST['project_id'] ?? 0) ?: null;
        $quotationId = (int) ($_POST['quotation_id'] ?? 0) ?: null;
        $notes = trim($_POST['notes'] ?? '') ?: null;
        $itemIds = $_POST['item_ids'] ?? [];
        $itemPrices = $_POST['item_price'] ?? [];

        if (!$contactId) throw new RuntimeException('Customer wajib dipilih.');
        $lines = [];
        foreach ($itemIds as $iid) {
            $iid = (int) $iid;
            if (!$iid) continue;
            $lines[] = ['inventory_item_id' => $iid, 'unit_price' => (float) ($itemPrices[$iid] ?? 0)];
        }
        if (!$lines) throw new RuntimeException('Pilih minimal 1 barang buat dijual.');

        $pdo->beginTransaction();
        $check = $pdo->prepare("SELECT id FROM inventory_items WHERE id=? AND organization_id=? AND status='in_stock' FOR UPDATE");
        foreach ($lines as $l) {
            $check->execute([$l['inventory_item_id'], $org['organization_id']]);
            if (!$check->fetch()) throw new RuntimeException("Barang #{$l['inventory_item_id']} udah gak tersedia buat dijual.");
        }

        $docNumber = next_doc_number($org['organization_id'], 'JUAL-EMAS');
        $pdo->prepare('INSERT INTO sales_gold (organization_id, doc_number, contact_id, project_id, quotation_id, notes, sold_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$org['organization_id'], $docNumber, $contactId, $projectId, $quotationId, $notes, $user['id']]);
        $saleId = (int) $pdo->lastInsertId();
        $insLine = $pdo->prepare('INSERT INTO sales_gold_lines (sale_id, inventory_item_id, unit_price) VALUES (?,?,?)');
        $markSold = $pdo->prepare("UPDATE inventory_items SET status='sold' WHERE id=?");
        foreach ($lines as $l) {
            $insLine->execute([$saleId, $l['inventory_item_id'], $l['unit_price']]);
            $markSold->execute([$l['inventory_item_id']]);
        }
        if ($quotationId) {
            $pdo->prepare("UPDATE quotations_gold SET status='approved' WHERE id=? AND organization_id=? AND status != 'approved'")->execute([$quotationId, $org['organization_id']]);
        }
        $pdo->commit();
        $total = array_sum(array_column($lines, 'unit_price'));
        $flash = ['ok', "Penjualan $docNumber tersimpan, " . count($lines) . ' barang terjual, total Rp ' . number_format($total, 0, ',', '.') . '.'];
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

$approvedQuotations = $pdo->prepare(
    "SELECT qg.id, qg.doc_number, c.name AS customer_name FROM quotations_gold qg
     LEFT JOIN contacts c ON c.id = qg.contact_id
     WHERE qg.organization_id=? AND qg.status='approved' ORDER BY qg.id DESC"
);
$approvedQuotations->execute([$org['organization_id']]);
$approvedQuotations = $approvedQuotations->fetchAll();

$availableItems = $pdo->prepare(
    "SELECT ii.id, ii.plu_code, ii.certificate_code, ii.weight, ii.product_id, p.name AS product_name, p.base_price
     FROM inventory_items ii JOIN products p ON p.id = ii.product_id
     WHERE ii.organization_id=? AND ii.status='in_stock' ORDER BY p.name, ii.plu_code"
);
$availableItems->execute([$org['organization_id']]);
$availableItems = $availableItems->fetchAll();

$sales = $pdo->prepare(
    "SELECT sg.*, c.name AS customer_name,
       (SELECT COUNT(*) FROM sales_gold_lines l WHERE l.sale_id = sg.id) AS item_count,
       (SELECT COALESCE(SUM(unit_price),0) FROM sales_gold_lines l WHERE l.sale_id = sg.id) AS total
     FROM sales_gold sg
     LEFT JOIN contacts c ON c.id = sg.contact_id
     WHERE sg.organization_id=? ORDER BY sg.id DESC LIMIT 30"
);
$sales->execute([$org['organization_id']]);
$sales = $sales->fetchAll();
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if (!$availableItems): ?>
  <div class="card txn-empty">Belum ada barang in-stock buat dijual.</div>
<?php elseif (!$customers): ?>
  <div class="card txn-empty">Belum ada kontak customer. Bikin dulu di <a href="contacts.php">Kontak</a>.</div>
<?php else: ?>

<div class="card txn-form-page" style="margin-bottom:20px;">
  <h3 style="margin-top:0;">Penjualan Baru</h3>
  <form method="post" id="sale-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_sale">
    <div class="field-row">
      <div class="field">
        <label>Customer</label>
        <select name="contact_id" required>
          <option value="">— pilih —</option>
          <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Dari Penawaran (opsional)</label>
        <select name="quotation_id">
          <option value="">— tanpa penawaran —</option>
          <?php foreach ($approvedQuotations as $q): ?><option value="<?= $q['id'] ?>"><?= htmlspecialchars($q['doc_number'] . ($q['customer_name'] ? ' — ' . $q['customer_name'] : '')) ?></option><?php endforeach; ?>
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

    <div class="field">
      <label>Barang yang dijual</label>
      <div style="border:1px solid var(--border-strong); border-radius:8px; max-height:280px; overflow-y:auto;">
        <table class="data-table" style="margin:0;">
          <thead><tr><th style="width:26px;"></th><th>Produk</th><th>PLU / Sertifikat</th><th style="width:150px;">Harga Jual</th></tr></thead>
          <tbody>
            <?php foreach ($availableItems as $it): ?>
              <tr>
                <td><input type="checkbox" name="item_ids[]" value="<?= $it['id'] ?>" class="sale-item-cb" onchange="toggleSalePrice(<?= $it['id'] ?>)" style="width:auto;"></td>
                <td><?= htmlspecialchars($it['product_name']) ?><?= $it['weight'] !== null ? ' <span style="color:var(--ink-muted); font-size:12px;">(' . number_format((float) $it['weight'], 2, ',', '.') . ' gr)</span>' : '' ?></td>
                <td style="font-size:12px; color:var(--ink-muted);">PLU <?= htmlspecialchars($it['plu_code']) ?><?php if ($it['certificate_code']): ?> · <?= htmlspecialchars($it['certificate_code']) ?><?php endif; ?></td>
                <td><input type="text" name="item_price[<?= $it['id'] ?>]" id="sale-price-<?= $it['id'] ?>" inputmode="decimal" value="<?= (float) $it['base_price'] ?>" disabled style="width:100%; padding:6px 8px; border:1px solid var(--border-strong); border-radius:6px;"></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="modal-foot" style="border-top:1px solid var(--border); margin-top:8px; padding-top:14px; justify-content:flex-end;">
      <button type="submit" class="btn">Simpan Penjualan</button>
    </div>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0;">Penjualan Terakhir</h3>
  <table class="data-table">
    <thead><tr><th>No. Dokumen</th><th>Customer</th><th class="num">Jumlah Barang</th><th class="num">Total</th><th>Tanggal</th></tr></thead>
    <tbody>
      <?php foreach ($sales as $s): ?>
        <tr>
          <td><?= htmlspecialchars($s['doc_number']) ?></td>
          <td><?= htmlspecialchars($s['customer_name'] ?? '-') ?></td>
          <td class="num"><?= (int) $s['item_count'] ?></td>
          <td class="num">Rp <?= number_format((float) $s['total'], 0, ',', '.') ?></td>
          <td><?= htmlspecialchars(date('d M Y H:i', strtotime($s['sold_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$sales): ?><tr><td colspan="5" style="text-align:center; color:var(--ink-muted);">Belum ada penjualan.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<script>
function toggleSalePrice(id) {
  var cb = document.querySelector('.sale-item-cb[value="' + id + '"]');
  document.getElementById('sale-price-' + id).disabled = !cb.checked;
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
