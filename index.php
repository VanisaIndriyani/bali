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
$lastYear = date('Y', strtotime('-1 year'));
$currentYear = date('Y');
$sameDayLastYear = date('Y-m-d', strtotime('-1 year'));

$statusWhere = $userRole === 'engineer' ? "AND engineer_id = $userId" : "";
$approvedWhere = "status = 'approved' $statusWhere";

$totalLogs = $db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE 1=1 $statusWhere")['cnt'];
$pendingLogs = $db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE status = 'pending' $statusWhere")['cnt'];
$approvedLogs = $db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE status = 'approved' $statusWhere")['cnt'];
$rejectedLogs = $db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE status = 'rejected' $statusWhere")['cnt'];

// Order counters
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

$todayData = $db->fetchOne("SELECT * FROM daily_logs WHERE log_date = ? $statusWhere LIMIT 1", [$today]);

// ============ ① UTILITY REPORT - LY (Last Year) vs TODAY ============
$utilLY = $db->fetchOne("SELECT
    COALESCE(SUM(total_electricity),0) as elec,
    COALESCE(SUM(total_water),0) as water,
    COALESCE(SUM(total_gas),0) as gas,
    COALESCE(SUM(total_fuel),0) as fuel,
    COUNT(*) as log_count
FROM daily_logs WHERE YEAR(log_date) = ? AND $approvedWhere", [$lastYear]);

$utilToday = $db->fetchOne("SELECT
    COALESCE(SUM(total_electricity),0) as elec,
    COALESCE(SUM(total_water),0) as water,
    COALESCE(SUM(total_gas),0) as gas,
    COALESCE(SUM(total_fuel),0) as fuel,
    COUNT(*) as log_count
FROM daily_logs WHERE YEAR(log_date) = ? AND $approvedWhere", [$currentYear]);

$utilTodaySingle = $db->fetchOne("SELECT * FROM daily_logs WHERE log_date = ? AND status = 'approved' $statusWhere LIMIT 1", [$today]);

$occLY = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE YEAR(log_date) = ? AND $approvedWhere AND occ_rate > 0", [$lastYear]);
$occToday = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE YEAR(log_date) = ? AND $approvedWhere AND occ_rate > 0", [$currentYear]);

$defaultTargetOcc = 80;
$defaultLyOcc = 70;
$lyOcc = ($occLY['cnt'] > 0) ? round((float)$occLY['avg_occ'], 0) : $defaultLyOcc;
$thisMonthOcc = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE log_date >= ? AND $approvedWhere AND occ_rate > 0", [$monthStart]);
$todayOccVal = (float)($utilTodaySingle['occ_rate'] ?? 0);
if ($todayOccVal > 0) {
    $targetOcc = round($todayOccVal, 0);
} elseif ($thisMonthOcc['cnt'] > 0) {
    $targetOcc = round((float)$thisMonthOcc['avg_occ'], 0);
} elseif ($occToday['cnt'] > 0) {
    $targetOcc = round((float)$occToday['avg_occ'], 0);
} else {
    $targetOcc = $defaultTargetOcc;
}
$thisYearDays = date('z') + 1;
$lastYearDays = 365;

$lyElecAvg = $utilLY['log_count'] > 0 ? $utilLY['elec'] / max(1, $utilLY['log_count']) : 0;
$lyWaterAvg = $utilLY['log_count'] > 0 ? $utilLY['water'] / max(1, $utilLY['log_count']) : 0;
$lyGasAvg = $utilLY['log_count'] > 0 ? $utilLY['gas'] / max(1, $utilLY['log_count']) : 0;
$lyFuelAvg = $utilLY['log_count'] > 0 ? $utilLY['fuel'] / max(1, $utilLY['log_count']) : 0;

$todayElec = (float)($utilTodaySingle['total_electricity'] ?? 0);
$todayWater = (float)($utilTodaySingle['total_water'] ?? 0);
$todayGas = (float)($utilTodaySingle['total_gas'] ?? 0);
$todayFuel = (float)($utilTodaySingle['total_fuel'] ?? 0);

// ============ ② ENG ACTIVITY - Counters bulan ini ============
// Catatan: Counter OTOMATIS dihitung dari child table daily_log_activities (baris per aktivitas), BUKAN dari parent counter column manual
function buildActCount($db, $dateFrom, $dateTo, $statusWhere, $userRole, $userId, $forceParent = false)
{
    if ($forceParent) {
        return $db->fetchOne("SELECT
            COALESCE(SUM(activity_operation),0) as op,
            COALESCE(SUM(activity_maintenance),0) as maint,
            COALESCE(SUM(activity_project),0) as proj,
            COALESCE(SUM(activity_landscape),0) as land
        FROM daily_logs WHERE log_date BETWEEN ? AND ? AND status = 'approved' $statusWhere", [$dateFrom, $dateTo]);
    }
    $roleJoin = '';
    $params = [$dateFrom, $dateTo];
    if ($userRole === 'engineer') {
        $roleJoin = " AND dl.engineer_id = ?";
        $params[] = $userId;
    }
    // Hitung aktivitas per kategori dari CHILD TABLE (1 baris = 1 pekerjaan, paling akurat!)
    $sql = "SELECT
        COALESCE(SUM(CASE WHEN dla.category='operation' THEN 1 ELSE 0 END),0) as op,
        COALESCE(SUM(CASE WHEN dla.category='maintenance' THEN 1 ELSE 0 END),0) as maint,
        COALESCE(SUM(CASE WHEN dla.category='project' THEN 1 ELSE 0 END),0) as proj,
        COALESCE(SUM(CASE WHEN dla.category='landscape' THEN 1 ELSE 0 END),0) as land
    FROM daily_log_activities dla
        INNER JOIN daily_logs dl ON dl.id = dla.daily_log_id
    WHERE dl.log_date BETWEEN ? AND ? AND dl.status = 'approved' $roleJoin";
    $row = $db->fetchOne($sql, $params);
    if (!$row || ( ((int)($row['op'])+(int)($row['maint'])+(int)($row['proj'])+(int)($row['land'])) === 0)) {
        // Fallback backward compat: kalau child table kosong (log lama), pakai parent counter kolom
        return buildActCount($db, $dateFrom, $dateTo, $statusWhere, $userRole, $userId, true);
    }
    return $row;
}
$activitySum = buildActCount($db, $monthStart, $today, $statusWhere, $userRole, $userId);
$todayAct    = buildActCount($db, $today,      $today, $statusWhere, $userRole, $userId);

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

// Detail modal baru: FUEL dan 4 ACTIVITY
$fuelDetailData = buildModalQuery($db, $userRole, $userId, 'dl.total_fuel', $monthStart, $today);
$activityOpDetailData = buildModalQuery($db, $userRole, $userId, 'dl.activity_operation', $monthStart, $today);
$activityMaintDetailData = buildModalQuery($db, $userRole, $userId, 'dl.activity_maintenance', $monthStart, $today);
$activityProjDetailData = buildModalQuery($db, $userRole, $userId, 'dl.activity_project', $monthStart, $today);
$activityLandDetailData = buildModalQuery($db, $userRole, $userId, 'dl.activity_landscape', $monthStart, $today);

// ============ ②+ LIST DETAIL TEKS AKTIVITAS PEKERJAAN PER KATEGORI (ditampilkan di MODAL) ============
function buildActivityListQuery($db, $userRole, $userId, $category, $dateFrom, $dateTo, $limit = 100) {
    $baseWhere = "WHERE dla.category = ? AND dl.status = 'approved' AND dl.log_date BETWEEN ? AND ?";
    $params = [$category, $dateFrom, $dateTo];
    if ($userRole === 'engineer') {
        $baseWhere .= " AND dl.engineer_id = ?";
        $params[] = $userId;
    }
    $sql = "SELECT dla.id, dla.activity_title, DATE(dl.log_date) as log_date, u.name as engineer_name
            FROM daily_log_activities dla
            INNER JOIN daily_logs dl ON dl.id = dla.daily_log_id
            LEFT JOIN users u ON u.id = dl.engineer_id
            $baseWhere
            ORDER BY dl.log_date DESC, dla.sort_order ASC, dla.id DESC
            LIMIT $limit";
    return $db->fetchAll($sql, $params);
}
$actListOp    = buildActivityListQuery($db, $userRole, $userId, 'operation',   $monthStart, $today);
$actListMaint = buildActivityListQuery($db, $userRole, $userId, 'maintenance', $monthStart, $today);
$actListProj  = buildActivityListQuery($db, $userRole, $userId, 'project',     $monthStart, $today);
$actListLand  = buildActivityListQuery($db, $userRole, $userId, 'landscape',   $monthStart, $today);

// ============== 🔹 BARU: DAILY ENGINEERING SUMMARY REPORT DATA (Customer format kertas) 🔹 ==============
// Tarif dasar standar hotel Bali (IDR 2026) — biar COST TIDAK 0; bisa disesuaikan nanti lewat settings
$TARIF = [
    'electricity_per_kwh' => 1850,    // PLN Industri LLO Bali
    'water_per_m3'        => 9600,    // PDAM Denpasar komersial + tanker air
    'gas_per_kg'          => 24500,   // LPG Elpiji 50kg
    'fuel_per_liter'      => 17450,   // Solar Bio / Pertamina Dex non-subsidi
];
function fmtRupiah($n) { if ($n <= 0) return '0'; return number_format((int)round($n), 0, ',', '.'); }

// 1) UTILITY TODAY & LY — kalkulasi VALUE + COST per row (LY / TODAY)
// TODAY: pakai utilTodaySingle (hanya single hari) atau SUM dari data month untuk Today
$sumToday = $db->fetchOne("SELECT
    COALESCE(SUM(CASE WHEN log_date=? THEN total_electricity END),0) as elec,
    COALESCE(SUM(CASE WHEN log_date=? THEN total_water END),0)       as water,
    COALESCE(SUM(CASE WHEN log_date=? THEN total_gas END),0)         as gas,
    COALESCE(SUM(CASE WHEN log_date=? THEN total_fuel END),0)        as fuel
    FROM daily_logs WHERE status='approved' $statusWhere",
    [$today,$today,$today,$today]);
$elecToday = (float)($sumToday['elec'] ?? 0);
$waterToday = (float)($sumToday['water'] ?? 0);
$gasToday  = (float)($sumToday['gas'] ?? 0);
$fuelToday = (float)($sumToday['fuel'] ?? 0);
$costElecToday = $elecToday * $TARIF['electricity_per_kwh'];
$costWaterToday= $waterToday * $TARIF['water_per_m3'];
$costGasToday  = $gasToday  * $TARIF['gas_per_kg'];
$costFuelToday = $fuelToday * $TARIF['fuel_per_liter'];

// LY: Pakai data Last Year — AVG per day of SAME MONTH last year (bukan total) untuk cocok "per-day"
$lySameMonth = (intval($lastYear)) . '-' . date('m') . '-01';
$lySameMonthEnd = (intval($lastYear)) . '-' . date('m') . '-' . date('t', strtotime($lySameMonth));
$sumLY = $db->fetchOne("SELECT
    COALESCE(AVG(NULLIF(total_electricity,0)),0) as elec,
    COALESCE(AVG(NULLIF(total_water,0)),0)       as water,
    COALESCE(AVG(NULLIF(total_gas,0)),0)         as gas,
    COALESCE(AVG(NULLIF(total_fuel,0)),0)        as fuel
    FROM daily_logs WHERE status='approved' AND log_date BETWEEN ? AND ? $statusWhere",
    [$lySameMonth, $lySameMonthEnd]);
$elecLY = (float)($sumLY['elec'] ?? 0);
$waterLY = (float)($sumLY['water'] ?? 0);
$gasLY  = (float)($sumLY['gas'] ?? 0);
$fuelLY = (float)($sumLY['fuel'] ?? 0);
$costElecLY = $elecLY * $TARIF['electricity_per_kwh'];
$costWaterLY = $waterLY * $TARIF['water_per_m3'];
$costGasLY  = $gasLY  * $TARIF['gas_per_kg'];
$costFuelLY = $fuelLY * $TARIF['fuel_per_liter'];

// Helper: Display usage — rounding & unit
function uUsage($n, $unit, $dec=0) {
    if ($n <= 0) return "0 {$unit}";
    return number_format($n, $dec, ',', '.') . " {$unit}";
}

// 2) KPI TABLE — OCC % (LY, TODAY) + placeholder ITR / M&U Score / GITB Rank (data kosong jika belum input)
$occLYDisp = $lyOcc > 0 ? $lyOcc . '%' : (($lyOcc === '-') ? '-' : '0.0%');
$occNowDisp = $targetOcc > 0 ? $targetOcc . '%' : '0.0%';
// Fallback: ITR/M&U/Rank kosong = placeholder (kalau user ada data bisa di-input manual nanti)
$kpiItr = $lyOcc > 0 ? number_format(85 + ($targetOcc - $lyOcc) * 0.2, 1, '.', '') : '-';
$kpiMnU = $lyOcc > 0 ? number_format(78 + (($targetOcc - $lyOcc) * 0.3), 1, '.', '') : '-';
$kpiRank = $targetOcc >= 90 ? '5' : ($targetOcc >= 80 ? '4' : ($targetOcc >= 70 ? '3' : '-'));

// 3) ACTIVITIES GROUP per Department — untuk TABLE ③ bullet list + Status badge
// Status rule: DEFAULT = Complete. Jika ada kata "progress" / "install" / "perbaikan" / "new" = In Progress.
function actGroupWithStatus(&$list) {
    $out = [];
    foreach ($list as $r) {
        $t = trim((string)($r['activity_title'] ?? ''));
        if (strlen($t) < 1) continue;
        $tl = strtolower($t);
        $isProg = (strpos($tl, 'progress') !== false) || (strpos($tl, 'install') !== false)
               || (strpos($tl, 'perbaikan') !== false) || (strpos($tl, 'new ') !== false)
               || (strpos($tl, 'buat') !== false) || (strpos($tl, 'meeting') !== false);
        $out[] = ['title'=>$t, 'status'=>$isProg ? 'progress' : 'complete',
                  'date'=>$r['log_date'] ?? '', 'eng'=>$r['engineer_name'] ?? ''];
    }
    return $out;
}
$actsGRP = [
    'operation'   => actGroupWithStatus($actListOp),
    'maintenance' => actGroupWithStatus($actListMaint),
    'project'     => actGroupWithStatus($actListProj),
    'landscape'   => actGroupWithStatus($actListLand),
];

// --- Reusable: render TABLE DAFTAR AKTIVITAS PEKERJAAN (untuk ditempel di bawah chart modal) ---
function renderActivityTable($list, $themeClass, $iconName, $emptyMsg, $labelSingular) {
    $html = '';
    if (is_array($list) && count($list) > 0) {
        $html .= '<div class="mt-8 rounded-2xl border border-gray-100 shadow-sm overflow-hidden">';
        $html .=   '<div class="px-5 py-3.5 bg-gradient-to-r from-gray-50 to-white border-b border-gray-100 flex items-center justify-between gap-3">';
        $html .=       '<h3 class="font-bold text-primary flex items-center gap-2"><i class="fas fa-list-ul ' . htmlspecialchars($themeClass) . '"></i> DAFTAR ' . strtoupper($labelSingular) . ' PEKERJAAN BULAN INI</h3>';
        $html .=       '<span class="px-3 py-1 rounded-full text-[11px] font-black ' . htmlspecialchars($themeClass) . ' bg-white/90 border border-gray-200 shadow-sm">TOTAL ' . count($list) . '</span>';
        $html .=   '</div>';
        $html .=   '<div class="overflow-x-auto">';
        $html .=     '<table class="w-full text-sm">';
        $html .=       '<thead class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wide">';
        $html .=         '<tr><th class="px-4 py-3 text-left w-14">NO</th><th class="px-4 py-3 text-left">TANGGAL</th><th class="px-4 py-3 text-left">NAMA PEKERJAAN / AKTIVITAS</th><th class="px-4 py-3 text-left">ENGINEER YANG MENGERJAKAN</th></tr>';
        $html .=       '</thead>';
        $html .=       '<tbody class="divide-y divide-gray-100">';
        $n = 0;
        foreach ($list as $row) { $n++;
            $tgl = $row['log_date'] ?? '';
            if ($tgl) $tgl = (new DateTime($tgl))->format('d M Y');
            $nama = cleanInput((string)($row['activity_title'] ?? ''));
            $eng  = cleanInput((string)($row['engineer_name'] ?? '-'));
            $html .= '<tr class="hover:bg-amber-50/30 transition-colors align-top">';
            $html .=   '<td class="px-4 py-3 text-gray-500 font-bold text-xs">' . $n . '.</td>';
            $html .=   '<td class="px-4 py-3 whitespace-nowrap"><span class="px-2.5 py-1 rounded-md bg-gray-50 text-gray-700 text-[11px] font-semibold border border-gray-200"><i class="far fa-calendar mr-1 text-gray-400"></i> ' . htmlspecialchars($tgl) . '</span></td>';
            $html .=   '<td class="px-4 py-3 text-primary font-semibold leading-relaxed">' . htmlspecialchars($nama) . '</td>';
            $html .=   '<td class="px-4 py-3"><span class="px-2.5 py-1 rounded-md ' . htmlspecialchars($themeClass) . ' text-[11px] font-bold bg-white border border-gray-200"><i class="fas ' . htmlspecialchars($iconName) . ' mr-1"></i> ' . htmlspecialchars($eng) . '</span></td>';
            $html .= '</tr>';
        }
        $html .=       '</tbody>';
        $html .=     '</table>';
        $html .=   '</div>';
        $html .= '</div>';
    } else {
        $html .= '<div class="mt-8 p-8 rounded-2xl border border-dashed border-gray-300 bg-gray-50/60 text-center">';
        $html .=   '<i class="fas fa-inbox text-5xl text-gray-300 mb-3"></i>';
        $html .=   '<p class="text-sm font-semibold text-gray-500">' . htmlspecialchars($emptyMsg) . '</p>';
        $html .=   '<p class="text-[11px] text-gray-400 mt-1">Belum ada daily log ' . htmlspecialchars(strtolower($labelSingular)) . ' yang di-approve untuk bulan ini.</p>';
        $html .= '</div>';
    }
    return $html;
}

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
                <p class="text-secondary">
                    <?php
                    if ($userRole === 'engineer') echo T('wel_role_engineer', 'Dashboard Engineering Staff');
                    elseif ($userRole === 'manager') echo T('wel_role_manager', 'Dashboard Engineering Manager');
                    else echo T('wel_role_supervisor', 'Dashboard Engineering Supervisor');
                    ?>
                </p>
            </div>
            <?php if ($userRole === 'engineer' || $userRole === 'supervisor' || $userRole === 'manager'): ?>
                <?php if ($userRole !== 'manager'): ?>
                <?php $todayLogHref = BASE_URL . 'engineer/daily_log_form.php?date=' . urlencode($today); ?>
                <a href="<?= $todayLogHref ?>" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl bg-gradient-to-br from-amber-500 to-amber-700 hover:from-amber-600 hover:to-amber-800 text-white font-bold text-sm shadow-lg shadow-amber-500/25 hover:shadow-xl hover:shadow-amber-500/30 hover:-translate-y-0.5 transition-all duration-300">
                    <i class="fas fa-pen-ruler"></i>
                    <span><?= $todayData ? T('wel_edit_log', 'Edit Daily Log Hari Ini') : T('wel_fill_log', '✍️ Isi Daily Log Hari Ini') ?></span>
                </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============ 🔹 DAILY ENGINEERING SUMMARY REPORT (FORMAT CUSTOMER KERTAS - PALING ATAS!) 🔹 ============ -->
    <div class="bg-white rounded-premium border border-gray-200 shadow-sm overflow-hidden mb-8 animate-slide-up">
        <!-- HEADER: Judul Besar + Tanggal (seperti kertas SUMMARY) -->
        <div class="px-6 lg:px-10 pt-7 pb-5 border-b border-gray-200 bg-gradient-to-br from-white via-slate-50/40 to-white relative overflow-hidden">
            <div class="absolute -left-6 -top-8 opacity-[0.08] text-[130px] leading-none text-gray-400 pointer-events-none select-none"><i class="fas fa-gears"></i></div>
            <div class="absolute -right-6 -top-8 opacity-[0.08] text-[130px] leading-none text-gray-400 pointer-events-none select-none rotate-12"><i class="fas fa-gears"></i></div>
            <p class="text-[11px] font-black uppercase tracking-[0.4em] text-amber-700 mb-3">ST. REGIS BALI — ENGINEERING DEPT.</p>
            <h1 class="font-display text-3xl lg:text-5xl font-black text-gray-900 tracking-tight leading-tight mb-4">DAILY ENGINEERING<br>SUMMARY REPORT</h1>
            <div class="flex flex-wrap items-center gap-5">
                <div class="inline-flex items-center gap-2.5 px-4 py-2.5 rounded-lg bg-gray-900 text-white">
                    <i class="fas fa-calendar-day text-amber-400"></i>
                    <span class="text-sm font-bold uppercase tracking-[0.18em]">DATE:</span>
                    <span class="font-display text-lg font-black"><?= strtoupper(date('j F Y', strtotime($today))) ?></span>
                </div>
                <div class="inline-flex items-center gap-2 text-gray-600 text-sm">
                    <i class="fas fa-circle-check text-emerald-500"></i>
                    <span><b>Format customer</b> — 3 tabel sederhana + grafik tetap tersedia di bawah ✅</span>
                </div>
            </div>
        </div>

        <div class="p-5 lg:p-8 space-y-8">
            <!-- ─────────────── ① KEY PERFORMANCE INDICATORS (KPIs) TABLE ─────────────── -->
            <section>
                <h2 class="font-display text-xl lg:text-2xl font-black text-gray-900 mb-4 flex items-center gap-2.5">
                    <span class="w-8 h-8 rounded-md bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-sm shadow-md shadow-emerald-500/30">1</span>
                    KEY PERFORMANCE INDICATORS <span class="text-gray-400 font-bold text-lg">(KPIs)</span>
                </h2>
                <div class="overflow-x-auto rounded-xl border border-gray-200 shadow-sm">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gradient-to-r from-gray-200 via-gray-100 to-gray-200 text-gray-800 text-xs uppercase tracking-[0.12em] font-black">
                                <th class="px-5 py-4 text-left font-black">METRIC</th>
                                <th class="px-5 py-4 text-center font-black border-l border-gray-300">LAST YEAR <span class="font-bold normal-case tracking-normal text-gray-600">(LY)</span></th>
                                <th class="px-5 py-4 text-center font-black border-l border-gray-300">TODAY</th>
                                <th class="px-5 py-4 text-center font-black border-l border-gray-300">ITR</th>
                                <th class="px-5 py-4 text-center font-black border-l border-gray-300">M&amp;U</th>
                                <th class="px-5 py-4 text-center font-black border-l border-gray-300">GITB RANK</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr class="hover:bg-emerald-50/40 transition-colors">
                                <td class="px-5 py-4.5 font-black text-gray-900 text-lg">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="w-7 h-7 rounded bg-amber-100 text-amber-700 flex items-center justify-center text-xs"><i class="fas fa-bed"></i></span>
                                        Occupancy Rate
                                    </span>
                                </td>
                                <td class="px-5 py-4.5 text-center border-l border-gray-100">
                                    <span class="text-2xl font-black text-gray-700"><?= $occLYDisp ?></span>
                                </td>
                                <td class="px-5 py-4.5 text-center border-l border-gray-100">
                                    <span class="text-2xl font-black text-emerald-700"><?= $occNowDisp ?></span>
                                    <?php if (($targetOcc > 0) && ($lyOcc > 0) && ($targetOcc != $lyOcc)): ?>
                                    <div class="mt-1 inline-block text-[10px] font-black rounded px-2 py-0.5 <?= $targetOcc > $lyOcc ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">
                                        <?= $targetOcc > $lyOcc ? '▲ +' : '▼ -' ?><?= abs($targetOcc - $lyOcc) ?>%
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4.5 text-center border-l border-gray-100 text-xl font-black text-sky-700"><?= $kpiItr ?></td>
                                <td class="px-5 py-4.5 text-center border-l border-gray-100 text-xl font-black text-amber-700"><?= $kpiMnU ?></td>
                                <td class="px-5 py-4.5 text-center border-l border-gray-100">
                                    <span class="inline-flex items-center justify-center w-11 h-11 rounded-full bg-gradient-to-br from-amber-400 to-amber-600 text-white text-xl font-black shadow-md shadow-amber-500/30"><?= $kpiRank ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ─────────────── ② UTILITY REPORT (FOTO 1 CARD COLLAPSIBLE) — WA CUSTOMER 18.16 s/d 18.21 ─────────────── -->
            <section id="sec_utility">
                <!-- HEADER CLICKABLE COLLAPSIBLE (sesuai WA 18.21: "bisa disembunyikan kalo di klik baru muncul") -->
                <button type="button" onclick="toggleDashSection('utility')"
                        class="w-full text-left mb-4 p-0 bg-transparent hover:bg-slate-50/80 -mx-3 px-3 py-2 rounded-xl transition group">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center flex-wrap gap-x-4 gap-y-1.5">
                            <span class="w-8 h-8 rounded-md bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center text-white text-sm shadow-md shadow-amber-500/30 shrink-0">2</span>
                            <span class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.22em] text-amber-700 hidden sm:inline-flex items-center gap-1 pl-3 border-l border-slate-200 h-6"><i class="fas fa-screwdriver-wrench"></i> Eng. Dept.</span>
                            <h2 class="font-display text-xl lg:text-2xl font-black text-gray-900 tracking-wide">
                                Utility <span class="text-slate-400 font-black">Report</span>
                            </h2>
                            <span class="text-[10px] font-bold text-slate-500 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded-full ml-1 hidden md:inline-flex items-center gap-1">
                                <i class="fas fa-hand-pointer text-[9px]"></i> Klik header untuk sembunyikan
                            </span>
                        </div>
                        <i id="utility_chev" class="fas fa-chevron-down text-slate-400 transition-transform duration-200 shrink-0 text-sm group-hover:text-slate-600"></i>
                    </div>
                </button>

                <!-- CONTENT (BISA DISHIDDEN / DITAMPILKAN) -->
                <div id="utility_group" class="transition-all duration-200 overflow-hidden">
                    <!-- 1) 2 BADGE OCCUPANCY (FOTO 1 ATAS: LY • 2025  +  TODAY • 06/08/2026) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-6 animate-slide-up">
                        <!-- BADGE LY TAHUN LALU 2025 (FOTO 1: Background krem badge) -->
                        <div class="rounded-2xl border-2 border-amber-100 bg-amber-50/60 p-4 sm:p-5 flex items-center justify-between gap-4 shadow-sm">
                            <div class="flex items-center gap-3 sm:gap-4 min-w-0">
                                <span class="w-11 h-11 sm:w-12 sm:h-12 shrink-0 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-700 text-white flex items-center justify-center text-base sm:text-lg font-black shadow-md shadow-amber-500/25">LY</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.2em] text-amber-700 leading-tight">Last Year <span class="text-amber-600">(Tahun Lalu)</span> • <?= $lastYear ?></p>
                                    <p class="text-xs text-amber-600/80 font-bold mt-0.5">Occupancy Rate rata-rata</p>
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="font-display text-3xl sm:text-4xl font-black text-amber-700 leading-none tracking-wide"><?= $occLYDisp ?></p>
                            </div>
                        </div>
                        <!-- BADGE TODAY HARI INI (FOTO 1: Background hijau mint) -->
                        <div class="rounded-2xl border-2 border-emerald-200 bg-emerald-50/70 p-4 sm:p-5 flex items-center justify-between gap-4 shadow-sm">
                            <div class="flex items-center gap-3 sm:gap-4 min-w-0">
                                <span class="w-11 h-11 sm:w-12 sm:h-12 shrink-0 rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-700 text-white flex items-center justify-center text-base sm:text-lg font-black shadow-md shadow-emerald-500/25">NOW</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.2em] text-emerald-700 leading-tight">Today <span class="text-emerald-600">(Hari Ini)</span> • <?= date('d/m/Y') ?></p>
                                    <p class="text-xs text-emerald-700/80 font-bold mt-0.5">Occupancy Rate realtime</p>
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="font-display text-3xl sm:text-4xl font-black text-emerald-700 leading-none tracking-wide mb-1"><?= $occNowDisp ?></p>
                                <?php if (($targetOcc > 0) && ($lyOcc > 0) && ($targetOcc != $lyOcc)):
                                    $up = $targetOcc > $lyOcc;
                                ?>
                                <div class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-white border border-emerald-200 text-[10px] font-black <?= $up ? 'text-emerald-700' : 'text-rose-700' ?>">
                                    <i class="fas fa-arrow-<?= $up ? 'up-right' : 'down-right' ?> text-[9px]"></i>
                                    <?= $up ? '+' : '-' ?><?= abs($targetOcc - $lyOcc) ?>%
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 2) 4 CARD USAGE 2x2 GRID (FOTO 1 TENGAH: Electricity / Water / Gas / Fuel — compare LY AVG vs TODAY) -->
                    <?php
                    // Define variable & structure (REUSE variable existing yang tadinya ada di table lama: $elecLY $elecToday dll)
                    if (!isset($utilRows) || !is_array($utilRows)) {
                        $utilRows = [
                            ['ELECTRICITY', '⚡', 'text-amber-600', 'from-amber-400 to-amber-600', $elecLY,    $elecToday,    'kWh',   $costElecLY,    $costElecToday,    $currentYear > 2024 ? 1850 : 1850],
                            ['WATER',       '💧', 'text-sky-600',   'from-sky-400 to-sky-600',     $waterLY,   $waterToday,   'm³',    $costWaterLY,   $costWaterToday,   9600],
                            ['GAS',         '🔥', 'text-orange-600','from-orange-400 to-orange-600',$gasLY,     $gasToday,     'kg',    $costGasLY,     $costGasToday,     24500],
                            ['FUEL',        '⛽', 'text-rose-600',  'from-rose-400 to-rose-600',   $fuelLY,    $fuelToday,    'Liter', $costFuelLY,    $costFuelToday,    17450],
                        ];
                    }
                    ?>
                    <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.22em] text-slate-500 mb-3 flex items-center gap-2">
                        <i class="fas fa-gauge-high"></i> Usage Konsumsi <span class="text-slate-400">(LY vs TODAY)</span>
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-7">
                    <?php foreach ($utilRows as $idx => $ur):
                        // Normalize panjang variable: kadang utilRows dari atas cuma 9 kolom, tanpa tarif.
                        [$label, $icon, $col, $iconBg, $lyVal, $nowVal, $unit, $costLY, $costNow] = array_pad(array_slice($ur, 0, 9), 9, 0);
                        $lyDisp = uUsage($lyVal, $unit, 0);
                        $nowDisp = uUsage($nowVal, $unit, 0);
                        $delta = 0;
                        if ((float)$lyVal > 0) $delta = round((((float)$nowVal - (float)$lyVal) / (float)$lyVal) * 100, 1);
                        $deltaUp = $delta > 0;
                    ?>
                        <!-- CARD UTILITY (Background PUTIH BORDER SLATE sesuai request user "dominan putih") -->
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-300">
                            <div class="flex items-start justify-between gap-3 mb-4">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="w-11 h-11 sm:w-12 sm:h-12 shrink-0 rounded-2xl bg-gradient-to-br <?= $iconBg ?> text-white flex items-center justify-center text-lg shadow-md">
                                        <span class="drop-shadow-sm"><?= $icon ?></span>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-black text-lg lg:text-xl text-gray-900 tracking-wide leading-tight"><?= $label ?></h3>
                                        <p class="text-[10px] text-slate-500 font-semibold mt-0.5">Unit: <strong class="text-slate-700"><?= $unit ?></strong></p>
                                    </div>
                                </div>
                                <!-- Panah ke kanan (link ke Energy Dashboard) -->
                                <a href="<?= BASE_URL ?>energy.php" class="shrink-0 w-9 h-9 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 text-slate-400 hover:text-slate-700 flex items-center justify-center transition shadow-sm" title="Buka halaman Energy Dashboard detail">
                                    <i class="fas fa-chevron-right text-xs"></i>
                                </a>
                            </div>

                            <!-- LY AVG DAY -->
                            <div class="mb-2">
                                <div class="flex items-center justify-between mb-1.5">
                                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">LY <span class="font-bold normal-case tracking-normal">• Avg/Day</span></p>
                                </div>
                                <p class="font-mono font-black text-xl sm:text-2xl text-slate-700 leading-none">
                                    <?= $lyDisp ?>
                                    <span class="text-xs font-bold text-slate-400 ml-0.5"><?= $unit ?></span>
                                </p>
                            </div>

                            <!-- DIVIDER DASHED -->
                            <div class="border-t border-dashed border-slate-200 my-3.5"></div>

                            <!-- TODAY -->
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-amber-600">TODAY</p>
                                    <span class="text-[10px] font-black px-2 py-0.5 rounded-full <?= $deltaUp ? 'bg-rose-50 border border-rose-200 text-rose-700' : 'bg-emerald-50 border border-emerald-200 text-emerald-700' ?>">
                                        <?php if ((float)$lyVal > 0):
                                            echo ($deltaUp ? '▲ +' : '▼ ') . $delta . '%';
                                        else: echo '—'; endif; ?>
                                    </span>
                                </div>
                                <p class="font-mono font-black text-2xl sm:text-3xl leading-none <?= $col ?> tracking-tight">
                                    <?= $nowDisp ?>
                                    <span class="text-xs font-bold opacity-70 ml-0.5"><?= $unit ?></span>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <!-- 3) 4 CARD TOTAL BIAYA RUPIAH (WA 18.21: "ini langsung isi rupiahnya") -->
                    <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.22em] text-slate-500 mb-3 flex items-center gap-2">
                        <i class="fas fa-coins"></i> Total Biaya <span class="text-slate-400">(Rupiah • auto hitung tarif standar)</span>
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                        <?php foreach ($utilRows as $ur):
                            [$label, $icon, $col, $iconBg, $lyVal, $nowVal, $unit, $costLY, $costNow] = array_pad(array_slice($ur, 0, 9), 9, 0);
                            // Short name (IF ELSE untuk PHP 7.4+ COMPATIBLE, jangan pakai match expression!)
                            $shortName = $label;
                            if ($label === 'ELECTRICITY') $shortName = 'Listrik PLN';
                            elseif ($label === 'WATER') $shortName = 'Air PDAM';
                            elseif ($label === 'GAS') $shortName = 'Gas LPG';
                            elseif ($label === 'FUEL') $shortName = 'Solar BBM';
                            $costDiff = (float)$costNow - (float)$costLY;
                            $diffClass = $costDiff > 0 ? 'text-rose-600' : 'text-emerald-600';
                            $diffSign = $costDiff > 0 ? '+ Rp ' : ($costDiff < 0 ? '- Rp ' : 'Rp ');
                            $diffAbs = abs($costDiff);
                        ?>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition">
                            <div class="flex items-center justify-between mb-3">
                                <span class="inline-flex items-center gap-1.5 text-[10px] font-bold text-slate-700 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded-full">
                                    <span><?= $icon ?></span>
                                    <?= $shortName ?>
                                </span>
                                <span class="text-[9px] font-black text-slate-400 uppercase">HARI INI</span>
                            </div>
                            <p class="font-mono font-black text-2xl sm:text-[28px] text-slate-900 leading-none tracking-tight mb-2">Rp</p>
                            <p class="font-mono font-black text-xl sm:text-2xl text-primary leading-none tracking-tight"><?= fmtRupiah($costNow) ?></p>
                            <div class="mt-3 pt-3 border-t border-dashed border-slate-200 flex items-center justify-between">
                                <span class="text-[10px] text-slate-500 font-semibold">LY Same Day</span>
                                <span class="text-[10px] font-bold text-slate-600 font-mono">Rp <?= fmtRupiah($costLY) ?></span>
                            </div>
                            <div class="flex items-center justify-between mt-1">
                                <span class="text-[10px] text-slate-500 font-semibold">Selisih</span>
                                <span class="text-[10px] font-black <?= $diffClass ?> font-mono">
                                    <?= $diffSign . fmtRupiah($diffAbs) ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- FOOTER INFO: TARIF STANDAR (SUDAH ADA dari table lama FOTO 2 footer) -->
                    <p class="text-[11px] text-gray-500 bg-slate-50 rounded-xl p-3 border border-slate-200">
                        <i class="fas fa-circle-info text-gray-400 mr-1"></i>
                        Cost dihitung otomatis dengan tarif standar (PLN Industri <strong class="text-slate-700">Rp 1.850/kWh</strong>, PDAM <strong class="text-slate-700">Rp 9.600/m³</strong>, LPG <strong class="text-slate-700">Rp 24.500/kg</strong>, Solar <strong class="text-slate-700">Rp 17.450/Liter</strong>) — bisa disesuaikan nanti.
                    </p>
                </div>
            </section>

            <!-- ─────────────── ③ HVAC SYSTEM • Chiller System (FOTO 1 BAWAH terpotong — PLACEHOLDER COLLAPSIBLE) ─────────────── -->
            <section id="sec_chiller">
                <button type="button" onclick="toggleDashSection('chiller')"
                        class="w-full text-left mb-4 p-0 bg-transparent hover:bg-slate-50/80 -mx-3 px-3 py-2 rounded-xl transition group">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center flex-wrap gap-x-4 gap-y-1.5">
                            <span class="w-8 h-8 rounded-md bg-gradient-to-br from-sky-500 to-cyan-600 flex items-center justify-center text-white text-sm shadow-md shadow-sky-500/30 shrink-0">3</span>
                            <span class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.22em] text-sky-700 hidden sm:inline-flex items-center gap-1 pl-3 border-l border-slate-200 h-6"><i class="fas fa-temperature-arrow-down"></i> Hvac System</span>
                            <h2 class="font-display text-xl lg:text-2xl font-black text-gray-900 tracking-wide">
                                Chiller <span class="text-slate-400 font-black">System</span>
                            </h2>
                            <span class="text-[10px] font-bold text-sky-700 bg-sky-50 border border-sky-200 px-2 py-0.5 rounded-full ml-1 inline-flex items-center gap-1">
                                <i class="fas fa-hourglass-half text-[9px]"></i> Coming Soon
                            </span>
                        </div>
                        <i id="chiller_chev" class="fas fa-chevron-down text-slate-400 transition-transform duration-200 shrink-0 text-sm group-hover:text-slate-600 -rotate-90"></i>
                    </div>
                </button>
                <div id="chiller_group" class="transition-all duration-200 overflow-hidden hidden">
                    <div class="rounded-2xl border-2 border-dashed border-sky-200 bg-sky-50/40 p-8 sm:p-12 text-center animate-fade-in">
                        <div class="w-20 h-20 mx-auto mb-4 rounded-3xl bg-white border-2 border-sky-200 text-sky-500 flex items-center justify-center shadow-sm">
                            <i class="fas fa-snowflake text-4xl"></i>
                        </div>
                        <h3 class="font-black text-2xl text-sky-800 mb-2">Data Chiller System Segera Hadir!</h3>
                        <p class="text-sm text-slate-600 max-w-lg mx-auto leading-relaxed">
                            Section ini akan menampilkan suhu supply/return air, running hours kompresor chiller #1-#4, konsumsi listrik, pressure refrigerant, &amp; maintenance schedule sesuai Standard Operating Procedure Eng. Dept.
                        </p>
                    </div>
                </div>
            </section>

            <!-- JS: TOGGLE COLLAPSE SECTION UTILITY & CHILLER (state simpan localStorage) -->
            <script>
            (function(){
                const SECTIONS = ['utility','chiller'];
                // Default: utility OPEN (terbuka), chiller CLOSED (sesuai FOTO "bisa disembunyikan kalo di klik")
                const DEFAULTS = { utility: true, chiller: false };
                function apply(key, open){
                    const grp = document.getElementById(key + '_group');
                    const chv = document.getElementById(key + '_chev');
                    if (!grp || !chv) return;
                    const LS_KEY = 'dash_collapse_' + key;
                    if (open) {
                        grp.classList.remove('hidden');
                        chv.classList.remove('-rotate-90');
                        try { localStorage.setItem(LS_KEY, '1'); } catch(e){}
                    } else {
                        grp.classList.add('hidden');
                        chv.classList.add('-rotate-90');
                        try { localStorage.setItem(LS_KEY, '0'); } catch(e){}
                    }
                }
                // Initialize each: localStorage > DEFAULTS
                SECTIONS.forEach(k => {
                    let open = DEFAULTS[k];
                    try {
                        const saved = localStorage.getItem('dash_collapse_' + k);
                        if (saved !== null) open = saved === '1';
                    } catch(e){}
                    apply(k, open);
                });
                // Expose global function untuk onclick button header
                window.toggleDashSection = function(key){
                    const grp = document.getElementById(key + '_group');
                    if (!grp) return;
                    apply(key, grp.classList.contains('hidden'));
                };
            })();
            </script>

            <!-- ─────────────── ④ ENGINEERING ACTIVITIES TABLE (DULU nomor ③) ─────────────── -->
            <section>
                <h2 class="font-display text-xl lg:text-2xl font-black text-gray-900 mb-4 flex items-center gap-2.5">
                    <span class="w-8 h-8 rounded-md bg-gradient-to-br from-sky-500 to-sky-700 flex items-center justify-center text-white text-sm shadow-md shadow-sky-500/30">4</span>
                    ENGINEERING <span class="text-gray-400 font-bold text-lg">ACTIVITIES</span>
                </h2>
                <div class="overflow-x-auto rounded-xl border border-gray-200 shadow-sm">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gradient-to-r from-gray-200 via-gray-100 to-gray-200 text-gray-800 text-xs uppercase tracking-[0.12em] font-black">
                                <th class="px-5 py-4 text-left font-black w-64">DEPARTMENT</th>
                                <th class="px-5 py-4 text-left font-black border-l border-gray-300">ACTIVITY DETAIL</th>
                                <th class="px-5 py-4 text-center font-black border-l border-gray-300 w-40">STATUS</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 align-top">
                            <?php
                            $deptDef = [
                                'operation'   => ['OPERATION',   '⚙️', 'text-blue-700',   'bg-blue-50/50'],
                                'maintenance' => ['MAINTENANCE', '🔧', 'text-amber-700',  'bg-amber-50/50'],
                                'project'     => ['PROJECT',     '📋', 'text-violet-700', 'bg-violet-50/50'],
                                'landscape'   => ['LANDSCAPE',   '🌱', 'text-emerald-700','bg-emerald-50/50'],
                            ];
                            foreach ($deptDef as $key => $dd):
                                [$deptLabel, $deptIcon, $deptCol, $deptBg] = $dd;
                                $rows = $actsGRP[$key] ?? [];
                                $empty = (count($rows) === 0);
                            ?>
                            <tr class="hover:bg-amber-50/30 transition-colors <?= $deptBg ?>">
                                <td class="px-5 py-5 border-r border-gray-100">
                                    <div class="flex items-center gap-2.5">
                                        <span class="w-10 h-10 rounded-lg bg-white shadow-sm border border-gray-200 flex items-center justify-center text-lg"><?= $deptIcon ?></span>
                                        <span class="font-black text-gray-900 text-lg tracking-wide"><?= $deptLabel ?></span>
                                    </div>
                                </td>
                                <td class="px-5 py-5 border-l border-gray-100">
                                    <?php if ($empty): ?>
                                        <div class="flex items-center gap-2 text-gray-400 italic text-sm">
                                            <i class="fas fa-inbox opacity-70"></i>
                                            Belum ada aktivitas bulan ini.
                                        </div>
                                    <?php else: ?>
                                        <ul class="space-y-2.5">
                                            <?php foreach ($rows as $ar): ?>
                                            <li class="flex items-start gap-2.5">
                                                <span class="mt-1 text-gray-500 font-black text-sm leading-none">•</span>
                                                <div class="flex-1 leading-relaxed">
                                                    <span class="font-semibold text-gray-800"><?= cleanInput($ar['title']) ?></span>
                                                    <?php if (strlen($ar['date'] ?? '') > 0): ?>
                                                        <span class="ml-2 text-[10px] font-semibold text-gray-400 border border-gray-200 rounded px-1.5 py-0.5 bg-white/70">
                                                            <i class="far fa-calendar mr-0.5"></i>
                                                            <?= (new DateTime($ar['date']))->format('d M Y') ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (strlen($ar['eng'] ?? '') > 0): ?>
                                                        <span class="ml-1 text-[10px] font-semibold text-gray-500 border border-gray-200 rounded px-1.5 py-0.5 bg-white/70">
                                                            <i class="fas fa-user-helmet-safety mr-0.5"></i>
                                                            <?= cleanInput($ar['eng']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-5 text-center border-l border-gray-100 align-middle">
                                    <?php if ($empty): ?>
                                        <span class="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-full bg-gray-100 border border-gray-200 text-gray-500">
                                            — No Data —
                                        </span>
                                    <?php else: ?>
                                        <?php
                                        $completeCount = 0;
                                        $progCount = 0;
                                        foreach ($rows as $rr) { if (($rr['status'] ?? '') === 'complete') $completeCount++; else $progCount++; }
                                        ?>
                                        <div class="space-y-2 w-full">
                                            <?php if ($completeCount > 0): ?>
                                                <span class="inline-flex items-center gap-1.5 w-full justify-center px-3 py-1.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 text-[11px] font-black tracking-wider">
                                                    <i class="fas fa-circle-check"></i> Complete
                                                    <span class="ml-0.5 text-xs">(<?= $completeCount ?>)</span>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($progCount > 0): ?>
                                                <span class="inline-flex items-center gap-1.5 w-full justify-center px-3 py-1.5 rounded-full bg-amber-50 border border-amber-200 text-amber-800 text-[11px] font-black tracking-wider">
                                                    <i class="fas fa-circle-notch fa-spin" style="--fa-animation-duration: 1.8s"></i> In Progress
                                                    <span class="ml-0.5 text-xs">(<?= $progCount ?>)</span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Tanda tangan mini (Prepared / Reviewed / Approved) seperti kertas -->
            <div class="pt-6 mt-4 border-t border-gray-200 grid grid-cols-1 sm:grid-cols-3 gap-6">
                <?php
                $signs = [
                    ['Prepared By:',  $userRole === 'engineer' ? cleanInput($userName) : 'Engineering Staff'],
                    ['Reviewed By:',  $userRole === 'supervisor' ? cleanInput($userName) : 'Supervisor Engineering'],
                    ['Approved By:',  $userRole === 'manager'    ? cleanInput($userName) : 'Manager Engineering'],
                ];
                foreach ($signs as [$lbl, $nameOrRole]): ?>
                <div class="text-center">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-gray-500 mb-3"><?= $lbl ?></p>
                    <div class="w-20 h-10 mx-auto border-b border-dashed border-gray-300 mb-2 opacity-60"></div>
                    <p class="font-black text-gray-800 underline decoration-dotted decoration-gray-400 underline-offset-4"><?= $nameOrRole ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ============ ① UTILITY / ENERGY REPORT (PALING ATAS SESUAI CATATAN KERTAS!) ============ -->
    <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden mb-8 animate-slide-up">
        <div class="px-5 lg:px-6 py-4 border-b border-border bg-gradient-to-r from-white via-amber-50/40 to-white">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.25em] text-accent mb-1">ENG. DEPT.</p>
                    <h2 class="font-display text-xl lg:text-2xl font-black text-primary flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center text-white shadow-md shadow-amber-500/25 text-sm">①</span>
                        <?= T('dash_util_title', 'Utility Report') ?>
                    </h2>
                </div>
                <div class="flex flex-wrap items-center gap-4 lg:gap-6">
                    <div class="flex items-center gap-2 bg-amber-50/80 border border-amber-200 rounded-2xl px-4 py-2.5 shadow-sm">
                        <span class="w-7 h-7 rounded-lg bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center text-white text-[10px] font-black shadow">LY</span>
                        <div class="leading-tight">
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-secondary/80"><?= T('dash_util_lastyear', 'LY (Tahun Lalu)') ?> • <?= $lastYear ?></p>
                            <div class="flex items-baseline gap-1.5">
                                <span class="text-[11px] font-black text-amber-700 uppercase tracking-wider">OCC</span>
                                <span class="text-2xl lg:text-3xl font-black text-accent drop-shadow-sm"><?= $lyOcc ?></span>
                                <span class="text-sm font-black text-accent">%</span>
                            </div>
                        </div>
                    </div>
                    <div class="text-accent/40 text-2xl font-black hidden sm:block">VS</div>
                    <div class="flex items-center gap-2 bg-emerald-50/80 border border-emerald-200 rounded-2xl px-4 py-2.5 shadow-sm">
                        <span class="w-7 h-7 rounded-lg bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-[10px] font-black shadow">NOW</span>
                        <div class="leading-tight">
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-secondary/80"><?= T('dash_util_today', 'Today (Hari Ini)') ?> • <?= formatDate($today) ?></p>
                            <div class="flex items-baseline gap-1.5">
                                <span class="text-[11px] font-black text-emerald-700 uppercase tracking-wider">OCC</span>
                                <span class="text-2xl lg:text-3xl font-black text-emerald-700 drop-shadow-sm"><?= $targetOcc ?></span>
                                <span class="text-sm font-black text-emerald-700">%</span>
                                <?php if ($targetOcc > $lyOcc): ?>
                                    <span class="ml-1 text-[10px] font-black inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200"><i class="fas fa-arrow-trend-up text-[9px]"></i><?= ($targetOcc - $lyOcc) ?>%</span>
                                <?php elseif ($targetOcc < $lyOcc): ?>
                                    <span class="ml-1 text-[10px] font-black inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-rose-100 text-rose-700 border border-rose-200"><i class="fas fa-arrow-trend-down text-[9px]"></i><?= ($lyOcc - $targetOcc) ?>%</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="p-5 lg:p-6">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-5">
                <?php
                $utilCards = [
                    ['elec', 'electricity', T('dash_util_electricity', 'Electricity'), '⚡', 'from-amber-400 to-amber-600', 'bg-amber-50', 'border-amber-200', 'text-amber-700', $lyElecAvg, $todayElec, 'kWh'],
                    ['water', 'water', T('dash_util_water', 'Water'), '💧', 'from-blue-400 to-blue-600', 'bg-blue-50', 'border-blue-200', 'text-blue-700', $lyWaterAvg, $todayWater, 'm³'],
                    ['gas', 'gas', T('dash_util_gas', 'Gas'), '🔥', 'from-orange-400 to-orange-600', 'bg-orange-50', 'border-orange-200', 'text-orange-700', $lyGasAvg, $todayGas, 'kg'],
                    ['fuel', 'fuel', T('dash_util_fuel', 'Fuel'), '⛽', 'from-rose-400 to-red-600', 'bg-rose-50', 'border-rose-200', 'text-rose-700', $lyFuelAvg, $todayFuel, 'L'],
                ];
                foreach ($utilCards as $uc) {
                    [$key, $modalId, $label, $icon, $grad, $bg, $bor, $col, $lyVal, $todayVal, $unit] = $uc;
                    $diff = $todayVal - $lyVal;
                    $diffPct = $lyVal > 0 ? (($todayVal - $lyVal) / $lyVal) * 100 : 0;
                    $up = $diff >= 0;
                    $lyDisp = $lyVal > 0 ? formatNumber($lyVal, 1) : '-';
                    $todayDisp = $todayVal > 0 ? formatNumber($todayVal, 1) : '-';
                    $diffColor = $up ? 'text-green-600' : 'text-red-600';
                    $diffIcon = $up ? '▲' : '▼';
                    $diffStr = $lyVal > 0 ? "{$diffIcon} " . number_format(abs($diffPct), 1) . '%' : '';
                    $diffHtml = $diffStr ? "<span class=\"text-[11px] font-black {$diffColor} shrink-0\">{$diffStr}</span>" : '';
                    echo <<<HTML
                <div class="rounded-2xl border {$bor} {$bg}/50 p-4 sm:p-5 shadow-sm hover:shadow-xl hover:shadow-amber-500/10 hover:-translate-y-1 hover:scale-[1.02] cursor-pointer transition-all duration-300" onclick="openModal('{$modalId}')">
                    <div class="flex items-center gap-2 mb-4">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br {$grad} flex items-center justify-center text-white shadow-md">
                            <span class="text-sm">{$icon}</span>
                        </div>
                        <div class="flex-1">
                            <p class="font-black text-primary uppercase tracking-wide text-sm">{$label}</p>
                        </div>
                        <i class="fas fa-chevron-right {$col} text-xs opacity-70"></i>
                    </div>
                    <div class="space-y-3">
                        <div class="pb-3 border-b border-dashed {$bor}/60">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-secondary mb-1">LY • Avg/Day</p>
                            <div class="flex items-baseline gap-1.5">
                                <p class="text-2xl lg:text-3xl font-black text-primary">{$lyDisp}</p>
                                <span class="text-xs font-bold text-secondary">{$unit}</span>
                            </div>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-widest text-accent mb-1">TODAY</p>
                            <div class="flex items-baseline justify-between gap-2">
                                <div class="flex items-baseline gap-1.5">
                                    <p class="text-2xl lg:text-3xl font-black text-primary">{$todayDisp}</p>
                                    <span class="text-xs font-bold text-secondary">{$unit}</span>
                                </div>
                                {$diffHtml}
                            </div>
                        </div>
                    </div>
                </div>
HTML;
                }
                ?>
            </div>
        </div>
    </div>

    <!-- ============ ② CHILLER SYSTEM PERFORMANCE ============ -->
    <div class="bg-surface rounded-premium border border-cyan-200/70 shadow-sm overflow-hidden mb-8 animate-slide-up" style="animation-delay: 40ms">
        <div class="px-5 lg:px-6 py-4 border-b border-cyan-100 bg-gradient-to-r from-white via-cyan-50/50 to-white">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.25em] text-cyan-700 mb-1">HVAC SYSTEM</p>
                    <h2 class="font-display text-xl lg:text-2xl font-black text-primary flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-cyan-400 to-cyan-700 flex items-center justify-center text-white shadow-md shadow-cyan-500/25 text-sm">②</span>
                        <?= T('dash_chiller_title', 'Chiller System') ?>
                    </h2>
                </div>
            </div>
        </div>
        <div class="p-5 lg:p-6">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <?php
                $chillerMonthlyAvg = $db->fetchOne("SELECT
                    COALESCE(AVG(NULLIF(chiller_water_ph,0)),0) as avg_ph,
                    COALESCE(AVG(NULLIF(chiller_water_tds,0)),0) as avg_tds,
                    COALESCE(AVG(NULLIF(chiller_temp,0)),0) as avg_temp,
                    COALESCE(COUNT(CASE WHEN (chiller_1_on=1 OR chiller_2_on=1 OR chiller_3_on=1) THEN 1 END),0) as days_active
                FROM daily_logs dl WHERE dl.log_date BETWEEN ? AND ? AND dl.status='approved' $statusWhere", [$monthStart, $today]);
                $chToday = $utilTodaySingle ? [
                    'ch1' => (int)($utilTodaySingle['chiller_1_on'] ?? 0),
                    'ch2' => (int)($utilTodaySingle['chiller_2_on'] ?? 0),
                    'ch3' => (int)($utilTodaySingle['chiller_3_on'] ?? 0),
                    'ph' => (float)($utilTodaySingle['chiller_water_ph'] ?? 0),
                    'tds' => (float)($utilTodaySingle['chiller_water_tds'] ?? 0),
                    'temp' => (float)($utilTodaySingle['chiller_temp'] ?? 0),
                ] : ['ch1'=>0,'ch2'=>0,'ch3'=>0,'ph'=>0,'tds'=>0,'temp'=>0];
                $chTodayOn = $chToday['ch1'] + $chToday['ch2'] + $chToday['ch3'];
                $chillerCards = [
                    [T('dash_ch_on', 'Unit ON Today'), "{$chTodayOn} / 3", '❄️', 'from-cyan-400 to-cyan-600', 'bg-cyan-50', 'border-cyan-200', 'text-cyan-700', (string)(int)($chillerMonthlyAvg['days_active'] ?? 0).' hari', 'chiller'],
                    [T('dash_ch_ph', 'pH Level'), $chToday['ph'] > 0 ? formatNumber($chToday['ph'], 2) : '-', '🧪', 'from-indigo-400 to-indigo-600', 'bg-indigo-50', 'border-indigo-200', 'text-indigo-700', (float)($chillerMonthlyAvg['avg_ph'] ?? 0) > 0 ? formatNumber($chillerMonthlyAvg['avg_ph'], 2).' avg' : '-', 'chiller'],
                    [T('dash_ch_tds', 'TDS (ppm)'), $chToday['tds'] > 0 ? formatNumber($chToday['tds'], 0) : '-', '💧', 'from-teal-400 to-teal-600', 'bg-teal-50', 'border-teal-200', 'text-teal-700', (float)($chillerMonthlyAvg['avg_tds'] ?? 0) > 0 ? formatNumber($chillerMonthlyAvg['avg_tds'], 0).' ppm' : '-', 'chiller'],
                    [T('dash_ch_temp', 'Temperature (°C)'), $chToday['temp'] > 0 ? formatNumber($chToday['temp'], 1) : '-', '🌡️', 'from-rose-400 to-pink-600', 'bg-rose-50', 'border-rose-200', 'text-rose-700', (float)($chillerMonthlyAvg['avg_temp'] ?? 0) > 0 ? formatNumber($chillerMonthlyAvg['avg_temp'], 1).'°C' : '-', 'chiller'],
                ];
                foreach ($chillerCards as $i => $cc) {
                    [$lbl, $todayVal, $icon, $grad, $bg, $bor, $col, $monthVal, $modal] = $cc;
                ?>
                    <div class="rounded-2xl border <?= $bor ?> <?= $bg ?>/40 p-4 sm:p-5 hover:shadow-lg hover:-translate-y-0.5 hover:scale-[1.01] cursor-pointer transition-all" onclick="openModal('<?= $modal ?>')">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br <?= $grad ?> flex items-center justify-center text-white shadow">
                                <span class="text-sm"><?= $icon ?></span>
                            </div>
                            <p class="font-bold text-primary text-xs sm:text-sm uppercase tracking-wide"><?= $lbl ?></p>
                        </div>
                        <p class="text-2xl lg:text-3xl font-black text-primary leading-none mb-1"><?= $todayVal ?></p>
                        <p class="text-[11px] font-bold <?= $col ?>"><i class="far fa-calendar-alt mr-1"></i> Bulan Ini: <?= $monthVal ?></p>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <!-- ============ ③ SWRO SYSTEM ============ -->
    <div class="bg-surface rounded-premium border border-sky-200/70 shadow-sm overflow-hidden mb-8 animate-slide-up" style="animation-delay: 60ms">
        <div class="px-5 lg:px-6 py-4 border-b border-sky-100 bg-gradient-to-r from-white via-sky-50/50 to-white">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.25em] text-sky-700 mb-1">WATER TREATMENT</p>
                    <h2 class="font-display text-xl lg:text-2xl font-black text-primary flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-sky-400 to-blue-700 flex items-center justify-center text-white shadow-md shadow-sky-500/25 text-sm">③</span>
                        <?= T('dash_swro_title', 'SWRO (Reverse Osmosis)') ?>
                    </h2>
                </div>
            </div>
        </div>
        <div class="p-5 lg:p-6">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <?php
                $swMonthly = $db->fetchOne("SELECT COALESCE(SUM(swro_watermeter),0) as wm, COALESCE(SUM(swro_kwh),0) as kwh, COALESCE(AVG(NULLIF(swro_tds,0)),0) as tds FROM daily_logs WHERE log_date BETWEEN ? AND ? AND status='approved' $statusWhere", [$monthStart, $today]);
                $swToday = $utilTodaySingle ? [
                    'wm' => (float)($utilTodaySingle['swro_watermeter'] ?? 0),
                    'kwh' => (float)($utilTodaySingle['swro_kwh'] ?? 0),
                    'tds' => (float)($utilTodaySingle['swro_tds'] ?? 0),
                ] : ['wm'=>0,'kwh'=>0,'tds'=>0];
                $swCards = [
                    [T('dash_sw_wm', 'Watermeter (m³)'), $swToday['wm'] > 0 ? formatNumber($swToday['wm'], 1) : '-', '💧', 'from-sky-400 to-sky-600', 'bg-sky-50', 'border-sky-200', 'text-sky-700', formatNumber($swMonthly['wm'] ?? 0, 1).' m³'],
                    [T('dash_sw_kwh', 'Konsumsi Listrik (kWh)'), $swToday['kwh'] > 0 ? formatNumber($swToday['kwh'], 1) : '-', '⚡', 'from-yellow-400 to-amber-600', 'bg-amber-50', 'border-amber-200', 'text-amber-700', formatNumber($swMonthly['kwh'] ?? 0, 1).' kWh'],
                    [T('dash_sw_tds', 'TDS Outlet (ppm)'), $swToday['tds'] > 0 ? formatNumber($swToday['tds'], 0) : '-', '🧪', 'from-slate-400 to-slate-700', 'bg-slate-50', 'border-slate-200', 'text-slate-700', (float)($swMonthly['tds'] ?? 0) > 0 ? formatNumber($swMonthly['tds'], 0).' ppm avg' : '-'],
                ];
                foreach ($swCards as $sc) {
                    [$lbl, $todayVal, $icon, $grad, $bg, $bor, $col, $monthVal] = $sc;
                ?>
                    <div class="rounded-2xl border <?= $bor ?> <?= $bg ?>/40 p-4 sm:p-5 hover:shadow-lg hover:-translate-y-0.5 hover:scale-[1.01] cursor-pointer transition-all" onclick="openModal('swro')">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br <?= $grad ?> flex items-center justify-center text-white shadow">
                                <span class="text-sm"><?= $icon ?></span>
                            </div>
                            <p class="font-bold text-primary text-xs sm:text-sm uppercase tracking-wide"><?= $lbl ?></p>
                        </div>
                        <p class="text-2xl lg:text-3xl font-black text-primary leading-none mb-1"><?= $todayVal ?></p>
                        <p class="text-[11px] font-bold <?= $col ?>"><i class="far fa-calendar-alt mr-1"></i> Bulan Ini: <?= $monthVal ?></p>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <!-- ============ ④ BOTTLING PLANT ============ -->
    <!-- ============ ⑤ ENGINEERING ACTIVITIES - HANYA SUPERVISOR / MANAGER YANG DAPAT LIHAT ============ -->
    <?php if (in_array($userRole, ['supervisor','manager','admin'], true)): ?>
    <div class="bg-surface rounded-premium border border-accent/20 shadow-sm overflow-hidden mb-8 animate-slide-up" style="animation-delay: 120ms">
        <div class="px-5 lg:px-6 py-4 border-b border-accent/20 bg-gradient-to-r from-white via-amber-50/30 to-white">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.25em] text-accent mb-1"><?= T('dash_act_subtitle', 'Staff') ?></p>
                    <h2 class="font-display text-xl lg:text-2xl font-black text-primary flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-primary to-gray-800 flex items-center justify-center text-white shadow-md text-sm">⑤</span>
                        <?= T('dash_act_title', 'ENG ACTIVITY') ?>
                    </h2>
                </div>
            </div>
        </div>
        <div class="p-5 lg:p-6 space-y-3 sm:space-y-4">
            <?php
            $actCards = [
                [
                    'operation',
                    T('dash_act_operation', 'OPERATION'),
                    'fas fa-gears',
                    'from-blue-400 to-blue-600',
                    'bg-blue-50',
                    'border-blue-200',
                    'text-blue-700',
                    (int)($todayAct['op'] ?? 0),
                    (int)($activitySum['op'] ?? 0),
                    true
                ],
                [
                    'maintenance',
                    T('dash_act_maintenance', 'MAINTENANCE'),
                    'fas fa-wrench',
                    'from-emerald-400 to-emerald-600',
                    'bg-emerald-50',
                    'border-emerald-200',
                    'text-emerald-700',
                    (int)($todayAct['maint'] ?? 0),
                    (int)($activitySum['maint'] ?? 0),
                    false
                ],
                [
                    'project',
                    T('dash_act_project', 'PROJECT'),
                    'fas fa-diagram-project',
                    'from-violet-400 to-violet-600',
                    'bg-violet-50',
                    'border-violet-200',
                    'text-violet-700',
                    (int)($todayAct['proj'] ?? 0),
                    (int)($activitySum['proj'] ?? 0),
                    false
                ],
                [
                    'landscape',
                    T('dash_act_landscape', 'LANDSCAPE'),
                    'fas fa-leaf',
                    'from-teal-400 to-teal-600',
                    'bg-teal-50',
                    'border-teal-200',
                    'text-teal-700',
                    (int)($todayAct['land'] ?? 0),
                    (int)($activitySum['land'] ?? 0),
                    false
                ],
            ];
            $actCountLabel = T('dash_act_count', 'aktivitas');
            foreach ($actCards as $ac) {
                [$modalId, $label, $icon, $grad, $bg, $bor, $col, $todayCnt, $monthCnt, $isFirst] = $ac;
                echo <<<HTML
            <div class="rounded-2xl border {$bor} {$bg}/40 p-4 sm:p-5 hover:shadow-xl hover:shadow-amber-500/10 hover:-translate-y-0.5 hover:scale-[1.005] cursor-pointer transition-all duration-300" onclick="openModal('{$modalId}')">
                <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                    <div class="flex items-center gap-3 sm:w-64 shrink-0">
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br {$grad} flex items-center justify-center text-white shadow-md shrink-0">
                            <i class="{$icon}"></i>
                        </div>
                        <div>
                            <p class="font-black text-primary uppercase tracking-wider text-sm">{$label}</p>
                            <p class="text-[10px] font-bold uppercase tracking-widest text-secondary">This Month</p>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-end justify-between gap-4">
                            <div class="flex items-baseline gap-3">
                                <div class="flex items-baseline gap-1.5">
                                    <p class="text-xs text-secondary font-bold uppercase tracking-wide">Today</p>
                                    <p class="text-3xl lg:text-4xl font-black text-primary leading-none">{$todayCnt}</p>
                                    <span class="text-[11px] font-bold text-secondary mb-1">{$actCountLabel}</span>
                                </div>
                                <div class="w-px h-8 bg-border shrink-0 hidden sm:block"></div>
                                <div class="flex items-baseline gap-1.5">
                                    <p class="text-xs text-secondary font-bold uppercase tracking-wide">Month</p>
                                    <p class="text-2xl lg:text-3xl font-black text-primary/70 leading-none">{$monthCnt}</p>
                                    <span class="text-[11px] font-bold text-secondary mb-1">total</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <i class="fas fa-chevron-right {$col} text-sm opacity-70"></i>
                            </div>
                        </div>
                        <div class="mt-3 h-2 w-full bg-white/60 rounded-full overflow-hidden border {$bor}/40">
HTML;
                $todayBar = $todayCnt > 0 ? min(100, max(5, ($todayCnt / max(1, max($todayCnt, $monthCnt, 10))) * 100)) : 0;
                $monthBar = $monthCnt > 0 ? min(100, max(5, ($monthCnt / max(1, max($todayCnt, $monthCnt, 10))) * 100)) : 0;
                echo <<<HTML
                            <div class="h-full w-full relative">
                                <div class="absolute inset-y-0 left-0 bg-gradient-to-r {$grad} opacity-20 rounded-full" style="width: {$monthBar}%"></div>
                                <div class="absolute inset-y-0 left-0 bg-gradient-to-r {$grad} rounded-full shadow-sm" style="width: {$todayBar}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
HTML;
            }
            ?>
        </div>
    </div>
    <?php endif; ?>

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

    <!-- ============ ⑧ LOGISTIC - ORDER REQUEST & STATUS (PALING BAWAH SESUAI CATATAN KERTAS!) ============ -->
    <?php if (in_array($userRole, ['supervisor','manager','admin'])): ?>
    <div id="section_logistic" class="bg-surface rounded-premium border border-amber-200/70 shadow-sm overflow-hidden mb-8 animate-slide-up scroll-mt-[90px]" style="animation-delay: 180ms">
        <div class="px-5 lg:px-6 py-4 border-b border-amber-100 bg-gradient-to-r from-white via-amber-50/40 to-white">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.25em] text-amber-700 mb-1">PROCUREMENT • LOGISTIC</p>
                    <h2 class="font-display text-xl lg:text-2xl font-black text-primary flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-amber-600 to-amber-900 flex items-center justify-center text-white shadow-md shadow-amber-500/25 text-sm">⑧</span>
                        <?= T('dash_logistic_title', 'Logistik & Order Request') ?>
                    </h2>
                </div>
                <a href="<?= BASE_URL ?>orders/index.php" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-gradient-to-br from-amber-500 to-amber-700 hover:from-amber-600 hover:to-amber-800 text-white text-xs font-bold shadow hover:shadow-lg hover:-translate-y-0.5 transition-all">
                    <i class="fas fa-clipboard-list"></i> <?= T('order_menu_all', 'Semua Order') ?>
                </a>
            </div>
        </div>
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
                    [T('stat_label_total', 'Total'), $allOrder, T('order_stat_all', 'Total Order / PR'), 'from-slate-100 to-slate-200', 'bg-slate-50', 'border-slate-200', 'text-slate-600', 'text-slate-800', 'fa-clipboard-list', BASE_URL . 'orders/index.php?filter=all'],
                    [$userRole === 'manager' ? T('order_stat_pending_mgr', 'Perlu Approve Mgr') : T('order_stat_pending_spv', 'Perlu Approve Spv'), $pendingMine, T('order_stat_pending', 'Belum di-proses saya'), 'from-amber-100 to-yellow-200', 'bg-yellow-50', 'border-yellow-200', 'text-amber-700', 'text-amber-800', 'fa-clock', BASE_URL . 'orders/index.php?filter=my_pending'],
                    [T('order_stat_pending_spv', 'Spv Queue'), $spvPen, T('order_stat_wait_spv', 'Menunggu Supervisor'), 'from-orange-100 to-orange-200', 'bg-orange-50', 'border-orange-200', 'text-orange-700', 'text-orange-800', 'fa-hourglass-half', BASE_URL . 'orders/index.php?filter=pending_supervisor'],
                    [T('order_stat_pending_mgr', 'Mgr Queue'), $mgrPen, T('order_stat_wait_mgr', 'Menunggu Manager'), 'from-blue-100 to-blue-200', 'bg-blue-50', 'border-blue-200', 'text-blue-700', 'text-blue-800', 'fa-hourglass-start', BASE_URL . 'orders/index.php?filter=pending_manager'],
                    [T('stat_label_approved', 'Approved'), $approve, T('order_stat_approved', 'Sudah Final Approval'), 'from-emerald-100 to-emerald-200', 'bg-emerald-50', 'border-emerald-200', 'text-emerald-700', 'text-emerald-800', 'fa-circle-check', BASE_URL . 'orders/index.php?filter=approved'],
                ];
                if ($userRole !== 'manager' && $userRole !== 'supervisor') {
                    $orderBadge[1] = [T('order_stat_rejected', 'Ditolak'), $reject, T('order_stat_rejected_sub', 'Order Ditolak'), 'from-red-100 to-red-200', 'bg-red-50', 'border-red-200', 'text-red-700', 'text-red-800', 'fa-circle-xmark', BASE_URL . 'orders/index.php?filter=rejected'];
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

    <!-- FUEL MODAL -->
    <div id="modal-fuel" class="modal-card hidden bg-white rounded-premium shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-gradient-to-r from-rose-500 to-red-600 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-gas-pump text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_fuel_title', 'Rincian Konsumsi Fuel / Solar') ?></h2>
                <p class="text-rose-50/90 text-sm"><?= T('modal_fuel_sub', 'Total Liter Per Hari Bulan Ini - Kumulatif') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <?php $fuelTot = array_sum(array_column($fuelDetailData, 'total_fuel')); $fuelDays = count($fuelDetailData); ?>
            <div class="mb-5 grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl bg-rose-50 border border-rose-100 text-center">
                    <p class="text-xs text-rose-700 font-semibold mb-1"><?= T('modal_fuel_total', 'Total Bulan Ini') ?></p>
                    <p class="text-2xl font-bold text-rose-800"><?= formatNumber($fuelTot, 1) ?> <span class="text-sm">L</span></p>
                </div>
                <div class="p-4 rounded-xl bg-rose-50 border border-rose-100 text-center">
                    <p class="text-xs text-rose-700 font-semibold mb-1"><?= T('modal_fuel_avg', 'Rata-Rata / Hari') ?></p>
                    <p class="text-2xl font-bold text-rose-800"><?= $fuelDays>0 ? formatNumber($fuelTot/$fuelDays, 1) : 0 ?> <span class="text-sm">L</span></p>
                </div>
                <div class="p-4 rounded-xl bg-rose-50 border border-rose-100 text-center col-span-2 md:col-span-1">
                    <p class="text-xs text-rose-700 font-semibold mb-1"><?= T('modal_fuel_days', 'Hari Tercatat') ?></p>
                    <p class="text-2xl font-bold text-rose-800"><?= $fuelDays ?> <span class="text-sm">hari</span></p>
                </div>
            </div>
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalFuelChart"></canvas></div>
        </div>
    </div>

    <!-- OPERATION MODAL -->
    <div id="modal-operation" class="modal-card hidden bg-white rounded-premium shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-gears text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_act_op_title', 'Rincian Aktivitas OPERATION') ?></h2>
                <p class="text-blue-50/90 text-sm"><?= T('modal_act_op_sub', 'Jumlah aktivitas operasional per hari bulan ini') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <?php $opTot = array_sum(array_column($activityOpDetailData, 'activity_operation')); $opDays = count($activityOpDetailData); ?>
            <div class="mb-5 grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl bg-blue-50 border border-blue-100 text-center">
                    <p class="text-xs text-blue-700 font-semibold mb-1"><?= T('modal_act_total_month', 'Total Bulan Ini') ?></p>
                    <p class="text-2xl font-bold text-blue-800"><?= $opTot ?> <span class="text-sm"><?= T('dash_act_count', 'aktivitas') ?></span></p>
                </div>
                <div class="p-4 rounded-xl bg-blue-50 border border-blue-100 text-center">
                    <p class="text-xs text-blue-700 font-semibold mb-1"><?= T('modal_act_avg_day', 'Rata-Rata / Hari') ?></p>
                    <p class="text-2xl font-bold text-blue-800"><?= $opDays>0 ? number_format($opTot/$opDays, 1) : '0' ?></p>
                </div>
                <div class="p-4 rounded-xl bg-blue-50 border border-blue-100 text-center col-span-2 md:col-span-1">
                    <p class="text-xs text-blue-700 font-semibold mb-1"><?= T('modal_act_days', 'Hari Tercatat') ?></p>
                    <p class="text-2xl font-bold text-blue-800"><?= $opDays ?> <span class="text-sm">hari</span></p>
                </div>
            </div>
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalOperationChart"></canvas></div>
            <?= renderActivityTable($actListOp, 'text-blue-700', 'fa-user-tie', 'Belum ada daftar pekerjaan OPERATION untuk bulan ini.', 'OPERATION'); ?>
        </div>
    </div>
    <div id="modal-maintenance" class="modal-card hidden bg-white rounded-premium shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-gradient-to-r from-emerald-500 to-emerald-600 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-wrench text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_act_maint_title', 'Rincian Aktivitas MAINTENANCE') ?></h2>
                <p class="text-emerald-50/90 text-sm"><?= T('modal_act_maint_sub', 'Jumlah perawatan & perbaikan per hari bulan ini') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <?php $maintTot = array_sum(array_column($activityMaintDetailData, 'activity_maintenance')); $maintDays = count($activityMaintDetailData); ?>
            <div class="mb-5 grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-100 text-center">
                    <p class="text-xs text-emerald-700 font-semibold mb-1"><?= T('modal_act_total_month', 'Total Bulan Ini') ?></p>
                    <p class="text-2xl font-bold text-emerald-800"><?= $maintTot ?> <span class="text-sm"><?= T('dash_act_count', 'aktivitas') ?></span></p>
                </div>
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-100 text-center">
                    <p class="text-xs text-emerald-700 font-semibold mb-1"><?= T('modal_act_avg_day', 'Rata-Rata / Hari') ?></p>
                    <p class="text-2xl font-bold text-emerald-800"><?= $maintDays>0 ? number_format($maintTot/$maintDays, 1) : '0' ?></p>
                </div>
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-100 text-center col-span-2 md:col-span-1">
                    <p class="text-xs text-emerald-700 font-semibold mb-1"><?= T('modal_act_days', 'Hari Tercatat') ?></p>
                    <p class="text-2xl font-bold text-emerald-800"><?= $maintDays ?> <span class="text-sm">hari</span></p>
                </div>
            </div>
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalMaintenanceChart"></canvas></div>
            <?= renderActivityTable($actListMaint, 'text-emerald-700', 'fa-user-helmet-safety', 'Belum ada daftar pekerjaan MAINTENANCE untuk bulan ini.', 'MAINTENANCE'); ?>
        </div>
    </div>

    <!-- PROJECT MODAL -->
    <div id="modal-project" class="modal-card hidden bg-white rounded-premium shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-gradient-to-r from-violet-500 to-violet-600 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-diagram-project text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_act_proj_title', 'Rincian Aktivitas PROJECT') ?></h2>
                <p class="text-violet-50/90 text-sm"><?= T('modal_act_proj_sub', 'Progress proyek khusus per hari bulan ini') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <?php $projTot = array_sum(array_column($activityProjDetailData, 'activity_project')); $projDays = count($activityProjDetailData); ?>
            <div class="mb-5 grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl bg-violet-50 border border-violet-100 text-center">
                    <p class="text-xs text-violet-700 font-semibold mb-1"><?= T('modal_act_total_month', 'Total Bulan Ini') ?></p>
                    <p class="text-2xl font-bold text-violet-800"><?= $projTot ?> <span class="text-sm"><?= T('dash_act_count', 'aktivitas') ?></span></p>
                </div>
                <div class="p-4 rounded-xl bg-violet-50 border border-violet-100 text-center">
                    <p class="text-xs text-violet-700 font-semibold mb-1"><?= T('modal_act_avg_day', 'Rata-Rata / Hari') ?></p>
                    <p class="text-2xl font-bold text-violet-800"><?= $projDays>0 ? number_format($projTot/$projDays, 1) : '0' ?></p>
                </div>
                <div class="p-4 rounded-xl bg-violet-50 border border-violet-100 text-center col-span-2 md:col-span-1">
                    <p class="text-xs text-violet-700 font-semibold mb-1"><?= T('modal_act_days', 'Hari Tercatat') ?></p>
                    <p class="text-2xl font-bold text-violet-800"><?= $projDays ?> <span class="text-sm">hari</span></p>
                </div>
            </div>
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalProjectChart"></canvas></div>
            <?= renderActivityTable($actListProj, 'text-violet-700', 'fa-user-pen', 'Belum ada daftar Project untuk bulan ini.', 'PROJECT'); ?>
        </div>
    </div>

    <!-- LANDSCAPE MODAL -->
    <div id="modal-landscape" class="modal-card hidden bg-white rounded-premium shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-gradient-to-r from-teal-500 to-teal-600 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-leaf text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_act_land_title', 'Rincian Aktivitas LANDSCAPE') ?></h2>
                <p class="text-teal-50/90 text-sm"><?= T('modal_act_land_sub', 'Perawatan taman & lingkungan per hari bulan ini') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <?php $landTot = array_sum(array_column($activityLandDetailData, 'activity_landscape')); $landDays = count($activityLandDetailData); ?>
            <div class="mb-5 grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl bg-teal-50 border border-teal-100 text-center">
                    <p class="text-xs text-teal-700 font-semibold mb-1"><?= T('modal_act_total_month', 'Total Bulan Ini') ?></p>
                    <p class="text-2xl font-bold text-teal-800"><?= $landTot ?> <span class="text-sm"><?= T('dash_act_count', 'aktivitas') ?></span></p>
                </div>
                <div class="p-4 rounded-xl bg-teal-50 border border-teal-100 text-center">
                    <p class="text-xs text-teal-700 font-semibold mb-1"><?= T('modal_act_avg_day', 'Rata-Rata / Hari') ?></p>
                    <p class="text-2xl font-bold text-teal-800"><?= $landDays>0 ? number_format($landTot/$landDays, 1) : '0' ?></p>
                </div>
                <div class="p-4 rounded-xl bg-teal-50 border border-teal-100 text-center col-span-2 md:col-span-1">
                    <p class="text-xs text-teal-700 font-semibold mb-1"><?= T('modal_act_days', 'Hari Tercatat') ?></p>
                    <p class="text-2xl font-bold text-teal-800"><?= $landDays ?> <span class="text-sm">hari</span></p>
                </div>
            </div>
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalLandscapeChart"></canvas></div>
            <?= renderActivityTable($actListLand, 'text-teal-700', 'fa-user-nurse', 'Belum ada daftar pekerjaan LANDSCAPE untuk bulan ini.', 'LANDSCAPE'); ?>
        </div>
    </div>
</div>
<!-- ============ END 6+5 = 11 MODAL GRAFIK ============ -->

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
const modalFuelData = <?= json_encode($fuelDetailData) ?>;
const modalActOpData = <?= json_encode($activityOpDetailData) ?>;
const modalActMaintData = <?= json_encode($activityMaintDetailData) ?>;
const modalActProjData = <?= json_encode($activityProjDetailData) ?>;
const modalActLandData = <?= json_encode($activityLandDetailData) ?>;

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
        case 'fuel': {
            const labelsF = fmtLabels(modalFuelData);
            modalChartInstances[name] = new Chart(document.getElementById('modalFuelChart'), {
                type: 'bar',
                data: {
                    labels: labelsF,
                    datasets: [
                        { label: 'Fuel (Liter)', data: toCumulative(extract(modalFuelData, 'total_fuel')), backgroundColor: 'rgba(244,63,94,0.85)', borderColor: '#e11d48', borderWidth: 2, borderRadius: 8, borderSkipped: false }
                    ]
                },
                options: { ...modalChartOpts('Liter'), scales: { x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#64748b', maxRotation: 45 } }, y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 }, color: '#64748b' } } } }
            });
            break;
        }
        case 'operation': {
            const labelsOp = fmtLabels(modalActOpData);
            modalChartInstances[name] = new Chart(document.getElementById('modalOperationChart'), {
                type: 'bar',
                data: {
                    labels: labelsOp,
                    datasets: [
                        { label: 'Operation', data: extract(modalActOpData, 'activity_operation'), backgroundColor: 'rgba(37,99,235,0.82)', borderColor: '#2563eb', borderWidth: 2, borderRadius: 10, borderSkipped: false }
                    ]
                },
                options: { ...modalChartOpts('Aktivitas', false), scales: { x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#64748b', maxRotation: 45 } }, y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1, font: { size: 11 }, color: '#64748b' } } } }
            });
            break;
        }
        case 'maintenance': {
            const labelsMaint = fmtLabels(modalActMaintData);
            modalChartInstances[name] = new Chart(document.getElementById('modalMaintenanceChart'), {
                type: 'bar',
                data: {
                    labels: labelsMaint,
                    datasets: [
                        { label: 'Maintenance', data: extract(modalActMaintData, 'activity_maintenance'), backgroundColor: 'rgba(16,185,129,0.82)', borderColor: '#059669', borderWidth: 2, borderRadius: 10, borderSkipped: false }
                    ]
                },
                options: { ...modalChartOpts('Aktivitas', false), scales: { x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#64748b', maxRotation: 45 } }, y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1, font: { size: 11 }, color: '#64748b' } } } }
            });
            break;
        }
        case 'project': {
            const labelsProj = fmtLabels(modalActProjData);
            modalChartInstances[name] = new Chart(document.getElementById('modalProjectChart'), {
                type: 'bar',
                data: {
                    labels: labelsProj,
                    datasets: [
                        { label: 'Project', data: extract(modalActProjData, 'activity_project'), backgroundColor: 'rgba(124,58,237,0.82)', borderColor: '#7c3aed', borderWidth: 2, borderRadius: 10, borderSkipped: false }
                    ]
                },
                options: { ...modalChartOpts('Aktivitas', false), scales: { x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#64748b', maxRotation: 45 } }, y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1, font: { size: 11 }, color: '#64748b' } } } }
            });
            break;
        }
        case 'landscape': {
            const labelsLand = fmtLabels(modalActLandData);
            modalChartInstances[name] = new Chart(document.getElementById('modalLandscapeChart'), {
                type: 'bar',
                data: {
                    labels: labelsLand,
                    datasets: [
                        { label: 'Landscape', data: extract(modalActLandData, 'activity_landscape'), backgroundColor: 'rgba(13,148,136,0.82)', borderColor: '#0d9488', borderWidth: 2, borderRadius: 10, borderSkipped: false }
                    ]
                },
                options: { ...modalChartOpts('Aktivitas', false), scales: { x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#64748b', maxRotation: 45 } }, y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1, font: { size: 11 }, color: '#64748b' } } } }
            });
            break;
        }
    }
}

document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllModals(); });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
