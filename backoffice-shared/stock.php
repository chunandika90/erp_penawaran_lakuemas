<?php
/**
 * Konsumsi stok metode FIFO — dipakai Delivery Order buat minus stok &
 * hitung HPP (weighted-avg dari batch-batch stock_ledger IN yang kepakai,
 * tertua duluan). Lempar RuntimeException kalau stok kurang.
 */
require_once __DIR__ . '/db.php';

function fifo_consume_stock(int $organizationId, int $warehouseId, int $productId, float $qtyNeeded, string $refType, int $refId): float
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT * FROM stock_ledger
         WHERE organization_id=? AND warehouse_id=? AND product_id=? AND direction="in" AND qty_remaining > 0
         ORDER BY created_at ASC, id ASC FOR UPDATE'
    );
    $stmt->execute([$organizationId, $warehouseId, $productId]);
    $batches = $stmt->fetchAll();

    $available = array_sum(array_column($batches, 'qty_remaining'));
    if ($available < $qtyNeeded) {
        throw new RuntimeException("Stok tidak cukup (tersedia $available, dibutuhkan $qtyNeeded).");
    }

    $remaining = $qtyNeeded;
    $totalCost = 0.0;
    $updateBatch = $pdo->prepare('UPDATE stock_ledger SET qty_remaining = qty_remaining - ? WHERE id=?');
    foreach ($batches as $batch) {
        if ($remaining <= 0) break;
        $take = min($remaining, (float) $batch['qty_remaining']);
        $updateBatch->execute([$take, $batch['id']]);
        $totalCost += $take * (float) $batch['unit_cost'];
        $remaining -= $take;
    }

    $unitCost = $qtyNeeded > 0 ? $totalCost / $qtyNeeded : 0;

    $pdo->prepare(
        'INSERT INTO stock_ledger (organization_id, warehouse_id, product_id, direction, qty, qty_remaining, unit_cost, ref_type, ref_id)
         VALUES (?,?,?,"out",?,0,?,?,?)'
    )->execute([$organizationId, $warehouseId, $productId, $qtyNeeded, $unitCost, $refType, $refId]);

    return $unitCost;
}

function available_stock(int $organizationId, int $warehouseId, int $productId): float
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(qty_remaining),0) c FROM stock_ledger WHERE organization_id=? AND warehouse_id=? AND product_id=? AND direction="in"');
    $stmt->execute([$organizationId, $warehouseId, $productId]);
    return (float) $stmt->fetch()['c'];
}

/** Versi material dari fifo_consume_stock() — dipakai SPK (Manufacturing Order) minus stok bahan. */
function fifo_consume_material_stock(int $organizationId, int $warehouseId, int $materialId, float $qtyNeeded, string $refType, int $refId): float
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT * FROM stock_ledger
         WHERE organization_id=? AND warehouse_id=? AND material_id=? AND direction="in" AND qty_remaining > 0
         ORDER BY created_at ASC, id ASC FOR UPDATE'
    );
    $stmt->execute([$organizationId, $warehouseId, $materialId]);
    $batches = $stmt->fetchAll();

    $available = array_sum(array_column($batches, 'qty_remaining'));
    if ($available < $qtyNeeded) {
        throw new RuntimeException("Stok material tidak cukup (tersedia $available, dibutuhkan $qtyNeeded).");
    }

    $remaining = $qtyNeeded;
    $totalCost = 0.0;
    $updateBatch = $pdo->prepare('UPDATE stock_ledger SET qty_remaining = qty_remaining - ? WHERE id=?');
    foreach ($batches as $batch) {
        if ($remaining <= 0) break;
        $take = min($remaining, (float) $batch['qty_remaining']);
        $updateBatch->execute([$take, $batch['id']]);
        $totalCost += $take * (float) $batch['unit_cost'];
        $remaining -= $take;
    }

    $unitCost = $qtyNeeded > 0 ? $totalCost / $qtyNeeded : 0;

    $pdo->prepare(
        'INSERT INTO stock_ledger (organization_id, warehouse_id, material_id, direction, qty, qty_remaining, unit_cost, ref_type, ref_id)
         VALUES (?,?,?,"out",?,0,?,?,?)'
    )->execute([$organizationId, $warehouseId, $materialId, $qtyNeeded, $unitCost, $refType, $refId]);

    return $unitCost;
}

function available_material_stock(int $organizationId, int $warehouseId, int $materialId): float
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(qty_remaining),0) c FROM stock_ledger WHERE organization_id=? AND warehouse_id=? AND material_id=? AND direction="in"');
    $stmt->execute([$organizationId, $warehouseId, $materialId]);
    return (float) $stmt->fetch()['c'];
}

/**
 * Gudang virtual milik vendor — barang dari PO yang sengaja "dititip"/disimpan
 * di lokasi vendor (bukan gudang Svashta), tapi tetep ke-track stoknya.
 * Satu row per vendor, dibuat otomatis pas pertama kali dicentang di PO.
 */
function find_or_create_vendor_warehouse(int $organizationId, int $vendorId, string $vendorName): int
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM warehouses WHERE organization_id=? AND vendor_id=?');
    $stmt->execute([$organizationId, $vendorId]);
    if ($existing = $stmt->fetch()) return (int) $existing['id'];

    $pdo->prepare('INSERT INTO warehouses (organization_id, name, vendor_id, is_default) VALUES (?,?,?,0)')
        ->execute([$organizationId, 'Gudang Vendor — ' . $vendorName, $vendorId]);
    return (int) $pdo->lastInsertId();
}
