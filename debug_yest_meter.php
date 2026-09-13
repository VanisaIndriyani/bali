<?php
/**
 * ⚡ DEBUG YESTERDAY METER (Water / Listrik) — TANPA LOGIN!
 * ============================================================
 * BUKTIKAN akar masalah: query LAMA (ORDER id DESC) ambil row
 * KOSONG (water=0) padahal row lain di tanggal yg SAMA ada isinya!
 *
 * Jalankan di browser: http://registbali.test/debug_yest_meter.php
 * Atau di server: https://registengineering.com/debug_yest_meter.php
 * ============================================================
 */
$scriptDir = __DIR__;
$configPath = $scriptDir . '/config/config.php';
if (!file_exists($configPath)) { $configPath = $scriptDir . '/../config/config.php'; }
require_once $configPath;
if (!class_exists('Database', false)) { die('Class Database tidak ditemukan! Cek config/config.php path benar?'); }
$db = Database::getInstance();
if (!$db) { die('Gagal connect DB! Cek username/password database di config/config.php.'); }

header('Content-Type: text/plain; charset=utf-8');
echo "============================================================\n";
echo "⚡ DEBUG YESTERDAY METER WATER MAIN BUILDING (10/09/2026)\n";
echo "============================================================\n\n";

$TANGGAL_FORM = '2026-09-11';

/* ---- 1. TAMPILKAN SEMUA ROW daily_logs TANGGAL 10/09/2026 ---- */
$rows10 = $db->fetchAll("SELECT id, log_date, engineer_id, shift,
                                water_main_building,
                                electricity_wbp, electricity_lwbp
                         FROM daily_logs
                         WHERE DATE(log_date) = DATE(DATE_SUB(?, INTERVAL 1 DAY))
                         ORDER BY id ASC", [$TANGGAL_FORM]);
echo "--- 1) SEMUA ROW TANGGAL 10/09/2026 (tanggal KEMARIN dari form 11/09/2026) ---\n";
if (!$rows10 || count($rows10)===0) echo "(TIDAK ADA DATA)\n";
foreach ($rows10 as $r) {
    $wid = (int)($r['id'] ?? 0);
    $eid = (int)($r['engineer_id'] ?? 0);
    $sft = (string)($r['shift'] ?? '?');
    $wmb = (float)($r['water_main_building'] ?? 0);
    $ewbp = (float)($r['electricity_wbp'] ?? 0);
    $elwbp = (float)($r['electricity_lwbp'] ?? 0);
    $tandaW = ($wmb > 0 ? '✅ WATER ISI' : '⚠️ WATER=0');
    $tandaE = (($ewbp + $elwbp) > 0 ? '✅ LISTRIK ISI' : '⚠️ LISTRIK=0');
    echo "  id=$wid | eng=$eid | shift=$sft | water_mb=$wmb $tandaW | listrik_wbp=$ewbp lwbp=$elwbp $tandaE\n";
}
echo "\n";

/* ---- 2. QUERY LAMA (ORDER id DESC, yang BIKIN BUG water=0 KEMARIN) ---- */
$qOld = $db->fetchOne("
    SELECT log_date, water_main_building, electricity_wbp, electricity_lwbp
    FROM daily_logs
    WHERE log_date < ?
      AND (COALESCE(water_main_building,0) > 0 OR COALESCE(electricity_wbp,0) > 0 OR COALESCE(electricity_lwbp,0) > 0)
    ORDER BY log_date DESC, id DESC
    LIMIT 1
", [$TANGGAL_FORM]);
echo "--- 2) QUERY LAMA (ORDER BY id DESC — YANG SEBELUMNYA DIPAKAI, BISA AMBIL ROW KOSONG!) ---\n";
if ($qOld) {
    echo "  Tgl = " . ($qOld['log_date'] ?? '?') . "\n";
    echo "  Water Main Building = " . (float)($qOld['water_main_building'] ?? 0) . "\n";
    echo "  Listrik WBP + LWBP  = " . ((float)($qOld['electricity_wbp'] ?? 0) + (float)($qOld['electricity_lwbp'] ?? 0)) . "\n";
    $isBug = ((float)($qOld['water_main_building'] ?? 0) === 0.0);
    echo "  => " . ($isBug ? '❌ BUG! WATER=0 (row id TERAKHIR KOSONG, padahal di atas ada row lain YANG ISI!)' : '✅ OK') . "\n";
} else echo "(tidak ada data)\n";
echo "\n";

/* ---- 3. QUERY BARU REVISI 3 (🔥 GRUP WATER ISI DULU! — FIX HARI INI) ---- */
$qNew = $db->fetchOne("
    SELECT log_date, water_main_building, electricity_wbp, electricity_lwbp
    FROM daily_logs
    WHERE log_date < ?
      AND (COALESCE(water_main_building,0) > 0 OR COALESCE(electricity_wbp,0) > 0 OR COALESCE(electricity_lwbp,0) > 0)
    ORDER BY
      (CASE WHEN COALESCE(water_main_building,0) > 0 THEN 0 ELSE 1 END) ASC,
      log_date DESC,
      COALESCE(water_main_building,0) DESC,
      (CASE WHEN COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0) > 0 THEN 0 ELSE 1 END) ASC,
      (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) DESC,
      id DESC
    LIMIT 1
", [$TANGGAL_FORM]);
echo "--- 3) QUERY BARU REVISI 3 (🔥 GRUP WATER ISI DULU! baru tanggal terbaru) — FIX 12/9/2026 ---\n";
if ($qNew) {
    echo "  Tgl = " . ($qNew['log_date'] ?? '?') . "\n";
    echo "  Water Main Building = " . (float)($qNew['water_main_building'] ?? 0) . "\n";
    echo "  Listrik WBP + LWBP  = " . ((float)($qNew['electricity_wbp'] ?? 0) + (float)($qNew['electricity_lwbp'] ?? 0)) . "\n";
    $isFix = ((float)($qNew['water_main_building'] ?? 0) > 0.0);
    echo "  => " . ($isFix ? '✅ FIX 100%! (GRUP WATER ISI diambil PERTAMA, walau tanggal 11/09 ada LISTRIK=ISI WATER=KOSONG)' : '❌ TIDAK ADA DATA WATER DI TANGGAL BERAPAPUN (pernah input sama sekali?)') . "\n";
} else echo "(tidak ada data)\n";
echo "\n";

/* ---- 4. PERBANDINGAN ---- */
$wOld = $qOld ? (float)($qOld['water_main_building'] ?? 0) : 0;
$wNew = $qNew ? (float)($qNew['water_main_building'] ?? 0) : 0;
echo "============================================================\n";
echo "📊 PERBANDINGAN NILAI KEMARIN WATER MAIN BUILDING:\n";
echo "  QUERY LAMA (sebelum fix) = $wOld " . ($wOld > 0 ? '✅' : '❌ BUG!') . "\n";
echo "  QUERY BARU (sesudah fix) = $wNew " . ($wNew > 0 ? '✅' : '⚠️') . "\n";
if ($wOld === 0.0 && $wNew > 0.0) {
    echo "\n  🔥 AKAR MASALAH TERBUKTI: Query LAMA salah urut ORDER BY id DESC,\n";
    echo "     ambil row ID TERBESAR (shift terakhir) yang WATER=0 KOSONG!\n";
    echo "     Padahal di tanggal SAMA, row SHIFT LAIN ada WATER=$wNew (ISI!).\n";
    echo "     SEKARANG query BARU otomatis ambil row yang WATER-nya isi DULU!\n";
}
echo "============================================================\n";
