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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_activity_counters') {
    $logDate = !empty($_POST['log_date']) ? (string)$_POST['log_date'] : $today;
    $engId = (int)($_POST['engineer_id'] ?? 0);
    $actOp = max(0, (int)($_POST['activity_operation'] ?? 0));
    $actMt = max(0, (int)($_POST['activity_maintenance'] ?? 0));
    $actPr = max(0, (int)($_POST['activity_project'] ?? 0));
    $actLa = max(0, (int)($_POST['activity_landscape'] ?? 0));
    $ok = [];

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
            $db->query(
                "UPDATE daily_logs SET activity_operation = ?, activity_maintenance = ?, activity_project = ?, activity_landscape = ? WHERE id = ?",
                [$actOp, $actMt, $actPr, $actLa, (int)$exist['id']]
            );
            addTimeline($db, 'daily_log', (int)$exist['id'], $userId, 'manager', 'update_activity',
                'Manager ('.$userName.') update counters: O='.$actOp.' M='.$actMt.' P='.$actPr.' L='.$actLa);
            setFlash('success', 'Counters Activity berhasil di-update untuk '.$engRow['name'].' ('.$logDate.')');
        } else {
            $notes = 'Diisi langsung oleh Manager - Approved Otomatis';
            $status = 'approved';
            $db->query(
                "INSERT INTO daily_logs (log_date, engineer_id, shift, status, notes, activity_operation, activity_maintenance, activity_project, activity_landscape, created_by, updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [$logDate, $engId, 'Day', $status, $notes, $actOp, $actMt, $actPr, $actLa, $userId, $userId]
            );
            $newId = (int)$db->lastInsertId();
            addTimeline($db, 'daily_log', $newId, $userId, 'manager', 'create_activity',
                'Manager ('.$userName.') buat counters baru: O='.$actOp.' M='.$actMt.' P='.$actPr.' L='.$actLa);
            setFlash('success', 'Counters Activity berhasil disimpan baru untuk '.$engRow['name'].' ('.$logDate.') ID: '.$newId);
        }
    } catch (Throwable $e) {
        setFlash('danger', 'Gagal menyimpan: '.$e->getMessage());
    }
    redirect('manager/activities.php');
}

$engineers = $db->fetchAll("SELECT id, name, role FROM users WHERE LOWER(role) = 'engineer' AND (status = 'active' OR status IS NULL OR status = '') ORDER BY name ASC");

function buildActCnt($db, $from, $to, $cat, $userId, $userRole) {
    $col = match($cat) {
        'operation'   => 'activity_operation',
        'maintenance' => 'activity_maintenance',
        'project'     => 'activity_project',
        'landscape'   => 'activity_landscape',
        default       => 'activity_operation'
    };
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

$cats = [
    [
        'id'    => 'operation',
        'label' => T('dash_act_operation', 'OPERATION'),
        'icon'  => 'fas fa-gears',
        'iconBox' => 'from-blue-400 to-blue-600',
        'ring' => 'ring-blue-200',
        'bg' => 'bg-blue-50',
        'border' => 'border-blue-200',
        'color' => 'text-blue-700'
    ],
    [
        'id'    => 'maintenance',
        'label' => T('dash_act_maintenance', 'MAINTENANCE'),
        'icon'  => 'fas fa-wrench',
        'iconBox' => 'from-emerald-400 to-emerald-600',
        'ring' => 'ring-emerald-200',
        'bg' => 'bg-emerald-50',
        'border' => 'border-emerald-200',
        'color' => 'text-emerald-700'
    ],
    [
        'id'    => 'project',
        'label' => T('dash_act_project', 'PROJECT'),
        'icon'  => 'fas fa-diagram-project',
        'iconBox' => 'from-violet-400 to-violet-600',
        'ring' => 'ring-violet-200',
        'bg' => 'bg-violet-50',
        'border' => 'border-violet-200',
        'color' => 'text-violet-700'
    ],
    [
        'id'    => 'landscape',
        'label' => T('dash_act_landscape', 'LANDSCAPE'),
        'icon'  => 'fas fa-leaf',
        'iconBox' => 'from-teal-400 to-teal-600',
        'ring' => 'ring-teal-200',
        'bg' => 'bg-teal-50',
        'border' => 'border-teal-200',
        'color' => 'text-teal-700'
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
        "SELECT dl.id, dl.log_date, dl.{$col} AS cnt, COALESCE(u.name, '-') AS engineer_name
         FROM daily_logs dl
         LEFT JOIN users u ON u.id = dl.engineer_id
         WHERE dl.log_date BETWEEN ? AND ? AND dl.{$col} > 0
         ORDER BY dl.log_date DESC, dl.id DESC",
        [$monthStart, $today]
    );
    $colLeft[] = $c;
    $colRight[] = $cnt > 0
        ? '<div class="flex items-center gap-2 text-sm font-bold '.$c['color'].'"><i class="far fa-calendar-check"></i> <span class="font-black">'.$cnt.'</span> '.$actMonthLabel.($todayN > 0 ? ' <span class="text-secondary/70 font-medium text-xs ml-1">(+'.$todayN.' '.$actTodayLabel.')</span>' : '').' <span class="ml-2 inline-flex items-center gap-1 text-[10px] font-extrabold uppercase tracking-wider text-primary/70 px-2 py-0.5 rounded-full bg-white/70 border border-slate-200 shadow-sm"><i class="fas fa-hand-pointer text-xs"></i> Klik Detail</span></div>'
        : '<div class="flex items-center gap-2 text-sm text-secondary/70 font-medium">'.$blankIcon.$actEmpty.'</div>';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
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
            <a href="<?= BASE_URL ?>reports/pdf.php" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gradient-to-br from-rose-500 to-rose-700 hover:from-rose-600 hover:to-rose-800 text-white text-sm font-bold shadow hover:shadow-lg transition-all">
                <i class="far fa-file-pdf"></i> <?= T('btn_export_pdf', 'Export PDF') ?>
            </a>
            <a href="<?= BASE_URL ?>reports/excel.php" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 hover:from-emerald-600 hover:to-emerald-800 text-white text-sm font-bold shadow hover:shadow-lg transition-all">
                <i class="far fa-file-excel"></i> <?= T('btn_export_excel', 'Export Excel') ?>
            </a>
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
                        <option value="">-- Pilih Staff Engineer --</option>
                        <?php foreach ($engineers as $e): ?>
                            <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-slate-500 mb-3 pl-1 flex items-center gap-2">
                <i class="fas fa-layer-group"></i> <?= T('eng_act_field_counters', 'Counters Aktivitas 4 Divisi') ?>
            </p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="rounded-2xl border border-blue-200 bg-blue-50/50 p-4 hover:shadow-lg transition">
                    <label class="flex items-center gap-2 mb-2">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white shadow">
                            <i class="fas fa-gears text-sm"></i>
                        </span>
                        <span class="font-black text-primary tracking-wide text-xs uppercase">Operation</span>
                    </label>
                    <input type="number" min="0" step="1" name="activity_operation" value="0"
                           class="w-full px-3 py-2.5 rounded-xl border border-blue-200 bg-white text-lg font-black text-primary text-center focus:outline-none focus:ring-2 focus:ring-blue-300 focus:border-blue-500">
                </div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 hover:shadow-lg transition">
                    <label class="flex items-center gap-2 mb-2">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center text-white shadow">
                            <i class="fas fa-wrench text-sm"></i>
                        </span>
                        <span class="font-black text-primary tracking-wide text-xs uppercase">Maintenance</span>
                    </label>
                    <input type="number" min="0" step="1" name="activity_maintenance" value="0"
                           class="w-full px-3 py-2.5 rounded-xl border border-emerald-200 bg-white text-lg font-black text-primary text-center focus:outline-none focus:ring-2 focus:ring-emerald-300 focus:border-emerald-500">
                </div>
                <div class="rounded-2xl border border-violet-200 bg-violet-50/50 p-4 hover:shadow-lg transition">
                    <label class="flex items-center gap-2 mb-2">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-400 to-violet-600 flex items-center justify-center text-white shadow">
                            <i class="fas fa-diagram-project text-sm"></i>
                        </span>
                        <span class="font-black text-primary tracking-wide text-xs uppercase">Project</span>
                    </label>
                    <input type="number" min="0" step="1" name="activity_project" value="0"
                           class="w-full px-3 py-2.5 rounded-xl border border-violet-200 bg-white text-lg font-black text-primary text-center focus:outline-none focus:ring-2 focus:ring-violet-300 focus:border-violet-500">
                </div>
                <div class="rounded-2xl border border-teal-200 bg-teal-50/50 p-4 hover:shadow-lg transition">
                    <label class="flex items-center gap-2 mb-2">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-teal-400 to-teal-600 flex items-center justify-center text-white shadow">
                            <i class="fas fa-leaf text-sm"></i>
                        </span>
                        <span class="font-black text-primary tracking-wide text-xs uppercase">Landscape</span>
                    </label>
                    <input type="number" min="0" step="1" name="activity_landscape" value="0"
                           class="w-full px-3 py-2.5 rounded-xl border border-teal-200 bg-white text-lg font-black text-primary text-center focus:outline-none focus:ring-2 focus:ring-teal-300 focus:border-teal-500">
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
            <?php foreach ($colLeft as $idx => $c): ?>
            <div class="group rounded-2xl border-2 <?= $c['border'] ?> <?= $c['bg'] ?>/40 p-4 sm:p-5 hover:shadow-2xl hover:-translate-y-0.5 hover:scale-[1.005] hover:ring-4 <?= $c['ring'] ?> transition-all duration-300 cursor-pointer active:scale-[0.998]"
                 onclick="openModal('<?= htmlspecialchars($c['id']) ?>')"
                 role="button"
                 tabindex="0"
                 aria-label="Buka detail divisi <?= htmlspecialchars($c['label']) ?>"
                 onkeydown="if(event.key==='Enter'||event.key===' ')openModal('<?= htmlspecialchars($c['id']) ?>')">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="flex items-center gap-3 sm:gap-4 shrink-0 sm:w-56">
                        <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-gradient-to-br <?= $c['iconBox'] ?> flex items-center justify-center text-white shadow-lg ring-2 <?= $c['ring'] ?> shrink-0 group-hover:rotate-3 group-hover:scale-105 transition-transform duration-300">
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
    ?>
    <div id="modal-<?= htmlspecialchars($c['id']) ?>" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4 animate-fade-in-modal"
         onclick="if(event.target===this)closeModal('<?= htmlspecialchars($c['id']) ?>')">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md"></div>
        <div class="relative w-full max-w-4xl max-h-[88vh] bg-white rounded-3xl shadow-[0_30px_100px_-20px_rgba(30,41,59,0.45)] overflow-hidden flex flex-col animate-slide-up-modal border-2 <?= $c['border'] ?>">
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
                            <div class="flex items-center gap-3 mt-2">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/20 backdrop-blur-sm border border-white/30 text-white text-xs font-bold">
                                    <i class="fas fa-list-check"></i> Total Data: <span class="font-black text-white"><?= count($rows) ?> aktivitas</span>
                                </span>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white text-slate-800 text-xs font-black shadow">
                                    <i class="<?= $c['icon'] ?> <?= $c['color'] ?>"></i> Total Counters: <?= number_format($totalSum, 0, ',', '.') ?>
                                </span>
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
                <?php if (empty($rows)): ?>
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
                    <div class="overflow-x-auto rounded-2xl border border-slate-200 shadow-sm bg-white">
                        <table class="w-full text-sm">
                            <thead class="bg-gradient-to-r from-slate-50 to-slate-100 border-b border-slate-200">
                                <tr>
                                    <th class="px-4 py-3.5 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 w-14">#</th>
                                    <th class="px-4 py-3.5 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 w-24">Ref ID</th>
                                    <th class="px-4 py-3.5 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Tanggal</th>
                                    <th class="px-4 py-3.5 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Nama Engineer / Staff</th>
                                    <th class="px-4 py-3.5 text-right text-[10px] font-black uppercase tracking-widest text-slate-500 pr-5">Counters<br><span class="text-[9px] <?= $c['color'] ?> font-bold">Divisi <?= $c['label'] ?></span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php
                                $no = 1;
                                $runningSum = 0;
                                foreach ($rows as $r):
                                    $runningSum += (int)($r['cnt'] ?? 0);
                                ?>
                                <tr class="hover:bg-<?= $c['color'] ?>/5 transition-colors group">
                                    <td class="px-4 py-3.5 text-xs font-bold text-slate-400"><?= $no++ ?>.</td>
                                    <td class="px-4 py-3.5">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-900/5 border border-slate-200 text-xs font-mono font-bold text-slate-700">
                                            #<?= (int)$r['id'] ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex w-8 h-8 rounded-lg bg-slate-100 group-hover:bg-<?= $c['color'] ?>/10 items-center justify-center text-slate-500 group-hover:<?= $c['color'] ?> transition">
                                                <i class="far fa-calendar-day text-xs"></i>
                                            </span>
                                            <div class="flex flex-col">
                                                <span class="text-sm font-bold text-primary"><?= date('d M Y', strtotime($r['log_date'])) ?></span>
                                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400"><?= date('l', strtotime($r['log_date'])) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 text-white flex items-center justify-center font-black text-xs shadow-sm ring-2 ring-white">
                                                <?= strtoupper(mb_substr((string)$r['engineer_name'], 0, 1) ?: '?') ?>
                                            </div>
                                            <div class="flex flex-col">
                                                <span class="text-sm font-bold text-primary leading-tight"><?= htmlspecialchars($r['engineer_name']) ?></span>
                                                <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Engineering Staff</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3.5 pr-5 text-right">
                                        <span class="inline-flex items-center justify-end gap-1.5 min-w-[90px] px-3.5 py-2 rounded-xl <?= $c['bg'] ?> border <?= $c['border'] ?> text-base sm:text-lg font-black <?= $c['color'] ?> shadow-sm">
                                            <i class="<?= $c['icon'] ?> text-sm"></i>
                                            <?= number_format((int)($r['cnt'] ?? 0), 0, ',', '.') ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-gradient-to-r from-slate-50 to-<?= $c['color'] ?>/5 border-t-2 <?= $c['border'] ?>">
                                <tr>
                                    <td colspan="4" class="px-4 py-4 text-right">
                                        <span class="text-sm font-black uppercase tracking-widest text-slate-500 mr-2 flex items-center justify-end gap-2">
                                            <i class="fas fa-calculator"></i> TOTAL COUNTERS DIVISI <?= $c['label'] ?> BULAN INI:
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 pr-5 text-right">
                                        <span class="inline-flex items-center justify-end gap-2 min-w-[120px] px-4 py-2.5 rounded-2xl bg-gradient-to-br <?= $c['iconBox'] ?> text-white text-lg sm:text-xl font-black shadow-lg shadow-slate-500/10 ring-2 ring-white/80">
                                            <i class="<?= $c['icon'] ?>"></i>
                                            <?= number_format($totalSum, 0, ',', '.') ?>
                                        </span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

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
    <!-- ================================ / END MODAL ================================ -->

    <script>
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
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
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
