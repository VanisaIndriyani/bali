<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['supervisor', 'manager']);

$db = Database::getInstance();
$user = currentUser();
$role = (string)($user['role'] ?? '');

$filter = $_GET['filter'] ?? 'all';
$search = cleanInput($_GET['search'] ?? '');

$where = [];
$params = [];

if ($role === 'engineer') {
    $where[] = "o.requested_by = ?";
    $params[] = (int)$user['id'];
} elseif ($role === 'supervisor') {
    if ($filter === 'my_pending') {
        $where[] = "o.status = 'pending_supervisor'";
    } elseif ($filter !== 'all') {
        $where[] = "o.status = ?";
        $params[] = $filter;
    }
} elseif ($role === 'manager') {
    if ($filter === 'my_pending') {
        $where[] = "o.status = 'pending_manager'";
    } elseif ($filter !== 'all') {
        $where[] = "o.status = ?";
        $params[] = $filter;
    }
} else {
    if ($filter !== 'all') {
        $where[] = "o.status = ?";
        $params[] = $filter;
    }
}

if ($search !== '') {
    $where[] = "(o.order_no LIKE ? OR o.title LIKE ? OR cc.code LIKE ? OR cc.name LIKE ? OR u.name LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

$sqlWhere = count($where) ? " WHERE " . implode(" AND ", $where) : '';
$sql = "SELECT o.*, cc.code as cc_code, cc.name as cc_name, u.name as req_name
        FROM orders o
        LEFT JOIN cost_codes cc ON cc.id = o.cost_code_id
        LEFT JOIN users u ON u.id = o.requested_by
        {$sqlWhere}
        ORDER BY o.id DESC
        LIMIT 1000";
$orders = $db->fetchAll($sql, $params);

$statusLabelMap = [
    'all' => 'Semua Order',
    'my_pending' => $role === 'supervisor' ? 'Perlu Approval Saya (Spv)' : 'Perlu Approval Saya (Mgr)',
    'pending_supervisor' => 'Menunggu Supervisor',
    'pending_manager' => 'Menunggu Manager',
    'approved' => 'Disetujui',
    'rejected' => 'Ditolak',
    'draft' => 'Draft',
    'completed' => 'Selesai',
];
$filterLabel = $statusLabelMap[$filter] ?? 'Semua Order';
$totalAll = 0.0;
$cntByStatus = [
    'pending_supervisor' => 0, 'pending_manager' => 0,
    'approved' => 0, 'rejected' => 0, 'draft' => 0, 'completed' => 0
];
foreach ($orders as $o) {
    $totalAll += (float)($o['total_amount'] ?? 0);
    $st = (string)($o['status'] ?? '');
    if (isset($cntByStatus[$st])) $cntByStatus[$st]++;
}

$dateLabel = strtoupper(date('j F Y'));
$filterLabelUpper = strtoupper($filterLabel);
$qsExport = http_build_query($_GET);
if ($qsExport !== '') $qsExport = '?' . $qsExport;

$fileName = 'Logistik_Order_Report_' . date('Y_m_d') . '_' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$filter), 0, 18);
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="' . $fileName . '.pdf"');
header('Cache-Control: max-age=0, must-revalidate');
ob_start();
echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Logistik / Order Report - <?= htmlspecialchars($dateLabel) ?></title>
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
    .info-row { font-size:12px; color:#4b5563; margin-bottom:10px 0 4px; line-height:1.55;}
    .info-row strong { color:#111;}
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

    .st-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; font-size:10.5px; font-weight:800; letter-spacing:.02em; }
    .st-nodata { padding:4px 11px; border-radius:999px; background:#f9fafb; color:#6b7280; border:1px solid #d1d5db; font-size:11px; font-weight:700;}
    .st-sup { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
    .st-mgr { background:#f0f9ff; color:#075985; border:1px solid #bae6fd; }
    .st-ok  { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .st-no  { background:#fff1f2; color:#9f1239; border:1px solid #fecdd3; }
    .st-dr  { background:#f3f4f6; color:#374151; border:1px solid #cbd5e1; }
    .st-done{ background:#f5f3ff; color:#5b21b6; border:1px solid #ddd6fe; }
    .st-pill .count { background:#fff; font-size:9.5px; padding:1px 6px; border-radius:999px; border:1px solid; font-weight:900;}
    .empty { padding:18px 12px; text-align:center; color:#6b7280; font-weight:700; border:1px solid #000;}

    .tfoot-total td { background:#fff7ed; color:#92400e!important; font-weight:900; border-top:2px solid #000; }

    .sign-footer { width:100%; margin-top:18px; display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; page-break-inside:avoid; }
    .sign-box { text-align:center; font-size:12px; }
    .sign-box .lbl { font-weight:600; margin-bottom:46px; }
    .sign-box .line { border-top:1px solid #000; padding-top:5px; font-weight:800; }
</style>
</head>
<body>
<div class="action-bar">
    <a class="btn btn-excel" href="<?= BASE_URL ?>orders/orders_excel.php<?= $qsExport ?>" target="_blank"><i class="fa-solid fa-file-excel"></i> Download Excel</a>
    <button type="button" class="btn btn-pdf" onclick="window.print()"><i class="fa-solid fa-file-pdf"></i> Save as PDF / Print</button>
</div>
<div class="page-wrap">
    <h1>ENGINEERING<br>REPORT</h1>
    <div class="date-label">DATE: <?= htmlspecialchars($dateLabel) ?><?php if ($search !== ''): ?> &nbsp;•&nbsp; FILTER: <?= htmlspecialchars($filterLabelUpper) ?><?php else: ?> &nbsp;•&nbsp; <?= htmlspecialchars($filterLabelUpper) ?><?php endif; ?><?php if ($search !== ''): ?> &nbsp;•&nbsp; SEARCH: "<?= htmlspecialchars(strtoupper($search)) ?>"<?php endif; ?></div>

    <div class="info-row">
        <strong>Akses Login:</strong> <?= htmlspecialchars((string)($user['name'] ?? 'Unknown')) ?> (<?= htmlspecialchars(strtoupper($role)) ?>)
        &nbsp;&nbsp;•&nbsp;&nbsp; <strong>Periode Export:</strong> <?= htmlspecialchars($dateLabel) ?>
        &nbsp;&nbsp;•&nbsp;&nbsp; <strong>User Agent:</strong> <?= htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? '-') ?>
    </div>

    <!-- ① SUMMARY -->
    <h2>1. ORDER SUMMARY</h2>
    <table>
        <thead>
            <tr>
                <th>TOTAL ORDER</th>
                <th>NILAI TOTAL (Rp.)</th>
                <th>MENUNGGU SUPERVISOR</th>
                <th>MENUNGGU MANAGER</th>
                <th>DISETUJUI</th>
                <th>DITOLAK</th>
                <th>SELESAI</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="cen bold"><?= count($orders) ?></td>
                <td class="num bold">Rp <?= number_format($totalAll, 2, ',', '.') ?></td>
                <td class="cen"><?= (int)($cntByStatus['pending_supervisor'] ?? 0) ?></td>
                <td class="cen"><?= (int)($cntByStatus['pending_manager'] ?? 0) ?></td>
                <td class="cen"><?= (int)($cntByStatus['approved'] ?? 0) ?></td>
                <td class="cen"><?= (int)($cntByStatus['rejected'] ?? 0) + (int)($cntByStatus['draft'] ?? 0) ?></td>
                <td class="cen"><?= (int)($cntByStatus['completed'] ?? 0) ?></td>
            </tr>
        </tbody>
    </table>

    <!-- ② DETAIL -->
    <h2>2. DETAIL ORDER / PURCHASE REQUEST</h2>
    <table>
        <thead>
            <tr>
                <th style="width:42px;">NO</th>
                <th style="width:110px;">NO. PR</th>
                <th>KEPERLUAN / JUDUL</th>
                <th style="width:130px;">COST CODE</th>
                <th style="width:100px;">PEMOHON</th>
                <th style="width:90px;">TGL</th>
                <th style="width:125px;">TOTAL (Rp.)</th>
                <th style="width:120px;">STATUS</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($orders)): ?>
                <tr><td colspan="8"><div class="empty">Belum ada order request yang sesuai kriteria.</div></td></tr>
            <?php else:
                $i = 0; $sumAmount = 0.0;
                foreach ($orders as $o):
                    $i++;
                    $st = (string)($o['status'] ?? '');
                    $stMapClass = [
                        'pending_supervisor' => 'st-sup',
                        'pending_manager'    => 'st-mgr',
                        'approved'           => 'st-ok',
                        'rejected'           => 'st-no',
                        'draft'              => 'st-dr',
                        'completed'          => 'st-done',
                    ];
                    $stMapCount = [
                        'pending_supervisor' => '#fde68a',
                        'pending_manager'    => '#bae6fd',
                        'approved'           => '#a7f3d0',
                        'rejected'           => '#fecdd3',
                        'draft'              => '#cbd5e1',
                        'completed'          => '#ddd6fe',
                    ];
                    $stColor = [
                        'pending_supervisor' => '#92400e',
                        'pending_manager'    => '#075985',
                        'approved'           => '#065f46',
                        'rejected'           => '#9f1239',
                        'draft'              => '#374151',
                        'completed'          => '#5b21b6',
                    ];
                    $class = $stMapClass[$st] ?? 'st-dr';
                    $stText = getOrderStatusText($st);
                    $amount = (float)($o['total_amount'] ?? 0);
                    $sumAmount += $amount;
                ?>
                <tr>
                    <td class="cen bold"><?= $i ?></td>
                    <td class="bold"><?= htmlspecialchars((string)($o['order_no'] ?? '-')) ?></td>
                    <td>
                        <div class="bold" style="margin-bottom:2px;"><?= htmlspecialchars((string)($o['title'] ?? '-')) ?></div>
                        <?php if (!empty($o['purpose'])): ?>
                            <div style="color:#374151;"><?= htmlspecialchars((string)$o['purpose']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($o['cc_code'])): ?>
                            <div class="bold" style="font-family: ui-monospace, Consolas, monospace; font-size:11.5px;"><?= htmlspecialchars((string)$o['cc_code']) ?></div>
                            <div style="color:#374151; margin-top:2px; font-size:11.5px;"><?= htmlspecialchars((string)($o['cc_name'] ?? '-')) ?></div>
                        <?php else: ?>
                            <span style="color:#6b7280;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string)($o['req_name'] ?? '-')) ?></td>
                    <td class="cen"><?= formatDate($o['requested_date'] ?? $o['created_at'] ?? '') ?></td>
                    <td class="num bold">Rp <?= number_format($amount, 2, ',', '.') ?></td>
                    <td class="cen">
                        <span class="st-pill <?= $class ?>">
                            <?= htmlspecialchars($stText) ?>
                            <span class="count" style="border-color:<?= $stMapCount[$st] ?? '#cbd5e1' ?>; color:<?= $stColor[$st] ?? '#374151' ?>;">#<?= (int)($o['id'] ?? 0) ?></span>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="tfoot-total">
                    <td colspan="6" class="num">TOTAL NILAI KESELURUHAN (Rp.)</td>
                    <td class="num">Rp <?= number_format($sumAmount, 2, ',', '.') ?></td>
                    <td class="cen"></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="sign-footer">
        <div class="sign-box">
            <div class="lbl">Dibuat oleh,</div>
            <div class="line"><?= htmlspecialchars((string)($user['name'] ?? '________________')) ?></div>
            <div style="margin-top:2px; font-size:10.5px; color:#4b5563;"><?= htmlspecialchars(strtoupper($role)) ?></div>
        </div>
        <div class="sign-box">
            <div class="lbl">Disetujui oleh Supervisor,</div>
            <div class="line">________________</div>
            <div style="margin-top:2px; font-size:10.5px; color:#4b5563;">Engineering Supervisor</div>
        </div>
        <div class="sign-box">
            <div class="lbl">Diketahui Manager,</div>
            <div class="line">________________</div>
            <div style="margin-top:2px; font-size:10.5px; color:#4b5563;">Engineering Manager</div>
        </div>
    </div>
</div>
</body>
</html>
<?php
$out = ob_get_clean();
echo $out;
