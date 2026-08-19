<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/config/config.php';
requireLogin();

$db = Database::getInstance();
$user = currentUser();
$userName = (string)($user['name']  ?? 'User');
$userRole = (string)($user['role']  ?? 'engineer');
$userId = (int)($user['id']      ?? 0);

/* ---------- 💰 POST HANDLER: SAVE TARIFF (Supervisor / Manager / Admin only) ---------- */
$_tariffFlash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_tariff') {
    if (in_array($userRole, ['supervisor','manager','admin'], true)) {
        $clean = [
            'electricity_per_kwh' => (int)($_POST['electricity_per_kwh'] ?? 0),
            'water_per_m3'        => (int)($_POST['water_per_m3']        ?? 0),
            'gas_per_kg'          => (int)($_POST['gas_per_kg']          ?? 0),
            'fuel_per_liter'      => (int)($_POST['fuel_per_liter']      ?? 0),
        ];
        saveTariffSettings($clean, $userId);
        $_tariffFlash = 'Tarif berhasil diperbarui! Cost otomatis dihitung ulang.';
    }
}

/* ---------- ALLOW CUSTOM DATE RANGE VIA GET ?date_from=&date_to= ---------- */
$defToday    = date('Y-m-d');
$defMonthSt  = date('Y-m-01');
$today       = $defToday;
$monthStart  = $defMonthSt;
if (isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_from'])) {
    $monthStart = (string)$_GET['date_from'];
    $today      = (string)$_GET['date_from'];
}
if (isset($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_to'])) {
    $today = (string)$_GET['date_to'];
}
if (strtotime($monthStart) > strtotime($today)) {
    $tmp = $monthStart; $monthStart = $today; $today = $tmp;
}
/* Inisialisasi $dateFrom/$dateTo untuk KPI section (bisa di-custom via ?date_from=&date_to=, fallback = bulan ini s/d hari ini) */
$dateFrom = (isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_from'])) ? (string)$_GET['date_from'] : $monthStart;
$dateTo   = (isset($_GET['date_to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_to']))   ? (string)$_GET['date_to']   : $today;
if (strtotime($dateFrom) > strtotime($dateTo)) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }
$lastYear = date('Y', strtotime('-1 year'));
$currentYear = date('Y');
$sameDayLastYear = date('Y-m-d', strtotime($today . ' -1 year'));

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

/* ============================================================
 * 🔗 HELPER: MERGE DATA UTILITY DARI daily_logs + energy_logs
 * (Data user input di energy_logsheet.php disimpan ke energy_logs,
 *  TAPI dashboard sebelumnya HANYA baca daily_logs → fix gabungkan!)
 * Aggregation: 'SUM' atau 'AVG'
 * Return: [elec, water, gas, fuel, cnt, cnt_en]
 * ============================================================ */
function utilFetchBoth_Db($db, $approvedWhereDaily, $userId, $userRole, $dateFrom, $dateTo, $agg = 'SUM', $fallbackTariffs = null) {
    $isSum = ($agg !== 'AVG');
    $aggFn = $isSum ? 'SUM' : 'AVG';
    $aggFnNull = $isSum ? 'SUM' : 'AVG';
    $cntFn  = 'COUNT';
    if (!is_array($fallbackTariffs)) $fallbackTariffs = ['electricity_per_kwh'=>1850,'water_per_m3'=>9600,'gas_per_kg'=>24500,'fuel_per_liter'=>17450];
    $ftEL = (int)($fallbackTariffs['electricity_per_kwh'] ?? 1850);
    $ftWA = (int)($fallbackTariffs['water_per_m3']       ?? 9600);
    $ftGA = (int)($fallbackTariffs['gas_per_kg']         ?? 24500);
    $ftFU = (int)($fallbackTariffs['fuel_per_liter']     ?? 17450);
    $debug = (isset($_GET['_dbg']) && currentUser() && in_array((string)(currentUser()['role'] ?? ''), ['manager','admin','supervisor'], true));

    /* --- (A) daily_logs (primary legacy) --- */
    try {
        $sqlD = "SELECT
            COALESCE($aggFn(CAST(total_electricity AS DECIMAL(18,4))),0) as elec,
            COALESCE($aggFn(CAST(total_water AS DECIMAL(18,4))),0) as water,
            COALESCE($aggFn(CAST(total_gas AS DECIMAL(18,4))),0) as gas,
            COALESCE($aggFn(CAST(total_fuel AS DECIMAL(18,4))),0) as fuel,
            COALESCE($cntFn(*),0) as cnt,
            /* ✅ COST using PER-LOG TARIFF SNAPSHOT (jika ada), fallback global tariff (backward compat data lama) */
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
        if ($debug) { echo "<div style='display:none' class='_dbg_merge'>DAILY: SQL=$sqlD | params=$dateFrom,$dateTo | result=".json_encode($d)."</div>\n"; }
    } catch (Throwable $e) {
        $d = ['elec'=>0,'water'=>0,'gas'=>0,'fuel'=>0,'cnt'=>0,'cost_elec'=>0,'cost_water'=>0,'cost_gas'=>0,'cost_fuel'=>0];
        if ($debug) { echo "<div style='display:none' class='_dbg_merge'>DAILY ERROR: ".$e->getMessage()."</div>\n"; }
        else { error_log('utilFetchBoth_Db daily_logs ERROR: '.$e->getMessage()); }
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
        if ($debug) { echo "<div style='display:none' class='_dbg_merge'>ENERGY: SQL=$sqlE | params=".json_encode($pE)." | result=".json_encode($e)."</div>\n"; }
    } catch (Throwable $e) {
        $e = ['elec'=>0,'water'=>0,'gas'=>0,'fuel'=>0,'cnt'=>0,'cost_elec'=>0,'cost_water'=>0,'cost_gas'=>0,'cost_fuel'=>0];
        if ($debug) { echo "<div style='display:none' class='_dbg_merge'>ENERGY ERROR: ".$e->getMessage()."</div>\n"; }
        else { error_log('utilFetchBoth_Db energy_logs ERROR: '.$e->getMessage()); }
    }
    if (!$e) $e = ['elec'=>0,'water'=>0,'gas'=>0,'fuel'=>0,'cnt'=>0,'cost_elec'=>0,'cost_water'=>0,'cost_gas'=>0,'cost_fuel'=>0];
    $e['elec']=(float)($e['elec']??0); $e['water']=(float)($e['water']??0);
    $e['gas']=(float)($e['gas']??0); $e['fuel']=(float)($e['fuel']??0);
    $e['cnt']=(int)($e['cnt']??0);
    $e['cost_elec']=(float)($e['cost_elec']??0); $e['cost_water']=(float)($e['cost_water']??0);
    $e['cost_gas']=(float)($e['cost_gas']??0); $e['cost_fuel']=(float)($e['cost_fuel']??0);

    /* --- (C) MERGE: PRIORITY DAILY_LOGS (hindari DOUBLE COUNT karena user bisa input di 2 form!) --- */
    /*     RULE FIX 2026-08-18 (Bug Air 59651):
             - JIKA daily_logs SUDAH PUNYA NILAI NONZERO (total_xx > 0) = ambil HANYA DARI daily_logs (karena dihitung Today-Yesterday akurat)
             - JIKA daily_logs = 0 / TIDAK ADA DATA = baru pakai energy_logs (user hanya input di energy_logsheet)
             - INI JAUH LEBIH AMAN, menghindari kasus user input METER READING (bukan selisih) di energy_logsheet → TIDAK BOLEH dijumlah!
             - Berlaku untuk Elec, Water, Gas, Fuel BESERTA COST masing-masing (cost juga pakai aturan sama persis) */
    if ($isSum) {
        $pickD_elec = ($d['elec']  > 0.00001);
        $pickD_water = ($d['water'] > 0.00001);
        $pickD_gas = ($d['gas']   > 0.00001);
        $pickD_fuel = ($d['fuel']  > 0.00001);
        // Cost ikut priority sumber yang sama dengan utility-nya (jika pickD_elec → cost_elec dari D, jika tidak dari E)
        $out = [
            'elec'  => $pickD_elec  ? (float)$d['elec']  : (float)($d['elec']  + $e['elec']),
            'water' => $pickD_water ? (float)$d['water'] : (float)($d['water'] + $e['water']),
            'gas'   => $pickD_gas   ? (float)$d['gas']   : (float)($d['gas']   + $e['gas']),
            'fuel'  => $pickD_fuel  ? (float)$d['fuel']  : (float)($d['fuel']  + $e['fuel']),
            'cnt'   => (int)($d['cnt']   + $e['cnt']),
            'cnt_d' => (int)$d['cnt'],
            'cnt_e' => (int)$e['cnt'],
            'cost_elec'  => $pickD_elec  ? (float)$d['cost_elec']  : (float)($d['cost_elec']  + $e['cost_elec']),
            'cost_water' => $pickD_water ? (float)$d['cost_water'] : (float)($d['cost_water'] + $e['cost_water']),
            'cost_gas'   => $pickD_gas   ? (float)$d['cost_gas']   : (float)($d['cost_gas']   + $e['cost_gas']),
            'cost_fuel'  => $pickD_fuel  ? (float)$d['cost_fuel']  : (float)($d['cost_fuel']  + $e['cost_fuel']),
        ];
        // Debug marker (bisa dibuka lewat _dbg_merge): simpan info priority per utility
        $out['_prio_elec']  = $pickD_elec  ? 'daily_logs' : 'merged_or_energy';
        $out['_prio_water'] = $pickD_water ? 'daily_logs' : 'merged_or_energy';
        $out['_prio_gas']   = $pickD_gas   ? 'daily_logs' : 'merged_or_energy';
        $out['_prio_fuel']  = $pickD_fuel  ? 'daily_logs' : 'merged_or_energy';
    } else {
        /* AVG: jika daily_logs ada data yang nonzero → prefer daily_logs; else pilih energy_logs (hindari double count) */
        $pickDaily = ($d['elec'] > 0 || $d['water'] > 0 || $d['gas'] > 0 || $d['fuel'] > 0);
        $s = $pickDaily ? $d : $e;
        $out = [
            'elec'  => (float)$s['elec'],
            'water' => (float)$s['water'],
            'gas'   => (float)$s['gas'],
            'fuel'  => (float)$s['fuel'],
            'cnt'   => (int)$s['cnt'],
            'cnt_d' => (int)$d['cnt'],
            'cnt_e' => (int)$e['cnt'],
            'cost_elec'  => (float)$s['cost_elec'],
            'cost_water' => (float)$s['cost_water'],
            'cost_gas'   => (float)$s['cost_gas'],
            'cost_fuel'  => (float)$s['cost_fuel'],
        ];
    }
    /* --- (D) VALIDASI ANTI READING METER BULANAN / DATA SEBELUM SISTEM JALAN --- */
    /*     RULE FIX LY 2026-08-18:
             JIKA cnt_d = 0 (TIDAK ADA RECORD daily_logs SAMA SEKALI di range tanggal tsb)
             → KEMUNGKINAN BESAR: tanggal sebelum sistem beroperasi (misal tahun 2025 = sistem baru 2026)
             → energy_logs pasti diisi angka METER READING BULANAN (bukan selisih konsumsi HARIAN)
             → JANGAN DIPAKAI, SET NILAI UTILITY + COST = 0 SEMUA (hindari LY absurd 49.911 m3 lalu -99.2%) */
    if (((int)($out['cnt_d'] ?? 0)) === 0) {
        $out['elec'] = 0; $out['water'] = 0; $out['gas'] = 0; $out['fuel'] = 0;
        $out['cost_elec'] = 0; $out['cost_water'] = 0; $out['cost_gas'] = 0; $out['cost_fuel'] = 0;
        $out['_skip_reason'] = 'cnt_d_zero_energy_logs_meter_reading_skipped';
    }
    /* Backward compat: output key 'log_count' = cnt (biar line 101-104 lyXxxAvg TIDAK PERLU DIUBAH) */
    $out['log_count'] = max(1, (int)($out['cnt'] ?? 1));
    if ($debug) { echo "<div style='display:none' class='_dbg_merge'>FINAL ($agg) ".json_encode($out)."</div>\n"; }
    return $out;
}

// ============ INISIALISASI TARIF (dipindah ke ATAS agar semua utilFetchBoth_Db dapat fallback akurat) ============
$TARIF = getTariffSettings();
function fmtRupiah($n) { if ($n <= 0) return '0'; return number_format((int)round($n), 0, ',', '.'); }

// ============ â‘  UTILITY REPORT - LY (Last Year) vs TODAY ============
$lyFrom = (int)$lastYear . '-01-01'; $lyTo = (int)$lastYear . '-12-31';
$tyFrom = (int)$currentYear . '-01-01'; $tyTo = (int)$currentYear . '-12-31';
$utilLY    = utilFetchBoth_Db($db, $approvedWhere, $userId, $userRole, $lyFrom, $lyTo, 'SUM', $TARIF);
$utilToday = utilFetchBoth_Db($db, $approvedWhere, $userId, $userRole, $tyFrom, $tyTo, 'SUM', $TARIF);

/* todayElec/Water/Gas/Fuel per single date: MERGE kedua tabel */
$todayBoth = utilFetchBoth_Db($db, "status='approved' $statusWhere", $userId, $userRole, $today, $today, 'SUM', $TARIF);
$todayElec  = (float)($todayBoth['elec']  ?? 0);
$todayWater = (float)($todayBoth['water'] ?? 0);
$todayGas   = (float)($todayBoth['gas']   ?? 0);
$todayFuel  = (float)($todayBoth['fuel']  ?? 0);

/* todayDailySingle = HANYA dari daily_logs SAJA (khusus kolom TIDAK ADA di energy_logs: occ_rate, swro_*, chiller_*, bottling_*) */
try {
    $todayDailySingle = $db->fetchOne("SELECT * FROM daily_logs WHERE log_date = ? AND status='approved' $statusWhere ORDER BY id DESC LIMIT 1", [$today]);
} catch (\Throwable $ex) {
    $todayDailySingle = null;
}
if (empty($todayDailySingle)) $todayDailySingle = null;

$occLY = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE YEAR(log_date) = ? AND $approvedWhere AND occ_rate > 0", [$lastYear]);
$occToday = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE YEAR(log_date) = ? AND $approvedWhere AND occ_rate > 0", [$currentYear]);

$defaultTargetOcc = 80;
$defaultLyOcc = 70;
$lyOcc = ($occLY['cnt'] > 0) ? round((float)$occLY['avg_occ'], 0) : $defaultLyOcc;
$thisMonthOcc = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE log_date >= ? AND $approvedWhere AND occ_rate > 0", [$monthStart]);
$todayOccVal = (float)($todayDailySingle['occ_rate'] ?? 0);
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

// ✅ BARU: Utility Report LY • Avg/Day — ketemunya TANGGAL SAMA TAHUN LALU (bukan avg seluruh tahun lalu)
//    Misal: Hari ini = 17/08/2026 → LY = data tanggal 17/08/2025 (single date, bukan rata-rata 1 tahun)
$lySameDayBoth = utilFetchBoth_Db($db, "status='approved' $statusWhere", $userId, $userRole, $sameDayLastYear, $sameDayLastYear, 'SUM', $TARIF);
$lyElecAvg  = (float)($lySameDayBoth['elec']  ?? 0);
$lyWaterAvg = (float)($lySameDayBoth['water'] ?? 0);
$lyGasAvg   = (float)($lySameDayBoth['gas']   ?? 0);
$lyFuelAvg  = (float)($lySameDayBoth['fuel']  ?? 0);

// ============ â‘¡ ENG ACTIVITY - Counters bulan ini ============
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

$_mtBoth = utilFetchBoth_Db($db, "status = 'approved' $statusWhere", $userId, $userRole, $monthStart, $today, 'SUM', $TARIF);
$monthlyElectricity = (float)($_mtBoth['elec']  ?? 0);
$monthlyWater       = (float)($_mtBoth['water'] ?? 0);
$monthlyGas         = (float)($_mtBoth['gas']   ?? 0);
unset($_mtBoth);
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

// ============ â‘¡+ LIST DETAIL TEKS AKTIVITAS PEKERJAAN PER KATEGORI (ditampilkan di MODAL) ============
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

// 1) UTILITY TODAY & LY — KALKULASI VALUE + COST (SINGLE SOURCE OF TRUTH, TIDAK ADA DUPLIKAT!)
//    ✅ TODAY: 1 SUMBER SAJA = $todayBoth (L263) = single date HARI INI (usage + cost 100% KONSISTEN!)
//    ✅ LY (Last Year SAME DATE / SAME DAY) — 1 SUMBER SAJA = $lySameDayBoth (L299) = single date TAHUN LALU (sesuai label "LY • Avg/Day" & "LY Same Day" di card!)
//    ❌ SEBELUMNYA: ada $_tBoth & $_lBoth = CALL ULANG fungsi SAMA → hasil BEDA (Today Cost=0, LY Cost=479 JUTA dari angka meteran bulanan!)
$elecToday  = (float)($todayBoth['elec']  ?? 0);
$waterToday = (float)($todayBoth['water'] ?? 0);
$gasToday   = (float)($todayBoth['gas']   ?? 0);
$fuelToday  = (float)($todayBoth['fuel']  ?? 0);
$costElecToday  = (float)($todayBoth['cost_elec']  ?? 0);
$costWaterToday = (float)($todayBoth['cost_water'] ?? 0);
$costGasToday   = (float)($todayBoth['cost_gas']   ?? 0);
$costFuelToday  = (float)($todayBoth['cost_fuel']  ?? 0);

// ✅ LY = SINGLE DATE SAMA TAHUN LALU (18/08/26 → 18/08/25), BUKAN range bulan! (sesuai label LY Same Day / LY • Avg/Day)
//    Step D validasi anti meteran: kalau cnt_d=0 (sistem belum jalan tahun lalu) → otomatis LY = cost = 0 (tidak 479jt lagi!)
$elecLY  = (float)($lySameDayBoth['elec']  ?? 0);
$waterLY = (float)($lySameDayBoth['water'] ?? 0);
$gasLY   = (float)($lySameDayBoth['gas']   ?? 0);
$fuelLY  = (float)($lySameDayBoth['fuel']  ?? 0);
$costElecLY  = (float)($lySameDayBoth['cost_elec']  ?? 0);
$costWaterLY = (float)($lySameDayBoth['cost_water'] ?? 0);
$costGasLY   = (float)($lySameDayBoth['cost_gas']   ?? 0);
$costFuelLY  = (float)($lySameDayBoth['cost_fuel']  ?? 0);

// Helper: Display usage — rounding & unit
function uUsage($n, $unit, $dec=0) {
    if ($n <= 0) return "0 {$unit}";
    return number_format($n, $dec, ',', '.') . " {$unit}";
}

// 2) KPI TABLE — OCC % (LY, TODAY) + ITR / M&U Score / GITB RANK SOURCE: DB daily_logs (approved)
$occLYDisp = $lyOcc > 0 ? $lyOcc . '%' : (($lyOcc === '-') ? '-' : '0.0%');
$occNowDisp = $targetOcc > 0 ? $targetOcc . '%' : '0.0%';

// =========== KPI DATA: PRIORITAS DARI DB DAILY_LOGS PERIODE ATAU HARI INI (approved saja) ===========
$_kpiFromDb = ['itr' => null, 'mu' => null, 'rank' => null];
// 1. Rata-rata periode dateFrom - dateTo (lebih akurat untuk range tanggal)
try {
    $_kpiPeriod = $db->fetchOne(
        "SELECT
            COALESCE(AVG(NULLIF(itr_score,0)),0) AS avg_itr,
            COALESCE(AVG(NULLIF(mu_score,0)),0)  AS avg_mu,
            COUNT(NULLIF(itr_score,0)) AS cnt_itr,
            COUNT(NULLIF(mu_score,0))  AS cnt_mu
         FROM daily_logs
         WHERE status = 'approved' $statusWhere
           AND log_date BETWEEN ? AND ?",
        [$dateFrom, $dateTo]
    );
    if ($_kpiPeriod && $_kpiPeriod['cnt_itr'] > 0) $_kpiFromDb['itr'] = (float)$_kpiPeriod['avg_itr'];
    if ($_kpiPeriod && $_kpiPeriod['cnt_mu']  > 0) $_kpiFromDb['mu']  = (float)$_kpiPeriod['avg_mu'];
    // Rank: ambil dari last approved (paling baru periode ini) non-null non-zero
    $_kpiRankRow = $db->fetchOne(
        "SELECT gitb_rank FROM daily_logs
         WHERE status='approved' $statusWhere
           AND log_date BETWEEN ? AND ?
           AND gitb_rank IS NOT NULL AND gitb_rank > 0
         ORDER BY log_date DESC, id DESC LIMIT 1",
        [$dateFrom, $dateTo]
    );
    if ($_kpiRankRow && !empty($_kpiRankRow['gitb_rank'])) $_kpiFromDb['rank'] = (int)$_kpiRankRow['gitb_rank'];
} catch (\Throwable $ex) { /* ignore migration not ready */ }
// 2. Fallback: Hari ini single approved (jika periode belum ada data)
if (($_kpiFromDb['itr'] === null || $_kpiFromDb['mu'] === null || $_kpiFromDb['rank'] === null) && !empty($todayDailySingle)) {
    $_td = $todayDailySingle;
    if ($_kpiFromDb['itr'] === null && isset($_td['itr_score']) && $_td['itr_score'] > 0) $_kpiFromDb['itr'] = (float)$_td['itr_score'];
    if ($_kpiFromDb['mu']  === null && isset($_td['mu_score'])  && $_td['mu_score']  > 0) $_kpiFromDb['mu']  = (float)$_td['mu_score'];
    if ($_kpiFromDb['rank']=== null && isset($_td['gitb_rank']) && $_td['gitb_rank'] > 0) $_kpiFromDb['rank']= (int)$_td['gitb_rank'];
}
// 3. Fallback AKHIR: Placeholder heuristik (jika TIDAK ADA input KPI di form sama sekali - data lama sebelum ada field)
$kpiItr  = $_kpiFromDb['itr']  !== null ? number_format($_kpiFromDb['itr'], 1, '.', '') : ($lyOcc > 0 ? number_format(85 + ($targetOcc - $lyOcc) * 0.2, 1, '.', '') : '-');
$kpiMnU  = $_kpiFromDb['mu']   !== null ? number_format($_kpiFromDb['mu'],  1, '.', '') : ($lyOcc > 0 ? number_format(78 + (($targetOcc - $lyOcc) * 0.3), 1, '.', '') : '-');
$kpiRank = $_kpiFromDb['rank'] !== null ? (string)$_kpiFromDb['rank']                    : ($targetOcc >= 90 ? '5' : ($targetOcc >= 80 ? '4' : ($targetOcc >= 70 ? '3' : '-')));
unset($_kpiFromDb, $_kpiPeriod, $_kpiRankRow);

// 3) ACTIVITIES GROUP per Department â€” untuk TABLE â‘¢ bullet list + Status badge
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

/* ✨ Helper: Pisahkan tag MASTER ACTIVITY dari Nama Engineer */
function dashSplitMasterEng($engRaw) {
    $s = trim((string)$engRaw);
    if ($s === '' || $s === '-') return [false, ''];
    if (stripos($s, '(Master Activity)') !== false || stripos($s, '(MASTER ACTIVITY)') !== false || stripos($s, 'master activity') !== false) {
        return [true, ''];
    }
    return [false, $s];
}
/* ✨ Helper: GROUP LIST ACTIVITY PER TANGGAL sort ASC */
function dashGroupByDate(&$list) {
    $grp = [];
    foreach ($list as $it) {
        $dt = trim((string)($it['date'] ?? ''));
        if ($dt === '' || strlen($dt) < 8) $dt = '0000-00-00';
        if (!isset($grp[$dt]) || !is_array($grp[$dt])) $grp[$dt] = [];
        $grp[$dt][] = $it;
    }
    ksort($grp, SORT_STRING);
    $ordered = [];
    foreach ($grp as $k => $arr) {
        if ($k === '0000-00-00') $ordered['_nodate'] = $arr;
        else $ordered[$k] = $arr;
    }
    if (isset($ordered['_nodate'])) { $x = $ordered['_nodate']; unset($ordered['_nodate']); $ordered['_nodate'] = $x; }
    return $ordered;
}
$actsGRP = [
    'operation'   => actGroupWithStatus($actListOp),
    'maintenance' => actGroupWithStatus($actListMaint),
    'project'     => actGroupWithStatus($actListProj),
    'landscape'   => actGroupWithStatus($actListLand),
];

// ============== 🔹 TAMBAHAN: Merge data activity_counter 4 JSON kolom ke actsGRP (counter header) 🔹 ==============
// Tujuan: Master Activity yang dipilih user via COUNTER 4 KATEGORI (disimpan di daily_logs.activity_*_items JSON)
//         SEKARANG MUNCUL DENGAN NAMA ENGINEER YANG BENAR (bukan "- (Master Activity)").
//         Sebelumnya: aktivitas counter ini TIDAK di-merge ke actsGRP, jadi blok di bawah menambah dari tabel
//         activity_masters GLOBAL dengan label eng="-". Sekarang di-merge dulu agar cek existing title ke-detect.
try {
    $roleWhereAct = '';
    $actParams = [];
    if ($userRole === 'engineer') {
        $roleWhereAct = ' AND dl.engineer_id = ?';
        $actParams[] = $userId;
    }
    $actColMap = [
        'operation'   => 'activity_operation_items',
        'maintenance' => 'activity_maintenance_items',
        'project'     => 'activity_project_items',
        'landscape'   => 'activity_landscape_items',
    ];
    $_jsonTitleUsed = [];
    foreach ($actColMap as $dv => $col) {
        if (!isset($actsGRP[$dv]) || !is_array($actsGRP[$dv])) $actsGRP[$dv] = [];
        foreach ($actsGRP[$dv] as $_r) { $_jsonTitleUsed[$dv][mb_strtolower(trim((string)($_r['title'] ?? '')))] = true; }
    }
    foreach ($actColMap as $dv => $col) {
        $sqlAct = "SELECT dl.id as dlid, dl.log_date, u.name as engineer_name, dl.$col as json_col
                    FROM daily_logs dl
                    LEFT JOIN users u ON u.id = dl.engineer_id
                    WHERE dl.status='approved' $roleWhereAct
                      AND dl.log_date BETWEEN ? AND ?
                      AND dl.$col IS NOT NULL AND dl.$col <> ''
                    ORDER BY dl.log_date DESC, dl.id DESC";
        $rowsAct = $db->fetchAll($sqlAct, array_merge($actParams, [$monthStart, $today]));
        foreach ($rowsAct as $_ra) {
            $_rawJson = (string)($_ra['json_col'] ?? '');
            if ($_rawJson === '') continue;
            $_arrAct = json_decode($_rawJson, true);
            if (!is_array($_arrAct) || count($_arrAct) === 0) continue;
            foreach ($_arrAct as $_ia) {
                if (!is_array($_ia)) continue;
                $_ttl = trim((string)($_ia['t'] ?? ''));
                if ($_ttl === '') continue;
                $_st  = (string)($_ia['s'] ?? 'progress');
                $_keyLower = mb_strtolower($_ttl);
                if (isset($_jsonTitleUsed[$dv][$_keyLower])) continue; // hindari dobel
                $_jsonTitleUsed[$dv][$_keyLower] = true;
                // Status rule = heuristik (sama dengan actGroupWithStatus)
                $_tl = mb_strtolower($_ttl);
                $_isProg = ($_st === 'progress')
                        || (strpos($_tl, 'progress') !== false) || (strpos($_tl, 'install') !== false)
                        || (strpos($_tl, 'perbaikan') !== false) || (strpos($_tl, 'new ') !== false)
                        || (strpos($_tl, 'buat') !== false)     || (strpos($_tl, 'meeting') !== false);
                $actsGRP[$dv][] = [
                    'title'  => $_ttl,
                    'status' => $_isProg ? 'progress' : 'complete',
                    'date'   => (string)($_ra['log_date'] ?? ''),
                    'eng'    => (string)($_ra['engineer_name'] ?? '-'),
                ];
            }
        }
    }
    unset($rowsAct, $_ra, $_rawJson, $_arrAct, $_ia, $_ttl, $_st, $_keyLower, $_tl, $_isProg);
} catch (Throwable $eAct) {}

// ============== ðŸ”¹ BARU: ENGINEERING ACTIVITIES YANG MASIH IN PROGRESS (Dashboard Widget) ðŸ”¹ ==============
// INI STATUS ASLI (BUKAN HEURISTIK DARI TITLE!) diambil dari 4 JSON kolom daily_logs.*_items
// Jika s = 'complete' otomatis HILANG dari widget ini; hanya s='progress' saja yang muncul.
function fetchProgressActivities($db, $userRole, $userId, $dateFrom, $dateTo) {
    $roleWhere = '';
    $params = [];
    if ($userRole === 'engineer') {
        $roleWhere = ' AND dl.engineer_id = ?';
        $params[] = $userId;
    }
    $colMap = [
        'operation'   => 'activity_operation_items',
        'maintenance' => 'activity_maintenance_items',
        'project'     => 'activity_project_items',
        'landscape'   => 'activity_landscape_items',
    ];
    $out = [];
    foreach ($colMap as $division => $col) {
        $sql = "SELECT dl.id as dlid, dl.log_date, u.name as engineer_name, dl.$col as json_col
                FROM daily_logs dl
                LEFT JOIN users u ON u.id = dl.engineer_id
                WHERE dl.status='approved' $roleWhere
                  AND dl.log_date BETWEEN ? AND ?
                  AND dl.$col IS NOT NULL AND dl.$col <> ''
                ORDER BY dl.log_date DESC, dl.id DESC";
        $all = $db->fetchAll($sql, array_merge($params, [$dateFrom, $dateTo]));
        foreach ($all as $row) {
            $raw = (string)($row['json_col'] ?? '');
            if ($raw === '') continue;
            $arr = json_decode($raw, true);
            if (!is_array($arr) || count($arr) === 0) continue;
            foreach ($arr as $item) {
                if (!is_array($item)) continue;
                $s = (string)($item['s'] ?? 'progress');
                if ($s !== 'progress') continue; // âœ… HANYA YANG IN PROGRESS SAJA
                $t = trim((string)($item['t'] ?? ''));
                if ($t === '') continue;
                $out[] = [
                    'division'     => $division,
                    'activity'     => $t,
                    'log_date'     => (string)($row['log_date'] ?? ''),
                    'engineer_name'=> (string)($row['engineer_name'] ?? '-'),
                    'daily_log_id' => (int)($row['dlid'] ?? 0),
                    'source'       => 'daily_log',
                ];
            }
        }
    }
    usort($out, function($a, $b) {
        if ($a['log_date'] === $b['log_date']) return 0;
        return ($a['log_date'] < $b['log_date']) ? 1 : -1;
    });
    return $out;
}
$divInfo = [
    'operation'   => ['label'=>'OPERATION',   'icon'=>'<i class="fas fa-gear"></i>',            'col'=>'text-slate-700', 'bg'=>'bg-slate-50', 'chip'=>'bg-slate-100 border-slate-200 text-slate-700'],
    'maintenance' => ['label'=>'MAINTENANCE', 'icon'=>'<i class="fas fa-wrench"></i>',          'col'=>'text-slate-700', 'bg'=>'bg-slate-50', 'chip'=>'bg-slate-100 border-slate-200 text-slate-700'],
    'project'     => ['label'=>'PROJECT',     'icon'=>'<i class="fas fa-diagram-project"></i>', 'col'=>'text-slate-700', 'bg'=>'bg-slate-50', 'chip'=>'bg-slate-100 border-slate-200 text-slate-700'],
    'landscape'   => ['label'=>'LANDSCAPE',   'icon'=>'<i class="fas fa-leaf"></i>',            'col'=>'text-slate-700', 'bg'=>'bg-slate-50', 'chip'=>'bg-slate-100 border-slate-200 text-slate-700'],
];
$progressActivities = fetchProgressActivities($db, $userRole, $userId, $monthStart, $today);

// ============== ðŸ”¹ TAMBAHAN: DATA MASTER DEFAULT IN PROGRESS (TABLE activity_masters) ðŸ”¹ ==============
// Sesuai request user: Master Activity status_default = 'progress' (seperti foto "Pemindahan posisi new FCU FB office - In Progress")
// yang BELUM dipakai di Daily Log juga harus MUNCUL di Dashboard Widget Progress!
try {
    $masterWhere = "WHERE status_default = 'progress'";
    $masterParams = [];
    if ($userRole === 'engineer') {
        // Engineer tidak punya filter master, tapi show semua biar tau daftar progress
    }
    $masterRows = $db->fetchAll("SELECT id as mid, division, activity_name, sort_order, created_at
                                  FROM activity_masters $masterWhere
                                  ORDER BY FIELD(division,'operation','maintenance','project','landscape'), sort_order ASC, id ASC", $masterParams);
    $titleUsedDaily = [];
    foreach ($progressActivities as $pd) { if ($pd['source'] === 'daily_log') $titleUsedDaily[mb_strtolower(trim((string)($pd['activity'] ?? '')))] = true; }
    $idxDaily = count($progressActivities);
    // ✅ Fallback nama engineer untuk progress widget master (belum ditugaskan): pakai nama user login
    $userNameForProgMaster = !empty($user['name']) ? (string)$user['name'] : '- (Belum ditugaskan)';
    foreach ($masterRows as $m) {
        $title = trim((string)($m['activity_name'] ?? ''));
        if ($title === '') continue;
        $keyLower = mb_strtolower($title);
        if (isset($titleUsedDaily[$keyLower])) continue; // skip kalau sudah muncul di daily log (hindari dobel)
        $idxDaily++;
        $progressActivities[] = [
            'division'      => (string)($m['division'] ?? 'operation'),
            'activity'      => $title,
            'log_date'      => (string)($m['created_at'] ?? ''),
            'engineer_name' => $userNameForProgMaster,
            'daily_log_id'  => 0,
            'source'        => 'master_template',
            'master_id'     => (int)($m['mid'] ?? 0),
            'sort_order'    => (int)($m['sort_order'] ?? 0),
        ];
    }
    // Re-sort: Daily Log paling atas (sort date desc), Master Template paling bawah urut sort_order ASC
    $dailyPart = []; $masterPart = [];
    foreach ($progressActivities as $pa) {
        if (($pa['source'] ?? 'daily_log') === 'master_template') $masterPart[] = $pa;
        else $dailyPart[] = $pa;
    }
    usort($masterPart, function($a,$b){
        $sa = (int)($a['sort_order'] ?? 0); $sb = (int)($b['sort_order'] ?? 0);
        if ($sa === $sb) return (int)($a['master_id'] ?? 0) - (int)($b['master_id'] ?? 0);
        return $sa - $sb;
    });
    $progressActivities = array_merge($dailyPart, $masterPart);
} catch (Throwable $e) {
    // jika table activity_masters belum ada, ignore
}
$progressCount = count($progressActivities);

// ============== ðŸ”¹ TAMBAHAN: MASUKKAN JUGA DATA MASTER DEFAULT PROGRESS KE actsGRP (Section 3 Engineering Activities) ðŸ”¹ ==============
// Tujuan: Section â‘¢ ENGINEERING ACTIVITIES (yang 3 kolom: Department / Detail / Status) TIDAK LAGI menampilkan "- No Data -"
//         padahal user sudah menambahkan Master Activity status_default='progress' seperti foto "Pemindahan posisi new FCU FB office In Progress"
try {
    $_tmpMastersAct = $db->fetchAll("SELECT division, activity_name, sort_order, created_at, status_default FROM activity_masters ORDER BY FIELD(division,'operation','maintenance','project','landscape'), sort_order ASC, id ASC");
    $_existingTitleAct = [];
    // ✅ Fallback engineer label untuk master activity yang benar-benar belum dipakai di log manapun:
    //    Pakai NAMA USER YANG SEDANG LOGIN (semua role, sesuai request: "siapa yg punya akun langsung jadi namanya dia yg mengetik")
    $userNameForMaster = !empty($user['name']) ? (string)$user['name'] : '- (Master Activity)';
    foreach (['operation','maintenance','project','landscape'] as $dv) {
        if (!isset($actsGRP[$dv]) || !is_array($actsGRP[$dv])) $actsGRP[$dv] = [];
        foreach ($actsGRP[$dv] as $_r) { $_existingTitleAct[$dv][mb_strtolower(trim((string)($_r['title'] ?? '')))] = true; }
    }
    foreach ($_tmpMastersAct as $_m) {
        $dv = (string)($_m['division'] ?? 'operation');
        if (!in_array($dv,['operation','maintenance','project','landscape'], true)) $dv = 'operation';
        if (!isset($actsGRP[$dv]) || !is_array($actsGRP[$dv])) $actsGRP[$dv] = [];
        $title = trim((string)($_m['activity_name'] ?? ''));
        if ($title === '') continue;
        $key = mb_strtolower($title);
        if (isset($_existingTitleAct[$dv][$key])) continue; // hindari dobel dengan daily log title (counter / child table)
        $st = (string)($_m['status_default'] ?? 'progress');
        $actsGRP[$dv][] = ['title' => $title, 'status' => ($st === 'complete' ? 'complete' : 'progress'), 'date' => (string)($_m['created_at'] ?? ''), 'eng' => $userNameForMaster];
        $_existingTitleAct[$dv][$key] = true;
    }
    unset($_tmpMastersAct, $_existingTitleAct, $dv, $_m, $title, $key, $st, $userNameForMaster);
} catch (Throwable $e) {}

// --- Reusable: render TABLE DAFTAR AKTIVITAS PEKERJAAN (untuk ditempel di bawah chart modal) ---
function renderActivityTable($list, $themeClass, $iconName, $emptyMsg, $labelSingular) {
    $html = '';
    if (is_array($list) && count($list) > 0) {
        $html .= '<div class="mt-8 rounded-2xl border border-gray-100 shadow-sm overflow-hidden">';
        $html .=   '<div class="px-5 py-3.5 bg-slate-50 border-b border-slate-200 flex items-center justify-between gap-3">';
        $html .=       '<h3 class="font-bold text-slate-800 flex items-center gap-2"><i class="fas fa-list-ul ' . htmlspecialchars($themeClass) . '"></i> DAFTAR ' . strtoupper($labelSingular) . ' PEKERJAAN BULAN INI</h3>';
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
            $html .=   '<td class="px-4 py-3 text-slate-800 font-semibold leading-relaxed">' . htmlspecialchars($nama) . '</td>';
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
    <div class="mb-6 animate-fade-in">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
            <div>
                <p class="text-[12px] text-slate-500 mb-1">
                    <i class="fas fa-calendar-day mr-1.5 text-slate-400"></i><?= formatDate($today) ?>
                </p>
                <h1 class="font-display text-xl lg:text-2xl font-bold text-slate-800 mb-0.5">
                    <?= T('wel_back', 'Selamat Datang') ?>, <?= $userRole === 'engineer' ? T('wel_role_engineer_brand', 'Engineering Department') : cleanInput($userName) ?>
                </h1>
                <p class="text-slate-500 text-[13px]">
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
                <a href="<?= $todayLogHref ?>" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-[12px] shadow-sm hover:shadow-md hover:-translate-y-0.5 transition">
                    <i class="fas fa-pen-ruler text-[12px]"></i>
                    <span><?= $todayData ? T('wel_edit_log', 'Edit Daily Log Hari Ini') : T('wel_fill_log', 'Isi Daily Log Hari Ini') ?></span>
                </a>
                <?php endif; ?>
                <div class="inline-flex flex-wrap items-center gap-2">
                    <!-- Range Tanggal Filter (Dari s/d Sampai) -->
                    <form method="GET" action="" class="inline-flex flex-wrap items-center gap-1.5 px-2 py-1.5 rounded-xl bg-white border border-slate-200 shadow-sm">
                        <div class="flex items-center gap-1 shrink-0">
                            <i class="fas fa-calendar-day text-slate-500 text-[12px]"></i>
                            <span class="text-[10px] font-black uppercase text-slate-500 hidden sm:inline">Periode</span>
                        </div>
                        <?php $hFrom = htmlspecialchars($monthStart ?? '', ENT_QUOTES); $hTo = htmlspecialchars($today ?? '', ENT_QUOTES); ?>
                        <label class="text-[10px] font-bold text-slate-600">Dari</label>
                        <input type="date" id="rep_date_from" name="date_from" value="<?= $hFrom ?>" class="px-1 py-1 text-[11px] font-semibold text-slate-700 bg-slate-50 border border-slate-200 rounded-md outline-none focus:ring-1 focus:ring-slate-400" onchange="updateReportLinks()">
                        <span class="text-[11px] font-black text-slate-400">s/d</span>
                        <label class="text-[10px] font-bold text-slate-600">Sampai</label>
                        <input type="date" id="rep_date_to" name="date_to" value="<?= $hTo ?>" class="px-1 py-1 text-[11px] font-semibold text-slate-700 bg-slate-50 border border-slate-200 rounded-md outline-none focus:ring-1 focus:ring-slate-400" onchange="updateReportLinks()">
                        <button type="submit" class="ml-1 inline-flex items-center justify-center gap-1 px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-900 text-white font-bold text-[10px] shadow-sm transition">
                            <i class="fas fa-filter text-[9px]"></i>
                            <span>Terapkan</span>
                        </button>
                        <a href="<?= BASE_URL ?>index.php" class="inline-flex items-center justify-center gap-1 px-2 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-[10px] border border-slate-200 transition">
                            <i class="fas fa-rotate-left text-[9px]"></i>
                            <span>Reset</span>
                        </a>
                    </form>
                    <!-- Tombol Download Report (date_from / date_to diisi via JS updateReportLinks) -->
                    <div class="inline-flex flex-wrap items-center gap-1.5">
                        <a id="btn_excel_energy" href="<?= BASE_URL ?>reports/daily_summary.php?date_from=<?= urlencode($monthStart) ?>&date_to=<?= urlencode($today) ?>&format=excel" target="_blank" class="inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-xl bg-slate-700 hover:bg-slate-800 text-white font-bold text-[11px] shadow-sm hover:shadow-md transition">
                            <i class="fas fa-file-excel text-[12px]"></i>
                            <span>Energy</span>
                        </a>
                        <button type="button" id="btn_pdf_energy" onclick="window.open('<?= BASE_URL ?>reports/daily_summary.php?date_from=<?= urlencode($monthStart) ?>&date_to=<?= urlencode($today) ?>', '_blank')" class="inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-[11px] shadow-sm hover:shadow-md transition">
                            <i class="fas fa-file-pdf text-[12px]"></i>
                            <span>Print</span>
                        </button>
                        <?php if (in_array($userRole, ['supervisor','manager','admin'])): ?>
                        <a id="btn_xls_activity" href="<?= BASE_URL ?>reports/activity_export.php?date_from=<?= urlencode($monthStart) ?>&date_to=<?= urlencode($today) ?>&format=excel" target="_blank" class="inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-xl bg-slate-700 hover:bg-slate-800 text-white font-bold text-[11px] shadow-sm hover:shadow-md transition">
                            <i class="fas fa-list-check text-[12px]"></i>
                            <span>Activity</span>
                        </a>
                        <?php endif; ?>
                        <a id="btn_xls_order" href="<?= BASE_URL ?>reports/order_export.php?date_from=<?= urlencode($monthStart) ?>&date_to=<?= urlencode($today) ?>&format=excel" target="_blank" class="inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-900 text-white font-bold text-[11px] shadow-sm hover:shadow-md transition">
                            <i class="fas fa-truck-ramp-box text-[12px]"></i>
                            <span>Order</span>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============ 🔹 SECTION KPI CARD (Full Width) - SAMA POLA 4 CARD UTAMA 🔹 ============ -->
    <section id="sec_kpi" class="border border-slate-200 rounded-xl bg-white shadow-sm overflow-hidden mb-5 animate-slide-up self-start border-t-2 border-t-slate-500">
        <button type="button" onclick="toggleDashSection('kpi')"
                class="w-full text-left px-3 lg:px-4 py-2 bg-transparent hover:bg-slate-50/80 transition group flex items-center justify-between gap-2 border-b border-slate-200/80">
            <div class="flex items-center flex-wrap gap-x-3 gap-y-1 min-w-0">
                <span class="w-6 h-6 rounded-md bg-slate-700 flex items-center justify-center text-white text-[12px] shadow-sm shrink-0 font-black"><i class="fas fa-chart-line text-[11px]"></i></span>
                <span class="text-[9px] font-black uppercase tracking-[0.2em] text-slate-600 hidden sm:inline-flex items-center gap-1 pl-2.5 border-l border-slate-200 h-5"><i class="fas fa-chart-simple text-[9px]"></i> Performance</span>
                <h2 class="font-display text-[13px] lg:text-[14px] font-black text-gray-900 tracking-wide leading-tight truncate">
                    KEY <span class="text-slate-400 font-black">PERFORMANCE INDICATORS</span>
                </h2>
                <span class="text-[9px] font-bold text-slate-500 bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded-full ml-1 hidden md:inline-flex items-center gap-0.5">
                    <i class="fas fa-hand-pointer text-[8px]"></i> Klik sembunyikan
                </span>
            </div>
            <i id="kpi_chev" class="fas fa-chevron-down text-slate-400 transition-transform duration-200 shrink-0 text-[11px] group-hover:text-slate-600"></i>
        </button>
        <div id="kpi_group" class="transition-all duration-200 overflow-hidden">
            <div class="p-2 sm:p-2.5 lg:p-3">
                <div class="overflow-x-auto rounded-xl border border-gray-200 shadow-sm">
                    <table class="w-full text-[13px]">
                        <thead>
                            <tr class="bg-slate-100 text-slate-800 text-[11px] uppercase tracking-[0.12em] font-black">
                                <th class="px-3 py-2.5 text-left font-black">METRIC</th>
                                <th class="px-3 py-2.5 text-center font-black border-l border-slate-200">LAST YEAR <span class="font-bold normal-case tracking-normal text-slate-600">(LY)</span></th>
                                <th class="px-3 py-2.5 text-center font-black border-l border-slate-200">TODAY</th>
                                <th class="px-3 py-2.5 text-center font-black border-l border-slate-200">ITR</th>
                                <th class="px-3 py-2.5 text-center font-black border-l border-slate-200">M&amp;U</th>
                                <th class="px-3 py-2.5 text-center font-black border-l border-slate-200">GITB RANK</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-3 py-2.5 font-black text-gray-900 text-[14px]">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="w-6 h-6 rounded bg-slate-100 text-slate-700 flex items-center justify-center text-[11px]"><i class="fas fa-bed"></i></span>
                                        Occupancy Rate
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-center border-l border-slate-100">
                                    <span class="text-lg font-black text-slate-700"><?= $occLYDisp ?></span>
                                </td>
                                <td class="px-3 py-2.5 text-center border-l border-slate-100">
                                    <span class="text-lg font-black text-slate-800"><?= $occNowDisp ?></span>
                                    <?php if (($targetOcc > 0) && ($lyOcc > 0) && ($targetOcc != $lyOcc)): ?>
                                    <div class="mt-0.5 inline-block text-[10px] font-black rounded px-2 py-0.5 bg-slate-100 text-slate-700 border border-slate-200">
                                        <?= $targetOcc > $lyOcc ? '&#9650; +' : '&#9660; -' ?><?= abs($targetOcc - $lyOcc) ?>%
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2.5 text-center border-l border-slate-100 text-lg font-black text-slate-700"><?= $kpiItr ?></td>
                                <td class="px-3 py-2.5 text-center border-l border-slate-100 text-lg font-black text-slate-700"><?= $kpiMnU ?></td>
                                <td class="px-3 py-2.5 text-center border-l border-slate-100">
                                    <span class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-slate-700 text-white text-lg font-black shadow-sm"><?= $kpiRank ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 🔹 2 CARD RINGKASAN: #1 UTILITY + #2 ENG ACT (stacked 1 col penuh) ============ -->
    <div class="grid grid-cols-1 gap-2.5 md:gap-3 lg:gap-3.5 mb-6 lg:mb-7 animate-slide-up items-start">

        <!-- ==========================================
             CARD #1 - UTILITY REPORT (Eng Dept #1)
             ========================================== -->
        <section id="sec_utility" class="border border-slate-200 rounded-xl bg-white shadow-sm overflow-hidden self-start border-t-2 border-t-slate-500">
            <button type="button" onclick="toggleDashSection('utility')"
                    class="w-full text-left px-3 lg:px-4 py-2 bg-transparent hover:bg-slate-50/80 transition group flex items-center justify-between gap-2 border-b border-slate-200/80">
                <div class="flex items-center flex-wrap gap-x-3 gap-y-1 min-w-0">
                    <span class="w-6 h-6 rounded-md bg-slate-700 flex items-center justify-center text-white text-[11px] shadow-sm shrink-0 font-black leading-none">1</span>
                    <span class="text-[9px] font-black uppercase tracking-[0.2em] text-slate-600 hidden sm:inline-flex items-center gap-1 pl-2.5 border-l border-slate-200 h-5"><i class="fas fa-screwdriver-wrench text-[9px]"></i> Eng. Dept.</span>
                    <h2 class="font-display text-[13px] lg:text-[14px] font-black text-gray-900 tracking-wide leading-tight truncate">
                        Utility <span class="text-slate-400 font-black">Report</span>
                    </h2>
                    <span class="text-[9px] font-bold text-slate-500 bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded-full ml-1 hidden md:inline-flex items-center gap-0.5">
                        <i class="fas fa-hand-pointer text-[8px]"></i> Klik sembunyikan
                    </span>
                </div>
                <i id="utility_chev" class="fas fa-chevron-down text-slate-400 transition-transform duration-200 shrink-0 text-[11px] group-hover:text-slate-600"></i>
            </button>

            <div id="utility_group" class="transition-all duration-200 overflow-hidden">
                <div class="p-2 sm:p-2.5 lg:p-3">
                    <!-- 2) 4 CARD USAGE 2x2 GRID -->
                    <?php
                    if (!isset($utilRows) || !is_array($utilRows)) {
                        $utilRows = [
                            ['ELECTRICITY', 'fas fa-bolt',      'text-slate-800', 'from-slate-600 to-slate-800', $elecLY,    $elecToday,    'kWh',   $costElecLY,    $costElecToday,    'bg-slate-50', 'border-slate-200', 'border-slate-200/60'],
                            ['WATER',       'fas fa-droplet',   'text-slate-800', 'from-slate-600 to-slate-800', $waterLY,   $waterToday,   'm3',    $costWaterLY,   $costWaterToday,   'bg-slate-50', 'border-slate-200', 'border-slate-200/60'],
                            ['GAS',         'fas fa-fire',      'text-slate-800', 'from-slate-600 to-slate-800', $gasLY,     $gasToday,     'kg',    $costGasLY,     $costGasToday,     'bg-slate-50', 'border-slate-200', 'border-slate-200/60'],
                            ['FUEL',        'fas fa-gas-pump',  'text-slate-800', 'from-slate-600 to-slate-800', $fuelLY,    $fuelToday,    'Liter', $costFuelLY,    $costFuelToday,    'bg-slate-50', 'border-slate-200', 'border-slate-200/60'],
                        ];
                    } else {
                        $utilMapped = [];
                        foreach ($utilRows as $urTmp) {
                            $lbl = (string)($urTmp[0] ?? '');
                            $bg = 'bg-slate-50'; $bor = 'border-slate-200'; $borDas = 'border-slate-200/60';
                            $urTmp[] = $bg; $urTmp[] = $bor; $urTmp[] = $borDas;
                            $utilMapped[] = $urTmp;
                        }
                        $utilRows = $utilMapped;
                    }
                    ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5 mb-4">
                    <?php foreach ($utilRows as $idx => $ur):
                        [$label, $icon, $col, $iconBg, $lyVal, $nowVal, $unit, $costLY, $costNow, $cardBg, $cardBorder, $dividerBorder] = array_pad(array_slice($ur, 0, 13), 13, '');
                        if (!$cardBg) { $cardBg = 'bg-slate-50'; $cardBorder = 'border-slate-200'; $dividerBorder = 'border-slate-200/60'; }
                        $lyDisp = uUsage($lyVal, $unit, 0);
                        $nowDisp = uUsage($nowVal, $unit, 0);
                        $delta = 0;
                        if ((float)$lyVal > 0) $delta = round((((float)$nowVal - (float)$lyVal) / (float)$lyVal) * 100, 1);
                        $deltaUp = $delta > 0;
                    ?>
                        <div class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 sm:px-3.5 sm:py-3 shadow-sm transition-all duration-150">
                            <div class="flex items-start justify-between gap-2 mb-2.5">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="w-8 h-8 shrink-0 rounded-xl bg-slate-700 text-white flex items-center justify-center text-[13px] shadow-sm">
                                        <i class="<?= $icon ?>"></i>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-black text-[13px] lg:text-[14px] text-gray-900 tracking-wide leading-tight uppercase"><?= $label ?></h3>
                                    </div>
                                </div>
                                <a href="<?= BASE_URL ?>energy.php" class="shrink-0 w-7 h-7 rounded-lg bg-white/80 border border-slate-200 hover:bg-white text-slate-500 hover:text-slate-800 flex items-center justify-center transition shadow-sm" title="Energy">
                                    <i class="fas fa-chevron-right text-[10px]"></i>
                                </a>
                            </div>

                            <!-- LY AVG DAY -->
                            <div class="mb-0.5">
                                <div class="flex items-center justify-between mb-0.5">
                                    <p class="text-[9px] font-black uppercase tracking-[0.16em] text-slate-500">LY <span class="font-bold normal-case tracking-normal">&bull; Avg/Day</span></p>
                                </div>
                                <p class="font-mono font-black text-[15px] sm:text-lg text-slate-700 leading-none">
                                    <?= $lyDisp ?>
                                    <span class="text-[10px] font-bold text-slate-500 ml-0.5"><?= $unit ?></span>
                                </p>
                            </div>

                            <!-- DIVIDER DASHED -->
                            <div class="border-t border-dashed border-slate-200/60 my-2"></div>

                            <!-- TODAY -->
                            <div>
                                <div class="flex items-center justify-between mb-0.5">
                                    <p class="text-[9px] font-black uppercase tracking-[0.16em] text-slate-700">TODAY</p>
                                    <span class="hidden sm:inline text-[8px] font-black px-1.5 py-0.5 rounded-full bg-slate-100 border border-slate-200 text-slate-700">
                                        <?php if ((float)$lyVal > 0):
                                            echo ($deltaUp ? '&#9650;+' : '&#9660;') . $delta . '%';
                                        else: echo '&ndash;'; endif; ?>
                                    </span>
                                </div>
                                <p class="font-mono font-black text-[17px] sm:text-[19px] leading-none text-slate-800 tracking-tight">
                                    <?= $nowDisp ?>
                                    <span class="text-[10px] font-bold opacity-80 ml-0.5"><?= $unit ?></span>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <!-- 4 CARD TOTAL BIAYA RUPIAH (Rp menempel didepan angka, font kecil 1 halaman) -->
                    <?php
                    // INFO SHIFT HARI INI (catatan kecil di atas cost cards, sama kayak di form Daily Log)
                    $_shH = (int)date('H');
                    if ($_shH >= 6 && $_shH < 14)      { $_shNow = 'pagi';  $_shLbl = 'PAGI';  $_shIc = 'fa-sun-plant-wilt'; }
                    elseif ($_shH >= 14 && $_shH < 22) { $_shNow = 'siang'; $_shLbl = 'SIANG'; $_shIc = 'fa-sun'; }
                    else                                        { $_shNow = 'malam'; $_shLbl = 'MALAM'; $_shIc = 'fa-moon-stars'; }
                    ?>
                    <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
                        <div class="inline-flex items-center gap-2 text-[10.5px] text-slate-600 font-semibold bg-white/70 backdrop-blur-sm border border-slate-200 rounded-xl px-3 py-1.5 shadow-sm">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg bg-slate-700 text-white shadow-sm">
                                <i class="fas <?= $_shIc ?> text-[11px]"></i>
                            </span>
                            <span class="tracking-[0.06em] uppercase">Shift <?= $_shLbl ?> Hari Ini</span>
                            <span class="text-[9px] text-slate-400 font-normal ml-1 hidden sm:inline">(catatan PIC on-duty, tidak mempengaruhi kalkulasi cost)</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-2 mb-3">
                        <?php foreach ($utilRows as $ur):
                            [$label, $icon, $col, $iconBg, $lyVal, $nowVal, $unit, $costLY, $costNow, $cardBg, $cardBorder, $dividerBorder] = array_pad(array_slice($ur, 0, 13), 13, '');
                            $shortName = $label;
                            if ($label === 'ELECTRICITY') $shortName = 'Listrik PLN';
                            elseif ($label === 'WATER') $shortName = 'Konsumsi Air';
                            elseif ($label === 'GAS') $shortName = 'Gas LPG';
                            elseif ($label === 'FUEL') $shortName = 'Solar BBM';
                            $costDiff = (float)$costNow - (float)$costLY;
                            $diffSign = $costDiff > 0 ? '+ Rp ' : ($costDiff < 0 ? '- Rp ' : 'Rp ');
                            $diffAbs = abs($costDiff);
                        ?>
                        <div class="rounded-lg border border-slate-200 bg-white p-2 sm:p-2.5 shadow-sm transition">
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="inline-flex items-center gap-1 text-[9px] font-bold text-slate-700 bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded-full">
                                    <?= $shortName ?>
                                </span>
                                <span class="text-[8px] font-black text-slate-400 uppercase">HARI INI</span>
                            </div>
                            <p class="font-mono font-black text-[16px] sm:text-lg text-slate-800 leading-none tracking-tight mb-1">
                                Rp <?= fmtRupiah($costNow) ?>
                            </p>
                            <div class="mt-1.5 pt-1.5 border-t border-dashed border-slate-200 flex items-center justify-between">
                                <span class="text-[9px] text-slate-500 font-semibold">LY Same Day</span>
                                <span class="text-[9px] font-bold text-slate-600 font-mono">Rp <?= fmtRupiah($costLY) ?></span>
                            </div>
                            <div class="flex items-center justify-between mt-0.5">
                                <span class="text-[9px] text-slate-500 font-semibold">Selisih</span>
                                <span class="text-[9px] font-black text-slate-700 font-mono">
                                    <?= $diffSign . fmtRupiah($diffAbs) ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- FOOTER INFO: TARIF STANDAR (FALLBACK GLOBAL) + SNAPSHOT STATUS + TOMBOL UBAH -->
                    <?php
                    // Hitung indikator: berapa % log hari ini yang PAKAI SNAPSHOT tarif (bukan cuma fallback global)
                    $_tariffSnapCheck = $db->fetchOne(
                        "SELECT COUNT(*) as all_logs,
                                SUM(CASE WHEN COALESCE(tariff_electricity_per_kwh,0) > 0
                                          OR COALESCE(tariff_water_per_m3,0) > 0
                                          OR COALESCE(tariff_gas_per_kg,0) > 0
                                          OR COALESCE(tariff_fuel_per_liter,0) > 0 THEN 1 ELSE 0 END) as snap_logs
                         FROM daily_logs WHERE DATE(log_date) BETWEEN ? AND ? AND $approvedWhere",
                        [$dateFrom, $dateTo]
                    );
                    $_snapAll  = max(1, (int)($_tariffSnapCheck['all_logs'] ?? 0));
                    $_snapCnt  = (int)($_tariffSnapCheck['snap_logs'] ?? 0);
                    $_snapPct  = round(($_snapCnt / $_snapAll) * 100, 0);
                    if ($_snapPct >= 80) { $_snapCls = 'text-slate-700 bg-slate-100 border-slate-200'; $_snapIcon = 'fa-circle-check'; $_snapTxt = 'Cost menggunakan Tarif Snapshot per-Hari (permanen sesuai tanggal — tidak berubah walau tarif global diubah besok)'; }
                    elseif ($_snapPct >= 1) { $_snapCls = 'text-slate-600 bg-slate-50 border-slate-200'; $_snapIcon = 'fa-triangle-exclamation'; $_snapTxt = 'Campuran: sebagian cost pakai Tarif Snapshot permanen, data lama fallback ke Tarif Standar Global.'; }
                    else { $_snapCls = 'text-slate-600 bg-slate-50 border-slate-200'; $_snapIcon = 'fa-circle-info'; $_snapTxt = 'Semua cost memakai Tarif Standar Global (data lama sebelum fitur Tarif Snapshot diaktifkan).'; }
                    unset($_tariffSnapCheck, $_snapAll, $_snapCnt);
                    ?>
                    <div class="space-y-1.5 text-[10px] text-gray-500 bg-slate-50 rounded-lg p-2 border border-slate-200 leading-relaxed">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <p class="flex-1 min-w-0">
                                <i class="fas fa-globe text-gray-400 mr-1 text-[10px]"></i>
                                <span class="font-bold text-slate-700">Tarif Standar Global (Fallback):</span>
                                PLN <strong class="text-slate-700">Rp <?= number_format((int)$TARIF['electricity_per_kwh'], 0, ',', '.') ?>/kWh</strong> ·
                                PDAM <strong class="text-slate-700">Rp <?= number_format((int)$TARIF['water_per_m3'], 0, ',', '.') ?>/m³</strong> ·
                                LPG <strong class="text-slate-700">Rp <?= number_format((int)$TARIF['gas_per_kg'], 0, ',', '.') ?>/kg</strong> ·
                                Solar <strong class="text-slate-700">Rp <?= number_format((int)$TARIF['fuel_per_liter'], 0, ',', '.') ?>/L</strong>
                            </p>
                            <?php if (in_array($userRole, ['supervisor','manager','admin'], true)): ?>
                                <button type="button" onclick="document.getElementById('tariffModal').classList.remove('hidden'); document.getElementById('tariffModal').style.display='flex';" title="Ubah Tarif Global (hanya mempengaruhi FALLBACK untuk data lama / log baru yang belum di-snapshot)" class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-white border border-slate-300 text-slate-700 hover:bg-slate-900 hover:text-white hover:border-slate-900 transition font-bold text-[10px] flex-shrink-0 shadow-sm">
                                    <i class="fas fa-gear text-[10px]"></i> Ubah Tarif Global
                                </button>
                            <?php endif; ?>
                        </div>
                        <!-- STATUS SNAPSHOT PER-HARI -->
                        <div class="flex items-start gap-2 rounded-md px-2 py-1.5 border <?= $_snapCls ?>">
                            <i class="fas <?= $_snapIcon ?> mt-[1px] text-[10px] shrink-0"></i>
                            <div class="flex-1 min-w-0">
                                <div class="font-black uppercase tracking-[0.08em] text-[9px] opacity-80 mb-0.5">
                                    Status Cost Periode Ini (Snapshot: <?= $_snapPct ?>%)
                                </div>
                                <div class="font-semibold text-[10.5px] leading-snug"><?= $_snapTxt ?></div>
                            </div>
                        </div>
                    </div>
                    <?php if ($_tariffFlash): ?>
                        <div class="mt-2 text-[11px] font-bold text-green-800 bg-green-50 border border-green-200 rounded-lg px-3 py-2 animate-pulse">
                            <i class="fas fa-circle-check text-green-600 mr-1"></i><?= htmlspecialchars($_tariffFlash) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ==========================================
             CARD #2 - ENGINEERING ACTIVITIES (Daily #2) - FULL WIDTH
             ========================================== -->
        <section id="sec_engact" class="border border-slate-200 rounded-xl bg-white shadow-sm overflow-hidden self-start border-t-2 border-t-slate-500">
            <button type="button" onclick="toggleDashSection('engact')"
                    class="w-full text-left px-3 lg:px-4 py-2 bg-transparent hover:bg-slate-50/80 transition group flex items-center justify-between gap-2 border-b border-slate-200/80">
                <div class="flex items-center flex-wrap gap-x-3 gap-y-1 min-w-0">
                    <span class="w-6 h-6 rounded-md bg-slate-700 flex items-center justify-center text-white text-[11px] shadow-sm shrink-0 font-black leading-none">2</span>
                    <span class="text-[9px] font-black uppercase tracking-[0.2em] text-slate-600 hidden sm:inline-flex items-center gap-1 pl-2.5 border-l border-slate-200 h-5"><i class="fas fa-clipboard-check text-[9px]"></i> Daily Activity</span>
                    <h2 class="font-display text-[13px] lg:text-[14px] font-black text-gray-900 tracking-wide leading-tight truncate">
                        ENGINEERING <span class="text-slate-400 font-black">ACTIVITIES</span>
                    </h2>
                    <span class="text-[9px] font-bold text-slate-500 bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded-full ml-1 hidden md:inline-flex items-center gap-0.5">
                        <i class="fas fa-hand-pointer text-[8px]"></i> Klik sembunyikan
                    </span>
                    <!-- Download PDF / Excel (match data dashboard 100%) -->
                    <span class="ml-1 flex items-center gap-1 shrink-0" onclick="event.stopPropagation();">
                        <a href="<?= BASE_URL ?>reports/dashboard_activities_pdf.php?date_from=<?= urlencode($monthStart) ?>&date_to=<?= urlencode($today) ?>" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-100 hover:bg-slate-200 border border-slate-200 text-slate-700 text-[9px] font-black transition"
                           title="Download PDF Engineering Activities (match dashboard)">
                            <i class="fas fa-file-pdf text-[8px]"></i> PDF
                        </a>
                        <a href="<?= BASE_URL ?>reports/dashboard_activities_excel.php?date_from=<?= urlencode($monthStart) ?>&date_to=<?= urlencode($today) ?>" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-100 hover:bg-slate-200 border border-slate-200 text-slate-700 text-[9px] font-black transition"
                           title="Download Excel Engineering Activities (match dashboard)">
                            <i class="fas fa-file-excel text-[8px]"></i> XLS
                        </a>
                    </span>
                </div>
                <i id="engact_chev" class="fas fa-chevron-down text-slate-400 transition-transform duration-200 shrink-0 text-[11px] group-hover:text-slate-600"></i>
            </button>
            <div id="engact_group" class="transition-all duration-200 overflow-hidden">
                <div class="p-2 sm:p-2.5 lg:p-3">
                    <div class="overflow-x-auto rounded-xl border border-slate-200 shadow-sm">
                        <table class="w-full text-[13px] border-collapse">
                            <thead>
                                <tr class="bg-slate-100 text-slate-800 text-[11px] uppercase tracking-[0.12em] font-black">
                                    <th class="px-3.5 py-3 text-left font-black w-64 border-r border-slate-200">DEPARTMENT</th>
                                    <th class="px-3.5 py-3 text-left font-black border-r border-slate-200">ACTIVITY DETAIL</th>
                                    <th class="px-3.5 py-3 text-center font-black w-48">STATUS</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 align-top">
                                <?php
                                $deptDef = [
                                    'operation'   => ['OPERATION',   'fas fa-gear'],
                                    'maintenance' => ['MAINTENANCE', 'fas fa-wrench'],
                                    'project'     => ['PROJECT',     'fas fa-clipboard-list'],
                                    'landscape'   => ['LANDSCAPE',   'fas fa-seedling'],
                                ];
                                foreach ($deptDef as $key => $dd):
                                    [$deptLabel, $deptIcon] = $dd;
                                    $rows = $actsGRP[$key] ?? [];
                                    $empty = (count($rows) === 0);
                                ?>
                                <tr class="hover:bg-slate-50/60 transition-colors bg-slate-50/30">
                                    <td class="px-4 py-4 border-r border-slate-100">
                                        <div class="flex items-center gap-2.5">
                                            <span class="w-9 h-9 rounded-lg bg-slate-700 shadow-sm border border-slate-800 flex items-center justify-center text-white"><i class="<?= $deptIcon ?> text-base"></i></span>
                                            <span class="font-black text-slate-900 text-[15px] tracking-wide"><?= $deptLabel ?></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 border-r border-slate-100">
                                        <?php if ($empty): ?>
                                            <div class="flex items-center gap-2 text-slate-400 italic text-sm py-2">
                                                <i class="fas fa-inbox opacity-70"></i>
                                                Belum ada aktivitas bulan ini.
                                            </div>
                                        <?php else: ?>
                                            <?php
                                            $rowsDone = [];
                                            $rowsProg = [];
                                            foreach ($rows as $ar) {
                                                if (($ar['status'] ?? '') === 'complete') $rowsDone[] = $ar;
                                                else                                        $rowsProg[] = $ar;
                                            }
                                            $hasDone = (count($rowsDone) > 0);
                                            $hasProg = (count($rowsProg) > 0);
                                            ?>
                                            <div class="space-y-2.5">
                                                <?php if ($hasProg):
                                                    $groupedProg = dashGroupByDate($rowsProg);
                                                ?>
                                                    <div class="rounded-lg border border-slate-200 bg-white p-2.5 sm:p-3 shadow-sm">
                                                        <div class="mb-2.5">
                                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-slate-800 border border-slate-700 text-white text-[10px] font-black uppercase tracking-wider shadow-sm">
                                                                <i class="fas fa-circle-notch fa-spin text-[9px]" style="--fa-animation-duration:1.8s"></i>
                                                                Sedang Berjalan <span class="opacity-90 font-bold">(<?= count($rowsProg) ?>)</span>
                                                            </span>
                                                        </div>
                                                        <?php
                                                        $firstDt = true;
                                                        foreach ($groupedProg as $dtISO => $items):
                                                            $isNoDate = ($dtISO === '_nodate');
                                                            $labelDate = $isNoDate ? '' : (new DateTime($dtISO))->format('d M Y');
                                                            if (!$firstDt):
                                                        ?>
                                                            <div class="border-t border-dotted border-slate-200 my-2.5 opacity-90"></div>
                                                        <?php endif; $firstDt = false; ?>
                                                        <div class="pl-2.5 mb-2 border-l-[3px] <?= $labelDate !== '' ? 'border-slate-300' : 'border-slate-200' ?>">
                                                            <?php if ($labelDate !== ''): ?>
                                                            <div class="flex items-center gap-2 mb-2">
                                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-200/80 border border-slate-300 text-slate-800 text-[10.5px] font-black tracking-wide">
                                                                    <i class="far fa-calendar-day text-slate-600 text-[9.5px]"></i>
                                                                    <?= htmlspecialchars($labelDate) ?>
                                                                    <span class="ml-1 bg-white px-1.5 py-0.5 rounded-full border border-slate-300 text-[9px] text-slate-700 font-black"><?= count($items) ?></span>
                                                                </span>
                                                            </div>
                                                            <?php endif; ?>
                                                            <ul class="space-y-0">
                                                            <?php
                                                            $itemIdx = 0;
                                                            $itemTotal = count($items);
                                                            foreach ($items as $ar):
                                                                $itemIdx++;
                                                                $d = $isNoDate ? ((strlen($ar['date'] ?? '') > 0) ? (new DateTime($ar['date']))->format('d M Y') : '') : '';
                                                                list($isMaster, $cleanEng) = dashSplitMasterEng($ar['eng'] ?? '');
                                                                $isLastItem = ($itemIdx === $itemTotal);
                                                                $sep = $isLastItem ? '' : 'border-b border-dashed border-slate-100 pb-2 mb-2';
                                                            ?>
                                                                <li class="flex items-start gap-2.5 pl-0.5 <?= $sep ?>">
                                                                    <i class="fas fa-circle text-[6px] text-slate-500 mt-1.5 shrink-0"></i>
                                                                    <div class="flex-1 min-w-0">
                                                                        <span class="font-semibold text-slate-900 leading-snug"><?= cleanInput($ar['title']) ?></span>
                                                                        <?php
                                                                        $showDate = ($d !== '');
                                                                        $showMaster = $isMaster;
                                                                        $showEng = ($cleanEng !== '');
                                                                        if ($showDate || $showMaster || $showEng):
                                                                        ?>
                                                                        <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                                                                            <?php if ($showDate): ?>
                                                                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-md border text-slate-600 border-slate-200 bg-white">
                                                                                <i class="far fa-calendar mr-0.5 text-[9px] opacity-70"></i><?= htmlspecialchars($d) ?></span>
                                                                            <?php endif; ?>
                                                                            <?php if ($showMaster): ?>
                                                                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-md border text-slate-800 border-slate-200 bg-slate-100 font-black">
                                                                                <i class="fas fa-database mr-0.5 text-[9px] text-slate-500"></i>MASTER</span>
                                                                            <?php endif; ?>
                                                                            <?php if ($showEng): ?>
                                                                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-md border text-slate-700 border-slate-200 bg-slate-100">
                                                                                <i class="fas fa-user-helmet-safety mr-0.5 text-[9px] opacity-80"></i><?= htmlspecialchars($cleanEng) ?></span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($hasDone && $hasProg): ?>
                                                    <div class="border-t border-dashed border-slate-300 opacity-80"></div>
                                                <?php endif; ?>

                                                <?php if ($hasDone):
                                                    $groupedDone = dashGroupByDate($rowsDone);
                                                ?>
                                                    <div class="rounded-lg border border-slate-200 bg-white p-2.5 sm:p-3 shadow-sm">
                                                        <div class="mb-2.5">
                                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-slate-100 border border-slate-200 text-slate-700 text-[10px] font-black uppercase tracking-wider">
                                                                <i class="fas fa-circle-check text-[9px]"></i>
                                                                Selesai / Done <span class="opacity-90 font-bold">(<?= count($rowsDone) ?>)</span>
                                                            </span>
                                                        </div>
                                                        <?php
                                                        $firstDt = true;
                                                        foreach ($groupedDone as $dtISO => $items):
                                                            $isNoDate = ($dtISO === '_nodate');
                                                            $labelDate = $isNoDate ? '' : (new DateTime($dtISO))->format('d M Y');
                                                            if (!$firstDt):
                                                        ?>
                                                            <div class="border-t border-dotted border-slate-200 my-2.5 opacity-90"></div>
                                                        <?php endif; $firstDt = false; ?>
                                                        <div class="pl-2.5 mb-2 border-l-[3px] <?= $labelDate !== '' ? 'border-slate-300' : 'border-slate-200' ?>">
                                                            <?php if ($labelDate !== ''): ?>
                                                            <div class="flex items-center gap-2 mb-2">
                                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-200/80 border border-slate-300 text-slate-800 text-[10.5px] font-black tracking-wide">
                                                                    <i class="far fa-calendar-day text-slate-600 text-[9.5px]"></i>
                                                                    <?= htmlspecialchars($labelDate) ?>
                                                                    <span class="ml-1 bg-white px-1.5 py-0.5 rounded-full border border-slate-300 text-[9px] text-slate-700 font-black"><?= count($items) ?></span>
                                                                </span>
                                                            </div>
                                                            <?php endif; ?>
                                                            <ul class="space-y-0">
                                                            <?php
                                                            $itemIdx = 0;
                                                            $itemTotal = count($items);
                                                            foreach ($items as $ar):
                                                                $itemIdx++;
                                                                $d = $isNoDate ? ((strlen($ar['date'] ?? '') > 0) ? (new DateTime($ar['date']))->format('d M Y') : '') : '';
                                                                list($isMaster, $cleanEng) = dashSplitMasterEng($ar['eng'] ?? '');
                                                                $isLastItem = ($itemIdx === $itemTotal);
                                                                $sep = $isLastItem ? '' : 'border-b border-dashed border-slate-100 pb-2 mb-2';
                                                            ?>
                                                                <li class="flex items-start gap-2.5 pl-0.5 opacity-[0.98] <?= $sep ?>">
                                                                    <i class="fas fa-check text-[9px] text-slate-600 mt-1.5 shrink-0"></i>
                                                                    <div class="flex-1 min-w-0">
                                                                        <span class="font-semibold text-slate-500 leading-snug line-through decoration-slate-400 decoration-[1.2px] decoration-skip-ink-none"><?= cleanInput($ar['title']) ?></span>
                                                                        <?php
                                                                        $showDate = ($d !== '');
                                                                        $showMaster = $isMaster;
                                                                        $showEng = ($cleanEng !== '');
                                                                        if ($showDate || $showMaster || $showEng):
                                                                        ?>
                                                                        <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                                                                            <?php if ($showDate): ?>
                                                                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-md border text-slate-600 border-slate-200 bg-white">
                                                                                <i class="far fa-calendar mr-0.5 text-[9px] opacity-70"></i><?= htmlspecialchars($d) ?></span>
                                                                            <?php endif; ?>
                                                                            <?php if ($showMaster): ?>
                                                                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-md border text-slate-800 border-slate-200 bg-slate-100 font-black">
                                                                                <i class="fas fa-database mr-0.5 text-[9px] text-slate-500"></i>MASTER</span>
                                                                            <?php endif; ?>
                                                                            <?php if ($showEng): ?>
                                                                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-md border text-slate-700 border-slate-200 bg-slate-100">
                                                                                <i class="fas fa-user-helmet-safety mr-0.5 text-[9px] opacity-80"></i><?= htmlspecialchars($cleanEng) ?></span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-center align-middle">
                                        <?php if ($empty): ?>
                                            <span class="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-full bg-slate-100 border border-slate-200 text-slate-500">
                                                &ndash; No Data &ndash;
                                            </span>
                                        <?php else: ?>
                                            <?php
                                            $completeCount = 0;
                                            $progCount = 0;
                                            foreach ($rows as $rr) { if (($rr['status'] ?? '') === 'complete') $completeCount++; else $progCount++; }
                                            ?>
                                            <div class="space-y-2 w-full">
                                                <?php if ($progCount > 0): ?>
                                                    <span class="inline-flex items-center gap-1.5 w-full justify-center px-3 py-1.5 rounded-full bg-slate-800 border border-slate-700 text-white text-[11px] font-black tracking-wider shadow-sm">
                                                        <i class="fas fa-circle-notch fa-spin text-[10px]" style="--fa-animation-duration: 1.8s"></i> In Progress
                                                        <span class="ml-0.5 text-xs font-bold bg-white/15 px-1.5 py-0.5 rounded-full border border-white/20">(<?= $progCount ?>)</span>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($completeCount > 0): ?>
                                                    <span class="inline-flex items-center gap-1.5 w-full justify-center px-3 py-1.5 rounded-full bg-slate-100 border border-slate-200 text-slate-800 text-[11px] font-black tracking-wider">
                                                        <i class="fas fa-circle-check text-slate-600"></i> Complete
                                                        <span class="ml-0.5 text-xs font-bold text-slate-700 bg-white px-1.5 py-0.5 rounded-full border border-slate-200">(<?= $completeCount ?>)</span>
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

                    <!-- Tanda tangan mini (Prepared / Reviewed / Approved) seperti kertas -->
                    <div class="pt-5 mt-3 border-t border-slate-200 grid grid-cols-1 md:grid-cols-3 gap-3 sm:gap-6 md:gap-8 items-start">
                        <?php
                        $signs = [
                            ['Prepared By:',  $userRole === 'engineer' ? cleanInput($userName) : 'Engineering Staff'],
                            ['Reviewed By:',  $userRole === 'supervisor' ? cleanInput($userName) : 'Supervisor Engineering'],
                            ['Approved By:',  $userRole === 'manager'    ? cleanInput($userName) : 'Manager Engineering'],
                        ];
                        foreach ($signs as [$lbl, $nameOrRole]): ?>
                        <div class="text-center w-full min-w-0">
                            <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500 mb-3"><?= $lbl ?></p>
                            <div class="w-28 sm:w-32 h-12 mx-auto border-b border-dashed border-slate-300 mb-2 opacity-80"></div>
                            <p class="font-bold text-slate-800 underline decoration-dotted decoration-slate-400 underline-offset-4 text-sm break-words"><?= $nameOrRole ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- JS: TOGGLE COLLAPSE SEMUA SECTION DASHBOARD (state simpan localStorage) -->
    <!-- Section list: kpi, utility, chiller, engact (section dalam kertas), swro, engactcards (4 card divisi supervisor) -->
    <script>
    // Update link report (Excel/PDF) saat user ganti tanggal di picker
    function updateReportLinks() {
        const inp = document.getElementById('rep_date');
        if (!inp) return;
        const d = inp.value || '<?=$today?>';
        const btnX = document.getElementById('btn_excel');
        if (btnX) btnX.href = '<?=BASE_URL?>reports/daily_summary.php?date=' + encodeURIComponent(d) + '&format=excel';
    }
    (function(){
        const SECTIONS = ['kpi','utility','chiller','engact','swro','engactcards','logistic'];
        // Default state: SEMUA TERBUKA default (chiller = coming soon TETAP TERTUTUP)
        const DEFAULTS = { kpi: true, utility: true, chiller: false, engact: true, swro: true, engactcards: true, logistic: true };
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
        SECTIONS.forEach(k => {
            let open = DEFAULTS[k];
            try {
                const saved = localStorage.getItem('dash_collapse_' + k);
                if (saved !== null) open = saved === '1';
            } catch(e){}
            apply(k, open);
        });
        window.toggleDashSection = function(key){
            const grp = document.getElementById(key + '_group');
            if (!grp) return;
            apply(key, grp.classList.contains('hidden'));
        };
    })();
    </script>

    <!-- ============ 4 CARD UTAMA: SATU WRAPPER GRID RESPONSIF 1â†’2â†’4 KOLOM (items-start) BREAKPOINT BOOTSTRAP STYLE ============ -->
    <?php
    $isManagerSpv = in_array($userRole, ['supervisor','manager','admin'], true);
    $swSpan  = $isManagerSpv ? '' : 'lg:col-span-2';
    $riwSpan = $isManagerSpv ? '' : 'lg:col-span-2';
    ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 items-start gap-2.5 md:gap-3 lg:gap-3.5 mb-6">
    <!-- ============ â‘  SWRO SYSTEM (WATER TREATMENT REVERSE OSMOSIS) ============ -->
    <div id="sec_swro" class="self-start min-h-0 bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden animate-slide-up <?= $swSpan ?>" style="animation-delay: 60ms">
        <button type="button" onclick="toggleDashSection('swro')"
                class="w-full text-left px-3 lg:px-4 py-2 border-b border-slate-200 bg-slate-50 hover:bg-slate-100 transition group">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[9px] font-bold uppercase tracking-[0.2em] text-slate-600 mb-0.5">water treatment</p>
                    <div class="flex items-center flex-wrap gap-x-3 gap-y-1">
                        <span class="w-6 h-6 rounded-md bg-slate-700 flex items-center justify-center text-white shrink-0 text-[11px] font-black leading-none">3</span>
                        <h2 class="font-display text-[13px] lg:text-[14px] font-bold text-slate-800 tracking-wide">
                            <?= T('dash_swro_title', 'swro <span class="opacity-60">&bull;</span> reverse osmosis') ?>
                        </h2>
                    </div>
                </div>
                <i id="swro_chev" class="fas fa-chevron-down text-slate-400 transition-transform duration-200 shrink-0 text-[11px] group-hover:text-slate-600"></i>
            </div>
        </button>
        <div id="swro_group" class="transition-all duration-200 overflow-hidden">
        <div class="p-2 sm:p-2.5 lg:p-3">
            <div class="flex flex-col gap-2">
                <?php
                $swMonthly = $db->fetchOne("SELECT COALESCE(SUM(swro_watermeter),0) as wm, COALESCE(SUM(swro_kwh),0) as kwh, COALESCE(AVG(NULLIF(swro_tds,0)),0) as tds FROM daily_logs WHERE log_date BETWEEN ? AND ? AND status='approved' $statusWhere", [$monthStart, $today]);
                $swToday = $todayDailySingle ? [
                    'wm' => (float)($todayDailySingle['swro_watermeter'] ?? 0),
                    'kwh' => (float)($todayDailySingle['swro_kwh'] ?? 0),
                    'tds' => (float)($todayDailySingle['swro_tds'] ?? 0),
                ] : ['wm'=>0,'kwh'=>0,'tds'=>0];
                $swCards = [
                    ['Watermeter (m3)', $swToday['wm'] > 0 ? formatNumber($swToday['wm'], 1) : '-', 'fas fa-droplet', (float)($swMonthly['wm'] ?? 0) > 0 ? formatNumber($swMonthly['wm'] ?? 0, 1).' m3' : '—'],
                    ['Listrik (kWh)', $swToday['kwh'] > 0 ? formatNumber($swToday['kwh'], 1) : '-', 'fas fa-bolt', (float)($swMonthly['kwh'] ?? 0) > 0 ? formatNumber($swMonthly['kwh'] ?? 0, 1).' kWh' : '—'],
                    ['TDS Outlet (ppm)', $swToday['tds'] > 0 ? formatNumber($swToday['tds'], 0) : '-', 'fas fa-vial', (float)($swMonthly['tds'] ?? 0) > 0 ? formatNumber($swMonthly['tds'], 0).' ppm avg' : '—'],
                ];
                foreach ($swCards as $sc) {
                    [$lbl, $todayVal, $icon, $monthVal] = $sc;
                ?>
                    <div class="flex items-center justify-between gap-2.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 sm:px-3 py-2 min-h-[60px] hover:shadow-sm hover:bg-white transition cursor-pointer" onclick="openModal('swro')">
                        <div class="flex items-center gap-2.5 min-w-0 flex-1">
                            <div class="w-7 h-7 rounded-lg bg-slate-700 flex items-center justify-center text-white shrink-0">
                                <i class="<?= $icon ?> text-[12px]"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-bold text-slate-800 text-[12px] sm:text-[13px] leading-tight truncate"><?= $lbl ?></p>
                                <p class="text-[10px] font-semibold text-slate-600 opacity-90 leading-tight mt-0.5"><i class="far fa-calendar-alt mr-0.5"></i> bulan ini: <?= $monthVal ?></p>
                            </div>
                        </div>
                    <div class="flex items-center justify-between gap-2.5 shrink-0">
                            <span class="text-lg sm:text-xl font-black text-slate-800 leading-none tabular-nums inline-flex items-center"><?= $todayVal ?></span>
                            <i class="fas fa-chevron-right text-slate-500 text-[10px] opacity-70 inline-flex items-center"></i>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
        </div>
    </div>

    <!-- ============ â‘¤ ENGINEERING ACTIVITIES - HANYA SUPERVISOR / MANAGER YANG DAPAT LIHAT ============ -->
    <?php if (in_array($userRole, ['supervisor','manager','admin'], true)): ?>
    <div class="self-start min-h-0 bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 120ms">
        <button type="button" onclick="toggleDashSection('engactcards')"
                class="w-full text-left px-3 lg:px-4 py-2 border-b border-slate-200 bg-slate-50 hover:bg-slate-100 transition group">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center flex-wrap gap-x-3 gap-y-1">
                    <span class="w-6 h-6 rounded-md bg-slate-700 flex items-center justify-center text-white shrink-0 text-[11px] font-black leading-none">4</span>
                    <h2 class="font-display text-[13px] lg:text-[14px] font-bold text-slate-800 tracking-wide">
                        <?= T('dash_act_title', 'eng activity') ?>
                    </h2>
                </div>
                <i id="engactcards_chev" class="fas fa-chevron-down text-slate-400 transition-transform duration-200 shrink-0 text-[11px] group-hover:text-slate-600"></i>
            </div>
        </button>
        <div id="engactcards_group" class="transition-all duration-200 overflow-hidden">
        <div class="p-2 sm:p-2.5 lg:p-3 flex flex-col gap-2">
            <?php
            $actCards = [
                ['operation',   T('dash_act_operation',   'Operation'),   'fas fa-gears',            (int)($todayAct['op'] ?? 0),    (int)($activitySum['op'] ?? 0)],
                ['maintenance', T('dash_act_maintenance', 'Maintenance'),'fas fa-wrench',           (int)($todayAct['maint'] ?? 0), (int)($activitySum['maint'] ?? 0)],
                ['project',     T('dash_act_project',     'Project'),    'fas fa-diagram-project',  (int)($todayAct['proj'] ?? 0),  (int)($activitySum['proj'] ?? 0)],
                ['landscape',   T('dash_act_landscape',   'Landscape'),  'fas fa-leaf',             (int)($todayAct['land'] ?? 0),  (int)($activitySum['land'] ?? 0)],
            ];
            foreach ($actCards as $ac) {
                [$modalId, $label, $icon, $todayCnt, $monthCnt] = $ac;
                $todayBar = $todayCnt > 0 ? min(100, max(8, ($todayCnt / max(1, max($todayCnt, $monthCnt, 10))) * 100)) : 0;
                $monthBar = $monthCnt > 0 ? min(100, max(8, ($monthCnt / max(1, max($todayCnt, $monthCnt, 10))) * 100)) : 0;
                echo <<<HTML
            <div class="flex flex-col justify-between gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 sm:px-3 py-2 min-h-[60px] hover:shadow-sm hover:bg-white transition cursor-pointer" onclick="openModal('{$modalId}')">
                <div class="flex items-center justify-between gap-2.5">
                    <div class="flex items-center gap-2.5 min-w-0 flex-1">
                        <div class="w-7 h-7 rounded-lg bg-slate-700 flex items-center justify-center text-white shrink-0">
                            <i class="{$icon} text-[12px]"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold tracking-tight text-[12px] sm:text-[13px] text-slate-800 leading-tight truncate">{$label}</p>
                            <p class="text-[10px] font-semibold text-slate-600 opacity-90 leading-tight mt-0.5"><i class="far fa-calendar-alt mr-0.5"></i> bulan ini: {$monthCnt}</p>
                        </div>
                    </div>
                    <div class="flex items-center justify-between gap-2.5 shrink-0">
                        <span class="text-lg sm:text-xl font-black text-slate-800 leading-none tabular-nums inline-flex items-center">{$todayCnt}</span>
                        <i class="fas fa-chevron-right text-slate-500 text-[10px] opacity-70 inline-flex items-center"></i>
                    </div>
                </div>
                <div class="h-1 w-full bg-white rounded-full overflow-hidden border border-slate-200/50">
                    <div class="h-full w-full relative">
                        <div class="absolute inset-y-0 left-0 bg-slate-400 opacity-25 rounded-full" style="width: {$monthBar}%"></div>
                        <div class="absolute inset-y-0 left-0 bg-slate-600 rounded-full" style="width: {$todayBar}%"></div>
                    </div>
                </div>
            </div>
HTML;
            }
            ?>
        </div>
        </div>
    </div>
    <?php endif; ?>
    <!-- ============ LOGISTIC - ORDER REQUEST & STATUS ============ -->
    <?php if (in_array($userRole, ['supervisor','manager','admin'])): ?>
    <div id="section_logistic" class="self-start min-h-0 bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden animate-slide-up scroll-mt-[90px]" style="animation-delay: 180ms">
        <button type="button" onclick="toggleDashSection('logistic')"
                class="w-full text-left px-3 lg:px-4 py-2 border-b border-slate-200 bg-slate-50 hover:bg-slate-100 transition group">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[9px] font-bold uppercase tracking-[0.2em] text-slate-600 mb-0.5">procurement <span class="opacity-60">&bull;</span> logistic</p>
                    <div class="flex items-center flex-wrap gap-x-3 gap-y-1">
                        <span class="w-6 h-6 rounded-md bg-slate-700 flex items-center justify-center text-white shrink-0 text-[11px] font-black leading-none">5</span>
                        <h2 class="font-display text-[13px] lg:text-[14px] font-bold text-slate-800 tracking-wide">
                            <?= T('dash_logistic_title', 'logistik & order') ?>
                        </h2>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <a href="<?= BASE_URL ?>orders/index.php" onclick="event.stopPropagation()" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-slate-800 hover:bg-slate-900 text-white text-[9px] font-bold shadow-sm hover:shadow transition">
                        <i class="fas fa-clipboard-list text-[9px]"></i> <?= T('order_menu_all', 'orders') ?>
                    </a>
                    <i id="logistic_chev" class="fas fa-chevron-down text-slate-400 transition-transform duration-200 shrink-0 text-[11px] group-hover:text-slate-600"></i>
                </div>
            </div>
        </button>
        <div id="logistic_group" class="transition-all duration-200 overflow-hidden">
        <div class="p-2 sm:p-2.5 lg:p-3">
            <div class="flex flex-col gap-2">
                <?php
                $allOrder = (int)($orderCounts['all'] ?? 0);
                $pendingMine = (int)($orderCounts['my_pending'] ?? 0);
                $spvPen = (int)($orderCounts['pending_supervisor'] ?? 0);
                $mgrPen = (int)($orderCounts['pending_manager'] ?? 0);
                $approve = (int)($orderCounts['approved'] ?? 0);
                $reject = (int)($orderCounts['rejected'] ?? 0);

                $orderBadge = [
                    ['all',        $allOrder,    'Semua Order',        'fa-clipboard-list',  BASE_URL . 'orders/index.php?filter=all'],
                    ['my queue',   $pendingMine, 'Perlu Tindakan',     'fa-clock',           BASE_URL . 'orders/index.php?filter=my_pending'],
                    ['spv',        $spvPen,      'Wait Supervisor',    'fa-hourglass-half',  BASE_URL . 'orders/index.php?filter=pending_supervisor'],
                    ['mgr',        $mgrPen,      'Wait Manager',       'fa-hourglass-start', BASE_URL . 'orders/index.php?filter=pending_manager'],
                    ['approved',   $approve,     'Selesai / Approved', 'fa-circle-check',    BASE_URL . 'orders/index.php?filter=approved'],
                ];
                if ($userRole !== 'manager' && $userRole !== 'supervisor') {
                    $orderBadge[1] = ['rejected', $reject, 'Ditolak / Rejected', 'fa-circle-xmark', BASE_URL . 'orders/index.php?filter=rejected'];
                }
                foreach ($orderBadge as $ob) {
                    [$topLbl, $num, $botLbl, $icon, $href] = $ob;
                ?>
                    <a href="<?= $href ?>" class="flex items-center justify-between gap-2.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 sm:px-3 py-2 min-h-[60px] hover:shadow-sm hover:bg-white transition group">
                        <div class="flex items-center gap-2.5 min-w-0 flex-1">
                            <div class="w-7 h-7 rounded-lg bg-slate-700 flex items-center justify-center shrink-0">
                                <i class="fas <?= $icon ?> text-white text-[12px]"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-[12px] sm:text-[13px] font-bold uppercase tracking-wide text-slate-700 leading-tight"><?= $botLbl ?></p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-2.5 shrink-0">
                            <span class="text-lg sm:text-xl font-black text-slate-800 leading-none tabular-nums inline-flex items-center"><?= $num ?></span>
                            <i class="fas fa-chevron-right text-slate-500 text-[10px] opacity-70 group-hover:opacity-100 group-hover:translate-x-0.5 transition inline-flex items-center"></i>
                        </div>
                    </a>
                <?php } ?>
            </div>
        </div>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $historyColSpan = $userRole === 'supervisor' ? 6 : 5;
    ?>
    <!-- ============ â‘£ RIWAYAT DAILY LOG (4 CARD SEJAJAR) ============ -->
    <div class="self-start min-h-0 bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden animate-slide-up <?= $riwSpan ?>" style="animation-delay: 600ms">
        <div class="p-2.5 sm:p-3 lg:p-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3 sm:justify-between">
            <div>
                <h2 class="font-bold text-[14px] text-slate-800"><i class="fas fa-clock-rotate-left mr-1.5 text-slate-600 text-[13px]"></i><?= $userRole === 'supervisor' ? T('recent_sup_title', 'Daily Log Terbaru (Semua Staff)') : T('recent_user_title', 'Riwayat Daily Log Saya') ?></h2>
                <p class="text-[12px] text-slate-500"><?= $userRole === 'supervisor' ? T('recent_sup_sub', '5 entri terakhir dari seluruh engineer') : T('recent_user_sub', '5 entri terakhir Anda') ?></p>
            </div>
            <a href="<?= BASE_URL ?>history.php" class="text-[11px] font-semibold text-slate-800 hover:text-slate-600 transition-colors inline-flex items-center gap-1 self-start sm:self-center">
                <?= T('general_lihat_semua', 'Lihat Semua') ?> <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>
        <div class="overflow-x-auto -mx-4 sm:mx-0 px-4 sm:px-0">
            <table class="w-full text-[12px] min-w-[520px]">
                <thead class="bg-slate-100">
                    <tr class="text-left text-slate-500">
                        <th class="px-3 sm:px-4 py-2 font-semibold whitespace-nowrap"><?= T('table_tanggal', 'Tanggal') ?></th>
                        <?php if ($userRole === 'supervisor'): ?>
                            <th class="px-3 sm:px-4 py-2 font-semibold whitespace-nowrap"><?= T('table_engineer', 'Engineer') ?></th>
                        <?php endif; ?>
                        <th class="px-3 sm:px-4 py-2 font-semibold text-right whitespace-nowrap"><?= T('card_electricity', 'Listrik') ?></th>
                        <th class="px-3 sm:px-4 py-2 font-semibold text-right whitespace-nowrap"><?= T('card_water', 'Air') ?></th>
                        <th class="px-3 sm:px-4 py-2 font-semibold text-right whitespace-nowrap"><?= T('card_gas', 'Gas') ?></th>
                        <th class="px-3 sm:px-4 py-2 font-semibold whitespace-nowrap"><?= T('table_status', 'Status') ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php if (count($recentLogs) > 0): ?>
                        <?php foreach ($recentLogs as $log): ?>
                            <tr class="hover:bg-slate-100/50 transition-colors">
                                <td class="px-3 sm:px-4 py-2.5 font-medium text-slate-800 whitespace-nowrap"><?= formatDate($log['log_date']) ?></td>
                                <?php if ($userRole === 'supervisor'): ?>
                                    <td class="px-3 sm:px-4 py-2.5 text-slate-500 whitespace-nowrap"><?= cleanInput($log['engineer_name'] ?? '-') ?></td>
                                <?php endif; ?>
                                <td class="px-3 sm:px-4 py-2.5 text-right font-medium text-slate-800 whitespace-nowrap"><?= formatNumber($log['total_electricity']) ?> kWh</td>
                                <td class="px-3 sm:px-4 py-2.5 text-right font-medium text-slate-800 whitespace-nowrap"><?= formatNumber($log['total_water']) ?> m³</td>
                                <td class="px-3 sm:px-4 py-2.5 text-right font-medium text-slate-800 whitespace-nowrap"><?= formatNumber($log['total_gas']) ?> kg</td>
                                <td class="px-3 sm:px-4 py-2.5 whitespace-nowrap"><span class="px-2 py-0.5 rounded-full text-[10px] font-semibold <?= getStatusBadgeClass($log['status']) ?>"><?= getStatusText($log['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="<?= $historyColSpan ?>" class="px-3 sm:px-4 py-8 text-center text-slate-500 text-[12px]"><i class="fas fa-inbox text-2xl mb-2 block opacity-40"></i><?= T('recent_empty', 'Belum ada data daily log') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>
    <!-- ============ AKHIR 4 CARD UTAMA (WRAPPER GRID 4 KOLOM RESPONSIF) ============ -->

    <!-- ============ DAILY LOG HARI INI (PINDAH KE BAWAH 4 CARD) ============ -->
    <?php if ($todayData): ?>
        <div class="mb-8 p-4 sm:p-5 lg:p-6 bg-white rounded-lg border border-slate-200 shadow-sm animate-slide-up">
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-4">
                <i class="fas fa-calendar-check text-slate-600 text-xl"></i>
                <h2 class="font-bold text-lg text-slate-800"><?= T('today_title', 'Daily Log Hari Ini') ?></h2>
                <span class="sm:ml-auto px-3 py-1 rounded-full text-xs font-semibold <?= getStatusBadgeClass($todayData['status']) ?>"><?= getStatusText($todayData['status']) ?></span>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 text-sm">
                <div><p class="text-slate-500 mb-1 text-xs sm:text-sm"><i class="fas fa-bolt mr-1 text-[11px]"></i><?= T('card_electricity', 'Listrik') ?></p><p class="font-bold text-slate-800 text-base sm:text-lg"><?= formatNumber($todayData['total_electricity']) ?> kWh</p></div>
                <div><p class="text-slate-500 mb-1 text-xs sm:text-sm"><i class="fas fa-droplet mr-1 text-[11px]"></i><?= T('card_water', 'Air') ?></p><p class="font-bold text-slate-800 text-base sm:text-lg"><?= formatNumber($todayData['total_water']) ?> m³</p></div>
                <div><p class="text-slate-500 mb-1 text-xs sm:text-sm"><i class="fas fa-fire mr-1 text-[11px]"></i><?= T('card_gas', 'Gas') ?></p><p class="font-bold text-slate-800 text-base sm:text-lg"><?= formatNumber($todayData['total_gas']) ?> kg</p></div>
                <div><p class="text-slate-500 mb-1 text-xs sm:text-sm"><i class="fas fa-calendar mr-1 text-[11px]"></i><?= T('general_date', 'Tanggal') ?></p><p class="font-bold text-slate-800 text-base sm:text-lg"><?= formatDate($todayData['log_date']) ?></p></div>
            </div>
            <?php if ($todayData['status'] === 'rejected' && $todayData['revision_notes']): ?>
                <div class="mt-4 p-4 rounded-lg bg-slate-100 border border-slate-300">
                    <p class="text-xs font-semibold text-slate-700 mb-1.5"><i class="fas fa-triangle-exclamation mr-1"></i><?= T('today_revisi_label', 'Catatan Revisi Supervisor') ?>:</p>
                    <p class="text-sm text-slate-800"><?= nl2br(cleanInput($todayData['revision_notes'])) ?></p>
                    <?php if ($userRole === 'engineer'): ?>
                        <a href="<?= BASE_URL ?>engineer/daily_log_form.php?date=<?= $todayData['log_date'] ?>" class="inline-flex items-center gap-1.5 mt-3 px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-semibold rounded-full transition-all">
                            <i class="fas fa-redo"></i> <?= T('today_edit_ulang', 'Edit Ulang') ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ============ SUPERVISOR 3 CHARTS (PINDAH KE BAWAH DAILY LOG) ============ -->
    <?php if ($userRole === 'supervisor'): ?>

    <div class="mb-6 bg-white rounded-lg border border-slate-200 p-4 sm:p-5 shadow-sm animate-slide-up" style="animation-delay: 420ms">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div>
                <h2 class="font-bold text-lg text-slate-800 mb-1"><i class="fas fa-chart-line mr-2 text-slate-600"></i><?= T('sup_chart_title', 'Grafik Konsumsi Energi') ?></h2>
                <p class="text-sm text-slate-500"><?= T('sup_chart_sub', 'Setiap grafik dilengkapi filter Harian / Bulanan & pilih tanggal sendiri-sendiri') ?></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <?php $exportQs = 'view=' . urlencode($viewE) . '&date_from=' . urlencode($dateFromE) . '&date_to=' . urlencode($dateToE); ?>
                <a href="<?= BASE_URL ?>reports/dashboard_pdf.php?<?= $exportQs ?>" target="_blank"
                    class="inline-flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-lg bg-slate-100 text-slate-700 border border-slate-200 text-xs sm:text-sm font-semibold hover:bg-slate-200 hover:-translate-y-0.5 transition-all duration-200 shadow-sm">
                    <i class="fas fa-file-pdf"></i><span class="hidden sm:inline"><?= T('general_export', 'Export') ?></span> PDF
                </a>
              
            </div>
        </div>
    </div>

    <div class="flex flex-col gap-5 sm:gap-6 mb-8">

        <div class="chart-card animate-slide-up overflow-hidden" style="animation-delay: 450ms">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
                <div>
                    <h3 class="font-bold text-xl text-slate-800 mb-1"> <?= T('sup_elec_title', 'Total Consume Listrik') ?></h3>
                    <p class="text-xs text-slate-500"><?= T('sup_chart_period', 'Periode') ?>: <?= formatDate($dateFromE) ?> - <?= formatDate($dateToE) ?> (kWh)</p>
                </div>
            </div>
            <form method="GET" class="mb-4 flex flex-col gap-3">
                <div class="flex flex-wrap gap-2 w-full">
                    <?php $otherQs = buildOtherQs('e', $viewE, $dateFromE, $dateToE, $viewW, $dateFromW, $dateToW, $viewG, $dateFromG, $dateToG); ?>
                    <div class="flex gap-2 flex-1 sm:flex-none">
                        <a href="?<?= $otherQs ?>view_e=daily&date_from_e=<?= urlencode($dateFromE) ?>&date_to_e=<?= urlencode($dateToE) ?>"
                            class="px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium border border-slate-200 hover:bg-slate-100 transition-colors flex-1 sm:flex-none text-center <?= $viewE === 'daily' ? 'bg-slate-800 text-white border-slate-800 shadow-sm' : 'bg-white text-slate-800' ?>"><?= T('filter_daily', 'Harian') ?></a>
                        <a href="?<?= $otherQs ?>view_e=monthly&date_from_e=<?= urlencode($dateFromE) ?>&date_to_e=<?= urlencode($dateToE) ?>"
                            class="px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium border border-slate-200 hover:bg-slate-100 transition-colors flex-1 sm:flex-none text-center <?= $viewE === 'monthly' ? 'bg-slate-800 text-white border-slate-800 shadow-sm' : 'bg-white text-slate-800' ?>"><?= T('filter_monthly', 'Bulanan') ?></a>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 items-end">
                    <div class="md:col-span-2">
                        <label class="text-xs text-slate-500 font-medium mb-1 block"><?= T('filter_from', 'Dari Tanggal') ?></label>
                        <input type="date" name="date_from_e" value="<?= $dateFromE ?>" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-200 bg-white text-slate-800 focus:outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200">
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs text-slate-500 font-medium mb-1 block"><?= T('filter_to', 'Sampai Tanggal') ?></label>
                        <input type="date" name="date_to_e" value="<?= $dateToE ?>" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-200 bg-white text-slate-800 focus:outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200">
                    </div>
                    <div class="flex gap-2 md:col-span-1">
                        <input type="hidden" name="view_e" value="<?= $viewE ?>">
                        <input type="hidden" name="view_w" value="<?= $viewW ?>">
                        <input type="hidden" name="date_from_w" value="<?= $dateFromW ?>">
                        <input type="hidden" name="date_to_w" value="<?= $dateToW ?>">
                        <input type="hidden" name="view_g" value="<?= $viewG ?>">
                        <input type="hidden" name="date_from_g" value="<?= $dateFromG ?>">
                        <input type="hidden" name="date_to_g" value="<?= $dateToG ?>">
                        <button type="submit" class="flex-1 px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white text-sm rounded-lg transition-colors flex items-center justify-center gap-1.5 shadow-sm">
                            <i class="fas fa-filter"></i><span class="hidden sm:inline"><?= T('general_filter', 'Filter') ?></span>
                        </button>
                        <?php $resetQs = buildOtherQs('e', '', '', '', $viewW, $dateFromW, $dateToW, $viewG, $dateFromG, $dateToG); ?>
                        <a href="?<?= rtrim($resetQs, '&') ?>" class="flex-1 px-4 py-2 bg-slate-100 text-slate-800 text-sm rounded-lg border border-slate-200 hover:bg-white transition-colors flex items-center justify-center gap-1.5">
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
                    <h3 class="font-bold text-xl text-slate-800 mb-1"> <?= T('sup_water_title', 'Total Consume Air') ?></h3>
                    <p class="text-xs text-slate-500"><?= T('sup_chart_period', 'Periode') ?>: <?= formatDate($dateFromW) ?> - <?= formatDate($dateToW) ?> (m³)</p>
                </div>
            </div>
            <form method="GET" class="mb-4 flex flex-col gap-3">
                <div class="flex flex-wrap gap-2 w-full">
                    <?php $otherQs = buildOtherQs('w', $viewE, $dateFromE, $dateToE, $viewW, $dateFromW, $dateToW, $viewG, $dateFromG, $dateToG); ?>
                    <div class="flex gap-2 flex-1 sm:flex-none">
                        <a href="?<?= $otherQs ?>view_w=daily&date_from_w=<?= urlencode($dateFromW) ?>&date_to_w=<?= urlencode($dateToW) ?>"
                            class="px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium border border-slate-200 hover:bg-slate-100 transition-colors flex-1 sm:flex-none text-center <?= $viewW === 'daily' ? 'bg-slate-800 text-white border-slate-800 shadow-sm' : 'bg-white text-slate-800' ?>"><?= T('filter_daily', 'Harian') ?></a>
                        <a href="?<?= $otherQs ?>view_w=monthly&date_from_w=<?= urlencode($dateFromW) ?>&date_to_w=<?= urlencode($dateToW) ?>"
                            class="px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium border border-slate-200 hover:bg-slate-100 transition-colors flex-1 sm:flex-none text-center <?= $viewW === 'monthly' ? 'bg-slate-800 text-white border-slate-800 shadow-sm' : 'bg-white text-slate-800' ?>"><?= T('filter_monthly', 'Bulanan') ?></a>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 items-end">
                    <div class="md:col-span-2">
                        <label class="text-xs text-slate-500 font-medium mb-1 block"><?= T('filter_from', 'Dari Tanggal') ?></label>
                        <input type="date" name="date_from_w" value="<?= $dateFromW ?>" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-200 bg-white text-slate-800 focus:outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200">
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs text-slate-500 font-medium mb-1 block"><?= T('filter_to', 'Sampai Tanggal') ?></label>
                        <input type="date" name="date_to_w" value="<?= $dateToW ?>" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-200 bg-white text-slate-800 focus:outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200">
                    </div>
                    <div class="flex gap-2 md:col-span-1">
                        <input type="hidden" name="view_w" value="<?= $viewW ?>">
                        <input type="hidden" name="view_e" value="<?= $viewE ?>">
                        <input type="hidden" name="date_from_e" value="<?= $dateFromE ?>">
                        <input type="hidden" name="date_to_e" value="<?= $dateToE ?>">
                        <input type="hidden" name="view_g" value="<?= $viewG ?>">
                        <input type="hidden" name="date_from_g" value="<?= $dateFromG ?>">
                        <input type="hidden" name="date_to_g" value="<?= $dateToG ?>">
                        <button type="submit" class="flex-1 px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white text-sm rounded-lg transition-colors flex items-center justify-center gap-1.5 shadow-sm">
                            <i class="fas fa-filter"></i><span class="hidden sm:inline"><?= T('general_filter', 'Filter') ?></span>
                        </button>
                        <?php $resetQs = buildOtherQs('w', $viewE, $dateFromE, $dateToE, '', '', '', $viewG, $dateFromG, $dateToG); ?>
                        <a href="?<?= rtrim($resetQs, '&') ?>" class="flex-1 px-4 py-2 bg-slate-100 text-slate-800 text-sm rounded-lg border border-slate-200 hover:bg-white transition-colors flex items-center justify-center gap-1.5">
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
                    <h3 class="font-bold text-xl text-slate-800 mb-1"> <?= T('sup_gas_title', 'Total Consume Gas') ?></h3>
                    <p class="text-xs text-slate-500"><?= T('sup_chart_period', 'Periode') ?>: <?= formatDate($dateFromG) ?> - <?= formatDate($dateToG) ?> (kg)</p>
                </div>
            </div>
            <form method="GET" class="mb-4 flex flex-col gap-3">
                <div class="flex flex-wrap gap-2 w-full">
                    <?php $otherQs = buildOtherQs('g', $viewE, $dateFromE, $dateToE, $viewW, $dateFromW, $dateToW, $viewG, $dateFromG, $dateToG); ?>
                    <div class="flex gap-2 flex-1 sm:flex-none">
                        <a href="?<?= $otherQs ?>view_g=daily&date_from_g=<?= urlencode($dateFromG) ?>&date_to_g=<?= urlencode($dateToG) ?>"
                            class="px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium border border-slate-200 hover:bg-slate-100 transition-colors flex-1 sm:flex-none text-center <?= $viewG === 'daily' ? 'bg-slate-800 text-white border-slate-800 shadow-sm' : 'bg-white text-slate-800' ?>"><?= T('filter_daily', 'Harian') ?></a>
                        <a href="?<?= $otherQs ?>view_g=monthly&date_from_g=<?= urlencode($dateFromG) ?>&date_to_g=<?= urlencode($dateToG) ?>"
                            class="px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium border border-slate-200 hover:bg-slate-100 transition-colors flex-1 sm:flex-none text-center <?= $viewG === 'monthly' ? 'bg-slate-800 text-white border-slate-800 shadow-sm' : 'bg-white text-slate-800' ?>"><?= T('filter_monthly', 'Bulanan') ?></a>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 items-end">
                    <div class="md:col-span-2">
                        <label class="text-xs text-slate-500 font-medium mb-1 block"><?= T('filter_from', 'Dari Tanggal') ?></label>
                        <input type="date" name="date_from_g" value="<?= $dateFromG ?>" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-200 bg-white text-slate-800 focus:outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200">
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs text-slate-500 font-medium mb-1 block"><?= T('filter_to', 'Sampai Tanggal') ?></label>
                        <input type="date" name="date_to_g" value="<?= $dateToG ?>" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-200 bg-white text-slate-800 focus:outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200">
                    </div>
                    <div class="flex gap-2 md:col-span-1">
                        <input type="hidden" name="view_g" value="<?= $viewG ?>">
                        <input type="hidden" name="view_e" value="<?= $viewE ?>">
                        <input type="hidden" name="date_from_e" value="<?= $dateFromE ?>">
                        <input type="hidden" name="date_to_e" value="<?= $dateToE ?>">
                        <input type="hidden" name="view_w" value="<?= $viewW ?>">
                        <input type="hidden" name="date_from_w" value="<?= $dateFromW ?>">
                        <input type="hidden" name="date_to_w" value="<?= $dateToW ?>">
                        <button type="submit" class="flex-1 px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white text-sm rounded-lg transition-colors flex items-center justify-center gap-1.5 shadow-sm">
                            <i class="fas fa-filter"></i><span class="hidden sm:inline"><?= T('general_filter', 'Filter') ?></span>
                        </button>
                        <?php $resetQs = buildOtherQs('g', $viewE, $dateFromE, $dateToE, $viewW, $dateFromW, $dateToW, '', '', ''); ?>
                        <a href="?<?= rtrim($resetQs, '&') ?>" class="flex-1 px-4 py-2 bg-slate-100 text-slate-800 text-sm rounded-lg border border-slate-200 hover:bg-white transition-colors flex items-center justify-center gap-1.5">
                            <i class="fas fa-rotate-left"></i><span class="hidden sm:inline"><?= T('general_reset', 'Reset') ?></span>
                        </a>
                    </div>
                </div>
            </form>
            <div class="h-64 sm:h-72 lg:h-80 w-full min-w-0"><canvas id="gasChart"></canvas></div>
        </div>

    </div>

    <?php endif; ?>

    <!-- ============ AKTIVITAS DALAM PROGRESS (DIPINDAH KEDALAM PAGE-SHELL AGAR TIDAK OVERLAY JUDUL) ============ -->
    <div class="static z-0 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden mb-8 animate-slide-up" style="animation-delay: 90ms">
        <div class="px-5 lg:px-8 pt-6 pb-5 border-b border-slate-200 bg-slate-50 relative overflow-hidden">
            <div class="absolute -left-5 -top-6 opacity-[0.07] text-[130px] leading-none text-slate-700 pointer-events-none select-none"><i class="fas fa-list-check"></i></div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.35em] text-slate-600 mb-2"><i class="fas fa-bolt mr-1"></i> FOKUS HARI INI</p>
                    <h1 class="font-display text-2xl lg:text-4xl font-black text-slate-900 tracking-tight leading-tight">
                        AKTIVITAS DALAM <span class="text-slate-700">PROGRESS</span>
                    </h1>
                </div>
                <div class="flex items-center gap-2.5">
                    <span class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-800 text-white shadow-sm font-black text-sm">
                        <i class="fas fa-gears animate-spin-slow text-[13px]"></i>
                        TOTAL PROGRESS:
                        <span class="font-black text-lg leading-none ml-0.5"><?= $progressCount ?></span>
                    </span>
                    <?php if (in_array($userRole, ['manager','admin','supervisor'], true)): ?>
                    <a href="<?= BASE_URL ?>manager/activities.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-900 text-white text-xs font-bold hover:bg-slate-800 transition">
                        <i class="fas fa-arrow-right-to-bracket"></i>
                        BUKA ENGINEERING ACTIVITIES
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="p-5 lg:p-7">
            <?php if ($progressCount === 0): ?>
                <div class="py-14 px-6 rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50 text-center">
                    <div class="w-20 h-20 rounded-full bg-slate-50 border border-slate-200 flex items-center justify-center text-4xl text-slate-700 mx-auto mb-4 shadow-sm">
                        <i class="fas fa-check-double"></i>
                    </div>
                    <h3 class="font-black text-xl text-slate-800 mb-2">SEMUA AKTIVITAS SELESAI!</h3>
                    <p class="text-sm text-slate-500 leading-relaxed max-w-lg mx-auto">
                        Tidak ada pekerjaan Engineering yang masih berstatus <b>In Progress</b> untuk bulan ini.
                        Semua pekerjaan sudah ditandai <b class="text-slate-700">Complete</b>. Bagus!
                    </p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto rounded-xl border border-slate-200 shadow-sm">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="bg-slate-100 text-slate-800 text-[11px] uppercase tracking-[0.12em] font-black">
                                <th class="px-4 py-3.5 text-left font-black w-14 border-r border-slate-200">NO</th>
                                <th class="px-4 py-3.5 text-left font-black w-40 border-r border-slate-200">DIVISI</th>
                                <th class="px-4 py-3.5 text-left font-black border-r border-slate-200">NAMA PEKERJAAN / AKTIVITAS</th>
                                <th class="px-4 py-3.5 text-left font-black w-44 border-r border-slate-200">ENGINEER</th>
                                <th class="px-4 py-3.5 text-left font-black w-40 border-r border-slate-200">SUMBER DATA</th>
                                <th class="px-4 py-3.5 text-center font-black w-36 border-r border-slate-200">TANGGAL</th>
                                <th class="px-4 py-3.5 text-center font-black w-40">STATUS</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 align-top">
                            <?php
                            $no = 0;
                            foreach ($progressActivities as $pa):
                                $no++;
                                $div = $pa['division'] ?? 'operation';
                                $info = $divInfo[$div] ?? $divInfo['operation'];
                                $src = $pa['source'] ?? 'daily_log';
                                $tgl = $pa['log_date'] ?? '';
                                if (strlen($tgl) > 0) { try { $tglObj = new DateTime($tgl); $tglFmt = $tglObj->format('d M Y'); } catch (Throwable $e) { $tglFmt = $tgl; } } else { $tglFmt = '-'; }
                                $bgStripe = ($no % 2 === 0) ? ' bg-slate-50/40' : '';
                            ?>
                            <tr class="hover:bg-slate-100/70 transition-colors<?= $bgStripe ?>">
                                <td class="px-4 py-3.5 text-slate-600 font-black text-sm leading-none border-r border-slate-100">
                                    <span class="w-7 h-7 inline-flex items-center justify-center rounded-md bg-white border border-slate-200 shadow-xs"><?= $no ?></span>
                                </td>
                                <td class="px-4 py-3.5 border-r border-slate-100">
                                    <div class="flex items-center gap-2">
                                        <span class="w-9 h-9 rounded-lg bg-slate-700 border border-slate-800 flex items-center justify-center shadow-xs text-white text-[14px]"><?= $info['icon'] ?></span>
                                        <div class="flex flex-col gap-0.5">
                                            <span class="inline-block px-2 py-0.5 rounded border text-[10px] font-black tracking-wider bg-slate-100 border-slate-200 text-slate-700"><?= $info['label'] ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 border-r border-slate-100">
                                    <div class="font-bold text-slate-900 leading-relaxed text-[14px]"><?= cleanInput($pa['activity']) ?></div>
                                </td>
                                <td class="px-4 py-3.5 border-r border-slate-100">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-700 border border-slate-200 text-[11px] font-bold">
                                        <i class="fas fa-user-helmet-safety text-slate-500"></i>
                                        <?= cleanInput($pa['engineer_name']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 border-r border-slate-100">
                                    <?php if ($src === 'daily_log'): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-700 border border-slate-200 text-[11px] font-black shadow-xs">
                                            <i class="fas fa-file-pen text-slate-700"></i>
                                            LOG HARIAN
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-700 border border-slate-200 text-[11px] font-black shadow-xs">
                                            <i class="fas fa-database text-slate-600"></i>
                                            MASTER TEMPLATE
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3.5 text-center border-r border-slate-100">
                                    <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-white border border-slate-200 text-[11px] font-bold text-slate-700 shadow-xs font-mono">
                                        <i class="far fa-calendar text-slate-400 mr-0.5"></i>
                                        <?= $tglFmt ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-center">
                                    <?php if ($src === 'daily_log'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-800 text-white font-black text-[11px] shadow-sm border border-slate-700">
                                            <i class="fas fa-gears animate-spin-slow text-[10px]"></i>
                                            IN PROGRESS
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-700 text-white font-black text-[11px] shadow-sm border border-slate-600">
                                            <i class="fas fa-list-check text-[10px]"></i>
                                            TUGAS BARU
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- ============ AKHIR AKTIVITAS DALAM PROGRESS ============ -->
</div>

<!-- ============ 11 MODAL GRAFIK RINCIAN (TETAP LUAR PAGE-SHELL, POSITION FIXED AMAN) ============ -->
<div id="modalOverlay" class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm hidden items-center justify-center p-4 sm:p-6 transition-opacity duration-300" onclick="closeAllModals(event)">
    <!-- LISTRIK MODAL -->
    <div id="modal-electricity" class="modal-card hidden bg-white rounded-lg shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-slate-800 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-bolt text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_elec_title', 'Rincian Konsumsi Listrik') ?></h2>
                <p class="text-slate-100/90 text-sm"><?= T('modal_elec_sub', 'WBP (Tarif Puncak) & LWBP (Tarif Luar Puncak) Bulan Ini') ?></p>
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
    <div id="modal-water" class="modal-card hidden bg-white rounded-lg shadow-2xl max-w-5xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-slate-800 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-droplet text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_water_title', 'Rincian 9 Sumber Air') ?></h2>
                <p class="text-slate-100/90 text-sm"><?= T('modal_water_sub', 'PDAM, IKI Gaban, DW 1/2 Brr, ASEAN, LPB, Main Bldg, Cooling, Bottling') ?></p>
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
    <div id="modal-gas" class="modal-card hidden bg-white rounded-lg shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-slate-800 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-fire text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_gas_title', 'Rincian Konsumsi Gas') ?></h2>
                <p class="text-slate-100/90 text-sm"><?= T('modal_gas_sub', 'Gas LPG & Gas LNG Per Hari') ?></p>
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
    <div id="modal-swro" class="modal-card hidden bg-white rounded-lg shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-slate-800 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-water text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_swro_title', 'SWRO System Monitoring') ?></h2>
                <p class="text-slate-100/90 text-sm"><?= T('modal_swro_sub', 'Watermeter (m³), kWh Konsumsi, TDS Output (ppm)') ?></p>
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
    <div id="modal-bottling" class="modal-card hidden bg-white rounded-lg shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-slate-800 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-industry text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_bottling_title', 'Bottling Plant Monitoring') ?></h2>
                <p class="text-slate-100/90 text-sm"><?= T('modal_bottling_sub', 'Konsumsi kWh & Watermeter Air Produksi') ?></p>
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
    <div id="modal-chiller" class="modal-card hidden bg-white rounded-lg shadow-2xl max-w-5xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-slate-800 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-snowflake text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_chiller_title', 'Chiller System Performance') ?></h2>
                <p class="text-slate-100/90 text-sm"><?= T('modal_chiller_sub', 'pH, TDS, Temperature (Â°C) & Status Unit 1/2/3') ?></p>
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
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('chiller_unit1_on', 'Unit 1 ON') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $totalChDays > 0 ? round(($chiller1Cnt/$totalChDays)*100, 1) : 0 ?><span class="text-sm">%</span></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('chiller_unit2_on', 'Unit 2 ON') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $totalChDays > 0 ? round(($chiller2Cnt/$totalChDays)*100, 1) : 0 ?><span class="text-sm">%</span></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('chiller_unit3_on', 'Unit 3 ON') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $totalChDays > 0 ? round(($chiller3Cnt/$totalChDays)*100, 1) : 0 ?><span class="text-sm">%</span></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('chiller_rata_rata', 'Rata-Rata Aktif') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $chillerOnAvg ?><span class="text-sm">%</span></p>
                </div>
            </div>
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalChillerChart"></canvas></div>
        </div>
    </div>

    <!-- FUEL MODAL -->
    <div id="modal-fuel" class="modal-card hidden bg-white rounded-lg shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-slate-800 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-gas-pump text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_fuel_title', 'Rincian Konsumsi Fuel / Solar') ?></h2>
                <p class="text-slate-100/90 text-sm"><?= T('modal_fuel_sub', 'Total Liter Per Hari Bulan Ini - Kumulatif') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <?php $fuelTot = array_sum(array_column($fuelDetailData, 'total_fuel')); $fuelDays = count($fuelDetailData); ?>
            <div class="mb-5 grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_fuel_total', 'Total Bulan Ini') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= formatNumber($fuelTot, 1) ?> <span class="text-sm">L</span></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_fuel_avg', 'Rata-Rata / Hari') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $fuelDays>0 ? formatNumber($fuelTot/$fuelDays, 1) : 0 ?> <span class="text-sm">L</span></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center col-span-2 md:col-span-1">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_fuel_days', 'Hari Tercatat') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $fuelDays ?> <span class="text-sm">hari</span></p>
                </div>
            </div>
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalFuelChart"></canvas></div>
        </div>
    </div>

    <!-- OPERATION MODAL -->
    <div id="modal-operation" class="modal-card hidden bg-white rounded-lg shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-slate-800 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-gears text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_act_op_title', 'Rincian Aktivitas OPERATION') ?></h2>
                <p class="text-slate-100/90 text-sm"><?= T('modal_act_op_sub', 'Jumlah aktivitas operasional per hari bulan ini') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <?php $opTot = array_sum(array_column($activityOpDetailData, 'activity_operation')); $opDays = count($activityOpDetailData); ?>
            <div class="mb-5 grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_act_total_month', 'Total Bulan Ini') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $opTot ?> <span class="text-sm"><?= T('dash_act_count', 'aktivitas') ?></span></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_act_avg_day', 'Rata-Rata / Hari') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $opDays>0 ? number_format($opTot/$opDays, 1) : '0' ?></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center col-span-2 md:col-span-1">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_act_days', 'Hari Tercatat') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $opDays ?> <span class="text-sm">hari</span></p>
                </div>
            </div>
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalOperationChart"></canvas></div>
            <?= renderActivityTable($actListOp, 'text-slate-700', 'fa-user-tie', 'Belum ada daftar pekerjaan OPERATION untuk bulan ini.', 'OPERATION'); ?>
        </div>
    </div>
    <div id="modal-maintenance" class="modal-card hidden bg-white rounded-lg shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-slate-800 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-wrench text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_act_maint_title', 'Rincian Aktivitas MAINTENANCE') ?></h2>
                <p class="text-slate-100/90 text-sm"><?= T('modal_act_maint_sub', 'Jumlah perawatan & perbaikan per hari bulan ini') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <?php $maintTot = array_sum(array_column($activityMaintDetailData, 'activity_maintenance')); $maintDays = count($activityMaintDetailData); ?>
            <div class="mb-5 grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_act_total_month', 'Total Bulan Ini') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $maintTot ?> <span class="text-sm"><?= T('dash_act_count', 'aktivitas') ?></span></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_act_avg_day', 'Rata-Rata / Hari') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $maintDays>0 ? number_format($maintTot/$maintDays, 1) : '0' ?></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center col-span-2 md:col-span-1">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_act_days', 'Hari Tercatat') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $maintDays ?> <span class="text-sm">hari</span></p>
                </div>
            </div>
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalMaintenanceChart"></canvas></div>
            <?= renderActivityTable($actListMaint, 'text-slate-700', 'fa-user-helmet-safety', 'Belum ada daftar pekerjaan MAINTENANCE untuk bulan ini.', 'MAINTENANCE'); ?>
        </div>
    </div>

    <!-- PROJECT MODAL -->
    <div id="modal-project" class="modal-card hidden bg-white rounded-lg shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-slate-800 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-diagram-project text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_act_proj_title', 'Rincian Aktivitas PROJECT') ?></h2>
                <p class="text-slate-100/90 text-sm"><?= T('modal_act_proj_sub', 'Progress proyek khusus per hari bulan ini') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <?php $projTot = array_sum(array_column($activityProjDetailData, 'activity_project')); $projDays = count($activityProjDetailData); ?>
            <div class="mb-5 grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_act_total_month', 'Total Bulan Ini') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $projTot ?> <span class="text-sm"><?= T('dash_act_count', 'aktivitas') ?></span></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_act_avg_day', 'Rata-Rata / Hari') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $projDays>0 ? number_format($projTot/$projDays, 1) : '0' ?></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center col-span-2 md:col-span-1">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_act_days', 'Hari Tercatat') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $projDays ?> <span class="text-sm">hari</span></p>
                </div>
            </div>
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalProjectChart"></canvas></div>
            <?= renderActivityTable($actListProj, 'text-slate-700', 'fa-user-pen', 'Belum ada daftar Project untuk bulan ini.', 'PROJECT'); ?>
        </div>
    </div>

    <!-- LANDSCAPE MODAL -->
    <div id="modal-landscape" class="modal-card hidden bg-white rounded-lg shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden animate-slide-up">
        <div class="bg-slate-800 p-5 sm:p-6 flex items-center gap-4 relative">
            <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center shrink-0">
                <i class="fas fa-leaf text-white text-2xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-white font-bold text-xl sm:text-2xl"><?= T('modal_act_land_title', 'Rincian Aktivitas LANDSCAPE') ?></h2>
                <p class="text-slate-100/90 text-sm"><?= T('modal_act_land_sub', 'Perawatan taman & lingkungan per hari bulan ini') ?></p>
            </div>
            <button onclick="closeAllModals()" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 text-white transition-colors flex items-center justify-center shrink-0">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="p-5 sm:p-6 overflow-y-auto" style="max-height: calc(90vh - 90px)">
            <?php $landTot = array_sum(array_column($activityLandDetailData, 'activity_landscape')); $landDays = count($activityLandDetailData); ?>
            <div class="mb-5 grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_act_total_month', 'Total Bulan Ini') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $landTot ?> <span class="text-sm"><?= T('dash_act_count', 'aktivitas') ?></span></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_act_avg_day', 'Rata-Rata / Hari') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $landDays>0 ? number_format($landTot/$landDays, 1) : '0' ?></p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center col-span-2 md:col-span-1">
                    <p class="text-xs text-slate-700 font-semibold mb-1"><?= T('modal_act_days', 'Hari Tercatat') ?></p>
                    <p class="text-2xl font-bold text-slate-800"><?= $landDays ?> <span class="text-sm">hari</span></p>
                </div>
            </div>
            <div class="h-72 sm:h-80 lg:h-96 w-full"><canvas id="modalLandscapeChart"></canvas></div>
            <?= renderActivityTable($actListLand, 'text-slate-700', 'fa-user-nurse', 'Belum ada daftar pekerjaan LANDSCAPE untuk bulan ini.', 'LANDSCAPE'); ?>
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
                        { label: 'Temperature (Â°C)', data: extract(modalChillerData, 'chiller_temp'), borderColor: '#0891b2', backgroundColor: 'rgba(8,145,178,0.10)', fill: true, pointBorderColor: '#0891b2', yAxisID: 'y2' }
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

/* ---------- UPDATE LINK TOMBOL DOWNLOAD + SAVE TANGGAL KE LOCALSTORAGE (RANGE 2 DATE) ---------- */
(function () {
    const BASE = '<?= BASE_URL ?>';
    const FROM_DEF = '<?= $monthStart ?>';
    const TO_DEF   = '<?= $today ?>';
    function el(id) { return document.getElementById(id); }
    function isDate(v) { return /^\d{4}-\d{2}-\d{2}$/.test('' + v); }
    window.updateReportLinks = function () {
        const inpFrom = el('rep_date_from');
        const inpTo   = el('rep_date_to');
        let fromV = (inpFrom && inpFrom.value && isDate(inpFrom.value)) ? inpFrom.value : FROM_DEF;
        let toV   = (inpTo   && inpTo.value   && isDate(inpTo.value))   ? inpTo.value   : TO_DEF;
        if (fromV && toV && fromV > toV) { const t = fromV; fromV = toV; toV = t; }
        try {
            localStorage.setItem('report_range_from_last', fromV);
            localStorage.setItem('report_range_to_last',   toV);
        } catch (e) {}
        const encFrom = encodeURIComponent(fromV);
        const encTo   = encodeURIComponent(toV);
        const q = 'date_from=' + encFrom + '&date_to=' + encTo;
        if (el('btn_excel_energy')) el('btn_excel_energy').href = BASE + 'reports/daily_summary.php?' + q + '&format=excel';
        if (el('btn_pdf_energy')) {
            el('btn_pdf_energy').onclick = function () { window.open(BASE + 'reports/daily_summary.php?' + q, '_blank'); };
        }
        if (el('btn_xls_activity')) el('btn_xls_activity').href = BASE + 'reports/activity_export.php?' + q + '&format=excel';
        if (el('btn_xls_order'))    el('btn_xls_order').href    = BASE + 'reports/order_export.php?'    + q + '&format=excel';
    };
    document.addEventListener('DOMContentLoaded', function () {
        const inpFrom = el('rep_date_from');
        const inpTo   = el('rep_date_to');
        if (inpFrom || inpTo) {
            try {
                let applied = false;
                const savedFrom = localStorage.getItem('report_range_from_last');
                const savedTo   = localStorage.getItem('report_range_to_last');
                if (inpFrom && isDate(savedFrom)) { inpFrom.value = savedFrom; applied = true; }
                if (inpTo   && isDate(savedTo))   { inpTo.value   = savedTo;   applied = true; }
                if (applied) window.updateReportLinks();
            } catch (e) {}
        }
    });
})();
</script>

<!-- =============================================================
     💰 MODAL UBAH TARIF STANDAR (Supervisor / Manager / Admin)
     ============================================================= -->
<?php if (in_array($userRole, ['supervisor','manager','admin'], true)): ?>
<div id="tariffModal" class="hidden fixed inset-0 z-[120] px-3 sm:px-4 py-6 sm:py-8 bg-slate-900/70 backdrop-blur-sm items-center justify-center" aria-hidden="true" style="display:none;">
    <form method="POST" action="" class="w-full max-w-md mx-auto bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 max-h-[90vh] flex flex-col">
        <input type="hidden" name="action" value="save_tariff">
        <div class="flex-shrink-0 px-5 py-4 border-b border-slate-200 bg-gradient-to-br from-slate-50 to-white rounded-t-2xl flex items-start gap-3">
            <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center shadow-md flex-shrink-0">
                <i class="fas fa-coins"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h3 class="font-display text-lg font-black text-slate-900 leading-tight">Ubah Tarif Standar</h3>
                <p class="mt-0.5 text-[10px] text-slate-500 leading-relaxed">Cost otomatis dihitung ulang. Perubahan berlaku LANGSUNG ke semua halaman (dashboard / laporan PDF / Excel).</p>
            </div>
            <button type="button" onclick="document.getElementById('tariffModal').classList.add('hidden'); document.getElementById('tariffModal').style.display='';" class="w-8 h-8 -mr-1 -mt-1 rounded-lg hover:bg-slate-100 text-slate-500 hover:text-slate-900 transition flex items-center justify-center flex-shrink-0" title="Tutup">
                <i class="fas fa-times text-sm"></i>
            </button>
        </div>
        <div class="p-4 sm:p-5 space-y-3 overflow-y-auto flex-1 min-h-0">
            <div class="grid grid-cols-2 gap-3">
                <div class="space-y-1.5">
                    <label class="block text-[10px] font-black uppercase tracking-wider text-slate-600"><i class="fas fa-bolt mr-1"></i> PLN Listrik</label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5 text-[10px] font-black text-slate-400 border-r border-slate-200 pr-2 my-1.5">Rp</div>
                        <input type="number" min="0" step="1" name="electricity_per_kwh" value="<?= (int)$TARIF['electricity_per_kwh'] ?>" required
                               class="w-full pl-11 pr-3 py-2.5 text-sm font-bold rounded-xl border border-slate-300 focus:ring-2 focus:ring-slate-200 focus:border-slate-400 outline-none bg-white">
                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2.5 text-[10px] font-bold text-slate-400">/kWh</span>
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-[10px] font-black uppercase tracking-wider text-slate-700"><i class="fas fa-droplet mr-1"></i> PDAM Air</label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5 text-[10px] font-black text-slate-400 border-r border-slate-200 pr-2 my-1.5">Rp</div>
                        <input type="number" min="0" step="1" name="water_per_m3" value="<?= (int)$TARIF['water_per_m3'] ?>" required
                               class="w-full pl-11 pr-3 py-2.5 text-sm font-bold rounded-xl border border-slate-300 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 outline-none bg-white">
                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2.5 text-[10px] font-bold text-slate-400">/m3</span>
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-[10px] font-black uppercase tracking-wider text-slate-700"><i class="fas fa-fire mr-1"></i> LPG Gas</label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5 text-[10px] font-black text-slate-400 border-r border-slate-200 pr-2 my-1.5">Rp</div>
                        <input type="number" min="0" step="1" name="gas_per_kg" value="<?= (int)$TARIF['gas_per_kg'] ?>" required
                               class="w-full pl-11 pr-3 py-2.5 text-sm font-bold rounded-xl border border-slate-300 focus:ring-2 focus:ring-slate-200 focus:border-slate-400 outline-none bg-white">
                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2.5 text-[10px] font-bold text-slate-400">/kg</span>
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-[10px] font-black uppercase tracking-wider text-slate-600"><i class="fas fa-gas-pump mr-1"></i> Solar BBM</label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5 text-[10px] font-black text-slate-400 border-r border-slate-200 pr-2 my-1.5">Rp</div>
                        <input type="number" min="0" step="1" name="fuel_per_liter" value="<?= (int)$TARIF['fuel_per_liter'] ?>" required
                               class="w-full pl-11 pr-3 py-2.5 text-sm font-bold rounded-xl border border-slate-300 focus:ring-2 focus:ring-slate-200 focus:border-slate-400 outline-none bg-white">
                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2.5 text-[10px] font-bold text-slate-400">/Ltr</span>
                    </div>
                </div>
            </div>
            <div class="mt-1 text-[10px] text-slate-400 leading-relaxed bg-slate-50 rounded-lg border border-slate-200 p-2">
                <i class="fas fa-lightbulb text-slate-500 mr-1"></i> <strong class="text-slate-600">Catatan:</strong> tarif berbeda tiap hari? Isi sesuai tarif terbaru hari itu sebelum lihat cost summary. Simpanan tarif otomatis tersimpan global.
            </div>
        </div>
        <div class="flex-shrink-0 px-5 py-3.5 border-t border-slate-200 bg-white rounded-b-2xl flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('tariffModal').classList.add('hidden'); document.getElementById('tariffModal').style.display='';" class="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 transition text-xs font-bold flex items-center gap-1.5">
                <i class="fas fa-times"></i> Batal
            </button>
            <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:bg-slate-800 active:bg-slate-950 transition text-xs font-bold flex items-center gap-1.5 shadow-md shadow-slate-900/20">
                <i class="fas fa-floppy-disk"></i> Simpan Tarif
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
