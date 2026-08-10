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
    @page { size: A4; margin: 12mm 14mm 16mm 14mm; }
    * { -webkit-box-sizing: border-box; box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0;
        background: #ffffff !important;
        color: #0f172a !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        font-feature-settings: "cv11", "ss01";
    }
    .wrap {
        width: 100%;
        min-height: 100vh;
        background: #ffffff !important;
        padding: 6px 0 18px 0;
        position: relative;
    }

    /* ====== META TOP BAR (super slim) ====== */
    .meta-top {
        display: flex; align-items: center; justify-content: space-between;
        padding-bottom: 12px; margin-bottom: 18px;
        border-bottom: 1px solid #e2e8f0;
        color: #64748b; font-size: 10px;
        letter-spacing: 0.6px; font-weight: 600;
        text-transform: uppercase;
    }
    .meta-top .lft { display: flex; align-items: center; gap: 10px; }
    .meta-top .dot { width:6px; height:6px; border-radius:50%; background:#94a3b8; display:inline-block;}
    .meta-top .rgt { text-align: right; }

    /* ====== HEADER LISTS SIMPLE (NO HERO, NO NAVBAR) ====== */
    .head-block {
        margin-bottom: 28px;
    }
    .eyebrow {
        display: inline-flex; align-items: center; gap: 10px;
        color: #475569; font-size: 10px; font-weight: 800;
        letter-spacing: 2.5px; text-transform: uppercase;
        padding: 0 0 8px 0;
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
    .title-hero {
        color: #0f172a;
        font-weight: 900;
        letter-spacing: -0.02em;
        line-height: 1.1;
        font-size: 40px;
        margin: 0 0 10px 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    .title-hero .accent {
        color: #0ea5e9;
    }
    .subtitle-hero {
        color: #64748b;
        font-size: 12.5px; font-weight: 500;
        letter-spacing: 0.2px;
        margin: 0 0 0 0;
        line-height: 1.5;
    }
    .summary-row {
        display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap;
    }
    .chip {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 5px 11px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        color: #475569;
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: 0.2px;
        box-shadow: 0 1px 2px rgba(15,23,42,0.04);
    }
    .chip strong { color: #0f172a; font-weight: 800; }
    .chip .icn { color: #94a3b8; font-size: 10px; }

    /* ====== SECTION DIVIDER SLIM ====== */
    .divider-section {
        display: flex; align-items: center; gap: 10px;
        margin: 28px 0 12px 0;
        padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .divider-section .lbl {
        color: #334155;
        font-size: 11px; font-weight: 800;
        letter-spacing: 2px;
        text-transform: uppercase;
    }
    .divider-section .dotdiv {
        width: 8px; height: 8px; border-radius: 50%;
        background: #cbd5e1;
        box-shadow: inset 0 0 0 2px #fff, 0 0 0 1px #e2e8f0;
    }
    .divider-section .cnt {
        color: #94a3b8; font-size: 10px; font-weight: 600; letter-spacing: 0.5px;
        margin-left: auto;
    }

    /* ====== ITEM LIST (PURE WHITE + SLATE BORDER, NO BG COLOR) ====== */
    .items { display: flex; flex-direction: column; gap: 9px; }
    .item {
        background: #ffffff !important;
        color: #0f172a !important;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        padding: 14px 16px;
        display: flex; align-items: flex-start; gap: 14px;
        page-break-inside: avoid;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
        transition: border-color .15s ease;
    }
    .item:hover { border-color: #cbd5e1; }

    .radio {
        width: 24px; height: 24px;
        border-radius: 50%;
        border: 2px solid #cbd5e1 !important;
        background: #ffffff !important;
        flex-shrink: 0;
        margin-top: 2px;
    }
    .text {
        flex: 1 1 auto;
        min-width: 0;
        color: #0f172a !important;
        line-height: 1.5;
    }
    .text .name {
        font-size: 14.5px;
        font-weight: 600;
        color: #0f172a !important;
        word-wrap: break-word;
        overflow-wrap: break-word;
        letter-spacing: 0.1px;
    }
    .text .meta {
        margin-top: 5px;
        display: inline-flex; align-items: center; gap: 8px;
        flex-wrap: wrap;
    }
    .text .meta .t {
        font-size: 10px;
        font-weight: 600;
        color: #94a3b8;
        letter-spacing: 0.2px;
        text-transform: lowercase;
    }
    .text .meta .t strong {
        font-weight: 700;
        color: #64748b;
    }
    .text .meta .sep {
        width: 3px; height: 3px; border-radius: 50%; background: #e2e8f0; display: inline-block;
    }
    .text .meta .st-badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 9.5px;
        font-weight: 800;
        letter-spacing: 0.3px;
        text-transform: uppercase;
    }
    .text .meta .st-progress {
        color: #92400e;
        background: #fffbeb;
        border: 1px solid #fde68a;
    }
    .text .meta .st-done {
        color: #166534;
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
    }
    .text .meta .st-progress::before { content:""; display:inline-block; width:5px; height:5px; border-radius:50%; background:#f59e0b; }
    .text .meta .st-done::before { content:""; display:inline-block; width:5px; height:5px; border-radius:50%; background:#22c55e; }

    .star {
        flex-shrink: 0;
        padding-top: 2px;
        color: #cbd5e1 !important;
        align-self: flex-start;
    }
    .star i { font-size: 19px; }

    .empty {
        padding: 42px 22px; text-align: center;
        color: #64748b;
        background: #ffffff;
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
        font-size: 12.5px; font-weight: 500;
        letter-spacing: 0.2px;
    }
    .empty .big {
        font-size: 32px; color: #cbd5e1; margin-bottom: 10px;
    }

    /* ====== FOOTER SLIM (NO FLOATING + NO BLUR BG) ====== */
    .foot-note {
        margin-top: 36px;
        padding-top: 14px;
        border-top: 1px dashed #e2e8f0;
        color: #94a3b8; font-size: 9.5px; letter-spacing: 0.5px; font-weight: 500;
        display: flex; align-items: center; justify-content: space-between;
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

    @media print {
        body { background: #fff !important; }
    }
</style>
</head>
<body>
<div class="wrap">

    <!-- ============== META TOP BAR ============== -->
    <div class="meta-top">
        <div class="lft">
            <span class="dot"></span>
            <span>Engineering Report</span>
            <span class="dot"></span>
            <span>lists export</span>
        </div>
        <div class="rgt">
            print • <?= date('d M Y') ?>
        </div>
    </div>

    <!-- ============== HEADER ============== -->
    <div class="head-block">
        <div class="eyebrow">
            <span>Engineering Department</span>
            <span class="pill"><?= htmlspecialchars($monthLabel) ?></span>
        </div>

        <h1 class="title-hero">
            Engineering <span class="accent">Operation</span>
        </h1>
        <p class="subtitle-hero">
            daftar aktivitas master untuk dijalankan tim engineering • dikelompokkan per divisi, urut sesuai priority order number.
        </p>

        <div class="summary-row">
            <div class="chip"><i class="icn far fa-clipboard-list"></i> total item <strong><?= count($printAllActs) ?></strong></div>
            <?php foreach ($divisions as $dv):
                $c = count($mastersByDiv[$dv] ?? []); if ($c <= 0) continue;
            ?>
            <div class="chip"><i class="icn fas fa-layer-group"></i> <?= strtolower($divLabelMap[$dv] ?? $dv) ?> <strong><?= $c ?></strong></div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============== ITEMS ============== -->
    <?php if (empty($printAllActs)): ?>
        <div class="empty">
            <div class="big"><i class="far fa-folder-open"></i></div>
            belum ada activity master untuk periode ini.<br>tambah data melalui tombol ⚙️ kelola master di halaman manager activities.
        </div>
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
                    $cntCur = count($mastersByDiv[$dv] ?? 0);
            ?>
                <div class="divider-section" style="margin-top:<?= $idx === 1 ? '0' : '28' ?>px;">
                    <span class="dotdiv"></span>
                    <span class="lbl"><?= strtolower($divLabelMap[$dv] ?? $dv) ?></span>
                    <span class="cnt"><?= $cntCur ?> activities</span>
                </div>
            <?php endif; ?>
            <div class="item">
                <div class="radio" aria-hidden="true"></div>
                <div class="text">
                    <div class="name"><?= htmlspecialchars($nm) ?></div>
                    <div class="meta">
                        <span class="t">divisi <strong><?= strtolower($dv) ?></strong></span>
                        <span class="sep"></span>
                        <span class="t">order <strong>#<?= $sort ?></strong></span>
                        <span class="sep"></span>
                        <span class="st-badge <?= $st === 'complete' ? 'st-done' : 'st-progress' ?>"><?= $st === 'complete' ? 'done' : 'progress' ?></span>
                    </div>
                </div>
                <div class="star" aria-hidden="true"><i class="far fa-star"></i></div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ============== FOOTER ============== -->
    <div class="foot-note">
        <div class="lft">
            <span class="brand-mark">Engineering <em>Report</em></span>
            <span>•</span>
            <span>disediakan tanpa logo & branding hotel (format generic).</span>
        </div>
        <div class="rgt">
            <span>last updated: <?= date('Y-m-d H:i') ?></span>
        </div>
    </div>

</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="all">
</body>
</html>
<?php
echo ob_get_clean();
exit;
