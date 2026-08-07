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

// Stat cards — snapshot stock terkini + aktivitas bulan berjalan.
$stockCountStmt = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(weight),0) FROM inventory_items WHERE organization_id=? AND status="in_stock"');
$stockCountStmt->execute([$orgId]);
[$stockCount, $stockWeight] = $stockCountStmt->fetch(PDO::FETCH_NUM);

$receivedStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_items WHERE organization_id=? AND source_type="goods_receipt" AND DATE(created_at) BETWEEN ? AND ?');
$receivedStmt->execute([$orgId, $dateFrom, $dateTo]);
$receivedCount = (int) $receivedStmt->fetchColumn();

$soldStmt = $pdo->prepare(
    'SELECT COUNT(*), COALESCE(SUM(sgl.unit_price),0) FROM sales_gold_lines sgl
     JOIN sales_gold sg ON sg.id = sgl.sale_id
     WHERE sg.organization_id=? AND DATE(sg.sold_at) BETWEEN ? AND ?'
);
$soldStmt->execute([$orgId, $dateFrom, $dateTo]);
[$soldCount, $soldRevenue] = $soldStmt->fetch(PDO::FETCH_NUM);

// Penjualan by customer bulan berjalan.
$byCustomerStmt = $pdo->prepare(
    'SELECT c.name AS label, SUM(sgl.unit_price) AS value
     FROM sales_gold_lines sgl
     JOIN sales_gold sg ON sg.id = sgl.sale_id
     JOIN contacts c ON c.id = sg.contact_id
     WHERE sg.organization_id = ? AND DATE(sg.sold_at) BETWEEN ? AND ?
     GROUP BY c.id
     ORDER BY value DESC
     LIMIT 10'
);
$byCustomerStmt->execute([$orgId, $dateFrom, $dateTo]);
$byCustomer = $byCustomerStmt->fetchAll();

// Stock in_stock terkini, dikelompokkan per Group Product.
$catRows = $pdo->prepare('SELECT id, parent_id, name FROM product_categories WHERE organization_id=?');
$catRows->execute([$orgId]);
$catRows = $catRows->fetchAll();
$catById = [];
foreach ($catRows as $c) $catById[$c['id']] = $c;
function dash_root_name(array $catById, ?int $id): string
{
    $node = $id ? ($catById[$id] ?? null) : null;
    while ($node && $node['parent_id']) $node = $catById[$node['parent_id']] ?? null;
    return $node ? $node['name'] : 'Tanpa Kategori';
}
$stockRows = $pdo->prepare(
    "SELECT p.category_id, COUNT(*) AS cnt FROM inventory_items ii JOIN products p ON p.id = ii.product_id
     WHERE ii.organization_id=? AND ii.status='in_stock' GROUP BY p.category_id"
);
$stockRows->execute([$orgId]);
$stockByGroup = [];
foreach ($stockRows->fetchAll() as $r) {
    $name = dash_root_name($catById, $r['category_id'] ? (int) $r['category_id'] : null);
    $stockByGroup[$name] = ($stockByGroup[$name] ?? 0) + (int) $r['cnt'];
}
arsort($stockByGroup);
$stockByGroupRows = array_map(fn($k, $v) => ['label' => $k, 'value' => $v], array_keys($stockByGroup), array_values($stockByGroup));

// Stock in_stock terkini, dikelompokkan per Lokasi.
$stockByLocStmt = $pdo->prepare(
    "SELECT gloc.name AS group_name, loc.name AS loc_name, COUNT(*) AS cnt, COALESCE(SUM(ii.weight),0) AS total_weight
     FROM inventory_items ii
     LEFT JOIN locations loc ON loc.id = ii.location_id
     LEFT JOIN locations gloc ON gloc.id = loc.parent_id
     WHERE ii.organization_id=? AND ii.status='in_stock'
     GROUP BY ii.location_id ORDER BY cnt DESC"
);
$stockByLocStmt->execute([$orgId]);
$stockByLoc = $stockByLocStmt->fetchAll();

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

<div class="stat-grid">
  <div class="stat-card"><div class="val"><?= number_format((int) $stockCount) ?></div><div class="lbl">Barang In-Stock (skrg)</div></div>
  <div class="stat-card"><div class="val"><?= number_format((float) $stockWeight, 2, ',', '.') ?> gr</div><div class="lbl">Total Berat In-Stock</div></div>
  <div class="stat-card"><div class="val"><?= number_format($receivedCount) ?></div><div class="lbl">Barang Masuk — <?= htmlspecialchars($periodLabel) ?></div></div>
  <div class="stat-card"><div class="val">Rp <?= number_format((float) $soldRevenue, 0, ',', '.') ?></div><div class="lbl"><?= number_format((int) $soldCount) ?> Terjual — <?= htmlspecialchars($periodLabel) ?></div></div>
</div>

<div class="dash-grid">
  <div class="card dash-chart-card">
    <h3>Penjualan by Customer — <?= htmlspecialchars($periodLabel) ?></h3>
    <canvas id="chartByCustomer" height="220"></canvas>
    <?php if (!$byCustomer): ?><p class="dash-empty">Belum ada penjualan pada periode ini.</p><?php endif; ?>
  </div>

  <div class="card dash-chart-card">
    <h3>Stock In-Stock per Group Product <span class="dash-hint">(skrg)</span></h3>
    <canvas id="chartStockGroup" height="220"></canvas>
    <?php if (!$stockByGroupRows): ?><p class="dash-empty">Belum ada stock.</p><?php endif; ?>
  </div>

  <div class="card dash-chart-card dash-chart-wide">
    <h3>Stock per Lokasi <span class="dash-hint">(skrg)</span></h3>
    <table class="data-table">
      <thead><tr><th>Lokasi</th><th class="num">Jumlah Barang</th><th class="num">Total Berat</th></tr></thead>
      <tbody>
        <?php foreach ($stockByLoc as $r): ?>
          <tr>
            <td><?= htmlspecialchars(($r['group_name'] ? $r['group_name'] . ' › ' : '') . ($r['loc_name'] ?? '-')) ?></td>
            <td class="num"><?= (int) $r['cnt'] ?></td>
            <td class="num"><?= number_format((float) $r['total_weight'], 2, ',', '.') ?> gr</td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$stockByLoc): ?><tr><td colspan="3" style="text-align:center; color:var(--ink-muted);">Belum ada stock.</td></tr><?php endif; ?>
      </tbody>
    </table>
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
  barChart('chartStockGroup', <?= chart_payload($stockByGroupRows) ?>);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
