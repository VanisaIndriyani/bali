<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = T('review_title', 'Review Daily Log');
requireRole('supervisor');

$db = Database::getInstance();
$user = currentUser();

$filter = $_GET['filter'] ?? 'pending';
$allowedFilters = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($filter, $allowedFilters)) $filter = 'pending';

$where = '';
$params = [];
if ($filter !== 'all') {
    $where = "WHERE dl.status = ?";
    $params[] = $filter;
}

$logs = $db->fetchAll(
    "SELECT dl.*, u.name as engineer_name, u.email as engineer_email, u.position as engineer_position
     FROM daily_logs dl
     LEFT JOIN users u ON dl.engineer_id = u.id
     $where
     ORDER BY dl.log_date DESC, dl.created_at DESC
     LIMIT 100",
    $params
);

$counts = [
    'pending' => $db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE status = 'pending'")['cnt'],
    'approved' => $db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE status = 'approved'")['cnt'],
    'rejected' => $db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE status = 'rejected'")['cnt'],
    'all' => $db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs")['cnt'],
];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-shell page-shell--7xl">
    <div class="mb-8 animate-fade-in">
        <a href="<?= BASE_URL ?>index.php" class="inline-flex items-center gap-1.5 text-sm text-secondary hover:text-primary mb-4 transition-colors">
            <i class="fas fa-chevron-left text-xs"></i> <?= T('review_back_dashboard', 'Kembali ke Dashboard') ?>
        </a>
        <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-2">
            <i class="fas fa-file-signature mr-2 text-accent"></i><?= T('review_title', 'Review Daily Log') ?>
        </h1>
        <p class="text-secondary"><?= T('review_sub', 'Approve atau reject daily log engineering') ?></p>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <?php
            $filterLabels = [
                'pending' => T('review_filter_pending', 'Menunggu Approval'),
                'approved' => T('review_filter_approved', 'Disetujui'),
                'rejected' => T('review_filter_rejected', 'Ditolak'),
                'all' => T('review_filter_all', 'Semua'),
            ];
        ?>
        <?php foreach (['pending','approved','rejected','all'] as $key): ?>
            <a href="?filter=<?= $key ?>"
                class="filter-card <?= $filter === $key ? 'filter-card-active' : '' ?> animate-slide-up">
                <span class="text-2xl font-bold"><?= $counts[$key] ?></span>
                <span class="text-xs uppercase tracking-wider font-semibold opacity-80"><?= $filterLabels[$key] ?></span>
                <?php if ($key === 'pending' && $counts[$key] > 0): ?>
                    <span class="absolute top-2 right-2 w-2.5 h-2.5 bg-red-500 rounded-full animate-pulse"></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up">
        <div class="p-5 lg:p-6 border-b border-border flex items-center justify-between">
            <div>
                <h2 class="font-bold text-lg text-primary">
                    <i class="fas fa-clipboard-check mr-2 text-accent"></i>
                    <?= T('review_list_title', 'Daftar Daily Log') ?> - <?= $filterLabels[$filter] ?>
                </h2>
                <p class="text-sm text-secondary mt-0.5"><?= count($logs) ?> <?= T('review_entries_found', 'entri ditemukan') ?></p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr class="text-left text-secondary">
                        <th class="px-5 py-3.5 font-semibold whitespace-nowrap"><?= T('table_tanggal', 'Tanggal') ?></th>
                        <th class="px-5 py-3.5 font-semibold whitespace-nowrap"><?= T('table_engineer', 'Engineer') ?></th>
                        <th class="px-5 py-3.5 font-semibold text-right whitespace-nowrap"><?= T('table_listrik', 'Listrik') ?></th>
                        <th class="px-5 py-3.5 font-semibold text-right whitespace-nowrap"><?= T('table_air', 'Air') ?></th>
                        <th class="px-5 py-3.5 font-semibold text-right whitespace-nowrap"><?= T('table_gas', 'Gas') ?></th>
                        <th class="px-5 py-3.5 font-semibold whitespace-nowrap"><?= T('general_status', 'Status') ?></th>
                        <th class="px-5 py-3.5 font-semibold text-right whitespace-nowrap"><?= T('general_action', 'Aksi') ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <?php if (count($logs) > 0): ?>
                        <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-muted/50 transition-colors">
                                <td class="px-5 py-4 font-semibold text-primary whitespace-nowrap"><?= formatDate($log['log_date']) ?></td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-primary to-secondary flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                            <?= strtoupper(substr($log['engineer_name'], 0, 1)) ?>
                                        </div>
                                        <div class="leading-tight min-w-0">
                                            <p class="font-medium text-primary truncate"><?= cleanInput($log['engineer_name']) ?></p>
                                            <p class="text-xs text-secondary truncate"><?= cleanInput($log['engineer_position'] ?? '-') ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-right font-medium text-primary whitespace-nowrap"><?= formatNumber($log['total_electricity']) ?> kWh</td>
                                <td class="px-5 py-4 text-right font-medium text-primary whitespace-nowrap"><?= formatNumber($log['total_water']) ?> m³</td>
                                <td class="px-5 py-4 text-right font-medium text-primary whitespace-nowrap"><?= formatNumber($log['total_gas']) ?> kg</td>
                                <td class="px-5 py-4 whitespace-nowrap"><span class="px-3 py-1 rounded-full text-[11px] font-semibold <?= getStatusBadgeClass($log['status']) ?>"><?= getStatusText($log['status']) ?></span></td>
                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <a href="review_detail.php?id=<?= $log['id'] ?>"
                                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-primary text-white text-xs font-semibold hover:bg-secondary transition-all hover:shadow-md hover:-translate-y-0.5">
                                        <i class="fas fa-eye"></i>
                                        <?= T('review_btn', 'Review') ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-5 py-16 text-center">
                                <i class="fas fa-clipboard-list text-5xl mb-3 block opacity-30 text-secondary"></i>
                                <h3 class="text-lg font-semibold text-primary mb-1"><?= T('review_no_data', 'Tidak ada data') ?></h3>
                                <p class="text-sm text-secondary"><?= T('review_no_data_sub', 'Tidak ada daily log dengan filter ini') ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
