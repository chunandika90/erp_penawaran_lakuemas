<?php
/**
 * Cek Barang (PLU) — masukin/scan kode PLU, keluar spec barangnya + histori
 * lengkap (ledger): dari mana asalnya, udah pernah ditransfer ke mana,
 * kejual/kelebur/keretur kapan. Ngumpulin dari semua tabel yang nunjuk ke
 * inventory_item_id ini, disortir jadi 1 timeline.
 */
$pageTitle = 'Cek Barang (PLU)';
$activeMenu = 'item_lookup';
require __DIR__ . '/includes/header.php';
require_module_access('kontak');

$pdo = db();
$pluCode = trim($_GET['plu_code'] ?? '');
$item = null;
$timeline = [];

if ($pluCode !== '') {
    $stmt = $pdo->prepare(
        "SELECT ii.*, p.name AS product_name, p.unit, l.name AS location_name, st.name AS stock_type_name, pr.name AS project_name
         FROM inventory_items ii
         JOIN products p ON p.id = ii.product_id
         LEFT JOIN locations l ON l.id = ii.location_id
         LEFT JOIN stock_types st ON st.id = ii.stock_type_id
         LEFT JOIN projects pr ON pr.id = ii.project_id
         WHERE ii.organization_id=? AND ii.plu_code=?"
    );
    $stmt->execute([$org['organization_id'], $pluCode]);
    $item = $stmt->fetch() ?: null;

    if ($item) {
        $itemId = (int) $item['id'];

        // Asal barang.
        if ($item['source_type'] === 'goods_receipt' && $item['source_id']) {
            $gr = $pdo->prepare(
                "SELECT gr.doc_number, gr.received_at, v.name AS vendor_name, l.name AS location_name
                 FROM gold_goods_receipts gr LEFT JOIN contacts v ON v.id=gr.vendor_id LEFT JOIN locations l ON l.id=gr.location_id
                 WHERE gr.id=?"
            );
            $gr->execute([$item['source_id']]);
            $gr = $gr->fetch();
            if ($gr) {
                $timeline[] = ['at' => $gr['received_at'], 'label' => 'Penerimaan Barang', 'detail' => $gr['doc_number'] . ($gr['vendor_name'] ? ' — dari ' . $gr['vendor_name'] : '') . ($gr['location_name'] ? ' → ' . $gr['location_name'] : ''), 'href' => 'goods-receipt-gold.php'];
            }
            if ($item['po_line_id']) {
                $poLine = $pdo->prepare(
                    "SELECT po.doc_number, po.created_at FROM purchase_order_gold_lines pgl JOIN purchase_orders_gold po ON po.id=pgl.po_id WHERE pgl.id=?"
                );
                $poLine->execute([$item['po_line_id']]);
                $poLine = $poLine->fetch();
                if ($poLine) {
                    $timeline[] = ['at' => $poLine['created_at'], 'label' => 'Sumber PO', 'detail' => $poLine['doc_number'], 'href' => 'purchase-order-gold.php'];
                }
            }
        } elseif ($item['source_type'] === 'melting_output' && $item['source_id']) {
            $mb = $pdo->prepare("SELECT doc_number, melted_at FROM melting_batches WHERE id=?");
            $mb->execute([$item['source_id']]);
            $mb = $mb->fetch();
            if ($mb) {
                $timeline[] = ['at' => $mb['melted_at'], 'label' => 'Hasil Lebur Barang', 'detail' => $mb['doc_number'], 'href' => 'melting.php'];
            }
        } elseif ($item['source_type'] === 'adjustment') {
            $timeline[] = ['at' => $item['created_at'], 'label' => 'Penyesuaian Stock (Adjustment)', 'detail' => '-', 'href' => null];
        }

        // Dipakai buat bahan lebur (item ini KELUAR karena dilebur).
        $meltIn = $pdo->prepare(
            "SELECT mb.doc_number, mb.melted_at FROM melting_batch_lines mbl JOIN melting_batches mb ON mb.id=mbl.melting_batch_id WHERE mbl.inventory_item_id=?"
        );
        $meltIn->execute([$itemId]);
        foreach ($meltIn->fetchAll() as $r) {
            $timeline[] = ['at' => $r['melted_at'], 'label' => 'Dilebur (jadi bahan)', 'detail' => $r['doc_number'], 'href' => 'melting.php'];
        }

        // Transfer lokasi.
        $tr = $pdo->prepare(
            "SELECT t.doc_number, t.status, t.sent_at, t.received_at, fl.name AS from_name, tl.name AS to_name
             FROM stock_transfer_lines stl JOIN stock_transfers t ON t.id=stl.stock_transfer_id
             LEFT JOIN locations fl ON fl.id=t.from_location_id LEFT JOIN locations tl ON tl.id=t.to_location_id
             WHERE stl.inventory_item_id=?"
        );
        $tr->execute([$itemId]);
        foreach ($tr->fetchAll() as $r) {
            $timeline[] = ['at' => $r['sent_at'], 'label' => 'Transfer Stock', 'detail' => $r['doc_number'] . ' — ' . ($r['from_name'] ?? '-') . ' → ' . ($r['to_name'] ?? '-') . ' (' . strtoupper($r['status']) . ')', 'href' => 'stock-transfer.php'];
        }

        // Penjualan.
        $sale = $pdo->prepare(
            "SELECT sg.doc_number, sg.sold_at, sgl.unit_price, c.name AS contact_name
             FROM sales_gold_lines sgl JOIN sales_gold sg ON sg.id=sgl.sale_id LEFT JOIN contacts c ON c.id=sg.contact_id
             WHERE sgl.inventory_item_id=?"
        );
        $sale->execute([$itemId]);
        foreach ($sale->fetchAll() as $r) {
            $timeline[] = ['at' => $r['sold_at'], 'label' => 'Terjual', 'detail' => $r['doc_number'] . ' — ' . ($r['contact_name'] ?? '-') . ' @ Rp ' . number_format((float) $r['unit_price'], 0, ',', '.'), 'href' => 'sale-gold.php'];
        }

        // Retur supplier.
        $ret = $pdo->prepare(
            "SELECT sr.doc_number, sr.returned_at, v.name AS vendor_name
             FROM supplier_return_lines srl JOIN supplier_returns sr ON sr.id=srl.supplier_return_id LEFT JOIN contacts v ON v.id=sr.vendor_id
             WHERE srl.inventory_item_id=?"
        );
        $ret->execute([$itemId]);
        foreach ($ret->fetchAll() as $r) {
            $timeline[] = ['at' => $r['returned_at'], 'label' => 'Retur ke Supplier', 'detail' => $r['doc_number'] . ($r['vendor_name'] ? ' — ' . $r['vendor_name'] : ''), 'href' => 'supplier-return.php'];
        }

        usort($timeline, fn($a, $b) => strtotime($a['at']) <=> strtotime($b['at']));
    }
}

$statusLabel = [
    'in_stock' => 'Ready Stock', 'in_transit' => 'Dalam Transfer', 'melted' => 'Sudah Dilebur',
    'returned' => 'Sudah Diretur', 'sold' => 'Sudah Terjual',
];
?>

<div class="card" style="margin-bottom:20px;">
  <form method="get" style="display:flex; gap:10px; align-items:flex-end;">
    <div class="field" style="flex:1; margin-bottom:0;">
      <label>Kode PLU (scan barcode atau ketik manual)</label>
      <input type="text" name="plu_code" value="<?= htmlspecialchars($pluCode) ?>" placeholder="cth. 2026-000001" autofocus>
    </div>
    <button type="submit" class="btn">Cek</button>
  </form>
</div>

<?php if ($pluCode !== '' && !$item): ?>
  <div class="card txn-empty">Kode PLU <strong><?= htmlspecialchars($pluCode) ?></strong> gak ketemu.</div>
<?php elseif ($item): ?>
  <div class="card" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
      <div>
        <h2 style="margin:0 0 4px;"><?= htmlspecialchars($item['product_name']) ?></h2>
        <div style="font-size:13px; color:var(--ink-muted);">PLU <?= htmlspecialchars($item['plu_code']) ?><?= $item['certificate_code'] ? ' · Sert. ' . htmlspecialchars($item['certificate_code']) : '' ?></div>
      </div>
      <span class="pill <?= $item['status'] === 'in_stock' ? 'pill-received' : ($item['status'] === 'sold' ? 'pill-paid' : 'pill-pending') ?>"><?= htmlspecialchars($statusLabel[$item['status']] ?? strtoupper($item['status'])) ?></span>
    </div>
    <div class="txn-info-strip" style="margin-top:12px;">
      <div><span class="lbl">Berat</span><?= $item['weight'] !== null ? number_format((float) $item['weight'], 2, ',', '.') . ' gr' : '—' ?></div>
      <div><span class="lbl">Lokasi</span><?= htmlspecialchars($item['location_name'] ?? '—') ?></div>
      <div><span class="lbl">Tipe Stock</span><?= htmlspecialchars($item['stock_type_name'] ?? '—') ?></div>
      <div><span class="lbl">Project</span><?= htmlspecialchars($item['project_name'] ?? '—') ?></div>
      <div><span class="lbl">Masuk Sistem</span><?= htmlspecialchars(date('d M Y H:i', strtotime($item['created_at']))) ?></div>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0;">Histori / Log Aktivitas</h3>
    <?php if (!$timeline): ?>
      <div style="text-align:center; color:var(--ink-muted); padding:20px;">Belum ada aktivitas tercatat.</div>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th style="width:160px;">Tanggal</th><th style="width:200px;">Kejadian</th><th>Detail</th></tr></thead>
        <tbody>
          <?php foreach ($timeline as $t): ?>
            <tr>
              <td><?= htmlspecialchars(date('d M Y H:i', strtotime($t['at']))) ?></td>
              <td><strong><?= htmlspecialchars($t['label']) ?></strong></td>
              <td><?= $t['href'] ? '<a href="' . htmlspecialchars($t['href']) . '">' . htmlspecialchars($t['detail']) . '</a>' : htmlspecialchars($t['detail']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
