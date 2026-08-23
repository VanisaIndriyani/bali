<?php
$pageTitle = 'Riwayat Daily Log';
require_once __DIR__ . '/config/config.php';
requireLogin();

$db = Database::getInstance();
$user = currentUser();
$isSupervisor = in_array($user['role'], ['supervisor', 'manager', 'admin']);

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-t');
$status = $_GET['status'] ?? 'all';
$engineerId = (int)($_GET['engineer_id'] ?? 0);

$where = ["dl.log_date BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];

if (!$isSupervisor) {
    $where[] = "dl.engineer_id = ?";
    $params[] = $user['id'];
} elseif ($engineerId > 0) {
    $where[] = "dl.engineer_id = ?";
    $params[] = $engineerId;
}

if ($status !== 'all') {
    $where[] = "dl.status = ?";
    $params[] = $status;
}

$whereClause = implode(' AND ', $where);
/*  ✅ 2026-08-23 FIX DEDUP HISTORY: User bisa isi logshet tanggal yang sama berulang kali.
    "Daftar Riwayat" sebelumnya menampilkan SEMUA entry (3x tanggal sama = 3 baris = misleading),
    dan TOTAL di bagian atas dijumlahkan 3x (385 + 0 + 375 = 760 = DOUBLE!).
    Solusi: DEDUP MAX(id) per (DATE(log_date), engineer_id) = ambil entry TERAKHIR saja setiap tanggal setiap engineer. */
$logs = $db->fetchAll(
    "SELECT dl.*, u.name as engineer_name, u.position as engineer_position, s.name as supervisor_name
     FROM daily_logs dl
     INNER JOIN (
         SELECT MAX(id) AS keep_id
         FROM daily_logs dl_inner
         WHERE " . str_replace('dl.', 'dl_inner.', $whereClause) . "
         GROUP BY DATE(dl_inner.log_date), dl_inner.engineer_id
     ) k ON k.keep_id = dl.id
     LEFT JOIN users u ON dl.engineer_id = u.id
     LEFT JOIN users s ON dl.supervisor_id = s.id
     ORDER BY dl.log_date DESC, dl.created_at DESC
     LIMIT 500",
    $params
);

$engineers = [];
if ($isSupervisor) {
    $engineers = $db->fetchAll("SELECT id, name, position FROM users WHERE role = 'engineer' ORDER BY name");
}

$totals = ['electricity' => 0, 'water' => 0, 'gas' => 0];
$approvedLogs = array_filter($logs, fn($l) => $l['status'] === 'approved');
foreach ($approvedLogs as $l) {
    $totals['electricity'] += $l['total_electricity'];
    $totals['water'] += $l['total_water'];
    $totals['gas'] += $l['total_gas'];
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="page-shell page-shell--7xl">
    <div class="mb-8 animate-fade-in">
        <a href="<?= BASE_URL ?>index.php" class="inline-flex items-center gap-1.5 text-sm text-secondary hover:text-primary mb-4 transition-colors">
            <i class="fas fa-chevron-left text-xs"></i> Kembali ke Dashboard
        </a>
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
            <div>
                <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">
                    <i class="fas fa-clock-rotate-left mr-2 text-accent"></i>Riwayat Daily Log
                </h1>
                <p class="text-secondary">Arsip semua laporan engineering</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>reports/pdf.php?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&status=<?= $status ?><?= $engineerId ? "&engineer_id=$engineerId" : '' ?>" target="_blank"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-card bg-red-50 text-red-700 border border-red-200 font-semibold hover:bg-red-100 hover:shadow-md transition-all duration-300">
                    <i class="fas fa-file-pdf"></i>Download PDF
                </a>
             
            </div>
        </div>
    </div>

    <div class="bg-surface rounded-premium border border-border p-5 shadow-sm mb-6 animate-slide-up">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
            <div>
                <label class="block text-xs text-secondary uppercase tracking-wider font-semibold mb-1.5">Dari Tanggal</label>
                <input type="date" name="date_from" value="<?= $dateFrom ?>" class="w-full px-3 py-2.5 rounded-card border border-border bg-surface text-primary focus:outline-none focus:border-primary">
            </div>
            <div>
                <label class="block text-xs text-secondary uppercase tracking-wider font-semibold mb-1.5">Sampai Tanggal</label>
                <input type="date" name="date_to" value="<?= $dateTo ?>" class="w-full px-3 py-2.5 rounded-card border border-border bg-surface text-primary focus:outline-none focus:border-primary">
            </div>
            <?php if ($isSupervisor): ?>
                <div>
                    <label class="block text-xs text-secondary uppercase tracking-wider font-semibold mb-1.5">Engineer</label>
                    <select name="engineer_id" class="w-full px-3 py-2.5 rounded-card border border-border bg-surface text-primary focus:outline-none focus:border-primary">
                        <option value="0">Semua Engineer</option>
                        <?php foreach ($engineers as $e): ?>
                            <option value="<?= $e['id'] ?>" <?= $engineerId === $e['id'] ? 'selected' : '' ?>><?= cleanInput($e['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div>
                <label class="block text-xs text-secondary uppercase tracking-wider font-semibold mb-1.5">Status</label>
                <select name="status" class="w-full px-3 py-2.5 rounded-card border border-border bg-surface text-primary focus:outline-none focus:border-primary">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Semua Status</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-secondary uppercase tracking-wider font-semibold mb-1.5 text-transparent select-none pointer-events-none">Spacer</label>
                <button type="submit" class="w-full px-5 py-2.5 rounded-card bg-primary text-white font-semibold hover:bg-secondary transition-all hover:shadow-sm flex items-center justify-center gap-1.5">
                    <i class="fas fa-filter mr-1.5"></i>Filter
                </button>
            </div>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="p-5 bg-surface rounded-card border border-border shadow-sm animate-slide-up" style="animation-delay: 50ms">
            <p class="text-xs text-secondary uppercase tracking-wider font-semibold mb-1.5">⚡ Total Listrik (Approved)</p>
            <p class="text-2xl font-bold text-primary"><?= formatNumber($totals['electricity']) ?> <span class="text-sm font-medium text-secondary">kWh</span></p>
        </div>
        <div class="p-5 bg-surface rounded-card border border-border shadow-sm animate-slide-up" style="animation-delay: 100ms">
            <p class="text-xs text-secondary uppercase tracking-wider font-semibold mb-1.5">💧 Total Air (Approved)</p>
            <p class="text-2xl font-bold text-primary"><?= formatNumber($totals['water']) ?> <span class="text-sm font-medium text-secondary">m3</span></p>
        </div>
        <div class="p-5 bg-surface rounded-card border border-border shadow-sm animate-slide-up" style="animation-delay: 150ms">
            <p class="text-xs text-secondary uppercase tracking-wider font-semibold mb-1.5">🔥 Total Gas (Approved)</p>
            <p class="text-2xl font-bold text-primary"><?= formatNumber($totals['gas']) ?> <span class="text-sm font-medium text-secondary">kg</span></p>
        </div>
    </div>

    <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up">
        <div class="p-5 border-b border-border bg-muted/30 flex items-center justify-between">
            <div>
                <h2 class="font-bold text-primary">Daftar Riwayat</h2>
                <p class="text-sm text-secondary mt-0.5"><?= count($logs) ?> entri ditemukan • <?= formatDate($dateFrom) ?> s/d <?= formatDate($dateTo) ?></p>
            </div>
            <div class="text-xs text-secondary hidden sm:block">
                <i class="fas fa-circle-info mr-1"></i>Klik tanggal untuk detail
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr class="text-left text-secondary">
                        <th class="px-5 py-3.5 font-semibold whitespace-nowrap">Tanggal</th>
                        <?php if ($isSupervisor): ?>
                            <th class="px-5 py-3.5 font-semibold whitespace-nowrap">Engineer</th>
                        <?php endif; ?>
                        <th class="px-5 py-3.5 font-semibold text-right whitespace-nowrap">Listrik</th>
                        <th class="px-5 py-3.5 font-semibold text-right whitespace-nowrap">Air</th>
                        <th class="px-5 py-3.5 font-semibold text-right whitespace-nowrap">Gas</th>
                        <th class="px-5 py-3.5 font-semibold whitespace-nowrap">Status</th>
                        <?php if ($isSupervisor): ?>
                            <th class="px-5 py-3.5 font-semibold whitespace-nowrap">Approved By</th>
                        <?php endif; ?>
                        <th class="px-5 py-3.5 font-semibold text-right whitespace-nowrap">Detail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <?php if (count($logs) > 0): ?>
                        <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-muted/50 transition-colors group">
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <div class="font-semibold text-primary"><?= formatDate($log['log_date']) ?></div>
                                    <div class="text-[11px] text-secondary"><?= formatDateTime($log['created_at']) ?></div>
                                </td>
                                <?php if ($isSupervisor): ?>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <div class="font-medium text-primary"><?= cleanInput($log['engineer_name']) ?></div>
                                        <div class="text-[11px] text-secondary"><?= cleanInput($log['engineer_position'] ?? '-') ?></div>
                                    </td>
                                <?php endif; ?>
                                <td class="px-5 py-4 text-right font-medium text-primary whitespace-nowrap"><?= formatNumber($log['total_electricity']) ?> kWh</td>
                                <td class="px-5 py-4 text-right font-medium text-primary whitespace-nowrap"><?= formatNumber($log['total_water']) ?> m3</td>
                                <td class="px-5 py-4 text-right font-medium text-primary whitespace-nowrap"><?= formatNumber($log['total_gas']) ?> kg</td>
                                <td class="px-5 py-4 whitespace-nowrap"><span class="px-2.5 py-1 rounded-full text-[11px] font-semibold <?= getStatusBadgeClass($log['status']) ?>"><?= getStatusText($log['status']) ?></span></td>
                                <?php if ($isSupervisor): ?>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm text-secondary"><?= $log['supervisor_name'] ? cleanInput($log['supervisor_name']) : '<span class="opacity-60">-</span>' ?></td>
                                <?php endif; ?>
                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <?php if ($isSupervisor): ?>
                                        <a href="<?= BASE_URL ?>supervisor/review_detail.php?id=<?= $log['id'] ?>" class="text-primary hover:text-accent font-medium text-xs inline-flex items-center gap-1">
                                            <i class="fas fa-eye"></i>Review
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= BASE_URL ?>engineer/daily_log_form.php?date=<?= $log['log_date'] ?>" class="text-primary hover:text-accent font-medium text-xs inline-flex items-center gap-1">
                                            <i class="fas fa-edit"></i><?= $log['status'] === 'rejected' ? 'Edit' : 'Lihat' ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= $isSupervisor ? 8 : 6 ?>" class="px-5 py-16 text-center text-secondary">
                                <i class="fas fa-archive text-5xl mb-3 block opacity-30"></i>
                                <h3 class="text-lg font-semibold text-primary mb-1">Tidak ada riwayat</h3>
                                <p class="text-sm">Coba ubah filter tanggal atau status</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-success { background: #dcfce7; color: #166534; }
.badge-danger  { background: #fee2e2; color: #991b1b; }
.badge-secondary { background: #f3f4f6; color: #4b5563; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
