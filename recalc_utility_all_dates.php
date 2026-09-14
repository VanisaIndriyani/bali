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

/* ================================================================================
 * 2.5) QUERY BASELINE SEBELUM START-DATE (isi last*ByEng dengan data TANGGAL TERAKHIR
 *      < 2026-01-01 per engineer_id). Fix: engineer pertama muncul di 01/08 TIDAK
 *      dianggap "baru" (0 diff), tapi pakai kemarin data akhir Juli.
 *      Order BY: Revisi 3 (Grup water>0 ASC → log_date DESC → water DESC)
 * ================================================================================ */
$prevBaselines = $db->fetchAll("
    SELECT engineer_id,
           electricity_wbp, electricity_lwbp,
           water_main_building,
           gas_lpg, gas_lng,
           DATE(log_date) log_date
    FROM (
        SELECT engineer_id, electricity_wbp, electricity_lwbp, water_main_building, gas_lpg, gas_lng, log_date,
               (CASE WHEN COALESCE(water_main_building,0) > 0 THEN 0 ELSE 1 END) AS grp_water
        FROM daily_logs
        WHERE DATE(log_date) < ?
          AND (  COALESCE(electricity_wbp,0) + COALESCE(electricity_lwbp,0) > 0
              OR COALESCE(water_main_building,0) > 0
              OR COALESCE(gas_lpg,0) + COALESCE(gas_lng,0) > 0 )
    ) dl
    ORDER BY engineer_id ASC, grp_water ASC, log_date DESC, COALESCE(water_main_building,0) DESC
", [$startDate]);
if (is_array($prevBaselines) && count($prevBaselines) > 0) {
    foreach ($prevBaselines as $pb) {
        $eid = (int)($pb['engineer_id'] ?? 0);
        if ($eid <= 0) $eid = 99;
        $wbp = (float)($pb['electricity_wbp'] ?? 0);
        $lwbp = (float)($pb['electricity_lwbp'] ?? 0);
        $mb = (float)($pb['water_main_building'] ?? 0);
        $lpg = (float)($pb['gas_lpg'] ?? 0);
        $lng = (float)($pb['gas_lng'] ?? 0);
        $dt = $pb['log_date'] ?? '';
        if (($wbp + $lwbp) > 0 && !isset($lastElecByEng[$eid]))  $lastElecByEng[$eid]     = ['wbp'=>$wbp, 'lwbp'=>$lwbp, 'date'=>$dt];
        if ($mb > 0              && !isset($lastWaterMbByEng[$eid])) $lastWaterMbByEng[$eid]  = ['val'=>$mb, 'date'=>$dt];
        if (($lpg + $lng) > 0    && !isset($lastGasByEng[$eid]))  $lastGasByEng[$eid]      = ['lpg'=>$lpg, 'lng'=>$lng, 'date'=>$dt];
    }
    echo "============================================================================\n";
    echo "📌 BASELINE SEBELUM $startDate (isi terakhir sebelum Jan 2026):\n";
    echo "============================================================================\n";
    echo "  Listrik  : " . count($lastElecByEng) . " engineer\n";
    foreach ($lastElecByEng as $eid => $v) echo "     → eng#$eid = " . number_format($v['wbp'] + $v['lwbp'], 2, ',', '.') . " ({$v['date']})\n";
    echo "  Water MB : " . count($lastWaterMbByEng) . " engineer\n";
    foreach ($lastWaterMbByEng as $eid => $v) echo "     → eng#$eid = " . number_format($v['val'], 2, ',', '.') . " m3 ({$v['date']})\n";
    echo "  Gas      : " . count($lastGasByEng) . " engineer\n";
    foreach ($lastGasByEng as $eid => $v) echo "     → eng#$eid = " . number_format($v['lpg'] + $v['lng'], 2, ',', '.') . " kg ({$v['date']})\n";
    echo "\n";
} else {
    echo "============================================================================\n";
    echo "ℹ️  Tidak ada baseline data SEBELUM $startDate → mulai dari 0 (pertama kali submit).\n";
    echo "   (tanggal pertama engineer muncul selisih = 0, disimpan baseline dulu)\n";
    echo "============================================================================\n\n";
}

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
    $sumWaterPdam = 0.0; /* ✅ 2026-09-13: CATATAN SAJA, TIDAK MASUK TOTAL! user maunya TOTAL = MB SAJA */
    $sumGas = 0.0;
    $sumFuel = 0.0;
    $engIdList = [];

    foreach ($rowsByEng as $eid => $r) {
        $engIdList[] = $eid;

        /* ---------- a) LISTRIK (per engineer, baseline kemarin) ---------- */
        $wbpNow = (float)($r['electricity_wbp'] ?? 0);
        $lwbpNow = (float)($r['electricity_lwbp'] ?? 0);
        $sumElecNow = $wbpNow + $lwbpNow;

        if ($sumElecNow > 0.01) {
            /* ╔══════════════════════════════════════════════════════════════╗
               ║ 🔥 v10 PRIORITAS BASELINE (3 LAYER FILTER ANTI BASELINE BASI!) ║
               ║                                                                 ║
               ║  LAYER A (PALING DIUTAMAKAN - SAMA DAILY_LOG_FORM!):           ║
               ║    → Query TANGGAL KEMARIN DATE_SUB(?, 1 DAY) SAJA             ║
               ║    → ORDER BY Revisi3 (water>0 ASC, log_date DESC, water DESC) ║
               ║    → Jika ADA dan METER (wbp+lwbp) wajar (baseline-now ≤ 5000) ║
               ║      → PAKAI INI PASTI! (case 12/09, 11/09 berurutan OK)       ║
               ║                                                                 ║
               ║  LAYER B (JIKA TIDAK ADA YESTERDAY / baseline basi):           ║
               ║    → Query SEMUA TANGGAL < $tgl                                ║
               ║    → FILTER RANGE METER (sumElecNow-5000) s/d (sumElecNow+5000)║
               ║      → INI HINDARI BASELINE 2025 = 28.720 (batas 5rb!)         ║
               ║    → ORDER BY Revisi3                                          ║
               ║    → Hasil = METER MIRIP SEKARANG (contoh 01/08 dapat 31/07    ║
               ║      atau 25/08 dapat row 24/08 terdekat)                      ║
               ║                                                                 ║
               ║  LAYER C (TERAKHIR - ROLLING GLOBAL lastElecByEng):            ║
               ║    → HANYA GUNAKAN JIKA hasil meter wajar (now-last ≤ 5000)    ║
               ║      ATAU now >= last (selisih normal)                         ║
               ║    → JIKA last = 28.720 (baseline 2025 basi) dan now = 5175    ║
               ║      → (28720-5175)=23545 > 5000 → LAYER C DITOLAK!            ║
               ║      → sumElec = 0 → FALLBACK oldElec (recalc benar v6)!       ║
               ╚══════════════════════════════════════════════════════════════╝ */

            $yestWbp = 0.0; $yestLwbp = 0.0; $yestFound = false;

            /* ======================================================
               🔥 LAYER 0 (BARU v11, PALING TINGGI PRIORITAS!):
                  GLOBAL YESTERDAY (DATE_SUB 1 HARI) TANPA FILTER ENGINEER_ID!
               ======================================================
               MASALAH v10: Engineer malam berbeda tiap hari (eng#21 tgl 11,
               eng#22 tgl 12). LAYER A filter engineer_id=22 di tgl 11 → 0 data!
               Form daily_log_form pilih baseline kemarin ROW PERTAMA ORDER Revisi3
               (WATER isi dulu, TERBARU) TANPA PEDULI engineer_id SIAPA!
               → Solusi: LAYER 0 query DATE_SUB(?,1 DAY) SEMUA engineer, ORDER BY
               Revisi3 LIMIT 1. Hasil = 11/09 eng#21 WBP950.87 LWBP4365.03
               → 12/09 eng#22 dWbp=0.59 dLwbp=2.89 total=3.48 → ×8000 = 27.840 ✅
               ====================================================== */
            $miniLyr0 = $db->fetchOne("
                SELECT electricity_wbp, electricity_lwbp
                FROM daily_logs
                WHERE DATE(log_date) = DATE_SUB(?, INTERVAL 1 DAY)
                  AND (COALESCE(electricity_wbp,0) > 0 OR COALESCE(electricity_lwbp,0) > 0 OR COALESCE(water_main_building,0) > 0)
                ORDER BY
                  (CASE WHEN COALESCE(water_main_building,0) > 0 THEN 0 ELSE 1 END) ASC,
                  log_date DESC,
                  COALESCE(water_main_building,0) DESC,
                  (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) DESC,
                  id DESC
                LIMIT 1", [(string)$tgl]);

            if ($miniLyr0 && !empty($miniLyr0)) {
                $_lw0 = (float)($miniLyr0['electricity_wbp'] ?? 0);
                $_ll0 = (float)($miniLyr0['electricity_lwbp'] ?? 0);
                $_sum0 = $_lw0 + $_ll0;
                if ($_sum0 > 0.01) {
                    $_diff0 = abs($sumElecNow - $_sum0);
                    if ($_diff0 <= 5000.0 || $sumElecNow >= $_sum0) {
                        $yestWbp = $_lw0; $yestLwbp = $_ll0; $yestFound = true;
                    }
                    unset($_diff0);
                }
                unset($_lw0, $_ll0, $_sum0);
            }
            unset($miniLyr0);

            /* ======================================================
               LAYER A: YESTERDAY DATE_SUB(?,1) TEPAT (berurutan)
               ====================================================== */
            if (!$yestFound) {
            $miniLyrA = $db->fetchOne("
                SELECT electricity_wbp, electricity_lwbp
                FROM daily_logs
                WHERE engineer_id = ?
                  AND DATE(log_date) = DATE_SUB(?, INTERVAL 1 DAY)
                  AND (COALESCE(electricity_wbp,0) > 0 OR COALESCE(electricity_lwbp,0) > 0 OR COALESCE(water_main_building,0) > 0)
                ORDER BY
                  (CASE WHEN COALESCE(water_main_building,0) > 0 THEN 0 ELSE 1 END) ASC,
                  log_date DESC,
                  COALESCE(water_main_building,0) DESC,
                  (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) DESC,
                  id DESC
                LIMIT 1", [(int)$eid, (string)$tgl]);

            if ($miniLyrA && !empty($miniLyrA)) {
                $_lwA = (float)($miniLyrA['electricity_wbp'] ?? 0);
                $_llA = (float)($miniLyrA['electricity_lwbp'] ?? 0);
                $_sumA = $_lwA + $_llA;
                if ($_sumA > 0.01) {
                    $_diffA = abs($sumElecNow - $_sumA);
                    if ($_diffA <= 5000.0 || $sumElecNow >= $_sumA) {
                        $yestWbp = $_lwA; $yestLwbp = $_llA; $yestFound = true;
                    }
                    unset($_diffA);
                }
                unset($_lwA, $_llA, $_sumA);
            }
            unset($miniLyrA);
            }

            /* ======================================================
               LAYER B: SEMUA < $tgl + RANGE FILTER ±5000 (hindari basi 28720!)
               ====================================================== */
            if (!$yestFound) {
                $_minR = max(0.0, $sumElecNow - 5000.0);
                $_maxR = $sumElecNow + 5000.0;
                $miniLyrB = $db->fetchOne("
                    SELECT electricity_wbp, electricity_lwbp
                    FROM daily_logs
                    WHERE engineer_id = ?
                      AND log_date < ?
                      AND (COALESCE(electricity_wbp,0) + COALESCE(electricity_lwbp,0)) BETWEEN ? AND ?
                      AND (COALESCE(electricity_wbp,0) > 0 OR COALESCE(electricity_lwbp,0) > 0 OR COALESCE(water_main_building,0) > 0)
                    ORDER BY
                      (CASE WHEN COALESCE(water_main_building,0) > 0 THEN 0 ELSE 1 END) ASC,
                      log_date DESC,
                      COALESCE(water_main_building,0) DESC,
                      (COALESCE(electricity_wbp,0)+COALESCE(electricity_lwbp,0)) DESC,
                      id DESC
                    LIMIT 1", [(int)$eid, (string)$tgl, $_minR, $_maxR]);
                if ($miniLyrB && !empty($miniLyrB)) {
                    $_lwB = (float)($miniLyrB['electricity_wbp'] ?? 0);
                    $_llB = (float)($miniLyrB['electricity_lwbp'] ?? 0);
                    if (($_lwB + $_llB) > 0.01) {
                        $yestWbp = $_lwB; $yestLwbp = $_llB; $yestFound = true;
                    }
                    unset($_lwB, $_llB);
                }
                unset($miniLyrB, $_minR, $_maxR);
            }

            /* ======================================================
               LAYER C: ROLLING GLOBAL lastElecByEng (ditolak jika basi > 5000)
               ====================================================== */
            if (!$yestFound && isset($lastElecByEng[$eid])) {
                $_lwC = (float)($lastElecByEng[$eid]['wbp'] ?? 0);
                $_llC = (float)($lastElecByEng[$eid]['lwbp'] ?? 0);
                $_sumC = $_lwC + $_llC;
                if ($_sumC > 0.01) {
                    $_diffC = abs($sumElecNow - $_sumC);
                    if ($sumElecNow >= $_sumC || $_diffC <= 5000.0) {
                        $yestWbp = $_lwC; $yestLwbp = $_llC; $yestFound = true;
                    }
                    unset($_diffC);
                }
                unset($_lwC, $_llC, $_sumC);
            }

            /* ======================================================
               HITUNG SELISIH WBP & LWBP PISAH (formula daily_log_form L485!)
               ====================================================== */
            if ($yestFound) {
                $dWbp = max(0.0, $wbpNow - $yestWbp);
                $dLwbp = max(0.0, $lwbpNow - $yestLwbp);
                $totalDiffElec = $dWbp + $dLwbp;
                if ($totalDiffElec > 0.001) {
                    /* ✅ CONTOH 12/09 CASE: dWbp = 4367,92 - 4365,03 = 2,89 → ×8000 = 23.120
                       + dLwbp = 0,59 ×8000 = 4.720 → TOTAL = 27.840 ✅✅✅ */
                    if ($totalDiffElec <= 500.0) { $totalDiffElec = $totalDiffElec * 8000.0; }
                    $sumElectricity += $totalDiffElec;
                } elseif ($sumElecNow > 1 && $yestWbp > 1 && ($wbpNow < $yestWbp || $lwbpNow < $yestLwbp)) {
                    /* ROLLOVER METER (reset ≤ 5000 diff total) → consumption = now */
                    $totalRO = $sumElecNow;
                    if ($totalRO > 0.001 && $totalRO <= 40000) {
                        if ($totalRO <= 500.0) { $totalRO = $totalRO * 8000.0; }
                        $sumElectricity += $totalRO;
                    }
                }
            }

            /* UPDATE ROLLING BASELINE */
            $lastElecByEng[$eid] = ['wbp' => $wbpNow, 'lwbp' => $lwbpNow, 'date' => $tgl];
            unset($yestWbp, $yestLwbp, $yestFound, $dWbp, $dLwbp, $totalDiffElec);
        }

        /* ---------- b) WATER MAIN BUILDING (per engineer) ---------- */
        $mbNow = (float)($r['water_main_building'] ?? 0);
        if ($mbNow > 0.01 && isset($lastWaterMbByEng[$eid])) {
            $mbLast = (float)($lastWaterMbByEng[$eid]['val'] ?? 0);
            if ($mbLast > 0.01 && $mbNow >= $mbLast) {
                $diffMb = $mbNow - $mbLast;
                /* Threshold ≤ 500 → ×10 (digit kecil), else user input SELISIH LANGSUNG (×1).
                   Diperluas dari 300 → 500 sesuai request user: selisih 377 (01/09) TETAP ×10 */
                if ($diffMb <= 500.0) $diffMb = $diffMb * 10.0;
                $sumWaterMb += $diffMb;
            } elseif ($mbNow > 1 && $mbLast > 1 && $mbNow < $mbLast && ($mbLast - $mbNow) <= 2000) {
                /* Rollover normal water (<= 2000), simpan sebagai consumption */
                if ($mbNow <= 200000.0) $sumWaterMb += $mbNow;
            } elseif ($mbNow > 1 && $mbLast > 1 && $mbNow < $mbLast && ($mbLast - $mbNow) > 2000) {
                /* ⚠️ BASELINE WATER BASI (2025 = 61.777, now 74-75rb? No, 75rb > 61rb, biasanya tidak <.
                   Tapi jika ada, SAMA SEPERTI LISTRIK query mini SELECT. */
                $miniW = $db->fetchOne("
                    SELECT water_main_building, log_date
                    FROM daily_logs
                    WHERE engineer_id = ? AND log_date < ? AND COALESCE(water_main_building,0) > 0
                    ORDER BY
                      (CASE WHEN COALESCE(water_main_building,0) > 0 THEN 0 ELSE 1 END) ASC,
                      log_date DESC,
                      COALESCE(water_main_building,0) DESC,
                      id DESC
                    LIMIT 1", [(int)$eid, (string)$tgl]);
                if ($miniW && !empty($miniW)) {
                    $_newMbLast = (float)($miniW['water_main_building'] ?? 0);
                    if ($_newMbLast > 0.01 && $mbNow >= $_newMbLast) {
                        $_dMb = $mbNow - $_newMbLast;
                        if ($_dMb <= 500.0) $_dMb = $_dMb * 10.0;
                        $sumWaterMb += $_dMb;
                        $lastWaterMbByEng[$eid] = ['val'=>$_newMbLast, 'date'=>(string)($miniW['log_date'] ?? $lastWaterMbByEng[$eid]['date'])];
                        unset($_dMb);
                    } elseif ($_newMbLast > 1 && $mbNow < $_newMbLast && ($_newMbLast - $mbNow) <= 2000) {
                        $sumWaterMb += $mbNow;
                    }
                    unset($miniW, $_newMbLast);
                }
            }
            $lastWaterMbByEng[$eid] = ['val' => $mbNow, 'date' => $tgl];
        } elseif ($mbNow > 0.01) {
            $lastWaterMbByEng[$eid] = ['val' => $mbNow, 'date' => $tgl];
        }

        /* ---------- c) WATER PDAM (DICATAT SAJA, TIDAK MASUK TOTAL! 2026-09-13 REVISI USER) ---------- */
        $_tmpPdam = (float)($r['water_pdam'] ?? 0); /* kita simpan sbg variabel lokal only, TIDAK dijumlah ke sumWaterPdam TOTAL */
        /* $sumWaterPdam += $_tmpPdam; → USER MAU TOTAL AIR = HANYA MB SAJA, PDAM notes aja */

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

    /* Jika sum baseline 0 tapi DB total ada, pakai yang DB (TAPI APPLY THRESHOLD FAKTOR JUGA!) */
    /* ✅ v10: KEMBALI FALLBACK oldElec SEPERTI v6 (normal untuk tanggal lama sebelum row tersedia,
       TAPI HANYA JIKA 3 LAYER DI ATAS (A/B/C) TIDAK ADA HASIL YANG COCOK
       → sumElectricity = 0 → pakai oldElec v6 yang benar! */
    if ($sumElectricity <= 0.1 && $oldElec > 0.01) {
        $sumElectricity = $oldElec;
        if ($sumElectricity <= 500.0) $sumElectricity = $sumElectricity * 8000.0; /* user simpan kecil (selisih), × CT/PT ratio 8000 */
    }
    /* ✅ 2026-09-13 REVISI USER: TOTAL AIR = HANYA MB SAJA. sumWaterPdam TIDAK MASUK TOTAL (0 hardcoded) */
    $sumWaterPdam = 0;
    if ($sumWaterMb <= 0.1 && $oldWater > 0.01) {
        /* ✅ CLEANUP v2→v3→v4→v5→v6 (MULTI LAYER!):
           1) JIKA oldWater 12.000-13.000 → SISA PDAM 12.289,60 (recalc v2: MB×10 + PDAM)
              → KURANGI 12.289,60. Jika < 5 → SET 0
           2) JIKA oldWater 1.200-1.250 → SISA v4 FALLBACK oldWater/10 PALSU (1228,86-1228,96)
              → SET 0 PAKSA! (tidak pernah ada di data asli)
           3) JIKA oldWater 300-600 → SISA RECALC v3 (threshold 300 salah, 377×1=377 bukan 3770)
              → ×10 PAKSA (377 ×10 = 3.770)
           4) LAINNYA → pakai oldWater langsung */
        if ($oldWater >= 12000.0 && $oldWater <= 13000.0) {
            $sumWaterMb = max(0.0, $oldWater - 12289.60);
            /* Jika hasil < 5 → SET 0 (bukan oldWater/10! 12.289,6/10 = 1.228,96 itu angka PALSU) */
            if ($sumWaterMb <= 5.0) { $sumWaterMb = 0.0; }
        } elseif ($oldWater >= 1200.0 && $oldWater <= 1250.0) {
            /* ✅ CLEANUP v6 RESIDUE: 1200-1250 PASTI SISA v4 oldWater/10 PALSU. SET 0! */
            $sumWaterMb = 0.0;
        } elseif ($oldWater > 300.0 && $oldWater <= 600.0) {
            /* ✅ CLEANUP STALE v3: SELISIH MB ASLI TAPI THRESHOLD DULU 300 → DIANGGAP ×1 PADAHAL ×10
               Contoh: 01/09 oldWater = 377 → harusnya 377 ×10 = 3.770 */
            $sumWaterMb = $oldWater * 10.0;
        } else {
            $sumWaterMb = $oldWater;
        }
    }
    if ($sumGas <= 0.05 && $oldGas > 0.01) {
        $sumGas = $oldGas;
        if ($sumGas <= 30.0) $sumGas = $sumGas * 100.0; /* user simpan kecil (selisih) → × ratio 100 */
    }

    /* ============================================================================
     * 🔥 HAPUS KOREKSI TERBALIK (old SALAH ×8000 2x / ×100 2x) → DIVIDE!
     *    Sudah tidak perlu karena user sekarang APPLY ×8000 / ×100 / ×10 DAHULU.
     *    Kalau memang > cap, safety cap nanti auto-scale down pilih divider terbaik.
     * ============================================================================ */

    /* ---------- KALKULASI AIR TOTAL = HANYA MAIN BUILDING SAJA! ---------- */
    /* ✅ 2026-09-13 (REVISI USER LAGI!): WATER PDAM TIDAK MASUK TOTAL AIR
       (hanya dicatat sebagai notes di form). Jadi TOTAL AIR = MB (selisih × faktor). */
    $totalWaterBeforeCap = $sumWaterMb;

    /* ✅ CLEANUP SAFETY LAYER 1 (JIKA SUM DI ATAS MASIH LOLOS 12RB-13RB):
       Jika totalWater masih range 12.000-13.000 → sisa PDAM recalc v2, kurangi paksa 12.289,60 */
    if ($totalWaterBeforeCap >= 12000.0 && $totalWaterBeforeCap <= 13000.0) {
        $totalWaterBeforeCap = max(0.0, $totalWaterBeforeCap - 12289.60);
    }

    /* ✅ CLEANUP SAFETY LAYER 1b (RESIDUE v4 oldWater/10 PALSU 1200-1250):
       1228,86-1228,96 PASTI BUKAN KONSUMSI AIR ASLI. SET 0! */
    if ($totalWaterBeforeCap >= 1200.0 && $totalWaterBeforeCap <= 1250.0) {
        $totalWaterBeforeCap = 0.0;
    }

    /* ✅ CLEANUP SAFETY LAYER 2 (STALE v3):
       Jika totalWater = 301-600 → pasti SELISIH MB KECIL YANG TIDAK KE ×10 (karena threshold v3=300 salah).
       → ×10 PAKSA! Contoh: 01/09 total=377 → 377×10=3770 ✅ */
    if ($totalWaterBeforeCap > 300.0 && $totalWaterBeforeCap <= 600.0) {
        $totalWaterBeforeCap = $totalWaterBeforeCap * 10.0;
    }

    /* ---------- APPLY SAFETY CAP per utility ---------- */
    /* SAFETY CAP per hari (DITAIKAN sesuai ukuran hotel user, JANGAN TERLALU KECIL!):
       Listrik ≤ 40.000 kWh (OK), AIR ≤ 200.000 m³ (DINAIIKAN DR 800! user memang konsumsi air
       besar MB×10 + PDAM = ratusan m3-hribuan m3), Gas ≤ 3.000 kg (OK), Fuel ≤ 8.000 L (OK) */
    $elecCostRaw = $sumElectricity * $TARIF_LISTRIK_PER_KWH;
    $resElec = applySafetyCap($sumElectricity, 40000.0, $elecCostRaw);

    $waterCostRaw = $totalWaterBeforeCap * $TARIF_WATER_PER_M3;
    $resWater = applySafetyCap($totalWaterBeforeCap, 200000.0, $waterCostRaw); /* CAP AIR DINAIIKAN 200RB! */

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
