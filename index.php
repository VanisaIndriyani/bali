<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/config/config.php';
requireLogin();

$db = Database::getInstance();
$user = currentUser();
$userName = (string)($user['name']  ?? 'User');
$userRole = (string)($user['role']  ?? 'engineer');
$userId = (int)($user['id']      ?? 0);

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$statusWhere = $userRole === 'engineer' ? "AND engineer_id = $userId" : "";

$totalLogs = $db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE 1=1 $statusWhere")['cnt'];
$pendingLogs = $db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE status = 'pending' $statusWhere")['cnt'];
$approvedLogs = $db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE status = 'approved' $statusWhere")['cnt'];
$rejectedLogs = $db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE status = 'rejected' $statusWhere")['cnt'];

$todayData = $db->fetchOne("SELECT * FROM daily_logs WHERE log_date = ? $statusWhere LIMIT 1", [$today]);

$monthlyElectricity = $db->fetchOne("SELECT COALESCE(SUM(total_electricity),0) as total FROM daily_logs WHERE log_date >= ? AND status = 'approved' $statusWhere", [$monthStart])['total'];
$monthlyWater = $db->fetchOne("SELECT COALESCE(SUM(total_water),0) as total FROM daily_logs WHERE log_date >= ? AND status = 'approved' $statusWhere", [$monthStart])['total'];
$monthlyGas = $db->fetchOne("SELECT COALESCE(SUM(total_gas),0) as total FROM daily_logs WHERE log_date >= ? AND status = 'approved' $statusWhere", [$monthStart])['total'];
$monthlySwro = $db->fetchOne("SELECT COALESCE(SUM(swro_watermeter),0) as total FROM daily_logs WHERE log_date >= ? AND status = 'approved' $statusWhere", [$monthStart])['total'];
$monthlyBottling = $db->fetchOne("SELECT COALESCE(SUM(bottling_watermeter),0) as total FROM daily_logs WHERE log_date >= ? AND status = 'approved' $statusWhere", [$monthStart])['total'];
$monthlyChiller = $db->fetchOne("SELECT COALESCE(COUNT(*),0) as total FROM daily_logs WHERE log_date >= ? AND status = 'approved' AND (chiller_1_on = 1 OR chiller_2_on = 1 OR chiller_3_on = 1) $statusWhere", [$monthStart])['total'];

$whereClause = $userRole === 'engineer' ? "WHERE engineer_id = $userId" : "";
$recentLogs = $db->fetchAll("SELECT dl.*, u.name as engineer_name FROM daily_logs dl LEFT JOIN users u ON dl.engineer_id = u.id $whereClause ORDER BY dl.log_date DESC LIMIT 5");

$defaultFrom = date('Y-m-01');
$defaultTo = date('Y-m-t');

$viewE = $_GET['view_e'] ?? 'daily';
$dateFromE = $_GET['date_from_e'] ?? $defaultFrom;
$dateToE = $_GET['date_to_e'] ?? $defaultTo;

$viewW = $_GET['view_w'] ?? 'daily';
$dateFromW = $_GET['date_from_w'] ?? $defaultFrom;
$dateToW = $_GET['date_to_w'] ?? $defaultTo;

$viewG = $_GET['view_g'] ?? 'daily';
$dateFromG = $_GET['date_from_g'] ?? $defaultFrom;
$dateToG = $_GET['date_to_g'] ?? $defaultTo;

function buildChartQuery($db, $userRole, $userId, $viewType, $dateFrom, $dateTo, $sumColumn, $aggregate = 'SUM')
{
    $baseWhere = "WHERE dl.status = 'approved' AND dl.log_date BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    if ($userRole === 'engineer') {
        $baseWhere .= " AND dl.engineer_id = ?";
        $params[] = $userId;
    }
    if ($viewType === 'daily') {
        $sql = "SELECT dl.log_date as label, COALESCE($aggregate(dl.$sumColumn),0) as value FROM daily_logs dl $baseWhere GROUP BY dl.log_date ORDER BY dl.log_date ASC";
    } else {
        $sql = "SELECT DATE_FORMAT(dl.log_date, '%Y-%m') as label, COALESCE($aggregate(dl.$sumColumn),0) as value FROM daily_logs dl $baseWhere GROUP BY DATE_FORMAT(dl.log_date, '%Y-%m') ORDER BY label ASC";
    }
    return $db->fetchAll($sql, $params);
}

$electricityData = buildChartQuery($db, $userRole, $userId, $viewE, $dateFromE, $dateToE, 'total_electricity');
$waterData = buildChartQuery($db, $userRole, $userId, $viewW, $dateFromW, $dateToW, 'total_water');
$gasData = buildChartQuery($db, $userRole, $userId, $viewG, $dateFromG, $dateToG, 'total_gas');

function buildOtherQs($excludePrefix, $viewE, $dateFromE, $dateToE, $viewW, $dateFromW, $dateToW, $viewG, $dateFromG, $dateToG)
{
    $maps = [
        'e' => ['view_e' => $viewE, 'date_from_e' => $dateFromE, 'date_to_e' => $dateToE],
        'w' => ['view_w' => $viewW, 'date_from_w' => $dateFromW, 'date_to_w' => $dateToW],
        'g' => ['view_g' => $viewG, 'date_from_g' => $dateFromG, 'date_to_g' => $dateToG],
    ];
    $qs = [];
    foreach ($maps as $p => $kv) {
        if ($p === $excludePrefix) continue;
        foreach ($kv as $k => $v) $qs[] = $k . '=' . urlencode($v);
    }
    return empty($qs) ? '' : implode('&', $qs) . '&';
}

function buildModalQuery($db, $userRole, $userId, $columns, $dateFrom, $dateTo)
{
    $colArr = is_array($columns) ? $columns : array_map('trim', explode(',', $columns));
    $coalesced = [];
    foreach ($colArr as $c) {
        $pure = trim($c);
        if (strpos($pure, 'dl.') === 0) $pureName = substr($pure, 3);
        else $pureName = $pure;
        $coalesced[] = "COALESCE(SUM($pure), 0) as $pureName";
    }
    $colsSql = implode(', ', $coalesced);
    $baseWhere = "WHERE dl.status = 'approved' AND dl.log_date BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    if ($userRole === 'engineer') {
        $baseWhere .= " AND dl.engineer_id = ?";
        $params[] = $userId;
    }
    $sql = "SELECT DATE(dl.log_date) as label, $colsSql FROM daily_logs dl $baseWhere GROUP BY DATE(dl.log_date) ORDER BY DATE(dl.log_date) ASC";
    return $db->fetchAll($sql, $params);
}

$electricityDetailData = buildModalQuery($db, $userRole, $userId, 'dl.electricity_wbp, dl.electricity_lwbp', $monthStart, $today);
$waterDetailData = buildModalQuery($db, $userRole, $userId, 'dl.water_pdam, dl.water_iki_gaban, dl.water_deepwell_1, dl.water_deepwell_2_brr, dl.water_deepwell_asean, dl.water_deepwell_lpb, dl.water_main_building, dl.water_cooling_tower, dl.water_bottling', $monthStart, $today);
$gasDetailData = buildModalQuery($db, $userRole, $userId, 'dl.gas_lpg, dl.gas_lng', $monthStart, $today);
$swroDetailData = buildModalQuery($db, $userRole, $userId, 'dl.swro_watermeter, dl.swro_kwh, dl.swro_tds', $monthStart, $today);
$bottlingDetailData = buildModalQuery($db, $userRole, $userId, 'dl.bottling_kwh, dl.bottling_watermeter', $monthStart, $today);
$chillerDetailData = buildModalQuery($db, $userRole, $userId, 'dl.chiller_water_ph, dl.chiller_water_tds, dl.chiller_temp, dl.chiller_1_on, dl.chiller_2_on, dl.chiller_3_on', $monthStart, $today);

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="page-shell page-shell--7xl">
    <div class="mb-8 animate-fade-in">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <p class="text-sm text-secondary mb-1">
                    <i class="fas fa-calendar-day mr-1.5 text-accent"></i><?= formatDate($today) ?>
                </p>
                <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">
                    <?= T('wel_back', 'Selamat Datang') ?>, <?= $userRole === 'engineer' ? T('wel_role_engineer_brand', 'Engineering Department') : cleanInput($userName) ?> <span class="text-accent">👋</span>
                </h1>
                <p class="text-secondary"><?= $userRole === 'engineer' ? T('wel_role_engineer', 'Dashboard Engineering Staff') : T('wel_role_supervisor', 'Dashboard Engineering Supervisor') ?></p>
            </div>
            <?php if ($userRole === 'engineer' || $userRole === 'supervisor'): ?>
                <?php $todayLogHref = BASE_URL . 'engineer/daily_log_form.php?date=' . urlencode($today); ?>
                <a href="<?= $todayLogHref ?>" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl bg-gradient-to-br from-amber-500 to-amber-700 hover:from-amber-600 hover:to-amber-800 text-white font-bold text-sm shadow-lg shadow-amber-500/25 hover:shadow-xl hover:shadow-amber-500/30 hover:-translate-y-0.5 transition-all duration-300">
                    <i class="fas fa-pen-ruler"></i>
                    <span><?= $todayData ? T('wel_edit_log', 'Edit Daily Log Hari Ini') : T('wel_fill_log', '✍️ Isi Daily Log Hari Ini') ?></span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-5 mb-8">
        <div class="stat-card animate-slide-up" style="animation-delay: 0ms">
            <div class="flex items-start justify-between mb-4">
                <div class="w-11 h-11 rounded-xl bg-primary/10 flex items-center justify-center">
                    <i class="fas fa-file-lines text-lg text-primary"></i>
                </div>
                <span class="text-xs font-semibold text-secondary uppercase tracking-wider"><?= T('stat_label_total', 'Total') ?></span>
            </div>
            <h3 class="text-3xl font-bold text-primary mb-1"><?= formatNumber($totalLogs) ?></h3>
            <p class="text-sm text-secondary"><?= T('stat_total_log', 'Total Daily Log') ?></p>
        </div>

        <div class="stat-card animate-slide-up" style="animation-delay: 60ms">
            <div class="flex items-start justify-between mb-4">
                <div class="w-11 h-11 rounded-xl bg-yellow-100 flex items-center justify-center">
                    <i class="fas fa-clock text-lg text-yellow-600"></i>
                </div>
                <span class="text-xs font-semibold text-yellow-600 uppercase tracking-wider"><?= T('stat_label_pending', 'Pending') ?></span>
            </div>
            <h3 class="text-3xl font-bold text-yellow-600 mb-1"><?= formatNumber($pendingLogs) ?></h3>
            <p class="text-sm text-secondary"><?= T('stat_pending', 'Menunggu Approval') ?></p>
        </div>

        <div class="stat-card animate-slide-up" style="animation-delay: 120ms">
            <div class="flex items-start justify-between mb-4">
                <div class="w-11 h-11 rounded-xl bg-green-100 flex items-center justify-center">
                    <i class="fas fa-circle-check text-lg text-green-600"></i>
                </div>
                <span class="text-xs font-semibold text-green-600 uppercase tracking-wider"><?= T('stat_label_approved', 'Approved') ?></span>
            </div>
            <h3 class="text-3xl font-bold text-green-600 mb-1"><?= formatNumber($approvedLogs) ?></h3>
            <p class="text-sm text-secondary"><?= T('stat_approved', 'Sudah Disetujui') ?></p>
        </div>

        <div class="stat-card animate-slide-up" style="animation-delay: 180ms">
            <div class="flex items-start justify-between mb-4">
                <div class="w-11 h-11 rounded-xl bg-red-100 flex items-center justify-center">
                    <i class="fas fa-circle-xmark text-lg text-red-600"></i>
                </div>
                <span class="text-xs font-semibold text-red-600 uppercase tracking-wider"><?= T('stat_label_rejected', 'Rejected') ?></span>
            </div>
            <h3 class="text-3xl font-bold text-red-600 mb-1"><?= formatNumber($rejectedLogs) ?></h3>
            <p class="text-sm text-secondary"><?= T('stat_rejected', 'Ditolak') ?></p>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-5 mb-8">
        <div class="consumption-card animate-slide-up cursor-pointer hover:scale-[1.02] hover:shadow-xl hover:-translate-y-1 transition-all duration-300" style="animation-delay: 240ms" onclick="openModal('electricity')">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                    <i class="fas fa-bolt text-amber-600"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-secondary uppercase tracking-wider font-semibold"><?= T('card_this_month', 'Bulan Ini') ?></p>
                    <h3 class="font-bold text-primary"><?= T('card_electricity', 'Listrik') ?></h3>
                </div>
                <i class="fas fa-chevron-right text-amber-500 text-sm opacity-60 hover:opacity-100 transition-opacity"></i>
            </div>
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-bold text-primary"><?= formatNumber($monthlyElectricity) ?></span>
                <span class="text-sm text-secondary">kWh</span>
            </div>
            <div class="mt-3 pt-3 border-t border-border flex items-center gap-1.5 text-xs text-amber-600 font-semibold">
                <i class="fas fa-chart-line"></i><span><?= T('card_cta_electricity', 'Lihat rincian WBP / LWBP →') ?></span>
            </div>
        </div>

        <div class="consumption-card animate-slide-up cursor-pointer hover:scale-[1.02] hover:shadow-xl hover:-translate-y-1 transition-all duration-300" style="animation-delay: 280ms" onclick="openModal('water')">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                    <i class="fas fa-droplet text-blue-600"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-secondary uppercase tracking-wider font-semibold"><?= T('card_this_month', 'Bulan Ini') ?></p>
                    <h3 class="font-bold text-primary"><?= T('card_water', 'Air') ?></h3>
                </div>
                <i class="fas fa-chevron-right text-blue-500 text-sm opacity-60 hover:opacity-100 transition-opacity"></i>
            </div>
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-bold text-primary"><?= formatNumber($monthlyWater) ?></span>
                <span class="text-sm text-secondary">m³</span>
            </div>
            <div class="mt-3 pt-3 border-t border-border flex items-center gap-1.5 text-xs text-blue-600 font-semibold">
                <i class="fas fa-layer-group"></i><span><?= T('card_cta_water', 'Lihat 9 rincian sumber air →') ?></span>
            </div>
        </div>

        <div class="consumption-card animate-slide-up cursor-pointer hover:scale-[1.02] hover:shadow-xl hover:-translate-y-1 transition-all duration-300" style="animation-delay: 320ms" onclick="openModal('gas')">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-lg bg-orange-100 flex items-center justify-center">
                    <i class="fas fa-fire text-orange-600"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-secondary uppercase tracking-wider font-semibold"><?= T('card_this_month', 'Bulan Ini') ?></p>
                    <h3 class="font-bold text-primary"><?= T('card_gas', 'Gas') ?></h3>
                </div>
                <i class="fas fa-chevron-right text-orange-500 text-sm opacity-60 hover:opacity-100 transition-opacity"></i>
            </div>
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-bold text-primary"><?= formatNumber($monthlyGas) ?></span>
                <span class="text-sm text-secondary">kg</span>
            </div>
            <div class="mt-3 pt-3 border-t border-border flex items-center gap-1.5 text-xs text-orange-600 font-semibold">
                <i class="fas fa-chart-column"></i><span><?= T('card_cta_gas', 'Lihat rincian LPG / LNG →') ?></span>
            </div>
        </div>

        <div class="consumption-card animate-slide-up cursor-pointer hover:scale-[1.02] hover:shadow-xl hover:-translate-y-1 transition-all duration-300" style="animation-delay: 360ms" onclick="openModal('swro')">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-lg bg-cyan-100 flex items-center justify-center">
                    <i class="fas fa-water text-cyan-600"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-secondary uppercase tracking-wider font-semibold"><?= T('card_this_month', 'Bulan Ini') ?></p>
                    <h3 class="font-bold text-primary"><?= T('card_swro', 'SWRO System') ?></h3>
                </div>
                <i class="fas fa-chevron-right text-cyan-500 text-sm opacity-60 hover:opacity-100 transition-opacity"></i>
            </div>
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-bold text-primary"><?= formatNumber($monthlySwro) ?></span>
                <span class="text-sm text-secondary">m³</span>
            </div>
            <div class="mt-3 pt-3 border-t border-border flex items-center gap-1.5 text-xs text-cyan-600 font-semibold">
                <i class="fas fa-gauge-high"></i><span><?= T('card_cta_swro', 'Lihat rincian Watermeter & TDS →') ?></span>
            </div>
        </div>

        <div class="consumption-card animate-slide-up cursor-pointer hover:scale-[1.02] hover:shadow-xl hover:-translate-y-1 transition-all duration-300" style="animation-delay: 400ms" onclick="openModal('bottling')">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-lg bg-violet-100 flex items-center justify-center">
                    <i class="fas fa-industry text-violet-600"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-secondary uppercase tracking-wider font-semibold"><?= T('card_this_month', 'Bulan Ini') ?></p>
                    <h3 class="font-bold text-primary"><?= T('card_bottling', 'Bottling Plant') ?></h3>
                </div>
                <i class="fas fa-chevron-right text-violet-500 text-sm opacity-60 hover:opacity-100 transition-opacity"></i>
            </div>
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-bold text-primary"><?= formatNumber($monthlyBottling) ?></span>
                <span class="text-sm text-secondary">m³</span>
            </div>
            <div class="mt-3 pt-3 border-t border-border flex items-center gap-1.5 text-xs text-violet-600 font-semibold">
                <i class="fas fa-industry"></i><span><?= T('card_cta_bottling', 'Lihat rincian Watermeter & kWh →') ?></span>
            </div>
        </div>

        <div class="consumption-card animate-slide-up cursor-pointer hover:scale-[1.02] hover:shadow-xl hover:-translate-y-1 transition-all duration-300" style="animation-delay: 440ms" onclick="openModal('chiller')">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                    <i class="fas fa-snowflake text-emerald-600"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-secondary uppercase tracking-wider font-semibold"><?= T('card_this_month', 'Bulan Ini') ?></p>
                    <h3 class="font-bold text-primary"><?= T('card_chiller', 'Chiller System') ?></h3>
                </div>
                <i class="fas fa-chevron-right text-emerald-500 text-sm opacity-60 hover:opacity-100 transition-opacity"></i>
            </div>
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-bold text-primary"><?= formatNumber($monthlyChiller) ?></span>
                <span class="text-sm text-secondary"><?= T('chiller_hari_aktif', 'hari aktif') ?></span>
            </div>
            <div class="mt-3 pt-3 border-t border-border flex items-center gap-1.5 text-xs text-emerald-600 font-semibold">
                <i class="fas fa-temperature-half"></i><span><?= T('card_cta_chiller', 'Lihat pH, TDS & unit ON →') ?></span>
            </div>
        </div>
    </div>

    <?php if ($todayData): ?>
        <div class="mb-8 p-4 sm:p-5 lg:p-6 bg-surface rounded-premium border border-border shadow-sm animate-slide-up">
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-4">
                <i class="fas fa-calendar-check text-accent text-xl"></i>
                <h2 class="font-bold text-lg text-primary"><?= T('today_title', 'Daily Log Hari Ini') ?></h2>
                <span class="sm:ml-auto px-3 py-1 rounded-full text-xs font-semibold <?= getStatusBadgeClass($todayData['status']) ?>"><?= getStatusText($todayData['status']) ?></span>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 text-sm">
                <div><p class="text-secondary mb-1 text-xs sm:text-sm">⚡ <?= T('card_electricity', 'Listrik') ?></p><p class="font-bold text-primary text-base sm:text-lg"><?= formatNumber($todayData['total_electricity']) ?> kWh</p></div>
                <div><p class="text-secondary mb-1 text-xs sm:text-sm">💧 <?= T('card_water', 'Air') ?></p><p class="font-bold text-primary text-base sm:text-lg"><?= formatNumber($todayData['total_water']) ?> m³</p></div>
                <div><p class="text-secondary mb-1 text-xs sm:text-sm">🔥 <?= T('card_gas', 'Gas') ?></p><p class="font-bold text-primary text-base sm:text-lg"><?= formatNumber($todayData['total_gas']) ?> kg</p></div>
                <div><p class="text-secondary mb-1 text-xs sm:text-sm">📅 <?= T('general_date', 'Tanggal') ?></p><p class="font-bold text-primary text-base sm:text-lg"><?= formatDate($todayData['log_date']) ?></p></div>
            </div>
            <?php if ($todayData['status'] === 'rejected' && $todayData['revision_notes']): ?>
                <div class="mt-4 p-4 rounded-card bg-red-50 border border-red-200">
                    <p class="text-xs font-semibold text-red-700 mb-1.5"><i class="fas fa-triangle-exclamation mr-1"></i><?= T('today_revisi_label', 'Catatan Revisi Supervisor') ?>:</p>
                    <p class="text-sm text-red-800"><?= nl2br(cleanInput($todayData['revision_notes'])) ?></p>
                    <?php if ($userRole === 'engineer'): ?>
                        <a href="<?= BASE_URL ?>engineer/daily_log_form.php?date=<?= $todayData['log_date'] ?>" class="inline-flex items-center gap-1.5 mt-3 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold rounded-full transition-all">
                            <i class="fas fa-redo"></i> <?= T('today_edit_ulang', 'Edit Ulang') ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($userRole === 'supervisor'): ?>

    <div class="mb-6 bg-surface rounded-premium border border-border p-4 sm:p-5 shadow-sm animate-slide-up" style="animation-delay: 420ms">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div>
                <h2 class="font-bold text-lg text-primary mb-1"><i class="fas fa-chart-line mr-2 text-accent"></i><?= T('sup_chart_title', 'Grafik Konsumsi Energi') ?></h2>
                <p class="text-sm text-secondary"><?= T('sup_chart_sub', 'Setiap grafik dilengkapi filter Harian / Bulanan & pilih tanggal sendiri-sendiri') ?></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <?php $exportQs = 'view=' . urlencode($viewE) . '&date_from=' . urlencode($dateFromE) . '&date_to=' . urlencode($dateToE); ?>
                <a href="<?= BASE_URL ?>reports/dashboard_pdf.php?<?= $exportQs ?>" target="_blank"
                    class="inline-flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-lg bg-red-50 text-red-700 border border-red-200 text-xs sm:text-sm font-semibold hover:bg-red-100 hover:-translate-y-0.5 transition-all duration-200 shadow-sm">
                    <i class="fas fa-file-pdf"></i><span class="hidden sm:inline"><?= T('general_export', 'Export') ?></span> PDF
                </a>
              
            </div>
        </div>
    </div>

    <div class="flex flex-col gap-5 sm:gap-6 mb-8">

        <div class="chart-card animate-slide-up overflow-hidden" style="animation-delay: 450ms">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
                <div>
                    <h3 class="font-bold text-xl text-primary mb-1">⚡ <?= T('sup_elec_title', 'Total Consume Listrik') ?></h3>
                    <p class="text-xs text-secondary"><?= T('sup_chart_period', 'Periode') ?>: <?= formatDate($dateFromE) ?> - <?= formatDate($dateToE) ?> (kWh)</p>
                </div>
            </div>
            <form method="GET" class="mb-4 flex flex-col gap-3">
                <div class="flex flex-wrap gap-2 w-full">
                    <?php $otherQs = buildOtherQs('e', $viewE, $dateFromE, $dateToE, $viewW, $dateFromW, $dateToW, $viewG, $dateFromG, $dateToG); ?>
                    <div class="flex gap-2 flex-1 sm:flex-none">
                        <a href="?<?= $otherQs ?>view_e=daily&date_from_e=<?= urlencode($dateFromE) ?>&date_to_e=<?= urlencode($dateToE) ?>"
                            class="px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium border border-border hover:bg-muted transition-colors flex-1 sm:flex-none text-center <?= $viewE === 'daily' ? 'bg-amber-600 text-white border-amber-600 shadow-md' : 'bg-surface text-primary' ?>"><?= T('filter_daily', 'Harian') ?></a>
                        <a href="?<?= $otherQs ?>view_e=monthly&date_from_e=<?= urlencode($dateFromE) ?>&date_to_e=<?= urlencode($dateToE) ?>"
                            class="px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium border border-border hover:bg-muted transition-colors flex-1 sm:flex-none text-center <?= $viewE === 'monthly' ? 'bg-amber-600 text-white border-amber-600 shadow-md' : 'bg-surface text-primary' ?>"><?= T('filter_monthly', 'Bulanan') ?></a>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 items-end">
                    <div class="md:col-span-2">
                        <label class="text-xs text-secondary font-medium mb-1 block"><?= T('filter_from', 'Dari Tanggal') ?></label>
                        <input type="date" name="date_from_e" value="<?= $dateFromE ?>" class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-surface text-primary focus:outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-500/10">
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs text-secondary font-medium mb-1 block"><?= T('filter_to', 'Sampai Tanggal') ?></label>
                        <input type="date" name="date_to_e" value="<?= $dateToE ?>" class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-surface text-primary focus:outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-500/10">
                    </div>
                    <div class="flex gap-2 md:col-span-1">
                        <input type="hidden" name="view_e" value="<?= $viewE ?>">
                        <input type="hidden" name="view_w" value="<?= $viewW ?>">
                        <input type="hidden" name="date_from_w" value="<?= $dateFromW ?>">
                        <input type="hidden" name="date_to_w" value="<?= $dateToW ?>">
                        <input type="hidden" name="view_g" value="<?= $viewG ?>">
                        <input type="hidden" name="date_from_g" value="<?= $dateFromG ?>">
                        <input type="hidden" name="date_to_g" value="<?= $dateToG ?>">
                        <button type="submit" class="flex-1 px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm rounded-lg transition-colors flex items-center justify-center gap-1.5 shadow-sm">
                            <i class="fas fa-filter"></i><span class="hidden sm:inline"><?= T('general_filter', 'Filter') ?></span>
                        </button>
                        <?php $resetQs = buildOtherQs('e', '', '', '', $viewW, $dateFromW, $dateToW, $viewG, $dateFromG, $dateToG); ?>
                        <a href="?<?= rtrim($resetQs, '&') ?>" class="flex-1 px-4 py-2 bg-muted text-primary text-sm rounded-lg border border-border hover:bg-white transition-colors flex items-center justify-center gap-1.5">
                            <i class="fas fa-rotate-left"></i><span class="hidden sm:inline"><?= T('general_reset', 'Reset') ?></span>
                        </a>
                    </div>
                </div>
            </form>
            <div class="h-64 sm:h-72 lg:h-80 w-full min-w-0"><canvas id="electricityChart"></canvas></div>
        </div>

        <div class="chart-card animate-slide-up overflow-hidden" style="animation-delay: 500ms">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
                <div>
                    <h3 class="font-bold text-xl text-primary mb-1">💧 <?= T('sup_water_title', 'Total Consume Air') ?></h3>
                    <p class="text-xs text-secondary"><?= T('sup_chart_period', 'Periode') ?>: <?= formatDate($dateFromW) ?> - <?= formatDate($dateToW) ?> (m³)</p>
                </div>
            </div>
            <form method="GET" class="mb-4 flex flex-col gap-3">
                <div class="flex flex-wrap gap-2 w-full">
                    <?php $otherQs = buildOtherQs('w', $viewE, $dateFromE, $dateToE, $viewW, $dateFromW, $dateToW, $viewG, $dateFromG, $dateToG); ?>
                    <div class="flex gap-2 flex-1 sm:flex-none">
                        <a href="?<?= $otherQs ?>view_w=daily&date_from_w=<?= urlencode($dateFromW) ?>&date_to_w=<?= urlencode($dateToW) ?>"
                            class="px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium border border-border hover:bg-muted transition-colors flex-1 sm:flex-none text-center <?= $viewW === 'daily' ? 'bg-blue-600 text-white border-blue-600 shadow-md' : 'bg-surface text-primary' ?>"><?= T('filter_daily', 'Harian') ?></a>
                        <a href="?<?= $otherQs ?>view_w=monthly&date_from_w=<?= urlencode($dateFromW) ?>&date_to_w=<?= urlencode($dateToW) ?>"
                            class="px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium border border-border hover:bg-muted transition-colors flex-1 sm:flex-none text-center <?= $viewW === 'monthly' ? 'bg-blue-600 text-white border-blue-600 shadow-md' : 'bg-surface text-primary' ?>"><?= T('filter_monthly', 'Bulanan') ?></a>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 items-end">
                    <div class="md:col-span-2">
                        <label class="text-xs text-secondary font-medium mb-1 block"><?= T('filter_from', 'Dari Tanggal') ?></label>
                        <input type="date" name="date_from_w" value="<?= $dateFromW ?>" class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-surface text-primary focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/10">
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs text-secondary font-medium mb-1 block"><?= T('filter_to', 'Sampai Tanggal') ?></label>
                        <input type="date" name="date_to_w" value="<?= $dateToW ?>" class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-surface text-primary focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/10">
                    </div>
                    <div class="flex gap-2 md:col-span-1">
                        <input type="hidden" name="view_w" value="<?= $viewW ?>">
                        <input type="hidden" name="view_e" value="<?= $viewE ?>">
                        <input type="hidden" name="date_from_e" value="<?= $dateFromE ?>">
                        <input type="hidden" name="date_to_e" value="<?= $dateToE ?>">
                        <input type="hidden" name="view_g" value="<?= $viewG ?>">
                        <input type="hidden" name="date_from_g" value="<?= $dateFromG ?>">
                        <input type="hidden" name="date_to_g" value="<?= $dateToG ?>">
                        <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg transition-colors flex items-center justify-center gap-1.5 shadow-sm">
                            <i class="fas fa-filter"></i><span class="hidden sm:inline"><?= T('general_filter', 'Filter') ?></span>
                        </button>
                        <?php $resetQs = buildOtherQs('w', $viewE, $dateFromE, $dateToE, '', '', '', $viewG, $dateFromG, $dateToG); ?>
                        <a href="?<?= rtrim($resetQs, '&') ?>" class="flex-1 px-4 py-2 bg-muted text-primary text-sm rounded-lg border border-border hover:bg-white transition-colors flex items-center justify-center gap-1.5">
                            <i class="fas fa-rotate-left"></i><span class="hidden sm:inline"><?= T('general_reset', 'Reset') ?></span>
                        </a>
                    </div>
                </div>
            </form>
            <div class="h-64 sm:h-72 lg:h-80 w-full min-w-0"><canvas id="waterChart"></canvas></div>
        </div>

        <div class="chart-card animate-slide-up overflow-hidden" style="animation-delay: 550ms">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
                <div>
                    <h3 class="font-bold text-xl text-primary mb-1">🔥 <?= T('sup_gas_title', 'Total Consume Gas') ?></h3>
                    <p class="text-xs text-secondary"><?= T('sup_chart_period', 'Periode') ?>: <?= formatDate($dateFromG) ?> - <?= formatDate($dateToG) ?> (kg)</p>
                </div>
            </div>
            <form method="GET" class="mb-4 flex flex-col gap-3">
                <div class="flex flex-wrap gap-2 w-full">
                    <?php $otherQs = buildOtherQs('g', $viewE, $dateFromE, $dateToE, $viewW, $dateFromW, $dateToW, $viewG, $dateFromG, $dateToG); ?>
                    <div class="flex gap-2 flex-1 sm:flex-none">
                        <a href="?<?= $otherQs ?>view_g=daily&date_from_g=<?= urlencode($dateFromG) ?>&date_to_g=<?= urlencode($dateToG) ?>"
                            class="px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium border border-border hover:bg-muted transition-colors flex-1 sm:flex-none text-center <?= $viewG === 'daily' ? 'bg-orange-600 text-white border-orange-600 shadow-md' : 'bg-surface text-primary' ?>"><?= T('filter_daily', 'Harian') ?></a>
                        <a href="?<?= $otherQs ?>view_g=monthly&date_from_g=<?= urlencode($dateFromG) ?>&date_to_g=<?= urlencode($dateToG) ?>"
                            class="px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium border border-border hover:bg-muted transition-colors flex-1 sm:flex-none text-center <?= $viewG === 'monthly' ? 'bg-orange-600 text-white border-orange-600 shadow-md' : 'bg-surface text-primary' ?>"><?= T('filter_monthly', 'Bulanan') ?></a>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 items-end">
                    <div class="md:col-span-2">
                        <label class="text-xs text-secondary font-medium mb-1 block"><?= T('filter_from', 'Dari Tanggal') ?></label>
                        <input type="date" name="date_from_g" value="<?= $dateFromG ?>" class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-surface text-primary focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-500/10">
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs text-secondary font-medium mb-1 block"><?= T('filter_to', 'Sampai Tanggal') ?></label>
                        <input type="date" name="date_to_g" value="<?= $dateToG ?>" class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-surface text-primary focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-500/10">
                    </div>
                    <div class="flex gap-2 md:col-span-1">
                        <input type="hidden" name="view_g" value="<?= $viewG ?>">
                        <input type="hidden" name="view_e" value="<?= $viewE ?>">
                        <input type="hidden" name="date_from_e" value="<?= $dateFromE ?>">
                        <input type="hidden" name="date_to_e" value="<?= $dateToE ?>">
                        <input type="hidden" name="view_w" value="<?= $viewW ?>">
                        <input type="hidden" name="date_from_w" value="<?= $dateFromW ?>">
                        <input type="hidden" name="date_to_w" value="<?= $dateToW ?>">
                        <button type="submit" class="flex-1 px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white text-sm rounded-lg transition-colors flex items-center justify-center gap-1.5 shadow-sm">
                            <i class="fas fa-filter"></i><span class="hidden sm:inline"><?= T('general_filter', 'Filter') ?></span>
                        </button>
                        <?php $resetQs = buildOtherQs('g', $viewE, $dateFromE, $dateToE, $viewW, $dateFromW, $dateToW, '', '', ''); ?>
                        <a href="?<?= rtrim($resetQs, '&') ?>" class="flex-1 px-4 py-2 bg-muted text-primary text-sm rounded-lg border border-border hover:bg-white transition-colors flex items-center justify-center gap-1.5">
                            <i class="fas fa-rotate-left"></i><span class="hidden sm:inline"><?= T('general_reset', 'Reset') ?></span>
                        </a>
                    </div>
                </div>
            </form>
            <div class="h-64 sm:h-72 lg:h-80 w-full min-w-0"><canvas id="gasChart"></canvas></div>
        </div>

    </div>

    <?php endif; ?>

    <?php
    $historyColSpan = $userRole === 'supervisor' ? 6 : 5;
    ?>

    <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 600ms">
        <div class="p-4 sm:p-5 lg:p-6 border-b border-border flex flex-col sm:flex-row sm:items-center gap-3 sm:justify-between">
            <div>
                <h2 class="font-bold text-lg text-primary"><i class="fas fa-clock-rotate-left mr-2 text-accent"></i><?= $userRole === 'supervisor' ? T('recent_sup_title', 'Daily Log Terbaru (Semua Staff)') : T('recent_user_title', 'Riwayat Daily Log Saya') ?></h2>
                <p class="text-sm text-secondary"><?= $userRole === 'supervisor' ? T('recent_sup_sub', '5 entri terakhir dari seluruh engineer') : T('recent_user_sub', '5 entri terakhir Anda') ?></p>
            </div>
            <a href="<?= BASE_URL ?>history.php" class="text-sm font-semibold text-primary hover:text-accent transition-colors inline-flex items-center gap-1 self-start sm:self-center">
                <?= T('general_lihat_semua', 'Lihat Semua') ?> <i class="fas fa-arrow-right text-xs"></i>
            </a>
        </div>
        <div class="overflow-x-auto -mx-4 sm:mx-0 px-4 sm:px-0">
            <table class="w-full text-sm min-w-[640px]">
                <thead class="bg-muted">
                    <tr class="text-left text-secondary">
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap"><?= T('table_tanggal', 'Tanggal') ?></th>
                        <?php if ($userRole === 'supervisor'): ?>
                            <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap"><?= T('table_engineer', 'Engineer') ?></th>
                        <?php endif; ?>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold text-right whitespace-nowrap"><?= T('card_electricity', 'Listrik') ?></th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold text-right whitespace-nowrap"><?= T('card_water', 'Air') ?></th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold text-right whitespace-nowrap"><?= T('card_gas', 'Gas') ?></th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap"><?= T('table_status', 'Status') ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <?php if (count($recentLogs) > 0): ?>
                        <?php foreach ($recentLogs as $log): ?>
                            <tr class="hover:bg-muted/50 transition-colors">
                                <td class="px-4 sm:px-5 py-4 font-medium text-primary whitespace-nowrap"><?= formatDate($log['log_date']) ?></td>
                                <?php if ($userRole === 'supervisor'): ?>
                                    <td class="px-4 sm:px-5 py-4 text-secondary whitespace-nowrap"><?= cleanInput($log['engineer_name'] ?? '-') ?></td>
                                <?php endif; ?>
                                <td class="px-4 sm:px-5 py-4 text-right font-medium text-primary whitespace-nowrap"><?= formatNumber($log['total_electricity']) ?> kWh</td>
                                <td class="px-4 sm:px-5 py-4 text-right font-medium text-primary whitespace-nowrap"><?= formatNumber($log['total_water']) ?> m³</td>
                                <td class="px-4 sm:px-5 py-4 text-right font-medium text-primary whitespace-nowrap"><?= formatNumber($log['total_gas']) ?> kg</td>
                                <td class="px-4 sm:px-5 py-4 whitespace-nowrap"><span class="px-2.5 py-1 rounded-full text-[11px] font-semibold <?= getStatusBadgeClass($log['status']) ?>"><?= getStatusText($log['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="<?= $historyColSpan ?>" class="px-4 sm:px-5 py-12 text-center text-secondary"><i class="fas fa-inbox text-3xl mb-2 block opacity-40"></i><?= T('recent_empty', 'Belum ada data daily log') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============ 6 MODAL GRAFIK RINCIAN ============ -->
<div id="modalOverlay" class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm hidden items-center justify-center p-4 sm:p-6 transition-opacity duration-300" onclick="closeAllModals(event)">
    <!-- LISTRIK MODAL -->
    <div id="modal-electricity" class="modal-card hidden bg-white rounded-premium shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-gradient-to-r from-amber-500 to-amber-600 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-bolt text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_elec_title', 'Rincian Konsumsi Listrik') ?></h2>
                <p class="text-amber-50/90 text-sm"><?= T('modal_elec_sub', 'WBP (Tarif Puncak) & LWBP (Tarif Luar Puncak) Bulan Ini') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalElectricityChart"></canvas></div>
        </div>
    </div>

    <!-- AIR MODAL -->
    <div id="modal-water" class="modal-card hidden bg-white rounded-premium shadow-2xl max-w-5xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-droplet text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_water_title', 'Rincian 9 Sumber Air') ?></h2>
                <p class="text-blue-50/90 text-sm"><?= T('modal_water_sub', 'PDAM, IKI Gaban, DW 1/2 Brr, ASEAN, LPB, Main Bldg, Cooling, Bottling') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalWaterChart"></canvas></div>
        </div>
    </div>

    <!-- GAS MODAL -->
    <div id="modal-gas" class="modal-card hidden bg-white rounded-premium shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-gradient-to-r from-orange-500 to-orange-600 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-fire text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_gas_title', 'Rincian Konsumsi Gas') ?></h2>
                <p class="text-orange-50/90 text-sm"><?= T('modal_gas_sub', 'Gas LPG & Gas LNG Per Hari') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalGasChart"></canvas></div>
        </div>
    </div>

    <!-- SWRO MODAL -->
    <div id="modal-swro" class="modal-card hidden bg-white rounded-premium shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-gradient-to-r from-cyan-500 to-cyan-600 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-water text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_swro_title', 'SWRO System Monitoring') ?></h2>
                <p class="text-cyan-50/90 text-sm"><?= T('modal_swro_sub', 'Watermeter (m³), kWh Konsumsi, TDS Output (ppm)') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalSwroChart"></canvas></div>
        </div>
    </div>

    <!-- BOTTLING MODAL -->
    <div id="modal-bottling" class="modal-card hidden bg-white rounded-premium shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-gradient-to-r from-violet-500 to-violet-600 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-industry text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_bottling_title', 'Bottling Plant Monitoring') ?></h2>
                <p class="text-violet-50/90 text-sm"><?= T('modal_bottling_sub', 'Konsumsi kWh & Watermeter Air Produksi') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalBottlingChart"></canvas></div>
        </div>
    </div>

    <!-- CHILLER MODAL -->
    <div id="modal-chiller" class="modal-card hidden bg-white rounded-premium shadow-2xl max-w-5xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-gradient-to-r from-emerald-500 to-emerald-600 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-snowflake text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_chiller_title', 'Chiller System Performance') ?></h2>
                <p class="text-emerald-50/90 text-sm"><?= T('modal_chiller_sub', 'pH, TDS, Temperature (°C) & Status Unit 1/2/3') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <div class="mb-6 grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php
                $chiller1Cnt = 0; $chiller2Cnt = 0; $chiller3Cnt = 0; $totalChDays = count($chillerDetailData);
                foreach ($chillerDetailData as $d) {
                    if (!empty($d['chiller_1_on'])) $chiller1Cnt++;
                    if (!empty($d['chiller_2_on'])) $chiller2Cnt++;
                    if (!empty($d['chiller_3_on'])) $chiller3Cnt++;
                }
                $chillerOnAvg = $totalChDays > 0 ? round((($chiller1Cnt+$chiller2Cnt+$chiller3Cnt)/($totalChDays*3))*100, 1) : 0;
                ?>
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-100 text-center">
                    <p class="text-xs text-emerald-700 font-semibold mb-1"><?= T('chiller_unit1_on', 'Unit 1 ON') ?></p>
                    <p class="text-2xl font-bold text-emerald-800"><?= $totalChDays > 0 ? round(($chiller1Cnt/$totalChDays)*100, 1) : 0 ?><span class="text-sm">%</span></p>
                </div>
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-100 text-center">
                    <p class="text-xs text-emerald-700 font-semibold mb-1"><?= T('chiller_unit2_on', 'Unit 2 ON') ?></p>
                    <p class="text-2xl font-bold text-emerald-800"><?= $totalChDays > 0 ? round(($chiller2Cnt/$totalChDays)*100, 1) : 0 ?><span class="text-sm">%</span></p>
                </div>
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-100 text-center">
                    <p class="text-xs text-emerald-700 font-semibold mb-1"><?= T('chiller_unit3_on', 'Unit 3 ON') ?></p>
                    <p class="text-2xl font-bold text-emerald-800"><?= $totalChDays > 0 ? round(($chiller3Cnt/$totalChDays)*100, 1) : 0 ?><span class="text-sm">%</span></p>
                </div>
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-100 text-center">
                    <p class="text-xs text-emerald-700 font-semibold mb-1"><?= T('chiller_rata_rata', 'Rata-Rata Aktif') ?></p>
                    <p class="text-2xl font-bold text-emerald-800"><?= $chillerOnAvg ?><span class="text-sm">%</span></p>
                </div>
            </div>
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalChillerChart"></canvas></div>
        </div>
    </div>
</div>
<!-- ============ END 6 MODAL GRAFIK ============ -->

<?php if ($userRole === 'supervisor'): ?>
<script>
function toCumulativeBig(arr) {
    let total = 0;
    return arr.map(v => { total += (parseFloat(v) || 0); return total; });
}

const chartOptions = (yLabel) => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: true, position: 'top', labels: { usePointStyle: true, padding: 15, font: { size: 12 } } },
        tooltip: {
            backgroundColor: '#111',
            padding: 12,
            titleFont: { size: 12, weight: '600' },
            bodyFont: { size: 13 },
            cornerRadius: 10,
            displayColors: true,
        }
    },
    scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#666' } },
        y: { beginAtZero: true, grid: { color: '#f0f0f0' }, ticks: { font: { size: 11 }, color: '#666' } }
    },
    elements: {
        point: { radius: 4, hoverRadius: 6, borderWidth: 2, backgroundColor: '#fff' },
        line: { tension: 0.4, borderWidth: 3 }
    }
});

const electricityChart = new Chart(document.getElementById('electricityChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($electricityData, 'label')) ?>,
        datasets: [{
            label: 'Listrik (kWh)',
            data: toCumulativeBig(<?= json_encode(array_column($electricityData, 'value')) ?>),
            borderColor: '#d97706',
            backgroundColor: 'rgba(217,119,6,0.12)',
            fill: true,
            pointBorderColor: '#d97706',
        }]
    },
    options: chartOptions('kWh')
});

const waterChart = new Chart(document.getElementById('waterChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($waterData, 'label')) ?>,
        datasets: [{
            label: 'Air (m³)',
            data: toCumulativeBig(<?= json_encode(array_column($waterData, 'value')) ?>),
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,0.12)',
            fill: true,
            pointBorderColor: '#2563eb',
        }]
    },
    options: chartOptions('m³')
});

const gasChart = new Chart(document.getElementById('gasChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($gasData, 'label')) ?>,
        datasets: [{
            label: 'Gas (kg)',
            data: toCumulativeBig(<?= json_encode(array_column($gasData, 'value')) ?>),
            backgroundColor: 'rgba(234,88,12,0.85)',
            borderColor: '#ea580c',
            borderWidth: 2,
            borderRadius: 8,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#666' } },
            y: { beginAtZero: true, grid: { color: '#f0f0f0' }, ticks: { font: { size: 11 }, color: '#666' } }
        }
    }
});
</script>
<?php endif; ?>

<!-- ============ JS MODAL GRAFIK (ALL ROLES) ============ -->
<script>
const modalElectricityData = <?= json_encode($electricityDetailData) ?>;
const modalWaterData = <?= json_encode($waterDetailData) ?>;
const modalGasData = <?= json_encode($gasDetailData) ?>;
const modalSwroData = <?= json_encode($swroDetailData) ?>;
const modalBottlingData = <?= json_encode($bottlingDetailData) ?>;
const modalChillerData = <?= json_encode($chillerDetailData) ?>;

let modalChartInstances = {};

const modalChartOpts = (yLabel, showLegend = true) => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: showLegend, position: 'top', align: 'start', labels: { usePointStyle: true, padding: 14, boxWidth: 10, font: { size: 12, weight: '500' } } },
        tooltip: {
            backgroundColor: '#0f172a',
            padding: 14,
            titleFont: { size: 13, weight: '700' },
            bodyFont: { size: 13 },
            cornerRadius: 12,
            borderWidth: 1,
            borderColor: 'rgba(255,255,255,0.1)',
            displayColors: true,
        }
    },
    scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#64748b', maxRotation: 45, minRotation: 0 } },
        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 }, color: '#64748b' } }
    },
    elements: {
        point: { radius: 3.5, hoverRadius: 6, borderWidth: 2, backgroundColor: '#fff' },
        line: { tension: 0.35, borderWidth: 2.5 }
    }
});

function openModal(modalName) {
    const overlay = document.getElementById('modalOverlay');
    const target = document.getElementById('modal-' + modalName);
    if (!target) return;
    document.querySelectorAll('.modal-card').forEach(el => el.classList.add('hidden'));
    overlay.classList.remove('hidden');
    overlay.classList.add('flex');
    target.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    setTimeout(() => renderModalChart(modalName), 50);
}

function closeAllModals(e) {
    if (e && e.target && !e.target.closest('.modal-card') && e.target.id !== 'modalOverlay') return;
    const overlay = document.getElementById('modalOverlay');
    overlay.classList.add('hidden');
    overlay.classList.remove('flex');
    document.querySelectorAll('.modal-card').forEach(el => el.classList.add('hidden'));
    document.body.style.overflow = '';
    Object.values(modalChartInstances).forEach(ch => { try { ch.destroy(); } catch(e){} });
    modalChartInstances = {};
}

function fmtLabels(rawData) {
    return rawData.map(d => {
        const dt = new Date(d.label);
        return dt.getDate().toString().padStart(2,'0') + '/' + (dt.getMonth()+1).toString().padStart(2,'0');
    });
}

function extract(rawData, key) { return rawData.map(d => parseFloat(d[key] ?? 0)); }

function toCumulative(arr) {
    let total = 0;
    return arr.map(v => { total += (parseFloat(v) || 0); return total; });
}

function renderModalChart(name) {
    if (modalChartInstances[name]) return;
    switch(name) {
        case 'electricity': {
            const labels = fmtLabels(modalElectricityData);
            modalChartInstances[name] = new Chart(document.getElementById('modalElectricityChart'), {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        { label: 'WBP (Tarif Puncak)', data: toCumulative(extract(modalElectricityData, 'electricity_wbp')), borderColor: '#d97706', backgroundColor: 'rgba(217,119,6,0.12)', fill: true, pointBorderColor: '#d97706' },
                        { label: 'LWBP (Luar Puncak)', data: toCumulative(extract(modalElectricityData, 'electricity_lwbp')), borderColor: '#92400e', backgroundColor: 'rgba(146,64,14,0.08)', fill: true, pointBorderColor: '#92400e' }
                    ]
                },
                options: modalChartOpts('kWh')
            });
            break;
        }
        case 'water': {
            const labels = fmtLabels(modalWaterData);
            const waterColors = ['#2563eb','#0284c7','#0891b2','#0e7490','#0d9488','#059669','#16a34a','#4f46e5','#7c3aed'];
            modalChartInstances[name] = new Chart(document.getElementById('modalWaterChart'), {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'PDAM', data: toCumulative(extract(modalWaterData, 'water_pdam')), backgroundColor: waterColors[0], borderRadius: 6, borderSkipped: false },
                        { label: 'IKI Gaban', data: toCumulative(extract(modalWaterData, 'water_iki_gaban')), backgroundColor: waterColors[1], borderRadius: 6, borderSkipped: false },
                        { label: 'Deep Well 1', data: toCumulative(extract(modalWaterData, 'water_deepwell_1')), backgroundColor: waterColors[2], borderRadius: 6, borderSkipped: false },
                        { label: 'DW 2 Brr', data: toCumulative(extract(modalWaterData, 'water_deepwell_2_brr')), backgroundColor: waterColors[3], borderRadius: 6, borderSkipped: false },
                        { label: 'DW ASEAN', data: toCumulative(extract(modalWaterData, 'water_deepwell_asean')), backgroundColor: waterColors[4], borderRadius: 6, borderSkipped: false },
                        { label: 'DW LPB', data: toCumulative(extract(modalWaterData, 'water_deepwell_lpb')), backgroundColor: waterColors[5], borderRadius: 6, borderSkipped: false },
                        { label: 'Main Building', data: toCumulative(extract(modalWaterData, 'water_main_building')), backgroundColor: waterColors[6], borderRadius: 6, borderSkipped: false },
                        { label: 'Cooling Tower', data: toCumulative(extract(modalWaterData, 'water_cooling_tower')), backgroundColor: waterColors[7], borderRadius: 6, borderSkipped: false },
                        { label: 'Bottling', data: toCumulative(extract(modalWaterData, 'water_bottling')), backgroundColor: waterColors[8], borderRadius: 6, borderSkipped: false }
                    ]
                },
                options: { ...modalChartOpts('m³'), scales: { x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 }, color: '#64748b', maxRotation: 45 } }, y: { stacked: true, beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 }, color: '#64748b' } } } }
            });
            break;
        }
        case 'gas': {
            const labels = fmtLabels(modalGasData);
            modalChartInstances[name] = new Chart(document.getElementById('modalGasChart'), {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        { label: 'Gas LPG', data: toCumulative(extract(modalGasData, 'gas_lpg')), borderColor: '#ea580c', backgroundColor: 'rgba(234,88,12,0.12)', fill: true, pointBorderColor: '#ea580c' },
                        { label: 'Gas LNG', data: toCumulative(extract(modalGasData, 'gas_lng')), borderColor: '#c2410c', backgroundColor: 'rgba(194,65,12,0.08)', fill: true, pointBorderColor: '#c2410c' }
                    ]
                },
                options: modalChartOpts('kg')
            });
            break;
        }
        case 'swro': {
            const labels = fmtLabels(modalSwroData);
            modalChartInstances[name] = new Chart(document.getElementById('modalSwroChart'), {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        { label: 'Watermeter (m³)', data: toCumulative(extract(modalSwroData, 'swro_watermeter')), borderColor: '#0891b2', backgroundColor: 'rgba(8,145,178,0.12)', fill: true, pointBorderColor: '#0891b2', yAxisID: 'y' },
                        { label: 'Konsumsi kWh', data: toCumulative(extract(modalSwroData, 'swro_kwh')), borderColor: '#0e7490', backgroundColor: 'rgba(14,116,144,0.08)', fill: false, pointBorderColor: '#0e7490', yAxisID: 'y' },
                        { label: 'TDS Output (ppm)', data: extract(modalSwroData, 'swro_tds'), borderColor: '#155e75', backgroundColor: 'transparent', fill: false, pointBorderColor: '#155e75', yAxisID: 'y1', borderDash: [5,5] }
                    ]
                },
                options: { ...modalChartOpts(''),
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#64748b', maxRotation: 45 } },
                        y: { type: 'linear', position: 'left', beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 }, color: '#64748b' }, title: { display: true, text: 'm³ / kWh', font: { size: 11 }, color: '#64748b' } },
                        y1: { type: 'linear', position: 'right', beginAtZero: true, grid: { display: false }, ticks: { font: { size: 11 }, color: '#155e75' }, title: { display: true, text: 'ppm TDS', font: { size: 11 }, color: '#155e75' } }
                    }
                }
            });
            break;
        }
        case 'bottling': {
            const labels = fmtLabels(modalBottlingData);
            modalChartInstances[name] = new Chart(document.getElementById('modalBottlingChart'), {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        { label: 'Watermeter (m³)', data: toCumulative(extract(modalBottlingData, 'bottling_watermeter')), borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,0.12)', fill: true, pointBorderColor: '#7c3aed' },
                        { label: 'Konsumsi kWh', data: toCumulative(extract(modalBottlingData, 'bottling_kwh')), borderColor: '#5b21b6', backgroundColor: 'rgba(91,33,182,0.08)', fill: false, pointBorderColor: '#5b21b6' }
                    ]
                },
                options: modalChartOpts('m³ / kWh')
            });
            break;
        }
        case 'chiller': {
            const labels = fmtLabels(modalChillerData);
            modalChartInstances[name] = new Chart(document.getElementById('modalChillerChart'), {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        { label: 'pH Level', data: extract(modalChillerData, 'chiller_water_ph'), borderColor: '#059669', backgroundColor: 'rgba(5,150,105,0.08)', fill: false, pointBorderColor: '#059669', yAxisID: 'y' },
                        { label: 'TDS (ppm)', data: extract(modalChillerData, 'chiller_water_tds'), borderColor: '#047857', backgroundColor: 'transparent', fill: false, pointBorderColor: '#047857', yAxisID: 'y1', borderDash: [6,4] },
                        { label: 'Temperature (°C)', data: extract(modalChillerData, 'chiller_temp'), borderColor: '#0891b2', backgroundColor: 'rgba(8,145,178,0.10)', fill: true, pointBorderColor: '#0891b2', yAxisID: 'y2' }
                    ]
                },
                options: { ...modalChartOpts(''),
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#64748b', maxRotation: 45 } },
                        y: { type: 'linear', position: 'left', beginAtZero: false, min: 5, max: 9, grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 }, color: '#059669' }, title: { display: true, text: 'pH (5-9)', font: { size: 11 }, color: '#059669' } },
                        y1: { type: 'linear', position: 'right', beginAtZero: true, grid: { display: false }, ticks: { font: { size: 11 }, color: '#047857' }, title: { display: true, text: 'TDS ppm', font: { size: 11 }, color: '#047857' } },
                        y2: { type: 'linear', display: false, beginAtZero: true }
                    }
                }
            });
            break;
        }
    }
}

document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllModals(); });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
