<?php
require_once __DIR__ . '/../backoffice-shared/auth.php';
require_once __DIR__ . '/../backoffice-shared/modules.php';
require_login();
$org = require_org();
require_module_access('penawaran');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT q.*, c.name AS contact_name, c.address, c.phone, c.email, u.name AS sales_name
     FROM quotations q JOIN contacts c ON c.id=q.contact_id LEFT JOIN users u ON u.id=q.sales_user_id
     WHERE q.id=? AND q.organization_id=?'
);
$stmt->execute([$id, $org['organization_id']]);
$q = $stmt->fetch();
if (!$q) { http_response_code(404); exit('Penawaran tidak ditemukan.'); }

$lines = $pdo->prepare(
    'SELECT ql.*, p.photo_path, p.panjang, p.lebar, p.tinggi, p.tinggi_dudukan, p.tinggi_lengan, p.tinggi_sandaran, p.tinggi_kaki
     FROM quotation_lines ql LEFT JOIN products p ON p.id=ql.product_id
     WHERE ql.quotation_id=?'
);
$lines->execute([$id]);
$lines = $lines->fetchAll();
$subtotal = 0;
foreach ($lines as $l) $subtotal += $l['qty'] * $l['unit_price'];

function fmt_mm($v)
{
    return rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',');
}

function dimension_caption(array $row): string
{
    $main = [];
    if ($row['panjang'] !== null) $main[] = 'P' . fmt_mm($row['panjang']);
    if ($row['lebar'] !== null) $main[] = 'L' . fmt_mm($row['lebar']);
    if ($row['tinggi'] !== null) $main[] = 'T' . fmt_mm($row['tinggi']);
    $extra = [];
    if ($row['tinggi_dudukan'] !== null) $extra[] = 'Dudukan ' . fmt_mm($row['tinggi_dudukan']) . 'mm';
    if ($row['tinggi_lengan'] !== null) $extra[] = 'Lengan ' . fmt_mm($row['tinggi_lengan']) . 'mm';
    if ($row['tinggi_sandaran'] !== null) $extra[] = 'Sandaran ' . fmt_mm($row['tinggi_sandaran']) . 'mm';
    if ($row['tinggi_kaki'] !== null) $extra[] = 'Kaki ' . fmt_mm($row['tinggi_kaki']) . 'mm';
    $lines = [];
    if ($main) $lines[] = implode(' x ', $main) . ' mm';
    if ($extra) $lines[] = implode(' · ', $extra);
    return implode('<br>', $lines);
}
$discAmount = $q['discount_type'] === 'percent' ? $subtotal * ((float) $q['discount_value'] / 100) : (float) $q['discount_value'];
$afterDisc = max(0, $subtotal - $discAmount);
$ppn = $afterDisc * 0.11;
$total = $afterDisc + $ppn;

$orgFull = $pdo->prepare('SELECT * FROM organizations WHERE id=?');
$orgFull->execute([$org['organization_id']]);
$orgFull = $orgFull->fetch();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Penawaran <?= htmlspecialchars($q['doc_number']) ?></title>
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
    PENAWARAN — <?= htmlspecialchars($q['doc_number']) ?> · <?= htmlspecialchars(date('d M Y', strtotime($q['created_at']))) ?><br>
    Kepada: <?= htmlspecialchars($q['contact_name']) ?><?= $q['address'] ? ' — ' . htmlspecialchars($q['address']) : '' ?>
    <?php if ($q['sales_name']): ?><br>Sales: <?= htmlspecialchars($q['sales_name']) ?><?php endif; ?>
  </div>
  <table>
    <thead><tr><th>Foto</th><th>Produk</th><th>Tier</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($lines as $l): ?>
      <tr>
        <td style="width:70px;">
          <?php if (!empty($l['photo_path'])): ?>
            <img src="<?= htmlspecialchars($l['photo_path']) ?>" alt="" style="width:60px; height:60px; object-fit:cover; border-radius:4px; border:1px solid #e2dccd;">
          <?php else: ?>
            <div style="width:60px; height:60px; border-radius:4px; border:1px dashed #cfc8b8;"></div>
          <?php endif; ?>
        </td>
        <td>
          <?= htmlspecialchars($l['product_name_snapshot']) ?><?= $l['custom_note'] ? '<br><small>' . htmlspecialchars($l['custom_note']) . '</small>' : '' ?>
          <?php $dimCaption = dimension_caption($l); if ($dimCaption): ?><br><small style="color:#766f60;"><?= $dimCaption ?></small><?php endif; ?>
        </td>
        <td><?= htmlspecialchars(ucfirst($l['tier_level_snapshot'])) ?></td>
        <td class="num"><?= rtrim(rtrim(number_format((float) $l['qty'], 2, ',', '.'), '0'), ',') ?></td>
        <td class="num">Rp <?= number_format((float) $l['unit_price'], 0, ',', '.') ?></td>
        <td class="num">Rp <?= number_format((float) $l['qty'] * (float) $l['unit_price'], 0, ',', '.') ?></td>
      </tr>
      <?php endforeach; ?>
      <tr><td colspan="5">Subtotal</td><td class="num">Rp <?= number_format($subtotal, 0, ',', '.') ?></td></tr>
      <?php if ($discAmount > 0): ?><tr><td colspan="5">Diskon</td><td class="num">- Rp <?= number_format($discAmount, 0, ',', '.') ?></td></tr><?php endif; ?>
      <tr><td colspan="5">PPN 11%</td><td class="num">Rp <?= number_format($ppn, 0, ',', '.') ?></td></tr>
      <tr class="total-row"><td colspan="5">TOTAL</td><td class="num">Rp <?= number_format($total, 0, ',', '.') ?></td></tr>
    </tbody>
  </table>
  <?php if ($q['notes']): ?><p><strong>Catatan:</strong> <?= nl2br(htmlspecialchars($q['notes'])) ?></p><?php endif; ?>
  <?php if ($q['terms_snapshot']): ?><div style="margin-top:20px; font-size:12px; color:#3a362f;"><strong>Syarat &amp; Ketentuan:</strong><br><?= nl2br(htmlspecialchars($q['terms_snapshot'])) ?></div><?php endif; ?>
  <div class="no-print"><button onclick="window.print()">Print / Save PDF</button></div>
</body>
</html>
