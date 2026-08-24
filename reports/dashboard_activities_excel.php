<?php
/**
 * 📋 REPORT EXCEL: ENGINEERING ACTIVITIES DASHBOARD (3 KOLOM - DATA 100% SAMA PERSIS DASHBOARD)
 * - SUMBER DATA SAMA PERSIS DENGAN PDF (buildActivityListQuery + merge master default progress)
 * - SpreadsheetML HTML + UTF-8 BOM + table-layout: fixed + explicit colgroup (hindari ######## dan warning CSV)
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

$monthRaw = $_GET['month'] ?? date('Y-m');
if (preg_match('/^\d{4}-\d{2}$/', (string)$monthRaw)) {
    $monthStart = $monthRaw . '-01';
    $today = date('Y-m-t', strtotime($monthStart));
}
$monthLabel = (new DateTime($monthStart))->format('M Y');
if (isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_from'])) $monthStart = $_GET['date_from'];
if (isset($_GET['date_to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_to']))   $today      = $_GET['date_to'];

$fileName = 'Engineering_Activities_' . substr($monthStart,0,7);
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '.xls"');
header('Content-Transfer-Encoding: binary');
header('Pragma: public');
header('Cache-Control: max-age=0, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('X-Content-Type-Options: nosniff');

ob_start();
echo "\xEF\xBB\xBF"; // UTF-8 BOM (WAJIB untuk hindari warning CSV)

// ================= 2) FUNCTIONS =================
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
try {
    $_tmpMastersAct = $db->fetchAll("SELECT am.division, am.activity_name, am.sort_order, am.created_at, am.status_default,
                                            u.name as created_by_name
                                     FROM activity_masters am
                                     LEFT JOIN users u ON u.id = am.created_by
                                     ORDER BY FIELD(am.division,'project','operation','maintenance','landscape'), am.sort_order ASC, am.id ASC");
    $_existingTitleAct = [];
    foreach (['project','operation','maintenance','landscape'] as $dv) {
        if (!isset($actsGRP[$dv]) || !is_array($actsGRP[$dv])) $actsGRP[$dv] = [];
        foreach ($actsGRP[$dv] as $_r) {
            $t = mb_strtolower(trim((string)($_r['title'] ?? '')));
            if ($t !== '') $_existingTitleAct[$dv][$t] = true;
        }
    }
    $_defEngExcel = !empty($user['name']) ? (string)$user['name'] : '- (Master Activity)';
    foreach ($_tmpMastersAct as $_m) {
        $dv = (string)($_m['division'] ?? 'operation');
        if (!in_array($dv,['project','operation','maintenance','landscape'], true)) $dv = 'operation';
        if (!isset($actsGRP[$dv]) || !is_array($actsGRP[$dv])) $actsGRP[$dv] = [];
        $title = trim((string)($_m['activity_name'] ?? ''));
        if ($title === '') continue;
        $key = mb_strtolower($title);
        if (isset($_existingTitleAct[$dv][$key])) continue;
        $st = (string)($_m['status_default'] ?? 'progress');
        $_engExcel = !empty($_m['created_by_name']) ? (string)$_m['created_by_name'] : $_defEngExcel;
        $actsGRP[$dv][] = ['title'=>$title, 'status'=>($st==='complete'?'complete':'progress'), 'date'=>substr((string)($_m['created_at'] ?? ''),0,10), 'eng'=>$_engExcel];
    }
    unset($_tmpMastersAct, $_existingTitleAct, $dv, $_m, $title, $key, $st, $_engExcel, $_defEngExcel);
} catch (Throwable $e) {}

$totalActivities = 0; $totalProgress = 0; $totalComplete = 0;
foreach ($actsGRP as $rows) foreach ($rows as $r) { $totalActivities++; if (($r['status'] ?? '') === 'complete') $totalComplete++; else $totalProgress++; }

function fmtDtXls($d) { if (strlen((string)$d)<8) return '-'; try { return (new DateTime($d))->format('d M Y'); } catch (Throwable $e) { return '-'; } }
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="application/vnd.ms-excel; charset=utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="ProgId" content="Excel.Sheet">
<title><?= htmlspecialchars($fileName, ENT_QUOTES) ?></title>
<style>
    * { font-family: Calibri, "Segoe UI", Arial, sans-serif; }
    body { padding: 20px 24px; background:#ffffff; color:#0f172a; }
    table { border-collapse: collapse; width: 100%; table-layout: fixed; }
    th, td { border: 1px solid #e2e8f0; padding: 7px 9px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; white-space: normal; }
    th {
        background: #f8fafc !important; color: #334155 !important; font-weight: 800; font-size: 10.5px;
        text-align: left; letter-spacing: 0.8px; text-transform: uppercase;
        border-bottom: 2px solid #cbd5e1 !important; border-top: 1px solid #e2e8f0 !important;
    }
    tr:nth-child(even) td { background: #fcfcfd; }

    .brand-title { font-size: 24px; font-weight: 900; color:#0f172a; margin: 0; letter-spacing:-0.2px; }
    .brand-title .accent { background: linear-gradient(90deg, #0ea5e9, #6366f1); -webkit-background-clip:text; background-clip:text; color:transparent; }
    .brand-sub { font-size: 10px; color:#64748b; margin: 0 0 18px 0; letter-spacing: 2.5px; text-transform: uppercase; font-weight: 700; }
    .eyebrow { display: inline-flex; align-items:center; gap: 10px; color:#475569; font-size:10px; font-weight:800; letter-spacing:2.5px; text-transform:uppercase; margin-bottom: 8px; }
    .eyebrow .pill { display:inline-block; padding: 2px 10px; border: 1px solid #cbd5e1; background:#f8fafc; color:#475569; border-radius: 999px; font-size: 9.5px; letter-spacing:1px; font-weight: 700; }
    .hero-title { color:#0f172a; font-size: 28px; font-weight:900; margin: 0 0 6px 0; letter-spacing: -0.2px; }
    .hero-title .accent { background: linear-gradient(90deg, #0ea5e9, #6366f1); -webkit-background-clip:text; background-clip:text; color: transparent; }
    .hero-sub { color:#64748b; font-size: 11.5px; margin: 0 0 14px 0; letter-spacing: 0.1px; font-weight: 500; line-height: 1.5; }

    .meta { background: #ffffff; border: 1px solid #e2e8f0; padding: 10px 12px; margin: 0 0 16px 0; border-radius: 6px; }
    .meta td { border: none; padding: 3px 10px 3px 0; font-size: 10.5px; }
    .meta td:first-child { font-weight: 800; color: #64748b; width: 160px; letter-spacing: 0.8px; text-transform: uppercase; font-size: 10px; }

    .chips { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
    .chip { display: inline-flex; align-items:center; gap: 6px; padding: 5px 11px; background:#ffffff; border: 1px solid #e2e8f0; border-radius: 12px; color:#475569; font-size: 10px; font-weight: 700; }
    .chip strong { color:#0f172a; font-weight: 800; }

    .divider { display:flex; align-items:center; gap: 10px; margin: 22px 0 9px 0; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
    .divider .dotdiv { width: 8px; height: 8px; border-radius: 50%; background: #cbd5e1; box-shadow: inset 0 0 0 2px #fff, 0 0 0 1px #e2e8f0; }
    .divider .lbl { color:#334155; font-size: 11px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; }
    .divider .cnt { color:#94a3b8; font-size: 10px; font-weight: 600; letter-spacing: 0.3px; margin-left: auto; }

    .st-done { color:#15803d; font-weight: 800; text-transform: uppercase; font-size: 9.5px; letter-spacing: 1px; background:#f0fdf4; padding: 2px 7px; border-radius: 999px; border:1px solid #bbf7d0; display:inline-block; }
    .st-progress { color:#92400e; font-weight: 800; text-transform: uppercase; font-size: 9.5px; letter-spacing:1px; background:#fffbeb; padding:2px 7px; border-radius: 999px; border:1px solid #fde68a; display:inline-block; }
    .dept-label { font-weight: 900; color:#0f172a; font-size: 13px; letter-spacing: 0.5px; }
    .center { text-align: center; }
    .empty { padding: 18px; text-align:center; color:#64748b; background:#ffffff; border:1px dashed #cbd5e1; font-size: 11px; font-style: italic; border-radius: 10px; }

    .footer-note { margin-top: 26px; padding-top: 14px; border-top: 1px dashed #cbd5e1; color:#94a3b8; font-size: 9.5px; text-align: center; letter-spacing: 0.3px; }
    .footer-note .brand { color:#334155; font-weight: 800; letter-spacing: 1.5px; font-size: 10px; text-transform: uppercase; }
    .footer-note .brand em { font-style:normal; background: linear-gradient(90deg, #0ea5e9, #6366f1); -webkit-background-clip:text; background-clip:text; color: transparent; }

    .dept-op td.depcell { background: #eff6ff !important; }
    .dept-maint td.depcell { background: #fffbeb !important; }
    .dept-proj td.depcell { background: #f5f3ff !important; }
    .dept-land td.depcell { background: #ecfdf5 !important; }

    .signtable { margin-top: 28px; border: none; width: 100%; }
    .signtable td { border: none; text-align: center; padding: 4px 8px; }
    .signtable .lb { font-size: 9.5px; font-weight: 800; letter-spacing: 1.5px; color:#64748b; text-transform: uppercase; margin-bottom: 10px; display:block; }
    .signtable .line { display:block; width: 140px; height: 40px; margin: 0 auto 4px auto; border-bottom: 1px dashed #94a3b8; }
    .signtable .nm { font-weight: 800; color:#0f172a; font-size: 12px; display:block; }
</style>
</head>
<body>

<div class="brand-title">Engineering <span class="accent">Report</span></div>
<div class="brand-sub">Engineering Department • Activities Dashboard Export</div>

<div class="eyebrow">
    <span>Engineering Department</span>
    <span class="pill"><?= htmlspecialchars($monthLabel) ?></span>
</div>
<div class="hero-title">Engineering <span class="accent">Activities</span></div>
<div class="hero-sub">Rekap aktivitas harian tim engineering — data 100% match dengan dashboard section engineering activities (mix daily log items + master default progress).</div>

<div class="chips">
    <div class="chip">📋 total aktivitas <strong><?= number_format($totalActivities,0,',','.') ?></strong></div>
    <div class="chip">⏳ in progress <strong style="color:#92400e;"><?= number_format($totalProgress,0,',','.') ?></strong></div>
    <div class="chip">✅ complete <strong style="color:#15803d;"><?= number_format($totalComplete,0,',','.') ?></strong></div>
    <?php foreach (['project','operation','maintenance','landscape'] as $dv):
        $cnt = count($actsGRP[$dv] ?? []);
        if ($cnt <= 0) continue;
    ?>
    <div class="chip">📁 <?= $dv ?> <strong><?= $cnt ?></strong></div>
    <?php endforeach; ?>
</div>

<table class="meta">
    <colgroup>
        <col style="width:160px;"><col style="width:260px;">
        <col style="width:160px;"><col style="width:*;">
    </colgroup>
    <tr>
        <td>Periode</td><td>: <?= htmlspecialchars($monthLabel) ?> (<?= htmlspecialchars($monthStart) ?> s/d <?= htmlspecialchars($today) ?>)</td>
        <td>Total Aktivitas</td><td>: <?= number_format($totalActivities,0,',','.') ?> item (<?= number_format($totalProgress,0,',','.') ?> progress • <?= number_format($totalComplete,0,',','.') ?> complete)</td>
    </tr>
    <tr>
        <td>Role Akses</td><td>: <?= htmlspecialchars(ucfirst($userRole)) ?></td>
        <td>Dibuat Oleh</td><td>: <?= htmlspecialchars($userName) ?> • <?= date('d M Y H:i') ?></td>
    </tr>
</table>

<?php
$deptDef = [
    'operation'   => ['OPERATION',   'dept-op'],
    'maintenance' => ['MAINTENANCE', 'dept-maint'],
    'project'     => ['PROJECT',     'dept-proj'],
    'landscape'   => ['LANDSCAPE',   'dept-land'],
];
$adaIsi = false;
$no = 0;
foreach ($deptDef as $key => $dd):
    [$deptLabel, $clsRow] = $dd;
    $rows = $actsGRP[$key] ?? [];
    if (count($rows) === 0) continue;
    $adaIsi = true;
    $done = 0; $prog = 0;
    foreach ($rows as $_rr) if (($_rr['status'] ?? '') === 'complete') $done++; else $prog++;
?>
<div class="divider">
    <span class="dotdiv"></span>
    <span class="lbl"><?= $deptLabel ?></span>
    <span class="cnt"><?= count($rows) ?> activities • <?= $prog ?> progress • <?= $done ?> complete</span>
</div>

<table class="<?= $clsRow ?>">
    <colgroup>
        <col style="width:50px;">
        <col style="width:200px;">
        <col style="width:*;">
        <col style="width:120px;">
        <col style="width:180px;">
        <col style="width:160px;">
    </colgroup>
    <thead>
        <tr>
            <th class="center" style="width:50px;">#</th>
            <th style="width:200px;">DEPARTMENT</th>
            <th>NAMA AKTIVITAS / TASK</th>
            <th style="width:120px;" class="center">TANGGAL</th>
            <th style="width:180px;">PIC / ENGINEER</th>
            <th style="width:160px;" class="center">STATUS</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $i => $ar): $no++; ?>
        <tr>
            <td class="center" style="font-weight:900; color:#64748b; font-size: 11px;"><?= $no ?>.</td>
            <td class="depcell" style="padding: 7px 10px;">
                <span class="dept-label"><?= $deptLabel ?></span>
            </td>
            <td style="font-weight:600; color:#0f172a; font-size: 12px; line-height: 1.5;"><?= htmlspecialchars((string)($ar['title'] ?? '')) ?></td>
            <td class="center" style="font-size: 10.5px; font-family: Consolas, monospace; color:#475569; font-weight:700;"><?= fmtDtXls($ar['date'] ?? '') ?></td>
            <td style="font-size: 10.5px; color:#475569; font-weight:600;"><?= htmlspecialchars((string)($ar['eng'] ?? '-')) ?></td>
            <td class="center">
                <?php if (($ar['status'] ?? '') === 'complete'): ?>
                    <span class="st-done">✅ done</span>
                <?php else: ?>
                    <span class="st-progress">⏳ progress</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endforeach; ?>

<?php if (!$adaIsi): ?>
<div class="empty">
    📂 Belum ada data aktivitas untuk periode <?= htmlspecialchars($monthLabel) ?>. Silakan input daily log engineer terlebih dahulu atau tambah master activity progress.
</div>
<?php endif; ?>

<div class="footer-note">
    <span class="brand">Engineering <em>Report</em></span>
    &nbsp;•&nbsp; dokumen di-generate otomatis • last updated: <?= date('Y-m-d H:i:s') ?>&nbsp;
    •&nbsp; <span style="color:#64748b;">disediakan tanpa logo & branding hotel (format generic).</span>
</div>
</body>
</html>
<?php echo ob_get_clean(); exit; ?>
