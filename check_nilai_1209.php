<?php
/**
 * DEBUG MENDESAK: CEK NILAI ASLI WBP / LWBP / WATER MB
 * Tanggal: 10, 11, 12 September 2026 (ENGINEER #1)
 * DIGUNAKAN UNTUK MEMPERBAIKI RECALC v11 (listrik masih 3558 bukan 27840!)
 *
 * User: Upload file ini ke hosting → browse https://registengineering.com/check_nilai_1209.php
 *       → COPY SEMUA OUTPUT table HTML nya, PASTE ke WA / chat!
 *
 * (file ini TIDAK BOLEH diakses orang lain, hapus setelah selesai!)
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/Database.php';

$db = Database::getInstance();

echo "<h2 style='color:red'>🔥 DEBUG NILAI ASLI WBP / LWBP / WATER MB (ENG#1)</h2>";
echo "<h3>Tanggal: 10-12 September 2026</h3>";
echo "<b>NOTE: Isilah tabel ini jika nilainya berbeda, atau langsung screenshot halaman ini ya kak!</b><br><br>";

$sql = "SELECT id, engineer_id, DATE(log_date) AS tanggal,
               TIME(log_date) AS jam,
               shift,
               COALESCE(electricity_wbp,0) AS wbp,
               COALESCE(electricity_lwbp,0) AS lwbp,
               (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) AS total_meter,
               COALESCE(water_main_building,0) AS mb,
               COALESCE(total_electricity,0) AS total_elec_saved,
               COALESCE(total_water,0) AS total_water_saved,
               status
        FROM daily_logs
        WHERE engineer_id = 1
          AND log_date BETWEEN '2026-09-10 00:00:00' AND '2026-09-13 23:59:59'
        ORDER BY log_date ASC";

$rows = $db->fetchAll($sql);

if (!$rows) {
    echo "<p style='color:red'>❌ TIDAK ADA DATA daily_logs eng#1 10-13/09/2026!</p>";
    exit;
}

echo "<table border='1' cellpadding='6' cellspacing='0' style='font-family:monospace;font-size:14px;'>
<tr style='background:#ffe0e0;font-weight:bold'>
  <td>#</td><td>ID</td><td>TANGGAL</td><td>JAM</td><td>SHIFT</td>
  <td>WBP (kWh)</td><td>LWBP (kWh)</td><td>TOTAL METER WBP+LWBP</td>
  <td>WATER MB (m3)</td>
  <td>TOTAL ELEC DISIMPAN DB</td>
  <td>TOTAL WATER DISIMPAN DB</td>
  <td>STATUS</td>
</tr>";
$n = 0;
foreach ($rows as $r) {
    $n++;
    $bg = '';
    if ((float)$r['total_meter'] > 0 && (float)$r['mb'] > 0) $bg = 'background:#c8f7c5';
    if ((float)$r['total_elec_saved'] == 3558.40) $bg = 'background:#fffacd'; /* target 12/09 bug */
    echo "<tr style='$bg'>
      <td>$n</td>
      <td>{$r['id']}</td>
      <td><b>{$r['tanggal']}</b></td>
      <td>{$r['jam']}</td>
      <td>{$r['shift']}</td>
      <td style='text-align:right'>" . number_format((float)$r['wbp'], 2, ',', '.') . "</td>
      <td style='text-align:right'>" . number_format((float)$r['lwbp'], 2, ',', '.') . "</td>
      <td style='text-align:right;color:blue'><b>" . number_format((float)$r['total_meter'], 2, ',', '.') . "</b></td>
      <td style='text-align:right'>" . number_format((float)$r['mb'], 2, ',', '.') . "</td>
      <td style='text-align:right;color:darkred'><b>" . number_format((float)$r['total_elec_saved'], 2, ',', '.') . "</b></td>
      <td style='text-align:right'>" . number_format((float)$r['total_water_saved'], 2, ',', '.') . "</td>
      <td>{$r['status']}</td>
    </tr>";
}
echo "</table>";

/* ========================================================
   KEDUA: TAMPILKAN JUGA YANG DIPILIH recalc LAYER Revisi3
   (ORDER BY water>0 first, log_date DESC) per tanggal.
   MEMASTIKAN recalc memilih row BENAR yang WATER ISI!
   ======================================================== */

echo "<hr><h3>🎯 ROW TERPILIH RECALC (ORDER BY Revisi3: WATER ISI DULU → TERBARU)</h3>";
foreach (['2026-09-10','2026-09-11','2026-09-12'] as $t) {
    echo "<h4>Untuk tanggal: <b style='color:blue'>$t</b> (baseline kemarin dipilih row < tanggal ini!)</h4>";
    $sel = $db->fetchOne("
        SELECT id, engineer_id, DATE(log_date) AS tanggal, TIME(log_date) AS jam, shift,
               COALESCE(electricity_wbp,0) AS wbp,
               COALESCE(electricity_lwbp,0) AS lwbp,
               (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) AS total_meter,
               COALESCE(water_main_building,0) AS mb
        FROM daily_logs
        WHERE engineer_id = 1 AND log_date < ?
          AND (COALESCE(electricity_wbp,0) > 0 OR COALESCE(electricity_lwbp,0) > 0 OR COALESCE(water_main_building,0) > 0)
        ORDER BY
          (CASE WHEN COALESCE(water_main_building,0) > 0 THEN 0 ELSE 1 END) ASC,
          log_date DESC,
          COALESCE(water_main_building,0) DESC,
          (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) DESC,
          id DESC
        LIMIT 1", [$t . ' 00:00:00']);
    if (!$sel) { echo "<p style='color:gray'>❌ TIDAK ADA row baseline untuk $t</p>"; continue; }
    echo "<table border='1' cellpadding='5' cellspacing='0'><tr>
      <td>Row ID</td><td>TANGGAL BASELINE</td><td>JAM</td><td>SHIFT</td>
      <td>WBP BASELINE</td><td>LWBP BASELINE</td><td>TOTAL BASELINE</td><td>MB BASELINE</td>
    </tr>";
    echo "<tr style='background:#d4e8ff'>
      <td>{$sel['id']}</td>
      <td><b>{$sel['tanggal']}</b></td><td>{$sel['jam']}</td><td>{$sel['shift']}</td>
      <td style='text-align:right'>" . number_format((float)$sel['wbp'],2,',','.') . "</td>
      <td style='text-align:right'>" . number_format((float)$sel['lwbp'],2,',','.') . "</td>
      <td style='text-align:right'><b style='color:blue'>" . number_format((float)$sel['total_meter'],2,',','.') . "</b></td>
      <td style='text-align:right'>" . number_format((float)$sel['mb'],2,',','.') . "</td>
    </tr></table>";
    $nowTgl = date('Y-m-d', strtotime($t));
    $nRow = $db->fetchOne("SELECT COALESCE(electricity_wbp,0) wbp, COALESCE(electricity_lwbp,0) lwbp, (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) tm, COALESCE(water_main_building,0) mb FROM daily_logs WHERE engineer_id=1 AND DATE(log_date)=? ORDER BY (CASE WHEN COALESCE(water_main_building,0)>0 THEN 0 ELSE 1 END) ASC, log_date DESC LIMIT 1", [$nowTgl]);
    if ($nRow) {
        $dWbp = max(0.0, (float)$nRow['wbp'] - (float)$sel['wbp']);
        $dLwbp = max(0.0, (float)$nRow['lwbp'] - (float)$sel['lwbp']);
        $dTot = $dWbp + $dLwbp;
        $totalCons = ($dTot > 0 && $dTot <= 500) ? $dTot * 8000 : $dTot;
        echo "<p style='color:green;font-family:monospace'>
           <b>PERHITUNGAN FORMULA DAILY_LOG_FORM (SEHARUSNYA!):</b><br>
           Today WBP = " . number_format((float)$nRow['wbp'],2,',','.') . " − Yesterday WBP = " . number_format((float)$sel['wbp'],2,',','.') . " → dWbp = <b>" . number_format($dWbp,2,',','.') . "</b><br>
           Today LWBP = " . number_format((float)$nRow['lwbp'],2,',','.') . " − Yesterday LWBP = " . number_format((float)$sel['lwbp'],2,',','.') . " → dLwbp = <b>" . number_format($dLwbp,2,',','.') . "</b><br>
           TOTAL SELISIH = " . number_format($dTot,2,',','.') . " → ";
        if ($dTot <= 500) echo " ≤ 500 → × 8000 = "; else echo " > 500 → × 1 (LANGSUNG) = ";
        echo "<b style='font-size:16px;color:red'>" . number_format($totalCons,2,',','.') . " kWh</b> ✅✅✅ (INI YANG SEHARUSNYA disimpan di DB!)</p>";
    }
    echo "<hr>";
}

echo "<br><h2 style='color:red'>⚠️ INSTRUKSI:</h2>
      <ol>
        <li>SCREENSHOT HALAMAN INI SEMUA (table + perhitungan formula) ya kak!</li>
        <li>Atau COPY output table & perhitungan di atas, PASTE ke WA / chat ke saya.</li>
        <li>KITA BUTUH INI UNTUK MEMBUAT RECALC v11 yang AKHIRNYA LISTRIK 12/09 = 27840 kWh PASTI BENAR!</li>
        <li>SETELAH DIPAKAI: HAPUS file <b>check_nilai_1209.php</b> dari hosting.</li>
      </ol>";
