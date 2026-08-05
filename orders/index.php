<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = T('order_page_title', 'Daftar Order / Purchase Request');
requireRole(['engineer', 'supervisor', 'manager', 'admin']);

$db = Database::getInstance();
$user = currentUser();

$filter = $_GET['filter'] ?? 'all';
$search = cleanInput($_GET['search'] ?? '');
$role = (string)$user['role'];

$where = [];
$params = [];

if ($role === 'engineer') {
    $where[] = "o.requested_by = ?";
    $params[] = (int)$user['id'];
} elseif ($role === 'supervisor') {
    if ($filter === 'my_pending') {
        $where[] = "o.status = 'pending_supervisor'";
    } elseif ($filter !== 'all') {
        $where[] = "o.status = ?";
        $params[] = $filter;
    }
} elseif ($role === 'manager') {
    if ($filter === 'my_pending') {
        $where[] = "o.status = 'pending_manager'";
    } elseif ($filter !== 'all') {
        $where[] = "o.status = ?";
        $params[] = $filter;
    }
} else {
    if ($filter !== 'all') {
        $where[] = "o.status = ?";
        $params[] = $filter;
    }
}

if ($search !== '') {
    $where[] = "(o.order_no LIKE ? OR o.title LIKE ? OR cc.code LIKE ? OR cc.name LIKE ? OR u.name LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

$sqlWhere = count($where) ? " WHERE " . implode(" AND ", $where) : '';
$sql = "SELECT o.*, cc.code as cc_code, cc.name as cc_name, u.name as req_name
        FROM orders o
        LEFT JOIN cost_codes cc ON cc.id = o.cost_code_id
        LEFT JOIN users u ON u.id = o.requested_by
        {$sqlWhere}
        ORDER BY o.id DESC
        LIMIT 300";
$orders = $db->fetchAll($sql, $params);

$statusCounts = [
    'all' => 0,
    'pending_supervisor' => 0,
    'pending_manager' => 0,
    'approved' => 0,
    'rejected' => 0,
    'draft' => 0,
    'completed' => 0,
    'my_pending' => 0,
];
$sqlCnt = "SELECT o.status FROM orders o LEFT JOIN users u ON u.id = o.requested_by";
$cntWhere = [];
$cntParams = [];
if ($role === 'engineer') { $cntWhere[] = "o.requested_by = ?"; $cntParams[] = (int)$user['id']; }
$cntSqlWhere = count($cntWhere) ? " WHERE " . implode(" AND ", $cntWhere) : '';
$cntRows = $db->fetchAll($sqlCnt . $cntSqlWhere, $cntParams);
foreach ($cntRows as $r) {
    $s = (string)$r['status'];
    if (!isset($statusCounts[$s])) $statusCounts[$s] = 0;
    $statusCounts[$s]++;
    $statusCounts['all']++;
    if ($role === 'supervisor' && $s === 'pending_supervisor') $statusCounts['my_pending']++;
    if ($role === 'manager' && $s === 'pending_manager') $statusCounts['my_pending']++;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$tabs = [];
$tabs['all'] = ['label' => 'Semua', 'count' => (int)$statusCounts['all']];
if ($role === 'supervisor' || $role === 'manager') {
    $tabs['my_pending'] = [
        'label' => $role === 'supervisor' ? T('order_tab_my_pending_spv', 'Perlu Approval Saya (Spv)') : T('order_tab_my_pending_mgr', 'Perlu Approval Saya (Mgr)'),
        'count' => (int)$statusCounts['my_pending']
    ];
}
$tabs['pending_supervisor'] = ['label' => T('order_status_pending_spv', 'Menunggu Supervisor'), 'count' => (int)$statusCounts['pending_supervisor']];
$tabs['pending_manager'] = ['label' => T('order_status_pending_mgr', 'Menunggu Manager'), 'count' => (int)$statusCounts['pending_manager']];
$tabs['approved'] = ['label' => T('order_status_approved', 'Disetujui'), 'count' => (int)$statusCounts['approved']];
$tabs['rejected'] = ['label' => T('order_status_rejected', 'Ditolak'), 'count' => (int)$statusCounts['rejected']];
$tabs['draft'] = ['label' => T('order_status_draft', 'Draft'), 'count' => (int)$statusCounts['draft']];
?>
<div class="page-shell page-shell--7xl">
    <div class="page-header flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6 animate-fade-in">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-[0.25em] text-secondary mb-1">ENG. DEPT • <?= T('nav_order_list', 'Daftar Order / PR') ?></p>
            <h1 class="font-display text-3xl font-black text-primary"><?= T('order_page_title', 'Daftar Order / Purchase Request') ?></h1>
            <p class="text-sm text-secondary mt-1"><?= T('order_page_subtitle', 'List semua permintaan barang & jasa') ?></p>
        </div>
        <?php if ($role !== 'manager'): ?>
            <a href="<?= BASE_URL ?>orders/create.php" class="btn-gold self-start"><i class="fas fa-plus mr-1.5"></i><?= T('nav_order_create', 'Buat Order Request') ?></a>
        <?php endif; ?>
    </div>

    <div class="card-premium p-3 sm:p-4 mb-5">
        <div class="flex flex-wrap gap-2">
            <?php
            foreach ($tabs as $tabKey => $tab) {
                $active = $filter === $tabKey ? 'btn-gold !py-2 !px-3' : 'btn-outline !py-2 !px-3';
                $countBadge = $tab['count'] > 0
                    ? "<span class=\"ml-1.5 inline-flex items-center justify-center h-5 min-w-5 px-1.5 rounded-full text-[10px] font-black " . ($filter === $tabKey ? "bg-white/20 text-white" : "bg-amber-100 text-amber-800") . "\">{$tab['count']}</span>"
                    : '';
                $qs = http_build_query(array_merge($_GET, ['filter' => $tabKey]));
                echo "<a href=\"?{$qs}\" class=\"{$active} text-xs\"><span>{$tab['label']}</span>{$countBadge}</a>";
            }
            ?>
        </div>
    </div>

    <div class="card-premium p-3 sm:p-4 mb-5">
        <form method="get" class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-end">
            <div class="flex-1">
                <label class="form-label !mb-1"><?= T('order_search', 'Cari Order') ?></label>
                <input type="text" name="search" value="<?= $search ?>" placeholder="<?= T('order_search_ph', 'No PR / Judul / Cost Code / Nama Pemohon') ?>" class="form-input">
            </div>
            <input type="hidden" name="filter" value="<?= $filter ?>">
            <div class="flex gap-2">
                <button type="submit" class="btn-gold"><i class="fas fa-search mr-1"></i><?= T('btn_filter', 'Filter') ?></button>
                <a href="?filter=<?= $filter ?>" class="btn-ghost"><?= T('btn_reset', 'Reset') ?></a>
            </div>
        </form>
    </div>

    <div class="card-premium p-0 overflow-hidden">
        <?php if (count($orders) === 0): ?>
            <div class="text-center py-16">
                <div class="w-20 h-20 mx-auto mb-5 rounded-2xl bg-slate-100 flex items-center justify-center"><i class="fas fa-clipboard-list text-4xl text-slate-300"></i></div>
                <p class="text-lg font-bold text-secondary mb-1"><?= T('order_empty', 'Belum ada order request') ?></p>
                <p class="text-sm text-slate-400 mb-6"><?= T('order_empty_sub', 'Belum ada data yang sesuai kriteria') ?></p>
                <?php if ($role !== 'manager'): ?>
                    <a href="<?= BASE_URL ?>orders/create.php" class="btn-gold"><i class="fas fa-plus mr-1.5"></i><?= T('nav_order_create', 'Buat Order Request') ?></a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px]">
                    <thead class="bg-slate-50/80 border-b border-border">
                        <tr>
                            <th class="p-4 text-left text-[11px] font-black uppercase tracking-wider text-secondary"><?= T('order_field_no', 'No. PR') ?></th>
                            <th class="p-4 text-left text-[11px] font-black uppercase tracking-wider text-secondary"><?= T('order_field_title', 'Judul / Keperluan') ?></th>
                            <th class="p-4 text-left text-[11px] font-black uppercase tracking-wider text-secondary"><?= T('order_field_cost', 'Cost Code') ?></th>
                            <th class="p-4 text-left text-[11px] font-black uppercase tracking-wider text-secondary"><?= T('order_field_requestor', 'Pemohon') ?></th>
                            <th class="p-4 text-left text-[11px] font-black uppercase tracking-wider text-secondary"><?= T('order_field_date', 'Tgl') ?></th>
                            <th class="p-4 text-right text-[11px] font-black uppercase tracking-wider text-secondary"><?= T('order_field_total', 'Total') ?></th>
                            <th class="p-4 text-center text-[11px] font-black uppercase tracking-wider text-secondary"><?= T('order_field_status', 'Status') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr class="border-b border-border/60 hover:bg-amber-50/30 transition-colors cursor-pointer" onclick="document.location='<?= BASE_URL ?>orders/detail.php?id=<?= (int)$o['id'] ?>'">
                                <td class="p-4">
                                    <p class="font-black text-primary text-sm"><?= cleanInput($o['order_no']) ?></p>
                                </td>
                                <td class="p-4">
                                    <p class="font-bold text-primary"><?= cleanInput($o['title']) ?></p>
                                    <?php if (!empty($o['purpose'])): ?>
                                        <p class="text-xs text-secondary line-clamp-2 mt-1"><?= cleanInput($o['purpose']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-sm">
                                    <?php if (!empty($o['cc_code'])): ?>
                                        <p class="font-mono font-black text-accent text-xs"><?= cleanInput($o['cc_code']) ?></p>
                                        <p class="text-xs text-secondary mt-0.5"><?= cleanInput($o['cc_name']) ?></p>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-sm font-semibold text-primary"><?= cleanInput($o['req_name']) ?></td>
                                <td class="p-4 text-sm text-secondary whitespace-nowrap"><?= formatDate($o['requested_date']) ?></td>
                                <td class="p-4 text-right font-bold text-primary whitespace-nowrap">Rp <?= formatNumber((float)$o['total_amount'], 2) ?></td>
                                <td class="p-4 text-center"><span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-black uppercase border <?= getOrderStatusBadgeClass($o['status']) ?>"><?= getOrderStatusText($o['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>