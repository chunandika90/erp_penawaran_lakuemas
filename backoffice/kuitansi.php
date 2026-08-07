<?php
$pageTitle = 'Kuitansi';
$activeMenu = 'kuitansi';
require __DIR__ . '/includes/header.php';
require_module_access('kuitansi');
require_once __DIR__ . '/../backoffice-shared/doc_number.php';

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_kuitansi') {
            require_module_access('kuitansi', 'can_create');
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            $doId = (int) ($_POST['delivery_order_id'] ?? 0) ?: null;
            $amount = (float) ($_POST['amount'] ?? 0);
            $paymentType = $_POST['payment_type'] ?? 'pelunasan';
            $paidAt = trim($_POST['paid_at'] ?? '') ?: date('Y-m-d');
            if (!$invoiceId || $amount <= 0) throw new RuntimeException('Invoice dan nominal wajib diisi.');
            if (!in_array($paymentType, ['dp', 'termin', 'pelunasan'], true)) $paymentType = 'pelunasan';

            $invCheck = $pdo->prepare('SELECT id FROM invoices WHERE id=? AND organization_id=?');
            $invCheck->execute([$invoiceId, $org['organization_id']]);
            if (!$invCheck->fetch()) throw new RuntimeException('Invoice tidak ditemukan.');

            $docNumber = next_doc_number($org['organization_id'], 'kuitansi');
            $pdo->prepare('INSERT INTO kuitansi (organization_id, doc_number, invoice_id, delivery_order_id, amount, payment_type, paid_at, created_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$org['organization_id'], $docNumber, $invoiceId, $doId, $amount, $paymentType, $paidAt, $user['id']]);
            $newId = (int) $pdo->lastInsertId();
            header('Location: kuitansi.php?id=' . $newId);
            exit;
        }
    } catch (Throwable $e) {
        $flash = ['error', $e->getMessage()];
    }
}

$dosByInvoice = $pdo->prepare('SELECT id, doc_number, invoice_id, status FROM delivery_orders WHERE organization_id=?');
$dosByInvoice->execute([$org['organization_id']]);
$dosGrouped = [];
foreach ($dosByInvoice->fetchAll() as $row) {
    $dosGrouped[$row['invoice_id']][] = $row;
}

$isNewForm = isset($_GET['new']);

if ($isNewForm) {
    $invoices = $pdo->prepare(
        "SELECT i.id, i.doc_number, c.name AS contact_name, i.billed_amount AS total,
           (SELECT COALESCE(SUM(amount),0) FROM kuitansi WHERE invoice_id=i.id) AS paid
         FROM invoices i JOIN contacts c ON c.id=i.contact_id
         WHERE i.organization_id=? AND i.status IN ('issued','paid') ORDER BY i.created_at DESC"
    );
    $invoices->execute([$org['organization_id']]);
    $invoices = $invoices->fetchAll();
} else {
    $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
    $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

    $railStmt = $pdo->prepare(
        "SELECT k.*, i.doc_number AS invoice_doc_number, c.name AS contact_name
         FROM kuitansi k JOIN invoices i ON i.id=k.invoice_id JOIN contacts c ON c.id=i.contact_id
         WHERE k.organization_id=? AND DATE_FORMAT(k.created_at,'%Y-%m')=? ORDER BY k.created_at DESC"
    );
    $railStmt->execute([$org['organization_id'], $month]);
    $railItems = $railStmt->fetchAll();

    $selectedId = (int) ($_GET['id'] ?? ($railItems[0]['id'] ?? 0));
    $selected = null;
    foreach ($railItems as $r) { if ((int) $r['id'] === $selectedId) { $selected = $r; break; } }
    if (!$selected && $selectedId) {
        $sStmt = $pdo->prepare(
            "SELECT k.*, i.doc_number AS invoice_doc_number, c.name AS contact_name
             FROM kuitansi k JOIN invoices i ON i.id=k.invoice_id JOIN contacts c ON c.id=i.contact_id
             WHERE k.id=? AND k.organization_id=?"
        );
        $sStmt->execute([$selectedId, $org['organization_id']]);
        $selected = $sStmt->fetch() ?: null;
    }

    $doDoc = null;
    if ($selected && $selected['delivery_order_id']) {
        $dStmt = $pdo->prepare('SELECT doc_number FROM delivery_orders WHERE id=?');
        $dStmt->execute([$selected['delivery_order_id']]);
        $doDoc = $dStmt->fetch()['doc_number'] ?? null;
    }

    $invoiceTotalPaid = null;
    if ($selected) {
        $tStmt = $pdo->prepare(
            "SELECT i.billed_amount AS total,
                    (SELECT COALESCE(SUM(amount),0) FROM kuitansi WHERE invoice_id=i.id) AS paid
             FROM invoices i WHERE i.id=?"
        );
        $tStmt->execute([$selected['invoice_id']]);
        $invoiceTotalPaid = $tStmt->fetch();
    }
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if ($isNewForm): ?>
  <!-- ===================== FORM FULL PAGE: BUAT KUITANSI ===================== -->
  <div class="card txn-form-page">
    <div class="txn-detail-header"><h2>Buat Kuitansi</h2><a class="btn btn-sm btn-ghost" href="kuitansi.php">Batal</a></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_kuitansi">
      <div class="field">
        <label>Invoice</label>
        <select name="invoice_id" id="kuitansi-invoice" required>
          <option value="">— Pilih Invoice —</option>
          <?php foreach ($invoices as $i): ?>
            <option value="<?= $i['id'] ?>">
              <?= htmlspecialchars($i['doc_number']) ?> — <?= htmlspecialchars($i['contact_name']) ?>
              (Total Rp <?= number_format((float) $i['total'], 0, ',', '.') ?>, sudah dibayar Rp <?= number_format((float) $i['paid'], 0, ',', '.') ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Berdasarkan Status Delivery Order (opsional)</label>
        <select name="delivery_order_id" id="kuitansi-do">
          <option value="">— Tidak terkait DO tertentu —</option>
        </select>
      </div>
      <div class="field-row">
        <div class="field"><label>Nominal</label><input type="text" inputmode="numeric" class="rupiah-input" name="amount" required></div>
        <div class="field">
          <label>Tipe Pembayaran</label>
          <select name="payment_type">
            <option value="dp">DP</option>
            <option value="termin">Termin</option>
            <option value="pelunasan" selected>Pelunasan</option>
          </select>
        </div>
      </div>
      <div class="field"><label>Tanggal Bayar</label><input type="date" name="paid_at" value="<?= date('Y-m-d') ?>"></div>

      <div style="margin-top:16px;"><button type="submit" class="btn">Simpan Kuitansi</button></div>
    </form>
  </div>

  <script>
  var DOS_BY_INVOICE = <?= json_encode($dosGrouped) ?>;
  document.getElementById('kuitansi-invoice').addEventListener('change', function () {
    var sel = document.getElementById('kuitansi-do');
    sel.innerHTML = '<option value="">— Tidak terkait DO tertentu —</option>';
    var dos = DOS_BY_INVOICE[this.value];
    if (!dos) return;
    dos.forEach(function (d) {
      var opt = document.createElement('option');
      opt.value = d.id;
      opt.textContent = d.doc_number + ' — ' + d.status.toUpperCase();
      sel.appendChild(opt);
    });
  });
  </script>

<?php else: ?>
  <!-- ===================== LIST: RAIL + DETAIL ===================== -->
  <div class="txn-shell">
    <div class="txn-rail">
      <div class="txn-rail-month">
        <a href="kuitansi.php?month=<?= $prevMonth ?>">‹</a>
        <span><?= htmlspecialchars(date('F Y', strtotime($month . '-01'))) ?></span>
        <a href="kuitansi.php?month=<?= $nextMonth ?>">›</a>
        <a class="today-btn" href="kuitansi.php">Bulan Ini</a>
      </div>
      <div class="txn-rail-list">
        <?php foreach ($railItems as $r): ?>
          <a class="txn-rail-item <?= (int) $r['id'] === $selectedId ? 'active' : '' ?>" href="kuitansi.php?month=<?= $month ?>&id=<?= $r['id'] ?>">
            <div class="doc"><?= htmlspecialchars($r['doc_number']) ?></div>
            <div class="sub"><?= htmlspecialchars($r['contact_name']) ?> · <span class="pill"><?= strtoupper($r['payment_type']) ?></span></div>
          </a>
        <?php endforeach; ?>
        <?php if (!$railItems): ?><div style="padding:14px; font-size:12px; color:var(--ink-muted);">Gak ada kuitansi bulan ini.</div><?php endif; ?>
      </div>
      <div style="padding:10px;"><a class="btn btn-sm" style="width:100%;" href="kuitansi.php?new=1">+ Buat Kuitansi</a></div>
    </div>

    <div class="txn-detail">
      <?php if (!$selected): ?>
        <div class="card txn-empty">Pilih kuitansi di kiri, atau buat yang baru.</div>
      <?php else: ?>
        <div class="card">
          <div class="txn-detail-header">
            <div><h2><?= htmlspecialchars($selected['doc_number']) ?> <span class="pill"><?= strtoupper($selected['payment_type']) ?></span></h2></div>
            <div class="txn-detail-actions">
              <a class="btn btn-sm btn-ghost" href="kuitansi-print.php?id=<?= $selected['id'] ?>" target="_blank">Print</a>
            </div>
          </div>

          <div class="txn-info-strip">
            <div><span class="lbl">Customer</span><?= htmlspecialchars($selected['contact_name']) ?></div>
            <div><span class="lbl">Invoice</span><?= htmlspecialchars($selected['invoice_doc_number']) ?></div>
            <div><span class="lbl">Delivery Order</span><?= $doDoc ? htmlspecialchars($doDoc) : '—' ?></div>
            <div><span class="lbl">Tanggal Bayar</span><?= htmlspecialchars(date('d M Y', strtotime($selected['paid_at']))) ?></div>
          </div>

          <div class="txn-totals">
            <div class="row"><span>Total Invoice</span><span>Rp <?= number_format((float) $invoiceTotalPaid['total'], 0, ',', '.') ?></span></div>
            <div class="row"><span>Sudah Dibayar (semua kuitansi)</span><span>Rp <?= number_format((float) $invoiceTotalPaid['paid'], 0, ',', '.') ?></span></div>
            <div class="row grand"><span>Nominal Kuitansi Ini</span><span>Rp <?= number_format((float) $selected['amount'], 0, ',', '.') ?></span></div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
