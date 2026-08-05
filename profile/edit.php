<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance();
$user = currentUser();
$userId = (int)($user['id'] ?? 0);
$isSupervisor = ($user['role'] ?? '') === 'supervisor';
$roleLabel = $isSupervisor ? 'Supervisor Engineering' : 'Engineering Staff';
$roleBadgeClass = $isSupervisor
    ? 'bg-gradient-to-r from-amber-500 to-amber-700 text-white ring-2 ring-amber-200 shadow-lg shadow-amber-500/30'
    : 'bg-gradient-to-r from-blue-500 to-blue-700 text-white ring-2 ring-blue-200 shadow-lg shadow-blue-500/30';

$row = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
if (!$row) {
    setFlash('error', 'Data user tidak ditemukan');
    redirect('index.php');
}

$stats = [
    'total'    => (int)($db->fetchOne("SELECT COUNT(*) AS cnt FROM daily_logs WHERE engineer_id = ?", [$userId])['cnt'] ?? 0),
    'approved' => (int)($db->fetchOne("SELECT COUNT(*) AS cnt FROM daily_logs WHERE engineer_id = ? AND status = 'approved'", [$userId])['cnt'] ?? 0),
    'pending'  => (int)($db->fetchOne("SELECT COUNT(*) AS cnt FROM daily_logs WHERE engineer_id = ? AND status = 'pending'", [$userId])['cnt'] ?? 0),
    'rejected' => (int)($db->fetchOne("SELECT COUNT(*) AS cnt FROM daily_logs WHERE engineer_id = ? AND status = 'rejected'", [$userId])['cnt'] ?? 0),
];
if ($isSupervisor) {
    $stats = [
        'total'    => (int)($db->fetchOne("SELECT COUNT(*) AS cnt FROM daily_logs")['cnt'] ?? 0),
        'approved' => (int)($db->fetchOne("SELECT COUNT(*) AS cnt FROM daily_logs WHERE status = 'approved'")['cnt'] ?? 0),
        'pending'  => (int)($db->fetchOne("SELECT COUNT(*) AS cnt FROM daily_logs WHERE status = 'pending'")['cnt'] ?? 0),
        'rejected' => (int)($db->fetchOne("SELECT COUNT(*) AS cnt FROM daily_logs WHERE status = 'rejected'")['cnt'] ?? 0),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = cleanInput($_POST['name'] ?? '');
    $email = cleanInput($_POST['email'] ?? '');
    $phone = cleanInput($_POST['phone'] ?? '');
    $position = cleanInput($_POST['position'] ?? '');
    $passNew = $_POST['password_new'] ?? '';
    $passConfirm = $_POST['password_confirm'] ?? '';

    $errors = [];
    if (mb_strlen($name) < 2) $errors[] = 'Nama minimal 2 karakter';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid';

    $checkEmail = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM users WHERE email = ? AND id <> ?", [$email, $userId])['cnt'] ?? 0);
    if ($checkEmail > 0) $errors[] = 'Email sudah dipakai akun lain';

    if ($passNew !== '') {
        if (mb_strlen($passNew) < 6) $errors[] = 'Password baru minimal 6 karakter';
        if ($passNew !== $passConfirm) $errors[] = 'Password baru & konfirmasi tidak sama';
    }

    if (!empty($errors)) {
        setFlash('error', implode('<br>', $errors));
        redirect('profile/edit.php');
    }

    $data = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone === '' ? null : $phone,
        'position' => $position === '' ? null : $position,
    ];
    if ($passNew !== '') {
        $data['password'] = password_hash($passNew, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    $ok = $db->update('users', $data, 'id = :id', ['id' => $userId]);
    if ($ok) {
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        setFlash('success', 'Profil berhasil diperbarui!');
    } else {
        setFlash('warning', 'Tidak ada perubahan data yang disimpan.');
    }
    redirect('profile/edit.php');
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$init = strtoupper(mb_substr((string)($row['name'] ?? 'U'), 0, 1) ?: 'U');
$updatedAtRaw = $row['updated_at'] ?? null;
?>
<div class="page-shell page-shell--7xl pb-20 md:pb-10">
    <div class="mb-7 animate-fade-in">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <p class="text-sm text-secondary mb-1"><i class="fas fa-user-gear mr-1.5 text-accent"></i><?= $isSupervisor ? 'Supervisor Dashboard' : 'Staff Dashboard' ?> &nbsp;›&nbsp; Profil</p>
                <h1 class="font-display text-2xl lg:text-4xl font-black text-primary mb-1">Pengaturan Profil <span class="text-accent">✨</span></h1>
                <p class="text-secondary text-sm">Kelola identitas akun, kontak jabatan, dan keamanan password login Anda.</p>
            </div>
            <div class="hidden sm:flex items-center gap-2 text-xs text-secondary px-4 py-2 rounded-xl bg-amber-50 border border-amber-200 self-start sm:self-end">
                <i class="fas fa-shield-halved text-accent text-sm"></i>
                <span>Data akun Anda <strong class="text-primary">terenkripsi aman</strong></span>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-[300px_1fr] xl:grid-cols-[320px_1fr] gap-5 lg:gap-6">
        <div class="bg-surface rounded-[1.4rem] border border-border shadow-xl animate-slide-up p-6 sm:p-7 flex flex-col items-center text-center h-fit relative overflow-hidden">
            <div class="absolute inset-x-0 top-0 h-24 bg-gradient-to-br from-amber-100/80 via-amber-50 to-transparent pointer-events-none"></div>

            <div class="relative z-10 mb-5 mt-2">
                <div class="absolute -bottom-1 -right-1 z-20 w-8 h-8 rounded-full bg-green-500 ring-4 ring-white shadow-lg flex items-center justify-center">
                    <i class="fas fa-check text-white text-[10px]"></i>
                </div>
                <div class="w-32 h-32 rounded-[1.7rem] bg-gradient-to-br from-amber-400 via-amber-500 to-amber-700 text-white font-display text-5xl font-black flex items-center justify-center shadow-2xl ring-8 ring-white ring-offset-2 ring-offset-muted/30 animate-float" style="filter: drop-shadow(0 14px 24px rgba(201,162,39,0.28));"><?= $init ?></div>
            </div>

            <div class="relative z-10 w-full flex flex-col items-center">
                <h2 class="font-bold text-primary text-xl leading-tight mb-1.5"><?= cleanInput((string)($row['name'] ?? '')) ?></h2>
                <span class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full text-[11px] font-bold uppercase tracking-widest mb-2 <?= $roleBadgeClass ?>">
                    <i class="fas fa-crown text-[10px]"></i>
                    <?= $roleLabel ?>
                </span>
                <p class="text-sm text-secondary mb-5 break-all flex items-center gap-1.5"><i class="far fa-envelope text-accent text-xs"></i><?= cleanInput((string)($row['email'] ?? '')) ?></p>

                <div class="grid grid-cols-2 gap-2.5 w-full mb-5">
                    <div class="p-3 rounded-2xl bg-muted/60 border border-border flex flex-col items-center">
                        <p class="text-xl font-black text-primary"><?= $stats['total'] ?></p>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-secondary mt-0.5">Total Log</p>
                    </div>
                    <div class="p-3 rounded-2xl bg-green-50 border border-green-200 flex flex-col items-center">
                        <p class="text-xl font-black text-green-700"><?= $stats['approved'] ?></p>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-green-700 mt-0.5">Approved</p>
                    </div>
                    <div class="p-3 rounded-2xl bg-yellow-50 border border-yellow-200 flex flex-col items-center">
                        <p class="text-xl font-black text-yellow-700"><?= $stats['pending'] ?></p>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-yellow-700 mt-0.5">Pending</p>
                    </div>
                    <div class="p-3 rounded-2xl bg-red-50 border border-red-200 flex flex-col items-center">
                        <p class="text-xl font-black text-red-700"><?= $stats['rejected'] ?></p>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-red-700 mt-0.5">Rejected</p>
                    </div>
                </div>

                <div class="flex flex-col gap-2 w-full pt-4 border-t border-border">
                    <div class="flex items-center gap-2.5 text-xs text-secondary px-3 py-2 rounded-xl bg-white border border-border shadow-sm">
                        <div class="w-7 h-7 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0"><i class="far fa-calendar-plus text-accent text-xs"></i></div>
                        <span class="text-left min-w-0 flex-1 truncate"><strong class="text-primary block leading-tight">Bergabung</strong><?= formatDate((string)($row['created_at'] ?? '')) ?></span>
                    </div>
                    <?php if (!empty($row['last_login'])): ?>
                    <div class="flex items-center gap-2.5 text-xs text-secondary px-3 py-2 rounded-xl bg-white border border-border shadow-sm">
                        <div class="w-7 h-7 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0"><i class="far fa-clock text-blue-600 text-xs"></i></div>
                        <span class="text-left min-w-0 flex-1 truncate"><strong class="text-primary block leading-tight">Login Terakhir</strong><?= formatDateTime((string)($row['last_login'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <form method="POST" class="bg-surface rounded-[1.4rem] border border-border shadow-xl animate-slide-up p-6 sm:p-8 relative overflow-hidden" style="animation-delay: 100ms;">
            <div class="absolute top-0 right-0 w-72 h-72 bg-gradient-to-br from-amber-100/40 via-yellow-50/30 to-transparent rounded-full blur-3xl pointer-events-none -translate-y-1/2 translate-x-1/2"></div>

            <div class="relative z-10 flex items-center gap-4 mb-7 pb-6 border-b border-border">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center shadow-lg shadow-amber-500/30 flex-shrink-0">
                    <i class="fas fa-pen-to-square text-white text-xl"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="font-display font-black text-2xl text-primary">Ubah Data Profil</h2>
                    <p class="text-sm text-secondary mt-0.5">Perbarui informasi pribadi Anda, perubahan langsung disimpan setelah klik Simpan.</p>
                </div>
            </div>

            <div class="relative z-10 grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-6 mb-8">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-primary mb-2 tracking-[0.18em] uppercase flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-accent"></span>Nama Lengkap <span class="text-red-500">*</span></label>
                    <div class="relative group">
                        <span class="absolute left-0 top-0 bottom-0 flex items-center justify-center w-11 text-secondary group-focus-within:text-accent transition-colors"><i class="far fa-id-badge text-sm"></i></span>
                        <input type="text" name="name" maxlength="150" required value="<?= cleanInput((string)($row['name'] ?? '')) ?>"
                            class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-border bg-white text-primary placeholder-secondary/60 focus:outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 transition-all text-sm shadow-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-primary mb-2 tracking-[0.18em] uppercase flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-accent"></span>Email <span class="text-red-500">*</span></label>
                    <div class="relative group">
                        <span class="absolute left-0 top-0 bottom-0 flex items-center justify-center w-11 text-secondary group-focus-within:text-accent transition-colors"><i class="far fa-envelope text-sm"></i></span>
                        <input type="email" name="email" maxlength="150" required value="<?= cleanInput((string)($row['email'] ?? '')) ?>"
                            class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-border bg-white text-primary placeholder-secondary/60 focus:outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 transition-all text-sm shadow-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-primary mb-2 tracking-[0.18em] uppercase flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-accent"></span>Nomor HP / WhatsApp</label>
                    <div class="relative group">
                        <span class="absolute left-0 top-0 bottom-0 flex items-center justify-center w-11 text-secondary group-focus-within:text-accent transition-colors"><i class="fas fa-mobile-screen-button text-sm"></i></span>
                        <input type="tel" name="phone" maxlength="30" value="<?= cleanInput((string)($row['phone'] ?? '')) ?>" placeholder="+62 8xx-xxxx-xxxx"
                            class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-border bg-white text-primary placeholder-secondary/60 focus:outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 transition-all text-sm shadow-sm">
                    </div>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-primary mb-2 tracking-[0.18em] uppercase flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-accent"></span>Jabatan / Position</label>
                    <div class="relative group">
                        <span class="absolute left-0 top-0 bottom-0 flex items-center justify-center w-11 text-secondary group-focus-within:text-accent transition-colors"><i class="fas fa-briefcase text-sm"></i></span>
                        <input type="text" name="position" maxlength="100" value="<?= cleanInput((string)($row['position'] ?? '')) ?>" placeholder="Misal: Engineering Staff Senior, Maintenance, dll"
                            class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-border bg-white text-primary placeholder-secondary/60 focus:outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 transition-all text-sm shadow-sm">
                    </div>
                </div>
            </div>

            <div class="relative z-10 mb-8 p-6 sm:p-7 rounded-[1.2rem] bg-gradient-to-br from-amber-50/90 via-amber-50/60 to-yellow-50 border border-amber-200/80 shadow-inner">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-amber-600 flex items-center justify-center shadow-md shadow-amber-500/40 flex-shrink-0">
                            <i class="fas fa-lock text-white"></i>
                        </div>
                        <div>
                            <h3 class="font-display font-black text-lg text-primary flex items-center gap-2">
                                Ubah Password
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase bg-white border border-amber-200 px-2.5 py-0.5 rounded-full text-amber-700 tracking-wider shadow-sm">
                                    <i class="fas fa-circle-info"></i> Opsional
                                </span>
                            </h3>
                            <p class="text-xs text-secondary mt-0.5">Kosongkan kedua field jika tidak ingin mengubah password login Anda saat ini.</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-xs font-bold text-primary mb-2 tracking-[0.18em] uppercase">Password Baru</label>
                        <div class="relative group">
                            <span class="absolute left-0 top-0 bottom-0 flex items-center justify-center w-11 text-secondary group-focus-within:text-amber-600 transition-colors"><i class="fas fa-key text-sm"></i></span>
                            <input type="password" name="password_new" minlength="6" autocomplete="new-password"
                                class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-amber-200 bg-white/90 text-primary placeholder-secondary/60 focus:outline-none focus:border-amber-600 focus:ring-4 focus:ring-amber-500/20 transition-all text-sm shadow-sm" placeholder="Minimal 6 karakter...">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-primary mb-2 tracking-[0.18em] uppercase">Konfirmasi Password Baru</label>
                        <div class="relative group">
                            <span class="absolute left-0 top-0 bottom-0 flex items-center justify-center w-11 text-secondary group-focus-within:text-amber-600 transition-colors"><i class="fas fa-lock text-sm"></i></span>
                            <input type="password" name="password_confirm" minlength="6" autocomplete="new-password"
                                class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-amber-200 bg-white/90 text-primary placeholder-secondary/60 focus:outline-none focus:border-amber-600 focus:ring-4 focus:ring-amber-500/20 transition-all text-sm shadow-sm" placeholder="Ketik ulang password baru Anda...">
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pt-6 border-t border-border">
                <div class="order-2 sm:order-1 text-xs text-secondary flex items-center gap-1.5">
                    <i class="far fa-clock text-accent"></i>
                    <span>Terakhir diperbarui: <?= $updatedAtRaw ? formatDateTime((string)$updatedAtRaw) : '<span class="italic">Belum pernah diubah</span>' ?></span>
                </div>
                <div class="order-1 sm:order-2 flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
                    <a href="<?= BASE_URL ?>index.php" class="inline-flex items-center justify-center gap-2 px-5 py-3.5 rounded-xl bg-white text-primary text-sm font-bold border border-border hover:bg-muted/60 hover:scale-[1.01] transition-all shadow-sm flex-1 sm:flex-none">
                        <i class="fas fa-chevron-left"></i>
                        Kembali ke Dashboard
                    </a>
                    <button type="submit" class="inline-flex items-center justify-center gap-2.5 px-7 py-3.5 rounded-xl text-white font-bold shadow-xl shadow-amber-500/35 hover:shadow-2xl hover:shadow-amber-500/50 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-300 bg-gradient-to-r from-amber-500 via-amber-600 to-amber-700 shimmer-btn flex-1 sm:flex-none">
                        <i class="fas fa-floppy-disk"></i>
                        Simpan Perubahan Profil
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
