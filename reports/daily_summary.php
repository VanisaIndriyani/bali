<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

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
$TARIF_LISTRIK = 1850; $TARIF_AIR = 9600; $TARIF_GAS = 24500; $TARIF_FUEL = 17450;

/* ---------- 4. DATA UTILITY (WRAP TRY/CATCH SUPAYA TABLE TIDAK ADA = TIDAK FATAL ERROR) — SUPPORT RANGE DATE ---------- */
$elecToday = $waterToday = $gasToday = $fuelToday = 0;
$elecLY = $waterLY = $gasLY = $fuelLY = 0;
$lyRangeFrom = date('Y-m-d', strtotime($reportDateFrom . ' -1 year'));
$lyRangeTo   = date('Y-m-d', strtotime($reportDateTo   . ' -1 year'));
try {
    $sumToday = $db->fetchOne("SELECT
        COALESCE(SUM(total_electricity),0) as elec,
        COALESCE(SUM(total_water),0)       as water,
        COALESCE(SUM(total_gas),0)         as gas,
        COALESCE(SUM(total_fuel),0)        as fuel
        FROM daily_logs WHERE status='approved' AND log_date BETWEEN ? AND ?",
        [$reportDateFrom, $reportDateTo]);
    if ($sumToday) {
        $elecToday  = (float)($sumToday['elec']  ?? 0);
        $waterToday = (float)($sumToday['water'] ?? 0);
        $gasToday   = (float)($sumToday['gas']   ?? 0);
        $fuelToday  = (float)($sumToday['fuel']  ?? 0);
    }
    $sumLY = $db->fetchOne("SELECT
        COALESCE(SUM(total_electricity),0) as elec,
        COALESCE(SUM(total_water),0)       as water,
        COALESCE(SUM(total_gas),0)         as gas,
        COALESCE(SUM(total_fuel),0)        as fuel
        FROM daily_logs WHERE status='approved' AND log_date BETWEEN ? AND ?",
        [$lyRangeFrom, $lyRangeTo]);
    if ($sumLY) {
        $elecLY  = (float)($sumLY['elec']  ?? 0);
        $waterLY = (float)($sumLY['water'] ?? 0);
        $gasLY   = (float)($sumLY['gas']   ?? 0);
        $fuelLY  = (float)($sumLY['fuel']  ?? 0);
    }
} catch (Exception $e) { /* utility kosong */ }

/* ---------- 5. KPI OCCUPANCY + ITR + M&U + GITB (RANGE DATE SUPPORT) ---------- */
$kpiData = [['Occupancy Rate','- %','- %','-','-','-']];
try {
    $defaultLyOcc = 70; $defaultTargetOcc = 80;
    $occLYRow = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE log_date BETWEEN ? AND ? AND status = 'approved' AND occ_rate > 0", [$lyRangeFrom, $lyRangeTo]);
    $occReportRow = $db->fetchOne("SELECT COALESCE(AVG(occ_rate),0) as avg_occ, COUNT(*) as cnt FROM daily_logs WHERE log_date BETWEEN ? AND ? AND status = 'approved' AND occ_rate > 0", [$reportDateFrom, $reportDateTo]);
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

/* ---------- 6. ENGINEERING ACTIVITIES PER DIVISI (100% MATCH DASHBOARD, RANGE DATE BUKAN SINGLE) -------------- */
$divisions = ['OPERATION', 'MAINTENANCE', 'PROJECT', 'LANDSCAPE'];
$actByDiv = [];
$divLower = ['OPERATION'=>'operation','MAINTENANCE'=>'maintenance','PROJECT'=>'project','LANDSCAPE'=>'landscape'];
foreach ($divisions as $d) $actByDiv[$d] = [];

/* (A) LOOP 4 DIVISI: Query daily_log_activities BETWEEN date_from s/d date_to (BUKAN SINGLE DATE!)  */
try {
    foreach ($divisions as $d) {
        $cat = $divLower[$d];
        $baseWhere = "WHERE dla.category = ? AND dl.status IN ('approved','reviewed','pending') AND dl.log_date BETWEEN ? AND ?";
        $p = [$cat, $reportDateFrom, $reportDateTo];
        if ($userRole === 'engineer') { $baseWhere .= " AND dl.engineer_id = ?"; $p[] = $userId; }
        $sql = "SELECT dla.activity_title, DATE(dl.log_date) as log_date, u.name as engineer_name
                FROM daily_log_activities dla
                INNER JOIN daily_logs dl ON dl.id = dla.daily_log_id
                LEFT JOIN users u ON u.id = dl.engineer_id
                $baseWhere
                ORDER BY dl.log_date DESC, dla.sort_order ASC, dla.id DESC
                LIMIT 500";
        $rows = $db->fetchAll($sql, $p);
        foreach ($rows as $r) {
            $t = trim((string)($r['activity_title'] ?? ''));
            if (strlen($t) < 1) continue;
            $st = repActHeurStatus($t);
            $actByDiv[$d][] = ['name' => $t, 'status' => $st, 'date' => (string)($r['log_date'] ?? ''), 'eng' => (string)($r['engineer_name'] ?? '')];
        }
    }
} catch (Throwable $e) { /* daily_log_activities table not exists */ }

/* (B) MERGE ALL MASTER ACTIVITIES — TIDAK PEDULI ADA DAILY ATAU TIDAK (TANPA IF count===0, TANPA LIMIT 4) — POLA 100% MATCH DASHBOARD INDEX.PHP */
try {
    $existTitle = [];
    foreach ($divisions as $d) {
        $existTitle[$d] = [];
        foreach (($actByDiv[$d] ?? []) as $r) {
            $t = mb_strtolower(trim((string)($r['name'] ?? '')));
            if ($t !== '') $existTitle[$d][$t] = true;
        }
    }
    $allMaster = $db->fetchAll("SELECT division, activity_name, sort_order, created_at, status_default FROM activity_masters WHERE status = 'active' ORDER BY FIELD(division,'operation','maintenance','project','landscape'), sort_order ASC, id ASC");
    foreach ($allMaster as $m) {
        $dv = strtoupper((string)($m['division'] ?? 'operation'));
        if (!in_array($dv, $divisions, true)) $dv = 'OPERATION';
        if (!isset($actByDiv[$dv]) || !is_array($actByDiv[$dv])) $actByDiv[$dv] = [];
        $title = trim((string)($m['activity_name'] ?? ''));
        if ($title === '') continue;
        $key = mb_strtolower($title);
        if (isset($existTitle[$dv][$key])) continue; /* skip jika judul sudah ada dari daily log (hindari DOUBLE) */
        $stRaw = strtolower((string)($m['status_default'] ?? 'progress'));
        $st = in_array($stRaw,['complete','completed','progress','pending']) ? $stRaw : repActHeurStatus($title);
        $actByDiv[$dv][] = [
            'name' => $title,
            'status' => ($st === 'completed' ? 'complete' : $st),
            'date' => substr((string)($m['created_at'] ?? ''), 0, 10),
            'eng' => '- (Master Activity)'
        ];
    }
} catch (Throwable $e) { /* activity_masters table not exists = biarkan kosong */ }

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
        $first = true;
        foreach ($list as $item) {
            $meta = [];
            if ($f = repFmtDateAct($item['date'] ?? '')) $meta[] = $f;
            if (trim((string)($item['eng'] ?? '')) !== '') $meta[] = (string)$item['eng'];
            $detail = (string)($item['name'] ?? '');
            if (count($meta) > 0) $detail .= '   [ ' . implode(' | ', $meta) . ' ]';
            $st = repStatusLabel($item['status'] ?? 'progress');
            $out .= ($first ? repCsvEscape($d) . '  [' . $stSummary . ']' : '') . $sep
                  . repCsvEscape($detail) . $sep
                  . repCsvEscape(($first ? $stSummary . '  |  ' : '') . $st) . "\n";
            $first = false;
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
    /* ===== ENGINEERING ACTIVITIES (100% SAMA DASHBOARD CARD #2) ===== */
    table.act { border:1px solid #9ca3af; border-radius:10px; overflow:hidden;}
    table.act th { background:#e5e7eb; text-align:left; font-weight:800; letter-spacing:.08em; padding:9px 10px; font-size:12px; color:#111; }
    table.act th.dept-col { width: 20%; }
    table.act th.status-col { width: 22%; }
    table.act td { padding: 10px 10px; vertical-align:top; font-size: 12px; color:#111;}
    table.act td.dept { font-weight:900; font-size:13px; letter-spacing:.05em; }
    table.act td.op-bg { background:#eff6ff33; }
    table.act td.mt-bg { background:#fffbeb33; }
    table.act td.pr-bg { background:#f5f3ff33; }
    table.act td.la-bg { background:#ecfdf533; }
    .dept-ico { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:8px; color:#fff; font-size:12px; margin-right:8px;}
    .ico-op { background:#1d4ed8; }
    .ico-mt { background:#b45309; }
    .ico-pr { background:#6d28d9; }
    .ico-la { background:#047857; }
    .act-list { list-style:none; margin:0; padding:0;}
    .act-list li { padding:2px 0 5px 0; line-height:1.35;}
    .act-name { font-weight:700; color:#0f172a; margin-right:6px;}
    .meta { margin-top:3px; display:flex; gap:5px; flex-wrap:wrap;}
    .meta-tag { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700;}
    .meta-date { background:#f3f4f6; color:#4b5563; border:1px solid #e5e7eb;}
    .meta-eng { background:#eef2ff; color:#3730a3; border:1px solid #e0e7ff;}
    .st-group { display:flex; flex-direction:column; gap:5px; align-items:flex-end;}
    .st-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 10px 4px 8px; border-radius:999px; font-size:10.5px; font-weight:800; letter-spacing:.02em;}
    .st-nodata { padding:4px 11px; border-radius:999px; background:#f9fafb; color:#6b7280; border:1px solid #d1d5db; font-size:11px; font-weight:700;}
    .st-prog { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
    .st-done { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .st-pill .count { background:#fff; font-size:9.5px; padding:1px 6px; border-radius:999px; border:1px solid; font-weight:900;}
    .st-prog .count { border-color:#fde68a; color:#92400e;}
    .st-done .count { border-color:#a7f3d0; color:#065f46;}
    .empty-act { padding:4px 0; color:#9ca3af; font-style:italic;}
    .empty-act i { margin-right:6px; opacity:60%;}
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

    <!-- ③ ENGINEERING ACTIVITIES (100% MATCH DASHBOARD CARD #2 + RANGE DATE BUKAN SINGLE) -->
    <h2>3. ENGINEERING ACTIVITIES</h2>
    <table class="act">
        <colgroup>
            <col class="dept-col"><col><col class="status-col">
        </colgroup>
        <thead>
            <tr><th class="dept-col">DEPARTMENT</th><th>ACTIVITY DETAIL</th><th class="status-col">STATUS</th></tr>
        </thead>
        <tbody>
        <?php
        $bgMap = ['OPERATION'=>'op-bg','MAINTENANCE'=>'mt-bg','PROJECT'=>'pr-bg','LANDSCAPE'=>'la-bg'];
        $icoMap = ['OPERATION'=>'ico-op fa-gears','MAINTENANCE'=>'ico-mt fa-wrench','PROJECT'=>'ico-pr fa-clipboard-list','LANDSCAPE'=>'ico-la fa-seedling'];
        foreach ($divisions as $d) {
            $list = $actByDiv[$d] ?? [];
            $stN = repCountActStatus($list);
            $bg = $bgMap[$d] ?? '';
            $ico = $icoMap[$d] ?? 'ico-op fa-list';
        ?>
            <tr>
                <td class="dept <?=$bg?>">
                    <span class="dept-ico <?=$ico?>"><i class="fa-solid <?=substr($ico,6)?>"></i></span><?=htmlspecialchars($d)?>
                </td>
                <td class="<?=$bg?>">
                    <?php if (count($list) === 0): ?>
                        <span class="empty-act"><i class="fa-solid fa-box-open"></i>Belum ada aktivitas bulan ini.</span>
                    <?php else: ?>
                        <ul class="act-list">
                        <?php foreach ($list as $item):
                            $name = (string)($item['name'] ?? '');
                            $date = (string)($item['date'] ?? '');
                            $eng  = trim((string)($item['eng'] ?? ''));
                            $fDate = repFmtDateAct($date);
                        ?>
                            <li>
                                <span class="act-name">&bull; <?=htmlspecialchars($name)?></span>
                                <?php if ($fDate !== '' || $eng !== ''): ?>
                                    <div class="meta">
                                        <?php if ($fDate !== ''): ?>
                                            <span class="meta-tag meta-date"><i class="fa-solid fa-calendar" style="font-size:9px;opacity:.7;"></i> <?=htmlspecialchars($fDate)?></span>
                                        <?php endif; ?>
                                        <?php if ($eng !== ''): ?>
                                            <span class="meta-tag meta-eng"><i class="fa-solid fa-user-hard-hat" style="font-size:9px;opacity:.8;"></i> <?=htmlspecialchars($eng)?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
                <td class="status-col <?=$bg?>" style="text-align:right;">
                    <?php if (count($list) === 0): ?>
                        <span class="st-nodata">&ndash; No Data &ndash;</span>
                    <?php else: ?>
                        <div class="st-group">
                            <?php if ($stN['prog'] > 0): ?>
                                <span class="st-pill st-prog">
                                    <i class="fa-solid fa-spinner" style="font-size:9.5px;opacity:.8;"></i>
                                    In Progress <span class="count"><?=$stN['prog']?></span>
                                </span>
                            <?php endif; ?>
                            <?php if ($stN['done'] > 0): ?>
                                <span class="st-pill st-done">
                                    <i class="fa-solid fa-circle-check" style="font-size:9.5px;opacity:.8;"></i>
                                    Complete <span class="count"><?=$stN['done']?></span>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

</div>
</body>
</html>
