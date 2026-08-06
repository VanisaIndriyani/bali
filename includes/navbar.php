<?php
$user = currentUser();
$isLoggedIn = $user !== null;
$isSupervisor = $user && ($user['role'] ?? '') === 'supervisor';
$isEngineer = $user && ($user['role'] ?? '') === 'engineer';

if ($isLoggedIn):
    require __DIR__ . '/sidebar.php';
else:
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<nav class="bg-surface border-b border-border shadow-sm sticky top-0 z-50 animate-fade-in">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-[72px] sm:h-20 lg:h-24">
            <div class="flex items-center gap-3 sm:gap-4 min-w-0">
                <a href="<?= BASE_URL ?>index.php" class="flex items-center gap-3 sm:gap-4 group min-w-0">
                    <img src="<?= BASE_URL ?>logo.jpeg" alt="St. Regis Bali"
                        class="w-16 h-16 sm:w-[72px] sm:h-[72px] lg:w-20 lg:h-20 object-cover rounded-2xl p-1 bg-white ring-2 ring-amber-200 shadow-2xl group-hover:animate-float transition-all duration-300 flex-shrink-0"
                        style="image-rendering: -webkit-optimize-contrast; image-rendering: crisp-edges; image-rendering: pixelated; transform: translateZ(0); -webkit-backface-visibility: hidden; backface-visibility: hidden; will-change: transform; filter: drop-shadow(0 4px 12px rgba(0,0,0,0.25)) drop-shadow(0 1px 4px rgba(201,162,39,0.45));">
                    <div class="leading-tight min-w-0">
                        <h1 class="font-display text-lg sm:text-2xl lg:text-[26px] font-black text-primary tracking-[0.02em] leading-none truncate">ST. REGIS BALI</h1>
                        <p class="hidden sm:block text-xs lg:text-[13px] text-secondary font-semibold tracking-[0.2em] lg:tracking-[0.28em] uppercase mt-1.5">Engineering Daily Log</p>
                    </div>
                </a>
            </div>

            <div class="hidden lg:flex items-center gap-1">
                <?php if ($isLoggedIn): ?>
                    <a href="<?= BASE_URL ?>index.php" class="nav-link <?= $currentPage === 'index.php' ? 'nav-link-active' : '' ?>">
                        <i class="fas fa-chart-line mr-2 text-accent"></i>Dashboard
                    </a>
                    <?php if ($isEngineer || $isSupervisor): ?>
                    <a href="<?= BASE_URL ?>engineer/select_date.php" class="nav-link <?= in_array($currentPage, ['select_date.php','daily_log_form.php']) ? 'nav-link-active' : '' ?>">
                        <i class="fas fa-edit mr-2 text-accent"></i>Daily Log
                    </a>
                    <?php endif; ?>
                    <?php if ($isSupervisor || $isManager): ?>
                    <a href="<?= BASE_URL ?>orders/index.php" class="nav-link !bg-gradient-to-r !from-amber-50/80 !via-orange-50/70 !to-amber-50/80 !text-amber-900 !ring-1 !ring-amber-200 hover:!from-amber-100 hover:!to-orange-100 <?= (in_array($currentPage, ['create.php','index.php','detail.php']) && (dirname($_SERVER['PHP_SELF']) === '/orders' || basename(dirname($_SERVER['PHP_SELF'])) === 'orders')) ? 'nav-link-active' : '' ?>">
                        <i class="fas fa-boxes-stacked mr-2 text-amber-700"></i>📦 Logistik <span class="ml-1 text-[10px] font-black bg-gradient-to-br from-amber-500 to-orange-600 text-white px-1.5 py-0.5 rounded-full shadow-sm">Order/PR</span>
                    </a>
                    <a href="<?= BASE_URL ?>orders/index.php" class="nav-link <?= (in_array($currentPage, ['index.php','detail.php']) && (dirname($_SERVER['PHP_SELF']) === '/orders' || basename(dirname($_SERVER['PHP_SELF'])) === 'orders')) ? 'nav-link-active' : '' ?>">
                        <i class="fas fa-clipboard-list mr-2 text-accent"></i>Daftar Order
                    </a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>history.php" class="nav-link <?= $currentPage === 'history.php' ? 'nav-link-active' : '' ?>">
                        <i class="fas fa-clock-rotate-left mr-2 text-accent"></i>Riwayat
                    </a>
                <?php endif; ?>

                <?php if (!$isLoggedIn): ?>
                    <a href="<?= BASE_URL ?>login.php" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-white bg-primary hover:bg-secondary rounded-full transition-all duration-300 shadow-lg hover:shadow-xl hover:-translate-y-0.5">
                        <i class="fas fa-right-to-bracket"></i> Login
                    </a>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-3">
                <?php if ($user): ?>
                    <?php
                    $displayName = (string)($user['name'] ?? 'User');
                    $initial = strtoupper(mb_substr($displayName, 0, 1) ?: 'U');
                    $displayRole = (($user['role'] ?? '') === 'engineer') ? 'Engineer' : 'Supervisor';
                    ?>
                    <a href="<?= BASE_URL ?>profile/edit.php" class="hidden md:flex items-center gap-2 px-3 py-1.5 bg-muted rounded-full text-left decoration-0 hover:bg-white hover:shadow-md transition-all group border border-transparent hover:border-border">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-accent to-amber-700 flex items-center justify-center text-white text-xs font-bold shadow-sm">
                            <?= $initial ?>
                        </div>
                        <div class="leading-tight min-w-0">
                            <p class="text-sm font-semibold text-primary truncate"><?= cleanInput($displayName) ?></p>
                            <p class="text-[10px] uppercase tracking-wider text-secondary"><?= $displayRole ?> • <span class="text-accent font-semibold group-hover:underline">Edit Profil</span></p>
                        </div>
                        <i class="fas fa-chevron-right text-[10px] text-secondary opacity-60 group-hover:translate-x-0.5 group-hover:text-accent transition-all"></i>
                    </a>
                    <a href="<?= BASE_URL ?>logout.php" class="btn-logout inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-primary bg-muted hover:bg-primary hover:text-white rounded-full transition-all duration-300 group">
                        <i class="fas fa-right-from-bracket group-hover:translate-x-0.5 transition-transform"></i>
                        <span class="hidden sm:inline">Logout</span>
                    </a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>login.php" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-white bg-primary hover:bg-secondary rounded-full transition-all duration-300 shadow-lg hover:shadow-xl hover:-translate-y-0.5 md:hidden">
                        <i class="fas fa-right-to-bracket"></i>
                        Login
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($user): ?>
        <div class="lg:hidden flex gap-1 pb-3 overflow-x-auto no-scrollbar">
            <?php if ($isLoggedIn): ?>
                <a href="<?= BASE_URL ?>index.php" class="nav-link-mobile <?= $currentPage === 'index.php' ? 'nav-link-mobile-active' : '' ?>">
                    <i class="fas fa-chart-line mr-1.5"></i>Dashboard
                </a>
                <?php if ($isEngineer || $isSupervisor): ?>
                <a href="<?= BASE_URL ?>engineer/select_date.php" class="nav-link-mobile <?= in_array($currentPage, ['select_date.php','daily_log_form.php']) ? 'nav-link-mobile-active' : '' ?>">
                    <i class="fas fa-edit mr-1.5"></i>Daily Log
                </a>
                <?php endif; ?>
                <?php if ($isSupervisor || $isManager): ?>
                <a href="<?= BASE_URL ?>orders/index.php" class="nav-link-mobile !bg-gradient-to-r !from-amber-50/90 !to-orange-50/90 !text-amber-950 !font-black !ring-2 !ring-amber-300/60 <?= (in_array($currentPage, ['create.php','index.php','detail.php']) && (dirname($_SERVER['PHP_SELF']) === '/orders' || basename(dirname($_SERVER['PHP_SELF'])) === 'orders')) ? 'nav-link-mobile-active' : '' ?>">
                    <i class="fas fa-boxes-stacked mr-1.5 text-amber-700"></i>📦 Logistik
                </a>
                <a href="<?= BASE_URL ?>orders/index.php" class="nav-link-mobile <?= (in_array($currentPage, ['index.php','detail.php']) && (dirname($_SERVER['PHP_SELF']) === '/orders' || basename(dirname($_SERVER['PHP_SELF'])) === 'orders')) ? 'nav-link-mobile-active' : '' ?>">
                    <i class="fas fa-clipboard-list mr-1.5"></i>Daftar Order
                </a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>history.php" class="nav-link-mobile <?= $currentPage === 'history.php' ? 'nav-link-mobile-active' : '' ?>">
                    <i class="fas fa-clock-rotate-left mr-1.5"></i>Riwayat
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</nav>

<?php require __DIR__ . '/flash_alert.php'; ?>

<?php endif; ?>
