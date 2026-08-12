<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = T('select_date_title', 'Pilih Tanggal Daily Log');
requireRole(['engineer', 'supervisor']);

$db = Database::getInstance();
$user = currentUser();

$month = $_GET['month'] ?? date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$logs = $db->fetchAll(
    "SELECT log_date, status FROM daily_logs WHERE engineer_id = ? AND log_date BETWEEN ? AND ?",
    [$user['id'], $monthStart, $monthEnd]
);
$logMap = [];
foreach ($logs as $l) $logMap[$l['log_date']] = $l['status'];

$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));

$daysInMonth = (int)date('t', strtotime($monthStart));
$firstDayOfWeek = (int)date('N', strtotime($monthStart)) - 1;
$monthName = date('F Y', strtotime($monthStart));

$_selYm = explode('-', $month . '-01');
$_curMonth = (int)($_selYm[1] ?? date('n'));
$_curYear  = (int)($_selYm[0] ?? date('Y'));
$_monthNames = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$_yearFrom = max(2020, (int)date('Y') - 6);
$_yearTo   = (int)date('Y') + 5;
$_yearOpts = range($_yearFrom, $_yearTo);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-shell page-shell--3xl pb-20 sm:pb-10">
    <div class="mb-6 animate-fade-in">
        <a href="<?= BASE_URL ?>index.php" class="inline-flex items-center gap-1.5 text-xs sm:text-sm text-secondary hover:text-primary mb-3 transition-colors">
            <i class="fas fa-chevron-left text-[10px] sm:text-xs"></i> <?= T('general_back', 'Kembali') ?>
        </a>
        <h1 class="font-display text-xl lg:text-2xl font-bold text-primary mb-1.5">
            <i class="fas fa-calendar-days mr-2 text-accent"></i><?= T('select_date_title', 'Pilih Tanggal Daily Log') ?>
        </h1>
        <p class="text-secondary text-xs sm:text-sm"><?= T('select_date_sub', 'Pilih tanggal untuk mengisi atau mengedit Daily Log Engineering') ?></p>
    </div>

    <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up">
        <div class="p-3 sm:p-4 border-b border-border bg-gradient-to-r from-muted/50 to-surface">
            <div class="flex items-center justify-between gap-2">
                <a href="?month=<?= $prevMonth ?>" class="p-1.5 sm:p-2 rounded-full bg-surface border border-border hover:bg-muted transition-colors text-secondary hover:text-primary flex-shrink-0" title="<?= T('general_back', 'Bulan sebelumnya') ?>">
                    <i class="fas fa-chevron-left text-xs"></i>
                </a>
                <div class="text-center min-w-0 flex-1 px-1">
                    <div class="flex items-center justify-center gap-1.5 sm:gap-2 flex-wrap">
                        <select id="pickMonth" class="!bg-transparent !border-0 !outline-none !ring-0 !shadow-none font-display text-lg lg:text-xl font-bold text-primary text-center cursor-pointer hover:bg-muted/50 rounded-lg px-2 py-1 transition-colors appearance-none">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $_curMonth === $m ? 'selected' : '' ?>><?= $_monthNames[$m] ?></option>
                            <?php endfor; ?>
                        </select>
                        <select id="pickYear" class="!bg-transparent !border-0 !outline-none !ring-0 !shadow-none font-display text-lg lg:text-xl font-bold text-primary text-center cursor-pointer hover:bg-muted/50 rounded-lg px-2 py-1 transition-colors appearance-none w-auto min-w-[5rem]">
                            <?php foreach ($_yearOpts as $y): ?>
                                <option value="<?= $y ?>" <?= $_curYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="text-[10px] sm:text-xs text-secondary mt-0.5 truncate"><?= T('select_click_fill', 'Klik tanggal putih untuk mengisi') ?></p>
                </div>
                <script>
                (function(){
                    function pad(n){return String(n).padStart(2,'0');}
                    function go(){
                        var m = parseInt(document.getElementById('pickMonth').value,10);
                        var y = parseInt(document.getElementById('pickYear').value,10);
                        if (!m || !y) return;
                        window.location.href = '<?= BASE_URL ?>engineer/select_date.php?month=' + y + '-' + pad(m);
                    }
                    var pm = document.getElementById('pickMonth');
                    var py = document.getElementById('pickYear');
                    if (pm) pm.addEventListener('change', go);
                    if (py) py.addEventListener('change', go);
                })();
                </script>
                <a href="?month=<?= $nextMonth ?>" class="p-1.5 sm:p-2 rounded-full bg-surface border border-border hover:bg-muted transition-colors text-secondary hover:text-primary flex-shrink-0" title="<?= T('select_next', 'Bulan selanjutnya') ?>">
                    <i class="fas fa-chevron-right text-xs"></i>
                </a>
            </div>
        </div>

        <div class="p-2.5 sm:p-3">
            <div class="grid grid-cols-7 gap-[2px] sm:gap-1 mb-1 sm:mb-1.5">
                <?php foreach (['Sen','Sel','Rab','Kam','Jum','Sab','Min'] as $d): ?>
                    <div class="text-center text-[9px] sm:text-[11px] font-semibold text-secondary py-1 sm:py-1.5"><?= $d ?></div>
                <?php endforeach; ?>
            </div>

            <div class="grid grid-cols-7 gap-[2px] sm:gap-1">
                <?php for ($i = 0; $i < $firstDayOfWeek; $i++): ?>
                    <div></div>
                <?php endfor; ?>

                <?php
                $today = date('Y-m-d');
                for ($d = 1; $d <= $daysInMonth; $d++):
                    $dateStr = sprintf('%s-%02d', $month, $d);
                    $status = $logMap[$dateStr] ?? null;
                    $isToday = $dateStr === $today;
                    $isFuture = $dateStr > $today;

                    $class = 'aspect-square rounded-card flex flex-col items-center justify-center text-center font-medium transition-all duration-300 cursor-pointer border overflow-hidden ';
                    if ($status === 'approved') {
                        $class .= 'bg-green-50 border-green-200 text-green-700 hover:bg-green-100 ';
                    } elseif ($status === 'pending') {
                        $class .= 'bg-yellow-50 border-yellow-200 text-yellow-700 hover:bg-yellow-100 ';
                    } elseif ($status === 'rejected') {
                        $class .= 'bg-red-50 border-red-200 text-red-700 hover:bg-red-100 ';
                    } elseif ($isFuture) {
                        $class .= 'bg-muted/50 border-border text-secondary/40 cursor-not-allowed ';
                    } else {
                        $class .= 'bg-surface border-border text-primary hover:bg-primary hover:text-white hover:border-primary hover:shadow-md hover:-translate-y-0.5 ';
                    }
                    if ($isToday && !$status) $class .= 'ring-2 ring-accent ring-offset-1 animate-pulse-glow ';
                ?>
                    <?php if (!$isFuture): ?>
                        <a href="daily_log_form.php?date=<?= $dateStr ?>" class="<?= $class ?>">
                    <?php else: ?>
                        <div class="<?= $class ?>">
                    <?php endif; ?>
                            <span class="text-sm sm:text-base font-bold leading-none mb-0.5"><?= $d ?></span>
                            <?php if ($status === 'approved'): ?>
                                <i class="fas fa-circle-check text-[8px] sm:text-[10px] mt-0.5"></i>
                            <?php elseif ($status === 'pending'): ?>
                                <i class="fas fa-clock text-[8px] sm:text-[10px] mt-0.5"></i>
                            <?php elseif ($status === 'rejected'): ?>
                                <i class="fas fa-circle-xmark text-[8px] sm:text-[10px] mt-0.5"></i>
                            <?php elseif ($isToday): ?>
                                <span class="hidden sm:block text-[9px] font-bold mt-0.5 text-accent tracking-wide"><?= mb_strtoupper(T('wel_today', 'Hari Ini')) ?></span>
                                <span class="sm:hidden w-1 h-1 rounded-full bg-accent mt-0.5"></span>
                            <?php endif; ?>
                    <?php if (!$isFuture): ?>
                        </a>
                    <?php else: ?>
                        </div>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>

            <div class="mt-5 pt-4 border-t border-border">
                <p class="text-[10px] sm:text-xs font-semibold text-secondary uppercase tracking-wider mb-2"><?= T('general_note', 'Keterangan') ?>:</p>
                <div class="flex flex-wrap gap-3 sm:gap-4 text-[11px] sm:text-xs">
                    <div class="flex items-center gap-1.5"><div class="w-3.5 h-3.5 rounded bg-surface border border-border"></div><span class="text-secondary"><?= T('select_belum', 'Belum Diisi') ?></span></div>
                    <div class="flex items-center gap-1.5"><div class="w-3.5 h-3.5 rounded bg-yellow-50 border border-yellow-200"></div><span class="text-secondary"><?= T('stat_label_pending', 'Pending') ?></span></div>
                    <div class="flex items-center gap-1.5"><div class="w-3.5 h-3.5 rounded bg-green-50 border border-green-200"></div><span class="text-secondary"><?= T('stat_label_approved', 'Approved') ?></span></div>
                    <div class="flex items-center gap-1.5"><div class="w-3.5 h-3.5 rounded bg-red-50 border border-red-200"></div><span class="text-secondary"><?= T('stat_label_rejected', 'Rejected') ?></span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
