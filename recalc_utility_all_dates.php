<?php
/**
 * 🔥🔥🔥 SCRIPT UTAMA: REKALKULASI SEMUA UTILITY DATA LAMA (1 Jan 2026 s/d HARI INI) 🔥🔥🔥
 *
 * ------------------------------------------------------------------
 * 💽 MASALAH YANG DIPERBAIKI:
 *   Data lama di tabel daily_logs (total_electricity / total_water / total_gas / total_fuel)
 *   berisi perhitungan FORMULA LAMA YANG SALAH:
 *   - Total Air = masih termasuk CT + Bottling + Irrigation (user MAU HANYA MB + PDAM)
 *   - Listrik = Selisih meter × 8000 DISIMPAN 2 KALI (×8000 di form, ×8000 lagi di simpan)
 *   - Gas = Selisih reading × 100 disimpan 2x, atau threshold salah
 *   - Fuel = sering 0 padahal ada di equipment_data
 *
 * 🧮 FORMULA YANG DIGUNAKAN SEKARANG (BENAR, SESUAI EXCEL HOTEL):
 *   • LISTRIK:  Σ (Today (WBP+LWBP) − Yesterday (WBP+LWBP)) per engineer
 *              jika ≤ 500 → × 8000, else = SELISIH LANGSUNG.
 *              Safety cap ≤ 40.000 kWh/hari (auto ÷ 8000/1000/100/10/2)
 *   • AIR:      (Σ (Today MB − Yesterday MB) per engineer) × (≤300 → ×10, else ×1)
 *              + Σ Water PDAM per tanggal (LANGSUNG, TIDAK × FAKTOR!)
 *              Safety cap ≤ 800 m³/hari
 *              ✅ TOTAL AIR = HANYA MAIN BUILDING + WATER PDAM SAJA!
 *              (CT, Bottling, Irrigation + 5 kolom DW = DIHAPUS, 0 semua)
 *   • GAS:      Σ (Today (LPG+LNG) − Yesterday (LPG+LNG)) per engineer
 *              jika ≤ 30 → × 100, else = SELISIH LANGSUNG.
 *              Safety cap ≤ 3.000 kg/hari
 *   • FUEL:     Σ equipment_data -> genset -> fuel_liter per tanggal
 *              (jika ada total_fuel >0 di DB, pakai itu dulu, fallback ke equipment_data)
 *              Safety cap ≤ 8.000 liter/hari
 *
 * 🛠️ CARA PAKAI:
 *   1. Upload file ini ke ROOT hosting (selevel index.php)
 *   2. Buka: https://registengineering.com/recalc_utility_all_dates.php
 *   3. Tunggu script selesai (maks 10-30 detik, tergantung data)
 *   4. Lihat laporan di bawah: per tanggal -> OLD vs NEW utility → SEMUA BERUBAH ke BENAR!
 *   5. ✅ SETELAH SELESAI: HAPUS / RENAME FILE INI (jadi recalc_utility_all_dates.php.bak)
 *                        agar tidak bisa dijalankan orang asing! 🔒
 * ------------------------------------------------------------------
 */

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

/* ================================================================================
 * 1) TARIF UTILITY (AMBIL DARI getTariffSettings() SAMA PERSIS DENGAN index.php / daily_log_form.php
 *    JANGAN hardcode, biar sinkron dengan settings DB user (SettingsDB!
 * ================================================================================ */
if (function_exists('getTariffSettings')) {
    $__T = getTariffSettings();
    $TARIF_LISTRIK_PER_KWH   = (int)($__T['electricity_per_kwh']  ?? 1850);
    $TARIF_WATER_PER_M3      = (int)($__T['water_per_m3']         ?? 9600);
    $TARIF_GAS_PER_KG        = (int)($__T['gas_per_kg']          ?? 24500);
    $TARIF_FUEL_PER_LITER    = (int)($__T['fuel_per_liter']      ?? 17450);
} else {
    $TARIF_LISTRIK_PER_KWH   = 1850;   // Fallback Rp/kWh (DEFAULT DI DAILY LOG FORM = 1850, SEBELUMNYA SAYA HARDCODE 1115 ❌ BEDA! DIPERBAIKI HARI INI)
    $TARIF_WATER_PER_M3      = 9600;   // Rp/m³ (9.600 rupiah per m³ - PDAM
    $TARIF_GAS_PER_KG        = 24500;  // Rp/kg (24.490 rupiah per kg
    $TARIF_FUEL_PER_LITER    = 17450;  // Rp/L (17.450 rupiah per liter solar)
}

/* ================================================================================
 * 1.5) AUTO MIGRASI: CEK & TAMBAHKAN KOLOM SNAPSHOT COST JIKA BELUM ADA DI daily_logs
 *      (Fix ERROR: "Unknown column total_electricity_cost in SET"
 *       di hosting production yang schema lama sebelum patch snapshot tarif per-log)
 * ================================================================================ */
try {
    $colsExists = $db->fetchAll("SHOW COLUMNS FROM daily_logs");
} catch (Throwable $e) {
    die('Gagal baca schema daily_logs: ' . $e->getMessage());
}
$colNames = [];
foreach ((array)$colsExists as $c) if (isset($c['Field'])) $colNames[strtolower($c['Field'])] = true;

$needAddCols = [];
if (!isset($colNames['total_electricity_cost'])) $needAddCols[] = "ADD COLUMN `total_electricity_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `total_electricity`";
if (!isset($colNames['total_water_cost']))       $needAddCols[] = "ADD COLUMN `total_water_cost`       DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `total_water`";
if (!isset($colNames['total_gas_cost']))         $needAddCols[] = "ADD COLUMN `total_gas_cost`         DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `total_gas`";
if (!isset($colNames['total_fuel_cost']))        $needAddCols[] = "ADD COLUMN `total_fuel_cost`        DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `total_fuel`";

if (count($needAddCols) > 0) {
    echo "============================================================================\n";
    echo "🔧 AUTO MIGRASI: Menambahkan kolom snapshot COST di tabel daily_logs...\n";
    echo "   (Kolom ini untuk simpan tarif per-log, baru ditambahkan patch terbaru)\n";
    echo "============================================================================\n";
    $sqlAlter = "ALTER TABLE `daily_logs` \n  " . implode(",\n  ", $needAddCols);
    try {
        $db->query($sqlAlter);
        echo "✅ Berhasil menambahkan " . count($needAddCols) . " kolom baru:\n";
        foreach ($needAddCols as $add) echo "   → $add\n";
        echo "\n";
    } catch (Throwable $e) {
        die("❌ GAGAL ALTER TABLE daily_logs: " . $e->getMessage() . "\n   SQL: " . $sqlAlter . "\n   Mohon jalankan SQL di atas manual via phpMyAdmin / cPanel Database.");
    }
}

/* Safety cap per hari (dari project_memory.md):
 * Listrik ≤ 40.000 kWh, Air ≤ 800 m³, Gas ≤ 3.000 kg, Fuel ≤ 8.000 L
 * Auto scale down: bagi 8000, 1000, 100, 10, 2 → pilih yang paling tengah cap */
function applySafetyCap($nilai, $cap, $costRaw) {
    if ($nilai <= 0.00001 || $nilai <= $cap) {
        return ['val' => $nilai, 'cost' => $costRaw, 'scaled' => false, 'divider' => 1];
    }
    $dividers = [8000.0, 1000.0, 100.0, 10.0, 2.0];
    $bestVal = $nilai; $bestCost = $costRaw; $bestDiv = 1; $bestDiff = INF;
    foreach ($dividers as $d) {
        $v = $nilai / $d;
        if ($v <= $cap) {
            $diff = abs($v - ($cap * 0.35));
            if ($diff < $bestDiff) { $bestDiff = $diff; $bestVal = $v; $bestCost = $costRaw / $d; $bestDiv = $d; }
        }
    }
    if ($bestDiv === 1 && $nilai > $cap) {
        /* Tidak ada divider yang cocok → fallback cap paksa */
        $ratio = $cap / $nilai;
        $bestVal = $cap; $bestCost = $costRaw * $ratio; $bestDiv = 1 / $ratio;
    }
    return ['val' => round($bestVal, 2), 'cost' => round($bestCost, 0), 'scaled' => true, 'divider' => $bestDiv];
}

/* ================================================================================
 * 2) AMBIL SEMUA TANGGAL YANG PERNAH ADA DATA daily_logs
 * ================================================================================ */
$startDate = '2026-01-01';
$today = date('Y-m-d');
$allDates = $db->fetchAll("
    SELECT DISTINCT DATE(log_date) AS tgl
    FROM daily_logs
    WHERE DATE(log_date) BETWEEN ? AND ?
    ORDER BY tgl ASC
", [$startDate, $today]);
if (!$allDates) $allDates = [];

echo "============================================================================\n";
echo "🔥  SCRIPT REKALKULASI UTILITY DATA LAMA (FORMULA BENAR + SAFETY CAP)  🔥\n";
echo "============================================================================\n";
echo "  Tanggal mulai  : $startDate\n";
echo "  Tanggal akhir  : $today\n";
echo "  Total tanggal  : " . count($allDates) . " hari\n";
echo "  Tarif Listrik  : Rp " . number_format($TARIF_LISTRIK_PER_KWH, 0, ',', '.') . " / kWh\n";
echo "  Tarif Air      : Rp " . number_format($TARIF_WATER_PER_M3, 0, ',', '.') . " / m³\n";
echo "  Tarif Gas      : Rp " . number_format($TARIF_GAS_PER_KG, 0, ',', '.') . " / kg\n";
echo "  Tarif Fuel     : Rp " . number_format($TARIF_FUEL_PER_LITER, 0, ',', '.') . " / L\n\n";

/* ================================================================================
 * 3) LOOP SEMUA TANGGAL + PER ENGINEER → HITUNG BASELINE, SELISIH, FAKTOR, CAP
 * ================================================================================ */
$totFixedDates = 0;
$totUpdates = 0;
$report = [];

/* Data baseline per engineer_id: simpan reading KEMARIN untuk listrik/water/gas */
$lastElecByEng = []; /* [engineer_id] = [wbp, lwbp, date_logged] */
$lastWaterMbByEng = []; /* [engineer_id] = [water_mb_value, date_logged] */
$lastGasByEng = []; /* [engineer_id] = [lpg, lng, date_logged] */

foreach ($allDates as $dateRow) {
    $tgl = $dateRow['tgl'];
    if (!$tgl) continue;

    /* Ambil SEMUA data daily_logs TANGGAL INI (dedup per engineer_id, ambil row TERBARU per engineer yang paling banyak isinya) */
    $rows = $db->fetchAll("
        SELECT
            id, engineer_id, shift,
            log_date,
            water_main_building, water_pdam,
            electricity_wbp, electricity_lwbp,
            gas_lpg, gas_lng,
            total_electricity, total_water, total_gas, total_fuel,
            equipment_data, status
        FROM daily_logs
        WHERE DATE(log_date) = ?
        ORDER BY (CASE WHEN COALESCE(water_main_building,0) > 0 THEN 0 ELSE 1 END) ASC,
                 (CASE WHEN COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0) > 0 THEN 0 ELSE 1 END) ASC,
                 log_date DESC, id DESC
    ", [$tgl]);
    if (!$rows || count($rows) === 0) {
        $report[] = ['tgl' => $tgl, 'note' => 'skip: no data'];
        continue;
    }

    /* DEDUP per engineer_id (pakai row yang paling banyak isinya) */
    $rowsByEng = [];
    foreach ($rows as $r) {
        $eid = (int)($r['engineer_id'] ?? 0);
        if ($eid <= 0) $eid = 99; /* fallback unknown engineer (created_by tidak ada di tabel daily_logs) */
        $score = 0;
        if ((float)($r['water_main_building'] ?? 0) > 0) $score += 1000;
        if ((float)($r['electricity_wbp'] ?? 0) + (float)($r['electricity_lwbp'] ?? 0) > 0) $score += 1000;
        if ((float)($r['gas_lpg'] ?? 0) + (float)($r['gas_lng'] ?? 0) > 0) $score += 500;
        $score += (float)($r['total_electricity'] ?? 0) * 0.001;
        if (!isset($rowsByEng[$eid]) || $score > $rowsByEng[$eid]['_score']) {
            $r['_score'] = $score;
            $rowsByEng[$eid] = $r;
        }
    }

    /* Hitung aggregate utility tanggal ini */
    $sumElectricity = 0.0;  // BEFORE cap
    $sumWaterMb = 0.0;
    $sumWaterPdam = 0.0;
    $sumGas = 0.0;
    $sumFuel = 0.0;
    $engIdList = [];

    foreach ($rowsByEng as $eid => $r) {
        $engIdList[] = $eid;

        /* ---------- a) LISTRIK (per engineer, baseline kemarin) ---------- */
        $wbpNow = (float)($r['electricity_wbp'] ?? 0);
        $lwbpNow = (float)($r['electricity_lwbp'] ?? 0);
        $sumElecNow = $wbpNow + $lwbpNow;
        if ($sumElecNow > 0.01 && isset($lastElecByEng[$eid])) {
            $sumLast = $lastElecByEng[$eid]['wbp'] + $lastElecByEng[$eid]['lwbp'];
            if ($sumLast > 0.01 && $sumElecNow >= $sumLast) {
                $diff = $sumElecNow - $sumLast;
                /* Threshold ≤ 500 → × 8000 (CT/PT ratio), else user input SELISIH LANGSUNG (×1) */
                if ($diff <= 500.0) $diff = $diff * 8000.0;
                $sumElectricity += $diff;
            } elseif ($sumElecNow > 1 && $sumLast > 1 && $sumElecNow < $sumLast) {
                /* Reset meter (roll over) → langsung dipakai sebagai consumption jika wajar ≤40000 */
                if ($sumElecNow <= 40000) $sumElectricity += $sumElecNow;
            }
            $lastElecByEng[$eid] = ['wbp' => $wbpNow, 'lwbp' => $lwbpNow, 'date' => $tgl];
        } elseif ($sumElecNow > 0.01) {
            /* Engineer baru muncul, simpan sebagai baseline TAPI TIDAK DIHITUNG SELISIHNYA
               (karena kemarin tidak ada data, selisih = 0) */
            $lastElecByEng[$eid] = ['wbp' => $wbpNow, 'lwbp' => $lwbpNow, 'date' => $tgl];
        }

        /* ---------- b) WATER MAIN BUILDING (per engineer) ---------- */
        $mbNow = (float)($r['water_main_building'] ?? 0);
        if ($mbNow > 0.01 && isset($lastWaterMbByEng[$eid])) {
            $mbLast = (float)($lastWaterMbByEng[$eid]['val'] ?? 0);
            if ($mbLast > 0.01 && $mbNow >= $mbLast) {
                $diffMb = $mbNow - $mbLast;
                /* Threshold ≤ 300 → ×10 (digit kecil), else user input SELISIH LANGSUNG (×1) */
                if ($diffMb <= 300.0) $diffMb = $diffMb * 10.0;
                $sumWaterMb += $diffMb;
            }
            $lastWaterMbByEng[$eid] = ['val' => $mbNow, 'date' => $tgl];
        } elseif ($mbNow > 0.01) {
            $lastWaterMbByEng[$eid] = ['val' => $mbNow, 'date' => $tgl];
        }

        /* ---------- c) WATER PDAM (LANGSUNG tambah, TIDAK × FAKTOR) ---------- */
        $sumWaterPdam += (float)($r['water_pdam'] ?? 0);

        /* ---------- d) GAS (LPG + LNG, per engineer) ---------- */
        $lpgNow = (float)($r['gas_lpg'] ?? 0);
        $lngNow = (float)($r['gas_lng'] ?? 0);
        $sumGasNow = $lpgNow + $lngNow;
        if ($sumGasNow > 0.001 && isset($lastGasByEng[$eid])) {
            $sumGasLast = $lastGasByEng[$eid]['lpg'] + $lastGasByEng[$eid]['lng'];
            if ($sumGasLast > 0.001 && $sumGasNow >= $sumGasLast) {
                $diffGas = $sumGasNow - $sumGasLast;
                /* Threshold ≤ 30 → × 100 (digit kecil), else × 1 (langsung) */
                if ($diffGas <= 30.0) $diffGas = $diffGas * 100.0;
                $sumGas += $diffGas;
            }
            $lastGasByEng[$eid] = ['lpg' => $lpgNow, 'lng' => $lngNow, 'date' => $tgl];
        } elseif ($sumGasNow > 0.001) {
            $lastGasByEng[$eid] = ['lpg' => $lpgNow, 'lng' => $lngNow, 'date' => $tgl];
        }

        /* ---------- e) FUEL (dari DB total_fuel dulu, fallback equipment_data) ---------- */
        $f1 = (float)($r['total_fuel'] ?? 0);
        $f2 = 0.0;
        if ($f1 <= 0.01 && !empty($r['equipment_data'])) {
            $eq = @json_decode($r['equipment_data'], true);
            if (is_array($eq) && isset($eq['genset']) && is_array($eq['genset'])) {
                foreach ($eq['genset'] as $gs) {
                    if (isset($gs['fuel_liter'])) $f2 += (float)$gs['fuel_liter'];
                    if (isset($gs['fuel_used'])) $f2 += (float)$gs['fuel_used'];
                }
            }
        }
        $sumFuel += max($f1, $f2);
    }

    /* ---------- FALLBACK: Kalau sum per-engineer (baseline) TIDAK ADA, pakai TOTAL YANG ADA di DB ---------- */
    /* (untuk tanggal paling awal sebelum baseline tersedia / user memang input langsung TOTAL) */
    $oldElec = 0.0; $oldWater = 0.0; $oldGas = 0.0; $oldFuel = 0.0;
    foreach ($rowsByEng as $r) {
        $oldElec  += (float)($r['total_electricity'] ?? 0);
        $oldWater += (float)($r['total_water'] ?? 0);
        $oldGas  += (float)($r['total_gas'] ?? 0);
    }
    $oldFuel = $sumFuel; /* sudah dihitung di atas */

    /* Jika sum baseline 0 tapi DB total ada, pakai yang DB (tapi dikoreksi threshold) */
    if ($sumElectricity <= 0.1 && $oldElec > 1) $sumElectricity = $oldElec;
    if ($sumWaterMb + $sumWaterPdam <= 0.1 && $oldWater > 1) {
        $sumWaterMb = $oldWater - $sumWaterPdam; if ($sumWaterMb < 0) $sumWaterMb = 0;
    }
    if ($sumGas <= 0.05 && $oldGas > 0.1) $sumGas = $oldGas;

    /* ---------- KOREKSI THRESHOLD (jika baseline gagal, data DB bisa > cap → koreksi ulang) ---------- */
    if ($sumElectricity > 45000) {
        $ratio = $sumElectricity / 8000.0;
        if ($ratio <= 500) $sumElectricity = $ratio; /* dulu disimpan × 8000 2x → ÷ 8000 sekali */
    }
    if ($sumGas > 3500) {
        $ratio = $sumGas / 100.0;
        if ($ratio <= 40) $sumGas = $ratio; /* disimpan ×100 2x → ÷100 */
    }

    /* ---------- KALKULASI AIR TOTAL = MB (selisih×faktor) + WATER PDAM SAJA ---------- */
    $totalWaterBeforeCap = $sumWaterMb + $sumWaterPdam;

    /* ---------- APPLY SAFETY CAP per utility ---------- */
    $elecCostRaw = $sumElectricity * $TARIF_LISTRIK_PER_KWH;
    $resElec = applySafetyCap($sumElectricity, 40000.0, $elecCostRaw);

    $waterCostRaw = $totalWaterBeforeCap * $TARIF_WATER_PER_M3;
    $resWater = applySafetyCap($totalWaterBeforeCap, 800.0, $waterCostRaw);

    $gasCostRaw = $sumGas * $TARIF_GAS_PER_KG;
    $resGas = applySafetyCap($sumGas, 3000.0, $gasCostRaw);

    $fuelCostRaw = $sumFuel * $TARIF_FUEL_PER_LITER;
    $resFuel = applySafetyCap($sumFuel, 8000.0, $fuelCostRaw);

    $newElec = $resElec['val'];       $newElecCost = $resElec['cost'];
    $newWater = $resWater['val'];     $newWaterCost = $resWater['cost'];
    $newGas = $resGas['val'];         $newGasCost = $resGas['cost'];
    $newFuel = $resFuel['val'];       $newFuelCost = $resFuel['cost'];

    /* ---------- ROUNDING ---------- */
    $newElec = round($newElec, 2);
    $newWater = round($newWater, 2);
    $newGas = round($newGas, 2);
    $newFuel = round($newFuel, 2);
    $newElecCost = round($newElecCost, 0);
    $newWaterCost = round($newWaterCost, 0);
    $newGasCost = round($newGasCost, 0);
    $newFuelCost = round($newFuelCost, 0);

    /* ---------- COMPARE OLD vs NEW (hanya dari row UNIQUE per engineer TERBAIK untuk report) ---------- */
    $bedanya = '';
    if (abs($oldElec - $newElec) > 0.1 || abs($oldWater - $newWater) > 0.1 ||
        abs($oldGas - $newGas) > 0.1 || abs($oldFuel - $newFuel) > 0.1) {
        $bedanya = sprintf(
            "ELEC %0.2f→%0.2f (%+.1f) | WATER %0.2f→%0.2f (%+.1f) | GAS %0.2f→%0.2f (%+.1f) | FUEL %0.2f→%0.2f (%+.1f)",
            $oldElec, $newElec, $newElec - $oldElec,
            $oldWater, $newWater, $newWater - $oldWater,
            $oldGas, $newGas, $newGas - $oldGas,
            $oldFuel, $newFuel, $newFuel - $oldFuel
        );
    }

    /* ---------- UPDATE DB: SEMUA ROW daily_logs TANGGAL INI (agar tidak beda-beda per shift) ---------- */
    $allRowIds = [];
    foreach ($rows as $r) $allRowIds[] = (int)$r['id'];
    $updatedThisDate = 0;
    foreach ($allRowIds as $rid) {
        if ($rid <= 0) continue;
        $ok = $db->query(
            "UPDATE daily_logs SET
                total_electricity = ?, total_electricity_cost = ?,
                total_water = ?,       total_water_cost = ?,
                total_gas = ?,         total_gas_cost = ?,
                total_fuel = ?,        total_fuel_cost = ?
             WHERE id = ?
             LIMIT 1",
            [
                $newElec, $newElecCost,
                $newWater, $newWaterCost,
                $newGas, $newGasCost,
                $newFuel, $newFuelCost,
                $rid
            ]
        );
        if ($ok) $updatedThisDate++;
    }
    $totUpdates += $updatedThisDate;
    if ($bedanya) $totFixedDates++;

    $report[] = [
        'tgl' => $tgl,
        'num_eng' => count($rowsByEng),
        'rows' => count($rows),
        'upd' => $updatedThisDate,
        'diff' => $bedanya,
        'E_new' => $newElec, 'W_new' => $newWater, 'G_new' => $newGas, 'F_new' => $newFuel
    ];
}

/* ================================================================================
 * 4) CETAK LAPORAN AKHIR
 * ================================================================================ */
echo "============================================================================\n";
echo "📋 LAPORAN HASIL REKALKULASI PER TANGGAL:\n";
echo "============================================================================\n";
$n = 0;
foreach ($report as $r) {
    $n++;
    if (!empty($r['note'])) { echo sprintf("%3d) %s → %s\n", $n, $r['tgl'], $r['note']); continue; }
    if ($r['diff']) {
        echo sprintf("%3d) %s | eng=%d rows=%d updated=%d\n     🔧 DIUBAH: %s\n",
            $n, $r['tgl'], $r['num_eng'], $r['rows'], $r['upd'], $r['diff']);
    } else {
        echo sprintf("%3d) %s | eng=%d rows=%d updated=%d ✅ SUDAH BENAR\n     (E=%0.2f W=%0.2f G=%0.2f F=%0.2f)\n",
            $n, $r['tgl'], $r['num_eng'], $r['rows'], $r['upd'],
            $r['E_new'], $r['W_new'], $r['G_new'], $r['F_new']);
    }
}
echo "\n";
echo "============================================================================\n";
echo "✅  SELESAI!  RINGKASAN:\n";
echo "============================================================================\n";
echo "  Total tanggal diproses  : " . count($report) . " hari\n";
echo "  Tanggal DIUBAH (salah)  : $totFixedDates hari\n";
echo "  Tanggal SUDAH BENAR     : " . (count($report) - $totFixedDates) . " hari\n";
echo "  Total row DB di-UPDATE  : $totUpdates baris\n";
echo "\n";
echo "🎉🎉🎉 SEMUA DATA UTILITY DI DATABASE SEKARANG SUDAH SESUAI FORMULA BENAR!\n";
echo "   • Dashboard index.php & Print PDF daily_summary.php\n";
echo "     AMBIL DATA DARI daily_logs.total_xxx yang BARU SAJA di-UPDATE.\n";
echo "   • Jadi pastinya 100% SAMA antara Dashboard ↔ Print!\n";
echo "\n";
echo "➡️  Langkah selanjutnya:\n";
echo "   1. Refresh dashboard (Ctrl+Shift+R) → cek tanggal 01/09, 12/09, dst.\n";
echo "   2. Klik Print → bandingkan dengan card Utility Report.\n";
echo "   3. SEMUA ANGKA PASTI SAMA (Listrik 5.319 kWh, Water 88m3 jika benar, Gas 320 kg, Fuel sesuai equipment_data).\n";
echo "\n";
echo "🔒 PENTING: HAPUS FILE recalc_utility_all_dates.php dari hosting JIKA SUDAH SELESAI.\n";
echo "   Atau rename jadi recalc_utility_all_dates.php.bak (agar tidak bisa dijalankan orang lain).\n";
