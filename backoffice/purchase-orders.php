<?php
$pageTitle = 'Purchase Order';
$activeMenu = 'po';
require __DIR__ . '/includes/header.php';
require_module_access('po');
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_once __DIR__ . '/../backoffice-shared/stock.php';

$pdo = db();
$flash = null;
const PO_TYPES = ['bahan_baku' => 'Bahan Baku', 'jasa_produksi' => 'Jasa Produksi', 'barang_jadi' => 'Barang Jadi'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_po') {
            require_module_access('po', 'can_create');
            $vendorId = (int) ($_POST['vendor_id'] ?? 0) ?: null;
            $poType = $_POST['po_type'] ?? '';
            if (!isset(PO_TYPES[$poType])) throw new RuntimeException('Tipe PO wajib diisi.');

            $destWarehouseId = null;
            if ($vendorId && !empty($_POST['ship_to_vendor_stock'])) {
                $vStmt = $pdo->prepare('SELECT name FROM contacts WHERE id=? AND organization_id=?');
                $vStmt->execute([$vendorId, $org['organization_id']]);
                $vendorName = $vStmt->fetch()['name'] ?? 'Vendor';
                $destWarehouseId = find_or_create_vendor_warehouse($org['organization_id'], $vendorId, $vendorName);
            }

            $pdo->beginTransaction();
            try {
                $docNumber = next_doc_number($org['organization_id'], 'po');
                $pdo->prepare('INSERT INTO purchase_orders (organization_id, doc_number, vendor_id, destination_warehouse_id, po_type, created_by) VALUES (?,?,?,?,?,?)')
                    ->execute([$org['organization_id'], $docNumber, $vendorId, $destWarehouseId, $poType, $user['id']]);
                $poId = (int) $pdo->lastInsertId();

                $insertLine = $pdo->prepare('INSERT INTO po_lines (po_id, invoice_line_id, product_id, material_id, item_name, qty, unit_cost) VALUES (?,?,?,?,?,?,?)');
                $count = 0;

                if ($poType === 'bahan_baku') {
                    $itemNames = $_POST['item_name'] ?? [];
                    $materialIds = $_POST['material_id'] ?? [];
                    $qtys = $_POST['qty'] ?? [];
                    $costs = $_POST['unit_cost'] ?? [];
                    $matStmt = $pdo->prepare('SELECT name FROM materials WHERE id=? AND organization_id=?');
                    foreach ($itemNames as $i => $name) {
                        $materialId = (int) ($materialIds[$i] ?? 0) ?: null;
                        $name = trim($name);
                        if ($materialId) {
                            $matStmt->execute([$materialId, $org['organization_id']]);
                            $mat = $matStmt->fetch();
                            if (!$mat) { $materialId = null; } else { $name = $mat['name']; }
                        }
                        if ($name === '') continue;
                        $insertLine->execute([$poId, null, null, $materialId, $name, (float) ($qtys[$i] ?? 0) ?: 1, (float) ($costs[$i] ?? 0)]);
                        $count++;
                    }
                } else {
                    $invLineIds = array_map('intval', $_POST['invoice_line_id'] ?? []);
                    $costs = $_POST['unit_cost'] ?? [];
                    $ilStmt = $pdo->prepare('SELECT * FROM invoice_lines il JOIN invoices i ON i.id=il.invoice_id WHERE il.id=? AND i.organization_id=?');
                    foreach ($invLineIds as $i => $ilId) {
                        $ilStmt->execute([$ilId, $org['organization_id']]);
                        $il = $ilStmt->fetch();
                        if (!$il) continue;
                        $insertLine->execute([$poId, $ilId, $il['product_id'], null, $il['product_name_snapshot'], $il['qty'], (float) ($costs[$ilId] ?? 0)]);
                        $count++;
                    }
                }
                if ($count === 0) throw new RuntimeException('Minimal 1 baris item wajib diisi.');
                $pdo->commit();
                $flash = ['ok', "PO $docNumber dibuat."];
                header('Location: purchase-orders.php?id=' . $poId);
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } elseif ($action === 'update_status') {
            require_module_access('po', 'can_edit');
            $id = (int) ($_POST['po_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (in_array($status, ['draft', 'sent', 'partial', 'received', 'void'], true)) {
                $check = $pdo->prepare('SELECT vendor_id FROM purchase_orders WHERE id=? AND organization_id=?');
                $check->execute([$id, $org['organization_id']]);
                $poRow = $check->fetch();
                if ($status !== 'draft' && $poRow && !$poRow['vendor_id']) throw new RuntimeException('Isi Vendor dulu sebelum ubah status.');
                $pdo->prepare('UPDATE purchase_orders SET status=? WHERE id=? AND organization_id=?')->execute([$status, $id, $org['organization_id']]);
                $flash = ['ok', 'Status PO diperbarui.'];
            }
        } elseif ($action === 'update_vendor') {
            require_module_access('po', 'can_edit');
            $id = (int) ($_POST['po_id'] ?? 0);
            $vendorId = (int) ($_POST['vendor_id'] ?? 0) ?: null;
            $destWarehouseId = null;
            if ($vendorId && !empty($_POST['ship_to_vendor_stock'])) {
                $vStmt = $pdo->prepare('SELECT name FROM contacts WHERE id=? AND organization_id=?');
                $vStmt->execute([$vendorId, $org['organization_id']]);
                $vendorName = $vStmt->fetch()['name'] ?? 'Vendor';
                $destWarehouseId = find_or_create_vendor_warehouse($org['organization_id'], $vendorId, $vendorName);
            }
            $pdo->prepare('UPDATE purchase_orders SET vendor_id=?, destination_warehouse_id=? WHERE id=? AND organization_id=?')
                ->execute([$vendorId, $destWarehouseId, $id, $org['organization_id']]);
            $flash = ['ok', 'Vendor PO diperbarui.'];
        }
    } catch (Throwable $e) {
        $flash = ['error', $e->getMessage()];
    }
}

$vendors = $pdo->prepare("SELECT id, name FROM contacts WHERE organization_id=? AND type IN ('vendor','both') ORDER BY name");
$vendors->execute([$org['organization_id']]);
$vendors = $vendors->fetchAll();

$isNewForm = isset($_GET['new']);

if ($isNewForm) {
    $invoiceLines = $pdo->prepare(
        "SELECT il.id, il.product_name_snapshot, il.tier_level_snapshot, il.qty, i.doc_number, con.name AS customer_name
         FROM invoice_lines il JOIN invoices i ON i.id = il.invoice_id JOIN contacts con ON con.id = i.contact_id
         WHERE i.organization_id=? AND i.status IN ('issued','paid')
           AND NOT EXISTS (SELECT 1 FROM po_lines pl WHERE pl.invoice_line_id = il.id)
         ORDER BY i.created_at"
    );
    $invoiceLines->execute([$org['organization_id']]);
    $invoiceLines = $invoiceLines->fetchAll();

    $materialsList = $pdo->prepare('SELECT id, name, unit FROM materials WHERE organization_id=? ORDER BY name');
    $materialsList->execute([$org['organization_id']]);
    $materialsList = $materialsList->fetchAll();
} else {
    $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
    $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

    $railStmt = $pdo->prepare(
        "SELECT po.*, v.name AS vendor_name FROM purchase_orders po LEFT JOIN contacts v ON v.id=po.vendor_id
         WHERE po.organization_id=? AND DATE_FORMAT(po.created_at,'%Y-%m')=? ORDER BY po.created_at DESC"
    );
    $railStmt->execute([$org['organization_id'], $month]);
    $railItems = $railStmt->fetchAll();

    $selectedId = (int) ($_GET['id'] ?? ($railItems[0]['id'] ?? 0));
    $selected = null;
    foreach ($railItems as $r) { if ((int) $r['id'] === $selectedId) { $selected = $r; break; } }
    if (!$selected && $selectedId) {
        $sStmt = $pdo->prepare('SELECT po.*, v.name AS vendor_name FROM purchase_orders po LEFT JOIN contacts v ON v.id=po.vendor_id WHERE po.id=? AND po.organization_id=?');
        $sStmt->execute([$selectedId, $org['organization_id']]);
        $selected = $sStmt->fetch() ?: null;
    }
    $selectedLines = [];
    $trail = ['project' => null, 'customer' => null, 'penawaran' => null, 'invoice' => null, 'material_request' => null];
    if ($selected) {
        $lStmt = $pdo->prepare('SELECT * FROM po_lines WHERE po_id=?');
        $lStmt->execute([$selected['id']]);
        $selectedLines = $lStmt->fetchAll();

        if ($selected['project_id']) {
            $pjStmt = $pdo->prepare('SELECT name FROM projects WHERE id=?');
            $pjStmt->execute([$selected['project_id']]);
            $trail['project'] = $pjStmt->fetch()['name'] ?? null;
        }
        if ($selected['material_request_id']) {
            $mrStmt = $pdo->prepare(
                'SELECT mr.doc_number AS mr_doc, i.doc_number AS inv_doc, i.quotation_id, c.name AS contact_name
                 FROM material_requests mr JOIN invoices i ON i.id=mr.invoice_id JOIN contacts c ON c.id=i.contact_id
                 WHERE mr.id=?'
            );
            $mrStmt->execute([$selected['material_request_id']]);
            $mrRow = $mrStmt->fetch();
            if ($mrRow) {
                $trail['material_request'] = $mrRow['mr_doc'];
                $trail['invoice'] = $mrRow['inv_doc'];
                $trail['customer'] = $mrRow['contact_name'];
                if ($mrRow['quotation_id']) {
                    $qStmt = $pdo->prepare('SELECT doc_number FROM quotations WHERE id=?');
                    $qStmt->execute([$mrRow['quotation_id']]);
                    $trail['penawaran'] = $qStmt->fetch()['doc_number'] ?? null;
                }
            }
        } else {
            // PO dari flow lama (jasa_produksi/barang_jadi langsung dari invoice_line).
            $ilStmt = $pdo->prepare(
                'SELECT i.doc_number AS inv_doc, i.quotation_id, c.name AS contact_name
                 FROM po_lines pl JOIN invoice_lines il ON il.id=pl.invoice_line_id
                 JOIN invoices i ON i.id=il.invoice_id JOIN contacts c ON c.id=i.contact_id
                 WHERE pl.po_id=? LIMIT 1'
            );
            $ilStmt->execute([$selected['id']]);
            $ilRow = $ilStmt->fetch();
            if ($ilRow) {
                $trail['invoice'] = $ilRow['inv_doc'];
                $trail['customer'] = $ilRow['contact_name'];
                if ($ilRow['quotation_id']) {
                    $qStmt = $pdo->prepare('SELECT doc_number, project_id FROM quotations WHERE id=?');
                    $qStmt->execute([$ilRow['quotation_id']]);
                    $qRow = $qStmt->fetch();
                    $trail['penawaran'] = $qRow['doc_number'] ?? null;
                    if (!$trail['project'] && !empty($qRow['project_id'])) {
                        $pjStmt = $pdo->prepare('SELECT name FROM projects WHERE id=?');
                        $pjStmt->execute([$qRow['project_id']]);
                        $trail['project'] = $pjStmt->fetch()['name'] ?? null;
                    }
                }
            }
        }

        $spkStmt = $pdo->prepare('SELECT id FROM spk WHERE po_id=?');
        $spkStmt->execute([$selected['id']]);
        $linkedSpkId = $spkStmt->fetch()['id'] ?? null;

        $destWarehouseName = null;
        if ($selected['destination_warehouse_id']) {
            $dwStmt = $pdo->prepare('SELECT name FROM warehouses WHERE id=?');
            $dwStmt->execute([$selected['destination_warehouse_id']]);
            $destWarehouseName = $dwStmt->fetch()['name'] ?? null;
        }
    }
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if ($isNewForm): ?>
  <!-- ===================== FORM FULL PAGE: BUAT PO MANUAL ===================== -->
  <div class="card txn-form-page">
    <div class="txn-detail-header"><h2>Buat Purchase Order</h2><a class="btn btn-sm btn-ghost" href="purchase-orders.php">Batal</a></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_po">
      <div class="field-row">
        <div class="field">
          <label>Vendor (opsional, bisa diisi belakangan)</label>
          <select name="vendor_id" id="po-vendor-select">
            <option value="">— Belum ditentukan —</option>
            <?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
          </select>
          <label id="po-vendor-stock-label" style="display:none; margin-top:6px; font-size:12.5px; font-weight:400; align-items:center; gap:6px;">
            <input type="checkbox" name="ship_to_vendor_stock" value="1"> Barang disimpan di gudang vendor (bukan gudang Svashta) — bikin lokasi stok baru otomatis
          </label>
        </div>
        <div class="field">
          <label>Tipe PO</label>
          <select name="po_type" id="po-type-select" required>
            <option value="">— Pilih Tipe —</option>
            <?php foreach (PO_TYPES as $key => $label): ?><option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>

      <div id="po-lines-raw" style="display:none;">
        <label style="display:block; font-size:12px; font-weight:600; margin:14px 0 6px;">Item Bahan Baku</label>
        <div id="raw-lines"></div>
        <button type="button" class="btn btn-sm btn-ghost" id="add-raw-line">+ Tambah Item</button>
      </div>

      <div id="po-lines-invoice" style="display:none;">
        <label style="display:block; font-size:12px; font-weight:600; margin:14px 0 6px;">Pilih Item dari Invoice (belum di-PO-kan)</label>
        <div id="invoice-line-picker">
          <?php foreach ($invoiceLines as $il): ?>
            <label style="display:flex; gap:8px; align-items:center; padding:6px 0; border-bottom:1px solid var(--border); font-size:13px;">
              <input type="checkbox" name="invoice_line_id[]" value="<?= $il['id'] ?>">
              <span style="flex:1;"><?= htmlspecialchars($il['product_name_snapshot']) ?> (<?= htmlspecialchars($il['tier_level_snapshot']) ?>) — <?= $il['qty'] ?> unit — <?= htmlspecialchars($il['customer_name']) ?> <small style="color:var(--ink-muted);">[<?= htmlspecialchars($il['doc_number']) ?>]</small></span>
              <input type="text" inputmode="numeric" class="rupiah-input" name="unit_cost[<?= $il['id'] ?>]" placeholder="Cost/unit" style="width:110px; padding:6px; border:1px solid var(--border); border-radius:4px;">
            </label>
          <?php endforeach; ?>
          <?php if (!$invoiceLines): ?><p style="color:var(--ink-muted); font-size:13px;">Tidak ada item Invoice yang belum di-PO-kan.</p><?php endif; ?>
        </div>
      </div>

      <div style="margin-top:16px;"><button type="submit" class="btn">Simpan PO</button></div>
    </form>
  </div>

  <script>
  document.getElementById('po-vendor-select').addEventListener('change', function () {
    var lbl = document.getElementById('po-vendor-stock-label');
    lbl.style.display = this.value ? 'flex' : 'none';
    if (!this.value) lbl.querySelector('input').checked = false;
  });
  document.getElementById('po-type-select').addEventListener('change', function () {
    var isRaw = this.value === 'bahan_baku';
    var hasInvoiceType = this.value === 'jasa_produksi' || this.value === 'barang_jadi';
    document.getElementById('po-lines-raw').style.display = isRaw ? 'block' : 'none';
    document.getElementById('po-lines-invoice').style.display = hasInvoiceType ? 'block' : 'none';
  });
  var RAW_MATERIALS = <?= json_encode($materialsList) ?>;
  document.getElementById('add-raw-line').addEventListener('click', function () {
    var row = document.createElement('div');
    row.style.cssText = 'display:flex; gap:8px; margin-bottom:8px;';
    var matOpts = '<option value="">— Material (opsional) —</option>';
    RAW_MATERIALS.forEach(function (m) { matOpts += '<option value="' + m.id + '">' + m.name + '</option>'; });
    row.innerHTML =
      '<select class="raw-material-select" style="flex:1.5;padding:8px;border:1px solid var(--border);border-radius:4px;">' + matOpts + '</select>' +
      '<input type="text" name="item_name[]" placeholder="Nama item" style="flex:2;padding:8px;border:1px solid var(--border);border-radius:4px;">' +
      '<input type="hidden" name="material_id[]" class="raw-material-id">' +
      '<input type="number" name="qty[]" placeholder="Qty" step="0.01" style="width:80px;padding:8px;border:1px solid var(--border);border-radius:4px;">' +
      '<input type="text" inputmode="numeric" class="rupiah-input" name="unit_cost[]" placeholder="Cost/unit" style="width:110px;padding:8px;border:1px solid var(--border);border-radius:4px;">' +
      '<button type="button" class="btn btn-sm btn-ghost" onclick="this.closest(\'div\').remove()">✕</button>';
    row.querySelector('.raw-material-select').addEventListener('change', function () {
      row.querySelector('.raw-material-id').value = this.value;
      if (this.value) row.querySelector('input[name="item_name[]"]').value = this.options[this.selectedIndex].textContent;
    });
    initRupiahInput(row.querySelector('input[name="unit_cost[]"]'));
    document.getElementById('raw-lines').appendChild(row);
  });
  document.getElementById('add-raw-line').click();
  </script>

<?php else: ?>
  <!-- ===================== LIST: RAIL + DETAIL ===================== -->
  <div class="txn-shell">
    <div class="txn-rail">
      <div class="txn-rail-month">
        <a href="purchase-orders.php?month=<?= $prevMonth ?>">‹</a>
        <span><?= htmlspecialchars(date('F Y', strtotime($month . '-01'))) ?></span>
        <a href="purchase-orders.php?month=<?= $nextMonth ?>">›</a>
        <a class="today-btn" href="purchase-orders.php">Bulan Ini</a>
      </div>
      <div class="txn-rail-list">
        <?php foreach ($railItems as $r): ?>
          <a class="txn-rail-item <?= (int) $r['id'] === $selectedId ? 'active' : '' ?>" href="purchase-orders.php?month=<?= $month ?>&id=<?= $r['id'] ?>">
            <div class="doc"><?= htmlspecialchars($r['doc_number']) ?></div>
            <div class="sub"><?= htmlspecialchars($r['vendor_name'] ?? 'Belum ada vendor') ?> · <span class="pill pill-<?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span></div>
          </a>
        <?php endforeach; ?>
        <?php if (!$railItems): ?><div style="padding:14px; font-size:12px; color:var(--ink-muted);">Gak ada PO bulan ini.</div><?php endif; ?>
      </div>
      <div style="padding:10px;"><a class="btn btn-sm" style="width:100%;" href="purchase-orders.php?new=1">+ Buat PO</a></div>
    </div>

    <div class="txn-detail">
      <?php if (!$selected): ?>
        <div class="card txn-empty">Pilih PO di kiri, atau buat yang baru.</div>
      <?php else: ?>
        <?php $total = 0; foreach ($selectedLines as $l) $total += $l['qty'] * $l['unit_cost']; ?>
        <div class="card">
          <div class="txn-detail-header">
            <div><h2><?= htmlspecialchars($selected['doc_number']) ?> <span class="pill"><?= htmlspecialchars(PO_TYPES[$selected['po_type']]) ?></span> <span class="pill pill-<?= $selected['status'] ?>"><?= strtoupper($selected['status']) ?></span></h2></div>
            <div class="txn-detail-actions">
              <a class="btn btn-sm btn-ghost" href="po-print.php?id=<?= $selected['id'] ?>" target="_blank">Print</a>
              <?php if ($selected['po_type'] === 'jasa_produksi'): ?>
                <?php if ($linkedSpkId): ?><a class="btn btn-sm btn-ghost" href="spk.php">Lihat SPK</a>
                <?php elseif (has_access('spk', 'can_create')): ?><a class="btn btn-sm btn-ghost" href="spk.php?create_for_po=<?= $selected['id'] ?>">+ Buat SPK</a><?php endif; ?>
              <?php endif; ?>
              <?php if (has_access('po', 'can_edit')): ?>
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="po_id" value="<?= $selected['id'] ?>">
                  <select name="status" onchange="this.form.submit();" style="padding:6px 10px; border:1px solid var(--border); border-radius:4px; font-size:12px;">
                    <?php foreach (['draft', 'sent', 'partial', 'received', 'void'] as $s): ?>
                      <option value="<?= $s ?>" <?= $selected['status'] === $s ? 'selected' : '' ?>><?= strtoupper($s) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="txn-info-strip">
            <div><span class="lbl">Project</span><?= $trail['project'] ? htmlspecialchars($trail['project']) : '—' ?></div>
            <div><span class="lbl">Customer</span><?= $trail['customer'] ? htmlspecialchars($trail['customer']) : '—' ?></div>
            <div><span class="lbl">Penawaran</span><?= $trail['penawaran'] ? htmlspecialchars($trail['penawaran']) : '—' ?></div>
            <div><span class="lbl">Invoice</span><?= $trail['invoice'] ? htmlspecialchars($trail['invoice']) : '—' ?></div>
            <div><span class="lbl">Request Material</span><?= $trail['material_request'] ? htmlspecialchars($trail['material_request']) : '—' ?></div>
          </div>

          <?php if (has_access('po', 'can_edit')): ?>
          <form method="post" style="display:flex; gap:8px; align-items:flex-end; margin-bottom:6px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_vendor">
            <input type="hidden" name="po_id" value="<?= $selected['id'] ?>">
            <div class="field" style="margin-bottom:0; flex:1;">
              <label>Vendor</label>
              <select name="vendor_id">
                <option value="">— Belum ditentukan —</option>
                <?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>" <?= $v['id'] == $selected['vendor_id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <label style="font-size:12.5px; display:flex; align-items:center; gap:6px; margin-bottom:9px;">
              <input type="checkbox" name="ship_to_vendor_stock" value="1" <?= $selected['destination_warehouse_id'] ? 'checked' : '' ?>> Simpan di gudang vendor
            </label>
            <button type="submit" class="btn btn-sm">Simpan Vendor</button>
          </form>
          <?php if ($destWarehouseName): ?><div style="font-size:12px; color:var(--accent-text); margin-bottom:14px;">📦 Lokasi stok: <strong><?= htmlspecialchars($destWarehouseName) ?></strong></div><?php else: ?><div style="margin-bottom:14px;"></div><?php endif; ?>
          <?php endif; ?>

          <table class="data-table">
            <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Cost/unit</th><th class="num">Subtotal</th></tr></thead>
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
