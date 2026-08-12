<?php
$user = currentUser();
if (!$user) return;
$displayName = (string)($user['name'] ?? 'User');
$displayEmail = (string)($user['email'] ?? '');
$initial = strtoupper(mb_substr($displayName, 0, 1) ?: 'U');
$roleLower = strtolower((string)($user['role'] ?? ''));
$isEngineer = $roleLower === 'engineer';
$isSupervisor = $roleLower === 'supervisor';
$isManager = $roleLower === 'manager';
$isManagerOrSpv = $isManager || $isSupervisor;
$currentFile = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

$roleBadge = 'Engineer';
if ($isSupervisor) $roleBadge = 'Supervisor';
if ($isManager) $roleBadge = 'Manager';

$isDashboard = $currentFile === 'index.php' && !in_array($currentDir, ['users','engineer','supervisor','manager','reports','profile','orders']);
$isEnergyDashboard = ($currentFile === 'energy.php');
$isEnergyLogsheet  = ($currentFile === 'energy_logsheet.php');
$isDailyLog = $currentDir === 'engineer' && in_array($currentFile, ['select_date.php', 'daily_log_form.php']);
$isReview = $currentDir === 'supervisor' && in_array($currentFile, ['review.php', 'review_detail.php']);
$isAnyEnergy       = $isEnergyDashboard || $isEnergyLogsheet || $isDailyLog || $isReview;
$isHistory = $currentFile === 'history.php';
$isManageUsers = $currentDir === 'supervisor' && $currentFile === 'users' && in_array($currentFile, ['index.php', 'create.php', 'edit.php']);
if ($currentDir !== 'supervisor') $isManageUsers = $currentDir === 'users' && in_array($currentFile, ['index.php', 'create.php', 'edit.php']);

$isOrderIndex = $currentDir === 'orders' && $currentFile === 'index.php';
$isOrderCreate = $currentDir === 'orders' && $currentFile === 'create.php';
$isOrderDetail = $currentDir === 'orders' && $currentFile === 'detail.php';
$isOrderApprove = $currentDir === 'orders' && in_array($currentFile, ['approve.php']);
$isEngActivities = ($page ?? '') === 'engineering_activities' || ($currentDir === 'manager' && $currentFile === 'activities.php');
$isMasterCostCode = ($currentDir === 'manager' && $currentFile === 'master_cost_codes.php');
$isAnyDataMaster = $isMasterCostCode;
$isUsersPage = ($currentDir === 'manager' && $currentFile === 'users.php');

$isAnyManagerArea = $isUsersPage || $isEngActivities || $isAnyDataMaster;
$isAnyDailyLogArea = $isDailyLog;
$isAnyApprovalArea = $isReview;
$isAnyLogisticArea = $isOrderIndex || $isOrderCreate || $isOrderDetail || $isOrderApprove;

$db = Database::getInstance();
$pendingCount = 0;
$pendingOrderCount = 0;
if ($isSupervisor || $isManager) {
    // Manager Access All: bisa lihat pending review daily_logs juga (sama seperti Supervisor)
    $pendingCount = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE status = 'pending'")['cnt'] ?? 0);
}
if ($isSupervisor) {
    $pendingOrderCount = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM orders WHERE status = 'pending_supervisor'")['cnt'] ?? 0);
}
if ($isManager) {
    $pendingOrderCount = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM orders WHERE status = 'pending_manager'")['cnt'] ?? 0);
}
if ($isEngineer) {
    $myId = (int)($user['id'] ?? 0);
    if ($myId > 0) {
        $pendingOrderCount = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM orders WHERE requested_by = ? AND status IN ('pending_supervisor','pending_manager','rejected')", [$myId])['cnt'] ?? 0);
    }
}

$sbBase = 'flex items-center gap-3 w-full rounded-xl px-3.5 py-2.5 text-sm font-semibold text-slate-600 transition-all duration-150 hover:bg-slate-100 hover:text-slate-900';
$sbBaseActive = '!bg-slate-100 !text-slate-900 !font-bold';
$sbIconBase = 'w-8 h-8 shrink-0 flex items-center justify-center rounded-lg text-lg text-slate-500';
$sbIconActive = '!text-slate-900 !bg-slate-200';
$sbCountBadge = 'ml-auto inline-flex items-center justify-center h-5 min-w-[1.25rem] px-1.5 rounded-md bg-slate-100 text-slate-500 text-[11px] font-black tracking-tight';
$sbCountBadgeActive = '!bg-slate-200 !text-slate-700';
?>
<div class="app-layout is-sidebar-expanded" id="appLayout">
<aside id="sidebar" class="sidebar sidebar-expanded !bg-white !border-r !border-slate-200 !shadow-none">
    <div class="sidebar-inner !p-0 !bg-white">
        <div class="sidebar-brand !border-b !border-slate-100 !px-4 !pt-4 !pb-4 min-h-[88px] relative group/brand">
            <button type="button" id="sidebarToggle" class="sidebar-toggle hidden md:inline-flex sidebar-btn-topright z-50" aria-label="Toggle Sidebar" title="Collapse / Expand">
                <i class="fas fa-bars-staggered transition-transform duration-300"></i>
            </button>
            <button type="button" id="sidebarToggleMobile" class="sidebar-toggle-mobile md:hidden sidebar-btn-topright z-50" aria-label="Close Sidebar" title="Close">
                <i class="fas fa-xmark"></i>
            </button>
            <div class="absolute -right-6 -top-6 w-24 h-24 rounded-full bg-gradient-to-br from-sky-400/10 via-sky-500/5 to-transparent blur-2xl pointer-events-none group-hover/brand:from-sky-500/20 transition-all duration-500"></div>
            <div class="absolute -left-4 -bottom-4 w-16 h-16 rounded-full bg-gradient-to-br from-amber-400/10 via-amber-500/5 to-transparent blur-xl pointer-events-none"></div>
            <a href="<?= BASE_URL ?>index.php" class="no-underline block relative z-10">
                <div class="flex items-center gap-3 h-full !pt-1 !pr-14 group/logo">
                    <div class="shrink-0 relative">
                        <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-sky-500 via-sky-600 to-blue-700 flex items-center justify-center shadow-lg shadow-sky-500/25 group-hover/logo:shadow-sky-500/40 transition-all duration-300 group-hover/logo:-translate-y-0.5 group-hover/logo:scale-[1.03]">
                            <i class="fas fa-industry text-white text-[18px] relative z-10"></i>
                            <div class="absolute inset-0 rounded-2xl bg-gradient-to-t from-white/0 via-white/0 to-white/15"></div>
                        </div>
                        <div class="absolute -bottom-1 -right-1 w-4 h-4 rounded-full bg-gradient-to-br from-amber-400 to-amber-600 flex items-center justify-center border-[3px] border-white shadow-sm">
                            <i class="fas fa-file-pen text-white text-[7px]"></i>
                        </div>
                    </div>
                    <span class="nav-label min-w-0 flex-1 leading-tight block">
                        <span class="font-display text-[18px] leading-none font-black tracking-wide text-slate-900 mb-1 block">Engineering</span>
                        <span class="block text-[17px] leading-none font-black bg-gradient-to-r from-sky-600 via-sky-700 to-blue-700 bg-clip-text text-transparent">Report</span>
                    </span>
                </div>
            </a>
        </div>

        <nav class="sidebar-nav !px-3 !py-3 !space-y-0.5">
            <?php
            $isDashActive = $isDashboard;
            ?>
            <a href="<?= BASE_URL ?>index.php" class="<?= $sbBase ?> <?= $isDashActive ? $sbBaseActive : '' ?>">
                <span class="<?= $sbIconBase ?> <?= $isDashActive ? $sbIconActive : '' ?>"><i class="fas fa-chart-line text-[15px]"></i></span>
                <span class="nav-label">Dashboard Utama</span>
            </a>

            <?php
            $energyDefaultOpen = ($isAnyEnergy) ? 'true' : 'false';
            $isEnergyActive = $isAnyEnergy;
            $energySubCount = 2; // Dashboard + Log Sheet = default 2
            if ($isEngineer || $isSupervisor || $isManager) $energySubCount++; // + Isi Daily Log (Manager Access All)
            if ($isSupervisor || $isManager) $energySubCount++; // + Review Daily Log (Manager Access All)
            ?>
            <button type="button" id="energyToggleBtn" data-default-open="<?= $energyDefaultOpen ?>"
                    onclick="toggleEnergyMenu(this)"
                    class="w-full text-left mt-1 <?= $sbBase ?> <?= $isEnergyActive ? $sbBaseActive : '' ?>">
                <span class="<?= $sbIconBase ?> <?= $isEnergyActive ? $sbIconActive : '' ?>"><i class="fas fa-bolt text-[15px]"></i></span>
                <span class="nav-label">Energy</span>
                <span class="<?= $sbCountBadge ?> <?= $isEnergyActive ? $sbCountBadgeActive : '' ?>"><?= $energySubCount ?></span>
                <i id="energyChevron" class="fas fa-chevron-down text-[11px] text-slate-400 transition-transform duration-200 -rotate-90 ml-1 mr-0.5 shrink-0"></i>
            </button>
            <div id="energyGroup" class="overflow-hidden transition-all duration-200 hidden ml-3 my-0.5 space-y-0.5 border-l-2 border-slate-100 pl-3">
                <a href="<?= BASE_URL ?>energy.php"
                   class="w-full flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition-all <?= ($isEnergyDashboard) ? '!bg-slate-100 !text-slate-900 !font-semibold' : '' ?>">
                    <i class="fas fa-gauge-high text-[13px] w-4 text-center text-slate-400"></i>
                    <span>Energy Dashboard</span>
                </a>
                <a href="<?= BASE_URL ?>energy_logsheet.php"
                   class="w-full flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition-all <?= ($isEnergyLogsheet) ? '!bg-slate-100 !text-slate-900 !font-semibold' : '' ?>">
                    <i class="fas fa-receipt text-[13px] w-4 text-center text-amber-500"></i>
                    <span>🧾 Log Sheet Shift</span>
                </a>
                <?php if ($isEngineer || $isSupervisor || $isManager): ?>
                <a href="<?= BASE_URL ?>engineer/select_date.php"
                   class="w-full flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition-all <?= ($isDailyLog) ? '!bg-slate-100 !text-slate-900 !font-semibold' : '' ?>">
                    <i class="fas fa-clipboard-check text-[13px] w-4 text-center text-emerald-500"></i>
                    <span>✍️ Daily Log (Pilih Shift)</span>
                </a>
                <?php endif; ?>
                <?php if ($isSupervisor || $isManager): ?>
                <a href="<?= BASE_URL ?>supervisor/review.php"
                   class="w-full flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition-all <?= ($isReview) ? '!bg-slate-100 !text-slate-900 !font-semibold' : '' ?>">
                    <i class="fas fa-file-signature text-[13px] w-4 text-center text-slate-400"></i>
                    <span class="flex-1">Review Daily Log</span>
                    <?php if ($pendingCount > 0): ?>
                        <span class="inline-flex items-center justify-center h-5 min-w-[1.25rem] px-1.5 rounded-md bg-amber-100 text-amber-700 text-[10px] font-black ml-1"><?= $pendingCount > 99 ? '99+' : $pendingCount ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </div>
            <script>
                (function(){
                    try {
                        const btn = document.getElementById('energyToggleBtn');
                        const grp = document.getElementById('energyGroup');
                        const chv = document.getElementById('energyChevron');
                        const STORAGE_KEY = 'sb_energyMenu_open';
                        const isDefaultOpen = btn && btn.dataset.defaultOpen === 'true';
                        const saved = localStorage.getItem(STORAGE_KEY);
                        const shouldOpen = (saved !== null) ? (saved === '1') : isDefaultOpen;
                        function setEnergyOpen(open){
                            if(!grp || !btn || !chv) return;
                            if(open){
                                grp.classList.remove('hidden');
                                chv.classList.remove('-rotate-90');
                                localStorage.setItem(STORAGE_KEY, '1');
                            } else {
                                grp.classList.add('hidden');
                                chv.classList.add('-rotate-90');
                                localStorage.setItem(STORAGE_KEY, '0');
                            }
                        }
                        window.toggleEnergyMenu = function(b){ setEnergyOpen(grp.classList.contains('hidden')); };
                        setEnergyOpen(shouldOpen);
                    } catch(e) {}
                })();
            </script>

            <div class="h-px my-3 bg-slate-100 mx-1"></div>

            <?php if ($isSupervisor || $isManager): ?>
            <div class="nav-section !mt-4 !mb-1"><span>Logistic</span></div>
            <a href="<?= BASE_URL ?>orders/create.php" class="<?= $sbBase ?> <?= $isOrderCreate ? $sbBaseActive : '' ?>">
                <span class="<?= $sbIconBase ?> <?= $isOrderCreate ? $sbIconActive : '' ?>"><i class="fas fa-file-circle-plus text-[15px]"></i></span>
                <span class="nav-label">Buat Order Request</span>
            </a>
            <a href="<?= BASE_URL ?>orders/index.php" class="<?= $sbBase ?> <?= ($isOrderIndex || $isOrderDetail) ? $sbBaseActive : '' ?>">
                <span class="<?= $sbIconBase ?> <?= ($isOrderIndex || $isOrderDetail) ? $sbIconActive : '' ?>"><i class="fas fa-clipboard-list text-[15px]"></i></span>
                <span class="nav-label">Logistik</span>
                <?php if ($pendingOrderCount > 0): ?>
                    <span class="ml-auto inline-flex items-center justify-center h-5 min-w-[1.25rem] px-1.5 rounded-md bg-emerald-100 text-emerald-700 text-[11px] font-black"><?= $pendingOrderCount > 99 ? '99+' : $pendingOrderCount ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>

            <?php if ($isManager): ?>
            <div class="h-px my-3 bg-slate-100 mx-1"></div>
            <?php
            $dmDefaultOpen = ($isAnyDataMaster) ? 'true' : 'false';
            $managerMenuCount = 3;
            $isManagerAreaActive = $isAnyManagerArea;
            ?>
            <div class="nav-section !mb-1"><span>Manager Area</span></div>

            <a href="<?= BASE_URL ?>manager/users.php" class="<?= $sbBase ?> <?= $isUsersPage ? $sbBaseActive : '' ?>">
                <span class="<?= $sbIconBase ?> <?= $isUsersPage ? $sbIconActive : '' ?>"><i class="fas fa-users-gear text-[15px]"></i></span>
                <span class="nav-label">Daftar Akun</span>
            </a>
            <a href="<?= BASE_URL ?>manager/activities.php" class="<?= $sbBase ?> <?= $isEngActivities ? $sbBaseActive : '' ?>">
                <span class="<?= $sbIconBase ?> <?= $isEngActivities ? $sbIconActive : '' ?>"><i class="fas fa-layer-group text-[15px]"></i></span>
                <span class="nav-label">Engineering Activities</span>
            </a>

            <?php
            $isDmActive = $isAnyDataMaster;
            ?>
            <button type="button" id="dmToggleBtn" data-default-open="<?= $dmDefaultOpen ?>"
                    onclick="toggleDataMaster(this)"
                    class="w-full text-left <?= $sbBase ?> <?= $isDmActive ? $sbBaseActive : '' ?>">
                <span class="<?= $sbIconBase ?> <?= $isDmActive ? $sbIconActive : '' ?>"><i class="fas fa-folder-tree text-[15px]"></i></span>
                <span class="nav-label">Data Master</span>
                <span class="<?= $sbCountBadge ?> <?= $isDmActive ? $sbCountBadgeActive : '' ?>">1</span>
                <i id="dmChevron" class="fas fa-chevron-down text-[11px] text-slate-400 transition-transform duration-200 -rotate-90 ml-1 mr-0.5 shrink-0"></i>
            </button>
            <div id="dmGroup" class="overflow-hidden transition-all duration-200 hidden ml-3 my-0.5 space-y-0.5 border-l-2 border-slate-100 pl-3">
                <a href="<?= BASE_URL ?>manager/master_cost_codes.php"
                   class="w-full flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition-all <?= ($isMasterCostCode) ? '!bg-slate-100 !text-slate-900 !font-semibold' : '' ?>">
                    <i class="fas fa-barcode text-[13px] w-4 text-center text-slate-400"></i>
                    <span>Master Cost Code</span>
                </a>
            </div>
            <script>
                (function(){
                    try {
                        const btn = document.getElementById('dmToggleBtn');
                        const grp = document.getElementById('dmGroup');
                        const chv = document.getElementById('dmChevron');
                        const STORAGE_KEY = 'sb_dataMaster_open';
                        const isDefaultOpen = btn && btn.dataset.defaultOpen === 'true';
                        const saved = localStorage.getItem(STORAGE_KEY);
                        const shouldOpen = (saved !== null) ? (saved === '1') : isDefaultOpen;
                        function setDmOpen(open){
                            if(!grp || !btn || !chv) return;
                            if(open){
                                grp.classList.remove('hidden');
                                chv.classList.remove('-rotate-90');
                                localStorage.setItem(STORAGE_KEY, '1');
                            } else {
                                grp.classList.add('hidden');
                                chv.classList.add('-rotate-90');
                                localStorage.setItem(STORAGE_KEY, '0');
                            }
                        }
                        window.toggleDataMaster = function(b){ setDmOpen(grp.classList.contains('hidden')); };
                        setDmOpen(shouldOpen);
                    } catch(e) {}
                })();
            </script>
            <?php endif; ?>

            <div class="h-px my-3 bg-slate-100 mx-1"></div>
            <div class="nav-section !mb-1"><span>Umum</span></div>
            <a href="<?= BASE_URL ?>history.php" class="<?= $sbBase ?> <?= $isHistory ? $sbBaseActive : '' ?>">
                <span class="<?= $sbIconBase ?> <?= $isHistory ? $sbIconActive : '' ?>"><i class="fas fa-clock-rotate-left text-[15px]"></i></span>
                <span class="nav-label">Riwayat & Laporan</span>
            </a>
            <a href="<?= BASE_URL ?>profile/edit.php" class="<?= $sbBase ?>">
                <span class="<?= $sbIconBase ?>"><i class="fas fa-user-gear text-[15px]"></i></span>
                <span class="nav-label">Edit Profil</span>
            </a>

            <div class="mt-5 px-2.5">
                <div class="text-[10px] font-bold tracking-[0.2em] uppercase text-slate-400 mb-2 px-1">Bahasa</div>
                <div class="flex items-center gap-2 bg-slate-100/70 p-1 rounded-xl border border-slate-200">
                    <?php
                    $qsRemoveLang = $_GET;
                    unset($qsRemoveLang['lang']);
                    $baseQs = http_build_query($qsRemoveLang);
                    $suffix = $baseQs ? '?' . $baseQs . '&' : '?';
                    ?>
                    <a href="<?= $suffix ?>lang=id"
                       class="flex-1 text-center text-xs font-bold py-1.5 px-2 rounded-lg transition-all duration-200 <?= (APP_LANG === 'id') ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-500 hover:bg-white hover:text-slate-800' ?>">
                        <?= T('lang_id', '🇮🇩 ID') ?>
                    </a>
                    <a href="<?= $suffix ?>lang=en"
                       class="flex-1 text-center text-xs font-bold py-1.5 px-2 rounded-lg transition-all duration-200 <?= (APP_LANG === 'en') ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-500 hover:bg-white hover:text-slate-800' ?>">
                        <?= T('lang_en', '🇬🇧 EN') ?>
                    </a>
                </div>
            </div>

        </nav>

        <div class="!border-t !border-slate-200 !p-3 !mt-auto">
            <div class="w-full flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-slate-50 transition-all group">
                <div class="w-10 h-10 shrink-0 rounded-full bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center text-base font-black text-slate-700 border border-slate-200 shadow-sm">
                    <?= $initial ?>
                </div>
                <a href="<?= BASE_URL ?>profile/edit.php" class="flex-1 min-w-0 hover:opacity-80 transition-opacity" title="<?= T('nav_profile', 'Edit Profil') ?>">
                    <p class="truncate text-sm font-bold text-slate-900 leading-tight"><?= cleanInput($displayName) ?></p>
                    <p class="truncate text-[12px] font-medium text-slate-500 leading-tight mt-0.5"><?= $roleBadge ?></p>
                </a>
                <a href="<?= BASE_URL ?>logout.php" class="w-8 h-8 shrink-0 ml-2 flex items-center justify-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600 transition-all" title="<?= T('nav_logout', 'Keluar') ?>">
                    <i class="fas fa-right-from-bracket text-sm transform rotate-180"></i>
                </a>
            </div>
        </div>
    </div>
</aside>

<div id="sidebarBackdrop" class="sidebar-backdrop"></div>

<div class="main-wrapper min-h-screen flex flex-col flex-1 min-w-0">
    <div class="mobile-topbar md:hidden">
        <button type="button" id="sidebarOpenBtn" class="hamburger-btn" aria-label="Open Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <a href="<?= BASE_URL ?>index.php" class="mobile-brand !gap-2">
            <span class="font-display font-black tracking-wide text-base leading-none whitespace-nowrap">
                <span class="text-slate-900">Engineering</span>
                <span class="bg-gradient-to-r from-sky-600 via-sky-700 to-blue-700 bg-clip-text text-transparent">Report</span>
            </span>
        </a>
        <a href="<?= BASE_URL ?>logout.php" class="logout-mobile" title="Logout">
            <i class="fas fa-right-from-bracket"></i>
        </a>
    </div>
<?php require __DIR__ . '/flash_alert.php'; ?>
<script>
(function() {
    const body = document.body;
    const appLayout = document.getElementById('appLayout');
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggleBtn = document.getElementById('sidebarToggle');
    const mobileOpenBtn = document.getElementById('sidebarOpenBtn');
    const mobileCloseBtn = document.getElementById('sidebarToggleMobile');

    const KEY_EXPANDED = 'stregis_sidebar_expanded_v2';
    const saved = localStorage.getItem(KEY_EXPANDED);

    if (window.innerWidth >= 768) {
        const shouldExpand = saved !== 'collapsed';
        setDesktopMode(shouldExpand, true);
    }

    function setDesktopMode(expand, skipStorage) {
        if (!appLayout) return;
        if (expand) {
            appLayout.classList.remove('is-sidebar-collapsed');
            appLayout.classList.add('is-sidebar-expanded');
            sidebar.classList.add('sidebar-expanded');
            sidebar.classList.remove('sidebar-collapsed');
        } else {
            appLayout.classList.remove('is-sidebar-expanded');
            appLayout.classList.add('is-sidebar-collapsed');
            sidebar.classList.remove('sidebar-expanded');
            sidebar.classList.add('sidebar-collapsed');
        }
        if (!skipStorage) localStorage.setItem(KEY_EXPANDED, expand ? 'expanded' : 'collapsed');
    }

    function toggleDesktop() {
        setDesktopMode(!appLayout.classList.contains('is-sidebar-expanded'), false);
    }
    function openMobile() {
        sidebar.classList.add('sidebar-opened');
        backdrop.classList.add('sidebar-backdrop-show');
        body.classList.add('overflow-hidden');
    }
    function closeMobile() {
        sidebar.classList.remove('sidebar-opened');
        backdrop.classList.remove('sidebar-backdrop-show');
        body.classList.remove('overflow-hidden');
    }
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (window.innerWidth >= 768) toggleDesktop(); else closeMobile();
        });
    }
    if (mobileOpenBtn) mobileOpenBtn.addEventListener('click', function(e){ e.preventDefault(); openMobile(); });
    if (mobileCloseBtn) mobileCloseBtn.addEventListener('click', function(e){ e.preventDefault(); closeMobile(); });
    if (backdrop) backdrop.addEventListener('click', closeMobile);
    window.addEventListener('resize', function(){
        if (window.innerWidth >= 768) closeMobile();
    });
})();
</script>
