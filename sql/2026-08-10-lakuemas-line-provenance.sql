-- Line-item provenance buat alur pre-stock (Penawaran -> PO -> Penerimaan
-- Barang): sebelumnya "nyambung ke dokumen sebelumnya" cuma ditaruh di level
-- HEADER (gold_goods_receipts.po_id = 1 PO doang), padahal di lapangan 1
-- Penawaran bisa pecah jadi banyak PO (split), dan banyak PO bisa diterima
-- jadi 1 dokumen Penerimaan (merge). Fix-nya: taruh referensi sumber di level
-- BARIS, bukan header — pola sama kayak "Line-Item Provenance" yang udah
-- divalidasi di desain lama (lihat wujud-erp/DOKUMENTASI_ARSITEKTUR.md poin 1).
--
-- purchase_order_gold_lines.quotation_line_id: baris PO nunjuk ke baris
-- Penawaran sumbernya (opsional). Banyak baris PO -- bahkan lintas PO
-- header berbeda -- bisa nunjuk ke baris Penawaran yang sama = split.
--
-- inventory_items.po_line_id: barang fisik nunjuk ke baris PO yang
-- dipenuhinya (opsional). 1 Penerimaan Barang (gold_goods_receipts) sekarang
-- bisa punya barang-barang yang po_line_id-nya beda-beda PO = merge.
-- gold_goods_receipts.po_id (header) tetap ada sebagai info "PO utama kalau
-- single-sourced", tapi sumber kebenarannya sekarang di level barang lewat
-- po_line_id ini.

ALTER TABLE purchase_order_gold_lines
  ADD COLUMN quotation_line_id INT UNSIGNED DEFAULT NULL AFTER product_id,
  ADD KEY fk_pogline_quotation_line (quotation_line_id),
  ADD CONSTRAINT fk_pogline_quotation_line FOREIGN KEY (quotation_line_id) REFERENCES quotation_gold_lines (id) ON DELETE SET NULL;

ALTER TABLE inventory_items
  ADD COLUMN po_line_id INT UNSIGNED DEFAULT NULL AFTER product_id,
  ADD KEY fk_invitem_po_line (po_line_id),
  ADD CONSTRAINT fk_invitem_po_line FOREIGN KEY (po_line_id) REFERENCES purchase_order_gold_lines (id) ON DELETE SET NULL;
