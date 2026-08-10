<?php
/**
 * @var string $pageTitle
 * @var string $activeMenu
 */
require_once __DIR__ . '/../../backoffice-shared/auth.php';
require_once __DIR__ . '/../../backoffice-shared/modules.php';
require_once __DIR__ . '/icons.php';
$user = require_login();
$org = require_org();
$__active = $activeMenu ?? '';
function nav_link(string $key, string $href, string $label, string $active): string
{
    $cls = $key === $active ? 'active' : '';
    return '<a href="' . htmlspecialchars($href) . '" class="' . $cls . '">' . nav_icon($key) . '<span>' . htmlspecialchars($label) . '</span></a>';
}
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
  <button type="button" class="mobile-nav-toggle" id="mobile-nav-toggle" aria-label="Buka menu">
    <span></span><span></span><span></span>
  </button>
  <aside class="app-sidebar" id="app-sidebar">
    <div class="sidebar-brand">
      <div class="brand-mark">W</div>
      <div>
        <div class="brand">WUJUD ERP</div>
        <div class="org-name"><?= htmlspecialchars($org['legal_name']) ?></div>
      </div>
    </div>
    <nav class="app-nav">
      <div class="app-nav-group">
        <?= nav_link('dashboard', 'dashboard.php', 'Dashboard', $__active) ?>
      </div>
      <?php if (has_access('kontak')): ?>
        <div class="app-nav-group">
          <div class="app-nav-label">Kontak &amp; Master Data</div>
          <?= nav_link('kontak', 'contacts.php', 'Kontak', $__active) ?>
          <?= nav_link('product_master', 'product-master.php', 'Master Produk', $__active) ?>
          <?= nav_link('product_categories', 'product-categories.php', 'Kategori Produk', $__active) ?>
          <?= nav_link('locations', 'locations.php', 'Lokasi', $__active) ?>
          <?= nav_link('stock_types', 'stock-types.php', 'Tipe Stock', $__active) ?>
        </div>
        <div class="app-nav-group">
          <div class="app-nav-label">Transaksi Emas</div>
          <?= nav_link('flow_transaksi', 'flow-transaksi.php', 'Flow Transaksi', $__active) ?>
          <?= nav_link('quotation_gold', 'quotation-gold.php', 'Penawaran', $__active) ?>
          <?= nav_link('po_gold', 'purchase-order-gold.php', 'PO', $__active) ?>
          <?= nav_link('goods_receipt_gold', 'goods-receipt-gold.php', 'Penerimaan Barang', $__active) ?>
          <?= nav_link('stock_transfer', 'stock-transfer.php', 'Transfer Stock', $__active) ?>
          <?= nav_link('sale_gold', 'sale-gold.php', 'Penjualan', $__active) ?>
          <?= nav_link('melting', 'melting.php', 'Lebur Barang', $__active) ?>
          <?= nav_link('supplier_return', 'supplier-return.php', 'Retur Supplier', $__active) ?>
        </div>
        <div class="app-nav-group">
          <div class="app-nav-label">Laporan</div>
          <?= nav_link('stock_report', 'stock-report.php', 'Laporan Stock', $__active) ?>
          <?= nav_link('item_lookup', 'item-lookup.php', 'Cek Barang (PLU)', $__active) ?>
        </div>
      <?php endif; ?>
      <?php if (has_access('penawaran')): ?>
        <div class="app-nav-group">
          <div class="app-nav-label">Project</div>
          <?= nav_link('projects', 'projects.php', 'Project', $__active) ?>
          <?= nav_link('project_flow', 'project-flow.php', 'Project Flow', $__active) ?>
        </div>
      <?php endif; ?>
      <div class="app-nav-group">
        <div class="app-nav-label">Admin</div>
        <?= nav_link('users', 'users.php', 'Admin User', $__active) ?>
        <?= nav_link('roles', 'roles.php', 'Roles &amp; Akses', $__active) ?>
      </div>
    </nav>
  </aside>
  <div class="app-shell-scrim" id="app-shell-scrim"></div>
  <div class="app-main">
    <div class="app-topbar">
      <h1><?= htmlspecialchars($pageTitle ?? '') ?></h1>
      <div class="user">
        <div class="user-name">
          <span class="user-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
          <span><?= htmlspecialchars($user['name']) ?> <span class="pill <?= $org['role_name'] === 'Owner' ? 'owner' : '' ?>"><?= htmlspecialchars($org['role_name']) ?></span></span>
        </div>
        <div class="user-actions">
          <a href="select-org.php">Ganti Organisasi</a>
          <a href="logout.php" class="btn btn-sm btn-ghost">Logout</a>
        </div>
      </div>
    </div>
