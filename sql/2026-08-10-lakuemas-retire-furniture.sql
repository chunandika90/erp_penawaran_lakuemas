-- Retire alur lama (furniture/konstruksi "Wujud ERP") — Lakuemas murni bisnis
-- emas, gak ada tenant furniture yang masih hidup pakai instalasi ini. Drop
-- semua tabel yang cuma dipakai flow lama (Penawaran/Invoice/PO/SPK/Penerimaan/
-- DO/Kuitansi lama + Tier/BOM), project-flow.php dibangun ulang di atas
-- quotations_gold/purchase_orders_gold/gold_goods_receipts/sales_gold/dst.
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS kuitansi;
DROP TABLE IF EXISTS delivery_order_lines;
DROP TABLE IF EXISTS delivery_orders;
DROP TABLE IF EXISTS material_request_lines;
DROP TABLE IF EXISTS material_requests;
DROP TABLE IF EXISTS goods_receipt_lines;
DROP TABLE IF EXISTS goods_receipts;
DROP TABLE IF EXISTS spk_materials;
DROP TABLE IF EXISTS spk;
DROP TABLE IF EXISTS po_lines;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS invoice_lines;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS quotation_lines;
DROP TABLE IF EXISTS quotations;
DROP TABLE IF EXISTS product_tiers;
DROP TABLE IF EXISTS product_collections;
DROP TABLE IF EXISTS product_finishings;
DROP TABLE IF EXISTS product_item_types;
DROP TABLE IF EXISTS stock_ledger;
DROP TABLE IF EXISTS materials;
DROP TABLE IF EXISTS warehouses;
DROP TABLE IF EXISTS terms_conditions;

SET FOREIGN_KEY_CHECKS = 1;
