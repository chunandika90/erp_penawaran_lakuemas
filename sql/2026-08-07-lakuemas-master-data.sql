-- Master data buat vertikal Lakuemas (emas): tree kategori produk (Group Product >
-- Brand/Jenis Item > Pecahan) dan tree lokasi (Group Location > Location).
-- Reuse tabel `products` yang udah ada (dipakai juga sama vertikal furniture) —
-- ditambah category_id, is_active, base_price. Kolom lama furniture (panjang,
-- lebar, material, dst) tetap ada, gak kepake buat produk emas.

CREATE TABLE `product_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `parent_id` int unsigned DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `fk_pcat_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pcat_parent` FOREIGN KEY (`parent_id`) REFERENCES `product_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `locations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `parent_id` int unsigned DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `fk_loc_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_loc_parent` FOREIGN KEY (`parent_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `products`
  ADD COLUMN `category_id` int unsigned DEFAULT NULL AFTER `organization_id`,
  ADD COLUMN `base_price` decimal(15,2) NOT NULL DEFAULT '0.00' AFTER `unit`,
  ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT '1' AFTER `base_price`,
  ADD KEY `category_id` (`category_id`),
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`);
