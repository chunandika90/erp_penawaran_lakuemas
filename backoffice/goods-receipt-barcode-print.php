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
  /*
   * Ukuran fisik label thermal roll -- ubah 2 angka ini aja kalau roll
   * label yang dipakai beda ukuran (mis. 50mm x 30mm), gak perlu ubah CSS lain.
   * Default 40x30mm dipilih karena ukuran paling umum buat tag perhiasan
   * (printer thermal Xprinter/POS dkk).
   */
  :root { --label-w: 40mm; --label-h: 30mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f2f2f2; }
  .toolbar { max-width: 900px; margin: 0 auto 16px; display: flex; justify-content: space-between; align-items: center; }
  .toolbar button { padding: 8px 16px; font-size: 13px; cursor: pointer; }
  .toolbar .size-note { font-size: 12px; color: #666; }
  .sheet { max-width: 900px; margin: 0 auto; display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-start; }
  .label {
    width: var(--label-w); height: var(--label-h);
    background: #fff; border: 1px solid #999; border-radius: 2px;
    padding: 1.5mm; text-align: center; overflow: hidden;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
  }
  .label .prod { font-size: 8.5px; font-weight: 700; line-height: 1.2; max-height: 2.4em; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
  .label .meta { font-size: 7px; color: #555; margin: 1px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
  .label svg { width: 100%; height: 40%; max-height: 11mm; }
  .label .plu { font-size: 8px; letter-spacing: .5px; font-weight: 600; margin-top: 1px; }
  .empty { text-align: center; color: #777; padding: 40px; }
  @media print {
    @page { size: var(--label-w) var(--label-h); margin: 0; }
    body { background: #fff; padding: 0; }
    .toolbar { display: none; }
    .sheet { display: block; }
    .label { border: none; page-break-after: always; break-after: page; margin: 0; }
    .label:last-child { page-break-after: auto; break-after: auto; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <div>
    Penerimaan <strong><?= htmlspecialchars($gr['doc_number']) ?></strong> — <?= htmlspecialchars($gr['location_name'] ?? '-') ?><?= $gr['vendor_name'] ? ' · ' . htmlspecialchars($gr['vendor_name']) : '' ?> (<?= count($items) ?> barang)
    <div class="size-note">Ukuran label: 40×30mm (thermal roll) — 1 barang = 1 label, langsung print tanpa perlu atur ulang di dialog print.</div>
  </div>
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
