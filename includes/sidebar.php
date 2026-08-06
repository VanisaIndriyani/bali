<?php
$user = currentUser();
if (!$user) return;
$displayName = (string)($user['name'] ?? 'User');
$displayEmail = (string)($user['email'] ?? '');
$initial = strtoupper(mb_substr($displayName, 0, 1) ?: 'U');
$isEngineer = $user['role'] === 'engineer';
$isSupervisor = $user['role'] === 'supervisor';
$isManager = $user['role'] === 'manager';
$isManagerOrSpv = $isManager || $isSupervisor;
$currentFile = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

$isDashboard = $currentFile === 'index.php' && !in_array($currentDir, ['users','engineer','supervisor','manager','reports','profile','orders']);
$isDailyLog = $currentDir === 'engineer' && in_array($currentFile, ['select_date.php', 'daily_log_form.php']);
$isReview = $currentDir === 'supervisor' && in_array($currentFile, ['review.php', 'review_detail.php']);
$isHistory = $currentFile === 'history.php';
$isManageUsers = $currentDir === 'supervisor' && $currentFile === 'users' && in_array($currentFile, ['index.php', 'create.php', 'edit.php']);
if ($currentDir !== 'supervisor') $isManageUsers = $currentDir === 'users' && in_array($currentFile, ['index.php', 'create.php', 'edit.php']);

$isOrderIndex = $currentDir === 'orders' && $currentFile === 'index.php';
$isOrderCreate = $currentDir === 'orders' && $currentFile === 'create.php';
$isOrderDetail = $currentDir === 'orders' && $currentFile === 'detail.php';
$isOrderApprove = $currentDir === 'orders' && in_array($currentFile, ['approve.php']);

$db = Database::getInstance();
$pendingCount = 0;
$pendingOrderCount = 0;
if ($isSupervisor) {
    $pendingCount = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE status = 'pending'")['cnt'] ?? 0);
    $pendingOrderCount = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM orders WHERE status = 'pending_supervisor'")['cnt'] ?? 0);
}
if ($isManager) {
    $pendingOrderCount = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM orders WHERE status = 'pending_manager'")['cnt'] ?? 0);
}
if ($isEngineer) {
    $myId = (int)($user['id'] ?? 0);
    if ($myId > 0) {
        $pendingOrderCount = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM orders WHERE created_by = ? AND status IN ('pending_supervisor','pending_manager','rejected')", [$myId])['cnt'] ?? 0);
    }
}
?>
<div class="app-layout is-sidebar-expanded" id="appLayout">
<aside id="sidebar" class="sidebar sidebar-expanded">
    <div class="sidebar-inner">
        <div class="sidebar-brand">
            <button type="button" id="sidebarToggle" class="sidebar-toggle hidden md:inline-flex sidebar-btn-topright" aria-label="Toggle Sidebar" title="Collapse / Expand">
                <i class="fas fa-bars-staggered transition-transform duration-300"></i>
            </button>
            <button type="button" id="sidebarToggleMobile" class="sidebar-toggle-mobile md:hidden sidebar-btn-topright" aria-label="Close Sidebar" title="Close">
                <i class="fas fa-xmark"></i>
            </button>
            <a href="<?= BASE_URL ?>index.php" class="brand-link brand-link--stacked group">
                <div class="brand-logo animate-float" style="animation-delay: 0.1s; box-shadow: 0 10px 30px rgba(0,0,0,0.18), 0 1px 3px rgba(201,162,39,0.25); border-radius: 18px; background: white; padding: 4px; border: 1px solid rgba(229,229,229,0.9);">
                    <img src="<?= BASE_URL ?>logo.jpeg" alt="St. Regis Bali" class="w-full h-full object-cover rounded-2xl"
                        style="image-rendering: -webkit-optimize-contrast; image-rendering: crisp-edges; filter: drop-shadow(0 2px 6px rgba(0,0,0,0.14)) drop-shadow(0 1px 2px rgba(201,162,39,0.3));">
                </div>
                <div class="brand-text text-center">
                    <h1 class="font-display text-xl font-bold tracking-wide leading-tight">ST. REGIS BALI</h1>
                    <p class="text-[10px] font-semibold tracking-[0.2em] uppercase mt-1">Engineering Daily Log</p>
                </div>
            </a>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section"><span>Dashboard</span></div>
            <a href="<?= BASE_URL ?>index.php" class="nav-item <?= $isDashboard ? 'nav-item-active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                <span class="nav-label"><?= T('nav_dashboard', 'Dashboard Utama') ?></span>
            </a>

            <?php if ($isEngineer || $isSupervisor): ?>
            <div class="nav-section"><span>Daily Log</span></div>
            <a href="<?= BASE_URL ?>engineer/select_date.php" class="nav-item <?= $isDailyLog ? 'nav-item-active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-edit"></i></span>
                <span class="nav-label"><?= T('nav_fill_daily_log', 'Isi Daily Log') ?></span>
            </a>
            <?php endif; ?>

            <?php if ($isSupervisor || $isManager): ?>
            <a href="<?= BASE_URL ?>orders/create.php" class="sidebar-section-clickable block nav-section !mt-5 !mb-1.5 rounded-t-xl px-2 pt-2.5 pb-1.5 bg-gradient-to-br from-amber-50 via-amber-50/70 to-orange-50 ring-1 ring-amber-200/80 shadow-sm !no-underline !select-none transition-all duration-200 hover:!ring-amber-400 hover:!shadow-md hover:!shadow-amber-500/20 hover:-translate-y-[1px] active:translate-y-0 active:!shadow-sm">
                <span class="!text-amber-900 !tracking-[0.25em] flex items-center gap-1.5 !font-black cursor-pointer">
                    <i class="fas fa-diagram-project text-amber-700 fa-fw"></i>
                    FLOW LOGISTIC
                    <span class="ml-auto text-[9px] font-black tracking-normal bg-gradient-to-br from-amber-500 to-orange-600 text-white px-2 py-0.5 rounded-full shadow-md shadow-amber-500/30">3 STEP</span>
                    <i class="ml-1.5 fas fa-angle-right text-[11px] text-amber-600 opacity-70 transition-transform group-hover:translate-x-1"></i>
                </span>
            </a>
            <a href="<?= BASE_URL ?>orders/create.php" class="nav-item !rounded-b-none !rounded-t-none !mt-0 border border-amber-100/70 border-y-0 bg-gradient-to-r from-white via-amber-50/30 to-white <?= ($isOrderCreate) ? 'nav-item-active' : '' ?>">
                <span class="nav-icon text-amber-700 ring-2 ring-amber-200 bg-amber-50"><i class="fas fa-file-circle-plus"></i></span>
                <span class="nav-label font-bold text-amber-950 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-gradient-to-br from-amber-500 to-orange-600 text-white text-[10px] font-black shadow-sm">①</span>
                    Buat Order / Request Order
                </span>
            </a>
            <a href="<?= BASE_URL ?>index.php#section_logistic" class="nav-item !rounded-none !mt-0 border border-amber-100/70 border-y-0 bg-gradient-to-r from-white via-orange-50/40 to-white">
                <span class="nav-icon text-orange-700 ring-2 ring-orange-200 bg-orange-50"><i class="fas fa-boxes-stacked"></i></span>
                <span class="nav-label font-bold text-orange-950 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-gradient-to-br from-orange-500 to-amber-600 text-white text-[10px] font-black shadow-sm">②</span>
                    Dashboard Logistik (Ringkasan Status)
                </span>
            </a>
            <a href="<?= BASE_URL ?>orders/index.php" class="nav-item !rounded-t-none !mt-0 !pt-2 border border-amber-100/70 border-t-0 bg-gradient-to-r from-white via-amber-50/40 to-white <?= ($isOrderIndex) ? 'nav-item-active' : '' ?>">
                <span class="nav-icon text-emerald-700 ring-2 ring-emerald-200 bg-emerald-50"><i class="fas fa-clipboard-list"></i></span>
                <span class="nav-label font-semibold text-emerald-900 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-gradient-to-br from-emerald-500 to-teal-600 text-white text-[10px] font-black shadow-sm">⑧</span>
                    Daftar Order / PR
                    <?php if ($pendingOrderCount > 0): ?>
                        <span class="ml-auto badge-pill"><?= $pendingOrderCount > 99 ? '99+' : $pendingOrderCount ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <?php endif; ?>

            <?php if ($isSupervisor): ?>
                <div class="nav-section"><span>Approval</span></div>
                <a href="<?= BASE_URL ?>supervisor/review.php" class="nav-item <?= $isReview ? 'nav-item-active' : '' ?>">
                    <span class="nav-icon">
                        <i class="fas fa-file-signature"></i>
                    </span>
                    <span class="nav-label"><?= T('nav_review', 'Review Daily Log') ?>
                        <?php if ($pendingCount > 0): ?>
                            <span class="ml-auto badge-pill"><?= $pendingCount > 99 ? '99+' : $pendingCount ?></span>
                        <?php endif; ?>
                    </span>
                </a>
                <div class="nav-section"><span>Manajemen</span></div>
                <a href="<?= BASE_URL ?>supervisor/users/index.php" class="nav-item <?= $isManageUsers ? 'nav-item-active' : '' ?>">
                    <span class="nav-icon"><i class="fas fa-users-gear"></i></span>
                    <span class="nav-label"><?= T('nav_manage_staff', 'Kelola Staff Engineer') ?></span>
                </a>
            <?php endif; ?>

            <div class="nav-section"><span>Umum</span></div>
            <a href="<?= BASE_URL ?>history.php" class="nav-item <?= $isHistory ? 'nav-item-active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-clock-rotate-left"></i></span>
                <span class="nav-label"><?= T('nav_history', 'Riwayat & Laporan') ?></span>
            </a>

            <div class="mt-6 px-4">
                <div class="text-[10px] font-bold tracking-[0.2em] uppercase text-slate-500 mb-2 px-1"><?= T('language', 'Bahasa') ?></div>
                <div class="flex items-center gap-2 bg-slate-100/70 p-1 rounded-xl border border-slate-200">
                    <?php
                    $qsRemoveLang = $_GET;
                    unset($qsRemoveLang['lang']);
                    $baseQs = http_build_query($qsRemoveLang);
                    $suffix = $baseQs ? '?' . $baseQs . '&' : '?';
                    ?>
                    <a href="<?= $suffix ?>lang=id"
                       class="flex-1 text-center text-xs font-bold py-1.5 px-2 rounded-lg transition-all duration-200 <?= (APP_LANG === 'id') ? 'bg-gradient-to-r from-amber-500 to-amber-600 text-white shadow-gold-glow' : 'text-slate-500 hover:bg-white hover:text-slate-800' ?>">
                        <?= T('lang_id', '🇮🇩 ID') ?>
                    </a>
                    <a href="<?= $suffix ?>lang=en"
                       class="flex-1 text-center text-xs font-bold py-1.5 px-2 rounded-lg transition-all duration-200 <?= (APP_LANG === 'en') ? 'bg-gradient-to-r from-amber-500 to-amber-600 text-white shadow-gold-glow' : 'text-slate-500 hover:bg-white hover:text-slate-800' ?>">
                        <?= T('lang_en', '🇬🇧 EN') ?>
                    </a>
                </div>
            </div>

        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="avatar-md"><?= $initial ?></div>
                <div class="user-info min-w-0">
                    <p class="user-name truncate"><?= cleanInput($displayName) ?></p>
                    <p class="user-role truncate"><?= $displayEmail ?></p>
                </div>
            </div>
            <a href="<?= BASE_URL ?>profile/edit.php" class="profile-btn">
                <i class="fas fa-user-gear"></i>
                <span><?= T('nav_profile', 'Edit Profil') ?></span>
            </a>
            <a href="<?= BASE_URL ?>logout.php" class="logout-btn">
                <i class="fas fa-right-from-bracket"></i>
                <span><?= T('nav_logout', 'Keluar') ?></span>
            </a>
        </div>
    </div>
</aside>

<div id="sidebarBackdrop" class="sidebar-backdrop"></div>

<div class="main-wrapper min-h-screen flex flex-col flex-1 min-w-0">
    <div class="mobile-topbar md:hidden">
        <button type="button" id="sidebarOpenBtn" class="hamburger-btn" aria-label="Open Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <a href="<?= BASE_URL ?>index.php" class="mobile-brand">
            <img src="<?= BASE_URL ?>logo.jpeg" alt="Logo" class="w-9 h-9 object-cover rounded-lg bg-white ring-1 ring-amber-200 shadow-md flex-shrink-0"
                style="image-rendering: -webkit-optimize-contrast; image-rendering: crisp-edges; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.18)) drop-shadow(0 1px 2px rgba(201,162,39,0.4));">
            <span class="font-display font-black text-primary tracking-wide text-base">ST. REGIS BALI</span>
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
