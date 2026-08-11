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
<title>Logistik / Order Report - <?= htmlspecialchars($dateLabel) ?> | Engineering Report</title>
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
    .meta-top .rgt { text-align: right; }
    .meta-top .dot { width:6px; height:6px; border-radius:50%; background:#94a3b8; display:inline-block;}

    /* ===== HEADER ===== */
    .head-block { margin-bottom: 22px; }
    .eyebrow {
        display:inline-flex; align-items:center; gap: 10px;
        color: #475569; font-size: 10px; font-weight: 800; letter-spacing: 2.5px; text-transform: uppercase;
        margin-bottom: 8px;
    }
    .eyebrow .pill {
        display:inline-block; padding: 2px 10px; border: 1px solid #cbd5e1;
        background: #f8fafc; color:#475569;
        border-radius: 999px; font-size: 9.5px; letter-spacing: 1px; font-weight: 700;
    }
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

    /* ===== DIVIDER ===== */
    .divider-section {
        display:flex; align-items:center; gap:10px;
        margin: 22px 0 12px 0;
        padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .divider-section .lbl { color:#334155; font-size:11px; font-weight:800; letter-spacing:2px; text-transform: uppercase; }
    .divider-section .dotdiv { width:8px; height:8px; border-radius:50%; background:#cbd5e1;
        box-shadow: inset 0 0 0 2px #fff, 0 0 0 1px #e2e8f0; }
    .divider-section .cnt { color:#94a3b8; font-size:10px; font-weight:600; letter-spacing:0.5px; margin-left:auto; }

    /* ===== TABLE ORDERS ===== */
    .tbl {
        width: 100%; border-collapse: collapse;
        border: 1px solid #e2e8f0;
        border-radius: 14px; overflow: hidden;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15,23,42,0.03);
    }
    .tbl th, .tbl td {
        border: 1px solid #e2e8f0;
        padding: 7px 9px;
        vertical-align: top;
        word-wrap: break-word; overflow-wrap: break-word; white-space: normal;
        font-size: 10.5px;
    }
    .tbl th {
        background:#f8fafc !important; color:#334155 !important;
        font-weight:800; font-size: 9.5px; text-align:left;
        letter-spacing: 0.6px; text-transform: uppercase;
        border-bottom: 2px solid #e2e8f0 !important;
    }
    .tbl th.num, .tbl td.num { text-align: right !important; }
    .tbl th.ctr, .tbl td.ctr { text-align: center !important; }
    .tbl tbody tr:nth-child(even) td { background:#fcfcfd; }
    .tbl tfoot td {
        background: #fff7ed !important;
        font-weight: 900;
        border-top: 2px solid #fbbf24 !important;
        color: #92400e !important;
    }

    .badge {
        display:inline-flex; align-items:center; gap:5px;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 9.5px; font-weight:800; text-transform: uppercase; letter-spacing: 0.3px;
    }
    .badge::before { content:""; display:inline-block; width:5px; height:5px; border-radius:50%; }
    .bg-rose { background:#fff1f2; color:#9f1239; border:1px solid #fecdd3; }
    .bg-rose::before { background:#e11d48; }
    .bg-amber { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
    .bg-amber::before { background:#d97706; }
    .bg-emerald { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .bg-emerald::before { background:#10b981; }
    .bg-slate { background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; }
    .bg-slate::before { background:#64748b; }
    .bg-sky { background:#f0f9ff; color:#075985; border:1px solid #bae6fd; }
    .bg-sky::before { background:#0ea5e9; }
    .bg-violet { background:#f5f3ff; color:#5b21b6; border:1px solid #ddd6fe; }
    .bg-violet::before { background:#8b5cf6; }

    .orderno { font-family: "Segoe UI", Roboto, Arial; font-weight:800; color: #1e293b; letter-spacing:0.2px; }
    .cc-code { font-family: ui-monospace, "SF Mono", Consolas, monospace; font-weight:800; color:#6d28d9; font-size: 10px; }

    .empty {
        padding: 28px 14px; text-align: center;
        color: #64748b;
        background: #ffffff;
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
        font-size: 12.5px; font-weight: 600;
    }

    /* ===== FOOTER ===== */
    .foot-note {
        margin-top: 30px;
        padding-top: 14px;
        border-top: 1px dashed #e2e8f0;
        color: #94a3b8; font-size: 9.5px; letter-spacing: 0.5px; font-weight: 500;
        display:flex; align-items:center; justify-content: space-between;
    }
    .foot-note .lft, .foot-note .rgt { display:flex; align-items:center; gap:8px; }
    .foot-note .brand-mark {
        color: #334155; font-weight: 800; letter-spacing: 1.5px; font-size: 10px;
        text-transform: uppercase;
    }
    .foot-note .brand-mark em {
        font-style: normal;
        background: linear-gradient(90deg, #0ea5e9, #6366f1);
        -webkit-background-clip: text; background-clip: text;
        color: transparent;
    }

    @media print { body { background: #fff !important; } }
</style>
</head>
<body>
<div class="wrap">
    <div class="meta-top">
        <div class="lft">
            <span class="dot"></span>
            <span>Engineering Report</span>
            <span class="dot"></span>
            <span>ENG DEPT • Logistik &amp; Order</span>
        </div>
        <div class="rgt">
            <span>Printed: <?= htmlspecialchars($dateLabel) ?></span>
            <span class="dot"></span>
            <span>St. Regis Bali</span>
        </div>
    </div>

    <div class="head-block">
        <div class="eyebrow">
            <span class="pill">FILTER: <?= htmlspecialchars(strtoupper($filterLabel)) ?></span>
            <?php if ($search !== ''): ?>
                <span class="pill" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;">SEARCH: <?= htmlspecialchars(strtoupper($search)) ?></span>
            <?php endif; ?>
        </div>
        <h1 class="title-hero">Daftar Order / Purchase Request <span class="accent"><?= htmlspecialchars($filterLabel) ?></span></h1>
        <p class="subtitle-hero">
            Export tanggal <strong><?= htmlspecialchars($dateLabel) ?></strong> •
            Disediakan untuk <strong><?= htmlspecialchars(strtoupper($role)) ?></strong> •
            Ringkasan semua permintaan barang &amp; jasa sesuai kriteria di bawah ini.
        </p>
        <div class="summary-row">
            <div class="chip"><span class="dotmini" style="background:#0ea5e9;"></span>Total Order: <strong><?= count($orders) ?></strong></div>
            <div class="chip"><span class="dotmini" style="background:#8b5cf6;"></span>Nilai Total: <strong>Rp <?= number_format($totalAll, 0, ',', '.') ?></strong></div>
            <div class="chip"><span class="dotmini" style="background:#f59e0b;"></span>Periode Export: <strong>Tgl <?= htmlspecialchars($dateLabel) ?></strong></div>
            <div class="chip"><span class="dotmini" style="background:#10b981;"></span>Login: <strong><?= htmlspecialchars($user['name'] ?? 'Unknown') ?></strong></div>
        </div>
    </div>

    <div class="divider-section">
        <div class="dotdiv"></div>
        <div class="lbl">Tabel Detail Order Request</div>
        <div class="cnt"><?= count($orders) ?> Entries</div>
    </div>

    <table class="tbl">
        <thead>
            <tr>
                <th style="width: 42px;" class="ctr">No</th>
                <th style="width: 100px;">No. PR</th>
                <th style="width: 260px;">Judul / Keperluan</th>
                <th style="width: 130px;">Cost Code</th>
                <th style="width: 100px;">Pemohon</th>
                <th style="width: 82px;">Tgl</th>
                <th style="width: 120px;" class="num">Total (Rp)</th>
                <th style="width: 100px;" class="ctr">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($orders)): ?>
                <tr><td colspan="8"><div class="empty">Belum ada order request yang sesuai kriteria.</div></td></tr>
            <?php else:
                $i = 0;
                $sumAmount = 0.0;
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
                    $sumAmount += $amount;
                ?>
                <tr>
                    <td class="ctr"><?= $i ?></td>
                    <td><span class="orderno"><?= htmlspecialchars((string)($o['order_no'] ?? '-')) ?></span></td>
                    <td>
                        <div style="font-weight:800;color:#0f172a;line-height:1.45;"><?= htmlspecialchars((string)($o['title'] ?? '-')) ?></div>
                        <?php if (!empty($o['purpose'])): ?>
                            <div style="margin-top:4px;color:#475569;font-size:9.5px;line-height:1.5;"><?= htmlspecialchars((string)$o['purpose']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($o['cc_code'])): ?>
                            <span class="cc-code"><?= htmlspecialchars((string)$o['cc_code']) ?></span>
                            <div style="color:#475569;font-size:9.5px;margin-top:4px;line-height:1.45;"><?= htmlspecialchars((string)($o['cc_name'] ?? '-')) ?></div>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string)($o['req_name'] ?? '-')) ?></td>
                    <td><?= formatDate($o['requested_date'] ?? $o['created_at'] ?? '') ?></td>
                    <td class="num" style="font-weight:800;color:#0f172a;">Rp <?= number_format($amount, 2, ',', '.') ?></td>
                    <td class="ctr"><span class="badge <?= $class ?>"><?= htmlspecialchars($stText) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <tfoot>
                    <tr>
                        <td colspan="6" class="num">TOTAL NILAI KESELURUHAN (Rp)</td>
                        <td class="num">Rp <?= number_format($sumAmount, 2, ',', '.') ?></td>
                        <td class="ctr"></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="foot-note">
        <div class="lft"><div class="brand-mark"><em>ENGINEERING</em> REPORT</div></div>
        <div class="rgt">
            <span>Sistem export otomatis — tidak memerlukan tanda tangan fisik.</span>
        </div>
    </div>
</div>
</body>
</html>
<?php
$out = ob_get_clean();
echo $out;
