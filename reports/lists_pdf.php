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
$mastersByDiv = [];
foreach ($divisions as $dv) $mastersByDiv[$dv] = [];
foreach ($allMasters as $m) {
    $dv = (string)($m['division'] ?? 'operation');
    if (!isset($mastersByDiv[$dv])) $mastersByDiv[$dv] = [];
    $mastersByDiv[$dv][] = $m;
}

$printAllActs = [];
foreach ($divisions as $dv) {
    foreach ($mastersByDiv[$dv] ?? [] as $mm) $printAllActs[] = $mm;
}
if (empty($printAllActs)) {
    $printAllActs = array_values($allMasters);
}

$filename = 'Engineering_Lists_' . $monthRaw . '.pdf';
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: max-age=0, must-revalidate');
ob_start();
echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Engineering Operation Lists - Engineering Report</title>
<style>
    @page { size: A4; margin: 0; }
    * { -webkit-box-sizing: border-box; box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0; width: 100%; min-height: 100%;
        background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%) !important;
        color: #ffffff !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .wrap {
        width: 100%;
        min-height: 100vh;
        padding: 28px 30px 110px 30px;
        background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%) !important;
        position: relative;
    }
    .hdr {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 26px; color: #ffffff;
    }
    .hdr .left { display: flex; align-items: center; gap: 18px; }
    .hdr .left .chev { width: 34px; height: 34px; display:flex; align-items:center; justify-content:center; color: #fff; }
    .hdr .left .chev i { font-size: 20px; }
    .hdr .left .lbl { color: #ffffff; font-size: 16px; font-weight: 600; opacity: 0.92; letter-spacing: 0.5px; }
    .hdr .right { display: flex; align-items: center; gap: 14px; }
    .hdr .right .users { display: inline-flex; align-items: center; gap: 7px; color: #fff; opacity: 0.95; }
    .hdr .right .users i { font-size: 16px; }
    .hdr .right .users span { font-size: 15px; font-weight: 600; }
    .hdr .right .dots { color: #ffffff; opacity: 0.9; }
    .hdr .right .dots i { font-size: 20px; }

    .tag {
        display: inline-block; padding: 5px 12px;
        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.22);
        border-radius: 999px;
        color: #ffffff;
        font-size: 12px; font-weight: 700;
        letter-spacing: 1.5px;
        margin-bottom: 16px;
    }
    .title-hero {
        color: #ffffff;
        font-weight: 900;
        letter-spacing: -0.025em;
        line-height: 1.05;
        font-size: 44px;
        margin: 0 0 10px 0;
    }
    .subtitle-hero {
        color: rgba(255,255,255,0.88);
        font-size: 14px; font-weight: 500;
        letter-spacing: 0.5px;
        margin: 0 0 30px 0;
    }

    .items { display: flex; flex-direction: column; gap: 12px; }
    .item {
        background: #ffffff !important;
        color: #0f172a !important;
        border-radius: 14px;
        padding: 16px 18px;
        box-shadow: 0 6px 18px rgba(0,0,0,0.12);
        display: flex; align-items: flex-start; gap: 16px;
        page-break-inside: avoid;
    }
    .radio {
        width: 28px; height: 28px;
        border-radius: 50%;
        border: 3px solid #94a3b8 !important;
        background: #ffffff !important;
        flex-shrink: 0;
        margin-top: 3px;
    }
    .text {
        flex: 1 1 auto;
        min-width: 0;
        color: #0f172a !important;
        line-height: 1.4;
    }
    .text .name {
        font-size: 17px;
        font-weight: 600;
        color: #0f172a !important;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    .text .meta {
        margin-top: 6px;
        font-size: 12px;
        font-weight: 500;
        color: #64748b;
        letter-spacing: 0.4px;
    }
    .star {
        flex-shrink: 0;
        padding-top: 3px;
        color: #94a3b8 !important;
        align-self: flex-start;
    }
    .star i { font-size: 22px; }

    .empty {
        padding: 40px 20px; text-align: center;
        color: rgba(255,255,255,0.85);
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 14px;
        font-size: 15px; font-weight: 500;
    }

    .footer-float {
        position: fixed;
        left: 0; right: 0; bottom: 0;
        background: linear-gradient(180deg, rgba(30,64,175,0.0) 0%, rgba(30,64,175,0.98) 35%, #1e3a8a 100%);
        padding: 50px 30px 26px 30px;
        z-index: 10;
    }
    .footer-float .add {
        background: rgba(255,255,255,0.12) !important;
        border: 1px solid rgba(255,255,255,0.2) !important;
        border-radius: 18px;
        padding: 16px 22px;
        display: flex; align-items: center; gap: 18px;
        backdrop-filter: blur(6px);
    }
    .footer-float .plus {
        width: 34px; height: 34px;
        border-radius: 50%;
        background: rgba(255,255,255,0.22) !important;
        color: #ffffff !important;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .footer-float .plus i { font-size: 18px; }
    .footer-float .txt {
        color: #ffffff !important;
        font-size: 19px; font-weight: 600; letter-spacing: 0.6px;
    }
    .meta-foot {
        position: fixed; left: 30px; right: 30px; top: 10px;
        display: flex; justify-content: space-between; align-items: center;
        color: rgba(255,255,255,0.55); font-size: 10px; letter-spacing: 1px; font-weight: 600;
        z-index: 11;
    }
    .divider-section {
        margin: 24px 0 14px 0;
        color: rgba(255,255,255,0.85);
        font-size: 11px; font-weight: 800; letter-spacing: 2.5px;
        text-transform: uppercase;
        padding-bottom: 6px;
        border-bottom: 1px solid rgba(255,255,255,0.18);
    }
    .badge-div {
        display: inline-block; padding: 2px 8px; border-radius: 999px;
        background: rgba(255,255,255,0.15);
        color: #fff; font-size: 10px; font-weight: 700;
        letter-spacing: 1.5px; margin-left: 8px;
    }
</style>
</head>
<body>
<div class="meta-foot">
    <div>ENGINEERING REPORT • LISTS</div>
    <div>EXPORT: <?= date('Y-m-d H:i') ?></div>
</div>
<div class="wrap">
    <div class="hdr">
        <div class="left">
            <div class="chev"><i class="fas fa-chevron-left" aria-hidden="true"></i></div>
            <div class="lbl">Lists <span class="badge-div"><?= $monthLabel ?></span></div>
        </div>
        <div class="right">
            <div class="users"><i class="far fa-user" aria-hidden="true"></i><span><?= count($printAllActs) ?></span></div>
            <div class="dots"><i class="fas fa-ellipsis-h" aria-hidden="true"></i></div>
        </div>
    </div>

    <div class="tag">ENGINEERING DEPARTMENT</div>
    <h1 class="title-hero">Engineering Operation</h1>
    <div class="subtitle-hero">Daftar aktivitas master untuk dijalankan tim engineering • Total <?= count($printAllActs) ?> item</div>

    <?php if (empty($printAllActs)): ?>
        <div class="empty">Belum ada activity master. Silakan tambah melalui tombol ⚙️ Kelola Master di halaman Manager Activities.</div>
    <?php else: ?>
        <div class="items">
            <?php
            $curDiv = '';
            $idx = 0;
            foreach ($printAllActs as $actRow):
                $idx++;
                $nm = trim((string)($actRow['activity_name'] ?? ''));
                if ($nm === '') continue;
                $dv = (string)($actRow['division'] ?? 'operation');
                $sort = (int)($actRow['sort_order'] ?? 0);
                $st = strtolower((string)($actRow['status_default'] ?? 'progress'));
                if ($dv !== $curDiv && $division === 'all'):
                    $curDiv = $dv;
            ?>
                <div class="divider-section" style="margin-top:<?= $idx === 1 ? '0' : '24' ?>px;">
                    <?= $divLabelMap[$dv] ?? strtoupper($dv) ?>
                </div>
            <?php endif; ?>
            <div class="item">
                <div class="radio" aria-hidden="true"></div>
                <div class="text">
                    <div class="name"><?= htmlspecialchars($nm) ?></div>
                    <div class="meta">
                        Div. <?= strtoupper($dv) ?> • Order #<?= $sort ?> • Status: <?= $st === 'complete' ? 'Done' : 'Progress' ?>
                    </div>
                </div>
                <div class="star" aria-hidden="true"><i class="far fa-star"></i></div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<div class="footer-float">
    <div class="add">
        <div class="plus"><i class="fas fa-plus" aria-hidden="true"></i></div>
        <div class="txt">Add a Task</div>
    </div>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="all">
<script>
    // Auto suggest print on load
    window.addEventListener('load', function () {
        try {
            setTimeout(function () {
                if (window.matchMedia && window.matchMedia('print').matches === false) {
                    // Optional: uncomment next line to auto-open print dialog
                    // window.print();
                }
            }, 450);
        } catch (e) {}
    });
</script>
</body>
</html>
<?php
echo ob_get_clean();
exit;
