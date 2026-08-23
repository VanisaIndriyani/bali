<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance();
$user = currentUser();
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? 'engineer');
$userName = (string)($user['name'] ?? 'User');

$viewType = cleanInput($_GET['view'] ?? 'daily');
if (!in_array($viewType, ['daily', 'monthly'], true)) {
    $viewType = 'daily';
}

$dateFromRaw = $_GET['date_from'] ?? '';
$dateToRaw = $_GET['date_to'] ?? '';
if ($viewType === 'monthly') {
    $firstDayThisMonth = date('Y-m-01');
    $firstDay6Ago = date('Y-m-01', strtotime('-5 months'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateFromRaw)) {
        $dateFromRaw = $firstDay6Ago;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateToRaw)) {
        $dateToRaw = date('Y-m-t');
    }
} else {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateFromRaw)) {
        $dateFromRaw = date('Y-m-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateToRaw)) {
        $dateToRaw = date('Y-m-d');
    }
}
$dateFrom = $dateFromRaw;
$dateTo = $dateToRaw;

$whereApproved = " AND dl.status = 'approved'";
$whereUser = '';
$params = [$dateFrom, $dateTo];
if ($userRole !== 'supervisor') {
    $whereUser = " AND dl.engineer_id = ? ";
    $params[] = $userId;
}

if ($viewType === 'monthly') {
    $groupSelect = "DATE_FORMAT(dl.log_date, '%Y-%m') as period_key, DATE_FORMAT(dl.log_date, '%b %Y') as period_label";
    $groupBy = "GROUP BY DATE_FORMAT(dl.log_date, '%Y-%m'), DATE_FORMAT(dl.log_date, '%b %Y')";
    $orderBy = "ORDER BY DATE_FORMAT(dl.log_date, '%Y-%m') ASC";
} else {
    $groupSelect = "DATE(dl.log_date) as period_key, DATE(dl.log_date) as period_label";
    $groupBy = "GROUP BY DATE(dl.log_date)";
    $orderBy = "ORDER BY DATE(dl.log_date) ASC";
}

$summaryWhereExtra = '';
if (!empty($whereApproved)) $summaryWhereExtra .= " AND " . preg_replace('/^dl\./', 'dl_inner.', ltrim($whereApproved));
if (!empty($whereUser))     $summaryWhereExtra .= " AND " . preg_replace('/^dl\./', 'dl_inner.', ltrim($whereUser));

/* ✅ 2026-08-23 FIX DEDUP: inner subquery cari MAX(id) keep_id per DATE+engineer_id, baru outer JOIN untuk aggregate */
$summaryQuery = "SELECT $groupSelect,
    SUM(dl.total_electricity) as sum_elec,
    SUM(dl.total_water) as sum_water,
    SUM(dl.total_gas) as sum_gas,
    COUNT(DISTINCT dl.id) as log_count
    FROM daily_logs dl
    INNER JOIN (
        SELECT MAX(id) AS keep_id FROM daily_logs dl_inner
        WHERE dl_inner.log_date BETWEEN ? AND ? $summaryWhereExtra
        GROUP BY DATE(dl_inner.log_date), dl_inner.engineer_id
    ) k ON k.keep_id = dl.id
    $groupBy $orderBy";
$summaryData = $db->fetchAll($summaryQuery, $params);

$detailParams = [$dateFrom, $dateTo];
if ($userRole !== 'supervisor') {
    $detailParams[] = $userId;
}

$detailWhereExtra = '';
if (!empty($whereApproved)) $detailWhereExtra .= " AND " . preg_replace('/^dl\./', 'dl_inner.', ltrim($whereApproved));
if (!empty($whereUser))     $detailWhereExtra .= " AND " . preg_replace('/^dl\./', 'dl_inner.', ltrim($whereUser));

/* ✅ 2026-08-23 FIX DEDUP detail query juga */
$detailQuery = "SELECT dl.*, u.name as engineer_name
    FROM daily_logs dl
    INNER JOIN (
        SELECT MAX(id) AS keep_id FROM daily_logs dl_inner
        WHERE dl_inner.log_date BETWEEN ? AND ? $detailWhereExtra
        GROUP BY DATE(dl_inner.log_date), dl_inner.engineer_id
    ) k ON k.keep_id = dl.id
    LEFT JOIN users u ON u.id = dl.engineer_id
    ORDER BY dl.log_date DESC, dl.id DESC";
$detailData = $db->fetchAll($detailQuery, $detailParams);

$totElec = 0;
$totWater = 0;
$totGas = 0;
$totLog = 0;
foreach ($summaryData as $row) {
    $totElec += (float)$row['sum_elec'];
    $totWater += (float)$row['sum_water'];
    $totGas += (float)$row['sum_gas'];
    $totLog += (int)$row['log_count'];
}

$periodLabel = formatDate($dateFrom) . ' s/d ' . formatDate($dateTo);
$modeLabel = $viewType === 'monthly' ? 'Bulanan' : 'Harian';
$fileName = 'Dashboard_Konsumsi_' . $modeLabel . '_' . date('Ymd', strtotime($dateFrom)) . '-' . date('Ymd', strtotime($dateTo));

// ========== ENGINEERING ACTIVITIES DATA (100% MATCH DASHBOARD CARD #2) ==========
function buildActivityListQueryDp($db, $userRole, $userId, $category, $dFrom, $dTo, $limit = 500) {
    $baseWhere = "WHERE dla.category = ? AND dl.status = 'approved' AND dl.log_date BETWEEN ? AND ?";
    $p = [$category, $dFrom, $dTo];
    if ($userRole === 'engineer') { $baseWhere .= " AND dl.engineer_id = ?"; $p[] = $userId; }
    $sql = "SELECT dla.id, dla.activity_title, DATE(dl.log_date) as log_date, u.name as engineer_name
            FROM daily_log_activities dla
            INNER JOIN daily_logs dl ON dl.id = dla.daily_log_id
            LEFT JOIN users u ON u.id = dl.engineer_id
            $baseWhere
            ORDER BY dl.log_date DESC, dla.sort_order ASC, dla.id DESC
            LIMIT $limit";
    return $db->fetchAll($sql, $p);
}
function actGroupWithStatusDp($list) {
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
               || (strpos($tl, 'project ') !== false) || (strpos($tl, 'perbaika') !== false);
        $out[] = ['title'=>$t, 'status'=>$isProg ? 'progress' : 'complete',
                  'date'=>$r['log_date'] ?? '', 'eng'=>$r['engineer_name'] ?? ''];
    }
    return $out;
}
$actsGRP_DP = [
    'operation'   => actGroupWithStatusDp(buildActivityListQueryDp($db, $userRole, $userId, 'operation',   $dateFrom, $dateTo)),
    'maintenance' => actGroupWithStatusDp(buildActivityListQueryDp($db, $userRole, $userId, 'maintenance', $dateFrom, $dateTo)),
    'project'     => actGroupWithStatusDp(buildActivityListQueryDp($db, $userRole, $userId, 'project',     $dateFrom, $dateTo)),
    'landscape'   => actGroupWithStatusDp(buildActivityListQueryDp($db, $userRole, $userId, 'landscape',   $dateFrom, $dateTo)),
];
try {
    $_tmpM = $db->fetchAll("SELECT division, activity_name, sort_order, created_at, status_default FROM activity_masters ORDER BY FIELD(division,'operation','maintenance','project','landscape'), sort_order ASC, id ASC");
    $_existT = [];
    foreach (['operation','maintenance','project','landscape'] as $dv) {
        if (!isset($actsGRP_DP[$dv]) || !is_array($actsGRP_DP[$dv])) $actsGRP_DP[$dv] = [];
        foreach ($actsGRP_DP[$dv] as $_r) {
            $t = mb_strtolower(trim((string)($_r['title'] ?? '')));
            if ($t !== '') $_existT[$dv][$t] = true;
        }
    }
    foreach ($_tmpM as $_m) {
        $dv = (string)($_m['division'] ?? 'operation');
        if (!in_array($dv,['operation','maintenance','project','landscape'], true)) $dv = 'operation';
        if (!isset($actsGRP_DP[$dv]) || !is_array($actsGRP_DP[$dv])) $actsGRP_DP[$dv] = [];
        $title = trim((string)($_m['activity_name'] ?? ''));
        if ($title === '') continue;
        $key = mb_strtolower($title);
        if (isset($_existT[$dv][$key])) continue;
        $st = (string)($_m['status_default'] ?? 'progress');
        $actsGRP_DP[$dv][] = [
            'title' => $title,
            'status' => ($st === 'complete' ? 'complete' : 'progress'),
            'date'  => substr((string)($_m['created_at'] ?? ''), 0, 10),
            'eng'   => '- (Master Activity)'
        ];
    }
    unset($_tmpM, $_existT, $dv, $_m, $title, $key, $st);
} catch (Throwable $e) {}
function fmtDtDp($d) {
    if (strlen((string)$d) < 8) return '';
    try { return (new DateTime($d))->format('d M Y'); } catch (Throwable $e) { return ''; }
}
$divInfo_DP = [
    'operation'   => ['label'=>'OPERATION',   'bg'=>'#eff6ff', 'ico'=>'fa-gears',    'accent'=>'#1d4ed8'],
    'maintenance' => ['label'=>'MAINTENANCE', 'bg'=>'#fffbeb', 'ico'=>'fa-wrench',    'accent'=>'#b45309'],
    'project'     => ['label'=>'PROJECT',     'bg'=>'#f5f3ff', 'ico'=>'fa-clipboard-list','accent'=>'#6d28d9'],
    'landscape'   => ['label'=>'LANDSCAPE',   'bg'=>'#ecfdf5', 'ico'=>'fa-seedling',  'accent'=>'#047857'],
];

$logoSrc = '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?= $fileName ?> - Engineering Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; margin: 0; padding: 0; color:#1f2937; background:#fff; }
        .page { max-width: 960px; margin: 24px auto; background:#fff; border-radius: 14px; overflow: hidden; border:1px solid #e5e7eb; }
        .header { padding: 22px 32px; background:#fff; color:#1f2937; display:flex; align-items:center; gap:18px; border-bottom: 2px solid #c9a227; }
        .logo-box { width:60px; height:60px; background:#fff; border-radius:14px; display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0; padding:4px; border:1px solid #e5e7eb; }
        .logo-box img { max-width:100%; max-height:100%; object-fit:contain; }
        .header-text h1 { margin:0; font-family:'Playfair Display', serif; font-size:20px; letter-spacing:0.5px; color:#1f2937; }
        .header-text p { margin:3px 0 0; font-size:11px; letter-spacing:0.2em; text-transform:uppercase; color:#6b7280; }
        .divider { height:1px; background:#e5e7eb; }
        .content { padding: 26px 32px 34px; }
        .meta { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:12px; margin-bottom:22px; }
        .meta .card { padding:12px 14px; border-radius:10px; background:#fff; border:1px solid #e5e7eb; }
        .meta .card .label { font-size:10px; letter-spacing:0.1em; text-transform:uppercase; color:#6b7280; margin-bottom:4px; }
        .meta .card .value { font-size:14px; font-weight:700; color:#1f2937; }
        .summary-stat { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:12px; margin: 22px 0; }
        .stat { padding:16px 16px 16px 14px; border-radius:12px; background:#fff; color:#1f2937; border:1px solid #e5e7eb; border-left:4px solid #c9a227; position:relative; overflow:hidden; }
        .stat .label { font-size:10px; letter-spacing:0.15em; text-transform:uppercase; color:#6b7280; margin-bottom:5px; }
        .stat .value { font-size:24px; font-weight:800; color:#1f2937; }
        .stat .unit { font-size:12px; color:#6b7280; font-weight:500; margin-left:2px; }
        .stat .ico { position:absolute; right:12px; top:12px; font-size:22px; opacity:0.18; color:#c9a227; }
        .stat.elec { border-left-color:#d97706; }
        .stat.elec .ico { color:#d97706; }
        .stat.water { border-left-color:#0284c7; }
        .stat.water .ico { color:#0284c7; }
        .stat.gas { border-left-color:#ea580c; }
        .stat.gas .ico { color:#ea580c; }
        .stat.total { border-left-color:#c9a227; }
        .stat.total .ico { color:#c9a227; }
        section.title { display:flex; align-items:center; gap:10px; margin:26px 0 12px; padding-bottom:8px; border-bottom:1px solid #e5e7eb; }
        section.title h2 { margin:0; font-size:17px; font-weight:700; color:#1f2937; }
        section.title .bar { width:3px; height:20px; background:#c9a227; border-radius:4px; }
        table { width:100%; border-collapse: collapse; font-size:13px; margin-bottom: 16px; }
        thead tr { background:#fafafa; color:#1f2937; border-bottom: 2px solid #c9a227; }
        thead th { padding: 10px 12px; text-align:left; font-weight:700; font-size:12px; letter-spacing:0.03em; color:#1f2937; border-bottom: 2px solid #c9a227;}
        tbody td { padding: 9px 12px; border-bottom: 1px solid #f0f1f3; color:#1f2937; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #fcfcfd; }
        tfoot td { padding: 10px 12px; background: #fafafa; font-weight:700; color:#1f2937; border-top:2px solid #c9a227; border-bottom: 2px solid #c9a227;}
        .text-right { text-align:right; }
        .text-muted { color:#6b7280; font-size:12px; }
        .tag { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; }
        .tag-approve { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
        .footer-sign { display:grid; grid-template-columns: 1fr 1fr; gap:40px; margin-top:44px; }
        .sign-block { font-size:13px; }
        .sign-block .who { margin-bottom: 60px; color:#1f2937; font-weight:600; }
        .sign-line { border-top: 1px solid #6b7280; padding-top:6px; font-size:12px; color:#6b7280; }
        .empty { padding: 36px 20px; text-align:center; color:#6b7280; font-size:14px; background:#fafafa; border-radius:10px; border:1px dashed #d1d5db;}
        /* ===== ENGINEERING ACTIVITIES 3 KOLOM ===== */
        .tbl-act { width:100%; border-collapse:collapse; margin-top:8px; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;}
        .tbl-act thead th { background:#fafafa; color:#1f2937; border-bottom: 2px solid #c9a227; padding:10px 12px; text-align:left; font-weight:800; font-size:12px; letter-spacing:0.06em; text-transform:uppercase;}
        .tbl-act thead th.st-col { width: 180px; }
        .tbl-act .dept-col { width: 22%; min-width: 160px; }
        .tbl-act td { padding: 10px 12px; border-bottom: 1px solid #f0f1f3; vertical-align: top; font-size: 12.5px;}
        .tbl-act td.dept { font-weight: 900; font-size: 13px; color:#111827; letter-spacing:0.04em;}
        .tbl-act td.dept .ico { width: 32px; height:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:10px; margin-right: 10px; font-size: 14px; color:#fff;}
        .act-title { font-weight: 700; color:#111827; margin-right:8px; }
        .meta-tags { margin-top:4px; display:flex; gap:6px; flex-wrap:wrap;}
        .m-date { display:inline-flex; align-items:center; gap:4px; padding: 2px 9px; border-radius:999px; background:#f3f4f6; color:#4b5563; font-size: 10.5px; font-weight: 700; }
        .m-eng { display:inline-flex; align-items:center; gap:4px; padding: 2px 9px; border-radius:999px; background:#eef2ff; color:#3730a3; font-size: 10.5px; font-weight: 700; }
        .row-empty td { background: repeating-linear-gradient(45deg, #fafafa, #fafafa 8px, #fdfdfd 8px, #fdfdfd 16px); color:#9ca3af; font-style: italic;}
        .act-list { list-style:none; margin:0; padding:0;}
        .act-list li { padding: 3px 0 5px 0; }
        .st-pill-group { display:flex; flex-direction: column; gap:5px; align-items: flex-end; }
        .st-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 11px 4px 9px; border-radius:999px; font-size: 10.5px; font-weight:800; letter-spacing:0.03em;}
        .st-prog { background:#fffbeb; color:#92400e; border:1px solid #fde68a;}
        .st-done { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0;}
        .st-nodata { display:inline-flex; align-items:center; gap:6px; padding:4px 11px; border-radius:999px; background:#f9fafb; color:#6b7280; border:1px solid #e5e7eb; font-size: 11px; font-weight: 700;}
        .toolbar { display:none; position:fixed; top:16px; right:16px; z-index:50; gap:10px; }
        .toolbar button { border:none; padding: 10px 16px; border-radius: 10px; font-weight:600; cursor:pointer; font-size:13px; box-shadow: 0 6px 18px rgba(0,0,0,0.10);}
        .btn-primary { background:#c9a227; color:#fff; }
        .btn-primary:hover { background:#b8911f; }
        .btn-danger { background:#6b7280; color:#fff; }
        .btn-danger:hover { background:#4b5563; }
        @media screen { .toolbar { display:flex; } }
        @media print {
            body { background:#fff; }
            .page { margin:0; box-shadow:none; border-radius:0; }
            .toolbar { display:none !important; }
            .page { page-break-after: always; }
            @page { size: A4; margin: 10mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <button class="btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Cetak / Save PDF</button>
        <button class="btn-danger" onclick="window.close()"><i class="fas fa-xmark"></i> Tutup</button>
    </div>

    <div class="page">
        <div class="header">
            <div class="header-text">
                <h1>Engineering Report</h1>
                <p>Engineering Department — Laporan Dashboard Konsumsi Energi</p>
            </div>
        </div>
        <div class="divider"></div>
        <div class="content">
            <div class="meta">
                <div class="card"><div class="label">Periode</div><div class="value"><?= $periodLabel ?></div></div>
                <div class="card"><div class="label">Mode</div><div class="value"><?= $modeLabel ?></div></div>
                <div class="card"><div class="label">Dicetak Oleh</div><div class="value"><?= cleanInput($userName) ?></div></div>
                <div class="card"><div class="label">Tanggal Cetak</div><div class="value"><?= formatDate(date('Y-m-d')) ?></div></div>
            </div>

            <div class="summary-stat">
                <div class="stat elec">
                    <i class="fas fa-bolt ico"></i>
                    <div class="label">Total Listrik</div>
                    <div class="value"><?= formatNumber($totElec) ?><span class="unit"> kWh</span></div>
                </div>
                <div class="stat water">
                    <i class="fas fa-droplet ico"></i>
                    <div class="label">Total Air</div>
                    <div class="value"><?= formatNumber($totWater) ?><span class="unit"> m3</span></div>
                </div>
                <div class="stat gas">
                    <i class="fas fa-fire ico"></i>
                    <div class="label">Total Gas</div>
                    <div class="value"><?= formatNumber($totGas) ?><span class="unit"> kg</span></div>
                </div>
                <div class="stat total">
                    <i class="fas fa-file-lines ico"></i>
                    <div class="label">Daily Log</div>
                    <div class="value"><?= formatNumber($totLog) ?><span class="unit"> entri</span></div>
                </div>
            </div>

            <section class="title">
                <span class="bar"></span>
                <h2>Rangkuman Konsumsi <?= $modeLabel ?></h2>
            </section>
            <?php if (count($summaryData) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:20%"><?= $viewType === 'monthly' ? 'Bulan' : 'Tanggal' ?></th>
                            <th class="text-right">Total Daily Log</th>
                            <th class="text-right">⚡ Listrik (kWh)</th>
                            <th class="text-right">💧 Air (m3)</th>
                            <th class="text-right">🔥 Gas (kg)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summaryData as $row): ?>
                            <tr>
                                <td><strong><?= $viewType === 'monthly' ? $row['period_label'] : formatDate($row['period_label']) ?></strong></td>
                                <td class="text-right"><?= formatNumber((int)$row['log_count']) ?></td>
                                <td class="text-right"><?= formatNumber((float)$row['sum_elec']) ?></td>
                                <td class="text-right"><?= formatNumber((float)$row['sum_water']) ?></td>
                                <td class="text-right"><?= formatNumber((float)$row['sum_gas']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td><strong>TOTAL KESELURUHAN</strong></td>
                            <td class="text-right"><?= formatNumber($totLog) ?></td>
                            <td class="text-right"><?= formatNumber($totElec) ?> kWh</td>
                            <td class="text-right"><?= formatNumber($totWater) ?> m3</td>
                            <td class="text-right"><?= formatNumber($totGas) ?> kg</td>
                        </tr>
                    </tfoot>
                </table>
            <?php else: ?>
                <div class="empty"><i class="fas fa-chart-line" style="font-size:32px;opacity:40%;margin-bottom:10px;display:block;"></i>Belum ada data konsumsi yang disetujui pada periode ini.</div>
            <?php endif; ?>

            <section class="title">
                <span class="bar"></span>
                <h2>Detail Data Daily Log Approved</h2>
            </section>
            <?php if (count($detailData) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <?php if ($userRole === 'supervisor'): ?>
                                <th>Engineer</th>
                            <?php endif; ?>
                            <th class="text-right">Listrik</th>
                            <th class="text-right">Air</th>
                            <th class="text-right">Gas</th>
                            <th>Aktivitas Pekerjaan</th>
                            <th>Kendala</th>
                            <th>Solusi</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detailData as $row): ?>
                            <tr>
                                <td nowrap><strong><?= formatDate($row['log_date']) ?></strong></td>
                                <?php if ($userRole === 'supervisor'): ?>
                                    <td><?= cleanInput($row['engineer_name'] ?? '-') ?></td>
                                <?php endif; ?>
                                <td class="text-right" nowrap><?= formatNumber((float)$row['total_electricity']) ?> kWh</td>
                                <td class="text-right" nowrap><?= formatNumber((float)$row['total_water']) ?> m3</td>
                                <td class="text-right" nowrap><?= formatNumber((float)$row['total_gas']) ?> kg</td>
                                <td class="text-muted"><?= cleanInput($row['work_activities'] ?? '-') ?></td>
                                <td class="text-muted"><?= cleanInput($row['obstacles'] ?? '-') ?></td>
                                <td class="text-muted"><?= cleanInput($row['solutions'] ?? '-') ?></td>
                                <td><span class="tag tag-approve"><i class="fas fa-circle-check mr-1"></i>Approved</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty"><i class="fas fa-inbox" style="font-size:32px;opacity:40%;margin-bottom:10px;display:block;"></i>Belum ada data Daily Log yang disetujui Supervisor pada periode ini.</div>
            <?php endif; ?>

            <section class="title">
                <span class="bar" style="background:#6366f1;"></span>
                <h2>Engineering Activities <span style="font-weight:500;color:#6b7280;font-size:13px;">(Daily Log + Master Default Progress)</span></h2>
            </section>
            <table class="tbl-act">
                <thead>
                    <tr>
                        <th class="dept-col">Department</th>
                        <th>Activity Detail</th>
                        <th class="st-col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (['operation','maintenance','project','landscape'] as $dv):
                        $info = $divInfo_DP[$dv] ?? ['label'=>strtoupper($dv),'bg'=>'#f9fafb','ico'=>'fa-list','accent'=>'#6b7280'];
                        $rows = $actsGRP_DP[$dv] ?? [];
                        $nProg = 0; $nDone = 0;
                        foreach ($rows as $r) { if (($r['status'] ?? '') === 'complete') $nDone++; else $nProg++; }
                    ?>
                    <tr>
                        <td class="dept" style="background:<?= $info['bg'] ?>26;">
                            <span class="ico" style="background:<?= $info['accent'] ?>;">
                                <i class="fas <?= $info['ico'] ?>"></i>
                            </span><?= $info['label'] ?>
                        </td>
                        <td style="background:<?= $info['bg'] ?>1a;">
                            <?php if (count($rows) > 0): ?>
                                <ul class="act-list">
                                    <?php foreach ($rows as $r): ?>
                                    <li>
                                        <span class="act-title">&bull; <?= cleanInput((string)($r['title'] ?? '')) ?></span>
                                        <div class="meta-tags">
                                            <?php if ($f = fmtDtDp($r['date'] ?? '')): ?>
                                                <span class="m-date"><i class="fas fa-calendar" style="font-size:9px;opacity:70%;"></i> <?= $f ?></span>
                                            <?php endif; ?>
                                            <?php if (trim((string)($r['eng'] ?? '')) !== ''): ?>
                                                <span class="m-eng"><i class="fas fa-user-hard-hat" style="font-size:9px;opacity:80%;"></i> <?= cleanInput((string)$r['eng']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <span style="color:#9ca3af;font-style:italic;">
                                    <i class="fas fa-box-open" style="margin-right:6px;opacity:60%;"></i>Belum ada aktivitas bulan ini.
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="st-col" style="background:<?= $info['bg'] ?>1a; text-align:right;">
                            <?php if (count($rows) === 0): ?>
                                <span class="st-nodata">&ndash; No Data &ndash;</span>
                            <?php else: ?>
                                <div class="st-pill-group">
                                    <?php if ($nProg > 0): ?>
                                        <span class="st-pill st-prog">
                                            <i class="fas fa-spinner" style="font-size:9.5px;opacity:80%;"></i>
                                            In Progress <strong style="background:#fff;font-size:10px;padding:1px 7px;border-radius:999px;border:1px solid #fde68a;color:#92400e;"><?= $nProg ?></strong>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($nDone > 0): ?>
                                        <span class="st-pill st-done">
                                            <i class="fas fa-circle-check" style="font-size:9.5px;opacity:80%;"></i>
                                            Complete <strong style="background:#fff;font-size:10px;padding:1px 7px;border-radius:999px;border:1px solid #a7f3d0;color:#065f46;"><?= $nDone ?></strong>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () {
                try {
                    window.focus();
                    window.print();
                } catch (e) {}
            }, 450);
        });
    </script>
</body>
</html>
