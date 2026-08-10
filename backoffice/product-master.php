<?php
/**
 * Master produk (SKU final) buat vertikal Lakuemas (emas) — tampilan Kanban
 * (kolom = Group Product) dengan opsi switch ke tabel/detail. Kategori dipilih
 * lewat 3 dropdown cascading (Group > Brand/Jenis Item > Pecahan) yang ambil
 * pola dari tree product_categories. Nonaktifin = soft delete.
 */
$pageTitle = 'Master Produk';
$activeMenu = 'product_master';
require __DIR__ . '/includes/header.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_product') {
            require_module_access('kontak', $_POST['product_id'] ? 'can_edit' : 'can_create');
            $id = (int) ($_POST['product_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $categoryId = (int) ($_POST['category_id'] ?? 0) ?: null;
            $unit = trim($_POST['unit'] ?? '') ?: 'pcs';
            $basePrice = (float) ($_POST['base_price'] ?? 0);
            $berat = trim($_POST['berat'] ?? '');
            $kadar = trim($_POST['kadar'] ?? '');
            $catatan = trim($_POST['catatan'] ?? '');
            if ($name === '') throw new RuntimeException('Nama produk wajib diisi.');
            if (!$categoryId) throw new RuntimeException('Kategori produk wajib dipilih.');
            $catCheck = $pdo->prepare('SELECT id FROM product_categories WHERE id=? AND organization_id=?');
            $catCheck->execute([$categoryId, $org['organization_id']]);
            if (!$catCheck->fetch()) throw new RuntimeException('Kategori tidak valid.');
            $extraSpecs = json_encode(array_filter(['berat' => $berat, 'kadar' => $kadar, 'catatan' => $catatan]));
            if ($id > 0) {
                $pdo->prepare('UPDATE products SET name=?, category_id=?, unit=?, base_price=?, extra_specs=? WHERE id=? AND organization_id=?')
                    ->execute([$name, $categoryId, $unit, $basePrice, $extraSpecs, $id, $org['organization_id']]);
                $flash = ['ok', 'Produk diperbarui.'];
            } else {
                $pdo->prepare('INSERT INTO products (organization_id, category_id, name, unit, base_price, extra_specs) VALUES (?,?,?,?,?,?)')
                    ->execute([$org['organization_id'], $categoryId, $name, $unit, $basePrice, $extraSpecs]);
                $flash = ['ok', 'Produk ditambahkan.'];
            }
        } elseif ($action === 'toggle_active') {
            require_module_access('kontak', 'can_edit');
            $id = (int) ($_POST['product_id'] ?? 0);
            $pdo->prepare('UPDATE products SET is_active = NOT is_active WHERE id=? AND organization_id=?')
                ->execute([$id, $org['organization_id']]);
            $flash = ['ok', 'Status produk diperbarui.'];
        }
    } catch (Throwable $e) {
        $flash = ['error', str_contains($e->getMessage(), 'foreign key') ? 'Produk ini masih dipakai di transaksi, tidak bisa diubah.' : $e->getMessage()];
    }
}

$catRows = $pdo->prepare('SELECT id, parent_id, name, is_active FROM product_categories WHERE organization_id=? ORDER BY parent_id IS NOT NULL, sort_order, name');
$catRows->execute([$org['organization_id']]);
$catRows = $catRows->fetchAll();
$catById = [];
foreach ($catRows as $c) $catById[$c['id']] = $c;

function cat_root_id(array $catById, int $id): int
{
    $node = $catById[$id] ?? null;
    while ($node && $node['parent_id']) {
        $node = $catById[$node['parent_id']] ?? null;
    }
    return $node ? (int) $node['id'] : 0;
}
function cat_breadcrumb(array $catById, int $id): string
{
    $parts = [];
    $node = $catById[$id] ?? null;
    while ($node) {
        array_unshift($parts, $node['name']);
        $node = $node['parent_id'] ? ($catById[$node['parent_id']] ?? null) : null;
    }
    return implode(' › ', $parts);
}

$products = $pdo->prepare('SELECT * FROM products WHERE organization_id=? AND category_id IS NOT NULL ORDER BY name');
$products->execute([$org['organization_id']]);
$products = $products->fetchAll();

// Kanban 3 tier: Group Product (kolom) -> Brand (sub-grup di dalem kolom) ->
// Pecahan/produk (kartu). Brand = kategori anak langsung dari root. Produk
// yang category_id-nya LANGSUNG ke root (gak ada level Brand di antaranya)
// ditampung di sub-grup "Lainnya" di kolom itu.
$roots = array_values(array_filter($catRows, fn($c) => !$c['parent_id']));
$columns = [];
foreach ($roots as $root) {
    $brands = array_values(array_filter($catRows, fn($c) => $c['parent_id'] == $root['id']));
    $groups = [];
    foreach ($brands as $brand) $groups[$brand['id']] = ['label' => $brand['name'], 'items' => []];
    $groups['_other'] = ['label' => 'Lainnya', 'items' => []];
    $columns[$root['id']] = ['root' => $root, 'groups' => $groups];
}
foreach ($products as $p) {
    $rootId = cat_root_id($catById, (int) $p['category_id']);
    if (!isset($columns[$rootId])) continue;
    $catId = (int) $p['category_id'];
    $groupKey = ($catId !== $rootId && isset($columns[$rootId]['groups'][$catId])) ? $catId : '_other';
    $columns[$rootId]['groups'][$groupKey]['items'][] = $p;
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if (!$roots): ?>
  <div class="card txn-empty">Belum ada Group Product. Bikin dulu di <a href="product-categories.php">Kategori Produk</a> sebelum nambah produk.</div>
<?php else: ?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; gap:10px; flex-wrap:wrap;">
  <div style="display:flex; gap:6px;">
    <button type="button" class="btn btn-sm" id="btn-view-drilldown" onclick="setView('drilldown')">Drill-down</button>
    <button type="button" class="btn btn-sm btn-ghost" id="btn-view-kanban" onclick="setView('kanban')">Kanban</button>
    <button type="button" class="btn btn-sm btn-ghost" id="btn-view-table" onclick="setView('table')">Tabel</button>
    <button type="button" class="pf-orient-toggle" id="pm-orient-toggle" style="margin-left:6px; display:none;">⇄ Vertikal</button>
  </div>
  <?php if (has_access('kontak', 'can_create')): ?>
    <button class="btn btn-sm" type="button" onclick="openNewProductModal()">+ Produk Baru</button>
  <?php endif; ?>
</div>

<div id="view-drilldown">
  <div class="card" style="margin-bottom:14px; padding:12px 16px;">
    <div id="drill-breadcrumb" class="drill-breadcrumb"></div>
  </div>
  <div id="drill-grid" class="drill-grid"></div>
</div>

<div id="view-kanban" class="flow-board-wrap" style="display:none;">
  <div class="flow-board" id="pm-board" style="overflow-x:auto;">
    <?php foreach ($columns as $col): $root = $col['root']; $groups = $col['groups'];
      $totalCount = array_sum(array_map(fn($g) => count($g['items']), $groups));
    ?>
      <div class="flow-col" style="flex:0 0 270px; width:270px;">
        <div class="flow-col-head"><span><?= htmlspecialchars($root['name']) ?></span><span class="flow-count"><?= $totalCount ?></span></div>
        <?php if (!$totalCount): ?><div class="flow-empty">Belum ada produk.</div><?php endif; ?>
        <?php foreach ($groups as $groupKey => $group): if (!$group['items']) continue;
          $groupCatId = $groupKey === '_other' ? $root['id'] : $groupKey;
        ?>
          <div class="kanban-subgroup">
            <div class="kanban-subgroup-head"><?= htmlspecialchars($group['label']) ?> <span class="flow-count"><?= count($group['items']) ?></span></div>
            <?php foreach ($group['items'] as $p): ?>
              <div class="flow-card" style="cursor:pointer; <?= $p['is_active'] ? '' : 'opacity:.55;' ?>" onclick="openProductModal(<?= $p['id'] ?>)">
                <div class="flow-doc"><?= htmlspecialchars($p['name']) ?><?php if (!$p['is_active']): ?> <span class="pill">Nonaktif</span><?php endif; ?></div>
                <div class="flow-sub">Rp <?= number_format((float) $p['base_price'], 0, ',', '.') ?> / <?= htmlspecialchars($p['unit']) ?></div>
              </div>
            <?php endforeach; ?>
            <?php if (has_access('kontak', 'can_create')): ?>
              <a class="flow-add" href="#" onclick="openProductModal(0, <?= $groupCatId ?>); return false;">+ Tambah di <?= htmlspecialchars($group['label']) ?></a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (has_access('kontak', 'can_create')): ?>
          <a class="flow-add" href="#" onclick="openProductModal(0, <?= $root['id'] ?>); return false;">+ Tambah di <?= htmlspecialchars($root['name']) ?></a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div id="view-table" class="card" style="display:none;">
  <table class="data-table">
    <thead><tr><th>Nama</th><th>Kategori</th><th class="num">Harga</th><th>Satuan</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><?= htmlspecialchars($p['name']) ?></td>
          <td><?= htmlspecialchars(cat_breadcrumb($catById, (int) $p['category_id'])) ?></td>
          <td class="num">Rp <?= number_format((float) $p['base_price'], 0, ',', '.') ?></td>
          <td><?= htmlspecialchars($p['unit']) ?></td>
          <td><span class="pill <?= $p['is_active'] ? 'active' : '' ?>"><?= $p['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td><button class="btn btn-sm btn-ghost" type="button" onclick="openProductModal(<?= $p['id'] ?>)">Detail</button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$products): ?><tr><td colspan="6" style="text-align:center; color:var(--ink-muted);">Belum ada produk.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<div class="modal-scrim" id="product-modal">
  <div class="modal-card" style="width:520px;">
    <div class="modal-head"><h3 id="product-modal-title">Produk</h3><button class="modal-close" data-close-modal="product-modal">&times;</button></div>
    <form method="post" id="product-form">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_product">
        <input type="hidden" name="product_id" id="p_id">
        <div class="field"><label>Nama Produk</label><input type="text" name="name" id="p_name" required></div>
        <div class="field-row">
          <div class="field">
            <label>Group Product</label>
            <select id="p_cat_l0" onchange="onCatLevelChange(0)"><option value="">— pilih —</option></select>
          </div>
          <div class="field">
            <label>Brand / Jenis Item</label>
            <select id="p_cat_l1" onchange="onCatLevelChange(1)"><option value="">—</option></select>
          </div>
        </div>
        <div class="field" id="p_cat_l2_wrap" style="display:none;">
          <label>Pecahan</label>
          <select id="p_cat_l2" onchange="onCatLevelChange(2)"><option value="">—</option></select>
        </div>
        <input type="hidden" name="category_id" id="p_category_id">
        <div class="field-row">
          <div class="field"><label>Satuan</label><input type="text" name="unit" id="p_unit" value="pcs"></div>
          <div class="field"><label>Harga</label><input type="text" inputmode="numeric" class="rupiah-input" name="base_price" id="p_base_price" value="0"></div>
        </div>
        <div class="field-row">
          <div class="field"><label>Berat (opsional)</label><input type="text" name="berat" id="p_berat" placeholder="cth. 5 gram"></div>
          <div class="field"><label>Kadar (opsional)</label><input type="text" name="kadar" id="p_kadar" placeholder="cth. 24K / 999"></div>
        </div>
        <div class="field"><label>Catatan (opsional)</label><textarea name="catatan" id="p_catatan" rows="2"></textarea></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" id="p-toggle-active-btn" style="display:none;" onclick="toggleProductActive()"></button>
        <button type="button" class="btn btn-ghost" data-close-modal="product-modal">Batal</button>
        <button type="submit" class="btn">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
var CATEGORIES = <?= json_encode(array_map(fn($c) => ['id' => (int) $c['id'], 'parent_id' => $c['parent_id'] ? (int) $c['parent_id'] : null, 'name' => $c['name'], 'is_active' => (bool) $c['is_active']], $catRows)) ?>;
var PRODUCTS = <?= json_encode(array_map(fn($p) => [
    'id' => (int) $p['id'], 'name' => $p['name'], 'category_id' => (int) $p['category_id'],
    'unit' => $p['unit'], 'base_price' => (float) $p['base_price'], 'is_active' => (bool) $p['is_active'],
    'extra_specs' => json_decode($p['extra_specs'] ?? '{}', true) ?: [],
], $products)) ?>;
var CAT_CHILDREN = {};
CATEGORIES.forEach(function (c) {
  var key = c.parent_id || 0;
  CAT_CHILDREN[key] = CAT_CHILDREN[key] || [];
  CAT_CHILDREN[key].push(c);
});
var CAT_BY_ID = {};
CATEGORIES.forEach(function (c) { CAT_BY_ID[c.id] = c; });

function fillSelect(sel, items, placeholder) {
  sel.innerHTML = '<option value="">' + placeholder + '</option>';
  items.forEach(function (c) {
    var opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name + (c.is_active ? '' : ' (nonaktif)');
    sel.appendChild(opt);
  });
}

function refreshCategorySelects() {
  fillSelect(document.getElementById('p_cat_l0'), CAT_CHILDREN[0] || [], '— pilih —');
}
refreshCategorySelects();

function onCatLevelChange(level) {
  if (level === 0) {
    var l0 = document.getElementById('p_cat_l0').value;
    var children = l0 ? (CAT_CHILDREN[l0] || []) : [];
    fillSelect(document.getElementById('p_cat_l1'), children, children.length ? '— pilih —' : '(tidak ada sub-kategori)');
    document.getElementById('p_cat_l2_wrap').style.display = 'none';
    fillSelect(document.getElementById('p_cat_l2'), [], '—');
    updateCategoryId();
  } else if (level === 1) {
    var l1 = document.getElementById('p_cat_l1').value;
    var children = l1 ? (CAT_CHILDREN[l1] || []) : [];
    if (children.length) {
      document.getElementById('p_cat_l2_wrap').style.display = 'block';
      fillSelect(document.getElementById('p_cat_l2'), children, '— pilih —');
    } else {
      document.getElementById('p_cat_l2_wrap').style.display = 'none';
    }
    updateCategoryId();
  } else {
    updateCategoryId();
  }
}

function updateCategoryId() {
  var l2 = document.getElementById('p_cat_l2').value;
  var l1 = document.getElementById('p_cat_l1').value;
  var l0 = document.getElementById('p_cat_l0').value;
  document.getElementById('p_category_id').value = l2 || l1 || l0 || '';
}

function selectCategoryPath(categoryId) {
  var path = [];
  var node = CAT_BY_ID[categoryId];
  while (node) { path.unshift(node); node = node.parent_id ? CAT_BY_ID[node.parent_id] : null; }
  refreshCategorySelects();
  if (path[0]) {
    document.getElementById('p_cat_l0').value = path[0].id;
    onCatLevelChange(0);
  }
  if (path[1]) {
    document.getElementById('p_cat_l1').value = path[1].id;
    onCatLevelChange(1);
  }
  if (path[2]) {
    document.getElementById('p_cat_l2').value = path[2].id;
  }
  updateCategoryId();
}

// Tombol "+ Produk Baru" di toolbar atas -- kalau lagi drill-down ke suatu
// kategori/brand, auto isi kategorinya dari posisi drill-down sekarang.
function openNewProductModal() {
  var defaultCat = (typeof drillPath !== 'undefined' && drillPath.length) ? drillPath[drillPath.length - 1] : null;
  openProductModal(0, defaultCat);
}

var currentProductId = 0;
// defaultCategoryId (opsional): dipakai buat "+ Tambah" yang tau konteksnya
// (lagi drill-down ke kategori tertentu, atau klik "+ Tambah" di sub-grup
// kanban tertentu) -- kategorinya auto ke-isi, gak perlu cari-cari lagi.
function openProductModal(id, defaultCategoryId) {
  currentProductId = id;
  var form = document.getElementById('product-form');
  form.reset();
  document.getElementById('p_id').value = id || '';
  var toggleBtn = document.getElementById('p-toggle-active-btn');
  if (id) {
    var p = PRODUCTS.find(function (x) { return x.id === id; });
    if (!p) return;
    document.getElementById('product-modal-title').textContent = 'Edit Produk';
    document.getElementById('p_name').value = p.name;
    document.getElementById('p_unit').value = p.unit;
    document.getElementById('p_base_price').value = p.base_price;
    document.getElementById('p_base_price').dispatchEvent(new Event('input'));
    document.getElementById('p_berat').value = p.extra_specs.berat || '';
    document.getElementById('p_kadar').value = p.extra_specs.kadar || '';
    document.getElementById('p_catatan').value = p.extra_specs.catatan || '';
    selectCategoryPath(p.category_id);
    toggleBtn.style.display = 'inline-block';
    toggleBtn.textContent = p.is_active ? 'Nonaktifkan' : 'Aktifkan';
  } else {
    document.getElementById('product-modal-title').textContent = 'Produk Baru';
    if (defaultCategoryId) {
      selectCategoryPath(defaultCategoryId);
    } else {
      refreshCategorySelects();
      document.getElementById('p_cat_l2_wrap').style.display = 'none';
    }
    toggleBtn.style.display = 'none';
  }
  document.getElementById('product-modal').classList.add('open');
}
function toggleProductActive() {
  if (!currentProductId) return;
  __submitDeleteForm('toggle_active', { product_id: currentProductId });
}

function setView(mode) {
  document.getElementById('view-drilldown').style.display = mode === 'drilldown' ? '' : 'none';
  document.getElementById('view-kanban').style.display = mode === 'kanban' ? '' : 'none';
  document.getElementById('view-table').style.display = mode === 'table' ? '' : 'none';
  document.getElementById('btn-view-drilldown').className = 'btn btn-sm' + (mode === 'drilldown' ? '' : ' btn-ghost');
  document.getElementById('btn-view-kanban').className = 'btn btn-sm' + (mode === 'kanban' ? '' : ' btn-ghost');
  document.getElementById('btn-view-table').className = 'btn btn-sm' + (mode === 'table' ? '' : ' btn-ghost');
  document.getElementById('pm-orient-toggle').style.display = mode === 'kanban' ? '' : 'none';
  if (mode === 'drilldown') renderDrilldown();
}

// ===== Drill-down (pivot): Group Product -> Brand -> Pecahan, tiap level
// bisa dipilih "lihat semua" (skip sisa drill) atau lanjut drill 1-1. =====
var drillPath = [];
var drillShowAll = false;

function productsUnderCat(catId) {
  var direct = PRODUCTS.filter(function (p) { return p.category_id === catId; });
  var children = CAT_CHILDREN[catId] || [];
  children.forEach(function (c) { direct = direct.concat(productsUnderCat(c.id)); });
  return direct;
}
function countUnderCat(catId) { return productsUnderCat(catId).length; }

function renderProductCards(list) {
  if (!list.length) return '<div class="flow-empty" style="grid-column:1/-1;">Belum ada produk di sini.</div>';
  return list.map(function (p) {
    return '<div class="drill-card drill-card-product" onclick="openProductModal(' + p.id + ')" style="' + (p.is_active ? '' : 'opacity:.55;') + '">' +
      '<div class="drill-card-title">' + p.name.replace(/</g, '&lt;') + (p.is_active ? '' : ' <span class="pill">Nonaktif</span>') + '</div>' +
      '<div class="drill-card-sub">Rp ' + Math.round(p.base_price).toLocaleString('id-ID') + ' / ' + p.unit + '</div>' +
      '</div>';
  }).join('');
}
function renderCatCards(cats, isRoot, currentId) {
  var html = cats.map(function (c) {
    var n = countUnderCat(c.id);
    return '<div class="drill-card" onclick="drillInto(' + c.id + ')">' +
      '<div class="drill-card-title">' + c.name.replace(/</g, '&lt;') + '</div>' +
      '<div class="drill-card-sub">' + n + ' produk</div>' +
      '</div>';
  }).join('');
  // Total lewat productsUnderCat(currentId), BUKAN sum children -- kalau
  // cuma sum children, produk yang nempel LANGSUNG ke currentId (gak punya
  // Brand, ketampung di grup "Lainnya") ke-skip dari hitungan.
  var total = currentId ? countUnderCat(currentId) : PRODUCTS.length;
  html += '<div class="drill-card drill-card-viewall" onclick="drillShowAll = true; renderDrilldown();">' +
    '<div class="drill-card-title">📋 Lihat Semua</div>' +
    '<div class="drill-card-sub">' + total + ' produk' + (isRoot ? ' (semua kategori)' : ' di sini') + '</div>' +
    '</div>';
  return html;
}

function drillInto(catId) { drillPath.push(catId); drillShowAll = false; renderDrilldown(); }
function drillTo(index) { drillPath = index < 0 ? [] : drillPath.slice(0, index + 1); drillShowAll = false; renderDrilldown(); }

function renderDrilldown() {
  var crumbEl = document.getElementById('drill-breadcrumb');
  var gridEl = document.getElementById('drill-grid');
  if (!crumbEl || !gridEl) return;

  var crumbs = ['<a href="#" onclick="drillTo(-1); return false;">Semua Produk</a>'];
  drillPath.forEach(function (id, i) {
    var cat = CAT_BY_ID[id];
    crumbs.push('<a href="#" onclick="drillTo(' + i + '); return false;">' + (cat ? cat.name.replace(/</g, '&lt;') : '?') + '</a>');
  });
  if (drillShowAll) crumbs.push('<span>Semua</span>');
  crumbEl.innerHTML = crumbs.join(' <span class="drill-sep">›</span> ');

  var currentId = drillPath.length ? drillPath[drillPath.length - 1] : null;
  var children = currentId ? (CAT_CHILDREN[currentId] || []) : (CAT_CHILDREN[0] || []);

  if (drillShowAll || !children.length) {
    var list = currentId ? productsUnderCat(currentId) : PRODUCTS.slice();
    gridEl.innerHTML = renderProductCards(list);
  } else {
    gridEl.innerHTML = renderCatCards(children, !currentId, currentId);
  }
}

(function () {
  var board = document.getElementById('pm-board');
  var toggle = document.getElementById('pm-orient-toggle');
  if (!board || !toggle) return;
  var vertical = localStorage.getItem('pm-board-vertical') === '1';
  function apply() {
    board.classList.toggle('vertical', vertical);
    toggle.textContent = vertical ? '⇄ Horizontal' : '⇄ Vertikal';
  }
  apply();
  toggle.addEventListener('click', function () {
    vertical = !vertical;
    localStorage.setItem('pm-board-vertical', vertical ? '1' : '0');
    apply();
  });
})();

renderDrilldown();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
