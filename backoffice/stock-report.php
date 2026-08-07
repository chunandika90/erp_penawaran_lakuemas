<?php
/**
 * Laporan stock emas — 8 filter: group lokasi, lokasi, group product,
 * brand/jenis item, pecahan, tipe stock, kode sertifikat, kode PLU.
 * Default nampilin status in_stock aja (yang beneran masih ada barangnya).
 */
$pageTitle = 'Laporan Stock';
$activeMenu = 'stock_report';
require __DIR__ . '/includes/header.php';
require_module_access('kontak');

$pdo = db();
$orgId = $org['organization_id'];

$f = [
    'group_location_id' => (int) ($_GET['group_location_id'] ?? 0),
    'location_id' => (int) ($_GET['location_id'] ?? 0),
    'group_product_id' => (int) ($_GET['group_product_id'] ?? 0),
    'brand_id' => (int) ($_GET['brand_id'] ?? 0),
    'pecahan_id' => (int) ($_GET['pecahan_id'] ?? 0),
    'stock_type_id' => (int) ($_GET['stock_type_id'] ?? 0),
    'certificate_code' => trim($_GET['certificate_code'] ?? ''),
    'plu_code' => trim($_GET['plu_code'] ?? ''),
    'status' => trim($_GET['status'] ?? 'in_stock'),
];

$catRows = $pdo->prepare('SELECT id, parent_id, name FROM product_categories WHERE organization_id=? ORDER BY parent_id IS NOT NULL, sort_order, name');
$catRows->execute([$orgId]);
$catRows = $catRows->fetchAll();
$catById = [];
foreach ($catRows as $c) $catById[$c['id']] = $c;
function cat_root_id_r(array $catById, int $id): int
{
    $node = $catById[$id] ?? null;
    while ($node && $node['parent_id']) $node = $catById[$node['parent_id']] ?? null;
    return $node ? (int) $node['id'] : 0;
}
$groupProducts = array_values(array_filter($catRows, fn($c) => !$c['parent_id']));

$locRows = $pdo->prepare('SELECT id, parent_id, name FROM locations WHERE organization_id=? ORDER BY parent_id IS NOT NULL, sort_order, name');
$locRows->execute([$orgId]);
$locRows = $locRows->fetchAll();
$groupLocations = array_values(array_filter($locRows, fn($l) => !$l['parent_id']));

$stockTypes = $pdo->prepare('SELECT id, name FROM stock_types WHERE organization_id=? ORDER BY sort_order, name');
$stockTypes->execute([$orgId]);
$stockTypes = $stockTypes->fetchAll();

// Kumpulin descendant kategori/lokasi kalau filter group dipilih tapi sub-nya enggak,
// biar "Group Product = LM" tetep nyaring semua brand+pecahan di bawahnya.
function descendant_ids(array $rows, int $rootId): array
{
    $ids = [$rootId];
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($rows as $r) {
            if ($r['parent_id'] && in_array((int) $r['parent_id'], $ids, true) && !in_array((int) $r['id'], $ids, true)) {
                $ids[] = (int) $r['id'];
                $changed = true;
            }
        }
    }
    return $ids;
}

$where = ['ii.organization_id = ?'];
$params = [$orgId];

if ($f['status'] !== '' && $f['status'] !== 'all') {
    $where[] = 'ii.status = ?';
    $params[] = $f['status'];
}
if ($f['pecahan_id']) {
    $where[] = 'ii.product_id IN (SELECT id FROM products WHERE category_id = ?)';
    $params[] = $f['pecahan_id'];
} elseif ($f['brand_id']) {
    $ids = descendant_ids($catRows, $f['brand_id']);
    $where[] = 'ii.product_id IN (SELECT id FROM products WHERE category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
    array_push($params, ...$ids);
} elseif ($f['group_product_id']) {
    $ids = descendant_ids($catRows, $f['group_product_id']);
    $where[] = 'ii.product_id IN (SELECT id FROM products WHERE category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
    array_push($params, ...$ids);
}
if ($f['location_id']) {
    $where[] = 'ii.location_id = ?';
    $params[] = $f['location_id'];
} elseif ($f['group_location_id']) {
    $ids = descendant_ids($locRows, $f['group_location_id']);
    $where[] = 'ii.location_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    array_push($params, ...$ids);
}
if ($f['stock_type_id']) {
    $where[] = 'ii.stock_type_id = ?';
    $params[] = $f['stock_type_id'];
}
if ($f['certificate_code'] !== '') {
    $where[] = 'ii.certificate_code LIKE ?';
    $params[] = '%' . $f['certificate_code'] . '%';
}
if ($f['plu_code'] !== '') {
    $where[] = 'ii.plu_code LIKE ?';
    $params[] = '%' . $f['plu_code'] . '%';
}

$sql = "SELECT ii.*, p.name AS product_name, p.unit, st.name AS stock_type_name,
          loc.name AS location_name, gloc.name AS group_location_name
        FROM inventory_items ii
        JOIN products p ON p.id = ii.product_id
        LEFT JOIN stock_types st ON st.id = ii.stock_type_id
        LEFT JOIN locations loc ON loc.id = ii.location_id
        LEFT JOIN locations gloc ON gloc.id = loc.parent_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ii.id DESC LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalWeight = 0;
foreach ($rows as $r) $totalWeight += (float) ($r['weight'] ?? 0);

function cat_breadcrumb_r(array $catById, int $id): string
{
    $parts = [];
    $node = $catById[$id] ?? null;
    while ($node) { array_unshift($parts, $node['name']); $node = $node['parent_id'] ? ($catById[$node['parent_id']] ?? null) : null; }
    return implode(' › ', $parts);
}
$productCatCache = [];
$productCat = function (int $productId) use ($pdo, $orgId, &$productCatCache) {
    if (isset($productCatCache[$productId])) return $productCatCache[$productId];
    $stmt = $pdo->prepare('SELECT category_id FROM products WHERE id=?');
    $stmt->execute([$productId]);
    return $productCatCache[$productId] = (int) $stmt->fetchColumn();
};
?>

<form method="get" class="card" style="margin-bottom:20px;">
  <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:14px;">
    <div class="field" style="margin-bottom:0;">
      <label>Group Lokasi</label>
      <select name="group_location_id">
        <option value="">Semua</option>
        <?php foreach ($groupLocations as $g): ?><option value="<?= $g['id'] ?>" <?= $f['group_location_id'] === (int)$g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Lokasi</label>
      <select name="location_id">
        <option value="">Semua</option>
        <?php foreach ($locRows as $l): if (!$l['parent_id']) continue; ?>
          <option value="<?= $l['id'] ?>" <?= $f['location_id'] === (int)$l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Group Product</label>
      <select name="group_product_id">
        <option value="">Semua</option>
        <?php foreach ($groupProducts as $g): ?><option value="<?= $g['id'] ?>" <?= $f['group_product_id'] === (int)$g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Brand / Jenis Item</label>
      <select name="brand_id">
        <option value="">Semua</option>
        <?php foreach ($catRows as $c): if (!$c['parent_id'] || !empty($catById[$c['parent_id']]['parent_id'])) continue; ?>
          <option value="<?= $c['id'] ?>" <?= $f['brand_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars(cat_breadcrumb_r($catById, (int)$c['id'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Pecahan</label>
      <select name="pecahan_id">
        <option value="">Semua</option>
        <?php foreach ($catRows as $c): if (!$c['parent_id'] || !($catById[$c['parent_id']]['parent_id'] ?? null)) continue; ?>
          <option value="<?= $c['id'] ?>" <?= $f['pecahan_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars(cat_breadcrumb_r($catById, (int)$c['id'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Tipe Stock</label>
      <select name="stock_type_id">
        <option value="">Semua</option>
        <?php foreach ($stockTypes as $t): ?><option value="<?= $t['id'] ?>" <?= $f['stock_type_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Kode Sertifikat</label>
      <input type="text" name="certificate_code" value="<?= htmlspecialchars($f['certificate_code']) ?>" placeholder="cari...">
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Kode PLU</label>
      <input type="text" name="plu_code" value="<?= htmlspecialchars($f['plu_code']) ?>" placeholder="cari...">
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Status</label>
      <select name="status">
        <?php foreach (['in_stock' => 'In Stock', 'in_transit' => 'In Transit', 'melted' => 'Dilebur', 'returned' => 'Diretur', 'sold' => 'Terjual', 'all' => 'Semua Status'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div style="margin-top:14px; display:flex; gap:8px;">
    <button class="btn btn-sm" type="submit">Filter</button>
    <a href="stock-report.php" class="btn btn-sm btn-ghost">Reset</a>
  </div>
</form>

<div class="stat-grid" style="grid-template-columns: repeat(2, 1fr); margin-bottom:20px;">
  <div class="stat-card"><div class="val"><?= count($rows) ?></div><div class="lbl">Jumlah Barang</div></div>
  <div class="stat-card"><div class="val"><?= number_format($totalWeight, 2, ',', '.') ?> gr</div><div class="lbl">Total Berat</div></div>
</div>

<div class="card">
  <table class="data-table">
    <thead><tr><th>PLU</th><th>Produk</th><th>Kategori</th><th>Lokasi</th><th>Tipe Stock</th><th>Kode Sertifikat</th><th class="num">Berat</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['plu_code']) ?></td>
          <td><?= htmlspecialchars($r['product_name']) ?></td>
          <td style="font-size:12px; color:var(--ink-muted);"><?= htmlspecialchars(cat_breadcrumb_r($catById, $productCat((int) $r['product_id']))) ?></td>
          <td><?= htmlspecialchars(($r['group_location_name'] ? $r['group_location_name'] . ' › ' : '') . ($r['location_name'] ?? '-')) ?></td>
          <td><?= htmlspecialchars($r['stock_type_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['certificate_code'] ?? '-') ?></td>
          <td class="num"><?= $r['weight'] !== null ? number_format((float) $r['weight'], 2, ',', '.') . ' gr' : '-' ?></td>
          <td><span class="pill <?= $r['status'] === 'in_stock' ? 'active' : '' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center; color:var(--ink-muted);">Gak ada barang yang cocok sama filter.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
