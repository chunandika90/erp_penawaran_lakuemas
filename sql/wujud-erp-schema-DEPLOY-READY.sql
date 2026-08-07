
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `type` enum('customer','vendor','both') NOT NULL DEFAULT 'customer',
  `name` varchar(200) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text,
  `npwp` varchar(30) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `contacts_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_order_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_order_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `delivery_order_id` int unsigned NOT NULL,
  `invoice_line_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `product_name_snapshot` varchar(200) NOT NULL,
  `qty` decimal(12,2) NOT NULL,
  `unit_cost_snapshot` decimal(15,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `delivery_order_id` (`delivery_order_id`),
  KEY `invoice_line_id` (`invoice_line_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `delivery_order_lines_ibfk_1` FOREIGN KEY (`delivery_order_id`) REFERENCES `delivery_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_order_lines_ibfk_2` FOREIGN KEY (`invoice_line_id`) REFERENCES `invoice_lines` (`id`),
  CONSTRAINT `delivery_order_lines_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `invoice_id` int unsigned NOT NULL,
  `contact_id` int unsigned NOT NULL,
  `warehouse_id` int unsigned DEFAULT NULL,
  `status` enum('draft','shipped','delivered','void') NOT NULL DEFAULT 'draft',
  `shipped_at` datetime DEFAULT NULL,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doc` (`organization_id`,`doc_number`),
  KEY `invoice_id` (`invoice_id`),
  KEY `contact_id` (`contact_id`),
  KEY `warehouse_id` (`warehouse_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `delivery_orders_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_orders_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  CONSTRAINT `delivery_orders_ibfk_3` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `delivery_orders_ibfk_4` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `delivery_orders_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `doc_counters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `doc_counters` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_type` varchar(30) NOT NULL,
  `year` int NOT NULL,
  `last_number` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_counter` (`organization_id`,`doc_type`,`year`),
  CONSTRAINT `doc_counters_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `goods_receipt_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `goods_receipt_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `goods_receipt_id` int unsigned NOT NULL,
  `po_line_id` int unsigned DEFAULT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `material_id` int unsigned DEFAULT NULL,
  `item_name` varchar(200) NOT NULL,
  `qty` decimal(12,2) NOT NULL,
  `unit_cost` decimal(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `goods_receipt_id` (`goods_receipt_id`),
  KEY `po_line_id` (`po_line_id`),
  KEY `product_id` (`product_id`),
  KEY `fk_grline_material` (`material_id`),
  CONSTRAINT `fk_grline_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `goods_receipt_lines_ibfk_1` FOREIGN KEY (`goods_receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `goods_receipt_lines_ibfk_2` FOREIGN KEY (`po_line_id`) REFERENCES `po_lines` (`id`),
  CONSTRAINT `goods_receipt_lines_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `goods_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `goods_receipts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `po_id` int unsigned DEFAULT NULL,
  `spk_id` int unsigned DEFAULT NULL,
  `warehouse_id` int unsigned DEFAULT NULL,
  `destination` enum('warehouse','direct_customer') NOT NULL DEFAULT 'warehouse',
  `received_by` int unsigned NOT NULL,
  `received_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doc` (`organization_id`,`doc_number`),
  KEY `po_id` (`po_id`),
  KEY `warehouse_id` (`warehouse_id`),
  KEY `received_by` (`received_by`),
  KEY `fk_receipt_spk` (`spk_id`),
  CONSTRAINT `fk_receipt_spk` FOREIGN KEY (`spk_id`) REFERENCES `spk` (`id`),
  CONSTRAINT `goods_receipts_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `goods_receipts_ibfk_2` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`),
  CONSTRAINT `goods_receipts_ibfk_3` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `goods_receipts_ibfk_4` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int unsigned NOT NULL,
  `quotation_line_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `product_name_snapshot` varchar(200) NOT NULL,
  `tier_level_snapshot` varchar(20) NOT NULL,
  `qty` decimal(12,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  KEY `quotation_line_id` (`quotation_line_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `invoice_lines_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoice_lines_ibfk_2` FOREIGN KEY (`quotation_line_id`) REFERENCES `quotation_lines` (`id`),
  CONSTRAINT `invoice_lines_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `contact_id` int unsigned NOT NULL,
  `quotation_id` int unsigned DEFAULT NULL,
  `sales_user_id` int unsigned DEFAULT NULL,
  `payment_scheme` enum('full','dp') NOT NULL DEFAULT 'full',
  `dp_type` enum('percent','amount') DEFAULT NULL,
  `dp_value` decimal(15,2) DEFAULT NULL,
  `billed_amount` decimal(15,2) DEFAULT NULL,
  `terms_snapshot` text,
  `status` enum('draft','issued','paid','void') NOT NULL DEFAULT 'draft',
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doc` (`organization_id`,`doc_number`),
  KEY `contact_id` (`contact_id`),
  KEY `created_by` (`created_by`),
  KEY `fk_invoice_sales` (`sales_user_id`),
  KEY `fk_invoice_quotation` (`quotation_id`),
  CONSTRAINT `fk_invoice_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`),
  CONSTRAINT `fk_invoice_sales` FOREIGN KEY (`sales_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `invoices_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kuitansi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kuitansi` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `invoice_id` int unsigned NOT NULL,
  `delivery_order_id` int unsigned DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_type` enum('dp','termin','pelunasan') NOT NULL DEFAULT 'pelunasan',
  `paid_at` date NOT NULL,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doc` (`organization_id`,`doc_number`),
  KEY `invoice_id` (`invoice_id`),
  KEY `delivery_order_id` (`delivery_order_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `kuitansi_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `kuitansi_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  CONSTRAINT `kuitansi_ibfk_3` FOREIGN KEY (`delivery_order_id`) REFERENCES `delivery_orders` (`id`),
  CONSTRAINT `kuitansi_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_request_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_request_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int unsigned NOT NULL,
  `invoice_line_id` int unsigned NOT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `product_name_snapshot` varchar(200) NOT NULL,
  `material_id` int unsigned NOT NULL,
  `material_name_snapshot` varchar(200) NOT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `need_qty` decimal(12,2) NOT NULL,
  `stock_qty_snapshot` decimal(12,2) NOT NULL DEFAULT '0.00',
  `take_from_stock_qty` decimal(12,2) NOT NULL DEFAULT '0.00',
  `need_po_qty` decimal(12,2) NOT NULL DEFAULT '0.00',
  `po_line_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `invoice_line_id` (`invoice_line_id`),
  KEY `product_id` (`product_id`),
  KEY `material_id` (`material_id`),
  KEY `po_line_id` (`po_line_id`),
  CONSTRAINT `material_request_lines_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `material_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_request_lines_ibfk_2` FOREIGN KEY (`invoice_line_id`) REFERENCES `invoice_lines` (`id`),
  CONSTRAINT `material_request_lines_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `material_request_lines_ibfk_4` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `material_request_lines_ibfk_5` FOREIGN KEY (`po_line_id`) REFERENCES `po_lines` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `invoice_id` int unsigned NOT NULL,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doc` (`organization_id`,`doc_number`),
  UNIQUE KEY `uniq_invoice` (`invoice_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `material_requests_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_requests_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  CONSTRAINT `material_requests_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `materials` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `name` varchar(200) NOT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `default_cost` decimal(15,2) NOT NULL DEFAULT '0.00',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `materials_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `organizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `organizations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `legal_name` varchar(200) NOT NULL,
  `npwp` varchar(30) DEFAULT NULL,
  `address` text,
  `logo` varchar(255) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `document_prefix` varchar(20) DEFAULT NULL,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `organizations_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `po_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `po_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `po_id` int unsigned NOT NULL,
  `invoice_line_id` int unsigned DEFAULT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `material_id` int unsigned DEFAULT NULL,
  `item_name` varchar(200) NOT NULL,
  `qty` decimal(12,2) NOT NULL,
  `unit_cost` decimal(15,2) NOT NULL,
  `received_qty` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `po_id` (`po_id`),
  KEY `invoice_line_id` (`invoice_line_id`),
  KEY `product_id` (`product_id`),
  KEY `fk_poline_material` (`material_id`),
  CONSTRAINT `fk_poline_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `po_lines_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `po_lines_ibfk_2` FOREIGN KEY (`invoice_line_id`) REFERENCES `invoice_lines` (`id`),
  CONSTRAINT `po_lines_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_collections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_collections` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_org_collection` (`organization_id`,`name`),
  CONSTRAINT `fk_collection_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_finishings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_finishings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_org_finishing` (`organization_id`,`name`),
  CONSTRAINT `fk_finishing_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_item_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_item_types` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_org_item_type` (`organization_id`,`name`),
  CONSTRAINT `fk_item_type_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_tiers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `tier_level` enum('ekonomis','standard','premium','deluxe','bespoke') NOT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `bom_json` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tier_version` (`product_id`,`tier_level`,`version`),
  CONSTRAINT `product_tiers_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `name` varchar(200) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `material` varchar(150) DEFAULT NULL,
  `item_type` varchar(150) DEFAULT NULL,
  `collection` varchar(150) DEFAULT NULL,
  `size` varchar(150) DEFAULT NULL,
  `panjang` decimal(8,2) DEFAULT NULL,
  `lebar` decimal(8,2) DEFAULT NULL,
  `tinggi` decimal(8,2) DEFAULT NULL,
  `tinggi_dudukan` decimal(8,2) DEFAULT NULL,
  `tinggi_lengan` decimal(8,2) DEFAULT NULL,
  `tinggi_sandaran` decimal(8,2) DEFAULT NULL,
  `tinggi_kaki` decimal(8,2) DEFAULT NULL,
  `finishing` varchar(150) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `extra_specs` json DEFAULT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `name` varchar(200) NOT NULL,
  `contact_id` int unsigned DEFAULT NULL,
  `pic_user_id` int unsigned DEFAULT NULL,
  `sales_user_id` int unsigned DEFAULT NULL,
  `notes` text,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `contact_id` (`contact_id`),
  KEY `created_by` (`created_by`),
  KEY `fk_project_pic` (`pic_user_id`),
  KEY `fk_project_sales` (`sales_user_id`),
  CONSTRAINT `fk_project_pic` FOREIGN KEY (`pic_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_project_sales` FOREIGN KEY (`sales_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `projects_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `projects_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `project_id` int unsigned DEFAULT NULL,
  `material_request_id` int unsigned DEFAULT NULL,
  `doc_number` varchar(40) NOT NULL,
  `vendor_id` int unsigned DEFAULT NULL,
  `destination_warehouse_id` int unsigned DEFAULT NULL,
  `po_type` enum('bahan_baku','jasa_produksi','barang_jadi') NOT NULL,
  `status` enum('draft','sent','partial','received','void') NOT NULL DEFAULT 'draft',
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doc` (`organization_id`,`doc_number`),
  KEY `vendor_id` (`vendor_id`),
  KEY `created_by` (`created_by`),
  KEY `fk_po_project` (`project_id`),
  KEY `fk_po_request` (`material_request_id`),
  KEY `fk_po_destination_warehouse` (`destination_warehouse_id`),
  CONSTRAINT `fk_po_destination_warehouse` FOREIGN KEY (`destination_warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_po_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `fk_po_request` FOREIGN KEY (`material_request_id`) REFERENCES `material_requests` (`id`),
  CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_orders_ibfk_2` FOREIGN KEY (`vendor_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `purchase_orders_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quotation_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotation_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `quotation_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `product_name_snapshot` varchar(200) NOT NULL,
  `tier_id` int unsigned NOT NULL,
  `tier_level_snapshot` varchar(20) NOT NULL,
  `tier_version_snapshot` int unsigned NOT NULL,
  `bom_snapshot` json DEFAULT NULL,
  `qty` decimal(12,2) NOT NULL DEFAULT '1.00',
  `unit_price` decimal(15,2) NOT NULL,
  `custom_note` text,
  PRIMARY KEY (`id`),
  KEY `quotation_id` (`quotation_id`),
  KEY `product_id` (`product_id`),
  KEY `tier_id` (`tier_id`),
  CONSTRAINT `quotation_lines_ibfk_1` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quotation_lines_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `quotation_lines_ibfk_3` FOREIGN KEY (`tier_id`) REFERENCES `product_tiers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quotations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `contact_id` int unsigned NOT NULL,
  `project_id` int unsigned DEFAULT NULL,
  `sales_user_id` int unsigned DEFAULT NULL,
  `status` enum('draft','sent','approved','rejected') NOT NULL DEFAULT 'draft',
  `notes` text,
  `discount_type` enum('percent','amount') DEFAULT NULL,
  `discount_value` decimal(15,2) NOT NULL DEFAULT '0.00',
  `terms_snapshot` text,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doc` (`organization_id`,`doc_number`),
  KEY `contact_id` (`contact_id`),
  KEY `created_by` (`created_by`),
  KEY `fk_quotation_project` (`project_id`),
  KEY `fk_quotation_sales` (`sales_user_id`),
  CONSTRAINT `fk_quotation_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `fk_quotation_sales` FOREIGN KEY (`sales_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `quotations_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quotations_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `quotations_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_module_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_module_access` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int unsigned NOT NULL,
  `module_key` varchar(50) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT '0',
  `can_create` tinyint(1) NOT NULL DEFAULT '0',
  `can_edit` tinyint(1) NOT NULL DEFAULT '0',
  `can_delete` tinyint(1) NOT NULL DEFAULT '0',
  `can_print` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_role_module` (`role_id`,`module_key`),
  CONSTRAINT `role_module_access_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `is_owner_role` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `roles_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `spk`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spk` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `doc_number` varchar(40) NOT NULL,
  `po_id` int unsigned DEFAULT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `vendor_id` int unsigned NOT NULL,
  `status` enum('draft','in_production','done','void') NOT NULL DEFAULT 'draft',
  `estimated_finish` date DEFAULT NULL,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `output_qty` decimal(12,2) DEFAULT NULL,
  `assembly_fee` decimal(15,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doc` (`organization_id`,`doc_number`),
  KEY `po_id` (`po_id`),
  KEY `vendor_id` (`vendor_id`),
  KEY `created_by` (`created_by`),
  KEY `fk_spk_product` (`product_id`),
  CONSTRAINT `fk_spk_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `spk_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `spk_ibfk_2` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`),
  CONSTRAINT `spk_ibfk_3` FOREIGN KEY (`vendor_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `spk_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `spk_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spk_materials` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `spk_id` int unsigned NOT NULL,
  `material_id` int unsigned NOT NULL,
  `material_name_snapshot` varchar(200) NOT NULL,
  `qty` decimal(12,2) NOT NULL,
  `unit_cost` decimal(15,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `spk_id` (`spk_id`),
  KEY `material_id` (`material_id`),
  CONSTRAINT `spk_materials_ibfk_1` FOREIGN KEY (`spk_id`) REFERENCES `spk` (`id`) ON DELETE CASCADE,
  CONSTRAINT `spk_materials_ibfk_2` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_ledger` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `warehouse_id` int unsigned DEFAULT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `material_id` int unsigned DEFAULT NULL,
  `direction` enum('in','out') NOT NULL,
  `qty` decimal(12,2) NOT NULL,
  `qty_remaining` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit_cost` decimal(15,2) NOT NULL,
  `ref_type` varchar(30) NOT NULL,
  `ref_id` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `warehouse_id` (`warehouse_id`),
  KEY `product_id` (`product_id`),
  KEY `fk_ledger_material` (`material_id`),
  CONSTRAINT `fk_ledger_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `stock_ledger_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_ledger_ibfk_2` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `stock_ledger_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `terms_conditions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `terms_conditions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `title` varchar(150) NOT NULL,
  `content` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `terms_conditions_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_organization_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_organization_roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `organization_id` int unsigned NOT NULL,
  `role_id` int unsigned NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_membership` (`user_id`,`organization_id`),
  KEY `organization_id` (`organization_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `user_organization_roles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_organization_roles_ibfk_2` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_organization_roles_ibfk_3` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouses` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `vendor_id` int unsigned DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `fk_warehouse_vendor` (`vendor_id`),
  CONSTRAINT `fk_warehouse_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `warehouses_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

