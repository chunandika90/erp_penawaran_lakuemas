<?php
require_once __DIR__ . '/../backoffice-shared/auth.php';
require_once __DIR__ . '/../backoffice-shared/modules.php';
require_login();
$org = require_org();
require_module_access('kuitansi');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT k.*, i.doc_number AS invoice_doc_number, c.name AS contact_name, c.address
     FROM kuitansi k JOIN invoices i ON i.id=k.invoice_id JOIN contacts c ON c.id=i.contact_id
     WHERE k.id=? AND k.organization_id=?'
);
$stmt->execute([$id, $org['organization_id']]);
$k = $stmt->fetch();
if (!$k) { http_response_code(404); exit('Kuitansi tidak ditemukan.'); }

$orgFull = $pdo->prepare('SELECT * FROM organizations WHERE id=?');
$orgFull->execute([$org['organization_id']]);
$orgFull = $orgFull->fetch();

$paymentLabels = ['dp' => 'Uang Muka (DP)', 'termin' => 'Termin', 'pelunasan' => 'Pelunasan'];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Kuitansi <?= htmlspecialchars($k['doc_number']) ?></title>
<style>
  body { font-family: Arial, sans-serif; font-size: 13px; color: #1c1a17; max-width: 640px; margin: 30px auto; }
  h1 { font-size: 20px; margin-bottom: 0; }
  .doc-meta { color: #766f60; margin-bottom: 20px; }
  .amount-box { border: 2px solid #1c1a17; padding: 20px; text-align: center; margin: 24px 0; }
  .amount-box .amount { font-size: 28px; font-weight: 700; }
  .no-print { margin-top: 20px; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body>
  <h1><?= htmlspecialchars($orgFull['legal_name']) ?></h1>
  <div class="doc-meta">
    KUITANSI — <?= htmlspecialchars($k['doc_number']) ?> · <?= htmlspecialchars(date('d M Y', strtotime($k['paid_at']))) ?><br>
    Diterima dari: <?= htmlspecialchars($k['contact_name']) ?><?= $k['address'] ? ' — ' . htmlspecialchars($k['address']) : '' ?><br>
    Untuk pembayaran Invoice: <?= htmlspecialchars($k['invoice_doc_number']) ?> (<?= htmlspecialchars($paymentLabels[$k['payment_type']] ?? $k['payment_type']) ?>)
  </div>
  <div class="amount-box">
    <div style="font-size:11px; text-transform:uppercase; color:#766f60;">Jumlah Diterima</div>
    <div class="amount">Rp <?= number_format((float) $k['amount'], 0, ',', '.') ?></div>
  </div>
  <div class="no-print"><button onclick="window.print()">Print / Save PDF</button></div>
</body>
</html>
