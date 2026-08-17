<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = T('form_title', 'Isi Daily Log Engineering');
requireRole(['engineer', 'supervisor', 'manager']); // Manager Access All

$db = Database::getInstance();
$user = currentUser();
$roleLower = strtolower((string)($user['role'] ?? ''));
$isEngineerRole = $roleLower === 'engineer';
$canChooseEngineer = in_array($roleLower, ['supervisor','manager','admin'], true); // Manager/Spv bisa pilih engineer mana mau diisi

// ==============================================
// 🔧 AUTO MIGRATION: Tambah 3 kolom KPI + 4 kolom Tarif jika belum ada
// ==============================================
$_dlMigFlag = $db->fetchAll("SHOW COLUMNS FROM daily_logs LIKE 'itr_score'");
if (empty($_dlMigFlag)) {
    try {
        $pdoMig = $db->getConnection();
        $pdoMig->exec("ALTER TABLE daily_logs ADD COLUMN `itr_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'Index Tata Ruang (KPI)' AFTER `occ_rate`");
        $pdoMig->exec("ALTER TABLE daily_logs ADD COLUMN `mu_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'Maintenance & Utility (KPI)' AFTER `itr_score`");
        $pdoMig->exec("ALTER TABLE daily_logs ADD COLUMN `gitb_rank` TINYINT UNSIGNED DEFAULT NULL COMMENT 'Guest in the Book Rank (KPI)' AFTER `mu_score`");
    } catch (\Throwable $e) { /* Already exists, safe ignore */ }
}
// --- 4 kolom TARIF SNAPSHOT per-log (tidak berubah ketika global tarif diupdate) ---
$_dlMigFlag2 = $db->fetchAll("SHOW COLUMNS FROM daily_logs LIKE 'tariff_electricity_per_kwh'");
if (empty($_dlMigFlag2)) {
    try {
        $pdoMig2 = $db->getConnection();
        $afterCol = 'gitb_rank';
        $cols = $db->fetchAll("SHOW COLUMNS FROM daily_logs");
        $haveGitb = false; foreach ($cols as $c) if (strtolower($c['Field']) === 'gitb_rank') $haveGitb = true;
        if (!$haveGitb) $afterCol = 'mu_score';
        $colsLC = []; foreach ($cols as $c) $colsLC[strtolower($c['Field'])] = true;
        if (!isset($colsLC['tariff_electricity_per_kwh']))
            $pdoMig2->exec("ALTER TABLE daily_logs ADD COLUMN `tariff_electricity_per_kwh` INT UNSIGNED DEFAULT NULL COMMENT 'Snapshot Tarif PLN (Rp/kWh)' AFTER `{$afterCol}`");
        if (!isset($colsLC['tariff_water_per_m3']))
            $pdoMig2->exec("ALTER TABLE daily_logs ADD COLUMN `tariff_water_per_m3` INT UNSIGNED DEFAULT NULL COMMENT 'Snapshot Tarif PDAM (Rp/m3)' AFTER `tariff_electricity_per_kwh`");
        if (!isset($colsLC['tariff_gas_per_kg']))
            $pdoMig2->exec("ALTER TABLE daily_logs ADD COLUMN `tariff_gas_per_kg` INT UNSIGNED DEFAULT NULL COMMENT 'Snapshot Tarif LPG (Rp/kg)' AFTER `tariff_water_per_m3`");
        if (!isset($colsLC['tariff_fuel_per_liter']))
            $pdoMig2->exec("ALTER TABLE daily_logs ADD COLUMN `tariff_fuel_per_liter` INT UNSIGNED DEFAULT NULL COMMENT 'Snapshot Tarif Solar (Rp/Ltr)' AFTER `tariff_gas_per_kg`");
    } catch (\Throwable $e) { /* Already exists, safe ignore */ }
}
// --- BARU: Kolom SHIFT (Pagi/Siang/Malam) ---
$_dlMigFlag3 = $db->fetchAll("SHOW COLUMNS FROM daily_logs LIKE 'shift'");
if (empty($_dlMigFlag3)) {
    try {
        $pdoMig3 = $db->getConnection();
        // cari posisi setelah log_date (jika tidak ada, fallback taruh setelah id)
        $cols3 = $db->fetchAll("SHOW COLUMNS FROM daily_logs");
        $afterCol3 = 'id';
        foreach ($cols3 as $c) if (strtolower($c['Field']) === 'log_date') $afterCol3 = 'log_date';
        $pdoMig3->exec("ALTER TABLE daily_logs ADD COLUMN `shift` ENUM('pagi','siang','malam') DEFAULT NULL COMMENT 'Shift Pagi(06-14)/Siang(14-22)/Malam(22-06)' AFTER `{$afterCol3}`");
    } catch (\Throwable $e) { /* Already exists, safe ignore */ }
}
// --- BARU: Kolom EQUIPMENT_DATA (8 Section: Trafo/Genset/PumpRoom/Chiller/CT/RO/Pool/Gas) JSON LONGTEXT ---
$_dlMigFlag4 = $db->fetchAll("SHOW COLUMNS FROM daily_logs LIKE 'equipment_data'");
if (empty($_dlMigFlag4)) {
    try {
        $pdoMig4 = $db->getConnection();
        $cols4 = $db->fetchAll("SHOW COLUMNS FROM daily_logs");
        $afterCol4 = 'tariff_fuel_per_liter';
        $cols4LC = []; foreach ($cols4 as $c) $cols4LC[strtolower($c['Field'])] = true;
        if (!isset($cols4LC['tariff_fuel_per_liter'])) {
            if (isset($cols4LC['gitb_rank'])) $afterCol4 = 'gitb_rank';
            else $afterCol4 = 'total_fuel';
        }
        $pdoMig4->exec("ALTER TABLE daily_logs ADD COLUMN `equipment_data` LONGTEXT NULL COMMENT '8 Section Equipment Log JSON (Trafo,Genset,Pump,Chiller,CT,RO,Pool,Gas)' AFTER `{$afterCol4}`");
    } catch (\Throwable $e) { /* Already exists, safe ignore */ }
}
unset($_dlMigFlag, $_dlMigFlag2, $_dlMigFlag3, $_dlMigFlag4, $cols, $colsLC, $cols3, $cols4, $cols4LC);

// ==============================================
// 🔧 HELPER: Build Equipment Section Data (sama pattern dengan energy_logsheet)
// ==============================================
function buildDailyEquipSectionData() {
    return [
        'trafo' => [
            'units' => [
                1 => ['temp_c' => (float)($_POST['trafo_1_temp'] ?? 0), 'ampere_lvdp' => (float)($_POST['trafo_1_ampere'] ?? 0), 'oil_level_pct' => (float)($_POST['trafo_1_oil'] ?? 0)],
                2 => ['temp_c' => (float)($_POST['trafo_2_temp'] ?? 0), 'ampere_lvdp' => (float)($_POST['trafo_2_ampere'] ?? 0), 'oil_level_pct' => (float)($_POST['trafo_2_oil'] ?? 0)]
            ]
        ],
        'genset' => [
            'gen_1_volt' => (float)($_POST['genset_1_volt'] ?? 0),
            'gen_2_volt' => (float)($_POST['genset_2_volt'] ?? 0),
            'gen_3_volt' => (float)($_POST['genset_3_volt'] ?? 0),
            'fuel_tank_liter' => (float)($_POST['genset_fuel_tank'] ?? 0)
        ],
        'pump_room' => [
            'steam_boiler' => [
                'unit_op' => (string)($_POST['pump_sb_unit_op'] ?? 'off'),
                'sb1_running_hours' => (string)($_POST['pump_sb1_hours'] ?? ''),
                'sb2_running_hours' => (string)($_POST['pump_sb2_hours'] ?? ''),
                'water_test_tds_ph' => (string)($_POST['pump_sb_test'] ?? ''),
                'steam_pressure_kgcm2' => (string)($_POST['pump_sb_press'] ?? ''),
                'time_blow_down' => (string)($_POST['pump_sb_blow'] ?? ''),
                'econ_temp_c' => (string)($_POST['pump_sb_econ_temp'] ?? ''),
                'econ_press_psi_kgcm2' => (string)($_POST['pump_sb_econ_press'] ?? '')
            ],
            'hot_water_boiler' => [
                'unit_op' => (string)($_POST['pump_hwb_unit_op'] ?? 'off'),
                'hwb1_running_hours' => (string)($_POST['pump_hwb1_hours'] ?? ''),
                'hwb2_running_hours' => (string)($_POST['pump_hwb2_hours'] ?? ''),
                'hw_temp_c' => (string)($_POST['pump_hwb_temp'] ?? ''),
                'water_test_tds_ph' => (string)($_POST['pump_hwb_test'] ?? ''),
                'circ_pump_unit_op' => (string)($_POST['pump_hwb_circ_op'] ?? ''),
                'flow_press_psi_kgcm2' => (string)($_POST['pump_hwb_flow'] ?? ''),
                'return_press_psi_kgcm2' => (string)($_POST['pump_hwb_ret'] ?? '')
            ],
            'ground_tank' => [
                'raw_tank_level_pct_tds_ph' => (string)($_POST['pump_tank_raw'] ?? ''),
                'treated_tank_level_pct_tds_ph' => (string)($_POST['pump_tank_treated'] ?? ''),
                'irigation_tank_level_pct' => (string)($_POST['pump_tank_irigasi'] ?? '')
            ],
            'hydrant_pump' => [
                'unit_standby_auto' => (string)($_POST['pump_hyd_standby'] ?? 'auto'),
                'press_pump1' => (string)($_POST['pump_hyd_press1'] ?? ''),
                'press_pump2' => (string)($_POST['pump_hyd_press2'] ?? '')
            ],
            'jockey_pump' => [
                'standby_press_kgcm2' => (string)($_POST['pump_jockey_press'] ?? '')
            ],
            'sand_filter' => [
                'status' => (string)($_POST['pump_sf_status'] ?? 'off'),
                'water_press_sand_psi_kgcm2' => (string)($_POST['pump_sf_press_sand'] ?? ''),
                'water_press_carbon_psi_kgcm2' => (string)($_POST['pump_sf_press_carbon'] ?? '')
            ],
            'sand_filter_pump' => [
                'status' => (string)($_POST['pump_sfp_status'] ?? 'off'),
                'unit_op' => (string)($_POST['pump_sfp_unit_op'] ?? ''),
                'water_press_psi_kgcm2' => (string)($_POST['pump_sfp_press'] ?? '')
            ],
            'booster_pump_villa' => [
                'unit_op' => (string)($_POST['pump_bpv_unit_op'] ?? '0'),
                'water_press_psi_kgcm2' => (string)($_POST['pump_bpv_press'] ?? '')
            ],
            'booster_pump_main_house' => [
                'unit_op' => (string)($_POST['pump_bpm_unit_op'] ?? '0'),
                'water_press_psi_kgcm2' => (string)($_POST['pump_bpm_press'] ?? '')
            ],
            'irrigation_pump' => [
                'unit_op' => (string)($_POST['pump_irigasi_unit_op'] ?? '0'),
                'water_press_psi_kgcm2' => (string)($_POST['pump_irigasi_press'] ?? '')
            ]
        ],
        'chiller_system' => [
            'chiller' => [
                'unit_op' => (string)($_POST['chiller_unit_op'] ?? 'carrier'),
                'chilled_water_test_tds_ph' => (string)($_POST['chiller_cw_test'] ?? '')
            ],
            'condensor_water_pump' => [
                'unit_op' => (string)($_POST['chiller_cwp_unit_op'] ?? '1'),
                'water_press_kgcm2' => (string)($_POST['chiller_cwp_press'] ?? '')
            ],
            'chilled_water_pump' => [
                'unit_op' => (string)($_POST['chiller_chwp_unit_op'] ?? '1'),
                'water_press_in_kgcm2' => (string)($_POST['chiller_chwp_in'] ?? ''),
                'water_press_out_kgcm2' => (string)($_POST['chiller_chwp_out'] ?? '')
            ]
        ],
        'cooling_tower' => [
            'unit_op' => (string)($_POST['ct_unit_op'] ?? '1'),
            'water_level_pct' => (string)($_POST['ct_level'] ?? ''),
            'water_test_tds_ph' => (string)($_POST['ct_test'] ?? '')
        ],
        'reverse_osmosis' => [
            'water_meter_m3' => (string)($_POST['ro_meter'] ?? ''),
            'water_permeate_m3ph' => (string)($_POST['ro_permeate'] ?? ''),
            'tds_ph_permeate' => (string)($_POST['ro_test_permeate'] ?? ''),
            'tds_ph_deep_well' => (string)($_POST['ro_test_deepwell'] ?? '')
        ],
        'pool_system' => [
            'lagoon_1' => [
                'alarm_on_off' => (string)($_POST['pool_l1_alarm'] ?? 'on'),
                'pump_running_unit_op' => (string)($_POST['pool_l1_pump'] ?? '1'),
                'pressure_tank_kgcm2' => (string)($_POST['pool_l1_press'] ?? ''),
                'submersible_auto' => (string)($_POST['pool_l1_sub_auto'] ?? 'auto')
            ],
            'lagoon_2' => [
                'alarm_on_off' => (string)($_POST['pool_l2_alarm'] ?? 'on'),
                'pump_running_unit_op' => (string)($_POST['pool_l2_pump'] ?? '1'),
                'pressure_tank_kgcm2' => (string)($_POST['pool_l2_press'] ?? ''),
                'submersible_auto' => (string)($_POST['pool_l2_sub_auto'] ?? 'auto')
            ],
            'aquavitale' => [
                'alarm_on_off' => (string)($_POST['pool_aqua_alarm'] ?? 'on'),
                'pump_running_unit_op' => (string)($_POST['pool_aqua_pump'] ?? '7'),
                'hot_water_boiler_temp_c' => (string)($_POST['pool_aqua_hwbtemp'] ?? ''),
                'submersible_auto' => (string)($_POST['pool_aqua_sub_auto'] ?? 'auto')
            ],
            'main_pump_room' => [
                'alarm_on_off' => (string)($_POST['pool_mpr_alarm'] ?? 'on'),
                'submersible_auto' => (string)($_POST['pool_mpr_sub_auto'] ?? 'auto')
            ]
        ],
        'gas_system' => [
            'detector_boneka_resto' => [
                'selenoid_valve_open_close' => (string)($_POST['gas_boneka_valve'] ?? 'open'),
                'alarm_on_off' => (string)($_POST['gas_boneka_alarm'] ?? 'on')
            ],
            'detector_main_kitchen' => [
                'selenoid_valve_open_close' => (string)($_POST['gas_mainkitchen_valve'] ?? 'open'),
                'alarm_on_off' => (string)($_POST['gas_mainkitchen_alarm'] ?? 'on')
            ],
            'detector_kayu_puti_resto' => [
                'selenoid_valve_open_close' => (string)($_POST['gas_kayuputih_valve'] ?? 'open'),
                'alarm_on_off' => (string)($_POST['gas_kayuputih_alarm'] ?? 'on')
            ]
        ]
    ];
}

$date = $_GET['date'] ?? date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $date) || $date > date('Y-m-d')) {
    setFlash('error', T('form_error_invalid_date', 'Tanggal tidak valid'));
    redirect('engineer/select_date.php');
}

// ========== Manager/Supervisor Access All: pilih engineer_id yang mau diisi ==========
$engineerOptions = [];
$targetEngineerId = (int)$user['id'];
if ($canChooseEngineer) {
    // Ambil semua user engineer + supervisor (all option)
    $engineerOptions = $db->fetchAll(
        "SELECT id, name, role, position FROM users WHERE status='active' ORDER BY FIELD(role,'engineer','supervisor','manager','admin'), name ASC"
    );
    // Cek parameter ?engineer_id= atau POST target_engineer_id
    $reqEngId = isset($_GET['engineer_id']) ? (int)$_GET['engineer_id'] : (isset($_POST['_target_engineer_id']) ? (int)$_POST['_target_engineer_id'] : 0);
    if ($reqEngId > 0) {
        $foundEng = false;
        foreach ($engineerOptions as $eo) if ((int)$eo['id'] === $reqEngId) { $foundEng = true; break; }
        if ($foundEng) $targetEngineerId = $reqEngId;
    } else {
        // Default: jika tanggal sudah ada log milik siapapun → ambil engineer_id dari log terbaru
        $tmpFirst = $db->fetchOne("SELECT engineer_id FROM daily_logs WHERE log_date = ? ORDER BY id DESC LIMIT 1", [$date]);
        if ($tmpFirst && !empty($tmpFirst['engineer_id'])) $targetEngineerId = (int)$tmpFirst['engineer_id'];
    }
}

// Query existing log: Engineer = miliknya saja; Manager/Spv = sesuai targetEngineerId pilihan
// CATATAN (Revert Shift Multi Entries): 1 TANGGAL = 1 LOG SAJA per engineer (unique engineer_id + log_date).
//          Field 'shift' cuma catatan info tambahan di dalam form (PIC shift apa), BUKAN pemisah multiple entries per tanggal.
$log = $db->fetchOne(
    "SELECT * FROM daily_logs WHERE engineer_id = ? AND log_date = ?",
    [$targetEngineerId, $date]
);

// --- HELPER DEFAULT SHIFT BERDASARKAN JAM SEKARANG (WA: PAGI/SIANG/MALAM, catatan info di form SAJA) ---
// Pagi = 06:00 - 13:59, Siang = 14:00 - 21:59, Malam = 22:00 - 05:59
$allShifts = ['pagi','siang','malam'];
$_curHour = (int)date('H');
if ($_curHour >= 6 && $_curHour < 14) $defaultShiftNow = 'pagi';
elseif ($_curHour >= 14 && $_curHour < 22) $defaultShiftNow = 'siang';
else $defaultShiftNow = 'malam';

// --- READING METER MAIN BUILDING & LISTRIK (Yesterday MALAM - Today MALAM → Consumption) ---
// water_main_building / electricity = angka METER (READING) hari ini
// total_water / total_electricity   = consumption = today_read - yesterday_malam_read
// HANYA SHIFT MALAM SAJA YANG MENGHITUNG SELISIH → masuk ke total_electricity & total_water
// (Shift Pagi/Siang hanya catatan reading, TIDAK mempengaruhi total konsumsi → total = 0)
$yesterdayDate = date('Y-m-d', strtotime($date . ' -1 day'));
// Yesterday = cari log TANGGAL KEMARIN, SHIFT = MALAM (karena pola: MALAM ketemu MALAM)
$yesterdayLogMalam = $db->fetchOne(
    "SELECT water_main_building, electricity_wbp, electricity_lwbp
     FROM daily_logs
     WHERE engineer_id = ? AND log_date = ? AND COALESCE(shift,'malam') = 'malam'
     LIMIT 1",
    [$targetEngineerId, $yesterdayDate]
);
// Fallback: jika kemarin shift malam belum ada (data lama sebelum shift field), coba ambil log apapun kemarin
if (!$yesterdayLogMalam || empty($yesterdayLogMalam)) {
    $yesterdayLogMalam = $db->fetchOne(
        "SELECT water_main_building, electricity_wbp, electricity_lwbp
         FROM daily_logs WHERE engineer_id = ? AND log_date = ? LIMIT 1",
        [$targetEngineerId, $yesterdayDate]
    );
}
$mbYesterdayRead = $yesterdayLogMalam && isset($yesterdayLogMalam['water_main_building'])
    ? (float)$yesterdayLogMalam['water_main_building']
    : 0.0;
$elecYesterdayWbp = $yesterdayLogMalam && isset($yesterdayLogMalam['electricity_wbp'])
    ? (float)$yesterdayLogMalam['electricity_wbp']
    : 0.0;
$elecYesterdayLwbp = $yesterdayLogMalam && isset($yesterdayLogMalam['electricity_lwbp'])
    ? (float)$yesterdayLogMalam['electricity_lwbp']
    : 0.0;
$elecYesterdayTotal = $elecYesterdayWbp + $elecYesterdayLwbp;

$mbTodayRead = $log && isset($log['water_main_building']) ? (float)$log['water_main_building'] : 0.0;
$elecTodayWbp   = $log && isset($log['electricity_wbp'])   ? (float)$log['electricity_wbp']   : 0.0;
$elecTodayLwbp  = $log && isset($log['electricity_lwbp'])  ? (float)$log['electricity_lwbp']  : 0.0;
$elecTodayTotal = $elecTodayWbp + $elecTodayLwbp;

// Konsumsi hari ini (hanya berlaku jika SHIFT = MALAM. Pagi/Siang = 0)
$curLogShift = (!empty($log['shift']) && in_array($log['shift'], $allShifts, true)) ? (string)$log['shift'] : '';
$existingIsMalam = ($curLogShift === 'malam');
$mbConsumption      = $existingIsMalam ? max(0.0, $mbTodayRead     - $mbYesterdayRead)   : (float)($log['total_water'] ?? 0);
$elecConsumptionNow = $existingIsMalam ? max(0.0, $elecTodayTotal - $elecYesterdayTotal) : (float)($log['total_electricity'] ?? 0);
unset($existingIsMalam, $curLogShift);

// --- POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ① SHIFT (info catatan tambahan, TIDAK multiple entries) — cuma disimpan sebagai label di log
    $shift = trim((string)($_POST['shift'] ?? ''));
    if (!in_array($shift, $allShifts, true)) $shift = $defaultShiftNow; // aman fallback (tidak perlu error flash — cuma label)

    // ① Electricity Subdetails — SEMUA SHIFT BOLEH EDIT (Pagi/Siang/Malam)
    // Nilai WBP / LWBP = READING METER (sama seperti Main Building Water)
    // HANYA SHIFT MALAM SAJA YANG MENGHITUNG TOTAL KONSUMSI (today - yesterday_malam)
    // Shift Pagi/Siang: total_electricity = 0 (catatan reading saja, tidak masuk kalkulasi cost)
    $eWbp = (float)($_POST['electricity_wbp'] ?? 0);
    $eLwbp = (float)($_POST['electricity_lwbp'] ?? 0);
    $eTodayTotal = $eWbp + $eLwbp;
    $isShiftMalam = ($shift === 'malam');
    if ($isShiftMalam) {
        $electricity = max(0.0, $eTodayTotal - $elecYesterdayTotal);
    } else {
        $electricity = 0.0;
    }

    // ② Water 9 sources
    $wPdam = (float)($_POST['water_pdam'] ?? 0);
    $wIki = (float)($_POST['water_iki_gaban'] ?? 0);
    $wDw1 = (float)($_POST['water_deepwell_1'] ?? 0);
    $wDw2 = (float)($_POST['water_deepwell_2_brr'] ?? 0);
    $wDwAsean = (float)($_POST['water_deepwell_asean'] ?? 0);
    $wDwLpb = (float)($_POST['water_deepwell_lpb'] ?? 0);
    // Main Building: SEMUA SHIFT BOLEH EDIT READING METER (tidak dikunci lagi seperti sebelumnya)
    // HANYA SHIFT MALAM yang hitung konsumsi (today - yesterday_malam). Pagi/Siang total_water = 0.
    $wMainBldgRead = (float)($_POST['water_main_building'] ?? 0);
    if ($isShiftMalam) {
        $wMainBldgCons = max(0.0, $wMainBldgRead - $mbYesterdayRead);
        $water = $wMainBldgCons;
    } else {
        $wMainBldgCons = 0.0;
        $water = 0.0;
    }
    // $wMainBldg disamakan dengan reading (sesuai jawaban user: kolom existing = reading meter)
    $wMainBldg = $wMainBldgRead;
    $wCooling = (float)($_POST['water_cooling_tower'] ?? 0);
    $wBottling = (float)($_POST['water_bottling'] ?? 0);
    unset($eTodayTotal);

    // ③ Gas 2 types
    $gLpg = (float)($_POST['gas_lpg'] ?? 0);
    $gLng = (float)($_POST['gas_lng'] ?? 0);
    $gas = $gLpg + $gLng;

    // ④ SWRO 3
    $sWm = (float)($_POST['swro_watermeter'] ?? 0);
    $sKwh = (float)($_POST['swro_kwh'] ?? 0);
    $sTds = (float)($_POST['swro_tds'] ?? 0);

    // ⑤ Bottling 2
    $bKwh = (float)($_POST['bottling_kwh'] ?? 0);
    $bWm = (float)($_POST['bottling_watermeter'] ?? 0);

    // ⑥ Chiller System 8
    $ch1On = (int)(($_POST['chiller_1_on'] ?? 0) ? 1 : 0);
    $ch2On = (int)(($_POST['chiller_2_on'] ?? 0) ? 1 : 0);
    $ch3On = (int)(($_POST['chiller_3_on'] ?? 0) ? 1 : 0);
    $chPh = (float)($_POST['chiller_water_ph'] ?? 0);
    $chTds = (float)($_POST['chiller_water_tds'] ?? 0);
    $chTemp = (float)($_POST['chiller_temp'] ?? 0);
    $chChwp = (float)($_POST['chiller_pressure_chwp'] ?? 0);
    $chCwp = (float)($_POST['chiller_pressure_cwp'] ?? 0);

    // ⑦ Fuel
    $fuel = (float)($_POST['total_fuel'] ?? 0);

    // ⑧ Occupancy Rate (OCC %)
    $occRate = (float)($_POST['occ_rate'] ?? 0);
    if ($occRate < 0) $occRate = 0;
    if ($occRate > 100) $occRate = 100;

    // ⑧ BIS. ITR / M&U / GITB RANK (KPI Performance)
    $itrScore  = isset($_POST['itr_score'])  && $_POST['itr_score'] !== ''  ? (float)$_POST['itr_score']  : null;
    $muScore   = isset($_POST['mu_score'])   && $_POST['mu_score'] !== ''   ? (float)$_POST['mu_score']   : null;
    $gitbRank  = isset($_POST['gitb_rank'])  && $_POST['gitb_rank'] !== ''  ? (int)$_POST['gitb_rank']   : null;
    if ($gitbRank !== null && $gitbRank < 0) $gitbRank = null;
    if ($gitbRank !== null && $gitbRank > 255) $gitbRank = 255;
    if ($itrScore !== null) { if ($itrScore < 0) $itrScore = 0; if ($itrScore > 999.99) $itrScore = 999.99; }
    if ($muScore  !== null) { if ($muScore  < 0) $muScore  = 0; if ($muScore  > 999.99) $muScore  = 999.99; }

    // ⑧ TRIS. TARIF SNAPSHOT (per-log, permanen disimpan sesuai tanggal — tidak berubah jika global tarif dirubah besok)
    // — Hak akses: Engineer = SELALU pakai snapshot dari global settings (tidak boleh rubah)
    // — Hak akses: Supervisor / Manager = BISA override isi field manual (untuk penyesuaian nota PLN / tagihan asli)
    $isRoleCanEditTariff = in_array(($user['role'] ?? ''), ['supervisor','manager','admin'], true);
    $_defTar = getTariffSettings();
    $cleanTarFn = function($key, $post, $default, $min, $max) use ($isRoleCanEditTariff, $_defTar) {
        $fallback = (int)($_defTar[$key] ?? $default);
        if (!$isRoleCanEditTariff) return $fallback > 0 ? $fallback : null; // engineer = lock pakai global
        // Supervisor/Manager: cek apakah user isi POST? Jika kosong/ nol → pakai default global
        $raw = $post[$key] ?? null;
        if ($raw === null || $raw === '') return $fallback > 0 ? $fallback : null;
        $v = (int)$raw;
        if ($v <= 0) return $fallback > 0 ? $fallback : null;
        if ($v < $min) $v = $min;
        if ($v > $max) $v = $max;
        return $v;
    };
    $tarElec = $cleanTarFn('electricity_per_kwh', $_POST, 1850, 100, 10000000);
    $tarWater = $cleanTarFn('water_per_m3', $_POST, 9600, 100, 10000000);
    $tarGas = $cleanTarFn('gas_per_kg', $_POST, 24500, 100, 10000000);
    $tarFuel = $cleanTarFn('fuel_per_liter', $_POST, 17450, 100, 10000000);
    unset($cleanTarFn, $_defTar);

    // ⑨ Activity Counters
    // -- Baru: Counter OTOMATIS dari Dynamic Activity Rows (bukan input manual lagi)
    $actCats = ['operation','maintenance','project','landscape'];
    $actOp = $actMaint = $actProj = $actLand = 0;
    $activities = trim($_POST['work_activities'] ?? '');
    $actItems = $_POST['act'] ?? [];
    if (!is_array($actItems)) $actItems = [];
    $parsedActLines = [];
    $actRows = [];
    foreach ($actItems as $idx => $row) {
        $cat = $actCats[array_search($row['cat'] ?? '', $actCats, true)] ?? 'operation';
        $title = trim((string)($row['t'] ?? ''));
        if (strlen($title) < 1) continue;
        $actRows[] = ['cat' => $cat, 'title' => $title, 'sort' => (int)$idx];
        if ($cat === 'operation') $actOp++;
        elseif ($cat === 'maintenance') $actMaint++;
        elseif ($cat === 'project') $actProj++;
        elseif ($cat === 'landscape') $actLand++;
        $parsedActLines[] = "[".strtoupper($cat)."] ".$title;
    }
    if (count($parsedActLines) > 0) {
        $activities = implode("\n", $parsedActLines);
    } elseif (strlen($activities) < 2) {
        $activities = '';
    }
    $obstacles = trim($_POST['obstacles'] ?? '');
    $solutions = trim($_POST['solutions'] ?? '');

    if ($electricity < 0 || $water < 0 || $gas < 0) {
        setFlash('error', T('form_error_negative', 'Nilai konsumsi tidak boleh negatif'));
    } elseif (empty($activities)) {
        setFlash('error', T('form_error_activities', 'Aktivitas pekerjaan harus diisi'));
    } else {
        $photoFile = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = handleFileUpload('photo');
            if ($uploadResult['success']) {
                $photoFile = $uploadResult['filename'];
                if ($log && $log['photo_path']) {
                    $old = UPLOAD_PATH . $log['photo_path'];
                    if (file_exists($old)) @unlink($old);
                }
            } else {
                setFlash('warning', $uploadResult['error']);
            }
        }

        // ⑩ EQUIPMENT 8 SECTIONS (Trafo, Genset, PumpRoom, Chiller, CT, RO, Pool, Gas)
        $equipSectionData = buildDailyEquipSectionData();
        $equipJson = json_encode($equipSectionData);
        if ($equipJson === false) $equipJson = null;

        $data = [
            // 0 BARU: Shift Pagi/Siang/Malam
            'shift' => $shift,
            // Backwards Compatible Totals (auto sum dari sub field)
            'total_electricity' => $electricity,
            'total_water' => $water,
            'total_gas' => $gas,
            // ① Electricity WBP LWBP
            'electricity_wbp' => $eWbp,
            'electricity_lwbp' => $eLwbp,
            // ② 9 Water Sources
            'water_pdam' => $wPdam,
            'water_iki_gaban' => $wIki,
            'water_deepwell_1' => $wDw1,
            'water_deepwell_2_brr' => $wDw2,
            'water_deepwell_asean' => $wDwAsean,
            'water_deepwell_lpb' => $wDwLpb,
            'water_main_building' => $wMainBldg,
            'water_cooling_tower' => $wCooling,
            'water_bottling' => $wBottling,
            // ③ Gas LPG LNG
            'gas_lpg' => $gLpg,
            'gas_lng' => $gLng,
            // ④ SWRO
            'swro_watermeter' => $sWm,
            'swro_kwh' => $sKwh,
            'swro_tds' => $sTds,
            // ⑤ Bottling
            'bottling_kwh' => $bKwh,
            'bottling_watermeter' => $bWm,
            // ⑥ Chiller
            'chiller_1_on' => $ch1On,
            'chiller_2_on' => $ch2On,
            'chiller_3_on' => $ch3On,
            'chiller_water_ph' => $chPh,
            'chiller_water_tds' => $chTds,
            'chiller_temp' => $chTemp,
            'chiller_pressure_chwp' => $chChwp,
            'chiller_pressure_cwp' => $chCwp,
            // ⑦ Fuel
            'total_fuel' => $fuel,
            // ⑧ Occupancy Rate
            'occ_rate' => $occRate,
            // ⑧ BIS. KPI ITR / M&U / GITB RANK
            'itr_score' => $itrScore,
            'mu_score'  => $muScore,
            'gitb_rank' => $gitbRank,
            // ⑧ TRIS. TARIF SNAPSHOT (Permanen per-tanggal, tidak berubah ketika global diupdate besok)
            'tariff_electricity_per_kwh' => $tarElec,
            'tariff_water_per_m3' => $tarWater,
            'tariff_gas_per_kg' => $tarGas,
            'tariff_fuel_per_liter' => $tarFuel,
            // ⑩ EQUIPMENT DATA JSON
            'equipment_data' => $equipJson,
            // ⑨ Activity Counters
            'activity_operation' => $actOp,
            'activity_maintenance' => $actMaint,
            'activity_project' => $actProj,
            'activity_landscape' => $actLand,
            // Standard content
            'work_activities' => $activities,
            'obstacles' => $obstacles,
            'solutions' => $solutions,
        ];

        if ($photoFile) {
            $data['photo_path'] = $photoFile;
        }

        if ($log) {
            $data['status'] = 'pending';
            $data['revision_notes'] = null;
            $data['supervisor_id'] = null;
            $data['supervisor_signature'] = null;
            $data['approved_at'] = null;
            $db->update('daily_logs', $data, 'id = :id', ['id' => $log['id']]);
            $logId = (int)$log['id'];
        } else {
            $data['log_date'] = $date;
            $data['engineer_id'] = $targetEngineerId; // Manager Access All: sesuai pilihan engineer dropdown
            $db->insert('daily_logs', $data);
            $logId = (int)$db->lastInsertId();
        }
        // -- Simpan Child Activity Rows (replace all)
        if ($logId > 0) {
            $pdoC = $db->getConnection();
            $pdoC->exec("DELETE FROM daily_log_activities WHERE daily_log_id = " . $logId);
            if (count($actRows) > 0) {
                $stmt = $pdoC->prepare("INSERT INTO daily_log_activities (daily_log_id, category, activity_title, sort_order) VALUES (?,?,?,?)");
                foreach ($actRows as $ar) {
                    $stmt->execute([$logId, $ar['cat'], $ar['title'], $ar['sort']]);
                }
            }
        }
        setFlash('success', $log ? T('form_success_update', 'Daily Log berhasil diperbarui dan menunggu approval') : T('form_success_save', 'Daily Log berhasil disimpan dan menunggu approval'));
        // Manager Access All: redirect balik bawa engineer_id biar nyambung pilihannya (shift cuma label, TIDAK dijadikan parameter QS)
        $redirectQs = $canChooseEngineer ? '?engineer_id=' . $targetEngineerId : '';
        redirect('engineer/select_date.php' . $redirectQs);
    }   // end: if (activity ok, electricity ok etc)
}           // end: if POST REQUEST

// Refresh log setelah handler (agar targetEngineerId yang baru di-post / di-GET dipakai ulang load form untuk display)
$log = $db->fetchOne(
    "SELECT * FROM daily_logs WHERE engineer_id = ? AND log_date = ?",
    [$targetEngineerId, $date]
);

$existingActivities = [];
if ($log && !empty($log['id'])) {
    $existingActivities = $db->fetchAll("SELECT id, category, activity_title FROM daily_log_activities WHERE daily_log_id = ? ORDER BY sort_order ASC, id ASC", [(int)$log['id']]);
}

// =========================================================
// 🧰 PARSE EXISTING EQUIPMENT DATA (JSON) untuk Default Value Form
// =========================================================
$eq = [
    'trafo' => ['units'=>[1=>['temp_c'=>0,'ampere_lvdp'=>0,'oil_level_pct'=>0], 2=>['temp_c'=>0,'ampere_lvdp'=>0,'oil_level_pct'=>0]]],
    'genset' => ['gen_1_volt'=>0,'gen_2_volt'=>0,'gen_3_volt'=>0,'fuel_tank_liter'=>0],
    'pump' => [
        'sb_unit_op'=>'off','sb1_hours'=>'','sb2_hours'=>'','sb_test'=>'','sb_press'=>'','sb_blow'=>'','sb_econ_temp'=>'','sb_econ_press'=>'',
        'hwb_unit_op'=>'off','hwb1_hours'=>'','hwb2_hours'=>'','hwb_temp'=>'','hwb_test'=>'','hwb_circ_op'=>'','hwb_flow'=>'','hwb_ret'=>'',
        'tank_raw'=>'','tank_treated'=>'','tank_irigasi'=>'',
        'hyd_standby'=>'auto','hyd_press1'=>'','hyd_press2'=>'',
        'jockey_press'=>'',
        'sf_status'=>'off','sf_press_sand'=>'','sf_press_carbon'=>'',
        'sfp_status'=>'off','sfp_unit_op'=>'','sfp_press'=>'',
        'bpv_unit_op'=>'0','bpv_press'=>'',
        'bpm_unit_op'=>'0','bpm_press'=>'',
        'irigasi_unit_op'=>'0','irigasi_press'=>'',
    ],
    'chiller' => ['unit_op'=>'carrier','cw_test'=>'','cwp_unit_op'=>'1','cwp_press'=>'','chwp_unit_op'=>'1','chwp_in'=>'','chwp_out'=>''],
    'ct' => ['unit_op'=>'1','level'=>'','test'=>''],
    'ro' => ['meter'=>'','permeate'=>'','test_permeate'=>'','test_deepwell'=>''],
    'pool' => [
        'l1_alarm'=>'on','l1_pump'=>'1','l1_press'=>'','l1_sub_auto'=>'auto',
        'l2_alarm'=>'on','l2_pump'=>'1','l2_press'=>'','l2_sub_auto'=>'auto',
        'aqua_alarm'=>'on','aqua_pump'=>'7','aqua_press'=>'','aqua_hwbtemp'=>'','aqua_sub_auto'=>'auto',
        'mpr_alarm'=>'on','mpr_pump'=>'','mpr_press'=>'','mpr_hwbtemp'=>'','mpr_sub_auto'=>'auto',
    ],
    'gas' => [
        'boneka_valve'=>'open','boneka_alarm'=>'on',
        'mainkitchen_valve'=>'open','mainkitchen_alarm'=>'on',
        'kayuputih_valve'=>'open','kayuputih_alarm'=>'on',
    ],
];
if ($log && !empty($log['equipment_data'])) {
    $_eqDec = json_decode((string)$log['equipment_data'], true);
    if (is_array($_eqDec)) {
        // Trafo
        if (isset($_eqDec['trafo']['units'][1])) { foreach ($_eqDec['trafo']['units'][1] as $k=>$v) if (isset($eq['trafo']['units'][1][$k])) $eq['trafo']['units'][1][$k] = $v; }
        if (isset($_eqDec['trafo']['units'][2])) { foreach ($_eqDec['trafo']['units'][2] as $k=>$v) if (isset($eq['trafo']['units'][2][$k])) $eq['trafo']['units'][2][$k] = $v; }
        // Genset
        if (isset($_eqDec['genset'])) { foreach ($_eqDec['genset'] as $k=>$v) if (isset($eq['genset'][$k])) $eq['genset'][$k] = $v; }
        // Pump Room
        if (isset($_eqDec['pump_room']['steam_boiler'])) {
            $sb = &$_eqDec['pump_room']['steam_boiler'];
            if (isset($sb['unit_op'])) $eq['pump']['sb_unit_op'] = (string)$sb['unit_op'];
            if (isset($sb['sb1_running_hours'])) $eq['pump']['sb1_hours'] = (string)$sb['sb1_running_hours'];
            if (isset($sb['sb2_running_hours'])) $eq['pump']['sb2_hours'] = (string)$sb['sb2_running_hours'];
            if (isset($sb['water_test_tds_ph'])) $eq['pump']['sb_test'] = (string)$sb['water_test_tds_ph'];
            if (isset($sb['steam_pressure_kgcm2'])) $eq['pump']['sb_press'] = (string)$sb['steam_pressure_kgcm2'];
            if (isset($sb['time_blow_down'])) $eq['pump']['sb_blow'] = (string)$sb['time_blow_down'];
            if (isset($sb['econ_temp_c'])) $eq['pump']['sb_econ_temp'] = (string)$sb['econ_temp_c'];
            if (isset($sb['econ_press_psi_kgcm2'])) $eq['pump']['sb_econ_press'] = (string)$sb['econ_press_psi_kgcm2'];
        }
        if (isset($_eqDec['pump_room']['hot_water_boiler'])) {
            $hwb = &$_eqDec['pump_room']['hot_water_boiler'];
            if (isset($hwb['unit_op'])) $eq['pump']['hwb_unit_op'] = (string)$hwb['unit_op'];
            if (isset($hwb['hwb1_running_hours'])) $eq['pump']['hwb1_hours'] = (string)$hwb['hwb1_running_hours'];
            if (isset($hwb['hwb2_running_hours'])) $eq['pump']['hwb2_hours'] = (string)$hwb['hwb2_running_hours'];
            if (isset($hwb['hw_temp_c'])) $eq['pump']['hwb_temp'] = (string)$hwb['hw_temp_c'];
            if (isset($hwb['water_test_tds_ph'])) $eq['pump']['hwb_test'] = (string)$hwb['water_test_tds_ph'];
            if (isset($hwb['circ_pump_unit_op'])) $eq['pump']['hwb_circ_op'] = (string)$hwb['circ_pump_unit_op'];
            if (isset($hwb['flow_press_psi_kgcm2'])) $eq['pump']['hwb_flow'] = (string)$hwb['flow_press_psi_kgcm2'];
            if (isset($hwb['return_press_psi_kgcm2'])) $eq['pump']['hwb_ret'] = (string)$hwb['return_press_psi_kgcm2'];
        }
        if (isset($_eqDec['pump_room']['ground_tank'])) {
            $gt = &$_eqDec['pump_room']['ground_tank'];
            if (isset($gt['raw_tank_level_pct_tds_ph'])) $eq['pump']['tank_raw'] = (string)$gt['raw_tank_level_pct_tds_ph'];
            if (isset($gt['treated_tank_level_pct_tds_ph'])) $eq['pump']['tank_treated'] = (string)$gt['treated_tank_level_pct_tds_ph'];
            if (isset($gt['irigation_tank_level_pct'])) $eq['pump']['tank_irigasi'] = (string)$gt['irigation_tank_level_pct'];
        }
        if (isset($_eqDec['pump_room']['hydrant_pump'])) {
            $hp = &$_eqDec['pump_room']['hydrant_pump'];
            if (isset($hp['unit_standby_auto'])) $eq['pump']['hyd_standby'] = (string)$hp['unit_standby_auto'];
            if (isset($hp['press_pump1'])) $eq['pump']['hyd_press1'] = (string)$hp['press_pump1'];
            if (isset($hp['press_pump2'])) $eq['pump']['hyd_press2'] = (string)$hp['press_pump2'];
        }
        if (isset($_eqDec['pump_room']['jockey_pump']['standby_press_kgcm2'])) $eq['pump']['jockey_press'] = (string)$_eqDec['pump_room']['jockey_pump']['standby_press_kgcm2'];
        if (isset($_eqDec['pump_room']['sand_filter'])) {
            $sf = &$_eqDec['pump_room']['sand_filter'];
            if (isset($sf['status'])) $eq['pump']['sf_status'] = (string)$sf['status'];
            if (isset($sf['water_press_sand_psi_kgcm2'])) $eq['pump']['sf_press_sand'] = (string)$sf['water_press_sand_psi_kgcm2'];
            if (isset($sf['water_press_carbon_psi_kgcm2'])) $eq['pump']['sf_press_carbon'] = (string)$sf['water_press_carbon_psi_kgcm2'];
        }
        if (isset($_eqDec['pump_room']['sand_filter_pump'])) {
            $sfp = &$_eqDec['pump_room']['sand_filter_pump'];
            if (isset($sfp['status'])) $eq['pump']['sfp_status'] = (string)$sfp['status'];
            if (isset($sfp['unit_op'])) $eq['pump']['sfp_unit_op'] = (string)$sfp['unit_op'];
            if (isset($sfp['water_press_psi_kgcm2'])) $eq['pump']['sfp_press'] = (string)$sfp['water_press_psi_kgcm2'];
        }
        if (isset($_eqDec['pump_room']['booster_pump_villa'])) {
            $bpv = &$_eqDec['pump_room']['booster_pump_villa'];
            if (isset($bpv['unit_op'])) $eq['pump']['bpv_unit_op'] = (string)$bpv['unit_op'];
            if (isset($bpv['water_press_psi_kgcm2'])) $eq['pump']['bpv_press'] = (string)$bpv['water_press_psi_kgcm2'];
        }
        if (isset($_eqDec['pump_room']['booster_pump_main_house'])) {
            $bpm = &$_eqDec['pump_room']['booster_pump_main_house'];
            if (isset($bpm['unit_op'])) $eq['pump']['bpm_unit_op'] = (string)$bpm['unit_op'];
            if (isset($bpm['water_press_psi_kgcm2'])) $eq['pump']['bpm_press'] = (string)$bpm['water_press_psi_kgcm2'];
        }
        if (isset($_eqDec['pump_room']['irrigation_pump'])) {
            $ip = &$_eqDec['pump_room']['irrigation_pump'];
            if (isset($ip['unit_op'])) $eq['pump']['irigasi_unit_op'] = (string)$ip['unit_op'];
            if (isset($ip['water_press_psi_kgcm2'])) $eq['pump']['irigasi_press'] = (string)$ip['water_press_psi_kgcm2'];
        }
        // Chiller System
        if (isset($_eqDec['chiller_system']['chiller'])) {
            $cs_c = &$_eqDec['chiller_system']['chiller'];
            if (isset($cs_c['unit_op'])) $eq['chiller']['unit_op'] = (string)$cs_c['unit_op'];
            if (isset($cs_c['chilled_water_test_tds_ph'])) $eq['chiller']['cw_test'] = (string)$cs_c['chilled_water_test_tds_ph'];
        }
        if (isset($_eqDec['chiller_system']['condensor_water_pump'])) {
            $cs_cwp = &$_eqDec['chiller_system']['condensor_water_pump'];
            if (isset($cs_cwp['unit_op'])) $eq['chiller']['cwp_unit_op'] = (string)$cs_cwp['unit_op'];
            if (isset($cs_cwp['water_press_kgcm2'])) $eq['chiller']['cwp_press'] = (string)$cs_cwp['water_press_kgcm2'];
        }
        if (isset($_eqDec['chiller_system']['chilled_water_pump'])) {
            $cs_chwp = &$_eqDec['chiller_system']['chilled_water_pump'];
            if (isset($cs_chwp['unit_op'])) $eq['chiller']['chwp_unit_op'] = (string)$cs_chwp['unit_op'];
            if (isset($cs_chwp['water_press_in_kgcm2'])) $eq['chiller']['chwp_in'] = (string)$cs_chwp['water_press_in_kgcm2'];
            if (isset($cs_chwp['water_press_out_kgcm2'])) $eq['chiller']['chwp_out'] = (string)$cs_chwp['water_press_out_kgcm2'];
        }
        // Cooling Tower
        if (isset($_eqDec['cooling_tower'])) {
            $ctd = &$_eqDec['cooling_tower'];
            if (isset($ctd['unit_op'])) $eq['ct']['unit_op'] = (string)$ctd['unit_op'];
            if (isset($ctd['water_level_pct'])) $eq['ct']['level'] = (string)$ctd['water_level_pct'];
            if (isset($ctd['water_test_tds_ph'])) $eq['ct']['test'] = (string)$ctd['water_test_tds_ph'];
        }
        // Reverse Osmosis
        if (isset($_eqDec['reverse_osmosis'])) {
            $rod = &$_eqDec['reverse_osmosis'];
            if (isset($rod['water_meter_m3'])) $eq['ro']['meter'] = (string)$rod['water_meter_m3'];
            if (isset($rod['water_permeate_m3ph'])) $eq['ro']['permeate'] = (string)$rod['water_permeate_m3ph'];
            if (isset($rod['tds_ph_permeate'])) $eq['ro']['test_permeate'] = (string)$rod['tds_ph_permeate'];
            if (isset($rod['tds_ph_deep_well'])) $eq['ro']['test_deepwell'] = (string)$rod['tds_ph_deep_well'];
        }
        // Pool System
        if (isset($_eqDec['pool_system']['lagoon_1'])) {
            $p1 = &$_eqDec['pool_system']['lagoon_1'];
            if (isset($p1['alarm_on_off'])) $eq['pool']['l1_alarm'] = (string)$p1['alarm_on_off'];
            if (isset($p1['pump_running_unit_op'])) $eq['pool']['l1_pump'] = (string)$p1['pump_running_unit_op'];
            if (isset($p1['pressure_tank_kgcm2'])) $eq['pool']['l1_press'] = (string)$p1['pressure_tank_kgcm2'];
            if (isset($p1['submersible_auto'])) $eq['pool']['l1_sub_auto'] = (string)$p1['submersible_auto'];
        }
        if (isset($_eqDec['pool_system']['lagoon_2'])) {
            $p2 = &$_eqDec['pool_system']['lagoon_2'];
            if (isset($p2['alarm_on_off'])) $eq['pool']['l2_alarm'] = (string)$p2['alarm_on_off'];
            if (isset($p2['pump_running_unit_op'])) $eq['pool']['l2_pump'] = (string)$p2['pump_running_unit_op'];
            if (isset($p2['pressure_tank_kgcm2'])) $eq['pool']['l2_press'] = (string)$p2['pressure_tank_kgcm2'];
            if (isset($p2['submersible_auto'])) $eq['pool']['l2_sub_auto'] = (string)$p2['submersible_auto'];
        }
        if (isset($_eqDec['pool_system']['aquavitale'])) {
            $pa = &$_eqDec['pool_system']['aquavitale'];
            if (isset($pa['alarm_on_off'])) $eq['pool']['aqua_alarm'] = (string)$pa['alarm_on_off'];
            if (isset($pa['pump_running_unit_op'])) $eq['pool']['aqua_pump'] = (string)$pa['pump_running_unit_op'];
            if (isset($pa['hot_water_boiler_temp_c'])) $eq['pool']['aqua_hwbtemp'] = (string)$pa['hot_water_boiler_temp_c'];
            if (isset($pa['submersible_auto'])) $eq['pool']['aqua_sub_auto'] = (string)$pa['submersible_auto'];
        }
        if (isset($_eqDec['pool_system']['main_pump_room'])) {
            $pmpr = &$_eqDec['pool_system']['main_pump_room'];
            if (isset($pmpr['alarm_on_off'])) $eq['pool']['mpr_alarm'] = (string)$pmpr['alarm_on_off'];
            if (isset($pmpr['submersible_auto'])) $eq['pool']['mpr_sub_auto'] = (string)$pmpr['submersible_auto'];
        }
        // Gas System
        if (isset($_eqDec['gas_system']['detector_boneka_resto'])) {
            $g1 = &$_eqDec['gas_system']['detector_boneka_resto'];
            if (isset($g1['selenoid_valve_open_close'])) $eq['gas']['boneka_valve'] = (string)$g1['selenoid_valve_open_close'];
            if (isset($g1['alarm_on_off'])) $eq['gas']['boneka_alarm'] = (string)$g1['alarm_on_off'];
        }
        if (isset($_eqDec['gas_system']['detector_main_kitchen'])) {
            $g2 = &$_eqDec['gas_system']['detector_main_kitchen'];
            if (isset($g2['selenoid_valve_open_close'])) $eq['gas']['mainkitchen_valve'] = (string)$g2['selenoid_valve_open_close'];
            if (isset($g2['alarm_on_off'])) $eq['gas']['mainkitchen_alarm'] = (string)$g2['alarm_on_off'];
        }
        if (isset($_eqDec['gas_system']['detector_kayu_puti_resto'])) {
            $g3 = &$_eqDec['gas_system']['detector_kayu_puti_resto'];
            if (isset($g3['selenoid_valve_open_close'])) $eq['gas']['kayuputih_valve'] = (string)$g3['selenoid_valve_open_close'];
            if (isset($g3['alarm_on_off'])) $eq['gas']['kayuputih_alarm'] = (string)$g3['alarm_on_off'];
        }
        unset($_eqDec);
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-shell page-shell--5xl">
    <div class="mb-8 animate-fade-in">
        <a href="<?= BASE_URL ?>engineer/select_date.php" class="inline-flex items-center gap-1.5 text-sm text-secondary hover:text-primary mb-4 transition-colors">
            <i class="fas fa-chevron-left text-xs"></i> <?= T('select_date_title', 'Pilih Tanggal Lain') ?>
        </a>
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <div>
                <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">
                    <i class="fas fa-pen-to-square mr-2 text-accent"></i><?= T('form_title', 'Daily Log Engineering') ?>
                </h1>
                <p class="text-secondary flex items-center gap-2 flex-wrap">
                    <i class="fas fa-calendar-day text-accent"></i>
                    <?= T('general_date', 'Tanggal') ?>: <span class="font-semibold text-primary"><?= formatDate($date) ?></span>
                    <?php if ($log): ?>
                        <span class="ml-2 px-3 py-1 rounded-full text-[11px] font-semibold <?= getStatusBadgeClass($log['status']) ?>"><?= getStatusText($log['status']) ?></span>
                    <?php endif; ?>
                    <?php if ($log && !empty($log['engineer_id'])):
                        $engUsr = $db->fetchOne("SELECT id,name,role,position FROM users WHERE id = ?", [(int)$log['engineer_id']]);
                        if ($engUsr): ?>
                        <span class="ml-2 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-sky-50 text-sky-700 border border-sky-200">
                            <i class="fas fa-user-gear text-[10px]"></i>
                            <?= cleanInput($engUsr['name']) ?>
                            <?php if (!empty($engUsr['position'])): ?><span class="opacity-70 font-normal">(<?= cleanInput($engUsr['position']) ?>)</span><?php endif; ?>
                        </span>
                        <?php unset($engUsr); endif;
                    endif; ?>
                </p>
            </div>
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                <?php if ($canChooseEngineer): ?>
                    <!-- Manager / Supervisor Access All: Pilih Engineer yang Log-nya mau diisi / diedit -->
                    <div class="flex items-center gap-2 rounded-premium bg-white border border-slate-200 px-3.5 py-2 shadow-sm min-w-[260px]">
                        <label class="shrink-0 text-[11px] font-black tracking-[0.08em] uppercase text-slate-400"><i class="fas fa-user-pen mr-1"></i> Owner:</label>
                        <select id="engineerSwitcher"
                                class="flex-1 min-w-0 bg-transparent text-sm font-bold text-slate-800 px-1 py-0.5 border-none focus:outline-none focus:ring-0">
                            <?php foreach ($engineerOptions as $eo):
                                $sel = (int)$eo['id'] === (int)$targetEngineerId ? 'selected' : '';
                                $roleBadge = strtoupper(substr($eo['role'] ?? 'user', 0, 3));
                                $roleColor = match(strtolower($eo['role'] ?? '')) {
                                    'engineer'=>'text-sky-600', 'supervisor'=>'text-emerald-600','manager'=>'text-indigo-600','admin'=>'text-rose-600',
                                    default=>'text-slate-500'
                                };
                            ?>
                            <option value="<?= (int)$eo['id'] ?>" <?= $sel ?>><?= cleanInput($eo['name']) . (!empty($eo['position']) ? ' — ' . cleanInput($eo['position']) : '') . '  [' . $roleBadge . ']' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <script>
                        (function(){
                            const sel = document.getElementById('engineerSwitcher');
                            if(!sel) return;
                            sel.addEventListener('change', function(){
                                const uid = parseInt(sel.value || '0', 10);
                                if(uid > 0){
                                    // Redirect pindah engineer (reload halaman agar existing log load sesuai engineer target)
                                    const url = new URL(window.location.href);
                                    url.searchParams.set('engineer_id', String(uid));
                                    window.location.href = url.toString();
                                }
                            });
                        })();
                    </script>
                <?php endif; ?>
                <?php if ($log && $log['status'] === 'rejected' && $log['revision_notes']): ?>
                    <div class="p-4 rounded-card bg-red-50 border border-red-200 max-w-md animate-slide-up">
                        <p class="text-xs font-semibold text-red-700 mb-1"><i class="fas fa-triangle-exclamation mr-1"></i><?= T('today_revisi_label', 'Catatan Revisi') ?>:</p>
                        <p class="text-sm text-red-800"><?= nl2br(cleanInput($log['revision_notes'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="space-y-5">
        <!-- Manager/Supervisor Access All: hidden input kirim target engineer id ke handler POST (sinkron dgn select engineer di header) -->
        <?php if ($canChooseEngineer): ?>
            <input type="hidden" name="_target_engineer_id" value="<?= (int)$targetEngineerId ?>">
        <?php endif; ?>

        <!-- 0 PALING ATAS: PILIH SHIFT PAGI / SIANG / MALAM (Dropdown Sederhana) -->
        <?php
            $curShiftVal = (!empty($log['shift']) && in_array($log['shift'], $allShifts, true)) ? (string)$log['shift'] : $defaultShiftNow;
            $shiftLabels = [
                'pagi'  => '☀️ Pagi',
                'siang' => '🌤️ Siang',
                'malam' => '🌙 Malam',
            ];
            // ✅ BARU 2026-08-17: SEMUA SHIFT (Pagi/Siang/Malam) BOLEH EDIT Listrik (WBP/LWBP) & Air Main Building
            // ❌ TIDAK ADA readonly / disabled lagi untuk field input
            // ⚠️ HANYA SHIFT MALAM SAJA YANG "MENGHITUNG TOTAL KONSUMSI" (rumus = Today − Yesterday Malam)
            //    Pagi/Siang = 0 total_electricity & total_water (cuma catatan reading meter saja, tdk masuk cost)
            $isElecEditable = true;
            $isMalamNow = ($curShiftVal === 'malam');
            $elecReadonly = '';
            $elecRequired = 'required';
            $elecDisabledCls = '';
            // Yesterday values (untuk dikirim ke JS calcTotals frontend)
            $yElecWbpJs = number_format($elecYesterdayWbp, 2, '.', '');
            $yElecLwbpJs = number_format($elecYesterdayLwbp, 2, '.', '');
            $yElecTotalJs = number_format($elecYesterdayTotal, 2, '.', '');
            $yWaterMbJs  = number_format($mbYesterdayRead, 2, '.', '');
        ?>
        <div class="bg-surface rounded-premium border border-slate-200 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 30ms">
         
            <div class="p-5 lg:p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-center">
                    <div>
                        <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><i class="fas fa-clock-rotate-left mr-1 text-slate-600"></i>Pilih Shift Bertugas</label>
                        <div class="relative">
                            <select name="shift" id="shiftSelect" onchange="onShiftChange()"
                                class="w-full pl-4 pr-12 py-3.5 rounded-card border border-slate-300 bg-white text-lg font-semibold text-primary placeholder-secondary/60
                                       focus:outline-none focus:border-slate-500 focus:ring-4 focus:ring-slate-500/15 transition-all appearance-none cursor-pointer">
                                <?php foreach ($allShifts as $_sKey): ?>
                                    <option value="<?= $_sKey ?>" <?= ($curShiftVal === $_sKey) ? 'selected' : '' ?>><?= $shiftLabels[$_sKey] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4">
                                <i class="fas fa-chevron-down text-sm text-slate-500"></i>
                            </div>
                        </div>
                    </div>
                 
                </div>
            </div>
        </div>

        <!--  CCUPANCY RATE (OCC %) - PALING ATAS SENDIRI SESUAI REQUEST -->
        <div class="bg-surface rounded-premium border border-accent/40 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 50ms">
            <div class="px-5 lg:px-6 py-4 border-b border-accent/20 bg-gradient-to-r from-amber-50 via-yellow-50 to-amber-100/60">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 via-yellow-500 to-amber-700 flex items-center justify-center text-white shadow-md shadow-amber-500/30"><i class="fas fa-bed text-sm"></i></span>
                    <?= T( 'Occupancy Rate (OCC %) • Tingkat Hunian Kamar') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_occ_sub', 'Isi persentase kamar terisi hari ini (0 - 100%) • Data acuan compare utility consumption') ?></p>
            </div>
            <div class="p-5 lg:p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
                    <div class="md:col-span-1">
                        <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><i class="fas fa-percentage mr-1 text-amber-600"></i><?= T('form_occ_label', 'Occupancy Rate Hari Ini') ?></label>
                        <div class="relative">
                            <input type="number" id="occRate" step="0.01" min="0" max="100" name="occ_rate"
                                value="<?= $log['occ_rate'] ?? '0.00' ?>"
                                oninput="occVisual(this.value)"
                                class="w-full pl-4 pr-14 py-3.5 rounded-card border border-amber-300 bg-gradient-to-r from-amber-50 to-yellow-50 text-2xl font-black text-primary placeholder-secondary/60 focus:outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-500/15 focus:bg-white transition-all">
                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-lg text-amber-700 font-black bg-amber-100 border border-amber-300 px-2.5 py-0.5 rounded-full">%</span>
                        </div>
                        <div class="mt-3 flex gap-2 flex-wrap">
                            <button type="button" onclick="setOcc(50)" class="px-3 py-1 rounded-lg text-xs font-bold border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 transition">50%</button>
                            <button type="button" onclick="setOcc(65)" class="px-3 py-1 rounded-lg text-xs font-bold border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 transition">65%</button>
                            <button type="button" onclick="setOcc(70)" class="px-3 py-1 rounded-lg text-xs font-bold border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 transition">70%</button>
                            <button type="button" onclick="setOcc(80)" class="px-3 py-1 rounded-lg text-xs font-bold border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition">80%</button>
                            <button type="button" onclick="setOcc(90)" class="px-3 py-1 rounded-lg text-xs font-bold border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition">90%</button>
                            <button type="button" onclick="setOcc(100)" class="px-3 py-1 rounded-lg text-xs font-bold border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition">Full 100%</button>
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-black uppercase tracking-[0.18em] text-secondary mb-2"><?= T('form_occ_visual', 'Visual Bar') ?></label>
                        <div class="relative h-10 rounded-premium overflow-hidden bg-gray-100 border border-gray-200 shadow-inner">
                            <div id="occBar" class="absolute inset-y-0 left-0 h-full rounded-premium transition-all duration-700 ease-out bg-gradient-to-r from-amber-400 via-yellow-400 to-emerald-500" style="width: 0%"></div>
                            <div class="absolute inset-0 flex items-center justify-center text-sm font-black text-primary drop-shadow-[0_1px_0_rgba(255,255,255,0.7)]"><span id="occLabelText">0%</span> • <span id="occLabelTextDesc"><?= T('form_occ_empty', 'Kosong') ?></span></div>
                        </div>
                        <script>
                            function occVisual(v) {
                                let val = parseFloat(v) || 0;
                                if (val < 0) val = 0;
                                if (val > 100) val = 100;
                                const bar = document.getElementById('occBar');
                                const label = document.getElementById('occLabelText');
                                const desc = document.getElementById('occLabelTextDesc');
                                if (bar) bar.style.width = val + '%';
                                if (label) label.textContent = val.toFixed(2) + '%';
                                if (desc) {
                                    if (val <= 0) desc.textContent = '<?= T('form_occ_empty', 'Kosong') ?>';
                                    else if (val < 40) desc.textContent = '<?= T('form_occ_low', 'Low Season • Sepi') ?>';
                                    else if (val < 65) desc.textContent = '<?= T('form_occ_mid', 'Mid Season • Normal') ?>';
                                    else if (val < 85) desc.textContent = '<?= T('form_occ_high', 'High Season • Ramai') ?>';
                                    else desc.textContent = '<?= T('form_occ_full', 'Peak Season • Penuh!') ?>';
                                }
                            }
                            function setOcc(p) { const el = document.getElementById('occRate'); if (el) { el.value = p; occVisual(p); } }
                            document.addEventListener('DOMContentLoaded', function() { const el = document.getElementById('occRate'); if (el) occVisual(el.value); });
                        </script>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑧ BIS. KEY PERFORMANCE INDICATORS - ITR / M&U / GITB RANK (SETELAH OCC RATE) -->
        <div class="bg-surface rounded-premium border border-emerald-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 70ms">
            <div class="px-5 lg:px-6 py-4 border-b border-emerald-100/80 bg-gradient-to-r from-emerald-50/90 via-green-50/60 to-teal-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 via-green-600 to-teal-700 flex items-center justify-center text-white shadow-md shadow-emerald-500/30"><i class="fas fa-chart-line text-sm"></i></span>
                    <?= T('form_kpi_title', 'Key Performance Indicators (KPI) • ITR, M&U, dan GITB Rank') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_kpi_sub', 'Isi data KPI harian dari report Operational • Data akan otomatis tampil di Dashboard KPI Utama') ?></p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 md:grid-cols-3 gap-5">
                <?php
                // Helper default value (NULL = string kosong biar tampilan placeholder)
                $kDefItr = isset($log['itr_score']) && $log['itr_score'] !== null ? number_format((float)$log['itr_score'], 2, '.', '') : '';
                $kDefMu  = isset($log['mu_score'])  && $log['mu_score']  !== null ? number_format((float)$log['mu_score'],  2, '.', '') : '';
                $kDefGitb = isset($log['gitb_rank']) && $log['gitb_rank'] !== null ? (int)$log['gitb_rank'] : '';
                ?>
                <!-- 1. ITR SCORE (Index Tata Ruang) -->
                <div>
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><i class="fas fa-sheet-plastic mr-1 text-blue-600"></i>
                        <?= T('form_kpi_itr', 'ITR Score') ?>
                        <span class="text-[10px] font-normal text-secondary ml-1">(Index Tata Ruang)</span>
                    </label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" max="999.99" name="itr_score"
                            value="<?= $kDefItr ?>"
                            placeholder="Contoh: 87.00"
                            class="w-full pl-4 pr-12 py-3.5 rounded-card border border-emerald-200 bg-emerald-50/60 text-lg font-black text-primary placeholder-secondary/50 focus:outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/15 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-emerald-700 font-bold bg-emerald-100 border border-emerald-300 px-2.5 py-0.5 rounded-full">point</span>
                    </div>
                </div>
                <!-- 2. M&U SCORE (Maintenance & Utility) -->
                <div>
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><i class="fas fa-screwdriver-wrench mr-1 text-orange-600"></i>
                        <?= T('form_kpi_mu', 'M&U Score') ?>
                        <span class="text-[10px] font-normal text-secondary ml-1">(Maintenance & Utility)</span>
                    </label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" max="999.99" name="mu_score"
                            value="<?= $kDefMu ?>"
                            placeholder="Contoh: 81.00"
                            class="w-full pl-4 pr-12 py-3.5 rounded-card border border-green-200 bg-green-50/60 text-lg font-black text-primary placeholder-secondary/50 focus:outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/15 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-green-700 font-bold bg-green-100 border border-green-300 px-2.5 py-0.5 rounded-full">point</span>
                    </div>
                </div>
                <!-- 3. GITB RANK (Guest in the Book Rank) -->
                <div>
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><i class="fas fa-trophy mr-1 text-amber-600"></i>
                        <?= T('form_kpi_gitb', 'GITB Rank') ?>
                        <span class="text-[10px] font-normal text-secondary ml-1">(Guest in the Book • Posisi 1 s/d 99)</span>
                    </label>
                    <div class="relative">
                        <input type="number" step="1" min="1" max="99" name="gitb_rank"
                            value="<?= $kDefGitb ?>"
                            placeholder="Contoh: 4"
                            class="w-full pl-4 pr-12 py-3.5 rounded-card border border-teal-200 bg-teal-50/60 text-lg font-black text-primary placeholder-secondary/50 focus:outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-500/15 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-teal-700 font-bold bg-teal-100 border border-teal-300 px-2.5 py-0.5 rounded-full">#Rank</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑧ TRIS. TARIF BERLAKU HARI INI (SNAPSHOT permanen per-tanggal) -->
        <?php
        $roleCanEditTariff = in_array(($user['role'] ?? ''), ['supervisor','manager','admin'], true);
        $_defTarNow = getTariffSettings();
        // Default value: 1) Log existing > 0? ambil dari DB snapshot (jika supervisor edit, bisa rubah). 2) NULL / 0? pakai global default settings.
        $tDef = [
            'electricity' => ( !empty($log['tariff_electricity_per_kwh']) && (int)$log['tariff_electricity_per_kwh'] > 0 ) ? (int)$log['tariff_electricity_per_kwh'] : (int)($_defTarNow['electricity_per_kwh'] ?? 1850),
            'water'       => ( !empty($log['tariff_water_per_m3']) && (int)$log['tariff_water_per_m3'] > 0 ) ? (int)$log['tariff_water_per_m3'] : (int)($_defTarNow['water_per_m3'] ?? 9600),
            'gas'         => ( !empty($log['tariff_gas_per_kg']) && (int)$log['tariff_gas_per_kg'] > 0 ) ? (int)$log['tariff_gas_per_kg'] : (int)($_defTarNow['gas_per_kg'] ?? 24500),
            'fuel'        => ( !empty($log['tariff_fuel_per_liter']) && (int)$log['tariff_fuel_per_liter'] > 0 ) ? (int)$log['tariff_fuel_per_liter'] : (int)($_defTarNow['fuel_per_liter'] ?? 17450),
        ];
        $tariffReadonly = $roleCanEditTariff ? '' : 'readonly tabindex="-1"';
        $tariffCursorCls = $roleCanEditTariff ? '' : 'cursor-not-allowed opacity-90';
        ?>
        <div class="bg-surface rounded-premium border border-slate-200/70 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 90ms">
            <div class="px-5 lg:px-6 py-4 border-b border-slate-100/80 bg-gradient-to-r from-slate-50 via-slate-100/70 to-zinc-50">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-2">
                    <h3 class="font-bold text-primary flex items-center gap-2">
                        <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-slate-600 via-zinc-700 to-slate-900 flex items-center justify-center text-white shadow-md shadow-slate-500/30"><i class="fas fa-receipt text-sm"></i></span>
                        <?= T('form_tariff_title', 'Tarif Berlaku Hari Ini (Snapshot Permanen)') ?>
                    </h3>
                    <?php if (!$roleCanEditTariff): ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-slate-100 border border-slate-200 text-[10.5px] font-black text-slate-600 uppercase tracking-wide">
                        <i class="fas fa-lock text-[9px]"></i> Locked (Engineer)
                    </span>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-sky-50 border border-sky-200 text-[10.5px] font-black text-sky-700 uppercase tracking-wide">
                        <i class="fas fa-unlock text-[9px]"></i> Bisa Diedit
                    </span>
                    <?php endif; ?>
                </div>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_tariff_sub', 'Snapshot tarif sesuai tanggal ini • Biaya di Dashboard & PDF menggunakan nilai ini, TIDAK BERUBAH meskipun global tarif diupdate besok.') ?></p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-5">
                <!-- 1. LISTRIK PLN -->
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide">
                        <i class="fas fa-bolt mr-1 text-amber-600"></i> Tarif Listrik PLN
                    </label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999"
                               name="electricity_per_kwh" value="<?= $tDef['electricity'] ?>"
                               <?= $tariffReadonly ?>
                               class="w-full pl-3 pr-12 py-3 rounded-card border border-slate-300 bg-slate-50 text-lg font-black text-primary placeholder-secondary/50 focus:outline-none focus:border-slate-700 focus:ring-4 focus:ring-slate-700/10 focus:bg-white transition-all {$tariffCursorCls}">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-slate-700 font-black bg-white border border-slate-300 px-1.5 py-0.5 rounded-full">Rp/kWh</span>
                    </div>
                </div>
                <!-- 2. AIR PDAM -->
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide">
                        <i class="fas fa-droplet mr-1 text-blue-600"></i> Tarif Air PDAM
                    </label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999"
                               name="water_per_m3" value="<?= $tDef['water'] ?>"
                               <?= $tariffReadonly ?>
                               class="w-full pl-3 pr-12 py-3 rounded-card border border-slate-300 bg-slate-50 text-lg font-black text-primary placeholder-secondary/50 focus:outline-none focus:border-slate-700 focus:ring-4 focus:ring-slate-700/10 focus:bg-white transition-all {$tariffCursorCls}">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-slate-700 font-black bg-white border border-slate-300 px-1.5 py-0.5 rounded-full">Rp/m3</span>
                    </div>
                </div>
                <!-- 3. GAS LPG -->
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide">
                        <i class="fas fa-fire mr-1 text-orange-600"></i> Tarif Gas LPG
                    </label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999"
                               name="gas_per_kg" value="<?= $tDef['gas'] ?>"
                               <?= $tariffReadonly ?>
                               class="w-full pl-3 pr-12 py-3 rounded-card border border-slate-300 bg-slate-50 text-lg font-black text-primary placeholder-secondary/50 focus:outline-none focus:border-slate-700 focus:ring-4 focus:ring-slate-700/10 focus:bg-white transition-all {$tariffCursorCls}">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-slate-700 font-black bg-white border border-slate-300 px-1.5 py-0.5 rounded-full">Rp/kg</span>
                    </div>
                </div>
                <!-- 4. SOLAR BBM -->
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide">
                        <i class="fas fa-gas-pump mr-1 text-rose-600"></i> Tarif Solar BBM
                    </label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999"
                               name="fuel_per_liter" value="<?= $tDef['fuel'] ?>"
                               <?= $tariffReadonly ?>
                               class="w-full pl-3 pr-12 py-3 rounded-card border border-slate-300 bg-slate-50 text-lg font-black text-primary placeholder-secondary/50 focus:outline-none focus:border-slate-700 focus:ring-4 focus:ring-slate-700/10 focus:bg-white transition-all {$tariffCursorCls}">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-slate-700 font-black bg-white border border-slate-300 px-1.5 py-0.5 rounded-full">Rp/Ltr</span>
                    </div>
                </div>
            </div>
        </div>
        <?php
        unset($_defTarNow, $tDef, $tariffReadonly, $tariffCursorCls, $roleCanEditTariff);
        ?>

        <!-- ① TOTAL LISTRIK - WBP LWBP (SEMUA SHIFT BISA ISI — TOTAL KONSUMSI HANYA DIHITUNG JIKA SHIFT MALAM) -->
        <div class="bg-surface rounded-premium border border-amber-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 50ms">
            <div class="px-5 lg:px-6 py-4 border-b border-amber-100/80 bg-gradient-to-r from-amber-50/90 via-amber-50/60 to-yellow-50">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-400 to-amber-600 flex items-center justify-center text-white shadow-md shadow-amber-500/30"><i class="fas fa-bolt text-sm"></i></span>
                    <?= T('form_section_elec', '① Konsumsi Listrik (kWh)') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_elec_sub', 'Di isi WBP (Wilayah Beban Puncak) + LWBP (Luar WBP) — Reading Meter semua shift bisa diisi') ?></p>
                <!-- NOTICE BANNER -->
                <div id="elecNotice" class="mt-3 text-[11px] font-semibold px-3 py-2 rounded-lg border <?= $isMalamNow ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200' ?>">
                    <?php if ($isMalamNow): ?>
                        <i class="fas fa-moon mr-1"></i> <b>Shift Malam</b> — Total Konsumsi = Reading Hari ini − Reading Shift Malam Kemarin. Hasil otomatis masuk ke Cost & Dashboard.
                    <?php else: ?>
                        <i class="fas fa-circle-check mr-1"></i> <b>Shift Pagi/Siang</b> — Reading Meter Listrik <b>bisa diisi</b> (tidak dikunci). Total Konsumsi = 0 (tidak masuk kalkulasi biaya).
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><?= T('form_elec_wbp', 'KWH WBP') ?> <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="electricity_wbp" <?= $elecRequired ?> <?= $elecReadonly ?> oninput="calcTotals()"
                            value="<?= $log['electricity_wbp'] ?? '0.00' ?>"
                            class="js-sum-electric elec-input w-full pl-4 pr-16 py-3.5 rounded-card border border-amber-200 bg-amber-50/60 text-lg font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 focus:bg-white transition-all <?= $elecDisabledCls ?>">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-amber-700 font-bold bg-amber-100 px-2 py-0.5 rounded-full">kWh</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><?= T('form_elec_lwbp', 'KWH LWBP') ?> <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="electricity_lwbp" <?= $elecRequired ?> <?= $elecReadonly ?> oninput="calcTotals()"
                            value="<?= $log['electricity_lwbp'] ?? '0.00' ?>"
                            class="js-sum-electric elec-input w-full pl-4 pr-16 py-3.5 rounded-card border border-yellow-300 bg-yellow-50/70 text-lg font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-yellow-600 focus:ring-4 focus:ring-yellow-500/10 focus:bg-white transition-all <?= $elecDisabledCls ?>">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-yellow-800 font-bold bg-yellow-100 px-2 py-0.5 rounded-full">kWh</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-extrabold text-primary mb-2 tracking-tight"><i class="fas fa-calculator mr-1 text-primary"></i><?= T('form_elec_total', 'TOTAL LISTRIK (Auto)') ?></label>
                    <div class="relative">
                        <input type="number" id="totalElectricity" readonly step="0.01" min="0" name="total_electricity_show"
                            value="<?= $elecConsumptionNow > 0 ? number_format($elecConsumptionNow, 2, '.', '') : ($log['total_electricity'] ?? '0.00') ?>"
                            class="w-full pl-4 pr-16 py-3.5 rounded-card border-2 border-primary/80 bg-gradient-to-br from-primary to-primary/85 text-lg font-black text-white placeholder-white/80 shadow-lg shadow-primary/10 cursor-not-allowed opacity-90">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-white/95 font-extrabold border border-white/30 px-2 py-0.5 rounded-full bg-white/10">kWh</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ② WATER 9 SUMBER (SEMUA SHIFT BISA ISI — TOTAL KONSUMSI HANYA DIHITUNG JIKA SHIFT MALAM) -->
        <div class="bg-surface rounded-premium border border-blue-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 90ms">
            <div class="px-5 lg:px-6 py-4 border-b border-blue-100/80 bg-gradient-to-r from-blue-50/90 via-sky-50/60 to-cyan-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white shadow-md shadow-blue-500/30"><i class="fas fa-droplet text-sm"></i></span>
                    <?= T('form_water_title', '② Water - Konsumsi Air 9 Sumber (m3)') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_water_sub', '3 sumber sesuai catatan: PDAM / Main Building / Cooling Tower') ?></p>
                <!-- NOTICE BANNER WATER (sama seperti Listrik: Semua shift bisa isi, HANYA MALAM hitung total konsumsi) -->
                <div id="waterNotice" class="mt-3 text-[11px] font-semibold px-3 py-2 rounded-lg border <?= $isMalamNow ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200' ?>">
                    <?php if ($isMalamNow): ?>
                        <i class="fas fa-moon mr-1"></i> <b>Shift Malam</b> — Total Konsumsi = Main Building Hari ini − Shift Malam Kemarin. Hasil otomatis masuk ke Cost & Dashboard.
                    <?php else: ?>
                        <i class="fas fa-circle-check mr-1"></i> <b>Shift Pagi/Siang</b> — Main Building Reading Meter <b>bisa diisi</b> (tidak dikunci). Total Konsumsi = 0 (tidak masuk kalkulasi biaya).
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                <?php
                // 1. PDAM (biasa — input nilai langsung)
                [$field, $label, $col, $bg, $bor] = ['water_pdam', T('form_water_pdam', 'PDAM'), 'text-slate-600', 'bg-slate-50/60', 'border-slate-200'];
                $val = $log[$field] ?? '0.00';
                echo <<<HTML
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide">{$label}</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="{$field}" oninput="calcTotals()"
                            value="{$val}"
                            class="w-full pl-3 pr-12 py-3 rounded-card border {$bor} {$bg} font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 focus:bg-white transition-all text-sm">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] {$col} font-black bg-white/90 border {$bor} px-1.5 py-0.5 rounded-full">m3</span>
                    </div>
                </div>
HTML;

                // 2. MAIN BUILDING — READING METER (Yesterday MALAM - Today MALAM = Konsumsi, HANYA HITUNG JIKA SHIFT MALAM)
                //    Semua shift BISA EDIT READING (tidak dikunci readonly)
                [$field, $label, $col, $bg, $bor] = ['water_main_building', T('form_water_main', 'Main Building'), 'text-cyan-600', 'bg-cyan-50/60', 'border-cyan-200'];
                $val = $log[$field] ?? '0.00';
                $mbYesterdayFmt = number_format($mbYesterdayRead, 2, '.', '');
                $mbConsFmt = number_format($mbConsumption, 2, '.', '');
                $yDateLabel = date('d/m/Y', strtotime($yesterdayDate));
                $mbRdo = '';
                $mbDisCls = '';
                echo <<<HTML
                <div class="sm:col-span-1">
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-extrabold text-primary tracking-wide">{$label}</label>
                        <span class="text-[9px] font-black tracking-wider uppercase px-1.5 py-0.5 rounded bg-cyan-100 text-cyan-700 border border-cyan-200">Reading Meter</span>
                    </div>
                    <div class="rounded-card border-2 border-dashed border-cyan-200 bg-cyan-50/40 p-2.5 space-y-2">
                        <div class="flex items-center justify-between text-[10px] font-bold">
                            <span class="text-slate-500">Yesterday Malam ({$yDateLabel})</span>
                            <span class="text-slate-700">{$mbYesterdayFmt} m3</span>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-cyan-700 mb-1">Today (angka meter hari ini)</p>
                            <div class="relative">
                                <input type="number" step="0.01" min="0" name="{$field}" id="waterMainBuild" {$mbRdo} oninput="calcTotals()"
                                    value="{$val}"
                                    data-yesterday="{$mbYesterdayFmt}"
                                    class="water-mb-input w-full pl-3 pr-12 py-2 rounded-md border {$bor} {$bg} font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 focus:bg-white transition-all text-sm {$mbDisCls}">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] {$col} font-black bg-white/90 border {$bor} px-1.5 py-0.5 rounded-full">m3</span>
                            </div>
                        </div>
                        <div class="flex items-center justify-between text-[10px] font-bold pt-1 border-t border-cyan-200/70">
                            <span class="text-cyan-700">Konsumsi = Today − Yesterday (Hanya Shift Malam)</span>
                            <span class="text-cyan-800" id="waterMainCons">{$mbConsFmt} m3</span>
                        </div>
                    </div>
                </div>
HTML;

                // 3. COOLING TOWER (biasa — input nilai langsung)
                [$field, $label, $col, $bg, $bor] = ['water_cooling_tower', T('form_water_ct', 'Cooling Tower'), 'text-teal-600', 'bg-teal-50/60', 'border-teal-200'];
                $val = $log[$field] ?? '0.00';
                echo <<<HTML
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide">{$label}</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="{$field}" oninput="calcTotals()"
                            value="{$val}"
                            class="w-full pl-3 pr-12 py-3 rounded-card border {$bor} {$bg} font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 focus:bg-white transition-all text-sm">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] {$col} font-black bg-white/90 border {$bor} px-1.5 py-0.5 rounded-full">m3</span>
                    </div>
                </div>
HTML;
                ?>
                <div class="sm:col-span-2 md:col-span-3 pt-1 mt-1 border-t border-dashed border-blue-100">
                    <div class="flex items-center justify-between gap-4">
                        <label class="text-sm font-extrabold text-primary flex items-center gap-1.5"><i class="fas fa-calculator text-primary"></i><?= T('form_water_total_label', 'Konsumsi Air') ?></label>
                        <div class="relative w-full sm:w-56">
                            <input type="number" id="totalWater" readonly step="0.01" min="0"
                                value="<?= number_format($mbConsumption, 2, '.', '') ?>"
                                class="w-full pl-3 pr-11 py-3 rounded-card border-2 border-blue-600/85 bg-gradient-to-br from-blue-600 to-blue-800 text-white font-black shadow-md shadow-blue-500/15 cursor-not-allowed opacity-95">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-white/95 font-black border border-white/25 px-1.5 py-0.5 rounded-full bg-white/10">m3</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ③ GAS 2 TIPE (LPG + LNG) -->
        <div class="bg-surface rounded-premium border border-orange-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 130ms">
            <div class="px-5 lg:px-6 py-4 border-b border-orange-100/80 bg-gradient-to-r from-orange-50/90 via-orange-50/60 to-amber-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-orange-400 to-orange-600 flex items-center justify-center text-white shadow-md shadow-orange-500/30"><i class="fas fa-fire text-sm"></i></span>
                    <?= T( 'Gas Konsumsi - LPG & LNG (kg)') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_gas_sub', 'Isi masing-masing tipe gas — Total auto dijumlah') ?></p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><?= T('form_gas_lpg', 'LPG') ?> <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="gas_lpg" required oninput="calcTotals()"
                            value="<?= $log['gas_lpg'] ?? '0.00' ?>"
                            class="js-sum-gas w-full pl-4 pr-16 py-3.5 rounded-card border border-orange-200 bg-orange-50/60 text-lg font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-orange-500 focus:ring-4 focus:ring-orange-500/10 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-orange-700 font-bold bg-orange-100 px-2 py-0.5 rounded-full">kg</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><?= T('form_gas_lng', 'LNG') ?> <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="gas_lng" required oninput="calcTotals()"
                            value="<?= $log['gas_lng'] ?? '0.00' ?>"
                            class="js-sum-gas w-full pl-4 pr-16 py-3.5 rounded-card border border-red-200 bg-red-50/50 text-lg font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-red-500 focus:ring-4 focus:ring-red-500/10 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-red-700 font-bold bg-red-100 px-2 py-0.5 rounded-full">kg</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-extrabold text-primary mb-2 tracking-tight"><i class="fas fa-calculator mr-1 text-primary"></i><?= T('form_gas_total', 'TOTAL GAS (Auto)') ?></label>
                    <div class="relative">
                        <input type="number" id="totalGas" readonly step="0.01" min="0"
                            value="<?= $log['total_gas'] ?? '0.00' ?>"
                            class="w-full pl-4 pr-16 py-3.5 rounded-card border-2 border-orange-600/85 bg-gradient-to-br from-orange-500 to-orange-700 text-lg font-black text-white shadow-lg shadow-orange-500/15 cursor-not-allowed opacity-95">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-white/95 font-extrabold border border-white/30 px-2 py-0.5 rounded-full bg-white/10">kg</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ④ SWRO (Sea Water Reverse Osmosis) 3 -->
        <div class="bg-surface rounded-premium border border-cyan-200/70 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 170ms">
            <div class="px-5 lg:px-6 py-4 border-b border-cyan-100/80 bg-gradient-to-r from-cyan-50/90 via-teal-50/60 to-emerald-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-cyan-400 to-teal-600 flex items-center justify-center text-white shadow-md shadow-cyan-500/30"><i class="fas fa-water text-sm"></i></span>
                    <?= T( 'SWRO - Sea Water Reverse Osmosis') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_swro_sub', 'Water meter produksi air bersih, listrik kWh, & TDS (ppm)') ?></p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 sm:grid-cols-3 gap-5">
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="far fa-gauge-high mr-1 text-cyan-600"></i><?= T('form_swro_water', 'SWRO Watermeter (m3)') ?></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="swro_watermeter"
                            value="<?= $log['swro_watermeter'] ?? '0.00' ?>"
                            class="w-full pl-3 pr-12 py-3 rounded-card border border-cyan-200 bg-cyan-50/60 font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-cyan-500 focus:ring-4 focus:ring-cyan-500/10 focus:bg-white transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-cyan-700 font-black bg-white/90 border border-cyan-200 px-1.5 py-0.5 rounded-full">m3</span>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-bolt mr-1 text-amber-600"></i><?= T('form_swro_kwh', 'SWRO Electric (kWh)') ?></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="swro_kwh"
                            value="<?= $log['swro_kwh'] ?? '0.00' ?>"
                            class="w-full pl-3 pr-12 py-3 rounded-card border border-amber-200 bg-amber-50/60 font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 focus:bg-white transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-amber-700 font-black bg-white/90 border border-amber-200 px-1.5 py-0.5 rounded-full">kWh</span>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-vial mr-1 text-teal-600"></i><?= T('form_swro_tds', 'SWRO TDS (ppm)') ?></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="swro_tds"
                            value="<?= $log['swro_tds'] ?? '0.00' ?>"
                            class="w-full pl-3 pr-12 py-3 rounded-card border border-teal-200 bg-teal-50/60 font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-500/10 focus:bg-white transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-teal-700 font-black bg-white/90 border border-teal-200 px-1.5 py-0.5 rounded-full">ppm</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑤ BOTTLING WATER 2 -->
        <div class="bg-surface rounded-premium border border-violet-200/70 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 210ms">
            <div class="px-5 lg:px-6 py-4 border-b border-violet-100/80 bg-gradient-to-r from-violet-50/90 via-purple-50/60 to-fuchsia-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-400 to-purple-600 flex items-center justify-center text-white shadow-md shadow-violet-500/30"><i class="fas fa-bottle-water text-sm"></i></span>
                    <?= T('Bottling Water - Produksi Air Minum') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_bottling_sub', 'Listrik untuk proses bottling (kWh) + watermeter produksi (m3)') ?></p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-bolt mr-1 text-amber-600"></i><?= T('form_bottling_kwh', 'Bottling - Electric (kWh)') ?></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="bottling_kwh"
                            value="<?= $log['bottling_kwh'] ?? '0.00' ?>"
                            class="w-full pl-4 pr-14 py-3 rounded-card border border-violet-200 bg-violet-50/60 font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-violet-500 focus:ring-4 focus:ring-violet-500/10 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-[10px] text-violet-700 font-black bg-white/90 border border-violet-200 px-1.5 py-0.5 rounded-full">kWh</span>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-gauge-high mr-1 text-violet-600"></i><?= T('form_bottling_water', 'Bottling - Watermeter (m3)') ?></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="bottling_watermeter"
                            value="<?= $log['bottling_watermeter'] ?? '0.00' ?>"
                            class="w-full pl-4 pr-14 py-3 rounded-card border border-violet-200 bg-purple-50/60 font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-[10px] text-purple-700 font-black bg-white/90 border border-purple-200 px-1.5 py-0.5 rounded-full">m3</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑥ CHILLER SYSTEM 8 fields (3 unit on/off + pH/TDS/Temp/2 pressures) -->
        <div class="bg-surface rounded-premium border border-emerald-200/70 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 250ms">
            <div class="px-5 lg:px-6 py-4 border-b border-emerald-100/80 bg-gradient-to-r from-emerald-50/90 via-green-50/60 to-teal-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-400 to-green-700 flex items-center justify-center text-white shadow-md shadow-emerald-500/30"><i class="fas fa-snowflake text-sm"></i></span>
                    <?= T(' Chiller System - 3 Unit Operasi & Monitoring') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_chiller_sub', 'Checklist unit chiller yang jalan, test pH & TDS air chiller, suhu & tekanan pompa CHWP / CWP') ?></p>
            </div>
            <div class="p-5 lg:p-6 space-y-5">
                <!-- 3 Unit Checkbox ON/OFF -->
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.2em] text-emerald-800 mb-3 flex items-center gap-2"><i class="fas fa-toggle-on"></i> <?= T('form_chiller_unit_status', 'Unit Operation Status') ?></p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <?php
                        $chillers = [
                            ['chiller_1_on', T('form_chiller_unit1', 'Chiller Unit 1'), 'from-green-400 to-green-600'],
                            ['chiller_2_on', T('form_chiller_unit2', 'Chiller Unit 2'), 'from-emerald-400 to-teal-600'],
                            ['chiller_3_on', T('form_chiller_unit3', 'Chiller Unit 3'), 'from-teal-400 to-cyan-600'],
                        ];
                        foreach ($chillers as $ch) {
                            [$field, $label, $grad] = $ch;
                            $checked = !empty($log[$field]) ? 'checked' : '';
                            echo <<<HTML
                        <label class="group cursor-pointer select-none p-4 rounded-card border-2 border-dashed border-emerald-200 hover:border-solid hover:border-emerald-400 bg-emerald-50/40 hover:bg-emerald-50 transition-all">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 shrink-0 rounded-xl bg-gradient-to-br {$grad} flex items-center justify-center text-white shadow-lg shadow-emerald-500/25 group-hover:scale-110 transition-transform">
                                    <i class="fas fa-temperature-half"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-extrabold text-primary text-sm leading-tight">{$label}</p>
                                    <p class="text-[10px] text-emerald-700 font-bold mt-0.5 uppercase tracking-wider"><?= T('form_chiller_unit_on', 'ON / ACTIVE TODAY') ?></p>
                                </div>
                                <div class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="{$field}" value="1" class="sr-only peer" {$checked}>
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-emerald-500/20 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-emerald-500 peer-checked:to-green-600 after:shadow-sm"></div>
                                </div>
                            </div>
                        </label>
HTML;
                        }
                        ?>
                    </div>
                </div>
                <!-- 5 Numeric fields (pH / TDS / Temp / CHWP / CWP) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-flask mr-1 text-green-600"></i><?= T('form_chiller_ph', 'Chiller pH (0-14)') ?></label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" max="14" name="chiller_water_ph"
                                value="<?= $log['chiller_water_ph'] ?? '0.00' ?>"
                                class="w-full pl-3 pr-10 py-3 rounded-card border border-green-200 bg-green-50/60 font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 focus:bg-white transition-all text-sm">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-green-700 font-black bg-white/90 border border-green-200 px-1.5 py-0.5 rounded-full">pH</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-vial mr-1 text-emerald-600"></i><?= T('form_chiller_tds', 'Chiller TDS (ppm)') ?></label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="chiller_water_tds"
                                value="<?= $log['chiller_water_tds'] ?? '0.00' ?>"
                                class="w-full pl-3 pr-10 py-3 rounded-card border border-emerald-200 bg-emerald-50/60 font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 focus:bg-white transition-all text-sm">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-emerald-700 font-black bg-white/90 border border-emerald-200 px-1.5 py-0.5 rounded-full">ppm</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-temperature-arrow-up mr-1 text-cyan-600"></i><?= T('form_chiller_temp', 'Temperature (°C)') ?></label>
                        <div class="relative">
                            <input type="number" step="0.01" name="chiller_temp"
                                value="<?= $log['chiller_temp'] ?? '0.00' ?>"
                                class="w-full pl-3 pr-10 py-3 rounded-card border border-cyan-200 bg-cyan-50/60 font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-cyan-500 focus:ring-4 focus:ring-cyan-500/10 focus:bg-white transition-all text-sm">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-cyan-700 font-black bg-white/90 border border-cyan-200 px-1.5 py-0.5 rounded-full">°C</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-gauge-high mr-1 text-blue-600"></i><?= T('form_chiller_chwp', 'Pressure CHWP (bar)') ?></label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="chiller_pressure_chwp"
                                value="<?= $log['chiller_pressure_chwp'] ?? '0.00' ?>"
                                class="w-full pl-3 pr-10 py-3 rounded-card border border-blue-200 bg-blue-50/60 font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 focus:bg-white transition-all text-sm">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-blue-700 font-black bg-white/90 border border-blue-200 px-1.5 py-0.5 rounded-full">bar</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-gauge mr-1 text-indigo-600"></i><?= T('form_chiller_cwp', 'Pressure CWP (bar)') ?></label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="chiller_pressure_cwp"
                                value="<?= $log['chiller_pressure_cwp'] ?? '0.00' ?>"
                                class="w-full pl-3 pr-10 py-3 rounded-card border border-indigo-200 bg-indigo-50/60 font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 focus:bg-white transition-all text-sm">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-indigo-700 font-black bg-white/90 border border-indigo-200 px-1.5 py-0.5 rounded-full">bar</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑦ FUEL -->
        <div class="bg-surface rounded-premium border border-rose-200/70 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 290ms">
            <div class="px-5 lg:px-6 py-4 border-b border-rose-100/80 bg-gradient-to-r from-rose-50/90 via-pink-50/60 to-red-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-rose-400 to-red-600 flex items-center justify-center text-white shadow-md shadow-rose-500/30"><i class="fas fa-gas-pump text-sm"></i></span>
                    <?= T( ' Fuel - Konsumsi Solar / Bahan Bakar (Liter)') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_fuel_sub', 'Isi total konsumsi bahan bakar kendaraan / genset hari ini') ?></p>
            </div>
            <div class="p-5 lg:p-6">
                <div class="max-w-sm mx-auto sm:mx-0">
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><?= T('form_fuel_total', 'Total Fuel (Liter)') ?></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="total_fuel"
                            value="<?= $log['total_fuel'] ?? '0.00' ?>"
                            class="w-full pl-4 pr-16 py-3.5 rounded-card border border-rose-200 bg-rose-50/60 text-lg font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-rose-500 focus:ring-4 focus:ring-rose-500/10 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-rose-700 font-bold bg-rose-100 px-2 py-0.5 rounded-full">L</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑩ EQUIPMENT LOG — 8 SECTIONS (PINDAHAN DARI LOG SHEET) -->
        <!-- ⑩-1 TRAFO -->
        <div class="bg-surface rounded-premium border border-blue-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 310ms">
            <div class="px-5 lg:px-6 py-4 border-b border-blue-100/80 bg-gradient-to-r from-blue-50/90 via-sky-50/60 to-indigo-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 via-blue-600 to-indigo-700 flex items-center justify-center text-white shadow-md shadow-blue-500/30"><i class="fas fa-bolt text-sm"></i></span>
                    1. Trafo — 2 Unit (Temp, Ampere LVDP, Oil Level)
                </h3>
                <p class="text-xs text-secondary mt-0.5">Monitoring suhu, beban ampere LVDP, dan level oli trafo 1 & 2</p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php for ($tu=1; $tu<=2; $tu++):
                    $tv = $eq['trafo']['units'][$tu];
                ?>
                <div class="rounded-card border-2 border-dashed border-blue-200 bg-blue-50/40 p-4">
                    <p class="text-xs font-black uppercase tracking-[0.18em] text-blue-800 mb-3"><i class="fas fa-microchip mr-1"></i> Trafo Unit <?= $tu ?></p>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-primary mb-1">Temp (°C)</label>
                            <input type="number" step="0.01" name="trafo_<?= $tu ?>_temp" value="<?= $tv['temp_c'] ?>" class="w-full px-2.5 py-2 rounded-lg border border-blue-200 bg-white font-bold text-sm focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/10">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-primary mb-1">Ampere LVDP (A)</label>
                            <input type="number" step="0.01" name="trafo_<?= $tu ?>_ampere" value="<?= $tv['ampere_lvdp'] ?>" class="w-full px-2.5 py-2 rounded-lg border border-blue-200 bg-white font-bold text-sm focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/10">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-primary mb-1">Oil Level (%)</label>
                            <input type="number" step="0.01" name="trafo_<?= $tu ?>_oil" value="<?= $tv['oil_level_pct'] ?>" class="w-full px-2.5 py-2 rounded-lg border border-blue-200 bg-white font-bold text-sm focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/10">
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- ⑩-2 GENSET -->
        <div class="bg-surface rounded-premium border border-amber-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 315ms">
            <div class="px-5 lg:px-6 py-4 border-b border-amber-100/80 bg-gradient-to-r from-amber-50/90 via-yellow-50/60 to-orange-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 via-yellow-600 to-orange-700 flex items-center justify-center text-white shadow-md shadow-amber-500/30"><i class="fas fa-industry text-sm"></i></span>
                    2. Genset — 3 Unit Voltage + Fuel Tank (Liter)
                </h3>
                <p class="text-xs text-secondary mt-0.5">Tegangan output genset 1/2/3 dan level bahan bakar tangki</p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-2 md:grid-cols-4 gap-4">
                <?php for ($gu=1; $gu<=3; $gu++): ?>
                <div>
                    <label class="block text-[11px] font-bold text-primary mb-1"><i class="fas fa-bolt text-amber-600 mr-1"></i> Gen <?= $gu ?> Voltage (V)</label>
                    <input type="number" step="0.01" name="genset_<?= $gu ?>_volt" value="<?= $eq['genset']['gen_'.$gu.'_volt'] ?>" class="w-full px-3 py-2.5 rounded-card border border-amber-200 bg-amber-50/60 font-bold text-sm focus:outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 focus:bg-white transition-all">
                </div>
                <?php endfor; ?>
                <div>
                    <label class="block text-[11px] font-bold text-primary mb-1"><i class="fas fa-gas-pump text-rose-600 mr-1"></i> Fuel Tank (L)</label>
                    <input type="number" step="0.01" name="genset_fuel_tank" value="<?= $eq['genset']['fuel_tank_liter'] ?>" class="w-full px-3 py-2.5 rounded-card border border-rose-200 bg-rose-50/60 font-bold text-sm focus:outline-none focus:border-rose-500 focus:ring-4 focus:ring-rose-500/10 focus:bg-white transition-all">
                </div>
            </div>
        </div>

        <!-- ⑩-3 PUMP ROOM -->
        <div class="bg-surface rounded-premium border border-emerald-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 320ms">
            <div class="px-5 lg:px-6 py-4 border-b border-emerald-100/80 bg-gradient-to-r from-emerald-50/90 via-green-50/60 to-teal-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 via-green-600 to-teal-700 flex items-center justify-center text-white shadow-md shadow-emerald-500/30"><i class="fas fa-water text-sm"></i></span>
                    3. Pump Room — Boiler, Tank, Hydrant, Filter, Booster Pump
                </h3>
                <p class="text-xs text-secondary mt-0.5">Steam Boiler, Hot Water Boiler, Ground Tank, Hydrant Pump, Sand Filter, Booster</p>
            </div>
            <div class="p-5 lg:p-6 space-y-5">
                <!-- Steam Boiler -->
                <div class="rounded-card border border-emerald-200 bg-emerald-50/30 p-4">
                    <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-800 mb-3"><i class="fas fa-fire mr-1"></i> Steam Boiler (SB)</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold text-primary mb-1">Unit Op</label>
                            <select name="pump_sb_unit_op" class="w-full px-2 py-2 rounded-lg border border-emerald-200 bg-white text-xs font-bold focus:outline-none focus:border-emerald-500">
                                <?php foreach(['off','standby','on','auto'] as $o): ?><option value="<?= $o ?>" <?= $eq['pump']['sb_unit_op']===$o?'selected':'' ?>><?= strtoupper($o) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">SB-1 Hours</label><input type="text" name="pump_sb1_hours" value="<?= cleanInput($eq['pump']['sb1_hours']) ?>" class="w-full px-2 py-2 rounded-lg border border-emerald-200 bg-white text-xs font-bold focus:outline-none focus:border-emerald-500"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">SB-2 Hours</label><input type="text" name="pump_sb2_hours" value="<?= cleanInput($eq['pump']['sb2_hours']) ?>" class="w-full px-2 py-2 rounded-lg border border-emerald-200 bg-white text-xs font-bold focus:outline-none focus:border-emerald-500"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Test TDS/pH</label><input type="text" name="pump_sb_test" value="<?= cleanInput($eq['pump']['sb_test']) ?>" class="w-full px-2 py-2 rounded-lg border border-emerald-200 bg-white text-xs font-bold focus:outline-none focus:border-emerald-500"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Steam Press</label><input type="text" name="pump_sb_press" value="<?= cleanInput($eq['pump']['sb_press']) ?>" class="w-full px-2 py-2 rounded-lg border border-emerald-200 bg-white text-xs font-bold focus:outline-none focus:border-emerald-500"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Blow Down</label><input type="text" name="pump_sb_blow" value="<?= cleanInput($eq['pump']['sb_blow']) ?>" class="w-full px-2 py-2 rounded-lg border border-emerald-200 bg-white text-xs font-bold focus:outline-none focus:border-emerald-500"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Econ Temp</label><input type="text" name="pump_sb_econ_temp" value="<?= cleanInput($eq['pump']['sb_econ_temp']) ?>" class="w-full px-2 py-2 rounded-lg border border-emerald-200 bg-white text-xs font-bold focus:outline-none focus:border-emerald-500"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Econ Press</label><input type="text" name="pump_sb_econ_press" value="<?= cleanInput($eq['pump']['sb_econ_press']) ?>" class="w-full px-2 py-2 rounded-lg border border-emerald-200 bg-white text-xs font-bold focus:outline-none focus:border-emerald-500"></div>
                    </div>
                </div>
                <!-- Hot Water Boiler -->
                <div class="rounded-card border border-green-200 bg-green-50/30 p-4">
                    <p class="text-xs font-black uppercase tracking-[0.18em] text-green-800 mb-3"><i class="fas fa-mug-hot mr-1"></i> Hot Water Boiler (HWB)</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3">
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Unit Op</label><select name="pump_hwb_unit_op" class="w-full px-2 py-2 rounded-lg border border-green-200 bg-white text-xs font-bold focus:outline-none focus:border-green-500"><?php foreach(['off','standby','on','auto'] as $o): ?><option value="<?= $o ?>" <?= $eq['pump']['hwb_unit_op']===$o?'selected':'' ?>><?= strtoupper($o) ?></option><?php endforeach; ?></select></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">HWB-1 Hours</label><input type="text" name="pump_hwb1_hours" value="<?= cleanInput($eq['pump']['hwb1_hours']) ?>" class="w-full px-2 py-2 rounded-lg border border-green-200 bg-white text-xs font-bold focus:outline-none focus:border-green-500"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">HWB-2 Hours</label><input type="text" name="pump_hwb2_hours" value="<?= cleanInput($eq['pump']['hwb2_hours']) ?>" class="w-full px-2 py-2 rounded-lg border border-green-200 bg-white text-xs font-bold focus:outline-none focus:border-green-500"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">HW Temp (°C)</label><input type="text" name="pump_hwb_temp" value="<?= cleanInput($eq['pump']['hwb_temp']) ?>" class="w-full px-2 py-2 rounded-lg border border-green-200 bg-white text-xs font-bold focus:outline-none focus:border-green-500"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Test TDS/pH</label><input type="text" name="pump_hwb_test" value="<?= cleanInput($eq['pump']['hwb_test']) ?>" class="w-full px-2 py-2 rounded-lg border border-green-200 bg-white text-xs font-bold focus:outline-none focus:border-green-500"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Circ Pump</label><input type="text" name="pump_hwb_circ_op" value="<?= cleanInput($eq['pump']['hwb_circ_op']) ?>" class="w-full px-2 py-2 rounded-lg border border-green-200 bg-white text-xs font-bold focus:outline-none focus:border-green-500"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Flow Press</label><input type="text" name="pump_hwb_flow" value="<?= cleanInput($eq['pump']['hwb_flow']) ?>" class="w-full px-2 py-2 rounded-lg border border-green-200 bg-white text-xs font-bold focus:outline-none focus:border-green-500"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Return Press</label><input type="text" name="pump_hwb_ret" value="<?= cleanInput($eq['pump']['hwb_ret']) ?>" class="w-full px-2 py-2 rounded-lg border border-green-200 bg-white text-xs font-bold focus:outline-none focus:border-green-500"></div>
                    </div>
                </div>
                <!-- Ground Tank + Hydrant + Jockey + Sand Filter -->
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
                    <div class="rounded-card border border-teal-200 bg-teal-50/30 p-4">
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-teal-800 mb-3"><i class="fas fa-oil-can mr-1"></i> Ground Tank</p>
                        <div class="space-y-2">
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Raw Tank</label><input type="text" name="pump_tank_raw" value="<?= cleanInput($eq['pump']['tank_raw']) ?>" class="w-full px-2 py-2 rounded-lg border border-teal-200 bg-white text-xs font-bold focus:outline-none focus:border-teal-500"></div>
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Treated Tank</label><input type="text" name="pump_tank_treated" value="<?= cleanInput($eq['pump']['tank_treated']) ?>" class="w-full px-2 py-2 rounded-lg border border-teal-200 bg-white text-xs font-bold focus:outline-none focus:border-teal-500"></div>
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Irigasi Tank</label><input type="text" name="pump_tank_irigasi" value="<?= cleanInput($eq['pump']['tank_irigasi']) ?>" class="w-full px-2 py-2 rounded-lg border border-teal-200 bg-white text-xs font-bold focus:outline-none focus:border-teal-500"></div>
                        </div>
                    </div>
                    <div class="rounded-card border border-red-200 bg-red-50/30 p-4">
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-red-800 mb-3"><i class="fas fa-fire-extinguisher mr-1"></i> Hydrant Pump</p>
                        <div class="space-y-2">
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Standby/Auto</label><select name="pump_hyd_standby" class="w-full px-2 py-2 rounded-lg border border-red-200 bg-white text-xs font-bold focus:outline-none focus:border-red-500"><?php foreach(['standby','auto','off','on'] as $o): ?><option value="<?= $o ?>" <?= $eq['pump']['hyd_standby']===$o?'selected':'' ?>><?= strtoupper($o) ?></option><?php endforeach; ?></select></div>
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Press Pump-1</label><input type="text" name="pump_hyd_press1" value="<?= cleanInput($eq['pump']['hyd_press1']) ?>" class="w-full px-2 py-2 rounded-lg border border-red-200 bg-white text-xs font-bold focus:outline-none focus:border-red-500"></div>
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Press Pump-2</label><input type="text" name="pump_hyd_press2" value="<?= cleanInput($eq['pump']['hyd_press2']) ?>" class="w-full px-2 py-2 rounded-lg border border-red-200 bg-white text-xs font-bold focus:outline-none focus:border-red-500"></div>
                        </div>
                    </div>
                    <div class="rounded-card border border-pink-200 bg-pink-50/30 p-4">
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-pink-800 mb-3"><i class="fas fa-horse-head mr-1"></i> Jockey Pump</p>
                        <div class="space-y-2">
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Standby Press</label><input type="text" name="pump_jockey_press" value="<?= cleanInput($eq['pump']['jockey_press']) ?>" class="w-full px-2 py-2 rounded-lg border border-pink-200 bg-white text-xs font-bold focus:outline-none focus:border-pink-500"></div>
                        </div>
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-cyan-800 mb-3 mt-4"><i class="fas fa-filter mr-1"></i> Sand Filter</p>
                        <div class="space-y-2">
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Status</label><select name="pump_sf_status" class="w-full px-2 py-2 rounded-lg border border-cyan-200 bg-white text-xs font-bold focus:outline-none focus:border-cyan-500"><?php foreach(['off','on','backwash','rinse'] as $o): ?><option value="<?= $o ?>" <?= $eq['pump']['sf_status']===$o?'selected':'' ?>><?= strtoupper($o) ?></option><?php endforeach; ?></select></div>
                        </div>
                    </div>
                    <div class="rounded-card border border-sky-200 bg-sky-50/30 p-4">
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-sky-800 mb-3"><i class="fas fa-filter-circle-dollar mr-1"></i> SF + Booster</p>
                        <div class="space-y-2">
                            <div><label class="block text-[10px] font-bold text-primary mb-1">SF Press Sand</label><input type="text" name="pump_sf_press_sand" value="<?= cleanInput($eq['pump']['sf_press_sand']) ?>" class="w-full px-2 py-2 rounded-lg border border-sky-200 bg-white text-xs font-bold focus:outline-none focus:border-sky-500"></div>
                            <div><label class="block text-[10px] font-bold text-primary mb-1">SF Press Carbon</label><input type="text" name="pump_sf_press_carbon" value="<?= cleanInput($eq['pump']['sf_press_carbon']) ?>" class="w-full px-2 py-2 rounded-lg border border-sky-200 bg-white text-xs font-bold focus:outline-none focus:border-sky-500"></div>
                            <div><label class="block text-[10px] font-bold text-primary mb-1">SF Pump Status</label><select name="pump_sfp_status" class="w-full px-2 py-2 rounded-lg border border-sky-200 bg-white text-xs font-bold focus:outline-none focus:border-sky-500"><?php foreach(['off','on'] as $o): ?><option value="<?= $o ?>" <?= $eq['pump']['sfp_status']===$o?'selected':'' ?>><?= strtoupper($o) ?></option><?php endforeach; ?></select></div>
                            <div><label class="block text-[10px] font-bold text-primary mb-1">SF Pump Op</label><input type="text" name="pump_sfp_unit_op" value="<?= cleanInput($eq['pump']['sfp_unit_op']) ?>" class="w-full px-2 py-2 rounded-lg border border-sky-200 bg-white text-xs font-bold focus:outline-none focus:border-sky-500"></div>
                        </div>
                    </div>
                </div>
                <!-- Booster Villa + MH + Irigasi -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="rounded-card border border-violet-200 bg-violet-50/30 p-4">
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-violet-800 mb-3"><i class="fas fa-house-chimney mr-1"></i> Booster Pump Villa</p>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Unit Op</label><input type="text" name="pump_bpv_unit_op" value="<?= cleanInput($eq['pump']['bpv_unit_op']) ?>" class="w-full px-2 py-2 rounded-lg border border-violet-200 bg-white text-xs font-bold focus:outline-none focus:border-violet-500"></div>
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Press</label><input type="text" name="pump_bpv_press" value="<?= cleanInput($eq['pump']['bpv_press']) ?>" class="w-full px-2 py-2 rounded-lg border border-violet-200 bg-white text-xs font-bold focus:outline-none focus:border-violet-500"></div>
                        </div>
                    </div>
                    <div class="rounded-card border border-purple-200 bg-purple-50/30 p-4">
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-purple-800 mb-3"><i class="fas fa-building mr-1"></i> Booster Pump MH</p>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Unit Op</label><input type="text" name="pump_bpm_unit_op" value="<?= cleanInput($eq['pump']['bpm_unit_op']) ?>" class="w-full px-2 py-2 rounded-lg border border-purple-200 bg-white text-xs font-bold focus:outline-none focus:border-purple-500"></div>
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Press</label><input type="text" name="pump_bpm_press" value="<?= cleanInput($eq['pump']['bpm_press']) ?>" class="w-full px-2 py-2 rounded-lg border border-purple-200 bg-white text-xs font-bold focus:outline-none focus:border-purple-500"></div>
                        </div>
                    </div>
                    <div class="rounded-card border border-lime-200 bg-lime-50/30 p-4">
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-lime-800 mb-3"><i class="fas fa-seedling mr-1"></i> Irrigation Pump</p>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Unit Op</label><input type="text" name="pump_irigasi_unit_op" value="<?= cleanInput($eq['pump']['irigasi_unit_op']) ?>" class="w-full px-2 py-2 rounded-lg border border-lime-200 bg-white text-xs font-bold focus:outline-none focus:border-lime-500"></div>
                            <div><label class="block text-[10px] font-bold text-primary mb-1">Press</label><input type="text" name="pump_irigasi_press" value="<?= cleanInput($eq['pump']['irigasi_press']) ?>" class="w-full px-2 py-2 rounded-lg border border-lime-200 bg-white text-xs font-bold focus:outline-none focus:border-lime-500"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑩-4 CHILLER SYSTEM (Equipment) -->
        <div class="bg-surface rounded-premium border border-cyan-200/70 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 325ms">
            <div class="px-5 lg:px-6 py-4 border-b border-cyan-100/80 bg-gradient-to-r from-cyan-50/90 via-teal-50/60 to-sky-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-cyan-500 via-sky-600 to-blue-700 flex items-center justify-center text-white shadow-md shadow-cyan-500/30"><i class="fas fa-temperature-low text-sm"></i></span>
                    4. Chiller System — Unit Op, CWP & CHWP Pump
                </h3>
                <p class="text-xs text-secondary mt-0.5">Catatan: Ada Chiller System terpisah di section ⑥ (3 Unit + pH/TDS/Temp/Press). Ini adalah Equipment System log sheet.</p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="rounded-card border border-cyan-200 bg-cyan-50/40 p-4">
                    <p class="text-xs font-black uppercase tracking-[0.18em] text-cyan-800 mb-3"><i class="fas fa-snowflake mr-1"></i> Chiller Unit</p>
                    <div class="space-y-2">
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Unit Op</label><select name="chiller_unit_op" class="w-full px-2.5 py-2.5 rounded-lg border border-cyan-200 bg-white text-sm font-bold focus:outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/10"><?php foreach(['carrier','unit1','unit2','unit3','off'] as $o): ?><option value="<?= $o ?>" <?= $eq['chiller']['unit_op']===$o?'selected':'' ?>><?= strtoupper($o) ?></option><?php endforeach; ?></select></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Chilled Water Test</label><input type="text" name="chiller_cw_test" value="<?= cleanInput($eq['chiller']['cw_test']) ?>" class="w-full px-2.5 py-2.5 rounded-lg border border-cyan-200 bg-white text-sm font-bold focus:outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/10"></div>
                    </div>
                </div>
                <div class="rounded-card border border-sky-200 bg-sky-50/40 p-4">
                    <p class="text-xs font-black uppercase tracking-[0.18em] text-sky-800 mb-3"><i class="fas fa-water mr-1"></i> Condensor Water Pump</p>
                    <div class="space-y-2">
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Unit Op</label><input type="text" name="chiller_cwp_unit_op" value="<?= cleanInput($eq['chiller']['cwp_unit_op']) ?>" class="w-full px-2.5 py-2.5 rounded-lg border border-sky-200 bg-white text-sm font-bold focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/10"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Water Press (kg/cm2)</label><input type="text" name="chiller_cwp_press" value="<?= cleanInput($eq['chiller']['cwp_press']) ?>" class="w-full px-2.5 py-2.5 rounded-lg border border-sky-200 bg-white text-sm font-bold focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/10"></div>
                    </div>
                </div>
                <div class="rounded-card border border-blue-200 bg-blue-50/40 p-4">
                    <p class="text-xs font-black uppercase tracking-[0.18em] text-blue-800 mb-3"><i class="fas fa-arrows-turn-right mr-1"></i> Chilled Water Pump</p>
                    <div class="space-y-2">
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Unit Op</label><input type="text" name="chiller_chwp_unit_op" value="<?= cleanInput($eq['chiller']['chwp_unit_op']) ?>" class="w-full px-2.5 py-2.5 rounded-lg border border-blue-200 bg-white text-sm font-bold focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/10"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Press In (kg/cm2)</label><input type="text" name="chiller_chwp_in" value="<?= cleanInput($eq['chiller']['chwp_in']) ?>" class="w-full px-2.5 py-2.5 rounded-lg border border-blue-200 bg-white text-sm font-bold focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/10"></div>
                        <div><label class="block text-[10px] font-bold text-primary mb-1">Press Out (kg/cm2)</label><input type="text" name="chiller_chwp_out" value="<?= cleanInput($eq['chiller']['chwp_out']) ?>" class="w-full px-2.5 py-2.5 rounded-lg border border-blue-200 bg-white text-sm font-bold focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/10"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑩-5 COOLING TOWER -->
        <div class="bg-surface rounded-premium border border-sky-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 330ms">
            <div class="px-5 lg:px-6 py-4 border-b border-sky-100/80 bg-gradient-to-r from-sky-50/90 via-blue-50/60 to-cyan-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-sky-500 via-blue-600 to-cyan-700 flex items-center justify-center text-white shadow-md shadow-sky-500/30"><i class="fas fa-fan text-sm"></i></span>
                    5. Cooling Tower — Unit Op, Water Level & Test
                </h3>
                <p class="text-xs text-secondary mt-0.5">Operational unit cooling tower, level air, dan test TDS/pH</p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide">Unit Op (No Unit)</label>
                    <input type="text" name="ct_unit_op" value="<?= cleanInput($eq['ct']['unit_op']) ?>" class="w-full px-3 py-3 rounded-card border border-sky-200 bg-sky-50/60 font-bold text-sm focus:outline-none focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 focus:bg-white transition-all">
                </div>
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide">Water Level (%)</label>
                    <input type="text" name="ct_level" value="<?= cleanInput($eq['ct']['level']) ?>" class="w-full px-3 py-3 rounded-card border border-blue-200 bg-blue-50/60 font-bold text-sm focus:outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 focus:bg-white transition-all">
                </div>
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide">Test TDS/pH</label>
                    <input type="text" name="ct_test" value="<?= cleanInput($eq['ct']['test']) ?>" class="w-full px-3 py-3 rounded-card border border-cyan-200 bg-cyan-50/60 font-bold text-sm focus:outline-none focus:border-cyan-500 focus:ring-4 focus:ring-cyan-500/10 focus:bg-white transition-all">
                </div>
            </div>
        </div>

        <!-- ⑩-6 REVERSE OSMOSIS (RO) -->
        <div class="bg-surface rounded-premium border border-fuchsia-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 335ms">
            <div class="px-5 lg:px-6 py-4 border-b border-fuchsia-100/80 bg-gradient-to-r from-fuchsia-50/90 via-pink-50/60 to-rose-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-fuchsia-500 via-pink-600 to-rose-700 flex items-center justify-center text-white shadow-md shadow-fuchsia-500/30"><i class="fas fa-droplet text-sm"></i></span>
                    6. Reverse Osmosis (RO) — Water Meter, Permeate & Test
                </h3>
                <p class="text-xs text-secondary mt-0.5">Catatan: Ada SWRO terpisah di section ④. Ini adalah RO Equipment log sheet.</p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-gauge-high mr-1 text-fuchsia-600"></i> Water Meter (m3)</label>
                    <input type="text" name="ro_meter" value="<?= cleanInput($eq['ro']['meter']) ?>" class="w-full px-3 py-3 rounded-card border border-fuchsia-200 bg-fuchsia-50/60 font-bold text-sm focus:outline-none focus:border-fuchsia-500 focus:ring-4 focus:ring-fuchsia-500/10 focus:bg-white transition-all">
                </div>
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-water mr-1 text-pink-600"></i> Permeate (m3/jam)</label>
                    <input type="text" name="ro_permeate" value="<?= cleanInput($eq['ro']['permeate']) ?>" class="w-full px-3 py-3 rounded-card border border-pink-200 bg-pink-50/60 font-bold text-sm focus:outline-none focus:border-pink-500 focus:ring-4 focus:ring-pink-500/10 focus:bg-white transition-all">
                </div>
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-vial mr-1 text-rose-600"></i> TDS/pH Permeate</label>
                    <input type="text" name="ro_test_permeate" value="<?= cleanInput($eq['ro']['test_permeate']) ?>" class="w-full px-3 py-3 rounded-card border border-rose-200 bg-rose-50/60 font-bold text-sm focus:outline-none focus:border-rose-500 focus:ring-4 focus:ring-rose-500/10 focus:bg-white transition-all">
                </div>
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-flask-vial mr-1 text-red-600"></i> TDS/pH Deep Well</label>
                    <input type="text" name="ro_test_deepwell" value="<?= cleanInput($eq['ro']['test_deepwell']) ?>" class="w-full px-3 py-3 rounded-card border border-red-200 bg-red-50/60 font-bold text-sm focus:outline-none focus:border-red-500 focus:ring-4 focus:ring-red-500/10 focus:bg-white transition-all">
                </div>
            </div>
        </div>

        <!-- ⑩-7 POOL SYSTEM -->
        <div class="bg-surface rounded-premium border border-teal-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 340ms">
            <div class="px-5 lg:px-6 py-4 border-b border-teal-100/80 bg-gradient-to-r from-teal-50/90 via-emerald-50/60 to-cyan-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-teal-500 via-emerald-600 to-cyan-700 flex items-center justify-center text-white shadow-md shadow-teal-500/30"><i class="fas fa-person-swimming text-sm"></i></span>
                    7. Pool System — Lagoon 1 & 2, Aquavitale, Main Pump Room
                </h3>
                <p class="text-xs text-secondary mt-0.5">Alarm status, pump running, pressure tank, submersible auto</p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php
                $poolMap = [
                    ['l1','Lagoon 1','from-teal-400 to-cyan-600'],
                    ['l2','Lagoon 2','from-cyan-400 to-blue-600'],
                    ['aqua','Aquavitale','from-emerald-400 to-teal-600'],
                    ['mpr','Main Pump Room','from-green-400 to-emerald-600'],
                ];
                foreach ($poolMap as $pm):
                    [$pkey, $ptitle, $pg] = $pm;
                    $alarmKey = $pkey.'_alarm';
                    $pumpKey  = $pkey.'_pump';
                    $pressKey = $pkey.'_press';
                    $subKey   = $pkey.'_sub_auto';
                    $hwbKey   = $pkey.'_hwbtemp';
                    $postPre  = 'pool_'.$pkey.'_';
                    $hasHwb = ($pkey === 'aqua');
                    $hasPump = ($pkey !== 'mpr');
                    $hasPress = ($pkey === 'l1' || $pkey === 'l2');
                ?>
                <div class="rounded-card border-2 border-dashed border-teal-200 bg-teal-50/30 p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br <?= $pg ?> flex items-center justify-center text-white text-xs shadow-sm"><i class="fas fa-swimming-pool"></i></div>
                        <p class="text-xs font-black uppercase tracking-[0.15em] text-teal-900"><?= $ptitle ?></p>
                    </div>
                    <div class="space-y-2.5">
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block text-[10px] font-bold text-primary"><span>Alarm</span>
                                <select name="<?= $postPre ?>alarm" class="w-full mt-0.5 px-1.5 py-1.5 rounded-md border border-teal-200 bg-white text-[10px] font-bold focus:outline-none focus:border-teal-500">
                                    <option value="on"  <?= ($eq['pool'][$alarmKey] ?? 'on')==='on'?'selected':''?>>ON</option>
                                    <option value="off" <?= ($eq['pool'][$alarmKey] ?? 'on')==='off'?'selected':''?>>OFF</option>
                                </select>
                            </label>
                            <?php if ($hasPump): ?>
                            <label class="block text-[10px] font-bold text-primary"><span>Pump</span>
                                <input type="text" name="<?= $postPre ?>pump" value="<?= cleanInput($eq['pool'][$pumpKey] ?? '') ?>" class="w-full mt-0.5 px-1.5 py-1.5 rounded-md border border-teal-200 bg-white text-[10px] font-bold focus:outline-none focus:border-teal-500">
                            </label>
                            <?php endif; ?>
                        </div>
                        <?php if ($hasPress): ?>
                        <label class="block text-[10px] font-bold text-primary"><span>Press Tank (kg/cm2)</span>
                            <input type="text" name="<?= $postPre ?>press" value="<?= cleanInput($eq['pool'][$pressKey] ?? '') ?>" class="w-full mt-0.5 px-2 py-1.5 rounded-md border border-teal-200 bg-white text-[10px] font-bold focus:outline-none focus:border-teal-500">
                        </label>
                        <?php endif; ?>
                        <?php if ($hasHwb): ?>
                        <label class="block text-[10px] font-bold text-primary"><span>HWB Temp (°C)</span>
                            <input type="text" name="<?= $postPre ?>hwbtemp" value="<?= cleanInput($eq['pool'][$hwbKey] ?? '') ?>" class="w-full mt-0.5 px-2 py-1.5 rounded-md border border-teal-200 bg-white text-[10px] font-bold focus:outline-none focus:border-teal-500">
                        </label>
                        <?php endif; ?>
                        <label class="block text-[10px] font-bold text-primary"><span>Submersible</span>
                            <select name="<?= $postPre ?>sub_auto" class="w-full mt-0.5 px-1.5 py-1.5 rounded-md border border-teal-200 bg-white text-[10px] font-bold focus:outline-none focus:border-teal-500">
                                <option value="auto"  <?= ($eq['pool'][$subKey] ?? 'auto')==='auto'?'selected':''?>>AUTO</option>
                                <option value="manual" <?= ($eq['pool'][$subKey] ?? 'auto')==='manual'?'selected':''?>>MANUAL</option>
                                <option value="off" <?= ($eq['pool'][$subKey] ?? 'auto')==='off'?'selected':''?>>OFF</option>
                            </select>
                        </label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ⑩-8 GAS SYSTEM -->
        <div class="bg-surface rounded-premium border border-rose-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 345ms">
            <div class="px-5 lg:px-6 py-4 border-b border-rose-100/80 bg-gradient-to-r from-rose-50/90 via-red-50/60 to-orange-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-rose-500 via-red-600 to-orange-700 flex items-center justify-center text-white shadow-md shadow-rose-500/30"><i class="fas fa-gas-pump text-sm"></i></span>
                    8. Gas System — Detector 3 Lokasi (Valve + Alarm)
                </h3>
                <p class="text-xs text-secondary mt-0.5">Boneka Resto, Main Kitchen, Kayu Putih Resto — Selenoid Valve + Alarm</p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php
                $gasMap = [
                    ['boneka','Boneka Resto','from-rose-400 to-red-600'],
                    ['mainkitchen','Main Kitchen','from-red-400 to-orange-600'],
                    ['kayuputih','Kayu Putih Resto','from-orange-400 to-rose-600'],
                ];
                foreach ($gasMap as $gm):
                    [$gkey, $gtitle, $gg] = $gm;
                    $valveKey = $gkey.'_valve';
                    $alarmKey = $gkey.'_alarm';
                    $postPre  = 'gas_'.$gkey.'_';
                ?>
                <div class="rounded-card border-2 border-dashed border-rose-200 bg-rose-50/30 p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br <?= $gg ?> flex items-center justify-center text-white text-xs shadow-sm"><i class="fas fa-fire-flame-curved"></i></div>
                        <p class="text-xs font-black uppercase tracking-[0.15em] text-rose-900"><?= $gtitle ?></p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-[11px] font-bold text-primary"><span>Selenoid Valve</span>
                            <select name="<?= $postPre ?>valve" class="w-full mt-1 px-2 py-2 rounded-md border border-rose-200 bg-white text-xs font-bold focus:outline-none focus:border-rose-500">
                                <option value="open"  <?= ($eq['gas'][$valveKey] ?? 'open')==='open'?'selected':''?>>OPEN</option>
                                <option value="close" <?= ($eq['gas'][$valveKey] ?? 'open')==='close'?'selected':''?>>CLOSE</option>
                            </select>
                        </label>
                        <label class="block text-[11px] font-bold text-primary"><span>Alarm</span>
                            <select name="<?= $postPre ?>alarm" class="w-full mt-1 px-2 py-2 rounded-md border border-rose-200 bg-white text-xs font-bold focus:outline-none focus:border-rose-500">
                                <option value="on"  <?= ($eq['gas'][$alarmKey] ?? 'on')==='on'?'selected':''?>>ON</option>
                                <option value="off" <?= ($eq['gas'][$alarmKey] ?? 'on')==='off'?'selected':''?>>OFF</option>
                            </select>
                        </label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ⑨ ENG ACTIVITY COUNTERS (OTOMATIS DIHITUNG DARI LIST DINAMIS DI BAWAH) - HANYA MANAGER YANG BISA LIHAT -->
        <?php if (($user['role'] ?? '') === 'manager'): ?>
        <div class="bg-surface rounded-premium border border-accent/30 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 330ms">
            <div class="px-5 lg:px-6 py-4 border-b border-accent/20 bg-gradient-to-r from-amber-50/90 via-yellow-50/60 to-amber-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center text-white shadow-md shadow-amber-500/30"><i class="fas fa-list-ol text-sm"></i></span>
                    <?= T(' Engineering Activity Counter — OTOMATIS') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_activity_sub', 'Jumlah dihitung OTOMATIS berdasarkan daftar pekerjaan yang kamu buat di bawah!') ?></p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-2 md:grid-cols-4 gap-4">
                <?php
                $actFields = [
                    ['activity_operation', T('form_activity_operation', 'Operation (Operasional)'), 'from-blue-400 to-blue-600', 'bg-blue-50/60', 'border-blue-200', 'text-blue-700', 'fas fa-gears', 'act_count_op'],
                    ['activity_maintenance', T('form_activity_maintenance', 'Maintenance (Perawatan)'), 'from-emerald-400 to-emerald-600', 'bg-emerald-50/60', 'border-emerald-200', 'text-emerald-700', 'fas fa-wrench', 'act_count_maint'],
                    ['activity_project', T('form_activity_project', 'Project (Proyek)'), 'from-violet-400 to-violet-600', 'bg-violet-50/60', 'border-violet-200', 'text-violet-700', 'fas fa-diagram-project', 'act_count_proj'],
                    ['activity_landscape', T('form_activity_landscape', 'Landscape (Taman & Lingkungan)'), 'from-teal-400 to-teal-600', 'bg-teal-50/60', 'border-teal-200', 'text-teal-700', 'fas fa-leaf', 'act_count_land'],
                ];
                foreach ($actFields as $af) {
                    [$fname, $flabel, $fg, $fbg, $fbor, $fcol, $ficon, $fjs] = $af;
                    $fval = $log[$fname] ?? '0';
                    echo <<<HTML
                <div>
                    <label class="block text-[11px] font-black text-primary mb-2 tracking-wide uppercase flex items-center gap-1.5"><i class="{$ficon} {$fcol}"></i>{$flabel}</label>
                    <div class="relative">
                        <input type="hidden" name="{$fname}" id="{$fname}" value="{$fval}">
                        <div id="{$fjs}" class="w-full pl-3 pr-4 py-3 rounded-card border {$fbor} {$fbg} text-3xl font-black text-primary text-center shadow-inner">{$fval}</div>
                        <span class="absolute right-2 top-1/2 -translate-y-1/2 text-[10px] {$fcol} font-black bg-white/90 border {$fbor} px-2 py-1 rounded-full shadow-sm"><i class="fas fa-bolt"></i> AUTO</span>
                    </div>
                </div>
HTML;
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 370ms">
            <div class="px-5 lg:px-6 py-4 border-b border-border bg-muted/30">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <i class="fas fa-list-check text-green-600"></i><?= T('form_work_activities', '⑨ Aktivitas Pekerjaan (Per Baris)') ?> <span class="text-red-500">*</span>
                </h3>
                <p class="text-xs text-secondary mt-0.5">Kategori pekerjaan OTOMATIS hitung Counter di atas! Tambah baris untuk setiap aktivitas.</p>
            </div>
            <div class="p-5 lg:p-6 space-y-4">
                <div id="actList" class="space-y-3">
                    <!-- Dynamic Rows diisi JS dari existingActivities JSON -->
                </div>
                <button type="button" onclick="addActRow()" class="w-full py-3 rounded-xl border-2 border-dashed border-green-300 bg-green-50/70 hover:bg-green-50 hover:border-green-500 text-green-700 font-semibold transition-all">
                    <i class="fas fa-plus-circle mr-2 text-lg"></i> TAMBAH BARIS PEKERJAAN BARU
                </button>
                <input type="hidden" id="hidden_work_activities" name="work_activities" value="">
                <p class="text-[11px] text-secondary mt-1.5"><i class="fas fa-info-circle mr-1"></i><?= T('form_work_activities_min', 'Minimal 10 karakter • Total per baris dikumpulkan') ?></p>
            </div>
        </div>

        <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 410ms">
            <div class="px-5 lg:px-6 py-4 border-b border-border bg-muted/30">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <i class="fas fa-camera text-accent"></i><?= T('form_photo_title', 'Dokumentasi Foto') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_photo_sub', 'Unggah foto kondisi lapangan (opsional, max 10MB)') ?></p>
            </div>
            <div class="p-5 lg:p-6">
                <div class="flex flex-col lg:flex-row gap-5">
                    <label class="flex-1 cursor-pointer group">
                        <div class="border-2 border-dashed border-border hover:border-primary rounded-card p-8 text-center transition-all duration-300 group-hover:bg-muted/50 bg-muted/30">
                            <i class="fas fa-cloud-arrow-up text-4xl text-secondary group-hover:text-primary group-hover:scale-110 transition-all mb-3"></i>
                            <p class="font-semibold text-primary mb-1"><?= T('form_photo_upload', 'Klik untuk unggah foto') ?></p>
                            <p class="text-xs text-secondary"><?= T('form_photo_info', 'JPG, PNG, GIF • Max 10MB') ?></p>
                        </div>
                        <input type="file" name="photo" accept="image/*" class="hidden" id="photoInput" onchange="previewPhoto(this)">
                    </label>
                    <div class="w-full lg:w-64 h-56 rounded-card border border-border bg-muted overflow-hidden flex items-center justify-center">
                        <?php if ($log && $log['photo_path']): ?>
                            <img id="photoPreview" src="<?= UPLOAD_URL . $log['photo_path'] ?>" alt="<?= T('form_photo_title', 'Foto') ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <div id="photoPlaceholder" class="text-center text-secondary/60 p-4">
                                <i class="fas fa-image text-4xl mb-2 opacity-40"></i>
                                <p class="text-xs"><?= T('form_photo_none', 'Belum ada foto') ?></p>
                            </div>
                            <img id="photoPreview" class="hidden w-full h-full object-cover">
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 450ms">
            <div class="px-5 lg:px-6 py-4 border-b border-border bg-muted/30">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <i class="fas fa-clipboard-list text-accent"></i><?= T('form_work_title', 'Catatan Kendala & Solusi') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5">Isi kendala yang dihadapi dan solusi tindak lanjutnya</p>
            </div>
            <div class="p-5 lg:p-6 space-y-5">
                <div>
                    <label class="block text-sm font-semibold text-primary mb-2">
                        <i class="fas fa-triangle-exclamation mr-1.5 text-yellow-600"></i><?= T('form_work_obstacles', 'Kendala') ?>
                    </label>
                    <textarea name="obstacles" rows="3"
                        class="w-full px-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all resize-none"
                        placeholder="<?= T('form_work_obstacles_ph', 'Jelaskan kendala yang dihadapi (jika ada)...') ?>"><?= cleanInput($log['obstacles'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-primary mb-2">
                        <i class="fas fa-lightbulb mr-1.5 text-accent"></i><?= T('form_work_solutions', 'Solusi') ?>
                    </label>
                    <textarea name="solutions" rows="3"
                        class="w-full px-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all resize-none"
                        placeholder="<?= T('form_work_solutions_ph', 'Solusi yang dilakukan atau rencana tindak lanjut...') ?>"><?= cleanInput($log['solutions'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <script>
        // --- DYNAMIC ACTIVITY ROWS (OTOMATIS HITUNG COUNTER DI ATAS) ---
        const ACT_EXISTING = <?= json_encode($existingActivities ?: []) ?>;
        const ACT_FIELDS = [
            { v: 'operation',   c: 'Operation / Operasional', col: 'blue',    icon: 'fa-gears',          countEl: 'act_count_op',    hiddenEl: 'activity_operation' },
            { v: 'maintenance', c: 'Maintenance / Perawatan', col: 'emerald', icon: 'fa-wrench',         countEl: 'act_count_maint', hiddenEl: 'activity_maintenance' },
            { v: 'project',     c: 'Project / Proyek',        col: 'violet',  icon: 'fa-diagram-project',countEl: 'act_count_proj',  hiddenEl: 'activity_project' },
            { v: 'landscape',   c: 'Landscape / Taman',       col: 'teal',    icon: 'fa-leaf',           countEl: 'act_count_land',  hiddenEl: 'activity_landscape' },
        ];
        const COL_MAP = {
            blue:    { s: 'bg-blue-50 border-blue-200 text-blue-700',   b: 'border-blue-300',   i: 'bg-gradient-to-br from-blue-400 to-blue-600 text-white' },
            emerald: { s: 'bg-emerald-50 border-emerald-200 text-emerald-700', b: 'border-emerald-300', i: 'bg-gradient-to-br from-emerald-400 to-emerald-600 text-white' },
            violet:  { s: 'bg-violet-50 border-violet-200 text-violet-700',   b: 'border-violet-300',  i: 'bg-gradient-to-br from-violet-400 to-violet-600 text-white' },
            teal:    { s: 'bg-teal-50 border-teal-200 text-teal-700',         b: 'border-teal-300',    i: 'bg-gradient-to-br from-teal-400 to-teal-600 text-white' },
        };
        let actRowCounter = 0;
        function colOf(cat) { const f = ACT_FIELDS.find(x=>x.v===cat)||ACT_FIELDS[0]; return f; }
        function addActRow(cat='operation', title='') {
            const list = document.getElementById('actList');
            const idx = actRowCounter++;
            const row = document.createElement('div');
            row.className = 'actRow flex flex-col sm:flex-row gap-2 sm:items-stretch rounded-xl border-2 border-amber-200/50 bg-amber-50/40 p-2 hover:border-amber-300 hover:bg-amber-50/60 transition-all animate-slide-up';
            row.innerHTML = `
                <div class="sm:w-60">
                    <select class="actCat w-full h-12 px-3 rounded-lg bg-white border border-amber-200 font-bold text-sm" name="act[${idx}][cat]" onchange="onActChanged(this)">
                        ${ACT_FIELDS.map(f => `<option value="${f.v}" ${cat===f.v?'selected':''}><i class="fas ${f.icon}"></i> ${f.c}</option>`).join('')}
                    </select>
                </div>
                <div class="flex-1 flex gap-2">
                    <input type="text" class="actTitle flex-1 h-12 px-4 rounded-lg bg-white border border-amber-200 focus:border-amber-500 focus:ring-2 focus:ring-amber-200 outline-none transition-all text-primary"
                        name="act[${idx}][t]" placeholder="👉 Tulis nama pekerjaan disini..." value="${title.replace(/"/g,'&quot;')}" oninput="onActChanged(this)">
                    <button type="button" onclick="this.closest('.actRow').remove(); recalcAct();" class="h-12 px-4 rounded-lg bg-red-50 border border-red-200 text-red-600 font-bold hover:bg-red-100 transition-all">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            list.appendChild(row);
            recalcAct();
        }
        function onActChanged() { recalcAct(); }
        function recalcAct() {
            const counts = { operation:0, maintenance:0, project:0, landscape:0 };
            const lines = [];
            document.querySelectorAll('.actRow').forEach(row => {
                const cat = row.querySelector('.actCat').value;
                const t = (row.querySelector('.actTitle').value || '').trim();
                if (t.length > 0) {
                    counts[cat] = (counts[cat]||0) + 1;
                    lines.push('['+cat.toUpperCase()+'] '+t);
                }
            });
            ACT_FIELDS.forEach(f => {
                const n = counts[f.v] || 0;
                const ce = document.getElementById(f.countEl);
                const he = document.getElementById(f.hiddenEl);
                if (ce) { ce.textContent = String(n); if (n>0) { ce.classList.add('scale-110'); setTimeout(()=>ce.classList.remove('scale-110'),260); } }
                if (he) he.value = String(n);
            });
            document.getElementById('hidden_work_activities').value = lines.join('\n');
        }
        // init: tambahkan baris dari DB atau minimal 1 kosong
        document.addEventListener('DOMContentLoaded', () => {
            if (ACT_EXISTING && ACT_EXISTING.length) {
                ACT_EXISTING.forEach(a => addActRow(a.category || 'operation', a.activity_title || ''));
            }
            if (document.getElementById('actList').children.length === 0) {
                addActRow('operation',''); addActRow('maintenance','');
            }
        });
        </script>

        <script>
        // Global yesterday values (dari PHP: Shift MALAM kemarin) — diisi secara server-side
        window.Y_ELEC_WBP     = parseFloat('<?= $yElecWbpJs ?>')   || 0;
        window.Y_ELEC_LWBP    = parseFloat('<?= $yElecLwbpJs ?>')  || 0;
        window.Y_ELEC_TOTAL   = parseFloat('<?= $yElecTotalJs ?>') || 0;
        window.Y_WATER_MB     = parseFloat('<?= $yWaterMbJs ?>')   || 0;

        function onShiftChange() {
            const sel = document.getElementById('shiftSelect');
            const isMalam = sel && sel.value === 'malam';

            // 1. NOTICE BANNER LISTRIK
            const eNotice = document.getElementById('elecNotice');
            if (eNotice) {
                if (isMalam) {
                    eNotice.className = 'mt-3 text-[11px] font-semibold px-3 py-2 rounded-lg border bg-indigo-50 text-indigo-700 border-indigo-200';
                    eNotice.innerHTML = '<i class="fas fa-moon mr-1"></i> <b>Shift Malam</b> — Total Konsumsi = (WBP+LWBP) Hari ini − (WBP+LWBP) Shift Malam Kemarin. Hasil otomatis masuk ke Cost & Dashboard.';
                } else {
                    eNotice.className = 'mt-3 text-[11px] font-semibold px-3 py-2 rounded-lg border bg-emerald-50 text-emerald-700 border-emerald-200';
                    eNotice.innerHTML = '<i class="fas fa-circle-check mr-1"></i> <b>Shift Pagi/Siang</b> — WBP & LWBP <b>bisa diisi</b> (tidak dikunci). Total Konsumsi = 0 (tidak masuk kalkulasi biaya).';
                }
            }

            // 2. NOTICE BANNER WATER
            const wNotice = document.getElementById('waterNotice');
            if (wNotice) {
                if (isMalam) {
                    wNotice.className = 'mt-3 text-[11px] font-semibold px-3 py-2 rounded-lg border bg-indigo-50 text-indigo-700 border-indigo-200';
                    wNotice.innerHTML = '<i class="fas fa-moon mr-1"></i> <b>Shift Malam</b> — Total Konsumsi = Main Building Hari ini − Shift Malam Kemarin. Hasil otomatis masuk ke Cost & Dashboard.';
                } else {
                    wNotice.className = 'mt-3 text-[11px] font-semibold px-3 py-2 rounded-lg border bg-emerald-50 text-emerald-700 border-emerald-200';
                    wNotice.innerHTML = '<i class="fas fa-circle-check mr-1"></i> <b>Shift Pagi/Siang</b> — Main Building Reading Meter <b>bisa diisi</b> (tidak dikunci). Total Konsumsi = 0 (tidak masuk kalkulasi biaya).';
                }
            }
            calcTotals();
        }
        function calcTotals() {
            const isMalam = document.getElementById('shiftSelect').value === 'malam';

            // 1. TOTAL LISTRIK: hanya MALAM hitung selisih Today − Yesterday MALAM
            let wbpInp = document.querySelector('input[name="electricity_wbp"]');
            let lwbpInp = document.querySelector('input[name="electricity_lwbp"]');
            let tWbp  = parseFloat(wbpInp  && wbpInp.value  || 0);
            let tLwbp = parseFloat(lwbpInp && lwbpInp.value || 0);
            let tElecToday = tWbp + tLwbp;
            let totalElec = isMalam ? Math.max(0, tElecToday - (window.Y_ELEC_TOTAL || 0)) : 0;
            document.getElementById('totalElectricity').value = totalElec.toFixed(2);

            // 2. TOTAL WATER = HANYA MAIN BUILDING SAJA, hanya MALAM hitung selisih
            let wmb = document.getElementById('waterMainBuild');
            let wCons = 0;
            if (wmb) {
                let yWater = parseFloat(wmb.getAttribute('data-yesterday') || 0);
                let tWater = parseFloat(wmb.value || 0);
                wCons = isMalam ? Math.max(0, tWater - yWater) : 0;
                let lblCons = document.getElementById('waterMainCons');
                if (lblCons) lblCons.textContent = wCons.toFixed(2) + ' m3';
            }
            document.getElementById('totalWater').value = wCons.toFixed(2);

            // 3. Total Gas = sum 2 js-sum-gas (tetap sama seperti dulu, tidak dipengaruhi shift)
            let g = 0;
            document.querySelectorAll('.js-sum-gas').forEach(el => g += parseFloat(el.value || 0));
            document.getElementById('totalGas').value = g.toFixed(2);
        }
        document.addEventListener('DOMContentLoaded', calcTotals);
        </script>

        <div class="flex flex-col sm:flex-row sm:justify-end gap-3 pt-2 animate-slide-up" style="animation-delay: 200ms">
            <a href="<?= BASE_URL ?>engineer/select_date.php"
                class="px-6 py-3.5 rounded-card border border-border bg-surface text-primary font-semibold hover:bg-muted transition-all duration-300 text-center">
                <i class="fas fa-arrow-left mr-2"></i><?= T('general_cancel', 'Batal') ?>
            </a>
            <button type="submit"
                class="px-8 py-3.5 rounded-card bg-gradient-to-r from-primary via-gray-900 to-primary text-white font-semibold shadow-lg hover:shadow-2xl hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2 group">
                <i class="fas fa-save group-hover:scale-110 transition-transform"></i>
                <?= T('form_submit', 'Simpan Daily Log') ?>
            </button>
        </div>
    </form>
</div>

<script>
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('photoPreview');
            const placeholder = document.getElementById('photoPlaceholder');
            preview.src = e.target.result;
            preview.classList.remove('hidden');
            if (placeholder) placeholder.classList.add('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
