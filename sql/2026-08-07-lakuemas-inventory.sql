-- Inventory serialized buat vertikal Lakuemas: tiap barang fisik (LM, PG,
-- jewellery) dicatat sebagai 1 baris inventory_items (bukan cuma qty per SKU),
-- karena tiap barang punya kode sertifikat dari supplier + kode PLU auto kita
-- sendiri. stock_type master flat (bukan tree) karena cuma 1 tingkat & open-ended
-- ("jual/ready stock", "resell", "gold priority", dst — bisa nambah sendiri).

CREATE TABLE `stock_types` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_sellable` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `fk_stocktype_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `inventory_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `location_id` int unsigned DEFAULT NULL,
  `stock_type_id` int unsigned DEFAULT NULL,
  `certificate_code` varchar(100) DEFAULT NULL,
  `plu_code` varchar(60) NOT NULL,
  `weight` decimal(10,3) DEFAULT NULL,
  `status` enum('in_stock','in_transit','melted','returned','sold') NOT NULL DEFAULT 'in_stock',
  `project_id` int unsigned DEFAULT NULL,
  `source_type` enum('goods_receipt','melting_output','adjustment') NOT NULL DEFAULT 'goods_receipt',
  `source_id` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_org_plu` (`organization_id`,`plu_code`),
  KEY `organization_id` (`organization_id`),
  KEY `product_id` (`product_id`),
  KEY `location_id` (`location_id`),
  KEY `stock_type_id` (`stock_type_id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `fk_invitem_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invitem_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_invitem_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `fk_invitem_stocktype` FOREIGN KEY (`stock_type_id`) REFERENCES `stock_types` (`id`),
  CONSTRAINT `fk_invitem_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `gold_goods_receipts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `location_id` int unsigned NOT NULL,
  `vendor_id` int unsigned DEFAULT NULL,
  `project_id` int unsigned DEFAULT NULL,
  `notes` text,
  `received_by` int unsigned NOT NULL,
  `received_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `fk_ggr_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `stock_transfers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `from_location_id` int unsigned NOT NULL,
  `to_location_id` int unsigned NOT NULL,
  `project_id` int unsigned DEFAULT NULL,
  `status` enum('dikirim','diterima','void') NOT NULL DEFAULT 'dikirim',
  `notes` text,
  `created_by` int unsigned NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `received_by` int unsigned DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `fk_transfer_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `stock_transfer_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `stock_transfer_id` int unsigned NOT NULL,
  `inventory_item_id` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `stock_transfer_id` (`stock_transfer_id`),
  KEY `inventory_item_id` (`inventory_item_id`),
  CONSTRAINT `fk_transferline_transfer` FOREIGN KEY (`stock_transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_transferline_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
