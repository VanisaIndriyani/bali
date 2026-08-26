<?php
require_once __DIR__ . '/config/config.php';
$pdo = Database::getInstance()->getConnection();

echo "<h1 style='font-family:sans-serif;color:#0f172a'>🔍 CEK DATA LAST YEAR (LY) — SCRIPT DIAGNOSA LENGKAP v2</h1>";
echo "<div style='font-family:sans-serif;color:#475569;font-size:14px'>
        🚩 <b>CARA PAKAI ALUR INI (3 STEP):</b><br>
        <b>STEP 1 →</b> Lihat panel di bawah: apakah target tanggal 16,19,21 Agu 2025 punya <span style='color:#16a34a'>✅ status=APPROVED</span> & Total Listrik benar?<br>
        <b>STEP 2 →</b> Gulir ke Panel SIMULASI LY — bandingkan <code>LY hasil simulasi</code> dengan ekspektasi (26240 / 26880 / 26960).<br>
        <b>STEP 3 →</b> Jika salah / nol, lihat rekomendasi aksi perbaikan di panel terakhir.<br>
      </div>";
echo "<hr style='border:#cbd5e1'><br>";

/* =========================================================
   🔧 AUTO DETECT KOLOM daily_logs (hosting & local bisa beda!)
   ========================================================= */
$colsExist = [];
try {
    $qD = $pdo->query("DESCRIBE daily_logs");
    foreach ($qD->fetchAll() as $row) $colsExist[strtolower($row['Field'])] = $row['Field'];
} catch (Throwable $e) {
    die("<b>ERROR:</b> Gagal baca struktur tabel daily_logs. Pesan: " . htmlspecialchars($e->getMessage()));
}

function colIf($name, $alias = null, $colsExist) {
    $n = strtolower($name);
    if (!isset($colsExist[$n])) return "NULL";
    $real = $colsExist[$n];
    return $alias ? "$real AS $alias" : $real;
}

$usersTblExists = false;
try {
    $pdo->query("SELECT 1 FROM users LIMIT 1")->fetch();
    $usersTblExists = true;
} catch (Throwable $e) { $usersTblExists = false; }

$statusExists = isset($colsExist['status']);

/* Build safe kolom list */
$colId       = colIf('id', 'id', $colsExist);
$colLogDate  = colIf('log_date', 'log_date', $colsExist);
$colShift    = colIf('shift', 'shift', $colsExist);
$colEngId    = colIf('engineer_id', 'engineer_id', $colsExist);
$colStatus   = $statusExists ? "dl.status AS row_status" : "'(no-status-col)' AS row_status";
$colWbp      = colIf('electricity_wbp', 'electricity_wbp', $colsExist);
$colLwbp     = colIf('electricity_lwbp', 'electricity_lwbp', $colsExist);
$colTotElec  = colIf('total_electricity', 'total_electricity', $colsExist);
$colWmb      = colIf('water_main_building', 'water_main_building', $colsExist);
$colTotWater = colIf('total_water', 'total_water', $colsExist);
$colGasLpg   = colIf('gas_lpg', 'gas_lpg_kg', $colsExist);
if ($colGasLpg === 'NULL') $colGasLpg = colIf('gas_lpg_kg', 'gas_lpg_kg', $colsExist);
$colGasLng   = colIf('gas_lng', 'gas_lng_kg', $colsExist);
if ($colGasLng === 'NULL') $colGasLng = colIf('gas_lng_kg', 'gas_lng_kg', $colsExist);
$colTotGas   = colIf('total_gas', 'total_gas', $colsExist);
$colFuelL    = colIf('fuel_liter_used', 'fuel_liter_used', $colsExist);
if ($colFuelL === 'NULL') $colFuelL = colIf('konsumsi_fuel_liter', 'fuel_liter_used', $colsExist);
if ($colFuelL === 'NULL') $colFuelL = colIf('fuel_liter', 'fuel_liter_used', $colsExist);
$colTotFuel  = colIf('total_fuel', 'total_fuel', $colsExist);

$selectCols = "
    $colId, $colLogDate, $colShift, $colEngId, $colStatus,
    $colWbp, $colLwbp, $colTotElec,
    $colWmb, $colTotWater,
    $colGasLpg, $colGasLng, $colTotGas,
    $colFuelL, $colTotFuel
";

$fromSql = " FROM daily_logs dl ";
if ($usersTblExists && $colEngId !== 'NULL') {
    $fromSql .= " LEFT JOIN users u ON u.id = dl.engineer_id ";
    $selectCols .= ", u.name AS engineer_name_display ";
}

$targets = [
    ['label'=>'16/08/2025 (LY 16 Agu 2026)',    'ymd'=>'2025-08-16', 'expect'=>26240, 'today_ymd'=>'2026-08-16'],
    ['label'=>'19/08/2025 (LY 19 Agu 2026)',    'ymd'=>'2025-08-19', 'expect'=>26880, 'today_ymd'=>'2026-08-19'],
    ['label'=>'21/08/2025 (LY 21 Agu 2026)',    'ymd'=>'2025-08-21', 'expect'=>26960, 'today_ymd'=>'2026-08-21'],
    ['label'=>'24/08/2025 (LY 24 Agu 2026)',    'ymd'=>'2025-08-24', 'expect'=>0,     'today_ymd'=>'2026-08-24'],
];

/* Utility */
function fmtVal($r, $k, $suffix = '', $dec = 2) {
    $v = isset($r[$k]) ? (float)$r[$k] : 0;
    $num = number_format($v, $dec, ',', '.');
    return $num . $suffix;
}
function safeName($r) {
    if (!empty($r['engineer_name_display'])) return htmlspecialchars($r['engineer_name_display']);
    if (!empty($r['engineer_id'])) return 'ID: ' . (int)$r['engineer_id'];
    return '<i>n/a</i>';
}
function statusBadge($s) {
    $s = strtolower(trim((string)$s));
    if ($s === 'approved') return "<span style='padding:3px 8px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:600'>APPROVED ✅</span>";
    if ($s === 'pending')  return "<span style='padding:3px 8px;border-radius:999px;background:#fef3c7;color:#92400e;font-weight:600'>PENDING ⏳</span>";
    if ($s === 'rejected') return "<span style='padding:3px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;font-weight:600'>REJECTED ❌</span>";
    return "<span style='padding:3px 8px;border-radius:999px;background:#f1f5f9;color:#334155'>".htmlspecialchars($s)."</span>";
}

/* =========================================================
   STEP 1 — TAMPILKAN RAW DATA SETIAP TANGGAL (dengan STATUS)
   ========================================================= */
$lySimulationResults = [];
$approveTodo = [];
$zeroTodo = [];
$missingTodo = [];

foreach ($targets as $T) {
    $label = $T['label']; $dateYmd = $T['ymd']; $expect = $T['expect']; $todayYmd = $T['today_ymd'];
    echo "<h2 style='font-family:sans-serif;color:#334155'>📅 $label (<code>$dateYmd</code>)";
    if ($expect > 0) echo " <small style='color:#64748b'>— Ekspektasi Total Listrik: <b style='color:#0ea5e9'>".number_format($expect,0,',','.')." kWh</b></small>";
    echo "</h2>";

    try {
        $stmt = $pdo->prepare("SELECT $selectCols $fromSql WHERE DATE(dl.log_date) = ? ORDER BY dl.log_date DESC, dl.id DESC");
        $stmt->execute([$dateYmd]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        echo "<div style='padding:16px;background:#fff1f2;border:1px solid #fecdd3;border-radius:12px;color:#9f1239;font-family:sans-serif'>
                ❌ <b>ERROR SQL:</b> " . htmlspecialchars($e->getMessage()) . "
              </div><br>";
        continue;
    }

    if (!$rows) {
        $missingTodo[] = "📛 <b>$label</b>: TIDAK ADA DATA SAMA SEKALI. Perlu input manual di form Daily Log → tanggal <code>$dateYmd</code>.";
        echo "<div style='padding:18px 22px;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;color:#991b1b;font-family:sans-serif'>
                ❌ <b>TIDAK ADA DATA di tabel daily_logs untuk tanggal ini!</b><br>
                <i>Solusi: Buka daily_log_form.php → ganti tanggal jadi <b>$dateYmd</b> → isi WBP/LWBP → Submit → lalu SUPER VISOR APPROVE.</i>
              </div><br>";
        $lySimulationResults[] = array_merge($T, ['ly_elec'=>0,'cnt'=>0,'cnt_d'=>0,'cnt_e'=>0,'ok'=>false,'reason'=>'missing_rows']);
        continue;
    }

    /* Hitung summary sederhana */
    $totApproved = 0; $totPending = 0; $sumApproved = 0; $sumAll = 0; $maxElecApproved = 0;
    foreach ($rows as $r) {
        $s = strtolower(trim((string)($r['row_status'] ?? 'n/a')));
        $te = (float)($r['total_electricity'] ?? 0);
        $sumAll += $te;
        if ($s === 'approved') { $totApproved++; $sumApproved += $te; if ($te > $maxElecApproved) $maxElecApproved = $te; }
        else if ($s === 'pending') $totPending++;
    }

    $lySim = 0; $reason = ''; $ok = false;
    if ($totApproved === 0) {
        $approveTodo[] = "⏳ <b>$label</b>: $totPending row PENDING. Supervisor HARUS klik Approve! (nilai saat ini belum masuk LY)";
        $reason = "semua $totPending row masih status PENDING";
        $lySim = 0;
    } else if ($expect > 0 && abs($sumApproved - $expect) > 20) {
        $zeroTodo[] = "⚠️ <b>$label</b>: Approved total = <b>".number_format($sumApproved,2,',','.')."</b> ≠ ekspektasi ".number_format($expect,0,',','.')." kWh. Kemungkinan kolom total_electricity = 0 (Yesterday override lupa di-save / submit salah tahun 2026).";
        $reason = "sumApproved ($sumApproved) ≠ expect ($expect). Buka data ini di phpMyAdmin untuk koreksi nilai total_electricity, water, gas, fuel.";
        $lySim = $sumApproved;
    } else {
        $ok = true;
        $reason = "✅ COCOK — sum approved = ".number_format($sumApproved,2,',','.');
        $lySim = $sumApproved;
    }
    $lySimulationResults[] = array_merge($T, ['ly_elec'=>$sumApproved,'cnt'=>count($rows),'cnt_d'=>$totApproved,'cnt_pending'=>$totPending,'ok'=>$ok,'reason'=>$reason]);

    echo "<div style='padding:18px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:12px;color:#0f172a;font-family:sans-serif;margin-bottom:8px'>
            📊 Summary: <b>" . count($rows) . " baris</b> ·  " .
           ($totApproved > 0 ? "<span style='color:#166534'>✅ Approved $totApproved</span>" : "<span style='color:#92400e'>⏳ Approved 0</span>") . " · " .
           ($totPending  > 0 ? "<span style='color:#b45309'>⏳ Pending $totPending</span>" : "<span style='color:#64748b'>Pending 0</span>") .
           " · ⚡ <b>Σ Total Listrik (approved only):</b> <b style='color:#1d4ed8'>" . number_format($sumApproved,2,',','.') . " kWh</b>" .
           ($expect>0 ? " · Target: <b style='color:#0ea5e9'>".number_format($expect,0,',','.')."</b>" : "") .
           " <br>👉 <i>LY ke halaman <code>$todayYmd</code> → ambil dari <b>Σ Approved</b> ini.</i>
          </div>";

    if (!$ok) {
        echo "<div style='padding:10px 14px;background:#fef9c3;border:1px solid #facc15;border-radius:10px;color:#713f12;font-family:sans-serif;font-size:13px;margin-bottom:8px'>
                ⚠️ <b>DIAGNOSA:</b> $reason
              </div>";
    }

    echo "<div style='overflow:auto;margin-top:12px'>";
    echo "<table border='1' cellpadding='10' cellspacing='0' style='border-collapse:collapse;font-family:sans-serif;font-size:13px;background:#fff'>";
    echo "<thead style='background:#f1f5f9'>";
    echo "<tr>
            <th>#</th><th>id</th><th>log_date</th><th>shift</th><th>STATUS</th><th>Engineer (nama)</th>
            <th>⚡ WBP<br><small>electricity_wbp</small></th>
            <th>🌙 LWBP<br><small>electricity_lwbp</small></th>
            <th>Total Listrik<br><small>total_electricity</small></th>
            <th>💧 MB m3<br><small>water_main_building</small></th>
            <th>Total Air<br><small>total_water</small></th>
            <th>🔥 Gas LPG</th><th>Gas LNG</th><th>Total Gas</th>
            <th>⛽ Fuel L<br><small>fuel_liter_used</small></th><th>Total Fuel</th>
          </tr>";
    echo "</thead><tbody>";
    $no = 1;
    foreach ($rows as $r) {
        echo "<tr>";
        echo "<td>$no</td>";
        echo "<td>" . htmlspecialchars($r['id'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($r['log_date'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($r['shift'] ?? '') . "</td>";
        echo "<td style='background:#f8fafc'>" . statusBadge($r['row_status'] ?? 'n/a') . "</td>";
        echo "<td style='background:#f8fafc'>" . safeName($r) . "</td>";
        echo "<td align='right' style='background:#eff6ff'><b>" . fmtVal($r, 'electricity_wbp') . "</b></td>";
        echo "<td align='right' style='background:#eff6ff'><b>" . fmtVal($r, 'electricity_lwbp') . "</b></td>";
        echo "<td align='right' style='color:#1d4ed8'><b>" . fmtVal($r, 'total_electricity') . "</b></td>";
        echo "<td align='right'>" . fmtVal($r, 'water_main_building') . "</td>";
        echo "<td align='right' style='color:#0e7490'><b>" . fmtVal($r, 'total_water') . "</b></td>";
        echo "<td align='right'>" . fmtVal($r, 'gas_lpg_kg') . "</td>";
        echo "<td align='right'>" . fmtVal($r, 'gas_lng_kg') . "</td>";
        echo "<td align='right' style='color:#c2410c'><b>" . fmtVal($r, 'total_gas') . "</b></td>";
        echo "<td align='right'>" . fmtVal($r, 'fuel_liter_used') . "</td>";
        echo "<td align='right' style='color:#9f1239'><b>" . fmtVal($r, 'total_fuel') . "</b></td>";
        echo "</tr>";
        $no++;
    }
    echo "</tbody></table></div>";
    echo "<br><hr style='border:#e2e8f0'><br>";
}

/* =========================================================
   STEP 2 — PANEL RINGKASAN SIMULASI LY
   ========================================================= */
echo "<h2 style='font-family:sans-serif;color:#1e293b'>🧪 SIMULASI LY — APA YANG AKAN DITAMPILKAN DI DASHBOARD</h2>";
echo "<table border='1' cellpadding='10' cellspacing='0' style='border-collapse:collapse;font-family:sans-serif;font-size:14px;background:#fff;margin-top:14px;width:100%'>";
echo "<thead style='background:#1e293b;color:#f1f5f9'>";
echo "<tr>
        <th>Label</th><th>Tahun Lalu<br><small>(daily_logs 2025)</small></th>
        <th>Ekspektasi LY<br>(Total Listrik)</th>
        <th>✅ Approved<br>Rows</th><th>⏳ Pending<br>Rows</th>
        <th>⚡ Simulasi LY Listrik<br><small>(sum total_electricity approved)</small></th>
        <th>Hasil</th><th>DIAGNOSA AKAR MASALAH</th>
      </tr></thead><tbody>";
$numFailed = 0;
foreach ($lySimulationResults as $S) {
    $expect = $S['expect'];
    $lySim  = (float)$S['ly_elec'];
    $diff = $expect > 0 ? abs($lySim - $expect) : 0;
    $pass = $S['ok'];
    if (!$pass) $numFailed++;
    $colorPass = $pass ? '#dcfce7' : '#fee2e2';
    $colorTxt  = $pass ? '#166534' : '#991b1b';
    $iconPass  = $pass ? '✅' : '❌';
    $valFmt = $lySim > 0 ? number_format($lySim,0,',','.').' kWh' : '0 (tidak muncul / badge amber)';
    $expFmt = $expect > 0 ? number_format($expect,0,',','.').' kWh' : '—';
    echo "<tr>";
    echo "<td style='background:#f8fafc'><b>".htmlspecialchars($S['label'])."</b></td>";
    echo "<td><code>".$S['ymd']."</code></td>";
    echo "<td align='right'>$expFmt</td>";
    echo "<td align='center'>".(int)($S['cnt_d'])."</td>";
    echo "<td align='center'>".(int)($S['cnt_pending'])."</td>";
    echo "<td align='right' style='background:$colorPass;color:$colorTxt;font-weight:700'>$valFmt</td>";
    echo "<td align='center' style='background:$colorPass;color:$colorTxt'>$iconPass</td>";
    echo "<td style='font-size:12px;color:#334155'>".htmlspecialchars($S['reason'])."</td>";
    echo "</tr>";
}
echo "</tbody></table>";

/* =========================================================
   STEP 3 — RINGKASAN REKOMENDASI ACTION
   ========================================================= */
echo "<br><h2 style='font-family:sans-serif;color:#0f172a'>🎯 ACTION PLAN PERBAIKAN (URUTKAN PRIORITAS)</h2>";
if ($numFailed === 0 && !$approveTodo && !$zeroTodo && !$missingTodo) {
    echo "<div style='padding:20px;background:#dcfce7;border:1px solid #86efac;border-radius:14px;color:#14532d;font-family:sans-serif'>
            🎉 SEMUA DATA BERHASIL! LY tanggal 16,19,21 Agu SUDAH BENAR. Silakan refresh Dashboard di date 16/08/2026 & 21/08/2026, Last Year pasti muncul sesuai ekspektasi.
          </div>";
} else {
    if ($missingTodo) {
        echo "<h3 style='font-family:sans-serif;color:#991b1b'>🟥 PRIORITAS 1 — TIDAK ADA DATA SAMA SEKALI (HARUS INPUT ULANG)</h3>";
        echo "<div style='padding:16px;background:#fff7ed;border:1px solid #fdba74;border-radius:12px;font-family:sans-serif'>";
        foreach ($missingTodo as $m) echo "<div style='padding:6px 0'>$m</div>";
        echo "</div>";
    }
    if ($approveTodo) {
        echo "<h3 style='font-family:sans-serif;color:#92400e'>🟧 PRIORITAS 2 — MASIH PENDING (SUPERVISOR KLIK APPROVE SEKARANG)</h3>";
        echo "<div style='padding:16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:12px;font-family:sans-serif'>";
        foreach ($approveTodo as $m) echo "<div style='padding:6px 0'>$m</div>";
        echo "<div style='margin-top:8px;padding-top:8px;border-top:1px dashed #fbbf24;font-size:13px;color:#78350f'>
                <b>Cara Approve:</b> Login sebagai <b>Supervisor</b> → Menu <b>Review & Approve</b> → Pilih tanggal → tombol <b>✅ Approve</b>.
                <br>Atau SQL cepat (jika tahu id row): <code>UPDATE daily_logs SET status='approved' WHERE id = XXX LIMIT 1;</code>
              </div>";
        echo "</div>";
    }
    if ($zeroTodo) {
        echo "<h3 style='font-family:sans-serif;color:#92400e'>🟧 PRIORITAS 3 — ADA DATA TAPI NILAI total_electricity KECIL / 0 (BISA JADI KESALAHAN INPUT)</h3>";
        echo "<div style='padding:16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:12px;font-family:sans-serif'>";
        foreach ($zeroTodo as $m) echo "<div style='padding:6px 0'>$m</div>";
        echo "<div style='margin-top:8px;padding-top:8px;border-top:1px dashed #fbbf24;font-size:13px;color:#78350f'>
                <b>2 Kemungkinan penyebab:</b><br>
                <b>(A) Tahun Salah:</b> Engineer buka form tapi tanggalnya <code>2026-08-16</code> (bukan 2025) → data nyangkut ke tahun ini (bukan tahun lalu). Cek data 2026-08-16 jika ada total_electricity 26240 berarti ini kasusnya → edit kolom <code>log_date</code> via phpMyAdmin jadi 2025.<br>
                <b>(B) Yesterday override tidak tersimpan ke kolom total:</b> Form di JS menghitung 26960 tapi kolom <code>total_electricity</code> di DB nol → perlu buka row di phpMyAdmin dan set manual: <code>UPDATE daily_logs SET total_electricity = 26960, total_water = X, total_gas = Y WHERE id = XXX LIMIT 1;</code>
              </div>";
        echo "</div>";
    }
}

echo "<br><div style='padding:16px;background:#f8fafc;border:1px dashed #94a3b8;border-radius:10px;font-family:sans-serif;font-size:13px;color:#334155'>
        🔖 <b>TIPS LANJUTAN:</b> Jika STEP 2 simulasi SUDAH COCOK tapi Dashboard MASIH TIDAK MUNCUL → pastikan di hosting kode <b>SUDAH di-pull dari commit a43f3c0 (step D fix)</b>, lalu coba <b>Hard Refresh (Ctrl+Shift+R)</b>.
      </div>";
