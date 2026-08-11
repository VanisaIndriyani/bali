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
$detailQuery = "SELECT dl.*, u.name as engineer_name
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

$periodLabel = formatDate($dateFrom) . ' s/d ' . formatDate($dateTo);
$modeLabel = $viewType === 'monthly' ? 'Bulanan' : 'Harian';
$fileName = 'Dashboard_Konsumsi_' . $modeLabel . '_' . date('Ymd', strtotime($dateFrom)) . '-' . date('Ymd', strtotime($dateTo));

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
                    <div class="value"><?= formatNumber($totWater) ?><span class="unit"> m³</span></div>
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
                            <th class="text-right">💧 Air (m³)</th>
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
                            <td class="text-right"><?= formatNumber($totWater) ?> m³</td>
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
                                <td class="text-right" nowrap><?= formatNumber((float)$row['total_water']) ?> m³</td>
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

            <div class="footer-sign">
                <div class="sign-block">
                    <div class="who">Dibuat Oleh,<br><span class="text-muted" style="font-weight:400;">Engineering Staff</span></div>
                    <div class="sign-line">_______________________________<br>Nama &amp; Tanda Tangan</div>
                </div>
                <div class="sign-block" style="text-align:right;">
                    <div class="who">Mengetahui,<br><span class="text-muted" style="font-weight:400;">Supervisor Engineering</span></div>
                    <div class="sign-line" style="display:inline-block;min-width:260px;text-align:left;">_______________________________<br>Nama &amp; Tanda Tangan</div>
                </div>
            </div>
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
