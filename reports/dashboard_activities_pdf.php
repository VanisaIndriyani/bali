<?php
/**
 * 📋 REPORT PDF: ENGINEERING ACTIVITIES (ONLY IN PROGRESS / ON-GOING)
 * SUMBER DATA 100% MATCH DENGAN TABEL manager/activities.php
 *   - daily_logs.activity_*_items (JSON di log approved)   → unpack 1 row 1 item
 *   - activity_masters.status_default = 'progress'         → label BY ENG = "Master Activity"
 * FILTER: HANYA status = progress (Complete TIDAK ditampilkan)
 * FORMAT: FLAT TABLE 5 kolom → DEPARTMENT | ACTIVITY DETAIL (PALING LEBAR) | DATE | BY ENG | STATUS
 * DESIGN: SEDERHANA NETRAL (PUTIH + BORDER SLATE) TANPA WARNA RAMAI
 */

require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance();
$user  = currentUser();
$userRole = (string)($user['role']   ?? 'engineer');
$userId   = (int)   ($user['id']     ?? 0);
$userName = trim((string)($user['name'] ?? 'Engineering Staff'));

$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthRaw = $_GET['month'] ?? date('Y-m');
if (preg_match('/^\d{4}-\d{2}$/', (string)$monthRaw)) {
    $monthStart = $monthRaw . '-01';
    $today = date('Y-m-t', strtotime($monthStart));
}
if (isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_from'])) $monthStart = $_GET['date_from'];
if (isset($_GET['date_to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_to']))   $today      = $_GET['date_to'];
$monthLabel = (new DateTime($monthStart))->format('M Y');
$periodeLabel = date('d M Y', strtotime($monthStart)) . ' — ' . date('d M Y', strtotime($today));

$filename = 'Engineering_Activities_InProgress_' . substr($monthStart,0,7) . '.pdf';
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: max-age=0, must-revalidate');
ob_start();
echo "\xEF\xBB\xBF";

// ================================================================
// 📦 COLLECT DATA (SAMA PERSIS LOGIC DENGAN manager/activities.php)
// ================================================================
$engActRows = [];
try {
    // 🅰️ DARI DAILY LOGS: unpack 4 kolom JSON activity_*_items
    $colMap = [
        'operation'   => 'activity_operation_items',
        'maintenance' => 'activity_maintenance_items',
        'project'     => 'activity_project_items',
        'landscape'   => 'activity_landscape_items',
    ];
    $sqlLogs = "SELECT dl.id, dl.log_date, dl.engineer_id, COALESCE(u.name,'-') as engineer_name,
                       dl.activity_operation_items, dl.activity_maintenance_items,
                       dl.activity_project_items, dl.activity_landscape_items
                FROM daily_logs dl
                LEFT JOIN users u ON u.id = dl.engineer_id
                WHERE dl.status = 'approved'
                  AND DATE(dl.log_date) BETWEEN ? AND ?
                ORDER BY dl.log_date DESC, dl.id DESC";
    $logRows = $db->fetchAll($sqlLogs, [$monthStart, $today]);
    foreach ($logRows as $lr) {
        foreach ($colMap as $div => $colName) {
            $json = (string)($lr[$colName] ?? '');
            if ($json === '') continue;
            $arr = json_decode($json, true);
            if (!is_array($arr) || count($arr) === 0) continue;
            foreach ($arr as $it) {
                if (!is_array($it) || empty($it['t'])) continue;
                $st = (($it['s'] ?? 'progress') === 'complete') ? 'complete' : 'progress';
                if ($st !== 'progress') continue;
                $engActRows[] = [
                    'division'       => $div,
                    'activity_name'  => trim((string)$it['t']),
                    'log_date'       => (string)$lr['log_date'],
                    'engineer_name'  => (string)($lr['engineer_name'] ?? '-'),
                    'status'         => 'progress',
                    'is_master'      => false,
                ];
            }
        }
    }
    unset($logRows, $lr, $it, $arr, $json);

    // 🅱️ DARI ACTIVITY MASTERS (status_default = progress)
    $mstRows = $db->fetchAll("SELECT id, division, activity_name, status_default, created_at
                              FROM activity_masters
                              WHERE status_default = 'progress'
                              ORDER BY FIELD(division,'operation','maintenance','project','landscape'),
                                       sort_order ASC, id ASC");
    foreach ($mstRows as $mr) {
        $createdDT = substr((string)($mr['created_at'] ?? 'now'), 0, 10);
        if ($createdDT < $monthStart || $createdDT > $today) {
            // master tetap ditampilkan (template template default progress) — abaikan filter tanggal
        }
        $engActRows[] = [
            'division'       => (string)$mr['division'],
            'activity_name'  => trim((string)$mr['activity_name']),
            'log_date'       => $createdDT,
            'engineer_name'  => 'Master Activity',
            'status'         => 'progress',
            'is_master'      => true,
        ];
    }
    unset($mstRows, $mr);

    // 📶 Sort: tanggal DESC → divisi → nama activity
    usort($engActRows, function ($a, $b) {
        if ($a['log_date'] !== $b['log_date']) {
            return strcmp($b['log_date'], $a['log_date']);
        }
        $dvOrder = ['operation'=>0,'maintenance'=>1,'project'=>2,'landscape'=>3];
        $ao = $dvOrder[$a['division']] ?? 9;
        $bo = $dvOrder[$b['division']] ?? 9;
        if ($ao !== $bo) return $ao - $bo;
        return strcasecmp($a['activity_name'], $b['activity_name']);
    });
} catch (Throwable $e) {
    error_log('dashboard_activities_pdf.php collect: ' . $e->getMessage());
    $engActRows = [];
}

$divLabelMap = [
    'operation'   => 'OPERATION',
    'maintenance' => 'MAINTENANCE',
    'project'     => 'PROJECT',
    'landscape'   => 'LANDSCAPE',
];
$totalRows = count($engActRows);

// Count per divisi untuk summary
$cntPerDept = ['operation'=>0,'maintenance'=>0,'project'=>0,'landscape'=>0];
foreach ($engActRows as $r) {
    $d = $r['division'] ?? 'operation';
    if (isset($cntPerDept[$d])) $cntPerDept[$d]++;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Engineering Activities (In Progress) - <?= htmlspecialchars($monthLabel) ?></title>
<style>
    @page { size: A4 landscape; margin: 10mm 10mm 14mm 10mm; }
    * { -webkit-box-sizing: border-box; box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0;
        background: #ffffff !important;
        color: #0f172a !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        font-size: 11px;
    }
    .wrap { width:100%; min-height:100vh; background:#ffffff !important; padding:0 0 16px 0; position:relative; }

    /* ===== HEADER SIMPLE ===== */
    .header {
        display:flex; align-items:flex-end; justify-content:space-between;
        padding-bottom: 10px; margin-bottom: 14px;
        border-bottom: 2px solid #0f172a;
    }
    .header .left .eyebrow {
        color:#475569; font-size:9.5px; font-weight:800; letter-spacing:2.2px; text-transform:uppercase;
        margin-bottom: 6px;
    }
    .header .left h1 {
        color:#0f172a; font-size:22px; font-weight:900; letter-spacing:-0.015em; line-height:1;
        margin: 0 0 4px 0; padding:0;
    }
    .header .left .sub {
        color:#64748b; font-size:10.5px; font-weight:600; margin:0; padding:0;
    }
    .header .right {
        text-align:right; color:#475569;
    }
    .header .right .pill {
        display:inline-block; padding:3px 10px; border:1px solid #cbd5e1; background:#f8fafc;
        color:#334155; border-radius: 999px; font-size:10px; font-weight:800;
        letter-spacing: 0.6px; text-transform:uppercase;
    }
    .header .right .meta {
        margin-top:6px; font-size:9.5px; font-weight:700; color:#64748b;
        text-transform:uppercase; letter-spacing: 0.4px;
    }

    /* ===== SUMMARY CHIPS SIMPLE ===== */
    .chips {
        display:flex; flex-wrap:wrap; gap: 7px; margin: 0 0 13px 0;
    }
    .chip {
        display:inline-flex; align-items:center; gap:6px;
        padding:4px 10px; background:#ffffff; border:1px solid #e2e8f0; border-radius:10px;
        color:#475569; font-size:10px; font-weight:700;
    }
    .chip strong { color:#0f172a; font-weight:800; }
    .chip .dot { width:5px; height:5px; border-radius:50%; background:#94a3b8; display:inline-block; }
    .chip.on .dot { background:#f59e0b; }
    .chip.only {
        background:#f1f5f9; border-color:#cbd5e1; color:#0f172a;
    }

    /* ===== MAIN TABLE FLAT 5 KOLOM ===== */
    .tbl {
        width: 100%; border-collapse: collapse;
        border: 1px solid #cbd5e1; background: #fff;
        page-break-inside: auto;
    }
    .tbl thead th {
        background: #f1f5f9;
        color: #0f172a;
        font-size: 10px; font-weight: 900; letter-spacing: 0.14em; text-transform: uppercase;
        text-align: left;
        padding: 10px 12px;
        border-bottom: 2px solid #94a3b8;
        border-right: 1px solid #e2e8f0;
        vertical-align: middle;
    }
    .tbl thead th:last-child,
    .tbl tbody td:last-child { border-right: none; }

    .tbl thead th.c-dept   { width: 14%; }
    .tbl thead th.c-detail { width: 44%; }
    .tbl thead th.c-date   { width: 11%; text-align:center; }
    .tbl thead th.c-eng    { width: 20%; }
    .tbl thead th.c-st     { width: 11%; text-align:center; }

    .tbl tbody td {
        padding: 9px 12px;
        border-top: 1px solid #e2e8f0;
        border-right: 1px solid #f1f5f9;
        vertical-align: top;
        color:#0f172a;
        font-size: 11px;
    }
    .tbl tbody tr:nth-child(even) td { background:#fafbfc; }
    .tbl tbody tr:hover td { background:#f8fafc; }

    /* Dept badge */
    .dept-badge {
        display:inline-block;
        padding: 3px 9px;
        border:1px solid #cbd5e1;
        background:#f8fafc;
        border-radius:6px;
        font-size:9.5px;
        font-weight:900;
        letter-spacing:0.12em;
        text-transform:uppercase;
        color:#0f172a;
        white-space:nowrap;
    }

    /* Activity detail */
    .act-title {
        font-weight: 600; color:#0f172a; font-size: 11.5px;
        line-height: 1.45; word-break: break-word;
    }
    .act-master-tag {
        display:inline-block; margin-top:5px;
        padding:1.5px 7px;
        background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe;
        border-radius:5px;
        font-size:8.5px; font-weight:900; letter-spacing:0.1em; text-transform:uppercase;
    }

    /* Date */
    .date-val {
        display:block; text-align:center;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 10.5px; font-weight: 800; color:#0f172a;
        white-space: nowrap;
    }

    /* Engineer */
    .eng-row { display:flex; align-items:center; gap: 7px; min-width:0; }
    .eng-av {
        width: 22px; height: 22px; flex-shrink:0;
        border-radius: 6px;
        background:#334155; color:#ffffff;
        display:inline-flex; align-items:center; justify-content:center;
        font-size:9.5px; font-weight:900;
    }
    .eng-av.master {
        background:#2563eb;
    }
    .eng-name {
        font-size:10.5px; font-weight:700; color:#0f172a;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0;
    }
    .eng-master-chip {
        display:inline-block; margin-left: auto;
        padding:1px 6px;
        background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe;
        border-radius:5px;
        font-size:8.5px; font-weight:900; letter-spacing:0.06em;
        white-space:nowrap;
    }

    /* Status */
    .st-box {
        display:flex; justify-content:center;
    }
    .st-inprog {
        display:inline-flex; align-items:center; gap:5px; justify-content:center;
        padding:4px 10px; border-radius:999px;
        background:#f1f5f9; color:#0f172a;
        border: 1px solid #cbd5e1;
        font-size:9.5px; font-weight:900; letter-spacing:0.08em; text-transform:uppercase;
        white-space:nowrap;
    }
    .st-inprog .c { width:6px; height:6px; border-radius:50%; background:#f59e0b; display:inline-block; }

    /* Empty state */
    .empty td {
        padding: 32px 18px; text-align:center; color:#64748b;
        font-size: 12px; font-weight: 600; background:#fff !important;
        border-top: 1px solid #e2e8f0;
    }
    .empty .ico { font-size: 36px; color:#cbd5e1; margin-bottom: 8px; }

    /* ===== FOOTER SIMPLE ===== */
    .foot {
        margin-top: 18px; padding-top: 10px;
        border-top: 1px dashed #cbd5e1;
        display:flex; align-items:center; justify-content:space-between;
        color:#94a3b8; font-size:9px; font-weight:600; letter-spacing: 0.3px;
    }
    .foot .brand { color:#475569; font-weight: 800; letter-spacing: 1.5px; text-transform:uppercase; font-size:9.5px;}

    @media print { body { background: #fff !important; } }
</style>
</head>
<body>
<div class="wrap">

    <!-- HEADER KOP -->
    <div class="header">
        <div class="left">
            <div class="eyebrow">Engineering Department • Report</div>
            <h1>Engineering Activities</h1>
            <div class="sub">Daftar aktivitas on-going — status Complete otomatis disembunyikan.</div>
        </div>
        <div class="right">
            <div class="pill">Periode: <?= htmlspecialchars($periodeLabel) ?></div>
            <div class="meta">Printed: <?= date('d M Y, H:i') ?> • By: <?= htmlspecialchars($userName) ?></div>
        </div>
    </div>

    <!-- SUMMARY CHIPS -->
    <div class="chips">
        <div class="chip only">
            <i class="fas fa-filter" style="font-size:9px;color:#334155;"></i>
            Filter: <strong>Only In Progress</strong>
        </div>
        <div class="chip on">
            <span class="dot"></span> Total On-Going <strong><?= number_format($totalRows,0,',','.') ?></strong>
        </div>
        <?php foreach (['operation'=>'OPERATION','maintenance'=>'MAINTENANCE','project'=>'PROJECT','landscape'=>'LANDSCAPE'] as $k=>$lbl):
            if (($cntPerDept[$k] ?? 0) <= 0) continue; ?>
        <div class="chip">
            <i class="fas fa-folder" style="font-size:9px;color:#64748b;"></i>
            <?= $lbl ?> <strong><?= $cntPerDept[$k] ?></strong>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- TABLE UTAMA 5 KOLOM -->
    <table class="tbl">
        <thead>
            <tr>
                <th class="c-dept">DEPARTMENT</th>
                <th class="c-detail">ACTIVITY DETAIL</th>
                <th class="c-date">DATE</th>
                <th class="c-eng">BY ENG</th>
                <th class="c-st">STATUS</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($engActRows)): ?>
            <tr class="empty">
                <td colspan="5">
                    <div class="ico"><i class="far fa-clipboard-list-check"></i></div>
                    Belum ada aktivitas dengan status <strong>In Progress</strong> di periode ini.<br>
                    Tambahkan activity progress melalui form manager atau daily log engineer.
                </td>
            </tr>
            <?php else:
                $prevDept = '';
                foreach ($engActRows as $row):
                    $dept = (string)($row['division'] ?? 'operation');
                    $deptLabel = $divLabelMap[$dept] ?? strtoupper($dept);
                    $isMaster = !empty($row['is_master']);
                    $dateStr = date('j-M-y', strtotime((string)$row['log_date']));
                    $engRaw  = (string)($row['engineer_name'] ?? '-');
                    $actName = trim((string)$row['activity_name'] ?? '');
            ?>
            <tr>
                <!-- DEPARTMENT -->
                <td>
                    <span class="dept-badge"><?= $deptLabel ?></span>
                </td>
                <!-- ACTIVITY DETAIL -->
                <td>
                    <div class="act-title"><?= htmlspecialchars($actName) ?></div>
                    <?php if ($isMaster): ?>
                    <span class="act-master-tag"><i class="fas fa-database" style="font-size:8px;margin-right:3px;"></i> Master Template</span>
                    <?php endif; ?>
                </td>
                <!-- DATE -->
                <td>
                    <span class="date-val"><?= $dateStr ?></span>
                </td>
                <!-- BY ENG -->
                <td>
                    <?php if ($isMaster): ?>
                    <div class="eng-row">
                        <div class="eng-av master"><i class="fas fa-database" style="font-size:9px;"></i></div>
                        <span class="eng-name" style="color:#1e40af;">Master Activity</span>
                    </div>
                    <?php else:
                        $init = strtoupper(mb_substr($engRaw, 0, 1) ?: '?');
                    ?>
                    <div class="eng-row">
                        <div class="eng-av"><?= $init ?></div>
                        <span class="eng-name" title="<?= htmlspecialchars($engRaw) ?>"><?= htmlspecialchars($engRaw) ?></span>
                    </div>
                    <?php endif; ?>
                </td>
                <!-- STATUS -->
                <td>
                    <div class="st-box">
                        <span class="st-inprog">
                            <span class="c"></span> In Progress
                        </span>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <!-- FOOT NOTE -->
    <div class="foot">
        <div class="brand">Engineering Report</div>
        <div>• Filter hanya tampilkan status progress — Complete tidak dicetak •</div>
        <div>Generated: <?= date('Y-m-d H:i') ?></div>
    </div>

</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="all">
</body>
</html>
<?php echo ob_get_clean(); exit; ?>
