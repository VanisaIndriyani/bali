<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance();

$division = $_GET['division'] ?? 'all';
$monthRaw = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', (string)$monthRaw)) {
    $monthRaw = date('Y-m');
}
$monthLabel = (new DateTime($monthRaw . '-01'))->format('M Y');

$divFilter = '';
$params = [];
if ($division !== 'all' && in_array($division, ['operation','maintenance','project','landscape'], true)) {
    $divFilter = " WHERE division = ? ";
    $params[] = $division;
}
$allMasters = $db->fetchAll(
    "SELECT * FROM activity_masters $divFilter ORDER BY FIELD(division,'operation','maintenance','project','landscape'), sort_order ASC, id ASC",
    $params
);

$divisions = ['operation','maintenance','project','landscape'];
$divLabelMap = ['operation'=>'OPERATION','maintenance'=>'MAINTENANCE','project'=>'PROJECT','landscape'=>'LANDSCAPE'];
$divColorMap = ['operation'=>'#ea580c','maintenance'=>'#0891b2','project'=>'#6d28d9','landscape'=>'#15803d'];

$fileName = 'Engineering_Lists_' . $monthRaw;
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
<meta name="Generator" content="Engineering Report — Lists Simple MS Teams Style">
<title><?= htmlspecialchars($fileName, ENT_QUOTES) ?></title>
<style>
    * { font-family: Calibri, "Segoe UI", Arial, sans-serif; }
    body { padding: 18px 22px; background:#fff; color:#0f172a; }
    table { border-collapse: collapse; width: 100%; table-layout: fixed; }
    th, td { border: 1px solid #cbd5e1; padding: 8px 10px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; white-space: normal; }
    th {
        background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%) !important;
        color: #ffffff !important;
        font-weight: 800;
        font-size: 11px;
        text-align: left;
        letter-spacing: 0.3px;
    }
    tr:nth-child(even) td { background: #f8fafc; }
    .brand-title {
        font-size: 28px;
        font-weight: 900;
        font-family: Georgia, "Times New Roman", serif;
        color: #0f172a;
        margin: 0 0 4px 0;
        letter-spacing: 0.5px;
    }
    .brand-sub {
        font-size: 11px;
        color: #64748b;
        margin: 0 0 18px 0;
        letter-spacing: 2px;
        text-transform: uppercase;
        font-weight: 700;
    }
    .hero-bar {
        background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%) !important;
        color: #ffffff !important;
        padding: 18px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    .hero-bar .tag {
        display: inline-block; padding: 4px 12px;
        background: rgba(255,255,255,0.15);
        border: 1px solid rgba(255,255,255,0.25);
        border-radius: 999px;
        color: #fff; font-size: 11px; font-weight: 700; letter-spacing: 1.5px;
        margin-bottom: 10px;
    }
    .hero-bar h1 {
        color: #ffffff !important;
        font-size: 36px;
        font-weight: 900;
        margin: 0 0 6px 0;
        letter-spacing: -0.5px;
    }
    .hero-bar p {
        color: rgba(255,255,255,0.9) !important;
        font-size: 12px;
        margin: 0;
        letter-spacing: 0.4px;
    }
    .meta {
        background: #f8fafc; border: 1px solid #e2e8f0;
        padding: 12px 14px;
        margin: 0 0 18px 0;
        border-radius: 6px;
    }
    .meta td { border: none; padding: 3px 10px 3px 0; font-size: 11px; }
    .meta td:first-child { font-weight: 800; color: #64748b; width: 140px; letter-spacing: 0.4px; text-transform: uppercase; }
    .divider {
        background: linear-gradient(90deg, #1e3a8a 0%, #3b82f6 100%) !important;
        color: #ffffff !important;
        padding: 8px 14px;
        font-weight: 900;
        font-size: 12px;
        letter-spacing: 2.5px;
        margin: 20px 0 10px 0;
        border-radius: 6px;
    }
    .divider small { color: rgba(255,255,255,0.85); font-weight: 600; letter-spacing: 0.3px; margin-left: 10px; font-size: 10px; }
    .num { font-weight: 900; text-align: center; color: #475569; }
    .check { text-align: center; color: #1e40af; }
    .star { color: #ca8a04; text-align: center; }
    .st-done { color:#15803d; font-weight: 800; text-transform: uppercase; font-size: 10px; letter-spacing:1px; }
    .st-progress { color:#b45309; font-weight: 800; text-transform: uppercase; font-size: 10px; letter-spacing:1px; }
    .center { text-align: center; }
    .empty { padding: 22px; text-align:center; color:#64748b; background:#f8fafc; border:1px dashed #cbd5e1; font-size:12px; font-style: italic; }
    .footer-note {
        margin-top: 28px; padding-top: 14px;
        border-top: 2px solid #0f172a;
        color: #94a3b8; font-size: 10px;
        text-align: center; letter-spacing: 0.5px;
    }
    .dot-div {
        display:inline-block; width: 8px; height: 8px; border-radius: 50%;
        margin-right: 6px; vertical-align: middle;
    }
</style>
</head>
<body>

<div class="brand-title">Engineering Report</div>
<div class="brand-sub">Engineering Department • Lists Simple Export</div>

<div class="hero-bar">
    <div class="tag">Engineering Department • <?= htmlspecialchars($monthLabel) ?></div>
    <h1>Engineering Operation</h1>
    <p>Daftar aktivitas master tim engineering • Total <strong><?= count($allMasters) ?> item</strong> • Format simple Microsoft Lists style</p>
</div>

<table class="meta">
    <colgroup>
        <col style="width:140px;"><col style="width:260px;">
        <col style="width:140px;"><col style="width:*;">
    </colgroup>
    <tr>
        <td>Periode Lists</td><td>: <?= htmlspecialchars($monthLabel) ?> (<?= htmlspecialchars($monthRaw) ?>)</td>
        <td>Total Item</td><td>: <?= count($allMasters) ?> aktivitas master</td>
    </tr>
    <tr>
        <td>Filter Divisi</td><td>: <?= $division === 'all' ? 'Semua Divisi (Operation, Maintenance, Project, Landscape)' : strtoupper($division) ?></td>
        <td>Export Tanggal</td><td>: <?= date('d M Y') ?> • <?= date('H:i') ?></td>
    </tr>
    <tr>
        <td>Dibuat Oleh</td><td>: <?= htmlspecialchars((string)(($user['name'] ?? 'System') . ' (' . ucfirst((string)($user['role'] ?? 'user')) . ')')) ?></td>
        <td>Status Default</td><td>: Progress (bisa di-update di Kelola Master)</td>
    </tr>
</table>

<?php if (empty($allMasters)): ?>
    <div class="empty">Belum ada activity master. Silakan tambah melalui tombol ⚙️ Kelola Master di halaman Manager → Activities.</div>
<?php else:
    $curDiv = '';
    $runningNo = 0;
    foreach ($divisions as $dv):
        $items = [];
        foreach ($allMasters as $m) {
            if ((string)($m['division'] ?? 'operation') === $dv) $items[] = $m;
        }
        if (empty($items)) continue;
        $color = $divColorMap[$dv] ?? '#475569';
        $cnt = count($items);
        for ($k = 0; $k < $cnt; $k++) $runningNo++;
?>
    <div class="divider" style="border-left:6px solid <?= $color ?> !important;">
        <span class="dot-div" style="background:<?= $color ?> !important;"></span>
        <?= $divLabelMap[$dv] ?? strtoupper($dv) ?>
        <small>(<?= $cnt ?> item)</small>
    </div>

    <table>
        <colgroup>
            <col style="width:46px;">
            <col style="width:40px;">
            <col style="width:46px;">
            <col style="width:100px;">
            <col style="width:*;">
            <col style="width:70px;">
            <col style="width:90px;">
            <col style="width:110px;">
        </colgroup>
        <thead>
            <tr>
                <th class="center" style="width:46px;">#</th>
                <th class="center" style="width:40px;">☑</th>
                <th class="center" style="width:46px;">⭐</th>
                <th style="width:100px;">Order ID</th>
                <th style="width:*;">Nama Aktivitas / Task</th>
                <th class="center" style="width:70px;">Priority</th>
                <th class="center" style="width:90px;">Status</th>
                <th style="width:110px;">Divisi</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $localNo = 0;
            foreach ($items as $m):
                $localNo++;
                $nm = trim((string)($m['activity_name'] ?? ''));
                if ($nm === '') $nm = '(nama aktivitas kosong)';
                $sort = (int)($m['sort_order'] ?? 0);
                $st = strtolower((string)($m['status_default'] ?? 'progress'));
                $stTxt = $st === 'complete' ? 'DONE' : 'PROGRESS';
                $stCls = $st === 'complete' ? 'st-done' : 'st-progress';
                $cr = $m['created_at'] ?? '';
                if ($cr) {
                    try { $cr = (new DateTime($cr))->format('d M Y'); } catch (Throwable $e) { $cr = '-'; }
                } else { $cr = '-'; }
                // Priority: sort_order kecil (0-5)=High, (6-15)=Medium, else=Low
                if ($sort <= 5) $priority = 'HIGH';
                elseif ($sort <= 15) $priority = 'MEDIUM';
                else $priority = 'LOW';
            ?>
            <tr>
                <td class="num"><?= $localNo ?>.</td>
                <td class="check">☐</td>
                <td class="star">☆</td>
                <td class="center">#<?= $sort ?></td>
                <td style="font-weight:600; color:#0f172a; font-size:12px;"><?= htmlspecialchars($nm) ?></td>
                <td class="center" style="font-weight:800; font-size:10px; letter-spacing:1px; color:#<?= $priority==='HIGH'?'991b1b':($priority==='MEDIUM'?'a16207':'166534') ?>;"><?= $priority ?></td>
                <td class="center"><span class="<?= $stCls ?>"><?= $stTxt ?></span></td>
                <td>
                    <span class="dot-div" style="background:<?= $color ?> !important;"></span>
                    <strong style="font-size:10px; letter-spacing:1.5px; color:#334155;"><?= $divLabelMap[$dv] ?? strtoupper($dv) ?></strong>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>
<?php endif; ?>

<div class="footer-note">
    * Dokumen ini di-generate otomatis oleh sistem Engineering Report — Engineering Department • Terakhir diupdate: <?= date('Y-m-d H:i:s') ?> • Disediakan tanpa logo dan nama hotel (format generic).
</div>
</body>
</html>
<?php
echo ob_get_clean();
exit;
