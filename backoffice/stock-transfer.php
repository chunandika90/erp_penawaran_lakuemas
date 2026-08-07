<?php
/**
 * Transfer stock antar lokasi — 1 dokumen, 2 tahap status: 'dikirim' (barang
 * jadi in_transit, keluar dari lokasi asal) lalu 'diterima' (barang pindah ke
 * lokasi tujuan, balik jadi in_stock). Sengaja gak dipisah jadi 2 menu biar
 * konsisten sama pola dokumen lain di app ini (draft -> approved dst).
 */
$pageTitle = 'Transfer Stock';
$activeMenu = 'stock_transfer';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_transfer') {
            require_module_access('kontak', 'can_create');
            $fromId = (int) ($_POST['from_location_id'] ?? 0);
            $toId = (int) ($_POST['to_location_id'] ?? 0);
            $projectId = (int) ($_POST['project_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes'] ?? '') ?: null;
            $itemIds = array_map('intval', $_POST['item_ids'] ?? []);
            if (!$fromId || !$toId) throw new RuntimeException('Lokasi asal dan tujuan wajib dipilih.');
            if ($fromId === $toId) throw new RuntimeException('Lokasi asal dan tujuan tidak boleh sama.');
            if (!$itemIds) throw new RuntimeException('Pilih minimal 1 barang buat ditransfer.');

            $pdo->beginTransaction();
            $check = $pdo->prepare("SELECT id FROM inventory_items WHERE id=? AND organization_id=? AND location_id=? AND status='in_stock' FOR UPDATE");
            foreach ($itemIds as $iid) {
                $check->execute([$iid, $org['organization_id'], $fromId]);
                if (!$check->fetch()) throw new RuntimeException("Barang #$iid udah gak tersedia di lokasi asal (mungkin udah ditransfer/lebur duluan).");
            }
            $docNumber = next_doc_number($org['organization_id'], 'TRANSFER');
            $pdo->prepare('INSERT INTO stock_transfers (organization_id, doc_number, from_location_id, to_location_id, project_id, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$org['organization_id'], $docNumber, $fromId, $toId, $projectId, $notes, $user['id']]);
            $transferId = (int) $pdo->lastInsertId();
            $insLine = $pdo->prepare('INSERT INTO stock_transfer_lines (stock_transfer_id, inventory_item_id) VALUES (?,?)');
            $markTransit = $pdo->prepare("UPDATE inventory_items SET status='in_transit' WHERE id=?");
            foreach ($itemIds as $iid) {
                $insLine->execute([$transferId, $iid]);
                $markTransit->execute([$iid]);
            }
            $pdo->commit();
            $flash = ['ok', "Transfer $docNumber dikirim, " . count($itemIds) . ' barang menuju lokasi tujuan.'];
        } elseif ($action === 'receive_transfer') {
            require_module_access('kontak', 'can_edit');
            $transferId = (int) ($_POST['transfer_id'] ?? 0);
            $pdo->beginTransaction();
            $t = $pdo->prepare("SELECT * FROM stock_transfers WHERE id=? AND organization_id=? AND status='dikirim' FOR UPDATE");
            $t->execute([$transferId, $org['organization_id']]);
            $t = $t->fetch();
            if (!$t) throw new RuntimeException('Transfer tidak ditemukan atau sudah diterima.');
            $lines = $pdo->prepare('SELECT inventory_item_id FROM stock_transfer_lines WHERE stock_transfer_id=?');
            $lines->execute([$transferId]);
            $moveItem = $pdo->prepare("UPDATE inventory_items SET location_id=?, status='in_stock' WHERE id=?");
            foreach ($lines->fetchAll() as $line) {
                $moveItem->execute([$t['to_location_id'], $line['inventory_item_id']]);
            }
            $pdo->prepare("UPDATE stock_transfers SET status='diterima', received_by=?, received_at=NOW() WHERE id=?")
                ->execute([$user['id'], $transferId]);
            $pdo->commit();
            $flash = ['ok', 'Transfer diterima, stock udah pindah ke lokasi tujuan.'];
        }
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

$availableItems = $pdo->prepare(
    "SELECT ii.id, ii.location_id, ii.plu_code, ii.certificate_code, p.name AS product_name
     FROM inventory_items ii JOIN products p ON p.id = ii.product_id
     WHERE ii.organization_id=? AND ii.status='in_stock' ORDER BY p.name, ii.plu_code"
);
$availableItems->execute([$org['organization_id']]);
$availableItems = $availableItems->fetchAll();

$transfers = $pdo->prepare(
    "SELECT st.*, fl.name AS from_name, tl.name AS to_name,
       (SELECT COUNT(*) FROM stock_transfer_lines l WHERE l.stock_transfer_id = st.id) AS item_count
     FROM stock_transfers st
     LEFT JOIN locations fl ON fl.id = st.from_location_id
     LEFT JOIN locations tl ON tl.id = st.to_location_id
     WHERE st.organization_id=? ORDER BY st.id DESC LIMIT 30"
);
$transfers->execute([$org['organization_id']]);
$transfers = $transfers->fetchAll();
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if (!$locations): ?>
  <div class="card txn-empty">Belum ada lokasi. Bikin dulu di <a href="locations.php">Lokasi</a>.</div>
<?php elseif (!$availableItems): ?>
  <div class="card txn-empty">Belum ada barang di stock (in_stock). Terima barang dulu lewat <a href="goods-receipt-gold.php">Penerimaan Barang</a>.</div>
<?php else: ?>

<div class="card txn-form-page" style="margin-bottom:20px;">
  <h3 style="margin-top:0;">Buat Transfer Baru</h3>
  <form method="post" id="transfer-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_transfer">
    <div class="field-row">
      <div class="field">
        <label>Dari Lokasi</label>
        <select name="from_location_id" id="from_location_id" required onchange="filterTransferItems()">
          <option value="">— pilih —</option>
          <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['group_name'] . ' › ' . $l['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Ke Lokasi</label>
        <select name="to_location_id" required>
          <option value="">— pilih —</option>
          <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['group_name'] . ' › ' . $l['name']) ?></option><?php endforeach; ?>
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
      <label>Barang (pilih dari lokasi asal)</label>
      <div id="transfer-item-list" style="border:1px solid var(--border-strong); border-radius:8px; max-height:260px; overflow-y:auto;">
        <?php foreach ($availableItems as $it): ?>
          <label class="transfer-item-row" data-location="<?= $it['location_id'] ?>" style="display:none; align-items:center; gap:10px; padding:8px 12px; border-bottom:1px solid var(--border-hair); font-size:13px;">
            <input type="checkbox" name="item_ids[]" value="<?= $it['id'] ?>" style="width:auto;">
            <span style="font-weight:600;"><?= htmlspecialchars($it['product_name']) ?></span>
            <span style="color:var(--ink-muted);">PLU <?= htmlspecialchars($it['plu_code']) ?><?php if ($it['certificate_code']): ?> · Sert. <?= htmlspecialchars($it['certificate_code']) ?><?php endif; ?></span>
          </label>
        <?php endforeach; ?>
        <div id="transfer-item-empty" style="padding:14px; text-align:center; color:var(--ink-muted); font-size:13px;">Pilih lokasi asal dulu.</div>
      </div>
    </div>

    <div class="modal-foot" style="border-top:1px solid var(--border); margin-top:18px; padding-top:14px; justify-content:flex-end;">
      <button type="submit" class="btn">Kirim Transfer</button>
    </div>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0;">Riwayat Transfer</h3>
  <table class="data-table">
    <thead><tr><th>No. Dokumen</th><th>Dari</th><th>Ke</th><th class="num">Jumlah</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($transfers as $t): ?>
        <tr>
          <td><?= htmlspecialchars($t['doc_number']) ?></td>
          <td><?= htmlspecialchars($t['from_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars($t['to_name'] ?? '-') ?></td>
          <td class="num"><?= (int) $t['item_count'] ?></td>
          <td><span class="pill <?= $t['status'] === 'diterima' ? 'active' : ($t['status'] === 'void' ? '' : 'pill-pending') ?>"><?= htmlspecialchars(ucfirst($t['status'])) ?></span></td>
          <td>
            <?php if ($t['status'] === 'dikirim' && has_access('kontak', 'can_edit')): ?>
              <button class="btn btn-sm" type="button" onclick="if(confirm('Konfirmasi barang udah sampai di lokasi tujuan?')) __submitDeleteForm('receive_transfer', {transfer_id: <?= $t['id'] ?>})">Terima</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$transfers): ?><tr><td colspan="6" style="text-align:center; color:var(--ink-muted);">Belum ada transfer.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<script>
function filterTransferItems() {
  var fromId = document.getElementById('from_location_id').value;
  var rows = document.querySelectorAll('.transfer-item-row');
  var shown = 0;
  rows.forEach(function (row) {
    var match = fromId && row.dataset.location === fromId;
    row.style.display = match ? 'flex' : 'none';
    if (!match) row.querySelector('input').checked = false;
    if (match) shown++;
  });
  document.getElementById('transfer-item-empty').style.display = (fromId && shown === 0) ? 'block' : (fromId ? 'none' : 'block');
  document.getElementById('transfer-item-empty').textContent = fromId ? 'Gak ada barang in-stock di lokasi ini.' : 'Pilih lokasi asal dulu.';
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
