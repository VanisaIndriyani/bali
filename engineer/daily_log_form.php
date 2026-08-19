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
// water_main_building / electricity_wbp / electricity_lwbp = angka METER (READING) hari ini
// total_water / total_electricity = consumption = today_read - yesterday_malam_read
// HANYA SHIFT MALAM SAJA YANG MENGHITUNG SELISIH → masuk ke total_electricity & total_water
// (Shift Pagi/Siang hanya catatan reading, TIDAK mempengaruhi total konsumsi → total = 0)
//
// ✅ FIX LOGIC (Customer Lapor Yesterday Selalu 0!):
//  1. TIDAK FILTER engineer_id (Water & Listrik = MILIK BUILDING, bukan per orang! Ganti engineer shift MALAM bukan berarti data yesterday hilang!)
//  2. TIDAK H-1 EXACT. Cari LAST KNOWN SHIFT MALAM SEBELUM TANGGAL INI (jika H-1 tidak ada data, ambil H-2 dst sesuai data terakhir).
//  3. Label Tanggal Yesterday = SESUAI DATA LOG_DATE YANG DITEMUKAN, JANGAN hardcode H-1 (yang bikin label tanggal salah).
$yestRow = $db->fetchOne("
    SELECT log_date, water_main_building, electricity_wbp, electricity_lwbp
    FROM daily_logs
    WHERE log_date < ?
      AND COALESCE(shift, 'malam') = 'malam'
      AND COALESCE(water_main_building,0) + COALESCE(electricity_wbp,0) + COALESCE(electricity_lwbp,0) > 0
    ORDER BY log_date DESC, id DESC
    LIMIT 1
", [$date]);
// Fallback 1: jika TIDAK ADA record shift='malam' sama sekali, cari record TERAKHIR SEBELUM tanggal ini (apapun shift-nya) yang punya reading meter non-zero.
if (!$yestRow || empty($yestRow)) {
    $yestRow = $db->fetchOne("
        SELECT log_date, water_main_building, electricity_wbp, electricity_lwbp
        FROM daily_logs
        WHERE log_date < ?
          AND (COALESCE(water_main_building,0) > 0 OR COALESCE(electricity_wbp,0) > 0 OR COALESCE(electricity_lwbp,0) > 0)
        ORDER BY log_date DESC, id DESC
        LIMIT 1
    ", [$date]);
}
// Variabel hasil fetch yesterday + label tanggal (sesuai data yang ketemu, TIDAK hardcode H-1!)
if ($yestRow && !empty($yestRow) && !empty($yestRow['log_date'])) {
    $yesterdayFoundDate = (string)$yestRow['log_date'];
    $mbYesterdayRead      = (float)($yestRow['water_main_building'] ?? 0);
    $elecYesterdayWbp     = (float)($yestRow['electricity_wbp'] ?? 0);
    $elecYesterdayLwbp    = (float)($yestRow['electricity_lwbp'] ?? 0);
} else {
    $yesterdayFoundDate = null;
    $mbYesterdayRead      = 0.0;
    $elecYesterdayWbp     = 0.0;
    $elecYesterdayLwbp    = 0.0;
}
$elecYesterdayTotal = $elecYesterdayWbp + $elecYesterdayLwbp;

// Today Read = dari log tanggal INI (jika sudah ada / mode edit)
$mbTodayRead = $log && isset($log['water_main_building']) ? (float)$log['water_main_building'] : 0.0;
$elecTodayWbp   = $log && isset($log['electricity_wbp'])   ? (float)$log['electricity_wbp']   : 0.0;
$elecTodayLwbp  = $log && isset($log['electricity_lwbp'])  ? (float)$log['electricity_lwbp']  : 0.0;
$elecTodayTotal = $elecTodayWbp + $elecTodayLwbp;

// Konsumsi hari ini (hanya berlaku jika SHIFT = MALAM. Pagi/Siang = 0)
// ✅ Rumus Konsumsi Air = (MB Hari Ini − MB Kemarin) × 10 (dikalikan 10 sesuai request user)
// ✅ Rumus Konsumsi Listrik = (LWBP Hari Ini − LWBP Kemarin) × 8000 + (WBP Hari Ini − WBP Kemarin) × 8000 (Rumus dari WA customer: faktor kali digit meter × 8000 jadi kwh, BUKAN tarif!)
$curLogShift = (!empty($log['shift']) && in_array($log['shift'], $allShifts, true)) ? (string)$log['shift'] : '';
$existingIsMalam = ($curLogShift === 'malam');
// Air
$mbConsumption = $existingIsMalam ? (max(0.0, $mbTodayRead - $mbYesterdayRead) * 10) : (float)($log['total_water'] ?? 0);
// Listrik — sesuai rumus WA customer × 8000 FAKTOR KALI METER (bukan tarif)
$eLwbpConsNow = $existingIsMalam ? (max(0.0, $elecTodayLwbp - $elecYesterdayLwbp) * 8000) : 0.0;
$eWbpConsNow  = $existingIsMalam ? (max(0.0, $elecTodayWbp  - $elecYesterdayWbp)  * 8000) : 0.0;
$elecConsumptionNow = $existingIsMalam ? ($eLwbpConsNow + $eWbpConsNow) : (float)($log['total_electricity'] ?? 0);
unset($eLwbpConsNow, $eWbpConsNow, $existingIsMalam, $curLogShift, $yestRow);

// ========== ALIAS VARIABEL PHP → JS (untuk realtime calcTotals client-side) ==========
// (Harus sama nama var seperti di <script> window.Y_ELEC_WBP dkk agar tidak undefined notice!)
$yElecWbpJs   = (float)$elecYesterdayWbp;
$yElecLwbpJs  = (float)$elecYesterdayLwbp;
$yElecTotalJs = (float)$elecYesterdayTotal;
$yWaterMbJs   = (float)$mbYesterdayRead;

// ========== LABEL TANGGAL YESTERDAY UNTUK UI (sesuai data yang DITEMUKAN dari query last known shift malam) ==========
if ($yesterdayFoundDate) {
    $yDateLabelFmt = date('d/m/Y', strtotime($yesterdayFoundDate));
} else {
    $yDateLabelFmt = T('form_yesterday_never', 'Belum ada data');
}

// --- POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ① SHIFT (info catatan tambahan, TIDAK multiple entries) — cuma disimpan sebagai label di log
    $shift = trim((string)($_POST['shift'] ?? ''));
    if (!in_array($shift, $allShifts, true)) $shift = $defaultShiftNow; // aman fallback (tidak perlu error flash — cuma label)

    // ① Electricity Subdetails — SEMUA SHIFT BOLEH EDIT (Pagi/Siang/Malam)
    // Nilai WBP / LWBP = READING METER (sama seperti Main Building Water)
    // HANYA SHIFT MALAM SAJA YANG MENGHITUNG TOTAL KONSUMSI — SESUAI RUMUS WA CUSTOMER:
    //      LWBP = (Today Read − Yesterday Read) × 8000 → satuan kWh
    //      WBP  = (Today Read − Yesterday Read) × 8000 → satuan kWh
    //      TOTAL LISTRIK = LWBP + WBP
    // Shift Pagi/Siang: total_electricity = 0 (catatan reading saja, tidak masuk kalkulasi cost)
    $eWbp = (float)($_POST['electricity_wbp'] ?? 0);
    $eLwbp = (float)($_POST['electricity_lwbp'] ?? 0);
    $eTodayTotal = $eWbp + $eLwbp;
    $isShiftMalam = ($shift === 'malam');
    if ($isShiftMalam) {
        $_eLWBP = max(0.0, $eLwbp - $elecYesterdayLwbp) * 8000;  // faktor kali meter LWBP × 8000
        $_eWBP  = max(0.0, $eWbp  - $elecYesterdayWbp)  * 8000;  // faktor kali meter WBP × 8000
        $electricity = $_eLWBP + $_eWBP;
        unset($_eLWBP, $_eWBP);
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
    // ✅ Rumus Konsumsi Air = (MB Hari Ini − MB Kemarin) × 10 (dikalikan 10 sesuai request user)
    $wMainBldgRead = (float)($_POST['water_main_building'] ?? 0);
    if ($isShiftMalam) {
        $wMainBldgConsRaw = max(0.0, $wMainBldgRead - $mbYesterdayRead);
        $wMainBldgCons = $wMainBldgConsRaw * 10;
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
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-bed text-[11px]"></i></span>
                    Occupancy Rate
                </h3>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Occupancy Hari Ini (%)</label>
                        <div class="relative">
                            <input type="number" id="occRate" step="0.01" min="0" max="100" name="occ_rate"
                                value="<?= $log['occ_rate'] ?? '0.00' ?>"
                                oninput="occVisual(this.value)"
                                class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-500">%</span>
                        </div>
                        <div class="mt-2 flex gap-1.5 flex-wrap">
                            <button type="button" onclick="setOcc(50)" class="px-2 py-1 rounded-md text-[10px] font-semibold border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100 transition">50%</button>
                            <button type="button" onclick="setOcc(65)" class="px-2 py-1 rounded-md text-[10px] font-semibold border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100 transition">65%</button>
                            <button type="button" onclick="setOcc(70)" class="px-2 py-1 rounded-md text-[10px] font-semibold border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100 transition">70%</button>
                            <button type="button" onclick="setOcc(80)" class="px-2 py-1 rounded-md text-[10px] font-semibold border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100 transition">80%</button>
                            <button type="button" onclick="setOcc(90)" class="px-2 py-1 rounded-md text-[10px] font-semibold border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100 transition">90%</button>
                            <button type="button" onclick="setOcc(100)" class="px-2 py-1 rounded-md text-[10px] font-semibold border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100 transition">100%</button>
                        </div>
                    </div>
                    <div>
                        <div class="relative h-8 rounded-lg overflow-hidden bg-slate-100 border border-slate-200">
                            <div id="occBar" class="absolute inset-y-0 left-0 h-full rounded-lg transition-all duration-500 ease-out bg-slate-700" style="width: 0%"></div>
                            <div class="absolute inset-0 flex items-center justify-center text-[11px] font-semibold text-slate-700 bg-white/40 backdrop-blur-[1px]"><span id="occLabelText">0%</span> • <span id="occLabelTextDesc"><?= T('form_occ_empty', 'Kosong') ?></span></div>
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
                                    else if (val < 40) desc.textContent = '<?= T('form_occ_low', 'Low') ?>';
                                    else if (val < 65) desc.textContent = '<?= T('form_occ_mid', 'Normal') ?>';
                                    else if (val < 85) desc.textContent = '<?= T('form_occ_high', 'High') ?>';
                                    else desc.textContent = '<?= T('form_occ_full', 'Peak') ?>';
                                }
                            }
                            function setOcc(p) { const el = document.getElementById('occRate'); if (el) { el.value = p; occVisual(p); } }
                            document.addEventListener('DOMContentLoaded', function() { const el = document.getElementById('occRate'); if (el) occVisual(el.value); });
                        </script>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑧ BIS. KEY PERFORMANCE INDICATORS - ITR / M&U / GITB RANK -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-chart-line text-[11px]"></i></span>
                    KPI Harian
                </h3>
            </div>
            <div class="p-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <?php
                $kDefItr = isset($log['itr_score']) && $log['itr_score'] !== null ? number_format((float)$log['itr_score'], 2, '.', '') : '';
                $kDefMu  = isset($log['mu_score'])  && $log['mu_score']  !== null ? number_format((float)$log['mu_score'],  2, '.', '') : '';
                $kDefGitb = isset($log['gitb_rank']) && $log['gitb_rank'] !== null ? (int)$log['gitb_rank'] : '';
                ?>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">ITR Score <span class="text-slate-400 font-normal">(Index Tata Ruang)</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" max="999.99" name="itr_score"
                            value="<?= $kDefItr ?>" placeholder="87.00"
                            class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-500">pt</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">M&U Score <span class="text-slate-400 font-normal">(Maint & Utility)</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" max="999.99" name="mu_score"
                            value="<?= $kDefMu ?>" placeholder="81.00"
                            class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-500">pt</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">GITB Rank <span class="text-slate-400 font-normal">(Guest in the Book)</span></label>
                    <div class="relative">
                        <input type="number" step="1" min="1" max="99" name="gitb_rank"
                            value="<?= $kDefGitb ?>" placeholder="4"
                            class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-500">#</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑧ TRIS. TARIF BERLAKU HARI INI -->
        <?php
        $roleCanEditTariff = in_array(($user['role'] ?? ''), ['supervisor','manager','admin'], true);
        $_defTarNow = getTariffSettings();
        $tDef = [
            'electricity' => ( !empty($log['tariff_electricity_per_kwh']) && (int)$log['tariff_electricity_per_kwh'] > 0 ) ? (int)$log['tariff_electricity_per_kwh'] : (int)($_defTarNow['electricity_per_kwh'] ?? 1850),
            'water'       => ( !empty($log['tariff_water_per_m3']) && (int)$log['tariff_water_per_m3'] > 0 ) ? (int)$log['tariff_water_per_m3'] : (int)($_defTarNow['water_per_m3'] ?? 9600),
            'gas'         => ( !empty($log['tariff_gas_per_kg']) && (int)$log['tariff_gas_per_kg'] > 0 ) ? (int)$log['tariff_gas_per_kg'] : (int)($_defTarNow['gas_per_kg'] ?? 24500),
            'fuel'        => ( !empty($log['tariff_fuel_per_liter']) && (int)$log['tariff_fuel_per_liter'] > 0 ) ? (int)$log['tariff_fuel_per_liter'] : (int)($_defTarNow['fuel_per_liter'] ?? 17450),
        ];
        $tariffReadonly = $roleCanEditTariff ? '' : 'readonly tabindex="-1"';
        $tariffCursorCls = $roleCanEditTariff ? '' : 'cursor-not-allowed opacity-90';
        ?>
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-receipt text-[11px]"></i></span>
                    Tarif Hari Ini
                </h3>
                <?php if (!$roleCanEditTariff): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-100 text-[9.5px] font-semibold text-slate-600 border border-slate-200"><i class="fas fa-lock text-[8px]"></i> Locked</span>
                <?php else: ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-50 text-[9.5px] font-semibold text-slate-600 border border-slate-200"><i class="fas fa-unlock text-[8px]"></i> Editable</span>
                <?php endif; ?>
            </div>
            <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
                <div>
                    <label class="block text-[10px] font-semibold text-slate-600 mb-1.5">Listrik PLN</label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999" name="electricity_per_kwh" value="<?= $tDef['electricity'] ?>" <?= $tariffReadonly ?>
                               class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 focus:bg-white transition-all <?= $tariffCursorCls ?>">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">Rp/kWh</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-semibold text-slate-600 mb-1.5">Air PDAM</label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999" name="water_per_m3" value="<?= $tDef['water'] ?>" <?= $tariffReadonly ?>
                               class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 focus:bg-white transition-all <?= $tariffCursorCls ?>">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">Rp/m3</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-semibold text-slate-600 mb-1.5">Gas LPG</label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999" name="gas_per_kg" value="<?= $tDef['gas'] ?>" <?= $tariffReadonly ?>
                               class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 focus:bg-white transition-all <?= $tariffCursorCls ?>">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">Rp/kg</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-semibold text-slate-600 mb-1.5">Solar BBM</label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999" name="fuel_per_liter" value="<?= $tDef['fuel'] ?>" <?= $tariffReadonly ?>
                               class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 focus:bg-white transition-all <?= $tariffCursorCls ?>">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">Rp/Ltr</span>
                    </div>
                </div>
            </div>
        </div>
        <?php
        unset($_defTarNow, $tDef, $tariffReadonly, $tariffCursorCls, $roleCanEditTariff);
        ?>

        <!-- ① LISTRIK WBP + LWBP (Dashed Boxes — Netral) -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-bolt text-[11px]"></i></span>
                    Konsumsi Listrik
                </h3>
                <div id="elecNotice" class="mt-2 text-[10.5px] px-2.5 py-1.5 rounded-md border <?= $isMalamNow ? 'bg-slate-50 text-slate-700 border-slate-200' : 'bg-white text-slate-600 border-slate-200' ?>">
                    <?php if ($isMalamNow): ?>
                        <i class="fas fa-moon mr-1 text-slate-500"></i><b>Malam</b> — LWBP/WBP = (Today−Kemarin) × 8.000. Total masuk Cost.
                    <?php else: ?>
                        <i class="fas fa-circle-check mr-1 text-slate-500"></i><b>Pagi/Siang</b> — Bisa isi, Total = 0.
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-4 space-y-3">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php
                    $_isLogMalamE = ($log && isset($log['shift']) && $log['shift'] === 'malam');
                    $_eWbpYFmt = number_format($elecYesterdayWbp, 2, '.', '');
                    $_eWbpTVal = $log['electricity_wbp'] ?? '0.00';
                    $_eWbpCons = $_isLogMalamE ? number_format(max(0.0, (float)$_eWbpTVal - $elecYesterdayWbp) * 8000, 2, '.', '') : '0.00';
                    echo <<<HTML
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-[11px] font-semibold text-slate-700">KWH WBP <span class="text-red-500">*</span></label>
                            <span class="text-[9px] font-semibold px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 border border-slate-200">Reading</span>
                        </div>
                        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50/50 p-2 space-y-1.5">
                            <div class="flex items-center justify-between text-[10px] font-semibold">
                                <span class="text-slate-500">Yesterday ({$yDateLabelFmt})</span>
                                <span class="text-slate-700">{$_eWbpYFmt} kWh</span>
                            </div>
                            <div>
                                <p class="text-[9.5px] font-semibold text-slate-600 mb-0.5">Today</p>
                                <div class="relative">
                                    <input type="number" step="0.01" min="0" name="electricity_wbp" {$elecRequired} {$elecReadonly} oninput="calcTotals()"
                                        value="{$_eWbpTVal}"
                                        class="w-full px-2.5 py-2 rounded-md border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all {$elecDisabledCls}">
                                    <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">kWh</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between text-[10px] font-semibold pt-1 border-t border-slate-200/60">
                                <span class="text-slate-600">Selisih × 8000</span>
                                <span class="text-slate-800" id="elecWbpCons">{$_eWbpCons} kWh</span>
                            </div>
                        </div>
                    </div>
HTML;
                    $_eLwbpYFmt = number_format($elecYesterdayLwbp, 2, '.', '');
                    $_eLwbpTVal = $log['electricity_lwbp'] ?? '0.00';
                    $_eLwbpCons = $_isLogMalamE ? number_format(max(0.0, (float)$_eLwbpTVal - $elecYesterdayLwbp) * 8000, 2, '.', '') : '0.00';
                    echo <<<HTML
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-[11px] font-semibold text-slate-700">KWH LWBP <span class="text-red-500">*</span></label>
                            <span class="text-[9px] font-semibold px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 border border-slate-200">Reading</span>
                        </div>
                        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50/50 p-2 space-y-1.5">
                            <div class="flex items-center justify-between text-[10px] font-semibold">
                                <span class="text-slate-500">Yesterday ({$yDateLabelFmt})</span>
                                <span class="text-slate-700">{$_eLwbpYFmt} kWh</span>
                            </div>
                            <div>
                                <p class="text-[9.5px] font-semibold text-slate-600 mb-0.5">Today</p>
                                <div class="relative">
                                    <input type="number" step="0.01" min="0" name="electricity_lwbp" {$elecRequired} {$elecReadonly} oninput="calcTotals()"
                                        value="{$_eLwbpTVal}"
                                        class="w-full px-2.5 py-2 rounded-md border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all {$elecDisabledCls}">
                                    <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">kWh</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between text-[10px] font-semibold pt-1 border-t border-slate-200/60">
                                <span class="text-slate-600">Selisih × 8000</span>
                                <span class="text-slate-800" id="elecLwbpCons">{$_eLwbpCons} kWh</span>
                            </div>
                        </div>
                    </div>
HTML;
                    unset($_isLogMalamE, $_eWbpYFmt, $_eWbpTVal, $_eWbpCons, $_eLwbpYFmt, $_eLwbpTVal, $_eLwbpCons);
                    ?>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">TOTAL LISTRIK</label>
                    <div class="relative">
                        <input type="number" id="totalElectricity" readonly step="0.01" min="0" name="total_electricity_show"
                            value="<?= $elecConsumptionNow > 0 ? number_format($elecConsumptionNow, 2, '.', '') : ($log['total_electricity'] ?? '0.00') ?>"
                            class="w-full px-3 py-2.5 rounded-lg border-2 border-slate-700 bg-slate-800 text-sm font-bold text-white shadow-sm cursor-not-allowed opacity-95">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-white/85">kWh</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ② WATER 3 SUMBER (PDAM + MAIN BUILDING DASHED + COOLING TOWER) -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-droplet text-[11px]"></i></span>
                    Konsumsi Air
                </h3>
                <div id="waterNotice" class="mt-2 text-[10.5px] px-2.5 py-1.5 rounded-md border <?= $isMalamNow ? 'bg-slate-50 text-slate-700 border-slate-200' : 'bg-white text-slate-600 border-slate-200' ?>">
                    <?php if ($isMalamNow): ?>
                        <i class="fas fa-moon mr-1 text-slate-500"></i><b>Malam</b> — Main Bld = (Today−Kemarin) × 10. Total masuk Cost.
                    <?php else: ?>
                        <i class="fas fa-circle-check mr-1 text-slate-500"></i><b>Pagi/Siang</b> — Bisa isi, Total = 0.
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                <?php
                // 1. PDAM
                [$field, $label] = ['water_pdam', T('form_water_pdam', 'PDAM')];
                $val = $log[$field] ?? '0.00';
                echo <<<HTML
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">{$label}</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="{$field}" oninput="calcTotals()" value="{$val}"
                            class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">m3</span>
                    </div>
                </div>
HTML;

                // 2. MAIN BUILDING — DASHED READING METER (NETRAL)
                [$field, $label] = ['water_main_building', T('form_water_main', 'Main Building')];
                $val = $log[$field] ?? '0.00';
                $mbYesterdayFmt = number_format($mbYesterdayRead, 2, '.', '');
                $mbConsFmt = number_format($mbConsumption, 2, '.', '');
                echo <<<HTML
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-[11px] font-semibold text-slate-700">{$label}</label>
                        <span class="text-[9px] font-semibold px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 border border-slate-200">Reading</span>
                    </div>
                    <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50/50 p-2 space-y-1.5">
                        <div class="flex items-center justify-between text-[10px] font-semibold">
                            <span class="text-slate-500">Yesterday ({$yDateLabelFmt})</span>
                            <span class="text-slate-700">{$mbYesterdayFmt} m3</span>
                        </div>
                        <div>
                            <p class="text-[9.5px] font-semibold text-slate-600 mb-0.5">Today</p>
                            <div class="relative">
                                <input type="number" step="0.01" min="0" name="{$field}" id="waterMainBuild" oninput="calcTotals()" value="{$val}" data-yesterday="{$mbYesterdayFmt}"
                                    class="w-full px-2.5 py-2 rounded-md border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">m3</span>
                            </div>
                        </div>
                        <div class="flex items-center justify-between text-[10px] font-semibold pt-1 border-t border-slate-200/60">
                            <span class="text-slate-600">Selisih × 10</span>
                            <span class="text-slate-800" id="waterMainCons">{$mbConsFmt} m3</span>
                        </div>
                    </div>
                </div>
HTML;

                // 3. COOLING TOWER
                [$field, $label] = ['water_cooling_tower', T('form_water_ct', 'Cooling Tower')];
                $val = $log[$field] ?? '0.00';
                echo <<<HTML
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">{$label}</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="{$field}" oninput="calcTotals()" value="{$val}"
                            class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">m3</span>
                    </div>
                </div>
HTML;
                ?>
                <!-- TOTAL WATER -->
                <div class="sm:col-span-2 md:col-span-3 md:max-w-xs">
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">TOTAL AIR (Main Building)</label>
                    <div class="relative">
                        <input type="number" id="totalWater" readonly step="0.01" min="0" value="<?= number_format($mbConsumption, 2, '.', '') ?>"
                            class="w-full px-3 py-2.5 rounded-lg border-2 border-slate-700 bg-slate-800 text-sm font-bold text-white cursor-not-allowed opacity-95">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-white/85">m3</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ③ GAS LPG + LNG -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-fire text-[11px]"></i></span>
                    Konsumsi Gas
                </h3>
            </div>
            <div class="p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">LPG <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="gas_lpg" required oninput="calcTotals()" value="<?= $log['gas_lpg'] ?? '0.00' ?>"
                            class="js-sum-gas w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">kg</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">LNG <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="gas_lng" required oninput="calcTotals()" value="<?= $log['gas_lng'] ?? '0.00' ?>"
                            class="js-sum-gas w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">kg</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">TOTAL GAS</label>
                    <div class="relative">
                        <input type="number" id="totalGas" readonly step="0.01" min="0" value="<?= $log['total_gas'] ?? '0.00' ?>"
                            class="w-full px-3 py-2.5 rounded-lg border-2 border-slate-700 bg-slate-800 text-sm font-bold text-white cursor-not-allowed opacity-95">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-white/85">kg</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ④ SWRO 3 Input -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-water text-[11px]"></i></span>
                    SWRO
                </h3>
            </div>
            <div class="p-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Watermeter</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="swro_watermeter" value="<?= $log['swro_watermeter'] ?? '0.00' ?>"
                            class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">m3</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Electric</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="swro_kwh" value="<?= $log['swro_kwh'] ?? '0.00' ?>"
                            class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">kWh</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">TDS</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="swro_tds" value="<?= $log['swro_tds'] ?? '0.00' ?>"
                            class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">ppm</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑤ BOTTLING WATER 2 -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-bottle-water text-[11px]"></i></span>
                    Bottling Water
                </h3>
            </div>
            <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Electric</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="bottling_kwh" value="<?= $log['bottling_kwh'] ?? '0.00' ?>"
                            class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">kWh</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Watermeter</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="bottling_watermeter" value="<?= $log['bottling_watermeter'] ?? '0.00' ?>"
                            class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">m3</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑥ CHILLER SYSTEM -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-snowflake text-xs"></i></span>
                    Chiller System
                </h3>
            </div>
            <div class="p-4 space-y-4">
                <!-- 3 Unit ON/OFF -->
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">Status Unit</p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <?php
                        $chillers = [
                            ['chiller_1_on', 'Chiller 1'],
                            ['chiller_2_on', 'Chiller 2'],
                            ['chiller_3_on', 'Chiller 3'],
                        ];
                        foreach ($chillers as $ch) {
                            [$field, $label] = $ch;
                            $checked = !empty($log[$field]) ? 'checked' : '';
                            echo <<<HTML
                        <label class="group cursor-pointer select-none p-3 rounded-lg border border-dashed border-slate-300 hover:border-slate-500 bg-slate-50/50 hover:bg-slate-50 transition-all">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 shrink-0 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600 group-hover:scale-105 transition-transform">
                                    <i class="fas fa-temperature-half text-sm"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-slate-800 text-sm leading-tight">{$label}</p>
                                    <p class="text-[10px] text-slate-500 font-medium mt-0.5 uppercase tracking-wide">Aktif</p>
                                </div>
                                <div class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="{$field}" value="1" class="sr-only peer" {$checked}>
                                    <div class="w-10 h-5.5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-slate-300 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border after:rounded-full after:h-4.5 after:w-4.5 after:transition-all peer-checked:bg-slate-800 after:shadow-sm"></div>
                                </div>
                            </div>
                        </label>
HTML;
                        }
                        ?>
                    </div>
                </div>
                <!-- 5 Numeric fields -->
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">pH</label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" max="14" name="chiller_water_ph"
                                value="<?= $log['chiller_water_ph'] ?? '0.00' ?>"
                                class="w-full pl-3 pr-9 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:bg-white transition-all text-sm">
                            <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded-full">pH</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">TDS</label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="chiller_water_tds"
                                value="<?= $log['chiller_water_tds'] ?? '0.00' ?>"
                                class="w-full pl-3 pr-9 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200 transition-all text-sm">
                            <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded-full">ppm</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Suhu</label>
                        <div class="relative">
                            <input type="number" step="0.01" name="chiller_temp"
                                value="<?= $log['chiller_temp'] ?? '0.00' ?>"
                                class="w-full pl-3 pr-9 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200 transition-all text-sm">
                            <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded-full">°C</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">CHWP</label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="chiller_pressure_chwp"
                                value="<?= $log['chiller_pressure_chwp'] ?? '0.00' ?>"
                                class="w-full pl-3 pr-9 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200 transition-all text-sm">
                            <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded-full">bar</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">CWP</label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="chiller_pressure_cwp"
                                value="<?= $log['chiller_pressure_cwp'] ?? '0.00' ?>"
                                class="w-full pl-3 pr-9 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200 transition-all text-sm">
                            <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded-full">bar</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑦ FUEL -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-gas-pump text-xs"></i></span>
                    Konsumsi Fuel
                </h3>
            </div>
            <div class="p-4">
                <div class="max-w-xs">
                    <label class="block text-[11px] font-semibold text-slate-700 mb-2">Total (Liter)</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="total_fuel"
                            value="<?= $log['total_fuel'] ?? '0.00' ?>"
                            class="w-full pl-3.5 pr-12 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200 transition-all text-sm">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[11px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full">L</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- EQUIPMENT LOG 1/8 TRAFO -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-bolt text-xs"></i></span>
                    Trafo — 2 Unit
                </h3>
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php for ($tu=1; $tu<=2; $tu++):
                    $tv = $eq['trafo']['units'][$tu];
                ?>
                <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50/50 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-3">Trafo Unit <?= $tu ?></p>
                    <div class="grid grid-cols-3 gap-2.5">
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-600 mb-1">Temp (°C)</label>
                            <input type="number" step="0.01" name="trafo_<?= $tu ?>_temp" value="<?= $tv['temp_c'] ?>" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white font-semibold text-sm focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-600 mb-1">Ampere (A)</label>
                            <input type="number" step="0.01" name="trafo_<?= $tu ?>_ampere" value="<?= $tv['ampere_lvdp'] ?>" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white font-semibold text-sm focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-600 mb-1">Oil (%)</label>
                            <input type="number" step="0.01" name="trafo_<?= $tu ?>_oil" value="<?= $tv['oil_level_pct'] ?>" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white font-semibold text-sm focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- EQUIPMENT LOG 2/8 GENSET -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-industry text-xs"></i></span>
                    Genset — 3 Unit + Fuel
                </h3>
            </div>
            <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php for ($gu=1; $gu<=3; $gu++): ?>
                <div>
                    <label class="block text-[10px] font-semibold text-slate-600 mb-1">Gen <?= $gu ?> (V)</label>
                    <input type="number" step="0.01" name="genset_<?= $gu ?>_volt" value="<?= $eq['genset']['gen_'.$gu.'_volt'] ?>" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white font-semibold text-sm focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                </div>
                <?php endfor; ?>
                <div>
                    <label class="block text-[10px] font-semibold text-slate-600 mb-1">Tank (L)</label>
                    <input type="number" step="0.01" name="genset_fuel_tank" value="<?= $eq['genset']['fuel_tank_liter'] ?>" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white font-semibold text-sm focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                </div>
            </div>
        </div>

        <!-- EQUIPMENT LOG 3/8 PUMP ROOM -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-water text-xs"></i></span>
                    Pump Room
                </h3>
            </div>
            <div class="p-4 space-y-3.5">
                <!-- Steam Boiler -->
                <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">Steam Boiler (SB)</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-2.5">
                        <div>
                            <label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Unit Op</label>
                            <select name="pump_sb_unit_op" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500">
                                <?php foreach(['off','standby','on','auto'] as $o): ?><option value="<?= $o ?>" <?= $eq['pump']['sb_unit_op']===$o?'selected':'' ?>><?= strtoupper($o) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">SB-1 Jam</label><input type="text" name="pump_sb1_hours" value="<?= cleanInput($eq['pump']['sb1_hours']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">SB-2 Jam</label><input type="text" name="pump_sb2_hours" value="<?= cleanInput($eq['pump']['sb2_hours']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Test TDS/pH</label><input type="text" name="pump_sb_test" value="<?= cleanInput($eq['pump']['sb_test']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Steam Press</label><input type="text" name="pump_sb_press" value="<?= cleanInput($eq['pump']['sb_press']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Blow Down</label><input type="text" name="pump_sb_blow" value="<?= cleanInput($eq['pump']['sb_blow']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Econ Temp</label><input type="text" name="pump_sb_econ_temp" value="<?= cleanInput($eq['pump']['sb_econ_temp']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Econ Press</label><input type="text" name="pump_sb_econ_press" value="<?= cleanInput($eq['pump']['sb_econ_press']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                    </div>
                </div>
                <!-- Hot Water Boiler -->
                <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">Hot Water Boiler (HWB)</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-2.5">
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Unit Op</label><select name="pump_hwb_unit_op" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"><?php foreach(['off','standby','on','auto'] as $o): ?><option value="<?= $o ?>" <?= $eq['pump']['hwb_unit_op']===$o?'selected':'' ?>><?= strtoupper($o) ?></option><?php endforeach; ?></select></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">HWB-1 Jam</label><input type="text" name="pump_hwb1_hours" value="<?= cleanInput($eq['pump']['hwb1_hours']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">HWB-2 Jam</label><input type="text" name="pump_hwb2_hours" value="<?= cleanInput($eq['pump']['hwb2_hours']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">HW Temp (°C)</label><input type="text" name="pump_hwb_temp" value="<?= cleanInput($eq['pump']['hwb_temp']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Test TDS/pH</label><input type="text" name="pump_hwb_test" value="<?= cleanInput($eq['pump']['hwb_test']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Circ Pump</label><input type="text" name="pump_hwb_circ_op" value="<?= cleanInput($eq['pump']['hwb_circ_op']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Flow Press</label><input type="text" name="pump_hwb_flow" value="<?= cleanInput($eq['pump']['hwb_flow']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Return Press</label><input type="text" name="pump_hwb_ret" value="<?= cleanInput($eq['pump']['hwb_ret']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                    </div>
                </div>
                <!-- Ground Tank + Hydrant + Jockey + Sand Filter -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">Ground Tank</p>
                        <div class="space-y-2">
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Raw Tank</label><input type="text" name="pump_tank_raw" value="<?= cleanInput($eq['pump']['tank_raw']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Treated Tank</label><input type="text" name="pump_tank_treated" value="<?= cleanInput($eq['pump']['tank_treated']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Irigasi Tank</label><input type="text" name="pump_tank_irigasi" value="<?= cleanInput($eq['pump']['tank_irigasi']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">Hydrant Pump</p>
                        <div class="space-y-2">
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Standby/Auto</label><select name="pump_hyd_standby" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"><?php foreach(['standby','auto','off','on'] as $o): ?><option value="<?= $o ?>" <?= $eq['pump']['hyd_standby']===$o?'selected':'' ?>><?= strtoupper($o) ?></option><?php endforeach; ?></select></div>
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Press Pump-1</label><input type="text" name="pump_hyd_press1" value="<?= cleanInput($eq['pump']['hyd_press1']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Press Pump-2</label><input type="text" name="pump_hyd_press2" value="<?= cleanInput($eq['pump']['hyd_press2']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">Jockey Pump</p>
                        <div class="space-y-2">
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Standby Press</label><input type="text" name="pump_jockey_press" value="<?= cleanInput($eq['pump']['jockey_press']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        </div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5 mt-3">Sand Filter</p>
                        <div class="space-y-2">
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Status</label><select name="pump_sf_status" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"><?php foreach(['off','on','backwash','rinse'] as $o): ?><option value="<?= $o ?>" <?= $eq['pump']['sf_status']===$o?'selected':'' ?>><?= strtoupper($o) ?></option><?php endforeach; ?></select></div>
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">SF + Booster</p>
                        <div class="space-y-2">
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">SF Press Sand</label><input type="text" name="pump_sf_press_sand" value="<?= cleanInput($eq['pump']['sf_press_sand']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">SF Press Carbon</label><input type="text" name="pump_sf_press_carbon" value="<?= cleanInput($eq['pump']['sf_press_carbon']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">SF Pump Status</label><select name="pump_sfp_status" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"><?php foreach(['off','on'] as $o): ?><option value="<?= $o ?>" <?= $eq['pump']['sfp_status']===$o?'selected':'' ?>><?= strtoupper($o) ?></option><?php endforeach; ?></select></div>
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">SF Pump Op</label><input type="text" name="pump_sfp_unit_op" value="<?= cleanInput($eq['pump']['sfp_unit_op']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        </div>
                    </div>
                </div>
                <!-- Booster Villa + MH + Irigasi -->
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">Booster Villa</p>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Unit Op</label><input type="text" name="pump_bpv_unit_op" value="<?= cleanInput($eq['pump']['bpv_unit_op']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Press</label><input type="text" name="pump_bpv_press" value="<?= cleanInput($eq['pump']['bpv_press']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">Booster MH</p>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Unit Op</label><input type="text" name="pump_bpm_unit_op" value="<?= cleanInput($eq['pump']['bpm_unit_op']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Press</label><input type="text" name="pump_bpm_press" value="<?= cleanInput($eq['pump']['bpm_press']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">Irrigation Pump</p>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Unit Op</label><input type="text" name="pump_irigasi_unit_op" value="<?= cleanInput($eq['pump']['irigasi_unit_op']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                            <div><label class="block text-[9.5px] font-semibold text-slate-600 mb-1">Press</label><input type="text" name="pump_irigasi_press" value="<?= cleanInput($eq['pump']['irigasi_press']) ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- EQUIPMENT LOG 4/8 CHILLER SYSTEM EQUIP -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-temperature-low text-xs"></i></span>
                    Chiller System Equip
                </h3>
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3.5">
                <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">Chiller Unit</p>
                    <div class="space-y-2">
                        <div><label class="block text-[10px] font-semibold text-slate-600 mb-1">Unit Op</label><select name="chiller_unit_op" class="w-full px-2 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"><?php foreach(['carrier','unit1','unit2','unit3','off'] as $o): ?><option value="<?= $o ?>" <?= $eq['chiller']['unit_op']===$o?'selected':'' ?>><?= strtoupper($o) ?></option><?php endforeach; ?></select></div>
                        <div><label class="block text-[10px] font-semibold text-slate-600 mb-1">Chilled Test</label><input type="text" name="chiller_cw_test" value="<?= cleanInput($eq['chiller']['cw_test']) ?>" class="w-full px-2 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"></div>
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">CWP Pump</p>
                    <div class="space-y-2">
                        <div><label class="block text-[10px] font-semibold text-slate-600 mb-1">Unit Op</label><input type="text" name="chiller_cwp_unit_op" value="<?= cleanInput($eq['chiller']['cwp_unit_op']) ?>" class="w-full px-2 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"></div>
                        <div><label class="block text-[10px] font-semibold text-slate-600 mb-1">Press (kg/cm2)</label><input type="text" name="chiller_cwp_press" value="<?= cleanInput($eq['chiller']['cwp_press']) ?>" class="w-full px-2 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"></div>
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-2.5">CHWP Pump</p>
                    <div class="space-y-2">
                        <div><label class="block text-[10px] font-semibold text-slate-600 mb-1">Unit Op</label><input type="text" name="chiller_chwp_unit_op" value="<?= cleanInput($eq['chiller']['chwp_unit_op']) ?>" class="w-full px-2 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"></div>
                        <div><label class="block text-[10px] font-semibold text-slate-600 mb-1">Press In</label><input type="text" name="chiller_chwp_in" value="<?= cleanInput($eq['chiller']['chwp_in']) ?>" class="w-full px-2 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"></div>
                        <div><label class="block text-[10px] font-semibold text-slate-600 mb-1">Press Out</label><input type="text" name="chiller_chwp_out" value="<?= cleanInput($eq['chiller']['chwp_out']) ?>" class="w-full px-2 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- EQUIPMENT LOG 5/8 COOLING TOWER -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-fan text-xs"></i></span>
                    Cooling Tower
                </h3>
            </div>
            <div class="p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Unit Op</label>
                    <input type="text" name="ct_unit_op" value="<?= cleanInput($eq['ct']['unit_op']) ?>" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-sm focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Water Level (%)</label>
                    <input type="text" name="ct_level" value="<?= cleanInput($eq['ct']['level']) ?>" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-sm focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Test TDS/pH</label>
                    <input type="text" name="ct_test" value="<?= cleanInput($eq['ct']['test']) ?>" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-sm focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                </div>
            </div>
        </div>

        <!-- EQUIPMENT LOG 6/8 REVERSE OSMOSIS -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-droplet text-xs"></i></span>
                    Reverse Osmosis (RO)
                </h3>
            </div>
            <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Water Meter (m3)</label>
                    <input type="text" name="ro_meter" value="<?= cleanInput($eq['ro']['meter']) ?>" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-sm focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Permeate (m3/jam)</label>
                    <input type="text" name="ro_permeate" value="<?= cleanInput($eq['ro']['permeate']) ?>" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-sm focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">TDS/pH Permeate</label>
                    <input type="text" name="ro_test_permeate" value="<?= cleanInput($eq['ro']['test_permeate']) ?>" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-sm focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">TDS/pH Deep Well</label>
                    <input type="text" name="ro_test_deepwell" value="<?= cleanInput($eq['ro']['test_deepwell']) ?>" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white font-semibold text-sm focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
                </div>
            </div>
        </div>

        <!-- EQUIPMENT LOG 7/8 POOL SYSTEM -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-person-swimming text-xs"></i></span>
                    Pool System
                </h3>
            </div>
            <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <?php
                $poolMap = [
                    ['l1','Lagoon 1'],
                    ['l2','Lagoon 2'],
                    ['aqua','Aquavitale'],
                    ['mpr','Main Pump Room'],
                ];
                foreach ($poolMap as $pm):
                    [$pkey, $ptitle] = $pm;
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
                <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50/40 p-3.5">
                    <div class="flex items-center gap-2 mb-2.5">
                        <div class="w-7 h-7 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600 text-xs"><i class="fas fa-swimming-pool"></i></div>
                        <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-700"><?= $ptitle ?></p>
                    </div>
                    <div class="space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block text-[9.5px] font-semibold text-slate-600"><span>Alarm</span>
                                <select name="<?= $postPre ?>alarm" class="w-full mt-0.5 px-1.5 py-1.5 rounded-md border border-slate-200 bg-white text-[10px] font-semibold focus:outline-none focus:border-slate-500">
                                    <option value="on"  <?= ($eq['pool'][$alarmKey] ?? 'on')==='on'?'selected':''?>>ON</option>
                                    <option value="off" <?= ($eq['pool'][$alarmKey] ?? 'on')==='off'?'selected':''?>>OFF</option>
                                </select>
                            </label>
                            <?php if ($hasPump): ?>
                            <label class="block text-[9.5px] font-semibold text-slate-600"><span>Pump</span>
                                <input type="text" name="<?= $postPre ?>pump" value="<?= cleanInput($eq['pool'][$pumpKey] ?? '') ?>" class="w-full mt-0.5 px-1.5 py-1.5 rounded-md border border-slate-200 bg-white text-[10px] font-semibold focus:outline-none focus:border-slate-500">
                            </label>
                            <?php endif; ?>
                        </div>
                        <?php if ($hasPress): ?>
                        <label class="block text-[9.5px] font-semibold text-slate-600"><span>Press Tank</span>
                            <input type="text" name="<?= $postPre ?>press" value="<?= cleanInput($eq['pool'][$pressKey] ?? '') ?>" class="w-full mt-0.5 px-2 py-1.5 rounded-md border border-slate-200 bg-white text-[10px] font-semibold focus:outline-none focus:border-slate-500">
                        </label>
                        <?php endif; ?>
                        <?php if ($hasHwb): ?>
                        <label class="block text-[9.5px] font-semibold text-slate-600"><span>HWB Temp</span>
                            <input type="text" name="<?= $postPre ?>hwbtemp" value="<?= cleanInput($eq['pool'][$hwbKey] ?? '') ?>" class="w-full mt-0.5 px-2 py-1.5 rounded-md border border-slate-200 bg-white text-[10px] font-semibold focus:outline-none focus:border-slate-500">
                        </label>
                        <?php endif; ?>
                        <label class="block text-[9.5px] font-semibold text-slate-600"><span>Submersible</span>
                            <select name="<?= $postPre ?>sub_auto" class="w-full mt-0.5 px-1.5 py-1.5 rounded-md border border-slate-200 bg-white text-[10px] font-semibold focus:outline-none focus:border-slate-500">
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

        <!-- EQUIPMENT LOG 8/8 GAS SYSTEM -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-fire-flame-curved text-xs"></i></span>
                    Gas System
                </h3>
            </div>
            <div class="p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                <?php
                $gasMap = [
                    ['boneka','Boneka Resto'],
                    ['mainkitchen','Main Kitchen'],
                    ['kayuputih','Kayu Putih Resto'],
                ];
                foreach ($gasMap as $gm):
                    [$gkey, $gtitle] = $gm;
                    $valveKey = $gkey.'_valve';
                    $alarmKey = $gkey.'_alarm';
                    $postPre  = 'gas_'.$gkey.'_';
                ?>
                <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50/40 p-3.5">
                    <div class="flex items-center gap-2 mb-2.5">
                        <div class="w-7 h-7 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600 text-xs"><i class="fas fa-fire-flame-curved"></i></div>
                        <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-700"><?= $gtitle ?></p>
                    </div>
                    <div class="grid grid-cols-2 gap-2.5">
                        <label class="block text-[10px] font-semibold text-slate-600"><span>Valve</span>
                            <select name="<?= $postPre ?>valve" class="w-full mt-0.5 px-2 py-1.5 rounded-md border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500">
                                <option value="open"  <?= ($eq['gas'][$valveKey] ?? 'open')==='open'?'selected':''?>>OPEN</option>
                                <option value="close" <?= ($eq['gas'][$valveKey] ?? 'open')==='close'?'selected':''?>>CLOSE</option>
                            </select>
                        </label>
                        <label class="block text-[10px] font-semibold text-slate-600"><span>Alarm</span>
                            <select name="<?= $postPre ?>alarm" class="w-full mt-0.5 px-2 py-1.5 rounded-md border border-slate-200 bg-white text-xs font-semibold focus:outline-none focus:border-slate-500">
                                <option value="on"  <?= ($eq['gas'][$alarmKey] ?? 'on')==='on'?'selected':''?>>ON</option>
                                <option value="off" <?= ($eq['gas'][$alarmKey] ?? 'on')==='off'?'selected':''?>>OFF</option>
                            </select>
                        </label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ACTIVITY COUNTERS (MANAGER ONLY) -->
        <?php if (($user['role'] ?? '') === 'manager'): ?>
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-list-ol text-xs"></i></span>
                    Activity Counter (Auto)
                </h3>
            </div>
            <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php
                $actFields = [
                    ['activity_operation', 'Operation', 'fas fa-gears', 'act_count_op'],
                    ['activity_maintenance', 'Maintenance', 'fas fa-wrench', 'act_count_maint'],
                    ['activity_project', 'Project', 'fas fa-diagram-project', 'act_count_proj'],
                    ['activity_landscape', 'Landscape', 'fas fa-leaf', 'act_count_land'],
                ];
                foreach ($actFields as $af) {
                    [$fname, $flabel, $ficon, $fjs] = $af;
                    $fval = $log[$fname] ?? '0';
                    echo <<<HTML
                <div>
                    <label class="block text-[10.5px] font-bold text-slate-600 mb-2 uppercase tracking-wide flex items-center gap-1.5"><i class="{$ficon} text-slate-500"></i>{$flabel}</label>
                    <div class="relative">
                        <input type="hidden" name="{$fname}" id="{$fname}" value="{$fval}">
                        <div id="{$fjs}" class="w-full pl-3 pr-4 py-2.5 rounded-lg border border-slate-200 bg-slate-50 text-2xl font-bold text-slate-800 text-center">{$fval}</div>
                        <span class="absolute right-2 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500 bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded-full">AUTO</span>
                    </div>
                </div>
HTML;
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- DOKUMENTASI FOTO -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-camera text-xs"></i></span>
                    Dokumentasi Foto
                </h3>
            </div>
            <div class="p-4">
                <div class="flex flex-col md:flex-row gap-4">
                    <label class="flex-1 cursor-pointer group">
                        <div class="border-2 border-dashed border-slate-300 hover:border-slate-500 rounded-xl p-6 text-center transition-all group-hover:bg-slate-50 bg-slate-50/40">
                            <i class="fas fa-cloud-arrow-up text-2xl text-slate-400 group-hover:text-slate-600 group-hover:scale-110 transition-all mb-2"></i>
                            <p class="font-medium text-slate-700 text-sm mb-0.5">Unggah Foto</p>
                            <p class="text-xs text-slate-500">JPG, PNG, GIF • Max 10MB</p>
                        </div>
                        <input type="file" name="photo" accept="image/*" class="hidden" id="photoInput" onchange="previewPhoto(this)">
                    </label>
                    <div class="w-full md:w-60 h-52 rounded-xl border border-slate-200 bg-slate-50 overflow-hidden flex items-center justify-center">
                        <?php if ($log && $log['photo_path']): ?>
                            <img id="photoPreview" src="<?= UPLOAD_URL . $log['photo_path'] ?>" alt="Foto" class="w-full h-full object-cover">
                        <?php else: ?>
                            <div id="photoPlaceholder" class="text-center text-slate-400 p-4">
                                <i class="fas fa-image text-3xl mb-2 opacity-50"></i>
                                <p class="text-xs">Belum ada foto</p>
                            </div>
                            <img id="photoPreview" class="hidden w-full h-full object-cover">
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- KENDALA & SOLUSI -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-clipboard-list text-xs"></i></span>
                    Kendala & Solusi
                </h3>
            </div>
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-2">
                        <i class="fas fa-triangle-exclamation mr-1.5 text-slate-500"></i>Kendala
                    </label>
                    <textarea name="obstacles" rows="3"
                        class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 bg-slate-50/40 text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:bg-white transition-all resize-none text-sm"
                        placeholder="Jelaskan kendala yang dihadapi (jika ada)..."><?= cleanInput($log['obstacles'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-2">
                        <i class="fas fa-lightbulb mr-1.5 text-slate-500"></i>Solusi
                    </label>
                    <textarea name="solutions" rows="3"
                        class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 bg-slate-50/40 text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:bg-white transition-all resize-none text-sm"
                        placeholder="Solusi yang dilakukan atau rencana tindak lanjut..."><?= cleanInput($log['solutions'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <script>
        // --- DYNAMIC ACTIVITY ROWS ---
        const ACT_EXISTING = <?= json_encode($existingActivities ?: []) ?>;
        const ACT_FIELDS = [
            { v: 'operation',   c: 'Operation',   icon: 'fa-gears',           countEl: 'act_count_op',    hiddenEl: 'activity_operation' },
            { v: 'maintenance', c: 'Maintenance', icon: 'fa-wrench',          countEl: 'act_count_maint', hiddenEl: 'activity_maintenance' },
            { v: 'project',     c: 'Project',     icon: 'fa-diagram-project', countEl: 'act_count_proj',  hiddenEl: 'activity_project' },
            { v: 'landscape',   c: 'Landscape',   icon: 'fa-leaf',            countEl: 'act_count_land',  hiddenEl: 'activity_landscape' },
        ];
        let actRowCounter = 0;
        function colOf(cat) { const f = ACT_FIELDS.find(x=>x.v===cat)||ACT_FIELDS[0]; return f; }
        function addActRow(cat='operation', title='') {
            const list = document.getElementById('actList');
            const idx = actRowCounter++;
            const row = document.createElement('div');
            row.className = 'actRow flex flex-col sm:flex-row gap-2 sm:items-stretch rounded-xl border border-slate-200 bg-slate-50/40 p-2 hover:border-slate-400 hover:bg-slate-50 transition-all';
            row.innerHTML = `
                <div class="sm:w-56">
                    <select class="actCat w-full h-11 px-3 rounded-lg bg-white border border-slate-200 font-semibold text-sm text-slate-800" name="act[${idx}][cat]" onchange="onActChanged(this)">
                        ${ACT_FIELDS.map(f => `<option value="${f.v}" ${cat===f.v?'selected':''}>${f.c}</option>`).join('')}
                    </select>
                </div>
                <div class="flex-1 flex gap-2">
                    <input type="text" class="actTitle flex-1 h-11 px-3.5 rounded-lg bg-white border border-slate-200 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 outline-none transition-all text-slate-800 text-sm"
                        name="act[${idx}][t]" placeholder="Nama pekerjaan..." value="${title.replace(/"/g,'&quot;')}" oninput="onActChanged(this)">
                    <button type="button" onclick="this.closest('.actRow').remove(); recalcAct();" class="h-11 px-3.5 rounded-lg bg-slate-50 border border-slate-200 text-slate-500 font-semibold hover:bg-slate-100 hover:text-red-600 transition-all">
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

            // 1. NOTICE LISTRIK
            const eNotice = document.getElementById('elecNotice');
            if (eNotice) {
                if (isMalam) {
                    eNotice.className = 'mt-3 text-[11px] font-semibold px-3 py-2 rounded-lg border bg-slate-50 text-slate-700 border-slate-200';
                    eNotice.innerHTML = '<i class="fas fa-moon mr-1"></i> <b>Malam</b> — LWBP & WBP = (Today − Kemarin) × 8.000. Hasil masuk Cost & Dashboard.';
                } else {
                    eNotice.className = 'mt-3 text-[11px] font-semibold px-3 py-2 rounded-lg border bg-slate-50 text-slate-600 border-slate-200';
                    eNotice.innerHTML = '<i class="fas fa-circle-check mr-1"></i> <b>Pagi/Siang</b> — Reading diisi saja, Total Konsumsi = 0 (tidak masuk cost).';
                }
            }

            // 2. NOTICE WATER
            const wNotice = document.getElementById('waterNotice');
            if (wNotice) {
                if (isMalam) {
                    wNotice.className = 'mt-3 text-[11px] font-semibold px-3 py-2 rounded-lg border bg-slate-50 text-slate-700 border-slate-200';
                    wNotice.innerHTML = '<i class="fas fa-moon mr-1"></i> <b>Malam</b> — Main Bld = (Today − Kemarin) × 10. Hasil masuk Cost & Dashboard.';
                } else {
                    wNotice.className = 'mt-3 text-[11px] font-semibold px-3 py-2 rounded-lg border bg-slate-50 text-slate-600 border-slate-200';
                    wNotice.innerHTML = '<i class="fas fa-circle-check mr-1"></i> <b>Pagi/Siang</b> — Reading diisi saja, Total = 0 (tidak masuk cost).';
                }
            }
            calcTotals();
        }
        function calcTotals() {
            const isMalam = document.getElementById('shiftSelect').value === 'malam';

            // 1. TOTAL LISTRIK: SESUAI RUMUS WA CUSTOMER (FAKTOR KALI DIGIT METER × 8000)
            //    LWBP: (TODAY − YESTERDAY) × 8000 → kWh
            //    WBP:  (TODAY − YESTERDAY) × 8000 → kWh
            //    TOTAL LISTRIK = LWBP + WBP
            let wbpInp   = document.querySelector('input[name="electricity_wbp"]');
            let lwbpInp  = document.querySelector('input[name="electricity_lwbp"]');
            let tWbp      = parseFloat(wbpInp  && wbpInp.value  || 0);
            let tLwbp     = parseFloat(lwbpInp && lwbpInp.value || 0);

            let wbpDiff  = Math.max(0, tWbp  - (window.Y_ELEC_WBP  || 0));
            let lwbpDiff = Math.max(0, tLwbp - (window.Y_ELEC_LWBP || 0));
            let wbpUsage  = wbpDiff  * 8000;
            let lwbpUsage = lwbpDiff * 8000;
            let totalElec = isMalam ? (wbpUsage + lwbpUsage) : 0;
            document.getElementById('totalElectricity').value = totalElec.toFixed(2);

            // Update label konsumsi di masing-masing box LWBP / WBP (jika ada)
            let lblWbp = document.getElementById('elecWbpCons');
            if (lblWbp) lblWbp.textContent = wbpUsage.toFixed(2) + ' kWh';
            let lblLwbp = document.getElementById('elecLwbpCons');
            if (lblLwbp) lblLwbp.textContent = lwbpUsage.toFixed(2) + ' kWh';

            // 2. TOTAL WATER = HANYA MAIN BUILDING SAJA, hanya MALAM hitung selisih
            // ✅ Rumus Konsumsi Air = (MB Hari Ini − MB Kemarin) × 10 (sesuai request user)
            let wmb = document.getElementById('waterMainBuild');
            let wCons = 0;
            if (wmb) {
                let yWater = parseFloat(wmb.getAttribute('data-yesterday') || 0);
                let tWater = parseFloat(wmb.value || 0);
                let wDiff  = Math.max(0, tWater - yWater);
                wCons = isMalam ? (wDiff * 10) : 0;
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

        <div class="flex flex-col sm:flex-row sm:justify-end gap-3 pt-1">
            <a href="<?= BASE_URL ?>engineer/select_date.php"
                class="px-5 py-3 rounded-xl border border-slate-200 bg-white text-slate-700 font-medium hover:bg-slate-50 transition-all text-center text-sm">
                <i class="fas fa-arrow-left mr-1.5"></i>Batal
            </a>
            <button type="submit"
                class="px-7 py-3 rounded-xl bg-slate-800 text-white font-semibold shadow-sm hover:bg-slate-900 hover:shadow-md transition-all flex items-center justify-center gap-2 text-sm">
                <i class="fas fa-save"></i>
                Simpan Daily Log
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
