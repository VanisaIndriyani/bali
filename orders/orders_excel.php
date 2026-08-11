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
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '.xls"');
header('Content-Transfer-Encoding: binary');
header('Pragma: public');
header('Cache-Control: max-age=0, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('X-Content-Type-Options: nosniff');

ob_start();
echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="application/vnd.ms-excel; charset=utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="ProgId" content="Excel.Sheet">
<meta name="Generator" content="Engineering — Logistik Order Report Export">
<title><?= htmlspecialchars($fileName, ENT_QUOTES) ?></title>
<style>
    * { font-family: Calibri, "Segoe UI", Arial, sans-serif; }
    body { padding: 20px 24px; background:#ffffff; color:#0f172a; }
    table { border-collapse: collapse; width: 100%; table-layout: fixed; }
    th, td { border: 1px solid #e2e8f0; padding: 8px 10px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; white-space: normal; }
    th {
        background: #f8fafc !important;
        color: #334155 !important;
        font-weight: 800;
        font-size: 10.5px;
        text-align: left;
        letter-spacing: 0.8px;
        text-transform: uppercase;
        border-bottom: 2px solid #cbd5e1 !important;
        border-top: 1px solid #e2e8f0 !important;
    }
    th.num, td.num { mso-number-format: "\@"; text-align: right !important; }
    th.ctr, td.ctr { text-align: center !important; }
    tr:nth-child(even) td { background: #fcfcfd; }

    .brand-title { font-size: 26px; font-weight: 900; font-family: Calibri, "Segoe UI", Arial, sans-serif; color: #0f172a; margin: 0; letter-spacing: -0.2px; }
    .brand-title .accent { color: #b45309; }
    .brand-sub { font-size: 10px; color: #64748b; margin: 0 0 18px 0; letter-spacing: 2.5px; text-transform: uppercase; font-weight: 700; }
    .eyebrow { display: inline-flex; align-items: center; gap: 10px; color: #475569; font-size: 10px; font-weight: 800;
        letter-spacing: 2.5px; text-transform: uppercase; margin-bottom: 8px; }
    .eyebrow .pill { display: inline-block; padding: 2px 10px; border: 1px solid #cbd5e1; background: #f8fafc;
        color: #475569; border-radius: 999px; font-size: 9.5px; letter-spacing: 1px; font-weight: 700; }
    .hero-title { color: #0f172a; font-size: 32px; font-weight: 900; margin: 0 0 6px 0; letter-spacing: -0.2px; }
    .hero-title .accent { color: #b45309; }
    .hero-sub { color: #64748b; font-size: 11.5px; margin: 0 0 16px 0; letter-spacing: 0.1px; line-height: 1.55; }

    .sum-wrap { display: table; width: 100%; margin-bottom: 18px; border-collapse: separate; border-spacing: 8px; }
    .sum-card { display: table-cell; width: 24%; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; vertical-align: top; }
    .sum-card .lbl { font-size: 10px; font-weight: 800; letter-spacing: 0.8px; text-transform: uppercase; color: #64748b; margin-bottom: 6px; }
    .sum-card .val { font-size: 20px; font-weight: 900; color: #0f172a; }
    .sum-card.gold .val { color: #b45309; }
    .sum-card.blue .val { color: #0369a1; }
    .sum-card.green .val { color: #047857; }

    .orderno { font-weight: 800; color: #92400e; }
    .cc-code { font-family: Consolas, "Courier New", monospace; font-weight: 800; color: #6d28d9; font-size: 10.5px; }
    .footnote { margin-top: 18px; color: #94a3b8; font-size: 9.5px; letter-spacing: 0.5px; text-transform: uppercase; font-weight: 700; border-top: 1px solid #e2e8f0; padding-top: 10px; }
</style>
</head>
<body>
    <p class="brand-title"><span class="accent">LOGISTIK</span> REPORT</p>
    <p class="brand-sub">Purchase Request / Order Summary — St. Regis Bali</p>

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
        Total <strong><?= count($orders) ?> order</strong> sesuai kriteria.
    </p>

    <div class="sum-wrap">
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
            <div class="val" style="font-size:14px;"><?= htmlspecialchars($dateLabel) ?></div>
        </div>
        <div class="sum-card green">
            <div class="lbl">Akses Login</div>
            <div class="val" style="font-size:14px;"><?= htmlspecialchars($user['name'] ?? 'Unknown') ?></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="col-no ctr" style="width: 42px;">No</th>
                <th style="width: 100px;">No. PR</th>
                <th style="width: 320px;">Judul / Keperluan</th>
                <th style="width: 150px;">Cost Code</th>
                <th style="width: 120px;">Pemohon</th>
                <th style="width: 100px;">Tanggal</th>
                <th class="num" style="width: 130px;">Total (Rp)</th>
                <th class="ctr" style="width: 120px;">Status</th>
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
                    $stText = getOrderStatusText($st);
                    $amount = (float)($o['total_amount'] ?? 0);
                    $purpose = !empty($o['purpose']) ? (string)$o['purpose'] : '';
                ?>
                <tr>
                    <td class="ctr"><?= $i ?></td>
                    <td class="orderno"><?= htmlspecialchars((string)($o['order_no'] ?? '-')) ?></td>
                    <td>
                        <div style="font-weight:800;color:#0f172a;"><?= htmlspecialchars((string)($o['title'] ?? '-')) ?></div>
                        <?php if ($purpose !== ''): ?>
                            <div style="margin-top:3px;color:#475569;font-size:10px;line-height:1.45;"><?= htmlspecialchars($purpose) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($o['cc_code'])): ?>
                            <span class="cc-code"><?= htmlspecialchars((string)$o['cc_code']) ?></span>
                            <div style="color:#475569;font-size:10px;margin-top:3px;line-height:1.4;"><?= htmlspecialchars((string)($o['cc_name'] ?? '-')) ?></div>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string)($o['req_name'] ?? '-')) ?></td>
                    <td><?= formatDate($o['requested_date'] ?? $o['created_at'] ?? '') ?></td>
                    <td class="num" style="font-weight:800;color:#0f172a;">Rp <?= number_format($amount, 2, ',', '.') ?></td>
                    <td class="ctr" style="font-weight:800;letter-spacing:0.3px;text-transform:uppercase;font-size:10px;"><?= htmlspecialchars($stText) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#fff7ed;">
                    <td colspan="6" class="num" style="font-weight:900;color:#92400e;border-top:2px solid #fbbf24;">TOTAL NILAI KESELURUHAN (Rp)</td>
                    <td class="num" style="font-weight:900;color:#92400e;border-top:2px solid #fbbf24;">Rp <?= number_format($totalAll, 2, ',', '.') ?></td>
                    <td class="ctr" style="border-top:2px solid #fbbf24;"></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="footnote">
        Generated by Engineering Dashboard • St. Regis Bali • Sistem export otomatis, tidak memerlukan tanda tangan.
    </div>
</body>
</html>
<?php
$out = ob_get_clean();
echo $out;
