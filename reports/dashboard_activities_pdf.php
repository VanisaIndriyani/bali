<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance();
$user  = currentUser();
$userName = trim((string)($user['name'] ?? ''));

$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthRaw = $_GET['month'] ?? date('Y-m');
if (preg_match('/^\d{4}-\d{2}$/', (string)$monthRaw)) {
    $monthStart = $monthRaw . '-01';
    $today = date('Y-m-t', strtotime($monthStart));
}
if (isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_from'])) $monthStart = $_GET['date_from'];
if (isset($_GET['date_to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_to']))   $today      = $_GET['date_to'];
$periodeLabel = date('d M Y', strtotime($monthStart)) . ' — ' . date('d M Y', strtotime($today));

$filename = 'Engineering_Activities_' . substr($monthStart,0,7) . '.pdf';
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: max-age=0, must-revalidate, no-store, no-cache');
header('Pragma: public');
ob_start();

$engActRows = [];
try {
    $colMap = [
        'operation'   => 'activity_operation_items',
        'maintenance' => 'activity_maintenance_items',
        'project'     => 'activity_project_items',
        'landscape'   => 'activity_landscape_items',
    ];
    $logRows = $db->fetchAll(
        "SELECT dl.id, dl.log_date, COALESCE(u.name,'-') as engineer_name,
                dl.activity_operation_items, dl.activity_maintenance_items,
                dl.activity_project_items, dl.activity_landscape_items
         FROM daily_logs dl LEFT JOIN users u ON u.id = dl.engineer_id
         WHERE dl.status='approved' AND DATE(dl.log_date) BETWEEN ? AND ?
         ORDER BY dl.log_date DESC, dl.id DESC",
        [$monthStart, $today]
    );
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
                    'division'      => $div,
                    'activity_name' => trim((string)$it['t']),
                    'log_date'      => (string)$lr['log_date'],
                    'engineer_name' => (string)($lr['engineer_name'] ?? '-'),
                    'is_master'     => false,
                ];
            }
        }
    }
    unset($logRows, $lr, $it, $arr, $json);

    $mstRows = $db->fetchAll(
        "SELECT division, activity_name, created_at FROM activity_masters
         WHERE status_default='progress'
         ORDER BY FIELD(division,'operation','maintenance','project','landscape'), sort_order ASC, id ASC"
    );
    foreach ($mstRows as $mr) {
        $engActRows[] = [
            'division'      => (string)$mr['division'],
            'activity_name' => trim((string)$mr['activity_name']),
            'log_date'      => substr((string)($mr['created_at'] ?? 'now'), 0, 10),
            'engineer_name' => 'Master Activity',
            'is_master'     => true,
        ];
    }
    unset($mstRows, $mr);

    usort($engActRows, function ($a, $b) {
        if ($a['log_date'] !== $b['log_date']) return strcmp($b['log_date'], $a['log_date']);
        $o = ['operation'=>0,'maintenance'=>1,'project'=>2,'landscape'=>3];
        $ao = $o[$a['division']] ?? 9; $bo = $o[$b['division']] ?? 9;
        if ($ao !== $bo) return $ao - $bo;
        return strcasecmp($a['activity_name'], $b['activity_name']);
    });
} catch (Throwable $e) {
    error_log('pdf_activities collect: ' . $e->getMessage());
    $engActRows = [];
}

$divLabelMap = [
    'operation'   => 'OPERATION',
    'maintenance' => 'MAINTENANCE',
    'project'     => 'PROJECT',
    'landscape'   => 'LANDSCAPE',
];
$total = count($engActRows);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Engineering Activities - <?= htmlspecialchars($periodeLabel) ?></title>
<style>
    @page { size: A4 landscape; margin: 12mm 12mm 14mm 12mm; }
    * { -webkit-box-sizing: border-box; box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0; background:#fff !important; color:#0f172a !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        font-size: 10.5px;
        line-height: 1.45;
    }
    .wrap { width:100%; min-height:100vh; background:#fff; }

    /* ============ HEADER — CLEAN & POLISHED ============ */
    .head {
        display:flex; align-items:center; justify-content:space-between;
        padding: 0 2px 12px 2px; margin-bottom: 14px;
        border-bottom: 2px solid #0f172a;
    }
    .head .title-text h1 {
        margin: 0; padding: 0; line-height: 1.05;
        font-size: 20px; font-weight: 900; color:#0f172a; letter-spacing: -0.015em;
    }
    .head .title-text .sub {
        margin-top: 4px; padding: 0;
        font-size: 9.5px; font-weight: 700; color:#64748b;
        text-transform: uppercase; letter-spacing: 0.15em;
    }
    .head .right {
        display:flex; flex-direction:column; align-items:flex-end; gap: 6px;
    }
    .head .period {
        display:inline-block; padding: 4px 12px;
        border: 1px solid #e2e8f0; background:#f8fafc;
        border-radius: 8px;
        font-size: 10px; font-weight: 800; color:#0f172a;
        letter-spacing: 0.06em; text-transform: uppercase;
    }
    .head .filter {
        display:inline-flex; align-items:center; gap:5px;
        padding: 3px 10px;
        border: 1px solid #cbd5e1; background:#f1f5f9;
        border-radius: 999px;
        font-size: 9px; font-weight: 800; color:#334155;
        letter-spacing: 0.1em; text-transform: uppercase;
    }
    .head .filter .dot {
        width:5px; height:5px; border-radius:50%; background:#94a3b8; display:inline-block; }

    /* ============ TABLE — POLISHED NETRAL ============ */
    .tbl {
        width:100%; border-collapse: separate; border-spacing: 0;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
        background: #ffffff;
        box-shadow: 0 1px 3px rgba(15,23,42,0.05);
    }
    .tbl thead th {
        background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
        color:#0f172a;
        font-size: 9.5px; font-weight: 900;
        letter-spacing: 0.14em; text-transform: uppercase;
        text-align:left;
        padding: 11px 14px;
        border-bottom: 1.5px solid #cbd5e1;
        border-right: 1px solid #e2e8f0;
        position: sticky; top:0;
    }
    .tbl thead th:last-child { border-right:none; }
    .tbl thead th.c1 { width:13%; }
    .tbl thead th.c2 { width:46%; }
    .tbl thead th.c3 { width:11%; text-align:center; }
    .tbl thead th.c4 { width:19%; }
    .tbl thead th.c5 { width:11%; text-align:center; }

    .tbl tbody td {
        padding: 9px 14px;
        color:#0f172a; font-size: 10.5px;
        border-top: 1px solid #f1f5f9;
        border-right: 1px solid #f8fafc;
        vertical-align: top;
    }
    .tbl tbody td:last-child { border-right:none; }
    .tbl tbody tr:nth-child(even) td { background:#fafbfc; }
    .tbl tbody tr:hover td { background:#f8fafc; }
    .tbl tbody tr:last-child td { border-bottom: none; }

    /* Cell content */
    .dept {
        display:inline-block;
        padding: 3px 10px;
        border: 1px solid #e2e8f0;
        background:#ffffff;
        border-radius: 6px;
        font-size: 9.5px; font-weight: 900;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color:#334155;
        white-space: nowrap;
        box-shadow: 0 1px 1px rgba(15,23,42,0.03);
    }
    .act {
        font-size: 11px;
        font-weight: 600;
        line-height: 1.5;
        color:#0f172a;
        word-break: break-word;
    }
    .dt {
        display:block; text-align:center;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 10px; font-weight: 800; color:#0f172a;
        white-space: nowrap;
        padding: 2px 0;
    }
    .eng {
        display:flex; align-items:center; gap: 8px;
        min-width: 0;
    }
    .eng .av {
        width: 20px; height: 20px; border-radius: 6px;
        background:#334155; color:#ffffff;
        display:inline-flex; align-items:center; justify-content:center;
        font-size: 9px; font-weight: 900;
        flex-shrink: 0;
        letter-spacing: 0.02em;
    }
    .eng .av.master { background:#475569; }
    .eng .nm {
        font-size: 10.5px; font-weight: 700; color:#334155;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        min-width: 0; flex: 1 1 0%;
    }
    .st { display:flex; justify-content:center; align-items:center; }
    .st .sb {
        display:inline-flex; align-items:center; gap: 6px;
        padding: 4px 11px;
        border-radius: 999px;
        border: 1px solid #e2e8f0;
        background:#ffffff;
        font-size: 9.5px; font-weight: 800;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color:#334155;
        white-space: nowrap;
        box-shadow: 0 1px 1px rgba(15,23,42,0.03);
    }
    .st .sb .d {
        width: 6px; height: 6px; border-radius: 50%;
        background:#94a3b8; display:inline-block;
    }

    /* Empty state */
    .empty td {
        padding: 36px 18px !important;
        text-align:center !important;
        color:#64748b;
        font-size: 12px; font-weight: 600;
        background:#ffffff !important;
    }
    .empty .ico {
        display: inline-block;
        width: 44px; height: 44px; line-height: 44px;
        border-radius: 12px;
        background:#f8fafc; border:1px solid #e2e8f0;
        color:#cbd5e1;
        font-size: 18px;
        margin-bottom: 10px;
    }

    /* ============ FOOTER — CLEAN ============ */
    .foot {
        margin-top: 14px;
        padding: 10px 2px 0 2px;
        border-top: 1px dashed #cbd5e1;
        display:flex; align-items:center; justify-content:space-between;
        color:#94a3b8;
        font-size: 8.5px; font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
    }
    .foot .count { color:#334155; font-weight: 800; }
    .toolbar { display:none; position:fixed; top:16px; right:16px; z-index:50; gap:10px; }
    .toolbar button { border:none; padding: 10px 16px; border-radius: 10px; font-weight:700; cursor:pointer; font-size:13px; box-shadow: 0 6px 18px rgba(0,0,0,0.12);}
    .btn-primary { background:#0f172a; color:#fff; }
    .btn-primary:hover { background:#1e293b; }
    .btn-danger { background:#64748b; color:#fff; }
    .btn-danger:hover { background:#475569; }
    @media screen { .toolbar { display:flex; } }
    @media print {
        body { background:#fff; }
        .wrap { margin:0; box-shadow:none; border-radius:0; }
        .toolbar { display:none !important; }
    }
</style>
</head>
<body>
<div class="toolbar no-print">
    <button class="btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Save as PDF / Cetak</button>
    <button class="btn-danger" onclick="window.close()"><i class="fas fa-xmark"></i> Tutup</button>
</div>
<div class="wrap">

    <!-- HEADER -->
    <div class="head">
        <div class="title-text">
            <h1>Engineering Activities</h1>
            <div class="sub">Engineering Department • Report</div>
        </div>
        <div class="right">
            <div class="period">Periode <?= htmlspecialchars($periodeLabel) ?></div>
            <div class="filter"><span class="dot"></span> Only In Progress</div>
        </div>
    </div>

    <!-- MAIN TABLE -->
    <table class="tbl">
        <thead>
            <tr>
                <th class="c1">Department</th>
                <th class="c2">Activity Detail</th>
                <th class="c3">Date</th>
                <th class="c4">By Eng</th>
                <th class="c5">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($engActRows)): ?>
            <tr class="empty">
                <td colspan="5">
                    <div class="ico"><i class="far fa-clipboard-list-check"></i></div>
                    Tidak ada aktivitas In Progress di periode ini.
                </td>
            </tr>
            <?php else:
                foreach ($engActRows as $row):
                    $dept = (string)($row['division'] ?? 'operation');
                    $deptLbl = $divLabelMap[$dept] ?? strtoupper($dept);
                    $isMst = !empty($row['is_master']);
                    $dt = date('j-M-y', strtotime((string)$row['log_date']));
                    $eng = trim((string)($row['engineer_name'] ?? '-'));
                    $act = trim((string)($row['activity_name'] ?? ''));
                    $init = strtoupper(mb_substr($eng, 0, 1) ?: '?');
            ?>
            <tr>
                <td><span class="dept"><?= $deptLbl ?></span></td>
                <td><div class="act"><?= htmlspecialchars($act) ?></div></td>
                <td><span class="dt"><?= $dt ?></span></td>
                <td>
                    <div class="eng">
                        <div class="av<?= $isMst ? ' master' : '' ?>"><?= $isMst ? '<i class="fas fa-database" style="font-size:8px;"></i>' : $init ?></div>
                        <div class="nm"><?= htmlspecialchars($eng) ?></div>
                    </div>
                </td>
                <td>
                    <div class="st"><span class="sb"><span class="d"></span> In Progress</span></div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <!-- FOOTER -->
    <div class="foot">
        <div>Total Data: <span class="count"><?= $total ?></span></div>
        <div>Generated: <?= date('d M Y') ?></div>
    </div>

</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="all">
<script>
    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(function () {
            try { window.focus(); window.print(); } catch (e) {}
        }, 500);
    });
</script>
</body>
</html>
<?php echo ob_get_clean(); exit; ?>
