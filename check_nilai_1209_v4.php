<?php
/**
 * 🔧🔧🔧 DEBUG v4: CARI NILAI ASLI WBP/LWBP/MB eng#1 SEKITAR TANGGAL 10-12 SEPTEMBER 2026
 * ============================================================
 * MASALAH v3: WHERE DATE BETWEEN '2026-09-10' AND '2026-09-13' → TIDAK ADA DATA ❌
 * DIDUGA KUAT:
 *   1. Format tanggal di DB BUKAN '2026-09-10' TAPI '2026-09-12' (YYYY-MM-DD INGGRIS)
 *      ATAU format tanggal dd/mm/yyyy (12/09/2026) ??
 *   2. Atau engineer_id BUKAN 1! (eng#2? eng#3?)
 *   3. Atau status daily_logs = 'pending'? (Recalc hanya approved)
 *   4. Atau tanggal di DB = 12/09/2026 (tanggal user form = 12/09/2026)
 * ============================================================
 * SOLUSI v4:
 *   - TAMPILKAN 50 ROW TERAKHIR daily_logs (ORDER BY log_date DESC LIMIT 50)
 *     (SEMUA engineer, SEMUA status!) → LIHAT TANGGAL BERAPA DATA TERBARU ADA?
 *   - TAMPILKAN engineer_id, shift, WBP, LWBP, MB, status!
 *   - KITA LIHAT PASTI row 12/09 ada ID berapa, eng berapa, WBP berapa.
 *
 * User: Upload file ini → browse URL: https://registengineering.com/check_nilai_1209_v4.php
 *       → COPY TEKS SEMUA → PASTE!
 */

/* PERSIS SAMA INIT DENGAN recalc_utility_all_dates.php LINE 40-56 */
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

echo "========================================================================================\n";
echo "🔧🔧🔧 DEBUG v4: 50 ROW DAILY_LOGS TERAKHIR (SEMUA ENGINEER & STATUS)\n";
echo "========================================================================================\n";
echo "TUJUAN: CARI PASTI row tanggal 10/09, 11/09, 12/09 ADA DIMANA? eng ID berapa? WBP berapa?\n\n";

$rows = $db->fetchAll("
    SELECT id, engineer_id, DATE(log_date) AS tanggal, TIME(log_date) AS jam, shift, status,
           COALESCE(electricity_wbp,0) AS wbp,
           COALESCE(electricity_lwbp,0) AS lwbp,
           (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) AS total_meter,
           COALESCE(water_main_building,0) AS mb,
           COALESCE(total_electricity,0) AS t_elec,
           COALESCE(total_water,0) AS t_water
    FROM daily_logs
    ORDER BY log_date DESC
    LIMIT 50
");

if (!$rows) { echo "❌❌❌ TIDAK ADA DATA daily_logs SAMA SEKALI DI DB!\n"; exit; }

echo str_pad("NO",4) . str_pad("ID",6) . str_pad("ENG",5)
     . str_pad("TANGGAL",12) . str_pad("JAM",9) . str_pad("SHIFT",8)
     . str_pad("STATUS",12)
     . str_pad("WBP",18," ",STR_PAD_LEFT)
     . str_pad("LWBP",18," ",STR_PAD_LEFT)
     . str_pad("T_METER",18," ",STR_PAD_LEFT)
     . str_pad("MB_WATER",15," ",STR_PAD_LEFT)
     . str_pad("T_ELEC_DB",18," ",STR_PAD_LEFT)
     . str_pad("T_WATER_DB",15," ",STR_PAD_LEFT)
     . "\n";
echo str_repeat("-", 210) . "\n";

$n = 0;
foreach ($rows as $r) {
    $n++;
    $mark = '';
    $tgl = (string)($r['tanggal'] ?? '');
    /* Beri tanda jika tanggal sekitar 10-13 September 2026 (multi format) */
    if (strpos($tgl, '09-10')!==false || strpos($tgl, '09-11')!==false || strpos($tgl, '09-12')!==false || strpos($tgl, '09-13')!==false ||
        strpos($tgl, '09/10')!==false || strpos($tgl, '09/11')!==false || strpos($tgl, '09/12')!==false || strpos($tgl, '09/13')!==false ||
        strpos($tgl, '10/09')!==false || strpos($tgl, '11/09')!==false || strpos($tgl, '12/09')!==false || strpos($tgl, '13/09')!==false ||
        strpos($tgl, '10-09')!==false || strpos($tgl, '11-09')!==false || strpos($tgl, '12-09')!==false || strpos($tgl, '13-09')!==false) {
        $mark = ' 📌 TANGGAL KITA CARI!';
    }
    if ((float)$r['mb'] > 0) $mark .= ' 💧WATER_ISI';
    if ((float)$r['total_meter'] > 0 && (float)$r['t_elec'] > 0 && (float)$r['t_elec'] < 5000) $mark .= ' ⚠️LISTRIK_KECIL_BUG';
    if ((float)$r['t_elec'] == 3558.40) $mark .= ' 🚨TARGET_BUG_3558';
    if ((float)$r['t_elec'] >= 23000 && (float)$r['t_elec'] <= 28000) $mark .= ' ✅LISTRIK_BENAR_23RB_28RB';

    echo str_pad((string)$n,4)
         . str_pad((string)$r['id'],6)
         . str_pad((string)$r['engineer_id'],5)
         . str_pad($tgl,12)
         . str_pad((string)($r['jam'] ?? ''),9)
         . str_pad((string)($r['shift'] ?? '-'),8)
         . str_pad((string)($r['status'] ?? '-'),12)
         . str_pad(number_format((float)$r['wbp'],2,',','.'),18," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['lwbp'],2,',','.'),18," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['total_meter'],2,',','.'),18," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['mb'],2,',','.'),15," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['t_elec'],2,',','.'),18," ",STR_PAD_LEFT)
         . str_pad(number_format((float)$r['t_water'],2,',','.'),15," ",STR_PAD_LEFT)
         . $mark . "\n";
}
echo "\n\n========================================================================================\n";
echo "📋 [CATATAN PENTING]:\n";
echo "  • Cari baris yang ada MARK: 📌 TANGGAL KITA CARI! (sekitar tgl 10-13/09)\n";
echo "  • Perhatikan ENG ID (kolom ke-3) apakah benar = 1? Atau eng#2/eng#3?\n";
echo "  • Perhatikan WBP/LWBP: Jika WBP = 4365.03 / 4367.92 → ITULAH ROW KITA!\n";
echo "  • ✅LISTRIK_BENAR_23RB_28RB → listrik disimpan = 23120 / 27840 (FORM BENAR)\n";
echo "  • 🚨TARGET_BUG_3558 → ini baris 12/09 eng#1 T_ELEC_DB = 3558.40 (BUG recalc lama)\n\n";
echo "COPY SEMUA TEKS DI ATAS (Ctrl+A) & PASTE ke chat / WA ya kak! 🙏\n";
echo "========================================================================================\n";
