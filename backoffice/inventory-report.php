<?php
/**
 * Laporan sebaran stok per lokasi — gudang sendiri maupun gudang vendor
 * (virtual, dari fitur "kirim ke stock vendor" di PO). Read-only.
 */
$pageTitle = 'Laporan Inventory';
$activeMenu = 'inventory_report';
require __DIR__ . '/includes/header.php';
require_module_access('laporan');

$pdo = db();
$orgId = $org['organization_id'];

$materialStock = $pdo->prepare(
    "SELECT w.id AS warehouse_id, w.name AS warehouse_name, w.vendor_id, v.name AS vendor_name,
       m.id AS material_id, m.name AS material_name, m.unit,
       SUM(sl.qty_remaining) AS qty
     FROM stock_ledger sl
     JOIN warehouses w ON w.id = sl.warehouse_id
     JOIN materials m ON m.id = sl.material_id
     LEFT JOIN contacts v ON v.id = w.vendor_id
     WHERE sl.organization_id=? AND sl.direction='in' AND sl.material_id IS NOT NULL
     GROUP BY w.id, m.id
     HAVING qty > 0
     ORDER BY w.vendor_id IS NULL DESC, w.name, m.name"
);
$materialStock->execute([$orgId]);
$materialStock = $materialStock->fetchAll();

$productStock = $pdo->prepare(
    "SELECT w.id AS warehouse_id, w.name AS warehouse_name, w.vendor_id, v.name AS vendor_name,
       p.id AS product_id, p.name AS product_name, p.unit,
       SUM(sl.qty_remaining) AS qty
     FROM stock_ledger sl
     JOIN warehouses w ON w.id = sl.warehouse_id
     JOIN products p ON p.id = sl.product_id
     LEFT JOIN contacts v ON v.id = w.vendor_id
     WHERE sl.organization_id=? AND sl.direction='in' AND sl.product_id IS NOT NULL
     GROUP BY w.id, p.id
     HAVING qty > 0
     ORDER BY w.vendor_id IS NULL DESC, w.name, p.name"
);
$productStock->execute([$orgId]);
$productStock = $productStock->fetchAll();

function loc_badge(array $row): string
{
    if ($row['vendor_id']) {
        return '<span class="pill">VENDOR: ' . htmlspecialchars($row['vendor_name'] ?? '?') . '</span>';
    }
    return htmlspecialchars($row['warehouse_name']);
}
?>

<div class="txn-detail-header">
  <h2>Laporan Inventory</h2>
</div>
<p style="font-size:13px; color:var(--ink-muted); margin-top:-6px; margin-bottom:20px;">
  Sebaran stok sekarang — termasuk yang lagi "dititip" di gudang vendor (bukan cuma gudang Svashta sendiri).
</p>

<h3 style="margin-bottom:10px;">Stok Material</h3>
<div class="card" style="margin-bottom:24px;">
  <table class="data-table">
    <thead><tr><th>Lokasi</th><th>Material</th><th class="num">Qty Tersisa</th></tr></thead>
    <tbody>
      <?php foreach ($materialStock as $r): ?>
        <tr>
          <td><?= loc_badge($r) ?></td>
          <td><?= htmlspecialchars($r['material_name']) ?></td>
          <td class="num"><?= rtrim(rtrim(number_format((float) $r['qty'], 2, ',', '.'), '0'), ',') ?> <?= htmlspecialchars($r['unit']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$materialStock): ?><tr><td colspan="3" style="text-align:center; color:var(--ink-muted);">Belum ada stok material.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<h3 style="margin-bottom:10px;">Stok Barang Jadi</h3>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Lokasi</th><th>Produk</th><th class="num">Qty Tersisa</th></tr></thead>
    <tbody>
      <?php foreach ($productStock as $r): ?>
        <tr>
          <td><?= loc_badge($r) ?></td>
          <td><?= htmlspecialchars($r['product_name']) ?></td>
          <td class="num"><?= rtrim(rtrim(number_format((float) $r['qty'], 2, ',', '.'), '0'), ',') ?> <?= htmlspecialchars($r['unit']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$productStock): ?><tr><td colspan="3" style="text-align:center; color:var(--ink-muted);">Belum ada stok barang jadi.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
