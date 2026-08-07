<?php
$pageTitle = 'SPK / Manufaktur';
$activeMenu = 'spk';
require __DIR__ . '/includes/header.php';
require_module_access('spk');
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_once __DIR__ . '/../backoffice-shared/stock.php';

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_spk') {
            require_module_access('spk', 'can_create');
            $poId = (int) ($_POST['po_id'] ?? 0) ?: null;
            $vendorId = (int) ($_POST['vendor_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            $outputQty = (float) ($_POST['output_qty'] ?? 0);
            $assemblyFee = (float) ($_POST['assembly_fee'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $estFinish = trim($_POST['estimated_finish'] ?? '') ?: null;
            $materialIds = $_POST['material_id'] ?? [];
            $materialQtys = $_POST['material_qty'] ?? [];

            if (!$vendorId || !$productId || $outputQty <= 0 || !$warehouseId) {
                throw new RuntimeException('Vendor, Produk target, Qty hasil, dan Gudang (sumber material) wajib diisi.');
            }

            if ($poId) {
                $poStmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=? AND organization_id=? AND po_type='jasa_produksi'");
                $poStmt->execute([$poId, $org['organization_id']]);
                if (!$poStmt->fetch()) throw new RuntimeException('PO jasa produksi tidak ditemukan.');
                $dup = $pdo->prepare('SELECT id FROM spk WHERE po_id=?');
                $dup->execute([$poId]);
                if ($dup->fetch()) throw new RuntimeException('PO ini sudah punya SPK.');
            }

            $pdo->beginTransaction();
            try {
                $docNumber = next_doc_number($org['organization_id'], 'spk');
                $pdo->prepare('INSERT INTO spk (organization_id, doc_number, po_id, product_id, output_qty, assembly_fee, vendor_id, estimated_finish, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$org['organization_id'], $docNumber, $poId, $productId, $outputQty, $assemblyFee, $vendorId, $estFinish, $user['id']]);
                $spkId = (int) $pdo->lastInsertId();

                $matStmt = $pdo->prepare('SELECT name FROM materials WHERE id=? AND organization_id=?');
                $insertMat = $pdo->prepare('INSERT INTO spk_materials (spk_id, material_id, material_name_snapshot, qty, unit_cost) VALUES (?,?,?,?,?)');
                $used = 0;
                foreach ($materialIds as $i => $matId) {
                    $matId = (int) $matId;
                    $qty = (float) ($materialQtys[$i] ?? 0);
                    if (!$matId || $qty <= 0) continue;
                    $matStmt->execute([$matId, $org['organization_id']]);
                    $mat = $matStmt->fetch();
                    if (!$mat) continue;
                    // FIFO minus stok material — dikirim ke vendor perakit.
                    $unitCost = fifo_consume_material_stock($org['organization_id'], $warehouseId, $matId, $qty, 'spk_material', $spkId);
                    $insertMat->execute([$spkId, $matId, $mat['name'], $qty, $unitCost]);
                    $used++;
                }
                if ($used === 0) throw new RuntimeException('Minimal 1 material yang dikirim ke vendor wajib diisi.');

                $pdo->commit();
                header('Location: spk.php?id=' . $spkId);
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } elseif ($action === 'update_status') {
            require_module_access('spk', 'can_edit');
            $id = (int) ($_POST['spk_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (in_array($status, ['draft', 'in_production', 'done', 'void'], true)) {
                $pdo->prepare('UPDATE spk SET status=? WHERE id=? AND organization_id=?')->execute([$status, $id, $org['organization_id']]);
                $flash = ['ok', 'Status SPK diperbarui.'];
            }
        } elseif ($action === 'receive_output') {
            require_module_access('spk', 'can_edit');
            $spkId = (int) ($_POST['spk_id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            if (!$warehouseId) throw new RuntimeException('Gudang tujuan wajib dipilih.');

            $spkStmt = $pdo->prepare('SELECT * FROM spk WHERE id=? AND organization_id=?');
            $spkStmt->execute([$spkId, $org['organization_id']]);
            $spk = $spkStmt->fetch();
            if (!$spk) throw new RuntimeException('SPK tidak ditemukan.');

            $already = $pdo->prepare('SELECT id FROM goods_receipts WHERE spk_id=?');
            $already->execute([$spkId]);
            if ($already->fetch()) throw new RuntimeException('Hasil produksi SPK ini sudah pernah diterima.');

            $matCostStmt = $pdo->prepare('SELECT COALESCE(SUM(qty*unit_cost),0) c FROM spk_materials WHERE spk_id=?');
            $matCostStmt->execute([$spkId]);
            $materialCost = (float) $matCostStmt->fetch()['c'];
            $totalCost = $materialCost + (float) $spk['assembly_fee'];
            $outputQty = (float) $spk['output_qty'];
            $unitCost = $outputQty > 0 ? $totalCost / $outputQty : 0;

            $pdo->beginTransaction();
            try {
                $docNumber = next_doc_number($org['organization_id'], 'penerimaan');
                $pdo->prepare('INSERT INTO goods_receipts (organization_id, doc_number, spk_id, warehouse_id, destination, received_by) VALUES (?,?,?,?,"warehouse",?)')
                    ->execute([$org['organization_id'], $docNumber, $spkId, $warehouseId, $user['id']]);
                $receiptId = (int) $pdo->lastInsertId();

                $prodStmt = $pdo->prepare('SELECT name FROM products WHERE id=?');
                $prodStmt->execute([$spk['product_id']]);
                $productName = $prodStmt->fetch()['name'] ?? 'Produk';

                $pdo->prepare('INSERT INTO goods_receipt_lines (goods_receipt_id, po_line_id, product_id, item_name, qty, unit_cost) VALUES (?,NULL,?,?,?,?)')
                    ->execute([$receiptId, $spk['product_id'], $productName, $outputQty, $unitCost]);

                $pdo->prepare('INSERT INTO stock_ledger (organization_id, warehouse_id, product_id, direction, qty, qty_remaining, unit_cost, ref_type, ref_id) VALUES (?,?,?,"in",?,?,?,"goods_receipt",?)')
                    ->execute([$org['organization_id'], $warehouseId, $spk['product_id'], $outputQty, $outputQty, $unitCost, $receiptId]);

                $pdo->prepare('UPDATE spk SET status="done" WHERE id=?')->execute([$spkId]);

                $pdo->commit();
                $flash = ['ok', "Hasil produksi diterima ($docNumber). HPP per unit: Rp " . number_format($unitCost, 0, ',', '.')];
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    } catch (Throwable $e) {
        $flash = ['error', $e->getMessage()];
    }
}

$vendors = $pdo->prepare("SELECT id, name FROM contacts WHERE organization_id=? AND type IN ('vendor','both') ORDER BY name");
$vendors->execute([$org['organization_id']]);
$vendors = $vendors->fetchAll();

$products = $pdo->prepare('SELECT id, name FROM products WHERE organization_id=? ORDER BY name');
$products->execute([$org['organization_id']]);
$products = $products->fetchAll();

$materialsList = $pdo->prepare('SELECT id, name, unit FROM materials WHERE organization_id=? ORDER BY name');
$materialsList->execute([$org['organization_id']]);
$materialsList = $materialsList->fetchAll();

$warehouses = $pdo->prepare('SELECT id, name FROM warehouses WHERE organization_id=? ORDER BY is_default DESC, name');
$warehouses->execute([$org['organization_id']]);
$warehouses = $warehouses->fetchAll();

$isNewForm = isset($_GET['new']) || !empty($_GET['create_for_po']);

if ($isNewForm) {
    $createForPo = null;
    if (!empty($_GET['create_for_po'])) {
        $stmt = $pdo->prepare(
            "SELECT po.*, c.name AS vendor_name,
               (SELECT COALESCE(SUM(qty*unit_cost),0) FROM po_lines WHERE po_id=po.id) AS po_total,
               (SELECT COALESCE(SUM(qty),0) FROM po_lines WHERE po_id=po.id) AS po_qty,
               (SELECT product_id FROM po_lines WHERE po_id=po.id AND product_id IS NOT NULL LIMIT 1) AS po_product_id
             FROM purchase_orders po JOIN contacts c ON c.id=po.vendor_id
             WHERE po.id=? AND po.organization_id=? AND po.po_type='jasa_produksi'"
        );
        $stmt->execute([(int) $_GET['create_for_po'], $org['organization_id']]);
        $createForPo = $stmt->fetch() ?: null;
    }

    // BOM per produk (dari tier aktif pertama yang ketemu) — buat auto-isi
    // baris material pas Produk Target dipilih, plus stok yang ada per gudang
    // biar bisa langsung keliatan cukup/belum tanpa nunggu submit gagal.
    $productBom = [];
    $bomStmt = $pdo->prepare(
        'SELECT pt.product_id, pt.bom_json FROM product_tiers pt
         WHERE pt.product_id IN (SELECT id FROM products WHERE organization_id=?) AND pt.is_active=1
         ORDER BY pt.product_id, pt.id'
    );
    $bomStmt->execute([$org['organization_id']]);
    foreach ($bomStmt->fetchAll() as $row) {
        if (isset($productBom[$row['product_id']])) continue; // udah ada tier pertama, skip sisanya
        $bom = json_decode($row['bom_json'] ?? '[]', true) ?: [];
        $productBom[$row['product_id']] = array_values(array_filter($bom, fn($b) => !empty($b['material_id'])));
    }

    $materialStockByWarehouse = [];
    $stockStmt = $pdo->prepare(
        'SELECT warehouse_id, material_id, COALESCE(SUM(qty_remaining),0) qty FROM stock_ledger
         WHERE organization_id=? AND material_id IS NOT NULL AND direction="in"
         GROUP BY warehouse_id, material_id'
    );
    $stockStmt->execute([$org['organization_id']]);
    foreach ($stockStmt->fetchAll() as $row) {
        $materialStockByWarehouse[$row['warehouse_id']][$row['material_id']] = (float) $row['qty'];
    }
} else {
    $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
    $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

    $railStmt = $pdo->prepare(
        "SELECT s.*, c.name AS vendor_name, p.name AS product_name,
           (SELECT id FROM goods_receipts WHERE spk_id=s.id) AS receipt_id
         FROM spk s JOIN contacts c ON c.id=s.vendor_id JOIN products p ON p.id=s.product_id
         WHERE s.organization_id=? AND DATE_FORMAT(s.created_at,'%Y-%m')=? ORDER BY s.created_at DESC"
    );
    $railStmt->execute([$org['organization_id'], $month]);
    $railItems = $railStmt->fetchAll();

    $selectedId = (int) ($_GET['id'] ?? ($railItems[0]['id'] ?? 0));
    $selected = null;
    foreach ($railItems as $r) { if ((int) $r['id'] === $selectedId) { $selected = $r; break; } }
    if (!$selected && $selectedId) {
        $sStmt = $pdo->prepare(
            "SELECT s.*, c.name AS vendor_name, p.name AS product_name,
               (SELECT id FROM goods_receipts WHERE spk_id=s.id) AS receipt_id
             FROM spk s JOIN contacts c ON c.id=s.vendor_id JOIN products p ON p.id=s.product_id
             WHERE s.id=? AND s.organization_id=?"
        );
        $sStmt->execute([$selectedId, $org['organization_id']]);
        $selected = $sStmt->fetch() ?: null;
    }

    $selectedMaterials = [];
    $trail = ['po' => null];
    if ($selected) {
        $mStmt = $pdo->prepare('SELECT * FROM spk_materials WHERE spk_id=?');
        $mStmt->execute([$selected['id']]);
        $selectedMaterials = $mStmt->fetchAll();

        if ($selected['po_id']) {
            $poStmt = $pdo->prepare('SELECT doc_number FROM purchase_orders WHERE id=?');
            $poStmt->execute([$selected['po_id']]);
            $trail['po'] = $poStmt->fetch()['doc_number'] ?? null;
        }
    }
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if ($isNewForm): ?>
  <!-- ===================== FORM FULL PAGE: BUAT SPK ===================== -->
  <div class="card txn-form-page">
    <div class="txn-detail-header"><h2>Buat SPK / Manufaktur</h2><a class="btn btn-sm btn-ghost" href="spk.php">Batal</a></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_spk">
      <input type="hidden" name="po_id" value="<?= $createForPo['id'] ?? '' ?>">

      <?php if ($createForPo): ?>
        <div style="font-size:13px; color:var(--ink-muted); margin-bottom:10px;">
          Dari PO <strong><?= htmlspecialchars($createForPo['doc_number']) ?></strong>, Vendor: <strong><?= htmlspecialchars($createForPo['vendor_name']) ?></strong>
        </div>
        <input type="hidden" name="vendor_id" value="<?= $createForPo['vendor_id'] ?>">
      <?php else: ?>
        <div class="field">
          <label>Vendor Perakit</label>
          <select name="vendor_id" required>
            <option value="">— Pilih Vendor —</option>
            <?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <div class="field-row">
        <div class="field">
          <label>Produk Target (hasil rakitan)</label>
          <select name="product_id" id="spk-product-select" required>
            <option value="">— Pilih Produk —</option>
            <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" <?= ($createForPo['po_product_id'] ?? null) == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Qty Hasil<?= $createForPo ? ' (dari PO, ' . rtrim(rtrim(number_format((float) $createForPo['po_qty'], 2, ',', '.'), '0'), ',') . ')' : '' ?></label>
          <input type="number" step="0.01" name="output_qty" id="spk-output-qty" value="<?= $createForPo ? (float) $createForPo['po_qty'] : '' ?>" required>
          <?php if ($createForPo): ?><div style="font-size:11.5px; color:var(--ink-muted); margin-top:4px;">Boleh diubah kalau hasil produksi beneran beda dari qty PO (mis. ada yang cacat).</div><?php endif; ?>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label>Biaya Jasa Rakit<?= $createForPo ? ' (dari PO, Rp ' . number_format((float) $createForPo['po_total'], 0, ',', '.') . ')' : '' ?></label>
          <input type="text" inputmode="numeric" class="rupiah-input" name="assembly_fee" value="<?= $createForPo['po_total'] ?? 0 ?>" <?= $createForPo ? 'readonly' : '' ?>>
        </div>
        <div class="field">
          <label>Gudang Sumber Material</label>
          <select name="warehouse_id" id="spk-warehouse-select" required>
            <?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field"><label>Estimasi Selesai</label><input type="date" name="estimated_finish"></div>

      <label style="display:block; font-size:12px; font-weight:600; margin:14px 0 6px;">Checklist Material (dari BOM produk, auto-hitung kebutuhan)</label>
      <div id="spk-material-lines"></div>
      <button type="button" class="btn btn-sm btn-ghost" id="add-material-line">+ Tambah Material Manual</button>
      <div id="spk-material-summary" style="margin-top:10px; font-size:13px; font-weight:600;"></div>

      <div style="margin-top:16px;"><button type="submit" class="btn" id="spk-submit-btn">Simpan SPK</button></div>
    </form>
  </div>

  <script>
  var SPK_MATERIALS = <?= json_encode($materialsList) ?>;
  var PRODUCT_BOM = <?= json_encode($productBom) ?>;
  var MATERIAL_STOCK = <?= json_encode($materialStockByWarehouse) ?>;
  var linesWrap = document.getElementById('spk-material-lines');

  function materialOptions(selectedId) {
    var opts = '<option value="">— Material —</option>';
    SPK_MATERIALS.forEach(function (m) {
      opts += '<option value="' + m.id + '"' + (m.id == selectedId ? ' selected' : '') + '>' + m.name + ' (' + m.unit + ')</option>';
    });
    return opts;
  }

  function addMaterialLine(materialId, qty) {
    var row = document.createElement('div');
    row.className = 'spk-material-row';
    row.style.cssText = 'display:flex; gap:8px; margin-bottom:8px; align-items:center;';
    row.innerHTML =
      '<select name="material_id[]" class="spk-mat-select" style="flex:2;padding:8px;border:1px solid var(--border);border-radius:4px;">' + materialOptions(materialId) + '</select>' +
      '<input type="number" name="material_qty[]" class="spk-mat-qty" placeholder="Qty" step="0.01" value="' + (qty || '') + '" style="width:90px;padding:8px;border:1px solid var(--border);border-radius:4px;">' +
      '<span class="spk-mat-check" style="width:150px; font-size:12px;"></span>' +
      '<button type="button" class="btn btn-sm btn-ghost" onclick="this.closest(\'.spk-material-row\').remove(); checkMaterialSufficiency();">✕</button>';
    row.querySelector('.spk-mat-select').addEventListener('change', checkMaterialSufficiency);
    row.querySelector('.spk-mat-qty').addEventListener('input', checkMaterialSufficiency);
    linesWrap.appendChild(row);
  }

  document.getElementById('add-material-line').addEventListener('click', function () { addMaterialLine(null, null); });

  function fillFromBom() {
    linesWrap.innerHTML = '';
    var productId = document.getElementById('spk-product-select').value;
    var outputQty = parseFloat(document.getElementById('spk-output-qty').value) || 0;
    var bom = PRODUCT_BOM[productId];
    if (bom && bom.length) {
      bom.forEach(function (b) {
        addMaterialLine(b.material_id, (parseFloat(b.qty) * outputQty) || '');
      });
    } else {
      addMaterialLine(null, null);
    }
    checkMaterialSufficiency();
  }

  function checkMaterialSufficiency() {
    var warehouseId = document.getElementById('spk-warehouse-select').value;
    var stock = MATERIAL_STOCK[warehouseId] || {};
    var allOk = true;
    var anyRow = false;
    document.querySelectorAll('.spk-material-row').forEach(function (row) {
      var matId = row.querySelector('.spk-mat-select').value;
      var qty = parseFloat(row.querySelector('.spk-mat-qty').value) || 0;
      var check = row.querySelector('.spk-mat-check');
      if (!matId || qty <= 0) { check.textContent = ''; return; }
      anyRow = true;
      var available = stock[matId] || 0;
      if (available >= qty) {
        check.innerHTML = '✅ cukup (stok ' + available + ')';
        check.style.color = 'var(--green)';
      } else {
        check.innerHTML = '⚠️ kurang ' + (qty - available) + ' (stok ' + available + ')';
        check.style.color = 'var(--danger)';
        allOk = false;
      }
    });
    var summary = document.getElementById('spk-material-summary');
    var submitBtn = document.getElementById('spk-submit-btn');
    if (!anyRow) {
      summary.textContent = '';
      submitBtn.disabled = false;
      return;
    }
    if (allOk) {
      summary.innerHTML = '✅ Semua material cukup, siap dibuat SPK.';
      summary.style.color = 'var(--green)';
      submitBtn.disabled = false;
    } else {
      summary.innerHTML = '⚠️ Ada material yang stoknya belum cukup — buat/tunggu PO bahan baku dulu sebelum lanjut, atau kurangi Qty Hasil.';
      summary.style.color = 'var(--danger)';
      submitBtn.disabled = true;
    }
  }

  document.getElementById('spk-product-select').addEventListener('change', fillFromBom);
  document.getElementById('spk-output-qty').addEventListener('input', fillFromBom);
  document.getElementById('spk-warehouse-select').addEventListener('change', checkMaterialSufficiency);
  <?php if ($createForPo && $createForPo['po_product_id']): ?>
    fillFromBom();
  <?php else: ?>
    addMaterialLine(null, null);
  <?php endif; ?>
  </script>

<?php else: ?>
  <!-- ===================== LIST: RAIL + DETAIL ===================== -->
  <div class="txn-shell">
    <div class="txn-rail">
      <div class="txn-rail-month">
        <a href="spk.php?month=<?= $prevMonth ?>">‹</a>
        <span><?= htmlspecialchars(date('F Y', strtotime($month . '-01'))) ?></span>
        <a href="spk.php?month=<?= $nextMonth ?>">›</a>
        <a class="today-btn" href="spk.php">Bulan Ini</a>
      </div>
      <div class="txn-rail-list">
        <?php foreach ($railItems as $r): ?>
          <a class="txn-rail-item <?= (int) $r['id'] === $selectedId ? 'active' : '' ?>" href="spk.php?month=<?= $month ?>&id=<?= $r['id'] ?>">
            <div class="doc"><?= htmlspecialchars($r['doc_number']) ?></div>
            <div class="sub"><?= htmlspecialchars($r['product_name']) ?> · <span class="pill pill-<?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span></div>
          </a>
        <?php endforeach; ?>
        <?php if (!$railItems): ?><div style="padding:14px; font-size:12px; color:var(--ink-muted);">Gak ada SPK bulan ini.</div><?php endif; ?>
      </div>
      <div style="padding:10px;"><a class="btn btn-sm" style="width:100%;" href="spk.php?new=1">+ Buat SPK</a></div>
    </div>

    <div class="txn-detail">
      <?php if (!$selected): ?>
        <div class="card txn-empty">Pilih SPK di kiri, atau buat yang baru.</div>
      <?php else: ?>
        <?php
          $materialCost = 0;
          foreach ($selectedMaterials as $m) $materialCost += (float) $m['qty'] * (float) $m['unit_cost'];
          $totalCost = $materialCost + (float) $selected['assembly_fee'];
          $unitCost = (float) $selected['output_qty'] > 0 ? $totalCost / (float) $selected['output_qty'] : 0;
        ?>
        <div class="card">
          <div class="txn-detail-header">
            <div><h2><?= htmlspecialchars($selected['doc_number']) ?> <span class="pill pill-<?= $selected['status'] ?>"><?= strtoupper($selected['status']) ?></span></h2></div>
            <div class="txn-detail-actions">
              <a class="btn btn-sm btn-ghost" href="spk-print.php?id=<?= $selected['id'] ?>" target="_blank">Print</a>
              <?php if (has_access('spk', 'can_edit')): ?>
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="spk_id" value="<?= $selected['id'] ?>">
                  <select name="status" onchange="this.form.submit();" <?= $selected['receipt_id'] ? 'disabled' : '' ?> style="padding:6px 10px; border:1px solid var(--border); border-radius:4px; font-size:12px;">
                    <?php foreach (['draft', 'in_production', 'done', 'void'] as $st): ?>
                      <option value="<?= $st ?>" <?= $selected['status'] === $st ? 'selected' : '' ?>><?= strtoupper($st) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="txn-info-strip">
            <div><span class="lbl">Vendor</span><?= htmlspecialchars($selected['vendor_name']) ?></div>
            <div><span class="lbl">Produk Target</span><?= htmlspecialchars($selected['product_name']) ?> (x<?= rtrim(rtrim(number_format((float) $selected['output_qty'], 2, ',', '.'), '0'), ',') ?>)</div>
            <div><span class="lbl">PO Jasa Produksi</span><?= $trail['po'] ? htmlspecialchars($trail['po']) : '—' ?></div>
            <div><span class="lbl">Estimasi Selesai</span><?= $selected['estimated_finish'] ? htmlspecialchars(date('d M Y', strtotime($selected['estimated_finish']))) : '—' ?></div>
          </div>

          <table class="data-table">
            <thead><tr><th>Material Dikirim ke Vendor</th><th class="num">Qty</th><th class="num">Unit Cost (FIFO)</th><th class="num">Subtotal</th></tr></thead>
            <tbody>
              <?php foreach ($selectedMaterials as $m): ?>
                <tr>
                  <td><?= htmlspecialchars($m['material_name_snapshot']) ?></td>
                  <td class="num"><?= rtrim(rtrim(number_format((float) $m['qty'], 2, ',', '.'), '0'), ',') ?></td>
                  <td class="num">Rp <?= number_format((float) $m['unit_cost'], 0, ',', '.') ?></td>
                  <td class="num">Rp <?= number_format((float) $m['qty'] * (float) $m['unit_cost'], 0, ',', '.') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="txn-totals">
            <div class="row"><span>Total Cost Material</span><span>Rp <?= number_format($materialCost, 0, ',', '.') ?></span></div>
            <div class="row"><span>Biaya Jasa Rakit</span><span>Rp <?= number_format((float) $selected['assembly_fee'], 0, ',', '.') ?></span></div>
            <div class="row grand"><span>Total Cost Manufaktur</span><span>Rp <?= number_format($totalCost, 0, ',', '.') ?></span></div>
            <div class="row"><span>HPP per Unit (estimasi)</span><span>Rp <?= number_format($unitCost, 0, ',', '.') ?></span></div>
          </div>

          <?php if ($selected['receipt_id']): ?>
            <div class="alert alert-ok" style="margin-top:16px;">Hasil produksi sudah diterima ke gudang — lihat di Penerimaan Barang.</div>
          <?php elseif (has_access('spk', 'can_edit')): ?>
            <form method="post" style="display:flex; gap:8px; align-items:flex-end; margin-top:16px; padding-top:16px; border-top:1px solid var(--border);">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="receive_output">
              <input type="hidden" name="spk_id" value="<?= $selected['id'] ?>">
              <div class="field" style="margin-bottom:0; flex:1;">
                <label>Terima Hasil Produksi ke Gudang</label>
                <select name="warehouse_id" required>
                  <?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-sm">Terima &amp; Tambah Stok</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
