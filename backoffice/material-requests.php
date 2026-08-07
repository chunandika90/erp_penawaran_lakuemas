<?php
$pageTitle = 'Request Material';
$activeMenu = 'material_requests';
require __DIR__ . '/includes/header.php';
require_module_access('po');
require_once __DIR__ . '/../backoffice-shared/doc_number.php';
require_once __DIR__ . '/../backoffice-shared/material_request.php';

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_po_from_shortage') {
            require_module_access('po', 'can_create');
            $lineId = (int) ($_POST['request_line_id'] ?? 0);

            $lineStmt = $pdo->prepare(
                'SELECT mrl.*, mr.organization_id, mr.invoice_id
                 FROM material_request_lines mrl JOIN material_requests mr ON mr.id = mrl.request_id
                 WHERE mrl.id = ? AND mr.organization_id = ?'
            );
            $lineStmt->execute([$lineId, $org['organization_id']]);
            $line = $lineStmt->fetch();
            if (!$line) throw new RuntimeException('Baris material tidak ditemukan.');
            if ($line['po_line_id']) throw new RuntimeException('Baris ini sudah punya PO.');
            if ((float) $line['need_po_qty'] <= 0) throw new RuntimeException('Baris ini gak kekurangan stok.');

            // Project ditarik dari Invoice -> Penawaran (buat traceability di PO).
            $projStmt = $pdo->prepare('SELECT q.project_id FROM invoices i JOIN quotations q ON q.id=i.quotation_id WHERE i.id=?');
            $projStmt->execute([$line['invoice_id']]);
            $projectId = $projStmt->fetch()['project_id'] ?? null;

            $matStmt = $pdo->prepare('SELECT default_cost FROM materials WHERE id=?');
            $matStmt->execute([$line['material_id']]);
            $defaultCost = (float) ($matStmt->fetch()['default_cost'] ?? 0);

            $pdo->beginTransaction();
            try {
                $docNumber = next_doc_number($org['organization_id'], 'po');
                $pdo->prepare('INSERT INTO purchase_orders (organization_id, project_id, material_request_id, doc_number, po_type, created_by) VALUES (?,?,?,?,"bahan_baku",?)')
                    ->execute([$org['organization_id'], $projectId, $line['request_id'], $docNumber, $user['id']]);
                $poId = (int) $pdo->lastInsertId();

                $pdo->prepare('INSERT INTO po_lines (po_id, material_id, item_name, qty, unit_cost) VALUES (?,?,?,?,?)')
                    ->execute([$poId, $line['material_id'], $line['material_name_snapshot'], $line['need_po_qty'], $defaultCost]);
                $poLineId = (int) $pdo->lastInsertId();

                $pdo->prepare('UPDATE material_request_lines SET po_line_id=? WHERE id=?')->execute([$poLineId, $lineId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            header('Location: purchase-orders.php?id=' . $poId);
            exit;
        }
    } catch (Throwable $e) {
        $flash = ['error', $e->getMessage()];
    }
}

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

$railStmt = $pdo->prepare(
    "SELECT mr.*, c.name AS contact_name FROM material_requests mr
     JOIN invoices i ON i.id = mr.invoice_id JOIN contacts c ON c.id = i.contact_id
     WHERE mr.organization_id=? AND DATE_FORMAT(mr.created_at,'%Y-%m')=? ORDER BY mr.created_at DESC"
);
$railStmt->execute([$org['organization_id'], $month]);
$railItems = $railStmt->fetchAll();
foreach ($railItems as &$ri) { $ri['agg_status'] = invoice_material_status((int) $ri['invoice_id']); }
unset($ri);

$selectedInvoiceId = (int) ($_GET['invoice_id'] ?? 0);
$selectedId = (int) ($_GET['id'] ?? 0);
if ($selectedInvoiceId && !$selectedId) {
    $find = $pdo->prepare('SELECT id FROM material_requests WHERE invoice_id=? AND organization_id=?');
    $find->execute([$selectedInvoiceId, $org['organization_id']]);
    $selectedId = (int) ($find->fetch()['id'] ?? 0);
}
if (!$selectedId && $railItems) $selectedId = (int) $railItems[0]['id'];

$selected = null;
$productGroups = [];
$allResolved = true;
if ($selectedId) {
    $sStmt = $pdo->prepare(
        'SELECT mr.*, i.doc_number AS invoice_doc_number, i.created_at AS invoice_date, i.quotation_id, c.name AS contact_name
         FROM material_requests mr JOIN invoices i ON i.id=mr.invoice_id JOIN contacts c ON c.id=i.contact_id
         WHERE mr.id=? AND mr.organization_id=?'
    );
    $sStmt->execute([$selectedId, $org['organization_id']]);
    $selected = $sStmt->fetch() ?: null;

    if ($selected) {
        $quotationDoc = null;
        $quotationDate = null;
        $projectName = null;
        if ($selected['quotation_id']) {
            $qStmt = $pdo->prepare('SELECT doc_number, created_at, project_id FROM quotations WHERE id=?');
            $qStmt->execute([$selected['quotation_id']]);
            $qRow = $qStmt->fetch();
            if ($qRow) {
                $quotationDoc = $qRow['doc_number'];
                $quotationDate = $qRow['created_at'];
                if ($qRow['project_id']) {
                    $pjStmt = $pdo->prepare('SELECT name FROM projects WHERE id=?');
                    $pjStmt->execute([$qRow['project_id']]);
                    $projectName = $pjStmt->fetch()['name'] ?? null;
                }
            }
        }

        $linesStmt = $pdo->prepare(
            'SELECT mrl.*, pl.qty AS po_qty, pl.received_qty AS po_received_qty, po.id AS po_id, po.doc_number AS po_doc_number, po.status AS po_status
             FROM material_request_lines mrl
             LEFT JOIN po_lines pl ON pl.id = mrl.po_line_id
             LEFT JOIN purchase_orders po ON po.id = pl.po_id
             WHERE mrl.request_id = ? ORDER BY mrl.product_name_snapshot, mrl.material_name_snapshot'
        );
        $linesStmt->execute([$selectedId]);
        foreach ($linesStmt->fetchAll() as $row) {
            $needPo = (float) $row['need_po_qty'];
            if ($needPo <= 0) {
                $st = 'terpenuhi';
            } elseif (!$row['po_line_id']) {
                $st = 'perlu_po';
                $allResolved = false;
            } elseif ((float) $row['po_received_qty'] < (float) $row['po_qty']) {
                $st = 'menunggu_po';
                $allResolved = false;
            } else {
                $st = 'siap_produksi';
            }
            $row['status_key'] = $st;
            $productGroups[$row['product_name_snapshot']][] = $row;
        }
        if (!$productGroups) $allResolved = true;
    }
}
?>

<?php if ($flash): ?><div class="alert alert-error"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<div class="txn-shell">
  <div class="txn-rail">
    <div class="txn-rail-month">
      <a href="material-requests.php?month=<?= $prevMonth ?>">‹</a>
      <span><?= htmlspecialchars(date('F Y', strtotime($month . '-01'))) ?></span>
      <a href="material-requests.php?month=<?= $nextMonth ?>">›</a>
      <a class="today-btn" href="material-requests.php">Bulan Ini</a>
    </div>
    <div class="txn-rail-list">
      <?php foreach ($railItems as $r): ?>
        <a class="txn-rail-item <?= (int) $r['id'] === $selectedId ? 'active' : '' ?>" href="material-requests.php?month=<?= $month ?>&id=<?= $r['id'] ?>">
          <div class="doc"><?= htmlspecialchars($r['doc_number']) ?></div>
          <div class="sub"><?= htmlspecialchars($r['contact_name']) ?> · <span class="pill pill-<?= $r['agg_status'] === 'perlu_po' ? 'perlu' : ($r['agg_status'] === 'menunggu_po' ? 'menunggu' : ($r['agg_status'] === 'siap_produksi' ? 'siap' : 'terpenuhi')) ?>"><?= htmlspecialchars(MATERIAL_STATUS_LABELS[$r['agg_status']]) ?></span></div>
        </a>
      <?php endforeach; ?>
      <?php if (!$railItems): ?><div style="padding:14px; font-size:12px; color:var(--ink-muted);">Gak ada Request Material bulan ini.</div><?php endif; ?>
    </div>
  </div>

  <div class="txn-detail">
    <?php if (!$selected): ?>
      <div class="card txn-empty">Pilih Request Material di kiri. (Dibuat otomatis tiap kali Invoice baru dibikin.)</div>
    <?php else: ?>
      <div class="card">
        <div class="txn-detail-header"><h2><?= htmlspecialchars($selected['doc_number']) ?></h2></div>
        <div class="txn-info-strip">
          <div><span class="lbl">Customer</span><?= htmlspecialchars($selected['contact_name']) ?></div>
          <div><span class="lbl">Project</span><?= $projectName ? htmlspecialchars($projectName) : '—' ?></div>
          <div><span class="lbl">No. Penawaran</span><?= $quotationDoc ? htmlspecialchars($quotationDoc) . ' · ' . htmlspecialchars(date('d M Y', strtotime($quotationDate))) : '—' ?></div>
          <div><span class="lbl">No. Invoice</span><?= htmlspecialchars($selected['invoice_doc_number']) ?> · <?= htmlspecialchars(date('d M Y', strtotime($selected['invoice_date']))) ?></div>
          <div><span class="lbl">Jumlah Produk</span><?= count($productGroups) ?></div>
        </div>
      </div>

      <?php foreach ($productGroups as $productName => $lines): ?>
        <div class="card" style="margin-top:14px;">
          <h3 style="margin-top:0;"><?= htmlspecialchars($productName) ?></h3>
          <table class="data-table">
            <thead><tr><th>Material</th><th class="num">Butuh</th><th class="num">Stok</th><th class="num">Ambil dari Stok</th><th class="num">Perlu PO</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($lines as $l): ?>
                <tr>
                  <td><?= htmlspecialchars($l['material_name_snapshot']) ?> <small style="color:var(--ink-muted);">(<?= htmlspecialchars($l['unit']) ?>)</small></td>
                  <td class="num"><?= rtrim(rtrim(number_format((float) $l['need_qty'], 2, ',', '.'), '0'), ',') ?></td>
                  <td class="num"><?= rtrim(rtrim(number_format((float) $l['stock_qty_snapshot'], 2, ',', '.'), '0'), ',') ?></td>
                  <td class="num" style="color:#256029;"><?= rtrim(rtrim(number_format((float) $l['take_from_stock_qty'], 2, ',', '.'), '0'), ',') ?></td>
                  <td class="num" style="color:#8a2c2c;"><?= rtrim(rtrim(number_format((float) $l['need_po_qty'], 2, ',', '.'), '0'), ',') ?></td>
                  <td>
                    <span class="pill pill-<?= $l['status_key'] === 'perlu_po' ? 'perlu' : ($l['status_key'] === 'menunggu_po' ? 'menunggu' : ($l['status_key'] === 'siap_produksi' ? 'siap' : 'terpenuhi')) ?>">
                      <?= htmlspecialchars(MATERIAL_STATUS_LABELS[$l['status_key']]) ?>
                    </span>
                    <?php if ($l['po_doc_number']): ?><br><small style="color:var(--ink-muted);"><a href="purchase-orders.php?id=<?= $l['po_id'] ?? '' ?>"><?= htmlspecialchars($l['po_doc_number']) ?></a></small><?php endif; ?>
                  </td>
                  <td>
                    <?php if ($l['status_key'] === 'perlu_po' && has_access('po', 'can_create')): ?>
                      <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_po_from_shortage">
                        <input type="hidden" name="request_line_id" value="<?= $l['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-ghost">+ Buat PO</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>

      <?php if (!$productGroups): ?>
        <div class="card txn-empty" style="margin-top:14px;">
          Gak ada kebutuhan material buat invoice ini (produk belum punya BOM ter-link Material, atau tiap tier bom-nya kosong).
        </div>
      <?php endif; ?>

      <div style="margin-top:16px; display:flex; justify-content:flex-end;">
        <a class="btn <?= $allResolved ? '' : 'btn-ghost' ?>" href="<?= $allResolved ? 'spk.php' : '#' ?>" <?= $allResolved ? '' : 'onclick="return false;" style="opacity:.5; cursor:not-allowed;"' ?>>Lanjut ke Produksi (Buat SPK)</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
