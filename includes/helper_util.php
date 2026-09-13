<?php
/**
 * ============================================================
 * 🔗 SHARED HELPER: Utility Auto-Correction & Shared Logic
 * ============================================================
 * File ini dipakai BAIK index.php (dashboard) MAUPUN daily_summary.php (print/PDF)
 * Tujuannya: LOGIC AUTO-FIX DATA LAMA 100% SAMA ANTARA DASHBOARD & PRINT,
 *            agar angka utility TIDAK BEDA LAGI antara keduanya.
 *
 * ⚠️ JANGAN MODIF FUNGDI INI HANYA DI SATU FILE!
 *    Ubah di includes/helper_util.php SAJA → include di kedua file.
 * ============================================================ */

if (!defined('BASE_URL')) { define('BASE_URL', '../'); }
if (!function_exists('repAutoFixUtilityFormulaLama')):

/**
 * 🔧 AUTO CORRECTION (Fix Data Lama Sebelum Formula FAKTOR DIFIX!)
 * ROOT CAUSE: Customer terlanjur SAVE form SEBELUM patch formula (×8000 listrik, ×10 air, ×100 gas)
 *            → kolom total_* di-DB MASIH nyimpan SELISIH MENTAH (BELUM × faktor ratio),
 *               PADAHAL seharusnya SELISIH READING METER × FAKTOR RATIO = KONSUMSI NYATA.
 *            Atau sebaliknya: SELISIH × faktor DISIMPAN DUA KALI (×8000 dua kali → jadi 26M kWh).
 * SOLUSI: Sebelum fetch aggregate, SCAN SEMUA daily_logs di range tanggal.
 *         JIKA total_* TIDAK SESUAI dengan rumus benar → UPDATE OTOMATIS!
 *
 * ✅ VERSI DIPERLUAS (2026-09-12):
 *    - TIDAK HANYA listrik+air (versi lama) → TAMBAH GAS + FUEL!
 *    - Gas: Jika reading gas_lpg/gas_lng ADA tapi total_gas KECIL (≤50) → ×100 (mirip listrik ×8000).
 *      Threshold: jika SELISIH READING ≤30 → ×100 (digit kecil = ratio meter gas). Jika >30 → ×1 (langsung).
 *    - Fuel: Jika equipment_data.genset.konsumsi_fuel_liter ADA tapi total_fuel = 0 → pindahkan ke kolom total_fuel.
 *
 * @param Database $db
 * @param string $dateFrom  Y-m-d
 * @param string $dateTo    Y-m-d
 * @param int $TARIF_LISTRIK
 * @param int $TARIF_AIR
 * @param int $TARIF_GAS
 * @param int $TARIF_FUEL
 */
function repAutoFixUtilityFormulaLama($db, $dateFrom, $dateTo, $TARIF_LISTRIK, $TARIF_AIR, $TARIF_GAS, $TARIF_FUEL) {
    static $lastRunCache = null;
    $cacheKey = $dateFrom.'__'.$dateTo;
    if ($lastRunCache === $cacheKey) return;
    $lastRunCache = $cacheKey;

    try {
        /* --- SCAN daily_logs KANDIDAT (total_* TIDAK SESUAI) --- */
        $candidates = $db->fetchAll("SELECT id, log_date, engineer_id, shift,
                COALESCE(electricity_wbp,0) ew, COALESCE(electricity_lwbp,0) el,
                COALESCE(electricity_wbp_yesterday,0) ewy, COALESCE(electricity_lwbp_yesterday,0) ely,
                COALESCE(water_main_building,0) wmb, COALESCE(water_main_building_yesterday,0) wmby,
                COALESCE(gas_lpg,0) glpg, COALESCE(gas_lng,0) glng,
                COALESCE(NULLIF(gas_lpg_yesterday,0),0) glpgy, COALESCE(NULLIF(gas_lng_yesterday,0),0) glngy,
                COALESCE(total_electricity,0) te, COALESCE(total_water,0) tw,
                COALESCE(NULLIF(tariff_electricity_per_kwh,0),0) tel, COALESCE(NULLIF(tariff_water_per_m3,0),0) twa,
                COALESCE(NULLIF(tariff_gas_per_kg,0),0) tga, COALESCE(NULLIF(tariff_fuel_per_liter,0),0) tfu,
                COALESCE(total_gas,0) tg, COALESCE(total_fuel,0) tf,
                COALESCE(equipment_data, '') AS eq_json,
                /* ✅ 2026-09-13 UPDATE (REVISI USER LAGI!): TOTAL AIR = HANYA MAIN BUILDING SAJA!
                   WATER PDAM TIDAK MASUK TOTAL (hanya dicatat / notes), others_water = 0
                   Hapus permanen: CT, Bottling, Irrigation + 5 kolom lama */
                0 as others_water
             FROM daily_logs
             WHERE DATE(log_date) BETWEEN ? AND ?
               AND (
                   ((electricity_wbp > 0 OR electricity_lwbp > 0) AND (COALESCE(total_electricity,0) <= 500 OR COALESCE(total_electricity,0) > 50000))
                OR (water_main_building > 0 AND COALESCE(total_water,0) <= 100)
                OR ((gas_lpg > 0 OR gas_lng > 0) AND (COALESCE(total_gas,0) <= 50 OR COALESCE(total_gas,0) > 4000))
                OR (COALESCE(total_fuel,0) <= 0.01 AND COALESCE(equipment_data, '') != '')
               )
             ORDER BY log_date ASC, id ASC",
            [$dateFrom, $dateTo]
        );
        if (!$candidates || !is_array($candidates) || count($candidates) === 0) return;

        foreach ($candidates as $c) {
            $cid = (int)($c['id'] ?? 0);
            if ($cid <= 0) continue;
            $changed = false;

            /* --- (A) FIX LISTRIK --- */
            $te = (float)($c['te'] ?? 0);
            $ew = (float)($c['ew'] ?? 0); $el = (float)($c['el'] ?? 0);
            $ewy = (float)($c['ewy'] ?? 0); $ely = (float)($c['ely'] ?? 0);
            $tel = (float)($c['tel'] ?? 0); if ($tel <= 0) $tel = (float)$TARIF_LISTRIK;

            if (($ew > 0 || $el > 0)) {
                $fixElec = false;
                if ($te <= 500.0) $fixElec = true;              // SELISIH MENTAH (×8000 BLM KE-APPLY)
                if ($te > 50000.0) $fixElec = true;              // KEBALIKAN: ×8000 DISIMPAN 2X (26M kWh → harus 3k)
                if ($fixElec) {
                    $pEw = $ewy; $pEl = $ely;
                    if ($pEw <= 0 && $pEl <= 0) {
                        $prevR = $db->fetchOne("SELECT COALESCE(electricity_wbp,0) ew, COALESCE(electricity_lwbp,0) el
                                    FROM daily_logs
                                    WHERE engineer_id = ? AND DATE(log_date) < DATE(?)
                                    ORDER BY log_date DESC, id DESC LIMIT 1",
                            [(int)($c['engineer_id'] ?? 0), (string)($c['log_date'] ?? date('Y-m-d'))]);
                        if ($prevR) {
                            if ($pEw <= 0) $pEw = (float)($prevR['ew'] ?? 0);
                            if ($pEl <= 0) $pEl = (float)($prevR['el'] ?? 0);
                        }
                    }
                    $dEw = ($ew > 0 && $pEw > 0 && $ew > $pEw) ? max(0.0, $ew - $pEw) : 0.0;
                    $dEl = ($el > 0 && $pEl > 0 && $el > $pEl) ? max(0.0, $el - $pEl) : 0.0;
                    $dSum = $dEw + $dEl;
                    if ($dSum <= 0.00001) $dSum = $te > 50000 ? ($te / 8000.0) : $te;
                    $faktor = ($dSum > 0 && $dSum <= 500.0) ? 8000.0 : 1.0;
                    $teNew = $dSum * $faktor;
                    /* Safety reverse: Jika nilai ASLI di DB SANGAT BESAR (>50rb) = ×8000 2x → DIBAGI 8000 SAJA */
                    if ($te > 50000.0) {
                        $candidateDivide = $te / 8000.0;
                        if (abs($candidateDivide - $teNew) < ($teNew * 0.2)) { $teNew = $candidateDivide; }
                    }
                    /* Safety cap listrik: max 40.000 kWh/hari (lebih dari ini tidak masuk akal) */
                    if ($teNew > 40000.0) { $teNew = min(40000.0, $te / 8000.0, $te / 1000.0, $te / 100.0, $te / 10.0); }
                    if (abs($teNew - $te) > ($te * 0.1) || $teNew > 0.001 && $te <= 0.001) {
                        $te = $teNew;
                        $changed = true;
                    }
                }
            }

            /* --- (B) FIX AIR MAIN BUILDING --- */
            $tw = (float)($c['tw'] ?? 0);
            $wmb = (float)($c['wmb'] ?? 0); $wmby = (float)($c['wmby'] ?? 0);
            $othersW = 0; /* ✅ 2026-09-13: WATER PDAM TIDAK MASUK TOTAL LAGI! Hardcode 0 */
            $twa = (float)($c['twa'] ?? 0); if ($twa <= 0) $twa = (float)$TARIF_AIR;

            if ($wmb > 0) {
                $mbPart = $tw; /* ✅ 2026-09-13: TOTAL = MB SAJA, jadi bagian MB = seluruh total_water (TIDAK KURANG othersW) */
                $fixWater = ($mbPart <= 100.0) || ($mbPart > 900.0);
                if ($fixWater) {
                    $pWmb = $wmby;
                    if ($pWmb <= 0) {
                        $prevR = $db->fetchOne("SELECT COALESCE(water_main_building,0) wmb
                                    FROM daily_logs
                                    WHERE engineer_id = ? AND DATE(log_date) < DATE(?)
                                    ORDER BY log_date DESC, id DESC LIMIT 1",
                            [(int)($c['engineer_id'] ?? 0), (string)($c['log_date'] ?? date('Y-m-d'))]);
                        if ($prevR) { $pWmb = (float)($prevR['wmb'] ?? 0); }
                    }
                    $dWmb = ($wmb > 0 && $pWmb > 0 && $wmb > $pWmb) ? max(0.0, $wmb - $pWmb) : 0.0;
                    if ($dWmb <= 0.00001) $dWmb = max(0.0, $mbPart);
                    $fWmb = ($dWmb > 0 && $dWmb <= 300.0) ? 10.0 : 1.0;
                    $wmbConsNew = $dWmb * $fWmb;
                    if ($mbPart > 900.0) {
                        $rev = $mbPart / 10.0;
                        if (abs($rev - $wmbConsNew) < ($wmbConsNew * 0.3)) $wmbConsNew = $rev;
                    }
                    if ($wmbConsNew > 200000.0) $wmbConsNew = 200000.0; /* cap air ≤200.000 m3/hari (dinaikkan dr 800! user memang besar MB×10) */
                    $twNew = $wmbConsNew; /* ✅ 2026-09-13: TOTAL = MB SAJA (TIDAK + othersW / + PDAM) */
                    if (abs($twNew - $tw) > ($tw * 0.1) || abs($mbPart - $wmbConsNew) > ($wmbConsNew * 0.1)) {
                        $tw = $twNew;
                        $changed = true;
                    }
                }
            }

            /* --- (C) FIX GAS (LPG + LNG) BARU 2026-09-12 --- */
            $tg = (float)($c['tg'] ?? 0);
            $glpg = (float)($c['glpg'] ?? 0); $glng = (float)($c['glng'] ?? 0);
            $glpgy = (float)($c['glpgy'] ?? 0); $glngy = (float)($c['glngy'] ?? 0);
            $tga = (float)($c['tga'] ?? 0); if ($tga <= 0) $tga = (float)$TARIF_GAS;

            if ($glpg > 0 || $glng > 0) {
                $fixGas = ($tg <= 50.0) || ($tg > 4000.0); /* ≤50 = blm ×100 | >4000 = ×100 2x / cap */
                if ($fixGas) {
                    $pLpg = $glpgy; $pLng = $glngy;
                    if ($pLpg <= 0 && $pLng <= 0) {
                        $prevR = $db->fetchOne("SELECT COALESCE(gas_lpg,0) glpg, COALESCE(gas_lng,0) glng
                                    FROM daily_logs
                                    WHERE engineer_id = ? AND DATE(log_date) < DATE(?)
                                    ORDER BY log_date DESC, id DESC LIMIT 1",
                            [(int)($c['engineer_id'] ?? 0), (string)($c['log_date'] ?? date('Y-m-d'))]);
                        if ($prevR) {
                            if ($pLpg <= 0) $pLpg = (float)($prevR['glpg'] ?? 0);
                            if ($pLng <= 0) $pLng = (float)($prevR['glng'] ?? 0);
                        }
                    }
                    $dLpg = ($glpg > 0 && $pLpg > 0 && $glpg > $pLpg) ? max(0.0, $glpg - $pLpg) : 0.0;
                    $dLng = ($glng > 0 && $pLng > 0 && $glng > $pLng) ? max(0.0, $glng - $pLng) : 0.0;
                    $dSum = $dLpg + $dLng;
                    if ($dSum <= 0.00001) {
                        /* Tidak ada prev yang valid → gunakan nilai DB mentah / 100 */
                        $dSum = $tg > 4000.0 ? ($tg / 100.0) : max($tg, $glpg + $glng);
                    }
                    $faktorGas = ($dSum > 0 && $dSum <= 30.0) ? 100.0 : 1.0; /* digit kecil ≤30 = ratio ×100 */
                    $tgNew = $dSum * $faktorGas;
                    if ($tg > 4000.0) {
                        $rev = $tg / 100.0;
                        if (abs($rev - $tgNew) < ($tgNew * 0.5)) $tgNew = $rev;
                    }
                    if ($tgNew > 3000.0) $tgNew = min(3000.0, $tg / 100.0, $tg / 10.0); /* cap gas ≤3.000 kg/hari */
                    if (abs($tgNew - $tg) > ($tg * 0.1) || ($tg <= 0.001 && $tgNew > 0.001)) {
                        $tg = $tgNew;
                        $changed = true;
                    }
                }
            }

            /* --- (D) FIX FUEL (SOLAR) BARU 2026-09-12 --- */
            $tf = (float)($c['tf'] ?? 0);
            $tfu = (float)($c['tfu'] ?? 0); if ($tfu <= 0) $tfu = (float)$TARIF_FUEL;
            $eq = @json_decode((string)($c['eq_json'] ?? ''), true);
            $fuelFromEq = 0.0;
            if (is_array($eq) && isset($eq['genset']) && is_array($eq['genset'])) {
                $fuelFromEq = (float)($eq['genset']['konsumsi_fuel_liter'] ?? 0);
            }
            if ($fuelFromEq <= 0) {
                /* Fallback legacy: coba cari di _POST (tidak ada di DB) → gunakan nilai default 0, kecuali DB total_fuel sangat aneh */
            }
            if ($fuelFromEq > 0 && ($tf <= 0.01 || $tf > 9000.0)) {
                $tfNew = $fuelFromEq;
                if ($tf > 9000.0) {
                    $rev = $tf / 10.0;
                    if (abs($rev - $fuelFromEq) < ($fuelFromEq * 0.5)) $tfNew = $rev;
                    else $tfNew = min(8000.0, $tf / 10.0);
                }
                if ($tfNew > 8000.0) $tfNew = 8000.0; /* cap fuel ≤8.000 L/hari */
                if (abs($tfNew - $tf) > 0.001 || ($tf <= 0.01 && $tfNew > 0.01)) {
                    $tf = $tfNew;
                    $changed = true;
                }
            }

            /* --- JIKA ADA YANG BERUBAH → UPDATE DB --- */
            if ($changed) {
                $db->update('daily_logs', [
                    'total_electricity'      => $te,
                    'tariff_electricity_per_kwh' => $tel,
                    'total_water'            => $tw,
                    'tariff_water_per_m3'    => $twa,
                    'total_gas'              => $tg,
                    'tariff_gas_per_kg'      => $tga,
                    'total_fuel'             => $tf,
                    'tariff_fuel_per_liter'  => $tfu,
                ], 'id = ?', [$cid]);
            }
        }
    } catch (Throwable $e) {
        /* Jangan ganggu render kalo auto-fix gagal (misal DB lock) */
        error_log('repAutoFixUtilityFormulaLama (helper v2) ERROR: '.$e->getMessage());
    }
}
endif;
