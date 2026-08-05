<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole('supervisor');

$db = Database::getInstance();
$errors = [];
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('error', 'ID Engineer tidak valid');
    redirect('supervisor/users/index.php');
}
$user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
if (!$user || $user['role'] !== 'engineer') {
    setFlash('error', 'Engineer tidak ditemukan atau bukan Engineer');
    redirect('supervisor/users/index.php');
}

$old = [
    'name' => $user['name'],
    'email' => $user['email'],
    'phone' => (string)($user['phone'] ?? ''),
    'position' => (string)($user['position'] ?? 'Engineering Staff'),
    'new_password' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['name'] = cleanInput($_POST['name'] ?? '');
    $old['email'] = cleanInput($_POST['email'] ?? '');
    $old['phone'] = cleanInput($_POST['phone'] ?? '');
    $old['position'] = cleanInput($_POST['position'] ?? 'Engineering Staff');
    $newPwd = $_POST['new_password'] ?? '';
    $newPwd2 = $_POST['new_password_confirm'] ?? '';

    if (mb_strlen($old['name']) < 2) $errors[] = 'Nama minimal 2 karakter';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid';
    if ($old['position'] === '') $old['position'] = 'Engineering Staff';

    if (strtolower($old['email']) !== strtolower($user['email'])) {
        $dup = $db->fetchOne("SELECT id FROM users WHERE email = ? AND id <> ?", [strtolower($old['email']), $id]);
        if ($dup) $errors[] = 'Email sudah digunakan akun lain, silakan pakai email lain';
    }

    if ($newPwd !== '') {
        if (mb_strlen($newPwd) < 6) $errors[] = 'Password baru minimal 6 karakter';
        if ($newPwd !== $newPwd2) $errors[] = 'Konfirmasi password baru tidak cocok';
    }

    if (empty($errors)) {
        $data = [
            'name' => $old['name'],
            'email' => strtolower($old['email']),
            'phone' => $old['phone'] ?: null,
            'position' => $old['position'],
        ];
        if ($newPwd !== '') {
            $data['password'] = password_hash($newPwd, PASSWORD_BCRYPT, ['cost' => 10]);
        }
        $db->update('users', $data, 'id = :id', ['id' => $id]);
        $extra = $newPwd ? ' &amp; password berhasil diubah.' : '.';
        setFlash('success', 'Data Engineer <strong>' . cleanInput($old['name']) . '</strong> berhasil diperbarui' . $extra);
        redirect('supervisor/users/index.php');
    } else {
        setFlash('error', implode('<br>', $errors));
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>
<div class="page-shell page-shell--3xl pb-20 md:pb-10">
    <div class="mb-6 animate-fade-in">
        <a href="<?= BASE_URL ?>supervisor/users/index.php" class="inline-flex items-center gap-1.5 text-sm text-secondary hover:text-primary mb-4 transition-colors">
            <i class="fas fa-chevron-left text-xs"></i> Kembali ke Daftar Engineer
        </a>
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-sm text-secondary mb-1"><i class="fas fa-user-pen mr-1.5 text-accent"></i>Edit Staff Engineer</p>
                <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">Ubah Data: <?= cleanInput($user['name']) ?></h1>
                <p class="text-secondary text-sm">Email saat ini: <code class="px-2 py-0.5 bg-muted rounded text-xs"><?= cleanInput($user['email']) ?></code></p>
            </div>
        </div>
    </div>

    <form method="POST" class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up">
        <div class="px-5 lg:px-6 py-4 border-b border-border bg-gradient-to-r from-blue-50 to-surface">
            <h3 class="font-bold text-primary flex items-center gap-2">
                <i class="fas fa-id-card text-blue-600"></i>Data Pribadi Engineer
            </h3>
            <p class="text-xs text-secondary mt-0.5">Kosongkan kolom Password baru jika tidak ingin mengubah password.</p>
        </div>

        <div class="p-5 lg:p-6 space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-primary mb-2">
                        <i class="fas fa-signature mr-1.5 text-accent"></i>Nama Lengkap <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <i class="fas fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                        <input type="text" name="name" required value="<?= cleanInput($old['name']) ?>" autocomplete="name"
                            class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-primary mb-2">
                        <i class="fas fa-envelope mr-1.5 text-accent"></i>Email <span class="text-red-500">*</span> <span class="text-xs text-secondary font-normal">(unik)</span>
                    </label>
                    <div class="relative">
                        <i class="fas fa-at absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                        <input type="email" name="email" required value="<?= cleanInput($old['email']) ?>" autocomplete="email"
                            class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-primary mb-2">
                        <i class="fas fa-phone mr-1.5 text-accent"></i>No. Handphone / WhatsApp
                    </label>
                    <div class="relative">
                        <i class="fas fa-mobile-screen absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                        <input type="tel" name="phone" value="<?= cleanInput($old['phone']) ?>" autocomplete="tel" placeholder="+62 812-xxxx-xxxx"
                            class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all">
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-primary mb-2">
                        <i class="fas fa-briefcase mr-1.5 text-accent"></i>Jabatan / Posisi
                    </label>
                    <div class="relative">
                        <i class="fas fa-sitemap absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                        <input type="text" name="position" value="<?= cleanInput($old['position']) ?>"
                            class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all">
                    </div>
                </div>

                <div class="md:col-span-2 p-4 rounded-card border border-dashed border-border bg-muted/30">
                    <p class="text-xs font-semibold text-secondary uppercase tracking-wider mb-3">
                        <i class="fas fa-key mr-1.5 text-accent"></i>Ubah Password (Opsional)
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-semibold text-primary mb-2">Password Baru (min 6)</label>
                            <div class="relative">
                                <i class="fas fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                                <input type="password" id="pwd" name="new_password" minlength="6" autocomplete="new-password"
                                    placeholder="Kosongkan jika tidak diubah"
                                    class="w-full pl-11 pr-11 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all">
                                <button type="button" onclick="togglePwd('pwd','eye1')" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-secondary hover:text-primary transition-colors">
                                    <i id="eye1" class="fas fa-eye text-sm"></i>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary mb-2">Konfirmasi Password Baru</label>
                            <div class="relative">
                                <i class="fas fa-shield-halved absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                                <input type="password" id="pwd2" name="new_password_confirm" minlength="6" autocomplete="new-password"
                                    placeholder="Konfirmasi password baru"
                                    class="w-full pl-11 pr-11 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all">
                                <button type="button" onclick="togglePwd('pwd2','eye2')" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-secondary hover:text-primary transition-colors">
                                    <i id="eye2" class="fas fa-eye text-sm"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:justify-end gap-3 pt-4 border-t border-border">
                <a href="<?= BASE_URL ?>supervisor/users/index.php" class="px-5 py-3 rounded-card bg-muted text-primary text-sm font-semibold border border-border hover:bg-white transition-colors inline-flex items-center justify-center gap-1.5">
                    <i class="fas fa-xmark"></i> Batal
                </a>
                <button type="submit" class="px-6 py-3 rounded-card bg-gradient-to-r from-primary via-gray-900 to-primary text-white text-sm font-semibold shadow-lg hover:shadow-2xl hover:-translate-y-0.5 transition-all duration-300 inline-flex items-center justify-center gap-2 group">
                    <i class="fas fa-floppy-disk group-hover:scale-110 transition-transform"></i>
                    Simpan Perubahan
                </button>
            </div>
        </div>
    </form>
</div>

<script>
function togglePwd(inputId, iconId) {
    const i = document.getElementById(inputId);
    const ic = document.getElementById(iconId);
    if (i.type === 'password') {
        i.type = 'text';
        ic.classList.remove('fa-eye'); ic.classList.add('fa-eye-slash');
    } else {
        i.type = 'password';
        ic.classList.remove('fa-eye-slash'); ic.classList.add('fa-eye');
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
