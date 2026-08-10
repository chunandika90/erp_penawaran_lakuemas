<?php
/**
 * Nomor dokumen berurutan per organisasi, per jenis dokumen, per tahun.
 * Format: {PREFIX}/{doc_type}/{tahun}/{nomor 4 digit} — PREFIX dari
 * organizations.document_prefix (fallback ke inisial org kalau kosong).
 */
require_once __DIR__ . '/db.php';

/** Increment (atau bikin baru) counter tahunan per organisasi+doc_type, return angka urutnya. */
function next_counter(int $organizationId, string $docType): int
{
    $pdo = db();
    $year = (int) date('Y');

    // Dipanggil dari dalam transaction pemanggil (quotations.php dkk) HAMPIR SELALU —
    // jangan buka transaction baru kalau udah ada yang aktif (PDO gak support nested
    // transaction, bakal throw "There is already an active transaction").
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT last_number FROM doc_counters WHERE organization_id=? AND doc_type=? AND year=? FOR UPDATE');
        $stmt->execute([$organizationId, $docType, $year]);
        $row = $stmt->fetch();

        if ($row) {
            $next = (int) $row['last_number'] + 1;
            $pdo->prepare('UPDATE doc_counters SET last_number=? WHERE organization_id=? AND doc_type=? AND year=?')
                ->execute([$next, $organizationId, $docType, $year]);
        } else {
            $next = 1;
            $pdo->prepare('INSERT INTO doc_counters (organization_id, doc_type, year, last_number) VALUES (?,?,?,?)')
                ->execute([$organizationId, $docType, $year, $next]);
        }
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction) $pdo->rollBack();
        throw $e;
    }

    return $next;
}

function next_doc_number(int $organizationId, string $docType): string
{
    $next = next_counter($organizationId, $docType);
    $year = (int) date('Y');

    $pdo = db();
    $org = $pdo->prepare('SELECT document_prefix, legal_name FROM organizations WHERE id=?');
    $org->execute([$organizationId]);
    $org = $org->fetch();
    $prefix = $org['document_prefix'] ?: strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $org['legal_name'] ?? 'ORG'), 0, 4));

    return sprintf('%s/%s/%d/%04d', $prefix, strtoupper($docType), $year, $next);
}

/**
 * Kode PLU barang fisik — cuma tahun + running number, TANPA prefix/kata
 * (beda dari next_doc_number yang dokumen biasa), soalnya ini yang di-encode
 * jadi barcode & ditempel fisik ke barang — makin pendek/bersih makin gampang
 * discan & ditulis manual kalau labelnya rusak.
 */
function next_plu_code(int $organizationId): string
{
    $next = next_counter($organizationId, 'PLU_CODE');
    $year = (int) date('Y');
    return sprintf('%d-%06d', $year, $next);
}
