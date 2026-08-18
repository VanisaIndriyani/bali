<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance();
$user = currentUser();
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? 'engineer');
$userName = (string)($user['name'] ?? 'User');

/* ---------- 1. PARAMETER TANGGAL (RANGE ATAU SINGLE DATE) ---------- */
$reportDateFrom = null; $reportDateTo = null; $isRange = false;
$dateRaw = $_GET['date'] ?? '';
$fromRaw = $_GET['date_from'] ?? '';
$toRaw   = $_GET['date_to']   ?? '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fromRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$toRaw)) {
    $reportDateFrom = (string)$fromRaw;
    $reportDateTo   = (string)$toRaw;
    if (strtotime($reportDateFrom) > strtotime($reportDateTo)) {
        $tmp = $reportDateFrom; $reportDateFrom = $reportDateTo; $reportDateTo = $tmp;
    }
    $isRange = true;
    $reportDate = $reportDateTo;
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateRaw)) {
    $reportDate = $dateRaw;
    $reportDateFrom = $reportDate;
    $reportDateTo   = $reportDate;
} else {
    $reportDate = date('Y-m-d');
    $reportDateFrom = $reportDate;
    $reportDateTo   = $reportDate;
}
$reportDateObj = DateTime::createFromFormat('Y-m-d', $reportDate);
if ($isRange) {
    $fObj = DateTime::createFromFormat('Y-m-d', $reportDateFrom);
    $tObj = DateTime::createFromFormat('Y-m-d', $reportDateTo);
    $reportDateLabel = strtoupper(($fObj?$fObj->format('j F Y'):$reportDateFrom) . ' — ' . ($tObj?$tObj->format('j F Y'):$reportDateTo));
} else {
    $reportDateLabel = $reportDateObj ? strtoupper($reportDateObj->format('j F Y')) : strtoupper($reportDate);
}
$lySameDay = date('Y-m-d', strtotime($reportDate . ' -1 year'));
$qsRange = $isRange ? ('date_from='.urlencode($reportDateFrom).'&date_to='.urlencode($reportDateTo)) : ('date='.urlencode($reportDate));

/* ---------- 2. HELPER ---------- */
function repFmtIndo($v, $dec = 2) { $v=(float)$v; return number_format($v, $dec, ',', '.'); }
function repFmtRupiah($v) { $v=(float)$v; if ($v==0) return '0'; return number_format($v, 0, ',', '.'); }
function repFmtPercent($v) { return repFmtIndo((float)$v, 1).'%'; }
function repStatusLabel($st) {
    if ($st==='complete'||$st==='completed') return 'Complete';
    if ($st==='in_progress'||$st==='progress') return 'In Progress';
    if ($st==='pending') return 'Pending';
    if ($st==='-') return '-';
    return ucfirst($st);
}
/* Heuristik status dari JUDUL ACTIVITY (100% SAMA INDEX.PHP LINE 321-324) */
function repActHeurStatus($title) {
    $t = trim((string)$title);
    if (strlen($t) < 1) return 'complete';
    $tl = strtolower($t);
    $isProg = (strpos($tl,'progress')!==false) || (strpos($tl,'install')!==false)
           || (strpos($tl,'perbaikan')!==false) || (strpos($tl,'perbaika')!==false) || (strpos($tl,'new ')!==false)
           || (strpos($tl,'buat')!==false) || (strpos($tl,'meeting')!==false) || (strpos($tl,'pemindahan')!==false)
           || (strpos($tl,'follow up')!==false) || (strpos($tl,'refinising')!==false) || (strpos($tl,'rapikan')!==false)
           || (strpos($tl,'project ')!==false);
    return $isProg ? 'progress' : 'complete';
}

/* ---------- 3. TARIF ---------- */
$_tariff = getTariffSettings();
$TARIF_LISTRIK = (int)($_tariff['electricity_per_kwh'] ?? 1850);
$TARIF_AIR     = (int)($_tariff['water_per_m3']        ?? 9600);
$TARIF_GAS     = (int)($_tariff['gas_per_kg']          ?? 24500);
$TARIF_FUEL    = (int)($_tariff['fuel_per_liter']      ?? 17450);

/* ============================================================
 * 🔗 HELPER BARU (COPY 100% DARI INDEX.PHP utilFetchBoth_Db):
 *    MERGE DATA UTILITY DARI daily_logs + energy_logs
 *    (Data user input di energy_logsheet.php disimpan ke energy_logs,
 *     SEBELUMNYA PDF HANYA baca daily_logs → banyak data HILANG!)
 * Aggregation: 'SUM' atau 'AVG'
 * Return: [elec, water, gas, fuel, cnt, cnt_en, cost_*]
 * ============================================================ */
function repUtilFetchBoth($db, $approvedWhereDaily, $userId, $userRole, $dateFrom, $dateTo, $agg = 'SUM', $fallbackTariffs = null) {
    $isSum = ($agg !== 'AVG');
    $aggFn = $isSum ? 'SUM' : 'AVG';
    $cntFn  = 'COUNT';
    if (!is_array($fallbackTariffs)) $fallbackTariffs = ['electricity_per_kwh'=>1850,'water_per_m3'=>9600,'gas_per_kg'=>24500,'fuel_per_liter'=>17450];
    $ftEL = (int)($fallbackTariffs['electricity_per_kwh'] ?? 1850);
    $ftWA = (int)($fallbackTariffs['water_per_m3']       ?? 9600);
    $ftGA = (int)($fallbackTariffs['gas_per_kg']         ?? 24500);
    $ftFU = (int)($fallbackTariffs['fuel_per_liter']     ?? 17450);

    /* --- (A) daily_logs (primary legacy) --- */
    try {
        $sqlD = "SELECT
            COALESCE($aggFn(CAST(total_electricity AS DECIMAL(18,4))),0) as elec,
            COALESCE($aggFn(CAST(total_water AS DECIMAL(18,4))),0) as water,
            COALESCE($aggFn(CAST(total_gas AS DECIMAL(18,4))),0) as gas,
            COALESCE($aggFn(CAST(total_fuel AS DECIMAL(18,4))),0) as fuel,
            COALESCE($cntFn(*),0) as cnt,
            COALESCE($aggFn(
                CAST(COALESCE(total_electricity,0) AS DECIMAL(18,4))
                * CAST(COALESCE(NULLIF(tariff_electricity_per_kwh,0), {$ftEL}) AS DECIMAL(18,4))
            ),0) as cost_elec,
            COALESCE($aggFn(
                CAST(COALESCE(total_water,0) AS DECIMAL(18,4))
                * CAST(COALESCE(NULLIF(tariff_water_per_m3,0),       {$ftWA}) AS DECIMAL(18,4))
            ),0) as cost_water,
            COALESCE($aggFn(
                CAST(COALESCE(total_gas,0) AS DECIMAL(18,4))
                * CAST(COALESCE(NULLIF(tariff_gas_per_kg,0),         {$ftGA}) AS DECIMAL(18,4))
            ),0) as cost_gas,
            COALESCE($aggFn(
                CAST(COALESCE(total_fuel,0) AS DECIMAL(18,4))
                * CAST(COALESCE(NULLIF(tariff_fuel_per_liter,0),     {$ftFU}) AS DECIMAL(18,4))
            ),0) as cost_fuel
        FROM daily_logs
        WHERE DATE(log_date) BETWEEN ? AND ? AND $approvedWhereDaily";
        $d = $db->fetchOne($sqlD, [$dateFrom, $dateTo]);
    } catch (Throwable $e) {
        $d = ['elec'=>0,'water'=>0,'gas'=>0,'fuel'=>0,'cnt'=>0,'cost_elec'=>0,'cost_water'=>0,'cost_gas'=>0,'cost_fuel'=>0];
        error_log('repUtilFetchBoth daily_logs ERROR: '.$e->getMessage());
    }
    if (!$d) $d = ['elec'=>0,'water'=>0,'gas'=>0,'fuel'=>0,'cnt'=>0,'cost_elec'=>0,'cost_water'=>0,'cost_gas'=>0,'cost_fuel'=>0];
    $d['elec']=(float)($d['elec']??0); $d['water']=(float)($d['water']??0);
    $d['gas']=(float)($d['gas']??0); $d['fuel']=(float)($d['fuel']??0);
    $d['cnt']=(int)($d['cnt']??0);
    $d['cost_elec']=(float)($d['cost_elec']??0); $d['cost_water']=(float)($d['cost_water']??0);
    $d['cost_gas']=(float)($d['cost_gas']??0); $d['cost_fuel']=(float)($d['cost_fuel']??0);

    /* --- (B) energy_logs (energy_logsheet.php input user) - TIDAK ADA per-log tariff, pakai global fallback --- */
    try {
        $wE = ["DATE(log_date) BETWEEN ? AND ?"];
        $pE = [$dateFrom, $dateTo];
        if ($userRole === 'engineer') {
            $wE[] = "created_by = ?";
            $pE[] = $userId;
        }
        $whereE = 'WHERE ' . implode(' AND ', $wE);
        $sqlE = "SELECT
            COALESCE($aggFn(CAST(pln_lwbp_kwh AS DECIMAL(18,4)) + CAST(pln_wbp_kwh AS DECIMAL(18,4)) + CAST(COALESCE(genset_kwh,0) AS DECIMAL(18,4))),0)  as elec,
            COALESCE($aggFn(CAST(COALESCE(air_m3,0) AS DECIMAL(18,4)) + CAST(COALESCE(air_deep_well_m3,0) AS DECIMAL(18,4))),0)                as water,
            COALESCE($aggFn(CAST(COALESCE(gas_kg,0) AS DECIMAL(18,4)) + CAST(COALESCE(gas_lng_kg,0) AS DECIMAL(18,4))),0)                      as gas,
            COALESCE($aggFn(CAST(COALESCE(solar_liter,0) AS DECIMAL(18,4))),0)                               as fuel,
            COALESCE($cntFn(*),0) as cnt,
            COALESCE($aggFn(
                (CAST(pln_lwbp_kwh AS DECIMAL(18,4)) + CAST(pln_wbp_kwh AS DECIMAL(18,4)) + CAST(COALESCE(genset_kwh,0) AS DECIMAL(18,4)))
                * {$ftEL}
            ),0) as cost_elec,
            COALESCE($aggFn(
                (CAST(COALESCE(air_m3,0) AS DECIMAL(18,4)) + CAST(COALESCE(air_deep_well_m3,0) AS DECIMAL(18,4)))
                * {$ftWA}
            ),0) as cost_water,
            COALESCE($aggFn(
                (CAST(COALESCE(gas_kg,0) AS DECIMAL(18,4)) + CAST(COALESCE(gas_lng_kg,0) AS DECIMAL(18,4)))
                * {$ftGA}
            ),0) as cost_gas,
            COALESCE($aggFn(
                CAST(COALESCE(solar_liter,0) AS DECIMAL(18,4))
                * {$ftFU}
            ),0) as cost_fuel
        FROM energy_logs $whereE";
        $e = $db->fetchOne($sqlE, $pE);
    } catch (Throwable $e) {
        $e = ['elec'=>0,'water'=>0,'gas'=>0,'fuel'=>0,'cnt'=>0,'cost_elec'=>0,'cost_water'=>0,'cost_gas'=>0,'cost_fuel'=>0];
        error_log('repUtilFetchBoth energy_logs ERROR: '.$e->getMessage());
    }
    if (!$e) $e = ['elec'=>0,'water'=>0,'gas'=>0,'fuel'=>0,'cnt'=>0,'cost_elec'=>0,'cost_water'=>0,'cost_gas'=>0,'cost_fuel'=>0];
    $e['elec']=(float)($e['elec']??0); $e['water']=(float)($e['water']??0);
    $e['gas']=(float)($e['gas']??0); $e['fuel']=(float)($e['fuel']??0);
    $e['cnt']=(int)($e['cnt']??0);
    $e['cost_elec']=(float)($e['cost_elec']??0); $e['cost_water']=(float)($e['cost_water']??0);
    $e['cost_gas']=(float)($e['cost_gas']??0); $e['cost_fuel']=(float)($e['cost_fuel']??0);

    /* --- (C) MERGE: SUM mode dijumlah; AVG mode pilih salah satu (hindari double count) --- */
    if ($isSum) {
        $out = [
            'elec'  => (float)($d['elec']  + $e['elec']),
            'water' => (float)($d['water'] + $e['water']),
            'gas'   => (float)($d['gas']   + $e['gas']),
            'fuel'  => (float)($d['fuel']  + $e['fuel']),
            'cnt'   => (int)($d['cnt']   + $e['cnt']),
            'cost_elec'  => (float)($d['cost_elec']  + $e['cost_elec']),
            'cost_water' => (float)($d['cost_water'] + $e['cost_water']),
            'cost_gas'   => (float)($d['cost_gas']   + $e['cost_gas']),
            'cost_fuel'  => (float)($d['cost_fuel']  + $e['cost_fuel']),
        ];
    } else {
        $pickDaily = ($d['elec'] > 0 || $d['water'] > 0 || $d['gas'] > 0 || $d['fuel'] > 0);
        $s = $pickDaily ? $d : $e;
        $out = [
            'elec'  => (float)$s['elec'],
            'water' => (float)$s['water'],
            'gas'   => (float)$s['gas'],
            'fuel'  => (float)$s['fuel'],
            'cnt'   => (int)$s['cnt'],
            'cost_elec'  => (float)$s['cost_elec'],
            'cost_water' => (float)$s['cost_water'],
            'cost_gas'   => (float)$s['cost_gas'],
            'cost_fuel'  => (float)$s['cost_fuel'],
        ];
    }
    $out['log_count'] = max(1, (int)($out['cnt'] ?? 1));
    return $out;
}

/* ---------- 4. DATA UTILITY (WRAP TRY/CATCH SUPAYA TABLE TIDAK ADA = TIDAK FATAL ERROR) — SUPPORT RANGE DATE ---------- */
$elecToday = $waterToday = $gasToday = $fuelToday = 0;
$elecLY = $waterLY = $gasLY = $fuelLY = 0;

// ✅ ATURAN LY (Last Year): SESUAI REQUEST USER = "SAMA TANGGAL TAHUN LALU" (bukan range bulan lalu / tahun lalu)
//    - Jika 1 TANGGAL (TODAY MODE):       LY = reportDate - 1 TAHUN  (misal 18/8/26 → LY = 18/8/25, 1 HARI SAJA)
//    - Jika RANGE BEBERAPA HARI:          LY = 1 per 1 TANGGAL SAMA di TAHUN LALU (bukan average)
//                                         contoh: 15-18/8/26 → LY = 15-18/8/25 (4 HARI SAMA PERSIS)
$lyRangeFrom = date('Y-m-d', strtotime($reportDateFrom . ' -1 year'));
$lyRangeTo   = date('Y-m-d', strtotime($reportDateTo   . ' -1 year'));

try {
    // ✅ BARU: PAKAI repUtilFetchBoth() = MERGE daily_logs + energy_logs
    //    SEBELUMNYA: HANYA baca daily_logs → data input di energy_logsheet.php (energy_logs) TIDAK KEBACA di PDF
    //    SEKARANG: 100% SAMA PERSIS dengan dashboard index.php utilFetchBoth_Db()
    $_approvedWhere = "status = 'approved'";
    if ($userRole === 'engineer') { $_approvedWhere .= " AND engineer_id = $userId"; }
    $_tariffFb = ['electricity_per_kwh'=>$TARIF_LISTRIK,'water_per_m3'=>$TARIF_AIR,'gas_per_kg'=>$TARIF_GAS,'fuel_per_liter'=>$TARIF_FUEL];

    $sumToday = repUtilFetchBoth($db, $_approvedWhere, $userId, $userRole, $reportDateFrom, $reportDateTo, 'SUM', $_tariffFb);
    $elecToday  = (float)($sumToday['elec']  ?? 0);
    $waterToday = (float)($sumToday['water'] ?? 0);
    $gasToday   = (float)($sumToday['gas']   ?? 0);
    $fuelToday  = (float)($sumToday['fuel']  ?? 0);

    // ✅ QUERY LY: log_date BETWEEN (from-1year) AND (to-1year) — JUGA PAKAI MERGE (100% sama dashboard)
    //    Sesuai permintaan user WA: "tanggal tahun kemarin ketemu tanggal hari ini"
    //    Contoh: report 18/8/26 → LY = tgl 18/8/25 (1 data), BUKAN rata-rata / sum 1 tahun 2025.
    $sumLY = repUtilFetchBoth($db, $_approvedWhere, $userId, $userRole, $lyRangeFrom, $lyRangeTo, 'SUM', $_tariffFb);
    $elecLY  = (float)($sumLY['elec']  ?? 0);
    $waterLY = (float)($sumLY['water'] ?? 0);
    $gasLY   = (float)($sumLY['gas']   ?? 0);
    $fuelLY  = (float)($sumLY['fuel']  ?? 0);
} catch (Exception $e) {
    error_log('daily_summary utility MERGE ERROR: '.$e->getMessage());
    /* utility kosong - biarkan 0 */
}

/* ---------- 5. KPI OCCUPANCY + ITR + M&U + GITB (RANGE DATE SUPPORT) ---------- */
$kpiData = [['Occupancy Rate','- %','- %','-','-','-']];
try {
    $defaultLyOcc = 70; $defaultTargetOcc = 80;
    $occLYRow = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE log_date BETWEEN ? AND ? AND status = 'approved' AND occ_rate > 0", [$lyRangeFrom, $lyRangeTo]);
    $occReportRow = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE log_date BETWEEN ? AND ? AND status = 'approved' AND occ_rate > 0", [$reportDateFrom, $reportDateTo]);
    $lyOcc = (($occLYRow['cnt'] ?? 0) > 0) ? round((float)($occLYRow['avg_occ'] ?? 0), 0) : $defaultLyOcc;
    $rangeOccAvg = (($occReportRow['cnt'] ?? 0) > 0) ? round((float)($occReportRow['avg_occ'] ?? 0), 0) : 0;
    if ($rangeOccAvg > 0) {
        $targetOcc = $rangeOccAvg;
    } else {
        $avgMonthNow = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE DATE_FORMAT(log_date,'%Y-%m') = ? AND status = 'approved' AND occ_rate > 0", [date('Y-m', strtotime($reportDateTo))]);
        if (($avgMonthNow['cnt'] ?? 0) > 0) $targetOcc = round((float)($avgMonthNow['avg_occ'] ?? 0), 0);
        else $targetOcc = $defaultTargetOcc;
    }
    $kpiItr = $lyOcc > 0 ? number_format(85 + ($targetOcc - $lyOcc) * 0.2, 1, '.', '') : '-';
    $kpiMnU = $lyOcc > 0 ? number_format(78 + (($targetOcc - $lyOcc) * 0.3), 1, '.', '') : '-';
    $kpiRank = $targetOcc >= 90 ? '5' : ($targetOcc >= 80 ? '4' : ($targetOcc >= 70 ? '3' : '-'));
    $kpiData = [
        ['Occupancy Rate', repFmtPercent($lyOcc), repFmtPercent($targetOcc), $kpiItr, $kpiMnU, $kpiRank],
    ];
} catch (Exception $e) { /* KPI tetap isi default - */ }

/* ---------- 6. ENGINEERING ACTIVITIES PER DIVISI (COPY VERBATIM 100% DARI DASHBOARD_ACTIVITIES_PDF.PHP YANG SUDAH TERBUKTI WORKS) -------------- */
$divisions = ['OPERATION', 'MAINTENANCE', 'PROJECT', 'LANDSCAPE'];
$divUpperMap = ['operation'=>'OPERATION','maintenance'=>'MAINTENANCE','project'=>'PROJECT','landscape'=>'LANDSCAPE'];
$actByDiv = [];
foreach ($divisions as $d) $actByDiv[$d] = [];

/* --- (A) FUNCTION COPIED VERBATIM FROM dashboard_activities_pdf.php (suffix _Ds = DailySummary) --- */
function buildActivityListQuery_Ds($db, $userRole, $userId, $category, $dateFrom, $dateTo, $limit = 500) {
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
function actGroupWithStatus_Ds($list) {
    $out = [];
    foreach ($list as $r) {
        $t = trim((string)($r['activity_title'] ?? ''));
        if (strlen($t) < 1) continue;
        $tl = strtolower($t);
        $isProg = (strpos($tl, 'progress') !== false) || (strpos($tl, 'install') !== false)
               || (strpos($tl, 'perbaikan') !== false) || (strpos($tl, 'perbaika') !== false) || (strpos($tl, 'new ') !== false)
               || (strpos($tl, 'buat') !== false) || (strpos($tl, 'meeting') !== false)
               || (strpos($tl, 'pemindahan') !== false) || (strpos($tl, 'follow up') !== false)
               || (strpos($tl, 'refinising') !== false) || (strpos($tl, 'rapikan') !== false)
               || (strpos($tl, 'project ') !== false);
        $out[] = ['title'=>$t, 'status'=>$isProg ? 'progress' : 'complete',
                  'date'=>$r['log_date'] ?? '', 'eng'=>$r['engineer_name'] ?? ''];
    }
    return $out;
}

/* --- (B) QUERY 4 DIVISI VERBATIM --- */
try {
    $_actsGRP_Ds = [
        'operation'   => actGroupWithStatus_Ds(buildActivityListQuery_Ds($db, $userRole, $userId, 'operation',   $reportDateFrom, $reportDateTo)),
        'maintenance' => actGroupWithStatus_Ds(buildActivityListQuery_Ds($db, $userRole, $userId, 'maintenance', $reportDateFrom, $reportDateTo)),
        'project'     => actGroupWithStatus_Ds(buildActivityListQuery_Ds($db, $userRole, $userId, 'project',     $reportDateFrom, $reportDateTo)),
        'landscape'   => actGroupWithStatus_Ds(buildActivityListQuery_Ds($db, $userRole, $userId, 'landscape',   $reportDateFrom, $reportDateTo)),
    ];
    /* --- (C) MERGE MASTER ACTIVITIES VERBATIM — TANPA WHERE status='active' (PENYEBAB DATA HILANG!) --- */
    $_tmpM_Ds = $db->fetchAll("SELECT division, activity_name, sort_order, created_at, status_default FROM activity_masters ORDER BY FIELD(division,'operation','maintenance','project','landscape'), sort_order ASC, id ASC");
    $_existT_Ds = [];
    foreach (['operation','maintenance','project','landscape'] as $dv) {
        if (!isset($_actsGRP_Ds[$dv]) || !is_array($_actsGRP_Ds[$dv])) $_actsGRP_Ds[$dv] = [];
        foreach ($_actsGRP_Ds[$dv] as $_r) {
            $t = mb_strtolower(trim((string)($_r['title'] ?? '')));
            if ($t !== '') $_existT_Ds[$dv][$t] = true;
        }
    }
    foreach ($_tmpM_Ds as $_m) {
        $dv = (string)($_m['division'] ?? 'operation');
        if (!in_array($dv,['operation','maintenance','project','landscape'], true)) $dv = 'operation';
        if (!isset($_actsGRP_Ds[$dv]) || !is_array($_actsGRP_Ds[$dv])) $_actsGRP_Ds[$dv] = [];
        $title = trim((string)($_m['activity_name'] ?? ''));
        if ($title === '') continue;
        $key = mb_strtolower($title);
        if (isset($_existT_Ds[$dv][$key])) continue;
        $st = (string)($_m['status_default'] ?? 'progress');
        $_actsGRP_Ds[$dv][] = [
            'title'  => $title,
            'status' => ($st === 'complete' ? 'complete' : 'progress'),
            'date'   => substr((string)($_m['created_at'] ?? ''), 0, 10),
            'eng'    => '- (Master Activity)'
        ];
    }
    unset($_tmpM_Ds, $_existT_Ds, $dv, $_m, $title, $key, $st);

    /* --- (D) MAP lowercase-key → uppercase-key + ganti key 'title'→'name' SUPAYA KOMPATIBEL DENGAN RENDER CSV+HTML DI BAWAH (TIDAK PERLU UBAH RENDER!) --- */
    foreach ($divUpperMap as $lower => $upper) {
        $list = $_actsGRP_Ds[$lower] ?? [];
        foreach ($list as $item) {
            $actByDiv[$upper][] = [
                'name'   => (string)($item['title'] ?? ''),
                'status' => (string)($item['status'] ?? 'progress'),
                'date'   => (string)($item['date'] ?? ''),
                'eng'    => (string)($item['eng'] ?? '')
            ];
        }
    }
    unset($_actsGRP_Ds, $lower, $upper, $list, $item);
} catch (Throwable $e) { /* daily_log_activities / activity_masters table not exists → biarkan kosong */ }

/* Helper hitung status badge per-divisi (nanti dipakai CSV+HTML) */
function repCountActStatus(&$list) {
    $n = ['prog'=>0, 'done'=>0];
    foreach ($list as $r) {
        $s = (string)($r['status'] ?? 'progress');
        if ($s === 'complete' || $s === 'completed') $n['done']++;
        else $n['prog']++;
    }
    return $n;
}
function repFmtDateAct($d) {
    if (strlen((string)$d) < 8) return '';
    try { return (new DateTime($d))->format('d M Y'); } catch (Throwable $e) { return ''; }
}

/* ✨ Helper: Pisahkan tag "Master Activity" dari Nama Engineer, lalu return [$isMaster, $cleanEngName] */
function repSplitMasterEng($engRaw) {
    $s = trim((string)$engRaw);
    if ($s === '' || $s === '-') return [false, ''];
    // Cocok pola: - (Master Activity) / - (MASTER ACTIVITY) / dimulai dengan dash + ada kata "master activity"
    if (stripos($s, '(Master Activity)') !== false || stripos($s, '(MASTER ACTIVITY)') !== false || stripos($s, 'master activity') !== false) {
        return [true, ''];
    }
    return [false, $s];
}

/* ✨ Helper: GROUP Activity LIST PER TANGGAL (sort tanggal ASC). Return array [dateISO=>[items...]] */
function repGroupActsByDate(&$list) {
    $grp = [];
    foreach ($list as $idx => $it) {
        $dt = trim((string)($it['date'] ?? ''));
        if ($dt === '' || strlen($dt) < 8) $dt = '0000-00-00';
        if (!isset($grp[$dt]) || !is_array($grp[$dt])) $grp[$dt] = [];
        $grp[$dt][] = $it;
    }
    ksort($grp, SORT_STRING); // 2026-08-07 datang sebelum 2026-08-18 (ASC)
    $ordered = [];
    foreach ($grp as $k => $arr) {
        if ($k === '0000-00-00') {
            $ordered['_nodate'] = $arr; // taruh PALING AKHIR nanti
        } else {
            $ordered[$k] = $arr;
        }
    }
    if (isset($ordered['_nodate'])) {
        $x = $ordered['_nodate']; unset($ordered['_nodate']); $ordered['_nodate'] = $x;
    }
    return $ordered;
}

/* ---------- 7. RENDER MODE ---------- */
$format = isset($_GET['format']) ? strtolower(cleanInput($_GET['format'])) : 'print';
$fileName = $isRange ? ('Engineering_Report_' . $reportDateFrom . '_to_' . $reportDateTo) : ('Engineering_Report_' . $reportDate);

/* ---------- HELPER ESCAPE CSV ---------- */
function repCsvEscape($v) {
    $v = (string)$v;
    $v = str_replace(["\r\n","\r"], "\n", $v);
    $v = preg_replace('/\s+/u', ' ', $v);
    $v = trim($v);
    if (strpos($v, ',') !== false || strpos($v, ';') !== false || strpos($v, "\n") !== false
        || strpos($v, '"') !== false || strpos($v, '=') === 0 || strpos($v, '@') === 0) {
        return '"' . str_replace('"', '""', $v) . '"';
    }
    return $v;
}

if ($format === 'excel') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF";

    $sep = ';';
    $out = '';

    /* HEADER JUDUL LAPORAN (POLOS TANPA NAMA HOTEL!) */
    $out .= 'ENGINEERING REPORT' . $sep . $sep . $sep . $sep . $sep . "\n";
    $out .= 'DATE' . $sep . $reportDateLabel . $sep . $sep . $sep . $sep . $sep . "\n";
    $out .= "\n";

    /* 1. KPIs */
    $out .= '1. KEY PERFORMANCE INDICATORS (KPIs)' . "\n";
    $out .= repCsvEscape('METRIC') . $sep
          . repCsvEscape('LAST YEAR (LY)') . $sep
          . repCsvEscape('TODAY') . $sep
          . repCsvEscape('ITR') . $sep
          . repCsvEscape('M&U') . $sep
          . repCsvEscape('GITB RANK') . "\n";
    foreach ($kpiData as $r) {
        $out .= repCsvEscape($r[0]) . $sep
              . repCsvEscape($r[1]) . $sep
              . repCsvEscape($r[2]) . $sep
              . repCsvEscape($r[3]) . $sep
              . repCsvEscape($r[4]) . $sep
              . repCsvEscape($r[5]) . "\n";
    }
    $out .= "\n";

    /* 2. UTILITY USAGE SUMMARY */
    $out .= '2. UTILITY USAGE SUMMARY' . "\n";
    $out .= repCsvEscape('UTILITY') . $sep
          . repCsvEscape('PERIOD') . $sep
          . repCsvEscape('USAGE') . $sep
          . repCsvEscape('COST (Rp.)') . "\n";

    $utilCsvRows = [
        ['ELECTRICITY', 'kWh',   $elecLY,   $elecToday,   $TARIF_LISTRIK],
        ['WATER',       'm3',    $waterLY,  $waterToday,  $TARIF_AIR],
        ['GAS',         'kg',    $gasLY,    $gasToday,    $TARIF_GAS],
        ['FUEL',        'Liter', $fuelLY,   $fuelToday,   $TARIF_FUEL],
    ];
    foreach ($utilCsvRows as $row) {
        list($name, $unit, $ly, $today, $perUnit) = $row;
        $usageLY = repFmtIndo($ly, 0) . ' ' . $unit;
        $usageToday = repFmtIndo($today, 0) . ' ' . $unit;
        $costLY = repFmtRupiah($ly * $perUnit);
        $costToday = repFmtRupiah($today * $perUnit);
        $out .= repCsvEscape($name) . $sep . repCsvEscape('(LY)') . $sep
              . repCsvEscape($usageLY) . $sep . repCsvEscape($costLY) . "\n";
        $out .= $sep . repCsvEscape('(TODAY)') . $sep
              . repCsvEscape($usageToday) . $sep . repCsvEscape($costToday) . "\n";
    }
    $out .= "\n";

    /* 3. ENGINEERING ACTIVITIES (100% MATCH DASHBOARD + counter per divisi) */
    $out .= '3. ENGINEERING ACTIVITIES' . "\n";
    $out .= repCsvEscape('DEPARTMENT') . $sep
          . repCsvEscape('ACTIVITY DETAIL') . $sep
          . repCsvEscape('STATUS') . "\n";
    foreach ($divisions as $d) {
        $list = $actByDiv[$d] ?? [];
        $stN = repCountActStatus($list);
        /* Baris 1 divisi: Dept + summary counter status */
        $stSummary = '';
        if (count($list) === 0) $stSummary = '- No Data -';
        else {
            if ($stN['prog'] > 0) $stSummary .= 'In Progress (' . $stN['prog'] . ')  ';
            if ($stN['done'] > 0) $stSummary .= 'Complete (' . $stN['done'] . ')';
            $stSummary = trim($stSummary);
        }
        if (count($list) === 0) {
            $out .= repCsvEscape($d) . $sep . repCsvEscape('Belum ada aktivitas bulan ini.') . $sep . repCsvEscape($stSummary) . "\n";
            continue;
        }
        // ✅ CSV juga PISAH GROUP Prog & Done (TIDAK KECAMPUR sama seperti tampilan web & dashboard)
        $listDoneCSV = []; $listProgCSV = [];
        foreach ($list as $itx) {
            $sx = (string)($itx['status'] ?? 'progress');
            if ($sx === 'complete' || $sx === 'completed') $listDoneCSV[] = $itx;
            else                                                $listProgCSV[] = $itx;
        }
        // Output BERJALAN DULU (sesuai urutan visual web), baru SELESAI, pakai header pemisah di detail
        $first = true;
        $flushedProgHeader = false; $flushedDoneHeader = false;
        if (count($listProgCSV) > 0) {
            foreach ($listProgCSV as $item) {
                $meta = [];
                if ($f = repFmtDateAct($item['date'] ?? '')) $meta[] = $f;
                if (trim((string)($item['eng'] ?? '')) !== '') $meta[] = (string)$item['eng'];
                $detail = (string)($item['name'] ?? '');
                if (count($meta) > 0) $detail .= '   [ ' . implode(' | ', $meta) . ' ]';
                $st = repStatusLabel($item['status'] ?? 'progress');
                if (!$flushedProgHeader) {
                    $out .= ($first ? repCsvEscape($d) . '  [' . $stSummary . ']' : '') . $sep
                          . repCsvEscape('▶▶ SEDANG BERJALAN (In Progress: ' . count($listProgCSV) . ')') . $sep
                          . repCsvEscape(($first ? $stSummary . '  |  ' : '') . '— GROUP —') . "\n";
                    $flushedProgHeader = true; $first = false;
                }
                $out .= ($first ? repCsvEscape($d) . '  [' . $stSummary . ']' : '') . $sep
                      . repCsvEscape('   • ' . $detail) . $sep
                      . repCsvEscape(($first ? $stSummary . '  |  ' : '') . $st) . "\n";
                $first = false;
            }
        }
        if (count($listDoneCSV) > 0) {
            foreach ($listDoneCSV as $item) {
                $meta = [];
                if ($f = repFmtDateAct($item['date'] ?? '')) $meta[] = $f;
                if (trim((string)($item['eng'] ?? '')) !== '') $meta[] = (string)$item['eng'];
                $detail = (string)($item['name'] ?? '');
                if (count($meta) > 0) $detail .= '   [ ' . implode(' | ', $meta) . ' ]';
                $st = repStatusLabel($item['status'] ?? 'progress');
                if (!$flushedDoneHeader) {
                    $out .= ($first ? repCsvEscape($d) . '  [' . $stSummary . ']' : '') . $sep
                          . repCsvEscape('■■ SELESAI / DONE (Complete: ' . count($listDoneCSV) . ')') . $sep
                          . repCsvEscape(($first ? $stSummary . '  |  ' : '') . '— GROUP —') . "\n";
                    $flushedDoneHeader = true; $first = false;
                }
                $out .= ($first ? repCsvEscape($d) . '  [' . $stSummary . ']' : '') . $sep
                      . repCsvEscape('   ✓ ' . $detail) . $sep
                      . repCsvEscape(($first ? $stSummary . '  |  ' : '') . $st) . "\n";
                $first = false;
            }
        }
    }
    $out .= "\n";

    echo $out;
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Engineering Report - <?=$reportDate?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
    * { box-sizing: border-box; }
    html, body { margin:0; padding:0; font-family:Arial,Helvetica,sans-serif; color:#000; background:#f3f4f6; font-size:14px; line-height:1.35; }
    @page { size: A4; margin: 14mm 12mm 12mm 12mm; }
    @media print {
        body { background:#fff; }
        .page-wrap { background:#fff!important; padding:0!important; margin:0!important; box-shadow:none!important; width:100%!important; max-width:100%!important; }
        .action-bar { display:none!important; }
        h1 { margin-top:0!important; }
        table { page-break-inside: avoid; }
    }
    .action-bar { max-width:210mm; margin:0 auto 12px; display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap; padding:0 8px; }
    .btn { display:inline-flex; align-items:center; gap:6px; padding:10px 16px; border-radius:10px; font-weight:700; font-size:13px; text-decoration:none; border:1px solid transparent; cursor:pointer; transition:all .15s; }
    .btn-excel { background:#16a34a; color:#fff; box-shadow:0 1px 2px rgba(22,163,74,.15); }
    .btn-excel:hover { background:#15803d; }
    .btn-pdf { background:#2563eb; color:#fff; box-shadow:0 1px 2px rgba(37,99,235,.15); }
    .btn-pdf:hover { background:#1d4ed8; }
    .page-wrap { width:210mm; max-width:100%; min-height:297mm; margin:0 auto; background:#fff; padding:10mm 12mm 12mm; box-shadow:0 6px 24px rgba(0,0,0,.08); }
    h1 { font-size:22px; letter-spacing:1.5px; text-align:center; margin:0 0 8px; font-weight:900; line-height:1.05; }
    .date-label { font-size:15px; font-weight:800; margin:0 0 14px; }
    h2 { font-size:15px; font-weight:900; margin:12px 0 6px; letter-spacing:.5px; }
    table { width:100%; border-collapse:collapse; }
    th { background:#d9d9d9; border:1px solid #000; padding:6px 8px; font-weight:800; text-align:center; font-size:12px; }
    td { border:1px solid #000; padding:5px 8px; font-size:12px; vertical-align:top; color:#000; }
    td.num { text-align:right; font-variant-numeric: tabular-nums; }
    td.cen { text-align:center; }
    td.bold { font-weight:800; }
    td.mid { vertical-align: middle; }
    ul.dot { margin:0; padding-left:14px; }
    ul.dot li { margin:0; padding:0; line-height:1.3; }
    .sign-footer { width:100%; margin-top:18px; display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; page-break-inside:avoid; }
    .sign-box { text-align:center; font-size:12px; }
    .sign-box .lbl { font-weight:600; margin-bottom:46px; }
    .sign-box .line { border-top:1px solid #000; padding-top:5px; font-weight:800; }
    /* ===== ENGINEERING ACTIVITIES (100% SAMA DASHBOARD CARD #2) ===== */
    table.act { border:1px solid #9ca3af; border-radius:10px; overflow:hidden;}
    table.act th { background:#e5e7eb; text-align:left; font-weight:800; letter-spacing:.08em; padding:9px 10px; font-size:12px; color:#111; }
    table.act th.dept-col { width: 20%; }
    table.act th.status-col { width: 22%; }
    table.act td { padding: 10px 10px; vertical-align:top; font-size: 12px; color:#111;}
    table.act td.dept { font-weight:900; font-size:13px; letter-spacing:.05em; }
    table.act td.op-bg { background:#eff6ff33; }
    table.act td.mt-bg { background:#fffbeb33; }
    table.act td.pr-bg { background:#f5f3ff33; }
    table.act td.la-bg { background:#ecfdf533; }
    .dept-ico { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:8px; color:#fff; font-size:12px; margin-right:8px;}
    .ico-op { background:#1d4ed8; }
    .ico-mt { background:#b45309; }
    .ico-pr { background:#6d28d9; }
    .ico-la { background:#047857; }
    .act-list { list-style:none; margin:0; padding:0;}
    .act-list li { padding:2px 0 5px 0; line-height:1.35;}
    .act-name { font-weight:700; color:#0f172a; margin-right:6px;}
    .meta { margin-top:3px; display:flex; gap:5px; flex-wrap:wrap;}
    .meta-tag { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700;}
    .meta-date { background:#f3f4f6; color:#4b5563; border:1px solid #e5e7eb;}
    .meta-eng { background:#eef2ff; color:#3730a3; border:1px solid #e0e7ff;}
    .st-group { display:flex; flex-direction:column; gap:5px; align-items:flex-end;}
    .st-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 10px 4px 8px; border-radius:999px; font-size:10.5px; font-weight:800; letter-spacing:.02em;}
    .st-nodata { padding:4px 11px; border-radius:999px; background:#f9fafb; color:#6b7280; border:1px solid #d1d5db; font-size:11px; font-weight:700;}
    .st-prog { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
    .st-done { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .st-pill .count { background:#fff; font-size:9.5px; padding:1px 6px; border-radius:999px; border:1px solid; font-weight:900;}
    .st-prog .count { border-color:#fde68a; color:#92400e;}
    .st-done .count { border-color:#a7f3d0; color:#065f46;}
    .empty-act { padding:4px 0; color:#9ca3af; font-style:italic;}
    .empty-act i { margin-right:6px; opacity:60%;}
    /* ===== GRUP PEMISAH STATUS (TIDAK KECAMPUR Done vs Progress) ===== */
    .act-group { margin: 0 0 9px 0; padding: 0;}
    .act-group-hdr { display:inline-flex; align-items:center; gap:5px; padding:2px 9px; border-radius:6px; font-size:10px; font-weight:900; letter-spacing:.06em; margin:2px 0 5px 0; text-transform: uppercase;}
    .act-group-hdr.prog { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa;}
    .act-group-hdr.done { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0;}
    .act-group-divider { border-top:1px dashed #d1d5db; margin:7px 0 7px 0; opacity:.8;}
    .act-list li.act-done .act-name { color:#4b5563; text-decoration: line-through; text-decoration-color: #10b981; text-decoration-thickness: 1.5px;}
    /* ===== ✨ GRUP PEMISAH PER-TANGGAL + BADGE MASTER ACTIVITY RAPI ===== */
    .meta-master { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-weight:900;}
    .meta-master i.fa-database { color:#2563eb;}
    .meta-eng-real { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0;}
    .date-group { margin: 0 0 7px 0; padding: 0 0 3px 0;}
    .date-group-hdr {
        display:inline-flex; align-items:center; gap:5px;
        padding:1.5px 10px 1.5px 8px; border-radius: 8px;
        font-size: 10px; font-weight: 900; letter-spacing: .04em;
        background:linear-gradient(90deg,#eef2ff,#e0e7ff); color:#3730a3;
        border:1px solid #c7d2fe; margin:0 0 4px 2px;
    }
    .date-group-hdr i.fa-calendar-day { font-size: 9.5px; color:#4338ca;}
    .date-group-sep { border-top:1px dotted #e5e7eb; margin:3px 0 5px 0; opacity:.9;}
    .date-group:last-child { margin-bottom: 2px; }
</style>
</head>
<body>
<div class="action-bar">
    <a class="btn btn-excel" href="?<?=$qsRange?>&format=excel" target="_blank"><i class="fa-solid fa-file-excel"></i> Download Excel</a>
    <button type="button" class="btn btn-pdf" onclick="window.print()"><i class="fa-solid fa-file-pdf"></i> Save as PDF / Print</button>
</div>
<div class="page-wrap">
    <h1>ENGINEERING<br>REPORT</h1>
    <div class="date-label">DATE: <?=$reportDateLabel?></div>

    <!-- ① KPIs -->
    <h2>1. KEY PERFORMANCE INDICATORS (KPIs)</h2>
    <table>
        <thead><tr><th>METRIC</th><th>LAST YEAR (LY)</th><th>TODAY</th><th>ITR</th><th>M&amp;U</th><th>GITB RANK</th></tr></thead>
        <tbody>
        <?php foreach ($kpiData as $r) { ?>
            <tr>
                <td class="bold"><?=htmlspecialchars($r[0])?></td>
                <td class="cen"><?=htmlspecialchars($r[1])?></td>
                <td class="cen"><?=htmlspecialchars($r[2])?></td>
                <td class="cen"><?=htmlspecialchars($r[3])?></td>
                <td class="cen"><?=htmlspecialchars($r[4])?></td>
                <td class="cen"><?=htmlspecialchars($r[5])?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <!-- ② UTILITY -->
    <h2>2. UTILITY USAGE SUMMARY</h2>
    <table>
        <thead><tr><th>UTILITY</th><th>PERIOD</th><th>USAGE</th><th>COST (Rp.)</th></tr></thead>
        <tbody>
            <tr>
                <td rowspan="2" class="bold cen mid">ELECTRICITY</td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($elecLY, 0)?> kWh</td>
                <td class="num"><?=repFmtRupiah($elecLY * $TARIF_LISTRIK)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num"><?=repFmtIndo($elecToday, 0)?> kWh</td>
                <td class="num"><?=repFmtRupiah($elecToday * $TARIF_LISTRIK)?></td>
            </tr>
            <tr>
                <td rowspan="2" class="bold cen mid">WATER</td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($waterLY, 0)?> m&sup3;</td>
                <td class="num"><?=repFmtRupiah($waterLY * $TARIF_AIR)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num"><?=repFmtIndo($waterToday, 0)?> m&sup3;</td>
                <td class="num"><?=repFmtRupiah($waterToday * $TARIF_AIR)?></td>
            </tr>
            <tr>
                <td rowspan="2" class="bold cen mid">GAS</td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($gasLY, 0)?> kg</td>
                <td class="num"><?=repFmtRupiah($gasLY * $TARIF_GAS)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num"><?=repFmtIndo($gasToday, 0)?> kg</td>
                <td class="num"><?=repFmtRupiah($gasToday * $TARIF_GAS)?></td>
            </tr>
            <tr>
                <td rowspan="2" class="bold cen mid">FUEL</td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($fuelLY, 0)?> Liter</td>
                <td class="num"><?=repFmtRupiah($fuelLY * $TARIF_FUEL)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num"><?=repFmtIndo($fuelToday, 0)?> Liter</td>
                <td class="num"><?=repFmtRupiah($fuelToday * $TARIF_FUEL)?></td>
            </tr>
        </tbody>
    </table>

    <!-- ③ ENGINEERING ACTIVITIES (100% MATCH DASHBOARD CARD #2 + RANGE DATE BUKAN SINGLE) -->
    <h2>3. ENGINEERING ACTIVITIES</h2>
    <table class="act">
        <colgroup>
            <col class="dept-col"><col><col class="status-col">
        </colgroup>
        <thead>
            <tr><th class="dept-col">DEPARTMENT</th><th>ACTIVITY DETAIL</th><th class="status-col">STATUS</th></tr>
        </thead>
        <tbody>
        <?php
        $bgMap   = ['OPERATION'=>'op-bg','MAINTENANCE'=>'mt-bg','PROJECT'=>'pr-bg','LANDSCAPE'=>'la-bg'];
        $bgIco   = ['OPERATION'=>'ico-op','MAINTENANCE'=>'ico-mt','PROJECT'=>'ico-pr','LANDSCAPE'=>'ico-la'];
        $nameIco = ['OPERATION'=>'fa-gears','MAINTENANCE'=>'fa-wrench','PROJECT'=>'fa-clipboard-list','LANDSCAPE'=>'fa-seedling'];
        foreach ($divisions as $d) {
            $list = $actByDiv[$d] ?? [];
            $stN = repCountActStatus($list);
            $bg    = $bgMap[$d]   ?? '';
            $bico  = $bgIco[$d]   ?? 'ico-op';
            $nicon = $nameIco[$d] ?? 'fa-list';
        ?>
            <tr>
                <td class="dept <?=$bg?>">
                    <span class="dept-ico <?=$bico?>"><i class="fa-solid <?=$nicon?>"></i></span><?=htmlspecialchars($d)?>
                </td>
                <td class="<?=$bg?>">
                    <?php if (count($list) === 0): ?>
                        <span class="empty-act"><i class="fa-solid fa-box-open"></i>Belum ada aktivitas bulan ini.</span>
                    <?php else: ?>
                        <?php
                        // ✅ PISAHKAN Done vs Progress (TIDAK KECAMPUR) — sama persis dengan dashboard
                        $listDone = []; $listProg = [];
                        foreach ($list as $itx) {
                            $sx = (string)($itx['status'] ?? 'progress');
                            if ($sx === 'complete' || $sx === 'completed') $listDone[] = $itx;
                            else                                                 $listProg[] = $itx;
                        }
                        $hProg = count($listProg) > 0;
                        $hDone = count($listDone) > 0;
                        ?>
                        <div style="margin-bottom:2px;">
                            <?php if ($hProg): ?>
                                <div class="act-group">
                                    <div class="act-group-hdr prog">
                                        <i class="fa-solid fa-spinner" style="font-size:9px;opacity:.8;"></i>
                                        Sedang Berjalan <span style="opacity:.7;">(<?= count($listProg) ?>)</span>
                                    </div>
                                    <?php
                                    // ✨ KELOMPOKKAN PER TANGGAL (biar 07 Aug / 18 Aug TIDAK NYAMPUR)
                                    $grpProg = repGroupActsByDate($listProg);
                                    $firstProgGrp = true;
                                    foreach ($grpProg as $dtISO => $itemsInDate):
                                        $isNoDate = ($dtISO === '_nodate');
                                        $fHeader = $isNoDate ? '' : repFmtDateAct($dtISO);
                                        if (!$firstProgGrp):
                                    ?>
                                        <div class="date-group-sep"></div>
                                    <?php endif; $firstProgGrp = false; ?>
                                    <div class="date-group">
                                        <?php if ($fHeader !== ''): ?>
                                            <span class="date-group-hdr">
                                                <i class="fa-solid fa-calendar-day"></i>
                                                📅 Tanggal: <?= htmlspecialchars($fHeader) ?>
                                                <span style="background:#fff; padding:0 6px; border-radius:999px; border:1px solid #c7d2fe; color:#4338ca; font-size:9px; font-weight:900;"><?= count($itemsInDate) ?> item</span>
                                            </span>
                                        <?php endif; ?>
                                        <ul class="act-list">
                                        <?php foreach ($itemsInDate as $item):
                                            $name = (string)($item['name'] ?? '');
                                            $date = (string)($item['date'] ?? '');
                                            $engRaw  = trim((string)($item['eng'] ?? ''));
                                            $fDate = $isNoDate ? repFmtDateAct($date) : '';
                                            list($isMaster, $cleanEng) = repSplitMasterEng($engRaw);
                                        ?>
                                            <li>
                                                <span class="act-name">&bull; <?=htmlspecialchars($name)?></span>
                                                <?php if ($fDate !== '' || $cleanEng !== '' || $isMaster): ?>
                                                    <div class="meta">
                                                        <?php if ($fDate !== ''): ?>
                                                            <span class="meta-tag meta-date"><i class="fa-solid fa-calendar" style="font-size:9px;opacity:.7;"></i> <?=htmlspecialchars($fDate)?></span>
                                                        <?php endif; ?>
                                                        <?php if ($isMaster): ?>
                                                            <span class="meta-tag meta-master"><i class="fa-solid fa-database" style="font-size:9px;"></i> MASTER TEMPLATE</span>
                                                        <?php endif; ?>
                                                        <?php if ($cleanEng !== ''): ?>
                                                            <span class="meta-tag meta-eng-real"><i class="fa-solid fa-user-hard-hat" style="font-size:9px;opacity:.85;"></i> <?=htmlspecialchars($cleanEng)?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <?php endforeach; /* end group date prog */ ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($hProg && $hDone): ?>
                                <div class="act-group-divider"></div>
                            <?php endif; ?>

                            <?php if ($hDone): ?>
                                <div class="act-group">
                                    <div class="act-group-hdr done">
                                        <i class="fa-solid fa-circle-check" style="font-size:9px;opacity:.85;"></i>
                                        Selesai / Done <span style="opacity:.7;">(<?= count($listDone) ?>)</span>
                                    </div>
                                    <?php
                                    $grpDone = repGroupActsByDate($listDone);
                                    $firstDoneGrp = true;
                                    foreach ($grpDone as $dtISO => $itemsInDate):
                                        $isNoDate = ($dtISO === '_nodate');
                                        $fHeader = $isNoDate ? '' : repFmtDateAct($dtISO);
                                        if (!$firstDoneGrp):
                                    ?>
                                        <div class="date-group-sep"></div>
                                    <?php endif; $firstDoneGrp = false; ?>
                                    <div class="date-group">
                                        <?php if ($fHeader !== ''): ?>
                                            <span class="date-group-hdr">
                                                <i class="fa-solid fa-calendar-day"></i>
                                                📅 Tanggal: <?= htmlspecialchars($fHeader) ?>
                                                <span style="background:#fff; padding:0 6px; border-radius:999px; border:1px solid #c7d2fe; color:#4338ca; font-size:9px; font-weight:900;"><?= count($itemsInDate) ?> item</span>
                                            </span>
                                        <?php endif; ?>
                                        <ul class="act-list">
                                        <?php foreach ($itemsInDate as $item):
                                            $name = (string)($item['name'] ?? '');
                                            $date = (string)($item['date'] ?? '');
                                            $engRaw  = trim((string)($item['eng'] ?? ''));
                                            $fDate = $isNoDate ? repFmtDateAct($date) : '';
                                            list($isMaster, $cleanEng) = repSplitMasterEng($engRaw);
                                        ?>
                                            <li class="act-done">
                                                <span class="act-name">&bull; <?=htmlspecialchars($name)?></span>
                                                <?php if ($fDate !== '' || $cleanEng !== '' || $isMaster): ?>
                                                    <div class="meta">
                                                        <?php if ($fDate !== ''): ?>
                                                            <span class="meta-tag meta-date"><i class="fa-solid fa-calendar" style="font-size:9px;opacity:.7;"></i> <?=htmlspecialchars($fDate)?></span>
                                                        <?php endif; ?>
                                                        <?php if ($isMaster): ?>
                                                            <span class="meta-tag meta-master"><i class="fa-solid fa-database" style="font-size:9px;"></i> MASTER TEMPLATE</span>
                                                        <?php endif; ?>
                                                        <?php if ($cleanEng !== ''): ?>
                                                            <span class="meta-tag meta-eng-real"><i class="fa-solid fa-user-hard-hat" style="font-size:9px;opacity:.85;"></i> <?=htmlspecialchars($cleanEng)?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <?php endforeach; /* end group date done */ ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="status-col <?=$bg?>" style="text-align:right;">
                    <?php if (count($list) === 0): ?>
                        <span class="st-nodata">&ndash; No Data &ndash;</span>
                    <?php else: ?>
                        <div class="st-group">
                            <?php if ($stN['prog'] > 0): ?>
                                <span class="st-pill st-prog">
                                    <i class="fa-solid fa-spinner" style="font-size:9.5px;opacity:.8;"></i>
                                    In Progress <span class="count"><?=$stN['prog']?></span>
                                </span>
                            <?php endif; ?>
                            <?php if ($stN['done'] > 0): ?>
                                <span class="st-pill st-done">
                                    <i class="fa-solid fa-circle-check" style="font-size:9.5px;opacity:.8;"></i>
                                    Complete <span class="count"><?=$stN['done']?></span>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

</div>
</body>
</html>
