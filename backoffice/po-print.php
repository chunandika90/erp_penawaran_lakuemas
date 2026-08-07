<?php
require_once __DIR__ . '/../backoffice-shared/auth.php';
require_once __DIR__ . '/../backoffice-shared/modules.php';
require_login();
$org = require_org();
require_module_access('po');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT po.*, c.name AS vendor_name, c.address FROM purchase_orders po LEFT JOIN contacts c ON c.id=po.vendor_id WHERE po.id=? AND po.organization_id=?');
$stmt->execute([$id, $org['organization_id']]);
$po = $stmt->fetch();
if (!$po) { http_response_code(404); exit('PO tidak ditemukan.'); }

$lines = $pdo->prepare('SELECT * FROM po_lines WHERE po_id=?');
$lines->execute([$id]);
$lines = $lines->fetchAll();
$total = 0;
foreach ($lines as $l) $total += $l['qty'] * $l['unit_cost'];

$orgFull = $pdo->prepare('SELECT * FROM organizations WHERE id=?');
$orgFull->execute([$org['organization_id']]);
$orgFull = $orgFull->fetch();

$poTypeLabels = ['bahan_baku' => 'Bahan Baku', 'jasa_produksi' => 'Jasa Produksi', 'barang_jadi' => 'Barang Jadi'];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>PO <?= htmlspecialchars($po['doc_number']) ?></title>
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
    PURCHASE ORDER (<?= htmlspecialchars($poTypeLabels[$po['po_type']] ?? $po['po_type']) ?>) — <?= htmlspecialchars($po['doc_number']) ?> · <?= htmlspecialchars(date('d M Y', strtotime($po['created_at']))) ?><br>
    Kepada Vendor: <?= htmlspecialchars($po['vendor_name'] ?? 'Belum ditentukan') ?><?= $po['address'] ? ' — ' . htmlspecialchars($po['address']) : '' ?>
  </div>
  <table>
    <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Cost/unit</th><th class="num">Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($lines as $l): ?>
      <tr>
        <td><?= htmlspecialchars($l['item_name']) ?></td>
        <td class="num"><?= rtrim(rtrim(number_format((float) $l['qty'], 2, ',', '.'), '0'), ',') ?></td>
        <td class="num">Rp <?= number_format((float) $l['unit_cost'], 0, ',', '.') ?></td>
        <td class="num">Rp <?= number_format((float) $l['qty'] * (float) $l['unit_cost'], 0, ',', '.') ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total-row"><td colspan="3">TOTAL</td><td class="num">Rp <?= number_format($total, 0, ',', '.') ?></td></tr>
    </tbody>
  </table>
  <div class="no-print"><button onclick="window.print()">Print / Save PDF</button></div>
</body>
</html>
