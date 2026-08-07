-- Penawaran, PO, dan Penjualan versi emas — dibikin baru (bukan reuse tabel
-- quotations/purchase_orders/invoices lama) karena tabel lama nempel ke konsep
-- "tier" (product_tiers/BOM) punya furniture yang gak relevan buat emas.
-- Penjualan yang nurunin stock (inventory_items -> status 'sold') belum ada
-- modulnya sama sekali sebelum ini.

CREATE TABLE `quotations_gold` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `contact_id` int unsigned NOT NULL,
  `project_id` int unsigned DEFAULT NULL,
  `status` enum('draft','sent','approved','rejected') NOT NULL DEFAULT 'draft',
  `notes` text,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `fk_qgold_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `quotation_gold_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `quotation_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `qty` decimal(12,2) NOT NULL DEFAULT '1.00',
  `unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quotation_id` (`quotation_id`),
  CONSTRAINT `fk_qglline_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations_gold` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qglline_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `purchase_orders_gold` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `vendor_id` int unsigned NOT NULL,
  `project_id` int unsigned DEFAULT NULL,
  `status` enum('draft','sent','received','void') NOT NULL DEFAULT 'draft',
  `notes` text,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `fk_pogold_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `purchase_order_gold_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `po_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `qty` decimal(12,2) NOT NULL DEFAULT '1.00',
  `unit_cost` decimal(15,2) NOT NULL DEFAULT '0.00',
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `po_id` (`po_id`),
  CONSTRAINT `fk_pogline_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders_gold` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pogline_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Penerimaan barang bisa nempel ke PO asal (opsional, buat jejak & nutup status PO).
ALTER TABLE `gold_goods_receipts`
  ADD COLUMN `po_id` int unsigned DEFAULT NULL AFTER `location_id`,
  ADD CONSTRAINT `fk_ggr_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders_gold` (`id`);

-- Penjualan: nurunin barang serialized dari stock (status jadi 'sold').
CREATE TABLE `sales_gold` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `contact_id` int unsigned NOT NULL,
  `project_id` int unsigned DEFAULT NULL,
  `quotation_id` int unsigned DEFAULT NULL,
  `notes` text,
  `sold_by` int unsigned NOT NULL,
  `sold_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `fk_salegold_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_salegold_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations_gold` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `sales_gold_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` int unsigned NOT NULL,
  `inventory_item_id` int unsigned NOT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `inventory_item_id` (`inventory_item_id`),
  CONSTRAINT `fk_salegline_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales_gold` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_salegline_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
