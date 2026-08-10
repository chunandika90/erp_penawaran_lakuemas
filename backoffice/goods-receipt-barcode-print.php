<?php
/**
 * Cetak label barcode PLU buat barang-barang dari 1 Penerimaan — dicetak,
 * digunting, ditempel fisik ke barangnya. Halaman standalone (bukan lewat
 * header.php/footer.php) karena ini buat di-print langsung, gak butuh sidebar.
 * Barcode di-render di browser pakai JsBarcode (di-vendor lokal, gak CDN) —
 * value-nya = plu_code (Code128, alfanumerik).
 */
require_once __DIR__ . '/../backoffice-shared/auth.php';
$user = require_login();
$org = require_org();
require_module_access('kontak');

$pdo = db();
$grId = (int) ($_GET['gr_id'] ?? 0);

$gr = $pdo->prepare(
    "SELECT gr.*, l.name AS location_name, v.name AS vendor_name
     FROM gold_goods_receipts gr
     LEFT JOIN locations l ON l.id = gr.location_id
     LEFT JOIN contacts v ON v.id = gr.vendor_id
     WHERE gr.id=? AND gr.organization_id=?"
);
$gr->execute([$grId, $org['organization_id']]);
$gr = $gr->fetch();
if (!$gr) { http_response_code(404); die('Penerimaan tidak ditemukan.'); }

$items = $pdo->prepare(
    "SELECT ii.plu_code, ii.certificate_code, ii.weight, p.name AS product_name
     FROM inventory_items ii JOIN products p ON p.id = ii.product_id
     WHERE ii.source_type='goods_receipt' AND ii.source_id=? ORDER BY ii.id"
);
$items->execute([$grId]);
$items = $items->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Cetak Barcode — <?= htmlspecialchars($gr['doc_number']) ?></title>
<script src="assets/js/vendor/jsbarcode.min.js"></script>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f2f2f2; }
  .toolbar { max-width: 900px; margin: 0 auto 16px; display: flex; justify-content: space-between; align-items: center; }
  .toolbar button { padding: 8px 16px; font-size: 13px; cursor: pointer; }
  .sheet { max-width: 900px; margin: 0 auto; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
  .label { background: #fff; border: 1px solid #999; border-radius: 4px; padding: 8px; text-align: center; }
  .label .prod { font-size: 10.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .label .meta { font-size: 9px; color: #555; margin: 2px 0 4px; }
  .label svg { width: 100%; height: 36px; }
  .label .plu { font-size: 10px; letter-spacing: 1px; font-weight: 600; margin-top: 2px; }
  .empty { text-align: center; color: #777; padding: 40px; }
  @media print {
    body { background: #fff; padding: 0; }
    .toolbar { display: none; }
    .sheet { gap: 4mm; }
    .label { border: 1px dashed #aaa; break-inside: avoid; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <div>Penerimaan <strong><?= htmlspecialchars($gr['doc_number']) ?></strong> — <?= htmlspecialchars($gr['location_name'] ?? '-') ?><?= $gr['vendor_name'] ? ' · ' . htmlspecialchars($gr['vendor_name']) : '' ?> (<?= count($items) ?> barang)</div>
  <button type="button" onclick="window.print()">Cetak</button>
</div>

<?php if (!$items): ?>
  <div class="empty">Belum ada barang di penerimaan ini.</div>
<?php else: ?>
<div class="sheet">
  <?php foreach ($items as $it): ?>
    <div class="label">
      <div class="prod"><?= htmlspecialchars($it['product_name']) ?></div>
      <div class="meta"><?= $it['weight'] !== null ? number_format((float) $it['weight'], 2, ',', '.') . ' gr' : '' ?><?= $it['certificate_code'] ? ' · ' . htmlspecialchars($it['certificate_code']) : '' ?></div>
      <svg class="barcode" data-value="<?= htmlspecialchars($it['plu_code']) ?>"></svg>
      <div class="plu"><?= htmlspecialchars($it['plu_code']) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.barcode').forEach(function (el) {
  JsBarcode(el, el.getAttribute('data-value'), {
    format: 'CODE128', displayValue: false, height: 34, margin: 0, width: 1.4
  });
});
</script>
</body>
</html>
