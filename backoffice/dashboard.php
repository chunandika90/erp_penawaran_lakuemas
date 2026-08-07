<?php
$pageTitle = 'Dashboard';
$activeMenu = 'dashboard';
require __DIR__ . '/includes/header.php';

$pdo = db();
$orgId = $org['organization_id'];

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$dateFrom = $month . '-01';
$dateTo = date('Y-m-t', strtotime($dateFrom));
$periodLabel = date('F Y', strtotime($dateFrom));

// 1) Performa penjualan by customer — revenue invoice (qty x harga jual per baris) bulan berjalan.
$byCustomerStmt = $pdo->prepare(
    'SELECT c.name AS label, SUM(il.qty * il.unit_price) AS value
     FROM invoice_lines il
     JOIN invoices i ON i.id = il.invoice_id
     JOIN contacts c ON c.id = i.contact_id
     WHERE i.organization_id = ? AND i.status != "void" AND DATE(i.created_at) BETWEEN ? AND ?
     GROUP BY c.id
     ORDER BY value DESC
     LIMIT 10'
);
$byCustomerStmt->execute([$orgId, $dateFrom, $dateTo]);
$byCustomer = $byCustomerStmt->fetchAll();

// 2) Performa penjualan by project — revenue invoice ditelusuri balik lewat quotation_line -> quotation -> project.
$byProjectStmt = $pdo->prepare(
    'SELECT COALESCE(p.name, "Tanpa Project") AS label, SUM(il.qty * il.unit_price) AS value
     FROM invoice_lines il
     JOIN invoices i ON i.id = il.invoice_id
     JOIN quotation_lines ql ON ql.id = il.quotation_line_id
     JOIN quotations q ON q.id = ql.quotation_id
     LEFT JOIN projects p ON p.id = q.project_id
     WHERE i.organization_id = ? AND i.status != "void" AND DATE(i.created_at) BETWEEN ? AND ?
     GROUP BY p.id
     ORDER BY value DESC
     LIMIT 10'
);
$byProjectStmt->execute([$orgId, $dateFrom, $dateTo]);
$byProject = $byProjectStmt->fetchAll();

// 3) SPK per vendor — jumlah SPK bulan berjalan, dikelompokkan per vendor.
$spkByVendorStmt = $pdo->prepare(
    'SELECT c.name AS label, COUNT(*) AS value
     FROM spk s
     JOIN contacts c ON c.id = s.vendor_id
     WHERE s.organization_id = ? AND s.status != "void" AND DATE(s.created_at) BETWEEN ? AND ?
     GROUP BY c.id
     ORDER BY value DESC
     LIMIT 10'
);
$spkByVendorStmt->execute([$orgId, $dateFrom, $dateTo]);
$spkByVendor = $spkByVendorStmt->fetchAll();

// 4) Produk paling laku — total qty terjual (invoice_lines) bulan berjalan.
$topProductsStmt = $pdo->prepare(
    'SELECT il.product_name_snapshot AS label, SUM(il.qty) AS value
     FROM invoice_lines il
     JOIN invoices i ON i.id = il.invoice_id
     WHERE i.organization_id = ? AND i.status != "void" AND DATE(i.created_at) BETWEEN ? AND ?
     GROUP BY il.product_name_snapshot
     ORDER BY value DESC
     LIMIT 10'
);
$topProductsStmt->execute([$orgId, $dateFrom, $dateTo]);
$topProducts = $topProductsStmt->fetchAll();

// 5) Laporan inventory per material — stok TERKINI (snapshot, bukan per periode).
$stockByMaterialStmt = $pdo->prepare(
    'SELECT m.name AS label, SUM(sl.qty_remaining) AS value
     FROM stock_ledger sl
     JOIN materials m ON m.id = sl.material_id
     WHERE sl.organization_id = ? AND sl.direction = "in" AND sl.material_id IS NOT NULL
     GROUP BY m.id
     HAVING value > 0
     ORDER BY value DESC
     LIMIT 15'
);
$stockByMaterialStmt->execute([$orgId]);
$stockByMaterial = $stockByMaterialStmt->fetchAll();

function chart_payload(array $rows): string
{
    return json_encode([
        'labels' => array_map(fn($r) => $r['label'], $rows),
        'values' => array_map(fn($r) => (float) $r['value'], $rows),
    ]);
}
?>

<form method="get" class="txn-detail-header" style="flex-wrap:wrap; gap:10px;">
  <div style="font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-muted);">
    Periode <?= htmlspecialchars($periodLabel) ?>
  </div>
  <div class="txn-detail-actions">
    <input type="month" name="month" value="<?= htmlspecialchars($month) ?>">
    <button type="submit" class="btn btn-sm">Filter</button>
    <a class="btn btn-sm btn-ghost" href="dashboard.php">Bulan Ini</a>
  </div>
</form>

<div class="dash-grid">
  <div class="card dash-chart-card">
    <h3>Performa Penjualan by Customer</h3>
    <canvas id="chartByCustomer" height="220"></canvas>
    <?php if (!$byCustomer): ?><p class="dash-empty">Belum ada invoice pada periode ini.</p><?php endif; ?>
  </div>

  <div class="card dash-chart-card">
    <h3>Performa Penjualan by Project</h3>
    <canvas id="chartByProject" height="220"></canvas>
    <?php if (!$byProject): ?><p class="dash-empty">Belum ada invoice pada periode ini.</p><?php endif; ?>
  </div>

  <div class="card dash-chart-card">
    <h3>SPK per Vendor</h3>
    <canvas id="chartSpkVendor" height="220"></canvas>
    <?php if (!$spkByVendor): ?><p class="dash-empty">Belum ada SPK pada periode ini.</p><?php endif; ?>
  </div>

  <div class="card dash-chart-card">
    <h3>Produk Paling Laku — <?= htmlspecialchars($periodLabel) ?></h3>
    <canvas id="chartTopProducts" height="220"></canvas>
    <?php if (!$topProducts): ?><p class="dash-empty">Belum ada penjualan produk pada periode ini.</p><?php endif; ?>
  </div>

  <div class="card dash-chart-card dash-chart-wide">
    <h3>Laporan Inventory per Material <span class="dash-hint">(stok terkini)</span></h3>
    <canvas id="chartStockMaterial" height="180"></canvas>
    <?php if (!$stockByMaterial): ?><p class="dash-empty">Belum ada stok material.</p><?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
(function () {
  var ACCENT = '#4a6cf0';
  var ACCENT_SOFT = 'rgba(74, 108, 240, 0.55)';

  function barChart(canvasId, payload, opts) {
    var el = document.getElementById(canvasId);
    if (!el || !payload.labels.length) return;
    opts = opts || {};
    new Chart(el.getContext('2d'), {
      type: 'bar',
      data: {
        labels: payload.labels,
        datasets: [{
          data: payload.values,
          backgroundColor: ACCENT_SOFT,
          borderColor: ACCENT,
          borderWidth: 1,
          borderRadius: 4,
        }],
      },
      options: {
        indexAxis: opts.horizontal ? 'y' : 'x',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { ticks: { font: { size: 11 } } },
          y: { ticks: { font: { size: 11 } }, beginAtZero: true },
        },
      },
    });
  }

  barChart('chartByCustomer', <?= chart_payload($byCustomer) ?>, { horizontal: true });
  barChart('chartByProject', <?= chart_payload($byProject) ?>, { horizontal: true });
  barChart('chartSpkVendor', <?= chart_payload($spkByVendor) ?>);
  barChart('chartTopProducts', <?= chart_payload($topProducts) ?>, { horizontal: true });
  barChart('chartStockMaterial', <?= chart_payload($stockByMaterial) ?>);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
