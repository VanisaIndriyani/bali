<?php
/**
 * 🔧🔧🔧 DEBUG v5: GAS 12/09 BEDA! (v6 user terima 319,89 vs v11b sekarang = 52,56)
 * ============================================================
 * TUJUAN:
 *   1) TAMPILKAN 50 ROW TERAKHIR LENGKAP DENGAN:
 *        gas_lpg, gas_lng, TOTAL (LPG+LNG), total_gas DB!
 *   2) BANDINGKAN:
 *        a) Baseline per engineer_id eng#22 tgl 11/09 ADA DATA?
 *        b) Baseline global 11/09 (eng#21) LPG+LNG = BERAPA?
 *        c) Fallback oldGas (from DB total_gas row) = BERAPA?
 *   3) TEMUKAN: Kenapa recalc hitung 52.56 bukan 319.89? Apakah 319.89 itu
 *      FALLBACK oldGas (total_gas DB sebelum recalc dijalankan) JADI KITA TERIMA!
 * ============================================================
 */

$scriptDir = __DIR__;
$configPath = $scriptDir . '/config/config.php';
if (!file_exists($configPath)) { $configPath = $scriptDir . '/../config/config.php'; }
require_once $configPath;
if (!class_exists('Database', false)) { die('Class Database tidak ditemukan!'); }
$db = Database::getInstance();
if (!$db) { die('Gagal connect DB!'); }

header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(0);
date_default_timezone_set('Asia/Makassar');

echo "========================================================================================\n";
echo "🔧 DEBUG v5: 50 ROW TERAKHIR + LENGKAP DATA GAS (LPG+LNG) + TOTAL GAS DB\n";
echo "========================================================================================\n\n";

$rows = $db->fetchAll("
    SELECT id, engineer_id, DATE(log_date) AS tanggal, TIME(log_date) AS jam, shift, status,
           COALESCE(electricity_wbp,0) AS wbp,
           COALESCE(electricity_lwbp,0) AS lwbp,
           COALESCE(water_main_building,0) AS mb,
           COALESCE(gas_lpg,0) AS lpg,
           COALESCE(gas_lng,0) AS lng,
           (COALESCE(gas_lpg,0)+COALESCE(gas_lng,0)) AS gas_total_meter,
           COALESCE(total_gas,0) AS t_gas_db,
           COALESCE(total_electricity,0) AS t_elec,
           COALESCE(total_water,0) AS t_water
    FROM daily_logs
    ORDER BY log_date DESC
    LIMIT 50
");

if (!$rows) { echo "❌ TIDAK ADA DATA daily_logs!\n"; exit; }

echo str_pad("NO",4) . str_pad("ID",6) . str_pad("ENG",5)
     . str_pad("TANGGAL",12) . str_pad("JAM",9) . str_pad("SHIFT",8)
     . str_pad("STATUS",12)
     . str_pad("WBP",15," ",STR_PAD_LEFT)
     . str_pad("LWBP",15," ",STR_PAD_LEFT)
     . str_pad("MB_WATER",15," ",STR_PAD_LEFT)
     . str_pad("GAS_LPG",15," ",STR_PAD_LEFT)
     . str_pad("GAS_LNG",15," ",STR_PAD_LEFT)
     . str_pad("GAS_TOTAL",15," ",STR_PAD_LEFT)
     . str_pad("T_GAS_DB",18," ",STR_PAD_LEFT)
     . str_pad("T_ELEC_DB",18," ",STR_PAD_LEFT)
     . str_pad("T_WATER_DB",15," ",STR_PAD_LEFT)
     . "\n";
echo str_repeat("-", 260) . "\n";

$n = 0;
foreach ($rows as $r) {
    $n++;
    $mark = '';
    $tgl = (string)($r['tanggal'] ?? '');
    if (strpos($tgl, '09-10')!==false || strpos($tgl, '09-11')!==false || strpos($tgl, '09-12')!==false ||
        strpos($tgl, '09/10')!==false || strpos($tgl, '09/11')!==false || strpos($tgl, '09/12')!==false ||
        strpos($tgl, '10/09')!==false || strpos($tgl, '11/09')!==false || strpos($tgl, '12/09')!==false) {
        $mark .= ' 📌 10-12/09';
    }
    if ((float)$r['t_gas_db'] >= 250 && (float)$r['t_gas_db'] <= 400) $mark .= ' ✅GAS_BENAR_250_400';
    if ((float)$r['t_gas_db'] >= 1 && (float)$r['t_gas_db'] <= 80) $mark .= ' ⚠️GAS_KECIL_BUG(<80)';
    if ((float)$r['t_elec'] == 27840.00) $mark .= ' ⚡LISTRIK_BENAR_27840';
    if ((float)$r['t_water'] == 4964.00) $mark .= ' 💧WATER_BENAR_4964';

    echo str_pad((string)$n,4)
         . str_pad((string)$r['id'],6)
         . str_pad((string)$r['engineer_id'],5)
         . str_pad($tgl,12)
         . str_pad((string)($r['jam'] ?? ''),9)
         . str_pad((string)($r['shift'] ?? '-'),8)
         . str_pad((string)($r['status'] ?? '-'),12)
         . str_pad(number_format((float)$r['wbp'],2,',','.'),15," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['lwbp'],2,',','.'),15," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['mb'],2,',','.'),15," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['lpg'],2,',','.'),15," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['lng'],2,',','.'),15," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['gas_total_meter'],2,',','.'),15," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['t_gas_db'],2,',','.'),18," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['t_elec'],2,',','.'),18," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['t_water'],2,',','.'),15," ",STR_PAD_LEFT)
         . $mark . "\n";
}

echo "\n\n========================================================================================\n";
echo "📋 ANALISA GAS 12/09 (ENG#22 ID=106):\n";
echo "========================================================================================\n";
$id12 = 106; // eng#22 tgl 12/09 = ID baris 106 dr output v4
$id11 = 105; // eng#21 tgl 11/09 = ID baris 105 dr output v4
$row12 = $db->fetchOne("SELECT engineer_id,gas_lpg,gas_lng,total_gas,DATE(log_date) tgl FROM daily_logs WHERE id=?", [$id12]);
$row11 = $db->fetchOne("SELECT engineer_id,gas_lpg,gas_lng,total_gas,DATE(log_date) tgl FROM daily_logs WHERE id=?", [$id11]);
if ($row12) {
    $lpg12 = (float)($row12['gas_lpg'] ?? 0);
    $lng12 = (float)($row12['gas_lng'] ?? 0);
    echo "• ROW 12/09 ID=106 eng#{$row12['engineer_id']} ({$row12['tgl']}): LPG=" . number_format($lpg12,2,',','.') . " kg + LNG=" . number_format($lng12,2,',','.') . " kg → TOTAL_METER=" . number_format($lpg12+$lng12,2,',','.') . " kg\n";
    echo "• T_GAS_DB SEKARANG (setelah v11b): " . number_format((float)($row12['total_gas'] ?? 0),2,',','.') . " kg\n";
}
if ($row11) {
    $lpg11 = (float)($row11['gas_lpg'] ?? 0);
    $lng11 = (float)($row11['gas_lng'] ?? 0);
    echo "\n• BASELINE KEMARIN 11/09 ID=105 eng#{$row11['engineer_id']} ({$row11['tgl']}): LPG=" . number_format($lpg11,2,',','.') . " + LNG=" . number_format($lng11,2,',','.') . " → TOTAL_METER=" . number_format($lpg11+$lng11,2,',','.') . " kg\n";
    if ($row12) {
        $diffLpg = max(0, $lpg12 - $lpg11);
        $diffLng = max(0, $lng12 - $lng11);
        $totDiff = $diffLpg + $diffLng;
        $calc = ($totDiff > 0 && $totDiff <= 30) ? ($totDiff * 100) : $totDiff;
        echo "• PERHITUNGAN BASELINE GLOBAL (11/09 eng#21 → 12/09 eng#22): dLpg=$diffLpg dLng=$diffLng → diff=$totDiff kg → " . ($totDiff<=30? "≤30 → ×100 = $calc kg" : "langsung $calc kg") . "\n";
    }
    echo "• T_GAS_DB 11/09 SEKARANG: " . number_format((float)($row11['total_gas'] ?? 0),2,',','.') . " kg\n";
}
echo "\n========================================================================================\n";
echo "📋 [CATATAN]: MARK ✅GAS_BENAR_250_400 = 250-400 kg (user ACCEPTED range benar)\n";
echo "              MARK ⚠️GAS_KECIL_BUG = <80 kg (salah hitung / baseline eng beda!)\n\n";
echo "COPY SEMUA TEKS (Ctrl+A) & PASTE ke chat ya kak! 🙏\n";
echo "========================================================================================\n";
