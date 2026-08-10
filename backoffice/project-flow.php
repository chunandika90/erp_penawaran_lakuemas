<?php
/**
 * Flow board per project (vertikal emas) — model Trello: kolom per tahap
 * transaksi, kartu per dokumen. Klik kartu = ke halaman modul aslinya
 * (list+modal, bukan URL per-dokumen — gold pages gak punya route ?id=).
 * Garis penghubung cuma digambar buat 3 link FK yang beneran ada: Penjualan
 * -> Penawaran (quotation_id), Penerimaan -> PO (po_id), Retur -> Penerimaan
 * (goods_receipt_id). PO/Transfer/Lebur berdiri sendiri (gak ada FK dokumen
 * sumber), sama kayak kolom lama yang parent-nya null — tetep digambar,
 * cuma tanpa garis.
 */
$pageTitle = 'Project Flow';
$activeMenu = 'project_flow';
require __DIR__ . '/includes/header.php';
require_module_access('penawaran');

$pdo = db();
$orgId = $org['organization_id'];

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

$penawaran = $po = $penerimaan = $transfer = $penjualan = $lebur = $retur = [];

if ($selected) {
    $pid = $selected['id'];

    $qStmt = $pdo->prepare(
        "SELECT qg.*, (SELECT COALESCE(SUM(qty*unit_price),0) FROM quotation_gold_lines WHERE quotation_id=qg.id) AS total
         FROM quotations_gold qg WHERE qg.project_id=? AND qg.organization_id=? ORDER BY qg.created_at"
    );
    $qStmt->execute([$pid, $orgId]);
    $penawaran = $qStmt->fetchAll();

    $poStmt = $pdo->prepare(
        "SELECT po.*, (SELECT COALESCE(SUM(qty*unit_cost),0) FROM purchase_order_gold_lines WHERE po_id=po.id) AS total,
           (SELECT GROUP_CONCAT(DISTINCT qgl.quotation_id) FROM purchase_order_gold_lines pgl
              JOIN quotation_gold_lines qgl ON qgl.id = pgl.quotation_line_id WHERE pgl.po_id=po.id) AS source_quotation_ids
         FROM purchase_orders_gold po WHERE po.project_id=? AND po.organization_id=? ORDER BY po.created_at"
    );
    $poStmt->execute([$pid, $orgId]);
    $po = $poStmt->fetchAll();

    $grStmt = $pdo->prepare(
        "SELECT gr.*, (SELECT COUNT(*) FROM inventory_items WHERE source_type='goods_receipt' AND source_id=gr.id) AS item_count,
           (SELECT GROUP_CONCAT(DISTINCT pgl.po_id) FROM inventory_items ii
              JOIN purchase_order_gold_lines pgl ON pgl.id = ii.po_line_id
              WHERE ii.source_type='goods_receipt' AND ii.source_id=gr.id) AS source_po_ids
         FROM gold_goods_receipts gr WHERE gr.project_id=? AND gr.organization_id=? ORDER BY gr.received_at"
    );
    $grStmt->execute([$pid, $orgId]);
    $penerimaan = $grStmt->fetchAll();

    $trStmt = $pdo->prepare(
        "SELECT st.*, (SELECT COUNT(*) FROM stock_transfer_lines WHERE stock_transfer_id=st.id) AS item_count
         FROM stock_transfers st WHERE st.project_id=? AND st.organization_id=? ORDER BY st.sent_at"
    );
    $trStmt->execute([$pid, $orgId]);
    $transfer = $trStmt->fetchAll();

    $sgStmt = $pdo->prepare(
        "SELECT sg.*, (SELECT COALESCE(SUM(unit_price),0) FROM sales_gold_lines WHERE sale_id=sg.id) AS total
         FROM sales_gold sg WHERE sg.project_id=? AND sg.organization_id=? ORDER BY sg.sold_at"
    );
    $sgStmt->execute([$pid, $orgId]);
    $penjualan = $sgStmt->fetchAll();

    $mlStmt = $pdo->prepare(
        "SELECT mb.*, (SELECT COUNT(*) FROM melting_batch_lines WHERE melting_batch_id=mb.id) AS item_count
         FROM melting_batches mb WHERE mb.project_id=? AND mb.organization_id=? ORDER BY mb.melted_at"
    );
    $mlStmt->execute([$pid, $orgId]);
    $lebur = $mlStmt->fetchAll();

    $rtStmt = $pdo->prepare(
        "SELECT sr.*, (SELECT COUNT(*) FROM supplier_return_lines WHERE supplier_return_id=sr.id) AS item_count
         FROM supplier_returns sr JOIN gold_goods_receipts gr ON gr.id=sr.goods_receipt_id
         WHERE gr.project_id=? AND sr.organization_id=? ORDER BY sr.returned_at"
    );
    $rtStmt->execute([$pid, $orgId]);
    $retur = $rtStmt->fetchAll();
}

/**
 * List of [parent_col_key, parent_id] buat gambar garis penghubung ke kartu
 * di kolom sebelumnya — bisa lebih dari 1 (merge: 1 Penerimaan narik dari
 * beberapa PO; split: 1 PO bisa nunjuk balik ke Penawaran yang sama kayak PO
 * lain). Sumbernya dari GROUP_CONCAT baris (source_po_ids/source_quotation_ids),
 * bukan cuma 1 kolom header kayak dulu.
 */
function flow_parent_refs(string $colKey, array $row): array
{
    switch ($colKey) {
        case 'penjualan':
            return $row['quotation_id'] ? [['penawaran', (int) $row['quotation_id']]] : [];
        case 'po':
            if (empty($row['source_quotation_ids'])) return [];
            return array_map(fn($id) => ['penawaran', (int) $id], explode(',', $row['source_quotation_ids']));
        case 'penerimaan':
            if (!empty($row['source_po_ids'])) {
                return array_map(fn($id) => ['po', (int) $id], explode(',', $row['source_po_ids']));
            }
            // Fallback: gak ada baris yang kepilih PO Line-nya, tapi header masih nunjuk 1 PO "info aja".
            return $row['po_id'] ? [['po', (int) $row['po_id']]] : [];
        case 'retur':
            return $row['goods_receipt_id'] ? [['penerimaan', (int) $row['goods_receipt_id']]] : [];
        default:
            return [];
    }
}

const FLOW_COLUMNS = [
    ['key' => 'penawaran', 'label' => 'Penawaran', 'href' => 'quotation-gold.php'],
    ['key' => 'po', 'label' => 'Purchase Order', 'href' => 'purchase-order-gold.php'],
    ['key' => 'penerimaan', 'label' => 'Penerimaan Barang', 'href' => 'goods-receipt-gold.php'],
    ['key' => 'transfer', 'label' => 'Transfer Stock', 'href' => 'stock-transfer.php'],
    ['key' => 'penjualan', 'label' => 'Penjualan', 'href' => 'sale-gold.php'],
    ['key' => 'lebur', 'label' => 'Lebur Barang', 'href' => 'melting.php'],
    ['key' => 'retur', 'label' => 'Retur Supplier', 'href' => 'supplier-return.php'],
];
$flowData = [
    'penawaran' => $penawaran, 'po' => $po, 'penerimaan' => $penerimaan,
    'transfer' => $transfer, 'penjualan' => $penjualan, 'lebur' => $lebur, 'retur' => $retur,
];
// Kolom yang punya total Rp lewat baris item vs yang cuma punya jumlah barang
// (gold_goods_receipts/stock_transfers/melting_batches/supplier_returns gak
// nyimpen harga per baris — di-tampilin jumlah barang aja, bukan angka Rp
// yang dikarang).
$moneyColumns = ['penawaran', 'po', 'penjualan'];
$statusColumns = ['penawaran', 'po', 'transfer'];
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
          <button type="button" class="pf-orient-toggle" id="pf-orient-toggle">⇄ Vertikal</button>
          <a class="btn btn-sm btn-ghost" href="projects.php?id=<?= $selected['id'] ?>">Lihat Detail &amp; P&amp;L</a>
        </div>
      </div>

      <div class="flow-board-wrap">
        <svg class="flow-connectors" id="flow-connectors"></svg>
        <div class="flow-board" id="flow-board">
          <?php foreach (FLOW_COLUMNS as $col): $rows = $flowData[$col['key']]; ?>
            <div class="flow-col">
              <div class="flow-col-head"><?= htmlspecialchars($col['label']) ?> <span class="flow-count"><?= count($rows) ?></span></div>
              <?php if (!$rows): ?>
                <div class="flow-empty">—</div>
              <?php else: foreach ($rows as $row):
                $parentRefs = flow_parent_refs($col['key'], $row);
                $parentAttr = implode(',', array_map(fn($r) => $r[0] . '-' . $r[1], $parentRefs));
              ?>
                <a class="flow-card" href="<?= $col['href'] ?>" data-flow-id="<?= $col['key'] ?>-<?= $row['id'] ?>" data-flow-parent="<?= $parentAttr ?>">
                  <div class="flow-doc"><?= htmlspecialchars($row['doc_number']) ?></div>
                  <?php if (in_array($col['key'], $statusColumns, true)): ?>
                    <span class="pill pill-<?= $row['status'] ?>"><?= strtoupper($row['status']) ?></span>
                  <?php endif; ?>
                  <?php if (in_array($col['key'], $moneyColumns, true)): ?>
                    <div class="flow-sub">Rp <?= number_format((float) $row['total'], 0, ',', '.') ?></div>
                  <?php else: ?>
                    <div class="flow-sub"><?= (int) $row['item_count'] ?> barang</div>
                  <?php endif; ?>
                </a>
              <?php endforeach; endif; ?>
              <?php if (has_access('kontak', 'can_create')): ?>
                <a class="flow-add" href="<?= htmlspecialchars($col['href']) ?>">+ Tambah</a>
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
  var orientToggle = document.getElementById('pf-orient-toggle');
  if (!board || !svg) return;

  var vertical = localStorage.getItem('pf-board-vertical') === '1';

  function applyOrientation() {
    board.classList.toggle('vertical', vertical);
    if (orientToggle) orientToggle.textContent = vertical ? '⇄ Horizontal' : '⇄ Vertikal';
  }
  applyOrientation();
  if (orientToggle) {
    orientToggle.addEventListener('click', function () {
      vertical = !vertical;
      localStorage.setItem('pf-board-vertical', vertical ? '1' : '0');
      applyOrientation();
      setTimeout(draw, 20);
    });
  }

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
      var parentKeys = (child.getAttribute('data-flow-parent') || '').split(',').filter(Boolean);
      parentKeys.forEach(function (parentKey) {
      var parentEl = byId[parentKey];
      if (!parentEl) return;

      var pr = parentEl.getBoundingClientRect();
      var cr = child.getBoundingClientRect();
      var x1, y1, x2, y2, d;
      if (vertical) {
        x1 = pr.left + pr.width / 2 - boardRect.left;
        y1 = pr.bottom - boardRect.top;
        x2 = cr.left + cr.width / 2 - boardRect.left;
        y2 = cr.top - boardRect.top;
        var midY = (y1 + y2) / 2;
        d = 'M ' + x1 + ' ' + y1 + ' C ' + x1 + ' ' + midY + ', ' + x2 + ' ' + midY + ', ' + x2 + ' ' + y2;
      } else {
        x1 = pr.right - boardRect.left;
        y1 = pr.top + pr.height / 2 - boardRect.top;
        x2 = cr.left - boardRect.left;
        y2 = cr.top + cr.height / 2 - boardRect.top;
        var midX = (x1 + x2) / 2;
        d = 'M ' + x1 + ' ' + y1 + ' C ' + midX + ' ' + y1 + ', ' + midX + ' ' + y2 + ', ' + x2 + ' ' + y2;
      }

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
