<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = T('form_title', 'Isi Daily Log Engineering');
requireRole(['engineer', 'supervisor', 'manager']);

$db = Database::getInstance();
$user = currentUser();
$roleLower = strtolower((string)($user['role'] ?? ''));
$isEngineerRole = $roleLower === 'engineer';
$canChooseEngineer = in_array($roleLower, ['supervisor','manager','admin'], true);

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
        if (!isset($colsLC['tariff_electricity_wbp_per_kwh']))
            $pdoMig2->exec("ALTER TABLE daily_logs ADD COLUMN `tariff_electricity_wbp_per_kwh` INT UNSIGNED DEFAULT NULL COMMENT 'Snapshot Tarif Listrik WBP (Rp/kWh)' AFTER `tariff_electricity_per_kwh`");
        if (!isset($colsLC['tariff_electricity_lwbp_per_kwh']))
            $pdoMig2->exec("ALTER TABLE daily_logs ADD COLUMN `tariff_electricity_lwbp_per_kwh` INT UNSIGNED DEFAULT NULL COMMENT 'Snapshot Tarif Listrik LWBP (Rp/kWh)' AFTER `tariff_electricity_wbp_per_kwh`");
        if (!isset($colsLC['tariff_water_per_m3']))
            $pdoMig2->exec("ALTER TABLE daily_logs ADD COLUMN `tariff_water_per_m3` INT UNSIGNED DEFAULT NULL COMMENT 'Snapshot Tarif PDAM (Rp/m3)' AFTER `tariff_electricity_lwbp_per_kwh`");
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
        $cols3 = $db->fetchAll("SHOW COLUMNS FROM daily_logs");
        $afterCol3 = 'id';
        foreach ($cols3 as $c) if (strtolower($c['Field']) === 'log_date') $afterCol3 = 'log_date';
        $pdoMig3->exec("ALTER TABLE daily_logs ADD COLUMN `shift` ENUM('pagi','siang','malam') DEFAULT NULL COMMENT 'Shift Pagi(06-14)/Siang(14-22)/Malam(22-06)' AFTER `{$afterCol3}`");
    } catch (\Throwable $e) { /* Already exists, safe ignore */ }
}
// --- BARU: Kolom EQUIPMENT_DATA (Section Equipment Log JSON) ---
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
        $pdoMig4->exec("ALTER TABLE daily_logs ADD COLUMN `equipment_data` LONGTEXT NULL COMMENT 'Equipment Log JSON (Trafo,Genset,Pump,Chiller,CT,RO,Pool,Gas,Boiler,HeatPump)' AFTER `{$afterCol4}`");
    } catch (\Throwable $e) { /* Already exists, safe ignore */ }
}
// --- BARU (2026-08-23): Kolom DEDUCTION (SWRO already exists, add 916/PTMac/Biosystem) + Irrigation Water ---
$_dlMigFlag5 = $db->fetchAll("SHOW COLUMNS FROM daily_logs LIKE 'ded_916_water'");
if (empty($_dlMigFlag5)) {
    try {
        $pdoMig5 = $db->getConnection();
        $cols5 = $db->fetchAll("SHOW COLUMNS FROM daily_logs");
        $cols5LC = []; foreach ($cols5 as $c) $cols5LC[strtolower($c['Field'])] = true;
        $afterMig5 = 'bottling_watermeter';
        if (!isset($cols5LC['bottling_watermeter'])) {
            if (isset($cols5LC['swro_tds'])) $afterMig5 = 'swro_tds';
            else $afterMig5 = 'equipment_data';
        }
        $addCol5 = function($colName, $colDef) use ($pdoMig5, $cols5LC, &$afterMig5) {
            if (!isset($cols5LC[strtolower($colName)])) {
                $pdoMig5->exec("ALTER TABLE daily_logs ADD COLUMN `{$colName}` {$colDef} AFTER `{$afterMig5}`");
            }
            $afterMig5 = $colName;
        };
        $addCol5('water_irrigation',     "DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Konsumsi Air Irigasi (m3)'");
        $addCol5('ded_916_water',        "DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Deduction 916 Water Meter (m3)'");
        $addCol5('ded_916_kwh',          "DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Deduction 916 Listrik (kWh)'");
        $addCol5('ded_ptmac_kwh',        "DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Deduction PT Mac Listrik (kWh)'");
        $addCol5('ded_biosystem_water',  "DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Deduction Biosystem Water (m3)'");
        $addCol5('ded_biosystem_kwh',    "DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Deduction Biosystem Listrik (kWh)'");
        unset($addCol5);
    } catch (\Throwable $e) { /* Already exists, safe ignore */ }
}
unset($_dlMigFlag, $_dlMigFlag2, $_dlMigFlag3, $_dlMigFlag4, $_dlMigFlag5, $cols, $colsLC, $cols3, $cols4, $cols4LC, $cols5, $cols5LC);

// ==============================================
// 🔧 HELPER: Build Equipment Section Data (NEW STRUCTURE 2026-08-23)
// ==============================================
function buildDailyEquipSectionData() {
    // --- Chillers Units 1-3 ---
    $chillersUnits = [];
    for ($i = 1; $i <= 3; $i++) {
        $chillersUnits[$i] = [
            'ph'                => (float)normalizeDecimalInput($_POST["chillers_units_{$i}_ph"] ?? 0),
            'tds'               => (float)normalizeDecimalInput($_POST["chillers_units_{$i}_tds"] ?? 0),
            'pressure_kgcm2'    => (float)normalizeDecimalInput($_POST["chillers_units_{$i}_pressure"] ?? 0),
            'on'                => isset($_POST["chillers_units_{$i}_on"]) ? true : false
        ];
    }
    // --- CHWP Units 1-3 ---
    $chwpUnits = [];
    for ($i = 1; $i <= 3; $i++) {
        $opVal = (string)($_POST["chwp_units_{$i}_op"] ?? 'off');
        if (!in_array($opVal, ['1','2','3','off'], true)) $opVal = 'off';
        $chwpUnits[$i] = [
            'ph'                => (float)normalizeDecimalInput($_POST["chwp_units_{$i}_ph"] ?? 0),
            'tds'               => (float)normalizeDecimalInput($_POST["chwp_units_{$i}_tds"] ?? 0),
            'pressure_kgcm2'    => (float)normalizeDecimalInput($_POST["chwp_units_{$i}_pressure"] ?? 0),
            'op'                => $opVal
        ];
    }
    // --- Cooling Towers 1-3 ---
    $coolingTowers = [];
    for ($i = 1; $i <= 3; $i++) {
        $opVal = (string)($_POST["cooling_towers_{$i}_op"] ?? 'off');
        if (!in_array($opVal, ['1','2','3','off'], true)) $opVal = 'off';
        $coolingTowers[$i] = [
            'level_pct'     => (float)normalizeDecimalInput($_POST["cooling_towers_{$i}_level_pct"] ?? 0),
            'op'            => $opVal
        ];
    }
    // --- CWP Units 1-3 ---
    $cwpUnits = [];
    for ($i = 1; $i <= 3; $i++) {
        $opVal = (string)($_POST["cwp_units_{$i}_op"] ?? 'off');
        if (!in_array($opVal, ['1','2','3','off'], true)) $opVal = 'off';
        $cwpUnits[$i] = [
            'ph'                => (float)normalizeDecimalInput($_POST["cwp_units_{$i}_ph"] ?? 0),
            'tds'               => (float)normalizeDecimalInput($_POST["cwp_units_{$i}_tds"] ?? 0),
            'pressure_kgcm2'    => (float)normalizeDecimalInput($_POST["cwp_units_{$i}_pressure"] ?? 0),
            'op'                => $opVal
        ];
    }
    // --- Steam Boiler Units 1-2 ---
    $sbUnits = [];
    for ($i = 1; $i <= 2; $i++) {
        $opKey = 'sb_unit_op_' . $i;
        if ($i === 1) {
            $opVal = (string)($_POST['sb_unit_op'] ?? 'off');
        } else {
            $opVal = (string)($_POST[$opKey] ?? 'off');
        }
        if (!in_array($opVal, ['1','2','off'], true)) $opVal = 'off';
        if ($i === 1) {
            $pressVal = (float)normalizeDecimalInput($_POST['steam_boiler_pressure'] ?? 0);
        } else {
            $pressVal = (float)normalizeDecimalInput($_POST['steam_boiler_pressure_' . $i] ?? 0);
        }
        $sbUnits[$i] = [
            'op'                    => $opVal,
            'steam_pressure_kgcm2'  => $pressVal
        ];
    }
    // --- Hot Water Boiler Units 1-2 ---
    $hwbUnits = [];
    for ($i = 1; $i <= 2; $i++) {
        if ($i === 1) {
            $opVal = (string)($_POST['hwb_unit_op'] ?? 'off');
            $tempVal = (float)normalizeDecimalInput($_POST['hwb_temp'] ?? 0);
            $pressVal = (float)normalizeDecimalInput($_POST['hwb_press'] ?? 0);
        } else {
            $opVal = (string)($_POST['hwb_unit_op_' . $i] ?? 'off');
            $tempVal = (float)normalizeDecimalInput($_POST['hwb_temp_' . $i] ?? 0);
            $pressVal = (float)normalizeDecimalInput($_POST['hwb_press_' . $i] ?? 0);
        }
        if (!in_array($opVal, ['1','2','off'], true)) $opVal = 'off';
        $hwbUnits[$i] = [
            'op'                => $opVal,
            'temperature_c'     => $tempVal,
            'pressure_kgcm2'    => $pressVal
        ];
    }
    // --- HWB Circ Pump Units multi-select ---
    $hwbCircPumpUnits = [];
    for ($i = 1; $i <= 4; $i++) {
        if (isset($_POST['hwb_circ_pump_' . $i])) {
            $hwbCircPumpUnits[] = $i;
        }
    }
    // --- Heat Pump Units 1-3 ---
    $hpUnits = [];
    for ($i = 1; $i <= 3; $i++) {
        if ($i === 1) {
            $opVal = (string)($_POST['hp_unit_op'] ?? 'off');
            $tempVal = (float)normalizeDecimalInput($_POST['hp_temp'] ?? 0);
            $pressVal = (float)normalizeDecimalInput($_POST['hp_press'] ?? 0);
        } else {
            $opVal = (string)($_POST['hp_unit_op_' . $i] ?? 'off');
            $tempVal = (float)normalizeDecimalInput($_POST['hp_temp_' . $i] ?? 0);
            $pressVal = (float)normalizeDecimalInput($_POST['hp_press_' . $i] ?? 0);
        }
        if (!in_array($opVal, ['1','2','3','off'], true)) $opVal = 'off';
        $hpUnits[$i] = [
            'op'                => $opVal,
            'temperature_c'     => $tempVal,
            'pressure_kgcm2'    => $pressVal
        ];
    }
    // --- Genset Battery Gen 1-3 ---
    $gensetBattery = [];
    for ($i = 1; $i <= 3; $i++) {
        $gensetBattery[$i] = (float)normalizeDecimalInput($_POST['genset_' . $i . '_volt'] ?? 0);
    }

    // --- Legacy Pump Room fields (sb/hwb backward compat) ---
    $pumpSbLegacy = [
        'unit_op'               => (string)($_POST['pump_sb_unit_op'] ?? 'off'),
        'sb1_running_hours'     => (string)($_POST['pump_sb1_hours'] ?? ''),
        'sb2_running_hours'     => (string)($_POST['pump_sb2_hours'] ?? ''),
        'water_test_tds_ph'     => (string)($_POST['pump_sb_test'] ?? ''),
        'steam_pressure_kgcm2'  => (string)($_POST['pump_sb_press'] ?? ''),
        'time_blow_down'        => (string)($_POST['pump_sb_blow'] ?? ''),
        'econ_temp_c'           => (string)($_POST['pump_sb_econ_temp'] ?? ''),
        'econ_press_psi_kgcm2'  => (string)($_POST['pump_sb_econ_press'] ?? '')
    ];
    $pumpHwbLegacy = [
        'unit_op'                   => (string)($_POST['pump_hwb_unit_op'] ?? 'off'),
        'hwb1_running_hours'        => (string)($_POST['pump_hwb1_hours'] ?? ''),
        'hwb2_running_hours'        => (string)($_POST['pump_hwb2_hours'] ?? ''),
        'hw_temp_c'                 => (string)($_POST['pump_hwb_temp'] ?? ''),
        'water_test_tds_ph'         => (string)($_POST['pump_hwb_test'] ?? ''),
        'circ_pump_unit_op'         => (string)($_POST['pump_hwb_circ_op'] ?? ''),
        'flow_press_psi_kgcm2'      => (string)($_POST['pump_hwb_flow'] ?? ''),
        'return_press_psi_kgcm2'    => (string)($_POST['pump_hwb_ret'] ?? '')
    ];

    return [
        'trafo' => [
            'units' => [
                1 => ['temp_c' => (float)normalizeDecimalInput($_POST['trafo_1_temp'] ?? 0), 'ampere_lvdp' => (float)normalizeDecimalInput($_POST['trafo_1_ampere'] ?? 0), 'oil_level_pct' => (float)normalizeDecimalInput($_POST['trafo_1_oil'] ?? 0)],
                2 => ['temp_c' => (float)normalizeDecimalInput($_POST['trafo_2_temp'] ?? 0), 'ampere_lvdp' => (float)normalizeDecimalInput($_POST['trafo_2_ampere'] ?? 0), 'oil_level_pct' => (float)normalizeDecimalInput($_POST['trafo_2_oil'] ?? 0)]
            ]
        ],
        'genset' => [
            'battery_gen'           => $gensetBattery,
            'konsumsi_fuel_liter'   => (float)normalizeDecimalInput($_POST['konsumsi_fuel_liter'] ?? 0),
            'fuel_tank_liter'       => (float)normalizeDecimalInput($_POST['genset_fuel_tank'] ?? 0)
        ],
        'chiller_system' => [
            'chillers_units'    => $chillersUnits,
            'chwp_units'        => $chwpUnits,
            'cooling_towers'    => $coolingTowers,
            'cwp_units'         => $cwpUnits
        ],
        'steam_boiler' => [
            'units' => $sbUnits
        ],
        'hot_water_boiler' => [
            'units'             => $hwbUnits,
            'circ_pump_units'   => $hwbCircPumpUnits
        ],
        'heat_pump' => [
            'units' => $hpUnits
        ],
        'pump_room' => [
            'steam_boiler'      => $pumpSbLegacy,
            'hot_water_boiler'  => $pumpHwbLegacy,
            'ground_tank' => [
                'raw_tank_level_pct_tds_ph'         => (string)($_POST['pump_tank_raw'] ?? ''),
                'treated_tank_level_pct_tds_ph'     => (string)($_POST['pump_tank_treated'] ?? ''),
                'irigation_tank_level_pct'          => (string)($_POST['pump_tank_irigasi'] ?? ''),
                'reservoir_tank_level_pct_tds_ph'   => (string)($_POST['pump_tank_reservoir'] ?? '')
            ],
            'hydrant_pump' => [
                'unit_standby_auto' => (string)($_POST['pump_hyd_standby'] ?? 'auto'),
                'press_pump1'       => (string)($_POST['pump_hyd_press1'] ?? ''),
                'press_pump2'       => (string)($_POST['pump_hyd_press2'] ?? '')
            ],
            'jockey_pump' => [
                'standby_press_kgcm2' => (string)($_POST['pump_jockey_press'] ?? '')
            ],
            'sand_filter' => [
                'status'                    => (string)($_POST['pump_sf_status'] ?? 'off'),
                'water_press_sand_psi_kgcm2'    => (string)($_POST['pump_sf_press_sand'] ?? ''),
                'water_press_carbon_psi_kgcm2'  => (string)($_POST['pump_sf_press_carbon'] ?? '')
            ],
            'sand_filter_pump' => [
                'status'                => (string)($_POST['pump_sfp_status'] ?? 'off'),
                'unit_op'               => (string)($_POST['pump_sfp_unit_op'] ?? ''),
                'water_press_psi_kgcm2' => (string)($_POST['pump_sfp_press'] ?? '')
            ],
            'irrigation_pump' => [
                'unit_op'               => (string)($_POST['pump_irigasi_unit_op'] ?? '0'),
                'water_press_psi_kgcm2' => (string)($_POST['pump_irigasi_press'] ?? '')
            ]
        ],
        'reverse_osmosis' => [
            'water_meter_m3'        => (string)($_POST['ro_meter'] ?? ''),
            'water_permeate_m3ph'   => (string)($_POST['ro_permeate'] ?? ''),
            'tds_ph_permeate'       => (string)($_POST['ro_test_permeate'] ?? ''),
            'tds_ph_deep_well'      => (string)($_POST['ro_test_deepwell'] ?? '')
        ],
        'pool_system' => [
            'lagoon_1' => [
                'alarm_on_off'              => (string)($_POST['pool_l1_alarm'] ?? 'on'),
                'pump_running_unit_op'      => (string)($_POST['pool_l1_pump'] ?? '1'),
                'pressure_tank_kgcm2'       => (string)($_POST['pool_l1_press'] ?? ''),
                'submersible_auto'          => (string)($_POST['pool_l1_sub_auto'] ?? 'auto')
            ],
            'lagoon_2' => [
                'alarm_on_off'              => (string)($_POST['pool_l2_alarm'] ?? 'on'),
                'pump_running_unit_op'      => (string)($_POST['pool_l2_pump'] ?? '1'),
                'pressure_tank_kgcm2'       => (string)($_POST['pool_l2_press'] ?? ''),
                'submersible_auto'          => (string)($_POST['pool_l2_sub_auto'] ?? 'auto')
            ],
            'aquavitale' => [
                'alarm_on_off'              => (string)($_POST['pool_aqua_alarm'] ?? 'on'),
                'pump_running_unit_op'      => (string)($_POST['pool_aqua_pump'] ?? '7'),
                'hot_water_boiler_temp_c'   => (string)($_POST['pool_aqua_hwbtemp'] ?? ''),
                'submersible_auto'          => (string)($_POST['pool_aqua_sub_auto'] ?? 'auto')
            ],
            'main_pump_room' => [
                'alarm_on_off'      => (string)($_POST['pool_mpr_alarm'] ?? 'on'),
                'submersible_auto'  => (string)($_POST['pool_mpr_sub_auto'] ?? 'auto')
            ]
        ],
        'gas_system' => [
            'detector_boneka_resto' => [
                'selenoid_valve_open_close' => (string)($_POST['gas_boneka_valve'] ?? 'open'),
                'alarm_on_off'              => (string)($_POST['gas_boneka_alarm'] ?? 'on')
            ],
            'detector_main_kitchen' => [
                'selenoid_valve_open_close' => (string)($_POST['gas_mainkitchen_valve'] ?? 'open'),
                'alarm_on_off'              => (string)($_POST['gas_mainkitchen_alarm'] ?? 'on')
            ],
            'detector_kayu_puti_resto' => [
                'selenoid_valve_open_close' => (string)($_POST['gas_kayuputih_valve'] ?? 'open'),
                'alarm_on_off'              => (string)($_POST['gas_kayuputih_alarm'] ?? 'on')
            ],
            'detector_black_sand_pond' => [
                'selenoid_valve_open_close' => (string)($_POST['gas_bsp_valve'] ?? 'open'),
                'alarm_on_off'              => (string)($_POST['gas_bsp_alarm'] ?? 'on')
            ],
            'detector_hwb' => [
                'selenoid_valve_open_close' => (string)($_POST['gas_hwb_valve'] ?? 'open'),
                'alarm_on_off'              => (string)($_POST['gas_hwb_alarm'] ?? 'on')
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
    $engineerOptions = $db->fetchAll(
        "SELECT id, name, role, position FROM users WHERE status='active' ORDER BY FIELD(role,'engineer','supervisor','manager','admin'), name ASC"
    );
    $reqEngId = isset($_GET['engineer_id']) ? (int)$_GET['engineer_id'] : (isset($_POST['_target_engineer_id']) ? (int)$_POST['_target_engineer_id'] : 0);
    if ($reqEngId > 0) {
        $foundEng = false;
        foreach ($engineerOptions as $eo) if ((int)$eo['id'] === $reqEngId) { $foundEng = true; break; }
        if ($foundEng) $targetEngineerId = $reqEngId;
    } else {
        $tmpFirst = $db->fetchOne("SELECT engineer_id FROM daily_logs WHERE log_date = ? ORDER BY id DESC LIMIT 1", [$date]);
        if ($tmpFirst && !empty($tmpFirst['engineer_id'])) $targetEngineerId = (int)$tmpFirst['engineer_id'];
    }
}

$log = $db->fetchOne(
    "SELECT * FROM daily_logs WHERE engineer_id = ? AND log_date = ?",
    [$targetEngineerId, $date]
);

$allShifts = ['pagi','siang','malam'];
$_curHour = (int)date('H');
if ($_curHour >= 6 && $_curHour < 14) $defaultShiftNow = 'pagi';
elseif ($_curHour >= 14 && $_curHour < 22) $defaultShiftNow = 'siang';
else $defaultShiftNow = 'malam';

$yestRow = $db->fetchOne("
    SELECT log_date, water_main_building, electricity_wbp, electricity_lwbp
    FROM daily_logs
    WHERE log_date < ?
      AND COALESCE(shift, 'malam') = 'malam'
      AND COALESCE(water_main_building,0) + COALESCE(electricity_wbp,0) + COALESCE(electricity_lwbp,0) > 0
    ORDER BY log_date DESC, id DESC
    LIMIT 1
", [$date]);
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

$mbTodayRead = $log && isset($log['water_main_building']) ? (float)$log['water_main_building'] : 0.0;
$elecTodayWbp   = $log && isset($log['electricity_wbp'])   ? (float)$log['electricity_wbp']   : 0.0;
$elecTodayLwbp  = $log && isset($log['electricity_lwbp'])  ? (float)$log['electricity_lwbp']  : 0.0;
$elecTodayTotal = $elecTodayWbp + $elecTodayLwbp;

// Load DEFAULT — SEMUA SHIFT (Pagi/Siang/Malam) AUTO HITUNG sama formula JS
$_mbTodayRaw = $mbTodayRead;
$_mbYestRaw  = $mbYesterdayRead;
$mbConsumptionBase = max(0.0, $_mbTodayRaw - $_mbYestRaw) * 10;
$mbConsumption = $mbConsumptionBase
    + (float)($log['water_pdam'] ?? 0)
    + (float)($log['water_iki_gaban'] ?? 0)
    + (float)($log['water_deepwell_1'] ?? 0)
    + (float)($log['water_deepwell_2_brr'] ?? 0)
    + (float)($log['water_deepwell_asean'] ?? 0)
    + (float)($log['water_deepwell_lpb'] ?? 0)
    + (float)($log['water_cooling_tower'] ?? 0)
    + (float)($log['water_bottling'] ?? 0)
    + (float)($log['water_irrigation'] ?? 0);
$eLwbpConsNow = max(0.0, $elecTodayLwbp - $elecYesterdayLwbp) * 8000;
$eWbpConsNow  = max(0.0, $elecTodayWbp  - $elecYesterdayWbp)  * 8000;
$elecConsumptionNow = $eLwbpConsNow + $eWbpConsNow;
unset($eLwbpConsNow, $eWbpConsNow, $yestRow, $_mbTodayRaw, $_mbYestRaw, $mbConsumptionBase);

$yElecWbpJs   = (float)$elecYesterdayWbp;
$yElecLwbpJs  = (float)$elecYesterdayLwbp;
$yElecTotalJs = (float)$elecYesterdayTotal;
$yWaterMbJs   = (float)$mbYesterdayRead;

if ($yesterdayFoundDate) {
    $yDateLabelFmt = date('d/m/Y', strtotime($yesterdayFoundDate));
} else {
    $yDateLabelFmt = T('form_yesterday_never', 'Belum ada data');
}

// --- POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shift = trim((string)($_POST['shift'] ?? ''));
    if (!in_array($shift, $allShifts, true)) $shift = $defaultShiftNow;

    // ⭐ BACKFILL OVERRIDE YESTERDAY (jika user klik ✏️ Edit Yesterday di form):
    // Hidden input _y_*_override = KOSONG  → pakai Yesterday otomatis dari auto-fetch DB (defaut normal hari ini)
    // Hidden input _y_*_override = TERISI  → user sedang input data historis tahun lalu, timpa Yesterday pakai input manual
    $_yOvList = [
      'wbp'  => trim((string)($_POST['_y_elec_wbp_override']  ?? '')),
      'lwbp' => trim((string)($_POST['_y_elec_lwbp_override'] ?? '')),
      'wm'   => trim((string)($_POST['_y_water_mb_override']  ?? '')),
    ];
    // 🔥 MODE BACKFILL DETECTION (Fix BULAN DEPAN TIDAK USAH INJECT SQL LAGI!
    // Rule: JIKA SALAH SATU dari 3 field override TERISI = User unlock Yesterday = mode BACKFILL HISTORIS
    // Maka: total_xx disimpan sebagai TODAY READING (nilai hari itu sendiri), BUKAN (Today-Yesterday selisih
    $isBackfillMode = ($_yOvList['wbp'] !== '' || $_yOvList['lwbp'] !== '' || $_yOvList['wm'] !== '');
    if ($_yOvList['wbp']  !== '') $elecYesterdayWbp  = (float)normalizeDecimalInput($_yOvList['wbp']);
    if ($_yOvList['lwbp'] !== '') $elecYesterdayLwbp = (float)normalizeDecimalInput($_yOvList['lwbp']);
    if ($_yOvList['wm']   !== '') $mbYesterdayRead   = (float)normalizeDecimalInput($_yOvList['wm']);
    unset($_yOvList);

    // ① Electricity Subdetails — Phase 2c: use normalizeDecimalInput — SEMUA SHIFT AUTO HITUNG
    $eWbp   = (float)normalizeDecimalInput($_POST['electricity_wbp'] ?? 0);
    $eLwbp  = (float)normalizeDecimalInput($_POST['electricity_lwbp'] ?? 0);
    $eTodayTotal = $eWbp + $eLwbp;
    $isShiftMalam = ($shift === 'malam');
    $_eLWBP = max(0.0, $eLwbp - $elecYesterdayLwbp) * 8000;
    $_eWBP  = max(0.0, $eWbp  - $elecYesterdayWbp)  * 8000;
    $electricityConsumptionCostBase = $_eLWBP + $_eWBP;
    unset($_eLWBP, $_eWBP);
    // ✅ FIX 2026-08-26: TIDAK USAH INJECT DB MANUAL LAGI KAK BACKFILL!
    if ($isBackfillMode) {
        // MODE BACKFILL: total_electricity = NILAI TODAY READING (wbp+lwbp)
        // (bukan selisih hari ini - kemarin. Ini yang membuat LY kemarin 26.240 kWh tersimpan 3.27 saja)
        $electricity = $eTodayTotal;
    } else {
        // MODE NORMAL (HARI INI): pakai selisih (pemakaian hari ini kemarin) — tetap untuk log hari ini
        $electricity = $electricityConsumptionCostBase;
    }
    unset($electricityConsumptionCostBase);

    // ② Water 9+ sources — Phase 2c: use normalizeDecimalInput + ADD water_irrigation — SEMUA SHIFT AUTO HITUNG (sama formula JS)
    $wPdam   = (float)normalizeDecimalInput($_POST['water_pdam'] ?? 0);
    $wIki    = (float)normalizeDecimalInput($_POST['water_iki_gaban'] ?? 0);
    $wDw1    = (float)normalizeDecimalInput($_POST['water_deepwell_1'] ?? 0);
    $wDw2    = (float)normalizeDecimalInput($_POST['water_deepwell_2_brr'] ?? 0);
    $wDwAsean= (float)normalizeDecimalInput($_POST['water_deepwell_asean'] ?? 0);
    $wDwLpb  = (float)normalizeDecimalInput($_POST['water_deepwell_lpb'] ?? 0);
    $wMainBldgRead = (float)normalizeDecimalInput($_POST['water_main_building'] ?? 0);
    $wMainBldgConsRaw = max(0.0, $wMainBldgRead - $mbYesterdayRead);
    $wMainBldgCons = $wMainBldgConsRaw * 10;
    $wMainBldg = $wMainBldgRead;
    $wCooling  = (float)normalizeDecimalInput($_POST['water_cooling_tower'] ?? 0);
    $wBottling = (float)normalizeDecimalInput($_POST['water_bottling'] ?? 0);
    $wIrrigation = (float)normalizeDecimalInput($_POST['water_irrigation'] ?? 0);
    $waterOthersSum = $wPdam + $wIki + $wDw1 + $wDw2 + $wDwAsean + $wDwLpb + $wCooling + $wBottling + $wIrrigation;
    $waterNormalMode = $wMainBldgCons + $waterOthersSum;
    unset($eTodayTotal, $wMainBldgConsRaw);
    // ✅ FIX 2026-08-26 WATER: Backfill mode → total_water = wMainBldgRead (nilai reading hari ini) + sum others
    if ($isBackfillMode) {
        $water = $wMainBldgRead + $waterOthersSum;
    } else {
        $water = $waterNormalMode;
    }
    unset($waterOthersSum, $waterNormalMode);

    // ③ Gas 2 types
    $gLpg = (float)normalizeDecimalInput($_POST['gas_lpg'] ?? 0);
    $gLng = (float)normalizeDecimalInput($_POST['gas_lng'] ?? 0);
    $gas = $gLpg + $gLng;

    // ④ Deduction reads — Phase 2c
    $dedSwroWater = (float)normalizeDecimalInput($_POST['swro_watermeter'] ?? 0);
    $dedSwroKwh   = (float)normalizeDecimalInput($_POST['swro_kwh'] ?? 0);
    $sTds         = (float)normalizeDecimalInput($_POST['swro_tds'] ?? 0);
    $ded916Water  = (float)normalizeDecimalInput($_POST['ded_916_water'] ?? 0);
    $ded916Kwh    = (float)normalizeDecimalInput($_POST['ded_916_kwh'] ?? 0);
    $dedPtmacKwh  = (float)normalizeDecimalInput($_POST['ded_ptmac_kwh'] ?? 0);
    $dedBiosystemWater = (float)normalizeDecimalInput($_POST['ded_biosystem_water'] ?? 0);
    $dedBiosystemKwh   = (float)normalizeDecimalInput($_POST['ded_biosystem_kwh'] ?? 0);

    // ⑤ Bottling 2 (legacy keep for backward compat)
    $bKwh = (float)normalizeDecimalInput($_POST['bottling_kwh'] ?? 0);
    $bWm  = (float)normalizeDecimalInput($_POST['bottling_watermeter'] ?? 0);

    // ⑥ Chiller System legacy — Phase 2c: normalizeDecimalInput
    $ch1OnPost = (int)(($_POST['chiller_1_on'] ?? 0) ? 1 : 0);
    $ch2OnPost = (int)(($_POST['chiller_2_on'] ?? 0) ? 1 : 0);
    $ch3OnPost = (int)(($_POST['chiller_3_on'] ?? 0) ? 1 : 0);
    $ch1OnNew  = (int)(isset($_POST['chillers_units_1_on']) ? 1 : 0);
    $ch2OnNew  = (int)(isset($_POST['chillers_units_2_on']) ? 1 : 0);
    $ch3OnNew  = (int)(isset($_POST['chillers_units_3_on']) ? 1 : 0);
    $ch1On = ($ch1OnPost || $ch1OnNew) ? 1 : 0;
    $ch2On = ($ch2OnPost || $ch2OnNew) ? 1 : 0;
    $ch3On = ($ch3OnPost || $ch3OnNew) ? 1 : 0;
    $chPh   = (float)normalizeDecimalInput($_POST['chiller_water_ph'] ?? 0);
    $chTds  = (float)normalizeDecimalInput($_POST['chiller_water_tds'] ?? 0);
    $chTemp = (float)normalizeDecimalInput($_POST['chiller_temp'] ?? 0);
    $chChwp = (float)normalizeDecimalInput($_POST['chiller_pressure_chwp'] ?? 0);
    $chCwp  = (float)normalizeDecimalInput($_POST['chiller_pressure_cwp'] ?? 0);

    // ⑦ Fuel — Phase 2c: legacy total_fuel populated from NEW konsumsi_fuel_liter
    $fuel = (float)normalizeDecimalInput($_POST['konsumsi_fuel_liter'] ?? $_POST['total_fuel'] ?? 0);

    // ⑧ Occupancy Rate (OCC %)
    $occRate = (float)normalizeDecimalInput($_POST['occ_rate'] ?? 0);
    if ($occRate < 0) $occRate = 0;
    if ($occRate > 100) $occRate = 100;

    // ⑧ BIS. ITR / M&U / GITB RANK — Phase 2c: normalize
    $itrScore  = isset($_POST['itr_score'])  && $_POST['itr_score'] !== ''  ? (float)normalizeDecimalInput($_POST['itr_score'])  : null;
    $muScore   = isset($_POST['mu_score'])   && $_POST['mu_score'] !== ''   ? (float)normalizeDecimalInput($_POST['mu_score'])   : null;
    $gitbRank  = isset($_POST['gitb_rank'])  && $_POST['gitb_rank'] !== ''  ? normalizeIntInput($_POST['gitb_rank'])   : null;
    if ($gitbRank !== null && $gitbRank < 0) $gitbRank = null;
    if ($gitbRank !== null && $gitbRank > 255) $gitbRank = 255;
    if ($itrScore !== null) { if ($itrScore < 0) $itrScore = 0; if ($itrScore > 999.99) $itrScore = 999.99; }
    if ($muScore  !== null) { if ($muScore  < 0) $muScore  = 0; if ($muScore  > 999.99) $muScore  = 999.99; }

    // ⑧ TRIS. TARIF SNAPSHOT
    $isRoleCanEditTariff = in_array(($user['role'] ?? ''), ['supervisor','manager','admin'], true);
    $_defTar = getTariffSettings();
    $cleanTarFn = function($key, $post, $default, $min, $max) use ($isRoleCanEditTariff, $_defTar) {
        $fallback = (int)($_defTar[$key] ?? $default);
        if (!$isRoleCanEditTariff) return $fallback > 0 ? $fallback : null;
        $raw = $post[$key] ?? null;
        if ($raw === null || $raw === '') return $fallback > 0 ? $fallback : null;
        $v = (int)$raw;
        if ($v <= 0) return $fallback > 0 ? $fallback : null;
        if ($v < $min) $v = $min;
        if ($v > $max) $v = $max;
        return $v;
    };
    $tarElec    = $cleanTarFn('electricity_per_kwh',      $_POST, 1850, 100, 10000000);
    $tarElecWbp = $cleanTarFn('electricity_wbp_per_kwh',  $_POST, 1850, 100, 10000000);
    $tarElecLwbp= $cleanTarFn('electricity_lwbp_per_kwh', $_POST, 1200, 100, 10000000);
    $tarWater   = $cleanTarFn('water_per_m3',             $_POST, 9600, 100, 10000000);
    $tarGas     = $cleanTarFn('gas_per_kg',               $_POST, 24500, 100, 10000000);
    $tarFuel    = $cleanTarFn('fuel_per_liter',           $_POST, 17450, 100, 10000000);
    unset($cleanTarFn, $_defTar);

    // ⑨ Activity Counters
    $actCats = ['project','operation','maintenance','landscape'];
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

        $equipSectionData = buildDailyEquipSectionData();
        $equipJson = json_encode($equipSectionData);
        if ($equipJson === false) $equipJson = null;

        $data = [
            'shift' => $shift,
            'total_electricity' => $electricity,
            'total_water' => $water,
            'total_gas' => $gas,
            'electricity_wbp' => $eWbp,
            'electricity_lwbp' => $eLwbp,
            'water_pdam' => $wPdam,
            'water_iki_gaban' => $wIki,
            'water_deepwell_1' => $wDw1,
            'water_deepwell_2_brr' => $wDw2,
            'water_deepwell_asean' => $wDwAsean,
            'water_deepwell_lpb' => $wDwLpb,
            'water_main_building' => $wMainBldg,
            'water_cooling_tower' => $wCooling,
            'water_bottling' => $wBottling,
            'water_irrigation' => $wIrrigation,
            'gas_lpg' => $gLpg,
            'gas_lng' => $gLng,
            'swro_watermeter' => $dedSwroWater,
            'swro_kwh' => $dedSwroKwh,
            'swro_tds' => $sTds,
            'ded_916_water' => $ded916Water,
            'ded_916_kwh' => $ded916Kwh,
            'ded_ptmac_kwh' => $dedPtmacKwh,
            'ded_biosystem_water' => $dedBiosystemWater,
            'ded_biosystem_kwh' => $dedBiosystemKwh,
            'bottling_kwh' => $bKwh,
            'bottling_watermeter' => $bWm,
            'chiller_1_on' => $ch1On,
            'chiller_2_on' => $ch2On,
            'chiller_3_on' => $ch3On,
            'chiller_water_ph' => $chPh,
            'chiller_water_tds' => $chTds,
            'chiller_temp' => $chTemp,
            'chiller_pressure_chwp' => $chChwp,
            'chiller_pressure_cwp' => $chCwp,
            'total_fuel' => $fuel,
            'occ_rate' => $occRate,
            'itr_score' => $itrScore,
            'mu_score'  => $muScore,
            'gitb_rank' => $gitbRank,
            'tariff_electricity_per_kwh'       => $tarElec,
            'tariff_electricity_wbp_per_kwh'   => $tarElecWbp,
            'tariff_electricity_lwbp_per_kwh'  => $tarElecLwbp,
            'tariff_water_per_m3'              => $tarWater,
            'tariff_gas_per_kg'                => $tarGas,
            'tariff_fuel_per_liter'            => $tarFuel,
            'equipment_data' => $equipJson,
            'activity_operation' => $actOp,
            'activity_maintenance' => $actMaint,
            'activity_project' => $actProj,
            'activity_landscape' => $actLand,
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
            $data['engineer_id'] = $targetEngineerId;
            $db->insert('daily_logs', $data);
            $logId = (int)$db->lastInsertId();
        }
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
        $redirectQs = $canChooseEngineer ? '?engineer_id=' . $targetEngineerId : '';
        redirect('engineer/select_date.php' . $redirectQs);
    }
}

$log = $db->fetchOne(
    "SELECT * FROM daily_logs WHERE engineer_id = ? AND log_date = ?",
    [$targetEngineerId, $date]
);

$existingActivities = [];
if ($log && !empty($log['id'])) {
    $existingActivities = $db->fetchAll("SELECT id, category, activity_title FROM daily_log_activities WHERE daily_log_id = ? ORDER BY FIELD(category,'project','operation','maintenance','landscape'), sort_order ASC, id ASC", [(int)$log['id']]);
}

// =========================================================
// 🧰 PARSE EXISTING EQUIPMENT DATA (JSON) untuk Default Value Form
// =========================================================
$eq = [
    'trafo' => ['units'=>[1=>['temp_c'=>0,'ampere_lvdp'=>0,'oil_level_pct'=>0], 2=>['temp_c'=>0,'ampere_lvdp'=>0,'oil_level_pct'=>0]]],
    'genset' => ['battery_gen'=>[1=>0,2=>0,3=>0], 'konsumsi_fuel_liter'=>0, 'fuel_tank_liter'=>0],
    'chiller' => [
        'chillers_units' => [
            1=>['ph'=>0,'tds'=>0,'pressure_kgcm2'=>0,'on'=>false],
            2=>['ph'=>0,'tds'=>0,'pressure_kgcm2'=>0,'on'=>false],
            3=>['ph'=>0,'tds'=>0,'pressure_kgcm2'=>0,'on'=>false],
        ],
        'chwp_units' => [
            1=>['ph'=>0,'tds'=>0,'pressure_kgcm2'=>0,'op'=>'off'],
            2=>['ph'=>0,'tds'=>0,'pressure_kgcm2'=>0,'op'=>'off'],
            3=>['ph'=>0,'tds'=>0,'pressure_kgcm2'=>0,'op'=>'off'],
        ],
        'cooling_towers' => [
            1=>['level_pct'=>0,'op'=>'off'],
            2=>['level_pct'=>0,'op'=>'off'],
            3=>['level_pct'=>0,'op'=>'off'],
        ],
        'cwp_units' => [
            1=>['ph'=>0,'tds'=>0,'pressure_kgcm2'=>0,'op'=>'off'],
            2=>['ph'=>0,'tds'=>0,'pressure_kgcm2'=>0,'op'=>'off'],
            3=>['ph'=>0,'tds'=>0,'pressure_kgcm2'=>0,'op'=>'off'],
        ],
    ],
    'steam_boiler' => [
        'units'=>[
            1=>['op'=>'off','steam_pressure_kgcm2'=>0],
            2=>['op'=>'off','steam_pressure_kgcm2'=>0],
        ]
    ],
    'hot_water_boiler' => [
        'units'=>[
            1=>['op'=>'off','temperature_c'=>0,'pressure_kgcm2'=>0],
            2=>['op'=>'off','temperature_c'=>0,'pressure_kgcm2'=>0],
        ],
        'circ_pump_units'=>[]
    ],
    'heat_pump' => [
        'units'=>[
            1=>['op'=>'off','temperature_c'=>0,'pressure_kgcm2'=>0],
            2=>['op'=>'off','temperature_c'=>0,'pressure_kgcm2'=>0],
            3=>['op'=>'off','temperature_c'=>0,'pressure_kgcm2'=>0],
        ]
    ],
    'pump' => [
        'sb_unit_op'=>'off','sb1_hours'=>'','sb2_hours'=>'','sb_test'=>'','sb_press'=>'','sb_blow'=>'','sb_econ_temp'=>'','sb_econ_press'=>'',
        'hwb_unit_op'=>'off','hwb1_hours'=>'','hwb2_hours'=>'','hwb_temp'=>'','hwb_test'=>'','hwb_circ_op'=>'','hwb_flow'=>'','hwb_ret'=>'',
        'tank_raw'=>'','tank_treated'=>'','tank_irigasi'=>'','tank_reservoir'=>'',
        'hyd_standby'=>'auto','hyd_press1'=>'','hyd_press2'=>'',
        'jockey_press'=>'',
        'sf_status'=>'off','sf_press_sand'=>'','sf_press_carbon'=>'',
        'sfp_status'=>'off','sfp_unit_op'=>'','sfp_press'=>'',
        'irigasi_unit_op'=>'0','irigasi_press'=>'',
    ],
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
        'bsp_valve'=>'open','bsp_alarm'=>'on',
        'hwb_valve'=>'open','hwb_alarm'=>'on',
    ],
];
if ($log && !empty($log['equipment_data'])) {
    $_eqDec = json_decode((string)$log['equipment_data'], true);
    if (is_array($_eqDec)) {
        // --- Trafo (unchanged) ---
        if (isset($_eqDec['trafo']['units'][1])) { foreach ($_eqDec['trafo']['units'][1] as $k=>$v) if (isset($eq['trafo']['units'][1][$k])) $eq['trafo']['units'][1][$k] = $v; }
        if (isset($_eqDec['trafo']['units'][2])) { foreach ($_eqDec['trafo']['units'][2] as $k=>$v) if (isset($eq['trafo']['units'][2][$k])) $eq['trafo']['units'][2][$k] = $v; }

        // --- Genset: NEW battery_gen array + konsumsi_fuel_liter + FALLBACK old gen_1_volt etc ---
        if (isset($_eqDec['genset'])) {
            $gs = &$_eqDec['genset'];
            if (isset($gs['battery_gen']) && is_array($gs['battery_gen'])) {
                for ($gi=1;$gi<=3;$gi++) { if (isset($gs['battery_gen'][$gi])) $eq['genset']['battery_gen'][$gi] = (float)$gs['battery_gen'][$gi]; }
            } else {
                // FALLBACK OLD STRUCTURE
                for ($gi=1;$gi<=3;$gi++) { if (isset($gs['gen_'.$gi.'_volt'])) $eq['genset']['battery_gen'][$gi] = (float)$gs['gen_'.$gi.'_volt']; }
            }
            if (isset($gs['konsumsi_fuel_liter'])) $eq['genset']['konsumsi_fuel_liter'] = (float)$gs['konsumsi_fuel_liter'];
            if (isset($gs['fuel_tank_liter'])) $eq['genset']['fuel_tank_liter'] = (float)$gs['fuel_tank_liter'];
        }

        // --- Chiller NEW STRUCTURE: chillers_units, chwp_units, cooling_towers, cwp_units ---
        if (isset($_eqDec['chiller_system']) && is_array($_eqDec['chiller_system'])) {
            $cs = &$_eqDec['chiller_system'];
            // chillers_units
            if (isset($cs['chillers_units']) && is_array($cs['chillers_units'])) {
                for ($i=1;$i<=3;$i++) {
                    if (isset($cs['chillers_units'][$i])) {
                        $cu = &$cs['chillers_units'][$i];
                        if (isset($cu['ph'])) $eq['chiller']['chillers_units'][$i]['ph'] = (float)$cu['ph'];
                        if (isset($cu['tds'])) $eq['chiller']['chillers_units'][$i]['tds'] = (float)$cu['tds'];
                        if (isset($cu['pressure_kgcm2'])) $eq['chiller']['chillers_units'][$i]['pressure_kgcm2'] = (float)$cu['pressure_kgcm2'];
                        if (isset($cu['on'])) $eq['chiller']['chillers_units'][$i]['on'] = (bool)$cu['on'];
                    }
                }
            } else {
                // FALLBACK OLD chiller_system_equip + legacy chiller checkboxes
                if (isset($log['chiller_water_ph'])) { for ($i=1;$i<=3;$i++) $eq['chiller']['chillers_units'][$i]['ph'] = (float)($log['chiller_water_ph'] ?? 0); }
                if (isset($log['chiller_water_tds'])) { for ($i=1;$i<=3;$i++) $eq['chiller']['chillers_units'][$i]['tds'] = (float)($log['chiller_water_tds'] ?? 0); }
                if (isset($log['chiller_temp'])) { for ($i=1;$i<=3;$i++) $eq['chiller']['chillers_units'][$i]['pressure_kgcm2'] = (float)($log['chiller_temp'] ?? 0); }
                for ($i=1;$i<=3;$i++) { $eq['chiller']['chillers_units'][$i]['on'] = !empty($log['chiller_'.$i.'_on']); }
            }
            // chwp_units
            if (isset($cs['chwp_units']) && is_array($cs['chwp_units'])) {
                for ($i=1;$i<=3;$i++) {
                    if (isset($cs['chwp_units'][$i])) {
                        $cu = &$cs['chwp_units'][$i];
                        if (isset($cu['ph'])) $eq['chiller']['chwp_units'][$i]['ph'] = (float)$cu['ph'];
                        if (isset($cu['tds'])) $eq['chiller']['chwp_units'][$i]['tds'] = (float)$cu['tds'];
                        if (isset($cu['pressure_kgcm2'])) $eq['chiller']['chwp_units'][$i]['pressure_kgcm2'] = (float)$cu['pressure_kgcm2'];
                        if (isset($cu['op'])) $eq['chiller']['chwp_units'][$i]['op'] = (string)$cu['op'];
                    }
                }
            } else {
                // FALLBACK from old chiller_system.chilled_water_pump
                if (isset($cs['chilled_water_pump'])) {
                    $chwpOld = &$cs['chilled_water_pump'];
                    $opOld = $chwpOld['unit_op'] ?? '1';
                    if (!in_array($opOld, ['1','2','3','off'], true)) $opOld = 'off';
                    $pressIn  = (float)($chwpOld['water_press_in_kgcm2'] ?? 0);
                    for ($i=1;$i<=3;$i++) {
                        $eq['chiller']['chwp_units'][$i]['pressure_kgcm2'] = $pressIn;
                        $eq['chiller']['chwp_units'][$i]['op'] = ($opOld === (string)$i) ? (string)$i : 'off';
                    }
                }
            }
            // cooling_towers
            if (isset($cs['cooling_towers']) && is_array($cs['cooling_towers'])) {
                for ($i=1;$i<=3;$i++) {
                    if (isset($cs['cooling_towers'][$i])) {
                        $cu = &$cs['cooling_towers'][$i];
                        if (isset($cu['level_pct'])) $eq['chiller']['cooling_towers'][$i]['level_pct'] = (float)$cu['level_pct'];
                        if (isset($cu['op'])) $eq['chiller']['cooling_towers'][$i]['op'] = (string)$cu['op'];
                    }
                }
            }
            // cwp_units
            if (isset($cs['cwp_units']) && is_array($cs['cwp_units'])) {
                for ($i=1;$i<=3;$i++) {
                    if (isset($cs['cwp_units'][$i])) {
                        $cu = &$cs['cwp_units'][$i];
                        if (isset($cu['ph'])) $eq['chiller']['cwp_units'][$i]['ph'] = (float)$cu['ph'];
                        if (isset($cu['tds'])) $eq['chiller']['cwp_units'][$i]['tds'] = (float)$cu['tds'];
                        if (isset($cu['pressure_kgcm2'])) $eq['chiller']['cwp_units'][$i]['pressure_kgcm2'] = (float)$cu['pressure_kgcm2'];
                        if (isset($cu['op'])) $eq['chiller']['cwp_units'][$i]['op'] = (string)$cu['op'];
                    }
                }
            } else {
                // FALLBACK from old chiller_system.condensor_water_pump
                if (isset($cs['condensor_water_pump'])) {
                    $cwpOld = &$cs['condensor_water_pump'];
                    $opOld = $cwpOld['unit_op'] ?? '1';
                    if (!in_array($opOld, ['1','2','3','off'], true)) $opOld = 'off';
                    $pressOld = (float)($cwpOld['water_press_kgcm2'] ?? 0);
                    for ($i=1;$i<=3;$i++) {
                        $eq['chiller']['cwp_units'][$i]['pressure_kgcm2'] = $pressOld;
                        $eq['chiller']['cwp_units'][$i]['op'] = ($opOld === (string)$i) ? (string)$i : 'off';
                    }
                }
            }
        }
        // FALLBACK: old top-level cooling_tower
        if (isset($_eqDec['cooling_tower'])) {
            $ctd = &$_eqDec['cooling_tower'];
            $opOld = (string)($ctd['unit_op'] ?? '1');
            if (!in_array($opOld, ['1','2','3','off'], true)) $opOld = 'off';
            $lvlOld = (float)($ctd['water_level_pct'] ?? 0);
            for ($i=1;$i<=3;$i++) {
                $eq['chiller']['cooling_towers'][$i]['level_pct'] = $lvlOld;
                $eq['chiller']['cooling_towers'][$i]['op'] = ($opOld === (string)$i) ? (string)$i : 'off';
            }
            if (isset($ctd['unit_op'])) $eq['ct']['unit_op'] = (string)$ctd['unit_op'];
            if (isset($ctd['water_level_pct'])) $eq['ct']['level'] = (string)$ctd['water_level_pct'];
            if (isset($ctd['water_test_tds_ph'])) $eq['ct']['test'] = (string)$ctd['water_test_tds_ph'];
        }

        // --- Steam Boiler NEW TOP LEVEL ---
        if (isset($_eqDec['steam_boiler']['units']) && is_array($_eqDec['steam_boiler']['units'])) {
            for ($i=1;$i<=2;$i++) {
                if (isset($_eqDec['steam_boiler']['units'][$i])) {
                    $su = &$_eqDec['steam_boiler']['units'][$i];
                    if (isset($su['op'])) $eq['steam_boiler']['units'][$i]['op'] = (string)$su['op'];
                    if (isset($su['steam_pressure_kgcm2'])) $eq['steam_boiler']['units'][$i]['steam_pressure_kgcm2'] = (float)$su['steam_pressure_kgcm2'];
                }
            }
        }

        // --- Hot Water Boiler NEW TOP LEVEL ---
        if (isset($_eqDec['hot_water_boiler'])) {
            if (isset($_eqDec['hot_water_boiler']['units']) && is_array($_eqDec['hot_water_boiler']['units'])) {
                for ($i=1;$i<=2;$i++) {
                    if (isset($_eqDec['hot_water_boiler']['units'][$i])) {
                        $hu = &$_eqDec['hot_water_boiler']['units'][$i];
                        if (isset($hu['op'])) $eq['hot_water_boiler']['units'][$i]['op'] = (string)$hu['op'];
                        if (isset($hu['temperature_c'])) $eq['hot_water_boiler']['units'][$i]['temperature_c'] = (float)$hu['temperature_c'];
                        if (isset($hu['pressure_kgcm2'])) $eq['hot_water_boiler']['units'][$i]['pressure_kgcm2'] = (float)$hu['pressure_kgcm2'];
                    }
                }
            }
            if (isset($_eqDec['hot_water_boiler']['circ_pump_units']) && is_array($_eqDec['hot_water_boiler']['circ_pump_units'])) {
                $eq['hot_water_boiler']['circ_pump_units'] = array_map('intval', $_eqDec['hot_water_boiler']['circ_pump_units']);
            }
        }

        // --- Heat Pump NEW TOP LEVEL ---
        if (isset($_eqDec['heat_pump']['units']) && is_array($_eqDec['heat_pump']['units'])) {
            for ($i=1;$i<=3;$i++) {
                if (isset($_eqDec['heat_pump']['units'][$i])) {
                    $hu = &$_eqDec['heat_pump']['units'][$i];
                    if (isset($hu['op'])) $eq['heat_pump']['units'][$i]['op'] = (string)$hu['op'];
                    if (isset($hu['temperature_c'])) $eq['heat_pump']['units'][$i]['temperature_c'] = (float)$hu['temperature_c'];
                    if (isset($hu['pressure_kgcm2'])) $eq['heat_pump']['units'][$i]['pressure_kgcm2'] = (float)$hu['pressure_kgcm2'];
                }
            }
        }

        // --- Pump Room (legacy backward compat) + NEW reservoir_tank ---
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
            if (isset($gt['reservoir_tank_level_pct_tds_ph'])) $eq['pump']['tank_reservoir'] = (string)$gt['reservoir_tank_level_pct_tds_ph'];
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
        if (isset($_eqDec['pump_room']['irrigation_pump'])) {
            $ip = &$_eqDec['pump_room']['irrigation_pump'];
            if (isset($ip['unit_op'])) $eq['pump']['irigasi_unit_op'] = (string)$ip['unit_op'];
            if (isset($ip['water_press_psi_kgcm2'])) $eq['pump']['irigasi_press'] = (string)$ip['water_press_psi_kgcm2'];
        }

        // --- Reverse Osmosis ---
        if (isset($_eqDec['reverse_osmosis'])) {
            $rod = &$_eqDec['reverse_osmosis'];
            if (isset($rod['water_meter_m3'])) $eq['ro']['meter'] = (string)$rod['water_meter_m3'];
            if (isset($rod['water_permeate_m3ph'])) $eq['ro']['permeate'] = (string)$rod['water_permeate_m3ph'];
            if (isset($rod['tds_ph_permeate'])) $eq['ro']['test_permeate'] = (string)$rod['tds_ph_permeate'];
            if (isset($rod['tds_ph_deep_well'])) $eq['ro']['test_deepwell'] = (string)$rod['tds_ph_deep_well'];
        }

        // --- Pool System ---
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

        // --- Gas System + NEW detectors: bsp + hwb ---
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
        if (isset($_eqDec['gas_system']['detector_black_sand_pond'])) {
            $g4 = &$_eqDec['gas_system']['detector_black_sand_pond'];
            if (isset($g4['selenoid_valve_open_close'])) $eq['gas']['bsp_valve'] = (string)$g4['selenoid_valve_open_close'];
            if (isset($g4['alarm_on_off'])) $eq['gas']['bsp_alarm'] = (string)$g4['alarm_on_off'];
        }
        if (isset($_eqDec['gas_system']['detector_hwb'])) {
            $g5 = &$_eqDec['gas_system']['detector_hwb'];
            if (isset($g5['selenoid_valve_open_close'])) $eq['gas']['hwb_valve'] = (string)$g5['selenoid_valve_open_close'];
            if (isset($g5['alarm_on_off'])) $eq['gas']['hwb_alarm'] = (string)$g5['alarm_on_off'];
        }
        unset($_eqDec);
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<?php if ($_ua_mobile = (isset($_SERVER['HTTP_USER_AGENT']) && preg_match('/(android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile)/i', strtolower($_SERVER['HTTP_USER_AGENT'])) ?? false)): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ if(window.innerWidth<1024) { const al=document.getElementById('appLayout'); const sb=document.getElementById('sidebar'); if(al){al.classList.remove('is-sidebar-expanded');al.classList.add('is-sidebar-collapsed');} if(sb){sb.classList.remove('sidebar-expanded');sb.classList.add('sidebar-collapsed');} } });</script>
<?php endif; ?>
<?php

// --- Tariff for JS TARIF window var ---
$_tarNow = getTariffSettings();
$tarifForJs = [
    'electricity_wbp_per_kwh'  => !empty($log['tariff_electricity_wbp_per_kwh'])  ? (int)$log['tariff_electricity_wbp_per_kwh']  : (int)($_tarNow['electricity_wbp_per_kwh'] ?? 1850),
    'electricity_lwbp_per_kwh' => !empty($log['tariff_electricity_lwbp_per_kwh']) ? (int)$log['tariff_electricity_lwbp_per_kwh'] : (int)($_tarNow['electricity_lwbp_per_kwh'] ?? 1200),
    'water_per_m3'             => !empty($log['tariff_water_per_m3'])             ? (int)$log['tariff_water_per_m3']             : (int)($_tarNow['water_per_m3'] ?? 9600),
    'gas_per_kg'               => !empty($log['tariff_gas_per_kg'])               ? (int)$log['tariff_gas_per_kg']               : (int)($_tarNow['gas_per_kg'] ?? 24500),
    'fuel_per_liter'           => !empty($log['tariff_fuel_per_liter'])           ? (int)$log['tariff_fuel_per_liter']           : (int)($_tarNow['fuel_per_liter'] ?? 17450),
];
unset($_tarNow);
?>

<div class="page-shell page-shell--7xl pb-[9rem] px-3 sm:px-4 md:px-6 lg:px-8">
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

    <form method="POST" enctype="multipart/form-data" class="space-y-5 pb-28">
        <?php if ($canChooseEngineer): ?>
            <input type="hidden" name="_target_engineer_id" value="<?= (int)$targetEngineerId ?>">
        <?php endif; ?>
        <!-- Hidden: Override nilai Yesterday jika user klik ✏️ Edit (backfill data historis).
             Kosong = pakai auto Yesterday dari DB (default workflow input hari normal).
             Diisi = pakai nilai custom user (SAAT INI SAJA). -->
        <input type="hidden" name="_y_elec_wbp_override" id="_yOverrideElecWbp" value="">
        <input type="hidden" name="_y_elec_lwbp_override" id="_yOverrideElecLwbp" value="">
        <input type="hidden" name="_y_water_mb_override"  id="_yOverrideWaterMb" value="">

        <!-- ============================================== -->
        <!-- ✅ Req 1: LIVE SUMMARY PANEL (STICKY)         -->
        <!-- ============================================== -->
        <div class="sticky top-2 z-40 bg-gradient-to-br from-slate-800 to-slate-900 rounded-premium border border-slate-700 shadow-lg overflow-hidden animate-slide-up print:hidden" style="animation-delay: 10ms">
            <div class="p-2 sm:p-3 lg:px-5 lg:py-3.5">
                <div class="mb-2 lg:mb-3 flex items-center justify-between">
                    <h3 class="text-[10px] sm:text-[11px] lg:text-xs font-black uppercase tracking-wider text-slate-200 flex items-center gap-1.5">
                        <i class="fas fa-chart-pie mr-1 sm:mr-2 text-slate-300"></i>Live Summary Hasil Akhir & Kalkulasi
                    </h3>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-1.5 sm:gap-3">
                    <div class="px-2.5 sm:px-4 py-2.5 sm:py-3 rounded-lg sm:rounded-xl bg-slate-800/60 border border-slate-700/60">
                        <p class="text-[9px] sm:text-[10px] md:text-[11px] font-bold uppercase tracking-wider text-slate-300 mb-1">⚡ Total Listrik</p>
                        <p class="text-[14px] sm:text-[17px] md:text-xl font-black text-white leading-tight mt-0.5"><span id="sumKwh">0.00</span> <span class="text-[9px] sm:text-[10px] text-slate-400 font-medium ml-0.5">kWh</span></p>
                        <p class="text-[10px] sm:text-[11px] font-bold text-amber-300 mt-0.5 leading-tight">Rp <span id="sumKwhCost">0</span></p>
                    </div>
                    <div class="px-2.5 sm:px-4 py-2.5 sm:py-3 rounded-lg sm:rounded-xl bg-slate-800/60 border border-slate-700/60">
                        <p class="text-[9px] sm:text-[10px] md:text-[11px] font-bold uppercase tracking-wider text-slate-300 mb-1">💧 Total Air</p>
                        <p class="text-[14px] sm:text-[17px] md:text-xl font-black text-white leading-tight mt-0.5"><span id="sumWater">0.00</span> <span class="text-[9px] sm:text-[10px] text-slate-400 font-medium ml-0.5">m3</span></p>
                        <p class="text-[10px] sm:text-[11px] font-bold text-amber-300 mt-0.5 leading-tight">Rp <span id="sumWaterCost">0</span></p>
                    </div>
                    <div class="px-2.5 sm:px-4 py-2.5 sm:py-3 rounded-lg sm:rounded-xl bg-slate-800/60 border border-slate-700/60">
                        <p class="text-[9px] sm:text-[10px] md:text-[11px] font-bold uppercase tracking-wider text-slate-300 mb-1">🔥 Total Gas</p>
                        <p class="text-[14px] sm:text-[17px] md:text-xl font-black text-white leading-tight mt-0.5"><span id="sumGas">0.00</span> <span class="text-[9px] sm:text-[10px] text-slate-400 font-medium ml-0.5">kg</span></p>
                        <p class="text-[10px] sm:text-[11px] font-bold text-amber-300 mt-0.5 leading-tight">Rp <span id="sumGasCost">0</span></p>
                    </div>
                    <div class="px-2.5 sm:px-4 py-2.5 sm:py-3 rounded-lg sm:rounded-xl bg-slate-800/60 border border-slate-700/60">
                        <p class="text-[9px] sm:text-[10px] md:text-[11px] font-bold uppercase tracking-wider text-slate-300 mb-1">⛽ Total Fuel</p>
                        <p class="text-[14px] sm:text-[17px] md:text-xl font-black text-white leading-tight mt-0.5"><span id="sumFuel">0.00</span> <span class="text-[9px] sm:text-[10px] text-slate-400 font-medium ml-0.5">L</span></p>
                        <p class="text-[10px] sm:text-[11px] font-bold text-amber-300 mt-0.5 leading-tight">Rp <span id="sumFuelCost">0</span></p>
                    </div>
                    <div class="col-span-2 sm:col-span-4 px-2.5 sm:px-4 py-2.5 sm:py-3 rounded-lg sm:rounded-xl bg-gradient-to-br from-slate-800/80 to-slate-800/50 border border-slate-700/70">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[9px] sm:text-[10px] md:text-[11px] font-bold uppercase tracking-wider text-slate-300 mb-0.5">💰 GRAND TOTAL</p>
                                <p class="text-[13px] sm:text-[17px] md:text-xl font-black text-amber-300 leading-tight mt-0.5">Rp <span id="sumGrandTotal">0</span></p>
                            </div>
                            <p class="text-[9px] sm:text-[10px] font-semibold text-slate-400 mt-0.5 text-right shrink-0">Live • Auto-update</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ① SHIFT CARD -->
        <?php
            $curShiftVal = (!empty($log['shift']) && in_array($log['shift'], $allShifts, true)) ? (string)$log['shift'] : $defaultShiftNow;
            $shiftLabels = [
                'pagi'  => '☀️ Pagi',
                'siang' => '🌤️ Siang',
                'malam' => '🌙 Malam',
            ];
            $isElecEditable = true;
            $isMalamNow = ($curShiftVal === 'malam');
            $elecReadonly = '';
            $elecRequired = 'required';
            $elecDisabledCls = '';
            $yElecWbpJs = number_format($elecYesterdayWbp, 2, '.', '');
            $yElecLwbpJs = number_format($elecYesterdayLwbp, 2, '.', '');
            $yElecTotalJs = number_format($elecYesterdayTotal, 2, '.', '');
            $yWaterMbJs  = number_format($mbYesterdayRead, 2, '.', '');
        ?>
        <div class="bg-surface rounded-premium border border-slate-200 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 30ms">
            <div class="p-4 sm:p-5 lg:p-6">
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

        <!-- ② OCCUPANCY RATE -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
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
                                oninput="occVisual(this.value); calcTotals();"
                                class="js-norm-dec w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
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
                            function setOcc(p) { const el = document.getElementById('occRate'); if (el) { el.value = p; occVisual(p); calcTotals(); } }
                            document.addEventListener('DOMContentLoaded', function() { const el = document.getElementById('occRate'); if (el) occVisual(el.value); });
                        </script>
                    </div>
                </div>
            </div>
        </div>

        <!-- ③ KPI Harian ITR/MU/GITB -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-chart-line text-[11px]"></i></span>
                    KPI Harian
                </h3>
            </div>
            <div class="p-3 sm:p-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
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
                            class="js-norm-dec w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-500">pt</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">M&U Score <span class="text-slate-400 font-normal">(Maint & Utility)</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" max="999.99" name="mu_score"
                            value="<?= $kDefMu ?>" placeholder="81.00"
                            class="js-norm-dec w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
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

        <!-- ④ TARIF HARI INI -->
        <?php
        $roleCanEditTariff = in_array(($user['role'] ?? ''), ['supervisor','manager','admin'], true);
        $_defTarNow = getTariffSettings();
        $tDef = [
            'electricity'      => ( !empty($log['tariff_electricity_per_kwh']) && (int)$log['tariff_electricity_per_kwh'] > 0 ) ? (int)$log['tariff_electricity_per_kwh'] : (int)($_defTarNow['electricity_per_kwh'] ?? 1850),
            'electricity_wbp'  => ( !empty($log['tariff_electricity_wbp_per_kwh']) && (int)$log['tariff_electricity_wbp_per_kwh'] > 0 ) ? (int)$log['tariff_electricity_wbp_per_kwh'] : (int)($_defTarNow['electricity_wbp_per_kwh'] ?? 1850),
            'electricity_lwbp' => ( !empty($log['tariff_electricity_lwbp_per_kwh']) && (int)$log['tariff_electricity_lwbp_per_kwh'] > 0 ) ? (int)$log['tariff_electricity_lwbp_per_kwh'] : (int)($_defTarNow['electricity_lwbp_per_kwh'] ?? 1200),
            'water'            => ( !empty($log['tariff_water_per_m3']) && (int)$log['tariff_water_per_m3'] > 0 ) ? (int)$log['tariff_water_per_m3'] : (int)($_defTarNow['water_per_m3'] ?? 9600),
            'gas'              => ( !empty($log['tariff_gas_per_kg']) && (int)$log['tariff_gas_per_kg'] > 0 ) ? (int)$log['tariff_gas_per_kg'] : (int)($_defTarNow['gas_per_kg'] ?? 24500),
            'fuel'             => ( !empty($log['tariff_fuel_per_liter']) && (int)$log['tariff_fuel_per_liter'] > 0 ) ? (int)$log['tariff_fuel_per_liter'] : (int)($_defTarNow['fuel_per_liter'] ?? 17450),
        ];
        $tariffReadonly = $roleCanEditTariff ? '' : 'readonly tabindex="-1"';
        $tariffCursorCls = $roleCanEditTariff ? '' : 'cursor-not-allowed opacity-90';
        ?>
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
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
            <div class="p-3 sm:p-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                <div>
                    <label class="block text-[10px] font-semibold text-slate-600 mb-1.5">Listrik WBP</label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999" id="tarWbp" name="electricity_wbp_per_kwh" value="<?= $tDef['electricity_wbp'] ?>" <?= $tariffReadonly ?> onchange="onTariffChange(); calcTotals();"
                               class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 focus:bg-white transition-all <?= $tariffCursorCls ?>">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">Rp/kWh</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-semibold text-slate-600 mb-1.5">Listrik LWBP</label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999" id="tarLwbp" name="electricity_lwbp_per_kwh" value="<?= $tDef['electricity_lwbp'] ?>" <?= $tariffReadonly ?> onchange="onTariffChange(); calcTotals();"
                               class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 focus:bg-white transition-all <?= $tariffCursorCls ?>">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">Rp/kWh</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-semibold text-slate-600 mb-1.5">Air PDAM</label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999" id="tarWater" name="water_per_m3" value="<?= $tDef['water'] ?>" <?= $tariffReadonly ?> onchange="onTariffChange(); calcTotals();"
                               class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 focus:bg-white transition-all <?= $tariffCursorCls ?>">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">Rp/m3</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-semibold text-slate-600 mb-1.5">Gas LPG</label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999" id="tarGas" name="gas_per_kg" value="<?= $tDef['gas'] ?>" <?= $tariffReadonly ?> onchange="onTariffChange(); calcTotals();"
                               class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 focus:bg-white transition-all <?= $tariffCursorCls ?>">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">Rp/kg</span>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-semibold text-slate-600 mb-1.5">Solar BBM</label>
                    <div class="relative">
                        <input type="number" step="1" min="100" max="99999999" id="tarFuel" name="fuel_per_liter" value="<?= $tDef['fuel'] ?>" <?= $tariffReadonly ?> onchange="onTariffChange(); calcTotals();"
                               class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 focus:bg-white transition-all <?= $tariffCursorCls ?>">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">Rp/Ltr</span>
                    </div>
                </div>
            </div>
        </div>
        <?php unset($_defTarNow, $tDef, $tariffReadonly, $tariffCursorCls, $roleCanEditTariff); ?>

        <!-- ⑤ KONSUMSI LISTRIK -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-amber-50 text-amber-700 font-black text-[12px] mr-2">1</span><i class="fas fa-bolt mr-1.5 text-amber-600"></i>Konsumsi Listrik
                </h3>
                <div id="elecNotice" class="mt-2 text-[10.5px] px-2.5 py-1.5 rounded-md border <?= $isMalamNow ? 'bg-slate-50 text-slate-700 border-slate-200' : 'bg-white text-slate-600 border-slate-200' ?>">
                    <?php if ($isMalamNow): ?>
                        <i class="fas fa-moon mr-1 text-slate-500"></i><b>Malam</b> — LWBP/WBP = (Today−Kemarin) × 8.000. Total masuk Cost.
                    <?php else: ?>
                        <i class="fas fa-circle-check mr-1 text-slate-500"></i><b>Pagi/Siang</b> — Bisa isi, Total = 0.
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-3 sm:p-4 space-y-3">
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
                            <div class="flex items-center justify-between gap-2 text-[10px] font-semibold">
                                <div class="flex items-center gap-1 min-w-0 flex-1">
                                    <span class="text-slate-500 shrink-0">Yesterday ({$yDateLabelFmt})</span>
                                    <button type="button" onclick="unlockYesterday(this, 'elec_wbp')" class="shrink-0 text-[9px] px-1.5 py-0.5 rounded border border-slate-200 text-slate-500 hover:text-indigo-600 hover:border-indigo-300 hover:bg-indigo-50 transition" title="Edit nilai Yesterday (backfill data historis)">✏️</button>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <input type="number" step="0.01" min="0" readonly data-ykey="elec_wbp" value="{$_eWbpYFmt}" oninput="onYesterdayInput(this)"
                                           class="js-norm-dec _yesterdayInput w-[90px] text-right px-2 py-0.5 rounded border border-slate-200 bg-slate-100 text-slate-700 text-[10px] font-bold focus:outline-none focus:border-indigo-400 focus:bg-white">
                                    <span class="text-slate-700 shrink-0">kWh</span>
                                </div>
                            </div>
                            <div>
                                <p class="text-[9.5px] font-semibold text-slate-600 mb-0.5">Today</p>
                                <div class="relative">
                                    <input type="number" step="0.01" min="0" name="electricity_wbp" {$elecRequired} {$elecReadonly} oninput="calcTotals()"
                                        value="{$_eWbpTVal}"
                                        class="js-norm-dec w-full px-2.5 py-2 rounded-md border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all {$elecDisabledCls}">
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
                            <div class="flex items-center justify-between gap-2 text-[10px] font-semibold">
                                <div class="flex items-center gap-1 min-w-0 flex-1">
                                    <span class="text-slate-500 shrink-0">Yesterday ({$yDateLabelFmt})</span>
                                    <button type="button" onclick="unlockYesterday(this, 'elec_lwbp')" class="shrink-0 text-[9px] px-1.5 py-0.5 rounded border border-slate-200 text-slate-500 hover:text-indigo-600 hover:border-indigo-300 hover:bg-indigo-50 transition" title="Edit nilai Yesterday (backfill data historis)">✏️</button>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <input type="number" step="0.01" min="0" readonly data-ykey="elec_lwbp" value="{$_eLwbpYFmt}" oninput="onYesterdayInput(this)"
                                           class="js-norm-dec _yesterdayInput w-[90px] text-right px-2 py-0.5 rounded border border-slate-200 bg-slate-100 text-slate-700 text-[10px] font-bold focus:outline-none focus:border-indigo-400 focus:bg-white">
                                    <span class="text-slate-700 shrink-0">kWh</span>
                                </div>
                            </div>
                            <div>
                                <p class="text-[9.5px] font-semibold text-slate-600 mb-0.5">Today</p>
                                <div class="relative">
                                    <input type="number" step="0.01" min="0" name="electricity_lwbp" {$elecRequired} {$elecReadonly} oninput="calcTotals()"
                                        value="{$_eLwbpTVal}"
                                        class="js-norm-dec w-full px-2.5 py-2 rounded-md border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all {$elecDisabledCls}">
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

        <!-- ════════════════════════════════════════════════════════════════ -->
        <div class="my-6 flex items-center gap-3">
            <div class="flex-1 h-px bg-dashed bg-slate-300"></div>
            <span class="text-[10px] font-extrabold tracking-[0.2em] text-slate-500 bg-slate-50 border border-slate-200 rounded-full px-3 py-1">UTILITY — AIR (PDAM, DEEPWELL, MB, IRIGASI, DLL)</span>
            <div class="flex-1 h-px bg-dashed bg-slate-300"></div>
        </div>
        <!-- ② KONSUMSI AIR -->
        <div class="bg-white rounded-xl border-2 border-sky-200/80 overflow-hidden shadow-sm">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100 bg-sky-50/70">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-sky-50 text-sky-700 font-black text-[12px] mr-2">2</span><i class="fas fa-droplet mr-1.5 text-sky-600"></i>Konsumsi Air
                </h3>
                <div id="waterNotice" class="mt-2 text-[10.5px] px-2.5 py-1.5 rounded-md border <?= $isMalamNow ? 'bg-slate-50 text-slate-700 border-slate-200' : 'bg-white text-slate-600 border-slate-200' ?>">
                    <?php if ($isMalamNow): ?>
                        <i class="fas fa-moon mr-1 text-slate-500"></i><b>Malam</b> — Main Bld = (Today−Kemarin). Total masuk Cost.
                    <?php else: ?>
                        <i class="fas fa-circle-check mr-1 text-slate-500"></i><b>Pagi/Siang</b> — Bisa isi, Total = 0.
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-3 sm:p-4 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    <?php
                    $mbYesterday = 0;
                    if ($isMalamNow && $waterMbYesterdayLog) {
                        $mbYesterday = (float)($waterMbYesterdayLog['water_main_building'] ?? 0);
                    }
                    $mbToday = (float)($log['water_main_building'] ?? 0);
                    $mbCons = ($isMalamNow && $mbYesterday > 0 && $mbToday >= $mbYesterday) ? ($mbToday - $mbYesterday) : 0;
                    ?>
                    <div class="sm:col-span-2 md:col-span-3 lg:col-span-3">
                        <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Main Building (Meter)<span class="ml-1 text-slate-400 font-normal">Hari ini</span></label>
                        <div class="relative">
                            <input type="number" id="waterMainBuild" data-yesterday="<?= $mbYesterday ?>" step="0.01" min="0" name="water_main_building" oninput="calcTotals()"
                                value="<?= $log['water_main_building'] ?? '0.00' ?>"
                                class="js-norm-dec w-full px-3 py-2.5 rounded-lg border-2 border-dashed border-slate-300 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">m3</span>
                        </div>
                        <div class="mt-1.5 text-[10px] text-slate-600 space-y-1">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-1 min-w-0 flex-1">
                                    <span class="text-slate-500 shrink-0">Kemarin (<?= $yDateLabelFmt ?>):</span>
                                    <button type="button" onclick="unlockYesterday(this, 'water_mb')" class="shrink-0 text-[9px] px-1.5 py-0.5 rounded border border-slate-200 text-slate-500 hover:text-indigo-600 hover:border-indigo-300 hover:bg-indigo-50 transition" title="Edit nilai Yesterday (backfill data historis)">✏️</button>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <input type="number" step="0.01" min="0" readonly data-ykey="water_mb" value="<?= number_format($mbYesterday, 2) ?>" oninput="onYesterdayInput(this)"
                                           class="js-norm-dec _yesterdayInput w-[90px] text-right px-2 py-0.5 rounded border border-slate-200 bg-slate-100 text-slate-700 text-[10px] font-bold focus:outline-none focus:border-indigo-400 focus:bg-white">
                                    <span class="text-slate-700 shrink-0">m3</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">Selisih:</span>
                                <b id="mbSelisih" class="font-mono text-indigo-600"><?= number_format($mbCons, 2) ?> m3</b>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">Water PDAM</label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="water_pdam" oninput="calcTotals()"
                                value="<?= $log['water_pdam'] ?? '0.00' ?>"
                                class="js-norm-dec w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">m3</span>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    <?php
                    $waterFields = [
                        ['water_iki_gaban', 'Iki Gaban'],
                        ['water_deepwell_1', 'Deep Well 1'],
                        ['water_deepwell_2_brr', 'DW 2 BRR'],
                        ['water_deepwell_asean', 'DW ASEAN'],
                        ['water_deepwell_lpb', 'DW LPB'],
                        ['water_cooling_tower', 'Cooling Tower'],
                        ['water_bottling', 'Bottling'],
                        ['water_irrigation', 'Water Irrigation'],
                    ];
                    foreach ($waterFields as $wf) {
                        [$field, $label] = $wf;
                        $val = $log[$field] ?? '0.00';
                        echo <<<HTML
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">{$label}</label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="{$field}" oninput="calcTotals()" value="{$val}"
                                class="js-norm-dec w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">m3</span>
                        </div>
                    </div>
HTML;
                    }
                    ?>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">TOTAL AIR</label>
                    <div class="relative">
                        <input type="number" id="totalWater" readonly step="0.01" min="0" name="total_water_show"
                            value="<?= $log['total_water'] ?? '0.00' ?>"
                            class="w-full px-3 py-2.5 rounded-lg border-2 border-slate-700 bg-slate-800 text-sm font-bold text-white shadow-sm cursor-not-allowed opacity-95">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-white/85">m3</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════ -->
        <div class="my-6 flex items-center gap-3">
            <div class="flex-1 h-px bg-dashed bg-slate-300"></div>
            <span class="text-[10px] font-extrabold tracking-[0.2em] text-slate-500 bg-slate-50 border border-slate-200 rounded-full px-3 py-1">UTILITY — GAS LPG & LNG</span>
            <div class="flex-1 h-px bg-dashed bg-slate-300"></div>
        </div>
        <!-- ③ KONSUMSI GAS -->
        <div class="bg-white rounded-xl border-2 border-orange-200/80 overflow-hidden shadow-sm">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100 bg-orange-50/70">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-orange-50 text-orange-700 font-black text-[12px] mr-2">3</span><i class="fas fa-fire mr-1.5 text-orange-600"></i>Konsumsi Gas
                </h3>
            </div>
            <div class="p-3 sm:p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                <?php
                $gasFields = [
                    ['gas_lpg', T('form_gas_lpg', 'Gas LPG'), 'kg'],
                    ['gas_lng', T('form_gas_lng', 'Gas LNG'), 'kg']
                ];
                foreach ($gasFields as $gf) {
                    [$field, $label, $unit] = $gf;
                    $val = $log[$field] ?? '0.00';
                    echo <<<HTML
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">{$label}</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="{$field}" oninput="calcTotals()" value="{$val}"
                            class="js-norm-dec w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">{$unit}</span>
                    </div>
                </div>
HTML;
                }
                ?>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">TOTAL GAS</label>
                    <div class="relative">
                        <input type="number" id="totalGas" readonly step="0.01" min="0" name="total_gas_show"
                            value="<?= $log['total_gas'] ?? '0.00' ?>"
                            class="w-full px-3 py-2.5 rounded-lg border-2 border-slate-700 bg-slate-800 text-sm font-bold text-white shadow-sm cursor-not-allowed opacity-95">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-white/85">kg</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════ -->
        <div class="my-6 flex items-center gap-3">
            <div class="flex-1 h-px bg-dashed bg-slate-300"></div>
            <span class="text-[10px] font-extrabold tracking-[0.2em] text-slate-500 bg-slate-50 border border-slate-200 rounded-full px-3 py-1">DEDUCTION — TENANT / VENDOR (SWRO, 916, PT MAC, BIOSYSTEM)</span>
            <div class="flex-1 h-px bg-dashed bg-slate-300"></div>
        </div>
        <!-- ④ DEDUCTION -->
        <div class="bg-white rounded-xl border-2 border-slate-300 overflow-hidden shadow-sm">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100 bg-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-slate-100 text-slate-700 font-black text-[12px] mr-2">4</span><i class="fas fa-scissors mr-1.5 text-slate-600"></i>Deduction (Tenant / Vendor)
                </h3>
            </div>
            <div class="p-3 sm:p-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <?php
                function dedRow($label, $waterName, $waterVal, $kwhName, $kwhVal, $waterUnit = 'm3', $kwhUnit = 'kWh', $kwhOnly = false, $waterOnly = false) {
                    $html = '<div class="space-y-2">';
                    $html .= '<div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">' . $label . '</div>';
                    $html .= '<div class="grid grid-cols-' . ($kwhOnly ? '1' : ($waterOnly ? '1' : '2')) . ' gap-2.5">';
                    if (!$kwhOnly) {
                        $html .= '<div>
                            <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Water Meter</label>
                            <div class="relative">
                                <input type="number" step="0.01" min="0" name="' . $waterName . '" oninput="calcTotals()" value="' . $waterVal . '"
                                    class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">' . $waterUnit . '</span>
                            </div>
                        </div>';
                    }
                    if (!$waterOnly) {
                        $html .= '<div>
                            <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Electric kWh</label>
                            <div class="relative">
                                <input type="number" step="0.01" min="0" name="' . $kwhName . '" oninput="calcTotals()" value="' . $kwhVal . '"
                                    class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">' . $kwhUnit . '</span>
                            </div>
                        </div>';
                    }
                    $html .= '</div></div>';
                    return $html;
                }

                echo dedRow('SWRO', 'swro_watermeter', $log['swro_watermeter'] ?? '0.00', 'swro_kwh', $log['swro_kwh'] ?? '0.00');
                echo dedRow('916', 'ded_916_water', $log['ded_916_water'] ?? '0.00', 'ded_916_kwh', $log['ded_916_kwh'] ?? '0.00');
                echo dedRow('PT Mac', '', '', 'ded_ptmac_kwh', $log['ded_ptmac_kwh'] ?? '0.00', 'm3', 'kWh', true);
                echo dedRow('Biosystem', 'ded_biosystem_water', $log['ded_biosystem_water'] ?? '0.00', 'ded_biosystem_kwh', $log['ded_biosystem_kwh'] ?? '0.00');
                ?>
            </div>
            <div class="px-4 pb-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-[10.5px]">
                    <div class="px-3 py-2 rounded-lg bg-indigo-50/50 border border-indigo-100">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-slate-600">Net Deduction kWh</span>
                            <span id="netDedKwh" class="font-mono font-bold text-indigo-700">0.00</span>
                        </div>
                    </div>
                    <div class="px-3 py-2 rounded-lg bg-sky-50/50 border border-sky-100">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-slate-600">Net Deduction Water</span>
                            <span id="netDedWater" class="font-mono font-bold text-sky-700">0.00</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑤ CHILLER SYSTEM -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-cyan-50 text-cyan-700 font-black text-[12px] mr-2">5</span><i class="fas fa-snowflake mr-1.5 text-cyan-600"></i>Chiller System
                </h3>
            </div>
            <div class="p-3 sm:p-4 space-y-5">
                <!-- BLOK 1: CHILLER + CHWP -->
                <div class="space-y-4">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200 pb-1.5">BLOK 1 &mdash; Chiller Units + CHWP</div>
                    <div class="space-y-3">
                        <?php for ($i = 1; $i <= 3; $i++):
                            $cu = $eq['chiller']['chillers_units'][$i];
                            $chOnChecked = $cu['on'] ? 'checked' : '';
                            $legacyChOn = isset($log["chiller_{$i}_on"]) && $log["chiller_{$i}_on"] ? 'checked' : '';
                            if ($legacyChOn === 'checked') $chOnChecked = 'checked';
                        ?>
                        <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-[11px] font-bold text-slate-700">Chiller Unit <?= $i ?></span>
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="chillers_units_<?= $i ?>_on" <?= $chOnChecked ?>
                                        class="w-4 h-4 rounded border-slate-300 text-slate-800 focus:ring-slate-500" onchange="calcTotals()">
                                    <input type="hidden" name="chiller_<?= $i ?>_on" value="0">
                                    <input type="checkbox" name="chiller_<?= $i ?>_on" value="1" <?= $chOnChecked ?>
                                        class="w-4 h-4 rounded border-slate-300 text-slate-800 focus:ring-slate-500 sr-only" onchange="calcTotals()">
                                    <span class="text-[10.5px] font-semibold text-slate-600">ON</span>
                                </label>
                            </div>
                            <div class="grid grid-cols-3 gap-2.5">
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">pH</label>
                                    <input type="number" step="0.01" min="0" name="chillers_units_<?= $i ?>_ph" value="<?= $cu['ph'] ?>"
                                        class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">TDS</label>
                                    <input type="number" step="0.01" min="0" name="chillers_units_<?= $i ?>_tds" value="<?= $cu['tds'] ?>"
                                        class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pressure (kg/cm2)</label>
                                    <input type="number" step="0.01" min="0" name="chillers_units_<?= $i ?>_pressure" value="<?= $cu['pressure_kgcm2'] ?>"
                                        class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div class="space-y-3">
                        <?php for ($i = 1; $i <= 3; $i++):
                            $chwp = $eq['chiller']['chwp_units'][$i];
                        ?>
                        <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-[11px] font-bold text-slate-700">CHWP Unit <?= $i ?></span>
                            </div>
                            <div class="grid grid-cols-4 gap-2.5">
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Op Status</label>
                                    <select name="chwp_units_<?= $i ?>_op" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                        <?php
                                        $opOptions = ['off' => 'Off', '1' => 'Unit 1', '2' => 'Unit 2', '3' => 'Unit 3'];
                                        foreach ($opOptions as $opK => $opLbl) {
                                            $sel = $chwp['op'] === $opK ? 'selected' : '';
                                            echo "<option value=\"{$opK}\" {$sel}>{$opLbl}</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">pH</label>
                                    <input type="number" step="0.01" min="0" name="chwp_units_<?= $i ?>_ph" value="<?= $chwp['ph'] ?>"
                                        class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">TDS</label>
                                    <input type="number" step="0.01" min="0" name="chwp_units_<?= $i ?>_tds" value="<?= $chwp['tds'] ?>"
                                        class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pressure (kg/cm2)</label>
                                    <input type="number" step="0.01" min="0" name="chwp_units_<?= $i ?>_pressure" value="<?= $chwp['pressure_kgcm2'] ?>"
                                        class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <hr class="border-slate-200 border-dashed">
                <!-- BLOK 2: COOLING TOWER + CWP -->
                <div class="space-y-4">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200 pb-1.5">BLOK 2 &mdash; Cooling Tower + CWP</div>
                    <div class="space-y-3">
                        <?php for ($i = 1; $i <= 3; $i++):
                            $ct = $eq['chiller']['cooling_towers'][$i];
                        ?>
                        <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-[11px] font-bold text-slate-700">Cooling Tower <?= $i ?></span>
                            </div>
                            <div class="grid grid-cols-2 gap-2.5">
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Op Status</label>
                                    <select name="cooling_towers_<?= $i ?>_op" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                        <?php
                                        $opOptions = ['off' => 'Off', '1' => 'Unit 1', '2' => 'Unit 2', '3' => 'Unit 3'];
                                        foreach ($opOptions as $opK => $opLbl) {
                                            $sel = $ct['op'] === $opK ? 'selected' : '';
                                            echo "<option value=\"{$opK}\" {$sel}>{$opLbl}</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Level Water (%)</label>
                                    <input type="number" step="0.01" min="0" max="100" name="cooling_towers_<?= $i ?>_level_pct" value="<?= $ct['level_pct'] ?>"
                                        class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div class="space-y-3">
                        <?php for ($i = 1; $i <= 3; $i++):
                            $cwp = $eq['chiller']['cwp_units'][$i];
                        ?>
                        <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-[11px] font-bold text-slate-700">CWP Unit <?= $i ?></span>
                            </div>
                            <div class="grid grid-cols-4 gap-2.5">
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Op Status</label>
                                    <select name="cwp_units_<?= $i ?>_op" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                        <?php
                                        $opOptions = ['off' => 'Off', '1' => 'Unit 1', '2' => 'Unit 2', '3' => 'Unit 3'];
                                        foreach ($opOptions as $opK => $opLbl) {
                                            $sel = $cwp['op'] === $opK ? 'selected' : '';
                                            echo "<option value=\"{$opK}\" {$sel}>{$opLbl}</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">pH</label>
                                    <input type="number" step="0.01" min="0" name="cwp_units_<?= $i ?>_ph" value="<?= $cwp['ph'] ?>"
                                        class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">TDS</label>
                                    <input type="number" step="0.01" min="0" name="cwp_units_<?= $i ?>_tds" value="<?= $cwp['tds'] ?>"
                                        class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pressure (kg/cm2)</label>
                                    <input type="number" step="0.01" min="0" name="cwp_units_<?= $i ?>_pressure" value="<?= $cwp['pressure_kgcm2'] ?>"
                                        class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑥ TRAFO -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-indigo-50 text-indigo-700 font-black text-[12px] mr-2">6</span><i class="fas fa-plug mr-1.5 text-indigo-600"></i>Trafo
                </h3>
            </div>
            <div class="p-3 sm:p-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <?php for ($i = 1; $i <= 2; $i++):
                    $tr = $eq['trafo']['units'][$i];
                ?>
                <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                    <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Unit Trafo <?= $i ?></div>
                    <div>
                        <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Temp (°C)</label>
                        <input type="number" step="0.01" min="0" name="trafo_units_<?= $i ?>_temp_c" value="<?= $tr['temp_c'] ?>"
                            class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                    </div>
                    <div>
                        <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Ampere LVDP</label>
                        <input type="number" step="0.01" min="0" name="trafo_units_<?= $i ?>_ampere_lvdp" value="<?= $tr['ampere_lvdp'] ?>"
                            class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                    </div>
                    <div>
                        <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Oil Level (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="trafo_units_<?= $i ?>_oil_level_pct" value="<?= $tr['oil_level_pct'] ?>"
                            class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- ⑦ GENSET -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-rose-50 text-rose-700 font-black text-[12px] mr-2">7</span><i class="fas fa-car-battery mr-1.5 text-rose-600"></i>Genset + Konsumsi Fuel
                </h3>
            </div>
            <div class="p-3 sm:p-4 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <?php for ($i = 1; $i <= 3; $i++):
                        $bg = $eq['genset']['battery_gen'][$i];
                    ?>
                    <div>
                        <label class="block text-[10.5px] font-semibold text-slate-700 mb-1.5">Battery Gen <?= $i ?> (Voltage)</label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="genset_battery_gen_<?= $i ?>" value="<?= $bg ?>"
                                class="js-norm-dec w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">V</span>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[10.5px] font-semibold text-slate-700 mb-1.5">Konsumsi Fuel (Liter)</label>
                        <div class="relative">
                            <input type="number" id="fuelLiter" step="0.01" min="0" name="konsumsi_fuel_liter" oninput="calcTotals()"
                                value="<?= $eq['genset']['konsumsi_fuel_liter'] ?>"
                                class="js-norm-dec w-full px-3 py-2.5 rounded-lg border-2 border-dashed border-amber-300 bg-amber-50/30 text-sm font-bold text-slate-800 focus:outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-500/10 transition-all">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-amber-700">L</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10.5px] font-semibold text-slate-700 mb-1.5">Fuel Tank (Liter)</label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="genset_fuel_tank" value="<?= $eq['genset']['fuel_tank_liter'] ?>"
                                class="js-norm-dec w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9.5px] font-bold text-slate-500">L</span>
                        </div>
                    </div>
                </div>
                <div class="sm:hidden">
                    <div class="relative">
                        <input type="hidden" name="total_fuel" value="<?= $eq['genset']['konsumsi_fuel_liter'] ?>">
                        <input type="number" id="totalFuel" readonly step="0.01" min="0"
                            class="w-full px-3 py-2.5 rounded-lg border-2 border-slate-700 bg-slate-800 text-sm font-bold text-white shadow-sm cursor-not-allowed opacity-95"
                            value="<?= $eq['genset']['konsumsi_fuel_liter'] ?>">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-white/85">L</span>
                    </div>
                </div>
                <div class="hidden sm:block">
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5">TOTAL FUEL</label>
                    <div class="relative">
                        <input type="hidden" name="total_fuel" value="<?= $eq['genset']['konsumsi_fuel_liter'] ?>">
                        <input type="number" id="totalFuel" readonly step="0.01" min="0"
                            class="w-full px-3 py-2.5 rounded-lg border-2 border-slate-700 bg-slate-800 text-sm font-bold text-white shadow-sm cursor-not-allowed opacity-95"
                            value="<?= $eq['genset']['konsumsi_fuel_liter'] ?>">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-white/85">L</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑧ ELECTRIC STEAM BOILER -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-teal-50 text-teal-700 font-black text-[12px] mr-2">8</span><i class="fas fa-industry mr-1.5 text-teal-600"></i>Electric Steam Boiler
                </h3>
            </div>
            <div class="p-3 sm:p-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <?php for ($i = 1; $i <= 2; $i++):
                    $sb = $eq['steam_boiler']['units'][$i];
                    $opName = $i === 1 ? 'sb_unit_op' : 'sb_unit_op_2';
                    $pressName = $i === 1 ? 'steam_boiler_pressure' : 'steam_boiler_pressure_2';
                    $_sbOpCur = (string)$sb['op'];
                    if (!in_array($_sbOpCur, ['on','off'], true)) $_sbOpCur = ($_sbOpCur !== '' && $_sbOpCur !== 'off') ? 'on' : 'off';
                ?>
                <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                    <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Unit <?= $i ?></div>
                    <div>
                        <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Op Status</label>
                        <select name="<?= $opName ?>" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            <option value="on"  <?= $_sbOpCur === 'on'  ? 'selected' : '' ?>>ON</option>
                            <option value="off" <?= $_sbOpCur === 'off' ? 'selected' : '' ?>>OFF</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Steam Pressure (kg/cm2)</label>
                        <input type="number" step="0.01" min="0" name="<?= $pressName ?>" value="<?= $sb['steam_pressure_kgcm2'] ?>"
                            class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- ⑨ HOT WATER BOILER -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-orange-50 text-orange-700 font-black text-[12px] mr-2">9</span><i class="fas fa-radiator mr-1.5 text-orange-600"></i>Hot Water Boiler
                </h3>
            </div>
            <div class="p-3 sm:p-4 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php for ($i = 1; $i <= 2; $i++):
                        $hwb = $eq['hot_water_boiler']['units'][$i];
                        $opName = $i === 1 ? 'hwb_unit_op' : 'hwb_unit_op_2';
                        $tempName = $i === 1 ? 'hwb_temp' : 'hwb_temp_2';
                        $pressName = $i === 1 ? 'hwb_press' : 'hwb_press_2';
                        $_hwbOpCur = (string)$hwb['op'];
                        if (!in_array($_hwbOpCur, ['on','off'], true)) $_hwbOpCur = ($_hwbOpCur !== '' && $_hwbOpCur !== 'off') ? 'on' : 'off';
                    ?>
                    <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                        <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Unit <?= $i ?></div>
                        <div>
                            <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Op Status</label>
                            <select name="<?= $opName ?>" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                <option value="on"  <?= $_hwbOpCur === 'on'  ? 'selected' : '' ?>>ON</option>
                                <option value="off" <?= $_hwbOpCur === 'off' ? 'selected' : '' ?>>OFF</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Temperature (°C)</label>
                            <input type="number" step="0.01" min="0" name="<?= $tempName ?>" value="<?= $hwb['temperature_c'] ?>"
                                class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        </div>
                        <div>
                            <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pressure (kg/cm2)</label>
                            <input type="number" step="0.01" min="0" name="<?= $pressName ?>" value="<?= $hwb['pressure_kgcm2'] ?>"
                                class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50">
                    <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1 mb-2.5">Pump Circulation (Multi Select)</div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <?php
                        $hwbCircArr = $eq['hot_water_boiler']['circ_pump_units'] ?? [];
                        for ($i = 1; $i <= 4; $i++):
                            $checked = in_array($i, $hwbCircArr, true) ? 'checked' : '';
                        ?>
                        <label class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white border border-slate-200 cursor-pointer hover:bg-slate-50 transition-colors">
                            <input type="checkbox" name="hwb_circ_pump_<?= $i ?>" value="1" <?= $checked ?>
                                class="w-4 h-4 rounded border-slate-300 text-slate-800 focus:ring-slate-500">
                            <span class="text-[10.5px] font-semibold text-slate-700">Circ Pump <?= $i ?></span>
                        </label>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑩ HEAT PUMP -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-emerald-50 text-emerald-700 font-black text-[12px] mr-2">10</span><i class="fas fa-temperature-arrow-up mr-1.5 text-emerald-600"></i>Heat Pump
                </h3>
            </div>
            <div class="p-3 sm:p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                <?php for ($i = 1; $i <= 3; $i++):
                    $hp = $eq['heat_pump']['units'][$i];
                    $opName = "hp_unit_op_{$i}";
                    $tempName = "hp_temp_{$i}";
                    $pressName = "hp_press_{$i}";
                    if ($i === 1) { $opName = 'hp_unit_op'; $tempName = 'hp_temp'; $pressName = 'hp_press'; }
                    $_hpOpCur = (string)$hp['op'];
                    if (!in_array($_hpOpCur, ['on','off'], true)) $_hpOpCur = ($_hpOpCur !== '' && $_hpOpCur !== 'off') ? 'on' : 'off';
                ?>
                <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                    <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Unit <?= $i ?></div>
                    <div>
                        <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Op Status</label>
                        <select name="<?= $opName ?>" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            <option value="on"  <?= $_hpOpCur === 'on'  ? 'selected' : '' ?>>ON</option>
                            <option value="off" <?= $_hpOpCur === 'off' ? 'selected' : '' ?>>OFF</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Temperature (°C)</label>
                        <input type="number" step="0.01" min="0" name="<?= $tempName ?>" value="<?= $hp['temperature_c'] ?>"
                            class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                    </div>
                    <div>
                        <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pressure (kg/cm2)</label>
                        <input type="number" step="0.01" min="0" name="<?= $pressName ?>" value="<?= $hp['pressure_kgcm2'] ?>"
                            class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- ⑪ PUMP ROOM -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-blue-50 text-blue-700 font-black text-[12px] mr-2">11</span><i class="fas fa-water mr-1.5 text-blue-600"></i>Pump Room
                </h3>
            </div>
            <div class="p-3 sm:p-4 space-y-4">
                <!-- ROW A: Ground Tank + Sand Filter + SF Pump (side-by-side) -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Ground Tank -->
                    <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                        <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Ground Tank</div>
                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Raw Tank</label>
                                <input type="text" name="pump_tank_raw" value="<?= $eq['pump']['tank_raw'] ?? '' ?>"
                                    class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Treated Tank</label>
                                <input type="text" name="pump_tank_treated" value="<?= $eq['pump']['tank_treated'] ?? '' ?>"
                                    class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Irrigation Tank</label>
                                <input type="text" name="pump_tank_irigation" value="<?= $eq['pump']['tank_irigation'] ?? '' ?>"
                                    class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Reservoir Tank</label>
                                <input type="text" name="pump_tank_reservoir" value="<?= $eq['pump']['tank_reservoir'] ?? '' ?>"
                                    class="w-full px-2.5 py-2 rounded-lg border-2 border-dashed border-sky-300 bg-sky-50/30 text-sm font-bold text-slate-800 focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/10 transition-all">
                            </div>
                        </div>
                    </div>
                    <!-- Sand Filter + SF Pump side-by-side -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <!-- Sand Filter -->
                        <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                            <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Sand Filter</div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Status</label>
                                <select name="pump_sand_status" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $statOpts = ['backwash' => 'Backwash', 'rinse' => 'Rinse', 'filter' => 'Filter', 'off' => 'Off'];
                                    $cur = $eq['pump']['sand_status'] ?? 'filter';
                                    foreach ($statOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Press Sand</label>
                                <input type="number" step="0.01" min="0" name="pump_sand_press_sand" value="<?= $eq['pump']['sand_press_sand'] ?? 0 ?>"
                                    class="js-norm-dec w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Press Carbon</label>
                                <input type="number" step="0.01" min="0" name="pump_sand_press_carbon" value="<?= $eq['pump']['sand_press_carbon'] ?? 0 ?>"
                                    class="js-norm-dec w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                        </div>
                        <!-- SF Pump -->
                        <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                            <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">SF Pump</div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Status</label>
                                <select name="pump_sfpump_status" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $statOpts = ['on' => 'On', 'off' => 'Off'];
                                    $cur = $eq['pump']['sfpump_status'] ?? 'off';
                                    foreach ($statOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Unit Op</label>
                                <select name="pump_sfpump_unit_op" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $opOpts = ['1' => 'Unit 1', '2' => 'Unit 2', '3' => 'Unit 3', 'off' => 'Off'];
                                    $cur = $eq['pump']['sfpump_unit_op'] ?? '1';
                                    foreach ($opOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pressure</label>
                                <input type="number" step="0.01" min="0" name="pump_sfpump_pressure" value="<?= $eq['pump']['sfpump_pressure'] ?? 0 ?>"
                                    class="js-norm-dec w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                        </div>
                    </div>
                </div>
                <!-- ROW B: Hydrant Pump + Jockey Pump side-by-side -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Hydrant Pump -->
                    <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                        <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Hydrant Pump</div>
                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Status</label>
                                <select name="pump_hydrant_status" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $statOpts = ['on' => 'On', 'off' => 'Off'];
                                    $cur = $eq['pump']['hydrant_status'] ?? 'off';
                                    foreach ($statOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Unit Op</label>
                                <select name="pump_hydrant_unit_op" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $opOpts = ['1' => 'Unit 1', '2' => 'Unit 2', '3' => 'Unit 3', 'off' => 'Off'];
                                    $cur = $eq['pump']['hydrant_unit_op'] ?? '1';
                                    foreach ($opOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pressure</label>
                                <input type="number" step="0.01" min="0" name="pump_hydrant_pressure" value="<?= $eq['pump']['hydrant_pressure'] ?? 0 ?>"
                                    class="js-norm-dec w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                        </div>
                    </div>
                    <!-- Jockey Pump -->
                    <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                        <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Jockey Pump</div>
                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Status</label>
                                <select name="pump_jockey_status" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $statOpts = ['on' => 'On', 'off' => 'Off'];
                                    $cur = $eq['pump']['jockey_status'] ?? 'off';
                                    foreach ($statOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Unit Op</label>
                                <select name="pump_jockey_unit_op" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $opOpts = ['1' => 'Unit 1', '2' => 'Unit 2', '3' => 'Unit 3', 'off' => 'Off'];
                                    $cur = $eq['pump']['jockey_unit_op'] ?? '1';
                                    foreach ($opOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pressure</label>
                                <input type="number" step="0.01" min="0" name="pump_jockey_pressure" value="<?= $eq['pump']['jockey_pressure'] ?? 0 ?>"
                                    class="js-norm-dec w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Irrigation Pump (bottom single card — ONLY 1 block, correct spelling "irrigation") -->
                <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                    <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Irrigation Pump</div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                        <div>
                            <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Status</label>
                            <select name="pump_irrigation_status" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                <?php
                                $statOpts = ['on' => 'On', 'off' => 'Off'];
                                $cur = $eq['pump']['irrigation_status'] ?? 'off';
                                foreach ($statOpts as $k => $l) {
                                    $s = $cur === $k ? 'selected' : '';
                                    echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pressure</label>
                            <input type="number" step="0.01" min="0" name="pump_irrigation_pressure" value="<?= $eq['pump']['irrigation_pressure'] ?? 0 ?>"
                                class="js-norm-dec w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 12 RO SYSTEM (Reverse Osmosis) -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-slate-100 text-slate-700 font-black text-[12px] mr-2">12</span><i class="fas fa-water-ladder mr-1.5 text-sky-600"></i>RO System (Reverse Osmosis)
                </h3>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Water Meter (m3)</label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="ro_meter" value="<?= $eq['ro']['meter'] ?? '' ?>"
                                class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">m3</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Water Permeate (m3/jam)</label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="ro_permeate" value="<?= $eq['ro']['permeate'] ?? '' ?>"
                                class="js-norm-dec w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-bold text-slate-500">m3/h</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10.5px] font-medium text-slate-600 mb-1">TDS / pH Permeate</label>
                        <input type="text" name="ro_test_permeate" value="<?= $eq['ro']['test_permeate'] ?? '' ?>"
                            class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all" placeholder="mis. 50 / 7.2">
                    </div>
                    <div>
                        <label class="block text-[10.5px] font-medium text-slate-600 mb-1">TDS / pH Deep Well</label>
                        <input type="text" name="ro_test_deepwell" value="<?= $eq['ro']['test_deepwell'] ?? '' ?>"
                            class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all" placeholder="mis. 250 / 7.0">
                    </div>
                </div>
            </div>
        </div>

        <!-- 13 POOL SYSTEM -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-slate-100 text-slate-700 font-black text-[12px] mr-2">13</span><i class="fas fa-swimmer mr-1.5 text-cyan-600"></i>Pool System
                </h3>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php
                    $onOffOpts = ['on' => 'On', 'off' => 'Off'];
                    $autoOpts = ['auto' => 'Auto', 'manual' => 'Manual'];
                    $pump3Opts = ['1' => 'Unit 1', '2' => 'Unit 2', '3' => 'Unit 3', 'off' => 'Off'];
                    $pump7Opts = ['1' => 'Unit 1', '2' => 'Unit 2', '3' => 'Unit 3', '4' => 'Unit 4', '5' => 'Unit 5', '6' => 'Unit 6', '7' => 'Unit 7', 'off' => 'Off'];
                    ?>
                    <!-- (a) Lagoon 1 -->
                    <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                        <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Lagoon 1</div>
                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Alarm</label>
                                <select name="pool_l1_alarm" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $cur = $eq['pool']['l1_alarm'] ?? 'off';
                                    foreach ($onOffOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pump Running</label>
                                <select name="pool_l1_pump" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $cur = $eq['pool']['l1_pump'] ?? 'off';
                                    foreach ($pump3Opts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pressure Tank</label>
                                <input type="number" step="0.01" min="0" name="pool_l1_press" value="<?= $eq['pool']['l1_press'] ?? 0 ?>"
                                    class="js-norm-dec w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Submersible</label>
                                <select name="pool_l1_sub_auto" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $cur = $eq['pool']['l1_sub_auto'] ?? 'auto';
                                    foreach ($autoOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <!-- (b) Lagoon 2 -->
                    <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                        <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Lagoon 2</div>
                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Alarm</label>
                                <select name="pool_l2_alarm" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $cur = $eq['pool']['l2_alarm'] ?? 'off';
                                    foreach ($onOffOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pump Running</label>
                                <select name="pool_l2_pump" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $cur = $eq['pool']['l2_pump'] ?? 'off';
                                    foreach ($pump3Opts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pressure Tank</label>
                                <input type="number" step="0.01" min="0" name="pool_l2_press" value="<?= $eq['pool']['l2_press'] ?? 0 ?>"
                                    class="js-norm-dec w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Submersible</label>
                                <select name="pool_l2_sub_auto" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $cur = $eq['pool']['l2_sub_auto'] ?? 'auto';
                                    foreach ($autoOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <!-- (c) Aquavitale -->
                    <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                        <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Aquavitale</div>
                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Alarm</label>
                                <select name="pool_aqua_alarm" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $cur = $eq['pool']['aqua_alarm'] ?? 'off';
                                    foreach ($onOffOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Pump Running</label>
                                <select name="pool_aqua_pump" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $cur = $eq['pool']['aqua_pump'] ?? 'off';
                                    foreach ($pump7Opts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">HWB Temp (&deg;C)</label>
                                <input type="number" step="0.01" min="0" name="pool_aqua_hwbtemp" value="<?= $eq['pool']['aqua_hwbtemp'] ?? 0 ?>"
                                    class="js-norm-dec w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Submersible</label>
                                <select name="pool_aqua_sub_auto" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $cur = $eq['pool']['aqua_sub_auto'] ?? 'auto';
                                    foreach ($autoOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <!-- (d) Main Pump Room -->
                    <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                        <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1">Main Pump Room</div>
                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Alarm</label>
                                <select name="pool_mpr_alarm" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $cur = $eq['pool']['mpr_alarm'] ?? 'off';
                                    foreach ($onOffOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Submersible</label>
                                <select name="pool_mpr_sub_auto" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    $cur = $eq['pool']['mpr_sub_auto'] ?? 'auto';
                                    foreach ($autoOpts as $k => $l) {
                                        $s = $cur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php unset($onOffOpts, $autoOpts, $pump3Opts, $pump7Opts); ?>
                </div>
            </div>
        </div>

        <!-- 14 GAS SYSTEM (Monitoring Only / Req19) -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-slate-100 text-slate-700 font-black text-[12px] mr-2">14</span><i class="fas fa-gas-pump mr-1.5 text-orange-500"></i>Gas System Detector (Monitoring)
                </h3>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                    <?php
                    $valveOpts = ['open' => 'Open', 'close' => 'Close'];
                    $alarmOpts = ['on' => 'On', 'off' => 'Off'];
                    $gasDetectors = [
                        ['label' => 'Boneka Resto',    'vKey' => 'boneka_valve',    'aKey' => 'boneka_alarm',    'vName' => 'gas_boneka_valve',    'aName' => 'gas_boneka_alarm'],
                        ['label' => 'Main Kitchen',    'vKey' => 'mainkitchen_valve','aKey' => 'mainkitchen_alarm','vName' => 'gas_mainkitchen_valve','aName' => 'gas_mainkitchen_alarm'],
                        ['label' => 'Kayu Putih Resto','vKey' => 'kayuputih_valve', 'aKey' => 'kayuputih_alarm', 'vName' => 'gas_kayuputih_valve', 'aName' => 'gas_kayuputih_alarm'],
                        ['label' => 'Black Sand Pond', 'vKey' => 'bsp_valve',       'aKey' => 'bsp_alarm',       'vName' => 'gas_bsp_valve',       'aName' => 'gas_bsp_alarm'],
                        ['label' => 'HWB',             'vKey' => 'hwb_valve',       'aKey' => 'hwb_alarm',       'vName' => 'gas_hwb_valve',       'aName' => 'gas_hwb_alarm'],
                    ];
                    foreach ($gasDetectors as $gd):
                        $vCur = $eq['gas'][$gd['vKey']] ?? 'close';
                        $aCur = $eq['gas'][$gd['aKey']] ?? 'off';
                    ?>
                    <div class="p-3 rounded-lg border border-slate-200 bg-slate-50/50 space-y-2.5">
                        <div class="text-[11px] font-bold text-slate-700 border-b border-dashed border-slate-200 pb-1"><?= $gd['label'] ?></div>
                        <div class="space-y-2">
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Selenoid Valve</label>
                                <select name="<?= $gd['vName'] ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    foreach ($valveOpts as $k => $l) {
                                        $s = $vCur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10.5px] font-medium text-slate-600 mb-1">Alarm</label>
                                <select name="<?= $gd['aName'] ?>" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php
                                    foreach ($alarmOpts as $k => $l) {
                                        $s = $aCur === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; unset($gasDetectors, $valveOpts, $alarmOpts, $gd, $vCur, $aCur); ?>
                </div>
            </div>
        </div>

        <!-- ENGINEERING ACTIVITIES + Obstacles / Solutions + Photo -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-clipboard-list text-[11px]"></i></span>
                    Engineering Activities
                </h3>
            </div>
            <div class="p-3 sm:p-4 space-y-4">
                <!-- Activity Dynamic Rows -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-[11px] font-semibold text-slate-700">Daftar Aktivitas Hari Ini</label>
                        <button type="button" id="btnAddAct"
                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-[11px] font-bold bg-slate-800 text-white hover:bg-slate-700 active:bg-slate-900 transition-colors shadow-sm">
                            <i class="fas fa-plus text-[9.5px]"></i> Tambah Aktivitas
                        </button>
                    </div>
                    <div id="actRowsWrap" class="space-y-2">
                        <?php
                        // UI Category Order: PROJECT &rarr; OPERATION &rarr; MAINTENANCE &rarr; LANDSCAPE (Req20)
                        $actCatUIOrder = [
                            'project'     => 'PROJECT',
                            'operation'   => 'OPERATION',
                            'maintenance' => 'MAINTENANCE',
                            'landscape'   => 'LANDSCAPE',
                        ];
                        $existActIdx = 0;
                        if (!empty($existingActivities) && is_array($existingActivities)):
                            foreach ($existingActivities as $ea):
                                $idx = $existActIdx++;
                                $catVal = $ea['category'] ?? 'operation';
                                if (!isset($actCatUIOrder[$catVal])) $catVal = 'operation';
                                $titleVal = htmlspecialchars((string)($ea['activity_title'] ?? ''), ENT_QUOTES);
                        ?>
                        <div class="act-row flex flex-col sm:flex-row gap-2 items-stretch sm:items-center p-2.5 rounded-lg border border-slate-200 bg-slate-50/50">
                            <input type="hidden" name="act[<?= $idx ?>][id]" value="<?= (int)($ea['id'] ?? 0) ?>">
                            <div class="sm:w-44 shrink-0">
                                <select name="act[<?= $idx ?>][cat]" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-[11px] font-bold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php foreach ($actCatUIOrder as $k => $l):
                                        $s = $catVal === $k ? 'selected' : '';
                                        echo "<option value=\"{$k}\" {$s}>{$l}</option>";
                                    endforeach; ?>
                                </select>
                            </div>
                            <div class="flex-1 min-w-0">
                                <input type="text" name="act[<?= $idx ?>][t]" value="<?= $titleVal ?>" placeholder="Nama aktivitas..."
                                    class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                            <button type="button" class="act-del-btn shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg border border-slate-200 bg-white text-slate-500 hover:bg-slate-800 hover:text-white hover:border-slate-800 transition-all" title="Hapus baris ini">
                                <i class="fas fa-trash-can text-[11px]"></i>
                            </button>
                        </div>
                        <?php
                            endforeach;
                        endif;
                        // Minimal 1 row kosong jika belum ada
                        if ($existActIdx === 0):
                            $idx = 0;
                        ?>
                        <div class="act-row flex flex-col sm:flex-row gap-2 items-stretch sm:items-center p-2.5 rounded-lg border border-slate-200 bg-slate-50/50">
                            <input type="hidden" name="act[<?= $idx ?>][id]" value="0">
                            <div class="sm:w-44 shrink-0">
                                <select name="act[<?= $idx ?>][cat]" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-[11px] font-bold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                                    <?php foreach ($actCatUIOrder as $k => $l): ?>
                                        <option value="<?= $k ?>"><?= $l ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex-1 min-w-0">
                                <input type="text" name="act[<?= $idx ?>][t]" value="" placeholder="Nama aktivitas..."
                                    class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">
                            </div>
                            <button type="button" class="act-del-btn shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg border border-slate-200 bg-white text-slate-500 hover:bg-slate-800 hover:text-white hover:border-slate-800 transition-all" title="Hapus baris ini">
                                <i class="fas fa-trash-can text-[11px]"></i>
                            </button>
                        </div>
                        <?php endif;
                        unset($actCatUIOrder, $existActIdx, $ea, $idx, $catVal, $titleVal);
                        ?>
                    </div>
                </div>

                <hr class="border-slate-200 border-dashed">

                <!-- Obstacles + Solutions -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-700 mb-1.5"><i class="fas fa-triangle-exclamation mr-1 text-slate-500"></i>Kendala / Obstacles</label>
                        <textarea name="obstacles" rows="4" placeholder="Tuliskan kendala/hambatan yang ditemukan selama shift..."
                            class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-[12px] font-medium text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all resize-none"><?= $log['obstacles'] ?? '' ?></textarea>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-700 mb-1.5"><i class="fas fa-lightbulb mr-1 text-slate-500"></i>Solusi / Solutions</label>
                        <textarea name="solutions" rows="4" placeholder="Tuliskan solusi/tindakan perbaikan yang dilakukan..."
                            class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-[12px] font-medium text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all resize-none"><?= $log['solutions'] ?? '' ?></textarea>
                    </div>
                </div>

                <!-- Photo Upload -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1.5"><i class="fas fa-camera mr-1 text-slate-500"></i>Upload Foto Dokumentasi</label>
                    <label class="block cursor-pointer group">
                        <div class="mt-1 w-full rounded-xl border-2 border-dashed border-slate-300 hover:border-slate-400 hover:bg-slate-50 bg-slate-50/50 px-3 sm:px-4 py-4 sm:py-6 text-center transition">
                            <i class="fas fa-cloud-arrow-up text-slate-400 group-hover:text-slate-500 text-2xl sm:text-3xl mb-1.5 sm:mb-2 block"></i>
                            <div id="uplFileName" class="text-[11px] sm:text-xs font-bold text-slate-700 mb-1">
                                Pilih file foto dokumentasi
                            </div>
                            <div class="text-[10px] sm:text-[11px] text-slate-500">
                                Format: JPG, PNG, WEBP. Maks 1 file per submit.
                            </div>
                            <input type="file" name="photo" id="photo" accept="image/jpeg,image/png,image/webp"
                                   class="sr-only" onchange="const fn=document.getElementById('uplFileName'); if(fn){const n=(this.files&&this.files[0])?this.files[0].name:'Pilih file foto dokumentasi'; fn.innerHTML = (n!=='Pilih file foto dokumentasi') ? n + ' <i class=\'fas fa-check text-emerald-600 ml-1\'></i>' : n;}">
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <!-- STICKY BOTTOM ACTION BAR (MERGED: Grand Total + Action Buttons) -->
        <div class="fixed bottom-0 z-40 left-0 md:left-[272px] right-0
                    bg-slate-900/95 backdrop-blur-md text-white
                    shadow-[0_-6px_24px_rgba(15,23,42,0.22)]
                    border-t border-slate-700/70
                    px-3 sm:px-4 md:px-6 lg:px-8
                    py-2 sm:py-2.5 md:py-3 print:hidden">
            <div class="page-shell page-shell--7xl mx-auto">
                <!-- GRAND TOTAL SUMMARY ROW (mobile friendly, 1 line, 4 utility pills) -->
                <div class="hidden sm:flex items-center justify-between gap-2 mb-2 pb-2 border-b border-slate-700/60">
                    <div class="text-[10px] md:text-[11px] font-bold uppercase tracking-wider text-slate-300 flex items-center gap-1.5">
                        <i class="fas fa-calculator mr-1 text-amber-400"></i>Grand Total Cost
                    </div>
                    <div class="text-right">
                        <div id="mobileTotGrand" class="text-[14px] sm:text-[16px] md:text-lg font-black text-amber-400 leading-tight">Rp 0</div>
                    </div>
                </div>
                <!-- small mobile: compact summary pills 4 tiny -->
                <div class="flex sm:hidden items-center gap-1.5 mb-2 pb-2 border-b border-slate-700/60 overflow-x-auto [scrollbar-width:none] [-ms-overflow-style:none]">
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-slate-800 shrink-0">
                        <i class="fas fa-bolt text-amber-400 text-[9px]"></i><span id="mTotElec" class="text-[10px] font-bold text-slate-200">0</span>
                    </span>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-slate-800 shrink-0">
                        <i class="fas fa-droplet text-sky-400 text-[9px]"></i><span id="mTotWat" class="text-[10px] font-bold text-slate-200">0</span>
                    </span>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-slate-800 shrink-0">
                        <i class="fas fa-fire text-orange-400 text-[9px]"></i><span id="mTotGas" class="text-[10px] font-bold text-slate-200">0</span>
                    </span>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-slate-800 shrink-0">
                        <i class="fas fa-gas-pump text-rose-400 text-[9px]"></i><span id="mTotFuel" class="text-[10px] font-bold text-slate-200">0</span>
                    </span>
                    <span class="ml-auto inline-flex items-center gap-1 px-2 py-1 rounded-md bg-amber-500/20 shrink-0">
                        <i class="fas fa-money-bill-wave text-amber-400 text-[9px]"></i><span id="mTotGrand" class="text-[11px] font-black text-amber-300">Rp 0</span>
                    </span>
                </div>
                <!-- MAIN ACTION BAR CONTENT -->
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2 sm:gap-3">
                    <!-- Left: meta (form title + date) -->
                    <div class="hidden md:block shrink-0 text-left">
                        <div class="text-[11px] font-black uppercase tracking-wider text-slate-300 leading-tight">Form Daily Log Engineering</div>
                        <div class="text-[11px] text-slate-400 leading-tight mt-0.5">
                            <span id="metaDate"></span> &bull; <span id="metaUser"></span>
                        </div>
                    </div>
                    <!-- Center: mobile status + cancel -->
                    <div class="flex sm:hidden items-center gap-2 w-full">
                        <a href="<?= BASE_URL ?>engineer/select_date.php"
                           class="w-[44%] text-center inline-flex items-center justify-center gap-1 px-3 py-2.5 rounded-xl bg-slate-700/80 hover:bg-slate-700 text-white text-[12px] font-bold transition shrink-0">
                            <i class="fas fa-times text-[11px]"></i> Batal
                        </a>
                        <div class="flex-1 text-left">
                            <div class="text-[10px] font-black uppercase tracking-wider text-slate-300 leading-tight">Daily Log</div>
                            <div id="mobileDate" class="text-[11px] text-slate-400 leading-tight mt-0.5"></div>
                        </div>
                        <button type="submit" name="save_log" value="1" id="btnSaveForm"
                                class="w-[44%] text-center inline-flex items-center justify-center gap-1 px-3 py-2.5 rounded-xl bg-gradient-to-br from-slate-700 to-slate-800 hover:from-slate-600 hover:to-slate-700 text-white text-[12px] font-black shadow-lg shadow-slate-900/30 transition shrink-0">
                            <i class="fas fa-cloud-arrow-up text-[11px]"></i> Submit
                        </button>
                    </div>
                    <!-- sm+: buttons row (right side) -->
                    <div class="hidden sm:flex items-center justify-end gap-2.5 w-full sm:w-auto">
                        <a href="<?= BASE_URL ?>engineer/select_date.php"
                           class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-xl bg-slate-700/80 hover:bg-slate-700 text-white text-[13px] font-bold transition">
                            <i class="fas fa-times"></i> Batal
                        </a>
                        <button type="submit" name="save_log" value="1" id="btnSaveFormDesk"
                                class="inline-flex items-center justify-center gap-1.5 px-5 py-2.5 rounded-xl bg-gradient-to-br from-slate-700 to-slate-800 hover:from-slate-600 hover:to-slate-700 text-white text-[13px] font-black shadow-xl shadow-slate-900/35 transition">
                            <i class="fas fa-cloud-arrow-up"></i> Submit Daily Log
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    // =====================================
    // (a) Req 1 + Global Kalkulator LIVE
    // =====================================
    window.Y_ELEC_WBP  = parseFloat(<?= json_encode($yElecWbpJs) ?>) || 0;
    window.Y_ELEC_LWBP = parseFloat(<?= json_encode($yElecLwbpJs) ?>) || 0;
    window.Y_WATER_MB  = parseFloat(<?= json_encode($yWaterMbJs) ?>) || 0;
    window.SHIFT_MALAM = <?= ($curShiftVal === 'malam') ? 'true' : 'false' ?>;

    // Tarif snapshot dari PHP (gunakan $tarifForJs karena $tDef sudah di-unset)
    window.TARIF = {
        elec_wbp:  parseInt(<?= json_encode((int)($tarifForJs['electricity_wbp_per_kwh'] ?? 1850)) ?>, 10) || 1850,
        elec_lwbp: parseInt(<?= json_encode((int)($tarifForJs['electricity_lwbp_per_kwh'] ?? 1200)) ?>, 10) || 1200,
        water:     parseInt(<?= json_encode((int)($tarifForJs['water_per_m3'] ?? 9600)) ?>, 10) || 9600,
        gas:       parseInt(<?= json_encode((int)($tarifForJs['gas_per_kg'] ?? 24500)) ?>, 10) || 24500,
        fuel:      parseInt(<?= json_encode((int)($tarifForJs['fuel_per_liter'] ?? 17450)) ?>, 10) || 17450,
    };

    // Rupiah formatter
    const rpFmt = new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0
    });
    const rpFmtRaw = function (n) {
        const raw = rpFmt.format(n || 0);
        return raw.replace(/^Rp\s*/, '').replace(/,00$/, '');
    };
    const numFmt2 = function (n) {
        return (parseFloat(n) || 0).toFixed(2);
    };

    // Helper: panggil setiap user edit Yesterday input (yang sudah di-unlock).
    // 2 efek: (a) update window.Y_* global agar calcTotals hitung live.
    //         (b) set input hidden PHP POST handler agar SAVE di DB pakai override kemarin user.
    window.onYesterdayInput = function (inp) {
        if (!inp) return;
        const key = inp.getAttribute('data-ykey') || '';
        const v   = parseFloat(window.normDecStr(inp.value || '0')) || 0;
        if (key === 'elec_wbp') {
            window.Y_ELEC_WBP = v;
            const h = document.getElementById('_yOverrideElecWbp'); if (h) h.value = String(v);
        } else if (key === 'elec_lwbp') {
            window.Y_ELEC_LWBP = v;
            const h = document.getElementById('_yOverrideElecLwbp'); if (h) h.value = String(v);
        } else if (key === 'water_mb') {
            window.Y_WATER_MB = v;
            const h = document.getElementById('_yOverrideWaterMb'); if (h) h.value = String(v);
        }
        try { window.calcTotals(); } catch (e) {}
    };

    // Helper: unlock Yesterday input (untuk backfill data historis / tahun lalu).
    //  args: btn = tombol yang diklik
    //        key = 'elec_wbp' / 'elec_lwbp' / 'water_mb' (sama dengan atribut data-ykey input)
    window.unlockYesterday = function (btn, key) {
        if (!btn) return;
        const wrap = btn.closest('.flex') || btn.parentElement;
        if (!wrap) return;
        const inp = wrap.parentElement.querySelector('input._yesterdayInput');
        if (!inp) return;
        const wasLocked = inp.hasAttribute('readonly');
        if (wasLocked) {
            inp.removeAttribute('readonly');
            inp.classList.remove('bg-slate-100');
            inp.classList.add('bg-white', 'border-indigo-300', 'ring-2', 'ring-indigo-100');
            if (key) inp.setAttribute('data-ykey', key);
            if (btn) {
                btn.classList.remove('text-slate-500', 'border-slate-200');
                btn.classList.add('text-indigo-600', 'border-indigo-400', 'bg-indigo-50');
                btn.innerHTML = '🔒 Lock';
                btn.title = 'Kembali kunci (readonly) Yesterday';
            }
        } else {
            inp.setAttribute('readonly', 'readonly');
            inp.classList.add('bg-slate-100');
            inp.classList.remove('bg-white', 'border-indigo-300', 'ring-2', 'ring-indigo-100');
            // Jika di-lock KEMBALI → BERARTI user tidak ingin override lagi.
            // Clear hidden override value agar PHP POST handler kembali pakai auto Yesterday dari DB (SAFE DEFAULT).
            const k = inp.getAttribute('data-ykey') || key || '';
            if (k === 'elec_wbp')      { const h = document.getElementById('_yOverrideElecWbp'); if (h) h.value = ''; }
            else if (k === 'elec_lwbp'){ const h = document.getElementById('_yOverrideElecLwbp'); if (h) h.value = ''; }
            else if (k === 'water_mb') { const h = document.getElementById('_yOverrideWaterMb'); if (h) h.value = ''; }
            if (btn) {
                btn.classList.add('text-slate-500', 'border-slate-200');
                btn.classList.remove('text-indigo-600', 'border-indigo-400', 'bg-indigo-50');
                btn.innerHTML = '✏️';
                btn.title = 'Edit nilai Yesterday (backfill data historis)';
            }
        }
    };

    window.onShiftChange = function () {
        const sel = document.getElementById('shiftSelect');
        if (sel) {
            window.SHIFT_MALAM = (sel.value === 'malam');
        }
        // Notice update — SEMUA SHIFT hitung otomatis (tanpa gating)
        const en = document.getElementById('elecNotice');
        if (en) {
            en.className = 'mt-2 text-[10.5px] px-2.5 py-1.5 rounded-md border bg-slate-50 text-slate-700 border-slate-200';
            if (window.SHIFT_MALAM) {
                en.innerHTML = '<i class="fas fa-moon mr-1 text-slate-500"></i><b>Malam</b> &mdash; LWBP/WBP = (Today&minus;Kemarin) &times; 8.000. Auto-calc Live ✅';
            } else {
                en.innerHTML = '<i class="fas fa-sun mr-1 text-amber-500"></i><b>Pagi/Siang</b> &mdash; LWBP/WBP = (Today&minus;Kemarin) &times; 8.000. Auto-calc Live ✅';
            }
        }
        const wn = document.getElementById('waterNotice');
        if (wn) {
            wn.className = 'mt-2 text-[10.5px] px-2.5 py-1.5 rounded-md border bg-slate-50 text-slate-700 border-slate-200';
            if (window.SHIFT_MALAM) {
                wn.innerHTML = '<i class="fas fa-moon mr-1 text-slate-500"></i><b>Malam</b> &mdash; Main Building = (Today&minus;Kemarin) &times; 10. Auto-calc Live ✅';
            } else {
                wn.innerHTML = '<i class="fas fa-sun mr-1 text-amber-500"></i><b>Pagi/Siang</b> &mdash; Main Building = (Today&minus;Kemarin) &times; 10. Auto-calc Live ✅';
            }
        }
        calcTotals();
    };

    window.onTariffChange = function () {
        const tWbp  = document.getElementById('tarWbp');
        const tLwbp = document.getElementById('tarLwbp');
        const tW    = document.getElementById('tarWater');
        const tG    = document.getElementById('tarGas');
        const tF    = document.getElementById('tarFuel');
        if (tWbp)  window.TARIF.elec_wbp  = parseInt(tWbp.value,  10) || window.TARIF.elec_wbp;
        if (tLwbp) window.TARIF.elec_lwbp = parseInt(tLwbp.value, 10) || window.TARIF.elec_lwbp;
        if (tW)    window.TARIF.water     = parseInt(tW.value,    10) || window.TARIF.water;
        if (tG)    window.TARIF.gas       = parseInt(tG.value,    10) || window.TARIF.gas;
        if (tF)    window.TARIF.fuel      = parseInt(tF.value,    10) || window.TARIF.fuel;
    };

    function readF(name) {
        const el = document.querySelector('input[name="' + name + '"]');
        return el ? (parseFloat(normDecStr(el.value)) || 0) : 0;
    }

    window.calcTotals = function () {
        try {
        // --- Listrik --- (SEMUA SHIFT PAGI/SIANG/MALAM = AUTO HITUNG, TANPA GATING)
        const todayWbp  = readF('electricity_wbp');
        const todayLwbp = readF('electricity_lwbp');
        const eWbpCons  = Math.max(0, (todayWbp  - window.Y_ELEC_WBP))  * 8000;
        const eLwbpCons = Math.max(0, (todayLwbp - window.Y_ELEC_LWBP)) * 8000;
        const elecTotal = eWbpCons + eLwbpCons;
        const te = document.getElementById('totalElectricity');
        if (te) te.value = numFmt2(elecTotal);
        const ewC = document.getElementById('elecWbpCons');
        const elC = document.getElementById('elecLwbpCons');
        if (ewC) ewC.textContent = numFmt2(eWbpCons) + ' kWh';
        if (elC) elC.textContent = numFmt2(eLwbpCons) + ' kWh';

        // --- Water Main Building + PDAM + Sumber Lain --- (SEMUA SHIFT AUTO HITUNG)
        const wmb = document.getElementById('waterMainBuild');
        const wmbVal = wmb ? (parseFloat(normDecStr(wmb.value)) || 0) : 0;
        const waterMbCons = Math.max(0, (wmbVal - window.Y_WATER_MB)) * 10;
        // Tambah semua sumber air langsung: PDAM, Iki Gaban, DW, CT, Bottling, Irrigation
        const wPdam      = readF('water_pdam');
        const wIkiGaban  = readF('water_iki_gaban');
        const wDw1      = readF('water_deepwell_1');
        const wDw2Brr   = readF('water_deepwell_2_brr');
        const wDwAsean  = readF('water_deepwell_asean');
        const wDwLpb   = readF('water_deepwell_lpb');
        const wCoolingT = readF('water_cooling_tower');
        const wBottling = readF('water_bottling');
        const wIrrigation = readF('water_irrigation');
        const waterCons = waterMbCons + wPdam + wIkiGaban + wDw1 + wDw2Brr + wDwAsean + wDwLpb + wCoolingT + wBottling + wIrrigation;
        const tw = document.getElementById('totalWater');
        if (tw) tw.value = numFmt2(waterCons);
        const mbSelisih = document.getElementById('mbSelisih');
        if (mbSelisih) mbSelisih.textContent = numFmt2(waterMbCons);
        const wmc = document.getElementById('waterMainCons');
        if (wmc) wmc.textContent = numFmt2(waterCons) + ' m3';

        // --- Gas (LPG + LNG) ---
        const gLpg = readF('gas_lpg');
        const gLng = readF('gas_lng');
        const gasTotal = gLpg + gLng;
        const tg = document.getElementById('totalGas');
        if (tg) tg.value = numFmt2(gasTotal);

        // --- Fuel ---
        const fuelLiter = readF('konsumsi_fuel_liter');
        const tfl = document.getElementById('totalFuel');
        if (tfl) tfl.value = numFmt2(fuelLiter);
        const tfHidden = document.querySelector('input[name="total_fuel"]');
        if (tfHidden) tfHidden.value = numFmt2(fuelLiter);

        // --- Cost breakdown ---
        const cost_elec = (eWbpCons * window.TARIF.elec_wbp) + (eLwbpCons * window.TARIF.elec_lwbp);
        const cost_water = waterCons * window.TARIF.water;
        const cost_gas = gasTotal * window.TARIF.gas;
        const cost_fuel = fuelLiter * window.TARIF.fuel;
        const grandTotal = cost_elec + cost_water + cost_gas + cost_fuel;

        // --- Update LIVE SUMMARY PANEL (actual IDs from file HTML) ---
        const el = {
            kwh:        document.getElementById('sumKwh'),
            kwhCost:    document.getElementById('sumKwhCost'),
            water:      document.getElementById('sumWater'),
            waterCost:  document.getElementById('sumWaterCost'),
            gas:        document.getElementById('sumGas'),
            gasCost:    document.getElementById('sumGasCost'),
            fuel:       document.getElementById('sumFuel'),
            fuelCost:   document.getElementById('sumFuelCost'),
            grand:      document.getElementById('sumGrandTotal'),
            grandMob:   document.getElementById('sumGrandTotalMobile'),
            kwhMini:    document.getElementById('sumKwhMini'),
            waterMini:  document.getElementById('sumWaterMini'),
            gasMini:    document.getElementById('sumGasMini'),
            fuelMini:   document.getElementById('sumFuelMini'),
        };
        if (el.kwh)       el.kwh.textContent       = numFmt2(elecTotal);
        if (el.kwhCost)   el.kwhCost.textContent   = rpFmtRaw(cost_elec);
        if (el.water)     el.water.textContent     = numFmt2(waterCons);
        if (el.waterCost) el.waterCost.textContent = rpFmtRaw(cost_water);
        if (el.gas)       el.gas.textContent       = numFmt2(gasTotal);
        if (el.gasCost)   el.gasCost.textContent   = rpFmtRaw(cost_gas);
        if (el.fuel)      el.fuel.textContent      = numFmt2(fuelLiter);
        if (el.fuelCost)  el.fuelCost.textContent  = rpFmtRaw(cost_fuel);
        if (el.grand)     el.grand.textContent     = rpFmtRaw(grandTotal);
        if (el.grandMob)  el.grandMob.textContent  = rpFmtRaw(grandTotal);
        if (el.kwhMini)   el.kwhMini.textContent   = rpFmtRaw(cost_elec);
        if (el.waterMini) el.waterMini.textContent = rpFmtRaw(cost_water);
        if (el.gasMini)   el.gasMini.textContent   = rpFmtRaw(cost_gas);
        if (el.fuelMini)  el.fuelMini.textContent  = rpFmtRaw(cost_fuel);

        window._lastTots = {
            elecKwh: elecTotal || 0,
            watM3:   waterCons || 0,
            gasKg:   gasTotal || 0,
            fuelL:   fuelLiter || 0,
            grandRp: grandTotal || 0
        };
        const me = document.getElementById('mTotElec'),
              mw = document.getElementById('mTotWat'),
              mg = document.getElementById('mTotGas'),
              mf = document.getElementById('mTotFuel'),
              mgrdA = document.getElementById('mTotGrand'),
              mgrdB = document.getElementById('mobileTotGrand');
        if (me)    me.textContent    = numFmt2(window._lastTots.elecKwh);
        if (mw)    mw.textContent    = numFmt2(window._lastTots.watM3);
        if (mg)    mg.textContent    = numFmt2(window._lastTots.gasKg);
        if (mf)    mf.textContent    = numFmt2(window._lastTots.fuelL);
        if (mgrdA) mgrdA.textContent = 'Rp ' + rpFmtRaw(window._lastTots.grandRp);
        if (mgrdB) mgrdB.textContent = 'Rp ' + rpFmtRaw(window._lastTots.grandRp);
        } catch (e) {
            console.error('[calcTotals] ERROR:', e);
        }
    };

    // =====================================
    // (b) Req 6 JS NORMALIZATION (dot/comma)
    // =====================================
    function normDecStr(v) {
        if (v === null || v === undefined) return '';
        v = String(v).trim();
        if (v === '') return '';
        // Hanya angka, koma, titik, dan strip minus di depan
        const hasComma = v.indexOf(',') !== -1;
        const hasDot = v.indexOf('.') !== -1;
        if (hasComma && hasDot) {
            // Keduanya ada: yang posisi TERAKHIR = pemisah desimal
            const lastComma = v.lastIndexOf(',');
            const lastDot = v.lastIndexOf('.');
            if (lastComma > lastDot) {
                // EU style: dot = ribuan, comma = desimal
                v = v.replace(/\./g, '').replace(',', '.');
            } else {
                // US style: comma = ribuan, dot = desimal
                v = v.replace(/,/g, '');
            }
        } else if (hasComma && !hasDot) {
            // Hanya comma: jika ada 3 digit di belakang -> ribuan EU, else desimal
            const parts = v.split(',');
            if (parts.length === 2 && parts[1].length <= 2) {
                v = v.replace(',', '.');
            } else {
                v = v.replace(/,/g, '');
            }
        }
        // Sisakan satu dot
        const firstDot = v.indexOf('.');
        if (firstDot !== -1) {
            v = v.substring(0, firstDot + 1) + v.substring(firstDot + 1).replace(/\./g, '');
        }
        return v;
    }
    function normDec(el) {
        if (!el) return;
        const orig = el.value;
        const nv = normDecStr(orig);
        if (nv !== orig) { el.value = nv; }
        calcTotals();
    }
    window.normDec = normDec;
    window.normDecStr = normDecStr;

    // =====================================
    // (c)+(d) DOMContentLoaded: normDec attach + calcTotals initial + Activities dynamic rows
    // =====================================
    document.addEventListener('DOMContentLoaded', function () {
        // --- Attach normalization listeners --- (TAMBAH EVENT INPUT untuk HP realtime auto calc)
        function attachNormDec(root) {
            const scope = root || document;
            const nodes = scope.querySelectorAll('.js-norm-dec, input[type="number"][step][step!="1"]');
            nodes.forEach(function (n) {
                if (n.__normDecAttached) return;
                n.__normDecAttached = true;
                // INPUT: realtime ketik di HP/Desktop langsung hitung (TIDAK perlu blur/keluar field)
                n.addEventListener('input', function () { normDec(n); });
                // BLUR/CHANGE: fallback pastikan hitung ketika pindah field / selesai paste
                n.addEventListener('blur', function () { normDec(n); });
                n.addEventListener('change', function () { normDec(n); });
            });
        }
        attachNormDec(document);

        // --- Initial paint calc (pastikan SHIFT sync dulu baru hitung!) ---
        try { onShiftChange(); } catch (e) { /* noop */ }
        try { calcTotals(); } catch (e) { /* noop */ }
        // Fallback race-condition mobile-browser: re-hitung 100ms & 300ms setelah load
        //   (berguna untuk backfill data historical & Chrome Android/Safari iOS yg async paint)
        setTimeout(function(){ try { onShiftChange(); calcTotals(); } catch(e){} }, 100);
        setTimeout(function(){ try { onShiftChange(); calcTotals(); } catch(e){} }, 300);

        // --- Activity rows dynamic helper ---
        const CAT_OPTIONS_ORDER = ['project', 'operation', 'maintenance', 'landscape'];
        const CAT_LABELS = {
            project:     'PROJECT',
            operation:   'OPERATION',
            maintenance: 'MAINTENANCE',
            landscape:   'LANDSCAPE',
        };

        function computeNextIndex() {
            const rows = document.querySelectorAll('#actRowsWrap .act-row');
            if (!rows.length) return 0;
            let maxIdx = -1;
            rows.forEach(function (r) {
                const selects = r.querySelectorAll('select[name^="act["]');
                const inputs  = r.querySelectorAll('input[type="text"][name^="act["], input[type="hidden"][name^="act["]');
                const candidates = [];
                selects.forEach(function (s) { candidates.push(s.name); });
                inputs.forEach(function (i)  { candidates.push(i.name); });
                candidates.forEach(function (nm) {
                    const m = /^act\[(\d+)\]/.exec(nm);
                    if (m) {
                        const n = parseInt(m[1], 10);
                        if (n > maxIdx) maxIdx = n;
                    }
                });
            });
            return maxIdx + 1;
        }

        function renumberActivityRows() {
            const rows = document.querySelectorAll('#actRowsWrap .act-row');
            let idx = 0;
            rows.forEach(function (r) {
                const selects = r.querySelectorAll('select[name^="act["]');
                const inputs  = r.querySelectorAll('input[type="text"][name^="act["], input[type="hidden"][name^="act["]');
                selects.forEach(function (s) {
                    const m = /^act\[(\d+)\]\[(cat)\]$/.exec(s.name);
                    if (m) s.name = 'act[' + idx + '][cat]';
                });
                inputs.forEach(function (i) {
                    let m = /^act\[(\d+)\]\[(id)\]$/.exec(i.name);
                    if (m) { i.name = 'act[' + idx + '][id]'; return; }
                    m = /^act\[(\d+)\]\[(t)\]$/.exec(i.name);
                    if (m) { i.name = 'act[' + idx + '][t]'; return; }
                });
                idx++;
            });
        }

        function buildCatOptions(selectedKey) {
            if (!CAT_LABELS[selectedKey]) selectedKey = 'operation';
            let html = '';
            CAT_OPTIONS_ORDER.forEach(function (k) {
                const s = (k === selectedKey) ? ' selected' : '';
                html += '<option value="' + k + '"' + s + '>' + CAT_LABELS[k] + '</option>';
            });
            return html;
        }

        const btnAddAct = document.getElementById('btnAddAct');
        if (btnAddAct) {
            btnAddAct.addEventListener('click', function () {
                const wrap = document.getElementById('actRowsWrap');
                if (!wrap) return;
                const idx = computeNextIndex();
                const row = document.createElement('div');
                row.className = 'act-row flex flex-col sm:flex-row gap-2 items-stretch sm:items-center p-2.5 rounded-lg border border-slate-200 bg-slate-50/50';
                row.innerHTML =
                    '<input type="hidden" name="act[' + idx + '][id]" value="0">' +
                    '<div class="sm:w-44 shrink-0">' +
                        '<select name="act[' + idx + '][cat]" class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-[11px] font-bold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">' +
                            buildCatOptions('operation') +
                        '</select>' +
                    '</div>' +
                    '<div class="flex-1 min-w-0">' +
                        '<input type="text" name="act[' + idx + '][t]" value="" placeholder="Nama aktivitas..."' +
                            ' class="w-full px-2.5 py-2 rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-800 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10 transition-all">' +
                    '</div>' +
                    '<button type="button" class="act-del-btn shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg border border-slate-200 bg-white text-slate-500 hover:bg-slate-800 hover:text-white hover:border-slate-800 transition-all" title="Hapus baris ini">' +
                        '<i class="fas fa-trash-can text-[11px]"></i>' +
                    '</button>';
                wrap.appendChild(row);
                attachActDeleteHandler(row.querySelector('.act-del-btn'));
                renumberActivityRows();
            });
        }

        function attachActDeleteHandler(btn) {
            if (!btn || btn.__actDelAttached) return;
            btn.__actDelAttached = true;
            btn.addEventListener('click', function () {
                const wrap = document.getElementById('actRowsWrap');
                if (!wrap) return;
                const row = btn.closest('.act-row');
                if (!row) return;
                const allRows = wrap.querySelectorAll('.act-row');
                if (allRows.length <= 1) {
                    // Clear isi saja jika cuma satu
                    const ti = row.querySelector('input[type="text"][name^="act["]');
                    if (ti) ti.value = '';
                    const hi = row.querySelector('input[type="hidden"][name^="act["]');
                    if (hi) hi.value = '0';
                    const sel = row.querySelector('select[name^="act["]');
                    if (sel) sel.value = 'operation';
                    return;
                }
                row.remove();
                renumberActivityRows();
            });
        }

        // Pasang delete handler untuk semua row yang sudah ada di awal
        document.querySelectorAll('#actRowsWrap .act-del-btn').forEach(attachActDeleteHandler);
        // Pastikan nomor index berurutan dari 0
        renumberActivityRows();
    });
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const d = '<?= htmlspecialchars(formatDate($date)) ?>';
  const u = '<?= htmlspecialchars(($user['name'] ?? '')) ?>';
  ['metaDate','mobileDate'].forEach(function(id){const e=document.getElementById(id);if(e)e.textContent=d;});
  const mu = document.getElementById('metaUser'); if (mu) mu.textContent = u;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

