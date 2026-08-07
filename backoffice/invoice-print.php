<?php
require_once __DIR__ . '/../backoffice-shared/auth.php';
require_once __DIR__ . '/../backoffice-shared/modules.php';
require_login();
$org = require_org();
require_module_access('invoicing');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT i.*, c.name AS contact_name, c.address, u.name AS sales_name
     FROM invoices i JOIN contacts c ON c.id=i.contact_id LEFT JOIN users u ON u.id=i.sales_user_id
     WHERE i.id=? AND i.organization_id=?'
);
$stmt->execute([$id, $org['organization_id']]);
$inv = $stmt->fetch();
if (!$inv) { http_response_code(404); exit('Invoice tidak ditemukan.'); }

$lines = $pdo->prepare('SELECT * FROM invoice_lines WHERE invoice_id=?');
$lines->execute([$id]);
$lines = $lines->fetchAll();

// "Total Penawaran" di sini = grand total Penawaran asal (subtotal - diskon + PPN 11%),
// biar konsisten sama yang ditampilin di quotations.php & invoices.php.
$total = 0;
if ($inv['quotation_id']) {
    $qtStmt = $pdo->prepare('SELECT discount_type, discount_value, (SELECT COALESCE(SUM(qty*unit_price),0) FROM quotation_lines WHERE quotation_id=quotations.id) AS subtotal FROM quotations WHERE id=?');
    $qtStmt->execute([$inv['quotation_id']]);
    $qtRow = $qtStmt->fetch();
    if ($qtRow) {
        $qtSubtotal = (float) $qtRow['subtotal'];
        $qtDisc = $qtRow['discount_type'] === 'percent' ? $qtSubtotal * ((float) $qtRow['discount_value'] / 100) : (float) $qtRow['discount_value'];
        $qtAfterDisc = max(0, $qtSubtotal - $qtDisc);
        $total = $qtAfterDisc + ($qtAfterDisc * 0.11);
    }
} else {
    foreach ($lines as $l) $total += $l['qty'] * $l['unit_price'];
}

$orgFull = $pdo->prepare('SELECT * FROM organizations WHERE id=?');
$orgFull->execute([$org['organization_id']]);
$orgFull = $orgFull->fetch();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Invoice <?= htmlspecialchars($inv['doc_number']) ?></title>
<style>
  body { font-family: Arial, sans-serif; font-size: 13px; color: #1c1a17; max-width: 800px; margin: 30px auto; }
  h1 { font-size: 20px; margin-bottom: 0; }
  .doc-meta { color: #766f60; margin-bottom: 20px; }
  table { width: 100%; border-collapse: collapse; margin-top: 16px; }
  th, td { border-bottom: 1px solid #e2dccd; padding: 8px; text-align: left; }
  th { background: #f6f3ee; font-size: 11px; text-transform: uppercase; }
  .num { text-align: right; }
  .total-row td { font-weight: 700; border-top: 2px solid #1c1a17; }
  .no-print { margin-top: 20px; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body>
  <h1><?= htmlspecialchars($orgFull['legal_name']) ?></h1>
  <div class="doc-meta">
    INVOICE — <?= htmlspecialchars($inv['doc_number']) ?> · <?= htmlspecialchars(date('d M Y', strtotime($inv['created_at']))) ?><br>
    Kepada: <?= htmlspecialchars($inv['contact_name']) ?><?= $inv['address'] ? ' — ' . htmlspecialchars($inv['address']) : '' ?>
    <?php if ($inv['sales_name']): ?><br>Sales: <?= htmlspecialchars($inv['sales_name']) ?><?php endif; ?>
  </div>
  <table>
    <thead><tr><th>Produk</th><th>Tier</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($lines as $l): ?>
      <tr>
        <td><?= htmlspecialchars($l['product_name_snapshot']) ?></td>
        <td><?= htmlspecialchars(ucfirst($l['tier_level_snapshot'])) ?></td>
        <td class="num"><?= rtrim(rtrim(number_format((float) $l['qty'], 2, ',', '.'), '0'), ',') ?></td>
        <td class="num">Rp <?= number_format((float) $l['unit_price'], 0, ',', '.') ?></td>
        <td class="num">Rp <?= number_format((float) $l['qty'] * (float) $l['unit_price'], 0, ',', '.') ?></td>
      </tr>
      <?php endforeach; ?>
      <tr><td colspan="4">Total Penawaran</td><td class="num">Rp <?= number_format($total, 0, ',', '.') ?></td></tr>
      <?php if ($inv['payment_scheme'] === 'dp'): ?>
        <tr><td colspan="4">Skema</td><td class="num">DP <?= $inv['dp_type'] === 'percent' ? htmlspecialchars($inv['dp_value']) . '%' : 'Rp ' . number_format((float) $inv['dp_value'], 0, ',', '.') ?></td></tr>
      <?php endif; ?>
      <tr class="total-row"><td colspan="4">DITAGIHKAN</td><td class="num">Rp <?= number_format((float) $inv['billed_amount'], 0, ',', '.') ?></td></tr>
    </tbody>
  </table>
  <?php if ($inv['terms_snapshot']): ?><div style="margin-top:20px; font-size:12px; color:#3a362f;"><strong>Syarat &amp; Ketentuan:</strong><br><?= nl2br(htmlspecialchars($inv['terms_snapshot'])) ?></div><?php endif; ?>
  <div class="no-print"><button onclick="window.print()">Print / Save PDF</button></div>
</body>
</html>
