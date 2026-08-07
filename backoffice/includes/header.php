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
      <?php
      $moduleLinks = [
          'penawaran' => 'quotations.php', 'invoicing' => 'invoices.php', 'po' => 'purchase-orders.php',
          'spk' => 'spk.php', 'penerimaan' => 'goods-receipts.php', 'do' => 'delivery-orders.php',
          'kuitansi' => 'kuitansi.php', 'kontak' => 'contacts.php', 'laporan' => 'laporan.php',
      ];
      foreach (MODULES as $key => $label):
          if ($key === 'dashboard' || !has_access($key)) continue;
          $href = $moduleLinks[$key] ?? '#';
      ?>
        <a href="<?= htmlspecialchars($href) ?>" class="<?= ($activeMenu ?? '') === $key ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
        <?php if ($key === 'invoicing' && has_access('po')): ?>
          <a href="material-requests.php" class="<?= ($activeMenu ?? '') === 'material_requests' ? 'active' : '' ?>">Request Material</a>
        <?php endif; ?>
        <?php if ($key === 'laporan'): ?>
          <a href="inventory-report.php" class="<?= ($activeMenu ?? '') === 'inventory_report' ? 'active' : '' ?>">Laporan Inventory</a>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (has_access('kontak')): ?>
        <a href="products.php" class="<?= ($activeMenu ?? '') === 'products' ? 'active' : '' ?>" style="margin-top:12px; border-top:1px solid #3a362f; padding-top:16px;">Produk &amp; Tier</a>
        <a href="materials.php" class="<?= ($activeMenu ?? '') === 'materials' ? 'active' : '' ?>">Material</a>
        <a href="characteristics.php" class="<?= ($activeMenu ?? '') === 'characteristics' ? 'active' : '' ?>">Karakteristik Produk</a>
        <a href="warehouses.php" class="<?= ($activeMenu ?? '') === 'warehouses' ? 'active' : '' ?>">Gudang</a>
        <a href="terms.php" class="<?= ($activeMenu ?? '') === 'terms' ? 'active' : '' ?>">Syarat &amp; Ketentuan</a>
        <a href="product-master.php" class="<?= ($activeMenu ?? '') === 'product_master' ? 'active' : '' ?>" style="margin-top:12px; border-top:1px solid #3a362f; padding-top:16px;">Master Produk (Emas)</a>
        <a href="product-categories.php" class="<?= ($activeMenu ?? '') === 'product_categories' ? 'active' : '' ?>">Kategori Produk</a>
        <a href="locations.php" class="<?= ($activeMenu ?? '') === 'locations' ? 'active' : '' ?>">Lokasi</a>
        <a href="stock-types.php" class="<?= ($activeMenu ?? '') === 'stock_types' ? 'active' : '' ?>">Tipe Stock</a>
        <a href="flow-transaksi.php" class="<?= ($activeMenu ?? '') === 'flow_transaksi' ? 'active' : '' ?>" style="margin-top:12px; border-top:1px solid #3a362f; padding-top:16px;">Flow Transaksi (Emas)</a>
        <a href="goods-receipt-gold.php" class="<?= ($activeMenu ?? '') === 'goods_receipt_gold' ? 'active' : '' ?>">Penerimaan Barang</a>
        <a href="stock-transfer.php" class="<?= ($activeMenu ?? '') === 'stock_transfer' ? 'active' : '' ?>">Transfer Stock</a>
        <a href="melting.php" class="<?= ($activeMenu ?? '') === 'melting' ? 'active' : '' ?>">Lebur Barang</a>
        <a href="supplier-return.php" class="<?= ($activeMenu ?? '') === 'supplier_return' ? 'active' : '' ?>">Retur Supplier</a>
        <a href="stock-report.php" class="<?= ($activeMenu ?? '') === 'stock_report' ? 'active' : '' ?>">Laporan Stock</a>
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
