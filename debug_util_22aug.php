<?php
require_once __DIR__ . '/config/config.php';
$db = Database::getInstance();
session_start();
requireLogin();
$user = currentUser();
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? 'engineer');
$statusWhere = ($userRole === 'engineer') ? "AND engineer_id = $userId" : "";
$TARIF = getTariffSettings();
$TEST_DATE = '2026-08-22';
$TARIF_LISTRIK = (int)($TARIF['electricity_per_kwh'] ?? 1850);
$TARIF_AIR     = (int)($TARIF['water_per_m3']        ?? 9600);
$TARIF_GAS     = (int)($TARIF['gas_per_kg']          ?? 24500);
$TARIF_FUEL    = (int)($TARIF['fuel_per_liter']      ?? 17450);

header('Content-Type: text/plain; charset=utf-8');
echo "=== DEBUG UTILITY TEST DATE: $TEST_DATE | Role: $userRole | User: {$user['name']} ===\n\n";

/* -------- WHERE CLAUSES -------- */
$APPROVED_WHERE_DAILY = "status = 'approved'";
if ($userRole === 'engineer') $APPROVED_WHERE_DAILY .= " $statusWhere";
$TODAY_WHERE = $APPROVED_WHERE_DAILY;
if (in_array($userRole, ['manager','supervisor','admin'], true)) {
    $TODAY_WHERE = "status IN ('approved','pending')";
    if ($userRole === 'engineer') $TODAY_WHERE .= " $statusWhere";
}

/* -------- (1) DAILY LOGS DEDUP -------- */
echo "--- [STEP 1] daily_logs dedup query (MAX id per DATE+engineer_id) ---\n";
$dedupWhere = "WHERE DATE(log_date) BETWEEN ? AND ? AND $TODAY_WHERE";
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
print_r($d);
echo "\n  pickD_elec = " . ($d['elec'] > 0.00001 ? 'TRUE (pakai daily_logs SAJA, TIDAK tambah energy_logs)' : 'FALSE (merge energy_logs)') . "\n";
echo "  pickD_water = " . ($d['water'] > 0.00001 ? 'TRUE' : 'FALSE') . "\n";
echo "  pickD_gas = "   . ($d['gas']   > 0.00001 ? 'TRUE' : 'FALSE') . "\n";
echo "  pickD_fuel = "  . ($d['fuel']  > 0.00001 ? 'TRUE' : 'FALSE') . "\n\n";

/* -------- (1B) FALLBACK DAILY LOGS READING METER -------- */
$needElec  = ((float)($d['elec']  ?? 0) < 0.00001);
$needWater = ((float)($d['water'] ?? 0) < 0.00001);
echo "--- [STEP 1B] FALLBACK reading meter (needElec=" . ($needElec?'YA':'TIDAK') . ", needWater=" . ($needWater?'YA':'TIDAK') . ") ---\n";
if ($needElec || $needWater) {
    $fbFrom = date('Y-m-d', strtotime($TEST_DATE . ' -1 day'));
    $fbTo   = $TEST_DATE;
    $rowsRead = $db->fetchAll("SELECT dl.id, DATE(dl.log_date) AS tgl, dl.engineer_id, dl.shift,
                                      COALESCE(dl.electricity_wbp,0) AS ew, COALESCE(dl.electricity_lwbp,0) AS el,
                                      COALESCE(dl.water_main_building,0) AS wmb
                               FROM daily_logs dl
                               INNER JOIN (
                                   SELECT MAX(id) AS keep_id
                                   FROM daily_logs
                                   WHERE DATE(log_date) BETWEEN ? AND ? AND $TODAY_WHERE
                                   GROUP BY DATE(log_date), engineer_id
                               ) k ON k.keep_id = dl.id
                               ORDER BY dl.engineer_id ASC, dl.log_date ASC, dl.id ASC",
        [$fbFrom, $fbTo]
    );
    $manElec = 0.0; $manWater = 0.0;
    $lastMbByEng = []; $lastWbpByEng = []; $lastLwbpByEng = [];
    foreach ($rowsRead as $rr) {
        $eid = (int)($rr['engineer_id'] ?? 0);
        $tmb = (float)($rr['wmb'] ?? 0);
        $twbp = (float)($rr['ew'] ?? 0);
        $tlwbp = (float)($rr['el'] ?? 0);
        $tgl = (string)($rr['tgl'] ?? '');
        $inRange = ($tgl >= $TEST_DATE && $tgl <= $TEST_DATE);
        echo "  tgl=$tgl eid=$eid | wbp=$twbp lwbp=$tlwbp wmb=$tmb | inRange=".($inRange?'YA':'TIDAK')."\n";
        if ($needWater && $tmb > 0) {
            $prevMb = $lastMbByEng[$eid] ?? null;
            if ($prevMb !== null && $tmb > $prevMb && $inRange) {
                $c = max(0.0, $tmb - $prevMb);
                $_fWmb = ($c > 0 && $c <= 300.0) ? 10.0 : 1.0;
                $c = $c * $_fWmb;
                $manWater += $c;
                echo "    💧 WATER FIX: sel=".(max(0.0,$tmb-$prevMb))." × $_fWmb = $c (manWater now=$manWater)\n";
            }
            $lastMbByEng[$eid] = $tmb;
        }
        if ($needElec && ($twbp > 0 || $tlwbp > 0)) {
            $prevWbp = $lastWbpByEng[$eid] ?? null;
            $prevLwbp = $lastLwbpByEng[$eid] ?? null;
            if ($prevWbp !== null && $prevLwbp !== null && $inRange) {
                $cWbp = max(0.0, $twbp - $prevWbp);
                $cLwbp = max(0.0, $tlwbp - $prevLwbp);
                $_cSum = $cWbp + $cLwbp;
                if ($_cSum <= 500.0) $c = $_cSum * 8000.0;
                else $c = $_cSum;
                $manElec += $c;
                echo "    ⚡ ELEC FIX: wbp_sel=$cWbp lwbp_sel=$cLwbp sum=$_cSum → ".(($_cSum<=500)?'×8000':'LANGSUNG')." = $c (manElec now=$manElec)\n";
            }
            if ($twbp > 0)  $lastWbpByEng[$eid]  = $twbp;
            if ($tlwbp > 0) $lastLwbpByEng[$eid] = $tlwbp;
        }
    }
    echo "  ➜ Hasil fallback: manElec=$manElec, manWater=$manWater\n\n";
} else {
    echo "  (skip: total_xx dari daily_logs SUDAH ADA, fallback TIDAK DIPERLUKAN)\n\n";
}

/* -------- (2) ENERGY LOGS -------- */
echo "--- [STEP 2] energy_logs dedup query ---\n";
$wE = ["DATE(log_date) BETWEEN ? AND ?"];
$pE = [$TEST_DATE, $TEST_DATE];
if ($userRole === 'engineer') { $wE[] = "created_by = ?"; $pE[] = $userId; }
$dedupWhereE = 'WHERE ' . implode(' AND ', $wE);
$sqlE = "SELECT
    COALESCE(SUM(CAST(el.pln_lwbp_kwh AS DECIMAL(18,4)) + CAST(el.pln_wbp_kwh AS DECIMAL(18,4)) + CAST(COALESCE(el.genset_kwh,0) AS DECIMAL(18,4))),0)  as elec,
    COALESCE(SUM(CAST(COALESCE(el.air_m3,0) AS DECIMAL(18,4)) + CAST(COALESCE(el.air_deep_well_m3,0) AS DECIMAL(18,4))),0)                as water,
    COALESCE(SUM(CAST(COALESCE(el.gas_kg,0) AS DECIMAL(18,4)) + CAST(COALESCE(el.gas_lng_kg,0) AS DECIMAL(18,4))),0)                      as gas,
    COALESCE(SUM(CAST(COALESCE(el.solar_liter,0) AS DECIMAL(18,4))),0)                               as fuel,
    COALESCE(COUNT(el.id),0) as cnt
FROM energy_logs el
INNER JOIN (
    SELECT MAX(id) AS keep_id
    FROM energy_logs
    $dedupWhereE
    GROUP BY DATE(log_date), created_by
) kE ON kE.keep_id = el.id";
$e = $db->fetchOne($sqlE, $pE);
print_r($e);
echo "\n";

/* -------- (3) MERGE (Priority daily_logs > energy_logs) -------- */
echo "--- [STEP 3] MERGE (priority: daily_logs > 0 → AMBIL D SAJA) ---\n";
$pickD_elec = ((float)$d['elec']  > 0.00001);
$pickD_water = ((float)$d['water'] > 0.00001);
$pickD_gas = ((float)$d['gas']   > 0.00001);
$pickD_fuel = ((float)$d['fuel']  > 0.00001);
echo "  pickD_elec = " . ($pickD_elec ? 'true' : 'false') . " → elec = " . ($pickD_elec ? $d['elec'] : ($d['elec'].'+'.$e['elec'].'='.($d['elec']+$e['elec']))) . "\n";
echo "  pickD_water = " . ($pickD_water ? 'true' : 'false') . " → water = " . ($pickD_water ? $d['water'] : ($d['water'].'+'.$e['water'].'='.($d['water']+$e['water']))) . "\n";
echo "  pickD_gas = "   . ($pickD_gas   ? 'true' : 'false') . " → gas = "   . ($pickD_gas   ? $d['gas']   : ($d['gas'].'+'.$e['gas'].'='.($d['gas']+$e['gas']))) . "\n";
echo "  pickD_fuel = "  . ($pickD_fuel  ? 'true' : 'false') . " → fuel = "  . ($pickD_fuel  ? $d['fuel']  : ($d['fuel'].'+'.$e['fuel'].'='.($d['fuel']+$e['fuel']))) . "\n\n";

/* -------- (4) SAFETY CAP -------- */
$out_elec  = $pickD_elec  ? (float)$d['elec']  : (float)($d['elec']  + $e['elec']);
$out_water = $pickD_water ? (float)$d['water'] : (float)($d['water'] + $e['water']);
$out_gas   = $pickD_gas   ? (float)$d['gas']   : (float)($d['gas']   + $e['gas']);
$out_fuel  = $pickD_fuel  ? (float)$d['fuel']  : (float)($d['fuel']  + $e['fuel']);
echo "--- [STEP 4] SAFETY CAP per hari: elec<=40000, water<=800, gas<=3000, fuel<=8000 ---\n";
$capPerDay = ['elec'=>40000.0, 'water'=>800.0, 'gas'=>3000.0, 'fuel'=>8000.0];
$ukList = ['elec','water','gas','fuel'];
$outList = ['elec'=>$out_elec,'water'=>$out_water,'gas'=>$out_gas,'fuel'=>$out_fuel];
foreach ($ukList as $uk) {
    $valNow = $outList[$uk];
    $capNow = $capPerDay[$uk];
    $flag = '';
    if ($valNow <= 0) { echo "  $uk = 0 (skip)\n"; continue; }
    if ($valNow > $capNow) {
        $flag = ' ⚠️ OVER CAP!';
        $faktorCandidates = [8000.0, 1000.0, 100.0, 10.0, 2.0];
        $bestVal = $valNow; $bestDiff = INF; $bestF = 1.0;
        foreach ($faktorCandidates as $f) {
            $v = $valNow / $f;
            if ($v <= $capNow && $v > 0) {
                $diffx = abs($v - ($capNow * 0.35));
                echo "       coba bagi $f → $v (dari tengah=$diffx)\n";
                if ($diffx < $bestDiff) { $bestDiff = $diffx; $bestVal = $v; $bestF = $f; }
            }
        }
        if ($bestF > 1.0) {
            echo "    ➜ BEFORE: $uk = $valNow\n";
            $outList[$uk] = $bestVal;
            echo "    ➜ AFTER:  $uk = $bestVal (÷$bestF)\n";
        }
    }
    if (!$flag) echo "  $uk = $valNow (≤ $capNow ✅ aman)\n";
}

echo "\n=== FINAL RESULT (setelah cap) ===\n";
echo "ELEC = {$outList['elec']} kWh  | Cost = " . ($outList['elec'] * $TARIF_LISTRIK) . " Rp\n";
echo "WATER = {$outList['water']} m3 | Cost = " . ($outList['water'] * $TARIF_AIR) . " Rp\n";
echo "GAS = {$outList['gas']} kg     | Cost = " . ($outList['gas'] * $TARIF_GAS) . " Rp\n";
echo "FUEL = {$outList['fuel']} L    | Cost = " . ($outList['fuel'] * $TARIF_FUEL) . " Rp\n";

echo "\n=== PERBANDINGAN dengan screenshot ===\n";
echo "Dashboard screenshot: ELEC=26.978.640 | WATER=760 | GAS=64.794 | FUEL=0\n";
echo "Print     screenshot: ELEC=3.372      | WATER=760 | GAS=648    | FUEL=3.200\n";
