<?php
$pageTitle = 'Review Detail Daily Log';
require_once __DIR__ . '/../config/config.php';
requireRole(['supervisor', 'manager']); // Manager Access All

$db = Database::getInstance();
$user = currentUser();

$logId = (int)($_GET['id'] ?? 0);
$log = $db->fetchOne(
    "SELECT dl.*, u.name as engineer_name, u.email as engineer_email, u.position as engineer_position, u.phone as engineer_phone
     FROM daily_logs dl
     LEFT JOIN users u ON dl.engineer_id = u.id
     WHERE dl.id = ?",
    [$logId]
);

if (!$log) {
    setFlash('error', 'Daily Log tidak ditemukan');
    redirect('supervisor/review.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $signature = $_POST['signature_data'] ?? '';
    $revisionNotes = trim($_POST['revision_notes'] ?? '');

    if ($action === 'approve') {
        if (empty($signature)) {
            setFlash('error', 'Tanda tangan digital wajib diisi! Silakan tanda tangan di area yang tersedia.');
            redirect('supervisor/review_detail.php?id=' . $logId);
        } else {
            $sigFilename = null;
            if (!empty($signature) && strpos($signature, 'data:image') === 0) {
                $sigFilename = uniqid('sig_') . '.png';
                $sigPath = UPLOAD_PATH . $sigFilename;
                $sigData = explode(',', $signature)[1];
                file_put_contents($sigPath, base64_decode($sigData));
            }

            $db->update('daily_logs', [
                'status' => 'approved',
                'supervisor_id' => $user['id'],
                'supervisor_signature' => $sigFilename,
                'approved_at' => date('Y-m-d H:i:s'),
                'revision_notes' => null,
            ], 'id = :id', ['id' => $logId]);

            setFlash('success', 'Daily Log berhasil di-Approve');
            redirect('supervisor/review.php');
        }
    } elseif ($action === 'reject') {
        if (empty($revisionNotes)) {
            setFlash('error', 'Catatan revisi wajib diisi saat reject! Silakan isi kolom catatan di bawah.');
            redirect('supervisor/review_detail.php?id=' . $logId);
        } else {
            $db->update('daily_logs', [
                'status' => 'rejected',
                'supervisor_id' => $user['id'],
                'revision_notes' => $revisionNotes,
                'supervisor_signature' => null,
                'approved_at' => null,
            ], 'id = :id', ['id' => $logId]);

            setFlash('success', 'Daily Log ditolak dengan catatan revisi');
            redirect('supervisor/review.php');
        }
    }
}

$log = $db->fetchOne(
    "SELECT dl.*, u.name as engineer_name, u.email as engineer_email, u.position as engineer_position, u.phone as engineer_phone,
     s.name as supervisor_name, s.signature_image as supervisor_sig
     FROM daily_logs dl
     LEFT JOIN users u ON dl.engineer_id = u.id
     LEFT JOIN users s ON dl.supervisor_id = s.id
     WHERE dl.id = ?",
    [$logId]
);

// --- READING METER MAIN BUILDING (Yesterday - Today → Consumption) untuk tampilan review ---
$_mbYesterday = 0.0;
$_mbYesterdayDate = null;
$_mbToday = $log && isset($log['water_main_building']) ? (float)$log['water_main_building'] : 0.0;
$_mbCons = $log && isset($log['total_water']) ? (float)$log['total_water'] : max(0.0, $_mbToday - $_mbYesterday);
if ($log && !empty($log['log_date']) && !empty($log['engineer_id'])) {
    $_yd = date('Y-m-d', strtotime($log['log_date'] . ' -1 day'));
    $_mbYesterdayDate = $_yd;
    $_yl = $db->fetchOne(
        "SELECT water_main_building FROM daily_logs WHERE engineer_id = ? AND log_date = ? LIMIT 1",
        [(int)$log['engineer_id'], $_yd]
    );
    if ($_yl && isset($_yl['water_main_building'])) $_mbYesterday = (float)$_yl['water_main_building'];
    // Recalc ulang untuk konsistensi visual (jika total_water di DB mismatch)
    $_mbCons = max(0.0, $_mbToday - $_mbYesterday);
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

// --- HELPER BADGE SHIFT VISUAL (NETRAL) ---
$__shiftVal = (!empty($log['shift']) && in_array($log['shift'], ['pagi','siang','malam'], true)) ? (string)$log['shift'] : '';
$__shiftBadge = '';
if ($__shiftVal === 'pagi') {
    $__shiftBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-100 border border-slate-200 text-slate-700 text-[11px] font-bold tracking-wide uppercase"><i class="fas fa-sun text-[10px]"></i> Pagi</span>';
} elseif ($__shiftVal === 'siang') {
    $__shiftBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-100 border border-slate-200 text-slate-700 text-[11px] font-bold tracking-wide uppercase"><i class="fas fa-cloud-sun text-[10px]"></i> Siang</span>';
} elseif ($__shiftVal === 'malam') {
    $__shiftBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-800 text-white text-[11px] font-bold tracking-wide uppercase"><i class="fas fa-moon text-[10px]"></i> Malam</span>';
} else {
    $__shiftBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-50 border border-dashed border-slate-200 text-slate-500 text-[11px] font-semibold tracking-wide uppercase"><i class="fas fa-circle-question text-[10px]"></i> Belum ada shift</span>';
}

// =========================================================
// 🧰 PARSE EQUIPMENT DATA (JSON) untuk tampilan Detail
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
$_hasEquipment = false;
if ($log && !empty($log['equipment_data'])) {
    $_eqDec = json_decode((string)$log['equipment_data'], true);
    if (is_array($_eqDec)) {
        $_hasEquipment = true;
        if (isset($_eqDec['trafo']['units'][1])) { foreach ($_eqDec['trafo']['units'][1] as $k=>$v) if (isset($eq['trafo']['units'][1][$k])) $eq['trafo']['units'][1][$k] = $v; }
        if (isset($_eqDec['trafo']['units'][2])) { foreach ($_eqDec['trafo']['units'][2] as $k=>$v) if (isset($eq['trafo']['units'][2][$k])) $eq['trafo']['units'][2][$k] = $v; }
        if (isset($_eqDec['genset'])) { foreach ($_eqDec['genset'] as $k=>$v) if (isset($eq['genset'][$k])) $eq['genset'][$k] = $v; }
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
        if (isset($_eqDec['cooling_tower'])) {
            $ctd = &$_eqDec['cooling_tower'];
            if (isset($ctd['unit_op'])) $eq['ct']['unit_op'] = (string)$ctd['unit_op'];
            if (isset($ctd['water_level_pct'])) $eq['ct']['level'] = (string)$ctd['water_level_pct'];
            if (isset($ctd['water_test_tds_ph'])) $eq['ct']['test'] = (string)$ctd['water_test_tds_ph'];
        }
        if (isset($_eqDec['reverse_osmosis'])) {
            $rod = &$_eqDec['reverse_osmosis'];
            if (isset($rod['water_meter_m3'])) $eq['ro']['meter'] = (string)$rod['water_meter_m3'];
            if (isset($rod['water_permeate_m3ph'])) $eq['ro']['permeate'] = (string)$rod['water_permeate_m3ph'];
            if (isset($rod['tds_ph_permeate'])) $eq['ro']['test_permeate'] = (string)$rod['tds_ph_permeate'];
            if (isset($rod['tds_ph_deep_well'])) $eq['ro']['test_deepwell'] = (string)$rod['tds_ph_deep_well'];
        }
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
$_rvBadge = function($v, $type='text') {
    $v = (string)$v;
    if ($v === '' || $v === null) return '<span class="inline-block px-2 py-1 rounded-md bg-slate-100 text-slate-400 text-[10px] font-semibold italic">—</span>';
    if ($type === 'onoff') {
        $c = strtolower($v) === 'on' ? 'bg-green-100 text-green-700 border-green-200' : 'bg-slate-100 text-slate-600 border-slate-200';
        return '<span class="inline-block px-2.5 py-1 rounded-md border '.$c.' text-[10px] font-black uppercase tracking-wide">'.$v.'</span>';
    }
    if ($type === 'valve') {
        $c = strtolower($v) === 'open' ? 'bg-emerald-100 text-emerald-700 border-emerald-200' : 'bg-red-100 text-red-700 border-red-200';
        return '<span class="inline-block px-2.5 py-1 rounded-md border '.$c.' text-[10px] font-black uppercase tracking-wide">'.$v.'</span>';
    }
    if ($type === 'sub') {
        $cMap = ['auto'=>'bg-blue-100 text-blue-700 border-blue-200','manual'=>'bg-amber-100 text-amber-700 border-amber-200','off'=>'bg-slate-100 text-slate-600 border-slate-200'];
        $c = $cMap[strtolower($v)] ?? 'bg-slate-100 text-slate-600 border-slate-200';
        return '<span class="inline-block px-2.5 py-1 rounded-md border '.$c.' text-[10px] font-black uppercase tracking-wide">'.$v.'</span>';
    }
    if ($type === 'status') {
        return '<span class="inline-block px-2.5 py-1 rounded-md bg-indigo-50 border border-indigo-200 text-indigo-700 text-[10px] font-black uppercase tracking-wide">'.$v.'</span>';
    }
    return '<span class="inline-block px-2.5 py-1 rounded-md bg-white border border-slate-200 text-primary text-[11px] font-bold shadow-sm">'.cleanInput($v).'</span>';
};
?>

<div class="page-shell page-shell--5xl">
    <div class="mb-8 animate-fade-in">
        <a href="<?= BASE_URL ?>supervisor/review.php" class="inline-flex items-center gap-1.5 text-sm text-secondary hover:text-primary mb-4 transition-colors">
            <i class="fas fa-chevron-left text-xs"></i> Kembali ke Daftar Review
        </a>
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1 flex items-center gap-3 flex-wrap">
                    <i class="fas fa-magnifying-glass mr-2 text-accent"></i>Detail Daily Log
                    <?= $__shiftBadge ?>
                </h1>
                <p class="text-secondary">
                    <i class="fas fa-calendar-day text-accent mr-1"></i>
                    Tanggal <span class="font-semibold text-primary"><?= formatDate($log['log_date']) ?></span>
                </p>
            </div>
            <span class="self-start px-4 py-2 rounded-full text-sm font-bold <?= getStatusBadgeClass($log['status']) ?>">
                <i class="fas fa-<?= $log['status'] === 'approved' ? 'circle-check' : ($log['status'] === 'rejected' ? 'circle-xmark' : 'clock') ?> mr-1.5"></i>
                <?= getStatusText($log['status']) ?>
            </span>
        </div>
    </div>

    <!-- INFO ENGINEER -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden mb-5 animate-slide-up">
        <div class="px-4 py-3 border-b border-slate-100">
            <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-user text-xs"></i></span>
                Informasi Engineer
            </h3>
        </div>
        <div class="p-4 sm:p-5">
            <div class="flex flex-col sm:flex-row gap-3.5 sm:items-center">
                <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-xl bg-slate-700 flex items-center justify-center text-white text-lg sm:text-xl font-bold flex-shrink-0 shadow-sm">
                    <?= strtoupper(mb_substr((string)($log['engineer_name'] ?? 'U'), 0, 1) ?: 'U') ?>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 flex-1 min-w-0">
                    <div><p class="text-[10px] text-slate-500 uppercase tracking-wider mb-0.5">Nama</p><p class="font-semibold text-slate-800 text-sm"><?= cleanInput($log['engineer_name']) ?></p></div>
                    <div><p class="text-[10px] text-slate-500 uppercase tracking-wider mb-0.5">Jabatan</p><p class="font-semibold text-slate-800 text-sm"><?= cleanInput($log['engineer_position'] ?? '-') ?></p></div>
                    <div><p class="text-[10px] text-slate-500 uppercase tracking-wider mb-0.5">Email</p><p class="font-semibold text-slate-800 text-xs"><?= cleanInput($log['engineer_email']) ?></p></div>
                    <div><p class="text-[10px] text-slate-500 uppercase tracking-wider mb-0.5">Phone</p><p class="font-semibold text-slate-800 text-sm"><?= cleanInput($log['engineer_phone'] ?? '-') ?></p></div>
                    <div>
                        <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-0.5">Shift</p>
                        <div class="mt-0.5"><?= $__shiftBadge ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- DATA KONSUMSI -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden mb-5 animate-slide-up" style="animation-delay: 50ms">
        <div class="px-4 py-3 border-b border-slate-100">
            <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-gauge-high text-xs"></i></span>
                Data Konsumsi
            </h3>
        </div>
        <div class="p-4 sm:p-5 grid grid-cols-1 md:grid-cols-3 gap-3.5">
            <div class="p-4 rounded-lg bg-slate-50 border border-slate-200">
                <div class="flex items-center gap-2.5 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center"><i class="fas fa-bolt text-slate-600 text-sm"></i></div>
                    <div><p class="text-[10px] text-slate-600 uppercase tracking-wider font-bold">Listrik</p><p class="text-[9px] text-slate-500">Total</p></div>
                </div>
                <div class="text-2xl font-bold text-slate-800"><?= formatNumber($log['total_electricity']) ?> <span class="text-xs font-semibold">kWh</span></div>
            </div>
            <div class="p-4 rounded-lg bg-slate-50 border border-slate-200">
                <div class="flex items-center gap-2.5 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center"><i class="fas fa-droplet text-slate-600 text-sm"></i></div>
                    <div><p class="text-[10px] text-slate-600 uppercase tracking-wider font-bold">Air</p><p class="text-[9px] text-slate-500">Total</p></div>
                </div>
                <div class="text-2xl font-bold text-slate-800"><?= formatNumber($log['total_water']) ?> <span class="text-xs font-semibold">m3</span></div>
            </div>
            <div class="p-4 rounded-lg bg-slate-50 border border-slate-200">
                <div class="flex items-center gap-2.5 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center"><i class="fas fa-fire text-slate-600 text-sm"></i></div>
                    <div><p class="text-[10px] text-slate-600 uppercase tracking-wider font-bold">Gas</p><p class="text-[9px] text-slate-500">Total</p></div>
                </div>
                <div class="text-2xl font-bold text-slate-800"><?= formatNumber($log['total_gas']) ?> <span class="text-xs font-semibold">kg</span></div>
            </div>
        </div>
    </div>

    <!-- RINCIAN KONSUMSI AIR -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden mb-5 animate-slide-up" style="animation-delay: 75ms">
        <div class="px-4 py-3 border-b border-slate-100">
            <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-droplet text-xs"></i></span>
                Rincian Konsumsi Air
            </h3>
        </div>
        <div class="p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3.5">
            <!-- PDAM -->
            <div class="rounded-lg border border-slate-200 bg-slate-50/50 p-3.5">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-600 mb-2">PDAM</p>
                <div class="flex items-end justify-between">
                    <div class="text-xl font-bold text-slate-800"><?= formatNumber($log['water_pdam'] ?? 0) ?> <span class="text-[11px] font-semibold text-slate-500">m3</span></div>
                    <div class="w-7 h-7 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-faucet-drip text-[11px]"></i></div>
                </div>
                <p class="text-[10px] text-slate-500 mt-1.5 italic">Input langsung</p>
            </div>
            <!-- MAIN BUILDING -->
            <div class="rounded-lg border-2 border-dashed border-slate-300 bg-slate-50/50 p-3.5">
                <div class="flex items-center justify-between mb-2.5">
                    <p class="text-[10px] font-black uppercase tracking-wider text-slate-700">Main Building</p>
                    <span class="text-[9px] font-black tracking-wider uppercase px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200">Reading</span>
                </div>
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-slate-500 font-semibold">Yesterday<?= $_mbYesterdayDate ? ' ('.date('d/m', strtotime($_mbYesterdayDate)).')' : '' ?></span>
                        <span class="text-slate-700 font-bold"><?= number_format($_mbYesterday, 2) ?> m3</span>
                    </div>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-slate-700 font-semibold">Today (<?= date('d/m', strtotime($log['log_date'])) ?>)</span>
                        <span class="text-slate-800 font-black"><?= number_format($_mbToday, 2) ?> m3</span>
                    </div>
                    <div class="border-t border-slate-200 pt-1.5 flex items-center justify-between">
                        <span class="text-[11px] text-slate-700 font-bold">= Konsumsi (T − Y)</span>
                        <span class="text-base font-black text-slate-800"><?= number_format($_mbCons, 2) ?> <span class="text-[11px] font-semibold text-slate-600">m3</span></span>
                    </div>
                </div>
            </div>
            <!-- COOLING TOWER -->
            <div class="rounded-lg border border-slate-200 bg-slate-50/50 p-3.5">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-600 mb-2">Cooling Tower</p>
                <div class="flex items-end justify-between">
                    <div class="text-xl font-bold text-slate-800"><?= formatNumber($log['water_cooling_tower'] ?? 0) ?> <span class="text-[11px] font-semibold text-slate-500">m3</span></div>
                    <div class="w-7 h-7 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-fan text-[11px]"></i></div>
                </div>
                <p class="text-[10px] text-slate-500 mt-1.5 italic">Input langsung</p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 100ms">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-camera text-xs"></i></span>
                    Dokumentasi Foto
                </h3>
            </div>
            <div class="p-4 sm:p-5">
                <?php if ($log['photo_path']): ?>
                    <a href="<?= UPLOAD_URL . $log['photo_path'] ?>" target="_blank">
                        <img src="<?= UPLOAD_URL . $log['photo_path'] ?>" alt="Foto Dokumentasi" class="w-full h-60 rounded-lg object-cover hover:scale-[1.02] transition-transform duration-300 cursor-pointer shadow-sm">
                    </a>
                <?php else: ?>
                    <div class="w-full h-60 rounded-lg bg-slate-50 border-2 border-dashed border-slate-200 flex flex-col items-center justify-center text-slate-400">
                        <i class="fas fa-image text-3xl mb-1.5 opacity-50"></i>
                        <p class="text-xs">Tidak ada foto</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="space-y-5">
            
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 140ms">
                <div class="px-4 py-3 border-b border-slate-100">
                    <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                        <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-triangle-exclamation text-xs"></i></span>
                        Kendala
                    </h3>
                </div>
                <div class="p-4 sm:p-5"><div class="text-slate-800 leading-relaxed whitespace-pre-wrap text-sm"><?= $log['obstacles'] ? nl2br(cleanInput($log['obstacles'])) : '<span class="text-slate-400 italic text-xs">Tidak ada kendala</span>' ?></div></div>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 160ms">
                <div class="px-4 py-3 border-b border-slate-100">
                    <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                        <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-lightbulb text-xs"></i></span>
                        Solusi
                    </h3>
                </div>
                <div class="p-4 sm:p-5"><div class="text-slate-800 leading-relaxed whitespace-pre-wrap text-sm"><?= $log['solutions'] ? nl2br(cleanInput($log['solutions'])) : '<span class="text-slate-400 italic text-xs">Tidak ada solusi yang dicatat</span>' ?></div></div>
            </div>
        </div>
    </div>

    <?php if ($_hasEquipment): ?>
    <!-- 8 SECTION EQUIPMENT LOG -->
    <div class="space-y-5 mb-5 animate-slide-up" style="animation-delay: 180ms">

        <!-- 1 TRAFO -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-bolt text-xs"></i></span>
                    Trafo — 2 Unit
                </h3>
            </div>
            <div class="p-4 sm:p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php for ($tu=1; $tu<=2; $tu++): $tv = $eq['trafo']['units'][$tu]; ?>
                <div class="rounded-lg border-2 border-dashed border-slate-300 bg-slate-50/40 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5"><i class="fas fa-microchip mr-1 text-[10px]"></i> Trafo Unit <?= $tu ?></p>
                    <div class="grid grid-cols-3 gap-2.5">
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Temp (°C)</p><?= $_rvBadge($tv['temp_c']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Ampere (A)</p><?= $_rvBadge($tv['ampere_lvdp']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Oil (%)</p><?= $_rvBadge($tv['oil_level_pct']) ?></div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- 2 GENSET -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-industry text-xs"></i></span>
                    Genset — 3 Unit + Fuel
                </h3>
            </div>
            <div class="p-4 sm:p-5 grid grid-cols-2 md:grid-cols-4 gap-3">
                <div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-600 mb-2">Gen 1 (V)</p><?= $_rvBadge($eq['genset']['gen_1_volt']) ?></div>
                <div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-600 mb-2">Gen 2 (V)</p><?= $_rvBadge($eq['genset']['gen_2_volt']) ?></div>
                <div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-600 mb-2">Gen 3 (V)</p><?= $_rvBadge($eq['genset']['gen_3_volt']) ?></div>
                <div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-600 mb-2">Tank (L)</p><?= $_rvBadge($eq['genset']['fuel_tank_liter']) ?></div>
            </div>
        </div>

        <!-- 3 PUMP ROOM -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-water text-xs"></i></span>
                    Pump Room
                </h3>
            </div>
            <div class="p-4 sm:p-5 space-y-3.5">
                <div class="rounded-lg border border-slate-200 bg-slate-50/30 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5"><i class="fas fa-fire mr-1 text-[10px]"></i> Steam Boiler (SB)</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-2.5 text-center">
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Unit Op</p><?= $_rvBadge($eq['pump']['sb_unit_op'],'status') ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">SB-1 Jam</p><?= $_rvBadge($eq['pump']['sb1_hours']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">SB-2 Jam</p><?= $_rvBadge($eq['pump']['sb2_hours']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Test TDS/pH</p><?= $_rvBadge($eq['pump']['sb_test']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Steam Press</p><?= $_rvBadge($eq['pump']['sb_press']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Blow Down</p><?= $_rvBadge($eq['pump']['sb_blow']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Econ Temp</p><?= $_rvBadge($eq['pump']['sb_econ_temp']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Econ Press</p><?= $_rvBadge($eq['pump']['sb_econ_press']) ?></div>
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50/30 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5"><i class="fas fa-mug-hot mr-1 text-[10px]"></i> Hot Water Boiler (HWB)</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-2.5 text-center">
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Unit Op</p><?= $_rvBadge($eq['pump']['hwb_unit_op'],'status') ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">HWB-1 Jam</p><?= $_rvBadge($eq['pump']['hwb1_hours']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">HWB-2 Jam</p><?= $_rvBadge($eq['pump']['hwb2_hours']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">HW Temp</p><?= $_rvBadge($eq['pump']['hwb_temp']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Test TDS/pH</p><?= $_rvBadge($eq['pump']['hwb_test']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Circ Pump</p><?= $_rvBadge($eq['pump']['hwb_circ_op']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Flow Press</p><?= $_rvBadge($eq['pump']['hwb_flow']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Return Press</p><?= $_rvBadge($eq['pump']['hwb_ret']) ?></div>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50/30 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5">Ground Tank</p>
                        <div class="space-y-2">
                            <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Raw Tank</p><?= $_rvBadge($eq['pump']['tank_raw']) ?></div>
                            <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Treated Tank</p><?= $_rvBadge($eq['pump']['tank_treated']) ?></div>
                            <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Irigasi Tank</p><?= $_rvBadge($eq['pump']['tank_irigasi']) ?></div>
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50/30 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5">Hydrant Pump</p>
                        <div class="space-y-2">
                            <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Standby/Auto</p><?= $_rvBadge($eq['pump']['hyd_standby'],'status') ?></div>
                            <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Press Pump-1</p><?= $_rvBadge($eq['pump']['hyd_press1']) ?></div>
                            <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Press Pump-2</p><?= $_rvBadge($eq['pump']['hyd_press2']) ?></div>
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50/30 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5">Jockey Pump</p>
                        <div class="space-y-2 mb-3"><div><p class="text-[10px] font-semibold text-slate-600 mb-1">Standby Press</p><?= $_rvBadge($eq['pump']['jockey_press']) ?></div></div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5">Sand Filter</p>
                        <div class="space-y-2"><div><p class="text-[10px] font-semibold text-slate-600 mb-1">Status</p><?= $_rvBadge($eq['pump']['sf_status'],'status') ?></div></div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50/30 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5">SF + Booster</p>
                        <div class="space-y-2">
                            <div><p class="text-[10px] font-semibold text-slate-600 mb-1">SF Press Sand</p><?= $_rvBadge($eq['pump']['sf_press_sand']) ?></div>
                            <div><p class="text-[10px] font-semibold text-slate-600 mb-1">SF Press Carbon</p><?= $_rvBadge($eq['pump']['sf_press_carbon']) ?></div>
                            <div><p class="text-[10px] font-semibold text-slate-600 mb-1">SF Pump Status</p><?= $_rvBadge($eq['pump']['sfp_status'],'status') ?></div>
                            <div><p class="text-[10px] font-semibold text-slate-600 mb-1">SF Pump Op</p><?= $_rvBadge($eq['pump']['sfp_unit_op']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50/30 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5">Booster Villa</p>
                        <div class="grid grid-cols-2 gap-2"><div><p class="text-[10px] font-semibold text-slate-600 mb-1">Unit Op</p><?= $_rvBadge($eq['pump']['bpv_unit_op']) ?></div><div><p class="text-[10px] font-semibold text-slate-600 mb-1">Press</p><?= $_rvBadge($eq['pump']['bpv_press']) ?></div></div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50/30 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5">Booster MH</p>
                        <div class="grid grid-cols-2 gap-2"><div><p class="text-[10px] font-semibold text-slate-600 mb-1">Unit Op</p><?= $_rvBadge($eq['pump']['bpm_unit_op']) ?></div><div><p class="text-[10px] font-semibold text-slate-600 mb-1">Press</p><?= $_rvBadge($eq['pump']['bpm_press']) ?></div></div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50/30 p-3.5">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5">Irrigation Pump</p>
                        <div class="grid grid-cols-2 gap-2"><div><p class="text-[10px] font-semibold text-slate-600 mb-1">Unit Op</p><?= $_rvBadge($eq['pump']['irigasi_unit_op']) ?></div><div><p class="text-[10px] font-semibold text-slate-600 mb-1">Press</p><?= $_rvBadge($eq['pump']['irigasi_press']) ?></div></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4 CHILLER SYSTEM -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-temperature-low text-xs"></i></span>
                    Chiller System Equip
                </h3>
            </div>
            <div class="p-4 sm:p-5 grid grid-cols-1 md:grid-cols-3 gap-3.5">
                <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5">Chiller Unit</p>
                    <div class="space-y-2">
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Unit Op</p><?= $_rvBadge($eq['chiller']['unit_op'],'status') ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Chilled Test</p><?= $_rvBadge($eq['chiller']['cw_test']) ?></div>
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5">CWP Pump</p>
                    <div class="space-y-2">
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Unit Op</p><?= $_rvBadge($eq['chiller']['cwp_unit_op']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Press (kg/cm2)</p><?= $_rvBadge($eq['chiller']['cwp_press']) ?></div>
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50/40 p-3.5">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-2.5">CHWP Pump</p>
                    <div class="space-y-2">
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Unit Op</p><?= $_rvBadge($eq['chiller']['chwp_unit_op']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Press In</p><?= $_rvBadge($eq['chiller']['chwp_in']) ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Press Out</p><?= $_rvBadge($eq['chiller']['chwp_out']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 5 COOLING TOWER -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-fan text-xs"></i></span>
                    Cooling Tower
                </h3>
            </div>
            <div class="p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="p-3.5 rounded-lg bg-slate-50/60 border border-slate-200"><p class="text-[11px] font-extrabold text-slate-700 uppercase tracking-wide mb-2">Unit Op</p><?= $_rvBadge($eq['ct']['unit_op']) ?></div>
                <div class="p-3.5 rounded-lg bg-slate-50/60 border border-slate-200"><p class="text-[11px] font-extrabold text-slate-700 uppercase tracking-wide mb-2">Water Level (%)</p><?= $_rvBadge($eq['ct']['level']) ?></div>
                <div class="p-3.5 rounded-lg bg-slate-50/60 border border-slate-200"><p class="text-[11px] font-extrabold text-slate-700 uppercase tracking-wide mb-2">Test TDS/pH</p><?= $_rvBadge($eq['ct']['test']) ?></div>
            </div>
        </div>

        <!-- 6 REVERSE OSMOSIS -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-droplet text-xs"></i></span>
                    Reverse Osmosis (RO)
                </h3>
            </div>
            <div class="p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="p-3.5 rounded-lg bg-slate-50/60 border border-slate-200"><p class="text-[11px] font-extrabold text-slate-700 uppercase tracking-wide mb-2">Water Meter (m3)</p><?= $_rvBadge($eq['ro']['meter']) ?></div>
                <div class="p-3.5 rounded-lg bg-slate-50/60 border border-slate-200"><p class="text-[11px] font-extrabold text-slate-700 uppercase tracking-wide mb-2">Permeate (m3/jam)</p><?= $_rvBadge($eq['ro']['permeate']) ?></div>
                <div class="p-3.5 rounded-lg bg-slate-50/60 border border-slate-200"><p class="text-[11px] font-extrabold text-slate-700 uppercase tracking-wide mb-2">TDS/pH Permeate</p><?= $_rvBadge($eq['ro']['test_permeate']) ?></div>
                <div class="p-3.5 rounded-lg bg-slate-50/60 border border-slate-200"><p class="text-[11px] font-extrabold text-slate-700 uppercase tracking-wide mb-2">TDS/pH Deep Well</p><?= $_rvBadge($eq['ro']['test_deepwell']) ?></div>
            </div>
        </div>

        <!-- 7 POOL SYSTEM -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-person-swimming text-xs"></i></span>
                    Pool System
                </h3>
            </div>
            <div class="p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
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
                    $hasHwb = ($pkey === 'aqua');
                    $hasPump = ($pkey !== 'mpr');
                    $hasPress = ($pkey === 'l1' || $pkey === 'l2');
                ?>
                <div class="rounded-lg border-2 border-dashed border-slate-300 bg-slate-50/30 p-3.5">
                    <div class="flex items-center gap-2 mb-2.5">
                        <div class="w-7 h-7 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600 text-xs"><i class="fas fa-swimming-pool"></i></div>
                        <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-700"><?= $ptitle ?></p>
                    </div>
                    <div class="space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Alarm</p><?= $_rvBadge($eq['pool'][$alarmKey] ?? 'on','onoff') ?></div>
                            <?php if ($hasPump): ?><div><p class="text-[10px] font-semibold text-slate-600 mb-1">Pump</p><?= $_rvBadge($eq['pool'][$pumpKey] ?? '') ?></div><?php endif; ?>
                        </div>
                        <?php if ($hasPress): ?><div><p class="text-[10px] font-semibold text-slate-600 mb-1">Press Tank</p><?= $_rvBadge($eq['pool'][$pressKey] ?? '') ?></div><?php endif; ?>
                        <?php if ($hasHwb): ?><div><p class="text-[10px] font-semibold text-slate-600 mb-1">HWB Temp</p><?= $_rvBadge($eq['pool'][$hwbKey] ?? '') ?></div><?php endif; ?>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Submersible</p><?= $_rvBadge($eq['pool'][$subKey] ?? 'auto','sub') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 8 GAS SYSTEM -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-fire-flame-curved text-xs"></i></span>
                    Gas System
                </h3>
            </div>
            <div class="p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
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
                ?>
                <div class="rounded-lg border-2 border-dashed border-slate-300 bg-slate-50/30 p-3.5">
                    <div class="flex items-center gap-2 mb-2.5">
                        <div class="w-7 h-7 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600 text-xs"><i class="fas fa-fire-flame-curved"></i></div>
                        <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-700"><?= $gtitle ?></p>
                    </div>
                    <div class="grid grid-cols-2 gap-2.5">
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Valve</p><?= $_rvBadge($eq['gas'][$valveKey],'valve') ?></div>
                        <div><p class="text-[10px] font-semibold text-slate-600 mb-1">Alarm</p><?= $_rvBadge($eq['gas'][$alarmKey],'onoff') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
    <?php endif; ?>

    <?php if ($log['revision_notes']): ?>
        <div class="mb-5 p-4 rounded-xl bg-slate-50 border border-slate-200 animate-slide-up">
            <h3 class="font-semibold text-sm text-slate-800 mb-2 flex items-center gap-2">
                <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center"><i class="fas fa-note-sticky text-xs"></i></span>
                Catatan Revisi Sebelumnya
            </h3>
            <p class="text-slate-700 whitespace-pre-wrap text-sm"><?= nl2br(cleanInput($log['revision_notes'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($log['supervisor_signature']): ?>
        <div class="mb-5 p-4 bg-slate-50 border border-slate-200 rounded-xl animate-slide-up">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h3 class="font-semibold text-sm text-slate-800 flex items-center gap-2 mb-1">
                        <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center"><i class="fas fa-signature text-xs"></i></span>
                        Disetujui oleh
                    </h3>
                    <p class="text-slate-800 font-semibold text-sm"><?= cleanInput($log['supervisor_name'] ?? $user['name']) ?></p>
                    <p class="text-[11px] text-slate-500 mt-0.5">Pada: <?= formatDateTime($log['approved_at']) ?></p>
                </div>
                <img src="<?= UPLOAD_URL . $log['supervisor_signature'] ?>" alt="Signature" class="h-20 bg-white rounded-lg border border-slate-200 p-2 shadow-sm">
            </div>
        </div>
    <?php endif; ?>

    <?php if ($log['status'] === 'pending'): ?>
    <div class="bg-white rounded-xl border-2 border-slate-300 shadow-lg overflow-hidden animate-slide-up" style="animation-delay: 200ms">
        <div class="px-4 sm:px-5 py-3.5 bg-slate-800 text-white">
            <h3 class="font-bold text-base flex items-center gap-2"><i class="fas fa-file-signature"></i>Approval Digital</h3>
            <p class="text-[11px] text-slate-300 mt-0.5">Berikan tanda tangan dan persetujuan Anda</p>
        </div>
        <div class="p-4 sm:p-5 space-y-5">
            <form method="POST" id="reviewForm">
                <div id="approveSection" class="hidden">
                    <label class="block text-sm font-semibold text-slate-800 mb-2">
                        <i class="fas fa-pen mr-1.5 text-slate-500"></i>Tanda Tangan Digital <span class="text-red-500">*</span>
                    </label>
                    <div class="rounded-lg border-2 border-dashed border-slate-300 bg-slate-50/40 overflow-hidden touch-none">
                        <canvas id="signaturePad" width="800" height="220" class="w-full h-48 sm:h-56 cursor-crosshair bg-white block"></canvas>
                    </div>
                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mt-3">
                        <button type="button" onclick="clearSignature()" class="inline-flex items-center justify-center gap-1.5 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                            <i class="fas fa-eraser text-xs"></i>Hapus Tanda Tangan
                        </button>
                        <div class="flex items-center gap-2 text-[11px] text-slate-500">
                            <i class="fas fa-info-circle"></i>
                            Tanda tangan Anda sebagai bukti persetujuan
                        </div>
                    </div>
                </div>

                <div id="rejectSection" class="hidden">
                    <label class="block text-sm font-semibold text-slate-800 mb-2">
                        <i class="fas fa-note-sticky mr-1.5 text-slate-500"></i>Catatan Revisi <span class="text-red-500">*</span>
                    </label>
                    <textarea id="rejectNotes" name="revision_notes" rows="4"
                        class="w-full px-3.5 py-2.5 rounded-lg border border-slate-200 bg-slate-50/40 text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:bg-white transition-all resize-none text-sm"
                        placeholder="Jelaskan alasan penolakan dan hal yang perlu diperbaiki..."></textarea>
                    <p class="text-[11px] text-slate-500 mt-1.5"><i class="fas fa-info-circle mr-1"></i>Catatan ini akan dikirim ke engineer untuk perbaikan</p>
                </div>

                <div class="flex flex-col sm:flex-row sm:justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" onclick="setAction('reject')"
                        class="px-5 py-3 rounded-xl bg-slate-50 text-slate-700 font-semibold border border-slate-200 hover:bg-slate-100 hover:border-slate-300 transition-all flex items-center justify-center gap-2 text-sm">
                        <i class="fas fa-circle-xmark"></i>
                        Reject Daily Log
                    </button>
                    <button type="button" onclick="setAction('approve')"
                        class="px-5 py-3 rounded-xl bg-slate-800 text-white font-semibold shadow-sm hover:bg-slate-900 hover:shadow-md transition-all flex items-center justify-center gap-2 text-sm">
                        <i class="fas fa-circle-check"></i>
                        Approve Daily Log
                    </button>
                </div>

                <input type="hidden" name="action" id="actionField">
                <input type="hidden" name="signature_data" id="signatureDataField">
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
let signaturePad, sigCtx, isDrawing = false, hasSignature = false;

document.addEventListener('DOMContentLoaded', function() {
    signaturePad = document.getElementById('signaturePad');
    if (signaturePad) {
        sigCtx = signaturePad.getContext('2d');
        sigCtx.strokeStyle = '#111';
        sigCtx.lineWidth = 2.5;
        sigCtx.lineCap = 'round';
        sigCtx.lineJoin = 'round';

        function getPos(e) {
            const rect = signaturePad.getBoundingClientRect();
            const scaleX = signaturePad.width / rect.width;
            const scaleY = signaturePad.height / rect.height;
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            return { x: (clientX - rect.left) * scaleX, y: (clientY - rect.top) * scaleY };
        }

        function startDraw(e) {
            e.preventDefault();
            isDrawing = true;
            hasSignature = true;
            const pos = getPos(e);
            sigCtx.beginPath();
            sigCtx.moveTo(pos.x, pos.y);
        }
        function draw(e) {
            if (!isDrawing) return;
            e.preventDefault();
            const pos = getPos(e);
            sigCtx.lineTo(pos.x, pos.y);
            sigCtx.stroke();
        }
        function endDraw() { isDrawing = false; }

        signaturePad.addEventListener('mousedown', startDraw);
        signaturePad.addEventListener('mousemove', draw);
        window.addEventListener('mouseup', endDraw);
        signaturePad.addEventListener('touchstart', startDraw, { passive: false });
        signaturePad.addEventListener('touchmove', draw, { passive: false });
        signaturePad.addEventListener('touchend', endDraw);
    }
});

function clearSignature() {
    if (sigCtx) {
        sigCtx.clearRect(0, 0, signaturePad.width, signaturePad.height);
        hasSignature = false;
    }
}

function setAction(action) {
    document.getElementById('approveSection').classList.toggle('hidden', action !== 'approve');
    document.getElementById('rejectSection').classList.toggle('hidden', action !== 'reject');

    setTimeout(() => {
        const form = document.getElementById('reviewForm');
        const actionField = document.getElementById('actionField');
        const sigField = document.getElementById('signatureDataField');

        if (action === 'approve') {
            if (!hasSignature) {
                alert('Tanda tangan digital wajib diisi! Silakan tanda tangan di area yang tersedia.');
                return;
            }
            sigField.value = signaturePad.toDataURL('image/png');
            actionField.value = 'approve';
            form.submit();
        } else {
            const notes = document.getElementById('rejectNotes').value.trim();
            if (!notes) {
                alert('Catatan revisi wajib diisi untuk reject!');
                document.getElementById('rejectNotes').focus();
                return;
            }
            if (!confirm('Anda yakin ingin menolak Daily Log ini?')) return;
            actionField.value = 'reject';
            form.submit();
        }
    }, action === 'approve' ? 50 : 50);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
