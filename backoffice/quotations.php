<?php
$pageTitle = 'Penawaran';
$activeMenu = 'penawaran';
require __DIR__ . '/includes/header.php';
require_module_access('penawaran');
require_once __DIR__ . '/../backoffice-shared/doc_number.php';

$pdo = db();
$flash = null;
const TIER_LABELS_Q = ['ekonomis' => 'Ekonomis', 'standard' => 'Standard', 'premium' => 'Premium', 'deluxe' => 'Deluxe', 'bespoke' => 'Bespoke'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_quotation') {
            require_module_access('penawaran', 'can_create');
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            $projectId = (int) ($_POST['project_id'] ?? 0) ?: null;
            $salesUserId = (int) ($_POST['sales_user_id'] ?? 0) ?: $user['id'];
            $termsId = (int) ($_POST['terms_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '') ?: null;
            $discountType = in_array($_POST['discount_type'] ?? '', ['percent', 'amount'], true) ? $_POST['discount_type'] : null;
            $discountValue = (float) ($_POST['discount_value'] ?? 0);
            $tierIds = $_POST['tier_id'] ?? [];
            $qtys = $_POST['qty'] ?? [];
            $customNotes = $_POST['custom_note'] ?? [];
            if (!$contactId) throw new RuntimeException('Customer wajib dipilih.');
            if (!$tierIds) throw new RuntimeException('Minimal 1 baris item.');

            $termsSnapshot = null;
            if ($termsId) {
                $tc = $pdo->prepare('SELECT content FROM terms_conditions WHERE id=? AND organization_id=?');
                $tc->execute([$termsId, $org['organization_id']]);
                $termsSnapshot = $tc->fetch()['content'] ?? null;
            }

            $pdo->beginTransaction();
            try {
                $docNumber = next_doc_number($org['organization_id'], 'penawaran');
                $pdo->prepare('INSERT INTO quotations (organization_id, doc_number, contact_id, project_id, sales_user_id, notes, terms_snapshot, discount_type, discount_value, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$org['organization_id'], $docNumber, $contactId, $projectId, $salesUserId, $notes, $termsSnapshot, $discountType, $discountValue, $user['id']]);
                $quotationId = (int) $pdo->lastInsertId();

                $tierStmt = $pdo->prepare(
                    'SELECT pt.*, p.name AS product_name FROM product_tiers pt JOIN products p ON p.id=pt.product_id
                     WHERE pt.id=? AND p.organization_id=?'
                );
                $lineStmt = $pdo->prepare(
                    'INSERT INTO quotation_lines (quotation_id, product_id, product_name_snapshot, tier_id, tier_level_snapshot, tier_version_snapshot, bom_snapshot, qty, unit_price, custom_note)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
                foreach ($tierIds as $i => $tierId) {
                    $tierId = (int) $tierId;
                    if (!$tierId) continue;
                    $tierStmt->execute([$tierId, $org['organization_id']]);
                    $tier = $tierStmt->fetch();
                    if (!$tier) continue;
                    $qty = (float) ($qtys[$i] ?? 1);
                    $lineStmt->execute([
                        $quotationId, $tier['product_id'], $tier['product_name'],
                        $tierId, $tier['tier_level'], $tier['version'], $tier['bom_json'],
                        $qty ?: 1, $tier['price'], trim($customNotes[$i] ?? '') ?: null,
                    ]);
                }
                $pdo->commit();
                $flash = ['ok', "Penawaran $docNumber dibuat."];
                header('Location: quotations.php?id=' . $quotationId);
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } elseif ($action === 'quick_add_project') {
            $name = trim($_POST['name'] ?? '');
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            if ($name === '') throw new RuntimeException('Nama project wajib diisi.');
            if (!$contactId) throw new RuntimeException('Customer wajib dipilih.');
            $pdo->prepare('INSERT INTO projects (organization_id, name, contact_id, created_by) VALUES (?,?,?,?)')->execute([$org['organization_id'], $name, $contactId, $user['id']]);
            $newId = (int) $pdo->lastInsertId();
            header('Location: quotations.php?new=1&picked_project=' . $newId);
            exit;
        } elseif ($action === 'update_status') {
            require_module_access('penawaran', 'can_edit');
            $id = (int) ($_POST['quotation_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (in_array($status, ['draft', 'sent', 'approved', 'rejected'], true)) {
                $pdo->prepare('UPDATE quotations SET status=? WHERE id=? AND organization_id=?')->execute([$status, $id, $org['organization_id']]);
                $flash = ['ok', 'Status penawaran diperbarui.'];
            }
        } elseif ($action === 'delete_quotation') {
            require_module_access('penawaran', 'can_delete');
            $id = (int) ($_POST['quotation_id'] ?? 0);
            $used = $pdo->prepare('SELECT COUNT(*) c FROM invoices WHERE quotation_id=?');
            $used->execute([$id]);
            if ((int) $used->fetch()['c'] > 0) throw new RuntimeException('Penawaran ini sudah dipakai di Invoice, tidak bisa dihapus.');
            $pdo->prepare('DELETE FROM quotations WHERE id=? AND organization_id=?')->execute([$id, $org['organization_id']]);
            $flash = ['ok', 'Penawaran dihapus.'];
        }
    } catch (Throwable $e) {
        $flash = ['error', $e->getMessage()];
    }
}

$contacts = $pdo->prepare("SELECT id, name FROM contacts WHERE organization_id=? AND type IN ('customer','both') ORDER BY name");
$contacts->execute([$org['organization_id']]);
$contacts = $contacts->fetchAll();

$projectsList = $pdo->prepare('SELECT id, name FROM projects WHERE organization_id=? ORDER BY name');
$projectsList->execute([$org['organization_id']]);
$projectsList = $projectsList->fetchAll();

$orgMembers = $pdo->prepare('SELECT u.id, u.name FROM user_organization_roles uor JOIN users u ON u.id=uor.user_id WHERE uor.organization_id=? AND uor.status="active" ORDER BY u.name');
$orgMembers->execute([$org['organization_id']]);
$orgMembers = $orgMembers->fetchAll();

$termsList = $pdo->prepare('SELECT id, title, content FROM terms_conditions WHERE organization_id=? ORDER BY title');
$termsList->execute([$org['organization_id']]);
$termsList = $termsList->fetchAll();

// Katalog produk+tier+spec buat picker JS di form (harga di-lock ulang server-side pas submit).
$catalog = $pdo->prepare(
    "SELECT p.id AS product_id, p.name AS product_name, p.material, p.item_type, p.collection, p.size, p.extra_specs,
       pt.id AS tier_id, pt.tier_level, pt.price
     FROM products p JOIN product_tiers pt ON pt.product_id=p.id AND pt.is_active=1
     WHERE p.organization_id=? ORDER BY p.name"
);
$catalog->execute([$org['organization_id']]);
$productsForPicker = [];
foreach ($catalog->fetchAll() as $row) {
    if (!isset($productsForPicker[$row['product_id']])) {
        $productsForPicker[$row['product_id']] = [
            'name' => $row['product_name'], 'material' => $row['material'], 'item_type' => $row['item_type'],
            'collection' => $row['collection'], 'size' => $row['size'], 'extra_specs' => json_decode($row['extra_specs'] ?? '[]', true) ?: [],
            'tiers' => [],
        ];
    }
    $productsForPicker[$row['product_id']]['tiers'][] = ['tier_id' => $row['tier_id'], 'level' => $row['tier_level'], 'price' => $row['price']];
}

$isNewForm = isset($_GET['new']);
$pickedProject = (int) ($_GET['picked_project'] ?? 0);

if (!$isNewForm) {
    $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    [$monthYear, $monthNum] = explode('-', $month);
    $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
    $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

    $railStmt = $pdo->prepare(
        "SELECT q.*, c.name AS contact_name FROM quotations q JOIN contacts c ON c.id=q.contact_id
         WHERE q.organization_id=? AND DATE_FORMAT(q.created_at,'%Y-%m')=? ORDER BY q.created_at DESC"
    );
    $railStmt->execute([$org['organization_id'], $month]);
    $railItems = $railStmt->fetchAll();

    $selectedId = (int) ($_GET['id'] ?? ($railItems[0]['id'] ?? 0));
    $selected = null;
    $selectedLines = [];
    foreach ($railItems as $r) { if ((int) $r['id'] === $selectedId) { $selected = $r; break; } }
    if (!$selected && $selectedId) {
        $sStmt = $pdo->prepare('SELECT q.*, c.name AS contact_name FROM quotations q JOIN contacts c ON c.id=q.contact_id WHERE q.id=? AND q.organization_id=?');
        $sStmt->execute([$selectedId, $org['organization_id']]);
        $selected = $sStmt->fetch() ?: null;
    }
    if ($selected) {
        $lStmt = $pdo->prepare('SELECT * FROM quotation_lines WHERE quotation_id=?');
        $lStmt->execute([$selected['id']]);
        $selectedLines = $lStmt->fetchAll();

        $projName = null;
        if ($selected['project_id']) {
            $pjStmt = $pdo->prepare('SELECT name FROM projects WHERE id=?');
            $pjStmt->execute([$selected['project_id']]);
            $projName = $pjStmt->fetch()['name'] ?? null;
        }
        $salesName = null;
        if ($selected['sales_user_id']) {
            $suStmt = $pdo->prepare('SELECT name FROM users WHERE id=?');
            $suStmt->execute([$selected['sales_user_id']]);
            $salesName = $suStmt->fetch()['name'] ?? null;
        }
    }
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if ($isNewForm): ?>
  <!-- ===================== FORM FULL PAGE: BUAT PENAWARAN ===================== -->
  <div class="card txn-form-page">
    <div class="txn-detail-header"><h2>Buat Penawaran</h2><a class="btn btn-sm btn-ghost" href="quotations.php">Batal</a></div>
    <form method="post" id="quotation-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_quotation">
      <div class="field-row">
        <div class="field">
          <label>Customer</label>
          <select name="contact_id" id="quotation-contact-select" required>
            <option value="">— Pilih Customer —</option>
            <?php foreach ($contacts as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Project (opsional)</label>
          <div style="display:flex; gap:6px;">
            <select name="project_id" style="flex:1;">
              <option value="">— Tidak terkait project —</option>
              <?php foreach ($projectsList as $p): ?><option value="<?= $p['id'] ?>" <?= $pickedProject === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-sm btn-ghost" id="quick-project-open-btn">+ Baru</button>
          </div>
        </div>
        <div class="field">
          <label>Sales</label>
          <select name="sales_user_id">
            <?php foreach ($orgMembers as $m): ?><option value="<?= $m['id'] ?>" <?= $m['id'] == $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>

      <label style="display:block; font-size:12px; font-weight:600; margin:16px 0 8px;">Item</label>
      <div id="quotation-lines"></div>
      <button type="button" class="btn btn-sm btn-ghost" id="add-line-btn">+ Tambah Item</button>

      <div class="field-row" style="margin-top:16px;">
        <div class="field">
          <label>Syarat &amp; Ketentuan (opsional)</label>
          <select name="terms_id" id="terms-select">
            <option value="">— Tidak pakai T&amp;C —</option>
            <?php foreach ($termsList as $t): ?><option value="<?= $t['id'] ?>" data-preview="<?= htmlspecialchars($t['content']) ?>"><?= htmlspecialchars($t['title']) ?></option><?php endforeach; ?>
          </select>
          <div id="terms-preview" class="txn-spec-box" style="display:none;"></div>
        </div>
        <div class="field"><label>Catatan</label><input type="text" name="notes"></div>
      </div>

      <div class="txn-totals">
        <div class="row"><span>Subtotal</span><span id="calc-subtotal">Rp 0</span></div>
        <div class="row" style="align-items:center;">
          <span>Diskon</span>
          <span style="display:flex; gap:4px; align-items:center;">
            <input type="text" inputmode="numeric" class="rupiah-input" name="discount_value" id="discount-value" value="0" style="width:90px; padding:5px; border:1px solid var(--border); border-radius:4px;">
            <select name="discount_type" id="discount-type" style="padding:5px; border:1px solid var(--border); border-radius:4px;">
              <option value="percent">%</option>
              <option value="amount">Rp</option>
            </select>
          </span>
        </div>
        <div class="row"><span>PPN 11%</span><span id="calc-ppn">Rp 0</span></div>
        <div class="row grand"><span>Grand Total</span><span id="calc-total">Rp 0</span></div>
      </div>

      <div style="margin-top:16px;"><button type="submit" class="btn">Simpan Penawaran</button></div>
    </form>
  </div>

  <div class="modal-scrim" id="quick-project-modal">
    <div class="modal-card">
      <div class="modal-head"><h3>Project Baru</h3><button class="modal-close" data-close-modal="quick-project-modal">&times;</button></div>
      <form method="post">
        <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="quick_add_project">
          <input type="hidden" name="contact_id" id="quick-project-contact-id">
          <div class="field"><label>Nama Project</label><input type="text" name="name" required></div>
          <p id="quick-project-customer-hint" style="font-size:12px; color:var(--ink-muted); margin-top:-6px;"></p>
        </div>
        <div class="modal-foot">
          <button type="button" class="btn btn-ghost" data-close-modal="quick-project-modal">Batal</button>
          <button type="submit" class="btn">Simpan &amp; Pilih</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  var QUOTATION_CATALOG = <?= json_encode($productsForPicker) ?>;
  var TIER_LABELS = <?= json_encode(TIER_LABELS_Q) ?>;
  (function () {
    var openBtn = document.getElementById('quick-project-open-btn');
    var contactSelect = document.getElementById('quotation-contact-select');
    var hiddenContact = document.getElementById('quick-project-contact-id');
    var hint = document.getElementById('quick-project-customer-hint');
    if (openBtn) {
      openBtn.addEventListener('click', function () {
        var contactId = contactSelect ? contactSelect.value : '';
        if (!contactId) {
          alert('Pilih Customer dulu sebelum bikin Project baru.');
          return;
        }
        hiddenContact.value = contactId;
        var contactName = contactSelect.options[contactSelect.selectedIndex].text;
        hint.textContent = 'Customer: ' + contactName;
        document.getElementById('quick-project-modal').classList.add('open');
      });
    }
  })();
  (function () {
    var container = document.getElementById('quotation-lines');
    function recalc() {
      var subtotal = 0;
      container.querySelectorAll('.txn-item-row').forEach(function (row) {
        var tierSel = row.querySelector('.q-tier');
        var qty = parseFloat(row.querySelector('.q-qty').value) || 0;
        var opt = tierSel.options[tierSel.selectedIndex];
        var price = opt ? parseFloat(opt.getAttribute('data-price') || 0) : 0;
        var lineTotal = qty * price;
        row.querySelector('.q-line-total').textContent = 'Rp ' + lineTotal.toLocaleString('id-ID');
        subtotal += lineTotal;
      });
      var discVal = parseFloat(document.getElementById('discount-value').value.replace(/[^\d]/g, '')) || 0;
      var discType = document.getElementById('discount-type').value;
      var discAmount = discType === 'percent' ? subtotal * (discVal / 100) : discVal;
      var afterDisc = Math.max(0, subtotal - discAmount);
      var ppn = afterDisc * 0.11;
      var total = afterDisc + ppn;
      document.getElementById('calc-subtotal').textContent = 'Rp ' + subtotal.toLocaleString('id-ID');
      document.getElementById('calc-ppn').textContent = 'Rp ' + ppn.toLocaleString('id-ID');
      document.getElementById('calc-total').textContent = 'Rp ' + total.toLocaleString('id-ID');
    }
    document.getElementById('discount-value').addEventListener('input', recalc);
    document.getElementById('discount-type').addEventListener('change', recalc);
    document.getElementById('terms-select').addEventListener('change', function () {
      var preview = document.getElementById('terms-preview');
      var opt = this.options[this.selectedIndex];
      var content = opt.getAttribute('data-preview');
      if (content) { preview.textContent = content; preview.style.display = 'block'; } else { preview.style.display = 'none'; }
    });

    document.getElementById('add-line-btn').addEventListener('click', function () {
      var row = document.createElement('div');
      row.className = 'txn-item-row';
      var productOpts = '<option value="">— Pilih Produk —</option>';
      Object.keys(QUOTATION_CATALOG).forEach(function (pid) {
        var p = QUOTATION_CATALOG[pid];
        var label = p.name + (p.material ? ' — ' + p.material : '') + (p.size ? ' (' + p.size + ')' : '');
        productOpts += '<option value="' + pid + '">' + label + '</option>';
      });
      row.innerHTML =
        '<select class="q-product" style="width:100%; padding:8px; border:1px solid var(--border); border-radius:4px; margin-bottom:6px;">' + productOpts + '</select>' +
        '<div class="q-spec-box txn-spec-box" style="display:none;"></div>' +
        '<div style="display:flex; gap:8px; align-items:center; margin-top:6px;">' +
        '<select name="tier_id[]" class="q-tier" style="flex:2; padding:8px; border:1px solid var(--border); border-radius:4px;"><option value="">Tier</option></select>' +
        '<input type="number" name="qty[]" class="q-qty" value="1" min="0.01" step="0.01" style="width:70px; padding:8px; border:1px solid var(--border); border-radius:4px;">' +
        '<span class="q-line-total" style="width:130px; text-align:right; font-weight:600; font-size:13px;">Rp 0</span>' +
        '<button type="button" class="btn btn-sm btn-ghost" onclick="this.closest(\'.txn-item-row\').remove(); document.getElementById(\'discount-value\').dispatchEvent(new Event(\'input\'));">✕</button>' +
        '</div>' +
        '<input type="text" name="custom_note[]" placeholder="Catatan item (opsional)" style="width:100%; padding:8px; border:1px solid var(--border); border-radius:4px; margin-top:6px;">';
      container.appendChild(row);

      row.querySelector('.q-product').addEventListener('change', function () {
        var product = QUOTATION_CATALOG[this.value];
        var specBox = row.querySelector('.q-spec-box');
        var tierSelect = row.querySelector('.q-tier');
        tierSelect.innerHTML = '<option value="">Tier</option>';
        if (!product) { specBox.style.display = 'none'; recalc(); return; }
        var specLines = [];
        if (product.material) specLines.push('Material: ' + product.material);
        if (product.item_type) specLines.push('Item: ' + product.item_type);
        if (product.collection) specLines.push('Collection: ' + product.collection);
        if (product.size) specLines.push('Ukuran: ' + product.size);
        (product.extra_specs || []).forEach(function (es) { specLines.push(es.label + ': ' + es.value); });
        specBox.innerHTML = specLines.length ? specLines.join('<br>') : 'Belum ada spec.';
        specBox.style.display = 'block';
        product.tiers.forEach(function (t) {
          var opt = document.createElement('option');
          opt.value = t.tier_id;
          opt.setAttribute('data-price', t.price);
          opt.textContent = (TIER_LABELS[t.level] || t.level) + ' — Rp ' + Number(t.price).toLocaleString('id-ID');
          tierSelect.appendChild(opt);
        });
        recalc();
      });
      row.querySelector('.q-tier').addEventListener('change', recalc);
      row.querySelector('.q-qty').addEventListener('input', recalc);
    });
    document.getElementById('add-line-btn').click();
  })();
  </script>

<?php else: ?>
  <!-- ===================== LIST: RAIL + DETAIL ===================== -->
  <div class="txn-shell">
    <div class="txn-rail">
      <div class="txn-rail-month">
        <a href="quotations.php?month=<?= $prevMonth ?>">‹</a>
        <span><?= htmlspecialchars(date('F Y', strtotime($month . '-01'))) ?></span>
        <a href="quotations.php?month=<?= $nextMonth ?>">›</a>
        <a class="today-btn" href="quotations.php">Bulan Ini</a>
      </div>
      <div class="txn-rail-list">
        <?php foreach ($railItems as $r): ?>
          <a class="txn-rail-item <?= (int) $r['id'] === $selectedId ? 'active' : '' ?>" href="quotations.php?month=<?= $month ?>&id=<?= $r['id'] ?>">
            <div class="doc"><?= htmlspecialchars($r['doc_number']) ?></div>
            <div class="sub"><?= htmlspecialchars($r['contact_name']) ?> · <span class="pill pill-<?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span></div>
          </a>
        <?php endforeach; ?>
        <?php if (!$railItems): ?><div style="padding:14px; font-size:12px; color:var(--ink-muted);">Gak ada Penawaran bulan ini.</div><?php endif; ?>
      </div>
      <div style="padding:10px;"><a class="btn btn-sm" style="width:100%;" href="quotations.php?new=1">+ Buat Penawaran</a></div>
    </div>

    <div class="txn-detail">
      <?php if (!$selected): ?>
        <div class="card txn-empty">Pilih Penawaran di kiri, atau buat yang baru.</div>
      <?php else: ?>
        <?php
        $subtotal = 0;
        foreach ($selectedLines as $l) $subtotal += $l['qty'] * $l['unit_price'];
        $discAmount = $selected['discount_type'] === 'percent' ? $subtotal * ((float) $selected['discount_value'] / 100) : (float) $selected['discount_value'];
        $afterDisc = max(0, $subtotal - $discAmount);
        $ppn = $afterDisc * 0.11;
        $grandTotal = $afterDisc + $ppn;
        ?>
        <div class="card">
          <div class="txn-detail-header">
            <div>
              <h2><?= htmlspecialchars($selected['doc_number']) ?> <span class="pill pill-<?= $selected['status'] ?>"><?= strtoupper($selected['status']) ?></span></h2>
            </div>
            <div class="txn-detail-actions">
              <a class="btn btn-sm btn-ghost" href="quotation-print.php?id=<?= $selected['id'] ?>" target="_blank">Print</a>
              <?php if (has_access('penawaran', 'can_edit')): ?>
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="quotation_id" value="<?= $selected['id'] ?>">
                  <select name="status" onchange="this.form.submit();" style="padding:6px 10px; border:1px solid var(--border); border-radius:4px; font-size:12px;">
                    <?php foreach (['draft', 'sent', 'approved', 'rejected'] as $s): ?>
                      <option value="<?= $s ?>" <?= $selected['status'] === $s ? 'selected' : '' ?>><?= strtoupper($s) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php endif; ?>
              <?php if (has_access('penawaran', 'can_delete')): ?>
                <button class="btn btn-sm btn-ghost" type="button" onclick="if(confirm('Hapus penawaran ini?')) __submitDeleteForm('delete_quotation', {quotation_id: <?= $selected['id'] ?>})">Void</button>
              <?php endif; ?>
            </div>
          </div>

          <div class="txn-info-strip">
            <div><span class="lbl">Customer</span><?= htmlspecialchars($selected['contact_name']) ?></div>
            <div><span class="lbl">Project</span><?= $projName ? htmlspecialchars($projName) : '—' ?></div>
            <div><span class="lbl">Sales</span><?= $salesName ? htmlspecialchars($salesName) : '—' ?></div>
            <div><span class="lbl">Tanggal</span><?= htmlspecialchars(date('d M Y', strtotime($selected['created_at']))) ?></div>
          </div>

          <table class="data-table">
            <thead><tr><th>Produk</th><th>Tier</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Total</th></tr></thead>
            <tbody>
              <?php foreach ($selectedLines as $l): ?>
                <tr>
                  <td><?= htmlspecialchars($l['product_name_snapshot']) ?><?= $l['custom_note'] ? '<br><small style="color:var(--ink-muted);">' . htmlspecialchars($l['custom_note']) . '</small>' : '' ?></td>
                  <td><?= htmlspecialchars(ucfirst($l['tier_level_snapshot'])) ?></td>
                  <td class="num"><?= rtrim(rtrim(number_format((float) $l['qty'], 2, ',', '.'), '0'), ',') ?></td>
                  <td class="num">Rp <?= number_format((float) $l['unit_price'], 0, ',', '.') ?></td>
                  <td class="num">Rp <?= number_format((float) $l['qty'] * (float) $l['unit_price'], 0, ',', '.') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="txn-totals">
            <div class="row"><span>Subtotal</span><span>Rp <?= number_format($subtotal, 0, ',', '.') ?></span></div>
            <?php if ($discAmount > 0): ?><div class="row"><span>Diskon</span><span>- Rp <?= number_format($discAmount, 0, ',', '.') ?></span></div><?php endif; ?>
            <div class="row"><span>PPN 11%</span><span>Rp <?= number_format($ppn, 0, ',', '.') ?></span></div>
            <div class="row grand"><span>Grand Total</span><span>Rp <?= number_format($grandTotal, 0, ',', '.') ?></span></div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
