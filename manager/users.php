<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
requireRole('manager');

$db = Database::getInstance();
$me = currentUser();
$myId = (int)($me['id'] ?? 0);

// ==============================================
// AUTO ALTER TABLE (jika kolom / ENUM belum lengkap)
// ==============================================
try {
    $chkCol = $db->fetchOne("SHOW COLUMNS FROM users LIKE 'last_login'");
    if (!$chkCol) @$db->query("ALTER TABLE users ADD COLUMN last_login DATETIME NULL AFTER updated_at");

    $chkRole = $db->fetchOne("SHOW COLUMNS FROM users LIKE 'role'");
    if ($chkRole && stripos((string)($chkRole['Type'] ?? ''), 'supervisor') === false) {
        @$db->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin','manager','supervisor','engineer') NOT NULL DEFAULT 'engineer'");
    }
} catch (Throwable $_) {}

// ==============================================
// POST HANDLERS
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // ================= SAVE USER (CREATE / EDIT) =================
    if ($action === 'save_user') {
        $id = max(0, (int)($_POST['user_id'] ?? 0));
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $position = trim((string)($_POST['position'] ?? ''));
        $role = in_array(($_POST['role'] ?? ''), ['manager','supervisor','engineer'], true) ? (string)$_POST['role'] : 'engineer';
        $pwd = (string)($_POST['password'] ?? '');
        $pwd2 = (string)($_POST['password_confirm'] ?? '');
        $errors = [];

        if (mb_strlen($name) < 2) $errors[] = 'Nama lengkap minimal 2 karakter';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid';
        if ($position === '') $position = match($role) {
            'manager' => 'Engineering Manager',
            'supervisor' => 'Engineering Supervisor',
            default => 'Engineering Staff',
        };

        if ($id > 0) {
            // EDIT MODE
            $old = $db->fetchOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$id]);
            if (!$old) {
                setFlash('error', 'User tidak ditemukan');
                redirect('manager/users.php');
            }
            // Cek email unique kecuali dirinya sendiri
            $dup = $db->fetchOne("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1", [$email, $id]);
            if ($dup) $errors[] = 'Email sudah terdaftar di akun lain, gunakan email berbeda';
            // Password optional di edit
            if ($pwd !== '') {
                if (mb_strlen($pwd) < 6) $errors[] = 'Password baru minimal 6 karakter';
                if ($pwd !== $pwd2) $errors[] = 'Konfirmasi password baru tidak cocok';
            }
        } else {
            // CREATE MODE
            if (mb_strlen($pwd) < 6) $errors[] = 'Password minimal 6 karakter';
            if ($pwd !== $pwd2) $errors[] = 'Konfirmasi password tidak cocok';
            $dup = $db->fetchOne("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
            if ($dup) $errors[] = 'Email sudah terdaftar, pakai email berbeda';
        }

        if (empty($errors)) {
            $data = [
                'name' => $name,
                'email' => $email,
                'phone' => $phone ?: null,
                'position' => $position,
                'role' => $role,
            ];
            if ($id > 0) {
                if ($pwd !== '') {
                    $data['password'] = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 10]);
                }
                $db->update('users', $data, 'id = :id', ['id' => $id]);
                setFlash('success', "✅ Data akun <strong>{$name}</strong> (role: " . strtoupper($role) . ") BERHASIL di-UPDATE" . ($pwd !== '' ? ' & Password diubah.' : '.'));
            } else {
                $data['password'] = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 10]);
                $db->insert('users', $data);
                setFlash('success', "✅ Akun BARU <strong>{$name}</strong> (role: " . strtoupper($role) . ") BERHASIL dibuat. • Password default: <code class='px-2 py-0.5 bg-amber-100 text-amber-800 rounded text-[11px] font-black'>" . htmlspecialchars($pwd) . "</code>");
            }
            redirect('manager/users.php');
        } else {
            setFlash('error', implode('<br>', $errors));
        }
    }

    // ================= DELETE USER =================
    if ($action === 'delete_user') {
        $id = max(0, (int)($_POST['user_id'] ?? 0));
        if ($id <= 0) {
            setFlash('error', 'ID user tidak valid');
            redirect('manager/users.php');
        }
        if ($id === $myId) {
            setFlash('danger', '❌ TIDAK BISA MENGHAPUS AKUN SENDIRI!');
            redirect('manager/users.php');
        }
        $target = $db->fetchOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$id]);
        if (!$target) {
            setFlash('error', 'User tidak ditemukan');
            redirect('manager/users.php');
        }
        $checkLogs = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM daily_logs WHERE engineer_id = ?", [$id])['cnt'] ?? 0);
        if ($checkLogs > 0) {
            setFlash('warning', "⚠️ Tidak bisa dihapus! Akun <strong>{$target['name']}</strong> sudah memiliki {$checkLogs} Daily Log. Nonaktifkan secara manual jika perlu.");
            redirect('manager/users.php');
        }
        $checkReq = (int)($db->fetchOne("SELECT COUNT(*) as cnt FROM orders WHERE requested_by = ?", [$id])['cnt'] ?? 0);
        if ($checkReq > 0) {
            setFlash('warning', "⚠️ Tidak bisa dihapus! Akun <strong>{$target['name']}</strong> sudah buat {$checkReq} Order Request. Nonaktifkan secara manual jika perlu.");
            redirect('manager/users.php');
        }
        $db->query("DELETE FROM users WHERE id = ? LIMIT 1", [$id]);
        setFlash('success', "🗑️ Akun <strong>{$target['name']}</strong> (role " . strtoupper($target['role']) . ") BERHASIL di-HAPUS.");
        redirect('manager/users.php');
    }
}

// ==============================================
// QUERY LIST USERS
// ==============================================
$search = trim((string)($_GET['search'] ?? ''));
$filterRole = in_array(($_GET['role'] ?? ''), ['all','manager','supervisor','engineer'], true) ? (string)$_GET['role'] : 'all';

$where = "WHERE 1 = 1";
$params = [];
if ($filterRole !== 'all') { $where .= " AND u.role = ?"; $params[] = $filterRole; }
if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.position LIKE ? OR u.phone LIKE ?)";
    $kw = "%{$search}%";
    array_push($params, $kw, $kw, $kw, $kw);
}
$list = $db->fetchAll("SELECT u.*,
   (SELECT COUNT(*) FROM daily_logs dl WHERE dl.engineer_id = u.id) as total_log,
   (SELECT COUNT(*) FROM orders orr WHERE orr.requested_by = u.id) as total_orders
    FROM users u
    $where
    ORDER BY FIELD(u.role,'manager','supervisor','engineer'), u.created_at DESC, u.name ASC", $params);

// STATISTICS
$statTotal = count($db->fetchAll("SELECT id FROM users"));
$cntMgr = 0; $cntSpv = 0; $cntEng = 0;
foreach ($list as $u) {
    $r = (string)($u['role'] ?? '');
    if ($r === 'manager') $cntMgr++;
    elseif ($r === 'supervisor') $cntSpv++;
    else $cntEng++;
}
// Jika filter role, hitung ulang statistics tanpa filter
if ($filterRole !== 'all' || $search !== '') {
    $allUsers = $db->fetchAll("SELECT role FROM users");
    $cntMgr = 0; $cntSpv = 0; $cntEng = 0;
    foreach ($allUsers as $u) {
        $r = (string)($u['role'] ?? '');
        if ($r === 'manager') $cntMgr++;
        elseif ($r === 'supervisor') $cntSpv++;
        else $cntEng++;
    }
    $statTotal = count($allUsers);
}

// HELPER: Render Badge Role
$fnRoleBadge = function($role) {
    $role = strtolower((string)$role);
    return match($role) {
        'manager' => '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gradient-to-br from-purple-500 to-purple-700 text-white text-[11px] font-black shadow-sm border border-purple-300/30"><i class="fas fa-crown text-[10px]"></i> MANAGER</span>',
        'supervisor' => '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gradient-to-br from-blue-500 to-blue-700 text-white text-[11px] font-black shadow-sm border border-blue-300/30"><i class="fas fa-user-shield text-[10px]"></i> SUPERVISOR</span>',
        'admin' => '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gradient-to-br from-rose-500 to-rose-700 text-white text-[11px] font-black shadow-sm border border-rose-300/30"><i class="fas fa-user-lock text-[10px]"></i> ADMIN</span>',
        default => '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gradient-to-br from-emerald-500 to-emerald-700 text-white text-[11px] font-black shadow-sm border border-emerald-300/30"><i class="fas fa-hard-hat text-[10px]"></i> STAFF / ENGINEER</span>',
    };
};

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$isManageUsersPage = true;
?>

<div class="page-shell page-shell--7xl pb-20 md:pb-10">
    <!-- HEADER PAGE -->
    <div class="mb-6 animate-fade-in">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <p class="text-sm text-secondary mb-1">
                    <i class="fas fa-users-gear mr-1.5 text-purple-600"></i>
                    Manager Area • Pengelolaan Akun Sistem
                </p>
                <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">📚 Daftar Akun Pengguna</h1>
                <p class="text-secondary text-sm">Kelola semua akun sistem. Bisa buat akun <strong>Manager</strong>, <strong>Supervisor</strong>, dan <strong>Staff / Engineer</strong>. Total <strong class="text-primary"><?= $statTotal ?> akun</strong> terdaftar.</p>
            </div>
            <button type="button" onclick="openUserModal(0)"
                    class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-card bg-gradient-to-r from-purple-600 via-indigo-600 to-purple-700 text-white font-semibold shadow-lg hover:shadow-2xl hover:-translate-y-0.5 transition-all duration-300 self-start sm:self-end animate-pulse-slow">
                <i class="fas fa-user-plus text-lg"></i>
                + Tambah Akun Baru
            </button>
        </div>
    </div>

    <!-- STATISTIC 4 CARDS -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-[0.15em] text-slate-500">Total Akun</span>
                <span class="w-9 h-9 rounded-xl bg-slate-100 flex items-center justify-center text-slate-600"><i class="fas fa-users"></i></span>
            </div>
            <p class="font-display text-3xl font-black text-primary leading-none"><?= $statTotal ?></p>
            <p class="text-[11px] text-slate-500 mt-1">Semua role terdaftar</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-[0.15em] text-purple-600">Manager</span>
                <span class="w-9 h-9 rounded-xl bg-purple-50 flex items-center justify-center text-purple-600"><i class="fas fa-crown"></i></span>
            </div>
            <p class="font-display text-3xl font-black text-purple-700 leading-none"><?= $cntMgr ?></p>
            <p class="text-[11px] text-slate-500 mt-1">Akses penuh semua fitur</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-[0.15em] text-blue-600">Supervisor</span>
                <span class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600"><i class="fas fa-user-shield"></i></span>
            </div>
            <p class="font-display text-3xl font-black text-blue-700 leading-none"><?= $cntSpv ?></p>
            <p class="text-[11px] text-slate-500 mt-1">Review &amp; Approval Daily Log</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-[0.15em] text-emerald-600">Staff Engineer</span>
                <span class="w-9 h-9 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600"><i class="fas fa-hard-hat"></i></span>
            </div>
            <p class="font-display text-3xl font-black text-emerald-700 leading-none"><?= $cntEng ?></p>
            <p class="text-[11px] text-slate-500 mt-1">Isi Daily Log &amp; Order Request</p>
        </div>
    </div>

    <!-- TABLE FILTER & SEARCH -->
    <div class="bg-surface rounded-premium border border-border shadow-sm mb-6 animate-slide-up" style="animation-delay: 80ms">
        <form method="GET" class="flex flex-col md:flex-row gap-3 p-4 sm:p-5 border-b border-border">
            <div class="md:w-52">
                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Filter Role</label>
                <select name="role" onchange="this.form.submit()" class="w-full px-3 py-3 rounded-card border border-border bg-muted/50 text-primary text-sm font-bold focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all appearance-none pr-8">
                    <option value="all" <?= $filterRole === 'all' ? 'selected' : '' ?>>🌐 Semua Role</option>
                    <option value="manager" <?= $filterRole === 'manager' ? 'selected' : '' ?>>👑 Manager</option>
                    <option value="supervisor" <?= $filterRole === 'supervisor' ? 'selected' : '' ?>>🛡️ Supervisor</option>
                    <option value="engineer" <?= $filterRole === 'engineer' ? 'selected' : '' ?>>👷 Staff / Engineer</option>
                </select>
            </div>
            <div class="relative flex-1">
                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Pencarian</label>
                <i class="fas fa-magnifying-glass absolute left-3.5 bottom-4 -translate-y-1/2 text-secondary text-sm"></i>
                <input type="text" name="search" value="<?= cleanInput($search) ?>" placeholder="Cari nama, email, jabatan, atau nomor HP..."
                    class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 focus:bg-surface transition-all text-sm mt-[2px]">
            </div>
            <div class="flex gap-2 items-end">
                <button type="submit" class="flex-1 sm:flex-none px-5 py-3 rounded-card bg-primary text-white text-sm font-semibold hover:bg-secondary transition-colors inline-flex items-center justify-center gap-1.5">
                    <i class="fas fa-filter"></i> Terapkan
                </button>
                <a href="?" class="flex-1 sm:flex-none px-5 py-3 rounded-card bg-muted text-primary text-sm font-semibold border border-border hover:bg-white transition-colors inline-flex items-center justify-center gap-1.5">
                    <i class="fas fa-rotate-left"></i> Reset
                </a>
            </div>
        </form>

        <div class="overflow-x-auto -mx-4 sm:mx-0 px-4 sm:px-0">
            <table class="w-full text-sm min-w-[1000px]">
                <thead class="bg-gradient-to-r from-slate-50 to-slate-100 border-b-2 border-slate-200">
                    <tr class="text-left text-secondary">
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap w-10">#</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap">Pengguna</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap">Role Akses</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap text-center">Total Log</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap text-center">Total Order</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap">Terakhir Login</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap text-right pr-5">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (count($list) > 0):
                        $no = 1;
                        foreach ($list as $u):
                            $init = strtoupper(mb_substr((string)($u['name'] ?? ''), 0, 1) ?: 'U');
                            $lastLogin = $u['last_login'] ?? null;
                            $isMe = (int)$u['id'] === $myId;
                    ?>
                    <tr class="hover:bg-slate-50 transition-colors group">
                        <td class="px-4 sm:px-5 py-4 align-top text-xs font-bold text-slate-500"><?= $no++ ?>.</td>
                        <td class="px-4 sm:px-5 py-4 align-top">
                            <div class="flex items-center gap-3">
                                <div class="avatar-md-sm"><?= $init ?></div>
                                <div class="min-w-0">
                                    <p class="font-semibold text-primary">
                                        <?= cleanInput($u['name']) ?>
                                        <?php if ($isMe): ?>
                                            <span class="ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-50 border border-amber-200 text-amber-700 text-[9px] font-black uppercase tracking-wider"><i class="fas fa-hand-sparkles text-[8px]"></i> ANDA</span>
                                        <?php endif; ?>
                                    </p>
                                    <p class="text-xs text-secondary">
                                        <i class="fas fa-briefcase text-slate-400 mr-1"></i><?= cleanInput($u['position'] ?? '-') ?>
                                    </p>
                                    <div class="flex flex-col sm:flex-row sm:items-center gap-0.5 sm:gap-3 mt-1 text-[11px] text-slate-500">
                                        <span class="inline-flex items-center gap-1"><i class="fas fa-at text-slate-400 text-[10px]"></i><?= cleanInput($u['email']) ?></span>
                                        <?php if (!empty($u['phone'])): ?>
                                            <span class="inline-flex items-center gap-1"><i class="fas fa-mobile-screen text-slate-400 text-[10px]"></i><?= cleanInput($u['phone']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 sm:px-5 py-4 align-top"><?= $fnRoleBadge($u['role']) ?></td>
                        <td class="px-4 sm:px-5 py-4 text-center align-top"><span class="inline-flex items-center justify-center px-3 py-1 rounded-full bg-slate-100 border border-slate-200 text-slate-700 font-bold min-w-[52px]"><?= (int)($u['total_log'] ?? 0) ?></span></td>
                        <td class="px-4 sm:px-5 py-4 text-center align-top"><span class="inline-flex items-center justify-center px-3 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 font-bold min-w-[52px]"><?= (int)($u['total_orders'] ?? 0) ?></span></td>
                        <td class="px-4 sm:px-5 py-4 align-top">
                            <?php if ($lastLogin): ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-[11px] font-bold">
                                    <i class="fas fa-circle text-[6px] text-emerald-500"></i>
                                    <?= date('d M Y H:i', strtotime($lastLogin)) ?>
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 border border-slate-200 text-slate-500 text-[11px] font-bold">
                                    <i class="fas fa-circle text-[6px] text-slate-400"></i> Belum pernah login
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 sm:px-5 py-4 pr-5 align-top">
                            <div class="flex gap-2 justify-end flex-wrap items-center">
                                <input type="hidden" id="u_<?= (int)$u['id'] ?>_name" value="<?= htmlspecialchars((string)$u['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" id="u_<?= (int)$u['id'] ?>_email" value="<?= htmlspecialchars((string)$u['email'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" id="u_<?= (int)$u['id'] ?>_phone" value="<?= htmlspecialchars((string)($u['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" id="u_<?= (int)$u['id'] ?>_position" value="<?= htmlspecialchars((string)($u['position'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" id="u_<?= (int)$u['id'] ?>_role" value="<?= htmlspecialchars((string)$u['role'], ENT_QUOTES, 'UTF-8') ?>">
                                <button type="button" onclick="openUserModal(<?= (int)$u['id'] ?>)"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 hover:bg-indigo-500 hover:text-white hover:border-indigo-500 hover:-translate-y-0.5 transition-all">
                                    <i class="fas fa-pencil"></i> Edit
                                </button>
                                <button type="button" onclick='confirmDeleteUser(<?= (int)$u['id'] ?>, <?= json_encode((string)$u['name'], JSON_UNESCAPED_UNICODE) ?>, <?= (int)$isMe ? 'true' : 'false' ?>)'
                                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-500 hover:text-white hover:border-rose-500 hover:-translate-y-0.5 transition-all <?= $isMe ? 'opacity-50 cursor-not-allowed pointer-events-none' : '' ?>"
                                        <?= $isMe ? 'disabled title="Tidak bisa menghapus akun sendiri"' : '' ?>>
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr><td colspan="7" class="px-4 sm:px-5 py-20 text-center text-secondary">
                        <div class="w-20 h-20 mx-auto mb-4 rounded-3xl bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center text-slate-400">
                            <i class="fas fa-user-xmark text-3xl"></i>
                        </div>
                        <h3 class="font-black text-xl text-slate-700 mb-2">
                            <?= $search || $filterRole !== 'all' ? 'Tidak ada akun yang cocok' : 'Belum ada akun terdaftar' ?>
                        </h3>
                        <p class="text-sm text-slate-500 mb-5 max-w-md mx-auto">
                            <?= $search || $filterRole !== 'all' ? 'Coba ubah kata kunci pencarian atau reset filter Role.' : 'Klik tombol di pojok kanan atas untuk menambahkan akun pertama.' ?>
                        </p>
                        <?php if ($search || $filterRole !== 'all'): ?>
                            <a href="?" class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-card bg-muted text-primary text-xs font-bold border border-border hover:bg-white transition">
                                <i class="fas fa-rotate-left"></i> Reset Filter
                            </a>
                        <?php else: ?>
                            <button type="button" onclick="openUserModal(0)" class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-card bg-gradient-to-br from-purple-600 to-indigo-700 text-white text-xs font-bold shadow-md hover:shadow-lg transition">
                                <i class="fas fa-user-plus"></i> Tambah Akun Pertama
                            </button>
                        <?php endif; ?>
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================================ MODAL CRUD CREATE/EDIT USER ================================ -->
<div id="modal-user" class="fixed inset-0 z-[99999] hidden items-center justify-center p-4 animate-fade-in-modal"
     onclick="if(event.target===this)closeUserModal()">
    <div class="absolute inset-0 bg-slate-900/65 backdrop-blur-md"></div>
    <div class="relative w-full max-w-3xl max-h-[93vh] bg-white rounded-3xl shadow-[0_30px_100px_-20px_rgba(30,41,59,0.45)] overflow-hidden flex flex-col animate-slide-up-modal border-2 border-purple-200">
        <!-- HEADER MODAL -->
        <div class="bg-gradient-to-br from-purple-500 via-indigo-500 to-purple-700 p-5 sm:p-6 text-white relative overflow-hidden">
            <div class="absolute -right-12 -top-12 w-44 h-44 bg-white/10 rounded-full blur-2xl"></div>
            <div class="absolute -right-24 bottom-0 w-64 h-64 bg-black/10 rounded-full blur-3xl"></div>
            <div class="relative flex items-start justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-white/20 backdrop-blur-sm border border-white/30 flex items-center justify-center ring-2 ring-white/40 shadow-lg shrink-0">
                        <i id="modalUserIcon" class="fas fa-user-plus text-2xl sm:text-3xl"></i>
                    </div>
                    <div>
                        <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.25em] text-white/85 mb-1.5">Form Pengelolaan Akun</p>
                        <h4 id="modalUserTitle" class="font-display text-2xl sm:text-3xl font-black tracking-wide leading-tight">Tambah Akun Baru</h4>
                        <div class="flex items-center gap-3 mt-2">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/20 backdrop-blur-sm border border-white/30 text-white text-xs font-bold">
                                <i class="fas fa-shield-halved"></i> Hanya Manager yang bisa akses
                            </span>
                        </div>
                    </div>
                </div>
                <button type="button"
                        onclick="closeUserModal()"
                        class="shrink-0 w-11 h-11 rounded-2xl bg-white/20 hover:bg-white text-white hover:text-rose-600 border border-white/30 backdrop-blur-sm flex items-center justify-center transition-all duration-200 hover:scale-110 active:scale-95 shadow-md"
                        aria-label="Tutup modal">
                    <i class="fas fa-xmark text-xl font-black"></i>
                </button>
            </div>
        </div>

        <form method="POST" id="formUser" class="flex-1 overflow-y-auto">
            <input type="hidden" name="action" value="save_user">
            <input type="hidden" name="user_id" id="f_user_id" value="0">

            <div class="p-5 sm:p-6 space-y-5">
                <!-- PILIH ROLE (PALING ATAS) -->
                <div class="p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-purple-50 to-indigo-50 border-2 border-dashed border-purple-200 animate-fade-in">
                    <p class="text-[11px] font-black uppercase tracking-[0.2em] text-purple-700 mb-2 flex items-center gap-1.5">
                        <i class="fas fa-key"></i> 1. Pilih LEVEL / ROLE AKUN
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3" id="rolePicker">
                        <label class="cursor-pointer role-card" data-role="manager">
                            <input type="radio" name="role" value="manager" class="peer sr-only">
                            <div class="rounded-xl border-2 border-slate-200 bg-white p-3 peer-checked:border-purple-500 peer-checked:bg-purple-50 hover:border-purple-400 hover:-translate-y-0.5 transition-all shadow-sm">
                                <div class="flex items-center gap-3">
                                    <span class="w-11 h-11 rounded-xl bg-gradient-to-br from-purple-500 to-purple-700 text-white flex items-center justify-center shadow-sm"><i class="fas fa-crown"></i></span>
                                    <div class="min-w-0">
                                        <p class="font-black text-sm text-primary leading-tight">MANAGER</p>
                                        <p class="text-[10px] text-slate-500 leading-tight mt-0.5">Akses penuh semua fitur, Kelola Akun, Activities</p>
                                    </div>
                                </div>
                            </div>
                        </label>
                        <label class="cursor-pointer role-card" data-role="supervisor">
                            <input type="radio" name="role" value="supervisor" class="peer sr-only">
                            <div class="rounded-xl border-2 border-slate-200 bg-white p-3 peer-checked:border-blue-500 peer-checked:bg-blue-50 hover:border-blue-400 hover:-translate-y-0.5 transition-all shadow-sm">
                                <div class="flex items-center gap-3">
                                    <span class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500 to-blue-700 text-white flex items-center justify-center shadow-sm"><i class="fas fa-user-shield"></i></span>
                                    <div class="min-w-0">
                                        <p class="font-black text-sm text-primary leading-tight">SUPERVISOR</p>
                                        <p class="text-[10px] text-slate-500 leading-tight mt-0.5">Approval Daily Log, Buat Order, Review Staff</p>
                                    </div>
                                </div>
                            </div>
                        </label>
                        <label class="cursor-pointer role-card" data-role="engineer">
                            <input type="radio" name="role" value="engineer" class="peer sr-only" checked>
                            <div class="rounded-xl border-2 border-slate-200 bg-white p-3 peer-checked:border-emerald-500 peer-checked:bg-emerald-50 hover:border-emerald-400 hover:-translate-y-0.5 transition-all shadow-sm">
                                <div class="flex items-center gap-3">
                                    <span class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 text-white flex items-center justify-center shadow-sm"><i class="fas fa-hard-hat"></i></span>
                                    <div class="min-w-0">
                                        <p class="font-black text-sm text-primary leading-tight">STAFF / ENGINEER</p>
                                        <p class="text-[10px] text-slate-500 leading-tight mt-0.5">Isi Daily Log, Buat Order Request</p>
                                    </div>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- DATA PRIBADI -->
                <div class="p-4 sm:p-5 rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-700 mb-3 flex items-center gap-1.5">
                        <i class="fas fa-id-card text-slate-500"></i> 2. Data Pribadi Pengguna
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-primary mb-2">
                                <i class="fas fa-signature mr-1.5 text-purple-600"></i>Nama Lengkap <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <i class="fas fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                                <input type="text" name="name" id="f_name" required autocomplete="name"
                                    class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-200 focus:bg-surface transition-all text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-primary mb-2">
                                <i class="fas fa-envelope mr-1.5 text-purple-600"></i>Email <span class="text-red-500">*</span> <span class="text-[10px] text-slate-400 font-normal">(UNIQUE / tidak boleh sama)</span>
                            </label>
                            <div class="relative">
                                <i class="fas fa-at absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                                <input type="email" name="email" id="f_email" required autocomplete="email" placeholder="nama@stregisbali.com"
                                    class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-200 focus:bg-surface transition-all text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-primary mb-2">
                                <i class="fas fa-phone mr-1.5 text-purple-600"></i>No. Handphone / WhatsApp
                            </label>
                            <div class="relative">
                                <i class="fas fa-mobile-screen absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                                <input type="tel" name="phone" id="f_phone" autocomplete="tel" placeholder="+62 812-xxxx-xxxx"
                                    class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-200 focus:bg-surface transition-all text-sm">
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-primary mb-2">
                                <i class="fas fa-briefcase mr-1.5 text-purple-600"></i>Jabatan / Posisi
                            </label>
                            <div class="relative">
                                <i class="fas fa-sitemap absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                                <input type="text" name="position" id="f_position" placeholder="Engineering Staff, Senior Supervisor, Chief Engineer..."
                                    class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-200 focus:bg-surface transition-all text-sm">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PASSWORD -->
                <div class="p-4 sm:p-5 rounded-2xl border border-slate-200 bg-white shadow-sm" id="pwdWrap">
                    <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-700 mb-3 flex items-center gap-1.5">
                        <i class="fas fa-lock text-slate-500"></i> 3. Password
                        <span id="pwdHint" class="ml-auto text-[10px] font-bold text-rose-600 uppercase tracking-wider">* WAJIB diisi untuk akun BARU</span>
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label id="lbl_pwd" class="block text-sm font-semibold text-primary mb-2">
                                Password <span class="text-red-500">*</span> <span class="text-[10px] text-slate-400 font-normal">(min. 6 karakter)</span>
                            </label>
                            <div class="relative">
                                <i class="fas fa-key absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                                <input type="password" id="pwd" name="password" autocomplete="new-password" minlength="6" placeholder="••••••••"
                                    class="w-full pl-11 pr-11 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-200 focus:bg-surface transition-all">
                                <button type="button" onclick="togglePwd('pwd','eye1')" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-secondary hover:text-primary transition-colors">
                                    <i id="eye1" class="fas fa-eye text-sm"></i>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label id="lbl_pwd2" class="block text-sm font-semibold text-primary mb-2">
                                Konfirmasi Password <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <i class="fas fa-shield-halved absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                                <input type="password" id="pwd2" name="password_confirm" autocomplete="new-password" minlength="6" placeholder="••••••••"
                                    class="w-full pl-11 pr-11 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-200 focus:bg-surface transition-all">
                                <button type="button" onclick="togglePwd('pwd2','eye2')" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-secondary hover:text-primary transition-colors">
                                    <i id="eye2" class="fas fa-eye text-sm"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 p-3 rounded-xl bg-amber-50 border border-amber-200 text-[11px] text-amber-700 animate-fade-in">
                        <i class="fas fa-circle-info mr-1"></i>
                        <strong>Catatan Keamanan:</strong>
                        Password disimpan dalam bentuk hash bcrypt (tidak bisa dibaca admin). Sarankan user untuk ganti password setelah login pertama kali!
                    </div>
                </div>
            </div>

            <!-- FOOTER MODAL -->
            <div class="p-4 sm:p-5 border-t border-slate-200 bg-gradient-to-r from-slate-50 to-purple-50/40 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                <p class="text-[11px] font-semibold text-slate-500 flex items-center gap-1.5">
                    <i class="fas fa-info-circle text-slate-400"></i>
                    Email wajib unique & berbeda antar akun. Password min 6 karakter.
                </p>
                <div class="flex items-center justify-end gap-2">
                    <button type="button" onclick="closeUserModal()"
                            class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-sm font-bold shadow-sm transition flex items-center justify-center gap-1.5">
                        <i class="fas fa-xmark"></i> Batal
                    </button>
                    <button type="submit"
                            class="px-6 py-2.5 rounded-xl bg-gradient-to-br from-purple-600 to-indigo-700 hover:from-purple-700 hover:to-indigo-800 text-white text-sm font-bold shadow-md hover:shadow-lg hover:-translate-y-0.5 transition flex items-center justify-center gap-2 group">
                        <i id="btnSaveIcon" class="fas fa-floppy-disk group-hover:scale-110 transition"></i>
                        <span id="btnSaveText">Simpan Akun Baru</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ================================ DELETE FORM ================================ -->
<form method="POST" id="deleteUserForm" class="hidden">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="user_id" id="deleteUserId" value="0">
</form>

<script>
// ================= JS: CRUD USER MODAL FUNCTIONS =================
function openUserModal(userId) {
    userId = parseInt(userId || 0, 10);
    const modal = document.getElementById('modal-user');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';

    const title = document.getElementById('modalUserTitle');
    const icon = document.getElementById('modalUserIcon');
    const btnSaveText = document.getElementById('btnSaveText');
    const btnSaveIcon = document.getElementById('btnSaveIcon');
    const fId = document.getElementById('f_user_id');
    const fName = document.getElementById('f_name');
    const fEmail = document.getElementById('f_email');
    const fPhone = document.getElementById('f_phone');
    const fPos = document.getElementById('f_position');
    const pwdHint = document.getElementById('pwdHint');
    const lblPwd = document.getElementById('lbl_pwd');
    const lblPwd2 = document.getElementById('lbl_pwd2');
    const pwd = document.getElementById('pwd');
    const pwd2 = document.getElementById('pwd2');

    // Reset form
    document.getElementById('formUser').reset();
    document.querySelectorAll('#rolePicker input[type=radio][name=role]').forEach(r => { r.checked = (r.value === 'engineer'); });

    if (userId > 0) {
        // === EDIT MODE ===
        fId.value = String(userId);
        title.textContent = 'Edit Data Akun Pengguna';
        icon.className = 'fas fa-user-pen text-2xl sm:text-3xl';
        btnSaveText.textContent = 'Simpan Perubahan Akun';
        btnSaveIcon.className = 'fas fa-floppy-disk group-hover:scale-110 transition';
        pwdHint.textContent = 'KOSONGKAN jika tidak ingin mengubah password';
        pwdHint.className = 'ml-auto text-[10px] font-bold text-blue-600 uppercase tracking-wider';
        lblPwd.innerHTML = 'Password Baru <span class="text-[10px] text-slate-400 font-normal">(Opsional • Min 6)</span>';
        lblPwd2.innerHTML = 'Konfirmasi Password Baru <span class="text-[10px] text-slate-400 font-normal">(Opsional)</span>';
        pwd.required = false; pwd2.required = false;

        // Fill data
        const getName = document.getElementById('u_' + userId + '_name');
        if (getName) {
            fName.value = getName.value || '';
            fEmail.value = (document.getElementById('u_' + userId + '_email') || {value: ''}).value || '';
            fPhone.value = (document.getElementById('u_' + userId + '_phone') || {value: ''}).value || '';
            fPos.value = (document.getElementById('u_' + userId + '_position') || {value: ''}).value || '';
            const selRole = (document.getElementById('u_' + userId + '_role') || {value: 'engineer'}).value || 'engineer';
            document.querySelectorAll('#rolePicker input[type=radio][name=role]').forEach(r => { r.checked = (r.value === selRole); });
        }
    } else {
        // === CREATE MODE ===
        fId.value = '0';
        title.textContent = 'Tambah Akun Pengguna Baru';
        icon.className = 'fas fa-user-plus text-2xl sm:text-3xl';
        btnSaveText.textContent = 'Buat Akun Baru';
        btnSaveIcon.className = 'fas fa-user-plus group-hover:scale-110 transition';
        pwdHint.textContent = '* WAJIB diisi untuk akun BARU';
        pwdHint.className = 'ml-auto text-[10px] font-bold text-rose-600 uppercase tracking-wider';
        lblPwd.innerHTML = 'Password <span class="text-red-500">*</span> <span class="text-[10px] text-slate-400 font-normal">(min. 6 karakter)</span>';
        lblPwd2.innerHTML = 'Konfirmasi Password <span class="text-red-500">*</span>';
        pwd.required = true; pwd2.required = true;
    }

    setTimeout(() => { if (fName) fName.focus(); }, 120);
    const closeBtn = modal.querySelector('button[aria-label="Tutup modal"]');
    if (closeBtn) setTimeout(() => closeBtn.focus(), 100);
}

function closeUserModal() {
    const modal = document.getElementById('modal-user');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
}

function confirmDeleteUser(id, name, isMe) {
    if (isMe) {
        alert('❌ TIDAK BISA menghapus akun Anda SENDIRI!');
        return false;
    }
    const msg = '⚠️ ANDA YAKIN MENGHAPUS AKUN INI?\n\nNama: ' + name + '\n\n❗ Catatan: Jika user sudah punya Daily Log atau Order Request, penghapusan OTOMATIS DITOLAK oleh sistem (untuk menjaga data integrity).';
    if (confirm(msg)) {
        document.getElementById('deleteUserId').value = String(id);
        document.getElementById('deleteUserForm').submit();
    }
}

function togglePwd(inputId, iconId) {
    const i = document.getElementById(inputId);
    const ic = document.getElementById(iconId);
    if (!i || !ic) return;
    if (i.type === 'password') {
        i.type = 'text';
        ic.classList.remove('fa-eye'); ic.classList.add('fa-eye-slash');
    } else {
        i.type = 'password';
        ic.classList.remove('fa-eye-slash'); ic.classList.add('fa-eye');
    }
}

// ================= ESC CLOSE SEMUA MODAL (Prioritas: Modal User, lalu Modal CRUD Master, lalu Divisi) =================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const m1 = document.getElementById('modal-user');
        if (m1 && m1.classList.contains('flex')) { closeUserModal(); return; }
        const mm = document.getElementById('modal-master');
        if (mm && mm.classList.contains('flex')) { closeMasterModal(); return; }
        document.querySelectorAll('[id^="modal-"]').forEach(function(m) {
            if (m.classList.contains('flex')) {
                const id = m.id.replace('modal-', '');
                closeModal(id);
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
