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
// 🔧 MIGRATION: ADD MISSING COLUMNS IF NOT EXISTS (LWBP/WBP/LNG/Deep Well)
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

$userId = (int)($user['id'] ?? 0);
$userName = trim((string)($user['name'] ?? 'User'));

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
$where[] = "log_date BETWEEN ? AND ?";
$params[] = $startDate;
$params[] = $endDate;
if ($shiftF !== '') {
    $where[] = "shift = ?";
    $params[] = $shiftF;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

// ==============================================
// 📊 SUMMARY 6 CARDS MINI
// ==============================================
$sumRow = $db->fetchOne(
    "SELECT COUNT(id) AS total_entri,
            SUM(pln_kwh + genset_kwh) AS total_kwh,
            SUM(solar_liter) AS total_solar,
            SUM(gas_kg + gas_lng_kg) AS total_gas,
            SUM(air_m3 + air_deep_well_m3) AS total_air,
            COUNT(DISTINCT NULLIF(pic_name,'')) AS total_pic
     FROM energy_logs $whereSql",
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
// 📋 QUERY LIST LOG (REAL DB)
// ==============================================
$logs = $db->fetchAll(
    "SELECT * FROM energy_logs $whereSql ORDER BY log_date DESC, FIELD(shift,'pagi','siang','malam'), id DESC",
    $params
);

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
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1"><?= fmtNum($tAir, 1) ?> <span class="text-sm text-slate-500 font-bold">m³</span></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">PIC Aktif</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1"><?= number_format($tPic,0,',','.') ?> <span class="text-sm text-slate-500 font-bold">Orang</span></p>
        </div>
    </div>

    <!-- TABLE LIST LOG SHEET - REAL DB -->
    <div class="bg-white rounded-premium border border-slate-200 shadow-sm overflow-hidden mb-6 animate-slide-up" style="animation-delay: 90ms">
        <div class="px-3 sm:px-5 py-2.5 text-[11px] sm:text-xs text-slate-500 italic font-semibold flex flex-wrap items-center gap-1.5 bg-slate-50/70 border-b border-slate-100">
            <i class="fas fa-hand-point-right text-indigo-500 flex-shrink-0"></i>
            <span class="flex-shrink">Scroll ke kanan untuk melihat kolom <strong class="not-italic text-slate-700">PIC</strong> &amp; <strong class="not-italic text-slate-700">Aksi</strong> yang ada di sisi kanan tabel</span>
            <i class="fas fa-arrow-right text-indigo-500 flex-shrink-0"></i>
        </div>
        <div class="overflow-x-auto -mx-4 sm:mx-0 px-4 sm:px-0 pb-3 pr-2">
            <table class="w-full text-sm min-w-[1350px] table-auto border-collapse">
                <thead class="bg-slate-50 border-b-2 border-slate-200">
                    <tr class="text-left text-secondary text-xs">
                        <th class="px-2 sm:px-3 py-3 font-bold whitespace-nowrap w-12 text-center">#</th>
                        <th class="px-2 sm:px-3 py-3 font-bold whitespace-nowrap">Tanggal</th>
                        <th class="px-2 sm:px-3 py-3 font-bold whitespace-nowrap w-[220px]">Shift</th>
                        <th class="px-2 sm:px-3 py-3 font-bold text-center whitespace-nowrap w-[95px] border-l border-slate-200" colspan="3">LISTRIK (kWh)</th>
                        <th class="px-2 sm:px-3 py-3 font-bold text-right whitespace-nowrap w-[100px] border-l border-slate-200">Solar (L)</th>
                        <th class="px-2 sm:px-3 py-3 font-bold text-center whitespace-nowrap w-[190px] border-l border-slate-200" colspan="2">GAS (Kg)</th>
                        <th class="px-2 sm:px-3 py-3 font-bold text-center whitespace-nowrap w-[190px] border-l border-slate-200" colspan="2">AIR (m³)</th>
                        <th class="px-2 sm:px-3 py-3 font-bold whitespace-nowrap w-[200px] border-l border-slate-200">PIC</th>
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
                        <th class="px-2 sm:px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="13" class="px-4 py-16 text-center">
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
                        <td class="px-2 sm:px-3 py-3 pr-2 sm:pr-3 text-right whitespace-nowrap align-top">
                            <div class="flex gap-1.5 justify-end items-center">
                                <?php
                                    $qsEdit = $_GET; $qsEdit['edit'] = $r['id'];
                                    $qsDel  = $_GET; $qsDel['delete'] = $r['id'];
                                ?>
                                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($qsEdit) ?>#editLogModal" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-slate-700 border border-slate-200 hover:bg-slate-200 transition" title="Edit">
                                    <i class="fas fa-pencil text-xs"></i>
                                </a>
                                <button type="button" onclick="alert('Detail:\nTanggal: <?= $tgl ?>\nShift: <?= $shiftLabel ?>\nPLN LWBP: <?= fmtNum($lwbp,1) ?> kWh\nPLN WBP:  <?= fmtNum($wbp,1) ?> kWh\nPLN TOTAL:<?= fmtNum($plnT,1) ?> kWh\nGenset:   <?= fmtNum($r['genset_kwh'],1) ?> kWh\nSolar:    <?= fmtNum($r['solar_liter'],1) ?> L\nGas LPG:  <?= fmtNum($gasLpg,1) ?> Kg\nGas LNG:  <?= fmtNum($gasLng,1) ?> Kg\nAir PDAM: <?= fmtNum($airPdam,1) ?> m³\nAir DW:   <?= fmtNum($airDw,1) ?> m³\nPIC: <?= htmlspecialchars($r['pic_name'] ?: '-') ?>\nNotes: <?= htmlspecialchars($r['notes'] ?: '-') ?>')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 transition" title="Lihat Detail">
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

                <div class="p-5 sm:p-7 space-y-5 overflow-y-auto flex-1 min-h-0">
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
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Air PDAM (m³)</label>
                                <input type="number" step="0.01" min="0" name="air_m3" value="0" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Deep Well (m³)</label>
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

                <div class="p-5 sm:p-7 space-y-5 overflow-y-auto flex-1 min-h-0">
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
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Air PDAM (m³)</label>
                                <input type="number" step="0.01" min="0" name="air_m3" value="<?= (float)($editData['air_m3'] ?? 0) ?>" required class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-primary text-sm font-bold font-mono focus:outline-none focus:ring-2 focus:ring-slate-200 focus:border-slate-600">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1 block">Deep Well (m³)</label>
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
