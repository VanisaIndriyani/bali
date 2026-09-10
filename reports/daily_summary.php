<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
/* ✅ 2026-09-10 CACHE BUSTER PAKSA (report & auto correction utility formula DB) */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$db = Database::getInstance();
$user = currentUser();
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? 'engineer');
$userName = (string)($user['name'] ?? 'User');

/* ---------- 1. PARAMETER TANGGAL (RANGE ATAU SINGLE DATE) ---------- */
$reportDateFrom = null; $reportDateTo = null; $isRange = false;
$dateRaw = $_GET['date'] ?? '';
$fromRaw = $_GET['date_from'] ?? '';
$toRaw   = $_GET['date_to']   ?? '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fromRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$toRaw)) {
    $reportDateFrom = (string)$fromRaw;
    $reportDateTo   = (string)$toRaw;
    if (strtotime($reportDateFrom) > strtotime($reportDateTo)) {
        $tmp = $reportDateFrom; $reportDateFrom = $reportDateTo; $reportDateTo = $tmp;
    }
    $isRange = true;
    $reportDate = $reportDateTo;
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateRaw)) {
    $reportDate = $dateRaw;
    $reportDateFrom = $reportDate;
    $reportDateTo   = $reportDate;
} else {
    $reportDate = date('Y-m-d');
    $reportDateFrom = $reportDate;
    $reportDateTo   = $reportDate;
}
$reportDateObj = DateTime::createFromFormat('Y-m-d', $reportDate);
if ($isRange) {
    $fObj = DateTime::createFromFormat('Y-m-d', $reportDateFrom);
    $tObj = DateTime::createFromFormat('Y-m-d', $reportDateTo);
    $reportDateLabel = strtoupper(($fObj?$fObj->format('j F Y'):$reportDateFrom) . ' — ' . ($tObj?$tObj->format('j F Y'):$reportDateTo));
} else {
    $reportDateLabel = $reportDateObj ? strtoupper($reportDateObj->format('j F Y')) : strtoupper($reportDate);
}
$lySameDay = date('Y-m-d', strtotime($reportDate . ' -1 year'));
$qsRange = $isRange ? ('date_from='.urlencode($reportDateFrom).'&date_to='.urlencode($reportDateTo)) : ('date='.urlencode($reportDate));

/* ============================================================
 * 🔧 AUTO CORRECTION (Fix Data Lama Sebelum Formula ×8000 / ×10 DIFIX!)
 * ROOT CAUSE: Customer terlanjur SAVE form 1-6 September PAGI INI (sebelum patch commit 41e772a)
 *            → kolom total_electricity di-DB MASIH nyimpan SELISIH MENTAH (3.34 kWh),
 *               PADAHAL seharusnya = (0.57+2.77) × 8000 = 26.720 kWh (sesuai logsheet × faktor CT/PT)
 * SOLUSI: Sebelum report aggregate, SCAN SEMUA daily_logs di range tanggal.
 *         JIKA total_electricity kecil (<=500) PADAHAL reading WBP/LWBP ADA → UPDATE OTOMATIS × faktor!
 *         SAMA untuk AIR Main Building (total_water kecil tapi reading ada)
 * ============================================================ */
function repAutoFixUtilityFormulaLama(Database $db, $dateFrom, $dateTo, $TARIF_LISTRIK, $TARIF_AIR, $TARIF_GAS, $TARIF_FUEL) {
    static $lastRunCache = null;
    $cacheKey = $dateFrom.'__'.$dateTo;
    if ($lastRunCache === $cacheKey) return;
    $lastRunCache = $cacheKey;

    try {
        /* SCAN daily_logs KANDIDAT (total_electricity ≤ 500 PADAHAL reading meter WBP/LWBP ADA = pasti formula lama gak ×8000) */
        $candidates = $db->fetchAll("SELECT id, log_date, engineer_id, shift,
                COALESCE(electricity_wbp,0) ew, COALESCE(electricity_lwbp,0) el,
                COALESCE(electricity_wbp_yesterday,0) ewy, COALESCE(electricity_lwbp_yesterday,0) ely,
                COALESCE(water_main_building,0) wmb, COALESCE(water_main_building_yesterday,0) wmby,
                COALESCE(total_electricity,0) te, COALESCE(total_water,0) tw,
                COALESCE(NULLIF(tariff_electricity_per_kwh,0),0) tel, COALESCE(NULLIF(tariff_water_per_m3,0),0) twa,
                COALESCE(NULLIF(tariff_gas_per_kg,0),0) tga, COALESCE(NULLIF(tariff_fuel_per_liter,0),0) tfu,
                COALESCE(total_gas,0) tg, COALESCE(total_fuel,0) tf,
                COALESCE(water_pdam,0)+COALESCE(water_iki_gaban,0)+COALESCE(water_deepwell_1,0)+COALESCE(water_deepwell_2_brr,0)+
                COALESCE(water_deepwell_asean,0)+COALESCE(water_deepwell_lpb,0)+COALESCE(water_cooling_tower,0)+
                COALESCE(water_bottling,0)+COALESCE(water_irrigation,0) as others_water
             FROM daily_logs
             WHERE DATE(log_date) BETWEEN ? AND ?
               AND (
                   ( (electricity_wbp > 0 OR electricity_lwbp > 0) AND COALESCE(total_electricity,0) <= 500 )
                OR ( water_main_building > 0 AND COALESCE(total_water,0) <= 100 )
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

            if (($ew > 0 || $el > 0) && $te <= 500.0) {
                /* KETEMU KANDIDAT: reading ada, total_electricity kecil = ×8000 GAK KE-APPLY */
                /* 1) Coba pakai kolom _yesterday (jika simpan kemarin via hidden input form) */
                $pEw = $ewy; $pEl = $ely;
                /* 2) KALO _yesterday 0 → cari ROW SEBELUMNYA manual (engineer sama, tgl < log_date, order by tgl desc) */
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
                $faktor = ($dSum > 0 && $dSum <= 500.0) ? 8000.0 : 1.0;
                $teNew = $dSum * $faktor;
                if ($teNew > $te * 1.5 && $teNew > 0.001) {
                    $te = $teNew;
                    $changed = true;
                }
            }

            /* --- (B) FIX AIR MAIN BUILDING --- */
            $tw = (float)($c['tw'] ?? 0);
            $wmb = (float)($c['wmb'] ?? 0); $wmby = (float)($c['wmby'] ?? 0);
            $othersW = (float)($c['others_water'] ?? 0);
            $twa = (float)($c['twa'] ?? 0); if ($twa <= 0) $twa = (float)$TARIF_AIR;

            if ($wmb > 0 && ($tw - $othersW) <= 100.0) {
                $pWmb = $wmby;
                if ($pWmb <= 0) {
                    $prevR = $db->fetchOne("SELECT COALESCE(water_main_building,0) wmb
                                FROM daily_logs
                                WHERE engineer_id = ? AND DATE(log_date) < DATE(?)
                                ORDER BY log_date DESC, id DESC LIMIT 1",
                        [(int)($c['engineer_id'] ?? 0), (string)($c['log_date'] ?? date('Y-m-d'))]);
                    if ($prevR) {
                        if ($pWmb <= 0) $pWmb = (float)($prevR['wmb'] ?? 0);
                    }
                }
                $dWmb = ($wmb > 0 && $pWmb > 0 && $wmb > $pWmb) ? max(0.0, $wmb - $pWmb) : 0.0;
                $fWmb = ($dWmb > 0 && $dWmb <= 300.0) ? 10.0 : 1.0;
                $wmbConsNew = $dWmb * $fWmb;
                $twNew = $wmbConsNew + $othersW;
                if ($twNew > $tw * 1.2 && $twNew > 0.001) {
                    $tw = $twNew;
                    $changed = true;
                }
            }

            if ($changed) {
                $tg = (float)($c['tg'] ?? 0);
                $tf = (float)($c['tf'] ?? 0);
                $tga = (float)($c['tga'] ?? 0); if ($tga <= 0) $tga = (float)$TARIF_GAS;
                $tfu = (float)($c['tfu'] ?? 0); if ($tfu <= 0) $tfu = (float)$TARIF_FUEL;
                $db->update('daily_logs', [
                    'total_electricity'      => $te,
                    'tariff_electricity_per_kwh' => $tel,
                    'total_water'            => $tw,
                    'tariff_water_per_m3'    => $twa,
                    'total_gas'              => $tg,
                    'total_fuel'             => $tf,
                ], 'id = ?', [$cid]);
            }
        }
    } catch (Throwable $e) {
        /* Jangan ganggu render report kalo auto-fix gagal (misal DB lock) */
        error_log('repAutoFixUtilityFormulaLama ERROR: '.$e->getMessage());
    }
}
/* JALANKAN AUTO CORRECTION SEKARANG (hanya untuk mode SUM / report biasa, bukan mode debug) */
repAutoFixUtilityFormulaLama($db, $reportDateFrom, $reportDateTo, $TARIF_LISTRIK, $TARIF_AIR, $TARIF_GAS, $TARIF_FUEL);
/* ====================================================================== */

/* ---------- 2. HELPER ---------- */
function repFmtIndo($v, $dec = 2) { $v=(float)$v; return number_format($v, $dec, ',', '.'); }
function repFmtRupiah($v) { $v=(float)$v; if ($v==0) return '0'; return number_format($v, 0, ',', '.'); }
function repFmtPercent($v) { return repFmtIndo((float)$v, 1).'%'; }
function repStatusLabel($st) {
    if ($st==='complete'||$st==='completed') return 'Complete';
    if ($st==='in_progress'||$st==='progress') return 'In Progress';
    if ($st==='pending') return 'Pending';
    if ($st==='-') return '-';
    return ucfirst($st);
}
/* Heuristik status dari JUDUL ACTIVITY (100% SAMA INDEX.PHP LINE 321-324) */
function repActHeurStatus($title) {
    $t = trim((string)$title);
    if (strlen($t) < 1) return 'complete';
    $tl = strtolower($t);
    $isProg = (strpos($tl,'progress')!==false) || (strpos($tl,'install')!==false)
           || (strpos($tl,'perbaikan')!==false) || (strpos($tl,'perbaika')!==false) || (strpos($tl,'new ')!==false)
           || (strpos($tl,'buat')!==false) || (strpos($tl,'meeting')!==false) || (strpos($tl,'pemindahan')!==false)
           || (strpos($tl,'follow up')!==false) || (strpos($tl,'refinising')!==false) || (strpos($tl,'rapikan')!==false)
           || (strpos($tl,'project ')!==false);
    return $isProg ? 'progress' : 'complete';
}

/* ---------- 3. TARIF ---------- */
$_tariff = getTariffSettings();
$TARIF_LISTRIK = (int)($_tariff['electricity_per_kwh'] ?? 1850);
$TARIF_AIR     = (int)($_tariff['water_per_m3']        ?? 9600);
$TARIF_GAS     = (int)($_tariff['gas_per_kg']          ?? 24500);
$TARIF_FUEL    = (int)($_tariff['fuel_per_liter']      ?? 17450);

/* ============================================================
 * 🔗 HELPER BARU (COPY 100% DARI INDEX.PHP utilFetchBoth_Db):
 *    MERGE DATA UTILITY DARI daily_logs + energy_logs
 *    (Data user input di energy_logsheet.php disimpan ke energy_logs,
 *     SEBELUMNYA PDF HANYA baca daily_logs → banyak data HILANG!)
 * Aggregation: 'SUM' atau 'AVG'
 * Return: [elec, water, gas, fuel, cnt, cnt_en, cost_*]
 * ============================================================ */
function repUtilFetchBoth($db, $approvedWhereDaily, $userId, $userRole, $dateFrom, $dateTo, $agg = 'SUM', $fallbackTariffs = null) {
    $isSum = ($agg !== 'AVG');
    $aggFn = $isSum ? 'SUM' : 'AVG';
    $cntFn  = 'COUNT';
    if (!is_array($fallbackTariffs)) $fallbackTariffs = ['electricity_per_kwh'=>1850,'water_per_m3'=>9600,'gas_per_kg'=>24500,'fuel_per_liter'=>17450];
    $ftEL = (int)($fallbackTariffs['electricity_per_kwh'] ?? 1850);
    $ftWA = (int)($fallbackTariffs['water_per_m3']       ?? 9600);
    $ftGA = (int)($fallbackTariffs['gas_per_kg']         ?? 24500);
    $ftFU = (int)($fallbackTariffs['fuel_per_liter']     ?? 17450);

    /* --- (A) daily_logs (primary legacy) --- */
    /*   ✅ 2026-08-23 FIX DEDUP: user bisa mengisi logshet TANGGAL YANG SAMA berulang (multiple entries per day per engineer).
            Sebelumnya query SUM(total_xx) menjumlahkan SEMUA row → hasilnya DOUBLE (misal 2x input 385+375=760 m3).
            Solusi: DEDUP terlebih dahulu → HANYA AMBIL ROW TERAKHIR (created_at/id TERBESAR) per (DATE(log_date), engineer_id),
            baru aggregate hasil dedup tersebut.
       ✅ 2026-09-10 SINKRON DENGAN DASHBOARD INDEX.PHP: 
            - Dedup = per (DATE(log_date), engineer_id) BUKAN per DATE saja!
              Alasan: Kalau ada 2 engineer (misal shift pagi + supervisor) input tanggal YANG SAMA,
              keduanya HARUS di-SUM (tidak dibuang salah satu). SAMA PERSIS DENGAN dashboard index.php!
            - Fallback listrik × 8000 hanya jika cWbp+cLwbp < 500 (meteran reading CT/PT ratio). 
              Jika > 500 = user sudah input LANGSUNG kWh (sesuai logsheet), JANGAN dikali faktor. */
    try {
        $dedupWhere = "WHERE DATE(log_date) BETWEEN ? AND ? AND $approvedWhereDaily";
        $sqlD = "SELECT
            COALESCE($aggFn(CAST(dl.total_electricity AS DECIMAL(18,4))),0) as elec,
            COALESCE($aggFn(CAST(dl.total_water       AS DECIMAL(18,4))),0) as water,
            COALESCE($aggFn(CAST(dl.total_gas         AS DECIMAL(18,4))),0) as gas,
            COALESCE($aggFn(CAST(dl.total_fuel        AS DECIMAL(18,4))),0) as fuel,
            COALESCE($cntFn(dl.id),0) as cnt,
            COALESCE($aggFn(
                CAST(COALESCE(dl.total_electricity,0) AS DECIMAL(18,4))
                * CAST(COALESCE(NULLIF(dl.tariff_electricity_per_kwh,0), {$ftEL}) AS DECIMAL(18,4))
            ),0) as cost_elec,
            COALESCE($aggFn(
                CAST(COALESCE(dl.total_water,0) AS DECIMAL(18,4))
                * CAST(COALESCE(NULLIF(dl.tariff_water_per_m3,0),       {$ftWA}) AS DECIMAL(18,4))
            ),0) as cost_water,
            COALESCE($aggFn(
                CAST(COALESCE(dl.total_gas,0) AS DECIMAL(18,4))
                * CAST(COALESCE(NULLIF(dl.tariff_gas_per_kg,0),         {$ftGA}) AS DECIMAL(18,4))
            ),0) as cost_gas,
            COALESCE($aggFn(
                CAST(COALESCE(dl.total_fuel,0) AS DECIMAL(18,4))
                * CAST(COALESCE(NULLIF(dl.tariff_fuel_per_liter,0),     {$ftFU}) AS DECIMAL(18,4))
            ),0) as cost_fuel
        FROM daily_logs dl
        INNER JOIN (
            SELECT MAX(id) AS keep_id
            FROM daily_logs
            $dedupWhere
            GROUP BY DATE(log_date), engineer_id
        ) k ON k.keep_id = dl.id";
        $d = $db->fetchOne($sqlD, [$dateFrom, $dateTo]);

        /* --- (A-FALLBACK) Jika total_electricity / total_water aggregate-nya 0 → hitung manual dari reading meter per tanggal!
           Hanya aktif untuk SUM aggregate (harian / periode range single-date report).
           ✅ 2026-08-22 TAMBAHAN: Untuk SINGLE DATE (mis. LY same date = 19 Agu 2025 SAJA),
              fallback tidak bisa bekerja karena BUTUH kemarin sebagai baseline.
              Solusi: jika dateFrom == dateTo → EXTEND query ke BELAKANG 1 HARI lagi agar ada prev reading!
           ✅ 2026-08-23: FALLBACK juga DEDUP! Hanya pertahankan row TERAKHIR per (tgl, engineer) sebelum hitung selisih.
           ✅ 2026-09-06 SESUAI LOGSHEET:
              - Dedup fallback = per (DATE, engineer_id). SAMA PERSIS query utama!
              - Faktor ×8000 listrik HANYA jika total selisih WBP+LWBP < 500 (angka reading meter CT/PT).
              - Jika selisih > 500 → user sudah input langsung SELISIH kWh (sesuai logsheet), JANGAN ×8000!
              - ✅ 2026-09-10: Hitung per ENGINEER_ID (bukan global per tgl), biar jika ada 2 engineer masing2 punya baseline sendiri, tidak ketuker baseline nya! */
        if ($isSum && ((float)($d['elec'] ?? 0) < 0.00001 || (float)($d['water'] ?? 0) < 0.00001)) {
            $needElec = ((float)($d['elec'] ?? 0) < 0.00001);
            $needWater = ((float)($d['water'] ?? 0) < 0.00001);
            if ($needElec || $needWater) {
                $fbFrom = $dateFrom;
                $fbTo   = $dateTo;
                if ($fbFrom === $fbTo) {
                    $fbFrom = date('Y-m-d', strtotime($fbFrom . ' -1 day'));
                }
                $rowsRead = $db->fetchAll("SELECT dl.id, DATE(dl.log_date) AS tgl, dl.engineer_id, dl.shift,
                                                  COALESCE(dl.electricity_wbp,0) AS ew, COALESCE(dl.electricity_lwbp,0) AS el,
                                                  COALESCE(dl.water_main_building,0) AS wmb,
                                                  COALESCE(dl.tariff_electricity_per_kwh,0) AS tel, COALESCE(dl.tariff_water_per_m3,0) AS tw
                                           FROM daily_logs dl
                                           INNER JOIN (
                                               SELECT MAX(id) AS keep_id
                                               FROM daily_logs
                                               WHERE DATE(log_date) BETWEEN ? AND ? AND $approvedWhereDaily
                                               GROUP BY DATE(log_date), engineer_id
                                           ) k ON k.keep_id = dl.id
                                           ORDER BY dl.engineer_id ASC, dl.log_date ASC, dl.id ASC",
                    [$fbFrom, $fbTo]
                );
                $manElec = 0.0; $manWater = 0.0; $manCostElec = 0.0; $manCostWater = 0.0;
                $lastMbByEng = []; $lastWbpByEng = []; $lastLwbpByEng = [];
                foreach ($rowsRead as $rr) {
                    $eid = (int)($rr['engineer_id'] ?? 0);
                    $tmb = (float)($rr['wmb'] ?? 0);
                    $twbp = (float)($rr['ew'] ?? 0);
                    $tlwbp = (float)($rr['el'] ?? 0);
                    $tel = (float)($rr['tel'] ?? 0); if ($tel <= 0) $tel = (float)$ftEL;
                    $tw =  (float)($rr['tw']  ?? 0); if ($tw  <= 0) $tw  = (float)$ftWA;
                    if ($needWater && $tmb > 0) {
                        $prevMb = $lastMbByEng[$eid] ?? null;
                        if ($prevMb !== null && $tmb > $prevMb) {
                            $c = max(0.0, $tmb - $prevMb);
                            $f = ($c > 0 && $c <= 300.0) ? 10.0 : 1.0;
                            $c = $c * $f;
                            $manWater += $c;
                            $manCostWater += $c * $tw;
                        }
                        $lastMbByEng[$eid] = $tmb;
                    }
                    if ($needElec && ($twbp > 0 || $tlwbp > 0)) {
                        $prevWbp = $lastWbpByEng[$eid] ?? null;
                        $prevLwbp = $lastLwbpByEng[$eid] ?? null;
                        if ($prevWbp !== null && $prevLwbp !== null) {
                            $cWbp = max(0.0, $twbp - $prevWbp);
                            $cLwbp = max(0.0, $tlwbp - $prevLwbp);
                            $cSum = $cWbp + $cLwbp;
                            if ($cSum <= 500.0) {
                                $c = $cSum * 8000.0;
                            } else {
                                $c = $cSum;
                            }
                            $manElec += $c;
                            $manCostElec += $c * $tel;
                        }
                        if ($twbp > 0)  $lastWbpByEng[$eid]  = $twbp;
                        if ($tlwbp > 0) $lastLwbpByEng[$eid] = $tlwbp;
                    }
                }
                if ($needElec  && $manElec  > 0) { $d['elec'] = (float)($d['elec'] ?? 0) + $manElec;  $d['cost_elec']  = (float)($d['cost_elec']  ?? 0) + $manCostElec;  }
                if ($needWater && $manWater > 0) { $d['water'] = (float)($d['water'] ?? 0) + $manWater; $d['cost_water'] = (float)($d['cost_water'] ?? 0) + $manCostWater; }
                unset($rowsRead, $fbFrom, $fbTo, $manElec, $manWater, $manCostElec, $manCostWater, $lastMbByEng, $lastWbpByEng, $lastLwbpByEng, $rr, $eid, $tmb, $twbp, $tlwbp, $tel, $tw, $prevMb, $prevWbp, $prevLwbp, $c, $cWbp, $cLwbp, $cSum, $f, $dedupWhere);
            }
        }

    } catch (Throwable $e) {
        $d = ['elec'=>0,'water'=>0,'gas'=>0,'fuel'=>0,'cnt'=>0,'cost_elec'=>0,'cost_water'=>0,'cost_gas'=>0,'cost_fuel'=>0];
        error_log('repUtilFetchBoth daily_logs ERROR: '.$e->getMessage());
    }
    if (!$d) $d = ['elec'=>0,'water'=>0,'gas'=>0,'fuel'=>0,'cnt'=>0,'cost_elec'=>0,'cost_water'=>0,'cost_gas'=>0,'cost_fuel'=>0];
    $d['elec']=(float)($d['elec']??0); $d['water']=(float)($d['water']??0);
    $d['gas']=(float)($d['gas']??0); $d['fuel']=(float)($d['fuel']??0);
    $d['cnt']=(int)($d['cnt']??0);
    $d['cost_elec']=(float)($d['cost_elec']??0); $d['cost_water']=(float)($d['cost_water']??0);
    $d['cost_gas']=(float)($d['cost_gas']??0); $d['cost_fuel']=(float)($d['cost_fuel']??0);

    /* --- (B) energy_logs (energy_logsheet.php input user) - TIDAK ADA per-log tariff, pakai global fallback --- */
    /*   ✅ 2026-08-23: SAMA DENGAN daily_logs → DEDUP MAX(id) per (DATE, created_by) untuk hindari double SUM jika user isi berkali-kali sehari
       ✅ 2026-09-10 SINKRON INDEX.PHP: Dedup per (DATE(log_date), created_by). JANGAN per DATE saja! Kalau per DATE saja = created_by kedua (user lain) di hari yang sama HILANG! */
    try {
        $wE = ["DATE(log_date) BETWEEN ? AND ?"];
        $pE = [$dateFrom, $dateTo];
        if ($userRole === 'engineer') {
            $wE[] = "created_by = ?";
            $pE[] = $userId;
        }
        $dedupWhereE = 'WHERE ' . implode(' AND ', $wE);
        $sqlE = "SELECT
            COALESCE($aggFn(CAST(el.pln_lwbp_kwh AS DECIMAL(18,4)) + CAST(el.pln_wbp_kwh AS DECIMAL(18,4)) + CAST(COALESCE(el.genset_kwh,0) AS DECIMAL(18,4))),0)  as elec,
            COALESCE($aggFn(CAST(COALESCE(el.air_m3,0) AS DECIMAL(18,4)) + CAST(COALESCE(el.air_deep_well_m3,0) AS DECIMAL(18,4))),0)                as water,
            COALESCE($aggFn(CAST(COALESCE(el.gas_kg,0) AS DECIMAL(18,4)) + CAST(COALESCE(el.gas_lng_kg,0) AS DECIMAL(18,4))),0)                      as gas,
            COALESCE($aggFn(CAST(COALESCE(el.solar_liter,0) AS DECIMAL(18,4))),0)                               as fuel,
            COALESCE($cntFn(el.id),0) as cnt,
            COALESCE($aggFn(
                (CAST(el.pln_lwbp_kwh AS DECIMAL(18,4)) + CAST(el.pln_wbp_kwh AS DECIMAL(18,4)) + CAST(COALESCE(el.genset_kwh,0) AS DECIMAL(18,4)))
                * {$ftEL}
            ),0) as cost_elec,
            COALESCE($aggFn(
                (CAST(COALESCE(el.air_m3,0) AS DECIMAL(18,4)) + CAST(COALESCE(el.air_deep_well_m3,0) AS DECIMAL(18,4)))
                * {$ftWA}
            ),0) as cost_water,
            COALESCE($aggFn(
                (CAST(COALESCE(el.gas_kg,0) AS DECIMAL(18,4)) + CAST(COALESCE(el.gas_lng_kg,0) AS DECIMAL(18,4)))
                * {$ftGA}
            ),0) as cost_gas,
            COALESCE($aggFn(
                CAST(COALESCE(el.solar_liter,0) AS DECIMAL(18,4))
                * {$ftFU}
            ),0) as cost_fuel
        FROM energy_logs el
        INNER JOIN (
            SELECT MAX(id) AS keep_id
            FROM energy_logs
            $dedupWhereE
            GROUP BY DATE(log_date), created_by
        ) kE ON kE.keep_id = el.id";
        $e = $db->fetchOne($sqlE, $pE);
    } catch (Throwable $e) {
        $e = ['elec'=>0,'water'=>0,'gas'=>0,'fuel'=>0,'cnt'=>0,'cost_elec'=>0,'cost_water'=>0,'cost_gas'=>0,'cost_fuel'=>0];
        error_log('repUtilFetchBoth energy_logs ERROR: '.$e->getMessage());
    }
    if (!$e) $e = ['elec'=>0,'water'=>0,'gas'=>0,'fuel'=>0,'cnt'=>0,'cost_elec'=>0,'cost_water'=>0,'cost_gas'=>0,'cost_fuel'=>0];
    $e['elec']=(float)($e['elec']??0); $e['water']=(float)($e['water']??0);
    $e['gas']=(float)($e['gas']??0); $e['fuel']=(float)($e['fuel']??0);
    $e['cnt']=(int)($e['cnt']??0);
    $e['cost_elec']=(float)($e['cost_elec']??0); $e['cost_water']=(float)($e['cost_water']??0);
    $e['cost_gas']=(float)($e['cost_gas']??0); $e['cost_fuel']=(float)($e['cost_fuel']??0);

    /* --- (C) MERGE: PRIORITY DAILY_LOGS (hindari DOUBLE COUNT karena user bisa input di 2 form!) --- */
    /*     RULE FIX 2026-08-18 (Bug Air 59651):
             - JIKA daily_logs SUDAH PUNYA NILAI NONZERO (total_xx > 0) = ambil HANYA DARI daily_logs (karena dihitung Today-Yesterday akurat)
             - JIKA daily_logs = 0 / TIDAK ADA DATA = baru pakai energy_logs (user hanya input di energy_logsheet)
             - INI JAUH LEBIH AMAN, menghindari kasus user input METER READING (bukan selisih) di energy_logsheet → TIDAK BOLEH dijumlah!
             - Berlaku untuk Elec, Water, Gas, Fuel BESERTA COST masing-masing (cost juga pakai aturan sama persis) */
    if ($isSum) {
        $pickD_elec = ($d['elec']  > 0.00001);
        $pickD_water = ($d['water'] > 0.00001);
        $pickD_gas = ($d['gas']   > 0.00001);
        $pickD_fuel = ($d['fuel']  > 0.00001);
        $out = [
            'elec'  => $pickD_elec  ? (float)$d['elec']  : (float)($d['elec']  + $e['elec']),
            'water' => $pickD_water ? (float)$d['water'] : (float)($d['water'] + $e['water']),
            'gas'   => $pickD_gas   ? (float)$d['gas']   : (float)($d['gas']   + $e['gas']),
            'fuel'  => $pickD_fuel  ? (float)$d['fuel']  : (float)($d['fuel']  + $e['fuel']),
            'cnt'   => (int)($d['cnt']   + $e['cnt']),
            'cnt_d' => (int)$d['cnt'],
            'cnt_e' => (int)$e['cnt'],
            'cost_elec'  => $pickD_elec  ? (float)$d['cost_elec']  : (float)($d['cost_elec']  + $e['cost_elec']),
            'cost_water' => $pickD_water ? (float)$d['cost_water'] : (float)($d['cost_water'] + $e['cost_water']),
            'cost_gas'   => $pickD_gas   ? (float)$d['cost_gas']   : (float)($d['cost_gas']   + $e['cost_gas']),
            'cost_fuel'  => $pickD_fuel  ? (float)$d['cost_fuel']  : (float)($d['cost_fuel']  + $e['cost_fuel']),
        ];
        $out['_prio_elec']  = $pickD_elec  ? 'daily_logs' : 'merged_or_energy';
        $out['_prio_water'] = $pickD_water ? 'daily_logs' : 'merged_or_energy';
        $out['_prio_gas']   = $pickD_gas   ? 'daily_logs' : 'merged_or_energy';
        $out['_prio_fuel']  = $pickD_fuel  ? 'daily_logs' : 'merged_or_energy';
    } else {
        $pickDaily = ($d['elec'] > 0 || $d['water'] > 0 || $d['gas'] > 0 || $d['fuel'] > 0);
        $s = $pickDaily ? $d : $e;
        $out = [
            'elec'  => (float)$s['elec'],
            'water' => (float)$s['water'],
            'gas'   => (float)$s['gas'],
            'fuel'  => (float)$s['fuel'],
            'cnt'   => (int)$s['cnt'],
            'cnt_d' => (int)$d['cnt'],
            'cnt_e' => (int)$e['cnt'],
            'cost_elec'  => (float)$s['cost_elec'],
            'cost_water' => (float)$s['cost_water'],
            'cost_gas'   => (float)$s['cost_gas'],
            'cost_fuel'  => (float)$s['cost_fuel'],
        ];
    }
    /* --- (D) VALIDASI ANTI READING METER BULANAN / DATA SEBELUM SISTEM JALAN --- */
    /*     RULE FIX LY 2026-08-26 (SINKRON DENGAN INDEX.PHP — PERBAIKI BUG BACKFILL 2025 TIDAK MUNCUL):
             - cnt_d > 0 = ADA ROW daily_logs approved → INI PASTI BACKFILL ENGINEER ASLI 2025, TIDAK BOLEH di-NOL-kan!
             - cnt_d === 0 = TIDAK ADA daily_logs sama sekali → baru nol-kan HANYA energy_logs (merged_or_energy).
             - untuk AVG mode: pickDaily=true → data dari daily_logs, jadi DILINDUNGI juga. */
    $_utilSysCutoff = '2026-01-01';
    $_utilIsPreSystem = (strtotime((string)$dateTo) < strtotime($_utilSysCutoff));
    $_cntD_now = (int)($out['cnt_d'] ?? 0);
    if ($_cntD_now === 0 && $_utilIsPreSystem) {
        /* SUM mode: nol-kan hasil merge per-utility JIKA source-nya bukan daily_logs (merged_or_energy = energy_logs). */
        if ($isSum) {
            if (!($pickD_elec ?? true)) { $out['elec'] = 0; $out['cost_elec'] = 0; }
            if (!($pickD_water ?? true)) { $out['water'] = 0; $out['cost_water'] = 0; }
            if (!($pickD_gas ?? true)) { $out['gas'] = 0; $out['cost_gas'] = 0; }
            if (!($pickD_fuel ?? true)) { $out['fuel'] = 0; $out['cost_fuel'] = 0; }
            $_allPickedFromE = (!($pickD_elec ?? true) && !($pickD_water ?? true) && !($pickD_gas ?? true) && !($pickD_fuel ?? true));
            if ($_allPickedFromE) {
                $out['elec'] = 0; $out['water'] = 0; $out['gas'] = 0; $out['fuel'] = 0;
                $out['cost_elec'] = 0; $out['cost_water'] = 0; $out['cost_gas'] = 0; $out['cost_fuel'] = 0;
            }
            unset($_allPickedFromE);
        } else {
            /* AVG mode: pickDaily = false = pakai energy_logs → nol-kan. */
            if (!($pickDaily ?? true)) {
                $out['elec'] = 0; $out['water'] = 0; $out['gas'] = 0; $out['fuel'] = 0;
                $out['cost_elec'] = 0; $out['cost_water'] = 0; $out['cost_gas'] = 0; $out['cost_fuel'] = 0;
            }
        }
        $out['_skip_reason'] = 'cnt_d_zero_energy_logs_meter_reading_skipped (per-utility selective, sync with index.php)';
    }
    unset($_utilSysCutoff, $_utilIsPreSystem, $_cntD_now);
    $out['log_count'] = max(1, (int)($out['cnt'] ?? 1));

    /* ✅ 2026-09-06 SAFETY CAP (SESUAI LOGSHEET):
       Cegah angka tidak wajar per hari (hasil dari meter reading dijumlah langsung,
       fallback ×8000 salah double, atau dedup per-engineer sum ganda).
       Jika melebihi cap per-hari × jumlah hari → SCALE DOWN otomatis (cari faktor koreksi
       yang paling masuk akal). Cost juga di-scale agar ratio biar tetap konsisten. */
    if ($isSum) {
        $daysDiff = 1;
        if ($dateFrom !== null && $dateTo !== null && $dateFrom !== '' && $dateTo !== '') {
            $tsF = @strtotime((string)$dateFrom);
            $tsT = @strtotime((string)$dateTo);
            if ($tsF !== false && $tsT !== false) {
                $daysDiff = max(1, (int)floor(($tsT - $tsF) / 86400) + 1);
            }
        }
        /* CAP WAJAR per HARI (villa / resort 50+ kamar):
           - Listrik ≤ 40.000 kWh/hari (rata-rata villa besar: 5.000 - 25.000 kWh/hari)
           - Air     ≤ 800  m³/hari
           - Gas     ≤ 3.000 kg/hari
           - Solar   ≤ 8.000 liter/hari */
        $capPerDay = ['elec'=>40000.0, 'water'=>800.0, 'gas'=>3000.0, 'fuel'=>8000.0];
        $utilKeys = ['elec','water','gas','fuel'];
        foreach ($utilKeys as $_uk) {
            $valNow = (float)($out[$_uk] ?? 0);
            if ($valNow <= 0) continue;
            $capNow = $capPerDay[$_uk] * (float)$daysDiff;
            if ($valNow > $capNow) {
                /* Coba koreksi: kemungkinan faktor ×8000 salah terpakai → bagi 8000,
                   atau kemungkinan meter reading → cari faktor terdekat yang bikin di dalam cap. */
                $faktorCandidates = [8000.0, 1000.0, 100.0, 10.0, 2.0];
                $bestVal = $valNow; $bestDiff = INF; $bestF = 1.0;
                foreach ($faktorCandidates as $_f) {
                    $v = $valNow / $_f;
                    if ($v <= $capNow && $v > 0) {
                        $_d = abs($v - ($capNow * 0.35));
                        if ($_d < $bestDiff) { $bestDiff = $_d; $bestVal = $v; $bestF = $_f; }
                    }
                }
                if ($bestF > 1.0) {
                    $out[$_uk] = $bestVal;
                    if (isset($out['cost_'.$_uk])) {
                        $out['cost_'.$_uk] = (float)($out['cost_'.$_uk] ?? 0) / $bestF;
                    }
                }
            }
        }
        unset($capPerDay, $utilKeys, $_uk, $valNow, $capNow, $faktorCandidates, $bestVal, $bestDiff, $bestF, $_f, $v, $_d, $daysDiff, $tsF, $tsT);
    }

    return $out;
}

/* ============================================================
 * 🔗 HELPER BARU: repUtilFetchDetail
 *    Mengembalikan RINCIAN per sub-utility:
 *      elec_wbp, elec_lwbp, elec_total, water_mb, water_total,
 *      gas_lpg, gas_lng, gas_total, fuel
 *    BESERTA cost masing-masing.
 *    Pola 100% sama dengan repUtilFetchBoth: dedup, merge priority daily_logs > energy_logs,
 *    fallback meter-reading diff untuk WBP/LWBP/MB, validasi pre-system cutoff.
 * ============================================================ */
function repUtilFetchDetail($db, $approvedWhereDaily, $userId, $userRole, $dateFrom, $dateTo, $agg = 'SUM', $fallbackTariffs = null) {
    $isSum = ($agg !== 'AVG');
    if (!is_array($fallbackTariffs)) $fallbackTariffs = ['electricity_per_kwh'=>1850,'water_per_m3'=>9600,'gas_per_kg'=>24500,'fuel_per_liter'=>17450];
    $ftEL = (int)($fallbackTariffs['electricity_per_kwh'] ?? 1850);
    $ftWA = (int)($fallbackTariffs['water_per_m3']       ?? 9600);
    $ftGA = (int)($fallbackTariffs['gas_per_kg']         ?? 24500);
    $ftFU = (int)($fallbackTariffs['fuel_per_liter']     ?? 17450);
    $aggFn = $isSum ? 'SUM' : 'AVG';
    $cntFn  = 'COUNT';

    $d = ['ewbp_raw'=>0,'elwbp_raw'=>0,'wmb_raw'=>0,'glpg_raw'=>0,'glng_raw'=>0,'fuel_raw'=>0,
          'ewbp_c'=>0,'elwbp_c'=>0,'wmb_c'=>0,'glpg_c'=>0,'glng_c'=>0,'fuel_c'=>0,'cnt_d'=>0];
    try {
        $dedupWhere = "WHERE DATE(log_date) BETWEEN ? AND ? AND $approvedWhereDaily";
        $sqlD = "SELECT
            COALESCE($aggFn(CAST(dl.electricity_wbp AS DECIMAL(18,4))),0) as ewbp_raw,
            COALESCE($aggFn(CAST(dl.electricity_lwbp AS DECIMAL(18,4))),0) as elwbp_raw,
            COALESCE($aggFn(CAST(dl.water_main_building AS DECIMAL(18,4))),0) as wmb_raw,
            COALESCE($aggFn(CAST(COALESCE(dl.gas_lpg,0) AS DECIMAL(18,4))),0) as glpg_raw,
            COALESCE($aggFn(CAST(COALESCE(dl.gas_lng,0) AS DECIMAL(18,4))),0) as glng_raw,
            COALESCE($aggFn(CAST(COALESCE(dl.total_fuel,0) AS DECIMAL(18,4))),0) as fuel_raw,
            COALESCE($cntFn(dl.id),0) as cnt
        FROM daily_logs dl
        INNER JOIN (
            SELECT MAX(id) AS keep_id
            FROM daily_logs
            $dedupWhere
            GROUP BY DATE(log_date)
        ) k ON k.keep_id = dl.id";
        $rowD = $db->fetchOne($sqlD, [$dateFrom, $dateTo]);
        $d['cnt_d'] = (int)($rowD['cnt'] ?? 0);
        $d['glpg_c'] = (float)($rowD['glpg_raw'] ?? 0);
        $d['glng_c'] = (float)($rowD['glng_raw'] ?? 0);
        $d['fuel_c'] = (float)($rowD['fuel_raw'] ?? 0);

        if ($isSum) {
            $fbFrom = $dateFrom; $fbTo = $dateTo;
            if ($fbFrom === $fbTo) { $fbFrom = date('Y-m-d', strtotime($fbFrom . ' -1 day')); }
            $rowsRead = $db->fetchAll("SELECT dl.id, DATE(dl.log_date) AS tgl, dl.engineer_id, dl.shift,
                                              COALESCE(dl.electricity_wbp,0) AS ew, COALESCE(dl.electricity_lwbp,0) AS el,
                                              COALESCE(dl.water_main_building,0) AS wmb
                                       FROM daily_logs dl
                                       INNER JOIN (
                                           SELECT MAX(id) AS keep_id
                                           FROM daily_logs
                                           WHERE DATE(log_date) BETWEEN ? AND ? AND $approvedWhereDaily
                                           GROUP BY DATE(log_date)
                                       ) k ON k.keep_id = dl.id
                                       ORDER BY dl.log_date ASC, dl.id ASC",
                [$fbFrom, $fbTo]
            );
            $manEw = 0.0; $manEl = 0.0; $manWmb = 0.0;
            $lastEw = null; $lastEl = null; $lastWmb = null;
            foreach ($rowsRead as $rr) {
                $rew = (float)($rr['ew'] ?? 0);
                $rel = (float)($rr['el'] ?? 0);
                $rwmb = (float)($rr['wmb'] ?? 0);
                $tgl = (string)($rr['tgl'] ?? '');
                $inRange = ($tgl >= $dateFrom && $tgl <= $dateTo);
                if ($rew > 0) {
                    if ($lastEw !== null && $rew > $lastEw && $inRange) {
                        $diff = max(0.0, $rew - $lastEw);
                        $manEw += ($diff <= 500.0) ? ($diff * 8000.0) : $diff;
                    }
                    $lastEw = $rew;
                }
                if ($rel > 0) {
                    if ($lastEl !== null && $rel > $lastEl && $inRange) {
                        $diff = max(0.0, $rel - $lastEl);
                        $manEl += ($diff <= 500.0) ? ($diff * 8000.0) : $diff;
                    }
                    $lastEl = $rel;
                }
                if ($rwmb > 0) {
                    if ($lastWmb !== null && $rwmb > $lastWmb && $inRange) {
                        $manWmb += max(0.0, $rwmb - $lastWmb);
                    }
                    $lastWmb = $rwmb;
                }
            }
            $d['ewbp_c'] = $manEw;
            $d['elwbp_c'] = $manEl;
            $d['wmb_c'] = $manWmb;
            unset($rowsRead, $fbFrom, $fbTo, $manEw, $manEl, $manWmb, $lastEw, $lastEl, $lastWmb, $rr, $rew, $rel, $rwmb, $diff);
        }
    } catch (Throwable $e) {
        error_log('repUtilFetchDetail daily_logs ERROR: '.$e->getMessage());
    }

    $e = ['ewbp_c'=>0,'elwbp_c'=>0,'wmb_c'=>0,'glpg_c'=>0,'glng_c'=>0,'fuel_c'=>0,'cnt_e'=>0];
    try {
        $wE = ["DATE(log_date) BETWEEN ? AND ?"];
        $pE = [$dateFrom, $dateTo];
        if ($userRole === 'engineer') { $wE[] = "created_by = ?"; $pE[] = $userId; }
        $dedupWhereE = 'WHERE ' . implode(' AND ', $wE);
        $sqlE = "SELECT
            COALESCE($aggFn(CAST(el.pln_wbp_kwh AS DECIMAL(18,4))),0) as ewbp_c,
            COALESCE($aggFn(CAST(el.pln_lwbp_kwh AS DECIMAL(18,4))),0) as elwbp_c,
            COALESCE($aggFn(CAST(COALESCE(el.air_m3,0) AS DECIMAL(18,4))),0) as wmb_c,
            COALESCE($aggFn(CAST(COALESCE(el.gas_kg,0) AS DECIMAL(18,4))),0) as glpg_c,
            COALESCE($aggFn(CAST(COALESCE(el.gas_lng_kg,0) AS DECIMAL(18,4))),0) as glng_c,
            COALESCE($aggFn(CAST(COALESCE(el.solar_liter,0) AS DECIMAL(18,4))),0) as fuel_c,
            COALESCE($cntFn(el.id),0) as cnt
        FROM energy_logs el
        INNER JOIN (
            SELECT MAX(id) AS keep_id
            FROM energy_logs
            $dedupWhereE
            GROUP BY DATE(log_date)
        ) kE ON kE.keep_id = el.id";
        $rowE = $db->fetchOne($sqlE, $pE);
        $e['cnt_e'] = (int)($rowE['cnt'] ?? 0);
        $e['ewbp_c']  = (float)($rowE['ewbp_c'] ?? 0);
        $e['elwbp_c'] = (float)($rowE['elwbp_c'] ?? 0);
        $e['wmb_c']   = (float)($rowE['wmb_c'] ?? 0);
        $e['glpg_c']  = (float)($rowE['glpg_c'] ?? 0);
        $e['glng_c']  = (float)($rowE['glng_c'] ?? 0);
        $e['fuel_c']  = (float)($rowE['fuel_c'] ?? 0);
    } catch (Throwable $e) {
        error_log('repUtilFetchDetail energy_logs ERROR: '.$e->getMessage());
    }

    $_utilSysCutoff = '2026-01-01';
    $_utilIsPreSystem = (strtotime((string)$dateTo) < strtotime($_utilSysCutoff));
    $_cntD_detail = (int)($d['cnt_d'] ?? 0);
    if ($isSum) {
        $pickD_ewbp = ($d['ewbp_c'] > 0.00001);
        $pickD_elwbp = ($d['elwbp_c'] > 0.00001);
        $pickD_wmb = ($d['wmb_c'] > 0.00001);
        $pickD_glpg = ($d['glpg_c'] > 0.00001);
        $pickD_glng = ($d['glng_c'] > 0.00001);
        $pickD_fuel = ($d['fuel_c'] > 0.00001);
        /* ✅ FIX 2026-08-26 SINKRON INDEX.PHP:
           JIKA ADA daily_logs row (cnt_d > 0) → LINDUNGI data daily_logs, JANGAN pernah reset ke 0!
           Reset HANYA jika cnt_d === 0 (benar-benar TIDAK ADA daily_logs) dan tanggal pre-2026
           → dalam hal itu skip merge: paksa pakai nilai D (nol) SAJA dan jangan pakai energy_logs! */
        if ($_cntD_detail === 0 && $_utilIsPreSystem) {
            $pickD_ewbp = true; $pickD_elwbp = true; $pickD_wmb = true;
            $pickD_glpg = true; $pickD_glng = true; $pickD_fuel = true;
            /* Paksa nilai E (energy_logs) jadi 0 biar tidak ikut ter-merge D+E */
            $e['ewbp_c']=$e['elwbp_c']=$e['wmb_c']=$e['glpg_c']=$e['glng_c']=$e['fuel_c']=0;
        }
        $ewbp  = $pickD_ewbp  ? (float)$d['ewbp_c']  : (float)($d['ewbp_c']  + $e['ewbp_c']);
        $elwbp = $pickD_elwbp ? (float)$d['elwbp_c'] : (float)($d['elwbp_c'] + $e['elwbp_c']);
        $wmb   = $pickD_wmb   ? (float)$d['wmb_c']   : (float)($d['wmb_c']   + $e['wmb_c']);
        $glpg  = $pickD_glpg  ? (float)$d['glpg_c']  : (float)($d['glpg_c']  + $e['glpg_c']);
        $glng  = $pickD_glng  ? (float)$d['glng_c']  : (float)($d['glng_c']  + $e['glng_c']);
        $fuel  = $pickD_fuel  ? (float)$d['fuel_c']  : (float)($d['fuel_c']  + $e['fuel_c']);
    } else {
        $pickDaily = ($d['ewbp_c'] > 0 || $d['elwbp_c'] > 0 || $d['wmb_c'] > 0 || $d['glpg_c'] > 0 || $d['glng_c'] > 0 || $d['fuel_c'] > 0);
        $s = $pickDaily ? $d : $e;
        $ewbp = (float)$s['ewbp_c']; $elwbp = (float)$s['elwbp_c']; $wmb = (float)$s['wmb_c'];
        $glpg = (float)$s['glpg_c']; $glng = (float)$s['glng_c']; $fuel = (float)$s['fuel_c'];
    }
    unset($_utilSysCutoff, $_utilIsPreSystem);

    $elecTotal = $ewbp + $elwbp;
    $gasTotal = $glpg + $glng;
    $waterTotal = $wmb;
    return [
        'ewbp' => $ewbp, 'elwbp' => $elwbp, 'elec_total' => $elecTotal,
        'wmb' => $wmb, 'water_total' => $waterTotal,
        'glpg' => $glpg, 'glng' => $glng, 'gas_total' => $gasTotal,
        'fuel' => $fuel,
        'cost_ewbp' => $ewbp * $ftEL, 'cost_elwbp' => $elwbp * $ftEL, 'cost_elec_total' => $elecTotal * $ftEL,
        'cost_wmb' => $wmb * $ftWA, 'cost_water_total' => $waterTotal * $ftWA,
        'cost_glpg' => $glpg * $ftGA, 'cost_glng' => $glng * $ftGA, 'cost_gas_total' => $gasTotal * $ftGA,
        'cost_fuel' => $fuel * $ftFU,
    ];
}

function repRenderVarPct($todayVal, $lyVal, $unit = '', $dec = 0, $isCost = false) {
    $tv = (float)$todayVal; $lv = (float)$lyVal;
    $variance = $tv - $lv;
    if ($lv > 0.00001) { $pct = ($variance / $lv) * 100; }
    else { $pct = 0; }
    $vAbs = abs($variance);
    $arrow = ''; $varClass = 'text-slate-600'; $pctClass = 'text-slate-600';
    if ($variance > 0.00001) { $arrow = '&uarr;'; $varClass = 'text-slate-700'; $pctClass = 'text-slate-700'; }
    elseif ($variance < -0.00001) { $arrow = '&darr;'; $varClass = 'text-slate-500'; $pctClass = 'text-slate-500'; }
    if ($isCost) {
        $varStr = $arrow . ' Rp ' . repFmtRupiah($vAbs);
    } else {
        $varStr = $arrow . ' ' . repFmtIndo($vAbs, $dec) . ($unit !== '' ? ' '.$unit : '');
    }
    if ($variance < -0.00001) { $varStr = str_replace('&uarr; ', '', $varStr); $varStr = str_replace('&darr; ', '&darr; ', $varStr); }
    $pctStr = ($lv <= 0.00001) ? '&mdash;' : repFmtIndo(abs($pct), 1).'%';
    $out = '<td class="num util-num '.$varClass.' font-mono" style="text-align:right; vertical-align:middle;">'.$varStr.'</td>';
    $out .= '<td class="num util-num '.$pctClass.' font-mono" style="text-align:right; vertical-align:middle;">'.$pctStr.'</td>';
    return $out;
}

/* ---------- 4. DATA UTILITY (WRAP TRY/CATCH SUPAYA TABLE TIDAK ADA = TIDAK FATAL ERROR) — SUPPORT RANGE DATE ---------- */
$elecToday = $waterToday = $gasToday = $fuelToday = 0;
$elecLY = $waterLY = $gasLY = $fuelLY = 0;
$costElecToday = $costWaterToday = $costGasToday = $costFuelToday = 0;
$costElecLY = $costWaterLY = $costGasLY = $costFuelLY = 0;

$detToday = ['ewbp'=>0,'elwbp'=>0,'elec_total'=>0,'wmb'=>0,'water_total'=>0,'glpg'=>0,'glng'=>0,'gas_total'=>0,'fuel'=>0,
             'cost_ewbp'=>0,'cost_elwbp'=>0,'cost_elec_total'=>0,'cost_wmb'=>0,'cost_water_total'=>0,'cost_glpg'=>0,'cost_glng'=>0,'cost_gas_total'=>0,'cost_fuel'=>0];
$detLY = $detToday;

// ✅ 2026-09-10 SINKRONISASI DENGAN DASHBOARD INDEX.PHP (SAMA PERSIS DEFINISI):
//    DASHBOARD INDEX.PHP line 449-457:
//    LY    = SINGLE DATE TANGGAL SAMA TAHUN LALU DARI tanggal TERAKHIR REPORT → reportDateTo - 1 tahun
//          (BUKAN range 1-7 Sept 2025, tapi HANYA 8 Sept 2025 SAJA = sesuai label "LY • Avg/Day")
//    TODAY = SINGLE DATE tanggal TERAKHIR SAJA (reportDateTo)
//          (BUKAN range 7-8 Sept 2026, tapi HANYA 8 Sept 2026 SAJA = sesuai label TODAY di dashboard card!)
//    * Ini yang menyebabkan PRINT / PDF angkanya BEDA 2x LIPAT / KURANG, karena dulu dikasih range 2 hari.
$_utilSingleDateToday = $reportDateTo;                                   // TODAY = tgl terakhir (8 Sept 2026)
$_utilSingleDateLY    = date('Y-m-d', strtotime($reportDateTo . ' -1 year')); // LY    = tgl terakhir - 1 thn (8 Sept 2025)

try {
    // ✅ BARU: PAKAI repUtilFetchBoth() = MERGE daily_logs + energy_logs
    //    SEBELUMNYA: HANYA baca daily_logs → data input di energy_logsheet.php (energy_logs) TIDAK KEBACA di PDF
    //    SEKARANG: 100% SAMA PERSIS dengan dashboard index.php utilFetchBoth_Db()
    $_approvedWhere = "status = 'approved'";
    if ($userRole === 'engineer') { $_approvedWhere .= " AND engineer_id = $userId"; }
    $_todayWhere = $_approvedWhere;
    if (in_array($userRole, ['manager', 'supervisor', 'admin'], true)) {
        $_todayWhere = "status IN ('approved','pending')";
    }
    $_tariffFb = ['electricity_per_kwh'=>$TARIF_LISTRIK,'water_per_m3'=>$TARIF_AIR,'gas_per_kg'=>$TARIF_GAS,'fuel_per_liter'=>$TARIF_FUEL];

    $sumToday = repUtilFetchBoth($db, $_todayWhere, $userId, $userRole, $_utilSingleDateToday, $_utilSingleDateToday, 'SUM', $_tariffFb);
    $elecToday  = (float)($sumToday['elec']  ?? 0);
    $waterToday = (float)($sumToday['water'] ?? 0);
    $gasToday   = (float)($sumToday['gas']   ?? 0);
    $fuelToday  = (float)($sumToday['fuel']  ?? 0);
    // ✅ COST: pakai dari helper merge (SUDAH dihitung SQL pakai tarif snapshot per-log + step D validasi cnt_d=0)
    //    ❌ SEBELUMNYA: perkalian MANUAL $elecToday * $TARIF_LISTRIK → tidak sinkron step D, tidak akurat kalo tarif snapshot beda!
    $costElecToday  = (float)($sumToday['cost_elec']  ?? 0);
    $costWaterToday = (float)($sumToday['cost_water'] ?? 0);
    $costGasToday   = (float)($sumToday['cost_gas']   ?? 0);
    $costFuelToday  = (float)($sumToday['cost_fuel']  ?? 0);

    // ✅ QUERY LY: SINGLE DATE (lySameDay) — JUGA PAKAI MERGE (100% sama dashboard)
    //    Sesuai permintaan user WA: "tanggal tahun kemarin ketemu tanggal hari ini"
    //    Contoh: report 7-8/9/26 → LY = tgl 8/9/25 (1 data), BUKAN rata-rata / sum 7-8/9/25.
    $sumLY = repUtilFetchBoth($db, $_approvedWhere, $userId, $userRole, $_utilSingleDateLY, $_utilSingleDateLY, 'SUM', $_tariffFb);
    $elecLY  = (float)($sumLY['elec']  ?? 0);
    $waterLY = (float)($sumLY['water'] ?? 0);
    $gasLY   = (float)($sumLY['gas']   ?? 0);
    $fuelLY  = (float)($sumLY['fuel']  ?? 0);
    $costElecLY  = (float)($sumLY['cost_elec']  ?? 0);
    $costWaterLY = (float)($sumLY['cost_water'] ?? 0);
    $costGasLY   = (float)($sumLY['cost_gas']   ?? 0);
    $costFuelLY  = (float)($sumLY['cost_fuel']  ?? 0);

    $_dt = repUtilFetchDetail($db, $_todayWhere, $userId, $userRole, $_utilSingleDateToday, $_utilSingleDateToday, 'SUM', $_tariffFb);
    if (is_array($_dt)) {
        foreach ($detToday as $k => $v) { if (isset($_dt[$k])) $detToday[$k] = (float)($_dt[$k]); }
    }
    $_dl = repUtilFetchDetail($db, $_approvedWhere, $userId, $userRole, $_utilSingleDateLY, $_utilSingleDateLY, 'SUM', $_tariffFb);
    if (is_array($_dl)) {
        foreach ($detLY as $k => $v) { if (isset($_dl[$k])) $detLY[$k] = (float)($_dl[$k]); }
    }
    unset($_dt, $_dl);

    if ($detToday['elec_total'] < 0.00001 && $elecToday > 0.00001) {
        $detToday['elec_total'] = $elecToday;
        $detToday['cost_elec_total'] = $costElecToday;
    }
    if ($detToday['water_total'] < 0.00001 && $waterToday > 0.00001) {
        $detToday['wmb'] = $waterToday; $detToday['water_total'] = $waterToday;
        $detToday['cost_wmb'] = $costWaterToday; $detToday['cost_water_total'] = $costWaterToday;
    }
    if ($detToday['gas_total'] < 0.00001 && $gasToday > 0.00001) {
        $detToday['gas_total'] = $gasToday; $detToday['cost_gas_total'] = $costGasToday;
    }
    if ($detToday['fuel'] < 0.00001 && $fuelToday > 0.00001) {
        $detToday['fuel'] = $fuelToday; $detToday['cost_fuel'] = $costFuelToday;
    }
    if ($detLY['elec_total'] < 0.00001 && $elecLY > 0.00001) {
        $detLY['elec_total'] = $elecLY; $detLY['cost_elec_total'] = $costElecLY;
    }
    if ($detLY['water_total'] < 0.00001 && $waterLY > 0.00001) {
        $detLY['wmb'] = $waterLY; $detLY['water_total'] = $waterLY;
        $detLY['cost_wmb'] = $costWaterLY; $detLY['cost_water_total'] = $costWaterLY;
    }
    if ($detLY['gas_total'] < 0.00001 && $gasLY > 0.00001) {
        $detLY['gas_total'] = $gasLY; $detLY['cost_gas_total'] = $costGasLY;
    }
    if ($detLY['fuel'] < 0.00001 && $fuelLY > 0.00001) {
        $detLY['fuel'] = $fuelLY; $detLY['cost_fuel'] = $costFuelLY;
    }
} catch (Exception $e) {
    error_log('daily_summary utility MERGE ERROR: '.$e->getMessage());
    /* utility kosong - biarkan 0 */
}

/* ---------- 5. KPI OCCUPANCY + ITR + M&U + GITB (RANGE DATE SUPPORT) ---------- */
$kpiData = [['Occupancy Rate','- %','- %','-','-','-']];
try {
    $defaultLyOcc = 70; $defaultTargetOcc = 80;
    $occLYRow = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE log_date BETWEEN ? AND ? AND status = 'approved' AND occ_rate > 0", [$_utilSingleDateLY, $_utilSingleDateLY]);
    $occReportRow = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE log_date BETWEEN ? AND ? AND status = 'approved' AND occ_rate > 0", [$_utilSingleDateToday, $_utilSingleDateToday]);
    $lyOcc = (($occLYRow['cnt'] ?? 0) > 0) ? round((float)($occLYRow['avg_occ'] ?? 0), 0) : $defaultLyOcc;
    $rangeOccAvg = (($occReportRow['cnt'] ?? 0) > 0) ? round((float)($occReportRow['avg_occ'] ?? 0), 0) : 0;
    if ($rangeOccAvg > 0) {
        $targetOcc = $rangeOccAvg;
    } else {
        $avgMonthNow = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE DATE_FORMAT(log_date,'%Y-%m') = ? AND status = 'approved' AND occ_rate > 0", [date('Y-m', strtotime($reportDateTo))]);
        if (($avgMonthNow['cnt'] ?? 0) > 0) $targetOcc = round((float)($avgMonthNow['avg_occ'] ?? 0), 0);
        else $targetOcc = $defaultTargetOcc;
    }
    $kpiItr = $lyOcc > 0 ? number_format(85 + ($targetOcc - $lyOcc) * 0.2, 1, '.', '') : '-';
    $kpiMnU = $lyOcc > 0 ? number_format(78 + (($targetOcc - $lyOcc) * 0.3), 1, '.', '') : '-';
    $kpiRank = $targetOcc >= 90 ? '5' : ($targetOcc >= 80 ? '4' : ($targetOcc >= 70 ? '3' : '-'));
    $kpiData = [
        ['Occupancy Rate', repFmtPercent($lyOcc), repFmtPercent($targetOcc), $kpiItr, $kpiMnU, $kpiRank],
    ];
} catch (Exception $e) { /* KPI tetap isi default - */ }

/* ---------- 6. ENGINEERING ACTIVITIES PER DIVISI (COPY VERBATIM 100% DARI DASHBOARD_ACTIVITIES_PDF.PHP YANG SUDAH TERBUKTI WORKS) -------------- */
$divisions = ['PROJECT', 'OPERATION', 'MAINTENANCE', 'LANDSCAPE'];
$divUpperMap = ['project'=>'PROJECT','operation'=>'OPERATION','maintenance'=>'MAINTENANCE','landscape'=>'LANDSCAPE'];
$actByDiv = [];
foreach ($divisions as $d) $actByDiv[$d] = [];

/* ✅ 2026-09-10: RULE BARU ACTIVITIES (Req user):
 * 1. BERLAKU PER BULAN (bukan sesuai tanggal range laporan)
 *    → Activity daily_log_activities & JSON items: QUERY 1 BULAN PENUH (bulan dari report_date_to)
 *    → Master activity (activity_masters): YANG STATUS != complete MUNCUL TANPA BATAS TANGGAL (selama masih in progress)
 * 2. HANYA YANG MASIH IN PROGRESS / BELUM DI-COMPLETE yang ditampilkan di report & dashboard print
 *    → Semua yang status 'complete'/'completed' DI FILTER OUT (dibuang, tidak muncul!)
 * 3. Activity yg dibuat bulan lalu (misal Agustus) tapi status masih In Progress → TETAP MUNCUL bulan September!
 * ------------------------------------------------------------------ */
$_actBulananKey = date('Y-m', strtotime($reportDateTo));
$_actBulananStart = $_actBulananKey . '-01';
$_actBulananEnd   = date('Y-m-t', strtotime($_actBulananStart));
function __actFilterProgressOnly(&$list) {
    if (!is_array($list) || count($list) === 0) return [];
    $out = [];
    foreach ($list as $r) {
        if (!is_array($r)) continue;
        $s = mb_strtolower(trim((string)($r['status'] ?? 'progress')));
        if ($s === 'complete' || $s === 'completed') continue; // BUANG YANG SUDAH SELESAI!
        $out[] = $r;
    }
    return $out;
}
/* ------------------------------------------------------------------ */

/* --- (A) FUNCTION COPIED VERBATIM FROM dashboard_activities_pdf.php (suffix _Ds = DailySummary) --- */
function buildActivityListQuery_Ds($db, $userRole, $userId, $category, $dateFrom, $dateTo, $limit = 500) {
    global $_actBulananStart, $_actBulananEnd;
    $baseWhere = "WHERE dla.category = ? AND dl.status = 'approved' AND dl.log_date BETWEEN ? AND ?";
    /* ✅ 2026-09-10 Rule Baru: pakai RANGE 1 BULAN PENUH, bukan dateFrom laporan (yg cuma 1-2 hari) */
    $params = [$category, $_actBulananStart, $_actBulananEnd];
    if ($userRole === 'engineer') {
        $baseWhere .= " AND dl.engineer_id = ?";
        $params[] = $userId;
    }
    /* ✅ 2026-09-06 FIX BY ENG: Prioritas nama engineer:
       1. dla.created_by (user yang BENAR-BENAR input baris activity ini - bisa manager/supervisor/engineer)
       2. fallback ke dl.engineer_id (engineer pemilik daily_log parent) jika created_by NULL/0 */
    $sql = "SELECT dla.id, dla.activity_title, DATE(dl.log_date) as log_date,
                   COALESCE(NULLIF(uc.name,''), u.name) as engineer_name
            FROM daily_log_activities dla
            INNER JOIN daily_logs dl ON dl.id = dla.daily_log_id
            LEFT JOIN users u ON u.id = dl.engineer_id
            LEFT JOIN users uc ON uc.id = dla.created_by
            $baseWhere
            ORDER BY dl.log_date DESC, dla.sort_order ASC, dla.id DESC
            LIMIT $limit";
    return $db->fetchAll($sql, $params);
}
function actGroupWithStatus_Ds($list) {
    $out = [];
    foreach ($list as $r) {
        $t = trim((string)($r['activity_title'] ?? ''));
        if (strlen($t) < 1) continue;
        $tl = strtolower($t);
        $isProg = (strpos($tl, 'progress') !== false) || (strpos($tl, 'install') !== false)
               || (strpos($tl, 'perbaikan') !== false) || (strpos($tl, 'perbaika') !== false) || (strpos($tl, 'new ') !== false)
               || (strpos($tl, 'buat') !== false) || (strpos($tl, 'meeting') !== false)
               || (strpos($tl, 'pemindahan') !== false) || (strpos($tl, 'follow up') !== false)
               || (strpos($tl, 'refinising') !== false) || (strpos($tl, 'rapikan') !== false)
               || (strpos($tl, 'project ') !== false);
        $out[] = ['title'=>$t, 'status'=>$isProg ? 'progress' : 'complete',
                  'date'=>$r['log_date'] ?? '', 'eng'=>$r['engineer_name'] ?? ''];
    }
    return $out;
}

/* --- (B) QUERY 4 DIVISI VERBATIM --- */
try {
    $_actsGRP_Ds = [
        'project'     => actGroupWithStatus_Ds(buildActivityListQuery_Ds($db, $userRole, $userId, 'project',     $reportDateFrom, $reportDateTo)),
        'operation'   => actGroupWithStatus_Ds(buildActivityListQuery_Ds($db, $userRole, $userId, 'operation',   $reportDateFrom, $reportDateTo)),
        'maintenance' => actGroupWithStatus_Ds(buildActivityListQuery_Ds($db, $userRole, $userId, 'maintenance', $reportDateFrom, $reportDateTo)),
        'landscape'   => actGroupWithStatus_Ds(buildActivityListQuery_Ds($db, $userRole, $userId, 'landscape',   $reportDateFrom, $reportDateTo)),
    ];

    /* ✅ STEP PERTAMA: FILTER HANYA IN PROGRESS SAJA (complete → dibuang!) sebelum merge JSON & master */
    foreach ($_actsGRP_Ds as $_kG => &$_vG) {
        $_vG = __actFilterProgressOnly($_vG);
    }
    unset($_kG, $_vG);

    /* --- (B-EXTRA) MERGE DATA activity_*_items JSON DARI daily_logs (sumber data manager/activities.php) ---
       TANPA INI: Aktivitas yang diinput Manager di halaman activities.php TIDAK MUNCUL di report, karena tersimpan di
       kolom JSON activity_operation_items (bukan child table daily_log_activities). */
    $_roleWhereDs = '';
    $_actParamsDs = [];
    if ($userRole === 'engineer') {
        $_roleWhereDs = ' AND dl.engineer_id = ?';
        $_actParamsDs[] = $userId;
    }
    $_actColMapDs = [
        'project'     => 'activity_project_items',
        'operation'   => 'activity_operation_items',
        'maintenance' => 'activity_maintenance_items',
        'landscape'   => 'activity_landscape_items',
    ];
    $_jsonTitleUsedDs = [];
    foreach ($_actColMapDs as $_dvDs => $_colDs) {
        if (!isset($_actsGRP_Ds[$_dvDs]) || !is_array($_actsGRP_Ds[$_dvDs])) $_actsGRP_Ds[$_dvDs] = [];
        foreach ($_actsGRP_Ds[$_dvDs] as $_rDs) {
            $_kDs = mb_strtolower(trim((string)($_rDs['title'] ?? '')));
            if ($_kDs !== '') $_jsonTitleUsedDs[$_dvDs][$_kDs] = true;
        }
    }
    foreach ($_actColMapDs as $_dvDs => $_colDs) {
        $_sqlDs = "SELECT dl.id as dlid, dl.log_date, u.name as engineer_name, dl.$_colDs as json_col
                    FROM daily_logs dl
                    LEFT JOIN users u ON u.id = dl.engineer_id
                    WHERE dl.status='approved' $_roleWhereDs
                      AND dl.log_date BETWEEN ? AND ?
                      AND dl.$_colDs IS NOT NULL AND dl.$_colDs <> ''
                    ORDER BY dl.log_date DESC, dl.id DESC";
        /* ✅ 2026-09-10 Rule Baru: pakai RANGE 1 BULAN PENUH untuk JSON items */
        $_rowsDs = $db->fetchAll($_sqlDs, array_merge($_actParamsDs, [$_actBulananStart, $_actBulananEnd]));
        foreach ($_rowsDs as $_raDs) {
            $_rawJsonDs = (string)($_raDs['json_col'] ?? '');
            if ($_rawJsonDs === '') continue;
            $_arrActDs = json_decode($_rawJsonDs, true);
            if (!is_array($_arrActDs) || count($_arrActDs) === 0) continue;
            foreach ($_arrActDs as $_iaDs) {
                if (!is_array($_iaDs)) continue;
                $_ttlDs = trim((string)($_iaDs['t'] ?? ''));
                if ($_ttlDs === '') continue;
                $_stDs  = (string)($_iaDs['s'] ?? 'progress');
                $_keyLowerDs = mb_strtolower($_ttlDs);
                if (isset($_jsonTitleUsedDs[$_dvDs][$_keyLowerDs])) continue; // hindari dobel
                $_jsonTitleUsedDs[$_dvDs][$_keyLowerDs] = true;
                // Status rule = heuristik (sama dengan actGroupWithStatus_Ds)
                $_tlDs = mb_strtolower($_ttlDs);
                $_isProgDs = ($_stDs === 'progress')
                        || (strpos($_tlDs, 'progress') !== false) || (strpos($_tlDs, 'install') !== false)
                        || (strpos($_tlDs, 'perbaikan') !== false) || (strpos($_tlDs, 'new ') !== false)
                        || (strpos($_tlDs, 'buat') !== false)     || (strpos($_tlDs, 'meeting') !== false)
                        || (strpos($_tlDs, 'pemindahan') !== false) || (strpos($_tlDs, 'follow up') !== false)
                        || (strpos($_tlDs, 'refinising') !== false) || (strpos($_tlDs, 'rapikan') !== false)
                        || (strpos($_tlDs, 'project ') !== false);
                /* ✅ HANYA IN PROGRESS SAJA YANG MASUK (rule baru #2) */
                if (!$_isProgDs) continue;
                /* ✅ 2026-09-06 FIX BY ENG per-item JSON:
                   1. $_iaDs['un'] = nama user yang disimpan langsung di JSON saat input (Manager/Supervisor/Engineer)
                   2. $_iaDs['u']  = user id, fallback join ke users manual via extra lookup nanti
                   3. fallback ke engineer_name (engineer_id parent log) jika keduanya tidak ada (data lama) */
                $_engPerItemDs = '';
                if (!empty($_iaDs['un'])) {
                    $_engPerItemDs = trim((string)$_iaDs['un']);
                } elseif (!empty($_iaDs['u'])) {
                    $_uidDs = (int)$_iaDs['u'];
                    if ($_uidDs > 0) {
                        static $_uCacheDs = [];
                        if (!isset($_uCacheDs[$_uidDs])) {
                            $_uRowDs = $db->fetchOne("SELECT name FROM users WHERE id = ? LIMIT 1", [$_uidDs]);
                            $_uCacheDs[$_uidDs] = !empty($_uRowDs['name']) ? (string)$_uRowDs['name'] : '';
                        }
                        $_engPerItemDs = $_uCacheDs[$_uidDs];
                    }
                }
                if ($_engPerItemDs === '') {
                    $_engPerItemDs = (string)($_raDs['engineer_name'] ?? '-');
                }
                $_actsGRP_Ds[$_dvDs][] = [
                    'title'  => $_ttlDs,
                    'status' => 'progress',
                    'date'   => (string)($_raDs['log_date'] ?? ''),
                    'eng'    => $_engPerItemDs,
                ];
            }
        }
    }
    unset($_rowsDs, $_raDs, $_rawJsonDs, $_arrActDs, $_iaDs, $_ttlDs, $_stDs, $_keyLowerDs, $_tlDs, $_isProgDs, $_engPerItemDs, $_uCacheDs, $_uidDs, $_uRowDs);
    unset($_roleWhereDs, $_actParamsDs, $_actColMapDs, $_dvDs, $_colDs, $_sqlDs, $_jsonTitleUsedDs, $_rDs, $_kDs);

    /* --- (C) MERGE MASTER ACTIVITIES — RULE BARU:
     * ✅ 2026-09-10 JIKA STATUS != complete (yaitu progress/in_progress/new/pending) → MUNCUL TANPA BATAS TANGGAL
     *        (meskipun dibuat bulan Agustus, selama belum complete → muncul di laporan September!)
     *    HANYA master activity dengan status_default = complete → yang dibuang (tidak muncul). */
    $_mWhereDs = [];
    try {
        $_tmpM_Ds = $db->fetchAll("SELECT am.division, am.activity_name, am.sort_order, am.created_at, am.status_default,
                                      u.name as created_by_name
                               FROM activity_masters am
                               LEFT JOIN users u ON u.id = am.created_by
                               WHERE (am.status_default IS NULL OR LOWER(COALESCE(am.status_default,'progress')) NOT IN ('complete','completed'))
                               ORDER BY FIELD(am.division,'project','operation','maintenance','landscape'), am.sort_order ASC, am.id ASC");
    } catch (Throwable $_e) {
        /* untuk sistem lama (tanpa status_default) sbg fallback */
        $_tmpM_Ds = $db->fetchAll("SELECT am.division, am.activity_name, am.sort_order, am.created_at, am.status_default,
                                      u.name as created_by_name
                               FROM activity_masters am
                               LEFT JOIN users u ON u.id = am.created_by
                               ORDER BY FIELD(am.division,'project','operation','maintenance','landscape'), am.sort_order ASC, am.id ASC");
    }
    $_existT_Ds = [];
    foreach (['project','operation','maintenance','landscape'] as $dv) {
        if (!isset($_actsGRP_Ds[$dv]) || !is_array($_actsGRP_Ds[$dv])) $_actsGRP_Ds[$dv] = [];
        foreach ($_actsGRP_Ds[$dv] as $_r) {
            $t = mb_strtolower(trim((string)($_r['title'] ?? '')));
            if ($t !== '') $_existT_Ds[$dv][$t] = true;
        }
    }
    foreach ($_tmpM_Ds as $_m) {
        $dv = (string)($_m['division'] ?? 'operation');
        if (!in_array($dv,['project','operation','maintenance','landscape'], true)) $dv = 'operation';
        if (!isset($_actsGRP_Ds[$dv]) || !is_array($_actsGRP_Ds[$dv])) $_actsGRP_Ds[$dv] = [];
        $title = trim((string)($_m['activity_name'] ?? ''));
        if ($title === '') continue;
        $key = mb_strtolower($title);
        if (isset($_existT_Ds[$dv][$key])) continue;
        $st = mb_strtolower(trim((string)($_m['status_default'] ?? 'progress')));
        /* ✅ HANYA IN PROGRESS yang dimasukkan (sudah difilter di query WHERE, ini tambahan filter kedua!) */
        if ($st === 'complete' || $st === 'completed') continue;
        /* ✅ HANYA gunakan created_by_name yang BENAR dari pembuat master.
           JIKA KOSONG = tulis - (Master Activity).
           DILARANG KERAS fallback ke $user['name'] (nama user lagi login)!  */
        $_engLabel_Ds = !empty($_m['created_by_name']) ? (string)$_m['created_by_name'] : '- (Master Activity)';
        $_actsGRP_Ds[$dv][] = [
            'title'  => $title,
            'status' => 'progress',
            'date'   => substr((string)($_m['created_at'] ?? ''), 0, 10),
            'eng'    => $_engLabel_Ds
        ];
    }
    unset($_tmpM_Ds, $_existT_Ds, $dv, $_m, $title, $key, $st, $_engLabel_Ds, $_e);

    /* --- LAST FILTER (FINAL CHECK) PASTIKAN TIDAK ADA YANG STATUS COMPLETE LOLOS --- */
    foreach ($_actsGRP_Ds as $_kL => &$_vL) {
        $_vL = __actFilterProgressOnly($_vL);
    }
    unset($_kL, $_vL);

    /* --- (D) MAP lowercase-key → uppercase-key + ganti key 'title'→'name' SUPAYA KOMPATIBEL DENGAN RENDER CSV+HTML DI BAWAH (TIDAK PERLU UBAH RENDER!) --- */
    foreach ($divUpperMap as $lower => $upper) {
        $list = $_actsGRP_Ds[$lower] ?? [];
        foreach ($list as $item) {
            $actByDiv[$upper][] = [
                'name'   => (string)($item['title'] ?? ''),
                'status' => (string)($item['status'] ?? 'progress'),
                'date'   => (string)($item['date'] ?? ''),
                'eng'    => (string)($item['eng'] ?? '')
            ];
        }
    }
    unset($_actsGRP_Ds, $lower, $upper, $list, $item);
} catch (Throwable $e) { /* daily_log_activities / activity_masters table not exists → biarkan kosong */ }

/* Helper hitung status badge per-divisi (nanti dipakai CSV+HTML) */
function repCountActStatus(&$list) {
    $n = ['prog'=>0, 'done'=>0];
    foreach ($list as $r) {
        $s = (string)($r['status'] ?? 'progress');
        if ($s === 'complete' || $s === 'completed') $n['done']++;
        else $n['prog']++;
    }
    return $n;
}
function repFmtDateAct($d) {
    if (strlen((string)$d) < 8) return '';
    try { return (new DateTime($d))->format('d M Y'); } catch (Throwable $e) { return ''; }
}

/* ✨ Helper: Pisahkan tag "Master Activity" dari Nama Engineer, lalu return [$isMaster, $cleanEngName] */
function repSplitMasterEng($engRaw) {
    $s = trim((string)$engRaw);
    if ($s === '' || $s === '-') return [false, ''];
    // Cocok pola: - (Master Activity) / - (MASTER ACTIVITY) / dimulai dengan dash + ada kata "master activity"
    if (stripos($s, '(Master Activity)') !== false || stripos($s, '(MASTER ACTIVITY)') !== false || stripos($s, 'master activity') !== false) {
        return [true, ''];
    }
    return [false, $s];
}

/* ✨ Helper: GROUP Activity LIST PER TANGGAL (sort tanggal ASC). Return array [dateISO=>[items...]] */
function repGroupActsByDate(&$list) {
    $grp = [];
    foreach ($list as $idx => $it) {
        $dt = trim((string)($it['date'] ?? ''));
        if ($dt === '' || strlen($dt) < 8) $dt = '0000-00-00';
        if (!isset($grp[$dt]) || !is_array($grp[$dt])) $grp[$dt] = [];
        $grp[$dt][] = $it;
    }
    ksort($grp, SORT_STRING); // 2026-08-07 datang sebelum 2026-08-18 (ASC)
    $ordered = [];
    foreach ($grp as $k => $arr) {
        if ($k === '0000-00-00') {
            $ordered['_nodate'] = $arr; // taruh PALING AKHIR nanti
        } else {
            $ordered[$k] = $arr;
        }
    }
    if (isset($ordered['_nodate'])) {
        $x = $ordered['_nodate']; unset($ordered['_nodate']); $ordered['_nodate'] = $x;
    }
    return $ordered;
}

/* ---------- 7. RENDER MODE ---------- */
$format = isset($_GET['format']) ? strtolower(cleanInput($_GET['format'])) : 'print';
$fileName = $isRange ? ('Engineering_Report_' . $reportDateFrom . '_to_' . $reportDateTo) : ('Engineering_Report_' . $reportDate);

/* ---------- HELPER ESCAPE CSV ---------- */
function repCsvEscape($v) {
    $v = (string)$v;
    $v = str_replace(["\r\n","\r"], "\n", $v);
    $v = preg_replace('/\s+/u', ' ', $v);
    $v = trim($v);
    if (strpos($v, ',') !== false || strpos($v, ';') !== false || strpos($v, "\n") !== false
        || strpos($v, '"') !== false || strpos($v, '=') === 0 || strpos($v, '@') === 0) {
        return '"' . str_replace('"', '""', $v) . '"';
    }
    return $v;
}

if ($format === 'excel') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF";

    $sep = ';';
    $out = '';

    /* HEADER JUDUL LAPORAN (POLOS TANPA NAMA HOTEL!) */
    $out .= 'ENGINEERING REPORT' . $sep . $sep . $sep . $sep . $sep . "\n";
    $out .= 'DATE' . $sep . $reportDateLabel . $sep . $sep . $sep . $sep . $sep . "\n";
    $out .= "\n";

    /* 1. KPIs */
    $out .= '1. KEY PERFORMANCE INDICATORS (KPIs)' . "\n";
    $out .= repCsvEscape('METRIC') . $sep
          . repCsvEscape('LAST YEAR (LY)') . $sep
          . repCsvEscape('TODAY') . $sep
          . repCsvEscape('ITR') . $sep
          . repCsvEscape('M&U') . $sep
          . repCsvEscape('GITB RANK') . "\n";
    foreach ($kpiData as $r) {
        $out .= repCsvEscape($r[0]) . $sep
              . repCsvEscape($r[1]) . $sep
              . repCsvEscape($r[2]) . $sep
              . repCsvEscape($r[3]) . $sep
              . repCsvEscape($r[4]) . $sep
              . repCsvEscape($r[5]) . "\n";
    }
    $out .= "\n";

    /* 2. UTILITY USAGE SUMMARY */
    $out .= '2. UTILITY USAGE SUMMARY' . "\n";
    $out .= repCsvEscape('UTILITY') . $sep
          . repCsvEscape('PERIOD') . $sep
          . repCsvEscape('USAGE') . $sep
          . repCsvEscape('SELISIH') . $sep
          . repCsvEscape('COST (Rp.)') . "\n";

    $utilCsvRows = [
        ['ELECTRICITY', 'kWh',   $elecLY,   $elecToday,   $costElecLY,   $costElecToday],
        ['WATER',       'm3',    $waterLY,  $waterToday,  $costWaterLY,  $costWaterToday],
        ['GAS',         'kg',    $gasLY,    $gasToday,    $costGasLY,    $costGasToday],
        ['FUEL',        'Liter', $fuelLY,   $fuelToday,   $costFuelLY,   $costFuelToday],
    ];
    foreach ($utilCsvRows as $row) {
        list($name, $unit, $ly, $today, $costLY, $costToday) = $row;
        $usageLY = repFmtIndo($ly, 0) . ' ' . $unit;
        $usageToday = repFmtIndo($today, 0) . ' ' . $unit;
        $diffUsage = (float)$today - (float)$ly;
        $diffAbs = abs($diffUsage);
        if ($diffUsage > 0.00001) { $diffStr = '+ ' . repFmtIndo($diffAbs, 0) . ' ' . $unit; }
        elseif ($diffUsage < -0.00001) { $diffStr = '- ' . repFmtIndo($diffAbs, 0) . ' ' . $unit; }
        else { $diffStr = '0 ' . $unit; }
        $out .= repCsvEscape($name) . $sep . repCsvEscape('(LY)') . $sep
              . repCsvEscape($usageLY) . $sep . repCsvEscape('(baseline)') . $sep
              . repCsvEscape(repFmtRupiah($costLY)) . "\n";
        $out .= $sep . repCsvEscape('(TODAY)') . $sep
              . repCsvEscape($usageToday) . $sep . repCsvEscape($diffStr) . $sep
              . repCsvEscape(repFmtRupiah($costToday)) . "\n";
    }
    $out .= "\n";

    /* 3. ENGINEERING ACTIVITIES (100% MATCH DASHBOARD + counter per divisi) */
    $out .= '3. ENGINEERING ACTIVITIES' . "\n";
    $out .= repCsvEscape('DEPARTMENT') . $sep
          . repCsvEscape('ACTIVITY DETAIL') . $sep
          . repCsvEscape('STATUS') . "\n";
    foreach ($divisions as $d) {
        $list = $actByDiv[$d] ?? [];
        $stN = repCountActStatus($list);
        /* Baris 1 divisi: Dept + summary counter status */
        $stSummary = '';
        if (count($list) === 0) $stSummary = '- No Data -';
        else {
            if ($stN['prog'] > 0) $stSummary .= 'In Progress (' . $stN['prog'] . ')  ';
            if ($stN['done'] > 0) $stSummary .= 'Complete (' . $stN['done'] . ')';
            $stSummary = trim($stSummary);
        }
        if (count($list) === 0) {
            $out .= repCsvEscape($d) . $sep . repCsvEscape('Belum ada aktivitas bulan ini.') . $sep . repCsvEscape($stSummary) . "\n";
            continue;
        }
        // ✅ CSV juga PISAH GROUP Prog & Done (TIDAK KECAMPUR sama seperti tampilan web & dashboard)
        $listDoneCSV = []; $listProgCSV = [];
        foreach ($list as $itx) {
            $sx = (string)($itx['status'] ?? 'progress');
            if ($sx === 'complete' || $sx === 'completed') $listDoneCSV[] = $itx;
            else                                                $listProgCSV[] = $itx;
        }
        // Output BERJALAN DULU (sesuai urutan visual web), baru SELESAI, pakai header pemisah di detail
        $first = true;
        $flushedProgHeader = false; $flushedDoneHeader = false;
        if (count($listProgCSV) > 0) {
            foreach ($listProgCSV as $item) {
                $meta = [];
                if ($f = repFmtDateAct($item['date'] ?? '')) $meta[] = $f;
                if (trim((string)($item['eng'] ?? '')) !== '') $meta[] = (string)$item['eng'];
                $detail = (string)($item['name'] ?? '');
                if (count($meta) > 0) $detail .= '   [ ' . implode(' | ', $meta) . ' ]';
                $st = repStatusLabel($item['status'] ?? 'progress');
                if (!$flushedProgHeader) {
                    $out .= ($first ? repCsvEscape($d) . '  [' . $stSummary . ']' : '') . $sep
                          . repCsvEscape('▶▶ SEDANG BERJALAN (In Progress: ' . count($listProgCSV) . ')') . $sep
                          . repCsvEscape(($first ? $stSummary . '  |  ' : '') . '— GROUP —') . "\n";
                    $flushedProgHeader = true; $first = false;
                }
                $out .= ($first ? repCsvEscape($d) . '  [' . $stSummary . ']' : '') . $sep
                      . repCsvEscape('   • ' . $detail) . $sep
                      . repCsvEscape(($first ? $stSummary . '  |  ' : '') . $st) . "\n";
                $first = false;
            }
        }
        if (count($listDoneCSV) > 0) {
            foreach ($listDoneCSV as $item) {
                $meta = [];
                if ($f = repFmtDateAct($item['date'] ?? '')) $meta[] = $f;
                if (trim((string)($item['eng'] ?? '')) !== '') $meta[] = (string)$item['eng'];
                $detail = (string)($item['name'] ?? '');
                if (count($meta) > 0) $detail .= '   [ ' . implode(' | ', $meta) . ' ]';
                $st = repStatusLabel($item['status'] ?? 'progress');
                if (!$flushedDoneHeader) {
                    $out .= ($first ? repCsvEscape($d) . '  [' . $stSummary . ']' : '') . $sep
                          . repCsvEscape('■■ SELESAI / DONE (Complete: ' . count($listDoneCSV) . ')') . $sep
                          . repCsvEscape(($first ? $stSummary . '  |  ' : '') . '— GROUP —') . "\n";
                    $flushedDoneHeader = true; $first = false;
                }
                $out .= ($first ? repCsvEscape($d) . '  [' . $stSummary . ']' : '') . $sep
                      . repCsvEscape('   ✓ ' . $detail) . $sep
                      . repCsvEscape(($first ? $stSummary . '  |  ' : '') . $st) . "\n";
                $first = false;
            }
        }
    }
    $out .= "\n";

    echo $out;
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Engineering Report - <?=$reportDate?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
    * { box-sizing: border-box; }
    html, body { margin:0; padding:0; font-family:Arial,Helvetica,sans-serif; color:#000; background:#f3f4f6; font-size:14px; line-height:1.35; }
    @page { size: A4; margin: 14mm 12mm 12mm 12mm; }
    @media print {
        body { background:#fff; }
        .page-wrap { background:#fff!important; padding:0!important; margin:0!important; box-shadow:none!important; width:100%!important; max-width:100%!important; }
        .action-bar { display:none!important; }
        h1 { margin-top:0!important; }
        table { page-break-inside: avoid; }
    }
    .action-bar { max-width:210mm; margin:0 auto 12px; display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap; padding:0 8px; }
    .btn { display:inline-flex; align-items:center; gap:6px; padding:10px 16px; border-radius:10px; font-weight:700; font-size:13px; text-decoration:none; border:1px solid transparent; cursor:pointer; transition:all .15s; }
    .btn-excel { background:#16a34a; color:#fff; box-shadow:0 1px 2px rgba(22,163,74,.15); }
    .btn-excel:hover { background:#15803d; }
    .btn-pdf { background:#2563eb; color:#fff; box-shadow:0 1px 2px rgba(37,99,235,.15); }
    .btn-pdf:hover { background:#1d4ed8; }
    .page-wrap { width:210mm; max-width:100%; min-height:297mm; margin:0 auto; background:#fff; padding:10mm 12mm 12mm; box-shadow:0 6px 24px rgba(0,0,0,.08); }
    h1 { font-size:22px; letter-spacing:1.5px; text-align:center; margin:0 0 8px; font-weight:900; line-height:1.05; }
    .date-label { font-size:15px; font-weight:800; margin:0 0 14px; }
    h2 { font-size:15px; font-weight:900; margin:12px 0 6px; letter-spacing:.5px; }
    table { width:100%; border-collapse:collapse; }
    th { background:#d9d9d9; border:1px solid #000; padding:6px 8px; font-weight:800; text-align:center; font-size:12px; }
    td { border:1px solid #000; padding:5px 8px; font-size:12px; vertical-align:top; color:#000; }
    td.num { text-align:right; font-variant-numeric: tabular-nums; }
    td.cen { text-align:center; }
    td.bold { font-weight:800; }
    td.mid { vertical-align: middle; }
    ul.dot { margin:0; padding-left:14px; }
    ul.dot li { margin:0; padding:0; line-height:1.3; }
    .sign-footer { width:100%; margin-top:18px; display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; page-break-inside:avoid; }
    .sign-box { text-align:center; font-size:12px; }
    .sign-box .lbl { font-weight:600; margin-bottom:46px; }
    .sign-box .line { border-top:1px solid #000; padding-top:5px; font-weight:800; }
    /* ===== ENGINEERING ACTIVITIES (TABEL 5 KOLOM, SAMA PERSIS DASHBOARD INDEX.PHP) ===== */
    table.act { border:1px solid #cbd5e1; border-radius:10px; overflow:hidden; border-collapse: separate; border-spacing: 0;}
    table.act th { background:#f1f5f9; text-align:left; font-weight:900; letter-spacing:.08em; padding:10px 12px; font-size:11px; color:#0f172a; border-bottom:2px solid #cbd5e1; border-right:1px solid #cbd5e1;}
    table.act th:last-child { border-right:none;}
    table.act th.dept-col { width: 17%; text-align:left;}
    table.act th.act-col { width: 43%; text-align:left;}
    table.act th.date-col { width: 12%; text-align:center;}
    table.act th.eng-col { width: 18%; text-align:left;}
    table.act th.status-col { width: 10%; text-align:center;}
    table.act td { padding: 8px 10px; vertical-align:top; font-size: 12px; color:#0f172a; border-bottom:1px solid #e2e8f0; border-right:1px solid #e2e8f0;}
    table.act td:last-child { border-right:none;}
    table.act tbody tr:last-child td { border-bottom:none; }
    table.act tbody tr:hover td { background:#f8fafc;}
    table.act td.dept { font-weight:800; font-size:11.5px; letter-spacing:.05em; vertical-align: middle;}
    .dept-ico { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:7px; margin-right:6px; color:#fff; border:1px solid #1e293b; background:#334155; font-size:11.5px;}
    .ico-op, .ico-mt, .ico-pr, .ico-la { background:#334155; border:1px solid #1e293b;}
    .date-box { display:inline-flex; align-items:center; justify-content:center; padding:3px 8px; border-radius:6px; background:#f8fafc; border:1px solid #cbd5e1; font-size:10.5px; font-weight:700; color:#0f172a; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;}
    .eng-box { font-size:11.5px; font-weight:700; color:#0f172a;}
    .status-pill { display:inline-flex; align-items:center; gap:3px; padding:2.5px 8px; border-radius:999px; font-size:9px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; border:1px solid; white-space: nowrap;}
    .status-prog { background:#1e293b; color:#fff; border-color:#0f172a;}
    .status-new  { background:#475569; color:#fff; border-color:#334155;}
    .empty-act { padding:10px 0; color:#64748b; font-style:italic; display:inline-flex; align-items:center; gap:6px;}
    .empty-act i { opacity:60%;}

    /* ===== UTILITY USAGE SUMMARY TABLE (Req 2: Variance + % CHANGE) ===== */
    table.util { border:1px solid #cbd5e1; border-radius:8px; overflow:hidden; border-collapse: separate; border-spacing: 0; }
    table.util th {
        background:#e2e8f0; color:#0f172a;
        border: none; border-bottom:1px solid #cbd5e1; border-right:1px solid #cbd5e1;
        padding:7px 10px; font-weight:800; text-align:center; font-size:11px;
        letter-spacing:.04em;
    }
    table.util th:last-child { border-right:none; }
    table.util thead tr:nth-child(2) th { background:#f1f5f9; font-size:10.5px; color:#334155; }
    table.util td {
        border: none; border-bottom:1px solid #e2e8f0; border-right:1px solid #e2e8f0;
        padding:6px 10px; font-size:12px; vertical-align:middle; color:#0f172a;
    }
    table.util td:last-child { border-right:none; }
    table.util tbody tr:last-child td { border-bottom:none; }
    table.util tbody tr.subtotal td { background:#f8fafc; border-top:2px solid #cbd5e1; }
    table.util tbody tr.section-spacer td { height:0; padding:0; border:0; background:transparent; }
    table.util td.util-name { font-weight:700; color:#0f172a; padding-left:12px; }
    table.util td.util-unit { text-align:center; font-size:10.5px; color:#64748b; font-weight:600; }
    table.util td.util-num,
    table.util td.num.util-num {
        text-align:right;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Courier New", monospace;
        font-variant-numeric: tabular-nums;
        font-size: 12px;
        vertical-align: middle;
        white-space: nowrap;
    }
    table.util td.cost-rupiah { white-space: nowrap; }
    .text-slate-700 { color: #334155; }
    .text-slate-600 { color: #475569; }
    .text-slate-500 { color: #64748b; }
    .font-mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Courier New", monospace;
        font-variant-numeric: tabular-nums;
    }
</style>
</head>
<body>
<div class="action-bar">
    <a class="btn btn-excel" href="?<?=$qsRange?>&format=excel" target="_blank"><i class="fa-solid fa-file-excel"></i> Download Excel</a>
    <button type="button" class="btn btn-pdf" onclick="window.print()"><i class="fa-solid fa-file-pdf"></i> Save as PDF / Print</button>
</div>
<div class="page-wrap">
    <h1>ENGINEERING<br>REPORT</h1>
    <div class="date-label">DATE: <?=$reportDateLabel?></div>

    <!-- ① KPIs -->
    <h2>1. KEY PERFORMANCE INDICATORS (KPIs)</h2>
    <table>
        <thead><tr><th>METRIC</th><th>LAST YEAR (LY)</th><th>TODAY</th><th>ITR</th><th>M&amp;U</th><th>GITB RANK</th></tr></thead>
        <tbody>
        <?php foreach ($kpiData as $r) { ?>
            <tr>
                <td class="bold"><?=htmlspecialchars($r[0])?></td>
                <td class="cen"><?=htmlspecialchars($r[1])?></td>
                <td class="cen"><?=htmlspecialchars($r[2])?></td>
                <td class="cen"><?=htmlspecialchars($r[3])?></td>
                <td class="cen"><?=htmlspecialchars($r[4])?></td>
                <td class="cen"><?=htmlspecialchars($r[5])?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <!-- ② UTILITY USAGE SUMMARY (Format Semula + Kolom SELISIH + COST) -->
    <h2>2. UTILITY USAGE SUMMARY</h2>
    <table>
        <thead>
            <tr>
                <th style="width:22%;">UTILITY</th>
                <th style="width:12%;">PERIOD</th>
                <th style="width:20%;">USAGE</th>
                <th style="width:20%;">SELISIH</th>
                <th style="width:26%;">COST (Rp.)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $utilPairs = [
                ['name'=>'ELECTRICITY', 'unit'=>'kWh',   'ly_val'=>$elecLY,   'today_val'=>$elecToday,   'ly_cost'=>$costElecLY,   'today_cost'=>$costElecToday],
                ['name'=>'WATER',       'unit'=>'m3',    'ly_val'=>$waterLY,  'today_val'=>$waterToday,  'ly_cost'=>$costWaterLY,  'today_cost'=>$costWaterToday],
                ['name'=>'GAS',         'unit'=>'kg',    'ly_val'=>$gasLY,    'today_val'=>$gasToday,    'ly_cost'=>$costGasLY,    'today_cost'=>$costGasToday],
                ['name'=>'FUEL',        'unit'=>'liter', 'ly_val'=>$fuelLY,   'today_val'=>$fuelToday,   'ly_cost'=>$costFuelLY,   'today_cost'=>$costFuelToday],
            ];
            foreach ($utilPairs as $up) {
                $name = $up['name'];
                $unit = $up['unit'];
                $lyV = (float)$up['ly_val'];
                $tdV = (float)$up['today_val'];
                $lyC = (float)$up['ly_cost'];
                $tdC = (float)$up['today_cost'];
                $diffUsage = $tdV - $lyV;
                $diffAbsU = abs($diffUsage);
                if ($diffUsage > 0.00001) { $arrowU = '&uarr; +'; $clsU = 'color:#000;'; }
                elseif ($diffUsage < -0.00001) { $arrowU = '&darr; '; $clsU = 'color:#000;'; }
                else { $arrowU = ''; $clsU = 'color:#000;'; }
                $unitLabel = $unit;
                $diffStr = $arrowU . repFmtIndo($diffAbsU, 0) . ' ' . $unitLabel;
            ?>
            <tr>
                <td rowspan="2" class="bold cen mid"><?=htmlspecialchars($name)?></td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($lyV, 0)?> <?=$unitLabel?></td>
                <td class="cen num" style="color:#666; font-style:italic;">(baseline)</td>
                <td class="num"><?=repFmtRupiah($lyC)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num bold"><?=repFmtIndo($tdV, 0)?> <?=$unitLabel?></td>
                <td class="num bold" style="<?=$clsU?>"><?=$diffStr?></td>
                <td class="num bold"><?=repFmtRupiah($tdC)?></td>
            </tr>
            <?php } ?>
        </tbody>
    </table>

    <!-- ③ ENGINEERING ACTIVITIES (TABEL 5 KOLOM SAMA PERSIS DASHBOARD INDEX.PHP) -->
    <h2>3. ENGINEERING ACTIVITIES</h2>
    <table class="act">
        <colgroup>
            <col class="dept-col"><col class="act-col"><col class="date-col"><col class="eng-col"><col class="status-col">
        </colgroup>
        <thead>
            <tr>
                <th class="dept-col">DEPARTMENT</th>
                <th class="act-col">ACTIVITY DETAIL</th>
                <th class="date-col">DATE</th>
                <th class="eng-col">BY ENG</th>
                <th class="status-col">STATUS</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $bgMap   = ['OPERATION'=>'op-bg','MAINTENANCE'=>'mt-bg','PROJECT'=>'pr-bg','LANDSCAPE'=>'la-bg'];
        $bgIco   = ['OPERATION'=>'ico-op','MAINTENANCE'=>'ico-mt','PROJECT'=>'ico-pr','LANDSCAPE'=>'ico-la'];
        $nameIco = ['OPERATION'=>'fa-gears','MAINTENANCE'=>'fa-wrench','PROJECT'=>'fa-clipboard-list','LANDSCAPE'=>'fa-seedling'];

        $totalRowsAll = 0;
        $flatRows = [];
        foreach ($divisions as $d) {
            $list = $actByDiv[$d] ?? [];
            $bico  = $bgIco[$d]   ?? 'ico-op';
            $nicon = $nameIco[$d] ?? 'fa-list';

            // ✅ HANYA TAMPILKAN IN PROGRESS SAJA (complete DIFILTER TIDAK MASUK!)
            $listProg = [];
            foreach ($list as $itx) {
                $sx = (string)($itx['status'] ?? 'progress');
                if (!($sx === 'complete' || $sx === 'completed')) $listProg[] = $itx;
            }
            if (count($listProg) === 0) continue;

            $totalRowsAll += count($listProg);
            $rowIdx = 0;
            $rowTotal = count($listProg);
            foreach ($listProg as $item):
                $rowIdx++;
                $isFirstRow = ($rowIdx === 1);

                $name = (string)($item['name'] ?? '');
                $date = (string)($item['date'] ?? '');
                $engRaw  = trim((string)($item['eng'] ?? ''));
                $status  = (string)($item['status'] ?? 'in_progress');
                $fDate = (strlen($date) > 0) ? repFmtDateAct($date) : '';
                // ✅ Tidak lagi bedakan "Master Activity" - TAMPILKAN SAJA NAMA PEMBUAT (sesuai request user).
                $byLabel = ($engRaw !== '' && $engRaw !== '-') ? $engRaw : '- (Belum ditugaskan)';

                $flatRows[] = [
                    'deptName' => $d,
                    'deptIco'  => $bico,
                    'nameIco'  => $nicon,
                    'isFirst'  => $isFirstRow,
                    'title'    => $name,
                    'date'     => $fDate,
                    'byLabel'  => $byLabel,
                    'status'   => $status,
                ];
            endforeach;
        }

        if ($totalRowsAll === 0):
        ?>
            <tr>
                <td colspan="5" class="dept">
                    <span class="empty-act"><i class="fa-solid fa-inbox"></i>Semua aktivitas selesai. Tidak ada in progress.</span>
                </td>
            </tr>
        <?php else:
            foreach ($flatRows as $fr):
        ?>
            <tr>
                <td class="dept">
                    <?php if ($fr['isFirst']): ?>
                        <span class="dept-ico <?=$fr['deptIco']?>"><i class="fa-solid <?=$fr['nameIco']?>"></i></span><?=htmlspecialchars($fr['deptName'])?>
                    <?php endif; ?>
                </td>
                <td><span class="act-name"><?=htmlspecialchars($fr['title'])?></span></td>
                <td style="text-align:center; vertical-align: middle;">
                    <?php if ($fr['date'] !== ''): ?>
                        <span class="date-box"><?=htmlspecialchars($fr['date'])?></span>
                    <?php else: ?>
                        <span class="date-box" style="color:#64748b;">-</span>
                    <?php endif; ?>
                </td>
                <td><span class="eng-box"><?=htmlspecialchars($fr['byLabel'])?></span></td>
                <td style="text-align:center; vertical-align: middle;">
                    <?php
                    $s = (string)$fr['status'];
                    if ($s === 'complete' || $s === 'completed'):
                    ?>
                        <span class="status-pill status-new"><i class="fa-solid fa-circle-check" style="font-size:9px;"></i>Done</span>
                    <?php elseif ($s === 'new' || $s === 'tugas_baru'): ?>
                        <span class="status-pill status-new"><i class="fa-solid fa-asterisk" style="font-size:9px;"></i>Baru</span>
                    <?php else: ?>
                        <span class="status-pill status-prog"><i class="fa-solid fa-spinner fa-spin" style="font-size:9px;"></i>In Progress</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach;
        endif; ?>
        </tbody>
    </table>

</div>
</body>
</html>
