<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole('supervisor');

$db = Database::getInstance();

$hasLastLogin = $db->fetchAll("SHOW COLUMNS FROM users LIKE 'last_login'");
if (empty($hasLastLogin)) {
    @$db->query("ALTER TABLE users ADD COLUMN last_login DATETIME NULL AFTER updated_at");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        setFlash('error', 'ID user tidak valid');
        redirect('supervisor/users/index.php');
    }
    $target = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    if (!$target) {
        setFlash('error', 'User tidak ditemukan');
        redirect('supervisor/users/index.php');
    }
    if ($target['role'] !== 'engineer') {
        setFlash('error', 'Hanya akun Engineer yang dapat dihapus');
        redirect('supervisor/users/index.php');
    }
    $checkLogs = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE engineer_id = ?", [$id])['cnt'] ?? 0);
    if ($checkLogs > 0) {
        setFlash('warning', "Tidak dapat dihapus: user ini memiliki {$checkLogs} daily log. Nonaktifkan secara manual jika perlu.");
        redirect('supervisor/users/index.php');
    }
    $db->query("DELETE FROM users WHERE id = ? AND role = 'engineer'", [$id]);
    setFlash('success', 'Akun Engineer "' . cleanInput($target['name']) . '" berhasil dihapus');
    redirect('supervisor/users/index.php');
}

$search = cleanInput($_GET['search'] ?? '');
$where = "WHERE u.role = 'engineer'";
$params = [];
if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.position LIKE ? OR u.phone LIKE ?)";
    $kw = "%{$search}%";
    $params = [$kw, $kw, $kw, $kw];
}

$list = $db->fetchAll("SELECT u.*,
   (SELECT COUNT(*) FROM daily_logs dl WHERE dl.engineer_id = u.id) as total_log,
   (SELECT COUNT(*) FROM daily_logs dl WHERE dl.engineer_id = u.id AND dl.status = 'pending') as total_pending,
   (SELECT COUNT(*) FROM daily_logs dl WHERE dl.engineer_id = u.id AND dl.status = 'approved') as total_approved,
   (SELECT MAX(dl.log_date) FROM daily_logs dl WHERE dl.engineer_id = u.id) as last_active
    FROM users u
    $where
    ORDER BY u.created_at DESC, u.name ASC", $params);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>
<div class="page-shell page-shell--7xl pb-20 md:pb-10">
    <div class="mb-6 animate-fade-in">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <p class="text-sm text-secondary mb-1"><i class="fas fa-users-gear mr-1.5 text-accent"></i>Manajemen Staff Engineering</p>
                <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">Kelola Akun Engineer</h1>
                <p class="text-secondary text-sm">Total <strong class="text-primary"><?= count($list) ?></strong> Engineer terdaftar</p>
            </div>
            <a href="<?= BASE_URL ?>supervisor/users/create.php" class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-card bg-gradient-to-r from-primary via-gray-900 to-primary text-white font-semibold shadow-lg hover:shadow-2xl hover:-translate-y-0.5 transition-all duration-300 self-start sm:self-end">
                <i class="fas fa-user-plus group-hover:rotate-90 transition-transform"></i>
                Tambah Engineer Baru
            </a>
        </div>
    </div>

    <div class="bg-surface rounded-premium border border-border shadow-sm mb-6 animate-slide-up" style="animation-delay: 80ms">
        <form method="GET" class="flex flex-col sm:flex-row gap-3 p-4 sm:p-5 border-b border-border">
            <div class="relative flex-1">
                <i class="fas fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary text-sm"></i>
                <input type="text" name="search" value="<?= cleanInput($search) ?>" placeholder="Cari nama, email, jabatan, no hp engineer..."
                    class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all text-sm">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 sm:flex-none px-5 py-3 rounded-card bg-primary text-white text-sm font-semibold hover:bg-secondary transition-colors inline-flex items-center justify-center gap-1.5">
                    <i class="fas fa-filter"></i> Cari
                </button>
                <a href="?" class="flex-1 sm:flex-none px-5 py-3 rounded-card bg-muted text-primary text-sm font-semibold border border-border hover:bg-white transition-colors inline-flex items-center justify-center gap-1.5">
                    <i class="fas fa-rotate-left"></i> Reset
                </a>
            </div>
        </form>

        <div class="overflow-x-auto -mx-4 sm:mx-0 px-4 sm:px-0">
            <table class="w-full text-sm min-w-[900px]">
                <thead class="bg-muted">
                    <tr class="text-left text-secondary">
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap">Engineer</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap">Kontak</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold text-center whitespace-nowrap">Total Log</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold text-center whitespace-nowrap">Pending</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold text-center whitespace-nowrap">Approved</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold text-right whitespace-nowrap">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <?php if (count($list) > 0): ?>
                    <?php foreach ($list as $u):
                        $init = strtoupper(mb_substr((string)$u['name'], 0, 1) ?: 'U');
                    ?>
                        <tr class="hover:bg-muted/50 transition-colors">
                            <td class="px-4 sm:px-5 py-4 whitespace-nowrap">
                                <div class="flex items-center gap-3">
                                    <div class="avatar-md-sm"><?= $init ?></div>
                                    <div class="min-w-0">
                                        <p class="font-semibold text-primary"><?= cleanInput($u['name']) ?></p>
                                        <p class="text-xs text-secondary"><?= cleanInput($u['position'] ?? 'Engineering Staff') ?></p>
                                    
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 sm:px-5 py-4 min-w-0">
                                <p class="text-xs text-secondary mb-0.5"><i class="fas fa-envelope mr-1 text-accent"></i><?= cleanInput($u['email']) ?></p>
                                <p class="text-xs text-secondary"><i class="fas fa-phone mr-1 text-accent"></i><?= cleanInput($u['phone'] ?? '-') ?></p>
                            </td>
                            <td class="px-4 sm:px-5 py-4 text-center"><span class="inline-flex items-center justify-center px-3 py-1 rounded-full bg-muted border border-border text-primary font-bold"><?= (int)$u['total_log'] ?></span></td>
                            <td class="px-4 sm:px-5 py-4 text-center"><span class="inline-flex items-center justify-center px-3 py-1 rounded-full bg-yellow-50 border border-yellow-200 text-yellow-700 font-bold"><?= (int)$u['total_pending'] ?></span></td>
                            <td class="px-4 sm:px-5 py-4 text-center"><span class="inline-flex items-center justify-center px-3 py-1 rounded-full bg-green-50 border border-green-200 text-green-700 font-bold"><?= (int)$u['total_approved'] ?></span></td>
                            <td class="px-4 sm:px-5 py-4">
                                <div class="flex gap-2 justify-end flex-wrap">
                                    <button type="button" onclick="confirmDelete(<?= (int)$u['id'] ?>, <?= json_encode(cleanInput($u['name'])) ?>)"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 hover:-translate-y-0.5 transition-all">
                                        <i class="fas fa-trash"></i> Hapus
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="px-4 sm:px-5 py-16 text-center text-secondary">
                            <i class="fas fa-user-group text-4xl mb-3 block opacity-30"></i>
                            <p class="mb-2"><?= $search ? 'Tidak ada Engineer yang cocok dengan pencarian.' : 'Belum ada akun Engineer terdaftar.' ?></p>
                            <a href="<?= BASE_URL ?>supervisor/users/create.php" class="inline-flex items-center gap-1.5 mt-3 px-4 py-2 rounded-full bg-primary text-white text-xs font-semibold hover:bg-secondary transition-colors">
                                <i class="fas fa-plus"></i> Tambahkan Engineer Pertama
                            </a>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<form method="POST" id="deleteForm" class="hidden">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId" value="0">
</form>

<script>
function confirmDelete(id, name) {
    if (confirm('Anda yakin ingin MENGHAPUS akun Engineer "' + name + '"?\n\nTindakan ini tidak dapat dibatalkan (kecuali user sudah punya daily log).')) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
