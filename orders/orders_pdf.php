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
foreach ($orders as $o) $totalAll += (float)($o['total_amount'] ?? 0);

$dateLabel = date('d M Y');
$fileName = 'Order_Logistik_' . date('Y_m_d') . '_' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$filter), 0, 18);
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="' . $fileName . '.pdf"');
header('Cache-Control: max-age=0, must-revalidate');
ob_start();
echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Logistik / Order Report - <?= htmlspecialchars($dateLabel) ?></title>
<style>
    @page { size: A4 landscape; margin: 10mm 12mm 14mm 12mm; }
    * { -webkit-box-sizing: border-box; box-sizing: border-box; }
    html, body { margin:0; padding:0; background:#ffffff !important; color:#0f172a !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .wrap { width:100%; min-height:100vh; background:#fff; padding:4px 0 18px 0; position:relative; }

    .meta-top { display:flex; align-items:center; justify-content: space-between;
        padding-bottom: 10px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0;
        color:#64748b; font-size:9.5px; letter-spacing:0.5px; font-weight:700; text-transform: uppercase; }
    .meta-top .lft { display:flex; align-items:center; gap:10px; }
    .meta-top .dot { width:5px; height:5px; border-radius:50%; background:#94a3b8; display:inline-block;}

    .brand-title { font-size:22px; font-weight: 900; color:#0f172a; margin:0; letter-spacing:-0.3px; }
    .brand-title .accent { background: linear-gradient(90deg, #d97706, #92400e); -webkit-background-clip: text; background-clip: text; color: transparent; }
    .brand-sub { font-size: 10px; color:#64748b; margin:0 0 16px 0; letter-spacing:2px; text-transform: uppercase; font-weight:700; }

    .eyebrow { display:inline-flex; align-items:center; gap:10px; color:#475569; font-size:9.5px; font-weight:800;
        letter-spacing:2.2px; text-transform: uppercase; margin-bottom:8px; }
    .eyebrow .pill { display:inline-block; padding: 2px 10px; border: 1px solid #cbd5e1; background:#f8fafc;
        color:#475569; border-radius:999px; font-size:9px; letter-spacing:1px; font-weight:700; }

    .hero-title { color:#0f172a; font-size:26px; font-weight:900; margin:0 0 4px 0; letter-spacing:-0.2px; }
    .hero-title .accent { background:linear-gradient(90deg, #d97706, #92400e); -webkit-background-clip:text; background-clip:text; color:transparent; }
    .hero-sub { color:#64748b; font-size:11px; margin:0 0 14px 0; line-height: 1.55; }
    .hero-sub strong { color:#334155; }

    .summary-row { display: grid; grid-template-columns: repeat(4, 1fr); gap:10px; margin: 10px 0 18px 0; }
    .sum-card { border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; background:#f8fafc; }
    .sum-card .lbl { font-size:9px; font-weight:800; letter-spacing:0.8px; text-transform: uppercase; color:#64748b; margin-bottom:4px;}
    .sum-card .val { font-size:17px; font-weight: 900; color:#0f172a; font-family: "Segoe UI", Roboto, Arial; }
    .sum-card.gold .val { color:#b45309; }
    .sum-card.blue .val { color:#0369a1; }
    .sum-card.green .val { color:#047857; }

    table { border-collapse: collapse; width:100%; table-layout:fixed; }
    th, td { border: 1px solid #e2e8f0; padding:7px 9px; vertical-align: top;
        word-wrap: break-word; overflow-wrap: break-word; white-space: normal; font-size: 10.5px; }
    th { background:#f1f5f9 !important; color:#334155 !important; font-weight:800; font-size:9.5px; text-align:left;
        letter-spacing:0.6px; text-transform: uppercase; border-bottom:2px solid #cbd5e1 !important; }
    th.num, td.num { text-align: right !important; }
    th.ctr, td.ctr { text-align: center !important; }
    tr:nth-child(even) td { background:#fcfcfd; }

    .badge { display:inline-block; padding:2px 8px; border-radius:6px; font-size:9.5px; font-weight:800; text-transform: uppercase; letter-spacing:0.3px; border:1px solid; }
    .bg-rose { background:#fff1f2; color:#9f1239; border-color:#fecdd3; }
    .bg-amber { background:#fffbeb; color:#92400e; border-color:#fde68a; }
    .bg-emerald { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
    .bg-slate { background:#f1f5f9; color:#334155; border-color:#cbd5e1; }
    .bg-sky { background:#f0f9ff; color:#075985; border-color:#bae6fd; }
    .bg-violet { background:#f5f3ff; color:#5b21b6; border-color:#ddd6fe; }

    .col-no { width: 42px; }
    .col-pr { width: 90px; }
    .col-cc { width: 130px; }
    .col-req { width: 100px; }
    .col-date { width: 80px; }
    .col-total { width: 105px; }
    .col-status { width: 110px; }

    .footer-note { margin-top:16px; color:#94a3b8; font-size:9px; letter-spacing:0.5px; text-transform: uppercase; font-weight:700; border-top:1px solid #e2e8f0; padding-top:8px; display:flex; justify-content:space-between; }
    .orderno { font-family:"Segoe UI", Roboto, Arial; font-weight:800; color:#92400e; letter-spacing:0.2px; }
    .cc-code { font-family: ui-monospace, "SF Mono", Consolas, monospace; font-weight:800; color:#6d28d9; font-size: 10px; }
</style>
</head>
<body>
<div class="wrap">
    <div class="meta-top">
        <div class="lft">
            <span>ENG DEPT • LOGISTIK & ORDER REPORT</span>
            <span class="dot"></span>
            <span>PRINTED: <?= htmlspecialchars($dateLabel) ?></span>
        </div>
        <div class="rgt">St. Regis Bali — Engineering Office</div>
    </div>

    <p class="brand-title"><span class="accent">LOGISTIK</span> REPORT</p>
    <p class="brand-sub">Purchase Request / Order Summary</p>

    <div class="eyebrow">
        <span class="pill">FILTER: <?= htmlspecialchars(strtoupper($filterLabel)) ?></span>
        <?php if ($search !== ''): ?>
            <span class="pill" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;">SEARCH: <?= htmlspecialchars(strtoupper($search)) ?></span>
        <?php endif; ?>
    </div>
    <h1 class="hero-title">Daftar Order / Purchase Request <span class="accent"><?= htmlspecialchars($filterLabel) ?></span></h1>
    <p class="hero-sub">
        Export tanggal <strong><?= htmlspecialchars($dateLabel) ?></strong> •
        Disediakan untuk <strong><?= htmlspecialchars(strtoupper($role)) ?></strong> •
        Total <strong><?= count($orders) ?> order</strong> sesuai kriteria di bawah ini.
    </p>

    <div class="summary-row">
        <div class="sum-card gold">
            <div class="lbl">Total Order</div>
            <div class="val"><?= count($orders) ?></div>
        </div>
        <div class="sum-card blue">
            <div class="lbl">Nilai Total (Rp)</div>
            <div class="val"><?= number_format($totalAll, 0, ',', '.') ?></div>
        </div>
        <div class="sum-card">
            <div class="lbl">Periode Export</div>
            <div class="val" style="font-size:13px;">Tgl <?= htmlspecialchars($dateLabel) ?></div>
        </div>
        <div class="sum-card green">
            <div class="lbl">Akses Login</div>
            <div class="val" style="font-size:13px;"><?= htmlspecialchars($user['name'] ?? 'Unknown') ?></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="col-no ctr">No</th>
                <th class="col-pr">No. PR</th>
                <th>Judul / Keperluan</th>
                <th class="col-cc">Cost Code</th>
                <th class="col-req">Pemohon</th>
                <th class="col-date">Tgl</th>
                <th class="col-total num">Total (Rp)</th>
                <th class="col-status ctr">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($orders)): ?>
                <tr><td colspan="8" class="ctr" style="padding:24px 12px;color:#64748b;font-weight:700;">Belum ada order request yang sesuai kriteria.</td></tr>
            <?php else:
                $i = 0;
                foreach ($orders as $o):
                    $i++;
                    $st = (string)($o['status'] ?? '');
                    $stMapClass = [
                        'pending_supervisor' => 'bg-amber',
                        'pending_manager'    => 'bg-sky',
                        'approved'           => 'bg-emerald',
                        'rejected'           => 'bg-rose',
                        'draft'              => 'bg-slate',
                        'completed'          => 'bg-violet',
                    ];
                    $class = $stMapClass[$st] ?? 'bg-slate';
                    $stText = getOrderStatusText($st);
                    $amount = (float)($o['total_amount'] ?? 0);
                ?>
                <tr>
                    <td class="ctr"><?= $i ?></td>
                    <td><span class="orderno"><?= htmlspecialchars($o['order_no'] ?? '-') ?></span></td>
                    <td>
                        <div style="font-weight:800;color:#0f172a;"><?= htmlspecialchars($o['title'] ?? '-') ?></div>
                        <?php if (!empty($o['purpose'])): ?>
                            <div style="margin-top:3px;color:#475569;font-size:9.5px;line-height:1.45;"><?= htmlspecialchars($o['purpose']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($o['cc_code'])): ?>
                            <span class="cc-code"><?= htmlspecialchars($o['cc_code']) ?></span>
                            <div style="color:#475569;font-size:9.5px;margin-top:3px;line-height:1.4;"><?= htmlspecialchars($o['cc_name'] ?? '-') ?></div>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($o['req_name'] ?? '-') ?></td>
                    <td><?= formatDate($o['requested_date'] ?? $o['created_at'] ?? '') ?></td>
                    <td class="num" style="font-weight:800;color:#0f172a;font-family:Segoe UI, Roboto, Arial;">Rp <?= number_format($amount, 2, ',', '.') ?></td>
                    <td class="ctr"><span class="badge <?= $class ?>"><?= htmlspecialchars($stText) ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="footer-note">
        <div>Generated by Engineering Dashboard • St. Regis Bali</div>
        <div>Halaman dicetak otomatis dari sistem, tidak memerlukan tanda tangan fisik.</div>
    </div>
</div>
</body>
</html>
<?php
$out = ob_get_clean();
echo $out;
