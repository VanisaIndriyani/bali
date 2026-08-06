<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = T('eng_act_page_title', 'Engineering Activities');
requireRole('manager');
$page = 'engineering_activities';

$db = Database::getInstance();
$user = currentUser();
$userName = (string)($user['name']  ?? 'Manager');
$userId = (int)($user['id']    ?? 0);

$monthStart = date('Y-m-01');
$today = date('Y-m-d');

function buildActCnt($db, $from, $to, $cat, $userId, $userRole) {
    $col = match($cat) {
        'operation'   => 'activity_operation',
        'maintenance' => 'activity_maintenance',
        'project'     => 'activity_project',
        'landscape'   => 'activity_landscape',
        default       => 'activity_operation'
    };
    $where = "WHERE log_date BETWEEN ? AND ? AND status = 'approved'";
    $params = [$from, $to];
    if ($userRole === 'engineer') {
        $where .= " AND engineer_id = ?";
        $params[] = $userId;
    }
    $row = $db->fetchOne("SELECT COUNT(*) AS total_rows, COALESCE(SUM({$col}),0) AS total_sum FROM daily_logs {$where}", $params);
    $cntAct = 0;
    $listRows = $db->fetchAll("SELECT engineer_id, log_date, {$col}, id FROM daily_logs {$where} ORDER BY log_date DESC, id DESC", $params);
    foreach ($listRows as $lr) {
        $v = (int)($lr[$col] ?? 0);
        if ($v > 0) $cntAct++;
    }
    return ['count' => $cntAct, 'sum' => (float)($row['total_sum'] ?? 0)];
}

$cats = [
    [
        'id'    => 'operation',
        'label' => T('dash_act_operation', 'OPERATION'),
        'icon'  => 'fas fa-gears',
        'iconBox' => 'from-blue-400 to-blue-600',
        'ring' => 'ring-blue-200',
        'bg' => 'bg-blue-50',
        'border' => 'border-blue-200',
        'color' => 'text-blue-700'
    ],
    [
        'id'    => 'maintenance',
        'label' => T('dash_act_maintenance', 'MAINTENANCE'),
        'icon'  => 'fas fa-wrench',
        'iconBox' => 'from-emerald-400 to-emerald-600',
        'ring' => 'ring-emerald-200',
        'bg' => 'bg-emerald-50',
        'border' => 'border-emerald-200',
        'color' => 'text-emerald-700'
    ],
    [
        'id'    => 'project',
        'label' => T('dash_act_project', 'PROJECT'),
        'icon'  => 'fas fa-diagram-project',
        'iconBox' => 'from-violet-400 to-violet-600',
        'ring' => 'ring-violet-200',
        'bg' => 'bg-violet-50',
        'border' => 'border-violet-200',
        'color' => 'text-violet-700'
    ],
    [
        'id'    => 'landscape',
        'label' => T('dash_act_landscape', 'LANDSCAPE'),
        'icon'  => 'fas fa-leaf',
        'iconBox' => 'from-teal-400 to-teal-600',
        'ring' => 'ring-teal-200',
        'bg' => 'bg-teal-50',
        'border' => 'border-teal-200',
        'color' => 'text-teal-700'
    ],
];
$catsData = [];
$actMonthLabel = T('dash_act_bulan_ini', 'aktivitas bulan ini');
$actTodayLabel = T('dash_act_today', 'aktivitas hari ini');
$actEmpty = T('dash_act_kosong', 'Belum ada aktivitas bulan ini.');
$blankIcon = '<i class="far fa-folder-open opacity-60 mr-1"></i>';
$deptLabel = T('dash_act_department', 'DEPARTMENT');
$actDetailLabel = T('dash_act_activity_detail', 'ACTIVITY DETAIL');
$preparedLabel = T('dash_act_prepared', 'PREPARED BY:');
$reviewedLabel = T('dash_act_reviewed', 'REVIEWED BY:');
$preparedName = T('dash_act_prepared_name', 'Engineering Staff');
$reviewedName = T('dash_act_reviewed_name', 'Supervisor Engineering');

$colLeft = [];
$colRight = [];
foreach ($cats as $c) {
    $todayCnt = buildActCnt($db, $today, $today, $c['id'], $userId, 'manager');
    $monthCnt = buildActCnt($db, $monthStart, $today, $c['id'], $userId, 'manager');
    $cnt = (int)($monthCnt['count'] ?? 0);
    $todayN = (int)($todayCnt['count'] ?? 0);
    $colLeft[] = $c;
    $colRight[] = $cnt > 0
        ? '<div class="flex items-center gap-2 text-sm font-bold '.$c['color'].'"><i class="far fa-calendar-check"></i> <span class="font-black">'.$cnt.'</span> '.$actMonthLabel.($todayN > 0 ? ' <span class="text-secondary/70 font-medium text-xs ml-1">(+'.$todayN.' '.$actTodayLabel.')</span>' : '').'</div>'
        : '<div class="flex items-center gap-2 text-sm text-secondary/70 font-medium">'.$blankIcon.$actEmpty.'</div>';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="page-shell page-shell--6xl">
    <div class="page-header flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6 animate-fade-in">
        <div>
            <p class="text-[11px] font-black uppercase tracking-[0.25em] text-indigo-700 mb-2">MANAGER • <?= T('eng_act_header', 'DEPARTMENT PERFORMANCE') ?></p>
            <h1 class="font-display text-3xl font-black text-primary flex items-center gap-3">
                <span class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-800 flex items-center justify-center text-white shadow-md shadow-indigo-500/30">
                    <span class="text-base font-black">3</span>
                </span>
                <span class="tracking-wide"><?= T('eng_act_title', 'ENGINEERING') ?> <span class="font-semibold text-secondary/80 italic"><?= T('eng_act_activities', 'ACTIVITIES') ?></span></span>
            </h1>
            <p class="text-sm text-secondary mt-2"><?= T('eng_act_subtitle', 'Ringkasan 4 divisi Operation, Maintenance, Project dan Landscape per bulan.') ?></p>
        </div>
        <div class="flex flex-wrap gap-2.5 self-start">
            <a href="<?= BASE_URL ?>reports/pdf.php" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gradient-to-br from-rose-500 to-rose-700 hover:from-rose-600 hover:to-rose-800 text-white text-sm font-bold shadow hover:shadow-lg transition-all">
                <i class="far fa-file-pdf"></i> <?= T('btn_export_pdf', 'Export PDF') ?>
            </a>
            <a href="<?= BASE_URL ?>reports/excel.php" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 hover:from-emerald-600 hover:to-emerald-800 text-white text-sm font-bold shadow hover:shadow-lg transition-all">
                <i class="far fa-file-excel"></i> <?= T('btn_export_excel', 'Export Excel') ?>
            </a>
            <a href="<?= BASE_URL ?>index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 text-sm font-semibold shadow-sm transition-all">
                <i class="fas fa-arrow-left"></i> <?= T('btn_back_dash', 'Kembali ke Dashboard') ?>
            </a>
        </div>
    </div>

    <div class="card-premium p-5 sm:p-8 bg-white animate-slide-up" style="animation-delay: 90ms">
        <div class="mb-6">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-slate-200">
                        <th scope="col" class="text-left text-xs font-black uppercase tracking-[0.18em] text-slate-500 pb-3 pl-3 w-1/2"><?= $deptLabel ?></th>
                        <th scope="col" class="text-left text-xs font-black uppercase tracking-[0.18em] text-slate-500 pb-3 pl-3 w-1/2"><?= $actDetailLabel ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($colLeft as $idx => $c): ?>
                        <tr class="transition-colors hover:bg-slate-50/70">
                            <td class="py-4 sm:py-5 pl-3 pr-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br <?= $c['iconBox'] ?> flex items-center justify-center text-white shadow-md ring-2 <?= $c['ring'] ?> shrink-0">
                                        <i class="<?= $c['icon'] ?>"></i>
                                    </div>
                                    <div>
                                        <p class="font-black uppercase tracking-wider text-sm text-primary leading-tight"><?= $c['label'] ?></p>
                                        <p class="text-[10px] font-bold uppercase tracking-widest text-secondary/70 mt-0.5">This Month • <?= date('M Y') ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-4 sm:py-5 pl-3 pr-4">
                                <?= $colRight[$idx] ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="pt-6 sm:pt-8 border-t border-dashed border-slate-200">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-8">
                <div class="text-left">
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500 mb-8"><?= $preparedLabel ?></p>
                    <p class="font-bold text-base text-primary border-b-2 border-slate-900/80 pb-1 inline-block"><?= $preparedName ?></p>
                </div>
                <div class="text-right sm:text-right">
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500 mb-8"><?= $reviewedLabel ?></p>
                    <p class="font-bold text-base text-primary border-b-2 border-slate-900/80 pb-1 inline-block"><?= $reviewedName ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
