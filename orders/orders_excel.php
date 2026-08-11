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

$fileName = 'Engineering_Report_Logistik_' . date('Y_m_d') . '_' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$filter), 0, 18);
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
<meta name="Generator" content="Engineering Report — Logistik Export">
<title><?= htmlspecialchars($fileName, ENT_QUOTES) ?></title>
<style>
    * { font-family: Arial, Helvetica, sans-serif !important; }
    html, body { margin:0; padding:0; background:#ffffff; color:#000; font-size:12px; }
    body { padding: 12px 16px; }
    table { border-collapse: collapse; width: 100%; }
    table.t-blank { border-collapse: collapse; width: 100%; border:0 !important; }
    table.t-blank td { border:0 !important; padding:0 !important; }

    th {
        background: #d9d9d9 !important;
        color: #000 !important;
        border: 1px solid #000 !important;
        padding: 6px 8px !important;
        font-weight: 800 !important;
        text-align: center !important;
        font-size: 12px !important;
        white-space: normal !important;
        vertical-align: middle !important;
    }
    td {
        border: 1px solid #000 !important;
        padding: 5px 8px !important;
        font-size: 12px !important;
        vertical-align: top !important;
        color: #000 !important;
        white-space: normal !important;
        word-wrap: break-word !important;
        mso-number-format: "\@";
    }
    td.num { text-align: right !important; mso-number-format: "\#\,\#\#0.00"; }
    td.cen { text-align: center !important; }
    td.bold { font-weight: 800 !important; }
    td.left { text-align: left !important; }

    .col-1 { width: 42px; }
    .col-pr { width: 120px; }
    .col-cc { width: 150px; }
    .col-user { width: 110px; }
    .col-date { width: 100px; }
    .col-tot { width: 140px; }
    .col-st { width: 140px; }

    .h1-title {
        font-size: 22px !important;
        letter-spacing: 1.5px !important;
        text-align: center !important;
        font-weight: 900 !important;
        line-height: 1.05 !important;
        margin: 0 !important;
        padding: 0 0 6px 0 !important;
        color: #000 !important;
    }
    .date-label-row {
        font-size: 15px !important;
        font-weight: 800 !important;
        margin: 0 !important;
        padding: 0 0 14px 0 !important;
        color: #000 !important;
    }
    .h2-section {
        font-size: 15px !important;
        font-weight: 900 !important;
        margin: 0 !important;
        padding: 14px 0 6px 0 !important;
        letter-spacing: .5px !important;
        color: #000 !important;
    }
    .tfoot-total td {
        background: #fff7ed !important;
        color: #92400e !important;
        font-weight: 900 !important;
        border-top: 2px solid #000 !important;
    }
    .orderno-bold { font-weight: 800 !important; color: #000 !important; }
    .cc-mono { font-family: Consolas, "Courier New", monospace !important; font-weight: 800 !important; color: #000 !important; font-size: 11.5px !important; }
    .st-text { font-weight: 800 !important; text-transform: uppercase !important; letter-spacing: 0.3px !important; font-size: 10.5px !important; }
    .purpose-small { color: #374151 !important; font-size: 10.5px !important; line-height: 1.4 !important; margin-top: 2px !important; }
    .cc-sub { color: #374151 !important; font-size: 10.5px !important; margin-top: 2px !important; line-height: 1.4 !important; }
    .title-main { font-weight: 800 !important; color: #000 !important; margin-bottom: 2px !important; }
    .empty-row { padding: 18px 12px !important; text-align: center !important; color: #6b7280 !important; font-weight: 700 !important; }
    br.page { mso-special-character: line-break; }
</style>
<!--[if gte mso 9]>
<xml>
  <x:ExcelWorkbook>
    <x:ExcelWorksheets>
      <x:ExcelWorksheet>
        <x:Name>Engineering Report</x:Name>
        <x:WorksheetOptions>
          <x:DisplayGridlines/>
          <x:FreezePanes/>
          <x:FrozenNoSplit/>
          <x:SplitHorizontal>1</x:SplitHorizontal>
          <x:TopRowBottomPane>1</x:TopRowBottomPane>
          <x:ActivePane>2</x:ActivePane>
        </x:WorksheetOptions>
      </x:ExcelWorksheet>
    </x:ExcelWorksheets>
  </x:ExcelWorkbook>
</xml>
<![endif]-->
</head>
<body>

<table class="t-blank" border="0" cellpadding="0" cellspacing="0">
  <tr>
    <td>
        <div class="h1-title">ENGINEERING<br>REPORT</div>
    </td>
  </tr>
  <tr>
    <td>
        <div class="date-label-row">DATE: <?= htmlspecialchars($dateLabel) ?><?php if ($search !== ''): ?> &nbsp;•&nbsp; FILTER: <?= htmlspecialchars($filterLabelUpper) ?><?php else: ?> &nbsp;•&nbsp; <?= htmlspecialchars($filterLabelUpper) ?><?php endif; ?><?php if ($search !== ''): ?> &nbsp;•&nbsp; SEARCH: "<?= htmlspecialchars(strtoupper($search)) ?>"<?php endif; ?></div>
    </td>
  </tr>
</table>

<div class="h2-section">1. ORDER SUMMARY</div>
<table cellpadding="0" cellspacing="0">
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

<div class="h2-section">2. DETAIL ORDER / PURCHASE REQUEST</div>
<table cellpadding="0" cellspacing="0">
    <thead>
        <tr>
            <th class="col-1">NO</th>
            <th class="col-pr">NO. PR</th>
            <th>KEPERLUAN / JUDUL</th>
            <th class="col-cc">COST CODE</th>
            <th class="col-user">PEMOHON</th>
            <th class="col-date">TGL</th>
            <th class="col-tot">TOTAL (Rp.)</th>
            <th class="col-st">STATUS</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($orders)): ?>
            <tr><td colspan="8"><div class="empty-row">Belum ada order request yang sesuai kriteria.</div></td></tr>
        <?php else:
            $i = 0; $sumAmount = 0.0;
            foreach ($orders as $o):
                $i++;
                $st = (string)($o['status'] ?? '');
                $stText = getOrderStatusText($st);
                $amount = (float)($o['total_amount'] ?? 0);
                $sumAmount += $amount;
                $purpose = !empty($o['purpose']) ? (string)$o['purpose'] : '';
            ?>
            <tr>
                <td class="cen bold col-1"><?= $i ?></td>
                <td class="col-pr">
                    <span class="orderno-bold"><?= htmlspecialchars((string)($o['order_no'] ?? '-')) ?></span>
                </td>
                <td class="left">
                    <div class="title-main"><?= htmlspecialchars((string)($o['title'] ?? '-')) ?></div>
                    <?php if ($purpose !== ''): ?>
                        <div class="purpose-small"><?= htmlspecialchars($purpose) ?></div>
                    <?php endif; ?>
                </td>
                <td class="col-cc left">
                    <?php if (!empty($o['cc_code'])): ?>
                        <div class="cc-mono"><?= htmlspecialchars((string)$o['cc_code']) ?></div>
                        <div class="cc-sub"><?= htmlspecialchars((string)($o['cc_name'] ?? '-')) ?></div>
                    <?php else: ?>
                        <span style="color:#6b7280;">—</span>
                    <?php endif; ?>
                </td>
                <td class="col-user left"><?= htmlspecialchars((string)($o['req_name'] ?? '-')) ?></td>
                <td class="cen col-date"><?= formatDate($o['requested_date'] ?? $o['created_at'] ?? '') ?></td>
                <td class="num bold col-tot">Rp <?= number_format($amount, 2, ',', '.') ?></td>
                <td class="cen col-st"><span class="st-text"><?= htmlspecialchars($stText) ?> #<?= (int)($o['id'] ?? 0) ?></span></td>
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

</body>
</html>
<?php
$out = ob_get_clean();
echo $out;
