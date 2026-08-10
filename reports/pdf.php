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
$logs = $db->fetchAll(
    "SELECT dl.*, u.name as engineer_name, u.position as engineer_position, u.phone as engineer_phone, s.name as supervisor_name
     FROM daily_logs dl
     LEFT JOIN users u ON dl.engineer_id = u.id
     LEFT JOIN users s ON dl.supervisor_id = s.id
     WHERE $whereClause
     ORDER BY dl.log_date ASC",
    $params
);

$totals = ['electricity' => 0, 'water' => 0, 'gas' => 0];
foreach ($logs as $l) {
    if ($l['status'] === 'approved') {
        $totals['electricity'] += $l['total_electricity'];
        $totals['water'] += $l['total_water'];
        $totals['gas'] += $l['total_gas'];
    }
}

$filename = 'DailyLog_EngineeringReport_' . $dateFrom . '_to_' . $dateTo . '.pdf';
header('Content-Type: text/html; charset=utf-8');

function imgDataUri($pathAbs) {
    if (!$pathAbs || !is_file($pathAbs)) return '';
    $ext = strtolower(pathinfo($pathAbs, PATHINFO_EXTENSION));
    $mime = in_array($ext, ['png','gif','webp'], true) ? 'image/'.$ext : 'image/jpeg';
    $data = @file_get_contents($pathAbs);
    return $data ? 'data:'.$mime.';base64,'.base64_encode($data) : '';
}
$logoSrc = '';
$uploadsDir = realpath(__DIR__ . '/../assets/uploads');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?= $filename ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Tahoma, sans-serif; color: #111; background: #f5f5f5; padding: 20px; }
.wrapper { max-width: 900px; margin: 0 auto; background: #fff; padding: 40px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }
.header { display: flex; align-items: center; gap: 20px; padding-bottom: 20px; border-bottom: 2px solid #111; margin-bottom: 25px; }
.logo { width: 70px; height: 70px; object-fit: contain; border: 1px solid #e5e5e5; border-radius: 12px; padding: 4px; background: #fff; }
.logo-fallback {
    width: 70px; height: 70px; flex-shrink: 0;
    background: linear-gradient(135deg, #c9a227, #8a6e14);
    color: #fff; border-radius: 12px;
    display: inline-flex; align-items: center; justify-content: center;
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 26px; font-weight: 800; letter-spacing: 1px;
    box-shadow: 0 6px 14px rgba(201,162,39,0.32);
}
.header-text h1 { font-family: Georgia, serif; font-size: 24px; font-weight: 700; letter-spacing: 1px; }
.header-text p { font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: #555; margin-top: 3px; }
.header-text .sub { font-size: 10px; letter-spacing: 1.5px; margin-top: 2px; color: #888; }
.meta-bar { background: #f9fafb; border: 1px solid #e5e5e5; padding: 14px 20px; border-radius: 8px; margin-bottom: 25px; display: grid; grid-template-columns: repeat(3,1fr); gap: 15px; font-size: 12px; }
.meta-bar div { display: flex; flex-direction: column; gap: 3px; }
.meta-bar .label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #888; font-weight: 600; }
.meta-bar .value { font-weight: 600; color: #111; }
h2.title { font-size: 18px; margin: 25px 0 15px; padding-bottom: 8px; border-bottom: 1px solid #e5e5e5; color: #111; }
.summary { display: grid; grid-template-columns: repeat(3,1fr); gap: 15px; margin-bottom: 25px; }
.sum-card { background: #f9fafb; border: 1px solid #e5e5e5; border-left: 4px solid #111; padding: 15px; border-radius: 8px; }
.sum-card .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #888; font-weight: 600; }
.sum-card .val { font-size: 20px; font-weight: 700; color: #111; margin-top: 4px; }
.sum-card .val span { font-size: 12px; color: #666; font-weight: 500; margin-left: 3px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 11px; }
th { background: #111; color: #fff; text-align: left; padding: 10px 12px; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; }
td { padding: 10px 12px; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
tr:nth-child(even) td { background: #fafafa; }
.right { text-align: right; }
.center { text-align: center; }
.status-pending { background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 600; display: inline-block; }
.status-approved { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 600; display: inline-block; }
.status-rejected { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 600; display: inline-block; }
.log-detail { margin-top: 25px; border: 1px solid #e5e5e5; border-radius: 10px; overflow: hidden; }
.log-detail-head { background: linear-gradient(90deg, #111, #333); color: #fff; padding: 12px 18px; display: flex; justify-content: space-between; align-items: center; }
.log-detail-head h3 { font-size: 14px; }
.log-detail-body { padding: 18px; }
.detail-row { display: grid; grid-template-columns: repeat(3,1fr); gap: 20px; margin-bottom: 18px; }
.detail-card { background: #f9fafb; padding: 12px 14px; border-radius: 8px; border: 1px solid #eee; }
.detail-card .lbl { font-size: 10px; text-transform: uppercase; color: #888; font-weight: 600; letter-spacing: 0.3px; }
.detail-card .val { font-size: 16px; font-weight: 700; color: #111; margin-top: 2px; }
.detail-card .val span { font-size: 11px; color: #666; font-weight: 500; }
.text-block { margin-bottom: 14px; }
.text-block h4 { font-size: 11px; text-transform: uppercase; color: #555; letter-spacing: 0.5px; margin-bottom: 6px; font-weight: 700; }
.text-block p { font-size: 12px; line-height: 1.7; color: #111; white-space: pre-wrap; }
.img-block { margin-bottom: 14px; }
.img-block img { max-width: 250px; max-height: 180px; border: 1px solid #e5e5e5; border-radius: 8px; }
.signature-area { margin-top: 40px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
.sig-box { text-align: center; }
.sig-box p.name-label { font-size: 12px; margin-bottom: 60px; font-weight: 500; color: #555; }
.sig-box .name { font-size: 13px; font-weight: 700; border-top: 1px solid #999; padding-top: 8px; display: inline-block; min-width: 200px; }
.sig-box .role { font-size: 11px; color: #888; margin-top: 3px; }
.sig-box img { max-height: 60px; margin-bottom: -8px; }
.footer-notes { margin-top: 35px; padding-top: 15px; border-top: 1px solid #e5e5e5; font-size: 10px; color: #999; text-align: center; }
.print-btn { position: fixed; top: 20px; right: 20px; z-index: 999; padding: 12px 22px; background: #111; color: #fff; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 13px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); transition: all 0.2s; }
.print-btn:hover { background: #333; transform: translateY(-1px); }
@media print {
    body { padding: 0; background: #fff; }
    .wrapper { box-shadow: none; padding: 20px; max-width: 100%; }
    .print-btn { display: none !important; }
    .log-detail { page-break-inside: avoid; }
}
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Cetak / Save PDF</button>
<div class="wrapper">
    <div class="header">
        <div class="header-text">
            <h1>Engineering Report</h1>
            <p>Engineering Department</p>
            <div class="sub">Laporan Daily Log Engineering</div>
        </div>
    </div>

    <div class="meta-bar">
        <div><span class="label">Periode Laporan</span><span class="value"><?= formatDate($dateFrom) ?> - <?= formatDate($dateTo) ?></span></div>
        <div><span class="label">Dicetak Oleh</span><span class="value"><?= cleanInput($user['name']) ?> (<?= $user['role'] === 'engineer' ? 'Engineer' : 'Supervisor' ?>)</span></div>
        <div><span class="label">Tanggal Cetak</span><span class="value"><?= formatDateTime(date('Y-m-d H:i:s')) ?></span></div>
    </div>

    <h2 class="title">Ringkasan Konsumsi (Approved Only)</h2>
    <div class="summary">
        <div class="sum-card"><div class="lbl">⚡ Total Listrik</div><div class="val"><?= number_format($totals['electricity'],0,',','.') ?> <span>kWh</span></div></div>
        <div class="sum-card"><div class="lbl">💧 Total Air</div><div class="val"><?= number_format($totals['water'],0,',','.') ?> <span>m³</span></div></div>
        <div class="sum-card"><div class="lbl">🔥 Total Gas</div><div class="val"><?= number_format($totals['gas'],0,',','.') ?> <span>kg</span></div></div>
    </div>

    <h2 class="title">Daftar Daily Log (<?= count($logs) ?> Entri)</h2>
    <table>
        <thead>
            <tr>
                <th style="width:40px">#</th>
                <th>Tanggal</th>
                <?php if ($isSupervisor): ?>
                    <th>Engineer</th>
                <?php endif; ?>
                <th class="right">Listrik (kWh)</th>
                <th class="right">Air (m³)</th>
                <th class="right">Gas (kg)</th>
                <th class="center">Status</th>
                <?php if ($isSupervisor): ?>
                    <th>Approved By</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php
            $no = 1;
            $sumE = $sumW = $sumG = 0;
            foreach ($logs as $l):
                $sumE += $l['total_electricity'];
                $sumW += $l['total_water'];
                $sumG += $l['total_gas'];
            ?>
            <tr>
                <td class="center"><?= $no++ ?></td>
                <td><?= formatDate($l['log_date']) ?></td>
                <?php if ($isSupervisor): ?>
                    <td><?= cleanInput($l['engineer_name']) ?></td>
                <?php endif; ?>
                <td class="right"><?= number_format($l['total_electricity'],0,',','.') ?></td>
                <td class="right"><?= number_format($l['total_water'],0,',','.') ?></td>
                <td class="right"><?= number_format($l['total_gas'],0,',','.') ?></td>
                <td class="center"><span class="status-<?= $l['status'] ?>"><?= strtoupper($l['status']) ?></span></td>
                <?php if ($isSupervisor): ?>
                    <td><?= $l['supervisor_name'] ? cleanInput($l['supervisor_name']) : '-' ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (count($logs) > 0): ?>
            <tr style="background:#111 !important; color:#fff;">
                <td colspan="<?= $isSupervisor ? 3 : 2 ?>" style="background:#111;color:#fff;font-weight:700;">TOTAL SEMUA</td>
                <td class="right" style="background:#111;color:#fff;font-weight:700;"><?= number_format($sumE,0,',','.') ?></td>
                <td class="right" style="background:#111;color:#fff;font-weight:700;"><?= number_format($sumW,0,',','.') ?></td>
                <td class="right" style="background:#111;color:#fff;font-weight:700;"><?= number_format($sumG,0,',','.') ?></td>
                <td colspan="<?= $isSupervisor ? 2 : 1 ?>" style="background:#111;color:#fff;"></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if (count($logs) > 0): ?>
    <h2 class="title">Detail Setiap Daily Log</h2>
    <?php foreach ($logs as $l): ?>
    <div class="log-detail" style="margin-bottom:18px;">
        <div class="log-detail-head">
            <h3>📅 <?= formatDate($l['log_date']) ?> • <?= cleanInput($l['engineer_name']) ?></h3>
            <span class="status-<?= $l['status'] ?>" style="background: rgba(255,255,255,0.2); color: #fff; padding: 4px 10px; border-radius: 999px; font-size: 10px;"><?= strtoupper($l['status']) ?></span>
        </div>
        <div class="log-detail-body">
            <div class="detail-row">
                <div class="detail-card"><div class="lbl">⚡ Listrik</div><div class="val"><?= number_format($l['total_electricity'],0,',','.') ?> <span>kWh</span></div></div>
                <div class="detail-card"><div class="lbl">💧 Air</div><div class="val"><?= number_format($l['total_water'],0,',','.') ?> <span>m³</span></div></div>
                <div class="detail-card"><div class="lbl">🔥 Gas</div><div class="val"><?= number_format($l['total_gas'],0,',','.') ?> <span>kg</span></div></div>
            </div>
            <div class="text-block"><h4>✅ Aktivitas Pekerjaan</h4><p><?= nl2br(cleanInput($l['work_activities'])) ?></p></div>
            <?php if ($l['obstacles']): ?>
                <div class="text-block"><h4>⚠️ Kendala</h4><p><?= nl2br(cleanInput($l['obstacles'])) ?></p></div>
            <?php endif; ?>
            <?php if ($l['solutions']): ?>
                <div class="text-block"><h4>💡 Solusi</h4><p><?= nl2br(cleanInput($l['solutions'])) ?></p></div>
            <?php endif; ?>
            <?php if ($l['photo'] && $uploadsDir && file_exists($uploadsDir . DIRECTORY_SEPARATOR . $l['photo'])): ?>
                <?php $photoUri = imgDataUri($uploadsDir . DIRECTORY_SEPARATOR . $l['photo']); ?>
                <?php if ($photoUri): ?>
                <div class="img-block"><h4 style="font-size:11px; text-transform:uppercase; color:#555; margin-bottom:8px; font-weight:700;">📷 Foto Dokumentasi</h4>
                    <img src="<?= $photoUri ?>" alt="Photo Dokumentasi">
                </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($l['supervisor_signature']): ?>
                <?php
                    $sigUri = '';
                    $sigRaw = (string)$l['supervisor_signature'];
                    if (substr($sigRaw, 0, 5) === 'data:') {
                        $sigUri = $sigRaw;
                    } elseif ($uploadsDir && is_file($uploadsDir . DIRECTORY_SEPARATOR . $sigRaw)) {
                        $sigUri = imgDataUri($uploadsDir . DIRECTORY_SEPARATOR . $sigRaw);
                    }
                ?>
                <?php if (!empty($sigUri)): ?>
                <div style="margin-top:18px; padding-top:14px; border-top:1px dashed #ccc; display:flex; justify-content:flex-end;">
                    <div style="text-align:center;">
                        <p style="font-size:11px; color:#888; margin-bottom:6px;">Disetujui oleh Supervisor,</p>
                        <img src="<?= $sigUri ?>" alt="Tanda Tangan Supervisor" style="max-height:50px;">
                        <p style="font-size:12px; font-weight:700; border-top:1px solid #999; padding-top:6px; margin-top:2px;"><?= cleanInput((string)($l['supervisor_name'] ?? '')) ?></p>
                        <?php if ($l['approved_at']): ?>
                            <p style="font-size:10px; color:#888; margin-top:2px;"><?= formatDateTime((string)$l['approved_at']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($l['revision_notes']): ?>
                <div style="margin-top:14px; padding:12px; background:#fee2e2; border:1px solid #fca5a5; border-radius:8px;">
                    <h4 style="font-size:11px; color:#991b1b; margin-bottom:4px; font-weight:700;">❌ Catatan Revisi</h4>
                    <p style="font-size:12px; color:#7f1d1d;"><?= nl2br(cleanInput($l['revision_notes'])) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="footer-notes">
        Dokumen ini dicetak otomatis dari sistem Daily Log Engineering Department • Laporan ini sah tanpa tanda tangan fisik jika sudah ada approval digital
    </div>
</div>
</body>
</html>
