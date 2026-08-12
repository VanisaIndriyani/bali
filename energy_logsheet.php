<?php
/**
 * 📋 ENERGY LOG SHEET - Form Input & Daftar Catatan Konsumsi Energi Harian
 * Full CRUD: Tambah, Edit, Hapus, Filter, List Data REAL dari DB (table energy_logs)
 * Auto create table IF NOT EXISTS di awal — no manual migration needed.
 */

require_once __DIR__ . '/config/config.php';
requireLogin();

$db = Database::getInstance();

// ==============================================
// 🔧 AUTO CREATE TABLE ENERGY_LOGS (IF NOT EXISTS)
// ==============================================
$db->query("CREATE TABLE IF NOT EXISTS `energy_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `log_date` DATE NOT NULL,
    `shift` ENUM('pagi','siang','malam') NOT NULL DEFAULT 'pagi',
    `pln_lwbp_kwh` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `pln_wbp_kwh` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `pln_kwh` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `genset_kwh` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `solar_liter` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `gas_kg` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `gas_lng_kg` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `air_m3` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `air_deep_well_m3` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `pic_name` VARCHAR(180) NOT NULL DEFAULT '',
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_log_date` (`log_date`),
    INDEX `idx_shift` (`shift`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ==============================================
// 🔧 AUTO CREATE TABLE EQUIPMENT_LOGS (IF NOT EXISTS)
// ==============================================
$db->query("CREATE TABLE IF NOT EXISTS `equipment_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `log_date` DATE NOT NULL,
    `shift` ENUM('pagi','siang','malam') NOT NULL DEFAULT 'pagi',
    `section_data` LONGTEXT NULL,
    `pic_name` VARCHAR(180) NOT NULL DEFAULT '',
    `notes` TEXT NULL,
    `status` ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'approved',
    `created_by` INT UNSIGNED NULL,
    `updated_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_log_date` (`log_date`),
    INDEX `idx_shift` (`shift`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ==============================================
// 🔧 MIGRATION: ADD MISSING COLUMNS IF NOT EXISTS (LWBP/WBP/LNG/Deep Well + Equipment ID)
// ==============================================
$_mig = function() use ($db) {
    $cols = [];
    try {
        $rows = $db->fetchAll("SHOW COLUMNS FROM energy_logs");
        foreach ($rows as $r) $cols[strtolower($r['Field'])] = true;
    } catch (Throwable $e) { return; }
    $adds = [];
    if (!isset($cols['pln_lwbp_kwh']))      $adds[] = "ADD COLUMN `pln_lwbp_kwh` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `shift`";
    if (!isset($cols['pln_wbp_kwh']))       $adds[] = "ADD COLUMN `pln_wbp_kwh` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `pln_lwbp_kwh`";
    if (!isset($cols['gas_lng_kg']))        $adds[] = "ADD COLUMN `gas_lng_kg` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `gas_kg`";
    if (!isset($cols['air_deep_well_m3']))  $adds[] = "ADD COLUMN `air_deep_well_m3` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `air_m3`";
    if (!isset($cols['equipment_id']))      $adds[] = "ADD COLUMN `equipment_id` INT UNSIGNED NULL DEFAULT NULL AFTER `air_deep_well_m3`";
    if (count($adds) > 0) {
        try { $db->query("ALTER TABLE `energy_logs` " . implode(", ", $adds)); } catch (Throwable $e) {}
    }
};
$_mig();

// ==============================================
// 🧾 HANDLE ACTION: INSERT / UPDATE / DELETE
// ==============================================
$flashMsg = '';
$flashType = '';

$user = currentUser() ?: ['id' => 0, 'name' => 'User', 'role' => 'guest'];
$userId = (int)($user['id'] ?? 0);
$userName = trim((string)($user['name'] ?? 'User'));
$userRole = (string)($user['role'] ?? 'engineer');
/* Fallback extra-safe (hindari Warning "Undefined variable $userRole" jika variabel dihapus): */
if (!isset($userRole) || trim($userRole) === '') $userRole = 'engineer';

function buildSectionData() {
    $sectionData = [
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
                'unit_op' => (string)$_POST['pump_sb_unit_op'] ?? 'off',
                'sb1_running_hours' => (string)($_POST['pump_sb1_hours'] ?? ''),
                'sb2_running_hours' => (string)($_POST['pump_sb2_hours'] ?? ''),
                'water_test_tds_ph' => (string)($_POST['pump_sb_test'] ?? ''),
                'steam_pressure_kgcm2' => (string)($_POST['pump_sb_press'] ?? ''),
                'time_blow_down' => (string)($_POST['pump_sb_blow'] ?? ''),
                'econ_temp_c' => (string)($_POST['pump_sb_econ_temp'] ?? ''),
                'econ_press_psi_kgcm2' => (string)($_POST['pump_sb_econ_press'] ?? '')
            ],
            'hot_water_boiler' => [
                'unit_op' => (string)$_POST['pump_hwb_unit_op'] ?? 'off',
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
                'unit_standby_auto' => (string)$_POST['pump_hyd_standby'] ?? 'auto',
                'press_pump1' => (string)($_POST['pump_hyd_press1'] ?? ''),
                'press_pump2' => (string)($_POST['pump_hyd_press2'] ?? '')
            ],
            'jockey_pump' => [
                'standby_press_kgcm2' => (string)($_POST['pump_jockey_press'] ?? '')
            ],
            'sand_filter' => [
                'status' => (string)$_POST['pump_sf_status'] ?? 'off',
                'water_press_sand_psi_kgcm2' => (string)($_POST['pump_sf_press_sand'] ?? ''),
                'water_press_carbon_psi_kgcm2' => (string)($_POST['pump_sf_press_carbon'] ?? '')
            ],
            'sand_filter_pump' => [
                'status' => (string)$_POST['pump_sfp_status'] ?? 'off',
                'unit_op' => (string)($_POST['pump_sfp_unit_op'] ?? ''),
                'water_press_psi_kgcm2' => (string)($_POST['pump_sfp_press'] ?? '')
            ],
            'booster_pump_villa' => [
                'unit_op' => (string)$_POST['pump_bpv_unit_op'] ?? '0',
                'water_press_psi_kgcm2' => (string)($_POST['pump_bpv_press'] ?? '')
            ],
            'booster_pump_main_house' => [
                'unit_op' => (string)$_POST['pump_bpm_unit_op'] ?? '0',
                'water_press_psi_kgcm2' => (string)($_POST['pump_bpm_press'] ?? '')
            ],
            'irrigation_pump' => [
                'unit_op' => (string)$_POST['pump_irigasi_unit_op'] ?? '0',
                'water_press_psi_kgcm2' => (string)($_POST['pump_irigasi_press'] ?? '')
            ]
        ],
        'chiller_system' => [
            'chiller' => [
                'unit_op' => (string)$_POST['chiller_unit_op'] ?? 'carrier',
                'chilled_water_test_tds_ph' => (string)($_POST['chiller_cw_test'] ?? '')
            ],
            'condensor_water_pump' => [
                'unit_op' => (string)$_POST['chiller_cwp_unit_op'] ?? '1',
                'water_press_kgcm2' => (string)($_POST['chiller_cwp_press'] ?? '')
            ],
            'chilled_water_pump' => [
                'unit_op' => (string)$_POST['chiller_chwp_unit_op'] ?? '1',
                'water_press_in_kgcm2' => (string)($_POST['chiller_chwp_in'] ?? ''),
                'water_press_out_kgcm2' => (string)($_POST['chiller_chwp_out'] ?? '')
            ]
        ],
        'cooling_tower' => [
            'unit_op' => (string)$_POST['ct_unit_op'] ?? '1',
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
                'selenoid_valve_open_close' => (string)$_POST['gas_boneka_valve'] ?? 'open',
                'alarm_on_off' => (string)$_POST['gas_boneka_alarm'] ?? 'on'
            ],
            'detector_main_kitchen' => [
                'selenoid_valve_open_close' => (string)$_POST['gas_mainkitchen_valve'] ?? 'open',
                'alarm_on_off' => (string)$_POST['gas_mainkitchen_alarm'] ?? 'on'
            ],
            'detector_kayu_puti_resto' => [
                'selenoid_valve_open_close' => (string)$_POST['gas_kayuputih_valve'] ?? 'open',
                'alarm_on_off' => (string)$_POST['gas_kayuputih_alarm'] ?? 'on'
            ]
        ]
    ];
    return $sectionData;
}

// Action: TAMBAH LOG BARU (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_log') {
    $logDate = $_POST['log_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) $logDate = date('Y-m-d');
    $shift = in_array(strtolower($_POST['shift'] ?? ''), ['pagi','siang','malam'], true) ? strtolower($_POST['shift']) : 'pagi';
    $plnLwbp  = (float)($_POST['pln_lwbp_kwh'] ?? 0);
    $plnWbp   = (float)($_POST['pln_wbp_kwh'] ?? 0);
    $pln      = $plnLwbp + $plnWbp;
    $genset   = (float)($_POST['genset_kwh'] ?? 0);
    $solar    = (float)($_POST['solar_liter'] ?? 0);
    $gas      = (float)($_POST['gas_kg'] ?? 0);
    $gasLng   = (float)($_POST['gas_lng_kg'] ?? 0);
    $air      = (float)($_POST['air_m3'] ?? 0);
    $airDw    = (float)($_POST['air_deep_well_m3'] ?? 0);
    $pic = trim((string)($_POST['pic_name'] ?? $userName));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $db->insert('energy_logs', [
        'log_date'          => $logDate,
        'shift'             => $shift,
        'pln_lwbp_kwh'      => $plnLwbp,
        'pln_wbp_kwh'       => $plnWbp,
        'pln_kwh'           => $pln,
        'genset_kwh'        => $genset,
        'solar_liter'       => $solar,
        'gas_kg'            => $gas,
        'gas_lng_kg'        => $gasLng,
        'air_m3'            => $air,
        'air_deep_well_m3'  => $airDw,
        'pic_name'          => $pic,
        'notes'             => $notes,
        'created_by'        => $userId
    ]);
    $logId = $db->lastInsertId();

    $sectionData = buildSectionData();
    $db->insert('equipment_logs', [
        'log_date'          => $logDate,
        'shift'             => $shift,
        'section_data'      => json_encode($sectionData),
        'pic_name'          => $pic,
        'notes'             => $notes,
        'status'            => 'approved',
        'created_by'        => $userId
    ]);
    $equipId = $db->lastInsertId();

    $db->update('energy_logs', [
        'equipment_id' => $equipId
    ], 'id = :id', [':id' => $logId]);

    $flashMsg = '✅ Log energi baru berhasil ditambahkan.';
    $flashType = 'success';
}

// Action: UPDATE LOG (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_log') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $logDate = $_POST['log_date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) $logDate = date('Y-m-d');
        $shift = in_array(strtolower($_POST['shift'] ?? ''), ['pagi','siang','malam'], true) ? strtolower($_POST['shift']) : 'pagi';
        $plnLwbp  = (float)($_POST['pln_lwbp_kwh'] ?? 0);
        $plnWbp   = (float)($_POST['pln_wbp_kwh'] ?? 0);
        $pln      = $plnLwbp + $plnWbp;
        $genset   = (float)($_POST['genset_kwh'] ?? 0);
        $solar    = (float)($_POST['solar_liter'] ?? 0);
        $gas      = (float)($_POST['gas_kg'] ?? 0);
        $gasLng   = (float)($_POST['gas_lng_kg'] ?? 0);
        $air      = (float)($_POST['air_m3'] ?? 0);
        $airDw    = (float)($_POST['air_deep_well_m3'] ?? 0);
        $pic = trim((string)($_POST['pic_name'] ?? $userName));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $db->update('energy_logs', [
            'log_date'          => $logDate,
            'shift'             => $shift,
            'pln_lwbp_kwh'      => $plnLwbp,
            'pln_wbp_kwh'       => $plnWbp,
            'pln_kwh'           => $pln,
            'genset_kwh'        => $genset,
            'solar_liter'       => $solar,
            'gas_kg'            => $gas,
            'gas_lng_kg'        => $gasLng,
            'air_m3'            => $air,
            'air_deep_well_m3'  => $airDw,
            'pic_name'          => $pic,
            'notes'             => $notes,
            'updated_by'        => $userId
        ], 'id = :id', [':id' => $id]);

        $editData = $db->fetchOne("SELECT equipment_id FROM energy_logs WHERE id = ?", [$id]);
        $sectionData = buildSectionData();

        if (!empty($editData['equipment_id'])) {
            $db->update('equipment_logs', [
                'log_date'          => $logDate,
                'shift'             => $shift,
                'section_data'      => json_encode($sectionData),
                'pic_name'          => $pic,
                'notes'             => $notes,
                'updated_by'        => $userId
            ], 'id = :id', [':id' => (int)$editData['equipment_id']]);
        } else {
            $db->insert('equipment_logs', [
                'log_date'          => $logDate,
                'shift'             => $shift,
                'section_data'      => json_encode($sectionData),
                'pic_name'          => $pic,
                'notes'             => $notes,
                'status'            => 'approved',
                'created_by'        => $userId,
                'updated_by'        => $userId
            ]);
            $equipId = $db->lastInsertId();
            $db->update('energy_logs', [
                'equipment_id' => $equipId
            ], 'id = :id', [':id' => $id]);
        }

        $flashMsg = '✅ Log energi berhasil diupdate.';
        $flashType = 'success';
    }
}

// Action: HAPUS LOG (GET ?delete=ID)
if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $cek = $db->fetchOne("SELECT id FROM energy_logs WHERE id = ?", [$id]);
        if ($cek) {
            $db->delete('energy_logs', 'id = ?', [$id]);
            $flashMsg = '🗑️ Log energi berhasil dihapus.';
            $flashType = 'success';
        }
    }
}

// ==============================================
// 🔍 FILTER PARAMETER
// ==============================================
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate   = $_GET['end_date']   ?? date('Y-m-d');
$shiftF    = $_GET['shift']      ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = date('Y-m-d');
if ($startDate > $endDate) { [$startDate, $endDate] = [$endDate, $startDate]; }
$shiftF = in_array(strtolower($shiftF), ['pagi','siang','malam'], true) ? strtolower($shiftF) : '';

$where = [];
$params = [];
/* ⚡ ULTRA SAFE: WHERE clause SELALU DIBUAT DENGAN NAMA KOLOM POLOS (TANPA PREFIX ALIAS TABLE!)
   Kita TIDAK PERNAH pakai prefix di WHERE! Untuk query JOIN = WRAP JOIN jadi SUBQUERY (virtual table)
   agar outer query cuma 1 table virtual → 100% TIDAK BISA ERROR 'Column ... is ambiguous' LAGI! */
$where[] = "log_date BETWEEN ? AND ?";
$params[] = $startDate;
$params[] = $endDate;
if ($shiftF !== '') {
    $where[] = "shift = ?";
    $params[] = $shiftF;
}
/* Role filter: Engineer HANYA BOLEH LIHAT LOG DIA SENDIRI */
if ($userRole === 'engineer') {
    try {
        $colsExist = $db->fetchAll("SHOW COLUMNS FROM energy_logs LIKE 'created_by'");
        if (!empty($colsExist)) { $where[] = "created_by = ?"; $params[] = $userId; }
    } catch (Throwable $ex) { /* skip jika DB lama tidak punya kolom */ }
}
$whereSqlPlain = 'WHERE ' . implode(' AND ', $where);
/* Build versi PREFIX `e.` HANYA JIKA BUTUH untuk inner query (optional kita jaga biar work) */
$whereSqlPrefixed = str_replace([' log_date',' shift',' created_by'], [' e.log_date',' e.shift',' e.created_by'], $whereSqlPlain);

// ==============================================
// 📊 SUMMARY 6 CARDS MINI
// ==============================================
$sumRow = $db->fetchOne(
    "SELECT COUNT(id) AS total_entri,
            SUM(COALESCE(pln_lwbp_kwh,0) + COALESCE(pln_wbp_kwh,0) + COALESCE(genset_kwh,0)) AS total_kwh,
            SUM(COALESCE(solar_liter,0)) AS total_solar,
            SUM(COALESCE(gas_kg,0) + COALESCE(gas_lng_kg,0)) AS total_gas,
            SUM(COALESCE(air_m3,0) + COALESCE(air_deep_well_m3,0)) AS total_air,
            COUNT(DISTINCT NULLIF(pic_name,'')) AS total_pic
     FROM energy_logs $whereSqlPlain",
    $params
);
$tEntri  = (int)($sumRow['total_entri'] ?? 0);
$tKwh    = (float)($sumRow['total_kwh'] ?? 0);
$tSolar  = (float)($sumRow['total_solar'] ?? 0);
$tGas    = (float)($sumRow['total_gas'] ?? 0);
$tAir    = (float)($sumRow['total_air'] ?? 0);
$tPic    = (int)($sumRow['total_pic'] ?? 0);

function fmtNum($n, $dec = 1) {
    $n = (float)$n;
    return number_format($n, $dec, ',', '.');
}

// ==============================================
// 📋 QUERY LIST LOG (REAL DB) + LEFT JOIN EQUIPMENT
// 🔥 ULTRA SAFE PATTERN: WRAP JOIN JADI SUBQUERY (virtual table 1 alias = x)
//    WHERE clause outer query = PAKAI KOLOM POLOS → 100% TIDAK PERNAH ambiguous!
// ==============================================
$logs = $db->fetchAll(
    "SELECT x.*
     FROM (
        SELECT e.*, eq.section_data AS equip_section_data
        FROM energy_logs e
        LEFT JOIN equipment_logs eq ON e.equipment_id = eq.id
     ) x
     $whereSqlPlain
     ORDER BY x.log_date DESC, FIELD(x.shift,'pagi','siang','malam'), x.id DESC",
    $params
);

// Helper: cek apakah suatu section punya nilai non-zero / non-empty
function sectionHasData($node) {
    if (is_array($node)) {
        foreach ($node as $v) {
            if (sectionHasData($v)) return true;
        }
        return false;
    }
    $s = (string)$node;
    if ($s === '' || $s === '0' || $s === '0.0' || $s === 'off' || $s === 'auto') return false;
    return true;
}

// Data edit (jika ada param ?edit=ID)
$editData = null;
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $editData = $db->fetchOne("SELECT * FROM energy_logs WHERE id = ?", [$eid]);
}

$pageTitle = 'Energy Log Sheet';
$pageSubtitle = 'Form Input & Daftar Catatan Konsumsi Energi Harian (Listrik, Solar, Gas, Air).';

$shiftLabelMap = [
    'pagi'  => 'Pagi (07.00 - 15.00)',
    'siang' => 'Siang (15.00 - 23.00)',
    'malam' => 'Malam (23.00 - 07.00)',
];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<style>
.hide-scrollbar::-webkit-scrollbar { display: none !important; width: 0 !important; height: 0 !important; }
.hide-scrollbar { -ms-overflow-style: none !important; scrollbar-width: none !important; overflow-x: auto; overflow-y: hidden; }
table.logsheet-ramp th, table.logsheet-ramp td { padding-top: 10px !important; padding-bottom: 10px !important; }
@media (min-width: 640px) {
    table.logsheet-ramp th, table.logsheet-ramp td { padding-left: 10px !important; padding-right: 10px !important; }
}
</style>
<div class="main-content px-4 sm:px-6 lg:px-8 py-6 pb-16 max-w-[1800px] mx-auto">

    <!-- FLASH ALERT -->
    <?php if ($flashMsg !== ''): ?>
    <div class="mb-4 px-4 py-3 rounded-xl text-[13px] font-bold shadow-sm border <?= $flashType === 'success' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-rose-50 text-rose-800 border-rose-200' ?> animate-slide-up">
        <i class="fas <?= $flashType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> mr-1.5"></i>
        <?= htmlspecialchars($flashMsg) ?>
    </div>
    <?php endif; ?>

    <!-- BREADCRUMB -->
    <div class="mb-4 flex items-center gap-2 text-xs font-semibold text-slate-500">
        <a href="<?= BASE_URL ?>index.php" class="hover:text-primary transition"><i class="fas fa-house mr-1"></i> Dashboard</a>
        <i class="fas fa-chevron-right text-[10px] text-slate-400"></i>
        <a href="<?= BASE_URL ?>energy.php" class="hover:text-primary transition">Energy</a>
        <i class="fas fa-chevron-right text-[10px] text-slate-400"></i>
        <span class="text-primary font-black">Log Sheet</span>
    </div>

    <!-- HEADER JUDUL -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 animate-slide-up">
        <div>
            <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">Energy Log Sheet</h1>
            <p class="text-[12px] text-slate-500 font-semibold">Form input &amp; rekap konsumsi energi harian per shift (PLN, Genset, Solar, Gas, Air).</p>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-end flex-wrap">
            <a href="<?= BASE_URL ?>energy.php" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-card bg-white border border-slate-200 text-slate-700 text-sm font-bold hover:bg-slate-50 transition shadow-sm">
                <i class="fas fa-chart-line text-slate-500"></i>
                Kembali ke Dashboard
            </a>
            <button type="button" onclick="document.getElementById('addLogModal').classList.remove('hidden')"
                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-card bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold shadow-md hover:shadow-lg transition">
                <i class="fas fa-plus text-lg"></i>
                + Tambah Log Baru
            </button>
        </div>
    </div>

    <!-- FILTER + TOMBOL EXPORT -->
    <form method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="bg-white rounded-premium border border-slate-200 shadow-sm mb-6 p-4 sm:p-5 animate-slide-up" style="animation-delay: 60ms">
        <div class="flex flex-col md:flex-row md:items-end gap-3 flex-wrap">
            <div class="md:w-44">
                <label class="text-[11px] sm:text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Tanggal</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="w-full px-3 py-2.5 rounded-card border border-border bg-muted/50 text-primary text-sm font-bold focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200">
            </div>
            <div class="md:w-44">
                <label class="text-[11px] sm:text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">s/d Tanggal (Opsional)</label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="w-full px-3 py-2.5 rounded-card border border-border bg-muted/50 text-primary text-sm font-bold focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200">
            </div>
            <div class="md:w-44">
                <label class="text-[11px] sm:text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Shift</label>
                <select name="shift" class="w-full px-3 py-2.5 rounded-card border border-border bg-muted/50 text-primary text-sm font-bold focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200">
                    <option value="">Semua Shift</option>
                    <option value="pagi"  <?= $shiftF === 'pagi'  ? 'selected' : '' ?>>Pagi (07.00 - 15.00)</option>
                    <option value="siang" <?= $shiftF === 'siang' ? 'selected' : '' ?>>Siang (15.00 - 23.00)</option>
                    <option value="malam" <?= $shiftF === 'malam' ? 'selected' : '' ?>>Malam (23.00 - 07.00)</option>
                </select>
            </div>
            <div class="md:w-auto">
                <button type="submit" class="w-full md:w-auto px-4 py-2.5 rounded-card bg-slate-900 hover:bg-slate-800 text-white text-sm font-bold shadow-sm inline-flex items-center justify-center gap-2 transition">
                    <i class="fas fa-filter"></i> Terapkan
                </button>
            </div>
            <div class="md:ml-auto flex items-center gap-2">
                <a href="<?= BASE_URL ?>reports/dashboard_pdf.php" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-white text-slate-700 text-xs font-bold border border-slate-200 hover:bg-slate-50 transition shadow-sm">
                    <i class="fas fa-file-pdf text-rose-500"></i> PDF
                </a>
                <a href="<?= BASE_URL ?>reports/dashboard_excel.php" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-white text-slate-700 text-xs font-bold border border-slate-200 hover:bg-slate-50 transition shadow-sm">
                    <i class="fas fa-file-excel text-emerald-500"></i> Excel
                </a>
            </div>
        </div>
    </form>

    <!-- 6 QUICK INFO CARDS MINI (FILTER RESULT) - REAL DB SUM -->
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-6 gap-3 mb-6">
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Total Entri</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1"><?= number_format($tEntri,0,',','.') ?></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Total kWh</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1"><?= fmtNum($tKwh, 1) ?></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Total Solar</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1"><?= fmtNum($tSolar, 1) ?> <span class="text-sm text-slate-500 font-bold">L</span></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Total Gas</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1"><?= fmtNum($tGas, 1) ?> <span class="text-sm text-slate-500 font-bold">Kg</span></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Total Air</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1"><?= fmtNum($tAir, 1) ?> <span class="text-sm text-slate-500 font-bold">m3</span></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">PIC Aktif</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1"><?= number_format($tPic,0,',','.') ?> <span class="text-sm text-slate-500 font-bold">Orang</span></p>
        </div>
    </div>

    <!-- TABLE LIST LOG SHEET - REAL DB -->
    <div class="bg-white rounded-premium border border-slate-200 shadow-sm overflow-hidden mb-6 animate-slide-up" style="animation-delay: 90ms">
        <div class="hide-scrollbar -mx-4 sm:mx-0 px-4 sm:px-0">
            <table class="w-full text-sm min-w-[1100px] table-auto border-collapse logsheet-ramp">
                <thead class="bg-slate-50 border-b-2 border-slate-200">
                    <tr class="text-left text-secondary text-xs">
                        <th class="px-2 sm:px-3 py-3 font-bold whitespace-nowrap w-12 text-center">#</th>
                        <th class="px-2 sm:px-3 py-3 font-bold whitespace-nowrap">Tanggal</th>
                        <th class="px-2 sm:px-3 py-3 font-bold whitespace-nowrap w-[220px]">Shift</th>
                        <th class="px-2 sm:px-3 py-3 font-bold text-center whitespace-nowrap w-[95px] border-l border-slate-200" colspan="3">LISTRIK (kWh)</th>
                        <th class="px-2 sm:px-3 py-3 font-bold text-right whitespace-nowrap w-[100px] border-l border-slate-200">Solar (L)</th>
                        <th class="px-2 sm:px-3 py-3 font-bold text-center whitespace-nowrap w-[190px] border-l border-slate-200" colspan="2">GAS (Kg)</th>
                        <th class="px-2 sm:px-3 py-3 font-bold text-center whitespace-nowrap w-[190px] border-l border-slate-200" colspan="2">AIR (m3)</th>
                        <th class="px-2 sm:px-3 py-3 font-bold whitespace-nowrap w-[200px] border-l border-slate-200">PIC</th>
                        <th class="px-2 sm:px-3 py-3 font-bold whitespace-nowrap text-center w-[110px] border-l border-slate-200">Equipment 8-Sec</th>
                        <th class="px-2 sm:px-3 py-3 pr-2 sm:pr-3 font-bold text-right whitespace-nowrap w-[130px]">Aksi</th>
                    </tr>
                    <tr class="text-[10px] text-slate-500 border-t border-slate-200">
                        <th class="px-2 sm:px-3 py-2"></th>
                        <th class="px-2 sm:px-3 py-2"></th>
                        <th class="px-2 sm:px-3 py-2"></th>
                        <th class="px-2 sm:px-3 py-2 font-bold text-right border-l border-slate-200">LWBP</th>
                        <th class="px-2 sm:px-3 py-2 font-bold text-right">WBP</th>
                        <th class="px-2 sm:px-3 py-2 font-bold text-right text-primary">TOTAL</th>
                        <th class="px-2 sm:px-3 py-2 font-bold text-right border-l border-slate-200">Genset</th>
                        <th class="px-2 sm:px-3 py-2 font-bold text-right border-l border-slate-200">LPG</th>
                        <th class="px-2 sm:px-3 py-2 font-bold text-right text-primary">LNG</th>
                        <th class="px-2 sm:px-3 py-2 font-bold text-right border-l border-slate-200">PDAM</th>
                        <th class="px-2 sm:px-3 py-2 font-bold text-right text-primary">Deep Well</th>
                        <th class="px-2 sm:px-3 py-2 border-l border-slate-200"></th>
                        <th class="px-2 sm:px-3 py-2 border-l border-slate-200"></th>
                        <th class="px-2 sm:px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="14" class="px-4 py-16 text-center">
                            <div class="text-5xl text-slate-300 mb-3"><i class="far fa-folder-open"></i></div>
                            <p class="text-[14px] font-bold text-slate-600 mb-1">Belum ada log energi untuk periode ini</p>
                            <p class="text-[12px] text-slate-500 mb-5">Klik tombol <strong>+ Tambah Log Baru</strong> di pojok kanan atas untuk mulai input data.</p>
                            <button type="button" onclick="document.getElementById('addLogModal').classList.remove('hidden')" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-[12px] font-bold shadow-md transition">
                                <i class="fas fa-plus"></i> Tambah Log Pertama
                            </button>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $i => $r):
                        $shiftLabel = $shiftLabelMap[$r['shift']] ?? ucfirst($r['shift']);
                        $tgl = (new DateTime($r['log_date']))->format('d M Y');
                        $lwbp = (float)($r['pln_lwbp_kwh'] ?? 0);
                        $wbp  = (float)($r['pln_wbp_kwh'] ?? 0);
                        $plnT = $lwbp + $wbp;
                        $gasLpg = (float)($r['gas_kg'] ?? 0);
                        $gasLng = (float)($r['gas_lng_kg'] ?? 0);
                        $airPdam = (float)($r['air_m3'] ?? 0);
                        $airDw   = (float)($r['air_deep_well_m3'] ?? 0);

                        $secData = null;
                        $hasEquip = !empty($r['equip_section_data']);
                        if ($hasEquip) {
                            $dec = json_decode((string)$r['equip_section_data'], true);
                            if (is_array($dec)) $secData = $dec;
                        }
                        $secHas = [
                            'trafo'   => ($hasEquip && $secData && isset($secData['trafo']))   ? sectionHasData($secData['trafo'])   : false,
                            'genset'  => ($hasEquip && $secData && isset($secData['genset']))  ? sectionHasData($secData['genset'])  : false,
                            'pump'    => ($hasEquip && $secData && isset($secData['pump_room']))? sectionHasData($secData['pump_room']): false,
                            'chiller' => ($hasEquip && $secData && isset($secData['chiller_system']))? sectionHasData($secData['chiller_system']): false,
                            'cooling' => ($hasEquip && $secData && isset($secData['cooling_tower']))? sectionHasData($secData['cooling_tower']): false,
                            'ro'      => ($hasEquip && $secData && isset($secData['reverse_osmosis']))? sectionHasData($secData['reverse_osmosis']): false,
                            'pool'    => ($hasEquip && $secData && isset($secData['pool_system']))? sectionHasData($secData['pool_system']): false,
                            'gas'     => ($hasEquip && $secData && isset($secData['gas_system']))? sectionHasData($secData['gas_system']): false,
                        ];
                        $dotMap = [
                            'trafo'   => ['fill'=>'bg-blue-500',    'title'=>'Trafo'],
                            'genset'  => ['fill'=>'bg-amber-400',   'title'=>'Genset'],
                            'pump'    => ['fill'=>'bg-emerald-500', 'title'=>'Pump Room'],
                            'chiller' => ['fill'=>'bg-purple-500',  'title'=>'Chiller'],
                            'cooling' => ['fill'=>'bg-cyan-400',    'title'=>'Cooling Tower'],
                            'ro'      => ['fill'=>'bg-pink-500',    'title'=>'Reverse Osmosis'],
                            'pool'    => ['fill'=>'bg-teal-500',    'title'=>'Pool System'],
                            'gas'     => ['fill'=>'bg-rose-500',    'title'=>'Gas Detector'],
                        ];
                    ?>
                    <tr class="hover:bg-slate-50 transition-colors group">
                        <td class="px-2 sm:px-3 py-3 text-xs font-bold text-slate-500 align-top text-center"><?= ($i+1) ?>.</td>
                        <td class="px-2 sm:px-3 py-3 font-bold text-primary whitespace-nowrap align-top"><?= $tgl ?></td>
                        <td class="px-2 sm:px-3 py-3 align-top">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-100 border border-slate-200 text-slate-700 text-[11px] font-bold">
                                <i class="fas fa-clock mr-1.5 text-slate-400 text-[10px]"></i><?= $shiftLabel ?>
                            </span>
                        </td>
                        <td class="px-2 sm:px-3 py-3 text-right font-mono text-slate-600 align-top tabular-nums border-l border-slate-100"><?= fmtNum($lwbp, 1) ?></td>
                        <td class="px-2 sm:px-3 py-3 text-right font-mono text-slate-600 align-top tabular-nums"><?= fmtNum($wbp, 1) ?></td>
                        <td class="px-2 sm:px-3 py-3 text-right font-mono font-black text-primary align-top tabular-nums"><?= fmtNum($plnT, 1) ?></td>
                        <td class="px-2 sm:px-3 py-3 text-right font-mono font-bold text-slate-600 align-top tabular-nums border-l border-slate-100"><?= fmtNum($r['genset_kwh'], 1) ?></td>
                        <td class="px-2 sm:px-3 py-3 text-right font-mono text-slate-600 align-top tabular-nums border-l border-slate-100"><?= fmtNum($gasLpg, 1) ?></td>
                        <td class="px-2 sm:px-3 py-3 text-right font-mono font-black text-primary align-top tabular-nums"><?= fmtNum($gasLng, 1) ?></td>
                        <td class="px-2 sm:px-3 py-3 text-right font-mono text-slate-600 align-top tabular-nums border-l border-slate-100"><?= fmtNum($airPdam, 1) ?></td>
                        <td class="px-2 sm:px-3 py-3 text-right font-mono font-black text-primary align-top tabular-nums"><?= fmtNum($airDw, 1) ?></td>
                        <td class="px-2 sm:px-3 py-3 text-xs text-slate-700 font-semibold align-top whitespace-nowrap border-l border-slate-100">
                            <div class="inline-flex items-center gap-1.5">
                                <i class="far fa-user-circle text-slate-400"></i>
                                <?= htmlspecialchars($r['pic_name'] ?: '-') ?>
                            </div>
                            <?php if (!empty($r['notes'])): ?>
                                <div class="text-[10px] text-slate-400 mt-1 truncate max-w-[180px]" title="<?= htmlspecialchars($r['notes']) ?>"><i class="far fa-sticky-note mr-1"></i><?= htmlspecialchars($r['notes']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 sm:px-3 py-3 text-center align-top border-l border-slate-100">
                            <div class="inline-flex items-center gap-[2px] flex-wrap justify-center leading-none" title="<?= $hasEquip ? 'Equipment data tersedia' : 'Belum ada equipment data (log lama)' ?>">
                                <?php foreach ($dotMap as $key => $dm):
                                    $filled = $secHas[$key] ?? false;
                                    $cls = $filled ? $dm['fill'] . ' ring-1 ring-white/20' : 'bg-transparent border border-slate-300';
                                ?>
                                <span class="w-2 h-2 inline-block rounded-full <?= $cls ?>" title="<?= htmlspecialchars($dm['title']) ?>: <?= $filled ? 'terisi' : 'kosong' ?>"></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="text-[9px] text-slate-400 mt-1 font-semibold tracking-tight"><?= $hasEquip ? '8-sec' : '—' ?></div>
                        </td>
                        <td class="px-2 sm:px-3 py-3 pr-2 sm:pr-3 text-right whitespace-nowrap align-top">
                            <div class="flex gap-1.5 justify-end items-center">
                                <?php
                                    $qsEdit = $_GET; $qsEdit['edit'] = $r['id'];
                                    $qsDel  = $_GET; $qsDel['delete'] = $r['id'];
                                ?>
                                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($qsEdit) ?>#editLogModal" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-slate-700 border border-slate-200 hover:bg-slate-200 transition" title="Edit">
                                    <i class="fas fa-pencil text-xs"></i>
                                </a>
                                <button type="button" onclick="alert('Detail:\nTanggal: <?= $tgl ?>\nShift: <?= $shiftLabel ?>\nPLN LWBP: <?= fmtNum($lwbp,1) ?> kWh\nPLN WBP:  <?= fmtNum($wbp,1) ?> kWh\nPLN TOTAL:<?= fmtNum($plnT,1) ?> kWh\nGenset:   <?= fmtNum($r['genset_kwh'],1) ?> kWh\nSolar:    <?= fmtNum($r['solar_liter'],1) ?> L\nGas LPG:  <?= fmtNum($gasLpg,1) ?> Kg\nGas LNG:  <?= fmtNum($gasLng,1) ?> Kg\nAir PDAM: <?= fmtNum($airPdam,1) ?> m3\nAir DW:   <?= fmtNum($airDw,1) ?> m3\nPIC: <?= htmlspecialchars($r['pic_name'] ?: '-') ?>\nNotes: <?= htmlspecialchars($r['notes'] ?: '-') ?>')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 transition" title="Lihat Detail">
                                    <i class="fas fa-eye text-xs"></i>
                                </button>
                                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($qsDel) ?>" onclick="return confirm('Yakin hapus log tanggal <?= $tgl ?> shift <?= $shiftLabel ?>?')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white text-slate-600 border border-slate-200 hover:bg-rose-50 hover:text-rose-600 hover:border-rose-200 transition" title="Hapus">
                                    <i class="fas fa-trash text-xs"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION (SIMPLE) -->
        <div class="p-4 sm:p-5 border-t border-slate-200 bg-slate-50 flex flex-col md:flex-row md:items-center justify-between gap-3">
            <p class="text-xs font-semibold text-slate-500">Menampilkan <strong><?= number_format(count($logs),0,',','.') ?></strong> entri log sheet<?php if ($tEntri > 0): ?> • periode <?= (new DateTime($startDate))->format('d M Y') ?> s/d <?= (new DateTime($endDate))->format('d M Y') ?><?php endif; ?>.</p>
            <div class="flex items-center gap-1.5">
                <button disabled class="w-9 h-9 rounded-lg bg-white border border-slate-200 text-slate-400 cursor-not-allowed shadow-sm"><i class="fas fa-chevron-left text-[10px]"></i></button>
                <button class="w-9 h-9 rounded-lg bg-slate-900 text-white font-bold shadow-sm text-sm">1</button>
                <button disabled class="w-9 h-9 rounded-lg bg-white border border-slate-200 text-slate-400 cursor-not-allowed shadow-sm"><i class="fas fa-chevron-right text-[10px]"></i></button>
            </div>
        </div>
    </div>

    <!-- INFO CARD FOOTER -->
    <div class="bg-white rounded-premium border border-slate-200 shadow-sm p-4 sm:p-5">
        <p class="text-[11px] text-slate-500 flex items-start gap-2">
            <i class="fas fa-circle-info text-slate-400 mt-0.5"></i>
            <span>
                <strong class="text-slate-700">Catatan:</strong> Semua data log sheet otomatis tersimpan ke database table <code class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 text-[10px] font-mono">energy_logs</code>. Gunakan filter tanggal + shift di atas untuk melihat data periode tertentu. Data ini akan otomatis terintegrasi dengan summary dashboard <strong>Energy &amp; Utility Report</strong>.
            </span>
        </p>
    </div>
</div>

<!-- ===================================================== -->
<!-- 🔲 MODAL: TAMBAH LOG BARU                             -->
<!-- ===================================================== -->
<div id="addLogModal" class="hidden fixed inset-0 z-[9999]">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="document.getElementById('addLogModal').classList.add('hidden')"></div>
    <div class="relative min-h-screen flex items-center justify-center p-4 sm:p-6">
        <div class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden animate-slide-up max-h-[90vh] flex flex-col">
            <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="p-0 flex flex-col flex-1 min-h-0">
                <input type="hidden" name="action" value="add_log">
                <div class="px-5 sm:px-7 py-4 flex items-center justify-between border-b border-slate-100 bg-slate-50 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center shadow-md">
                            <i class="fas fa-plus text-sm"></i>
                        </div>
                        <div>
                            <h2 class="font-black text-lg text-primary">Tambah Log Energi Baru</h2>
                            <p class="text-[11px] text-slate-500 font-semibold">Input data konsumsi energi per shift — otomatis masuk ke rekap harian.</p>
                        </div>
                    </div>
                    <button type="button" onclick="document.getElementById('addLogModal').classList.add('hidden')" class="w-9 h-9 rounded-lg flex items-center justify-center text-slate-500 hover:text-slate-700 hover:bg-slate-200 transition">
                        <i class="fas fa-xmark text-lg"></i>
                    </button>
                </div>

                <div class="flex items-center gap-1 overflow-x-auto px-5 sm:px-7 pt-3 bg-slate-50 border-b border-slate-200 tab-bar hide-scrollbar -mx-0">
                    <button type="button" onclick="switchTab(event,'tab-energi')" class="tab-btn active shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-black whitespace-nowrap bg-slate-900 text-white">⚡ Energi</button>
                    <button type="button" onclick="switchTab(event,'tab-trafo')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">⚡ Trafo</button>
                    <button type="button" onclick="switchTab(event,'tab-genset')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">🔋 Genset</button>
                    <button type="button" onclick="switchTab(event,'tab-pump')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">💧 Pump Room</button>
                    <button type="button" onclick="switchTab(event,'tab-chiller')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">❄️ Chiller</button>
                    <button type="button" onclick="switchTab(event,'tab-cooling')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">🌀 Cooling Twr</button>
                    <button type="button" onclick="switchTab(event,'tab-ro')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">🧪 Reverse Osmosis</button>
                    <button type="button" onclick="switchTab(event,'tab-pool')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">🏊 Pool System</button>
                    <button type="button" onclick="switchTab(event,'tab-gas')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">🔥 Gas Detector</button>
                </div>
                <script>
                function switchTab(e,id){
                    document.querySelectorAll('.tab-pane').forEach(p=>p.classList.add('hidden'));
                    document.querySelectorAll('.tab-btn').forEach(b=>{b.classList.remove('active','bg-slate-900','text-white');b.classList.add('text-slate-600');});
                    e.currentTarget.classList.add('active','bg-slate-900','text-white');
                    e.currentTarget.classList.remove('text-slate-600');
                    document.getElementById(id).classList.remove('hidden');
                }
                </script>

                <div id="tab-energi" class="tab-pane p-5 sm:p-7 space-y-5 overflow-y-auto flex-1 min-h-0">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-[11px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Tanggal <span class="text-rose-500">*</span></label>
                            <input type="date" name="log_date" value="<?= date('Y-m-d') ?>" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div>
                            <label class="text-[11px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Shift <span class="text-rose-500">*</span></label>
                            <select name="shift" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                <option value="pagi">Pagi (07.00 - 15.00)</option>
                                <option value="siang">Siang (15.00 - 23.00)</option>
                                <option value="malam">Malam (23.00 - 07.00)</option>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-3 border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70">
                        <div class="flex items-center justify-between">
                            <p class="text-[10px] font-black uppercase tracking-[2px] text-slate-500"><i class="fas fa-bolt text-amber-500 mr-1.5"></i> Listrik PLN (kWh)</p>
                            <span class="text-[9px] text-slate-400 font-semibold uppercase">total = lwbp + wbp (auto hitung)</span>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">LWBP</label>
                                <input type="number" step="0.01" min="0" name="pln_lwbp_kwh" value="0" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">WBP</label>
                                <input type="number" step="0.01" min="0" name="pln_wbp_kwh" value="0" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div class="flex items-end">
                                <div class="w-full px-3 py-2.5 rounded-xl border-2 border-slate-800/10 bg-slate-100 text-slate-700 text-sm font-black font-mono text-right">
                                    <span data-role="pln-total">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3 border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70">
                        <p class="text-[10px] font-black uppercase tracking-[2px] text-slate-500"><i class="fas fa-industry text-slate-500 mr-1.5"></i> Sumber Daya Lain</p>
                        <div class="grid grid-cols-2 sm:grid-cols-6 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Genset (kWh)</label>
                                <input type="number" step="0.01" min="0" name="genset_kwh" value="0" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Solar (L)</label>
                                <input type="number" step="0.01" min="0" name="solar_liter" value="0" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Gas LPG (Kg)</label>
                                <input type="number" step="0.01" min="0" name="gas_kg" value="0" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Gas LNG (Kg)</label>
                                <input type="number" step="0.01" min="0" name="gas_lng_kg" value="0" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Air PDAM (m3)</label>
                                <input type="number" step="0.01" min="0" name="air_m3" value="0" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Deep Well (m3)</label>
                                <input type="number" step="0.01" min="0" name="air_deep_well_m3" value="0" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="text-[11px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">PIC / Nama Petugas <span class="text-rose-500">*</span></label>
                        <input type="text" name="pic_name" value="<?= htmlspecialchars($userName) ?>" required placeholder="contoh: Pak Wayan (Engineer)" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                    </div>

                    <div>
                        <label class="text-[11px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Catatan (Opsional)</label>
                        <textarea name="notes" rows="2" placeholder="Catatan khusus (opsional): perbaikan chiller, cuaca hujan, dll." class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600 resize-y"></textarea>
                    </div>
                </div>

                <div id="tab-trafo" class="tab-pane p-5 sm:p-7 space-y-5 overflow-y-auto flex-1 min-h-0 hidden">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">1. Trafo 1</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Temp. (°C)</label>
                                    <input type="number" step="0.1" min="0" name="trafo_1_temp" value="0" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Ampere (A) / LVDP 1</label>
                                    <input type="number" step="0.1" min="0" name="trafo_1_ampere" value="0" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Oil Level (%)</label>
                                    <input type="number" step="0.1" min="0" max="100" name="trafo_1_oil" value="0" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">2. Trafo 2</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Temp. (°C)</label>
                                    <input type="number" step="0.1" min="0" name="trafo_2_temp" value="0" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Ampere (A) / LVDP 2</label>
                                    <input type="number" step="0.1" min="0" name="trafo_2_ampere" value="0" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Oil Level (%)</label>
                                    <input type="number" step="0.1" min="0" max="100" name="trafo_2_oil" value="0" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-genset" class="tab-pane p-5 sm:p-7 space-y-5 overflow-y-auto flex-1 min-h-0 hidden">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">1. Genset 1 (V)</h3>
                            <input type="number" step="0.1" min="0" name="genset_1_volt" value="0" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">2. Genset 2 (V)</h3>
                            <input type="number" step="0.1" min="0" name="genset_2_volt" value="0" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">3. Genset 3 (V)</h3>
                            <input type="number" step="0.1" min="0" name="genset_3_volt" value="0" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">4. Fuel Tank (liter)</h3>
                            <input type="number" step="0.1" min="0" name="genset_fuel_tank" value="0" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                    </div>
                </div>

                <div id="tab-pump" class="tab-pane p-5 sm:p-7 space-y-4 overflow-y-auto flex-1 min-h-0 hidden">
                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">I. Steam Boiler</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="pump_sb_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="off">Off</option>
                                    <option value="on">On</option>
                                    <option value="auto">Auto</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">SB1 Running Hours</label>
                                <input type="text" name="pump_sb1_hours" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">SB2 Running Hours</label>
                                <input type="text" name="pump_sb2_hours" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Test (TDS:PH)</label>
                                <input type="text" name="pump_sb_test" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Steam Pressure (kg/cm2)</label>
                                <input type="text" name="pump_sb_press" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Time Blow Down</label>
                                <input type="text" name="pump_sb_blow" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Econ Temp (°C)</label>
                                <input type="text" name="pump_sb_econ_temp" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Econ Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_sb_econ_press" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">II. Hot Water Boiler (HWB)</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="pump_hwb_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="off">Off</option>
                                    <option value="on">On</option>
                                    <option value="auto">Auto</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">HWB1 Running Hours</label>
                                <input type="text" name="pump_hwb1_hours" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">HWB2 Running Hours</label>
                                <input type="text" name="pump_hwb2_hours" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">HW Temp (°C)</label>
                                <input type="text" name="pump_hwb_temp" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Test (TDS:PH)</label>
                                <input type="text" name="pump_hwb_test" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Circ Pump Unit Op</label>
                                <input type="text" name="pump_hwb_circ_op" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Flow Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_hwb_flow" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Return Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_hwb_ret" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">III. Ground Tank</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Raw Tank (Level%:TDS:PH)</label>
                                <input type="text" name="pump_tank_raw" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Treated Tank (Level%:TDS:PH)</label>
                                <input type="text" name="pump_tank_treated" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Irigation Tank (Level%)</label>
                                <input type="text" name="pump_tank_irigasi" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">IV. Hydrant Pump</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Standby / Auto</label>
                                <select name="pump_hyd_standby" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="auto">Auto</option>
                                    <option value="standby">Standby</option>
                                    <option value="on">On</option>
                                    <option value="off">Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Press Pump 1</label>
                                <input type="text" name="pump_hyd_press1" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Press Pump 2</label>
                                <input type="text" name="pump_hyd_press2" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">V. Jockey Pump</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Standby Press (kg/cm2)</label>
                                <input type="text" name="pump_jockey_press" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">VI. Sand Filter</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Status</label>
                                <select name="pump_sf_status" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="off">Off</option>
                                    <option value="on">On</option>
                                    <option value="backwash">Backwash</option>
                                    <option value="rinse">Rinse</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press Sand (psi/kg/cm2)</label>
                                <input type="text" name="pump_sf_press_sand" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press Carbon (psi/kg/cm2)</label>
                                <input type="text" name="pump_sf_press_carbon" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">VII. Sand Filter Pump</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Status</label>
                                <select name="pump_sfp_status" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="off">Off</option>
                                    <option value="on">On</option>
                                    <option value="auto">Auto</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Op</label>
                                <input type="text" name="pump_sfp_unit_op" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_sfp_press" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">VIII. Booster Pump for Villa</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="pump_bpv_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="0">0</option>
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="on">On</option>
                                    <option value="off">Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_bpv_press" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">IX. Booster Pump Main House</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="pump_bpm_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="0">0</option>
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="on">On</option>
                                    <option value="off">Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_bpm_press" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">X. Irrigation Pump</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="pump_irigasi_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="0">0</option>
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="on">On</option>
                                    <option value="off">Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_irigasi_press" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-chiller" class="tab-pane p-5 sm:p-7 space-y-4 overflow-y-auto flex-1 min-h-0 hidden">
                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">I. Chiller</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="chiller_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="carrier">Carrier</option>
                                    <option value="daikin">Daikin</option>
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="off">Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Chilled Water Test (TDS:PH)</label>
                                <input type="text" name="chiller_cw_test" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">II. Condensor Water Pump</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="chiller_cwp_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="on">On</option>
                                    <option value="off">Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press (kg/cm2)</label>
                                <input type="text" name="chiller_cwp_press" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">III. Chilled Water Pump</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="chiller_chwp_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="on">On</option>
                                    <option value="off">Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press In (kg/cm2)</label>
                                <input type="text" name="chiller_chwp_in" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press Out (kg/cm2)</label>
                                <input type="text" name="chiller_chwp_out" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-cooling" class="tab-pane p-5 sm:p-7 space-y-4 overflow-y-auto flex-1 min-h-0 hidden">
                    <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 space-y-4">
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                            <select name="ct_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                <option value="1">1</option>
                                <option value="2">2</option>
                                <option value="on">On</option>
                                <option value="off">Off</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Level (%)</label>
                            <input type="text" name="ct_level" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Test (TDS:PH)</label>
                            <input type="text" name="ct_test" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                    </div>
                </div>

                <div id="tab-ro" class="tab-pane p-5 sm:p-7 space-y-4 overflow-y-auto flex-1 min-h-0 hidden">
                    <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 space-y-4">
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Meter (m3)</label>
                            <input type="text" name="ro_meter" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Permeate (m3/h)</label>
                            <input type="text" name="ro_permeate" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">TDS ppm / PH Water Permeate</label>
                            <input type="text" name="ro_test_permeate" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">TDS ppm / PH Deep Well</label>
                            <input type="text" name="ro_test_deepwell" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                    </div>
                </div>

                <div id="tab-pool" class="tab-pane p-5 sm:p-7 space-y-4 overflow-y-auto flex-1 min-h-0 hidden">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">I. Lagoon 1</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm System</label>
                                    <select name="pool_l1_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on">On</option>
                                        <option value="off">Off</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Pump Running Unit Op</label>
                                    <input type="text" name="pool_l1_pump" value="1" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Pressure Tank (kg/cm2)</label>
                                    <input type="text" name="pool_l1_press" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Submersible Pump (On/Auto)</label>
                                    <select name="pool_l1_sub_auto" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="auto">Auto</option>
                                        <option value="on">On</option>
                                        <option value="off">Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">II. Lagoon 2</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm System</label>
                                    <select name="pool_l2_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on">On</option>
                                        <option value="off">Off</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Pump Running Unit Op</label>
                                    <input type="text" name="pool_l2_pump" value="1" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Pressure Tank (kg/cm2)</label>
                                    <input type="text" name="pool_l2_press" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Submersible Pump (On/Auto)</label>
                                    <select name="pool_l2_sub_auto" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="auto">Auto</option>
                                        <option value="on">On</option>
                                        <option value="off">Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">III. Aquavitale</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm System</label>
                                    <select name="pool_aqua_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on">On</option>
                                        <option value="off">Off</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Pump Running Unit Op</label>
                                    <input type="text" name="pool_aqua_pump" value="7" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">HWB Temp (°C)</label>
                                    <input type="text" name="pool_aqua_hwbtemp" value="" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Submersible Pump (On/Auto)</label>
                                    <select name="pool_aqua_sub_auto" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="auto">Auto</option>
                                        <option value="on">On</option>
                                        <option value="off">Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">IV. Main Pump Room</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm System</label>
                                    <select name="pool_mpr_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on">On</option>
                                        <option value="off">Off</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Submersible Pump (On/Auto)</label>
                                    <select name="pool_mpr_sub_auto" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="auto">Auto</option>
                                        <option value="on">On</option>
                                        <option value="off">Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-gas" class="tab-pane p-5 sm:p-7 space-y-4 overflow-y-auto flex-1 min-h-0 hidden">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">I. Gas Detector Boneka Resto</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Selenoid Valve</label>
                                    <select name="gas_boneka_valve" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="open">Open</option>
                                        <option value="close">Close</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm</label>
                                    <select name="gas_boneka_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on">On</option>
                                        <option value="off">Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">II. Gas Detector Main Kitchen</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Selenoid Valve</label>
                                    <select name="gas_mainkitchen_valve" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="open">Open</option>
                                        <option value="close">Close</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm</label>
                                    <select name="gas_mainkitchen_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on">On</option>
                                        <option value="off">Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">III. Gas Detector Kayu Puti Resto</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Selenoid Valve</label>
                                    <select name="gas_kayuputih_valve" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="open">Open</option>
                                        <option value="close">Close</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm</label>
                                    <select name="gas_kayuputih_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on">On</option>
                                        <option value="off">Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="px-5 sm:px-7 py-4 border-t border-slate-100 bg-slate-50 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 flex-shrink-0">
                    <p class="text-[11px] text-slate-500 font-semibold"><i class="fas fa-lock text-slate-400 mr-1"></i> data otomatis tersimpan &amp; tercatat di report summary energy.</p>
                    <div class="flex items-center gap-2 justify-end">
                        <button type="button" onclick="document.getElementById('addLogModal').classList.add('hidden')" class="px-4 py-2.5 rounded-xl bg-white text-slate-700 text-[12px] font-bold border border-slate-200 hover:bg-slate-100 transition">
                            Batal
                        </button>
                        <button type="submit" class="px-6 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-[12px] font-bold shadow-md transition inline-flex items-center gap-2">
                            <i class="fas fa-save text-[11px]"></i> Simpan Log Baru
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================================================== -->
<!-- 🔲 MODAL: EDIT LOG (muncul jika ?edit=ID ada)         -->
<!-- ===================================================== -->
<?php if ($editData):
    $edTgl = $editData['log_date'] ?? date('Y-m-d');
    $edShift = $editData['shift'] ?? 'pagi';
    $edSec = ['trafo'=>['units'=>[1=>['temp_c'=>0,'ampere_lvdp'=>0,'oil_level_pct'=>0],2=>['temp_c'=>0,'ampere_lvdp'=>0,'oil_level_pct'=>0]]],'genset'=>['gen_1_volt'=>0,'gen_2_volt'=>0,'gen_3_volt'=>0,'fuel_tank_liter'=>0],'pump_room'=>['steam_boiler'=>['unit_op'=>'off','sb1_running_hours'=>'','sb2_running_hours'=>'','water_test_tds_ph'=>'','steam_pressure_kgcm2'=>'','time_blow_down'=>'','econ_temp_c'=>'','econ_press_psi_kgcm2'=>''],'hot_water_boiler'=>['unit_op'=>'off','hwb1_running_hours'=>'','hwb2_running_hours'=>'','hw_temp_c'=>'','water_test_tds_ph'=>'','circ_pump_unit_op'=>'','flow_press_psi_kgcm2'=>'','return_press_psi_kgcm2'=>''],'ground_tank'=>['raw_tank_level_pct_tds_ph'=>'','treated_tank_level_pct_tds_ph'=>'','irigation_tank_level_pct'=>''],'hydrant_pump'=>['unit_standby_auto'=>'auto','press_pump1'=>'','press_pump2'=>''],'jockey_pump'=>['standby_press_kgcm2'=>''],'sand_filter'=>['status'=>'off','water_press_sand_psi_kgcm2'=>'','water_press_carbon_psi_kgcm2'=>''],'sand_filter_pump'=>['status'=>'off','unit_op'=>'','water_press_psi_kgcm2'=>''],'booster_pump_villa'=>['unit_op'=>'0','water_press_psi_kgcm2'=>''],'booster_pump_main_house'=>['unit_op'=>'0','water_press_psi_kgcm2'=>''],'irrigation_pump'=>['unit_op'=>'0','water_press_psi_kgcm2'=>'']],'chiller_system'=>['chiller'=>['unit_op'=>'carrier','chilled_water_test_tds_ph'=>''],'condensor_water_pump'=>['unit_op'=>'1','water_press_kgcm2'=>''],'chilled_water_pump'=>['unit_op'=>'1','water_press_in_kgcm2'=>'','water_press_out_kgcm2'=>'']],'cooling_tower'=>['unit_op'=>'1','water_level_pct'=>'','water_test_tds_ph'=>''],'reverse_osmosis'=>['water_meter_m3'=>'','water_permeate_m3ph'=>'','tds_ph_permeate'=>'','tds_ph_deep_well'=>''],'pool_system'=>['lagoon_1'=>['alarm_on_off'=>'on','pump_running_unit_op'=>'1','pressure_tank_kgcm2'=>'','submersible_auto'=>'auto'],'lagoon_2'=>['alarm_on_off'=>'on','pump_running_unit_op'=>'1','pressure_tank_kgcm2'=>'','submersible_auto'=>'auto'],'aquavitale'=>['alarm_on_off'=>'on','pump_running_unit_op'=>'7','hot_water_boiler_temp_c'=>'','submersible_auto'=>'auto'],'main_pump_room'=>['alarm_on_off'=>'on','submersible_auto'=>'auto']],'gas_system'=>['detector_boneka_resto'=>['selenoid_valve_open_close'=>'open','alarm_on_off'=>'on'],'detector_main_kitchen'=>['selenoid_valve_open_close'=>'open','alarm_on_off'=>'on'],'detector_kayu_puti_resto'=>['selenoid_valve_open_close'=>'open','alarm_on_off'=>'on']]];
    if (!empty($editData['equipment_id'])) {
        try {
            $eqRow = $db->fetchOne("SELECT section_data FROM equipment_logs WHERE id = ? LIMIT 1", [(int)$editData['equipment_id']]);
            if (!empty($eqRow['section_data'])) { $dec = json_decode((string)$eqRow['section_data'], true); if (is_array($dec)) $edSec = array_replace_recursive($edSec, $dec); }
        } catch (Throwable $ex) {}
    }
?>
<div id="editLogModal" class="fixed inset-0 z-[9999]">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="location.href='<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>' + location.hash.replace('#editLogModal','').replace(/^[#?]+/, '')"></div>
    <div class="relative min-h-screen flex items-center justify-center p-4 sm:p-6">
        <div class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden animate-slide-up max-h-[90vh] flex flex-col">
            <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="p-0 flex flex-col flex-1 min-h-0">
                <input type="hidden" name="action" value="edit_log">
                <input type="hidden" name="id" value="<?= (int)$editData['id'] ?>">
                <div class="px-5 sm:px-7 py-4 flex items-center justify-between border-b border-slate-100 bg-amber-50 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-600 text-white flex items-center justify-center shadow-md">
                            <i class="fas fa-pencil text-sm"></i>
                        </div>
                        <div>
                            <h2 class="font-black text-lg text-primary">Edit Log Energi</h2>
                            <p class="text-[11px] text-slate-500 font-semibold">Perbarui data log • ID #<?= (int)$editData['id'] ?>.</p>
                        </div>
                    </div>
                    <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="w-9 h-9 rounded-lg flex items-center justify-center text-slate-500 hover:text-slate-700 hover:bg-slate-200 transition">
                        <i class="fas fa-xmark text-lg"></i>
                    </a>
                </div>

                <div class="flex items-center gap-1 overflow-x-auto px-5 sm:px-7 pt-3 bg-amber-50 border-b border-slate-200 tab-bar hide-scrollbar -mx-0">
                    <button type="button" onclick="switchTab(event,'tab-energi-edit')" class="tab-btn active shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-black whitespace-nowrap bg-slate-900 text-white">⚡ Energi</button>
                    <button type="button" onclick="switchTab(event,'tab-trafo-edit')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">⚡ Trafo</button>
                    <button type="button" onclick="switchTab(event,'tab-genset-edit')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">🔋 Genset</button>
                    <button type="button" onclick="switchTab(event,'tab-pump-edit')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">💧 Pump Room</button>
                    <button type="button" onclick="switchTab(event,'tab-chiller-edit')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">❄️ Chiller</button>
                    <button type="button" onclick="switchTab(event,'tab-cooling-edit')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">🌀 Cooling Twr</button>
                    <button type="button" onclick="switchTab(event,'tab-ro-edit')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">🧪 Reverse Osmosis</button>
                    <button type="button" onclick="switchTab(event,'tab-pool-edit')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">🏊 Pool System</button>
                    <button type="button" onclick="switchTab(event,'tab-gas-edit')" class="tab-btn shrink-0 px-3 py-2 rounded-t-lg text-[11px] sm:text-xs font-bold whitespace-nowrap text-slate-600 hover:bg-slate-100">🔥 Gas Detector</button>
                </div>

                <div id="tab-energi-edit" class="tab-pane p-5 sm:p-7 space-y-5 overflow-y-auto flex-1 min-h-0">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-[11px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Tanggal <span class="text-rose-500">*</span></label>
                            <input type="date" name="log_date" value="<?= htmlspecialchars($edTgl) ?>" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div>
                            <label class="text-[11px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Shift <span class="text-rose-500">*</span></label>
                            <select name="shift" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                <option value="pagi"  <?= $edShift === 'pagi'  ? 'selected' : '' ?>>Pagi (07.00 - 15.00)</option>
                                <option value="siang" <?= $edShift === 'siang' ? 'selected' : '' ?>>Siang (15.00 - 23.00)</option>
                                <option value="malam" <?= $edShift === 'malam' ? 'selected' : '' ?>>Malam (23.00 - 07.00)</option>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-3 border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70">
                        <div class="flex items-center justify-between">
                            <p class="text-[10px] font-black uppercase tracking-[2px] text-slate-500"><i class="fas fa-bolt text-amber-500 mr-1.5"></i> Listrik PLN (kWh)</p>
                            <span class="text-[9px] text-slate-400 font-semibold uppercase">total = lwbp + wbp (auto hitung)</span>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">LWBP</label>
                                <input type="number" step="0.01" min="0" name="pln_lwbp_kwh" value="<?= (float)($editData['pln_lwbp_kwh'] ?? 0) ?>" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">WBP</label>
                                <input type="number" step="0.01" min="0" name="pln_wbp_kwh" value="<?= (float)($editData['pln_wbp_kwh'] ?? 0) ?>" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div class="flex items-end">
                                <div class="w-full px-3 py-2.5 rounded-xl border-2 border-slate-800/10 bg-slate-100 text-slate-700 text-sm font-black font-mono text-right">
                                    <span data-role="pln-total-edit"><?= fmtNum((float)(($editData['pln_lwbp_kwh'] ?? 0) + ($editData['pln_wbp_kwh'] ?? 0)), 1) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3 border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70">
                        <p class="text-[10px] font-black uppercase tracking-[2px] text-slate-500"><i class="fas fa-industry text-slate-500 mr-1.5"></i> Sumber Daya Lain</p>
                        <div class="grid grid-cols-2 sm:grid-cols-6 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Genset (kWh)</label>
                                <input type="number" step="0.01" min="0" name="genset_kwh" value="<?= (float)($editData['genset_kwh'] ?? 0) ?>" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Solar (L)</label>
                                <input type="number" step="0.01" min="0" name="solar_liter" value="<?= (float)($editData['solar_liter'] ?? 0) ?>" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Gas LPG (Kg)</label>
                                <input type="number" step="0.01" min="0" name="gas_kg" value="<?= (float)($editData['gas_kg'] ?? 0) ?>" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Gas LNG (Kg)</label>
                                <input type="number" step="0.01" min="0" name="gas_lng_kg" value="<?= (float)($editData['gas_lng_kg'] ?? 0) ?>" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Air PDAM (m3)</label>
                                <input type="number" step="0.01" min="0" name="air_m3" value="<?= (float)($editData['air_m3'] ?? 0) ?>" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Deep Well (m3)</label>
                                <input type="number" step="0.01" min="0" name="air_deep_well_m3" value="<?= (float)($editData['air_deep_well_m3'] ?? 0) ?>" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="text-[11px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">PIC / Nama Petugas <span class="text-rose-500">*</span></label>
                        <input type="text" name="pic_name" value="<?= htmlspecialchars((string)($editData['pic_name'] ?? $userName)) ?>" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                    </div>

                    <div>
                        <label class="text-[11px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Catatan (Opsional)</label>
                        <textarea name="notes" rows="2" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600 resize-y"><?= htmlspecialchars((string)($editData['notes'] ?? '')) ?></textarea>
                    </div>
                </div>

                <div id="tab-trafo-edit" class="tab-pane p-5 sm:p-7 space-y-5 overflow-y-auto flex-1 min-h-0 hidden">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">1. Trafo 1</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Temp. (°C)</label>
                                    <input type="number" step="0.1" min="0" name="trafo_1_temp" value="<?= (float)($edSec['trafo']['units'][1]['temp_c'] ?? 0) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Ampere (A) / LVDP 1</label>
                                    <input type="number" step="0.1" min="0" name="trafo_1_ampere" value="<?= (float)($edSec['trafo']['units'][1]['ampere_lvdp'] ?? 0) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Oil Level (%)</label>
                                    <input type="number" step="0.1" min="0" max="100" name="trafo_1_oil" value="<?= (float)($edSec['trafo']['units'][1]['oil_level_pct'] ?? 0) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">2. Trafo 2</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Temp. (°C)</label>
                                    <input type="number" step="0.1" min="0" name="trafo_2_temp" value="<?= (float)($edSec['trafo']['units'][2]['temp_c'] ?? 0) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Ampere (A) / LVDP 2</label>
                                    <input type="number" step="0.1" min="0" name="trafo_2_ampere" value="<?= (float)($edSec['trafo']['units'][2]['ampere_lvdp'] ?? 0) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Oil Level (%)</label>
                                    <input type="number" step="0.1" min="0" max="100" name="trafo_2_oil" value="<?= (float)($edSec['trafo']['units'][2]['oil_level_pct'] ?? 0) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-genset-edit" class="tab-pane p-5 sm:p-7 space-y-5 overflow-y-auto flex-1 min-h-0 hidden">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">1. Genset 1 (V)</h3>
                            <input type="number" step="0.1" min="0" name="genset_1_volt" value="<?= (float)($edSec['genset']['gen_1_volt'] ?? 0) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">2. Genset 2 (V)</h3>
                            <input type="number" step="0.1" min="0" name="genset_2_volt" value="<?= (float)($edSec['genset']['gen_2_volt'] ?? 0) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">3. Genset 3 (V)</h3>
                            <input type="number" step="0.1" min="0" name="genset_3_volt" value="<?= (float)($edSec['genset']['gen_3_volt'] ?? 0) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">4. Fuel Tank (liter)</h3>
                            <input type="number" step="0.1" min="0" name="genset_fuel_tank" value="<?= (float)($edSec['genset']['fuel_tank_liter'] ?? 0) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                    </div>
                </div>

                <div id="tab-pump-edit" class="tab-pane p-5 sm:p-7 space-y-4 overflow-y-auto flex-1 min-h-0 hidden">
                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">I. Steam Boiler</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="pump_sb_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="off" <?= ($edSec['pump_room']['steam_boiler']['unit_op'] ?? 'off') === 'off' ? 'selected' : '' ?>>Off</option>
                                    <option value="on" <?= ($edSec['pump_room']['steam_boiler']['unit_op'] ?? 'off') === 'on' ? 'selected' : '' ?>>On</option>
                                    <option value="auto" <?= ($edSec['pump_room']['steam_boiler']['unit_op'] ?? 'off') === 'auto' ? 'selected' : '' ?>>Auto</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">SB1 Running Hours</label>
                                <input type="text" name="pump_sb1_hours" value="<?= htmlspecialchars((string)($edSec['pump_room']['steam_boiler']['sb1_running_hours'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">SB2 Running Hours</label>
                                <input type="text" name="pump_sb2_hours" value="<?= htmlspecialchars((string)($edSec['pump_room']['steam_boiler']['sb2_running_hours'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Test (TDS:PH)</label>
                                <input type="text" name="pump_sb_test" value="<?= htmlspecialchars((string)($edSec['pump_room']['steam_boiler']['water_test_tds_ph'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Steam Pressure (kg/cm2)</label>
                                <input type="text" name="pump_sb_press" value="<?= htmlspecialchars((string)($edSec['pump_room']['steam_boiler']['steam_pressure_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Time Blow Down</label>
                                <input type="text" name="pump_sb_blow" value="<?= htmlspecialchars((string)($edSec['pump_room']['steam_boiler']['time_blow_down'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Econ Temp (°C)</label>
                                <input type="text" name="pump_sb_econ_temp" value="<?= htmlspecialchars((string)($edSec['pump_room']['steam_boiler']['econ_temp_c'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Econ Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_sb_econ_press" value="<?= htmlspecialchars((string)($edSec['pump_room']['steam_boiler']['econ_press_psi_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">II. Hot Water Boiler (HWB)</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="pump_hwb_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="off" <?= ($edSec['pump_room']['hot_water_boiler']['unit_op'] ?? 'off') === 'off' ? 'selected' : '' ?>>Off</option>
                                    <option value="on" <?= ($edSec['pump_room']['hot_water_boiler']['unit_op'] ?? 'off') === 'on' ? 'selected' : '' ?>>On</option>
                                    <option value="auto" <?= ($edSec['pump_room']['hot_water_boiler']['unit_op'] ?? 'off') === 'auto' ? 'selected' : '' ?>>Auto</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">HWB1 Running Hours</label>
                                <input type="text" name="pump_hwb1_hours" value="<?= htmlspecialchars((string)($edSec['pump_room']['hot_water_boiler']['hwb1_running_hours'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">HWB2 Running Hours</label>
                                <input type="text" name="pump_hwb2_hours" value="<?= htmlspecialchars((string)($edSec['pump_room']['hot_water_boiler']['hwb2_running_hours'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">HW Temp (°C)</label>
                                <input type="text" name="pump_hwb_temp" value="<?= htmlspecialchars((string)($edSec['pump_room']['hot_water_boiler']['hw_temp_c'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Test (TDS:PH)</label>
                                <input type="text" name="pump_hwb_test" value="<?= htmlspecialchars((string)($edSec['pump_room']['hot_water_boiler']['water_test_tds_ph'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Circ Pump Unit Op</label>
                                <input type="text" name="pump_hwb_circ_op" value="<?= htmlspecialchars((string)($edSec['pump_room']['hot_water_boiler']['circ_pump_unit_op'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Flow Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_hwb_flow" value="<?= htmlspecialchars((string)($edSec['pump_room']['hot_water_boiler']['flow_press_psi_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Return Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_hwb_ret" value="<?= htmlspecialchars((string)($edSec['pump_room']['hot_water_boiler']['return_press_psi_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">III. Ground Tank</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Raw Tank (Level%:TDS:PH)</label>
                                <input type="text" name="pump_tank_raw" value="<?= htmlspecialchars((string)($edSec['pump_room']['ground_tank']['raw_tank_level_pct_tds_ph'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Treated Tank (Level%:TDS:PH)</label>
                                <input type="text" name="pump_tank_treated" value="<?= htmlspecialchars((string)($edSec['pump_room']['ground_tank']['treated_tank_level_pct_tds_ph'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Irigation Tank (Level%)</label>
                                <input type="text" name="pump_tank_irigasi" value="<?= htmlspecialchars((string)($edSec['pump_room']['ground_tank']['irigation_tank_level_pct'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">IV. Hydrant Pump</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Standby / Auto</label>
                                <select name="pump_hyd_standby" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="auto" <?= ($edSec['pump_room']['hydrant_pump']['unit_standby_auto'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto</option>
                                    <option value="standby" <?= ($edSec['pump_room']['hydrant_pump']['unit_standby_auto'] ?? 'auto') === 'standby' ? 'selected' : '' ?>>Standby</option>
                                    <option value="on" <?= ($edSec['pump_room']['hydrant_pump']['unit_standby_auto'] ?? 'auto') === 'on' ? 'selected' : '' ?>>On</option>
                                    <option value="off" <?= ($edSec['pump_room']['hydrant_pump']['unit_standby_auto'] ?? 'auto') === 'off' ? 'selected' : '' ?>>Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Press Pump 1</label>
                                <input type="text" name="pump_hyd_press1" value="<?= htmlspecialchars((string)($edSec['pump_room']['hydrant_pump']['press_pump1'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Press Pump 2</label>
                                <input type="text" name="pump_hyd_press2" value="<?= htmlspecialchars((string)($edSec['pump_room']['hydrant_pump']['press_pump2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">V. Jockey Pump</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Standby Press (kg/cm2)</label>
                                <input type="text" name="pump_jockey_press" value="<?= htmlspecialchars((string)($edSec['pump_room']['jockey_pump']['standby_press_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">VI. Sand Filter</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Status</label>
                                <select name="pump_sf_status" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="off" <?= ($edSec['pump_room']['sand_filter']['status'] ?? 'off') === 'off' ? 'selected' : '' ?>>Off</option>
                                    <option value="on" <?= ($edSec['pump_room']['sand_filter']['status'] ?? 'off') === 'on' ? 'selected' : '' ?>>On</option>
                                    <option value="backwash" <?= ($edSec['pump_room']['sand_filter']['status'] ?? 'off') === 'backwash' ? 'selected' : '' ?>>Backwash</option>
                                    <option value="rinse" <?= ($edSec['pump_room']['sand_filter']['status'] ?? 'off') === 'rinse' ? 'selected' : '' ?>>Rinse</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press Sand (psi/kg/cm2)</label>
                                <input type="text" name="pump_sf_press_sand" value="<?= htmlspecialchars((string)($edSec['pump_room']['sand_filter']['water_press_sand_psi_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press Carbon (psi/kg/cm2)</label>
                                <input type="text" name="pump_sf_press_carbon" value="<?= htmlspecialchars((string)($edSec['pump_room']['sand_filter']['water_press_carbon_psi_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">VII. Sand Filter Pump</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Status</label>
                                <select name="pump_sfp_status" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="off" <?= ($edSec['pump_room']['sand_filter_pump']['status'] ?? 'off') === 'off' ? 'selected' : '' ?>>Off</option>
                                    <option value="on" <?= ($edSec['pump_room']['sand_filter_pump']['status'] ?? 'off') === 'on' ? 'selected' : '' ?>>On</option>
                                    <option value="auto" <?= ($edSec['pump_room']['sand_filter_pump']['status'] ?? 'off') === 'auto' ? 'selected' : '' ?>>Auto</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Op</label>
                                <input type="text" name="pump_sfp_unit_op" value="<?= htmlspecialchars((string)($edSec['pump_room']['sand_filter_pump']['unit_op'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_sfp_press" value="<?= htmlspecialchars((string)($edSec['pump_room']['sand_filter_pump']['water_press_psi_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">VIII. Booster Pump for Villa</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="pump_bpv_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="0" <?= ($edSec['pump_room']['booster_pump_villa']['unit_op'] ?? '0') === '0' ? 'selected' : '' ?>>0</option>
                                    <option value="1" <?= ($edSec['pump_room']['booster_pump_villa']['unit_op'] ?? '0') === '1' ? 'selected' : '' ?>>1</option>
                                    <option value="2" <?= ($edSec['pump_room']['booster_pump_villa']['unit_op'] ?? '0') === '2' ? 'selected' : '' ?>>2</option>
                                    <option value="on" <?= ($edSec['pump_room']['booster_pump_villa']['unit_op'] ?? '0') === 'on' ? 'selected' : '' ?>>On</option>
                                    <option value="off" <?= ($edSec['pump_room']['booster_pump_villa']['unit_op'] ?? '0') === 'off' ? 'selected' : '' ?>>Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_bpv_press" value="<?= htmlspecialchars((string)($edSec['pump_room']['booster_pump_villa']['water_press_psi_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">IX. Booster Pump Main House</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="pump_bpm_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="0" <?= ($edSec['pump_room']['booster_pump_main_house']['unit_op'] ?? '0') === '0' ? 'selected' : '' ?>>0</option>
                                    <option value="1" <?= ($edSec['pump_room']['booster_pump_main_house']['unit_op'] ?? '0') === '1' ? 'selected' : '' ?>>1</option>
                                    <option value="2" <?= ($edSec['pump_room']['booster_pump_main_house']['unit_op'] ?? '0') === '2' ? 'selected' : '' ?>>2</option>
                                    <option value="on" <?= ($edSec['pump_room']['booster_pump_main_house']['unit_op'] ?? '0') === 'on' ? 'selected' : '' ?>>On</option>
                                    <option value="off" <?= ($edSec['pump_room']['booster_pump_main_house']['unit_op'] ?? '0') === 'off' ? 'selected' : '' ?>>Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_bpm_press" value="<?= htmlspecialchars((string)($edSec['pump_room']['booster_pump_main_house']['water_press_psi_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">X. Irrigation Pump</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="pump_irigasi_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="0" <?= ($edSec['pump_room']['irrigation_pump']['unit_op'] ?? '0') === '0' ? 'selected' : '' ?>>0</option>
                                    <option value="1" <?= ($edSec['pump_room']['irrigation_pump']['unit_op'] ?? '0') === '1' ? 'selected' : '' ?>>1</option>
                                    <option value="2" <?= ($edSec['pump_room']['irrigation_pump']['unit_op'] ?? '0') === '2' ? 'selected' : '' ?>>2</option>
                                    <option value="on" <?= ($edSec['pump_room']['irrigation_pump']['unit_op'] ?? '0') === 'on' ? 'selected' : '' ?>>On</option>
                                    <option value="off" <?= ($edSec['pump_room']['irrigation_pump']['unit_op'] ?? '0') === 'off' ? 'selected' : '' ?>>Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press (psi/kg/cm2)</label>
                                <input type="text" name="pump_irigasi_press" value="<?= htmlspecialchars((string)($edSec['pump_room']['irrigation_pump']['water_press_psi_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-chiller-edit" class="tab-pane p-5 sm:p-7 space-y-4 overflow-y-auto flex-1 min-h-0 hidden">
                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">I. Chiller</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="chiller_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="carrier" <?= ($edSec['chiller_system']['chiller']['unit_op'] ?? 'carrier') === 'carrier' ? 'selected' : '' ?>>Carrier</option>
                                    <option value="daikin" <?= ($edSec['chiller_system']['chiller']['unit_op'] ?? 'carrier') === 'daikin' ? 'selected' : '' ?>>Daikin</option>
                                    <option value="1" <?= ($edSec['chiller_system']['chiller']['unit_op'] ?? 'carrier') === '1' ? 'selected' : '' ?>>1</option>
                                    <option value="2" <?= ($edSec['chiller_system']['chiller']['unit_op'] ?? 'carrier') === '2' ? 'selected' : '' ?>>2</option>
                                    <option value="off" <?= ($edSec['chiller_system']['chiller']['unit_op'] ?? 'carrier') === 'off' ? 'selected' : '' ?>>Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Chilled Water Test (TDS:PH)</label>
                                <input type="text" name="chiller_cw_test" value="<?= htmlspecialchars((string)($edSec['chiller_system']['chiller']['chilled_water_test_tds_ph'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">II. Condensor Water Pump</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="chiller_cwp_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="1" <?= ($edSec['chiller_system']['condensor_water_pump']['unit_op'] ?? '1') === '1' ? 'selected' : '' ?>>1</option>
                                    <option value="2" <?= ($edSec['chiller_system']['condensor_water_pump']['unit_op'] ?? '1') === '2' ? 'selected' : '' ?>>2</option>
                                    <option value="on" <?= ($edSec['chiller_system']['condensor_water_pump']['unit_op'] ?? '1') === 'on' ? 'selected' : '' ?>>On</option>
                                    <option value="off" <?= ($edSec['chiller_system']['condensor_water_pump']['unit_op'] ?? '1') === 'off' ? 'selected' : '' ?>>Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press (kg/cm2)</label>
                                <input type="text" name="chiller_cwp_press" value="<?= htmlspecialchars((string)($edSec['chiller_system']['condensor_water_pump']['water_press_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">III. Chilled Water Pump</h3>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                                <select name="chiller_chwp_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                    <option value="1" <?= ($edSec['chiller_system']['chilled_water_pump']['unit_op'] ?? '1') === '1' ? 'selected' : '' ?>>1</option>
                                    <option value="2" <?= ($edSec['chiller_system']['chilled_water_pump']['unit_op'] ?? '1') === '2' ? 'selected' : '' ?>>2</option>
                                    <option value="on" <?= ($edSec['chiller_system']['chilled_water_pump']['unit_op'] ?? '1') === 'on' ? 'selected' : '' ?>>On</option>
                                    <option value="off" <?= ($edSec['chiller_system']['chilled_water_pump']['unit_op'] ?? '1') === 'off' ? 'selected' : '' ?>>Off</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press In (kg/cm2)</label>
                                <input type="text" name="chiller_chwp_in" value="<?= htmlspecialchars((string)($edSec['chiller_system']['chilled_water_pump']['water_press_in_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Press Out (kg/cm2)</label>
                                <input type="text" name="chiller_chwp_out" value="<?= htmlspecialchars((string)($edSec['chiller_system']['chilled_water_pump']['water_press_out_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-cooling-edit" class="tab-pane p-5 sm:p-7 space-y-4 overflow-y-auto flex-1 min-h-0 hidden">
                    <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 space-y-4">
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Unit Operation</label>
                            <select name="ct_unit_op" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                <option value="1" <?= ($edSec['cooling_tower']['unit_op'] ?? '1') === '1' ? 'selected' : '' ?>>1</option>
                                <option value="2" <?= ($edSec['cooling_tower']['unit_op'] ?? '1') === '2' ? 'selected' : '' ?>>2</option>
                                <option value="on" <?= ($edSec['cooling_tower']['unit_op'] ?? '1') === 'on' ? 'selected' : '' ?>>On</option>
                                <option value="off" <?= ($edSec['cooling_tower']['unit_op'] ?? '1') === 'off' ? 'selected' : '' ?>>Off</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Level (%)</label>
                            <input type="text" name="ct_level" value="<?= htmlspecialchars((string)($edSec['cooling_tower']['water_level_pct'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Test (TDS:PH)</label>
                            <input type="text" name="ct_test" value="<?= htmlspecialchars((string)($edSec['cooling_tower']['water_test_tds_ph'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                    </div>
                </div>

                <div id="tab-ro-edit" class="tab-pane p-5 sm:p-7 space-y-4 overflow-y-auto flex-1 min-h-0 hidden">
                    <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50/70 space-y-4">
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Meter (m3)</label>
                            <input type="text" name="ro_meter" value="<?= htmlspecialchars((string)($edSec['reverse_osmosis']['water_meter_m3'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Water Permeate (m3/h)</label>
                            <input type="text" name="ro_permeate" value="<?= htmlspecialchars((string)($edSec['reverse_osmosis']['water_permeate_m3ph'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">TDS ppm / PH Water Permeate</label>
                            <input type="text" name="ro_test_permeate" value="<?= htmlspecialchars((string)($edSec['reverse_osmosis']['tds_ph_permeate'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">TDS ppm / PH Deep Well</label>
                            <input type="text" name="ro_test_deepwell" value="<?= htmlspecialchars((string)($edSec['reverse_osmosis']['tds_ph_deep_well'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                        </div>
                    </div>
                </div>

                <div id="tab-pool-edit" class="tab-pane p-5 sm:p-7 space-y-4 overflow-y-auto flex-1 min-h-0 hidden">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">I. Lagoon 1</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm System</label>
                                    <select name="pool_l1_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on" <?= ($edSec['pool_system']['lagoon_1']['alarm_on_off'] ?? 'on') === 'on' ? 'selected' : '' ?>>On</option>
                                        <option value="off" <?= ($edSec['pool_system']['lagoon_1']['alarm_on_off'] ?? 'on') === 'off' ? 'selected' : '' ?>>Off</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Pump Running Unit Op</label>
                                    <input type="text" name="pool_l1_pump" value="<?= htmlspecialchars((string)($edSec['pool_system']['lagoon_1']['pump_running_unit_op'] ?? '1')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Pressure Tank (kg/cm2)</label>
                                    <input type="text" name="pool_l1_press" value="<?= htmlspecialchars((string)($edSec['pool_system']['lagoon_1']['pressure_tank_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Submersible Pump (On/Auto)</label>
                                    <select name="pool_l1_sub_auto" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="auto" <?= ($edSec['pool_system']['lagoon_1']['submersible_auto'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto</option>
                                        <option value="on" <?= ($edSec['pool_system']['lagoon_1']['submersible_auto'] ?? 'auto') === 'on' ? 'selected' : '' ?>>On</option>
                                        <option value="off" <?= ($edSec['pool_system']['lagoon_1']['submersible_auto'] ?? 'auto') === 'off' ? 'selected' : '' ?>>Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">II. Lagoon 2</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm System</label>
                                    <select name="pool_l2_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on" <?= ($edSec['pool_system']['lagoon_2']['alarm_on_off'] ?? 'on') === 'on' ? 'selected' : '' ?>>On</option>
                                        <option value="off" <?= ($edSec['pool_system']['lagoon_2']['alarm_on_off'] ?? 'on') === 'off' ? 'selected' : '' ?>>Off</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Pump Running Unit Op</label>
                                    <input type="text" name="pool_l2_pump" value="<?= htmlspecialchars((string)($edSec['pool_system']['lagoon_2']['pump_running_unit_op'] ?? '1')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Pressure Tank (kg/cm2)</label>
                                    <input type="text" name="pool_l2_press" value="<?= htmlspecialchars((string)($edSec['pool_system']['lagoon_2']['pressure_tank_kgcm2'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Submersible Pump (On/Auto)</label>
                                    <select name="pool_l2_sub_auto" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="auto" <?= ($edSec['pool_system']['lagoon_2']['submersible_auto'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto</option>
                                        <option value="on" <?= ($edSec['pool_system']['lagoon_2']['submersible_auto'] ?? 'auto') === 'on' ? 'selected' : '' ?>>On</option>
                                        <option value="off" <?= ($edSec['pool_system']['lagoon_2']['submersible_auto'] ?? 'auto') === 'off' ? 'selected' : '' ?>>Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">III. Aquavitale</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm System</label>
                                    <select name="pool_aqua_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on" <?= ($edSec['pool_system']['aquavitale']['alarm_on_off'] ?? 'on') === 'on' ? 'selected' : '' ?>>On</option>
                                        <option value="off" <?= ($edSec['pool_system']['aquavitale']['alarm_on_off'] ?? 'on') === 'off' ? 'selected' : '' ?>>Off</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Pump Running Unit Op</label>
                                    <input type="text" name="pool_aqua_pump" value="<?= htmlspecialchars((string)($edSec['pool_system']['aquavitale']['pump_running_unit_op'] ?? '7')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">HWB Temp (°C)</label>
                                    <input type="text" name="pool_aqua_hwbtemp" value="<?= htmlspecialchars((string)($edSec['pool_system']['aquavitale']['hot_water_boiler_temp_c'] ?? '')) ?>" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Submersible Pump (On/Auto)</label>
                                    <select name="pool_aqua_sub_auto" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="auto" <?= ($edSec['pool_system']['aquavitale']['submersible_auto'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto</option>
                                        <option value="on" <?= ($edSec['pool_system']['aquavitale']['submersible_auto'] ?? 'auto') === 'on' ? 'selected' : '' ?>>On</option>
                                        <option value="off" <?= ($edSec['pool_system']['aquavitale']['submersible_auto'] ?? 'auto') === 'off' ? 'selected' : '' ?>>Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">IV. Main Pump Room</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm System</label>
                                    <select name="pool_mpr_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on" <?= ($edSec['pool_system']['main_pump_room']['alarm_on_off'] ?? 'on') === 'on' ? 'selected' : '' ?>>On</option>
                                        <option value="off" <?= ($edSec['pool_system']['main_pump_room']['alarm_on_off'] ?? 'on') === 'off' ? 'selected' : '' ?>>Off</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Submersible Pump (On/Auto)</label>
                                    <select name="pool_mpr_sub_auto" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="auto" <?= ($edSec['pool_system']['main_pump_room']['submersible_auto'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto</option>
                                        <option value="on" <?= ($edSec['pool_system']['main_pump_room']['submersible_auto'] ?? 'auto') === 'on' ? 'selected' : '' ?>>On</option>
                                        <option value="off" <?= ($edSec['pool_system']['main_pump_room']['submersible_auto'] ?? 'auto') === 'off' ? 'selected' : '' ?>>Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-gas-edit" class="tab-pane p-5 sm:p-7 space-y-4 overflow-y-auto flex-1 min-h-0 hidden">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">I. Gas Detector Boneka Resto</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Selenoid Valve</label>
                                    <select name="gas_boneka_valve" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="open" <?= ($edSec['gas_system']['detector_boneka_resto']['selenoid_valve_open_close'] ?? 'open') === 'open' ? 'selected' : '' ?>>Open</option>
                                        <option value="close" <?= ($edSec['gas_system']['detector_boneka_resto']['selenoid_valve_open_close'] ?? 'open') === 'close' ? 'selected' : '' ?>>Close</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm</label>
                                    <select name="gas_boneka_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on" <?= ($edSec['gas_system']['detector_boneka_resto']['alarm_on_off'] ?? 'on') === 'on' ? 'selected' : '' ?>>On</option>
                                        <option value="off" <?= ($edSec['gas_system']['detector_boneka_resto']['alarm_on_off'] ?? 'on') === 'off' ? 'selected' : '' ?>>Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">II. Gas Detector Main Kitchen</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Selenoid Valve</label>
                                    <select name="gas_mainkitchen_valve" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="open" <?= ($edSec['gas_system']['detector_main_kitchen']['selenoid_valve_open_close'] ?? 'open') === 'open' ? 'selected' : '' ?>>Open</option>
                                        <option value="close" <?= ($edSec['gas_system']['detector_main_kitchen']['selenoid_valve_open_close'] ?? 'open') === 'close' ? 'selected' : '' ?>>Close</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm</label>
                                    <select name="gas_mainkitchen_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on" <?= ($edSec['gas_system']['detector_main_kitchen']['alarm_on_off'] ?? 'on') === 'on' ? 'selected' : '' ?>>On</option>
                                        <option value="off" <?= ($edSec['gas_system']['detector_main_kitchen']['alarm_on_off'] ?? 'on') === 'off' ? 'selected' : '' ?>>Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-xl p-3 sm:p-4 bg-slate-50">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-700 mb-3">III. Gas Detector Kayu Puti Resto</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Selenoid Valve</label>
                                    <select name="gas_kayuputih_valve" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="open" <?= ($edSec['gas_system']['detector_kayu_puti_resto']['selenoid_valve_open_close'] ?? 'open') === 'open' ? 'selected' : '' ?>>Open</option>
                                        <option value="close" <?= ($edSec['gas_system']['detector_kayu_puti_resto']['selenoid_valve_open_close'] ?? 'open') === 'close' ? 'selected' : '' ?>>Close</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Alarm</label>
                                    <select name="gas_kayuputih_alarm" class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                                        <option value="on" <?= ($edSec['gas_system']['detector_kayu_puti_resto']['alarm_on_off'] ?? 'on') === 'on' ? 'selected' : '' ?>>On</option>
                                        <option value="off" <?= ($edSec['gas_system']['detector_kayu_puti_resto']['alarm_on_off'] ?? 'on') === 'off' ? 'selected' : '' ?>>Off</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="px-5 sm:px-7 py-4 border-t border-slate-100 bg-amber-50 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 flex-shrink-0">
                    <p class="text-[11px] text-slate-500 font-semibold"><i class="fas fa-clock text-slate-400 mr-1"></i> last updated: <?= htmlspecialchars($editData['updated_at'] ?? '-') ?></p>
                    <div class="flex items-center gap-2 justify-end">
                        <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="px-4 py-2.5 rounded-xl bg-white text-slate-700 text-[12px] font-bold border border-slate-200 hover:bg-slate-100 transition inline-flex items-center justify-center">
                            Batal
                        </a>
                        <button type="submit" class="px-6 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-[12px] font-bold shadow-md transition inline-flex items-center gap-2">
                            <i class="fas fa-pencil text-[11px]"></i> Update Log
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function(){
    function fmt(n, d){
        n = parseFloat(n || 0);
        if (isNaN(n)) n = 0;
        return n.toLocaleString('id-ID', {minimumFractionDigits: d, maximumFractionDigits: d});
    }
    function bindAdd(){
        var lw = document.querySelector('input[name="pln_lwbp_kwh"]');
        var wb = document.querySelector('input[name="pln_wbp_kwh"]');
        var tv = document.querySelector('[data-role="pln-total"]');
        if(!lw || !wb || !tv) return;
        function up(){ tv.textContent = fmt((parseFloat(lw.value)||0) + (parseFloat(wb.value)||0), 1); }
        lw.addEventListener('input', up);
        wb.addEventListener('input', up);
        up();
    }
    function bindEdit(){
        var lw = document.querySelectorAll('input[name="pln_lwbp_kwh"]')[1];
        var wb = document.querySelectorAll('input[name="pln_wbp_kwh"]')[1];
        var tv = document.querySelector('[data-role="pln-total-edit"]');
        if(!lw || !wb || !tv) return;
        function up(){ tv.textContent = fmt((parseFloat(lw.value)||0) + (parseFloat(wb.value)||0), 1); }
        lw.addEventListener('input', up);
        wb.addEventListener('input', up);
        up();
    }
    bindAdd();
    bindEdit();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
