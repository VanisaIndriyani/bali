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
header('Cache-Control: max-age=0, must-revalidate');
ob_start();
echo "\xEF\xBB\xBF";

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
    @page { size: A4 landscape; margin: 10mm 10mm 12mm 10mm; }
    * { -webkit-box-sizing: border-box; box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0; background:#fff !important; color:#0f172a !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
        font-size: 10.5px;
    }
    .wrap { width:100%; min-height:100vh; background:#fff; }

    /* HEADER SUPER MINI — 1 BARIS SAJA */
    .head {
        display:flex; align-items:center; justify-content:space-between;
        padding-bottom: 8px; margin-bottom: 10px;
        border-bottom: 1.5px solid #0f172a;
    }
    .head .t { font-size:16px; font-weight:900; color:#0f172a; letter-spacing:-0.01em; }
    .head .r { font-size:10px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.08em; }
    .filter {
        display:inline-block; margin-left:8px;
        padding:2px 8px; border:1px solid #cbd5e1; background:#f8fafc;
        font-size:9px; font-weight:800; color:#334155; border-radius:999px;
        letter-spacing:0.08em; text-transform:uppercase; vertical-align: middle;
    }

    /* TABLE — NETRAL FULL, TANPA WARNA */
    table.t { width:100%; border-collapse:collapse; border:1px solid #e2e8f0; }
    table.t th {
        background:#f8fafc; color:#0f172a;
        font-size:9.5px; font-weight:900; letter-spacing:0.14em; text-transform:uppercase;
        text-align:left; padding:8px 10px;
        border-bottom: 1.5px solid #cbd5e1;
        border-right: 1px solid #e2e8f0;
    }
    table.t th:last-child { border-right:none; }
    table.t th.d1 { width:13%; }
    table.t th.d2 { width:46%; }
    table.t th.d3 { width:11%; text-align:center; }
    table.t th.d4 { width:19%; }
    table.t th.d5 { width:11%; text-align:center; }

    table.t td {
        padding:7px 10px; color:#0f172a; font-size:10.5px;
        border-top:1px solid #f1f5f9; border-right:1px solid #f8fafc;
        vertical-align: top;
    }
    table.t td:last-child { border-right:none; }
    table.t tr:nth-child(even) td { background:#fafbfc; }

    /* Cell content styling — SINGKAT, TANPA WARNA */
    .dept { font-size:9.5px; font-weight:900; letter-spacing:0.12em; text-transform:uppercase; color:#334155; }
    .act  { font-size:11px; font-weight:600; line-height:1.45; color:#0f172a; word-break:break-word; }
    .dt   { display:block; text-align:center; font-family: ui-monospace, Menlo, monospace;
            font-size:10px; font-weight:800; color:#0f172a; white-space:nowrap; }
    .eng  { font-size:10.5px; font-weight:600; color:#334155; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .eng.master { color:#334155; }
    .st   { display:flex; justify-content:center; }
    .st-s {
        display:inline-block; padding:3px 9px; border-radius:999px;
        border:1px solid #cbd5e1; background:#f8fafc;
        font-size:9px; font-weight:800; letter-spacing:0.1em; text-transform:uppercase;
        color:#334155; white-space:nowrap;
    }

    /* Empty — HANYA TEKS SINGKAT */
    .empty td {
        padding:26px 14px; text-align:center;
        color:#64748b; font-size:11px; font-weight:600; background:#fff !important;
    }

    /* Foot mini — 1 baris */
    .foot {
        margin-top:10px; padding-top:6px; border-top:1px dashed #cbd5e1;
        display:flex; justify-content:space-between;
        color:#94a3b8; font-size:8.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em;
    }
</style>
</head>
<body>
<div class="wrap">

    <div class="head">
        <div class="t">
            Engineering Activities
            <span class="filter">Only In Progress</span>
        </div>
        <div class="r">
            Periode <?= htmlspecialchars($periodeLabel) ?>
        </div>
    </div>

    <table class="t">
        <thead>
            <tr>
                <th class="d1">Department</th>
                <th class="d2">Activity Detail</th>
                <th class="d3">Date</th>
                <th class="d4">By Eng</th>
                <th class="d5">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($engActRows)): ?>
            <tr class="empty"><td colspan="5">Tidak ada data In Progress di periode ini.</td></tr>
            <?php else:
                foreach ($engActRows as $row):
                    $dept = (string)($row['division'] ?? 'operation');
                    $deptLbl = $divLabelMap[$dept] ?? strtoupper($dept);
                    $isMst = !empty($row['is_master']);
                    $dt = date('j-M-y', strtotime((string)$row['log_date']));
                    $eng = trim((string)($row['engineer_name'] ?? '-'));
                    $act = trim((string)($row['activity_name'] ?? ''));
            ?>
            <tr>
                <td><span class="dept"><?= $deptLbl ?></span></td>
                <td><div class="act"><?= htmlspecialchars($act) ?></div></td>
                <td><span class="dt"><?= $dt ?></span></td>
                <td><div class="eng<?= $isMst ? ' master':'' ?>"><?= htmlspecialchars($eng) ?></div></td>
                <td><div class="st"><span class="st-s">In Progress</span></div></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="foot">
        <div>Total: <?= $total ?> rows</div>
        <div>Print: <?= date('d M Y') ?></div>
    </div>

</div>
</body>
</html>
<?php echo ob_get_clean(); exit; ?>
