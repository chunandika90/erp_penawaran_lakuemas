<?php
/**
 * Flow board per project — read-only, model Trello: kolom per tahap dokumen
 * (Penawaran -> Invoice -> PO -> SPK -> Penerimaan -> DO -> Kuitansi), kartu
 * per transaksi. Klik kartu = lompat ke halaman aslinya (bukan UI baru).
 */
$pageTitle = 'Project Flow';
$activeMenu = 'project_flow';
require __DIR__ . '/includes/header.php';
require_module_access('penawaran');

$pdo = db();
$orgId = $org['organization_id'];

function flow_in_clause(array $ids): array
{
    if (!$ids) return ['1=0', []];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return ["IN ($placeholders)", $ids];
}

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

$projects = $pdo->prepare("SELECT id, name FROM projects WHERE organization_id=? AND DATE_FORMAT(created_at,'%Y-%m')=? ORDER BY name");
$projects->execute([$orgId, $month]);
$projects = $projects->fetchAll();

$selectedId = (int) ($_GET['id'] ?? ($projects[0]['id'] ?? 0));
$selected = null;
foreach ($projects as $p) { if ((int) $p['id'] === $selectedId) { $selected = $p; break; } }
if (!$selected && $selectedId) {
    // Klik project dari luar (misal dari halaman detail Project) bisa aja beda bulan dari filter default.
    $sStmt = $pdo->prepare('SELECT id, name FROM projects WHERE id=? AND organization_id=?');
    $sStmt->execute([$selectedId, $orgId]);
    $selected = $sStmt->fetch() ?: null;
}

$quotations = $invoices = $purchaseOrders = $spks = $receipts = $deliveryOrders = $kuitansiRows = [];

if ($selected) {
    $qStmt = $pdo->prepare(
        "SELECT q.*, (SELECT COALESCE(SUM(qty*unit_price),0) FROM quotation_lines WHERE quotation_id=q.id) AS subtotal
         FROM quotations q WHERE q.project_id=? AND q.organization_id=? ORDER BY q.created_at"
    );
    $qStmt->execute([$selected['id'], $orgId]);
    $quotations = $qStmt->fetchAll();
    foreach ($quotations as &$q) {
        $disc = $q['discount_type'] === 'percent' ? $q['subtotal'] * ((float) $q['discount_value'] / 100) : (float) $q['discount_value'];
        $afterDisc = max(0, (float) $q['subtotal'] - $disc);
        $q['total'] = $afterDisc + ($afterDisc * 0.11);
    }
    unset($q);
    $quotationIds = array_column($quotations, 'id');

    if ($quotationIds) {
        [$in, $params] = flow_in_clause($quotationIds);
        $iStmt = $pdo->prepare("SELECT * FROM invoices WHERE quotation_id $in ORDER BY created_at");
        $iStmt->execute($params);
        $invoices = $iStmt->fetchAll();
    }
    $invoiceIds = array_column($invoices, 'id');

    // PO: langsung punya project_id (dari alur Request Material) ATAU nyambung
    // lewat po_lines->invoice_lines (alur lama jasa_produksi/barang_jadi manual).
    $poTotalSub = "(SELECT COALESCE(SUM(qty*unit_cost),0) FROM po_lines WHERE po_id=po.id) AS total";
    $poById = [];
    $poDirect = $pdo->prepare("SELECT po.*, $poTotalSub FROM purchase_orders po WHERE po.project_id=? AND po.organization_id=?");
    $poDirect->execute([$selected['id'], $orgId]);
    foreach ($poDirect->fetchAll() as $po) $poById[$po['id']] = $po;

    if ($invoiceIds) {
        [$in, $params] = flow_in_clause($invoiceIds);
        $poViaInv = $pdo->prepare(
            "SELECT DISTINCT po.*, $poTotalSub FROM purchase_orders po
             JOIN po_lines pl ON pl.po_id = po.id
             JOIN invoice_lines il ON il.id = pl.invoice_line_id
             WHERE il.invoice_id $in"
        );
        $poViaInv->execute($params);
        foreach ($poViaInv->fetchAll() as $po) $poById[$po['id']] = $po;

        // PO auto-generate dari Request Material — nyambungnya lewat
        // material_request_id (bukan project_id-nya sendiri, yang kadang
        // masih NULL kalau project baru di-assign ke quotation belakangan).
        [$in, $params] = flow_in_clause($invoiceIds);
        $poViaMr = $pdo->prepare(
            "SELECT DISTINCT po.*, $poTotalSub FROM purchase_orders po
             JOIN material_requests mr ON mr.id = po.material_request_id
             WHERE mr.invoice_id $in"
        );
        $poViaMr->execute($params);
        foreach ($poViaMr->fetchAll() as $po) $poById[$po['id']] = $po;
    }
    $purchaseOrders = array_values($poById);
    $poIds = array_column($purchaseOrders, 'id');

    if ($poIds) {
        [$in, $params] = flow_in_clause($poIds);
        $sStmt = $pdo->prepare(
            "SELECT s.*, (SELECT COALESCE(SUM(qty*unit_cost),0) FROM spk_materials WHERE spk_id=s.id) + s.assembly_fee AS total
             FROM spk s WHERE s.po_id $in ORDER BY s.created_at"
        );
        $sStmt->execute($params);
        $spks = $sStmt->fetchAll();
    }
    $spkIds = array_column($spks, 'id');

    if ($poIds || $spkIds) {
        $conds = [];
        $params = [];
        if ($poIds) { [$in, $p] = flow_in_clause($poIds); $conds[] = "po_id $in"; $params = array_merge($params, $p); }
        if ($spkIds) { [$in, $p] = flow_in_clause($spkIds); $conds[] = "spk_id $in"; $params = array_merge($params, $p); }
        $rStmt = $pdo->prepare(
            'SELECT gr.*, (SELECT COALESCE(SUM(qty*unit_cost),0) FROM goods_receipt_lines WHERE goods_receipt_id=gr.id) AS total
             FROM goods_receipts gr WHERE (' . implode(' OR ', $conds) . ') ORDER BY gr.received_at'
        );
        $rStmt->execute($params);
        $receipts = $rStmt->fetchAll();
    }

    if ($invoiceIds) {
        [$in, $params] = flow_in_clause($invoiceIds);
        $dStmt = $pdo->prepare(
            "SELECT do_.*, (SELECT COALESCE(SUM(qty*unit_cost_snapshot),0) FROM delivery_order_lines WHERE delivery_order_id=do_.id) AS total
             FROM delivery_orders do_ WHERE do_.invoice_id $in ORDER BY do_.created_at"
        );
        $dStmt->execute($params);
        $deliveryOrders = $dStmt->fetchAll();

        [$in, $params] = flow_in_clause($invoiceIds);
        $kStmt = $pdo->prepare("SELECT * FROM kuitansi WHERE invoice_id $in ORDER BY paid_at");
        $kStmt->execute($params);
        $kuitansiRows = $kStmt->fetchAll();
    }

    // PO -> Invoice, buat garis penghubung (kalau nyambung lewat Request Material
    // atau lewat po_lines.invoice_line_id — PO yang cuma nyambung lewat project_id
    // langsung, tanpa salah satu dari itu, gak ke-gambar garisnya).
    $poInvoiceMap = [];
    if ($poIds) {
        [$in, $params] = flow_in_clause($poIds);
        $viaMr = $pdo->prepare("SELECT po.id po_id, mr.invoice_id FROM purchase_orders po JOIN material_requests mr ON mr.id=po.material_request_id WHERE po.id $in");
        $viaMr->execute($params);
        foreach ($viaMr->fetchAll() as $r) $poInvoiceMap[$r['po_id']] = (int) $r['invoice_id'];

        [$in, $params] = flow_in_clause($poIds);
        $viaLine = $pdo->prepare("SELECT DISTINCT pl.po_id, il.invoice_id FROM po_lines pl JOIN invoice_lines il ON il.id=pl.invoice_line_id WHERE pl.po_id $in");
        $viaLine->execute($params);
        foreach ($viaLine->fetchAll() as $r) if (!isset($poInvoiceMap[$r['po_id']])) $poInvoiceMap[$r['po_id']] = (int) $r['invoice_id'];
    }
}

/** [parent_col_key, parent_id] buat gambar garis penghubung ke kartu di kolom sebelumnya. */
function flow_parent_ref(string $colKey, array $row, array $poInvoiceMap): ?array
{
    switch ($colKey) {
        case 'invoice': return $row['quotation_id'] ? ['penawaran', (int) $row['quotation_id']] : null;
        case 'po': return isset($poInvoiceMap[$row['id']]) ? ['invoice', (int) $poInvoiceMap[$row['id']]] : null;
        case 'spk': return $row['po_id'] ? ['po', (int) $row['po_id']] : null;
        case 'penerimaan':
            if ($row['spk_id']) return ['spk', (int) $row['spk_id']];
            if ($row['po_id']) return ['po', (int) $row['po_id']];
            return null;
        case 'do': return $row['invoice_id'] ? ['invoice', (int) $row['invoice_id']] : null;
        case 'kuitansi': return $row['invoice_id'] ? ['invoice', (int) $row['invoice_id']] : null;
        default: return null;
    }
}

const FLOW_COLUMNS = [
    ['key' => 'penawaran', 'module' => 'penawaran', 'label' => 'Penawaran', 'href' => 'quotations.php'],
    ['key' => 'invoice', 'module' => 'invoicing', 'label' => 'Invoice', 'href' => 'invoices.php'],
    ['key' => 'po', 'module' => 'po', 'label' => 'Purchase Order', 'href' => 'purchase-orders.php'],
    ['key' => 'spk', 'module' => 'spk', 'label' => 'SPK / Manufaktur', 'href' => 'spk.php'],
    ['key' => 'penerimaan', 'module' => 'penerimaan', 'label' => 'Penerimaan Barang', 'href' => 'goods-receipts.php'],
    ['key' => 'do', 'module' => 'do', 'label' => 'Delivery Order', 'href' => 'delivery-orders.php'],
    ['key' => 'kuitansi', 'module' => 'kuitansi', 'label' => 'Kuitansi', 'href' => 'kuitansi.php'],
];
$flowData = [
    'penawaran' => $quotations, 'invoice' => $invoices, 'po' => $purchaseOrders,
    'spk' => $spks, 'penerimaan' => $receipts, 'do' => $deliveryOrders, 'kuitansi' => $kuitansiRows,
];
?>

<div class="txn-shell" id="pf-shell">
  <div class="txn-rail" id="pf-rail">
    <div class="txn-rail-month">
      <a href="project-flow.php?month=<?= $prevMonth ?><?= $selectedId ? '&id=' . $selectedId : '' ?>">‹</a>
      <span><?= htmlspecialchars(date('F Y', strtotime($month . '-01'))) ?></span>
      <a href="project-flow.php?month=<?= $nextMonth ?><?= $selectedId ? '&id=' . $selectedId : '' ?>">›</a>
      <a class="today-btn" href="project-flow.php<?= $selectedId ? '?id=' . $selectedId : '' ?>">Bulan Ini</a>
    </div>
    <div class="txn-rail-list">
      <?php foreach ($projects as $p): ?>
        <a class="txn-rail-item <?= (int) $p['id'] === $selectedId ? 'active' : '' ?>" href="project-flow.php?month=<?= $month ?>&id=<?= $p['id'] ?>">
          <div class="doc"><?= htmlspecialchars($p['name']) ?></div>
        </a>
      <?php endforeach; ?>
      <?php if (!$projects): ?><div style="padding:14px; font-size:12px; color:var(--ink-muted);">Gak ada project dibuat bulan ini.</div><?php endif; ?>
    </div>
  </div>

  <button type="button" class="pf-rail-toggle" id="pf-rail-toggle" title="Sembunyikan/tampilkan list project">‹</button>

  <div class="txn-detail" style="overflow-x:auto;">
    <?php if (!$selected): ?>
      <div class="card txn-empty">Pilih project di kiri buat lihat alur dokumennya.</div>
    <?php else: ?>
      <div class="txn-detail-header">
        <h2><?= htmlspecialchars($selected['name']) ?></h2>
        <div class="txn-detail-actions">
          <a class="btn btn-sm btn-ghost" href="projects.php?id=<?= $selected['id'] ?>">Lihat Detail &amp; P&amp;L</a>
        </div>
      </div>

      <div class="flow-board-wrap">
        <svg class="flow-connectors" id="flow-connectors"></svg>
        <div class="flow-board" id="flow-board">
          <?php foreach (FLOW_COLUMNS as $col): $rows = $flowData[$col['key']];
            $newHref = $col['href'] . '?new=1' . ($col['key'] === 'penawaran' ? '&picked_project=' . $selected['id'] : '');
          ?>
            <div class="flow-col">
              <div class="flow-col-head"><?= htmlspecialchars($col['label']) ?> <span class="flow-count"><?= count($rows) ?></span></div>
              <?php if (!$rows): ?>
                <div class="flow-empty">—</div>
              <?php else: foreach ($rows as $row):
                $parentRef = flow_parent_ref($col['key'], $row, $poInvoiceMap ?? []);
                $parentAttr = $parentRef ? $parentRef[0] . '-' . $parentRef[1] : '';
              ?>
                <?php
                  $amount = $col['key'] === 'kuitansi' ? (float) $row['amount'] : (float) ($row['total'] ?? $row['billed_amount'] ?? 0);
                ?>
                <a class="flow-card" href="<?= $col['href'] ?>?id=<?= $row['id'] ?>" data-flow-id="<?= $col['key'] ?>-<?= $row['id'] ?>" data-flow-parent="<?= $parentAttr ?>">
                  <div class="flow-doc"><?= htmlspecialchars($row['doc_number']) ?></div>
                  <?php if ($col['key'] === 'kuitansi'): ?>
                    <span class="pill pill-<?= $row['payment_type'] ?>"><?= strtoupper($row['payment_type']) ?></span>
                  <?php elseif ($col['key'] === 'penerimaan'): ?>
                    <span class="pill"><?= $row['destination'] === 'direct_customer' ? 'KE CUSTOMER' : 'GUDANG' ?></span>
                  <?php else: ?>
                    <span class="pill pill-<?= $row['status'] ?>"><?= strtoupper($row['status']) ?></span>
                  <?php endif; ?>
                  <?php if ($col['key'] === 'invoice'): ?>
                    <span class="pill" style="margin-left:4px;"><?= $row['payment_scheme'] === 'dp' ? 'DP' : 'LUNAS' ?></span>
                  <?php endif; ?>
                  <div class="flow-sub">Rp <?= number_format($amount, 0, ',', '.') ?></div>
                </a>
              <?php endforeach; endif; ?>
              <?php if (has_access($col['module'], 'can_create')): ?>
                <a class="flow-add" href="<?= htmlspecialchars($newHref) ?>">+ Tambah</a>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var shell = document.getElementById('pf-shell');
  var toggle = document.getElementById('pf-rail-toggle');
  if (shell && toggle) {
    var collapsed = localStorage.getItem('pf-rail-collapsed') === '1';
    if (collapsed) shell.classList.add('rail-collapsed');
    toggle.addEventListener('click', function () {
      shell.classList.toggle('rail-collapsed');
      localStorage.setItem('pf-rail-collapsed', shell.classList.contains('rail-collapsed') ? '1' : '0');
      setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 220);
    });
  }
})();
(function () {
  var board = document.getElementById('flow-board');
  var svg = document.getElementById('flow-connectors');
  if (!board || !svg) return;

  function draw() {
    var boardRect = board.getBoundingClientRect();
    svg.setAttribute('width', board.scrollWidth);
    svg.setAttribute('height', board.scrollHeight);
    svg.innerHTML = '';

    var cards = board.querySelectorAll('.flow-card[data-flow-id]');
    var byId = {};
    cards.forEach(function (c) { byId[c.getAttribute('data-flow-id')] = c; });

    var paths = [];
    cards.forEach(function (child) {
      var parentKey = child.getAttribute('data-flow-parent') || '';
      var parentEl = parentKey ? byId[parentKey] : null;
      if (!parentEl) return;

      var pr = parentEl.getBoundingClientRect();
      var cr = child.getBoundingClientRect();
      var x1 = pr.right - boardRect.left;
      var y1 = pr.top + pr.height / 2 - boardRect.top;
      var x2 = cr.left - boardRect.left;
      var y2 = cr.top + cr.height / 2 - boardRect.top;
      var midX = (x1 + x2) / 2;
      var d = 'M ' + x1 + ' ' + y1 + ' C ' + midX + ' ' + y1 + ', ' + midX + ' ' + y2 + ', ' + x2 + ' ' + y2;

      var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', d);
      path.setAttribute('class', 'flow-link');
      svg.appendChild(path);

      var dots = [[x1, y1], [x2, y2]].map(function (pt) {
        var dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        dot.setAttribute('cx', pt[0]);
        dot.setAttribute('cy', pt[1]);
        dot.setAttribute('r', 3);
        dot.setAttribute('class', 'flow-link-dot');
        svg.appendChild(dot);
        return dot;
      });
      paths.push({ path: path, dots: dots });
    });

    // Animasi "gambar garis" — jalan setelah semua path ke-append ke DOM,
    // titik ujungnya muncul begitu garisnya selesai "digambar".
    paths.forEach(function (item, i) {
      var len = item.path.getTotalLength();
      item.path.style.strokeDasharray = len;
      item.path.style.strokeDashoffset = len;
      item.path.getBoundingClientRect(); // force reflow biar transition kepicu
      setTimeout(function () {
        item.path.style.transition = 'stroke-dashoffset .5s ease';
        item.path.style.strokeDashoffset = '0';
        setTimeout(function () {
          item.dots.forEach(function (d) { d.classList.add('show'); });
        }, 500);
      }, 60 * i);
    });
  }

  window.addEventListener('load', draw);
  window.addEventListener('resize', draw);
  if (document.readyState === 'complete') draw();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
