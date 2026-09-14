<?php
/**
 * 🔧 DEBUG NILAI ASLI WBP / LWBP (10-12 Sept 2026, eng#1)
 * 
 * ⚠️ PENTING: FILE INI PERSIS SAMA INIT DENGAN recalc_utility_all_dates.php
 *            (100% SAMA line 40-50 recalc.php) SUPAYA PASTI BERJALAN SEPERTI RECALC!
 * 
 * User: Upload file ini ke hosting (selevel index.php, sama seperti recalc)
 *       Buka: https://registengineering.com/check_nilai_1209_v3.php
 *       → COPY SEMUA OUTPUT (text plain) → PASTE ke WA / chat.
 *       HAPUS file ini SETELAH PAKAI!
 */

/* ═══════════════════════════════════════════════════════════════════
   100% PERSIS SAMA DENGAN recalc_utility_all_dates.php LINE 40-56!
   ═══════════════════════════════════════════════════════════════════ */
$scriptDir = __DIR__;
$configPath = $scriptDir . '/config/config.php';
if (!file_exists($configPath)) { $configPath = $scriptDir . '/../config/config.php'; }
require_once $configPath;
if (!class_exists('Database', false)) { die('Class Database tidak ditemukan! Cek config/config.php path benar?'); }
$db = Database::getInstance();
if (!$db) { die('Gagal connect DB! Cek username/password database di config/config.php.'); }

header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(0);
@ini_set('memory_limit', '-1');
date_default_timezone_set('Asia/Makassar');

/* ═══════════════════════════════════════════════════════════════════
   DEBUG OUTPUT (TEXT PLAIN, mudah di copy paste!)
   ═══════════════════════════════════════════════════════════════════ */

echo "============================================================================\n";
echo "🔧 DEBUG NILAI ASLI WBP / LWBP / WATER MB (ENGINEER #1)\n";
echo "   Tanggal: 10/09 s/d 12/09 September 2026\n";
echo "============================================================================\n\n";

echo "[1] SEMUA ROW DAILY_LOGS eng#1 (10-12/09/2026):\n";
echo str_pad("ID",6) . str_pad("TANGGAL",12) . str_pad("JAM",9) . str_pad("SHIFT",7)
     . str_pad("WBP (kWh)",18," ",STR_PAD_LEFT) . str_pad("LWBP (kWh)",18," ",STR_PAD_LEFT)
     . str_pad("TOTAL METER",18," ",STR_PAD_LEFT) . str_pad("WATER MB",15," ",STR_PAD_LEFT)
     . str_pad("T ELEC DB",18," ",STR_PAD_LEFT) . str_pad("T WATER DB",15," ",STR_PAD_LEFT)
     . " STATUS\n";
echo str_repeat("-", 170) . "\n";

$rows = $db->fetchAll("
    SELECT id, engineer_id, DATE(log_date) AS tanggal, TIME(log_date) AS jam, shift,
           COALESCE(electricity_wbp,0) AS wbp,
           COALESCE(electricity_lwbp,0) AS lwbp,
           (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) AS total_meter,
           COALESCE(water_main_building,0) AS mb,
           COALESCE(total_electricity,0) AS telec,
           COALESCE(total_water,0) AS twater,
           status
    FROM daily_logs
    WHERE engineer_id = 1
      AND log_date BETWEEN '2026-09-10 00:00:00' AND '2026-09-13 23:59:59'
    ORDER BY log_date ASC
");
if (!$rows) { echo "❌ TIDAK ADA DATA!\n"; } else {
    foreach ($rows as $r) {
        $bgW = '';
        if ((float)$r['mb'] > 0 && (float)$r['total_meter'] > 0) $bgW = ' [WATER+LISTRIK ISI ✅]';
        echo str_pad((string)$r['id'],6)
             . str_pad((string)$r['tanggal'],12)
             . str_pad((string)$r['jam'],9)
             . str_pad((string)($r['shift'] ?? '-'),7)
             . str_pad(number_format((float)$r['wbp'],2,',','.'),18," ",STR_PAD_LEFT)
             . str_pad(number_format((float)$r['lwbp'],2,',','.'),18," ",STR_PAD_LEFT)
             . str_pad(number_format((float)$r['total_meter'],2,',','.'),18," ",STR_PAD_LEFT)
             . str_pad(number_format((float)$r['mb'],2,',','.'),15," ",STR_PAD_LEFT)
             . str_pad(number_format((float)$r['telec'],2,',','.'),18," ",STR_PAD_LEFT)
             . str_pad(number_format((float)$r['twater'],2,',','.'),15," ",STR_PAD_LEFT)
             . " " . ($r['status'] ?? '-') . $bgW . "\n";
    }
}
echo "\n";

/* -----------------------------------------------------------------------------
   [2] BASELINE YANG DIPILIH FORMULA REVISI3 (ORDER BY water>0 first log_date DESC)
   UNTUK SETIAP TANGGAL 10/09, 11/09, 12/09
   ----------------------------------------------------------------------------- */
echo "============================================================================\n";
echo "[2] ROW BASELINE KEMARIN YANG DIPILIH (SAMA FORMULA daily_log_form Revisi3!):\n";
echo "   (ORDER BY: water>0 group first ASC → log_date DESC → water DESC → listrik DESC)\n";
echo "============================================================================\n\n";

foreach (['2026-09-10','2026-09-11','2026-09-12'] as $tglTarget) {
    echo "--- Untuk TANGGAL TARGET: <$tglTarget> (baseline = row TERAKHIR SEBELUM $tglTarget) ---\n";

    /* Ambil TODAY row (target tanggal) untuk dapat today WBP LWBP MB */
    $todayRow = $db->fetchOne("
        SELECT COALESCE(electricity_wbp,0) wbp, COALESCE(electricity_lwbp,0) lwbp,
               (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) tm,
               COALESCE(water_main_building,0) mb
        FROM daily_logs
        WHERE engineer_id = 1 AND DATE(log_date) = ?
        ORDER BY (CASE WHEN COALESCE(water_main_building,0) > 0 THEN 0 ELSE 1 END) ASC,
                 log_date DESC, id DESC
        LIMIT 1
    ", [$tglTarget]);

    if (!$todayRow) { echo "   ⚠️ TIDAK ADA ROW TODAY $tglTarget (skip)\n\n"; continue; }

    /* BASELINE REVISI 3 (SAMA PERSIS daily_log_form line L420): */
    $baseRow = $db->fetchOne("
        SELECT id, DATE(log_date) t, TIME(log_date) j, shift,
               COALESCE(electricity_wbp,0) wbp, COALESCE(electricity_lwbp,0) lwbp,
               (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) tm,
               COALESCE(water_main_building,0) mb
        FROM daily_logs
        WHERE engineer_id = 1
          AND log_date < ?
          AND (COALESCE(electricity_wbp,0) > 0 OR COALESCE(electricity_lwbp,0) > 0 OR COALESCE(water_main_building,0) > 0)
        ORDER BY
          (CASE WHEN COALESCE(water_main_building,0) > 0 THEN 0 ELSE 1 END) ASC,
          log_date DESC,
          COALESCE(water_main_building,0) DESC,
          (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) DESC,
          id DESC
        LIMIT 1
    ", [$tglTarget . ' 00:00:00']);

    if (!$baseRow) { echo "   ❌ TIDAK ADA BASELINE!\n\n"; continue; }

    echo "   • BASELINE DIPILIH: Row ID=" . $baseRow['id']
         . " TANGGAL=" . $baseRow['t'] . " JAM=" . $baseRow['j']
         . " SHIFT=" . ($baseRow['shift'] ?? '-') . "\n";
    echo "     → WBP baseline : " . number_format((float)$baseRow['wbp'],2,',','.') . " kWh\n";
    echo "     → LWBP baseline: " . number_format((float)$baseRow['lwbp'],2,',','.') . " kWh\n";
    echo "     → MB baseline   : " . number_format((float)$baseRow['mb'],2,',','.') . " m3\n";
    echo "\n";
    echo "   • TODAY $tglTarget:\n";
    echo "     → WBP today     : " . number_format((float)$todayRow['wbp'],2,',','.') . " kWh\n";
    echo "     → LWBP today    : " . number_format((float)$todayRow['lwbp'],2,',','.') . " kWh\n";
    echo "     → MB today      : " . number_format((float)$todayRow['mb'],2,',','.') . " m3\n";

    $dWbp = max(0.0, (float)$todayRow['wbp'] - (float)$baseRow['wbp']);
    $dLwbp = max(0.0, (float)$todayRow['lwbp'] - (float)$baseRow['lwbp']);
    $totalDiff = $dWbp + $dLwbp;
    $totalCons = $totalDiff;
    $noteKali = '×1 (LANGSUNG > 500)';
    if ($totalDiff > 0.001 && $totalDiff <= 500.0) {
        $totalCons = $totalDiff * 8000.0;
        $noteKali = '≤ 500 → × 8000 ✅';
    }

    /* WATER FORMULA SAMA: */
    $dMb = max(0.0, (float)$todayRow['mb'] - (float)$baseRow['mb']);
    $totalMbCons = $dMb;
    $noteMb = '×1 (LANGSUNG > 500)';
    if ($dMb > 0.001 && $dMb <= 500.0) {
        $totalMbCons = $dMb * 10.0;
        $noteMb = '≤ 500 → × 10 ✅';
    }

    echo "\n";
    echo "   🧮🧮🧮 PERHITUNGAN LISTRIK (SAMA PERSIS DAILY_LOG_FORM L485!):\n";
    echo "     dWbp  = Today WBP - Yesterday WBP = "
         . number_format((float)$todayRow['wbp'],2,',','.') . " - "
         . number_format((float)$baseRow['wbp'],2,',','.') . " = "
         . str_pad(number_format($dWbp,2,',','.'),15," ",STR_PAD_LEFT) . " kWh\n";
    echo "     dLwbp = Today LWBP - Yesterday LWBP = "
         . number_format((float)$todayRow['lwbp'],2,',','.') . " - "
         . number_format((float)$baseRow['lwbp'],2,',','.') . " = "
         . str_pad(number_format($dLwbp,2,',','.'),15," ",STR_PAD_LEFT) . " kWh\n";
    echo "     ─────────────────────────────────────────────────────────\n";
    echo "     TOTAL SELISIH = dWbp + dLwbp = "
         . str_pad(number_format($totalDiff,2,',','.'),15," ",STR_PAD_LEFT) . " kWh\n";
    echo "     RUMUS: $noteKali\n";
    echo "     ⭐⭐⭐ TOTAL LISTRIK SEHARUSNYA DISIMPAN = "
         . str_pad(number_format($totalCons,2,',','.'),20," ",STR_PAD_LEFT) . " kWh\n";

    echo "\n";
    echo "   💧💧💧 PERHITUNGAN WATER MAIN BUILDING (FORMULA BENAR v6):\n";
    echo "     dMB = Today MB - Yesterday MB = "
         . number_format((float)$todayRow['mb'],2,',','.') . " - "
         . number_format((float)$baseRow['mb'],2,',','.') . " = "
         . str_pad(number_format($dMb,2,',','.'),15," ",STR_PAD_LEFT) . " m3\n";
    echo "     RUMUS: $noteMb\n";
    echo "     ⭐⭐⭐ TOTAL WATER MB SEHARUSNYA DISIMPAN = "
         . str_pad(number_format($totalMbCons,2,',','.'),20," ",STR_PAD_LEFT) . " m3\n";
    echo "\n\n";
}

echo "============================================================================\n";
echo "📋 INSTRUKSI: COPY SEMUA TEKS INI (Ctrl+A → Ctrl+C), PASTE ke chat/WA ya kak!\n";
echo "============================================================================\n";
