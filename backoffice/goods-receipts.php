<?php
$pageTitle = 'Penerimaan Barang';
$activeMenu = 'penerimaan';
require __DIR__ . '/includes/header.php';
require_module_access('penerimaan');
require_once __DIR__ . '/../backoffice-shared/doc_number.php';

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_receipt') {
            require_module_access('penerimaan', 'can_create');
            $poId = (int) ($_POST['po_id'] ?? 0);
            $destination = $_POST['destination'] ?? 'warehouse';
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $qtys = $_POST['receive_qty'] ?? [];
            if (!$poId) throw new RuntimeException('PO wajib dipilih.');
            if (!$warehouseId) throw new RuntimeException('Gudang wajib dipilih (tetap dipakai buat catat stok & HPP walau destination langsung ke customer).');
            if (!in_array($destination, ['warehouse', 'direct_customer'], true)) $destination = 'warehouse';

            $pdo->beginTransaction();
            try {
                $poCheck = $pdo->prepare('SELECT * FROM purchase_orders WHERE id=? AND organization_id=?');
                $poCheck->execute([$poId, $org['organization_id']]);
                $po = $poCheck->fetch();
                if (!$po) throw new RuntimeException('PO tidak ditemukan.');

                $docNumber = next_doc_number($org['organization_id'], 'penerimaan');
                $pdo->prepare('INSERT INTO goods_receipts (organization_id, doc_number, po_id, warehouse_id, destination, received_by) VALUES (?,?,?,?,?,?)')
                    ->execute([$org['organization_id'], $docNumber, $poId, $warehouseId, $destination, $user['id']]);
                $receiptId = (int) $pdo->lastInsertId();

                $lineStmt = $pdo->prepare('SELECT * FROM po_lines WHERE id=? AND po_id=?');
                $insertGrLine = $pdo->prepare('INSERT INTO goods_receipt_lines (goods_receipt_id, po_line_id, product_id, material_id, item_name, qty, unit_cost) VALUES (?,?,?,?,?,?,?)');
                $insertLedger = $pdo->prepare('INSERT INTO stock_ledger (organization_id, warehouse_id, product_id, material_id, direction, qty, qty_remaining, unit_cost, ref_type, ref_id) VALUES (?,?,?,?,"in",?,?,?,"goods_receipt",?)');
                $updatePoLine = $pdo->prepare('UPDATE po_lines SET received_qty = received_qty + ? WHERE id=?');

                $received = 0;
                foreach ($qtys as $lineId => $qty) {
                    $qty = (float) $qty;
                    if ($qty <= 0) continue;
                    $lineStmt->execute([(int) $lineId, $poId]);
                    $line = $lineStmt->fetch();
                    if (!$line) continue;
                    $remaining = (float) $line['qty'] - (float) $line['received_qty'];
                    if ($qty > $remaining) $qty = $remaining;
                    if ($qty <= 0) continue;

                    $insertGrLine->execute([$receiptId, $line['id'], $line['product_id'], $line['material_id'], $line['item_name'], $qty, $line['unit_cost']]);
                    // Item bahan baku manual (bukan produk katalog ATAU material katalog) gak masuk
                    // stock_ledger — cuma tercatat di goods_receipt_lines, gak ke-track stoknya.
                    if ($line['product_id'] || $line['material_id']) {
                        $insertLedger->execute([$org['organization_id'], $warehouseId, $line['product_id'], $line['material_id'], $qty, $qty, $line['unit_cost'], $receiptId]);
                    }
                    $updatePoLine->execute([$qty, $line['id']]);
                    $received += $qty;
                }
                if ($received <= 0) throw new RuntimeException('Tidak ada qty valid yang diterima.');

                $allLines = $pdo->prepare('SELECT qty, received_qty FROM po_lines WHERE po_id=?');
                $allLines->execute([$poId]);
                $fullyReceived = true;
                $anyReceived = false;
                foreach ($allLines->fetchAll() as $l) {
                    if ((float) $l['received_qty'] > 0) $anyReceived = true;
                    if ((float) $l['received_qty'] < (float) $l['qty']) $fullyReceived = false;
                }
                $newStatus = $fullyReceived ? 'received' : ($anyReceived ? 'partial' : $po['status']);
                $pdo->prepare('UPDATE purchase_orders SET status=? WHERE id=?')->execute([$newStatus, $poId]);

                $pdo->commit();
                header('Location: goods-receipts.php?id=' . $receiptId);
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    } catch (Throwable $e) {
        $flash = ['error', $e->getMessage()];
    }
}

$warehouses = $pdo->prepare('SELECT id, name FROM warehouses WHERE organization_id=? ORDER BY is_default DESC, name');
$warehouses->execute([$org['organization_id']]);
$warehouses = $warehouses->fetchAll();

$isNewForm = isset($_GET['new']);

if ($isNewForm) {
    $openPos = $pdo->prepare(
        "SELECT po.id, po.doc_number, po.destination_warehouse_id, c.name AS vendor_name FROM purchase_orders po JOIN contacts c ON c.id=po.vendor_id
         WHERE po.organization_id=? AND po.status IN ('sent','partial')
           AND EXISTS (SELECT 1 FROM po_lines pl WHERE pl.po_id=po.id AND pl.received_qty < pl.qty)
         ORDER BY po.created_at"
    );
    $openPos->execute([$org['organization_id']]);
    $openPos = $openPos->fetchAll();

    $poLinesByPo = [];
    if ($openPos) {
        $ph = implode(',', array_fill(0, count($openPos), '?'));
        $stmt = $pdo->prepare("SELECT * FROM po_lines WHERE po_id IN ($ph) AND received_qty < qty");
        $stmt->execute(array_column($openPos, 'id'));
        foreach ($stmt->fetchAll() as $row) {
            $poLinesByPo[$row['po_id']][] = $row;
        }
    }
} else {
    $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
    $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

    // LEFT JOIN ke po DAN spk — penerimaan bisa dari salah satunya (SPK bikin
    // goods_receipt tanpa po_id, jadi INNER JOIN ke po doang bikin baris itu ilang).
    $railStmt = $pdo->prepare(
        "SELECT gr.*, po.doc_number AS po_doc_number, s.doc_number AS spk_doc_number, w.name AS warehouse_name
         FROM goods_receipts gr
         LEFT JOIN purchase_orders po ON po.id=gr.po_id
         LEFT JOIN spk s ON s.id=gr.spk_id
         LEFT JOIN warehouses w ON w.id=gr.warehouse_id
         WHERE gr.organization_id=? AND DATE_FORMAT(gr.received_at,'%Y-%m')=? ORDER BY gr.received_at DESC"
    );
    $railStmt->execute([$org['organization_id'], $month]);
    $railItems = $railStmt->fetchAll();

    $selectedId = (int) ($_GET['id'] ?? ($railItems[0]['id'] ?? 0));
    $selected = null;
    foreach ($railItems as $r) { if ((int) $r['id'] === $selectedId) { $selected = $r; break; } }
    if (!$selected && $selectedId) {
        $sStmt = $pdo->prepare(
            "SELECT gr.*, po.doc_number AS po_doc_number, s.doc_number AS spk_doc_number, w.name AS warehouse_name
             FROM goods_receipts gr
             LEFT JOIN purchase_orders po ON po.id=gr.po_id
             LEFT JOIN spk s ON s.id=gr.spk_id
             LEFT JOIN warehouses w ON w.id=gr.warehouse_id
             WHERE gr.id=? AND gr.organization_id=?"
        );
        $sStmt->execute([$selectedId, $org['organization_id']]);
        $selected = $sStmt->fetch() ?: null;
    }

    $selectedLines = [];
    if ($selected) {
        $lStmt = $pdo->prepare('SELECT * FROM goods_receipt_lines WHERE goods_receipt_id=?');
        $lStmt->execute([$selected['id']]);
        $selectedLines = $lStmt->fetchAll();
    }
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if ($isNewForm): ?>
  <!-- ===================== FORM FULL PAGE: CATAT PENERIMAAN ===================== -->
  <div class="card txn-form-page">
    <div class="txn-detail-header"><h2>Catat Penerimaan Barang</h2><a class="btn btn-sm btn-ghost" href="goods-receipts.php">Batal</a></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_receipt">
      <div class="field-row">
        <div class="field">
          <label>Purchase Order</label>
          <select name="po_id" id="receipt-po" required>
            <option value="">— Pilih PO yang masih ada sisa qty —</option>
            <?php foreach ($openPos as $po): ?><option value="<?= $po['id'] ?>"><?= htmlspecialchars($po['doc_number']) ?> — <?= htmlspecialchars($po['vendor_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Gudang</label>
          <select name="warehouse_id" id="receipt-warehouse" required>
            <option value="">— Pilih Gudang —</option>
            <?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option><?php endforeach; ?>
          </select>
          <div id="receipt-warehouse-hint" style="display:none; font-size:11.5px; color:var(--accent-text); margin-top:4px;">📦 PO ini ditandai kirim ke gudang vendor — otomatis dipilih.</div>
        </div>
      </div>
      <div class="field">
        <label>Tujuan</label>
        <select name="destination">
          <option value="warehouse">Masuk Gudang</option>
          <option value="direct_customer">Langsung ke Customer (skip gudang)</option>
        </select>
      </div>
      <label style="display:block; font-size:12px; font-weight:600; margin:14px 0 6px;">Item yang Diterima</label>
      <div id="receipt-line-picker" style="font-size:13px; color:var(--ink-muted);">Pilih PO dulu.</div>

      <div style="margin-top:16px;"><button type="submit" class="btn">Simpan Penerimaan</button></div>
    </form>
  </div>

  <script>
  var PO_LINES = <?= json_encode($poLinesByPo) ?>;
  var PO_DEST_WAREHOUSE = <?= json_encode(array_column($openPos, 'destination_warehouse_id', 'id')) ?>;
  document.getElementById('receipt-po').addEventListener('change', function () {
    var destWarehouseId = PO_DEST_WAREHOUSE[this.value];
    var warehouseSelect = document.getElementById('receipt-warehouse');
    var hint = document.getElementById('receipt-warehouse-hint');
    if (destWarehouseId) {
      warehouseSelect.value = destWarehouseId;
      hint.style.display = 'block';
    } else {
      hint.style.display = 'none';
    }

    var picker = document.getElementById('receipt-line-picker');
    var lines = PO_LINES[this.value];
    if (!lines || !lines.length) { picker.innerHTML = 'Tidak ada sisa item di PO ini.'; return; }
    var html = '';
    lines.forEach(function (l) {
      var remaining = (parseFloat(l.qty) - parseFloat(l.received_qty));
      html += '<div style="display:flex; gap:8px; align-items:center; padding:6px 0; border-bottom:1px solid var(--border);">' +
        '<span style="flex:1;">' + l.item_name + ' <small style="color:var(--ink-muted);">(sisa ' + remaining + ')</small></span>' +
        '<input type="number" name="receive_qty[' + l.id + ']" value="' + remaining + '" max="' + remaining + '" step="0.01" style="width:100px;padding:6px;border:1px solid var(--border);border-radius:4px;">' +
        '</div>';
    });
    picker.innerHTML = html;
  });
  </script>

<?php else: ?>
  <!-- ===================== LIST: RAIL + DETAIL ===================== -->
  <div class="txn-shell">
    <div class="txn-rail">
      <div class="txn-rail-month">
        <a href="goods-receipts.php?month=<?= $prevMonth ?>">‹</a>
        <span><?= htmlspecialchars(date('F Y', strtotime($month . '-01'))) ?></span>
        <a href="goods-receipts.php?month=<?= $nextMonth ?>">›</a>
        <a class="today-btn" href="goods-receipts.php">Bulan Ini</a>
      </div>
      <div class="txn-rail-list">
        <?php foreach ($railItems as $r): ?>
          <a class="txn-rail-item <?= (int) $r['id'] === $selectedId ? 'active' : '' ?>" href="goods-receipts.php?month=<?= $month ?>&id=<?= $r['id'] ?>">
            <div class="doc"><?= htmlspecialchars($r['doc_number']) ?></div>
            <div class="sub"><?= htmlspecialchars($r['po_doc_number'] ?? $r['spk_doc_number'] ?? '—') ?> · <span class="pill"><?= $r['destination'] === 'direct_customer' ? 'KE CUSTOMER' : 'GUDANG' ?></span></div>
          </a>
        <?php endforeach; ?>
        <?php if (!$railItems): ?><div style="padding:14px; font-size:12px; color:var(--ink-muted);">Gak ada penerimaan bulan ini.</div><?php endif; ?>
      </div>
      <div style="padding:10px;"><a class="btn btn-sm" style="width:100%;" href="goods-receipts.php?new=1">+ Catat Penerimaan</a></div>
    </div>

    <div class="txn-detail">
      <?php if (!$selected): ?>
        <div class="card txn-empty">Pilih penerimaan di kiri, atau catat yang baru.</div>
      <?php else: ?>
        <?php $total = 0; foreach ($selectedLines as $l) $total += $l['qty'] * $l['unit_cost']; ?>
        <div class="card">
          <div class="txn-detail-header">
            <div><h2><?= htmlspecialchars($selected['doc_number']) ?> <span class="pill"><?= $selected['destination'] === 'direct_customer' ? 'LANGSUNG KE CUSTOMER' : 'GUDANG' ?></span></h2></div>
          </div>

          <div class="txn-info-strip">
            <div><span class="lbl">Sumber</span><?= htmlspecialchars($selected['po_doc_number'] ?? $selected['spk_doc_number'] ?? '—') ?></div>
            <div><span class="lbl">Gudang</span><?= htmlspecialchars($selected['warehouse_name'] ?? '—') ?></div>
            <div><span class="lbl">Tanggal Terima</span><?= htmlspecialchars(date('d M Y H:i', strtotime($selected['received_at']))) ?></div>
          </div>

          <table class="data-table">
            <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit Cost</th><th class="num">Subtotal</th></tr></thead>
            <tbody>
              <?php foreach ($selectedLines as $l): ?>
                <tr>
                  <td><?= htmlspecialchars($l['item_name']) ?></td>
                  <td class="num"><?= rtrim(rtrim(number_format((float) $l['qty'], 2, ',', '.'), '0'), ',') ?></td>
                  <td class="num">Rp <?= number_format((float) $l['unit_cost'], 0, ',', '.') ?></td>
                  <td class="num">Rp <?= number_format((float) $l['qty'] * (float) $l['unit_cost'], 0, ',', '.') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="txn-totals">
            <div class="row grand"><span>Total</span><span>Rp <?= number_format($total, 0, ',', '.') ?></span></div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
