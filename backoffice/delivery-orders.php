<?php
$pageTitle = 'Delivery Order';
$activeMenu = 'do';
require __DIR__ . '/includes/header.php';
require_module_access('do');
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_once __DIR__ . '/../backoffice-shared/stock.php';

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_do') {
            require_module_access('do', 'can_create');
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $qtys = $_POST['ship_qty'] ?? [];
            if (!$invoiceId || !$warehouseId) throw new RuntimeException('Invoice dan Gudang wajib dipilih.');

            $invStmt = $pdo->prepare('SELECT * FROM invoices WHERE id=? AND organization_id=?');
            $invStmt->execute([$invoiceId, $org['organization_id']]);
            $invoice = $invStmt->fetch();
            if (!$invoice) throw new RuntimeException('Invoice tidak ditemukan.');

            $pdo->beginTransaction();
            try {
                $docNumber = next_doc_number($org['organization_id'], 'do');
                $pdo->prepare('INSERT INTO delivery_orders (organization_id, doc_number, invoice_id, contact_id, warehouse_id, created_by) VALUES (?,?,?,?,?,?)')
                    ->execute([$org['organization_id'], $docNumber, $invoiceId, $invoice['contact_id'], $warehouseId, $user['id']]);
                $doId = (int) $pdo->lastInsertId();

                $lineStmt = $pdo->prepare(
                    'SELECT il.*, (SELECT COALESCE(SUM(dol.qty),0) FROM delivery_order_lines dol WHERE dol.invoice_line_id = il.id) AS shipped_qty
                     FROM invoice_lines il WHERE il.id=? AND il.invoice_id=?'
                );
                $insertLine = $pdo->prepare('INSERT INTO delivery_order_lines (delivery_order_id, invoice_line_id, product_id, product_name_snapshot, qty, unit_cost_snapshot) VALUES (?,?,?,?,?,?)');

                $shipped = 0;
                foreach ($qtys as $lineId => $qty) {
                    $qty = (float) $qty;
                    if ($qty <= 0) continue;
                    $lineStmt->execute([(int) $lineId, $invoiceId]);
                    $line = $lineStmt->fetch();
                    if (!$line || !$line['product_id']) continue;
                    $remaining = (float) $line['qty'] - (float) $line['shipped_qty'];
                    if ($qty > $remaining) $qty = $remaining;
                    if ($qty <= 0) continue;

                    $unitCost = fifo_consume_stock($org['organization_id'], $warehouseId, (int) $line['product_id'], $qty, 'delivery_order', $doId);
                    $insertLine->execute([$doId, $line['id'], $line['product_id'], $line['product_name_snapshot'], $qty, $unitCost]);
                    $shipped++;
                }
                if ($shipped === 0) throw new RuntimeException('Tidak ada item valid untuk dikirim (cek sisa qty & stok).');

                $pdo->commit();
                header('Location: delivery-orders.php?id=' . $doId);
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } elseif ($action === 'update_status') {
            require_module_access('do', 'can_edit');
            $id = (int) ($_POST['do_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (in_array($status, ['draft', 'shipped', 'delivered', 'void'], true)) {
                if ($status === 'shipped') {
                    $pdo->prepare('UPDATE delivery_orders SET status=?, shipped_at=NOW() WHERE id=? AND organization_id=?')->execute([$status, $id, $org['organization_id']]);
                } else {
                    $pdo->prepare('UPDATE delivery_orders SET status=? WHERE id=? AND organization_id=?')->execute([$status, $id, $org['organization_id']]);
                }
                $flash = ['ok', 'Status Delivery Order diperbarui.'];
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
    $openInvoices = $pdo->prepare(
        "SELECT i.id, i.doc_number, c.name AS contact_name FROM invoices i JOIN contacts c ON c.id=i.contact_id
         WHERE i.organization_id=? AND i.status IN ('issued','paid')
           AND EXISTS (
             SELECT 1 FROM invoice_lines il WHERE il.invoice_id=i.id AND il.product_id IS NOT NULL
               AND il.qty > (SELECT COALESCE(SUM(qty),0) FROM delivery_order_lines dol WHERE dol.invoice_line_id=il.id)
           )
         ORDER BY i.created_at"
    );
    $openInvoices->execute([$org['organization_id']]);
    $openInvoices = $openInvoices->fetchAll();

    $invoiceLinesByInvoice = [];
    if ($openInvoices) {
        $ph = implode(',', array_fill(0, count($openInvoices), '?'));
        $stmt = $pdo->prepare(
            "SELECT il.*, (SELECT COALESCE(SUM(qty),0) FROM delivery_order_lines dol WHERE dol.invoice_line_id=il.id) AS shipped_qty
             FROM invoice_lines il WHERE il.invoice_id IN ($ph) AND il.product_id IS NOT NULL"
        );
        $stmt->execute(array_column($openInvoices, 'id'));
        foreach ($stmt->fetchAll() as $row) {
            $remaining = (float) $row['qty'] - (float) $row['shipped_qty'];
            if ($remaining <= 0) continue;
            $row['remaining'] = $remaining;
            $invoiceLinesByInvoice[$row['invoice_id']][] = $row;
        }
    }
} else {
    $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
    $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

    $railStmt = $pdo->prepare(
        "SELECT do_.*, c.name AS contact_name, inv.doc_number AS invoice_doc_number,
           (SELECT COALESCE(SUM(qty*unit_cost_snapshot),0) FROM delivery_order_lines WHERE delivery_order_id=do_.id) AS total_cogs
         FROM delivery_orders do_ JOIN contacts c ON c.id=do_.contact_id JOIN invoices inv ON inv.id=do_.invoice_id
         WHERE do_.organization_id=? AND DATE_FORMAT(do_.created_at,'%Y-%m')=? ORDER BY do_.created_at DESC"
    );
    $railStmt->execute([$org['organization_id'], $month]);
    $railItems = $railStmt->fetchAll();

    $selectedId = (int) ($_GET['id'] ?? ($railItems[0]['id'] ?? 0));
    $selected = null;
    foreach ($railItems as $r) { if ((int) $r['id'] === $selectedId) { $selected = $r; break; } }
    if (!$selected && $selectedId) {
        $sStmt = $pdo->prepare(
            "SELECT do_.*, c.name AS contact_name, inv.doc_number AS invoice_doc_number,
               (SELECT COALESCE(SUM(qty*unit_cost_snapshot),0) FROM delivery_order_lines WHERE delivery_order_id=do_.id) AS total_cogs
             FROM delivery_orders do_ JOIN contacts c ON c.id=do_.contact_id JOIN invoices inv ON inv.id=do_.invoice_id
             WHERE do_.id=? AND do_.organization_id=?"
        );
        $sStmt->execute([$selectedId, $org['organization_id']]);
        $selected = $sStmt->fetch() ?: null;
    }

    $selectedLines = [];
    if ($selected) {
        $lStmt = $pdo->prepare('SELECT * FROM delivery_order_lines WHERE delivery_order_id=?');
        $lStmt->execute([$selected['id']]);
        $selectedLines = $lStmt->fetchAll();
    }
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if ($isNewForm): ?>
  <!-- ===================== FORM FULL PAGE: BUAT DELIVERY ORDER ===================== -->
  <div class="card txn-form-page">
    <div class="txn-detail-header"><h2>Buat Delivery Order</h2><a class="btn btn-sm btn-ghost" href="delivery-orders.php">Batal</a></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_do">
      <div class="field-row">
        <div class="field">
          <label>Invoice</label>
          <select name="invoice_id" id="do-invoice" required>
            <option value="">— Pilih Invoice —</option>
            <?php foreach ($openInvoices as $inv): ?><option value="<?= $inv['id'] ?>"><?= htmlspecialchars($inv['doc_number']) ?> — <?= htmlspecialchars($inv['contact_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Gudang Asal</label>
          <select name="warehouse_id" required>
            <option value="">— Pilih Gudang —</option>
            <?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <label style="display:block; font-size:12px; font-weight:600; margin:14px 0 6px;">Item yang Dikirim</label>
      <div id="do-line-picker" style="font-size:13px; color:var(--ink-muted);">Pilih invoice dulu. Pastikan stok di gudang tercukupi (dicek ulang saat simpan, FIFO).</div>

      <div style="margin-top:16px;"><button type="submit" class="btn">Simpan &amp; Minus Stok</button></div>
    </form>
  </div>

  <script>
  var DO_LINES = <?= json_encode($invoiceLinesByInvoice) ?>;
  document.getElementById('do-invoice').addEventListener('change', function () {
    var picker = document.getElementById('do-line-picker');
    var lines = DO_LINES[this.value];
    if (!lines || !lines.length) { picker.innerHTML = 'Tidak ada sisa item di invoice ini.'; return; }
    var html = '';
    lines.forEach(function (l) {
      html += '<div style="display:flex; gap:8px; align-items:center; padding:6px 0; border-bottom:1px solid var(--border);">' +
        '<span style="flex:1;">' + l.product_name_snapshot + ' <small style="color:var(--ink-muted);">(sisa ' + l.remaining + ')</small></span>' +
        '<input type="number" name="ship_qty[' + l.id + ']" value="' + l.remaining + '" max="' + l.remaining + '" step="0.01" style="width:100px;padding:6px;border:1px solid var(--border);border-radius:4px;">' +
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
        <a href="delivery-orders.php?month=<?= $prevMonth ?>">‹</a>
        <span><?= htmlspecialchars(date('F Y', strtotime($month . '-01'))) ?></span>
        <a href="delivery-orders.php?month=<?= $nextMonth ?>">›</a>
        <a class="today-btn" href="delivery-orders.php">Bulan Ini</a>
      </div>
      <div class="txn-rail-list">
        <?php foreach ($railItems as $r): ?>
          <a class="txn-rail-item <?= (int) $r['id'] === $selectedId ? 'active' : '' ?>" href="delivery-orders.php?month=<?= $month ?>&id=<?= $r['id'] ?>">
            <div class="doc"><?= htmlspecialchars($r['doc_number']) ?></div>
            <div class="sub"><?= htmlspecialchars($r['contact_name']) ?> · <span class="pill pill-<?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span></div>
          </a>
        <?php endforeach; ?>
        <?php if (!$railItems): ?><div style="padding:14px; font-size:12px; color:var(--ink-muted);">Gak ada Delivery Order bulan ini.</div><?php endif; ?>
      </div>
      <div style="padding:10px;"><a class="btn btn-sm" style="width:100%;" href="delivery-orders.php?new=1">+ Buat Delivery Order</a></div>
    </div>

    <div class="txn-detail">
      <?php if (!$selected): ?>
        <div class="card txn-empty">Pilih Delivery Order di kiri, atau buat yang baru.</div>
      <?php else: ?>
        <div class="card">
          <div class="txn-detail-header">
            <div><h2><?= htmlspecialchars($selected['doc_number']) ?> <span class="pill pill-<?= $selected['status'] ?>"><?= strtoupper($selected['status']) ?></span></h2></div>
            <div class="txn-detail-actions">
              <a class="btn btn-sm btn-ghost" href="do-print.php?id=<?= $selected['id'] ?>" target="_blank">Print</a>
              <?php if (has_access('do', 'can_edit')): ?>
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="do_id" value="<?= $selected['id'] ?>">
                  <select name="status" onchange="this.form.submit();" style="padding:6px 10px; border:1px solid var(--border); border-radius:4px; font-size:12px;">
                    <?php foreach (['draft', 'shipped', 'delivered', 'void'] as $s): ?>
                      <option value="<?= $s ?>" <?= $selected['status'] === $s ? 'selected' : '' ?>><?= strtoupper($s) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="txn-info-strip">
            <div><span class="lbl">Customer</span><?= htmlspecialchars($selected['contact_name']) ?></div>
            <div><span class="lbl">Invoice</span><?= htmlspecialchars($selected['invoice_doc_number']) ?></div>
            <div><span class="lbl">HPP (COGS)</span>Rp <?= number_format((float) $selected['total_cogs'], 0, ',', '.') ?></div>
          </div>

          <table class="data-table">
            <thead><tr><th>Produk</th><th class="num">Qty</th><th class="num">Unit Cost (FIFO)</th><th class="num">Subtotal</th></tr></thead>
            <tbody>
              <?php foreach ($selectedLines as $l): ?>
                <tr>
                  <td><?= htmlspecialchars($l['product_name_snapshot']) ?></td>
                  <td class="num"><?= rtrim(rtrim(number_format((float) $l['qty'], 2, ',', '.'), '0'), ',') ?></td>
                  <td class="num">Rp <?= number_format((float) $l['unit_cost_snapshot'], 0, ',', '.') ?></td>
                  <td class="num">Rp <?= number_format((float) $l['qty'] * (float) $l['unit_cost_snapshot'], 0, ',', '.') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="txn-totals">
            <div class="row grand"><span>Total HPP (COGS)</span><span>Rp <?= number_format((float) $selected['total_cogs'], 0, ',', '.') ?></span></div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
