<?php
$pageTitle = 'Syarat & Ketentuan';
$activeMenu = 'terms';
require __DIR__ . '/includes/header.php';
require_module_access('kontak');

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_terms') {
            require_module_access('kontak', $_POST['terms_id'] ? 'can_edit' : 'can_create');
            $id = (int) ($_POST['terms_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            if ($title === '' || $content === '') throw new RuntimeException('Judul dan isi wajib diisi.');
            if ($id > 0) {
                $pdo->prepare('UPDATE terms_conditions SET title=?, content=? WHERE id=? AND organization_id=?')
                    ->execute([$title, $content, $id, $org['organization_id']]);
                $flash = ['ok', 'Syarat & Ketentuan diperbarui.'];
            } else {
                $pdo->prepare('INSERT INTO terms_conditions (organization_id, title, content) VALUES (?,?,?)')
                    ->execute([$org['organization_id'], $title, $content]);
                $flash = ['ok', 'Syarat & Ketentuan ditambahkan.'];
            }
        } elseif ($action === 'delete_terms') {
            require_module_access('kontak', 'can_delete');
            $id = (int) ($_POST['terms_id'] ?? 0);
            $pdo->prepare('DELETE FROM terms_conditions WHERE id=? AND organization_id=?')->execute([$id, $org['organization_id']]);
            $flash = ['ok', 'Syarat & Ketentuan dihapus. Dokumen yang udah terbit sebelumnya tetap simpan salinan teksnya sendiri (gak ikut hilang).'];
        }
    } catch (Throwable $e) {
        $flash = ['error', $e->getMessage()];
    }
}

$termsList = $pdo->prepare('SELECT * FROM terms_conditions WHERE organization_id=? ORDER BY title');
$termsList->execute([$org['organization_id']]);
$termsList = $termsList->fetchAll();

$editingTerms = null;
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM terms_conditions WHERE id=? AND organization_id=?');
    $stmt->execute([(int) $_GET['edit'], $org['organization_id']]);
    $editingTerms = $stmt->fetch() ?: null;
}
?>

<?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<p style="font-size:13px; color:var(--ink-muted); max-width:600px;">
  Setiap Syarat & Ketentuan yang dipilih pas bikin Penawaran/Invoice, teksnya di-<em>snapshot</em>
  ke dokumen itu — kalau master-nya diedit/dihapus di sini, dokumen yang udah terbit sebelumnya
  tetap aman, gak ikut berubah.
</p>

<div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
  <?php if (has_access('kontak', 'can_create')): ?>
    <button class="btn btn-sm" data-open-modal="terms-modal" onclick="document.getElementById('terms-form').reset(); document.getElementById('terms_id').value=''; document.getElementById('terms-modal-title').textContent='Syarat & Ketentuan Baru';">+ Tambah</button>
  <?php endif; ?>
</div>

<div class="card">
  <table class="data-table">
    <thead><tr><th>Judul</th><th>Preview</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($termsList as $t): ?>
        <tr>
          <td><?= htmlspecialchars($t['title']) ?></td>
          <td style="max-width:400px; color:var(--ink-muted);"><?= htmlspecialchars(mb_strimwidth($t['content'], 0, 100, '...')) ?></td>
          <td>
            <?php if (has_access('kontak', 'can_edit')): ?>
              <a class="btn btn-sm btn-ghost" href="terms.php?edit=<?= $t['id'] ?>#terms-modal">Edit</a>
            <?php endif; ?>
            <?php if (has_access('kontak', 'can_delete')): ?>
              <button class="btn btn-sm btn-ghost" type="button" onclick="if(confirm('Hapus Syarat & Ketentuan ini?')) __submitDeleteForm('delete_terms', {terms_id: <?= $t['id'] ?>})">Hapus</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$termsList): ?><tr><td colspan="3" style="text-align:center; color:var(--ink-muted);">Belum ada Syarat & Ketentuan.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="modal-scrim<?= $editingTerms ? ' open' : '' ?>" id="terms-modal">
  <div class="modal-card" style="width:560px;">
    <div class="modal-head">
      <h3 id="terms-modal-title"><?= $editingTerms ? 'Edit Syarat & Ketentuan' : 'Syarat & Ketentuan Baru' ?></h3>
      <button class="modal-close" data-close-modal="terms-modal">&times;</button>
    </div>
    <form method="post" id="terms-form">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_terms">
        <input type="hidden" name="terms_id" id="terms_id" value="<?= $editingTerms['id'] ?? '' ?>">
        <div class="field"><label>Judul (mis. "Pembayaran DP 50%", "Custom Order")</label><input type="text" name="title" required value="<?= htmlspecialchars($editingTerms['title'] ?? '') ?>"></div>
        <div class="field"><label>Isi</label><textarea name="content" rows="8" required><?= htmlspecialchars($editingTerms['content'] ?? '') ?></textarea></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" data-close-modal="terms-modal">Batal</button>
        <button type="submit" class="btn">Simpan</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
