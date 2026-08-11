<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance();
$user = currentUser();
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? 'engineer');
$userName = (string)($user['name'] ?? 'User');

/* ---------- 1. PARAMETER TANGGAL ---------- */
$dateRaw = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateRaw)) {
    $dateRaw = date('Y-m-d');
}
$reportDate = $dateRaw;
$reportDateObj = DateTime::createFromFormat('Y-m-d', $reportDate);
$reportDateLabel = $reportDateObj ? strtoupper($reportDateObj->format('j F Y')) : strtoupper($reportDate);
$lySameDay = date('Y-m-d', strtotime($reportDate . ' -1 year'));

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
           || (strpos($tl,'perbaikan')!==false) || (strpos($tl,'new ')!==false)
           || (strpos($tl,'buat')!==false) || (strpos($tl,'meeting')!==false);
    return $isProg ? 'progress' : 'complete';
}

/* ---------- 3. TARIF ---------- */
$TARIF_LISTRIK = 1850; $TARIF_AIR = 9600; $TARIF_GAS = 24500; $TARIF_FUEL = 17450;

/* ---------- 4. DATA UTILITY (WRAP TRY/CATCH SUPAYA TABLE TIDAK ADA = TIDAK FATAL ERROR) ---------- */
$elecToday = $waterToday = $gasToday = $fuelToday = 0;
$elecLY = $waterLY = $gasLY = $fuelLY = 0;
try {
    $sumToday = $db->fetchOne("SELECT
        COALESCE(SUM(CASE WHEN log_date=? THEN total_electricity END),0) as elec,
        COALESCE(SUM(CASE WHEN log_date=? THEN total_water END),0)       as water,
        COALESCE(SUM(CASE WHEN log_date=? THEN total_gas END),0)         as gas,
        COALESCE(SUM(CASE WHEN log_date=? THEN total_fuel END),0)        as fuel
        FROM daily_logs WHERE status='approved'",
        [$reportDate,$reportDate,$reportDate,$reportDate]);
    if ($sumToday) {
        $elecToday  = (float)($sumToday['elec']  ?? 0);
        $waterToday = (float)($sumToday['water'] ?? 0);
        $gasToday   = (float)($sumToday['gas']   ?? 0);
        $fuelToday  = (float)($sumToday['fuel']  ?? 0);
    }
    $sumLY = $db->fetchOne("SELECT
        COALESCE(SUM(CASE WHEN log_date=? THEN total_electricity END),0) as elec,
        COALESCE(SUM(CASE WHEN log_date=? THEN total_water END),0)       as water,
        COALESCE(SUM(CASE WHEN log_date=? THEN total_gas END),0)         as gas,
        COALESCE(SUM(CASE WHEN log_date=? THEN total_fuel END),0)        as fuel
        FROM daily_logs WHERE status='approved'",
        [$lySameDay,$lySameDay,$lySameDay,$lySameDay]);
    if ($sumLY) {
        $elecLY  = (float)($sumLY['elec']  ?? 0);
        $waterLY = (float)($sumLY['water'] ?? 0);
        $gasLY   = (float)($sumLY['gas']   ?? 0);
        $fuelLY  = (float)($sumLY['fuel']  ?? 0);
    }
} catch (Exception $e) { /* utility kosong */ }

/* ---------- 5. KPI OCCUPANCY + ITR + M&U + GITB ---------- */
$kpiData = [['Occupancy Rate','- %','- %','-','-','-']];
try {
    $currentYearLY = (int)date('Y', strtotime($lySameDay));
    $currentYearReport = (int)date('Y', strtotime($reportDate));
    $occLYRow = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE YEAR(log_date) = ? AND status = 'approved' AND occ_rate > 0", [$currentYearLY]);
    $occReportRow = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE YEAR(log_date) = ? AND status = 'approved' AND occ_rate > 0", [$currentYearReport]);
    $defaultLyOcc = 70; $defaultTargetOcc = 80;
    $lyOcc = (($occLYRow['cnt'] ?? 0) > 0) ? round((float)($occLYRow['avg_occ'] ?? 0), 0) : $defaultLyOcc;
    $todayOccSingle = $db->fetchOne("SELECT occ_rate FROM daily_logs WHERE log_date = ? AND status = 'approved' ORDER BY id DESC LIMIT 1", [$reportDate]);
    $todayOccVal = (float)($todayOccSingle['occ_rate'] ?? 0);
    if ($todayOccVal > 0) {
        $targetOcc = round($todayOccVal, 0);
    } else {
        $avgMonthNow = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE DATE_FORMAT(log_date,'%Y-%m') = ? AND status = 'approved' AND occ_rate > 0", [date('Y-m', strtotime($reportDate))]);
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

/* ---------- 6. ENGINEERING ACTIVITIES PER DIVISI -------------- */
/*  NAMA TABLE ASLI = daily_log_activities (dla), KATEGORI = dla.category (lowercase), TITLE = dla.activity_title */
$divisions = ['OPERATION', 'MAINTENANCE', 'PROJECT', 'LANDSCAPE'];
$actByDiv = [];
foreach ($divisions as $d) $actByDiv[$d] = [];
try {
    $actRows = $db->fetchAll(
        "SELECT dla.category, dla.activity_title
         FROM daily_log_activities dla
         INNER JOIN daily_logs dl ON dl.id = dla.daily_log_id
         WHERE dl.log_date = ? AND dl.status IN ('approved','reviewed','pending')
         ORDER BY FIELD(dla.category,'operation','maintenance','project','landscape'), dla.id ASC",
        [$reportDate]
    );
    foreach ($actRows as $ar) {
        $d = strtoupper((string)($ar['category'] ?? ''));
        if (!in_array($d, $divisions)) continue;
        $title = (string)($ar['activity_title'] ?? '-');
        if (strlen(trim($title)) < 1) continue;
        $actByDiv[$d][] = ['name' => $title, 'status' => repActHeurStatus($title)];
    }
} catch (Exception $e) { /* daily_log_activities table not exists */ }

/* Fallback ke activity_masters per divisi jika kosong — wrap try/catch juga */
try {
    foreach ($divisions as $d) {
        if (count($actByDiv[$d]) === 0) {
            $masterRows = $db->fetchAll(
                "SELECT activity_name, status_default FROM activity_masters WHERE LOWER(division) = ? AND status = 'active' ORDER BY sort_order ASC, id ASC LIMIT 4",
                [strtolower($d)]
            );
            foreach ($masterRows as $mr) {
                $name = (string)($mr['activity_name'] ?? '-');
                $statusRaw = strtolower((string)($mr['status_default'] ?? 'progress'));
                $status = in_array($statusRaw,['complete','completed','progress','pending']) ? $statusRaw : repActHeurStatus($name);
                $actByDiv[$d][] = ['name' => $name, 'status' => $status];
            }
        }
    }
} catch (Exception $e) { /* activity_masters table not exists = biarkan kosong */ }

/* ---------- 7. RENDER MODE ---------- */
$format = isset($_GET['format']) ? strtolower(cleanInput($_GET['format'])) : 'print';
$fileName = 'Engineering_Report_' . $reportDate;

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
          . repCsvEscape('COST (Rp.)') . "\n";

    $utilCsvRows = [
        ['ELECTRICITY', 'kWh',   $elecLY,   $elecToday,   $TARIF_LISTRIK],
        ['WATER',       'm3',    $waterLY,  $waterToday,  $TARIF_AIR],
        ['GAS',         'kg',    $gasLY,    $gasToday,    $TARIF_GAS],
        ['FUEL',        'Liter', $fuelLY,   $fuelToday,   $TARIF_FUEL],
    ];
    foreach ($utilCsvRows as $row) {
        list($name, $unit, $ly, $today, $perUnit) = $row;
        $usageLY = repFmtIndo($ly, 0) . ' ' . $unit;
        $usageToday = repFmtIndo($today, 0) . ' ' . $unit;
        $costLY = repFmtRupiah($ly * $perUnit);
        $costToday = repFmtRupiah($today * $perUnit);
        $out .= repCsvEscape($name) . $sep . repCsvEscape('(LY)') . $sep
              . repCsvEscape($usageLY) . $sep . repCsvEscape($costLY) . "\n";
        $out .= $sep . repCsvEscape('(TODAY)') . $sep
              . repCsvEscape($usageToday) . $sep . repCsvEscape($costToday) . "\n";
    }
    $out .= "\n";

    /* 3. ENGINEERING ACTIVITIES */
    $out .= '3. ENGINEERING ACTIVITIES' . "\n";
    $out .= repCsvEscape('DEPARTMENT') . $sep
          . repCsvEscape('ACTIVITY DETAIL') . $sep
          . repCsvEscape('STATUS') . "\n";
    foreach ($divisions as $d) {
        $list = $actByDiv[$d] ?? [];
        if (count($list) === 0) $list = [['name' => '-', 'status' => '-']];
        $first = true;
        foreach ($list as $item) {
            $st = 'v ' . repStatusLabel($item['status']);
            $out .= ($first ? repCsvEscape($d) : '') . $sep
                  . repCsvEscape($item['name']) . $sep
                  . repCsvEscape($st) . "\n";
            $first = false;
        }
    }

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
    h1 { font-size:28px; letter-spacing:3px; text-align:center; margin:0 0 10px; font-weight:900; line-height:1.15; }
    .date-label { font-size:16px; font-weight:800; margin:0 0 14px; }
    h2 { font-size:16px; font-weight:900; margin:12px 0 6px; letter-spacing:.5px; }
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
</style>
</head>
<body>
<div class="action-bar">
    <a class="btn btn-excel" href="?date=<?=urlencode($reportDate)?>&format=excel" target="_blank"><i class="fa-solid fa-file-excel"></i> Download Excel</a>
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

    <!-- ② UTILITY -->
    <h2>2. UTILITY USAGE SUMMARY</h2>
    <table>
        <thead><tr><th>UTILITY</th><th>PERIOD</th><th>USAGE</th><th>COST (Rp.)</th></tr></thead>
        <tbody>
            <tr>
                <td rowspan="2" class="bold cen mid">ELECTRICITY</td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($elecLY, 0)?> kWh</td>
                <td class="num"><?=repFmtRupiah($elecLY * $TARIF_LISTRIK)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num"><?=repFmtIndo($elecToday, 0)?> kWh</td>
                <td class="num"><?=repFmtRupiah($elecToday * $TARIF_LISTRIK)?></td>
            </tr>
            <tr>
                <td rowspan="2" class="bold cen mid">WATER</td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($waterLY, 0)?> m&sup3;</td>
                <td class="num"><?=repFmtRupiah($waterLY * $TARIF_AIR)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num"><?=repFmtIndo($waterToday, 0)?> m&sup3;</td>
                <td class="num"><?=repFmtRupiah($waterToday * $TARIF_AIR)?></td>
            </tr>
            <tr>
                <td rowspan="2" class="bold cen mid">GAS</td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($gasLY, 0)?> kg</td>
                <td class="num"><?=repFmtRupiah($gasLY * $TARIF_GAS)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num"><?=repFmtIndo($gasToday, 0)?> kg</td>
                <td class="num"><?=repFmtRupiah($gasToday * $TARIF_GAS)?></td>
            </tr>
            <tr>
                <td rowspan="2" class="bold cen mid">FUEL</td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($fuelLY, 0)?> Liter</td>
                <td class="num"><?=repFmtRupiah($fuelLY * $TARIF_FUEL)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num"><?=repFmtIndo($fuelToday, 0)?> Liter</td>
                <td class="num"><?=repFmtRupiah($fuelToday * $TARIF_FUEL)?></td>
            </tr>
        </tbody>
    </table>

    <!-- ③ ENGINEERING ACTIVITIES -->
    <h2>3. ENGINEERING ACTIVITIES</h2>
    <table>
        <thead><tr><th>DEPARTMENT</th><th>ACTIVITY DETAIL</th><th>STATUS</th></tr></thead>
        <tbody>
        <?php foreach ($divisions as $d) {
            $list = $actByDiv[$d] ?? [];
            if (count($list) === 0) $list = [['name' => '-', 'status' => '-']];
            $rows = count($list);
            foreach ($list as $idx => $item) {
                $stLabel = repStatusLabel($item['status']);
        ?>
            <tr>
                <?php if ($idx === 0) { ?>
                    <td rowspan="<?=$rows?>" class="bold cen mid"><?=htmlspecialchars($d)?></td>
                <?php } ?>
                <td><ul class="dot"><li><?=htmlspecialchars($item['name'])?></li></ul></td>
                <td class="cen mid">&check; <?=htmlspecialchars($stLabel)?></td>
            </tr>
        <?php } } ?>
        </tbody>
    </table>

</div>
</body>
</html>
