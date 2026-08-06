<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = T('dash_logistic_page_title', 'Dashboard Logistik & Order Request');
requireRole(['supervisor', 'manager']);

$db = Database::getInstance();
$user = currentUser();
$userName = (string)($user['name']  ?? 'User');
$userRole = (string)($user['role']  ?? 'engineer');
$userId = (int)($user['id']      ?? 0);

$orderWhere = $userRole === 'engineer' ? "WHERE requested_by = $userId" : "";
$orderCounts = ['all'=>0,'pending_supervisor'=>0,'pending_manager'=>0,'approved'=>0,'rejected'=>0];
if (in_array($userRole, ['supervisor','engineer','manager','admin'], true)) {
    $orderSQL = "SELECT status, COUNT(*) as cnt FROM orders $orderWhere GROUP BY status";
    foreach ($db->fetchAll($orderSQL) as $row) {
        $orderCounts[$row['status']] = (int)$row['cnt'];
        $orderCounts['all'] += (int)$row['cnt'];
    }
    if ($userRole === 'manager') {
        $orderCounts['my_pending'] = (int)$orderCounts['pending_manager'];
    } elseif ($userRole === 'supervisor') {
        $orderCounts['my_pending'] = (int)$orderCounts['pending_supervisor'];
    }
}
if ($userRole === 'manager') {
    $orderCounts['my_pending'] = (int)($orderCounts['my_pending'] ?? $orderCounts['pending_manager']);
} elseif ($userRole === 'supervisor') {
    $orderCounts['my_pending'] = (int)($orderCounts['my_pending'] ?? $orderCounts['pending_supervisor']);
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="page-shell page-shell--7xl">
    <div class="page-header flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6 animate-fade-in">
        <div>
            <p class="text-[11px] font-black uppercase tracking-[0.25em] text-amber-700 mb-2">PROCUREMENT • LOGISTIC</p>
            <h1 class="font-display text-3xl font-black text-primary flex items-center gap-3">
                <span class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-amber-800 flex items-center justify-center text-white shadow-md shadow-amber-500/30">
                    <i class="fas fa-boxes-stacked"></i>
                </span>
                <?= T('dash_logistic_title', 'Logistik & Order Request') ?>
            </h1>
            <p class="text-sm text-secondary mt-2"><?= T('dash_logistic_sub', 'Ringkasan status Order / Purchase Request dan pipeline Approval') ?></p>
        </div>
        <div class="flex flex-wrap gap-2.5 self-start">
            <a href="<?= BASE_URL ?>orders/create.php" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 hover:from-emerald-600 hover:to-emerald-800 text-white text-sm font-bold shadow hover:shadow-lg hover:-translate-y-0.5 transition-all">
                <i class="fas fa-file-circle-plus"></i> <?= T('order_btn_new_request', 'Buat Request Baru →') ?>
            </a>
            <a href="<?= BASE_URL ?>orders/index.php" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-gradient-to-br from-amber-500 to-amber-700 hover:from-amber-600 hover:to-amber-800 text-white text-sm font-bold shadow hover:shadow-lg hover:-translate-y-0.5 transition-all">
                <i class="fas fa-clipboard-list"></i> <?= T('order_menu_all', 'Semua Order') ?>
            </a>
        </div>
    </div>

    <!-- ============ FLOWCHART SESUAI KERTAS TULIS TANGAN CUSTOMER ============ -->
    <div class="card-premium p-5 sm:p-7 mb-8 bg-gradient-to-b from-white via-sky-50/20 to-white ring-1 ring-sky-100 shadow-sm animate-slide-up" style="animation-delay: 80ms">
        <div class="mb-6 flex items-end gap-3 border-b-2 border-dashed border-sky-200 pb-4">
            <h3 class="font-handwriting font-black text-3xl sm:text-4xl text-sky-900 tracking-wide" style="transform: rotate(-1deg)">LOGISTIC</h3>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-sky-600 mb-1.5">Flow Order Request sesuai catatan kertas</p>
        </div>

        <div class="relative max-w-3xl mx-auto space-y-1">
            <?php
            $flowItems = [
                [
                    'num' => '①', 'hash' => true, 'title' => 'REQUEST ORDER',
                    'note' => 'Tampilan dashboard.',
                    'href' => BASE_URL . 'orders/create.php',
                    'rotate' => '-rotate-[0.4deg]',
                    'color' => 'amber',
                    'boxInner' => null
                ],
                [
                    'num' => '②', 'hash' => false, 'title' => 'Order by : Pilih Eng',
                    'note' => 'nama eng di data master.',
                    'rotate' => 'rotate-[0.3deg]',
                    'color' => 'slate'
                ],
                [
                    'num' => '③', 'hash' => false, 'title' => 'Pilih account.',
                    'note' => 'Account di buat di data master.',
                    'rotate' => '-rotate-[0.25deg]',
                    'color' => 'slate'
                ],
                [
                    'num' => '④', 'hash' => false, 'title' => 'Item Order QTY',
                    'note' => 'Bisa dikecil / tambah baris.',
                    'rotate' => 'rotate-[0.4deg]',
                    'color' => 'slate',
                    'boxInner' => '<div class="mt-3 pl-2 pr-5 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs font-mono text-sky-800"><div class="flex gap-2 border-b border-sky-200/60 pb-0.5"><span>1</span><span class="text-slate-400">.............</span><span class="text-slate-400">.............</span></div><div class="flex gap-2 border-b border-sky-200/60 pb-0.5"><span>2</span><span class="text-slate-400">.............</span><span class="text-slate-400">.............</span></div><div class="flex gap-2 border-b border-sky-200/60 pb-0.5"><span>3</span><span class="text-slate-400">.............</span><span class="text-slate-400">.............</span></div></div>'
                ],
                [
                    'num' => '⑤', 'hash' => false, 'title' => 'REQ NUMBER',
                    'note' => '➝  Input oleh admin dan isi harga.',
                    'rotate' => '-rotate-[0.3deg]',
                    'color' => 'slate'
                ],
                [
                    'num' => '⑥', 'hash' => true, 'title' => 'APPROVAL · 1',
                    'note' => null,
                    'rotate' => 'rotate-[0.35deg]',
                    'color' => 'orange'
                ],
                [
                    'num' => '⑥', 'hash' => false, 'title' => 'Approved by.',
                    'note' => 'Yang punya account.',
                    'rotate' => '-rotate-[0.2deg]',
                    'color' => 'slate',
                    'strike' => 'Pilih nama eng / manager'
                ],
                [
                    'num' => '⑦', 'hash' => true, 'title' => 'Approval 2',
                    'note' => null,
                    'rotate' => 'rotate-[0.3deg]',
                    'color' => 'emerald'
                ],
                [
                    'num' => '⑦', 'hash' => false, 'title' => 'Approved by.',
                    'note' => 'Pilih orang yang punya account.',
                    'rotate' => '-rotate-[0.25deg]',
                    'color' => 'slate'
                ],
            ];

            $total = count($flowItems);
            foreach ($flowItems as $idx => $f) {
                $isLast = ($idx === $total - 1);
                $num = $f['num'];
                $title = htmlspecialchars($f['title'] ?? '', ENT_QUOTES);
                $note = $f['note'] ?? null;
                $hash = !empty($f['hash']);
                $href = $f['href'] ?? null;
                $rot = $f['rotate'] ?? 'rotate-0';
                $color = $f['color'] ?? 'slate';
                $strike = $f['strike'] ?? null;
                $boxInner = $f['boxInner'] ?? null;

                $colTitle = match($color) {
                    'amber'   => 'from-amber-500 to-orange-600',
                    'orange'  => 'from-orange-500 to-amber-600',
                    'emerald' => 'from-emerald-500 to-teal-600',
                    default   => 'from-sky-600 to-indigo-700'
                };
                $colBorder = match($color) {
                    'amber'   => 'border-amber-400/80 bg-amber-50/70',
                    'orange'  => 'border-orange-400/80 bg-orange-50/70',
                    'emerald' => 'border-emerald-400/80 bg-emerald-50/70',
                    default   => 'border-sky-600/70 bg-white'
                };
                $colHash = match($color) {
                    'amber'   => 'text-amber-600',
                    'orange'  => 'text-orange-600',
                    'emerald' => 'text-emerald-600',
                    default   => 'text-sky-700'
                };

                $wrapOpen = $href ? ('<a href="'.htmlspecialchars($href, ENT_QUOTES).'" class="block group">') : '<div class="block">';
                $wrapClose = $href ? '</a>' : '</div>';
            ?>
                <div class="relative pl-10 py-1">
                    <?php if ($hash): ?>
                        <span class="absolute left-0 top-4 text-xl font-black <?= $colHash ?> select-none" title="Tanda check list">#</span>
                    <?php endif; ?>

                    <?= $wrapOpen ?>
                        <div class="relative border-2 border-sky-700/80 rounded-lg p-3.5 pr-5 <?= $colBorder ?> shadow-sm transition-all duration-200 transform <?= $rot ?> <?= ($href ? 'hover:-translate-y-0.5 hover:shadow-lg hover:shadow-sky-500/10 hover:border-sky-800' : '') ?>">
                            <div class="flex items-start gap-3">
                                <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-gradient-to-br <?= $colTitle ?> text-white text-base font-black shadow-md ring-2 ring-white/80"><?= $num ?></span>
                                <div class="min-w-0 flex-1 pt-0.5">
                                    <h4 class="font-display font-black text-base sm:text-lg text-sky-950 tracking-wide leading-tight <?= ($href ? 'group-hover:text-sky-800' : '') ?>"><?= $title ?></h4>
                                    <?php if ($strike): ?>
                                        <p class="mt-1 text-xs text-slate-500 line-through decoration-2 decoration-red-400/70 font-medium"><?= htmlspecialchars($strike) ?></p>
                                    <?php endif; ?>
                                    <?php if ($note): ?>
                                        <p class="mt-1.5 text-xs sm:text-sm text-slate-600 font-medium leading-snug"><?= htmlspecialchars($note) ?></p>
                                    <?php endif; ?>
                                    <?php if ($boxInner) echo $boxInner; ?>
                                </div>
                                <?php if ($href): ?>
                                    <i class="fas fa-arrow-right text-sm text-amber-600 opacity-0 group-hover:opacity-100 -translate-x-2 group-hover:translate-x-0 transition-all mt-1.5 shrink-0"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?= $wrapClose ?>

                    <?php if (!$isLast): ?>
                        <div class="absolute left-[22px] top-full h-7 w-[2px] bg-gradient-to-b from-sky-600/80 to-sky-400/60 -translate-x-1/2"></div>
                        <div class="absolute left-[22px] top-[calc(100%+1.6rem)] h-0 w-0 -translate-x-1/2 border-l-[7px] border-r-[7px] border-t-[10px] border-l-transparent border-r-transparent border-t-sky-600/80"></div>
                    <?php endif; ?>
                </div>
            <?php } ?>
        </div>
    </div>

    <div class="bg-surface rounded-premium border border-amber-200/70 shadow-sm overflow-hidden mb-8 animate-slide-up" style="animation-delay: 100ms">
        <div class="p-5 lg:p-6">
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                <?php
                $allOrder = (int)($orderCounts['all'] ?? 0);
                $pendingMine = (int)($orderCounts['my_pending'] ?? 0);
                $spvPen = (int)($orderCounts['pending_supervisor'] ?? 0);
                $mgrPen = (int)($orderCounts['pending_manager'] ?? 0);
                $approve = (int)($orderCounts['approved'] ?? 0);
                $reject = (int)($orderCounts['rejected'] ?? 0);

                $orderBadge = [
                    [T('stat_label_total', 'TOTAL'), $allOrder, T('order_stat_all', 'Total Order / PR'), 'from-slate-100 to-slate-200', 'bg-slate-50', 'border-slate-200', 'text-slate-600', 'text-slate-800', 'fa-clipboard-list', BASE_URL . 'orders/index.php?filter=all'],
                    [$userRole === 'manager' ? T('order_stat_pending_mgr', 'PERLU APPROVE MGR') : T('order_stat_pending_spv', 'PERLU APPROVE SPV'), $pendingMine, T('order_stat_pending', 'Belum di-proses saya'), 'from-amber-100 to-yellow-200', 'bg-yellow-50', 'border-yellow-200', 'text-amber-700', 'text-amber-800', 'fa-clock', BASE_URL . 'orders/index.php?filter=my_pending'],
                    [T('order_stat_pending_spv', 'PERLU APPROVE SPV'), $spvPen, T('order_stat_wait_spv', 'Menunggu Supervisor'), 'from-orange-100 to-orange-200', 'bg-orange-50', 'border-orange-200', 'text-orange-700', 'text-orange-800', 'fa-hourglass-half', BASE_URL . 'orders/index.php?filter=pending_supervisor'],
                    [T('order_stat_pending_mgr', 'PERLU APPROVE MGR'), $mgrPen, T('order_stat_wait_mgr', 'Menunggu Manager'), 'from-blue-100 to-blue-200', 'bg-blue-50', 'border-blue-200', 'text-blue-700', 'text-blue-800', 'fa-hourglass-start', BASE_URL . 'orders/index.php?filter=pending_manager'],
                    [T('stat_label_approved', 'APPROVED'), $approve, T('order_stat_approved', 'Sudah Final Approval'), 'from-emerald-100 to-emerald-200', 'bg-emerald-50', 'border-emerald-200', 'text-emerald-700', 'text-emerald-800', 'fa-circle-check', BASE_URL . 'orders/index.php?filter=approved'],
                ];
                if (!in_array($userRole, ['manager','supervisor'], true)) {
                    $orderBadge[1] = [T('order_stat_rejected', 'DITOLAK'), $reject, T('order_stat_rejected_sub', 'Order Ditolak'), 'from-red-100 to-red-200', 'bg-red-50', 'border-red-200', 'text-red-700', 'text-red-800', 'fa-circle-xmark', BASE_URL . 'orders/index.php?filter=rejected'];
                }
                foreach ($orderBadge as $ob) {
                    [$topLbl, $num, $botLbl, $iconBg, $bg, $bor, $col, $colNum, $icon, $href] = $ob;
                ?>
                    <a href="<?= $href ?>" class="rounded-2xl border <?= $bor ?> <?= $bg ?> p-4 sm:p-5 shadow-sm hover:shadow-xl hover:shadow-amber-500/10 hover:-translate-y-1 hover:scale-[1.02] cursor-pointer transition-all duration-300 block">
                        <div class="flex items-start justify-between mb-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br <?= $iconBg ?> flex items-center justify-center shadow-md">
                                <i class="fas <?= $icon ?> text-white text-xl"></i>
                            </div>
                            <p class="text-[10px] font-black uppercase tracking-wider <?= $col ?>"><?= $topLbl ?></p>
                        </div>
                        <p class="text-3xl lg:text-4xl font-black <?= $colNum ?> leading-none mb-2"><?= $num ?></p>
                        <p class="text-[11px] text-secondary font-semibold leading-snug"><?= $botLbl ?></p>
                    </a>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php';
