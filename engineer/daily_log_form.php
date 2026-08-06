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
    $water = $wMainBldg;

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
            // ⑦ Fuel
            'total_fuel' => $fuel,
            // ⑧ Occupancy Rate
            'occ_rate' => $occRate,
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
            $data['engineer_id'] = $user['id'];
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
        redirect('engineer/select_date.php');
    }
}

$log = $db->fetchOne(
    "SELECT * FROM daily_logs WHERE engineer_id = ? AND log_date = ?",
    [$user['id'], $date]
);

$existingActivities = [];
if ($log && !empty($log['id'])) {
    $existingActivities = $db->fetchAll("SELECT id, category, activity_title FROM daily_log_activities WHERE daily_log_id = ? ORDER BY sort_order ASC, id ASC", [(int)$log['id']]);
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
        <!-- ⑧ OCCUPANCY RATE (OCC %) - PALING ATAS SENDIRI SESUAI REQUEST -->
        <div class="bg-surface rounded-premium border border-accent/40 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 50ms">
            <div class="px-5 lg:px-6 py-4 border-b border-accent/20 bg-gradient-to-r from-amber-50 via-yellow-50 to-amber-100/60">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 via-yellow-500 to-amber-700 flex items-center justify-center text-white shadow-md shadow-amber-500/30"><i class="fas fa-bed text-sm"></i></span>
                    <?= T('form_occ_title', 'Occupancy Rate (OCC %) • Tingkat Hunian Kamar') ?>
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
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><?= T('form_elec_wbp', 'KWH WBP') ?> <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="electricity_wbp" required oninput="calcTotals()"
                            value="<?= $log['electricity_wbp'] ?? '0.00' ?>"
                            class="js-sum-electric w-full pl-4 pr-16 py-3.5 rounded-card border border-amber-200 bg-amber-50/60 text-lg font-bold text-primary placeholder-secondary/60 focus:outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 focus:bg-white transition-all">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-amber-700 font-bold bg-amber-100 px-2 py-0.5 rounded-full">kWh</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-primary mb-2 tracking-tight"><?= T('form_elec_lwbp', 'KWH LWBP') ?> <span class="text-red-500">*</span></label>
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

        <!-- ② WATER MAIN BUILDING + COOLING TOWER (HANYA 2 SUMBER) -->
        <div class="bg-surface rounded-premium border border-blue-200/60 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 90ms">
            <div class="px-5 lg:px-6 py-4 border-b border-blue-100/80 bg-gradient-to-r from-blue-50/90 via-sky-50/60 to-cyan-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white shadow-md shadow-blue-500/30"><i class="fas fa-droplet text-sm"></i></span>
                    <?= T('form_water_title', '② Water - Konsumsi Air Main Building & Cooling Tower (m³)') ?>
                </h3>
                <p class="text-xs text-secondary mt-0.5"><?= T('form_water_sub', '2 sumber utama: Main Building + Cooling Tower') ?></p>
            </div>
            <div class="p-5 lg:p-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 gap-4">
                <?php
                $waterFields = [
                    ['water_main_building', T('form_water_main', 'Main Building'), 'text-cyan-600', 'bg-cyan-50/60', 'border-cyan-200'],
                    ['water_cooling_tower', T('form_water_ct', 'Cooling Tower'), 'text-teal-600', 'bg-teal-50/60', 'border-teal-200'],
                ];
                foreach ($waterFields as $wf) {
                    [$field, $label, $col, $bg, $bor] = $wf;
                    $val = $log[$field] ?? '0.00';
                    echo <<<HTML
                <div>
                    <label class="block text-xs font-extrabold text-primary mb-1.5 tracking-wide">{$label}</label>
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
                <div class="sm:col-span-2 md:col-span-2 pt-1 mt-1 border-t border-dashed border-blue-100">
                    <div class="flex items-center justify-between gap-4">
                        <label class="text-sm font-extrabold text-primary flex items-center gap-1.5"><i class="fas fa-calculator text-primary"></i><?= T('form_water_total_label', 'TOTAL KONSUMSI AIR (Hanya Main Building)') ?></label>
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

        <!-- ⑦ FUEL -->
        <div class="bg-surface rounded-premium border border-rose-200/70 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 290ms">
            <div class="px-5 lg:px-6 py-4 border-b border-rose-100/80 bg-gradient-to-r from-rose-50/90 via-pink-50/60 to-red-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-rose-400 to-red-600 flex items-center justify-center text-white shadow-md shadow-rose-500/30"><i class="fas fa-gas-pump text-sm"></i></span>
                    <?= T('form_fuel_title', '⑦ Fuel - Konsumsi Solar / Bahan Bakar (Liter)') ?>
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

        <!-- ⑨ ENG ACTIVITY COUNTERS (OTOMATIS DIHITUNG DARI LIST DINAMIS DI BAWAH) - HANYA MANAGER YANG BISA LIHAT -->
        <?php if (($user['role'] ?? '') === 'manager'): ?>
        <div class="bg-surface rounded-premium border border-accent/30 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 330ms">
            <div class="px-5 lg:px-6 py-4 border-b border-accent/20 bg-gradient-to-r from-amber-50/90 via-yellow-50/60 to-amber-50/70">
                <h3 class="font-bold text-primary flex items-center gap-2">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center text-white shadow-md shadow-amber-500/30"><i class="fas fa-list-ol text-sm"></i></span>
                    <?= T('form_activity_title', '⑨ Engineering Activity Counter — OTOMATIS') ?>
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
        function calcTotals() {
            // Total Listrik = sum all js-sum-electric
            let e = 0;
            document.querySelectorAll('.js-sum-electric').forEach(el => e += parseFloat(el.value || 0));
            document.getElementById('totalElectricity').value = e.toFixed(2);
            // Total Water = HANYA MAIN BUILDING SAJA
            let wmb = document.querySelector('input[name="water_main_building"]');
            let w = wmb ? parseFloat(wmb.value || 0) : 0;
            document.getElementById('totalWater').value = w.toFixed(2);
            // Total Gas = sum 2 js-sum-gas
            let g = 0;
            document.querySelectorAll('.js-sum-gas').forEach(el => g += parseFloat(el.value || 0));
            document.getElementById('totalGas').value = g.toFixed(2);
        }
        // Initialize totals saat page load (jika ada data dari DB yang sudah di set ke input field hidden tapi sumnya update)
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
