<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance();
$user = currentUser();
$userRole = (string)($user['role'] ?? 'engineer');

/* ---------- 1. PARAMETER TANGGAL (SINGLE ?date= ATAU RANGE ?date_from=&date_to=) ---------- */
$def = date('Y-m-d');
$rangeMode = false;
$reportDate = $def;           /* PRE-DECLARE SAFE DEFAULT */
$reportDateFrom = $def;       /* PRE-DECLARE SAFE DEFAULT */
$reportDateTo   = $def;       /* PRE-DECLARE SAFE DEFAULT */
$dateFromRaw = $_GET['date_from'] ?? '';
$dateToRaw   = $_GET['date_to']   ?? '';
$dateRaw     = $_GET['date']      ?? '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateFromRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateToRaw)) {
    $rangeMode = true;
    $reportDateFrom = (string)$dateFromRaw;
    $reportDateTo   = (string)$dateToRaw;
    if (strtotime($reportDateFrom) > strtotime($reportDateTo)) { $t = $reportDateFrom; $reportDateFrom = $reportDateTo; $reportDateTo = $t; }
    $reportDate = $reportDateTo;
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateRaw)) {
    $reportDate = (string)$dateRaw;
    $reportDateFrom = $reportDate;
    $reportDateTo   = $reportDate;
} /* ELSE: pakai default $def yang sudah di-assign diatas */
$reportDateObj = DateTime::createFromFormat('Y-m-d', $reportDate);
$reportFromObj = DateTime::createFromFormat('Y-m-d', $reportDateFrom);
$reportToObj   = DateTime::createFromFormat('Y-m-d', $reportDateTo);
if ($rangeMode || $reportDateFrom !== $reportDateTo) {
    $reportDateLabel = strtoupper(($reportFromObj ? $reportFromObj->format('j F Y') : $reportDateFrom)) . '  S/D  ' . strtoupper(($reportToObj ? $reportToObj->format('j F Y') : $reportDateTo));
} else {
    $reportDateLabel = $reportDateObj ? strtoupper($reportDateObj->format('j F Y')) : strtoupper($reportDate);
}

/* ---------- 2. HELPER ---------- */
function repFmtIndo($v, $dec = 2) { $v=(float)$v; return number_format($v, $dec, ',', '.'); }
function repActHeurStatus($title) {
    $t = trim((string)$title);
    if (strlen($t) < 1) return 'complete';
    $tl = strtolower($t);
    $isProg = (strpos($tl,'progress')!==false) || (strpos($tl,'install')!==false)
           || (strpos($tl,'perbaikan')!==false) || (strpos($tl,'new ')!==false)
           || (strpos($tl,'buat')!==false) || (strpos($tl,'meeting')!==false);
    return $isProg ? 'progress' : 'complete';
}
function repStatusLabel($st) {
    if ($st==='complete'||$st==='completed') return 'Complete';
    if ($st==='in_progress'||$st==='progress') return 'In Progress';
    if ($st==='pending') return 'Pending';
    if ($st==='-') return '-';
    return ucfirst($st);
}
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

/* ---------- 3. DATA ACTIVITY PER DIVISI ---------- */
$divisions = ['OPERATION', 'MAINTENANCE', 'PROJECT', 'LANDSCAPE'];
$actByDiv = [];
foreach ($divisions as $d) $actByDiv[$d] = [];
try {
    $actRows = $db->fetchAll(
        "SELECT dla.category, dla.activity_title, dla.notes, dla.pic
         FROM daily_log_activities dla
         INNER JOIN daily_logs dl ON dl.id = dla.daily_log_id
         WHERE dl.log_date BETWEEN ? AND ? AND dl.status IN ('approved','reviewed','pending')
         ORDER BY FIELD(dla.category,'operation','maintenance','project','landscape'), dla.id ASC",
        [$reportDateFrom, $reportDateTo]
    );
    foreach ($actRows as $ar) {
        $d = strtoupper((string)($ar['category'] ?? ''));
        if (!in_array($d, $divisions)) continue;
        $title = (string)($ar['activity_title'] ?? '-');
        if (strlen(trim($title)) < 1) continue;
        $notes = (string)($ar['notes'] ?? '');
        $pic = (string)($ar['pic'] ?? '');
        $actByDiv[$d][] = ['name' => $title, 'status' => repActHeurStatus($title), 'notes' => $notes, 'pic' => $pic];
    }
} catch (Exception $e) { /* daily_log_activities not exists */ }

/* Fallback ke activity_masters jika kosong */
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
                $actByDiv[$d][] = ['name' => $name, 'status' => $status, 'notes' => '', 'pic' => ''];
            }
        }
    }
} catch (Exception $e) { /* ignore */ }

/* ---------- 4. FLATTEN ALL ROWS UNTUK CSV + HTML ---------- */
$allRows = [];
foreach ($divisions as $d) {
    foreach ($actByDiv[$d] as $row) {
        $allRows[] = [
            'dept' => $d,
            'activity' => $row['name'],
            'status' => repStatusLabel($row['status']),
            'pic' => $row['pic'],
            'notes' => $row['notes'],
        ];
    }
}

/* ---------- 5. RENDER MODE ---------- */
$format = isset($_GET['format']) ? strtolower(cleanInput($_GET['format'])) : 'print';
$fileName = 'Engineering_Activities_' . $reportDateFrom . '_' . $reportDateTo;

if ($format === 'excel') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF";

    $sep = ';';
    $out = '';

    $out .= 'ENGINEERING ACTIVITIES REPORT' . $sep . $sep . $sep . $sep . "\n";
    $out .= 'DATE' . $sep . $reportDateLabel . $sep . $sep . $sep . $sep . "\n";
    $out .= "\n";

    if (count($allRows) === 0) {
        $out .= 'No activities data found for selected date.' . "\n";
    } else {
        $out .= 'DEPARTMENT' . $sep . 'ACTIVITY DETAIL' . $sep . 'STATUS' . $sep . 'PIC' . $sep . 'NOTES' . "\n";
        foreach ($allRows as $r) {
            $out .= repCsvEscape($r['dept']) . $sep
                  . repCsvEscape($r['activity']) . $sep
                  . repCsvEscape($r['status']) . $sep
                  . repCsvEscape($r['pic']) . $sep
                  . repCsvEscape($r['notes']) . "\n";
        }
    }

    echo $out;
    exit;
}

/* ====================================================== */
/* MODE PRINT / HTML DEFAULT                               */
/* ====================================================== */
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?= $fileName ?></title>
<style>
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #0f172a; font-size: 12px; background: #fff; }
    .wrap { max-width: 1100px; margin: 0 auto; padding: 14mm 14mm; }
    .hd { margin-bottom: 14px; border-bottom: 1px solid #cbd5e1; padding-bottom: 10px; }
    .hd h1 { margin: 0 0 4px 0; font-size: 18px; font-weight: 800; letter-spacing: 0.2px; }
    .hd .sub { color: #475569; font-size: 11px; font-weight: 600; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; vertical-align: top; font-size: 11px; }
    th { background: #f1f5f9; font-weight: 700; color: #334155; font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; }
    td.dept { font-weight: 800; width: 130px; color: #0f172a; text-transform: uppercase; font-size: 10px; letter-spacing: 0.3px; }
    td.status { width: 110px; font-weight: 700; }
    td.pic { width: 120px; color: #475569; }
    .st-progress { color: #b45309; }
    .st-complete { color: #047857; }
    .st-pending { color: #7c3aed; }
    tr.dept-sep td { background: #f8fafc; border-top: 2px solid #cbd5e1; }
    .empty { padding: 20px; text-align: center; color: #94a3b8; font-style: italic; border: 1px dashed #cbd5e1; border-radius: 8px; margin-top: 14px; }
    .ft { margin-top: 16px; display: flex; justify-content: space-between; color: #64748b; font-size: 10px; border-top: 1px solid #e2e8f0; padding-top: 8px; }
    @media print {
        @page { margin: 14mm 12mm; size: A4 portrait; }
        body { font-size: 11px; }
        .wrap { padding: 0; max-width: 100%; }
        th, td { padding: 4px 6px; }
    }
</style>
</head>
<body>
<div class="wrap">
    <div class="hd">
        <h1>ENGINEERING ACTIVITIES REPORT</h1>
        <div class="sub">DATE: <?= $reportDateLabel ?></div>
    </div>

    <?php if (count($allRows) === 0): ?>
        <div class="empty">No activities data found for selected date.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th style="width:130px">Department</th>
                <th>Activity Detail</th>
                <th style="width:110px">Status</th>
                <th style="width:120px">PIC</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $lastDept = '';
            foreach ($allRows as $r):
                $stCls = 'st-complete';
                if (stripos($r['status'], 'progress') !== false) $stCls = 'st-progress';
                elseif (stripos($r['status'], 'pending') !== false) $stCls = 'st-pending';
                $sepCls = ($lastDept !== '' && $r['dept'] !== $lastDept) ? 'dept-sep' : '';
                $lastDept = $r['dept'];
            ?>
            <tr class="<?= $sepCls ?>">
                <td class="dept"><?= htmlspecialchars($r['dept']) ?></td>
                <td><?= htmlspecialchars($r['activity']) ?></td>
                <td class="status <?= $stCls ?>"><?= htmlspecialchars($r['status']) ?></td>
                <td class="pic"><?= htmlspecialchars($r['pic']) ?></td>
                <td><?= htmlspecialchars($r['notes']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="ft">
        <span>Generated: <?= date('Y-m-d H:i:s') ?></span>
        <span>Page 1 / 1</span>
    </div>
</div>
<script>window.addEventListener('load', function () { if (window.location.search.indexOf('autoprint') !== -1 || window.location.search.indexOf('format=print') === -1) { setTimeout(function(){ window.print(); }, 400); } });</script>
</body>
</html>
