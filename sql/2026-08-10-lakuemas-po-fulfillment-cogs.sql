-- Partial receiving buat PO: sebelumnya status PO cuma draft/sent/received/void
-- (all-or-nothing, sekali ada 1 barang masuk langsung dianggap "received"
-- penuh). Sekarang ditambah 'partial' -- dihitung dari perbandingan qty per
-- baris PO vs jumlah inventory_items yang po_line_id-nya nunjuk ke baris itu
-- (dihitung on-the-fly lewat COUNT(), gak perlu kolom received_qty terpisah
-- biar gak ada resiko data ke-desync).
ALTER TABLE purchase_orders_gold
  MODIFY COLUMN status ENUM('draft','sent','partial','received','void') NOT NULL DEFAULT 'draft';

-- COGS/HPP per barang fisik: snapshot harga beli (dari baris PO asalnya) dan
-- harga jual master (products.base_price) PAS BARANG DITERIMA -- bukan pas
-- dijual -- biar kelihatan margin di titik waktu barang itu masuk, gak
-- kebawa naik/turun kalau harga master berubah belakangan.
ALTER TABLE inventory_items
  ADD COLUMN unit_cost DECIMAL(15,2) DEFAULT NULL AFTER weight,
  ADD COLUMN market_price_snapshot DECIMAL(15,2) DEFAULT NULL AFTER unit_cost;
