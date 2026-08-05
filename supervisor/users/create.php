<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
requireRole('supervisor');

$db = Database::getInstance();
$errors = [];
$old = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'position' => 'Engineering Staff',
    'password' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['name'] = cleanInput($_POST['name'] ?? '');
    $old['email'] = cleanInput($_POST['email'] ?? '');
    $old['phone'] = cleanInput($_POST['phone'] ?? '');
    $old['position'] = cleanInput($_POST['position'] ?? 'Engineering Staff');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password_confirm'] ?? '';

    if (mb_strlen($old['name']) < 2) $errors[] = 'Nama minimal 2 karakter';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid';
    if (mb_strlen($password) < 6) $errors[] = 'Password minimal 6 karakter';
    if ($password !== $password2) $errors[] = 'Konfirmasi password tidak cocok';
    if ($old['position'] === '') $old['position'] = 'Engineering Staff';

    $dup = $db->fetchOne("SELECT id FROM users WHERE email = ?", [strtolower($old['email'])]);
    if ($dup) $errors[] = 'Email sudah digunakan, silakan pakai email lain';

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $db->insert('users', [
            'name' => $old['name'],
            'email' => strtolower($old['email']),
            'password' => $hash,
            'role' => 'engineer',
            'phone' => $old['phone'] ?: null,
            'position' => $old['position'],
        ]);
        setFlash('success', 'Akun Engineer <strong>' . cleanInput($old['name']) . '</strong> berhasil dibuat. Password default: ' . cleanInput($password));
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
                <p class="text-sm text-secondary mb-1"><i class="fas fa-user-plus mr-1.5 text-accent"></i>Tambah Staff Baru</p>
                <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">Buat Akun Engineer</h1>
                <p class="text-secondary text-sm">Akun akan otomatis memiliki role Engineer &amp; bisa mengisi Daily Log.</p>
            </div>
        </div>
    </div>

    <form method="POST" class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up">
        <div class="px-5 lg:px-6 py-4 border-b border-border bg-gradient-to-r from-accent/10 to-surface">
            <h3 class="font-bold text-primary flex items-center gap-2">
                <i class="fas fa-id-card text-accent"></i>Informasi Pribadi Engineer
            </h3>
            <p class="text-xs text-secondary mt-0.5">Isi data lengkap Engineer yang akan diberikan akses sistem.</p>
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
                        <input type="email" name="email" required value="<?= cleanInput($old['email']) ?>" autocomplete="email" placeholder="nama@stregisbali.com"
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
                        <input type="text" name="position" value="<?= cleanInput($old['position']) ?>" placeholder="Engineering Staff / Senior Engineer / dll..."
                            class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-primary mb-2">
                        <i class="fas fa-key mr-1.5 text-accent"></i>Password <span class="text-red-500">*</span> <span class="text-xs text-secondary font-normal">(min. 6 karakter)</span>
                    </label>
                    <div class="relative">
                        <i class="fas fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                        <input type="password" id="pwd" name="password" required minlength="6" autocomplete="new-password" placeholder="••••••••"
                            class="w-full pl-11 pr-11 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all">
                        <button type="button" onclick="togglePwd('pwd','eye1')" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-secondary hover:text-primary transition-colors">
                            <i id="eye1" class="fas fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-primary mb-2">
                        <i class="fas fa-lock mr-1.5 text-accent"></i>Konfirmasi Password <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <i class="fas fa-shield-halved absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                        <input type="password" id="pwd2" name="password_confirm" required minlength="6" autocomplete="new-password" placeholder="••••••••"
                            class="w-full pl-11 pr-11 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all">
                        <button type="button" onclick="togglePwd('pwd2','eye2')" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-secondary hover:text-primary transition-colors">
                            <i id="eye2" class="fas fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="p-4 rounded-card bg-amber-50 border border-amber-200 animate-fade-in">
                <p class="text-xs font-semibold text-amber-700 mb-1"><i class="fas fa-circle-info mr-1"></i>Catatan Keamanan</p>
                <ul class="text-[12px] text-amber-800 list-disc list-inside space-y-0.5">
                    <li>Password disimpan dalam bentuk hash bcrypt (tidak dapat dibaca admin).</li>
                    <li>Sarankan Engineer untuk mengganti password setelah login pertama.</li>
                    <li>Email harus UNIQUE — tidak boleh ada akun lain dengan email sama.</li>
                </ul>
            </div>

            <div class="flex flex-col sm:flex-row sm:justify-end gap-3 pt-4 border-t border-border">
                <a href="<?= BASE_URL ?>supervisor/users/index.php" class="px-5 py-3 rounded-card bg-muted text-primary text-sm font-semibold border border-border hover:bg-white transition-colors inline-flex items-center justify-center gap-1.5">
                    <i class="fas fa-xmark"></i> Batal
                </a>
                <button type="submit" class="px-6 py-3 rounded-card bg-gradient-to-r from-primary via-gray-900 to-primary text-white text-sm font-semibold shadow-lg hover:shadow-2xl hover:-translate-y-0.5 transition-all duration-300 inline-flex items-center justify-center gap-2 group">
                    <i class="fas fa-user-plus group-hover:scale-110 transition-transform"></i>
                    Buat Akun Engineer
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
