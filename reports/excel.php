<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance();
$user = currentUser();
$isSupervisor = $user['role'] === 'supervisor';

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-t');
$status = $_GET['status'] ?? 'all';
$engineerId = (int)($_GET['engineer_id'] ?? 0);

$where = ["dl.log_date BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];

if (!$isSupervisor) {
    $where[] = "dl.engineer_id = ?";
    $params[] = $user['id'];
} elseif ($engineerId > 0) {
    $where[] = "dl.engineer_id = ?";
    $params[] = $engineerId;
}

if ($status !== 'all') {
    $where[] = "dl.status = ?";
    $params[] = $status;
}

$whereClause = implode(' AND ', $where);
/* ✅ 2026-08-23 FIX DEDUP MAX(id) per DATE+engineer_id: user isi logshet tanggal sama berkali-kali = hanya ambil entry TERAKHIR (id terbesar) setiap hari setiap engineer */
$logs = $db->fetchAll(
    "SELECT dl.*, u.name as engineer_name, u.position as engineer_position, u.email as engineer_email, s.name as supervisor_name
     FROM daily_logs dl
     INNER JOIN (
         SELECT MAX(id) AS keep_id
         FROM daily_logs dl_inner
         WHERE " . str_replace('dl.', 'dl_inner.', $whereClause) . "
         GROUP BY DATE(dl_inner.log_date), dl_inner.engineer_id
     ) k ON k.keep_id = dl.id
     LEFT JOIN users u ON dl.engineer_id = u.id
     LEFT JOIN users s ON dl.supervisor_id = s.id
     ORDER BY dl.log_date ASC",
    $params
);

$filename = 'DailyLog_EngineeringReport_' . $dateFrom . '_to_' . $dateTo;

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment;filename="' . $filename . '.xls"');
header('Cache-Control: max-age=0');
header('Expires: 0');

ob_start();
echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<style>
* { font-family: Calibri, Arial, sans-serif; }
body { padding: 15px; }
table { border-collapse: collapse; width: 100%; table-layout: fixed; }
th { background: #111; color: #fff; padding: 10px 12px; font-weight: 700; font-size: 11px; text-align: left; border: 1px solid #111; }
td { padding: 8px 12px; font-size: 11px; border: 1px solid #d0d0d0; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; white-space: normal; }
tr:nth-child(even) td { background: #fafafa; }
.title { font-size: 17px; font-weight: 800; color: #111; margin-bottom: 4px; font-family: Georgia, serif; }
.subtitle { font-size: 11px; color: #666; margin-bottom: 15px; letter-spacing: 1px; }
.meta { background: #f5f5f5; border: 1px solid #e5e5e5; padding: 12px; margin: 15px 0; }
.meta td { border: none; padding: 4px 8px; font-size: 11px; }
.meta .label { color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; width: 160px; }
.summary { margin: 20px 0; }
.sum-card { display: inline-block; width: 200px; background: #f5f5f5; border: 1px solid #e5e5e5; margin: 0 8px 8px 0; padding: 12px 15px; border-left: 4px solid #111; }
.sum-card .lbl { font-size: 10px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.sum-card .val { font-size: 22px; font-weight: 800; color: #111; margin-top: 4px; }
.sum-card .val span { font-size: 12px; color: #666; font-weight: 500; }
.section-title { font-size: 15px; font-weight: 800; color: #111; margin: 25px 0 10px; padding-bottom: 6px; border-bottom: 2px solid #111; }
.total-row td { background: #111 !important; color: #fff !important; font-weight: 800 !important; }
.status-pending { color: #92400e; font-weight: 700; }
.status-approved { color: #166534; font-weight: 700; }
.status-rejected { color: #991b1b; font-weight: 700; }
.right { text-align: right; }
.center { text-align: center; }
.detail-block { background: #fff; border: 1px solid #e5e5e5; margin: 12px 0; page-break-inside: avoid; }
.detail-head { background: #111; color: #fff; padding: 10px 14px; font-weight: 700; font-size: 12px; }
.detail-body { padding: 14px; }
.tri-grid { display: table; width: 100%; margin-bottom: 12px; }
.tri-grid > div { display: table-cell; width: 33.33%; padding: 8px; background: #fafafa; border: 1px solid #eee; }
.field-block { margin-bottom: 10px; }
.field-label { font-size: 10px; color: #888; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.field-value { font-size: 12px; color: #111; line-height: 1.6; white-space: pre-wrap; }
.revision-box { background: #ffe5e5; border: 1px solid #f5b4b4; padding: 10px 12px; margin-top: 10px; }
</style>
</head>
<body>
<div class="title">Engineering Report</div>
<div class="subtitle">ENGINEERING DEPARTMENT • DAILY LOG REPORT</div>

<table class="meta">
<colgroup>
    <col style="width:160px;"><col style="width:*;">
</colgroup>
<tr><td class="label">Periode Laporan</td><td><?= formatDate($dateFrom) ?> s/d <?= formatDate($dateTo) ?></td></tr>
<tr><td class="label">Dibuat Oleh</td><td><?= cleanInput($user['name']) ?> (<?= $user['role'] === 'engineer' ? 'Engineer' : 'Supervisor' ?>)</td></tr>
<tr><td class="label">Tanggal Export</td><td><?= formatDateTime(date('Y-m-d H:i:s')) ?></td></tr>
<tr><td class="label">Total Entri</td><td><?= count($logs) ?> daily log</td></tr>
</table>

<div class="section-title">Ringkasan Konsumsi (Approved Only)</div>
<div class="summary">
    <?php
    $tE = $tW = $tG = 0;
    foreach ($logs as $l) { if ($l['status'] === 'approved') { $tE+=$l['total_electricity']; $tW+=$l['total_water']; $tG+=$l['total_gas']; } }
    ?>
    <div class="sum-card"><div class="lbl">⚡ Total Listrik</div><div class="val"><?= number_format($tE,0,',','.') ?> <span>kWh</span></div></div>
    <div class="sum-card"><div class="lbl">💧 Total Air</div><div class="val"><?= number_format($tW,0,',','.') ?> <span>m3</span></div></div>
    <div class="sum-card"><div class="lbl">🔥 Total Gas</div><div class="val"><?= number_format($tG,0,',','.') ?> <span>kg</span></div></div>
</div>

<div class="section-title">Rekapitulasi Data</div>
<table>
<colgroup>
    <col style="width:45px;">
    <col style="width:110px;">
    <?php if ($isSupervisor): ?>
        <col style="width:140px;"><col style="width:120px;">
    <?php endif; ?>
    <col style="width:110px;">
    <col style="width:100px;">
    <col style="width:100px;">
    <col style="width:90px;">
    <?php if ($isSupervisor): ?>
        <col style="width:140px;">
    <?php endif; ?>
</colgroup>
<thead>
<tr>
    <th style="width:30px" class="center">NO</th>
    <th>TANGGAL</th>
    <?php if ($isSupervisor): ?>
        <th>ENGINEER</th>
        <th>JABATAN</th>
    <?php endif; ?>
    <th class="right">LISTRIK (kWh)</th>
    <th class="right">AIR (m3)</th>
    <th class="right">GAS (kg)</th>
    <th class="center">STATUS</th>
    <?php if ($isSupervisor): ?>
        <th>APPROVED BY</th>
    <?php endif; ?>
</tr>
</thead>
<tbody>
<?php
$no = 1; $totE = $totW = $totG = 0;
foreach ($logs as $l):
$totE += $l['total_electricity']; $totW += $l['total_water']; $totG += $l['total_gas'];
?>
<tr>
    <td class="center"><?= $no++ ?></td>
    <td><?= formatDate($l['log_date']) ?></td>
    <?php if ($isSupervisor): ?>
        <td><?= cleanInput($l['engineer_name']) ?></td>
        <td><?= cleanInput($l['engineer_position'] ?? '-') ?></td>
    <?php endif; ?>
    <td class="right"><?= number_format($l['total_electricity'],0,',','.') ?></td>
    <td class="right"><?= number_format($l['total_water'],0,',','.') ?></td>
    <td class="right"><?= number_format($l['total_gas'],0,',','.') ?></td>
    <td class="center status-<?= $l['status'] ?>"><?= strtoupper($l['status']) ?></td>
    <?php if ($isSupervisor): ?>
        <td><?= $l['supervisor_name'] ? cleanInput($l['supervisor_name']) : '-' ?></td>
    <?php endif; ?>
</tr>
<?php endforeach; ?>
<?php if (count($logs) > 0): ?>
<tr class="total-row">
    <td colspan="<?= $isSupervisor ? 4 : 2 ?>"><strong>TOTAL</strong></td>
    <td class="right"><strong><?= number_format($totE,0,',','.') ?></strong></td>
    <td class="right"><strong><?= number_format($totW,0,',','.') ?></strong></td>
    <td class="right"><strong><?= number_format($totG,0,',','.') ?></strong></td>
    <td colspan="<?= $isSupervisor ? 2 : 1 ?>"></td>
</tr>
<?php endif; ?>
</tbody>
</table>

<div class="section-title">Detail Daily Log</div>
<?php if (count($logs) > 0): foreach ($logs as $l): ?>
<div class="detail-block">
    <div class="detail-head">📅 <?= formatDate($l['log_date']) ?> • <?= cleanInput($l['engineer_name']) ?> • Status: <?= strtoupper($l['status']) ?></div>
    <div class="detail-body">
        <div class="tri-grid">
            <div>
                <div class="field-label">⚡ Total Listrik</div>
                <div class="field-value" style="font-size:18px;font-weight:800;"><?= number_format($l['total_electricity'],0,',','.') ?> kWh</div>
            </div>
            <div>
                <div class="field-label">💧 Total Air</div>
                <div class="field-value" style="font-size:18px;font-weight:800;"><?= number_format($l['total_water'],0,',','.') ?> m3</div>
            </div>
            <div>
                <div class="field-label">🔥 Total Gas</div>
                <div class="field-value" style="font-size:18px;font-weight:800;"><?= number_format($l['total_gas'],0,',','.') ?> kg</div>
            </div>
        </div>
        <div class="field-block">
            <div class="field-label">✅ Aktivitas Pekerjaan</div>
            <div class="field-value"><?= cleanInput($l['work_activities']) ?></div>
        </div>
        <?php if ($l['obstacles']): ?>
        <div class="field-block">
            <div class="field-label">⚠️ Kendala</div>
            <div class="field-value"><?= cleanInput($l['obstacles']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($l['solutions']): ?>
        <div class="field-block">
            <div class="field-label">💡 Solusi</div>
            <div class="field-value"><?= cleanInput($l['solutions']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($l['revision_notes']): ?>
        <div class="revision-box">
            <div class="field-label" style="color:#991b1b;">❌ CATATAN REVISI SUPERVISOR</div>
            <div class="field-value"><?= cleanInput($l['revision_notes']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($l['approved_at']): ?>
        <div style="margin-top:10px; padding-top:8px; border-top:1px dashed #ccc; font-size:11px; color:#666;">
            ⏰ Approved at: <?= formatDateTime($l['approved_at']) ?>
            <?php if ($l['supervisor_name']): ?> oleh <strong><?= cleanInput($l['supervisor_name']) ?></strong><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; else: ?>
<p style="padding:20px; text-align:center; color:#888; background:#f5f5f5; border:1px solid #e5e5e5;">Tidak ada data daily log pada periode ini.</p>
<?php endif; ?>

<div style="margin-top:30px; padding-top:15px; border-top:2px solid #111; font-size:10px; color:#888; text-align:center;">
    * Dokumen ini di-generate otomatis oleh sistem Daily Log Engineering Department • Terakhir diupdate: <?= date('Y-m-d H:i:s') ?>
</div>
</body>
</html>
<?php
echo ob_get_clean();
exit;
