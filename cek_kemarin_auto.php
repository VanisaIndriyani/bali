<?php
/**
 * 🔍 AUTO CHECK: Data "Hari Ini" Yang Disimpan Kemarin → Jadi "Kemarin" Hari ini?
 * (TANPA LOGIN, TANPA PARAMETER. Ambil tanggal hari ini otomatis.)
 *
 * Cara pakai:
 * 1. Upload ke hosting
 * 2. Buka: https://registengineering.com/cek_kemarin_auto.php
 * 3. Bandingkan nilai "QUERY BARU (REVISI 3) → Water Main Building" dengan nilai di form Daily Log bagian "Kemarin".
 *    → JIKA SAMA = fixnya jalan! (kalau beda berarti file daily_log_form.php BELUM diupload / cache browser.)
 */
$scriptDir = __DIR__;
$configPath = $scriptDir . '/config/config.php';
if (!file_exists($configPath)) { $configPath = $scriptDir . '/../config/config.php'; }
require_once $configPath;
if (!class_exists('Database', false)) { die('Class Database tidak ditemukan! Cek config/config.php path benar?'); }
$db = Database::getInstance();
if (!$db) { die('Gagal connect DB! Cek username/password database di config/config.php.'); }

header('Content-Type: text/plain; charset=utf-8');

// ---------- Ambil TANGGAL HARI INI otomatis (sesuai timezone Asia/Makassar = UTC+8) ----------
date_default_timezone_set('Asia/Makassar');
$todayObj = new DateTime();
$today = $todayObj->format('Y-m-d');
$yesterdayObj = (clone $todayObj)->sub(new DateInterval('P1D'));
$yesterday = $yesterdayObj->format('Y-m-d');

echo "============================================================\n";
echo "🔍  AUTO CHECK DATA KEMARIN MAIN BUILDING (TANPA LOGIN)\n";
echo "============================================================\n";
echo "  Hari ini      (form baru) = $today\n";
echo "  Tanggal kemarin (expected)= $yesterday\n\n";

// ---------- 1) Lihat 7 HARI TERAKHIR (apakah pernah ada submit WATER MAIN BUILDING?) ----------
echo "--- 1) SEMUA DATA 7 HARI TERAKHIR (pernah SUBMIT WATER MAIN BUILDING > 0?) ---\n";
$rows7 = $db->fetchAll("
    SELECT id, DATE(log_date) as tgl, engineer_id, shift,
           water_main_building, electricity_wbp, electricity_lwbp
    FROM daily_logs
    WHERE DATE(log_date) >= DATE_SUB(DATE(?), INTERVAL 7 DAY)
      AND DATE(log_date) < DATE(?)
    ORDER BY log_date DESC, id DESC
", [$today, $today]);
if (!$rows7 || count($rows7)===0) {
    echo "  ❌ TIDAK ADA DATA SAMA SEKALI di 7 hari terakhir!\n";
    echo "     => Artinya: BELUM PERNAH ada user yang SUBMIT Daily Log (minimal 1x submit).\n";
    echo "        Solusi: Submit dulu 1x Daily Log hari ini, besoknya KEMARIN baru otomatis terisi!\n";
} else {
    $adaWaterIsi = false;
    foreach ($rows7 as $r) {
        $wid = (int)($r['id'] ?? 0);
        $tgl = (string)($r['tgl'] ?? '?');
        $sft = (string)($r['shift'] ?? '?');
        $wmb = (float)($r['water_main_building'] ?? 0);
        $ewbp = (float)($r['electricity_wbp'] ?? 0);
        $elwbp = (float)($r['electricity_lwbp'] ?? 0);
        $tandaW = ($wmb > 0 ? '✅ WATER ISI' : '  ');
        if ($wmb > 0) $adaWaterIsi = true;
        $tandaE = (($ewbp + $elwbp) > 0 ? '⚡LISTRIK' : '  ');
        echo "  id=$wid | tgl=$tgl | shift=$sft | water_mb=$wmb $tandaW $tandaE\n";
    }
    if (!$adaWaterIsi) {
        echo "\n  ⚠️ ADA DATA TAPI TIDAK ADA SATU PUN WATER MAIN BUILDING > 0!\n";
        echo "     Artinya: User cuma submit listrik/gas/fuel, TIDAK PERNAH isi WATER MAIN BUILDING.\n";
        echo "     Solusi: Isi kolom 'Main Building (Meter) Hari Ini' di tanggal manapun, submit.\n";
        echo "              Besoknya nilai Kemarin baru otomatis muncul!\n";
    }
}
echo "\n";

// ---------- 2) QUERY LAMA (ORDER tanggal + id DESC) ----------
$qOld = $db->fetchOne("
    SELECT DATE(log_date) tgl, water_main_building, electricity_wbp, electricity_lwbp
    FROM daily_logs
    WHERE log_date < ?
      AND (COALESCE(water_main_building,0) > 0 OR COALESCE(electricity_wbp,0) > 0 OR COALESCE(electricity_lwbp,0) > 0)
    ORDER BY log_date DESC, id DESC
    LIMIT 1
", [$today]);
echo "--- 2) QUERY LAMA (ORDER ID DESC — sebelum perbaikan) ---\n";
if ($qOld) {
    echo "  Tgl ambil = " . ($qOld['tgl'] ?? '?') . "\n";
    echo "  Water MB  = " . (float)($qOld['water_main_building'] ?? 0) . "\n";
    if ((float)($qOld['water_main_building'] ?? 0) === 0.0) echo "  ❌ WATER=0 (row LISTRIK ISI, tapi WATER KOSONG diambil duluan = BUG!)\n";
    else echo "  ✅ OK\n";
} else echo "  (tidak ada data)\n";
echo "\n";

// ---------- 3) QUERY BARU (REVISI 3 — GRUP WATER ISI DULU!) ----------
$qNew = $db->fetchOne("
    SELECT DATE(log_date) tgl, water_main_building, electricity_wbp, electricity_lwbp
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
", [$today]);
$wmbFinal = 0;
echo "--- 3) QUERY BARU (REVISI 3 — 🔥 FIX YANG DIPAKAI SEKARANG!) ---\n";
if ($qNew) {
    echo "  Tgl ambil = " . ($qNew['tgl'] ?? '?') . "\n";
    $wmbFinal = (float)($qNew['water_main_building'] ?? 0);
    echo "  Water MB  = $wmbFinal\n";
    if ($wmbFinal > 0) echo "  ✅ FIX BERHASIL! Nilai ini SEHARUSNYA MUNCUL di form Daily Log bagian 'Kemarin'.\n";
    else echo "  ⚠️ WATER=0 (lihat kesimpulan di bawah!)\n";
} else echo "  (tidak ada data)\n";
echo "\n";

// ---------- 4) KESIMPULAN ----------
echo "============================================================\n";
echo "📋 KESIMPULAN & CARA MEMPERBAIKI JIKA KEMARIN MASIH KOSONG:\n";
echo "============================================================\n";
if ($wmbFinal > 0) {
    echo "  ✅ DB SUDAH PUNYA DATA WATER MAIN BUILDING = $wmbFinal\n";
    echo "     (diambil dari tanggal " . ($qNew['tgl'] ?? '?') . ")\n";
    echo "  🎯 JIKA di form Daily Log tulisan 'Kemarin' masih 0 / kosong:\n";
    echo "     1. Upload file `engineer/daily_log_form.php` TERBARU (Revisi 3) ke hosting!\n";
    echo "     2. Refresh form dengan Ctrl + Shift + R (bypass cache browser / Ctrl F5)\n";
    echo "     3. Kalau masih 0 → coba ganti shift jadi MALAM / PAGI / SIANG, refresh lagi.\n";
} else {
    echo "  ❌ DB TIDAK PUNYA DATA WATER MAIN BUILDING SAMA SEKALI (atau cuma 0).\n";
    echo "     Step untuk MUNCULKAN AUTO KEMARIN:\n";
    echo "     1. Buka Daily Log Form → pilih tanggal HARI INI ($today)\n";
    echo "     2. Isi kolom 'Main Building (Meter) Hari Ini' → misal 75157,30\n";
    echo "     3. Isi juga Listrik (WBP / LWBP) jika perlu → KLIK SUBMIT ✅\n";
    echo "     4. Buka form baru besok ($yesterday) → bagian 'Kemarin' OTOMATIS = 75157,30!\n";
    echo "        (TIDAK PERLU input lagi manual, auto ambil dari submit hari ini.)\n";
}
echo "\n  Link form: https://registengineering.com/engineer/daily_log_form.php\n";
