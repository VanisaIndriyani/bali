<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = T('eng_act_page_title', 'Engineering Activities');
requireRole(['admin', 'manager', 'supervisor']);
$page = 'engineering_activities';

$db = Database::getInstance();
$user = currentUser();
$userName = (string)($user['name']  ?? 'Manager');
$userId = (int)($user['id']    ?? 0);

$monthStart = date('Y-m-01');
$today = date('Y-m-d');

// 🔧 AUTO ALTER TABLE TAMBAH 4 KOLOM BARU UNTUK LIST ACTIVITY MANUAL PER DIVISI (JIKA BELUM ADA)
try {
    $chkAct = $db->fetchOne("SHOW COLUMNS FROM daily_logs LIKE 'activity_operation_items'");
    if (!$chkAct) {
        $db->query("ALTER TABLE daily_logs ADD COLUMN activity_operation_items TEXT NULL AFTER activity_operation");
        $db->query("ALTER TABLE daily_logs ADD COLUMN activity_maintenance_items TEXT NULL AFTER activity_maintenance");
        $db->query("ALTER TABLE daily_logs ADD COLUMN activity_project_items TEXT NULL AFTER activity_project");
        $db->query("ALTER TABLE daily_logs ADD COLUMN activity_landscape_items TEXT NULL AFTER activity_landscape");
    }
} catch (Throwable $_) {}

// 🔧 AUTO CREATE TABLE activity_masters UNTUK CRUD MASTER DAFTAR AKTIVITAS PER DIVISI (JIKA BELUM ADA)
try {
    $chkTbl = $db->fetchOne("SHOW TABLES LIKE 'activity_masters'");
    if (!$chkTbl) {
        $db->query("CREATE TABLE activity_masters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            division ENUM('operation','maintenance','project','landscape') NOT NULL,
            activity_name VARCHAR(255) NOT NULL,
            status_default ENUM('complete','progress') DEFAULT 'progress',
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_division (division)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $chkCol = $db->fetchOne("SHOW COLUMNS FROM activity_masters LIKE 'sort_order'");
        if (!$chkCol) $db->query("ALTER TABLE activity_masters ADD COLUMN sort_order INT DEFAULT 0 AFTER status_default");
    }
} catch (Throwable $_) {}

// ================================ POST HANDLER: CRUD MASTER ACTIVITY ================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');

    // ✅ SAVE MASTER (Create / Edit)
    if ($act === 'save_master_activity') {
        $masterId  = max(0, (int)($_POST['master_id'] ?? 0));
        $div       = in_array(($_POST['division'] ?? ''), ['operation','maintenance','project','landscape']) ? (string)$_POST['division'] : '';
        $name      = trim((string)($_POST['activity_name'] ?? ''));
        $statusDef = in_array(($_POST['status_default'] ?? ''), ['complete','progress']) ? (string)$_POST['status_default'] : 'progress';
        $sortOrder = max(0, (int)($_POST['sort_order'] ?? 0));
        if ($div === '' || $name === '') {
            setFlash('danger', 'Divisi dan Nama Activity wajib diisi');
            redirect('manager/activities.php');
        }
        try {
            if ($masterId > 0) {
                $db->update('activity_masters', [
                    'division' => $div,
                    'activity_name' => $name,
                    'status_default' => $statusDef,
                    'sort_order' => $sortOrder,
                ], 'id = :id', ['id' => $masterId]);
                setFlash('success', '✅ Master Activity berhasil di-UPDATE: '.$name);
            } else {
                $db->insert('activity_masters', [
                    'division' => $div,
                    'activity_name' => $name,
                    'status_default' => $statusDef,
                    'sort_order' => $sortOrder,
                ]);
                setFlash('success', '✅ Master Activity berhasil di-TAMBAH: '.$name);
            }
        } catch (Throwable $e) {
            setFlash('danger', 'ERROR simpan master: '.$e->getMessage());
        }
        redirect('manager/activities.php');
    }

    // ❌ DELETE MASTER by ID
    if ($act === 'delete_master_activity') {
        $masterId = max(0, (int)($_POST['master_id'] ?? 0));
        if ($masterId <= 0) {
            setFlash('danger', 'ID Master tidak valid');
            redirect('manager/activities.php');
        }
        try {
            $row = $db->fetchOne("SELECT activity_name FROM activity_masters WHERE id = ? LIMIT 1", [$masterId]);
            $db->query("DELETE FROM activity_masters WHERE id = ? LIMIT 1", [$masterId]);
            setFlash('success', '🗑️ Master Activity berhasil di-HAPUS'.($row ? ': '.$row['activity_name'] : ''));
        } catch (Throwable $e) {
            setFlash('danger', 'ERROR hapus master: '.$e->getMessage());
        }
        redirect('manager/activities.php');
    }
}

// ✅ FETCH SEMUA MASTER ACTIVITY PER DIVISI (untuk Modal CRUD & Dropdown Form Input)
$allMasters = $db->fetchAll("SELECT * FROM activity_masters ORDER BY FIELD(division,'operation','maintenance','project','landscape'), sort_order ASC, id ASC");
$mastersByDiv = [
    'operation'   => [],
    'maintenance' => [],
    'project'     => [],
    'landscape'   => [],
];
foreach ($allMasters as $m) {
    $d = $m['division'] ?? '';
    if (isset($mastersByDiv[$d])) $mastersByDiv[$d][] = $m;
}
$divCodeMap = [
    'operation'   => 'op',
    'maintenance' => 'mt',
    'project'     => 'pr',
    'landscape'   => 'la',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_activity_counters') {
    $logDate = !empty($_POST['log_date']) ? (string)$_POST['log_date'] : $today;
    $engId = (int)($_POST['engineer_id'] ?? 0);
    $actOp = max(0, (int)($_POST['activity_operation'] ?? 0));
    $actMt = max(0, (int)($_POST['activity_maintenance'] ?? 0));
    $actPr = max(0, (int)($_POST['activity_project'] ?? 0));
    $actLa = max(0, (int)($_POST['activity_landscape'] ?? 0));
    $ok = [];

    // ✨ HELPER: Parse Activity Items (Text + Status + MasterID) dari POST Array → return JSON or NULL
    // Format value dropdown: "Nama Activity|masterId" (split by | LIMIT 2)
    $fnParseItems = function ($keyText, $keyStatus) {
        $texts = $_POST[$keyText] ?? [];
        $statuses = $_POST[$keyStatus] ?? [];
        if (!is_array($texts) || count($texts) === 0) return null;
        $items = [];
        foreach ($texts as $i => $t) {
            $raw = trim((string)$t);
            if ($raw === '') continue;
            $mid = 0;
            $realText = $raw;
            if (strpos($raw, '|') !== false) {
                $parts = explode('|', $raw, 2);
                $realText = trim($parts[0] ?? '');
                $mid = max(0, (int)($parts[1] ?? 0));
            }
            if ($realText === '') continue;
            $s = in_array(($statuses[$i] ?? ''), ['complete', 'progress']) ? (string)$statuses[$i] : 'progress';
            $entry = ['t' => $realText, 's' => $s];
            if ($mid > 0) $entry['mid'] = $mid;
            $items[] = $entry;
        }
        return count($items) > 0 ? json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    };
    $itemsOpJson = $fnParseItems('act_op_text', 'act_op_status');
    $itemsMtJson = $fnParseItems('act_mt_text', 'act_mt_status');
    $itemsPrJson = $fnParseItems('act_pr_text', 'act_pr_status');
    $itemsLaJson = $fnParseItems('act_la_text', 'act_la_status');

    if (!preg_match('/^20\d{2}-\d{2}-\d{2}$/', $logDate)) {
        setFlash('danger', 'Format tanggal salah');
        redirect('manager/activities.php');
    }
    if ($engId <= 0) {
        setFlash('danger', 'Pilih Engineer terlebih dahulu');
        redirect('manager/activities.php');
    }

    $engRow = $db->fetchOne("SELECT id, name FROM users WHERE id = ? AND LOWER(role) = 'engineer' LIMIT 1", [$engId]);
    if (!$engRow) {
        setFlash('danger', 'Engineer tidak ditemukan');
        redirect('manager/activities.php');
    }
    try {
        $exist = $db->fetchOne("SELECT id FROM daily_logs WHERE engineer_id = ? AND log_date = ? LIMIT 1", [$engId, $logDate]);
        if ($exist) {
            $dataUpdate = [
                'activity_operation' => $actOp,
                'activity_maintenance' => $actMt,
                'activity_project' => $actPr,
                'activity_landscape' => $actLa,
                'activity_operation_items' => $itemsOpJson,
                'activity_maintenance_items' => $itemsMtJson,
                'activity_project_items' => $itemsPrJson,
                'activity_landscape_items' => $itemsLaJson,
                'status' => 'approved',
                'revision_notes' => null,
                'supervisor_id' => null,
                'supervisor_signature' => null,
                'approved_at' => date('Y-m-d H:i:s'),
            ];
            $db->update('daily_logs', $dataUpdate, 'id = :id', ['id' => (int)$exist['id']]);
            setFlash('success', 'Counters Activity berhasil di-update untuk '.$engRow['name'].' ('.$logDate.') ID: '.$exist['id']);
        } else {
            $dataInsert = [
                'log_date' => $logDate,
                'engineer_id' => $engId,
                'activity_operation' => $actOp,
                'activity_maintenance' => $actMt,
                'activity_project' => $actPr,
                'activity_landscape' => $actLa,
                'activity_operation_items' => $itemsOpJson,
                'activity_maintenance_items' => $itemsMtJson,
                'activity_project_items' => $itemsPrJson,
                'activity_landscape_items' => $itemsLaJson,
                'status' => 'approved',
                'approved_at' => date('Y-m-d H:i:s'),
                // Standard fields (isi default biar GA ERROR karena NOT NULL)
                'total_electricity' => 0,
                'total_water' => 0,
                'total_gas' => 0,
                'electricity_wbp' => 0,
                'electricity_lwbp' => 0,
                'water_pdam' => 0,
                'water_iki_gaban' => 0,
                'water_deepwell_1' => 0,
                'water_deepwell_2_brr' => 0,
                'water_deepwell_asean' => 0,
                'water_deepwell_lpb' => 0,
                'water_main_building' => 0,
                'water_cooling_tower' => 0,
                'water_bottling' => 0,
                'gas_lpg' => 0,
                'gas_lng' => 0,
                'swro_watermeter' => 0,
                'swro_kwh' => 0,
                'swro_tds' => 0,
                'bottling_kwh' => 0,
                'bottling_watermeter' => 0,
                'chiller_1_on' => 0,
                'chiller_2_on' => 0,
                'chiller_3_on' => 0,
                'chiller_water_ph' => 0,
                'chiller_water_tds' => 0,
                'chiller_temp' => 0,
                'chiller_pressure_chwp' => 0,
                'chiller_pressure_cwp' => 0,
                'total_fuel' => 0,
                'occ_rate' => 0,
                'work_activities' => 'Diisi langsung oleh Manager - Approved Otomatis',
                'obstacles' => '',
                'solutions' => '',
            ];
            $db->insert('daily_logs', $dataInsert);
            $newId = (int)$db->getConnection()->lastInsertId();
            setFlash('success', 'Counters Activity berhasil disimpan baru untuk '.$engRow['name'].' ('.$logDate.') ID: '.$newId);
        }
    } catch (Throwable $e) {
        setFlash('danger', 'Gagal menyimpan: '.$e->getMessage());
    }
    redirect('manager/activities.php');
}

$engineers = $db->fetchAll("SELECT id, name, role FROM users WHERE status = 'active' OR status IS NULL OR status = '' ORDER BY name ASC");

function buildActCnt($db, $from, $to, $cat, $userId, $userRole) {
    $col = 'activity_operation';
    if ($cat === 'operation') $col = 'activity_operation';
    elseif ($cat === 'maintenance') $col = 'activity_maintenance';
    elseif ($cat === 'project') $col = 'activity_project';
    elseif ($cat === 'landscape') $col = 'activity_landscape';
    $where = "WHERE log_date BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($userRole === 'engineer') {
        $where .= " AND engineer_id = ?";
        $params[] = $userId;
    }
    $row = $db->fetchOne("SELECT COUNT(*) AS total_rows, COALESCE(SUM({$col}),0) AS total_sum FROM daily_logs {$where}", $params);
    $cntAct = 0;
    $listRows = $db->fetchAll("SELECT engineer_id, log_date, {$col}, id FROM daily_logs {$where} ORDER BY log_date DESC, id DESC", $params);
    foreach ($listRows as $lr) {
        $v = (int)($lr[$col] ?? 0);
        if ($v > 0) $cntAct++;
    }
    return ['count' => $cntAct, 'sum' => (float)($row['total_sum'] ?? 0)];
}

// ✨ HELPER: Render Activity Items (JSON Array of [{t:...,s:...}]) → HTML Bullet Badge Status
function renderActItems($jsonItems, $maxItems = null) {
    if (empty($jsonItems)) return '';
    $arr = is_string($jsonItems) ? json_decode($jsonItems, true) : $jsonItems;
    if (!is_array($arr) || count($arr) === 0) return '';
    $out = [];
    $items = $maxItems ? array_slice($arr, 0, $maxItems) : $arr;
    foreach ($items as $it) {
        if (!is_array($it) || empty($it['t'])) continue;
        $text = htmlspecialchars(trim((string)$it['t']));
        $status = ($it['s'] ?? 'progress') === 'complete' ? 'complete' : 'progress';
        if ($status === 'complete') {
            $badge = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-black text-emerald-700 bg-emerald-50 border border-emerald-200 ml-1.5 whitespace-nowrap shrink-0"><i class="fas fa-check-circle text-[9px]"></i> Complete</span>';
        } else {
            $badge = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-black text-amber-700 bg-amber-50 border border-amber-200 ml-1.5 whitespace-nowrap shrink-0"><i class="fas fa-spinner fa-spin text-[9px]"></i> In Progress</span>';
        }
        $out[] = '<div class="flex items-start gap-1 text-xs text-primary leading-relaxed"><span class="text-slate-400 font-black shrink-0 mt-0.5">•</span><span class="flex-1 min-w-0 break-words">'.$text.'</span>'.$badge.'</div>';
    }
    if ($maxItems && count($arr) > $maxItems) {
        $out[] = '<div class="text-[10px] font-bold text-slate-500 italic pl-3">+ '.(count($arr) - $maxItems).' item lainnya (klik detail untuk lihat lengkapnya)</div>';
    }
    return count($out) > 0 ? '<div class="space-y-1.5 pt-2">'.implode('', $out).'</div>' : '';
}

$cats = [
    [
        'id'    => 'operation',
        'label' => T('dash_act_operation', 'OPERATION'),
        'icon'  => 'fas fa-gears',
        'iconBox' => 'from-blue-400 to-blue-600',
        'ring' => 'ring-slate-200',
        'bg' => 'bg-slate-100',
        'border' => 'border-slate-300',
        'color' => 'text-slate-700'
    ],
    [
        'id'    => 'maintenance',
        'label' => T('dash_act_maintenance', 'MAINTENANCE'),
        'icon'  => 'fas fa-wrench',
        'iconBox' => 'from-emerald-400 to-emerald-600',
        'ring' => 'ring-slate-200',
        'bg' => 'bg-slate-100',
        'border' => 'border-slate-300',
        'color' => 'text-slate-700'
    ],
    [
        'id'    => 'project',
        'label' => T('dash_act_project', 'PROJECT'),
        'icon'  => 'fas fa-diagram-project',
        'iconBox' => 'from-violet-400 to-violet-600',
        'ring' => 'ring-slate-200',
        'bg' => 'bg-slate-100',
        'border' => 'border-slate-300',
        'color' => 'text-slate-700'
    ],
    [
        'id'    => 'landscape',
        'label' => T('dash_act_landscape', 'LANDSCAPE'),
        'icon'  => 'fas fa-leaf',
        'iconBox' => 'from-teal-400 to-teal-600',
        'ring' => 'ring-slate-200',
        'bg' => 'bg-slate-100',
        'border' => 'border-slate-300',
        'color' => 'text-slate-700'
    ],
];
$catsData = [];
$actMonthLabel = T('dash_act_bulan_ini', 'aktivitas bulan ini');
$actTodayLabel = T('dash_act_today', 'aktivitas hari ini');
$actEmpty = T('dash_act_kosong', 'Belum ada aktivitas bulan ini.');
$blankIcon = '<i class="far fa-folder-open opacity-60 mr-1"></i>';
$deptLabel = T('dash_act_department', 'DEPARTMENT');
$actDetailLabel = T('dash_act_activity_detail', 'ACTIVITY DETAIL');
$preparedLabel = T('dash_act_prepared', 'PREPARED BY:');
$reviewedLabel = T('dash_act_reviewed', 'REVIEWED BY:');
$preparedName = T('dash_act_prepared_name', 'Engineering Staff');
$reviewedName = T('dash_act_reviewed_name', 'Supervisor Engineering');

$colLeft = [];
$colRight = [];
$catDetailRows = [];
// 📦 BARU: Simpan activity_masters per divisi + preview items master untuk dipakai render card dan modal nanti
$masterRowsPerDiv = ['operation'=>[], 'maintenance'=>[], 'project'=>[], 'landscape'=>[]];
$masterPreviewPerDiv = ['operation'=>[], 'maintenance'=>[], 'project'=>[], 'landscape'=>[]];
$masterCntPerDiv = ['operation'=>0, 'maintenance'=>0, 'project'=>0, 'landscape'=>0];
try {
    $_mastersAll = $db->fetchAll("SELECT id, division, activity_name, sort_order, status_default, created_at FROM activity_masters ORDER BY FIELD(division,'operation','maintenance','project','landscape'), sort_order ASC, id ASC");
    foreach ($_mastersAll as $_m) {
        $dv = (string)($_m['division'] ?? 'operation');
        if (!in_array($dv, ['operation','maintenance','project','landscape'], true)) $dv = 'operation';
        $ttl = trim((string)($_m['activity_name'] ?? ''));
        if ($ttl === '') continue;
        $st = (strtolower((string)($_m['status_default'] ?? 'progress')) === 'complete') ? 'complete' : 'progress';
        $masterRowsPerDiv[$dv][] = $_m;
        if (count($masterPreviewPerDiv[$dv]) < 3) {
            $masterPreviewPerDiv[$dv][] = ['t'=>$ttl, 's'=>$st, '__from_master'=>true];
        }
        $masterCntPerDiv[$dv]++;
    }
    unset($_mastersAll, $_m, $dv, $ttl, $st);
} catch (Throwable $e) {}

foreach ($cats as $c) {
    $todayCnt = buildActCnt($db, $today, $today, $c['id'], $userId, 'manager');
    $monthCnt = buildActCnt($db, $monthStart, $today, $c['id'], $userId, 'manager');
    $cnt = (int)($monthCnt['count'] ?? 0);
    $todayN = (int)($todayCnt['count'] ?? 0);
    $col = match($c['id']) {
        'operation'   => 'activity_operation',
        'maintenance' => 'activity_maintenance',
        'project'     => 'activity_project',
        'landscape'   => 'activity_landscape',
        default       => 'activity_operation'
    };
    $catDetailRows[$c['id']] = $db->fetchAll(
        "SELECT dl.id, dl.log_date, dl.{$col} AS cnt, COALESCE(u.name, '-') AS engineer_name,
                dl.activity_operation_items, dl.activity_maintenance_items, dl.activity_project_items, dl.activity_landscape_items
         FROM daily_logs dl
         LEFT JOIN users u ON u.id = dl.engineer_id
         WHERE dl.log_date BETWEEN ? AND ? AND dl.{$col} > 0
         ORDER BY dl.log_date DESC, dl.id DESC",
        [$monthStart, $today]
    );
    // ============== ✨ TAMBAHAN: MASUKKAN DATA MASTER KE COUNT & PREVIEW ✨ ==============
    $div = $c['id'];
    $hasDaily = (is_array($catDetailRows[$div]) && count($catDetailRows[$div]) > 0) || ($cnt > 0);
    $mCnt = (int)($masterCntPerDiv[$div] ?? 0);
    $totalMasterShown = 0;
    if ($mCnt > 0) {
        $totalMasterShown = $mCnt;
        // JIKA TIDAK ADA daily log sama sekali: count bulan ini diambil dari master count (agar tidak muncul 0)
        if (!$hasDaily) $cnt += $mCnt;
    }
    $colLeft[] = $c;
    if ($cnt > 0 || $totalMasterShown > 0) {
        $badgeMaster = '';
        if ($totalMasterShown > 0 && !$hasDaily) {
            $badgeMaster = ' <span class="ml-2 inline-flex items-center gap-1 text-[10px] font-extrabold uppercase tracking-wider text-sky-700 px-2.5 py-0.5 rounded-full bg-sky-50 border border-sky-200 shadow-xs"><i class="fas fa-database text-[10px]"></i> MASTER TEMPLATE</span>';
        } elseif ($totalMasterShown > 0) {
            $badgeMaster = ' <span class="ml-1 inline-flex items-center gap-1 text-[9px] font-extrabold uppercase tracking-wider text-slate-500 px-2 py-0.5 rounded-full bg-white/60 border border-slate-200">+'.$totalMasterShown.' Master</span>';
        }
        $txtCount = '<div class="flex items-center gap-2 text-sm font-bold '.$c['color'].'"><i class="far fa-calendar-check"></i> <span class="font-black">'.$cnt.'</span> '.$actMonthLabel.($todayN > 0 ? ' <span class="text-secondary/70 font-medium text-xs ml-1">(+'.$todayN.' '.$actTodayLabel.')</span>' : '').$badgeMaster.'</div>';
        $colRight[] = $txtCount;
    } else {
        $colRight[] = '<div class="flex items-center gap-2 text-sm text-secondary/70 font-medium">'.$blankIcon.$actEmpty.'</div>';
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

// ✨ PRINT SIMPLE LISTS STYLE DATA
$printAllActs = [];
foreach (['operation','maintenance','project','landscape'] as $dv) {
    foreach ($mastersByDiv[$dv] ?? [] as $mm) $printAllActs[] = $mm;
}
if (empty($printAllActs)) {
    $printAllActs = array_values($allMasters);
}

// ===================================================
// 🖨️ CSS PRINT GLOBAL (HARUS DI ATAS, URUTAN BENAR: DEFAULT MODE WEB DULU → BARU @media PRINT)
// ===================================================
?>
<style>
/* ✅ DEFAULT MODE WEB: Hide area print */
.print-only-area { display: none !important; visibility: hidden !important; }

/* ✅ MODE PRINT: Override BALIK & Hide SEMUA ELEMEN LAIN (PASTIKAN TIDAK ADA NAMA HOTEL / SIDEBAR / NAVBAR MASUK) */
@media print {
    @page {
        size: A4;
        margin: 0 !important;
    }
    html, body {
        background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%) !important;
        margin: 0 !important;
        padding: 0 !important;
        color: #fff !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        -webkit-filter: opacity(1) !important;
    }
    body > *:not(.print-only-area) { display: none !important; visibility: hidden !important; }
    .sidebar, .sidebar-brand, .mobile-topbar, .main-wrapper, .page-shell, .page-header, .card-premium, .modal, .toast-container, .flash-container,
    script, style:not(.keep-in-print), #sidebarBackdrop, #sidebarToggle, #sidebarToggleMobile, #sidebarOpenBtn,
    nav, .bg-surface, .border-b, .top-navbar, .navbar, [class*="brand"]:not(.print-only-brand) {
        display: none !important;
        visibility: hidden !important;
    }
    /* ✨ FORCE SHOW PRINT AREA — PRIORITAS TERTINGGI */
    body > .print-only-area,
    .print-only-area,
    .print-only-area * {
        display: block !important;
        visibility: visible !important;
        box-sizing: border-box !important;
    }
    .print-only-area {
        position: relative !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        min-height: 100vh !important;
        background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%) !important;
        padding: 24px 18px 120px 18px !important;
        page-break-after: avoid;
    }
    .print-only-area .flex  { display: flex !important; }
    .print-only-area .inline-flex { display: inline-flex !important; }
    .print-only-area .items-center { align-items: center !important; }
    .print-only-area .justify-between { justify-content: space-between !important; }
    .print-only-area .flex-col { flex-direction: column !important; }
    .print-only-area .shrink-0 { flex-shrink: 0 !important; }
    .print-only-area .flex-1 { flex: 1 1 0% !important; }
    .print-only-area .min-w-0 { min-width: 0 !important; }
    .print-only-area .break-words { word-wrap: break-word !important; overflow-wrap: break-word !important; }
    .print-only-area .leading-none { line-height: 1 !important; }
    .print-only-area .leading-snug { line-height: 1.375 !important; }
    .print-only-area .gap-3 { gap: 0.75rem !important; }
    .print-only-area .gap-3\.5 { gap: 0.875rem !important; }
    .print-only-area .gap-4 { gap: 1rem !important; }
    .print-only-area .mb-6 { margin-bottom: 1.5rem !important; }
    .print-only-area .mb-7 { margin-bottom: 1.75rem !important; }
    .print-only-area .px-4 { padding-left: 1rem !important; padding-right: 1rem !important; }
    .print-only-area .py-3\.5 { padding-top: 0.875rem !important; padding-bottom: 0.875rem !important; }
    .print-only-area .pb-6 { padding-bottom: 1.5rem !important; }
    .print-only-area .w-7 { width: 1.75rem !important; }
    .print-only-area .h-7 { height: 1.75rem !important; }
    .print-only-area .w-8 { width: 2rem !important; }
    .print-only-area .h-8 { height: 2rem !important; }
    .print-only-area .rounded-full { border-radius: 9999px !important; }
    .print-only-area .font-black { font-weight: 900 !important; }
    .print-only-area .font-semibold { font-weight: 600 !important; }
    .print-only-area .tracking-tight { letter-spacing: -0.025em !important; }
    .print-only-area .tracking-wide { letter-spacing: 0.025em !important; }
    .print-only-area .opacity-90 { opacity: 0.9 !important; }
    .print-only-area .opacity-95 { opacity: 0.95 !important; }
    .print-only-area .text-white { color: #fff !important; }
    .print-only-area .text-slate-400 { color: #94a3b8 !important; }
    .print-only-area i, .print-only-area svg { display: inline-block !important; }
    .print-only-area .min-h-screen { min-height: 100vh !important; }
    .print-only-area .rounded-\[14px\] { border-radius: 14px !important; }
    .print-only-area .shadow-lg { box-shadow: 0 4px 12px rgba(0,0,0,0.08) !important; }
}
</style>
<?php
// ===================================================
// 🖨️ PRINT ONLY AREA (TAMPIL HANYA SAAT PRINT)
// ===================================================
?>
<div class="print-only-area">
    <div class="min-h-screen" style="background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%); color:#fff;">
        <!-- 🔝 HEADER BAR PERSIS MICROSOFT LISTS (MOBILE STYLE) -->
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <div class="w-8 h-8 inline-flex items-center justify-center text-white">
                    <i class="fas fa-chevron-left text-xl"></i>
                </div>
                <div class="text-white text-sm font-semibold opacity-90 tracking-wide">Lists</div>
            </div>
            <div class="flex items-center gap-3">
                <div class="inline-flex items-center gap-1.5 text-white opacity-95">
                    <i class="far fa-user text-base"></i>
                    <span class="text-sm font-semibold">2</span>
                </div>
                <div class="text-white opacity-90 inline-flex items-center">
                    <i class="fas fa-ellipsis-h text-lg"></i>
                </div>
            </div>
        </div>

        <!-- 📝 JUDUL BESAR PUTIH PERSIS LISTS - TIDAK ADA NAMA HOTEL SAMA SEKALI! -->
        <h1 class="text-white font-black tracking-tight mb-7 leading-none" style="font-size: 38px; line-height: 1.05;">
            Engineering Operation
        </h1>

        <!-- 📋 LOOP SEMUA ACTIVITY = CARD PUTIH ROW PERSIS LISTS -->
        <div class="flex flex-col gap-3">
            <?php foreach ($printAllActs as $idx => $actRow):
                $nm = trim((string)($actRow['activity_name'] ?? ''));
                if ($nm === '') continue;
            ?>
            <div class="bg-white rounded-[14px] px-4 py-3.5 shadow-lg flex items-center gap-3.5" style="background-color:#ffffff !important; color:#0f172a !important; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
                <!-- ⚪ Radio Button Lingkaran Kosong KIRI -->
                <div class="w-7 h-7 rounded-full border-[2.5px] border-slate-400 shrink-0 inline-flex items-center justify-center" style="background-color:#ffffff !important; border-color:#94a3b8 !important;"></div>
                <!-- 📝 TEXT TENGAH 2 BARIS -->
                <div class="flex-1 min-w-0 leading-snug" style="color:#1e293b !important;">
                    <div style="font-size:17px; font-weight:600; color:#0f172a !important; word-wrap:break-word; line-height:1.375;"><?= htmlspecialchars($nm) ?></div>
                </div>
                <!-- ⭐ Star Icon KANAN ATAS -->
                <div class="shrink-0 pb-6 inline-flex items-center" style="color:#94a3b8 !important;">
                    <i class="far fa-star text-xl"></i>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 🔽 BOTTOM FLOATING + ADD A TASK -->
        <div style="position: fixed; left: 0; right: 0; bottom: 0; background: linear-gradient(180deg, rgba(30,64,175,0.0) 0%, rgba(30,64,175,0.98) 35%, #1e3a8a 100%); padding: 40px 18px 22px 18px;">
            <div class="bg-white/10 backdrop-blur-sm rounded-2xl border border-white/15 px-5 py-4 flex items-center gap-4" style="background-color: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.15);">
                <div class="w-8 h-8 rounded-full bg-white/20 inline-flex items-center justify-center shrink-0" style="background-color: rgba(255,255,255,0.20);">
                    <i class="fas fa-plus text-white text-lg"></i>
                </div>
                <div class="text-white font-semibold tracking-wide" style="color:#ffffff !important; font-size:18px;">Add a Task</div>
            </div>
        </div>
    </div>
</div>
<div class="page-shell page-shell--6xl">
    <div class="page-header flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6 animate-fade-in">
        <div>
            <p class="text-[11px] font-black uppercase tracking-[0.25em] text-indigo-700 mb-2">MANAGER • <?= T('eng_act_header', 'DEPARTMENT PERFORMANCE') ?></p>
            <h1 class="font-display text-3xl font-black text-primary flex items-center gap-3">
                <span class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-800 flex items-center justify-center text-white shadow-md shadow-indigo-500/30">
                    <span class="text-base font-black">3</span>
                </span>
                <span class="tracking-wide"><?= T('eng_act_title', 'ENGINEERING') ?> <span class="font-semibold text-secondary/80 italic"><?= T('eng_act_activities', 'ACTIVITIES') ?></span></span>
            </h1>
            <p class="text-sm text-secondary mt-2"><?= T('eng_act_subtitle', 'Ringkasan 4 divisi Operation, Maintenance, Project dan Landscape per bulan.') ?></p>
        </div>
        <div class="flex flex-wrap gap-2.5 self-start">
            <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gradient-to-br from-sky-600 via-blue-700 to-blue-900 hover:from-sky-700 hover:via-blue-800 hover:to-blue-950 text-white text-sm font-bold shadow hover:shadow-lg transition-all"
                    title="Cetak simple list engineering operation seperti Microsoft Lists">
                <i class="fas fa-print"></i> 🖨️ Cetak Lists
            </button>
            <a href="<?= BASE_URL ?>reports/pdf.php" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gradient-to-br from-rose-500 to-rose-700 hover:from-rose-600 hover:to-rose-800 text-white text-sm font-bold shadow hover:shadow-lg transition-all">
                <i class="far fa-file-pdf"></i> <?= T('btn_export_pdf', 'Export PDF') ?>
            </a>
            <a href="<?= BASE_URL ?>reports/excel.php" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 hover:from-emerald-600 hover:to-emerald-800 text-white text-sm font-bold shadow hover:shadow-lg transition-all">
                <i class="far fa-file-excel"></i> <?= T('btn_export_excel', 'Export Excel') ?>
            </a>
            <button type="button" onclick="openMasterModal()"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-700 hover:from-indigo-600 hover:to-purple-800 text-white text-sm font-bold shadow hover:shadow-lg transition-all">
                <i class="fas fa-gear"></i> ⚙️ Kelola Master Activity
            </button>
            <a href="<?= BASE_URL ?>index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 text-sm font-semibold shadow-sm transition-all">
                <i class="fas fa-arrow-left"></i> <?= T('btn_back_dash', 'Kembali ke Dashboard') ?>
            </a>
        </div>
    </div>

    <div class="card-premium p-5 sm:p-7 bg-white mb-8 animate-slide-up" style="animation-delay: 60ms">
        <div class="mb-5 pb-4 border-b border-slate-100">
            <div class="flex items-center gap-3 mb-1.5">
                <span class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-400 to-orange-600 flex items-center justify-center text-white shadow-md shadow-amber-500/30">
                    <i class="fas fa-pen-to-square"></i>
                </span>
                <div>
                    <h2 class="font-display text-xl font-black text-primary"><?= T('eng_act_input_title', 'Manager Isi Activity Counters') ?></h2>
                    <p class="text-xs text-secondary mt-0.5"><?= T('eng_act_input_sub', 'Pilih tanggal dan engineer, lalu isi jumlah aktivitas per 4 divisi di bawah. Data akan masuk ke Daily Log otomatis.') ?></p>
                </div>
            </div>
        </div>

        <form method="POST" action="<?= BASE_URL ?>manager/activities.php" novalidate>
            <input type="hidden" name="action" value="save_activity_counters">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-primary mb-2">
                        <i class="far fa-calendar-day text-orange-600 mr-1"></i> <?= T('eng_act_field_date', 'Tanggal Aktivitas') ?>
                    </label>
                    <input type="date" name="log_date" id="log_date_activity" value="<?= htmlspecialchars($today) ?>"
                           class="w-full px-3.5 py-3 rounded-card border border-slate-300 bg-white text-primary font-semibold shadow-sm focus:outline-none focus:ring-2 focus:ring-orange-300 focus:border-orange-400 transition">
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-primary mb-2">
                        <i class="fas fa-user-hard-hat text-blue-600 mr-1"></i> <?= T('eng_act_field_eng', 'Pilih Engineer / Staff') ?>
                    </label>
                    <select name="engineer_id" id="engineer_id_activity"
                            class="w-full px-3.5 py-3 rounded-card border border-slate-300 bg-white text-primary font-semibold shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-300 focus:border-blue-400 transition appearance-none pr-10">
                        <option value="">-- Pilih Staff / Engineer --</option>
                        <?php foreach ($engineers as $e):
                            $roleLabel = '';
                            $rClean = strtolower((string)($e['role'] ?? ''));
                            if ($rClean === 'engineer') $roleLabel = ' (Staff Engineer)';
                            elseif ($rClean === 'supervisor') $roleLabel = ' (Supervisor)';
                            elseif ($rClean === 'manager') $roleLabel = ' (Manager)';
                            elseif ($rClean === 'admin') $roleLabel = ' (Admin)';
                            else $roleLabel = '';
                        ?>
                            <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['name'] . $roleLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-slate-500 mb-3 pl-1 flex items-center gap-2">
                <i class="fas fa-layer-group"></i> <?= T('eng_act_field_counters', 'Counters Aktivitas 4 Divisi') ?>
            </p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
                <!-- ========================================================== -->
                <!-- 🔷 DIVISI 1: OPERATION                                     -->
                <!-- ========================================================== -->
                <div class="rounded-2xl border-2 border-slate-200 bg-white p-5 hover:shadow-lg transition">
                    <div class="flex items-center justify-between mb-3">
                        <label class="flex items-center gap-2">
                            <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white shadow-md">
                                <i class="fas fa-gears"></i>
                            </span>
                            <div>
                                <span class="font-black text-primary tracking-wide text-xs uppercase block">Operation</span>
                                <span class="text-[10px] text-slate-500">Counter & Daftar Activity</span>
                            </div>
                        </label>
                        <input type="number" min="0" step="1" name="activity_operation" value="0"
                               class="w-24 px-3 py-2 rounded-xl border border-slate-300 bg-white text-xl font-black text-primary text-center focus:outline-none focus:ring-2 focus:ring-slate-300 focus:border-slate-500">
                    </div>
                    <div class="space-y-2 pt-3 mt-3 border-t border-slate-200">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-[11px] font-bold uppercase text-slate-600 tracking-wide">📋 Daftar Activity</span>
                            <button type="button" onclick="addActRow('op')"
                                class="text-[11px] font-black inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white shadow-sm transition">
                                <i class="fas fa-plus text-[10px]"></i> Tambah Activity
                            </button>
                        </div>
                        <div id="actRows_op" class="space-y-2" data-rows="0"><!-- JS isi rows dinamis --></div>
                    </div>
                </div>
                <!-- ========================================================== -->
                <!-- 🟢 DIVISI 2: MAINTENANCE                                   -->
                <!-- ========================================================== -->
                <div class="rounded-2xl border-2 border-slate-200 bg-white p-5 hover:shadow-lg transition">
                    <div class="flex items-center justify-between mb-3">
                        <label class="flex items-center gap-2">
                            <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center text-white shadow-md">
                                <i class="fas fa-wrench"></i>
                            </span>
                            <div>
                                <span class="font-black text-primary tracking-wide text-xs uppercase block">Maintenance</span>
                                <span class="text-[10px] text-slate-500">Counter & Daftar Activity</span>
                            </div>
                        </label>
                        <input type="number" min="0" step="1" name="activity_maintenance" value="0"
                               class="w-24 px-3 py-2 rounded-xl border border-slate-300 bg-white text-xl font-black text-primary text-center focus:outline-none focus:ring-2 focus:ring-slate-300 focus:border-slate-500">
                    </div>
                    <div class="space-y-2 pt-3 mt-3 border-t border-slate-200">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-[11px] font-bold uppercase text-slate-600 tracking-wide">📋 Daftar Activity</span>
                            <button type="button" onclick="addActRow('mt')"
                                class="text-[11px] font-black inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white shadow-sm transition">
                                <i class="fas fa-plus text-[10px]"></i> Tambah Activity
                            </button>
                        </div>
                        <div id="actRows_mt" class="space-y-2" data-rows="0"><!-- JS isi rows dinamis --></div>
                    </div>
                </div>
                <!-- ========================================================== -->
                <!-- 🟣 DIVISI 3: PROJECT                                       -->
                <!-- ========================================================== -->
                <div class="rounded-2xl border-2 border-slate-200 bg-white p-5 hover:shadow-lg transition">
                    <div class="flex items-center justify-between mb-3">
                        <label class="flex items-center gap-2">
                            <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-400 to-violet-600 flex items-center justify-center text-white shadow-md">
                                <i class="fas fa-diagram-project"></i>
                            </span>
                            <div>
                                <span class="font-black text-primary tracking-wide text-xs uppercase block">Project</span>
                                <span class="text-[10px] text-slate-500">Counter & Daftar Activity</span>
                            </div>
                        </label>
                        <input type="number" min="0" step="1" name="activity_project" value="0"
                               class="w-24 px-3 py-2 rounded-xl border border-slate-300 bg-white text-xl font-black text-primary text-center focus:outline-none focus:ring-2 focus:ring-slate-300 focus:border-slate-500">
                    </div>
                    <div class="space-y-2 pt-3 mt-3 border-t border-slate-200">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-[11px] font-bold uppercase text-slate-600 tracking-wide">📋 Daftar Activity</span>
                            <button type="button" onclick="addActRow('pr')"
                                class="text-[11px] font-black inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white shadow-sm transition">
                                <i class="fas fa-plus text-[10px]"></i> Tambah Activity
                            </button>
                        </div>
                        <div id="actRows_pr" class="space-y-2" data-rows="0"><!-- JS isi rows dinamis --></div>
                    </div>
                </div>
                <!-- ========================================================== -->
                <!-- 🟩 DIVISI 4: LANDSCAPE                                     -->
                <!-- ========================================================== -->
                <div class="rounded-2xl border-2 border-slate-200 bg-white p-5 hover:shadow-lg transition">
                    <div class="flex items-center justify-between mb-3">
                        <label class="flex items-center gap-2">
                            <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-teal-400 to-teal-600 flex items-center justify-center text-white shadow-md">
                                <i class="fas fa-leaf"></i>
                            </span>
                            <div>
                                <span class="font-black text-primary tracking-wide text-xs uppercase block">Landscape</span>
                                <span class="text-[10px] text-slate-500">Counter & Daftar Activity</span>
                            </div>
                        </label>
                        <input type="number" min="0" step="1" name="activity_landscape" value="0"
                               class="w-24 px-3 py-2 rounded-xl border border-slate-300 bg-white text-xl font-black text-primary text-center focus:outline-none focus:ring-2 focus:ring-slate-300 focus:border-slate-500">
                    </div>
                    <div class="space-y-2 pt-3 mt-3 border-t border-slate-200">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-[11px] font-bold uppercase text-slate-600 tracking-wide">📋 Daftar Activity</span>
                            <button type="button" onclick="addActRow('la')"
                                class="text-[11px] font-black inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white shadow-sm transition">
                                <i class="fas fa-plus text-[10px]"></i> Tambah Activity
                            </button>
                        </div>
                        <div id="actRows_la" class="space-y-2" data-rows="0"><!-- JS isi rows dinamis --></div>
                    </div>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="reset" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-bold hover:bg-slate-50 transition shadow-sm">
                    <i class="fas fa-rotate-left"></i> Reset
                </button>
                <button type="submit" class="inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-xl bg-gradient-to-br from-amber-400 to-orange-600 hover:from-amber-500 hover:to-orange-700 text-white text-sm font-black shadow-lg shadow-amber-500/30 hover:shadow-xl transition">
                    <i class="fas fa-cloud-arrow-up"></i> Simpan / Update Counters
                </button>
            </div>
        </form>
    </div>

    <div class="card-premium p-5 sm:p-8 bg-white animate-slide-up" style="animation-delay: 90ms">
        <div class="mb-6 pb-4 border-b border-slate-100">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.25em] text-slate-500 mb-1.5 pl-1">4 DIVISI ENGINEERING</p>
                    <h3 class="font-display text-xl lg:text-2xl font-black text-primary flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-slate-700 to-primary flex items-center justify-center text-white shadow-md text-sm">
                            <i class="fas fa-layer-group text-xs"></i>
                        </span>
                        Rekap Aktivitas Bulan Ini
                    </h3>
                </div>
            </div>
        </div>
        <div class="flex flex-col gap-4 sm:gap-5 mb-6">
            <?php foreach ($colLeft as $idx => $c):
                $rowsCard = $catDetailRows[$c['id']] ?? [];
                $colItems = 'activity_operation_items';
                if ($c['id'] === 'operation') $colItems = 'activity_operation_items';
                elseif ($c['id'] === 'maintenance') $colItems = 'activity_maintenance_items';
                elseif ($c['id'] === 'project') $colItems = 'activity_project_items';
                elseif ($c['id'] === 'landscape') $colItems = 'activity_landscape_items';
                $itemsAllPreview = '';
                $collect = [];
                if (count($rowsCard) > 0) {
                    foreach ($rowsCard as $rc) {
                        $jsonText = $rc[$colItems] ?? '';
                        if (empty($jsonText)) continue;
                        $arr = json_decode($jsonText, true);
                        if (is_array($arr)) foreach ($arr as $ai) $collect[] = $ai;
                        if (count($collect) >= 3) break;
                    }
                }
                // ✨ BARU: Tambahkan juga master activity preview (max total 3)
                if (count($collect) < 3) {
                    $mPrev = $masterPreviewPerDiv[$c['id']] ?? [];
                    foreach ($mPrev as $mp) {
                        if (count($collect) >= 3) break;
                        $collect[] = $mp;
                    }
                }
                if (count($collect) > 0) {
                    $itemsAllPreview = renderActItems($collect, 3);
                }
            ?>
            <div class="group rounded-2xl border-2 border-slate-200 bg-white p-4 sm:p-5 hover:shadow-2xl hover:-translate-y-0.5 hover:scale-[1.005] hover:ring-4 hover:ring-slate-300 transition-all duration-300 cursor-pointer active:scale-[0.998]"
                 onclick="openModal('<?= htmlspecialchars($c['id']) ?>')"
                 role="button"
                 tabindex="0"
                 aria-label="Buka detail divisi <?= htmlspecialchars($c['label']) ?>"
                 onkeydown="if(event.key==='Enter'||event.key===' ')openModal('<?= htmlspecialchars($c['id']) ?>')">
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex items-center gap-3 sm:gap-4 shrink-0 sm:w-56">
                            <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-gradient-to-br <?= $c['iconBox'] ?> flex items-center justify-center text-white shadow-lg ring-2 ring-white/90 shrink-0 group-hover:rotate-3 group-hover:scale-105 transition-transform duration-300">
                                <i class="<?= $c['icon'] ?> text-xl sm:text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500 mb-1"><?= $deptLabel ?></p>
                                <p class="font-black uppercase tracking-wider text-base sm:text-lg text-primary leading-tight"><?= $c['label'] ?></p>
                                <p class="text-[11px] font-bold uppercase tracking-widest text-secondary/70 mt-0.5">This Month • <?= date('M Y') ?></p>
                            </div>
                        </div>
                        <div class="flex-1 min-w-0 pl-0 sm:pl-8 sm:border-l sm:border-dashed sm:border-slate-300">
                            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500 mb-1.5"><?= $actDetailLabel ?></p>
                            <div class="text-sm">
                                <?= $colRight[$idx] ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($itemsAllPreview !== ''): ?>
                    <div class="rounded-xl border border-dashed border-slate-300 bg-white/60 p-3.5">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-500 mb-2 flex items-center gap-1"><i class="fas fa-list-ul"></i> Preview Daftar Activity Terbaru</p>
                        <?= $itemsAllPreview ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="pt-6 sm:pt-8 border-t border-dashed border-slate-200">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-8">
                <div class="text-left">
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500 mb-8"><?= $preparedLabel ?></p>
                    <p class="font-bold text-base text-primary border-b-2 border-slate-900/80 pb-1 inline-block"><?= $preparedName ?></p>
                </div>
                <div class="text-right sm:text-right">
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500 mb-8"><?= $reviewedLabel ?></p>
                    <p class="font-bold text-base text-primary border-b-2 border-slate-900/80 pb-1 inline-block"><?= $reviewedName ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================ MODAL DETAIL REKAP DIVISI (4 PCS) ================================ -->
    <?php foreach ($colLeft as $idx => $c):
        $rows = $catDetailRows[$c['id']] ?? [];
        $totalSum = 0;
        foreach ($rows as $r) $totalSum += (int)($r['cnt'] ?? 0);
        $divKey = $c['id'];
        $masterRows = $masterRowsPerDiv[$divKey] ?? [];
        $masterCnt = (int)($masterCntPerDiv[$divKey] ?? 0);
        $colItems = 'activity_operation_items';
        if ($c['id'] === 'operation') $colItems = 'activity_operation_items';
        elseif ($c['id'] === 'maintenance') $colItems = 'activity_maintenance_items';
        elseif ($c['id'] === 'project') $colItems = 'activity_project_items';
        elseif ($c['id'] === 'landscape') $colItems = 'activity_landscape_items';
        $showMasterBox = ($masterCnt > 0);
    ?>
    <div id="modal-<?= htmlspecialchars($c['id']) ?>" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4 animate-fade-in-modal"
         onclick="if(event.target===this)closeModal('<?= htmlspecialchars($c['id']) ?>')">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md"></div>
        <div class="relative w-full max-w-4xl max-h-[88vh] bg-white rounded-3xl shadow-[0_30px_100px_-20px_rgba(30,41,59,0.45)] overflow-hidden flex flex-col animate-slide-up-modal border-2 border-slate-200">
            <!-- HEADER MODAL GRADIENT -->
            <div class="bg-gradient-to-br <?= $c['iconBox'] ?> p-6 sm:p-7 text-white relative overflow-hidden">
                <div class="absolute -right-10 -top-10 w-40 h-40 bg-white/10 rounded-full blur-2xl"></div>
                <div class="absolute -right-20 bottom-0 w-60 h-60 bg-black/10 rounded-full blur-3xl"></div>
                <div class="relative flex items-start justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-white/20 backdrop-blur-sm border border-white/30 flex items-center justify-center ring-2 ring-white/40 shadow-lg shrink-0">
                            <i class="<?= $c['icon'] ?> text-2xl sm:text-3xl"></i>
                        </div>
                        <div>
                            <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.25em] text-white/85 mb-1.5">DETAIL REKAP BULAN INI • <?= date('M Y') ?></p>
                            <h4 class="font-display text-2xl sm:text-3xl font-black tracking-wide leading-tight">Divisi <?= $c['label'] ?></h4>
                            <div class="flex flex-wrap items-center gap-3 mt-2">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/20 backdrop-blur-sm border border-white/30 text-white text-xs font-bold">
                                    <i class="fas fa-list-check"></i> Total Data: <span class="font-black text-white"><?= count($rows) ?> aktivitas</span>
                                </span>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white text-slate-800 text-xs font-black shadow">
                                    <i class="<?= $c['icon'] ?> <?= $c['color'] ?>"></i> Total Counters: <?= number_format($totalSum, 0, ',', '.') ?>
                                </span>
                                <?php if ($showMasterBox): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-sky-50 border border-sky-200 text-sky-700 text-xs font-black shadow">
                                    <i class="fas fa-database"></i> Master Template: <?= $masterCnt ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <button type="button"
                            onclick="closeModal('<?= htmlspecialchars($c['id']) ?>')"
                            class="shrink-0 w-11 h-11 rounded-2xl bg-white/20 hover:bg-white text-white hover:text-rose-600 border border-white/30 backdrop-blur-sm flex items-center justify-center transition-all duration-200 hover:scale-110 active:scale-95 shadow-md"
                            aria-label="Tutup modal">
                        <i class="fas fa-xmark text-xl font-black"></i>
                    </button>
                </div>
            </div>

            <!-- BODY MODAL -->
            <div class="flex-1 overflow-y-auto p-5 sm:p-7 bg-gradient-to-b from-slate-50 to-white">
                <?php if (empty($rows) && !$showMasterBox): ?>
                    <div class="text-center py-16 px-6">
                        <div class="w-20 h-20 mx-auto mb-5 rounded-3xl bg-slate-100 flex items-center justify-center text-slate-400">
                            <i class="far fa-folder-open text-3xl"></i>
                        </div>
                        <h5 class="text-xl font-black text-slate-700 mb-2">Belum ada data aktivitas divisi <?= $c['label'] ?></h5>
                        <p class="text-sm text-slate-500 mb-6">Isi counters terlebih dahulu melalui Form Manager Isi Activity Counters di atas.</p>
                        <button type="button"
                                onclick="closeModal('<?= htmlspecialchars($c['id']) ?>')"
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-br from-slate-700 to-slate-900 hover:from-slate-800 hover:to-black text-white text-sm font-bold shadow-lg shadow-slate-500/20 transition">
                            <i class="fas fa-arrow-left"></i> Kembali
                        </button>
                    </div>
                <?php else: ?>
                    <?php if (!empty($rows)): ?>
                    <div class="overflow-x-auto rounded-2xl border border-slate-200 shadow-sm bg-white">
                        <table class="w-full text-sm">
                            <thead class="bg-gradient-to-r from-slate-50 to-slate-100 border-b border-slate-200">
                                <tr>
                                    <th class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 w-10">#</th>
                                    <th class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 w-20">Ref ID</th>
                                    <th class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 w-28">Tanggal</th>
                                    <th class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 w-40">Nama Staff</th>
                                    <th class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Activity Detail<br><span class="text-[9px] font-bold text-slate-500">(Daftar Aktivitas + Status)</span></th>
                                    <th class="px-3 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500 pr-3 w-32">Counters<br><span class="text-[9px] text-slate-600 font-bold">Divisi <?= $c['label'] ?></span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php
                                $no = 1;
                                $runningSum = 0;
                                foreach ($rows as $r):
                                    $runningSum += (int)($r['cnt'] ?? 0);
                                    $actRowHtml = renderActItems($r[$colItems] ?? '');
                                ?>
                                <tr class="hover:bg-slate-50 transition-colors group">
                                    <td class="px-3 py-3 text-xs font-bold text-slate-400 align-top"><?= $no++ ?>.</td>
                                    <td class="px-3 py-3 align-top">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-slate-900/5 border border-slate-200 text-[11px] font-mono font-bold text-slate-700">
                                            #<?= (int)$r['id'] ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <div class="flex flex-col">
                                            <span class="text-xs font-bold text-primary"><?= date('d M Y', strtotime($r['log_date'])) ?></span>
                                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400"><?= date('l', strtotime($r['log_date'])) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <div class="flex items-center gap-2">
                                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 text-white flex items-center justify-center font-black text-[11px] shadow-sm ring-2 ring-white shrink-0">
                                                <?= strtoupper(mb_substr((string)$r['engineer_name'], 0, 1) ?: '?') ?>
                                            </div>
                                            <span class="text-xs font-bold text-primary leading-tight"><?= htmlspecialchars($r['engineer_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 align-top min-w-[300px]">
                                        <?php if ($actRowHtml === ''): ?>
                                            <span class="inline-block text-[11px] italic text-slate-400 font-semibold">(tidak ada detail activity manual)</span>
                                        <?php else: ?>
                                            <?= $actRowHtml ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-3 pr-3 text-right align-top">
                                        <span class="inline-flex items-center justify-end gap-1 px-2.5 py-1.5 rounded-lg bg-slate-100 border border-slate-300 text-base font-black text-slate-700 shadow-sm">
                                            <i class="<?= $c['icon'] ?> text-[12px] text-slate-500"></i>
                                            <?= number_format((int)($r['cnt'] ?? 0), 0, ',', '.') ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-gradient-to-r from-slate-50 to-slate-100 border-t-2 border-slate-300">
                                <tr>
                                    <td colspan="5" class="px-3 py-3.5 text-right">
                                        <span class="text-[11px] font-black uppercase tracking-widest text-slate-500 mr-1.5 flex items-center justify-end gap-1.5">
                                            <i class="fas fa-calculator"></i> TOTAL COUNTERS DIVISI <?= $c['label'] ?> BULAN INI:
                                        </span>
                                    </td>
                                    <td class="px-3 py-3.5 pr-3 text-right">
                                        <span class="inline-flex items-center justify-end gap-1.5 px-3 py-2 rounded-xl bg-gradient-to-br <?= $c['iconBox'] ?> text-white text-lg font-black shadow-lg shadow-slate-500/10 ring-2 ring-white/80">
                                            <i class="<?= $c['icon'] ?>"></i>
                                            <?= number_format($totalSum, 0, ',', '.') ?>
                                        </span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php endif; ?>

                    <?php if ($showMasterBox): ?>
                    <div class="mt-7 border-t-2 border-dashed border-slate-200 pt-7">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-500 to-sky-700 flex items-center justify-center text-white shadow shadow-sky-500/30">
                                <i class="fas fa-database text-sm"></i>
                            </div>
                            <div>
                                <p class="text-[11px] font-black uppercase tracking-[0.2em] text-sky-700">MASTER TEMPLATE</p>
                                <h6 class="font-black text-lg text-primary">Daftar Master Activity Divisi <?= $c['label'] ?> (<?= $masterCnt ?>)</h6>
                            </div>
                        </div>
                        <div class="overflow-x-auto rounded-2xl border border-sky-200 bg-gradient-to-br from-white to-sky-50/40 shadow-sm">
                            <table class="w-full text-sm">
                                <thead class="bg-gradient-to-r from-sky-100 to-sky-50 border-b border-sky-200">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-widest text-sky-800 w-12">#</th>
                                        <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-widest text-sky-800 w-20">Urutan</th>
                                        <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-widest text-sky-800">Nama Master Activity</th>
                                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase tracking-widest text-sky-800 w-44">Status Default</th>
                                        <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-widest text-sky-800 w-40">Dibuat Tgl</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-sky-100">
                                    <?php
                                    $_nMaster = 0;
                                    foreach ($masterRows as $mr):
                                        $_nMaster++;
                                        $stM = (strtolower((string)($mr['status_default'] ?? 'progress')) === 'complete') ? 'complete' : 'progress';
                                        $crAt = (string)($mr['created_at'] ?? '');
                                        if ($crAt !== '') { try { $dtM = new DateTime($crAt); $crFmt = $dtM->format('d M Y'); } catch (Throwable $e) { $crFmt = $crAt; } } else { $crFmt = '-'; }
                                    ?>
                                    <tr class="hover:bg-sky-50 transition-colors">
                                        <td class="px-4 py-3 align-top text-slate-500 font-black text-xs"><?= $_nMaster ?>.</td>
                                        <td class="px-4 py-3 align-top">
                                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white border border-sky-200 text-sky-700 font-black shadow-xs">
                                                <?= (int)($mr['sort_order'] ?? 0) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 align-top font-bold text-slate-800"><?= htmlspecialchars(trim((string)($mr['activity_name'] ?? ''))) ?></td>
                                        <td class="px-4 py-3 align-top text-center">
                                            <?php if ($stM === 'complete'): ?>
                                                <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-[11px] font-black">
                                                    <i class="fas fa-check-circle text-[10px]"></i> COMPLETE
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full bg-amber-50 border border-amber-200 text-amber-700 text-[11px] font-black">
                                                    <i class="fas fa-spinner fa-spin text-[10px]"></i> IN PROGRESS
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 align-top text-xs text-slate-600 font-semibold">
                                            <span class="inline-flex items-center gap-1">
                                                <i class="far fa-calendar text-slate-400"></i> <?= $crFmt ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mt-6 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pt-2">
                        <p class="text-[11px] font-semibold text-slate-500 flex items-center gap-2">
                            <i class="fas fa-info-circle text-slate-400"></i> Data diurutkan dari tanggal terbaru (DESC). Total Running Sum bulan ini sudah ditampilkan di atas kanan.
                        </p>
                        <div class="flex items-center gap-2 sm:gap-3 justify-end flex-wrap">
                            <button type="button"
                                    onclick="closeModal('<?= htmlspecialchars($c['id']) ?>')"
                                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-sm font-bold shadow-sm transition">
                                <i class="fas fa-xmark"></i> Tutup
                            </button>
                            <a href="<?= BASE_URL ?>reports/excel.php?cat=<?= urlencode($c['id']) ?>&month=<?= date('Y-m') ?>" target="_blank" rel="noopener noreferrer"
                               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 hover:from-emerald-600 hover:to-emerald-800 text-white text-sm font-bold shadow-md hover:shadow-lg transition">
                                <i class="far fa-file-excel"></i> Export Excel
                            </a>
                            <a href="<?= BASE_URL ?>reports/pdf.php?cat=<?= urlencode($c['id']) ?>&month=<?= date('Y-m') ?>" target="_blank" rel="noopener noreferrer"
                               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-br from-rose-500 to-rose-700 hover:from-rose-600 hover:to-rose-800 text-white text-sm font-bold shadow-md hover:shadow-lg transition">
                                <i class="far fa-file-pdf"></i> Export PDF
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <!-- ================================ / END MODAL DIVISI ================================ -->

    <!-- ================================ MODAL CRUD MASTER ACTIVITY ================================ -->
    <?php
    $divMeta = [];
    foreach ($cats as $c) $divMeta[$c['id']] = [
      'label' => $c['label'], 'icon' => $c['icon'], 'color' => $c['color'],
      'bg' => $c['bg'], 'border' => $c['border'], 'ring' => $c['ring'], 'iconBox' => $c['iconBox'],
    ];
    ?>
    <div id="modal-master" class="fixed inset-0 z-[99999] hidden items-center justify-center p-4 animate-fade-in-modal"
         onclick="if(event.target===this)closeMasterModal()">
        <div class="absolute inset-0 bg-slate-900/65 backdrop-blur-md"></div>
        <div class="relative w-full max-w-5xl max-h-[93vh] bg-white rounded-3xl shadow-[0_30px_100px_-20px_rgba(30,41,59,0.45)] overflow-hidden flex flex-col animate-slide-up-modal border-2 border-indigo-200">
            <!-- HEADER MODAL GRADIENT -->
            <div class="bg-gradient-to-br from-indigo-500 via-purple-500 to-purple-700 p-5 sm:p-6 text-white relative overflow-hidden">
                <div class="absolute -right-12 -top-12 w-44 h-44 bg-white/10 rounded-full blur-2xl"></div>
                <div class="absolute -right-24 bottom-0 w-64 h-64 bg-black/10 rounded-full blur-3xl"></div>
                <div class="relative flex items-start justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-white/20 backdrop-blur-sm border border-white/30 flex items-center justify-center ring-2 ring-white/40 shadow-lg shrink-0">
                            <i class="fas fa-database text-2xl sm:text-3xl"></i>
                        </div>
                        <div>
                            <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.25em] text-white/85 mb-1.5">CRUD MASTER DATA AKTIVITAS</p>
                            <h4 class="font-display text-2xl sm:text-3xl font-black tracking-wide leading-tight">Master Activity per Divisi</h4>
                            <div class="flex items-center gap-3 mt-2">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/20 backdrop-blur-sm border border-white/30 text-white text-xs font-bold">
                                    <i class="fas fa-list-check"></i> Total Master: <span class="font-black text-white"><?= count($allMasters) ?></span>
                                </span>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white text-slate-800 text-xs font-black shadow">
                                    <i class="fas fa-layer-group text-indigo-600"></i> 4 Divisi Tersedia
                                </span>
                            </div>
                        </div>
                    </div>
                    <button type="button"
                            onclick="closeMasterModal()"
                            class="shrink-0 w-11 h-11 rounded-2xl bg-white/20 hover:bg-white text-white hover:text-rose-600 border border-white/30 backdrop-blur-sm flex items-center justify-center transition-all duration-200 hover:scale-110 active:scale-95 shadow-md"
                            aria-label="Tutup modal">
                        <i class="fas fa-xmark text-xl font-black"></i>
                    </button>
                </div>
            </div>

            <!-- BODY MODAL -->
            <div class="flex-1 overflow-y-auto p-5 sm:p-6 bg-gradient-to-b from-slate-50 to-white">
                <!-- TAB FILTER 4 DIVISI -->
                <div class="flex flex-wrap gap-2 mb-5 p-1.5 rounded-2xl bg-slate-100 border border-slate-200">
                    <?php foreach ($cats as $c): ?>
                    <button type="button" data-tab="<?= htmlspecialchars($c['id']) ?>" onclick="switchMasterTab('<?= htmlspecialchars($c['id']) ?>')"
                            class="master-tab-btn flex-1 min-w-[120px] px-3 py-2 rounded-xl text-[11px] sm:text-xs font-black uppercase tracking-wider transition-all duration-200 border border-transparent flex items-center justify-center gap-1.5">
                        <i class="<?= $c['icon'] ?>"></i>
                        <span class="truncate"><?= $c['label'] ?></span>
                        <span class="inline-flex items-center justify-center min-w-[22px] h-5 px-1.5 rounded-full bg-black/10 backdrop-blur-sm text-[10px] font-black"><?= count($mastersByDiv[$c['id']] ?? []) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- FORM CREATE / EDIT MASTER -->
                <form method="POST" id="masterForm" class="mb-5 p-4 sm:p-5 rounded-2xl bg-white border-2 border-dashed border-indigo-200 shadow-sm">
                    <input type="hidden" name="action" value="save_master_activity">
                    <input type="hidden" name="master_id" id="masterId" value="0">
                    <input type="hidden" name="division" id="masterDivision" value="operation">
                    <h5 class="font-black text-base sm:text-lg text-primary mb-3 flex items-center gap-2" id="masterFormTitle">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center shadow-sm">
                            <i class="fas fa-plus text-[14px]"></i>
                        </span>
                        <span id="masterFormLabel">Tambah Master Activity Divisi Operation</span>
                    </h5>
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        <div class="md:col-span-7">
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">
                                <i class="fas fa-keyboard text-indigo-500 mr-1"></i> Nama Activity / Pekerjaan
                            </label>
                            <input type="text" name="activity_name" id="masterActivityName" required
                                   placeholder="Contoh: Perbaikan pompa air Main Building lantai 2"
                                   class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 bg-white text-sm font-semibold text-primary shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 transition">
                        </div>
                        <div class="md:col-span-2">
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">
                                <i class="fas fa-flag-checkered text-slate-500 mr-1"></i> Status Default
                            </label>
                            <select name="status_default" id="masterStatusDefault"
                                    class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-sm font-bold text-primary shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 transition appearance-none pr-8">
                                <option value="progress">⏳ In Progress</option>
                                <option value="complete">✅ Complete</option>
                            </select>
                        </div>
                        <div class="md:col-span-1">
                            <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">
                                <i class="fas fa-arrow-down-1-9 text-slate-500 mr-1"></i> Urutan
                            </label>
                            <input type="number" min="0" step="1" name="sort_order" id="masterSortOrder" value="0"
                                   class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-sm font-bold text-primary text-center shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 transition">
                        </div>
                        <div class="md:col-span-2 flex gap-2">
                            <button type="submit"
                                    class="flex-1 px-3 py-2.5 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 text-white text-sm font-bold shadow-md hover:shadow-lg transition flex items-center justify-center gap-1.5">
                                <i class="fas fa-save text-[13px]"></i> Simpan
                            </button>
                            <button type="button" onclick="resetMasterForm()"
                                    class="w-11 shrink-0 px-2.5 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-600 text-sm font-bold transition"
                                    title="Reset Form ke Tambah Baru" aria-label="Reset Form">
                                <i class="fas fa-rotate-left text-[13px]"></i>
                            </button>
                        </div>
                    </div>
                </form>

                <!-- PANEL LIST MASTER PER DIVISI -->
                <?php foreach ($cats as $c):
                    $listMaster = $mastersByDiv[$c['id']] ?? [];
                ?>
                <div data-master-panel="<?= htmlspecialchars($c['id']) ?>" class="master-panel hidden">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 flex items-center gap-1.5">
                            <i class="<?= $c['icon'] ?> text-slate-500"></i>
                            Daftar Master Activity Divisi <?= $c['label'] ?> • Total <span class="font-black text-primary"><?= count($listMaster) ?> Item</span>
                        </p>
                    </div>
                    <?php if (count($listMaster) === 0): ?>
                    <div class="rounded-2xl border-2 border-dashed border-slate-200 bg-white p-8 text-center">
                        <div class="w-20 h-20 mx-auto mb-4 rounded-3xl bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center text-slate-500">
                            <i class="far fa-folder-open text-3xl"></i>
                        </div>
                        <h6 class="font-black text-lg text-slate-700 mb-1">Belum ada Master Activity Divisi <?= $c['label'] ?></h6>
                        <p class="text-sm text-slate-500 mb-4 max-w-md mx-auto">Tambahkan master activity melalui form Create di atas. Data master akan muncul sebagai DROPDOWN PILIHAN di Form Counters.</p>
                        <button type="button" onclick="document.getElementById('masterActivityName').focus();"
                                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 text-white text-xs font-bold shadow-md hover:shadow-lg transition">
                            <i class="fas fa-plus"></i> Tambah Master Pertama
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto rounded-2xl border border-slate-200 shadow-sm bg-white">
                        <table class="w-full text-sm">
                            <thead class="bg-gradient-to-r from-slate-50 to-slate-100 border-b border-slate-200">
                                <tr>
                                    <th class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 w-10">#</th>
                                    <th class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Nama Master Activity</th>
                                    <th class="px-3 py-3 text-center text-[10px] font-black uppercase tracking-widest text-slate-500 w-32">Status Default</th>
                                    <th class="px-3 py-3 text-center text-[10px] font-black uppercase tracking-widest text-slate-500 w-16">Urut</th>
                                    <th class="px-3 py-3 text-center text-[10px] font-black uppercase tracking-widest text-slate-500 w-32 pr-3">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php $no = 1; foreach ($listMaster as $m): ?>
                                <tr class="hover:bg-<?= $c['color'] ?>/5 transition-colors group">
                                    <td class="px-3 py-3 text-xs font-bold text-slate-400 align-top"><?= $no++ ?>.</td>
                                    <td class="px-3 py-3 align-top">
                                        <input type="hidden" id="m-name-<?= (int)$m['id'] ?>" value="<?= htmlspecialchars($m['activity_name']) ?>">
                                        <input type="hidden" id="m-status-<?= (int)$m['id'] ?>" value="<?= htmlspecialchars($m['status_default']) ?>">
                                        <input type="hidden" id="m-sort-<?= (int)$m['id'] ?>" value="<?= (int)$m['sort_order'] ?>">
                                        <div class="flex items-start gap-2">
                                            <span class="inline-flex w-8 h-8 rounded-lg bg-slate-100 text-slate-600 items-center justify-center shrink-0 mt-0.5 group-hover:scale-110 transition">
                                                <i class="<?= $c['icon'] ?> text-[12px]"></i>
                                            </span>
                                            <span class="text-sm font-bold text-primary leading-relaxed pt-1"><?= htmlspecialchars($m['activity_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-center align-top pt-4">
                                        <?php if (($m['status_default'] ?? 'progress') === 'complete'): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-[11px] font-black shadow-sm">
                                                <i class="fas fa-check-circle text-[10px]"></i> Complete
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-50 border border-amber-200 text-amber-700 text-[11px] font-black shadow-sm">
                                                <i class="fas fa-spinner fa-spin text-[10px]"></i> In Progress
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-3 text-center text-xs font-bold text-slate-500 align-top pt-4"><?= (int)$m['sort_order'] ?></td>
                                    <td class="px-3 py-3 pr-3 align-top pt-3">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <button type="button" onclick="editMaster('<?= htmlspecialchars($c['id']) ?>', <?= (int)$m['id'] ?>)"
                                                    class="w-9 h-9 rounded-lg bg-amber-50 hover:bg-amber-500 text-amber-600 hover:text-white border border-amber-200 hover:border-amber-500 flex items-center justify-center transition-all duration-200 hover:scale-105 active:scale-95 shadow-sm"
                                                    title="Edit master activity ini" aria-label="Edit">
                                                <i class="fas fa-pencil text-[13px]"></i>
                                            </button>
                                            <form method="POST" onsubmit="return confirm('⚠️ YAKIN HAPUS master activity ini? Data activity yang sudah tersimpan di Daily Log TIDAK AKAN TERHAPUS (tetap tampil textnya).');">
                                                <input type="hidden" name="action" value="delete_master_activity">
                                                <input type="hidden" name="master_id" value="<?= (int)$m['id'] ?>">
                                                <button type="submit"
                                                        class="w-9 h-9 rounded-lg bg-rose-50 hover:bg-rose-500 text-rose-600 hover:text-white border border-rose-200 hover:border-rose-500 flex items-center justify-center transition-all duration-200 hover:scale-105 active:scale-95 shadow-sm"
                                                        title="Hapus master activity ini" aria-label="Hapus">
                                                    <i class="fas fa-trash-can text-[13px]"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <!-- FOOTER MODAL -->
            <div class="p-4 sm:p-5 border-t border-slate-200 bg-slate-50/70 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                <p class="text-[11px] font-semibold text-slate-500 flex items-center gap-1.5">
                    <i class="fas fa-info-circle text-slate-400"></i>
                    Master activity yang sudah dihapus TIDAK merusak data lama di Daily Log (nama activity tetap tersimpan sebagai text).
                </p>
                <div class="flex items-center justify-end gap-2">
                    <button type="button" onclick="closeMasterModal()"
                            class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-sm font-bold shadow-sm transition flex items-center justify-center gap-1.5">
                        <i class="fas fa-xmark"></i> Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- ================================ / END MODAL CRUD MASTER ================================ -->

    <script>
    // ✨ EMBED DATA MASTER ACTIVITY PER DIVISI KE JS GLOBAL (UNTUK DROPDOWN DINAMIS)
    window.ACTIVITY_MASTERS = <?= json_encode($mastersByDiv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.CUR_MASTER_TAB = 'operation';
    window.DIV_META = <?= json_encode($divMeta, JSON_UNESCAPED_UNICODE) ?>;
    window.DIV_CODE_TO_FULL = { op: 'operation', mt: 'maintenance', pr: 'project', la: 'landscape' };

    function openModal(cat) {
        const modal = document.getElementById('modal-' + cat);
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
        const closeBtn = modal.querySelector('button[aria-label="Tutup modal"]');
        if (closeBtn) setTimeout(() => closeBtn.focus(), 100);
    }
    function closeModal(cat) {
        const modal = document.getElementById('modal-' + cat);
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = '';
    }
    // ================================ JS: CRUD MASTER MODAL FUNCTION ================================
    function openMasterModal(initialTab) {
        const modal = document.getElementById('modal-master');
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
        const tab = initialTab || window.CUR_MASTER_TAB || 'operation';
        switchMasterTab(tab);
        // Reset ke form create kosong setiap buka modal
        setTimeout(() => resetMasterForm(), 80);
        const closeBtn = modal.querySelector('button[aria-label="Tutup modal"]');
        if (closeBtn) setTimeout(() => closeBtn.focus(), 100);
    }
    function closeMasterModal() {
        const modal = document.getElementById('modal-master');
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = '';
    }
    function switchMasterTab(divId) {
        window.CUR_MASTER_TAB = divId;
        const meta = window.DIV_META && window.DIV_META[divId] ? window.DIV_META[divId] : null;
        const label = meta ? meta.label : divId;
        // Update Tab Buttons UI (NETRAL SEMUA - INDIGO ACTIVE CONSISTENT)
        document.querySelectorAll('.master-tab-btn').forEach(btn => {
            const isActive = btn.getAttribute('data-tab') === divId;
            if (!isActive) {
                btn.classList.remove('bg-white', 'shadow', 'ring-2', 'ring-indigo-300', 'border-white', 'text-indigo-700');
                btn.classList.add('text-slate-600', 'hover:bg-white/70');
                return;
            }
            btn.classList.remove('text-slate-600', 'hover:bg-white/70');
            btn.classList.add('bg-white', 'shadow', 'ring-2', 'ring-indigo-300', 'border-white', 'text-indigo-700');
        });
        // Update Panels visibility
        document.querySelectorAll('.master-panel').forEach(p => {
            const show = p.getAttribute('data-master-panel') === divId;
            p.classList.toggle('hidden', !show);
        });
        // Update hidden form division + label form
        const inpDiv = document.getElementById('masterDivision');
        if (inpDiv) inpDiv.value = divId;
        const labelTitle = document.getElementById('masterFormLabel');
        if (labelTitle) {
            const mid = parseInt((document.getElementById('masterId') || {value: '0'}).value || '0', 10);
            labelTitle.textContent = (mid > 0 ? 'Edit Master Activity Divisi ' : 'Tambah Master Activity Divisi ') + label;
        }
        resetMasterForm(true); // keep current div only
    }
    function resetMasterForm(skipTabFocus) {
        const mid = document.getElementById('masterId');
        const nameInp = document.getElementById('masterActivityName');
        const stInp = document.getElementById('masterStatusDefault');
        const sortInp = document.getElementById('masterSortOrder');
        const divInp = document.getElementById('masterDivision');
        const titleLabel = document.getElementById('masterFormLabel');
        const formTitle = document.getElementById('masterFormTitle');
        if (mid) mid.value = '0';
        if (nameInp) { nameInp.value = ''; }
        if (stInp) { stInp.value = 'progress'; }
        if (sortInp) { sortInp.value = '0'; }
        const tab = window.CUR_MASTER_TAB || (divInp && divInp.value) || 'operation';
        const meta = window.DIV_META && window.DIV_META[tab] ? window.DIV_META[tab] : null;
        const label = meta ? meta.label : tab;
        if (divInp) divInp.value = tab;
        if (titleLabel) titleLabel.textContent = 'Tambah Master Activity Divisi ' + label;
        if (formTitle) {
            const iconWrap = formTitle.querySelector('span');
            if (iconWrap) { iconWrap.className = 'w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center shadow-sm'; iconWrap.innerHTML = '<i class="fas fa-plus text-[14px]"></i>'; }
        }
        if (!skipTabFocus && nameInp) setTimeout(() => nameInp.focus(), 50);
    }
    function editMaster(divId, masterId) {
        // Buka modal dan pindah tab ke divisi target (jika beda)
        const modal = document.getElementById('modal-master');
        if (!modal || !modal.classList.contains('flex')) openMasterModal(divId);
        else switchMasterTab(divId);
        setTimeout(() => {
            const nameEl   = document.getElementById('m-name-'   + masterId);
            const statusEl = document.getElementById('m-status-' + masterId);
            const sortEl   = document.getElementById('m-sort-'   + masterId);
            const midInp   = document.getElementById('masterId');
            const nameInp  = document.getElementById('masterActivityName');
            const stInp    = document.getElementById('masterStatusDefault');
            const sortInp  = document.getElementById('masterSortOrder');
            const labelTtl = document.getElementById('masterFormLabel');
            const formTitle = document.getElementById('masterFormTitle');
            if (!nameEl || !midInp) return;
            midInp.value = String(masterId);
            nameInp.value = nameEl.value || '';
            if (stInp && statusEl) stInp.value = statusEl.value || 'progress';
            if (sortInp && sortEl) sortInp.value = String((sortEl.value || '0'));
            const meta = window.DIV_META && window.DIV_META[divId] ? window.DIV_META[divId] : null;
            const label = meta ? meta.label : divId;
            if (labelTtl) labelTtl.textContent = 'Edit Master Activity Divisi ' + label + ' (ID #' + masterId + ')';
            if (formTitle) {
                const iconWrap = formTitle.querySelector('span');
                if (iconWrap) { iconWrap.className = 'w-8 h-8 rounded-lg bg-gradient-to-br from-amber-400 to-orange-600 text-white flex items-center justify-center shadow-sm'; iconWrap.innerHTML = '<i class="fas fa-pencil text-[13px]"></i>'; }
            }
            // Scroll form CREATE-EDIT ke view paling atas di body modal
            const body = document.querySelector('#modal-master .overflow-y-auto');
            if (body) body.scrollTo({ top: 0, behavior: 'smooth' });
            setTimeout(() => { if (nameInp) { nameInp.focus(); nameInp.select(); } }, 120);
        }, 120);
    }

    // ================================ JS: DINAMIS ROW INPUT TEXT MANUAL (TIDAK PAKAI DROPDOWN MASTER!) ================================
    function addActRow(divCode) {
        const map = {
            op: { prefix: 'act_op',       color: 'indigo', border: 'border-slate-200', bg: 'bg-slate-50/70' },
            mt: { prefix: 'act_mt',       color: 'indigo', border: 'border-slate-200', bg: 'bg-slate-50/70' },
            pr: { prefix: 'act_pr',       color: 'indigo', border: 'border-slate-200', bg: 'bg-slate-50/70' },
            la: { prefix: 'act_la',       color: 'indigo', border: 'border-slate-200', bg: 'bg-slate-50/70' },
        };
        const cfg = map[divCode];
        if (!cfg) return;
        const container = document.getElementById('actRows_' + divCode);
        if (!container) return;
        let curNum = parseInt(container.getAttribute('data-rows') || '0', 10);
        curNum += 1;
        container.setAttribute('data-rows', String(curNum));

        const row = document.createElement('div');
        row.className = 'flex flex-col sm:flex-row gap-2 items-stretch sm:items-center p-2.5 rounded-xl ' + cfg.bg + ' border ' + cfg.border + ' animate-fade-in';
        row.setAttribute('data-act-row', '1');

        row.innerHTML = `
            <span class="inline-flex w-8 h-8 rounded-lg bg-white border ` + cfg.border + ` items-center justify-center text-[11px] font-black text-slate-600 shrink-0 self-start sm:self-center">` + curNum + `</span>
            <div class="flex-1 min-w-0">
                <input type="text" name="` + cfg.prefix + `_text[]"
                    placeholder="Ketik nama aktivitas... Contoh: Perbaikan AC Lobby Lantai 2"
                    class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-semibold text-primary shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 transition" required>
            </div>
            <div class="sm:w-44">
                <select name="` + cfg.prefix + `_status[]"
                    class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-bold text-primary shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 transition appearance-none pr-8">
                    <option value="progress">⏳ In Progress</option>
                    <option value="complete">✅ Complete</option>
                </select>
            </div>
            <button type="button" onclick="removeActRow(this)"
                class="w-10 h-10 rounded-lg bg-slate-50 hover:bg-rose-500 text-slate-600 hover:text-white border border-slate-200 hover:border-rose-500 flex items-center justify-center transition shrink-0 self-start sm:self-center"
                aria-label="Hapus activity ini" title="Hapus baris ini">
                <i class="fas fa-trash-can text-[14px] hover:text-white"></i>
            </button>
        `;
        container.appendChild(row);

        // Auto focus input text baris baru agar user bisa langsung ketik
        setTimeout(() => {
            const inpTxt = row.querySelector('input[type="text"]');
            if (inpTxt) inpTxt.focus();
        }, 50);
    }
    function removeActRow(btn) {
        if (!btn) return;
        const row = btn.closest('[data-act-row]');
        const container = row ? row.parentElement : null;
        if (row) row.remove();
        if (container) {
            const allRows = container.querySelectorAll('[data-act-row]');
            let n = 0;
            allRows.forEach(r => {
                n++;
                const numSpan = r.querySelector('span:first-child');
                if (numSpan) numSpan.textContent = String(n);
            });
            container.setAttribute('data-rows', String(n));
        }
    }

    // ================================ ESCAPE KEY CLOSE SEMUA MODAL (TERMASUK MASTER) ================================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            // Close Master Modal dulu (jika terbuka)
            const mm = document.getElementById('modal-master');
            if (mm && mm.classList.contains('flex')) { closeMasterModal(); return; }
            // Close Divisi Modal
            document.querySelectorAll('[id^="modal-"]').forEach(function(m) {
                if (m.classList.contains('flex')) {
                    const id = m.id.replace('modal-', '');
                    closeModal(id);
                }
            });
        }
    });
    </script>
    <style>
    @keyframes fadeInModal { from { opacity: 0; } to { opacity: 1; } }
    @keyframes slideUpModal { from { opacity: 0; transform: translateY(30px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
    .animate-fade-in-modal { animation: fadeInModal 0.18s ease-out !important; }
    .animate-slide-up-modal { animation: slideUpModal 0.26s cubic-bezier(0.22, 1, 0.36, 1) !important; }
    </style>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
