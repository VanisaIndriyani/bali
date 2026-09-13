<?php
require_once __DIR__ . '/config/config.php';
$db = Database::getInstance();
$userId = 1;
$userRole = 'admin';
$statusWhere = "";
$TARIF = getTariffSettings();
$TEST_DATE = '2026-08-22';
$TARIF_LISTRIK = (int)($TARIF['electricity_per_kwh'] ?? 1850);
$TARIF_AIR     = (int)($TARIF['water_per_m3']        ?? 9600);
$TARIF_GAS     = (int)($TARIF['gas_per_kg']          ?? 24500);
$TARIF_FUEL    = (int)($TARIF['fuel_per_liter']      ?? 17450);

header('Content-Type: text/plain; charset=utf-8');
echo "=== DEBUG UTILITY (BYPASS LOGIN) TEST DATE: $TEST_DATE | Role: $userRole ===\n\n";

echo "[A] RAW DATA DAILY_LOGS TANGGAL $TEST_DATE (ALL STATUS):\n";
$rowsRaw = $db->fetchAll("SELECT id, log_date, engineer_id, status,
    total_electricity tot_elec, electricity_wbp wbp, electricity_lwbp lwbp,
    total_water tot_water, water_main_building wmb,
    total_gas tot_gas, gas_lpg, gas_lng,
    total_fuel tot_fuel
    FROM daily_logs WHERE DATE(log_date) = ? ORDER BY id DESC",
    [$TEST_DATE]);
foreach ($rowsRaw as $r) {
    echo "  #{$r['id']} | eng:{$r['engineer_id']} | {$r['status']} | {$r['log_date']}\n";
    echo "    ⚡ ELEC: tot={$r['tot_elec']} | wbp={$r['wbp']} lwbp={$r['lwbp']}\n";
    echo "    💧 WATER: tot={$r['tot_water']} | wmb={$r['wmb']}\n";
    echo "    🔥 GAS: tot={$r['tot_gas']} (lpg={$r['gas_lpg']}+lng={$r['gas_lng']})\n";
    echo "    ⛽ FUEL: tot={$r['tot_fuel']}\n\n";
}

echo "[B] RAW DATA ENERGY_LOGS TANGGAL $TEST_DATE:\n";
$erowsRaw = $db->fetchAll("SELECT id, log_date, created_by,
    pln_wbp_kwh wbp, pln_lwbp_kwh lwbp, genset_kwh genset,
    air_m3 air_m3, air_deep_well_m3 air_dw,
    gas_kg gas_kg, gas_lng_kg gas_lng_kg,
    solar_liter solar
    FROM energy_logs WHERE DATE(log_date) = ? ORDER BY id DESC",
    [$TEST_DATE]);
foreach ($erowsRaw as $r) {
    echo "  #{$r['id']} | cb:{$r['created_by']} | {$r['log_date']}\n";
    echo "    ⚡ ELEC: wbp+lwbp+gen = ".($r['wbp']+$r['lwbp']+$r['genset'])." ({$r['wbp']} + {$r['lwbp']} + {$r['genset']})\n";
    echo "    💧 WATER: air+dw = ".($r['air_m3']+$r['air_dw'])." ({$r['air_m3']} + {$r['air_dw']})\n";
    echo "    🔥 GAS: kg+lngkg = ".($r['gas_kg']+$r['gas_lng_kg'])." ({$r['gas_kg']} + {$r['gas_lng_kg']})\n";
    echo "    ⛽ FUEL: solar = {$r['solar']}\n\n";
}

/* -------- WHERE CLAUSES (SAMA DENGAN INDEX.PHP manager/admin) -------- */
$TODAY_WHERE = "status IN ('approved','pending')";
$dedupWhere = "WHERE DATE(log_date) BETWEEN ? AND ? AND $TODAY_WHERE";

echo "--- [STEP 1] daily_logs DEDUP query MAX(id) per DATE+engineer_id ---\n";
$sqlD = "SELECT
    COALESCE(SUM(CAST(dl.total_electricity AS DECIMAL(18,4))),0) as elec,
    COALESCE(SUM(CAST(dl.total_water       AS DECIMAL(18,4))),0) as water,
    COALESCE(SUM(CAST(dl.total_gas         AS DECIMAL(18,4))),0) as gas,
    COALESCE(SUM(CAST(dl.total_fuel        AS DECIMAL(18,4))),0) as fuel,
    COALESCE(COUNT(dl.id),0) as cnt
FROM daily_logs dl
INNER JOIN (
    SELECT MAX(id) AS keep_id
    FROM daily_logs
    $dedupWhere
    GROUP BY DATE(log_date), engineer_id
) k ON k.keep_id = dl.id";
$d = $db->fetchOne($sqlD, [$TEST_DATE, $TEST_DATE]);
echo "  elec = {$d['elec']}  |  water = {$d['water']}  |  gas = {$d['gas']}  |  fuel = {$d['fuel']}  |  cnt = {$d['cnt']}\n";
echo "  => pickD_elec = " . ($d['elec'] > 0.00001 ? 'TRUE (AMBIL D SAJA)' : 'FALSE (MERGE E)') . "\n";
echo "  => pickD_water = " . ($d['water'] > 0.00001 ? 'TRUE' : 'FALSE') . "\n";
echo "  => pickD_gas = "   . ($d['gas']   > 0.00001 ? 'TRUE' : 'FALSE') . "\n";
echo "  => pickD_fuel = "  . ($d['fuel']  > 0.00001 ? 'TRUE' : 'FALSE') . "\n\n";

/* -------- STEP 1B: FALLBACK -------- */
$needElec  = ((float)($d['elec']  ?? 0) < 0.00001);
$needWater = ((float)($d['water'] ?? 0) < 0.00001);
echo "--- [STEP 1B] FALLBACK (needElec=" . ($needElec?'YA':'TIDAK') . ", needWater=" . ($needWater?'YA':'TIDAK') . ") ---\n";
if ($needElec || $needWater) {
    $fbFrom = date('Y-m-d', strtotime($TEST_DATE . ' -1 day'));
    $fbTo   = $TEST_DATE;
    $fbSql = "SELECT dl.id, DATE(dl.log_date) AS tgl, dl.engineer_id,
                     COALESCE(dl.electricity_wbp,0) AS ew, COALESCE(dl.electricity_lwbp,0) AS el,
                     COALESCE(dl.water_main_building,0) AS wmb
              FROM daily_logs dl
              INNER JOIN (
                  SELECT MAX(id) AS keep_id FROM daily_logs
                  WHERE DATE(log_date) BETWEEN ? AND ? AND $TODAY_WHERE
                  GROUP BY DATE(log_date), engineer_id
              ) k ON k.keep_id = dl.id
              ORDER BY dl.engineer_id ASC, dl.log_date ASC, dl.id ASC";
    $rowsRead = $db->fetchAll($fbSql, [$fbFrom, $fbTo]);
    $manElec = 0.0; $manWater = 0.0; $lastMbByEng = $lastWbpByEng = $lastLwbpByEng = [];
    foreach ($rowsRead as $rr) {
        $eid = (int)$rr['engineer_id'];
        $inRange = ($rr['tgl'] >= $TEST_DATE && $rr['tgl'] <= $TEST_DATE);
        echo "  tgl={$rr['tgl']} eid=$eid wbp={$rr['ew']} lwbp={$rr['el']} wmb={$rr['wmb']} | inRange=$inRange\n";
        if ($needWater && $rr['wmb'] > 0) {
            $p = $lastMbByEng[$eid] ?? null;
            if ($p !== null && $rr['wmb'] > $p && $inRange) {
                $c = max(0.0, $rr['wmb'] - $p);
                $_fw = ($c > 0 && $c <= 300.0) ? 10.0 : 1.0;
                $c = $c * $_fw;
                $manWater += $c;
                echo "    WATER +$c (sel=".max(0,$rr['wmb']-$p)." × $_fw)\n";
            }
            $lastMbByEng[$eid] = $rr['wmb'];
        }
        if ($needElec && ($rr['ew'] > 0 || $rr['el'] > 0)) {
            $pw = $lastWbpByEng[$eid] ?? null; $pl = $lastLwbpByEng[$eid] ?? null;
            if ($pw !== null && $pl !== null && $inRange) {
                $cw = max(0, $rr['ew']-$pw); $cl = max(0, $rr['el']-$pl); $sm = $cw+$cl;
                $c = ($sm <= 500) ? $sm*8000 : $sm;
                $manElec += $c;
                echo "    ELEC +$c (cw=$cw cl=$cl sum=$sm → ".($sm<=500?'×8000':'LANGSUNG').")\n";
            }
            if ($rr['ew']>0) $lastWbpByEng[$eid]=$rr['ew'];
            if ($rr['el']>0) $lastLwbpByEng[$eid]=$rr['el'];
        }
    }
    echo "  fallback manElec=$manElec manWater=$manWater\n";
    if ($needElec  && $manElec  > 0) $d['elec']  = (float)$d['elec']  + $manElec;
    if ($needWater && $manWater > 0) $d['water'] = (float)$d['water'] + $manWater;
    echo "  ➜ SETELAH FALLBACK: d[elec]={$d['elec']} d[water]={$d['water']}\n\n";
} else {
    echo "  (skip, total_xx > 0, tidak butuh fallback)\n\n";
}

/* -------- STEP 2: ENERGY_LOGS DEDUP -------- */
echo "--- [STEP 2] energy_logs DEDUP per DATE+created_by ---\n";
$sqlE = "SELECT
    COALESCE(SUM(CAST(el.pln_lwbp_kwh AS DECIMAL(18,4)) + CAST(el.pln_wbp_kwh AS DECIMAL(18,4)) + CAST(COALESCE(el.genset_kwh,0) AS DECIMAL(18,4))),0)  as elec,
    COALESCE(SUM(CAST(COALESCE(el.air_m3,0) AS DECIMAL(18,4)) + CAST(COALESCE(el.air_deep_well_m3,0) AS DECIMAL(18,4))),0)                as water,
    COALESCE(SUM(CAST(COALESCE(el.gas_kg,0) AS DECIMAL(18,4)) + CAST(COALESCE(el.gas_lng_kg,0) AS DECIMAL(18,4))),0)                      as gas,
    COALESCE(SUM(CAST(COALESCE(el.solar_liter,0) AS DECIMAL(18,4))),0)                               as fuel,
    COALESCE(COUNT(el.id),0) as cnt
FROM energy_logs el
INNER JOIN (
    SELECT MAX(id) AS keep_id FROM energy_logs
    WHERE DATE(log_date) BETWEEN ? AND ?
    GROUP BY DATE(log_date), created_by
) kE ON kE.keep_id = el.id";
$e = $db->fetchOne($sqlE, [$TEST_DATE, $TEST_DATE]);
echo "  elec = {$e['elec']}  |  water = {$e['water']}  |  gas = {$e['gas']}  |  fuel = {$e['fuel']}  |  cnt = {$e['cnt']}\n\n";

/* -------- STEP 3: MERGE Priority daily_logs > energy_logs -------- */
echo "--- [STEP 3] MERGE ---\n";
$pickD_elec = ((float)$d['elec']  > 0.00001);
$pickD_water = ((float)$d['water'] > 0.00001);
$pickD_gas = ((float)$d['gas']   > 0.00001);
$pickD_fuel = ((float)$d['fuel']  > 0.00001);
$oElec = $pickD_elec  ? (float)$d['elec']  : (float)($d['elec']  + $e['elec']);
$oWater= $pickD_water ? (float)$d['water'] : (float)($d['water'] + $e['water']);
$oGas  = $pickD_gas   ? (float)$d['gas']   : (float)($d['gas']   + $e['gas']);
$oFuel = $pickD_fuel  ? (float)$d['fuel']  : (float)($d['fuel']  + $e['fuel']);
echo "  ELEC  = pickD=" . ($pickD_elec?'true':'false') . " → $oElec  = " . ($pickD_elec?"{$d['elec']} (D only)":"{$d['elec']} + {$e['elec']} = ".($d['elec']+$e['elec'])) . "\n";
echo "  WATER = pickD=" . ($pickD_water?'true':'false') . " → $oWater = " . ($pickD_water?"{$d['water']}":"{$d['water']}+{$e['water']}") . "\n";
echo "  GAS   = pickD=" . ($pickD_gas?'true':'false')  . " → $oGas   = " . ($pickD_gas?"{$d['gas']}":"{$d['gas']}+{$e['gas']}") . "\n";
echo "  FUEL  = pickD=" . ($pickD_fuel?'true':'false') . " → $oFuel  = " . ($pickD_fuel?"{$d['fuel']}":"{$d['fuel']}+{$e['fuel']}") . "\n\n";

/* -------- STEP 4: SAFETY CAP -------- */
echo "--- [STEP 4] SAFETY CAP (harusnya listrik 26,978,640 > 40,000 → DIKURANGI) ---\n";
$capPerDay = ['elec'=>40000.0, 'water'=>800.0, 'gas'=>3000.0, 'fuel'=>8000.0];
$out = ['elec'=>$oElec, 'water'=>$oWater, 'gas'=>$oGas, 'fuel'=>$oFuel];
foreach (['elec','water','gas','fuel'] as $uk) {
    $vn = (float)$out[$uk]; $cp = $capPerDay[$uk];
    if ($vn <= 0) { echo "  $uk = 0 (skip)\n"; continue; }
    if ($vn > $cp) {
        echo "  ⚠️ $uk = $vn MELEBIHI CAP $cp → coba pembagi...\n";
        $faks = [8000.0, 1000.0, 100.0, 10.0, 2.0];
        $bv = $vn; $bd = INF; $bf = 1.0;
        foreach ($faks as $f) {
            $v = $vn / $f;
            if ($v <= $cp && $v > 0) {
                $dd = abs($v - ($cp * 0.35));
                echo "     ÷$f → v=$v  jarak_dari_tengah=$dd\n";
                if ($dd < $bd) { $bd = $dd; $bv = $v; $bf = $f; }
            }
        }
        if ($bf > 1.0) {
            $out[$uk] = $bv;
            echo "     → PILIH ÷$bf, JADI $uk = $bv\n";
        }
    } else {
        echo "  ✅ $uk = $vn ≤ $cp\n";
    }
}

echo "\n=== FINAL SETELAH SAFETY CAP ===\n";
echo "ELEC  = {$out['elec']} kWh   | Rp " . number_format($out['elec']  * $TARIF_LISTRIK, 0, ',', '.') . "\n";
echo "WATER = {$out['water']} m3   | Rp " . number_format($out['water'] * $TARIF_AIR, 0, ',', '.') . "\n";
echo "GAS   = {$out['gas']} kg     | Rp " . number_format($out['gas']   * $TARIF_GAS, 0, ',', '.') . "\n";
echo "FUEL  = {$out['fuel']} L    | Rp " . number_format($out['fuel']  * $TARIF_FUEL, 0, ',', '.') . "\n";

echo "\n=== BANDINGKAN DENGAN SCREENSHOT USER ===\n";
echo "DASHBOARD:  ELEC=26.978.640 | WATER=760 | GAS=64.794 | FUEL=0\n";
echo "PRINT PDF:  ELEC=3.372      | WATER=760 | GAS=648    | FUEL=3.200\n";
echo "\n(Jika FINAL ELEC cap hasilnya = 3,372 berarti safety cap jalan. Kalau masih 26,978,640 berarti ada bug di code safety cap index.php yang saya tulis kemarin - tolong trace baris mana yang salah)\n";
