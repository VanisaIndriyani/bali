<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance();
$user = currentUser();
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? 'engineer');
$userName = (string)($user['name'] ?? 'User');

/* ---------- 1. PARAMETER TANGGAL LAPORAN ---------- */
$dateRaw = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateRaw)) {
    $dateRaw = date('Y-m-d');
}
$reportDate = $dateRaw;
$reportDateObj = DateTime::createFromFormat('Y-m-d', $reportDate);
$reportDateLabel = $reportDateObj ? strtoupper($reportDateObj->format('j F Y')) : strtoupper($reportDate);
$lastYearSameDay = date('Y-m-d', strtotime($reportDate . ' -1 year'));

/* ---------- 2. HELPER FUNGSI FORMAT (SAMA DENGAN INDEX.PHP) ---------- */
function repFmtIndo($v, $dec = 2) {
    $v = (float)$v;
    return number_format($v, $dec, ',', '.');
}
function repFmtRupiah($v) {
    $v = (float)$v;
    if ($v == 0) return '0';
    return number_format($v, 0, ',', '.');
}
function repFmtPercent($v) {
    return repFmtIndo((float)$v, 1) . '%';
}

/* ---------- 3. DATA SECTION 1 : KEY PERFORMANCE INDICATORS (KPIs) ---------- */
$occToday = 0; $occLY = 0; $itr = 0; $mu = 0; $gitbRank = '-';

$kpiRow = $db->fetchOne(
    "SELECT occupancy_rate, itr_score, mu_score, gitb_rank 
     FROM daily_logs 
     WHERE log_date = ? AND status IN ('approved','reviewed','pending') 
     ORDER BY FIELD(status,'approved','reviewed','pending') LIMIT 1",
    [$reportDate]
);
if ($kpiRow) {
    $occToday = (float)($kpiRow['occupancy_rate'] ?? 0);
    $itr = (float)($kpiRow['itr_score'] ?? 0);
    $mu = (float)($kpiRow['mu_score'] ?? 0);
    $gitbRank = !empty($kpiRow['gitb_rank']) ? $kpiRow['gitb_rank'] : '-';
}
$kpiLYRow = $db->fetchOne("SELECT occupancy_rate FROM daily_logs WHERE log_date = ? LIMIT 1", [$lastYearSameDay]);
if ($kpiLYRow) $occLY = (float)($kpiLYRow['occupancy_rate'] ?? 0);

$kpiData = [
    ['Occupancy Rate', repFmtPercent($occLY), repFmtPercent($occToday), $itr > 0 ? repFmtIndo($itr,1) : '-', $mu > 0 ? repFmtIndo($mu,1) : '-', $gitbRank],
];

/* ---------- 4. DATA SECTION 2 : UTILITY USAGE SUMMARY ---------- */
$elecLY = $elecToday = 0;
$waterLY = $waterToday = 0;
$gasLY = $gasToday = 0;
$fuelLY = $fuelToday = 0;

$utRow = $db->fetchOne(
    "SELECT electricity_today, electricity_ly, water_today, water_ly, gas_today, gas_ly, fuel_today, fuel_ly 
     FROM daily_logs WHERE log_date = ? AND status IN ('approved','reviewed','pending') 
     ORDER BY FIELD(status,'approved','reviewed','pending') LIMIT 1",
    [$reportDate]
);
if ($utRow) {
    $elecToday = (float)($utRow['electricity_today'] ?? 0);
    $elecLY = (float)($utRow['electricity_ly'] ?? 0);
    $waterToday = (float)($utRow['water_today'] ?? 0);
    $waterLY = (float)($utRow['water_ly'] ?? 0);
    $gasToday = (float)($utRow['gas_today'] ?? 0);
    $gasLY = (float)($utRow['gas_ly'] ?? 0);
    $fuelToday = (float)($utRow['fuel_today'] ?? 0);
    $fuelLY = (float)($utRow['fuel_ly'] ?? 0);
}

$tarifListrik = 1850; $tarifAir = 9600; $tarifGas = 24500; $tarifFuel = 17450;

/* ---------- 5. DATA SECTION 3 : ENGINEERING ACTIVITIES (4 DIVISI) ---------- */
$divisions = ['OPERATION', 'MAINTENANCE', 'PROJECT', 'LANDSCAPE'];
$actByDiv = [];
foreach ($divisions as $d) {
    $actByDiv[$d] = [];
}

$actRows = $db->fetchAll(
    "SELECT ac.division, ac.activity_name, ac.status 
     FROM activity_counters ac
     INNER JOIN daily_logs dl ON dl.id = ac.daily_log_id
     WHERE dl.log_date = ? AND dl.status IN ('approved','reviewed','pending')
     ORDER BY FIELD(ac.division,'OPERATION','MAINTENANCE','PROJECT','LANDSCAPE'), ac.id ASC",
    [$reportDate]
);
foreach ($actRows as $ar) {
    $d = strtoupper((string)($ar['division'] ?? ''));
    if (!in_array($d, $divisions)) continue;
    $actByDiv[$d][] = [
        'name' => (string)($ar['activity_name'] ?? '-'),
        'status' => strtolower((string)($ar['status'] ?? 'pending')),
    ];
}

/* Master Template Fallback */
foreach ($divisions as $d) {
    if (count($actByDiv[$d]) === 0) {
        $masterRows = $db->fetchAll(
            "SELECT activity_name FROM activity_masters WHERE LOWER(division) = ? AND status = 'active' ORDER BY id ASC LIMIT 4",
            [strtolower($d)]
        );
        foreach ($masterRows as $mr) {
            $actByDiv[$d][] = ['name' => (string)($mr['activity_name'] ?? '-'), 'status' => 'in_progress'];
        }
    }
}

/* ---------- 6. HELPER STATUS LABEL ---------- */
function repStatusLabel($st) {
    if ($st === 'complete' || $st === 'completed') return 'Complete';
    if ($st === 'in_progress' || $st === 'progress') return 'In Progress';
    if ($st === 'pending') return 'Pending';
    if ($st === '-') return '-';
    return ucfirst($st);
}

/* ---------- 7. MODE EXCEL = EXIT AWAL (HINDARI UNCLEARED BRACE DI PHP LINT) ---------- */
$format = isset($_GET['format']) ? strtolower(cleanInput($_GET['format'])) : 'print';
$fileName = 'Daily_Engineering_Summary_' . $reportDate;

if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF";
    require __DIR__ . '/_daily_summary_xls.php';
    exit;
}

/* ---------- 8. DEFAULT = PRINT / PDF HTML ---------- */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Daily Engineering Summary - <?=$reportDate?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
    * { box-sizing: border-box; }
    html, body { 
        margin: 0; padding: 0; 
        font-family: Arial, Helvetica, sans-serif; 
        color: #000; background: #f3f4f6; font-size: 14px; line-height: 1.35;
    }
    @page { size: A4; margin: 14mm 12mm 12mm 12mm; }
    @media print {
        body { background: #fff; }
        .page-wrap { background: #fff !important; padding: 0 !important; margin: 0 !important; box-shadow: none !important; width: 100% !important; max-width: 100% !important; }
        .action-bar { display: none !important; }
        h1 { margin-top: 0 !important; }
        table { page-break-inside: avoid; }
    }
    .action-bar {
        max-width: 210mm; margin: 0 auto 12px;
        display:flex; gap:8px; justify-content: flex-end; flex-wrap: wrap;
        padding: 0 8px;
    }
    .btn {
        display:inline-flex; align-items:center; gap:6px;
        padding:10px 16px; border-radius:10px; font-weight:700; font-size:13px;
        text-decoration:none; border:1px solid transparent; cursor:pointer;
        transition: all .15s ease;
    }
    .btn-excel { background:#16a34a; color:#fff; box-shadow:0 1px 2px rgba(22,163,74,.15); }
    .btn-excel:hover { background:#15803d; }
    .btn-pdf { background:#2563eb; color:#fff; box-shadow:0 1px 2px rgba(37,99,235,.15); }
    .btn-pdf:hover { background:#1d4ed8; }
    .btn-print { background:#111827; color:#fff; box-shadow:0 1px 2px rgba(17,24,39,.15); }
    .btn-print:hover { background:#000; }

    .page-wrap {
        width: 210mm; max-width: 100%;
        min-height: 297mm;
        margin: 0 auto;
        background: #fff;
        padding: 10mm 12mm 12mm;
        box-shadow: 0 6px 24px rgba(0,0,0,0.08);
    }

    h1 {
        font-size: 28px; letter-spacing: 3px; text-align: center;
        margin: 0 0 10px; font-weight: 900; line-height: 1.15;
    }
    .date-label {
        font-size: 16px; font-weight: 800; margin: 0 0 14px;
    }
    h2 {
        font-size: 16px; font-weight: 900; margin: 12px 0 6px;
        letter-spacing: 0.5px;
    }
    table {
        width: 100%; border-collapse: collapse;
    }
    th {
        background: #d9d9d9;
        border: 1px solid #000;
        padding: 6px 8px;
        font-weight: 800;
        text-align: center;
        font-size: 12px;
    }
    td {
        border: 1px solid #000;
        padding: 5px 8px;
        font-size: 12px;
        vertical-align: top;
        color: #000;
    }
    td.num { text-align: right; font-variant-numeric: tabular-nums; }
    td.cen { text-align: center; }
    td.bold { font-weight: 800; }
    td.mid { vertical-align: middle; }
    ul.dot { margin: 0; padding-left: 14px; }
    ul.dot li { margin: 0; padding: 0; line-height: 1.3; }
    .sign-footer {
        width: 100%;
        margin-top: 18px;
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;
        page-break-inside: avoid;
    }
    .sign-box { text-align:center; font-size: 12px; }
    .sign-box .lbl { font-weight: 600; margin-bottom: 46px; }
    .sign-box .line { border-top: 1px solid #000; padding-top: 5px; font-weight: 800; }
</style>
</head>
<body>
<?php
$urlExcel = '?date=' . urlencode($reportDate) . '&format=excel';
?>
<div class="action-bar">
    <a class="btn btn-excel" href="<?=htmlspecialchars($urlExcel)?>" target="_blank">
        <i class="fa-solid fa-file-excel"></i> Download Excel
    </a>
    <button type="button" class="btn btn-pdf" onclick="window.print()">
        <i class="fa-solid fa-file-pdf"></i> Save as PDF / Print
    </button>
</div>

<div class="page-wrap">
    <h1>DAILY ENGINEERING<br>SUMMARY REPORT</h1>
    <div class="date-label">DATE: <?=$reportDateLabel?></div>

    <!-- ① KPIs -->
    <h2>1. KEY PERFORMANCE INDICATORS (KPIs)</h2>
    <table>
        <thead>
            <tr>
                <th>METRIC</th>
                <th>LAST YEAR (LY)</th>
                <th>TODAY</th>
                <th>ITR</th>
                <th>M&amp;U</th>
                <th>GITB RANK</th>
            </tr>
        </thead>
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
        <thead>
            <tr>
                <th>UTILITY</th>
                <th>PERIOD</th>
                <th>USAGE</th>
                <th>COST (Rp.)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td rowspan="2" class="bold cen mid">ELECTRICITY</td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($elecLY, 0)?> kWh</td>
                <td class="num"><?=repFmtRupiah($elecLY * $tarifListrik)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num"><?=repFmtIndo($elecToday, 0)?> kWh</td>
                <td class="num"><?=repFmtRupiah($elecToday * $tarifListrik)?></td>
            </tr>
            <tr>
                <td rowspan="2" class="bold cen mid">WATER</td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($waterLY, 0)?> m&sup3;</td>
                <td class="num"><?=repFmtRupiah($waterLY * $tarifAir)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num"><?=repFmtIndo($waterToday, 0)?> m&sup3;</td>
                <td class="num"><?=repFmtRupiah($waterToday * $tarifAir)?></td>
            </tr>
            <tr>
                <td rowspan="2" class="bold cen mid">GAS</td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($gasLY, 0)?> kg</td>
                <td class="num"><?=repFmtRupiah($gasLY * $tarifGas)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num"><?=repFmtIndo($gasToday, 0)?> kg</td>
                <td class="num"><?=repFmtRupiah($gasToday * $tarifGas)?></td>
            </tr>
            <tr>
                <td rowspan="2" class="bold cen mid">FUEL</td>
                <td class="cen">(LY)</td>
                <td class="num"><?=repFmtIndo($fuelLY, 0)?> Liter</td>
                <td class="num"><?=repFmtRupiah($fuelLY * $tarifFuel)?></td>
            </tr>
            <tr>
                <td class="cen">(TODAY)</td>
                <td class="num"><?=repFmtIndo($fuelToday, 0)?> Liter</td>
                <td class="num"><?=repFmtRupiah($fuelToday * $tarifFuel)?></td>
            </tr>
        </tbody>
    </table>

    <!-- ③ ENGINEERING ACTIVITIES -->
    <h2>3. ENGINEERING ACTIVITIES</h2>
    <table>
        <thead>
            <tr>
                <th>DEPARTMENT</th>
                <th>ACTIVITY DETAIL</th>
                <th>STATUS</th>
            </tr>
        </thead>
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
                <td class="act-cell">
                    <ul class="dot"><li><?=htmlspecialchars($item['name'])?></li></ul>
                </td>
                <td class="cen mid">&check; <?=htmlspecialchars($stLabel)?></td>
            </tr>
        <?php } } ?>
        </tbody>
    </table>

    <!-- SIGNATURE -->
    <div class="sign-footer">
        <div class="sign-box">
            <div class="lbl">Prepared By:</div>
            <div class="line"><?=htmlspecialchars($userName)?></div>
        </div>
        <div class="sign-box">
            <div class="lbl">Reviewed By:</div>
            <div class="line">Supervisor / Manager</div>
        </div>
        <div class="sign-box">
            <div class="lbl">Approved By:</div>
            <div class="line">Chief Engineer / EAM</div>
        </div>
    </div>
</div>
</body>
</html>
