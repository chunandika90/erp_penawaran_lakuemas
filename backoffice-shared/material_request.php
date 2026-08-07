<?php
/**
 * Request Material — jembatan Invoice -> Purchase Order. Dihitung otomatis dari
 * BOM tiap baris invoice (product_tiers.bom_json versi baru, isinya material_id)
 * dibanding stok yang ada (lintas gudang), dipecah "ambil dari stok" vs "perlu PO".
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/doc_number.php';

function available_material_stock_any_warehouse(int $organizationId, int $materialId): float
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(qty_remaining),0) c FROM stock_ledger WHERE organization_id=? AND material_id=? AND direction="in"');
    $stmt->execute([$organizationId, $materialId]);
    return (float) $stmt->fetch()['c'];
}

/** Bikin (atau balikin yang udah ada) Request Material buat 1 invoice. */
function generate_material_request(int $organizationId, int $invoiceId, int $userId): int
{
    $pdo = db();
    $check = $pdo->prepare('SELECT id FROM material_requests WHERE invoice_id=?');
    $check->execute([$invoiceId]);
    if ($existing = $check->fetch()) return (int) $existing['id'];

    $docNumber = next_doc_number($organizationId, 'mr');
    $pdo->prepare('INSERT INTO material_requests (organization_id, doc_number, invoice_id, created_by) VALUES (?,?,?,?)')
        ->execute([$organizationId, $docNumber, $invoiceId, $userId]);
    $requestId = (int) $pdo->lastInsertId();

    $lines = $pdo->prepare(
        'SELECT il.id AS invoice_line_id, il.product_id, il.product_name_snapshot, il.qty, ql.bom_snapshot
         FROM invoice_lines il JOIN quotation_lines ql ON ql.id = il.quotation_line_id
         WHERE il.invoice_id = ?'
    );
    $lines->execute([$invoiceId]);

    $matStmt = $pdo->prepare('SELECT name, unit FROM materials WHERE id=? AND organization_id=?');
    $insertLine = $pdo->prepare(
        'INSERT INTO material_request_lines (request_id, invoice_line_id, product_id, product_name_snapshot, material_id, material_name_snapshot, unit, need_qty, stock_qty_snapshot, take_from_stock_qty, need_po_qty)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );

    foreach ($lines->fetchAll() as $l) {
        $bom = json_decode($l['bom_snapshot'] ?? '[]', true) ?: [];
        foreach ($bom as $b) {
            if (empty($b['material_id'])) continue; // BOM lama (pre-material_id) — skip, gak bisa dihitung
            $matId = (int) $b['material_id'];
            $needQty = (float) ($b['qty'] ?? 0) * (float) $l['qty'];
            if ($needQty <= 0) continue;

            $matStmt->execute([$matId, $organizationId]);
            $mat = $matStmt->fetch();
            if (!$mat) continue;

            $stockQty = available_material_stock_any_warehouse($organizationId, $matId);
            $takeFromStock = min($needQty, $stockQty);
            $needPo = max(0, $needQty - $stockQty);

            $insertLine->execute([
                $requestId, $l['invoice_line_id'], $l['product_id'], $l['product_name_snapshot'],
                $matId, $mat['name'], $mat['unit'], $needQty, $stockQty, $takeFromStock, $needPo,
            ]);
        }
    }
    return $requestId;
}

/**
 * Status agregat 1 invoice buat badge di list Invoicing:
 * belum_request | terpenuhi | perlu_po | menunggu_po | siap_produksi
 */
function invoice_material_status(int $invoiceId): string
{
    $pdo = db();
    $req = $pdo->prepare('SELECT id FROM material_requests WHERE invoice_id=?');
    $req->execute([$invoiceId]);
    $reqRow = $req->fetch();
    if (!$reqRow) return 'belum_request';

    $lines = $pdo->prepare(
        'SELECT mrl.need_po_qty, mrl.po_line_id, pl.received_qty, pl.qty AS po_qty
         FROM material_request_lines mrl LEFT JOIN po_lines pl ON pl.id = mrl.po_line_id
         WHERE mrl.request_id = ?'
    );
    $lines->execute([$reqRow['id']]);
    $rows = $lines->fetchAll();
    if (!$rows) return 'terpenuhi';

    $allStockOnly = true;
    $anyMissingPo = false;
    $anyWaitingPo = false;
    foreach ($rows as $r) {
        if ((float) $r['need_po_qty'] > 0) {
            $allStockOnly = false;
            if (!$r['po_line_id']) {
                $anyMissingPo = true;
            } elseif ((float) $r['received_qty'] < (float) $r['po_qty']) {
                $anyWaitingPo = true;
            }
        }
    }
    if ($allStockOnly) return 'terpenuhi';
    if ($anyMissingPo) return 'perlu_po';
    if ($anyWaitingPo) return 'menunggu_po';
    return 'siap_produksi';
}

const MATERIAL_STATUS_LABELS = [
    'belum_request' => 'Belum Request',
    'terpenuhi' => 'Terpenuhi dari Stok',
    'perlu_po' => 'Perlu PO',
    'menunggu_po' => 'Menunggu PO',
    'siap_produksi' => 'Siap Produksi',
];
