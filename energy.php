<?php
/**
 * ⚡ ENERGY DASHBOARD (WA 18.09)
 * Bisa diakses SEMUA ROLE (Manager, Supervisor, Engineer/Staff)
 * Posisi: Sidebar DI BAWAH Dashboard Utama (COLLAPSIBLE)
 * Style DOMINAN PUTIH SLATE NETRAL.
 * Data SEKARANG REAL DARI DATABASE: merge daily_logs + energy_logs
 */

require_once __DIR__ . '/config/config.php';
requireLogin();

$db = Database::getInstance();

$userId   = intval($_SESSION['user_id'] ?? 0);
$userRole = $_SESSION['role'] ?? 'manager';

$statusWhere = '';
if ($userRole === 'engineer') {
    $statusWhere = " AND engineer_id = " . $userId;
}

$today     = date('Y-m-d');
$monthStart = date('Y-m-01');
$lastYear  = intval(date('Y')) - 1;

$defaultFrom = $monthStart;
$defaultTo   = date('Y-m-t');
$dateFrom = $_GET['date_from'] ?? $defaultFrom;
$dateTo   = $_GET['date_to']   ?? $defaultTo;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $defaultTo;

/**
 * HELPER: Merge SUM / AVG utility dari 2 tabel (daily_logs + energy_logs)
 * Sama 1:1 dengan index.php agar result konsisten dengan Dashboard Utama
 */
function utilFetchBoth_Db($db, $approvedWhereDaily, $userId, $userRole, $dateFrom, $dateTo, $agg = 'SUM')
{
    $out = [
        'elec'  => 0.0,
        'water' => 0.0,
        'gas'   => 0.0,
        'fuel'  => 0.0,
        'cnt_d' => 0,
        'cnt_e' => 0,
    ];

    $agg = strtoupper($agg) === 'AVG' ? 'AVG' : 'SUM';
    $debug = (isset($_GET['_dbg']) && currentUser() && in_array((string)(currentUser()['role'] ?? ''), ['manager','admin','supervisor'], true));

    try {
        // ── A) daily_logs (legacy)
        $sqlD = "SELECT
            COALESCE($agg(CAST(COALESCE(dl.total_electricity,0) AS DECIMAL(18,4))),0) as elec,
            COALESCE($agg(CAST(COALESCE(dl.total_water,0) AS DECIMAL(18,4))),0)       as water,
            COALESCE($agg(CAST(COALESCE(dl.total_gas,0) AS DECIMAL(18,4))),0)         as gas,
            COALESCE($agg(CAST(COALESCE(dl.total_fuel,0) AS DECIMAL(18,4))),0)        as fuel,
            COUNT(*) as cnt
            FROM daily_logs dl
            WHERE DATE(dl.log_date) BETWEEN ? AND ? AND $approvedWhereDaily";
        $d = $db->fetchOne($sqlD, [$dateFrom, $dateTo]);
        if ($debug) { echo "<div style='display:none' class='_dbg_e'>DAILY: $sqlD | ".json_encode($d)."</div>"; }

        // ── B) energy_logs (dari energy_logsheet.php)
        $elWhere   = "DATE(el.log_date) BETWEEN ? AND ?";
        $elParams  = [$dateFrom, $dateTo];
        if ($userRole === 'engineer') {
            $cols = @$db->fetchAll("SHOW COLUMNS FROM energy_logs LIKE 'created_by'");
            if (!empty($cols)) {
                $elWhere .= " AND el.created_by = ?";
                $elParams[] = $userId;
            }
        }
        /* ⚠️ KRITIS FIX: SETIAP KOLOM DIBUNGKUS COALESCE(...,0) DULU SEBELUM DIJUMLAH!
           KALAU TIDAK: 100 + 200 + NULL = NULL (hasil 0 semua di cards!) */
        $sqlE = "SELECT
            COALESCE($agg(
                CAST(COALESCE(el.pln_lwbp_kwh,0) AS DECIMAL(18,4))
              + CAST(COALESCE(el.pln_wbp_kwh,0)  AS DECIMAL(18,4))
              + CAST(COALESCE(el.genset_kwh,0)   AS DECIMAL(18,4))
            ), 0) as elec,
            COALESCE($agg(
                CAST(COALESCE(el.air_m3,0)            AS DECIMAL(18,4))
              + CAST(COALESCE(el.air_deep_well_m3,0) AS DECIMAL(18,4))
            ), 0) as water,
            COALESCE($agg(
                CAST(COALESCE(el.gas_kg,0)    AS DECIMAL(18,4))
              + CAST(COALESCE(el.gas_lng_kg,0) AS DECIMAL(18,4))
            ), 0) as gas,
            COALESCE($agg(CAST(COALESCE(el.solar_liter,0) AS DECIMAL(18,4))), 0) as fuel,
            COUNT(*) as cnt
            FROM energy_logs el WHERE $elWhere";
        $e = $db->fetchOne($sqlE, $elParams);
        if ($debug) { echo "<div style='display:none' class='_dbg_e'>ENERGY: $sqlE | params=".json_encode($elParams)." | result=".json_encode($e)."</div>"; }

        $dElec  = (float)($d['elec']  ?? 0);
        $dWater = (float)($d['water'] ?? 0);
        $dGas   = (float)($d['gas']   ?? 0);
        $dFuel  = (float)($d['fuel']  ?? 0);
        $cntD   = (int)($d['cnt'] ?? 0);

        $eElec  = (float)($e['elec']  ?? 0);
        $eWater = (float)($e['water'] ?? 0);
        $eGas   = (float)($e['gas']   ?? 0);
        $eFuel  = (float)($e['fuel']  ?? 0);
        $cntE   = (int)($e['cnt'] ?? 0);

        if ($agg === 'SUM') {
            $out['elec']  = (float)($dElec  + $eElec);
            $out['water'] = (float)($dWater + $eWater);
            $out['gas']   = (float)($dGas   + $eGas);
            $out['fuel']  = (float)($dFuel  + $eFuel);
        } else {
            $hasD = $dElec + $dWater + $dGas + $dFuel > 0;
            $hasE = $eElec + $eWater + $eGas + $eFuel > 0;
            if ($hasD && !$hasE) {
                $out['elec']  = (float)$dElec;
                $out['water'] = (float)$dWater;
                $out['gas']   = (float)$dGas;
                $out['fuel']  = (float)$dFuel;
            } elseif ($hasE && !$hasD) {
                $out['elec']  = (float)$eElec;
                $out['water'] = (float)$eWater;
                $out['gas']   = (float)$eGas;
                $out['fuel']  = (float)$eFuel;
            } elseif ($hasD && $hasE) {
                $out['elec']  = (float)(($dElec  + $eElec)  / 2);
                $out['water'] = (float)(($dWater + $eWater) / 2);
                $out['gas']   = (float)(($dGas   + $eGas)   / 2);
                $out['fuel']  = (float)(($dFuel  + $eFuel)  / 2);
            }
        }

        $out['cnt_d'] = (int)$cntD;
        $out['cnt_e'] = (int)$cntE;
        $out['log_count'] = max(1, (int)($cntD + $cntE));
    } catch (\Throwable $ex) {
        $out['elec'] = 0; $out['water'] = 0; $out['gas'] = 0; $out['fuel'] = 0;
        $out['log_count'] = 1; $out['cnt_d'] = 0; $out['cnt_e'] = 0;
        if ($debug) { echo "<div style='display:none' class='_dbg_e'>EXCEPTION: ".$ex->getMessage()."</div>"; }
        else { error_log('energy.php utilFetchBoth_Db ERROR: '.$ex->getMessage()); }
    }

    return $out;
}

// ───────────────────────────────────────────────────────────────
// ── 1) 6 CARDS UTILITY PERIODE (sesuai filter tanggal) ─────────
// ───────────────────────────────────────────────────────────────
$sumBoth = utilFetchBoth_Db($db, "status='approved' $statusWhere", $userId, $userRole, $dateFrom, $dateTo, 'SUM');

// ── Query SPLIT energy_logs untuk kartu Gas LPG / Gas LNG / Deep Well (split tidak ada di daily_logs)
$elWhere  = "el.log_date BETWEEN ? AND ?";
$elParams = [$dateFrom, $dateTo];
if ($userRole === 'engineer') {
    $cols = @$db->fetchAll("SHOW COLUMNS FROM energy_logs LIKE 'created_by'");
    if (!empty($cols)) {
        $elWhere .= " AND el.created_by = ?";
        $elParams[] = $userId;
    }
}
try {
    $splitEL = $db->fetchOne("SELECT
        COALESCE(SUM(el.gas_kg),0)            as gas_lpg,
        COALESCE(SUM(el.gas_lng_kg),0)        as gas_lng,
        COALESCE(SUM(el.air_m3),0)            as air_pdam,
        COALESCE(SUM(el.air_deep_well_m3),0)  as air_deep
        FROM energy_logs el WHERE $elWhere", $elParams);
} catch (\Throwable $ex) {
    $splitEL = ['gas_lpg' => 0, 'gas_lng' => 0, 'air_pdam' => 0, 'air_deep' => 0];
}

$elecTotal  = (float)($sumBoth['elec']  ?? 0);
$solarTotal = (float)($sumBoth['fuel']  ?? 0);

$gasLpgSplit = (float)($splitEL['gas_lpg']  ?? 0);
$gasLngSplit = (float)($splitEL['gas_lng']  ?? 0);
$gasBothTotal = (float)($sumBoth['gas'] ?? 0);
$gasSisaDaily = max(0, $gasBothTotal - ($gasLpgSplit + $gasLngSplit));
$gasLpgTotal  = $gasLpgSplit + $gasSisaDaily;
$gasLngTotal  = $gasLngSplit;

$airDeepSplit = (float)($splitEL['air_deep'] ?? 0);
$airDeepTotal = $airDeepSplit;

// Konsumsi Air = akumulasi selisih konsumsi Main Building per hari (total_water di daily_logs)
try {
    $mbWaterRow = $db->fetchOne(
        "SELECT COALESCE(SUM(CAST(COALESCE(dl.total_water,0) AS DECIMAL(18,4))),0) as mb_water
         FROM daily_logs dl
         WHERE DATE(dl.log_date) BETWEEN ? AND ? AND dl.status='approved' $statusWhere",
        [$dateFrom, $dateTo]
    );
    $konsumsiAirTotal = (float)($mbWaterRow['mb_water'] ?? 0);
} catch (\Throwable $ex) {
    $konsumsiAirTotal = 0.0;
}

function fmtENum($n, $dec = 2)
{
    if ($n <= 0) return '0';
    return number_format($n, $dec, ',', '.');
}

$periodeLabel = date('d M Y', strtotime($dateFrom)) . ' - ' . date('d M Y', strtotime($dateTo));

$energyStats = [
    ['label' => 'Konsumsi Listrik', 'unit' => 'kWh',   'val' => fmtENum($elecTotal, 2),  'sub' => $periodeLabel],
    ['label' => 'Konsumsi Solar',   'unit' => 'Liter', 'val' => fmtENum($solarTotal, 2), 'sub' => $periodeLabel],
    ['label' => 'Konsumsi Gas LPG', 'unit' => 'Kg',    'val' => fmtENum($gasLpgTotal, 1),'sub' => $periodeLabel],
    ['label' => 'Konsumsi Gas LNG', 'unit' => 'Kg',    'val' => fmtENum($gasLngTotal, 1),'sub' => $periodeLabel],
    ['label' => 'Konsumsi Air',     'unit' => 'm3',    'val' => fmtENum($konsumsiAirTotal, 1),'sub' => $periodeLabel],
    ['label' => 'Air Deep Well',    'unit' => 'm3',    'val' => fmtENum($airDeepTotal, 1),'sub' => $periodeLabel],
];

$pageTitle = 'Energy Dashboard';
$pageSubtitle = 'Dashboard Ringkasan Konsumsi Energi Harian St. Regis Bali. Listrik, Solar, Gas, Air & Utility Lainnya.';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content px-4 sm:px-6 lg:px-8 py-6 max-w-[1800px] mx-auto">
    <!-- BREADCRUMB -->
    <div class="mb-4 flex items-center gap-2 text-xs font-semibold text-slate-500">
        <a href="<?= BASE_URL ?>index.php" class="hover:text-primary transition"><i class="fas fa-house mr-1"></i> Dashboard</a>
        <i class="fas fa-chevron-right text-[10px] text-slate-400"></i>
        <span class="text-primary font-black">⚡ Energy</span>
    </div>

    <!-- HEADER JUDUL -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 animate-slide-up">
        <div>
            <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">Energy Dashboard</h1>
        </div>
     
    </div>

    <!-- FILTER PERIODE TANGGAL (REAL FILTER! submit ke GET parameter) -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-6 animate-slide-up" style="animation-delay: 60ms">
        <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>energy.php">
            <div class="p-4 sm:p-5 max-w-[780px]">
                <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                    <div class="flex-1 min-w-0">
                        <label class="text-[11px] sm:text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Tanggal Mulai</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-slate-900 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary/70">
                    </div>
                    <div class="flex-1 min-w-0">
                        <label class="text-[11px] sm:text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Tanggal Akhir</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-slate-900 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary/70">
                    </div>
                    <div class="sm:shrink-0 flex gap-2">
                        <button type="submit" class="w-full sm:w-auto px-5 py-2.5 rounded-lg bg-slate-900 text-white text-sm font-bold shadow-sm hover:bg-slate-800 transition inline-flex items-center justify-center gap-2 whitespace-nowrap">
                            <i class="fas fa-filter text-xs"></i> Terapkan
                        </button>
                        <a href="<?= BASE_URL ?>energy.php" type="button" class="w-full sm:w-auto px-4 py-2.5 rounded-lg bg-white border border-slate-200 text-slate-600 text-sm font-bold shadow-sm hover:bg-slate-50 transition inline-flex items-center justify-center gap-2 whitespace-nowrap">
                            <i class="fas fa-rotate-left text-xs"></i> Reset
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- 6 STATISTIC CARDS ENERGY (REAL DARI DB! 2 BARIS x 3 KOLOM DI LAPTOP. 6th card Air Deep Well) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-6 gap-3 mb-6 animate-slide-up" style="animation-delay: 80ms">
        <?php foreach ($energyStats as $s): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500 leading-tight"><?= htmlspecialchars($s['label']) ?></p>
            <?php if (!empty($s['sub'])): ?>
            <p class="text-[9px] font-bold text-slate-400 mt-0.5 leading-tight"><?= htmlspecialchars($s['sub']) ?></p>
            <?php endif; ?>
            <div class="mt-2">
                <p class="font-display text-xl sm:text-2xl font-black text-primary leading-none">
                    <?= htmlspecialchars($s['val']) ?>
                </p>
                <p class="text-[12px] font-bold text-slate-400 mt-1.5 leading-tight">
                    <?= htmlspecialchars($s['unit']) ?>
                </p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>


</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
