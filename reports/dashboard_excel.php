<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
@ini_set('memory_limit', '256M');
@set_time_limit(300);

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
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateFromRaw)) {
        $dateFromRaw = date('Y-m-01', strtotime('-5 months'));
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
    $groupSelect = "DATE_FORMAT(dl.log_date, '%b %Y') as period_label";
    $groupBy = "GROUP BY DATE_FORMAT(dl.log_date, '%Y-%m'), DATE_FORMAT(dl.log_date, '%b %Y')";
    $orderBy = "ORDER BY DATE_FORMAT(dl.log_date, '%Y-%m') ASC";
} else {
    $groupSelect = "DATE(dl.log_date) as period_label";
    $groupBy = "GROUP BY DATE(dl.log_date)";
    $orderBy = "ORDER BY DATE(dl.log_date) ASC";
}

$summaryQuery = "SELECT $groupSelect,
    SUM(dl.total_electricity) as sum_elec,
    SUM(dl.total_water) as sum_water,
    SUM(dl.total_gas) as sum_gas,
    COUNT(DISTINCT dl.id) as log_count
    FROM daily_logs dl
    WHERE dl.log_date BETWEEN ? AND ? $whereApproved $whereUser
    $groupBy $orderBy";
$summaryData = $db->fetchAll($summaryQuery, $params);

$detailParams = [$dateFrom, $dateTo];
if ($userRole !== 'supervisor') {
    $detailParams[] = $userId;
}
$detailQuery = "SELECT dl.*, u.name as engineer_name, u.position as engineer_position, u.phone as engineer_phone
    FROM daily_logs dl LEFT JOIN users u ON u.id = dl.engineer_id
    WHERE dl.log_date BETWEEN ? AND ? $whereApproved $whereUser
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

$modeLabel = $viewType === 'monthly' ? 'Bulanan' : 'Harian';
$fileName = 'EngineeringReport_Dashboard_Konsumsi_' . $modeLabel . '_' . date('Ymd', strtotime($dateFrom)) . '-' . date('Ymd', strtotime($dateTo));

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '.xls"');
header('Content-Transfer-Encoding: binary');
header('Pragma: public');
header('Cache-Control: max-age=0, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('X-Content-Type-Options: nosniff');

function ecell($v) {
    $v = (string)($v ?? '');
    $v = htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return $v;
}

ob_start();
echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="application/vnd.ms-excel; charset=utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="ProgId" content="Excel.Sheet">
<meta name="Generator" content="Engineering Report — Powered by Native PHP SpreadsheetML Export">
<title><?= ecell($fileName) ?></title>
<style>
    body { font-family: Calibri, Arial, sans-serif; color:#1f2937; background:#fff; }
    table { border-collapse: collapse; width: 100%; table-layout: fixed; }
    td, th { border: 1px solid #d1d5db; padding: 7px 10px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; white-space: normal; }
    th { background: #fafafa; color:#1f2937; text-align:left; font-weight:bold; border-bottom: 2px solid #c9a227; }
    .h1 { font-size: 19px; font-weight: bold; color:#1f2937; margin:0; font-family: Georgia, 'Times New Roman', serif; letter-spacing:0.8px; }
    .h2 { font-size: 10px; color:#6b7280; margin:3px 0 0; text-transform: uppercase; letter-spacing:0.2em; }
    .h-section { font-size:14px; font-weight:bold; color:#1f2937; padding: 12px 0 6px; border-bottom: 2px solid #c9a227; }
    .meta td { border:none; padding: 3px 8px 3px 0; font-size: 12px; color:#1f2937; }
    .meta td:first-child { font-weight: bold; color:#6b7280; width: 160px; }
    .stat td { border: none; padding: 10px; font-size: 12px; vertical-align: middle; }
    .stat .num { font-size: 22px; font-weight: bold; color:#1f2937; }
    .stat .box { padding: 12px 14px 12px 12px; border-radius: 4px; color:#1f2937; background:#fff; border:1px solid #e5e7eb; border-left:4px solid #c9a227; }
    .stat .box .lbl { font-size: 10px; letter-spacing:0.15em; text-transform: uppercase; color:#6b7280; margin-bottom:4px;}
    .totals td { font-weight: bold; background:#fafafa; border-top:2px solid #c9a227; border-bottom:2px solid #c9a227; }
    .wrap { max-width: 1200px; padding: 20px 24px; }
    .brand { padding: 16px 20px; background: #fff; color:#1f2937; border-radius: 4px; border-bottom:2px solid #c9a227; display:flex; align-items:center; gap:18px; margin-bottom: 16px;}
    .brand img { background:#fff; padding:4px; border-radius:8px; border:1px solid #e5e7eb; }
    .empty { padding: 24px; color:#6b7280; text-align:center; font-style: italic; background:#fafafa; border:1px dashed #d1d5db; }
    tr.zebra td { background:#fcfcfd; }
    .nowrap { white-space:nowrap; }
    .text-right { text-align:right; }
    .text-muted { color:#6b7280; font-size:12px; }
    .sign { margin-top:36px; width: 100%; }
    .sign td { border:none; padding: 6px 0; font-size:12px; color:#1f2937; }
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <table style="border:none; margin:0; width:auto;"><tr>
            <td style="border:none; padding:0;">
                <div class="h1">Engineering Report</div>
                <div class="h2">Engineering Department — Dashboard Konsumsi Energi (Mode <?= ecell($modeLabel) ?>)</div>
            </td>
        </tr></table>
    </div>

    <table class="meta" style="margin-bottom:18px; width:100%;">
        <colgroup>
            <col style="width:150px;"><col style="width:280px;"><col style="width:150px;"><col style="width:*;">
        </colgroup>
        <tr><td>Periode Laporan</td><td>: <?= ecell(formatDate($dateFrom)) ?> s/d <?= ecell(formatDate($dateTo)) ?></td>
            <td>Dicetak Tanggal</td><td>: <?= ecell(formatDate(date('Y-m-d'))) ?> <?= ecell(date('H:i')) ?></td></tr>
        <tr><td>Mode Tampilan</td><td>: <?= ecell($modeLabel) ?> (<?= ecell($viewType) ?>)</td>
            <td>Dicetak Oleh</td><td>: <?= ecell($userName) ?> (<?= ecell(ucfirst($userRole)) ?>)</td></tr>
    </table>

    <table class="stat" style="width:100%; margin-bottom: 12px;">
        <colgroup>
            <col style="width:25%;"><col style="width:25%;"><col style="width:25%;"><col style="width:25%;">
        </colgroup>
        <tr>
            <td style="width:25%;"><div class="box" style="border-left-color:#d97706;">
                <div class="lbl">⚡ TOTAL LISTRIK</div>
                <div class="num"><?= ecell(formatNumber($totElec)) ?> <span style="font-size:14px;">kWh</span></div>
            </div></td>
            <td style="width:25%;"><div class="box" style="border-left-color:#0284c7;">
                <div class="lbl">💧 TOTAL AIR</div>
                <div class="num"><?= ecell(formatNumber($totWater)) ?> <span style="font-size:14px;">m³</span></div>
            </div></td>
            <td style="width:25%;"><div class="box" style="border-left-color:#ea580c;">
                <div class="lbl">🔥 TOTAL GAS</div>
                <div class="num"><?= ecell(formatNumber($totGas)) ?> <span style="font-size:14px;">kg</span></div>
            </div></td>
            <td style="width:25%;"><div class="box" style="border-left-color:#c9a227;">
                <div class="lbl">📄 TOTAL DAILY LOG</div>
                <div class="num"><?= ecell(formatNumber($totLog)) ?> <span style="font-size:14px;">entri</span></div>
            </div></td>
        </tr>
    </table>

    <div class="h-section">■ RANGKUMAN KONSUMSI <?= ecell(strtoupper($modeLabel)) ?> — PERIODE <?= ecell(ecell(formatDate($dateFrom))) ?> S/D <?= ecell(formatDate($dateTo)) ?></div>
    <?php if (count($summaryData) > 0): ?>
    <table style="width:100%;">
        <colgroup>
            <col style="width:22%;"><col style="width:16%;"><col style="width:21%;"><col style="width:21%;"><col style="width:20%;">
        </colgroup>
        <thead>
            <tr>
                <th style="width:20%;"><?= $viewType === 'monthly' ? 'Bulan' : 'Tanggal' ?></th>
                <th class="text-right" style="width:15%;">Total Daily Log</th>
                <th class="text-right" style="width:20%;">⚡ Listrik (kWh)</th>
                <th class="text-right" style="width:20%;">💧 Air (m³)</th>
                <th class="text-right" style="width:20%;">🔥 Gas (kg)</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 0; foreach ($summaryData as $row): ?>
                <tr class="<?= ($i++ % 2 === 1) ? 'zebra' : '' ?>">
                    <td><strong><?= ecell($viewType === 'monthly' ? $row['period_label'] : formatDate($row['period_label'])) ?></strong></td>
                    <td class="text-right"><?= ecell(formatNumber((int)$row['log_count'])) ?></td>
                    <td class="text-right"><?= ecell(formatNumber((float)$row['sum_elec'])) ?></td>
                    <td class="text-right"><?= ecell(formatNumber((float)$row['sum_water'])) ?></td>
                    <td class="text-right"><?= ecell(formatNumber((float)$row['sum_gas'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="totals">
                <td>TOTAL KESELURUHAN (<?= ecell($modeLabel) ?>)</td>
                <td class="text-right"><?= ecell(formatNumber($totLog)) ?> entri</td>
                <td class="text-right"><?= ecell(formatNumber($totElec)) ?> kWh</td>
                <td class="text-right"><?= ecell(formatNumber($totWater)) ?> m³</td>
                <td class="text-right"><?= ecell(formatNumber($totGas)) ?> kg</td>
            </tr>
        </tfoot>
    </table>
    <?php else: ?>
        <div class="empty">Belum ada data konsumsi yang disetujui pada periode ini.</div>
    <?php endif; ?>

    <br><div class="h-section">■ DETAIL DAILY LOG APPROVED</div>
    <?php if (count($detailData) > 0): ?>
    <table style="width:100%;">
        <colgroup>
            <col style="width:5%;"><col style="width:11%;">
            <?php if ($userRole === 'supervisor'): ?>
                <col style="width:12%;"><col style="width:11%;"><col style="width:10%;">
            <?php endif; ?>
            <col style="width:9%;"><col style="width:9%;"><col style="width:9%;">
            <col style="width:13%;"><col style="width:10%;"><col style="width:11%;">
        </colgroup>
        <thead>
            <tr>
                <th style="width:5%;">No</th>
                <th style="width:9%;" class="nowrap">Tanggal</th>
                <?php if ($userRole === 'supervisor'): ?>
                    <th style="width:12%;">Nama Engineer</th>
                    <th style="width:11%;">Jabatan</th>
                    <th style="width:10%;">No. HP</th>
                <?php endif; ?>
                <th style="width:9%;" class="nowrap">⚡ Listrik (kWh)</th>
                <th style="width:9%;" class="nowrap">💧 Air (m³)</th>
                <th style="width:9%;" class="nowrap">🔥 Gas (kg)</th>
                <th style="width:18%;">Aktivitas Pekerjaan</th>
                <th style="width:18%;">Kendala</th>
                <th style="width:18%;">Solusi</th>
                <th style="width:9%;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 0; foreach ($detailData as $row): $i++; ?>
                <tr class="<?= ($i % 2 === 0) ? 'zebra' : '' ?>">
                    <td class="nowrap"><?= ecell($i) ?></td>
                    <td class="nowrap"><?= ecell(formatDate($row['log_date'])) ?></td>
                    <?php if ($userRole === 'supervisor'): ?>
                        <td><?= ecell($row['engineer_name'] ?? '-') ?></td>
                        <td><?= ecell($row['engineer_position'] ?? '-') ?></td>
                        <td><?= ecell($row['engineer_phone'] ?? '-') ?></td>
                    <?php endif; ?>
                    <td class="text-right"><?= ecell(formatNumber((float)$row['total_electricity'])) ?></td>
                    <td class="text-right"><?= ecell(formatNumber((float)$row['total_water'])) ?></td>
                    <td class="text-right"><?= ecell(formatNumber((float)$row['total_gas'])) ?></td>
                    <td class="text-muted"><?= ecell($row['work_activities'] ?? '-') ?></td>
                    <td class="text-muted"><?= ecell($row['obstacles'] ?? '-') ?></td>
                    <td class="text-muted"><?= ecell($row['solutions'] ?? '-') ?></td>
                    <td class="nowrap"><?= ecell(getStatusText($row['status'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="totals">
                <td colspan="<?= $userRole === 'supervisor' ? '5' : '2' ?>" style="text-align:right;">TOTAL (<?= ecell(count($detailData)) ?> entri)</td>
                <?php
                    $sumElec = 0; $sumWater = 0; $sumGas = 0;
                    foreach ($detailData as $row) {
                        $sumElec += (float)$row['total_electricity'];
                        $sumWater += (float)$row['total_water'];
                        $sumGas += (float)$row['total_gas'];
                    }
                ?>
                <td class="text-right"><?= ecell(formatNumber($sumElec)) ?></td>
                <td class="text-right"><?= ecell(formatNumber($sumWater)) ?></td>
                <td class="text-right"><?= ecell(formatNumber($sumGas)) ?></td>
                <td colspan="4">&nbsp;</td>
            </tr>
        </tfoot>
    </table>
    <?php else: ?>
        <div class="empty">Belum ada data Daily Log yang disetujui Supervisor pada periode ini.</div>
    <?php endif; ?>

    <table class="sign" style="margin-top:40px;">
        <tr>
            <td style="width:50%; vertical-align:top;">
                Dibuat Oleh,
                <br><span class="text-muted">Engineering Staff</span>
                <br><br><br><br>
                _______________________________<br>
                <span class="text-muted">Nama Lengkap &amp; Tanda Tangan</span>
            </td>
            <td style="width:50%; vertical-align:top; text-align:right;">
                Mengetahui,
                <br><span class="text-muted">Supervisor Engineering</span>
                <br><br><br><br>
                _______________________________<br>
                <span class="text-muted">Nama Lengkap &amp; Tanda Tangan</span>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
<?php
$out = ob_get_clean();
echo "\xEF\xBB\xBF";
echo $out;
while (ob_get_level() > 0) {
    if (ob_get_length() > 0) { ob_end_flush(); } else { ob_end_clean(); }
}
flush();
exit;
