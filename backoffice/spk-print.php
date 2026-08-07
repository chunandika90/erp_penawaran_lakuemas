<?php
require_once __DIR__ . '/../backoffice-shared/auth.php';
require_once __DIR__ . '/../backoffice-shared/modules.php';
require_login();
$org = require_org();
require_module_access('spk');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT s.*, c.name AS vendor_name, c.address, p.name AS product_name, po.doc_number AS po_doc_number
     FROM spk s
     JOIN contacts c ON c.id=s.vendor_id
     JOIN products p ON p.id=s.product_id
     LEFT JOIN purchase_orders po ON po.id=s.po_id
     WHERE s.id=? AND s.organization_id=?'
);
$stmt->execute([$id, $org['organization_id']]);
$s = $stmt->fetch();
if (!$s) { http_response_code(404); exit('SPK tidak ditemukan.'); }

$materials = $pdo->prepare('SELECT * FROM spk_materials WHERE spk_id=?');
$materials->execute([$id]);
$materials = $materials->fetchAll();
$materialCost = 0;
foreach ($materials as $m) $materialCost += $m['qty'] * $m['unit_cost'];
$totalCost = $materialCost + (float) $s['assembly_fee'];

$orgFull = $pdo->prepare('SELECT * FROM organizations WHERE id=?');
$orgFull->execute([$org['organization_id']]);
$orgFull = $orgFull->fetch();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>SPK <?= htmlspecialchars($s['doc_number']) ?></title>
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
    SURAT PERINTAH KERJA (MANUFAKTUR/RAKIT) — <?= htmlspecialchars($s['doc_number']) ?> · <?= htmlspecialchars(date('d M Y', strtotime($s['created_at']))) ?><br>
    Vendor Pelaksana: <?= htmlspecialchars($s['vendor_name']) ?><?= $s['address'] ? ' — ' . htmlspecialchars($s['address']) : '' ?><br>
    Produk Target: <?= htmlspecialchars($s['product_name']) ?> — Qty Hasil: <?= rtrim(rtrim(number_format((float) $s['output_qty'], 2, ',', '.'), '0'), ',') ?><br>
    <?php if ($s['po_doc_number']): ?>PO Terkait: <?= htmlspecialchars($s['po_doc_number']) ?><br><?php endif; ?>
    Estimasi Selesai: <?= $s['estimated_finish'] ? htmlspecialchars(date('d M Y', strtotime($s['estimated_finish']))) : '—' ?>
  </div>
  <table>
    <thead><tr><th>Material Dikirim</th><th class="num">Qty</th><th class="num">Cost/unit</th><th class="num">Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($materials as $m): ?>
      <tr>
        <td><?= htmlspecialchars($m['material_name_snapshot']) ?></td>
        <td class="num"><?= rtrim(rtrim(number_format((float) $m['qty'], 2, ',', '.'), '0'), ',') ?></td>
        <td class="num">Rp <?= number_format((float) $m['unit_cost'], 0, ',', '.') ?></td>
        <td class="num">Rp <?= number_format((float) $m['qty'] * (float) $m['unit_cost'], 0, ',', '.') ?></td>
      </tr>
      <?php endforeach; ?>
      <tr><td colspan="3">Jasa Rakit</td><td class="num">Rp <?= number_format((float) $s['assembly_fee'], 0, ',', '.') ?></td></tr>
      <tr class="total-row"><td colspan="3">TOTAL COST MANUFAKTUR</td><td class="num">Rp <?= number_format($totalCost, 0, ',', '.') ?></td></tr>
    </tbody>
  </table>
  <div class="no-print"><button onclick="window.print()">Print / Save PDF</button></div>
</body>
</html>
