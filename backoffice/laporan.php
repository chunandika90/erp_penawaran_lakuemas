<?php
$pageTitle = 'Laporan HPP / COGS';
$activeMenu = 'laporan';
require __DIR__ . '/includes/header.php';
require_module_access('laporan');

$pdo = db();

$showAll = !empty($_GET['all']);
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$dateWhere = $showAll ? '' : ' AND DATE(do_.created_at) BETWEEN ? AND ?';
$dateParams = $showAll ? [] : [$dateFrom, $dateTo];

// COGS = qty terkirim (Delivery Order) x unit_cost_snapshot (HPP FIFO saat DO dibuat).
// Revenue = qty terkirim yang sama x harga jual di invoice_lines (baris asalnya) — biar
// perbandingan apple-to-apple: cuma barang yang SUDAH terkirim yang dihitung margin-nya.
$sql = 'SELECT
    dol.product_name_snapshot,
    SUM(dol.qty) AS qty_shipped,
    SUM(dol.qty * il.unit_price) AS revenue,
    SUM(dol.qty * dol.unit_cost_snapshot) AS cogs
  FROM delivery_order_lines dol
  JOIN delivery_orders do_ ON do_.id = dol.delivery_order_id
  JOIN invoice_lines il ON il.id = dol.invoice_line_id
  WHERE do_.organization_id = ? AND do_.status != "void"' . $dateWhere . '
  GROUP BY dol.product_name_snapshot
  ORDER BY revenue DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$org['organization_id']], $dateParams));
$rows = $stmt->fetchAll();

$totalRevenue = array_sum(array_column($rows, 'revenue'));
$totalCogs = array_sum(array_column($rows, 'cogs'));
$grossProfit = $totalRevenue - $totalCogs;
$margin = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0;

// Biaya operasional flat 15% dari Gross Profit — bukan dari Revenue.
$operationalCost = $grossProfit * 0.15;
$netProfit = $grossProfit - $operationalCost;

// Performa Sales — basisnya Invoice (billed_amount, status issued/paid), BUKAN nunggu
// Delivery Order kayak sebelumnya. Sales bisa jual banyak sebelum barang kekirim; laporan
// performa harus kelihatan dari status invoice, bukan status pengiriman.
$invoiceDateWhere = $showAll ? '' : ' AND DATE(i.created_at) BETWEEN ? AND ?';
$salesSql = 'SELECT
    COALESCE(u.name, "Tanpa Sales") AS sales_name,
    COUNT(*) AS jumlah_invoice,
    SUM(i.billed_amount) AS revenue
  FROM invoices i
  LEFT JOIN users u ON u.id = i.sales_user_id
  WHERE i.organization_id = ? AND i.status IN ("issued","paid")' . $invoiceDateWhere . '
  GROUP BY i.sales_user_id
  ORDER BY revenue DESC';
$salesStmt = $pdo->prepare($salesSql);
$salesStmt->execute(array_merge([$org['organization_id']], $dateParams));
$salesRows = $salesStmt->fetchAll();

// Komisi Associate — 3% dari billed_amount invoice yang SUDAH LUNAS (paid) di periode ini,
// dipotong PPh 23 sesuai tipe associate (badan 2%, perorangan 2.5%) kalau subject_to_pph aktif.
const COMMISSION_RATE = 0.03;
const PPH_RATE_BADAN = 0.02;
const PPH_RATE_PERORANGAN = 0.025;

$commissionSql = 'SELECT
    COALESCE(u.name, "Tanpa Sales") AS sales_name,
    COALESCE(u.entity_type, "perorangan") AS entity_type,
    COALESCE(u.subject_to_pph, 1) AS subject_to_pph,
    COUNT(*) AS jumlah_invoice,
    SUM(i.billed_amount) AS revenue
  FROM invoices i
  LEFT JOIN users u ON u.id = i.sales_user_id
  WHERE i.organization_id = ? AND i.status = "paid"' . $invoiceDateWhere . '
  GROUP BY i.sales_user_id
  ORDER BY revenue DESC';
$commissionStmt = $pdo->prepare($commissionSql);
$commissionStmt->execute(array_merge([$org['organization_id']], $dateParams));
$commissionRows = array_map(function ($r) {
    $gross = (float) $r['revenue'] * COMMISSION_RATE;
    $pphRate = 0.0;
    if ($r['subject_to_pph']) {
        $pphRate = $r['entity_type'] === 'badan' ? PPH_RATE_BADAN : PPH_RATE_PERORANGAN;
    }
    $pphAmount = $gross * $pphRate;
    $r['commission_gross'] = $gross;
    $r['pph_rate'] = $pphRate;
    $r['pph_amount'] = $pphAmount;
    $r['commission_net'] = $gross - $pphAmount;
    return $r;
}, $commissionStmt->fetchAll());
?>

<form method="get" class="txn-detail-header" style="flex-wrap:wrap; gap:10px;">
  <div style="font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-muted);">
    <?= $showAll ? 'Semua Periode' : 'Periode ' . htmlspecialchars(date('d M Y', strtotime($dateFrom))) . ' – ' . htmlspecialchars(date('d M Y', strtotime($dateTo))) ?>
  </div>
  <div class="txn-detail-actions">
    <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>">
    <span style="color:var(--ink-muted);">s/d</span>
    <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>">
    <button type="submit" class="btn btn-sm">Filter</button>
    <a class="btn btn-sm btn-ghost" href="laporan.php">Bulan Ini</a>
    <a class="btn btn-sm btn-ghost" href="laporan.php?all=1">Semua Periode</a>
  </div>
</form>

<div class="stat-grid">
  <div class="stat-card"><div class="val">Rp <?= number_format($totalRevenue, 0, ',', '.') ?></div><div class="lbl">Revenue (terkirim)</div></div>
  <div class="stat-card"><div class="val">Rp <?= number_format($totalCogs, 0, ',', '.') ?></div><div class="lbl">HPP / COGS</div></div>
  <div class="stat-card"><div class="val">Rp <?= number_format($grossProfit, 0, ',', '.') ?></div><div class="lbl">Gross Profit</div></div>
  <div class="stat-card"><div class="val"><?= number_format($margin, 1) ?>%</div><div class="lbl">Margin</div></div>
  <div class="stat-card"><div class="val">Rp <?= number_format($operationalCost, 0, ',', '.') ?></div><div class="lbl">Biaya Operasional (15%)</div></div>
  <div class="stat-card"><div class="val">Rp <?= number_format($netProfit, 0, ',', '.') ?></div><div class="lbl">Net Profit</div></div>
</div>

<div class="card">
  <table class="data-table">
    <thead><tr><th>Produk</th><th class="num">Qty Terkirim</th><th class="num">Revenue</th><th class="num">HPP</th><th class="num">Margin</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): $rowMargin = $r['revenue'] > 0 ? (($r['revenue'] - $r['cogs']) / $r['revenue']) * 100 : 0; ?>
        <tr>
          <td><?= htmlspecialchars($r['product_name_snapshot']) ?></td>
          <td class="num"><?= rtrim(rtrim(number_format((float) $r['qty_shipped'], 2, ',', '.'), '0'), ',') ?></td>
          <td class="num">Rp <?= number_format((float) $r['revenue'], 0, ',', '.') ?></td>
          <td class="num">Rp <?= number_format((float) $r['cogs'], 0, ',', '.') ?></td>
          <td class="num"><?= number_format($rowMargin, 1) ?>%</td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" style="text-align:center; color:var(--ink-muted);">Belum ada Delivery Order pada periode ini.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<h3 style="margin-top:28px;">Performa Sales</h3>
<p style="font-size:12px; color:var(--ink-muted); margin-top:-6px; margin-bottom:10px;">
  Basis: Invoice yang sudah diterbitkan (status Issued/Paid) — bukan nunggu barang terkirim.
</p>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Sales</th><th class="num">Jumlah Invoice</th><th class="num">Revenue (Billed)</th></tr></thead>
    <tbody>
      <?php foreach ($salesRows as $sr): ?>
        <tr>
          <td><?= htmlspecialchars($sr['sales_name']) ?></td>
          <td class="num"><?= (int) $sr['jumlah_invoice'] ?></td>
          <td class="num">Rp <?= number_format((float) $sr['revenue'], 0, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$salesRows): ?><tr><td colspan="3" style="text-align:center; color:var(--ink-muted);">Belum ada data.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<h3 style="margin-top:28px;">Komisi Associate</h3>
<p style="font-size:12px; color:var(--ink-muted); margin-top:-6px; margin-bottom:10px;">
  3% dari invoice yang statusnya sudah Paid (lunas) di periode ini. Dipotong PPh 23 (Badan 2%, Perorangan 2.5%) kalau associate-nya ditandai kena PPh — atur di menu <a href="users.php">Admin User</a>.
</p>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Associate</th><th>Tipe</th><th class="num">Jumlah Invoice Lunas</th><th class="num">Revenue (Paid)</th><th class="num">Komisi Kotor (3%)</th><th class="num">PPh 23</th><th class="num">Komisi Bersih</th></tr></thead>
    <tbody>
      <?php foreach ($commissionRows as $cr): ?>
        <tr>
          <td><?= htmlspecialchars($cr['sales_name']) ?></td>
          <td><span class="pill"><?= $cr['entity_type'] === 'badan' ? 'BADAN' : 'PERORANGAN' ?></span><?= !$cr['subject_to_pph'] ? ' <span class="pill">BEBAS PPh</span>' : '' ?></td>
          <td class="num"><?= (int) $cr['jumlah_invoice'] ?></td>
          <td class="num">Rp <?= number_format((float) $cr['revenue'], 0, ',', '.') ?></td>
          <td class="num">Rp <?= number_format($cr['commission_gross'], 0, ',', '.') ?></td>
          <td class="num"><?= $cr['pph_rate'] > 0 ? '−Rp ' . number_format($cr['pph_amount'], 0, ',', '.') . ' (' . ($cr['pph_rate'] * 100) . '%)' : '—' ?></td>
          <td class="num">Rp <?= number_format($cr['commission_net'], 0, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$commissionRows): ?><tr><td colspan="7" style="text-align:center; color:var(--ink-muted);">Belum ada invoice lunas di periode ini.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
