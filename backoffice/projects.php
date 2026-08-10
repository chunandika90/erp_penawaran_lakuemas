<?php
$pageTitle = 'Project';
$activeMenu = 'projects';
require __DIR__ . '/includes/header.php';
require_module_access('penawaran');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_project') {
            require_module_access('penawaran', $_POST['proj_id'] ? 'can_edit' : 'can_create');
            $id = (int) ($_POST['proj_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $contactId = (int) ($_POST['contact_id'] ?? 0) ?: null;
            $picUserId = (int) ($_POST['pic_user_id'] ?? 0) ?: null;
            $salesUserId = (int) ($_POST['sales_user_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes'] ?? '') ?: null;
            if ($name === '') throw new RuntimeException('Nama project wajib diisi.');
            if (!$contactId) throw new RuntimeException('Customer wajib dipilih.');
            if ($id > 0) {
                $pdo->prepare('UPDATE projects SET name=?, contact_id=?, pic_user_id=?, sales_user_id=?, notes=? WHERE id=? AND organization_id=?')
                    ->execute([$name, $contactId, $picUserId, $salesUserId, $notes, $id, $org['organization_id']]);
                $flash = ['ok', 'Project diperbarui.'];
            } else {
                $pdo->prepare('INSERT INTO projects (organization_id, name, contact_id, pic_user_id, sales_user_id, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$org['organization_id'], $name, $contactId, $picUserId, $salesUserId, $notes, $user['id']]);
                $flash = ['ok', 'Project dibuat.'];
            }
        } elseif ($action === 'quick_add_contact') {
            $cname = trim($_POST['contact_name'] ?? '');
            $cphone = trim($_POST['contact_phone'] ?? '') ?: null;
            if ($cname === '') throw new RuntimeException('Nama customer wajib diisi.');
            $pdo->prepare('INSERT INTO contacts (organization_id, type, name, phone) VALUES (?,?,?,?)')
                ->execute([$org['organization_id'], 'customer', $cname, $cphone]);
            $newContactId = (int) $pdo->lastInsertId();
            $qs = http_build_query([
                'reopen' => 1,
                'picked_contact' => $newContactId,
                'keep_proj_id' => $_POST['keep_proj_id'] ?? '',
                'keep_name' => $_POST['keep_name'] ?? '',
                'keep_pic' => $_POST['keep_pic'] ?? '',
                'keep_sales' => $_POST['keep_sales'] ?? '',
                'keep_notes' => $_POST['keep_notes'] ?? '',
            ]);
            header('Location: projects.php?' . $qs);
            exit;
        } elseif ($action === 'delete_project') {
            require_module_access('penawaran', 'can_delete');
            $id = (int) ($_POST['proj_id'] ?? 0);
            $linked = $pdo->prepare(
                'SELECT
                   (SELECT COUNT(*) FROM quotations_gold WHERE project_id=?) +
                   (SELECT COUNT(*) FROM purchase_orders_gold WHERE project_id=?) +
                   (SELECT COUNT(*) FROM gold_goods_receipts WHERE project_id=?) +
                   (SELECT COUNT(*) FROM sales_gold WHERE project_id=?) +
                   (SELECT COUNT(*) FROM melting_batches WHERE project_id=?) +
                   (SELECT COUNT(*) FROM stock_transfers WHERE project_id=?) +
                   (SELECT COUNT(*) FROM inventory_items WHERE project_id=?) AS cnt'
            );
            $linked->execute([$id, $id, $id, $id, $id, $id, $id]);
            if ((int) $linked->fetch()['cnt'] > 0) {
                throw new RuntimeException('Project masih punya dokumen/stock terkait (Penawaran, PO, Penerimaan, Penjualan, Lebur, Transfer, atau barang inventory). Lepas/hapus dulu sebelum menghapus project ini.');
            }
            $pdo->prepare('DELETE FROM projects WHERE id=? AND organization_id=?')->execute([$id, $org['organization_id']]);
            $flash = ['ok', 'Project dihapus.'];
        }
    } catch (Throwable $e) {
        $flash = ['error', $e->getMessage()];
    }
}

$projects = $pdo->prepare(
    "SELECT p.*, c.name AS contact_name, pic.name AS pic_name, sales.name AS sales_name,
       (SELECT COUNT(*) FROM quotations_gold q WHERE q.project_id=p.id) AS quotation_count,
       (SELECT COALESCE(SUM(qgl.qty*qgl.unit_price),0) FROM quotation_gold_lines qgl JOIN quotations_gold q ON q.id=qgl.quotation_id WHERE q.project_id=p.id) AS quotation_value
     FROM projects p
     LEFT JOIN contacts c ON c.id=p.contact_id
     LEFT JOIN users pic ON pic.id=p.pic_user_id
     LEFT JOIN users sales ON sales.id=p.sales_user_id
     WHERE p.organization_id=? ORDER BY p.created_at DESC"
);
$projects->execute([$org['organization_id']]);
$projects = $projects->fetchAll();

$contacts = $pdo->prepare('SELECT id, name FROM contacts WHERE organization_id=? ORDER BY name');
$contacts->execute([$org['organization_id']]);
$contacts = $contacts->fetchAll();

$orgMembers = $pdo->prepare('SELECT u.id, u.name FROM user_organization_roles uor JOIN users u ON u.id=uor.user_id WHERE uor.organization_id=? AND uor.status="active" ORDER BY u.name');
$orgMembers->execute([$org['organization_id']]);
$orgMembers = $orgMembers->fetchAll();

$detail = null;
$detailQuotations = [];
$detailStats = null;
if (!empty($_GET['id'])) {
    $stmt = $pdo->prepare(
        'SELECT p.*, c.name AS contact_name, pic.name AS pic_name, sales.name AS sales_name
         FROM projects p
         LEFT JOIN contacts c ON c.id=p.contact_id
         LEFT JOIN users pic ON pic.id=p.pic_user_id
         LEFT JOIN users sales ON sales.id=p.sales_user_id
         WHERE p.id=? AND p.organization_id=?'
    );
    $stmt->execute([(int) $_GET['id'], $org['organization_id']]);
    $detail = $stmt->fetch() ?: null;

    if ($detail) {
        $qStmt = $pdo->prepare(
            'SELECT q.*, c.name AS contact_name, (SELECT COALESCE(SUM(qty*unit_price),0) FROM quotation_gold_lines WHERE quotation_id=q.id) AS total
             FROM quotations_gold q JOIN contacts c ON c.id=q.contact_id
             WHERE q.project_id=? ORDER BY q.created_at'
        );
        $qStmt->execute([$detail['id']]);
        $detailQuotations = $qStmt->fetchAll();

        $revStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(sgl.unit_price),0) c FROM sales_gold_lines sgl
             JOIN sales_gold sg ON sg.id=sgl.sale_id WHERE sg.project_id=?'
        );
        $revStmt->execute([$detail['id']]);
        $revenue = (float) $revStmt->fetch()['c'];

        $poCount = (int) count_scalar($pdo, 'SELECT COUNT(*) FROM purchase_orders_gold WHERE project_id=?', $detail['id']);
        $saleCount = (int) count_scalar($pdo, 'SELECT COUNT(*) FROM sales_gold WHERE project_id=?', $detail['id']);

        $detailStats = ['revenue' => $revenue, 'quotation_count' => count($detailQuotations), 'po_count' => $poCount, 'sale_count' => $saleCount];
    }
}

function count_scalar(PDO $pdo, string $sql, int $projectId): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$projectId]);
    return (int) $stmt->fetchColumn();
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<?php if ($detail): ?>
  <a href="projects.php" style="font-size:13px;">&larr; Semua Project</a>
  <div class="card" style="margin:14px 0 20px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
      <div>
        <h2 style="margin:0 0 4px;"><?= htmlspecialchars($detail['name']) ?></h2>
        <div style="font-size:13px; color:var(--ink-muted);"><?= htmlspecialchars($detail['contact_name'] ?? '—') ?></div>
      </div>
      <div style="display:flex; gap:8px;">
        <?php if (has_access('penawaran', 'can_edit')): ?>
          <button class="btn btn-sm btn-ghost" type="button" data-open-modal="project-modal"
            onclick="document.getElementById('proj_id').value='<?= $detail['id'] ?>';
                     document.querySelector('#project-form [name=name]').value=<?= json_encode($detail['name']) ?>;
                     document.querySelector('#project-form [name=contact_id]').value='<?= (int) $detail['contact_id'] ?>';
                     document.querySelector('#project-form [name=pic_user_id]').value='<?= (int) $detail['pic_user_id'] ?>';
                     document.querySelector('#project-form [name=sales_user_id]').value='<?= (int) $detail['sales_user_id'] ?>';
                     document.querySelector('#project-form [name=notes]').value=<?= json_encode($detail['notes'] ?? '') ?>;">Edit</button>
        <?php endif; ?>
        <a class="btn btn-sm btn-ghost" href="project-flow.php?id=<?= $detail['id'] ?>">Lihat Flow Dokumen</a>
      </div>
    </div>
    <div class="txn-info-strip" style="margin-top:12px;">
      <div><span class="lbl">PIC</span><?= $detail['pic_name'] ? htmlspecialchars($detail['pic_name']) : '—' ?></div>
      <div><span class="lbl">Lead Sales</span><?= $detail['sales_name'] ? htmlspecialchars($detail['sales_name']) : '—' ?></div>
    </div>
    <?php if ($detail['notes']): ?><p style="margin-top:10px;"><?= nl2br(htmlspecialchars($detail['notes'])) ?></p><?php endif; ?>
  </div>

  <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px;">
    <div class="card"><div style="font-size:20px; font-weight:700;">Rp <?= number_format($detailStats['revenue'], 0, ',', '.') ?></div><div style="font-size:12px; color:var(--ink-muted);">Revenue (Penjualan)</div></div>
    <div class="card"><div style="font-size:20px; font-weight:700;"><?= $detailStats['quotation_count'] ?></div><div style="font-size:12px; color:var(--ink-muted);">Jumlah Penawaran</div></div>
    <div class="card"><div style="font-size:20px; font-weight:700;"><?= $detailStats['po_count'] ?></div><div style="font-size:12px; color:var(--ink-muted);">Jumlah PO</div></div>
    <div class="card"><div style="font-size:20px; font-weight:700;"><?= $detailStats['sale_count'] ?></div><div style="font-size:12px; color:var(--ink-muted);">Jumlah Penjualan</div></div>
  </div>

  <div class="card">
    <h3 style="margin-top:0;">Penawaran Vendor dalam Project ini</h3>
    <table class="data-table">
      <thead><tr><th>No. Dokumen</th><th>Vendor</th><th>Total</th><th>Status</th><th>Tanggal</th></tr></thead>
      <tbody>
        <?php foreach ($detailQuotations as $q): ?>
          <tr>
            <td><a href="quotation-gold.php"><?= htmlspecialchars($q['doc_number']) ?></a></td>
            <td><?= htmlspecialchars($q['contact_name']) ?></td>
            <td>Rp <?= number_format((float) $q['total'], 0, ',', '.') ?></td>
            <td><span class="pill"><?= strtoupper($q['status']) ?></span></td>
            <td class="num"><?= htmlspecialchars(date('d M Y', strtotime($q['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$detailQuotations): ?><tr><td colspan="5" style="text-align:center; color:var(--ink-muted);">Belum ada Penawaran di project ini. Tambahkan lewat halaman <a href="quotation-gold.php">Penawaran</a>.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

<?php else: ?>

  <div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
    <?php if (has_access('penawaran', 'can_create')): ?>
      <button class="btn btn-sm" data-open-modal="project-modal" onclick="document.getElementById('project-form').reset(); document.getElementById('proj_id').value='';">+ Project Baru</button>
    <?php endif; ?>
  </div>

  <div class="card">
    <table class="data-table">
      <thead><tr><th>Nama Project</th><th>Customer</th><th>PIC</th><th>Lead Sales</th><th>Jumlah Penawaran (Vendor)</th><th>Nilai Penawaran (Vendor)</th></tr></thead>
      <tbody>
        <?php foreach ($projects as $p): ?>
          <tr>
            <td><a href="projects.php?id=<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></a></td>
            <td><?= htmlspecialchars($p['contact_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($p['pic_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($p['sales_name'] ?? '—') ?></td>
            <td><?= $p['quotation_count'] ?></td>
            <td>Rp <?= number_format((float) $p['quotation_value'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$projects): ?><tr><td colspan="6" style="text-align:center; color:var(--ink-muted);">Belum ada project.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="modal-scrim" id="project-modal">
    <div class="modal-card">
      <div class="modal-head"><h3>Project</h3><button class="modal-close" data-close-modal="project-modal">&times;</button></div>
      <form method="post" action="projects.php" id="project-form">
        <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_project">
          <input type="hidden" name="proj_id" id="proj_id">
          <div class="field"><label>Nama Project</label><input type="text" name="name" required></div>
          <div class="field">
            <label>Customer</label>
            <div style="display:flex; gap:6px;">
              <select name="contact_id" id="project-contact-select" required style="flex:1;">
                <option value="">— Pilih Customer —</option>
                <?php foreach ($contacts as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-sm btn-ghost" id="quick-contact-open-btn">+ Baru</button>
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label>PIC (penanggung jawab internal)</label>
              <select name="pic_user_id">
                <option value="">— Belum ditentukan —</option>
                <?php foreach ($orgMembers as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Lead Sales</label>
              <select name="sales_user_id">
                <option value="">— Belum ditentukan —</option>
                <?php foreach ($orgMembers as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="field"><label>Catatan</label><input type="text" name="notes"></div>
        </div>
        <div class="modal-foot">
          <button type="button" class="btn btn-ghost" data-close-modal="project-modal">Batal</button>
          <button type="submit" class="btn">Simpan</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-scrim" id="quick-contact-modal">
    <div class="modal-card">
      <div class="modal-head"><h3>Customer Baru</h3><button class="modal-close" data-close-modal="quick-contact-modal">&times;</button></div>
      <form method="post" action="projects.php">
        <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="quick_add_contact">
          <input type="hidden" name="keep_proj_id" id="keep_proj_id">
          <input type="hidden" name="keep_name" id="keep_name">
          <input type="hidden" name="keep_pic" id="keep_pic">
          <input type="hidden" name="keep_sales" id="keep_sales">
          <input type="hidden" name="keep_notes" id="keep_notes">
          <div class="field"><label>Nama Customer</label><input type="text" name="contact_name" required></div>
          <div class="field"><label>Telepon (opsional)</label><input type="text" name="contact_phone"></div>
        </div>
        <div class="modal-foot">
          <button type="button" class="btn btn-ghost" data-close-modal="quick-contact-modal">Batal</button>
          <button type="submit" class="btn">Simpan &amp; Pilih</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  (function () {
    var openBtn = document.getElementById('quick-contact-open-btn');
    if (openBtn) {
      openBtn.addEventListener('click', function () {
        document.getElementById('keep_proj_id').value = document.getElementById('proj_id').value;
        document.getElementById('keep_name').value = document.querySelector('#project-form [name=name]').value;
        document.getElementById('keep_pic').value = document.querySelector('#project-form [name=pic_user_id]').value;
        document.getElementById('keep_sales').value = document.querySelector('#project-form [name=sales_user_id]').value;
        document.getElementById('keep_notes').value = document.querySelector('#project-form [name=notes]').value;
        document.getElementById('quick-contact-modal').classList.add('open');
      });
    }
    <?php if (isset($_GET['reopen'])): ?>
    document.getElementById('proj_id').value = <?= json_encode($_GET['keep_proj_id'] ?? '') ?>;
    document.querySelector('#project-form [name=name]').value = <?= json_encode($_GET['keep_name'] ?? '') ?>;
    document.getElementById('project-contact-select').value = <?= json_encode((string) (int) ($_GET['picked_contact'] ?? 0)) ?>;
    document.querySelector('#project-form [name=pic_user_id]').value = <?= json_encode($_GET['keep_pic'] ?? '') ?>;
    document.querySelector('#project-form [name=sales_user_id]').value = <?= json_encode($_GET['keep_sales'] ?? '') ?>;
    document.querySelector('#project-form [name=notes]').value = <?= json_encode($_GET['keep_notes'] ?? '') ?>;
    document.getElementById('project-modal').classList.add('open');
    <?php endif; ?>
  })();
  </script>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
