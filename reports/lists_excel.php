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
$divLabelMap = ['operation'=>'operation','maintenance'=>'maintenance','project'=>'project','landscape'=>'landscape'];
$divColorMap = ['operation'=>'#475569','maintenance'=>'#475569','project'=>'#475569','landscape'=>'#475569'];

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
<meta name="Generator" content="Engineering Report — Lists Simple Export">
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
    tr:nth-child(even) td { background: #fcfcfd; }

    .brand-title {
        font-size: 26px;
        font-weight: 900;
        font-family: Calibri, "Segoe UI", Arial, sans-serif;
        color: #0f172a;
        margin: 0;
        letter-spacing: -0.2px;
    }
    .brand-title .accent {
        background: linear-gradient(90deg, #0ea5e9, #6366f1);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
    }
    .brand-sub {
        font-size: 10px;
        color: #64748b;
        margin: 0 0 18px 0;
        letter-spacing: 2.5px;
        text-transform: uppercase;
        font-weight: 700;
    }
    .eyebrow {
        display: inline-flex; align-items: center; gap: 10px;
        color: #475569; font-size: 10px; font-weight: 800;
        letter-spacing: 2.5px; text-transform: uppercase;
        margin-bottom: 8px;
    }
    .eyebrow .pill {
        display: inline-block; padding: 2px 10px;
        border: 1px solid #cbd5e1;
        background: #f8fafc;
        color: #475569;
        border-radius: 999px;
        font-size: 9.5px;
        letter-spacing: 1px;
        font-weight: 700;
    }
    .hero-title {
        color: #0f172a;
        font-size: 32px;
        font-weight: 900;
        margin: 0 0 6px 0;
        letter-spacing: -0.2px;
    }
    .hero-title .accent {
        background: linear-gradient(90deg, #0ea5e9, #6366f1);
        -webkit-background-clip: text; background-clip: text;
        color: transparent;
    }
    .hero-sub {
        color: #64748b;
        font-size: 11.5px;
        margin: 0 0 16px 0;
        letter-spacing: 0.1px;
        font-weight: 500;
        line-height: 1.5;
    }

    .meta {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        padding: 12px 14px;
        margin: 0 0 18px 0;
        border-radius: 6px;
    }
    .meta td { border: none; padding: 3px 10px 3px 0; font-size: 10.5px; }
    .meta td:first-child { font-weight: 800; color: #64748b; width: 140px; letter-spacing: 0.8px; text-transform: uppercase; font-size: 10px; }

    .chips {
        display: flex; gap: 8px; margin-bottom: 22px; flex-wrap: wrap;
    }
    .chip {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 5px 11px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        color: #475569;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.2px;
    }
    .chip strong { color: #0f172a; font-weight: 800; }

    .divider {
        display: flex; align-items: center; gap: 10px;
        margin: 24px 0 10px 0;
        padding-bottom: 7px;
        border-bottom: 1px solid #e2e8f0;
    }
    .divider .dotdiv {
        width: 8px; height: 8px; border-radius: 50%;
        background: #cbd5e1;
        box-shadow: inset 0 0 0 2px #fff, 0 0 0 1px #e2e8f0;
    }
    .divider .lbl {
        color: #334155;
        font-size: 11px; font-weight: 800;
        letter-spacing: 2px;
        text-transform: uppercase;
    }
    .divider .cnt {
        color: #94a3b8; font-size: 10px; font-weight: 600; letter-spacing: 0.3px;
        margin-left: auto;
    }

    .num { font-weight: 900; text-align: center; color: #64748b; font-size: 11px; }
    .check { text-align: center; color: #475569; }
    .star { color: #cbd5e1; text-align: center; }
    .st-done { color:#15803d; font-weight: 800; text-transform: uppercase; font-size: 9.5px; letter-spacing: 1px; background:#f0fdf4; padding:2px 6px; border-radius:999px; border:1px solid #bbf7d0; display:inline-block;}
    .st-progress { color:#92400e; font-weight: 800; text-transform: uppercase; font-size: 9.5px; letter-spacing: 1px; background:#fffbeb; padding:2px 6px; border-radius:999px; border:1px solid #fde68a; display:inline-block;}
    .center { text-align: center; }
    .empty { padding: 22px; text-align:center; color:#64748b; background:#ffffff; border:1px dashed #cbd5e1; font-size:11.5px; font-style: italic; border-radius:10px;}

    .footer-note {
        margin-top: 28px; padding-top: 14px;
        border-top: 1px dashed #cbd5e1;
        color: #94a3b8; font-size: 9.5px;
        text-align: center; letter-spacing: 0.3px;
    }
    .footer-note .brand {
        color: #334155; font-weight: 800; letter-spacing: 1.5px; font-size: 10px;
        text-transform: uppercase;
    }
    .footer-note .brand em {
        font-style: normal;
        background: linear-gradient(90deg, #0ea5e9, #6366f1);
        -webkit-background-clip: text; background-clip: text;
        color: transparent;
    }
    .dot-div {
        display:inline-block; width: 6px; height: 6px; border-radius: 50%;
        margin-right: 6px; vertical-align: middle;
        background: #cbd5e1;
    }
    .prio-high { color:#991b1b; }
    .prio-med { color:#a16207; }
    .prio-low { color:#166534; }
</style>
</head>
<body>

<div class="brand-title">Engineering <span class="accent">Report</span></div>
<div class="brand-sub">Engineering Department • Lists Simple Export</div>

<div class="eyebrow">
    <span>Engineering Department</span>
    <span class="pill"><?= htmlspecialchars($monthLabel) ?></span>
</div>
<div class="hero-title">Engineering <span class="accent">Operation</span></div>
<div class="hero-sub">
    daftar aktivitas master tim engineering • format simple mirip microsoft lists. dikelompokkan per divisi, urut sesuai priority order number.
</div>

<div class="chips">
    <div class="chip">📋 total item <strong><?= count($allMasters) ?></strong></div>
    <?php foreach ($divisions as $dv):
        $c = 0;
        foreach ($allMasters as $m) if ((string)($m['division'] ?? 'operation') === $dv) $c++;
        if ($c <= 0) continue;
    ?>
    <div class="chip">📁 <?= strtolower($divLabelMap[$dv] ?? $dv) ?> <strong><?= $c ?></strong></div>
    <?php endforeach; ?>
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
        <td>Filter Divisi</td><td>: <?= $division === 'all' ? 'semua divisi (operation, maintenance, project, landscape)' : strtolower($division) ?></td>
        <td>Export Tanggal</td><td>: <?= date('d M Y') ?> • <?= date('H:i') ?></td>
    </tr>
    <tr>
        <td>Dibuat Oleh</td><td>: <?= htmlspecialchars((string)(($user['name'] ?? 'System') . ' (' . ucfirst((string)($user['role'] ?? 'user')) . ')')) ?></td>
        <td>Status Default</td><td>: progress (bisa update di ⚙️ kelola master)</td>
    </tr>
</table>

<?php if (empty($allMasters)): ?>
    <div class="empty">
        <div style="font-size:28px;color:#cbd5e1;margin-bottom:8px;">📂</div>
        belum ada activity master. silakan tambah melalui tombol ⚙️ kelola master di halaman manager → activities.
    </div>
<?php else:
    $curDiv = '';
    foreach ($divisions as $dv):
        $items = [];
        foreach ($allMasters as $m) {
            if ((string)($m['division'] ?? 'operation') === $dv) $items[] = $m;
        }
        if (empty($items)) continue;
        $cnt = count($items);
        $color = $divColorMap[$dv] ?? '#475569';
?>
    <div class="divider">
        <span class="dotdiv"></span>
        <span class="lbl"><?= strtolower($divLabelMap[$dv] ?? $dv) ?></span>
        <span class="cnt"><?= $cnt ?> activities</span>
    </div>

    <table>
        <colgroup>
            <col style="width:46px;">
            <col style="width:40px;">
            <col style="width:46px;">
            <col style="width:90px;">
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
                <th style="width:90px;">Order</th>
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
                if ($sort <= 5) { $priority = 'HIGH'; $prioCls = 'prio-high'; }
                elseif ($sort <= 15) { $priority = 'MEDIUM'; $prioCls = 'prio-med'; }
                else { $priority = 'LOW'; $prioCls = 'prio-low'; }
            ?>
            <tr>
                <td class="num"><?= $localNo ?>.</td>
                <td class="check">☐</td>
                <td class="star">☆</td>
                <td class="center" style="font-weight:800;font-family:Consolas,monospace;font-size:10.5px;color:#475569;">#<?= $sort ?></td>
                <td style="font-weight:600; color:#0f172a; font-size:12px; line-height:1.5;"><?= htmlspecialchars($nm) ?></td>
                <td class="center" style="font-weight:800; font-size:9.5px; letter-spacing:1.2px;" class="<?= $prioCls ?>">
                    <span class="<?= $prioCls ?>"><?= $priority ?></span>
                </td>
                <td class="center">
                    <span class="<?= $st === 'complete' ? 'st-done' : 'st-progress' ?>">
                        <?= $st === 'complete' ? 'done' : 'progress' ?>
                    </span>
                </td>
                <td>
                    <span class="dot-div" style="background:<?= $color ?> !important;"></span>
                    <strong style="font-size:9.5px; letter-spacing:1.5px; color:#475569; text-transform: uppercase;"><?= strtolower($divLabelMap[$dv] ?? $dv) ?></strong>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>
<?php endif; ?>

<div class="footer-note">
    <span class="brand">Engineering <em>Report</em></span>
    &nbsp;•&nbsp; dokumen di-generate otomatis • last updated: <?= date('Y-m-d H:i:s') ?>&nbsp;
    •&nbsp; <span style="color:#64748b;">disediakan tanpa logo & branding hotel (format generic).</span>
</div>
</body>
</html>
<?php
echo ob_get_clean();
exit;
