<?php
$pageTitle = 'Invoicing';
$activeMenu = 'invoicing';
require __DIR__ . '/includes/header.php';
require_module_access('invoicing');
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_once __DIR__ . '/../backoffice-shared/material_request.php';

$pdo = db();
$flash = null;

/** Grand total Penawaran (subtotal - diskon + PPN 11%), sama kayak quotations.php. */
function quotation_grand_total(PDO $pdo, int $quotationId): float
{
    $stmt = $pdo->prepare(
        'SELECT discount_type, discount_value, (SELECT COALESCE(SUM(qty*unit_price),0) FROM quotation_lines WHERE quotation_id=quotations.id) AS subtotal
         FROM quotations WHERE id=?'
    );
    $stmt->execute([$quotationId]);
    $row = $stmt->fetch();
    if (!$row) return 0.0;
    $subtotal = (float) $row['subtotal'];
    $disc = $row['discount_type'] === 'percent' ? $subtotal * ((float) $row['discount_value'] / 100) : (float) $row['discount_value'];
    $afterDisc = max(0, $subtotal - $disc);
    return $afterDisc + ($afterDisc * 0.11);
}

/** Total udah ditagih (semua invoice non-void) buat 1 Penawaran. */
function quotation_billed_so_far(PDO $pdo, int $quotationId, ?int $excludeInvoiceId = null): float
{
    $sql = 'SELECT COALESCE(SUM(billed_amount),0) c FROM invoices WHERE quotation_id=? AND status != "void"';
    $params = [$quotationId];
    if ($excludeInvoiceId) { $sql .= ' AND id != ?'; $params[] = $excludeInvoiceId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetch()['c'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_invoice') {
            require_module_access('invoicing', 'can_create');
            $quotationId = (int) ($_POST['quotation_id'] ?? 0);
            $salesUserId = (int) ($_POST['sales_user_id'] ?? 0) ?: $user['id'];
            $termsId = (int) ($_POST['terms_id'] ?? 0);
            $scheme = $_POST['payment_scheme'] === 'dp' ? 'dp' : 'full';
            $dpType = in_array($_POST['dp_type'] ?? '', ['percent', 'amount'], true) ? $_POST['dp_type'] : null;
            $dpValue = (float) ($_POST['dp_value'] ?? 0);
            if (!$quotationId) throw new RuntimeException('Pilih Penawaran yang mau di-invoice.');

            $qStmt = $pdo->prepare('SELECT * FROM quotations WHERE id=? AND organization_id=? AND status="approved"');
            $qStmt->execute([$quotationId, $org['organization_id']]);
            $quotation = $qStmt->fetch();
            if (!$quotation) throw new RuntimeException('Penawaran tidak ditemukan / belum approved.');

            $qLinesStmt = $pdo->prepare('SELECT * FROM quotation_lines WHERE quotation_id=?');
            $qLinesStmt->execute([$quotationId]);
            $qLines = $qLinesStmt->fetchAll();
            if (!$qLines) throw new RuntimeException('Penawaran ini tidak punya item.');

            $totalPenawaran = quotation_grand_total($pdo, $quotationId);
            $alreadyBilled = quotation_billed_so_far($pdo, $quotationId);
            $remaining = max(0, $totalPenawaran - $alreadyBilled);
            $invCountStmt = $pdo->prepare('SELECT COUNT(*) c FROM invoices WHERE quotation_id=?');
            $invCountStmt->execute([$quotationId]);
            $isFirstInvoice = (int) $invCountStmt->fetch()['c'] === 0;

            $billedAmount = $remaining;
            if ($scheme === 'dp') {
                $billedAmount = $dpType === 'percent' ? $totalPenawaran * ($dpValue / 100) : $dpValue;
            }
            if ($billedAmount <= 0) throw new RuntimeException('Nominal tagihan harus lebih dari 0.');
            if ($billedAmount > $remaining + 0.5) { // toleransi pembulatan kecil
                throw new RuntimeException(
                    'Nominal tagihan (Rp ' . number_format($billedAmount, 0, ',', '.') . ') melebihi sisa Penawaran ini (Rp ' . number_format($remaining, 0, ',', '.') . ' dari total Rp ' . number_format($totalPenawaran, 0, ',', '.') . ', sudah ditagih Rp ' . number_format($alreadyBilled, 0, ',', '.') . ').'
                );
            }

            $termsSnapshot = $quotation['terms_snapshot'];
            if ($termsId) {
                $tc = $pdo->prepare('SELECT content FROM terms_conditions WHERE id=? AND organization_id=?');
                $tc->execute([$termsId, $org['organization_id']]);
                $termsSnapshot = $tc->fetch()['content'] ?? $termsSnapshot;
            }

            $pdo->beginTransaction();
            try {
                $docNumber = next_doc_number($org['organization_id'], 'invoice');
                $pdo->prepare(
                    'INSERT INTO invoices (organization_id, doc_number, contact_id, quotation_id, sales_user_id, terms_snapshot, payment_scheme, dp_type, dp_value, billed_amount, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$org['organization_id'], $docNumber, $quotation['contact_id'], $quotationId, $salesUserId, $termsSnapshot, $scheme, $dpType, $dpValue, $billedAmount, $user['id']]);
                $invoiceId = (int) $pdo->lastInsertId();

                // Barang fisik (trigger Request Material + jatah Delivery Order) cuma
                // di-copy sekali, di invoice PERTAMA — invoice ke-2+ dari Penawaran
                // yang sama itu murni tagihan susulan (sisa DP/termin), bukan
                // pesanan barang baru, jadi jangan sampai ke-double-count.
                if ($isFirstInvoice) {
                    $insertLine = $pdo->prepare(
                        'INSERT INTO invoice_lines (invoice_id, quotation_line_id, product_id, product_name_snapshot, tier_level_snapshot, qty, unit_price)
                         VALUES (?,?,?,?,?,?,?)'
                    );
                    foreach ($qLines as $l) {
                        $insertLine->execute([$invoiceId, $l['id'], $l['product_id'], $l['product_name_snapshot'], $l['tier_level_snapshot'], $l['qty'], $l['unit_price']]);
                    }
                }
                $pdo->commit();

                if ($isFirstInvoice) {
                    generate_material_request($org['organization_id'], $invoiceId, $user['id']);
                }

                $flash = ['ok', "Invoice $docNumber dibuat."];
                header('Location: invoices.php?id=' . $invoiceId);
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } elseif ($action === 'update_status') {
            require_module_access('invoicing', 'can_edit');
            $id = (int) ($_POST['invoice_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (in_array($status, ['draft', 'issued', 'paid', 'void'], true)) {
                $pdo->prepare('UPDATE invoices SET status=? WHERE id=? AND organization_id=?')->execute([$status, $id, $org['organization_id']]);
                $flash = ['ok', 'Status invoice diperbarui.'];
            }
        }
    } catch (Throwable $e) {
        $flash = ['error', $e->getMessage()];
    }
}

$orgMembers = $pdo->prepare('SELECT u.id, u.name FROM user_organization_roles uor JOIN users u ON u.id=uor.user_id WHERE uor.organization_id=? AND uor.status="active" ORDER BY u.name');
$orgMembers->execute([$org['organization_id']]);
$orgMembers = $orgMembers->fetchAll();

$termsList = $pdo->prepare('SELECT id, title, content FROM terms_conditions WHERE organization_id=? ORDER BY title');
$termsList->execute([$org['organization_id']]);
$termsList = $termsList->fetchAll();

$isNewForm = isset($_GET['new']);

if ($isNewForm) {
    $projectsList = $pdo->prepare('SELECT id, name FROM projects WHERE organization_id=? ORDER BY name');
    $projectsList->execute([$org['organization_id']]);
    $projectsList = $projectsList->fetchAll();

    // Penawaran approved yang masih ada sisa belum ditagih (0 invoice = sisa penuh,
    // sudah sebagian di-DP/termin = sisa yang belum), dikelompokkan per project_id (0 = tanpa project).
    $availQ = $pdo->prepare(
        "SELECT q.id, q.doc_number, q.project_id, c.name AS contact_name, q.created_at,
           (SELECT COUNT(*) FROM invoices i WHERE i.quotation_id=q.id) AS invoice_count
         FROM quotations q JOIN contacts c ON c.id=q.contact_id
         WHERE q.organization_id=? AND q.status='approved'
         ORDER BY q.created_at DESC"
    );
    $availQ->execute([$org['organization_id']]);
    $quotationsByProject = [];
    foreach ($availQ->fetchAll() as $row) {
        $total = quotation_grand_total($pdo, (int) $row['id']);
        $billed = quotation_billed_so_far($pdo, (int) $row['id']);
        $remaining = $total - $billed;
        if ($remaining <= 0.5) continue; // udah lunas ditagih semua, gak usah muncul lagi
        $row['total'] = $total;
        $row['billed'] = $billed;
        $row['remaining'] = $remaining;
        $key = $row['project_id'] ?: '0';
        $quotationsByProject[$key][] = $row;
    }
} else {
    $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
    $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

    $railStmt = $pdo->prepare(
        "SELECT i.*, c.name AS contact_name FROM invoices i JOIN contacts c ON c.id=i.contact_id
         WHERE i.organization_id=? AND DATE_FORMAT(i.created_at,'%Y-%m')=? ORDER BY i.created_at DESC"
    );
    $railStmt->execute([$org['organization_id'], $month]);
    $railItems = $railStmt->fetchAll();

    $selectedId = (int) ($_GET['id'] ?? ($railItems[0]['id'] ?? 0));
    $selected = null;
    foreach ($railItems as $r) { if ((int) $r['id'] === $selectedId) { $selected = $r; break; } }
    $selectedLines = [];
    $quotationTotal = 0;
    $matStatus = 'belum_request';
    if ($selected) {
        $lStmt = $pdo->prepare('SELECT * FROM invoice_lines WHERE invoice_id=?');
        $lStmt->execute([$selected['id']]);
        $selectedLines = $lStmt->fetchAll();
        $siblingInvoices = [];
        $quotationBilled = 0;
        $quotationRemaining = 0;
        if ($selected['quotation_id']) {
            $quotationTotal = quotation_grand_total($pdo, (int) $selected['quotation_id']);
            $quotationBilled = quotation_billed_so_far($pdo, (int) $selected['quotation_id']);
            $quotationRemaining = max(0, $quotationTotal - $quotationBilled);

            $sibStmt = $pdo->prepare('SELECT id, doc_number, billed_amount, status FROM invoices WHERE quotation_id=? AND id != ? ORDER BY created_at');
            $sibStmt->execute([$selected['quotation_id'], $selected['id']]);
            $siblingInvoices = $sibStmt->fetchAll();
        }
        $salesName = null;
        if ($selected['sales_user_id']) {
            $suStmt = $pdo->prepare('SELECT name FROM users WHERE id=?');
            $suStmt->execute([$selected['sales_user_id']]);
            $salesName = $suStmt->fetch()['name'] ?? null;
        }
        $matStatus = invoice_material_status((int) $selected['id']);
    }
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if ($isNewForm): ?>
  <!-- ===================== FORM FULL PAGE: BUAT INVOICE ===================== -->
  <div class="card txn-form-page">
    <div class="txn-detail-header"><h2>Buat Invoice</h2><a class="btn btn-sm btn-ghost" href="invoices.php">Batal</a></div>
    <form method="post" id="invoice-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_invoice">
      <input type="hidden" name="quotation_id" id="picked-quotation-id" value="">

      <div class="field">
        <label>Project</label>
        <select id="project-select">
          <option value="">— Semua Project —</option>
          <?php foreach ($projectsList as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
          <option value="0">Tanpa Project</option>
        </select>
      </div>

      <label style="display:block; font-size:12px; font-weight:600; margin:14px 0 8px;">Pilih Penawaran (status Approved)</label>
      <div id="quotation-picker" style="display:flex; flex-direction:column; gap:8px;"></div>

      <div id="invoice-scheme-section" style="display:none; margin-top:16px;">
        <div id="terms-preview-box" class="txn-spec-box" style="display:none;"></div>

        <div class="field">
          <label>Sales</label>
          <select name="sales_user_id">
            <?php foreach ($orgMembers as $m): ?><option value="<?= $m['id'] ?>" <?= $m['id'] == $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Syarat &amp; Ketentuan (override, opsional — default ikut Penawaran)</label>
          <select name="terms_id">
            <option value="">— Ikut Penawaran —</option>
            <?php foreach ($termsList as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['title']) ?></option><?php endforeach; ?>
          </select>
        </div>

        <label style="display:block; font-size:12px; font-weight:600; margin:14px 0 8px;">Skema Pembayaran</label>
        <div style="display:flex; gap:16px; margin-bottom:10px;">
          <label style="font-size:13px;"><input type="radio" name="payment_scheme" value="full" checked> Lunas Sekaligus</label>
          <label style="font-size:13px;"><input type="radio" name="payment_scheme" value="dp"> DP / Termin</label>
        </div>
        <div id="dp-fields" style="display:none; gap:8px;" class="field-row">
          <div class="field">
            <label>Tipe</label>
            <select name="dp_type" id="dp-type">
              <option value="percent">%</option>
              <option value="amount">Rp</option>
            </select>
          </div>
          <div class="field"><label>Nilai</label><input type="text" inputmode="numeric" class="rupiah-input" name="dp_value" id="dp-value" value="0"></div>
        </div>

        <div class="txn-totals">
          <div class="row"><span>Sisa Belum Ditagih</span><span id="calc-total-penawaran">Rp 0</span></div>
          <div class="row" id="calc-dp-row" style="display:none;"><span>DP/Termin ini</span><span id="calc-dp">Rp 0</span></div>
          <div class="row grand"><span>Ditagihkan</span><span id="calc-billed">Rp 0</span></div>
        </div>

        <div style="margin-top:16px;"><button type="submit" class="btn">Simpan Invoice</button></div>
      </div>
    </form>
  </div>

  <script>
  var QUOTATIONS_BY_PROJECT = <?= json_encode($quotationsByProject) ?>;
  var picker = document.getElementById('quotation-picker');
  var schemeSection = document.getElementById('invoice-scheme-section');
  var pickedTotal = 0;

  function renderQuotationList(list) {
    picker.innerHTML = '';
    if (!list || !list.length) { picker.innerHTML = '<p style="color:var(--ink-muted); font-size:13px;">Gak ada Penawaran approved yang masih ada sisa tagihan.</p>'; return; }
    list.forEach(function (q) {
      var card = document.createElement('label');
      card.className = 'org-pick-item';
      card.style.cursor = 'pointer';
      var billedNote = q.invoice_count > 0
        ? '<br><span style="font-size:12px; color:var(--amber);">Sudah ditagih ' + q.invoice_count + 'x, Rp ' + Number(q.billed).toLocaleString('id-ID') + ' — sisa Rp ' + Number(q.remaining).toLocaleString('id-ID') + '</span>'
        : '';
      card.innerHTML =
        '<input type="radio" name="quotation_radio" value="' + q.id + '" style="margin-right:8px;">' +
        '<strong>' + q.doc_number + '</strong> — ' + q.contact_name + '<br>' +
        '<span style="font-size:12px; color:var(--ink-muted);">Total Rp ' + Number(q.total).toLocaleString('id-ID') + ' · ' + q.created_at + '</span>' + billedNote;
      card.querySelector('input').addEventListener('change', function () {
        document.getElementById('picked-quotation-id').value = q.id;
        pickedTotal = Number(q.remaining);
        schemeSection.style.display = 'block';
        recalcInvoice();
      });
      picker.appendChild(card);
    });
  }
  document.getElementById('project-select').addEventListener('change', function () {
    var key = this.value === '' ? null : (this.value || '0');
    var list = key === null
      ? Object.values(QUOTATIONS_BY_PROJECT).flat()
      : (QUOTATIONS_BY_PROJECT[key] || []);
    renderQuotationList(list);
  });
  renderQuotationList(Object.values(QUOTATIONS_BY_PROJECT).flat());

  document.querySelectorAll('input[name=payment_scheme]').forEach(function (r) {
    r.addEventListener('change', function () {
      document.getElementById('dp-fields').style.display = this.value === 'dp' ? 'flex' : 'none';
      recalcInvoice();
    });
  });
  document.getElementById('dp-type').addEventListener('change', recalcInvoice);
  document.getElementById('dp-value').addEventListener('input', recalcInvoice);

  function recalcInvoice() {
    document.getElementById('calc-total-penawaran').textContent = 'Rp ' + pickedTotal.toLocaleString('id-ID');
    var scheme = document.querySelector('input[name=payment_scheme]:checked').value;
    var billed = pickedTotal;
    var dpRow = document.getElementById('calc-dp-row');
    if (scheme === 'dp') {
      var type = document.getElementById('dp-type').value;
      var val = parseFloat(document.getElementById('dp-value').value.replace(/[^\d]/g, '')) || 0;
      billed = type === 'percent' ? pickedTotal * (val / 100) : val;
      billed = Math.min(billed, pickedTotal);
      document.getElementById('calc-dp').textContent = 'Rp ' + billed.toLocaleString('id-ID');
      dpRow.style.display = 'flex';
    } else {
      dpRow.style.display = 'none';
    }
    document.getElementById('calc-billed').textContent = 'Rp ' + billed.toLocaleString('id-ID');
  }
  </script>

<?php else: ?>
  <!-- ===================== LIST: RAIL + DETAIL ===================== -->
  <div class="txn-shell">
    <div class="txn-rail">
      <div class="txn-rail-month">
        <a href="invoices.php?month=<?= $prevMonth ?>">‹</a>
        <span><?= htmlspecialchars(date('F Y', strtotime($month . '-01'))) ?></span>
        <a href="invoices.php?month=<?= $nextMonth ?>">›</a>
        <a class="today-btn" href="invoices.php">Bulan Ini</a>
      </div>
      <div class="txn-rail-list">
        <?php foreach ($railItems as $r): ?>
          <a class="txn-rail-item <?= (int) $r['id'] === $selectedId ? 'active' : '' ?>" href="invoices.php?month=<?= $month ?>&id=<?= $r['id'] ?>">
            <div class="doc"><?= htmlspecialchars($r['doc_number']) ?></div>
            <div class="sub"><?= htmlspecialchars($r['contact_name']) ?> · <span class="pill pill-<?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span></div>
          </a>
        <?php endforeach; ?>
        <?php if (!$railItems): ?><div style="padding:14px; font-size:12px; color:var(--ink-muted);">Gak ada Invoice bulan ini.</div><?php endif; ?>
      </div>
      <div style="padding:10px;"><a class="btn btn-sm" style="width:100%;" href="invoices.php?new=1">+ Buat Invoice</a></div>
    </div>

    <div class="txn-detail">
      <?php if (!$selected): ?>
        <div class="card txn-empty">Pilih Invoice di kiri, atau buat yang baru.</div>
      <?php else: ?>
        <div class="card">
          <div class="txn-detail-header">
            <div><h2><?= htmlspecialchars($selected['doc_number']) ?> <span class="pill pill-<?= $selected['status'] ?>"><?= strtoupper($selected['status']) ?></span></h2></div>
            <div class="txn-detail-actions">
              <a class="btn btn-sm btn-ghost" href="material-requests.php?invoice_id=<?= $selected['id'] ?>">Material: <?= htmlspecialchars(MATERIAL_STATUS_LABELS[$matStatus]) ?></a>
              <a class="btn btn-sm btn-ghost" href="invoice-print.php?id=<?= $selected['id'] ?>" target="_blank">Print</a>
              <?php if (has_access('invoicing', 'can_edit')): ?>
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="invoice_id" value="<?= $selected['id'] ?>">
                  <select name="status" onchange="this.form.submit();" style="padding:6px 10px; border:1px solid var(--border); border-radius:4px; font-size:12px;">
                    <?php foreach (['draft', 'issued', 'paid', 'void'] as $s): ?>
                      <option value="<?= $s ?>" <?= $selected['status'] === $s ? 'selected' : '' ?>><?= strtoupper($s) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="txn-info-strip">
            <div><span class="lbl">Customer</span><?= htmlspecialchars($selected['contact_name']) ?></div>
            <div><span class="lbl">Sales</span><?= $salesName ? htmlspecialchars($salesName) : '—' ?></div>
            <div><span class="lbl">Tanggal</span><?= htmlspecialchars(date('d M Y', strtotime($selected['created_at']))) ?></div>
          </div>

          <?php if ($siblingInvoices): ?>
            <div class="alert" style="background: var(--accent-bg); color: var(--accent-text); margin-bottom:16px;">
              Penawaran ini punya <strong><?= count($siblingInvoices) + 1 ?> invoice</strong> (DP/termin bertahap) —
              <?php foreach ($siblingInvoices as $sib): ?>
                <a href="invoices.php?id=<?= $sib['id'] ?>" style="text-decoration:underline;"><?= htmlspecialchars($sib['doc_number']) ?></a> (Rp <?= number_format((float) $sib['billed_amount'], 0, ',', '.') ?>)<?= $sib !== end($siblingInvoices) ? ', ' : '' ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if (!$selectedLines): ?>
            <div class="alert" style="background: oklch(0.97 0.005 260); color: var(--ink-soft);">
              Invoice tagihan susulan (DP/termin) — item barangnya sama seperti invoice pertama dari Penawaran ini, gak diulang di sini biar gak dobel proses produksi/pengiriman.
            </div>
          <?php else: ?>
          <table class="data-table">
            <thead><tr><th>Produk</th><th>Tier</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Subtotal</th></tr></thead>
            <tbody>
              <?php foreach ($selectedLines as $l): ?>
                <tr>
                  <td><?= htmlspecialchars($l['product_name_snapshot']) ?></td>
                  <td><?= htmlspecialchars(ucfirst($l['tier_level_snapshot'])) ?></td>
                  <td class="num"><?= rtrim(rtrim(number_format((float) $l['qty'], 2, ',', '.'), '0'), ',') ?></td>
                  <td class="num">Rp <?= number_format((float) $l['unit_price'], 0, ',', '.') ?></td>
                  <td class="num">Rp <?= number_format((float) $l['qty'] * (float) $l['unit_price'], 0, ',', '.') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>

          <div class="txn-totals">
            <div class="row"><span>Total Penawaran</span><span>Rp <?= number_format($quotationTotal, 0, ',', '.') ?></span></div>
            <?php if ($siblingInvoices): ?>
              <div class="row"><span>Total Sudah Ditagih (semua invoice)</span><span>Rp <?= number_format($quotationBilled, 0, ',', '.') ?></span></div>
              <div class="row"><span>Sisa Belum Ditagih</span><span>Rp <?= number_format($quotationRemaining, 0, ',', '.') ?></span></div>
            <?php endif; ?>
            <?php if ($selected['payment_scheme'] === 'dp'): ?>
              <div class="row"><span>Skema</span><span>DP <?= $selected['dp_type'] === 'percent' ? $selected['dp_value'] . '%' : 'Rp ' . number_format((float) $selected['dp_value'], 0, ',', '.') ?></span></div>
            <?php endif; ?>
            <div class="row grand"><span>Ditagihkan</span><span>Rp <?= number_format((float) $selected['billed_amount'], 0, ',', '.') ?></span></div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
