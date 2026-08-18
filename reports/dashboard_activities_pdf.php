<?php
/**
 * 📋 REPORT PDF: ENGINEERING ACTIVITIES DASHBOARD (3 KOLOM - DATA 100% SAMA PERSIS DASHBOARD INDEX.PHP)
 * - BUKAN query dari activity_masters DOANG ( kayak lists_pdf.php )
 * - SUMBER DATA SAMA PERSIS index.php CARD #2 = (daily_log_activities INNER JOIN daily_logs) + MERGE master default progress
 * - Design = PUTIH BERSIH SLIM PREMIUM (no biru gradient)
 */

require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance();

// ================= 1) SETUP VARIABLE SAMA PERSIS INDEX.PHP =================
$userRole = (string)($user['role']   ?? 'engineer');
$userId   = (int)   ($user['id']     ?? 0);
$userName = trim((string)($user['name'] ?? 'Engineering Staff'));

$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$lastYear   = date('Y', strtotime('-1 year'));
$statusWhere = $userRole === 'engineer' ? "AND engineer_id = $userId" : "";

// Filter custom jika ada ?month=YYYY-MM (opsional)
$monthRaw = $_GET['month'] ?? date('Y-m');
if (preg_match('/^\d{4}-\d{2}$/', (string)$monthRaw)) {
    $monthStart = $monthRaw . '-01';
    $today = date('Y-m-t', strtotime($monthStart)); // last day of that month
}
$monthLabel = (new DateTime($monthStart))->format('M Y');

// Allow custom date range via GET (opsional)
if (isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_from'])) $monthStart = $_GET['date_from'];
if (isset($_GET['date_to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_to']))   $today      = $_GET['date_to'];

$filename = 'Engineering_Activities_' . substr($monthStart,0,7) . '.pdf';
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: max-age=0, must-revalidate');
ob_start();
echo "\xEF\xBB\xBF";

// ================= 2) FUNCTIONS SAMA PERSIS INDEX.PHP =================
function buildActivityListQuery($db, $userRole, $userId, $category, $dateFrom, $dateTo, $limit = 500) {
    $baseWhere = "WHERE dla.category = ? AND dl.status = 'approved' AND dl.log_date BETWEEN ? AND ?";
    $params = [$category, $dateFrom, $dateTo];
    if ($userRole === 'engineer') {
        $baseWhere .= " AND dl.engineer_id = ?";
        $params[] = $userId;
    }
    $sql = "SELECT dla.id, dla.activity_title, DATE(dl.log_date) as log_date, u.name as engineer_name
            FROM daily_log_activities dla
            INNER JOIN daily_logs dl ON dl.id = dla.daily_log_id
            LEFT JOIN users u ON u.id = dl.engineer_id
            $baseWhere
            ORDER BY dl.log_date DESC, dla.sort_order ASC, dla.id DESC
            LIMIT $limit";
    return $db->fetchAll($sql, $params);
}
function actGroupWithStatus(&$list) {
    $out = [];
    foreach ($list as $r) {
        $t = trim((string)($r['activity_title'] ?? ''));
        if (strlen($t) < 1) continue;
        $tl = strtolower($t);
        $isProg = (strpos($tl, 'progress') !== false) || (strpos($tl, 'install') !== false)
               || (strpos($tl, 'perbaikan') !== false) || (strpos($tl, 'new ') !== false)
               || (strpos($tl, 'buat') !== false) || (strpos($tl, 'meeting') !== false)
               || (strpos($tl, 'pemindahan') !== false) || (strpos($tl, 'follow up') !== false)
               || (strpos($tl, 'refinising') !== false) || (strpos($tl, 'rapikan') !== false)
               || (strpos($tl, 'project ') !== false);
        $out[] = ['title'=>$t, 'status'=>$isProg ? 'progress' : 'complete',
                  'date'=>$r['log_date'] ?? '', 'eng'=>$r['engineer_name'] ?? ''];
    }
    return $out;
}

// ================= 3) QUERY DATA SAMA PERSIS INDEX.PHP =================
$actListOp    = buildActivityListQuery($db, $userRole, $userId, 'operation',   $monthStart, $today);
$actListMaint = buildActivityListQuery($db, $userRole, $userId, 'maintenance', $monthStart, $today);
$actListProj  = buildActivityListQuery($db, $userRole, $userId, 'project',     $monthStart, $today);
$actListLand  = buildActivityListQuery($db, $userRole, $userId, 'landscape',   $monthStart, $today);
$actsGRP = [
    'operation'   => actGroupWithStatus($actListOp),
    'maintenance' => actGroupWithStatus($actListMaint),
    'project'     => actGroupWithStatus($actListProj),
    'landscape'   => actGroupWithStatus($actListLand),
];

// ================= 4) MERGE DATA MASTER DEFAULT PROGRESS KE actsGRP (Line index.php 447-470) =================
try {
    $_tmpMastersAct = $db->fetchAll("SELECT division, activity_name, sort_order, created_at, status_default FROM activity_masters ORDER BY FIELD(division,'operation','maintenance','project','landscape'), sort_order ASC, id ASC");
    $_existingTitleAct = [];
    foreach (['operation','maintenance','project','landscape'] as $dv) {
        if (!isset($actsGRP[$dv]) || !is_array($actsGRP[$dv])) $actsGRP[$dv] = [];
        foreach ($actsGRP[$dv] as $_r) {
            $t = mb_strtolower(trim((string)($_r['title'] ?? '')));
            if ($t !== '') $_existingTitleAct[$dv][$t] = true;
        }
    }
    foreach ($_tmpMastersAct as $_m) {
        $dv = (string)($_m['division'] ?? 'operation');
        if (!in_array($dv,['operation','maintenance','project','landscape'], true)) $dv = 'operation';
        if (!isset($actsGRP[$dv]) || !is_array($actsGRP[$dv])) $actsGRP[$dv] = [];
        $title = trim((string)($_m['activity_name'] ?? ''));
        if ($title === '') continue;
        $key = mb_strtolower($title);
        if (isset($_existingTitleAct[$dv][$key])) continue;
        $st = (string)($_m['status_default'] ?? 'progress');
        $actsGRP[$dv][] = [
            'title' => $title,
            'status' => ($st === 'complete' ? 'complete' : 'progress'),
            'date'  => substr((string)($_m['created_at'] ?? ''), 0, 10),
            'eng'   => '- (Master Activity)'
        ];
    }
    unset($_tmpMastersAct, $_existingTitleAct, $dv, $_m, $title, $key, $st);
} catch (Throwable $e) {}

// ================= 5) SUMMARY TOTAL =================
$totalActivities = 0;
$totalProgress = 0;
$totalComplete = 0;
foreach ($actsGRP as $rows) {
    foreach ($rows as $r) {
        $totalActivities++;
        if (($r['status'] ?? '') === 'complete') $totalComplete++;
        else $totalProgress++;
    }
}

function fmtDt($d) {
    if (strlen((string)$d) < 8) return '';
    try { return (new DateTime($d))->format('d M Y'); } catch (Throwable $e) { return ''; }
}
/* ✨ Pisahkan tag Master Activity dari nama engineer (sama persis logic daily_summary) */
function pdfSplitMasterEng($engRaw) {
    $s = trim((string)$engRaw);
    if ($s === '' || $s === '-') return [false, ''];
    if (stripos($s, '(Master Activity)') !== false || stripos($s, '(MASTER ACTIVITY)') !== false || stripos($s, 'master activity') !== false) {
        return [true, ''];
    }
    return [false, $s];
}
/* ✨ GROUP per TANGGAL sort ASC — sama persis daily_summary */
function pdfGroupByDate(&$list) {
    $grp = [];
    foreach ($list as $it) {
        $dt = trim((string)($it['date'] ?? ''));
        if ($dt === '' || strlen($dt) < 8) $dt = '0000-00-00';
        if (!isset($grp[$dt]) || !is_array($grp[$dt])) $grp[$dt] = [];
        $grp[$dt][] = $it;
    }
    ksort($grp, SORT_STRING);
    $ordered = [];
    foreach ($grp as $k => $arr) {
        if ($k === '0000-00-00') $ordered['_nodate'] = $arr;
        else $ordered[$k] = $arr;
    }
    if (isset($ordered['_nodate'])) { $x = $ordered['_nodate']; unset($ordered['_nodate']); $ordered['_nodate'] = $x; }
    return $ordered;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Engineering Activities - <?= htmlspecialchars($monthLabel) ?> | Engineering Report</title>
<style>
    @page { size: A4; margin: 12mm 12mm 16mm 12mm; }
    * { -webkit-box-sizing: border-box; box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0;
        background: #ffffff !important;
        color: #0f172a !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .wrap { width:100%; min-height:100vh; background:#ffffff !important; padding: 2px 0 18px 0; position:relative; }

    /* ===== META TOP ===== */
    .meta-top {
        display:flex; align-items:center; justify-content:space-between;
        padding-bottom: 12px; margin-bottom: 18px;
        border-bottom: 1px solid #e2e8f0;
        color: #64748b; font-size: 10px; letter-spacing: 0.6px; font-weight: 700; text-transform: uppercase;
    }
    .meta-top .lft, .meta-top .rgt { display:flex; align-items:center; gap: 10px; }
    .meta-top .dot { width:6px; height:6px; border-radius:50%; background:#94a3b8; display:inline-block;}

    /* ===== HEADER ===== */
    .head-block { margin-bottom: 22px; }
    .eyebrow {
        display:inline-flex; align-items:center; gap: 10px;
        color: #475569; font-size: 10px; font-weight: 800; letter-spacing: 2.5px; text-transform: uppercase;
        margin-bottom: 8px;
    }
    .eyebrow .pill { display:inline-block; padding: 2px 10px; border: 1px solid #cbd5e1; background: #f8fafc; color:#475569; border-radius: 999px; font-size: 9.5px; letter-spacing: 1px; font-weight: 700; }
    .title-hero {
        color:#0f172a; font-weight:900; letter-spacing:-0.02em; line-height:1.08;
        font-size: 28px; margin: 0 0 8px 0;
    }
    .title-hero .accent {
        background: linear-gradient(90deg, #0ea5e9, #6366f1);
        -webkit-background-clip:text; background-clip:text; color: transparent;
    }
    .subtitle-hero {
        color:#64748b; font-size: 12px; font-weight: 500; line-height: 1.5; margin: 0;
    }
    .summary-row { display:flex; gap: 9px; margin-top: 16px; flex-wrap: wrap; }
    .chip {
        display:inline-flex; align-items:center; gap: 6px;
        padding: 5px 11px; background:#ffffff; border: 1px solid #e2e8f0;
        border-radius: 12px; color: #475569; font-size: 10.5px; font-weight: 700;
        box-shadow: 0 1px 2px rgba(15,23,42,0.04);
    }
    .chip strong { color:#0f172a; font-weight: 800; }
    .chip .dotmini { width:5px; height:5px; border-radius:50%; display:inline-block; }

    /* ===== TABLE 3 KOLOM DEPT / DETAIL / STATUS ===== */
    .tbl {
        width: 100%; border-collapse: collapse;
        border: 1px solid #e2e8f0;
        border-radius: 14px; overflow: hidden;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15,23,42,0.03);
        page-break-inside: avoid;
    }
    .tbl thead th {
        background: linear-gradient(90deg, #f8fafc 0%, #f1f5f9 50%, #f8fafc 100%);
        color: #334155; font-size: 11px; font-weight: 800; letter-spacing: 0.12em;
        text-transform: uppercase; text-align: left; padding: 11px 14px;
        border-bottom: 2px solid #cbd5e1;
    }
    .tbl thead th.stcol { text-align:center; width: 140px; }
    .tbl thead th.depcol { width: 200px; border-right: 1px solid #e2e8f0; }

    .dept {
        background: #f8fafc;
        border-right: 1px solid #e2e8f0;
        border-top: 1px solid #e2e8f0;
        vertical-align: top;
        padding: 16px 14px;
    }
    .dept .box {
        display:flex; align-items:center; gap: 10px;
    }
    .dept .ic {
        width:34px; height:34px; border-radius: 10px;
        background:#fff; border: 1px solid #e2e8f0;
        display:flex; align-items:center; justify-content:center;
        box-shadow: 0 1px 2px rgba(15,23,42,0.04);
    }
    .dept .lbl { font-weight: 900; color:#0f172a; font-size: 15px; letter-spacing: 0.5px; }

    .detail {
        padding: 16px 16px;
        border-top: 1px solid #e2e8f0;
        border-left: 1px solid #e2e8f0;
        background: #ffffff;
    }
    .detail ul { margin: 0; padding: 0; list-style: none; }
    .detail li {
        display:flex; align-items:flex-start; gap: 9px;
        padding: 4px 0;
    }
    .detail li + li { border-top: 1px dashed #f1f5f9; padding-top: 7px; margin-top: 3px; }
    .detail .bulu { color:#64748b; width: 6px; height: 6px; border-radius: 50%; background:#94a3b8; display:inline-block; flex-shrink:0; margin-top: 8px; }
    .detail .bulu.b-prog { background:#f59e0b; }
    .detail .bulu.b-done { background:#10b981; width: 10px; height: 10px; border-radius: 50%; position: relative; margin-top: 6px; }
    .detail .bulu.b-done::after { content:"✓"; position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#fff; font-size: 7px; font-weight: 900; line-height: 1; }
    .detail .title { font-weight: 600; color:#0f172a; font-size: 13px; line-height: 1.45; }
    .detail li.item-done .title { color:#475569; text-decoration: line-through; text-decoration-color:#10b981; text-decoration-thickness:1.5px; }
    .detail .meta { margin-top: 3px; display:flex; flex-wrap: wrap; align-items: center; gap: 6px; }
    .detail .meta .tag {
        display:inline-flex; align-items:center; gap: 4px;
        padding: 1.5px 6.5px; border-radius: 5px;
        border: 1px solid #e2e8f0; background: #fcfcfd;
        font-size: 9.5px; font-weight: 700; color:#64748b;
    }
    /* ✨ Badges: Master Template (BIRU) vs Real Engineer (HIJAU) vs Tanggal (ABU) */
    .detail .meta .tag.master-tag { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-weight:900; letter-spacing: .03em;}
    .detail .meta .tag.master-tag i { color:#2563eb;}
    .detail .meta .tag.eng-tag { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0;}
    .detail .meta .tag.date-tag { background:#f8fafc; color:#334155; border:1px solid #cbd5e1;}
    /* ===== GROUP PEMISAH STATUS (Done vs Prog TIDAK KECAMPUR) ===== */
    .detail .grp-wrap { display:flex; flex-direction: column; gap: 10px; }
    .detail .grp-head {
        display:inline-flex; align-items:center; gap: 6px;
        padding: 2.5px 9px; border-radius: 6px;
        font-size: 10px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase;
        margin: 0 0 5px 0;
    }
    .detail .grp-head.prog { background:#fff7ed; color:#9a3412; border: 1px solid #fed7aa; }
    .detail .grp-head.done { background:#ecfdf5; color:#065f46; border: 1px solid #a7f3d0; }
    .detail .grp-sep { border-top: 1px dashed #d1d5db; margin: 4px 0 6px 0; opacity: .8; }
    .detail .grp-list { padding: 0; margin: 0; list-style: none; }
    /* ===== ✨ GRUP PEMISAH PER-TANGGAL (biar tanggal 07 Aug & 18 Aug TIDAK NYAMPUR) ===== */
    .detail .date-grp { margin: 0 0 8px 0; padding: 0 0 4px 0;}
    .detail .date-grp-hdr {
        display:inline-flex; align-items:center; gap:5px;
        padding: 1.5px 8px 1.5px 7px; border-radius: 7px;
        font-size: 9.5px; font-weight: 900; letter-spacing: .04em;
        background: linear-gradient(90deg,#eef2ff,#e0e7ff); color:#3730a3;
        border: 1px solid #c7d2fe; margin: 0 0 4px 2px;
    }
    .detail .date-grp-hdr .cntmini { background:#fff; padding: 0 6px; border-radius: 999px; border: 1px solid #c7d2fe; color:#4338ca; font-size: 8.5px; font-weight: 900;}
    .detail .date-grp-sep { border-top: 1px dotted #e2e8f0; margin: 3px 0 5px 0; opacity: .9; }
    .detail .date-grp:last-child { margin-bottom: 2px;}

    .statuscol {
        text-align: center; vertical-align: middle;
        padding: 16px 12px;
        border-top: 1px solid #e2e8f0;
        border-left: 1px solid #e2e8f0;
        background: #fcfcfd;
    }
    .statuscol .row + .row { margin-top: 8px; }
    .stdone {
        display:inline-flex; align-items:center; gap: 6px; justify-content:center;
        padding: 5px 10px; border-radius: 999px;
        background:#ecfdf5; color:#065f46; border: 1px solid #a7f3d0;
        font-size: 10.5px; font-weight: 900; letter-spacing: 0.6px;
        min-width: 120px;
    }
    .stdone .c { width:6px; height:6px; border-radius: 50%; background:#10b981; }
    .stprog {
        display:inline-flex; align-items:center; gap: 6px; justify-content:center;
        padding: 5px 10px; border-radius: 999px;
        background:#fffbeb; color:#92400e; border: 1px solid #fde68a;
        font-size: 10.5px; font-weight: 900; letter-spacing: 0.6px;
        min-width: 120px;
    }
    .stprog .c { width:6px; height:6px; border-radius: 50%; background:#f59e0b; }

    .empty-row td { padding: 28px 18px; text-align:center; color:#64748b; font-size: 12.5px; font-weight: 600; background:#fff; }
    .empty-row .big { font-size: 30px; color: #cbd5e1; margin-bottom: 8px; }

    /* ====== DIVIDER DEPT COLOR TINT ====== */
    .row-op .dept, .row-op .detail, .row-op .statuscol { background-color: #ffffff; }
    .row-op .dept { background-color: #eff6ff; }
    .row-maint .dept { background-color: #fffbeb; }
    .row-proj .dept { background-color: #f5f3ff; }
    .row-land .dept { background-color: #ecfdf5; }

    .row-op .ic i { color:#1d4ed8; }
    .row-maint .ic i { color:#b45309; }
    .row-proj .ic i { color:#6d28d9; }
    .row-land .ic i { color:#047857; }

    /* ===== FOOTER SIGNATURE ===== */
    .sign {
        margin-top: 30px; padding-top: 16px;
        border-top: 1px dashed #cbd5e1;
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px;
    }
    .sign .col { text-align:center; }
    .sign .col .lb {
        font-size: 10px; font-weight: 800; letter-spacing: 1.5px;
        color: #64748b; text-transform: uppercase; margin-bottom: 10px;
    }
    .sign .col .line { width: 130px; height: 44px; margin: 0 auto 4px auto; border-bottom: 1px dashed #94a3b8; }
    .sign .col .nm { font-weight: 800; color:#0f172a; font-size: 12.5px; }

    .foot-note {
        margin-top: 22px; padding-top: 12px;
        border-top: 1px dashed #e2e8f0;
        color:#94a3b8; font-size: 9.5px; letter-spacing: 0.4px; font-weight: 500;
        display:flex; align-items:center; justify-content:space-between;
    }
    .foot-note .brand-mark { color:#334155; font-weight: 800; letter-spacing: 1.5px; font-size: 10px; text-transform: uppercase; }
    .foot-note .brand-mark em { font-style: normal; background: linear-gradient(90deg,#0ea5e9,#6366f1); -webkit-background-clip:text; background-clip:text; color:transparent; }

    @media print { body { background: #fff !important; } }
</style>
</head>
<body>
<div class="wrap">

    <!-- META TOP -->
    <div class="meta-top">
        <div class="lft">
            <span class="dot"></span>
            <span>Engineering Report</span>
            <span class="dot"></span>
            <span>engineering activities</span>
            <span class="dot"></span>
            <span>data source: dashboard</span>
        </div>
        <div class="rgt">
            <span>print • <?= date('d M Y') ?></span>
        </div>
    </div>

    <!-- HEADER -->
    <div class="head-block">
        <div class="eyebrow">
            <span>Engineering Department</span>
            <span class="pill"><?= htmlspecialchars($monthLabel) ?></span>
        </div>
        <h1 class="title-hero">Engineering <span class="accent">Activities</span></h1>
        <p class="subtitle-hero">Rekap aktivitas harian tim engineering periode <?= htmlspecialchars($monthLabel) ?> — data 100% match dengan dashboard section engineering activities (mix daily log items + master default progress).</p>

        <div class="summary-row">
            <div class="chip"><i class="far fa-clipboard-list" style="color:#94a3b8;font-size:10px;"></i> total aktivitas <strong><?= number_format($totalActivities,0,',','.') ?></strong></div>
            <div class="chip"><span class="dotmini" style="background:#f59e0b;"></span> in progress <strong><?= number_format($totalProgress,0,',','.') ?></strong></div>
            <div class="chip"><span class="dotmini" style="background:#10b981;"></span> complete <strong><?= number_format($totalComplete,0,',','.') ?></strong></div>
            <?php foreach (['operation','maintenance','project','landscape'] as $dv):
                $cnt = count($actsGRP[$dv] ?? []);
                if ($cnt <= 0) continue;
                $cProg = 0; foreach ($actsGRP[$dv] as $_r) if (($_r['status'] ?? '') !== 'complete') $cProg++;
            ?>
            <div class="chip">
                <i class="fas fa-layer-group" style="color:#94a3b8;font-size:10px;"></i>
                <?= $dv ?> <strong><?= $cnt ?></strong>
                <span style="color:#94a3b8; font-weight:600;">(<?= $cProg ?> prog)</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- TABLE 3 KOLOM -->
    <table class="tbl">
        <thead>
            <tr>
                <th class="depcol">DEPARTMENT</th>
                <th>ACTIVITY DETAIL</th>
                <th class="stcol">STATUS</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $deptDef = [
            'operation'   => ['OPERATION',   'fas fa-gear',           'row-op'],
            'maintenance' => ['MAINTENANCE', 'fas fa-wrench',         'row-maint'],
            'project'     => ['PROJECT',     'fas fa-diagram-project','row-proj'],
            'landscape'   => ['LANDSCAPE',   'fas fa-leaf',           'row-land'],
        ];
        $adaIsi = false;
        foreach ($deptDef as $key => $dd):
            [$deptLabel, $deptIcon, $rowCls] = $dd;
            $rows = $actsGRP[$key] ?? [];
            if (count($rows) === 0) continue;
            $adaIsi = true;
            $done = 0; $prog = 0;
            foreach ($rows as $_rr) if (($_rr['status'] ?? '') === 'complete') $done++; else $prog++;
        ?>
            <tr class="<?= $rowCls ?>">
                <td class="dept">
                    <div class="box">
                        <div class="ic"><i class="<?= $deptIcon ?> text-base"></i></div>
                        <div class="lbl"><?= $deptLabel ?></div>
                    </div>
                </td>
                <td class="detail">
                    <?php
                    // ✅ PISAHKAN DONE vs PROGRESS (TIDAK KECAMPUR) — 100% sama dashboard
                    $rowsDone = []; $rowsProg = [];
                    foreach ($rows as $_rr) {
                        if (($_rr['status'] ?? '') === 'complete') $rowsDone[] = $_rr;
                        else                                             $rowsProg[] = $_rr;
                    }
                    $hasP = count($rowsProg) > 0;
                    $hasD = count($rowsDone) > 0;

                    // ✨ Function helper inline: render list (Prog/Done) dengan GROUP PER TANGGAL
                    $renderListByDate = function($items, $mode) {
                        if (count($items) === 0) return '';
                        $grouped = pdfGroupByDate($items);
                        $isProg = ($mode === 'progress');
                        $liClass = $isProg ? '' : 'item-done';
                        $buluClass = $isProg ? 'b-prog' : 'b-done';
                        $engTagColor = $isProg
                            ? 'background:#fffbeb;border-color:#fde68a;color:#92400e;'
                            : 'background:#ecfdf5;border-color:#a7f3d0;color:#065f46;';
                        $engIconColor = $isProg ? '#92400e' : '#065f46';
                        $html = '';
                        $firstGrp = true;
                        foreach ($grouped as $dtISO => $itemsInDate) {
                            $isNoDate = ($dtISO === '_nodate');
                            $hdr = $isNoDate ? '' : fmtDt($dtISO);
                            if (!$firstGrp) $html .= '<div class="date-grp-sep" aria-hidden="true"></div>';
                            $firstGrp = false;
                            $html .= '<div class="date-grp">';
                            if ($hdr !== '') {
                                $html .= '<span class="date-grp-hdr"><i class="far fa-calendar-day" style="font-size:8.5px;"></i>';
                                $html .= '📅 ' . htmlspecialchars($hdr);
                                $html .= ' <span class="cntmini">' . count($itemsInDate) . '</span>';
                                $html .= '</span>';
                            }
                            $html .= '<ul class="grp-list">';
                            foreach ($itemsInDate as $ar) {
                                $title = htmlspecialchars((string)($ar['title'] ?? ''));
                                $d = $isNoDate ? fmtDt($ar['date'] ?? '') : '';
                                list($isMaster, $cleanEng) = pdfSplitMasterEng($ar['eng'] ?? '');
                                $html .= '<li class="' . $liClass . '">';
                                $html .= '<span class="bulu ' . $buluClass . '" aria-hidden="true"></span>';
                                $html .= '<div class="flex-1">';
                                $html .= '<div class="title">' . $title . '</div>';
                                // Meta tags: date, master, eng
                                if ($d !== '' || $isMaster || $cleanEng !== '') {
                                    $html .= '<div class="meta">';
                                    if ($d !== '') {
                                        $html .= '<span class="tag date-tag"><i class="far fa-calendar" style="color:#64748b;font-size:8.5px;"></i> ' . htmlspecialchars($d) . '</span>';
                                    }
                                    if ($isMaster) {
                                        $html .= '<span class="tag master-tag"><i class="fas fa-database" style="font-size:8.5px;"></i> MASTER TEMPLATE</span>';
                                    }
                                    if ($cleanEng !== '') {
                                        $html .= '<span class="tag eng-tag" style="' . $engTagColor . '"><i class="fas fa-user-helmet-safety" style="color:' . $engIconColor . ';font-size:8.5px;"></i> ' . htmlspecialchars($cleanEng) . '</span>';
                                    }
                                    $html .= '</div>';
                                }
                                $html .= '</div>';
                                $html .= '</li>';
                            }
                            $html .= '</ul>';
                            $html .= '</div>';
                        }
                        return $html;
                    };
                    ?>
                    <div class="grp-wrap">
                        <?php if ($hasP): ?>
                            <div>
                                <div class="grp-head prog">
                                    <i class="fas fa-spinner" style="font-size:9px;"></i>
                                    Sedang Berjalan <span style="opacity:.75;">(<?= count($rowsProg) ?>)</span>
                                </div>
                                <?= $renderListByDate($rowsProg, 'progress') ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($hasP && $hasD): ?>
                            <div class="grp-sep" aria-hidden="true"></div>
                        <?php endif; ?>

                        <?php if ($hasD): ?>
                            <div>
                                <div class="grp-head done">
                                    <i class="fas fa-circle-check" style="font-size:9px;"></i>
                                    Selesai / Done <span style="opacity:.75;">(<?= count($rowsDone) ?>)</span>
                                </div>
                                <?= $renderListByDate($rowsDone, 'done') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="statuscol">
                    <?php if ($done > 0): ?>
                    <div class="row"><div class="stdone"><span class="c"></span> Complete <span style="margin-left:3px;">(<?= $done ?>)</span></div></div>
                    <?php endif; ?>
                    <?php if ($prog > 0): ?>
                    <div class="row"><div class="stprog"><span class="c"></span> In Progress <span style="margin-left:3px;">(<?= $prog ?>)</span></div></div>
                    <?php endif; ?>
                    <?php if ($done === 0 && $prog === 0): ?>
                    <div class="row"><span style="color:#94a3b8;font-size:10.5px;font-weight:800;">No Data</span></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$adaIsi): ?>
            <tr class="empty-row">
                <td colspan="3">
                    <div class="big"><i class="far fa-folder-open"></i></div>
                    Belum ada data aktivitas untuk periode <?= htmlspecialchars($monthLabel) ?>.<br>
                    Silakan input daily log engineer terlebih dahulu atau tambah master activity progress.
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- FOOT NOTE -->
    <div class="foot-note">
        <div style="display:flex; align-items:center; gap:8px;">
            <span class="brand-mark">Engineering <em>Report</em></span>
            <span>•</span>
            <span>disediakan tanpa logo & branding hotel (format generic).</span>
        </div>
        <div>last updated: <?= date('Y-m-d H:i') ?></div>
    </div>

</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="all">
</body>
</html>
<?php echo ob_get_clean(); exit; ?>
