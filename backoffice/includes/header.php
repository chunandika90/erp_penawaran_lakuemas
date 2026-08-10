<?php
/**
 * @var string $pageTitle
 * @var string $activeMenu
 */
require_once __DIR__ . '/../../backoffice-shared/auth.php';
require_once __DIR__ . '/../../backoffice-shared/modules.php';
$user = require_login();
$org = require_org();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? 'Backoffice') ?> — Wujud ERP</title>
<link rel="apple-touch-icon" sizes="180x180" href="assets/img/favicons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicons/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/img/favicons/favicon-16x16.png">
<link rel="shortcut icon" type="image/x-icon" href="assets/img/favicons/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?: time() ?>">
<script>window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
</head>
<body>
<div class="app-shell">
  <aside class="app-sidebar">
    <div class="brand">WUJUD ERP</div>
    <div class="org-name"><?= htmlspecialchars($org['legal_name']) ?></div>
    <nav class="app-nav">
      <a href="dashboard.php" class="<?= ($activeMenu ?? '') === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
      <?php if (has_access('kontak')): ?>
        <a href="contacts.php" class="<?= ($activeMenu ?? '') === 'kontak' ? 'active' : '' ?>" style="margin-top:12px; border-top:1px solid #3a362f; padding-top:16px;">Kontak</a>
        <a href="product-master.php" class="<?= ($activeMenu ?? '') === 'product_master' ? 'active' : '' ?>" style="margin-top:12px; border-top:1px solid #3a362f; padding-top:16px;">Master Produk (Emas)</a>
        <a href="product-categories.php" class="<?= ($activeMenu ?? '') === 'product_categories' ? 'active' : '' ?>">Kategori Produk</a>
        <a href="locations.php" class="<?= ($activeMenu ?? '') === 'locations' ? 'active' : '' ?>">Lokasi</a>
        <a href="stock-types.php" class="<?= ($activeMenu ?? '') === 'stock_types' ? 'active' : '' ?>">Tipe Stock</a>
        <a href="flow-transaksi.php" class="<?= ($activeMenu ?? '') === 'flow_transaksi' ? 'active' : '' ?>" style="margin-top:12px; border-top:1px solid #3a362f; padding-top:16px;">Flow Transaksi (Emas)</a>
        <a href="quotation-gold.php" class="<?= ($activeMenu ?? '') === 'quotation_gold' ? 'active' : '' ?>">Penawaran</a>
        <a href="purchase-order-gold.php" class="<?= ($activeMenu ?? '') === 'po_gold' ? 'active' : '' ?>">PO</a>
        <a href="goods-receipt-gold.php" class="<?= ($activeMenu ?? '') === 'goods_receipt_gold' ? 'active' : '' ?>">Penerimaan Barang</a>
        <a href="stock-transfer.php" class="<?= ($activeMenu ?? '') === 'stock_transfer' ? 'active' : '' ?>">Transfer Stock</a>
        <a href="sale-gold.php" class="<?= ($activeMenu ?? '') === 'sale_gold' ? 'active' : '' ?>">Penjualan</a>
        <a href="melting.php" class="<?= ($activeMenu ?? '') === 'melting' ? 'active' : '' ?>">Lebur Barang</a>
        <a href="supplier-return.php" class="<?= ($activeMenu ?? '') === 'supplier_return' ? 'active' : '' ?>">Retur Supplier</a>
        <a href="stock-report.php" class="<?= ($activeMenu ?? '') === 'stock_report' ? 'active' : '' ?>">Laporan Stock</a>
        <a href="item-lookup.php" class="<?= ($activeMenu ?? '') === 'item_lookup' ? 'active' : '' ?>">Cek Barang (PLU)</a>
      <?php endif; ?>
      <?php if (has_access('penawaran')): ?>
        <a href="projects.php" class="<?= ($activeMenu ?? '') === 'projects' ? 'active' : '' ?>" style="margin-top:12px; border-top:1px solid #3a362f; padding-top:16px;">Project</a>
        <a href="project-flow.php" class="<?= ($activeMenu ?? '') === 'project_flow' ? 'active' : '' ?>">Project Flow</a>
      <?php endif; ?>
      <a href="users.php" class="<?= ($activeMenu ?? '') === 'users' ? 'active' : '' ?>" style="margin-top:12px; border-top:1px solid #3a362f; padding-top:16px;">Admin User</a>
      <a href="roles.php" class="<?= ($activeMenu ?? '') === 'roles' ? 'active' : '' ?>">Roles &amp; Akses</a>
    </nav>
  </aside>
  <div class="app-main">
    <div class="app-topbar">
      <h1><?= htmlspecialchars($pageTitle ?? '') ?></h1>
      <div class="user">
        <div class="user-name"><?= htmlspecialchars($user['name']) ?> — <span class="pill <?= $org['role_name'] === 'Owner' ? 'owner' : '' ?>"><?= htmlspecialchars($org['role_name']) ?></span></div>
        <div class="user-actions">
          <a href="select-org.php">Ganti Organisasi</a>
          <a href="logout.php" class="btn btn-sm btn-ghost">Logout</a>
        </div>
      </div>
    </div>
