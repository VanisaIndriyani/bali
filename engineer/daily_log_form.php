<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = T('form_title', 'Isi Daily Log Engineering');
requireRole(['engineer', 'supervisor']);

$db = Database::getInstance();
$user = currentUser();

$date = $_GET['date'] ?? date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $date) || $date > date('Y-m-d')) {
    setFlash('error', T('form_error_invalid_date', 'Tanggal tidak valid'));
    redirect('engineer/select_date.php');
}

$log = $db->fetchOne(
    "SELECT * FROM daily_logs WHERE engineer_id = ? AND log_date = ?",
    [$user['id'], $date]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ① Electricity Subdetails
    $eWbp = (float)($_POST['electricity_wbp'] ?? 0);
    $eLwbp = (float)($_POST['electricity_lwbp'] ?? 0);
    $electricity = $eWbp + $eLwbp;

    // ② Water 9 sources
    $wPdam = (float)($_POST['water_pdam'] ?? 0);
    $wIki = (float)($_POST['water_iki_gaban'] ?? 0);
    $wDw1 = (float)($_POST['water_deepwell_1'] ?? 0);
    $wDw2 = (float)($_POST['water_deepwell_2_brr'] ?? 0);
    $wDwAsean = (float)($_POST['water_deepwell_asean'] ?? 0);
    $wDwLpb = (float)($_POST['water_deepwell_lpb'] ?? 0);
    $wMainBldg = (float)($_POST['water_main_building'] ?? 0);
    $wCooling = (float)($_POST['water_cooling_tower'] ?? 0);
    $wBottling = (float)($_POST['water_bottling'] ?? 0);
    $water = $wPdam + $wIki + $wDw1 + $wDw2 + $wDwAsean + $wDwLpb + $wMainBldg + $wCooling + $wBottling;

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

    $activities = trim($_POST['work_activities'] ?? '');
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

        $data = [
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
            setFlash('success', T('form_success_update', 'Daily Log berhasil diperbarui dan menunggu approval'));
        } else {
            $data['log_date'] = $date;
            $data['engineer_id'] = $user['id'];
            $db->insert('daily_logs', $data);
            setFlash('success', T('form_success_save', 'Daily Log berhasil disimpan dan menunggu approval'));
        }
        redirect('engineer/select_date.php');
    }
}

$log = $db->fetchOne(
    "SELECT * FROM daily_logs WHERE engineer_id = ? AND log_date = ?",
    [$user['id'], $date]
);

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
                <p class="text-secondary flex items-center gap-2">
                    <i class="fas fa-calendar-day text-accent"></i>
                    <?= T('general_date', 'Tanggal') ?>: <span class="font-semibold text-primary"><?= formatDate($date) ?></span>
                    <?php if ($log): ?>
                        <span class="ml-2 px-3 py-1 rounded-full text-[11px] font-semibold <?= getStatusBadgeClass($log['status']) ?>"><?= getStatusText($log['status']) ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($log && $log['status'] === 'rejected' && $log['revision_notes']): ?>
                <div class="p-4 rounded-card bg-red-50 border border-red-200 max-w-md animate-slide-up">
                    <p class="text-xs font-semibold text-red-700 mb-1"><i class="fas fa-triangle-exclamation mr-1"></i><?= T('today_revisi_label', 'Catatan Revisi') ?>:</p>
                    <p class="text-sm text-red-800"><?= nl2br(cleanInput($log['revision_notes'])) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="space-y-5">
        <!-- ① TOTAL LISTRIK - WBP LWBP -->
        <div class="bg-surface rounded-premium border border-amber-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 50ms">
            <div class="px-5 lg:px-6 py-4 border-b border-amber-100/80 bg-gradient-to-r from-amber-50/90 via-amber-50/60 to-yellow-50">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-400 to-amber-600 flex items-center justify-center text-white shadow-md shadow-amber-500/30"><i class="fas fa-bolt text-sm"></i></span>
                    <?= T('form_section_elec', '① Total Consume Listrik (Kwh)') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_elec_sub', 'Di isi WBP (Wilayah Beban Puncak) + LWBP (Luar WBP) — Total otomatis dijumlah') ?></p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><i class="far fa-circle-dot mr-1 text-amber-500"></i><?= T('form_elec_wbp', 'KWH WBP') ?> <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="electricity_wbp" required oninput="calcTotals()"
                            value="<?= $log['electricity_wbp'] ?? '0.00' ?>"
                            class="js-sum-electric w-full pl-4 pr-16 py-3.5 rounded-card border border-amber-200 bg-amber-50/60 text-lg font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-amber-700 font-bold bg-amber-100 px-2 py-0.5 rounded-full">kWh</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><i class="far fa-circle-dot mr-1 text-yellow-700"></i><?= T('form_elec_lwbp', 'KWH LWBP') ?> <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="electricity_lwbp" required oninput="calcTotals()"
                            value="<?= $log['electricity_lwbp'] ?? '0.00' ?>"
                            class="js-sum-electric w-full pl-4 pr-16 py-3.5 rounded-card border border-yellow-300 bg-yellow-50/70 text-lg font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-yellow-600 focus:ring-4 focus:ring-yellow-500/10 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-yellow-800 font-bold bg-yellow-100 px-2 py-0.5 rounded-full">kWh</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-extrabold text-primary mb-2 tracking-tight"><i class="fas fa-calculator mr-1 text-primary"></i><?= T('form_elec_total', 'TOTAL LISTRIK (Auto)') ?></label>
                    <div class="relative">
                        <input type="number" id="totalElectricity" readonly step="0.01" min="0" name="total_electricity_show"
                            value="<?= $log['total_electricity'] ?? '0.00' ?>"
                            class="w-full pl-4 pr-16 py-3.5 rounded-card border-2 border-primary/80 bg-gradient-to-br from-primary to-primary/85 text-lg font-black text-white placeholder-white/80 shadow-lg shadow-primary/10 cursor-not-allowed opacity-90">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-white/95 font-extrabold border border-white/30 px-2 py-0.5 rounded-full bg-white/10">kWh</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ② WATER 9 SUMBER -->
        <div class="bg-surface rounded-premium border border-blue-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 90ms">
            <div class="px-5 lg:px-6 py-4 border-b border-blue-100/80 bg-gradient-to-r from-blue-50/90 via-sky-50/60 to-cyan-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white shadow-md shadow-blue-500/30"><i class="fas fa-droplet text-sm"></i></span>
                    <?= T('form_water_title', '② Water - Konsumsi Air 9 Sumber (m³)') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_water_sub', '9 sumber sesuai catatan: PDAM / IKI Gaban / Deep Well / Main Building / Cooling Tower / Bottling') ?></p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                <?php
                $waterFields = [
                    ['water_pdam', T('form_water_pdam', 'PDAM'), 'text-blue-600', 'bg-blue-50/60', 'border-blue-200'],
                    ['water_iki_gaban', T('form_water_iki', 'IKI Gaban'), 'text-blue-700', 'bg-blue-50/40', 'border-blue-200/80'],
                    ['water_deepwell_1', T('form_water_dw1', 'Deep Well 1'), 'text-indigo-600', 'bg-indigo-50/60', 'border-indigo-200'],
                    ['water_deepwell_2_brr', T('form_water_dw2', 'Deep Well 2 Brr'), 'text-indigo-700', 'bg-indigo-50/40', 'border-indigo-200/80'],
                    ['water_deepwell_asean', T('form_water_dw_asean', 'Deep Well ASEAN'), 'text-sky-600', 'bg-sky-50/60', 'border-sky-200'],
                    ['water_deepwell_lpb', T('form_water_dw_lpb', 'Deep Well LPB'), 'text-sky-700', 'bg-sky-50/40', 'border-sky-200/80'],
                    ['water_main_building', T('form_water_main', 'Main Building'), 'text-cyan-600', 'bg-cyan-50/60', 'border-cyan-200'],
                    ['water_cooling_tower', T('form_water_ct', 'Cooling Tower'), 'text-teal-600', 'bg-teal-50/60', 'border-teal-200'],
                    ['water_bottling', T('form_water_bottling', 'Bottling Water'), 'text-blue-800', 'bg-blue-100/60', 'border-blue-300/80'],
                ];
                foreach ($waterFields as $wf) {
                    [$field, $label, $col, $bg, $bor] = $wf;
                    $val = $log[$field] ?? '0.00';
                    echo <<<HTML
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="far fa-circle-dot mr-1 {$col}"></i>{$label}</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="{$field}" oninput="calcTotals()"
                            value="{$val}"
                            class="js-sum-water w-full pl-3 pr-12 py-3 rounded-card border {$bor} {$bg} font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 focus:bg-white transition-all text-sm">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] {$col} font-black bg-white/90 border {$bor} px-1.5 py-0.5 rounded-full">m³</span>
                    </div>
                </div>
HTML;
                }
                ?>
                <div class="sm:col-span-2 md:col-span-3 pt-1 mt-1 border-t border-dashed border-blue-100">
                    <div class="flex items-center justify-between gap-4">
                        <label class="text-sm font-extrabold text-primary flex items-center gap-1.5"><i class="fas fa-calculator text-primary"></i><?= T('form_water_total_label', 'TOTAL KONSUMSI AIR (Auto sum 9 sumber)') ?></label>
                        <div class="relative w-full sm:w-56">
                            <input type="number" id="totalWater" readonly step="0.01" min="0"
                                value="<?= $log['total_water'] ?? '0.00' ?>"
                                class="w-full pl-3 pr-11 py-3 rounded-card border-2 border-blue-600/85 bg-gradient-to-br from-blue-600 to-blue-800 text-white font-black shadow-md shadow-blue-500/15 cursor-not-allowed opacity-95">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-white/95 font-black border border-white/25 px-1.5 py-0.5 rounded-full bg-white/10">m³</span>
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
                    <?= T('form_gas_title', '③ Gas Konsumsi - LPG & LNG (kg)') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_gas_sub', 'Isi masing-masing tipe gas — Total auto dijumlah') ?></p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><i class="far fa-circle-dot mr-1 text-orange-500"></i><?= T('form_gas_lpg', 'LPG') ?> <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="gas_lpg" required oninput="calcTotals()"
                            value="<?= $log['gas_lpg'] ?? '0.00' ?>"
                            class="js-sum-gas w-full pl-4 pr-16 py-3.5 rounded-card border border-orange-200 bg-orange-50/60 text-lg font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-orange-500 focus:ring-4 focus:ring-orange-500/10 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-orange-700 font-bold bg-orange-100 px-2 py-0.5 rounded-full">kg</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><i class="far fa-circle-dot mr-1 text-red-500"></i><?= T('form_gas_lng', 'LNG') ?> <span class="text-red-500">*</span></label>
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
                    <?= T('form_swro_title', '④ SWRO - Sea Water Reverse Osmosis') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_swro_sub', 'Water meter produksi air bersih, listrik kWh, & TDS (ppm)') ?></p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 sm:grid-cols-3 gap-5">
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="far fa-gauge-high mr-1 text-cyan-600"></i><?= T('form_swro_water', 'SWRO Watermeter (m³)') ?></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="swro_watermeter"
                            value="<?= $log['swro_watermeter'] ?? '0.00' ?>"
                            class="w-full pl-3 pr-12 py-3 rounded-card border border-cyan-200 bg-cyan-50/60 font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-cyan-500 focus:ring-4 focus:ring-cyan-500/10 focus:bg-white transition-all">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-cyan-700 font-black bg-white/90 border border-cyan-200 px-1.5 py-0.5 rounded-full">m³</span>
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
                    <?= T('form_bottling_title', '⑤ Bottling Water - Produksi Air Minum') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_bottling_sub', 'Listrik untuk proses bottling (kWh) + watermeter produksi (m³)') ?></p>
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
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide"><i class="fas fa-gauge-high mr-1 text-violet-600"></i><?= T('form_bottling_water', 'Bottling - Watermeter (m³)') ?></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="bottling_watermeter"
                            value="<?= $log['bottling_watermeter'] ?? '0.00' ?>"
                            class="w-full pl-4 pr-14 py-3 rounded-card border border-violet-200 bg-purple-50/60 font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-[10px] text-purple-700 font-black bg-white/90 border border-purple-200 px-1.5 py-0.5 rounded-full">m³</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⑥ CHILLER SYSTEM 8 fields (3 unit on/off + pH/TDS/Temp/2 pressures) -->
        <div class="bg-surface rounded-premium border border-emerald-200/70 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 250ms">
            <div class="px-5 lg:px-6 py-4 border-b border-emerald-100/80 bg-gradient-to-r from-emerald-50/90 via-green-50/60 to-teal-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-400 to-green-700 flex items-center justify-center text-white shadow-md shadow-emerald-500/30"><i class="fas fa-snowflake text-sm"></i></span>
                    <?= T('form_chiller_title', '⑥ Chiller System - 3 Unit Operasi & Monitoring') ?>
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

        <script>
        function calcTotals() {
            // Total Listrik = sum all js-sum-electric
            let e = 0;
            document.querySelectorAll('.js-sum-electric').forEach(el => e += parseFloat(el.value || 0));
            document.getElementById('totalElectricity').value = e.toFixed(2);
            // Total Water = sum 9 js-sum-water
            let w = 0;
            document.querySelectorAll('.js-sum-water').forEach(el => w += parseFloat(el.value || 0));
            document.getElementById('totalWater').value = w.toFixed(2);
            // Total Gas = sum 2 js-sum-gas
            let g = 0;
            document.querySelectorAll('.js-sum-gas').forEach(el => g += parseFloat(el.value || 0));
            document.getElementById('totalGas').value = g.toFixed(2);
        }
        // Initialize totals saat page load (jika ada data dari DB yang sudah di set ke input field hidden tapi sumnya update)
        document.addEventListener('DOMContentLoaded', calcTotals);
        </script>

        <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 100ms">
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

        <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 150ms">
            <div class="px-5 lg:px-6 py-4 border-b border-border bg-muted/30">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <i class="fas fa-clipboard-list text-accent"></i><?= T('form_work_title', 'Detail Pekerjaan') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_work_sub', 'Isi aktivitas, kendala, dan solusi') ?></p>
            </div>
            <div class="p-5 lg:p-6 space-y-5">
                <div>
                    <label class="block text-sm font-semibold text-primary mb-2">
                        <i class="fas fa-list-check mr-1.5 text-green-600"></i><?= T('form_work_activities', 'Aktivitas Pekerjaan') ?> <span class="text-red-500">*</span>
                    </label>
                    <textarea name="work_activities" required rows="4"
                        class="w-full px-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all resize-none"
                        placeholder="<?= T('form_work_activities_ph', 'Deskripsikan pekerjaan yang dilakukan hari ini secara detail...') ?>"><?= cleanInput($log['work_activities'] ?? '') ?></textarea>
                    <p class="text-[11px] text-secondary mt-1.5"><i class="fas fa-info-circle mr-1"></i><?= T('form_work_activities_min', 'Minimal 10 karakter') ?></p>
                </div>
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
