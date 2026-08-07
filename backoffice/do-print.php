<?php
require_once __DIR__ . '/../backoffice-shared/auth.php';
require_once __DIR__ . '/../backoffice-shared/modules.php';
require_login();
$org = require_org();
require_module_access('do');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT do_.*, c.name AS contact_name, c.address, inv.doc_number AS invoice_doc_number FROM delivery_orders do_ JOIN contacts c ON c.id=do_.contact_id JOIN invoices inv ON inv.id=do_.invoice_id WHERE do_.id=? AND do_.organization_id=?');
$stmt->execute([$id, $org['organization_id']]);
$d = $stmt->fetch();
if (!$d) { http_response_code(404); exit('Delivery Order tidak ditemukan.'); }

$lines = $pdo->prepare('SELECT * FROM delivery_order_lines WHERE delivery_order_id=?');
$lines->execute([$id]);
$lines = $lines->fetchAll();

$orgFull = $pdo->prepare('SELECT * FROM organizations WHERE id=?');
$orgFull->execute([$org['organization_id']]);
$orgFull = $orgFull->fetch();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Surat Jalan <?= htmlspecialchars($d['doc_number']) ?></title>
<style>
  body { font-family: Arial, sans-serif; font-size: 13px; color: #1c1a17; max-width: 800px; margin: 30px auto; }
  h1 { font-size: 20px; margin-bottom: 0; }
  .doc-meta { color: #766f60; margin-bottom: 20px; }
  table { width: 100%; border-collapse: collapse; margin-top: 16px; }
  th, td { border-bottom: 1px solid #e2dccd; padding: 8px; text-align: left; }
  th { background: #f6f3ee; font-size: 11px; text-transform: uppercase; }
  .num { text-align: right; }
  .no-print { margin-top: 20px; }
  .sign { display:flex; justify-content:space-between; margin-top:60px; }
  .sign div { width: 200px; text-align:center; border-top:1px solid #1c1a17; padding-top:6px; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body>
  <h1><?= htmlspecialchars($orgFull['legal_name']) ?></h1>
  <div class="doc-meta">
    SURAT JALAN — <?= htmlspecialchars($d['doc_number']) ?> · <?= htmlspecialchars(date('d M Y', strtotime($d['created_at']))) ?><br>
    Kepada: <?= htmlspecialchars($d['contact_name']) ?><?= $d['address'] ? ' — ' . htmlspecialchars($d['address']) : '' ?><br>
    Ref. Invoice: <?= htmlspecialchars($d['invoice_doc_number']) ?>
  </div>
  <table>
    <thead><tr><th>Produk</th><th class="num">Qty</th></tr></thead>
    <tbody>
      <?php foreach ($lines as $l): ?>
      <tr><td><?= htmlspecialchars($l['product_name_snapshot']) ?></td><td class="num"><?= rtrim(rtrim(number_format((float) $l['qty'], 2, ',', '.'), '0'), ',') ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="sign">
    <div>Pengirim</div>
    <div>Penerima</div>
  </div>
  <div class="no-print"><button onclick="window.print()">Print / Save PDF</button></div>
</body>
</html>
