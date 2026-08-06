<?php
$pageTitle = T('dash_logistic_page_title', 'Dashboard Logistik & Order Request');
require_once __DIR__ . '/../config/config.php';
requireRole(['supervisor', 'manager']);

$db = Database::getInstance();
$user = currentUser();
$userName = (string)($user['name']  ?? 'User');
$userRole = (string)($user['role']  ?? 'engineer');
$userId = (int)($user['id']      ?? 0);

$orderWhere = $userRole === 'engineer' ? "WHERE requested_by = $userId" : "";
$orderCounts = ['all'=>0,'pending_supervisor'=>0,'pending_manager'=>0,'approved'=>0,'rejected'=>0];
if (in_array($userRole, ['supervisor','engineer','manager','admin'], true)) {
    $orderSQL = "SELECT status, COUNT(*) as cnt FROM orders $orderWhere GROUP BY status";
    foreach ($db->fetchAll($orderSQL) as $row) {
        $orderCounts[$row['status']] = (int)$row['cnt'];
        $orderCounts['all'] += (int)$row['cnt'];
    }
    if ($userRole === 'manager') {
        $orderCounts['my_pending'] = (int)$orderCounts['pending_manager'];
    } elseif ($userRole === 'supervisor') {
        $orderCounts['my_pending'] = (int)$orderCounts['pending_supervisor'];
    }
}
if ($userRole === 'manager') {
    $orderCounts['my_pending'] = (int)($orderCounts['my_pending'] ?? $orderCounts['pending_manager']);
} elseif ($userRole === 'supervisor') {
    $orderCounts['my_pending'] = (int)($orderCounts['my_pending'] ?? $orderCounts['pending_supervisor']);
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="page-shell page-shell--7xl">
    <div class="page-header flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6 animate-fade-in">
        <div>
            <p class="text-[11px] font-black uppercase tracking-[0.25em] text-amber-700 mb-2">PROCUREMENT • LOGISTIC</p>
            <h1 class="font-display text-3xl font-black text-primary flex items-center gap-3">
                <span class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-amber-800 flex items-center justify-center text-white shadow-md shadow-amber-500/30">
                    <i class="fas fa-boxes-stacked"></i>
                </span>
                <?= T('dash_logistic_title', 'Logistik & Order Request') ?>
            </h1>
            <p class="text-sm text-secondary mt-2"><?= T('dash_logistic_sub', 'Ringkasan status Order / Purchase Request dan pipeline Approval') ?></p>
        </div>
        <div class="flex flex-wrap gap-2.5 self-start">
            <a href="<?= BASE_URL ?>orders/create.php" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 hover:from-emerald-600 hover:to-emerald-800 text-white text-sm font-bold shadow hover:shadow-lg hover:-translate-y-0.5 transition-all">
                <i class="fas fa-file-circle-plus"></i> <?= T('order_btn_new_request', 'Buat Request Baru →') ?>
            </a>
            <a href="<?= BASE_URL ?>orders/index.php" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-gradient-to-br from-amber-500 to-amber-700 hover:from-amber-600 hover:to-amber-800 text-white text-sm font-bold shadow hover:shadow-lg hover:-translate-y-0.5 transition-all">
                <i class="fas fa-clipboard-list"></i> <?= T('order_menu_all', 'Semua Order') ?>
            </a>
        </div>
    </div>

    <div class="bg-surface rounded-premium border border-amber-200/70 shadow-sm overflow-hidden mb-8 animate-slide-up" style="animation-delay: 100ms">
        <div class="p-5 lg:p-6">
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                <?php
                $allOrder = (int)($orderCounts['all'] ?? 0);
                $pendingMine = (int)($orderCounts['my_pending'] ?? 0);
                $spvPen = (int)($orderCounts['pending_supervisor'] ?? 0);
                $mgrPen = (int)($orderCounts['pending_manager'] ?? 0);
                $approve = (int)($orderCounts['approved'] ?? 0);
                $reject = (int)($orderCounts['rejected'] ?? 0);

                $orderBadge = [
                    [T('stat_label_total', 'TOTAL'), $allOrder, T('order_stat_all', 'Total Order / PR'), 'from-slate-100 to-slate-200', 'bg-slate-50', 'border-slate-200', 'text-slate-600', 'text-slate-800', 'fa-clipboard-list', BASE_URL . 'orders/index.php?filter=all'],
                    [$userRole === 'manager' ? T('order_stat_pending_mgr', 'PERLU APPROVE MGR') : T('order_stat_pending_spv', 'PERLU APPROVE SPV'), $pendingMine, T('order_stat_pending', 'Belum di-proses saya'), 'from-amber-100 to-yellow-200', 'bg-yellow-50', 'border-yellow-200', 'text-amber-700', 'text-amber-800', 'fa-clock', BASE_URL . 'orders/index.php?filter=my_pending'],
                    [T('order_stat_pending_spv', 'PERLU APPROVE SPV'), $spvPen, T('order_stat_wait_spv', 'Menunggu Supervisor'), 'from-orange-100 to-orange-200', 'bg-orange-50', 'border-orange-200', 'text-orange-700', 'text-orange-800', 'fa-hourglass-half', BASE_URL . 'orders/index.php?filter=pending_supervisor'],
                    [T('order_stat_pending_mgr', 'PERLU APPROVE MGR'), $mgrPen, T('order_stat_wait_mgr', 'Menunggu Manager'), 'from-blue-100 to-blue-200', 'bg-blue-50', 'border-blue-200', 'text-blue-700', 'text-blue-800', 'fa-hourglass-start', BASE_URL . 'orders/index.php?filter=pending_manager'],
                    [T('stat_label_approved', 'APPROVED'), $approve, T('order_stat_approved', 'Sudah Final Approval'), 'from-emerald-100 to-emerald-200', 'bg-emerald-50', 'border-emerald-200', 'text-emerald-700', 'text-emerald-800', 'fa-circle-check', BASE_URL . 'orders/index.php?filter=approved'],
                ];
                if (!in_array($userRole, ['manager','supervisor'], true)) {
                    $orderBadge[1] = [T('order_stat_rejected', 'DITOLAK'), $reject, T('order_stat_rejected_sub', 'Order Ditolak'), 'from-red-100 to-red-200', 'bg-red-50', 'border-red-200', 'text-red-700', 'text-red-800', 'fa-circle-xmark', BASE_URL . 'orders/index.php?filter=rejected'];
                }
                foreach ($orderBadge as $ob) {
                    [$topLbl, $num, $botLbl, $iconBg, $bg, $bor, $col, $colNum, $icon, $href] = $ob;
                ?>
                    <a href="<?= $href ?>" class="rounded-2xl border <?= $bor ?> <?= $bg ?> p-4 sm:p-5 shadow-sm hover:shadow-xl hover:shadow-amber-500/10 hover:-translate-y-1 hover:scale-[1.02] cursor-pointer transition-all duration-300 block">
                        <div class="flex items-start justify-between mb-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br <?= $iconBg ?> flex items-center justify-center shadow-md">
                                <i class="fas <?= $icon ?> text-white text-xl"></i>
                            </div>
                            <p class="text-[10px] font-black uppercase tracking-wider <?= $col ?>"><?= $topLbl ?></p>
                        </div>
                        <p class="text-3xl lg:text-4xl font-black <?= $colNum ?> leading-none mb-2"><?= $num ?></p>
                        <p class="text-[11px] text-secondary font-semibold leading-snug"><?= $botLbl ?></p>
                    </a>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php';
