-- Lebur Barang: item in_stock keluar (status 'melted'), hasil leburan jadi
-- inventory_item baru (source_type='melting_output') — stock gak hilang,
-- cuma ganti bentuk jadi raw gold/produk lain sesuai pilihan user.
CREATE TABLE `melting_batches` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `location_id` int unsigned NOT NULL,
  `project_id` int unsigned DEFAULT NULL,
  `output_inventory_item_id` int unsigned DEFAULT NULL,
  `notes` text,
  `melted_by` int unsigned NOT NULL,
  `melted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `fk_melt_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `melting_batch_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `melting_batch_id` int unsigned NOT NULL,
  `inventory_item_id` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `melting_batch_id` (`melting_batch_id`),
  CONSTRAINT `fk_meltline_batch` FOREIGN KEY (`melting_batch_id`) REFERENCES `melting_batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_meltline_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Retur Supplier: nempel ke gold_goods_receipts asal — cuma barang yang
-- masih in_stock dari GR itu yang boleh diretur.
CREATE TABLE `supplier_returns` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `goods_receipt_id` int unsigned NOT NULL,
  `vendor_id` int unsigned DEFAULT NULL,
  `notes` text,
  `returned_by` int unsigned NOT NULL,
  `returned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `goods_receipt_id` (`goods_receipt_id`),
  CONSTRAINT `fk_retur_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_retur_gr` FOREIGN KEY (`goods_receipt_id`) REFERENCES `gold_goods_receipts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `supplier_return_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `supplier_return_id` int unsigned NOT NULL,
  `inventory_item_id` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `supplier_return_id` (`supplier_return_id`),
  CONSTRAINT `fk_returline_return` FOREIGN KEY (`supplier_return_id`) REFERENCES `supplier_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_returline_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
