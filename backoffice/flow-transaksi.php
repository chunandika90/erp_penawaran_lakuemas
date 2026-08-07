<?php
/**
 * Flow board transaksi Lakuemas — peta 8 tahap (Penawaran s/d Retur Supplier).
 * Sebagian reuse modul existing (Penawaran/PO/Laporan), sebagian baru
 * (Penerimaan Barang, Transfer, Lebur, Retur). Yang belum dibangun ditandai
 * "Segera" biar kelihatan jelas mana yang udah jalan.
 */
$pageTitle = 'Flow Transaksi';
$activeMenu = 'flow_transaksi';
require __DIR__ . '/includes/header.php';
require_module_access('kontak');

$pdo = db();
$orgId = $org['organization_id'];

function count_rows(PDO $pdo, string $sql, int $orgId): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$orgId]);
    return (int) $stmt->fetchColumn();
}

$stages = [
    ['title' => 'Penawaran', 'href' => 'quotations.php', 'desc' => 'Quotation ke customer', 'count' => count_rows($pdo, 'SELECT COUNT(*) FROM quotations WHERE organization_id=?', $orgId), 'ready' => true],
    ['title' => 'PO', 'href' => 'purchase-orders.php', 'desc' => 'Purchase order ke supplier', 'count' => count_rows($pdo, 'SELECT COUNT(*) FROM purchase_orders WHERE organization_id=?', $orgId), 'ready' => true],
    ['title' => 'Penerimaan Barang', 'href' => 'goods-receipt-gold.php', 'desc' => 'Barang masuk + kode sertifikat & PLU', 'count' => count_rows($pdo, 'SELECT COUNT(*) FROM gold_goods_receipts WHERE organization_id=?', $orgId), 'ready' => true],
    ['title' => 'Transfer / Terima Transfer', 'href' => 'stock-transfer.php', 'desc' => 'Pindah lokasi, 1 dokumen 2 status', 'count' => count_rows($pdo, 'SELECT COUNT(*) FROM stock_transfers WHERE organization_id=?', $orgId), 'ready' => true],
    ['title' => 'Laporan Stock', 'href' => 'stock-report.php', 'desc' => '8 filter: lokasi, group product, tipe stock, dll', 'count' => null, 'ready' => true],
    ['title' => 'Lebur Barang', 'href' => 'melting.php', 'desc' => 'Keluarin barang jadi, masuk stock raw gold', 'count' => count_rows($pdo, 'SELECT COUNT(*) FROM melting_batches WHERE organization_id=?', $orgId), 'ready' => true],
    ['title' => 'Retur Supplier', 'href' => 'supplier-return.php', 'desc' => 'Retur nempel ke Penerimaan asal', 'count' => count_rows($pdo, 'SELECT COUNT(*) FROM supplier_returns WHERE organization_id=?', $orgId), 'ready' => true],
];
?>

<p style="color:var(--ink-muted); font-size:13px; margin-top:-8px;">
  Peta alur transaksi Lakuemas. Kolom yang masih "Segera" belum dibangun — klik kolom yang udah aktif buat masuk ke modulnya.
</p>

<div class="flow-board-wrap">
  <div class="flow-board" style="overflow-x:auto;">
    <?php foreach ($stages as $s): ?>
      <div class="flow-col" style="flex:0 0 220px; width:220px;">
        <div class="flow-col-head">
          <span><?= htmlspecialchars($s['title']) ?></span>
          <?php if ($s['ready']): ?><span class="flow-count"><?= $s['count'] ?></span><?php else: ?><span class="pill">Segera</span><?php endif; ?>
        </div>
        <div style="font-size:11.5px; color:var(--ink-muted); margin-bottom:10px; line-height:1.5;"><?= htmlspecialchars($s['desc']) ?></div>
        <?php if ($s['ready']): ?>
          <a href="<?= htmlspecialchars($s['href']) ?>" class="flow-add">Buka →</a>
        <?php else: ?>
          <div class="flow-empty">Belum dibangun</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
