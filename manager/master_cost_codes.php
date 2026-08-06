<?php
/**
 * Manager Data Master - COST CODE (Kode Akun Biaya Logistik)
 * Hanya bisa diakses Role Manager
 * 1 File CRUD: List Table + Modal Create/Edit + Toggle Active/Inactive
 * Style DOMINAN PUTIH + SLATE NETRAL sesuai request user
 */

require_once __DIR__ . '/../config/config.php';
requireLogin();
requireRole('manager');

$db = Database::getInstance();

// ================================================================
// AUTO MIGRASI - PERTAMA KALI BUKA HALAMAN:
// 1. Cek kolom updated_at, category di table cost_codes. JIKA BELUM ADA -> ALTER TABLE ADD
// ================================================================
try {
    $colsUpdated = $db->fetchAll("SHOW COLUMNS FROM cost_codes LIKE 'updated_at'");
    if (empty($colsUpdated)) {
        $pdo->exec("ALTER TABLE cost_codes ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }
    $colsCat = $db->fetchAll("SHOW COLUMNS FROM cost_codes LIKE 'category'");
    if (empty($colsCat)) {
        $pdo->exec("ALTER TABLE cost_codes ADD COLUMN category VARCHAR(50) NULL DEFAULT NULL AFTER name");
    }
} catch (Throwable $e) {
    // ignore migration error
}

$pageTitle = 'Master Cost Code';
$pageSubtitle = 'Data Master Kode Akun Biaya / Cost Code Logistik. Semua perubahan otomatis update di dropdown Order Request & halaman lain yang pakai Cost Code.';

// ================================================================
// POST HANDLER: 2 Action
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = cleanInput($_POST['action'] ?? '');

    // --- Action 1: SAVE (create jika id=0, update jika id>0)
    if ($action === 'save_cost_code') {
        $id = (int)($_POST['id'] ?? 0);
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        if ($category === '') $category = null;
        $isActive = (int)($_POST['is_active'] ?? 1);

        $errors = [];
        if (strlen($code) < 3) $errors[] = 'Kode Cost Code minimal 3 karakter (misal 606101)';
        if (strlen($name) < 3) $errors[] = 'Nama Cost Code minimal 3 karakter';

        // Cek code UNIQUE (kecuali id sendiri)
        $chk = ($id > 0)
            ? $db->fetchOne("SELECT id FROM cost_codes WHERE code=? AND id<>? LIMIT 1", [$code, $id])
            : $db->fetchOne("SELECT id FROM cost_codes WHERE code=? LIMIT 1", [$code]);
        if ($chk) $errors[] = "Kode Cost Code <strong>{$code}</strong> SUDAH ADA. Kode harus unik per data.";

        if (!empty($errors)) {
            setFlash('danger', implode('<br>', $errors));
            redirect('manager/master_cost_codes.php');
        }

        if ($id === 0) {
            // CREATE
            $db->query(
                "INSERT INTO cost_codes (code, name, category, is_active, created_at) VALUES (?,?,?,?, NOW())",
                [$code, $name, $category, $isActive]
            );
            setFlash('success', "✅ Cost Code BARU <strong>{$code} - {$name}</strong> BERHASIL ditambahkan. Dropdown di Order Request otomatis update.");
        } else {
            // UPDATE
            $db->query(
                "UPDATE cost_codes SET code=?, name=?, category=?, is_active=? WHERE id=? LIMIT 1",
                [$code, $name, $category, $isActive, $id]
            );
            setFlash('success', "✅ Cost Code <strong>{$code} - {$name}</strong> BERHASIL di-update.");
        }
        redirect('manager/master_cost_codes.php');
    }

    // --- Action 2: TOGGLE ACTIVE (soft delete = set is_active 0, data tetap ada agar order lama tidak rusak)
    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === 0) {
            setFlash('danger', 'ID Cost Code tidak valid.');
            redirect('manager/master_cost_codes.php');
        }
        $current = $db->fetchOne("SELECT is_active, code, name FROM cost_codes WHERE id=? LIMIT 1", [$id]);
        if (!$current) {
            setFlash('danger', 'Cost Code tidak ditemukan di database.');
            redirect('manager/master_cost_codes.php');
        }
        $nextActive = ((int)$current['is_active'] === 1) ? 0 : 1;
        $label = ($nextActive === 1) ? '✅ DI-AKTIFKAN' : '⛔ DI-NONAKTIFKAN';
        $db->query("UPDATE cost_codes SET is_active=? WHERE id=? LIMIT 1", [$nextActive, $id]);
        $warn = ($nextActive === 0) ? " <br>⚠️ Data Cost Code ini HILANG dari dropdown Order Request, tapi data Order LAMA yang pakai kode ini TETAP AMAN (tidak terhapus)." : " <br>📌 Cost Code ini MUNCUL kembali di dropdown Order Request semua halaman.";
        setFlash(($nextActive === 1 ? 'success' : 'warning'), "Cost Code <strong>{$current['code']} - {$current['name']}</strong> <strong>{$label}</strong>.{$warn}");
        redirect('manager/master_cost_codes.php');
    }
}

// ================================================================
// QUERY DATA UNTUK DITAMPILKAN
// ================================================================
$search = cleanInput($_GET['search'] ?? '');
$filterStatus = cleanInput($_GET['status'] ?? 'all');

$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = "(code LIKE ? OR name LIKE ? OR IFNULL(category,'') LIKE ?)";
    $kw = "%{$search}%";
    $params[] = $kw; $params[] = $kw; $params[] = $kw;
}
if ($filterStatus === 'active')   { $where[] = "is_active=1"; }
if ($filterStatus === 'inactive') { $where[] = "is_active=0"; }

$whereSql = implode(' AND ', $where);

// Statistic
$statTotal  = (int)$db->fetchOne("SELECT COUNT(*) FROM cost_codes")['COUNT(*)'];
$statActive = (int)$db->fetchOne("SELECT COUNT(*) FROM cost_codes WHERE is_active=1")['COUNT(*)'];
$statNon    = max(0, $statTotal - $statActive);

// Hitung total order terpakai per cost code
$list = $db->fetchAll("
    SELECT cc.*,
       (SELECT COUNT(*) FROM orders o WHERE o.cost_code_id = cc.id) AS usage_count
    FROM cost_codes cc
    WHERE {$whereSql}
    ORDER BY cc.is_active DESC, cc.code ASC
", $params);

// Helper Badge Status Active
$fnActiveBadge = function ($active) {
    if ((int)$active === 1) {
        return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-white border-2 border-emerald-200 text-emerald-700 text-[11px] font-black uppercase tracking-wider"><i class="fas fa-circle-check text-emerald-600"></i> AKTIF</span>';
    }
    return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-white border-2 border-slate-200 text-slate-500 text-[11px] font-black uppercase tracking-wider"><i class="fas fa-circle-xmark text-slate-400"></i> NONAKTIF</span>';
};

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content px-4 sm:px-6 lg:px-8 py-6 max-w-[1500px] mx-auto">
    <!-- HEADER JUDUL -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 animate-slide-up">
        <div>
            <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-500 mb-1 flex items-center gap-1.5"><i class="fas fa-database"></i> Data Master Logistik</p>
            <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">🔧 Master Cost Code (Kode Akun Biaya)</h1>
            <p class="text-secondary text-sm">Total <strong class="text-primary"><?= $statTotal ?> kode</strong> terdaftar. Tambah / edit / nonaktifkan disini, <strong>semua dropdown Cost Code di Order Request &amp; halaman lain OTOMATIS UPDATE</strong> (jika is_active=1).</p>
        </div>
        <button type="button" onclick="openCcModal(0)"
                class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-card bg-slate-900 hover:bg-slate-800 text-white font-semibold shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300 self-start sm:self-end">
            <i class="fas fa-plus text-lg"></i>
            + Tambah Cost Code Baru
        </button>
    </div>

    <!-- STATISTIC 3 CARDS (DOMINAN PUTIH!) -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-[0.15em] text-slate-500">Total Kode</span>
                <span class="w-9 h-9 rounded-xl bg-white border border-slate-200 flex items-center justify-center text-slate-500"><i class="fas fa-barcode"></i></span>
            </div>
            <p class="font-display text-3xl font-black text-primary leading-none"><?= $statTotal ?></p>
            <p class="text-[11px] text-slate-500 mt-1">Semua cost code pernah diinput</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-[0.15em] text-slate-500">Aktif (Muncul di Dropdown)</span>
                <span class="w-9 h-9 rounded-xl bg-white border border-emerald-200 flex items-center justify-center text-emerald-600"><i class="fas fa-circle-check"></i></span>
            </div>
            <p class="font-display text-3xl font-black text-primary leading-none"><?= $statActive ?></p>
            <p class="text-[11px] text-slate-500 mt-1">Bisa dipilih di Order Request</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-[0.15em] text-slate-500">Nonaktif (Sembunyikan)</span>
                <span class="w-9 h-9 rounded-xl bg-white border border-slate-200 flex items-center justify-center text-slate-500"><i class="fas fa-eye-slash"></i></span>
            </div>
            <p class="font-display text-3xl font-black text-primary leading-none"><?= $statNon ?></p>
            <p class="text-[11px] text-slate-500 mt-1">Hilang dari dropdown, data lama tetap aman</p>
        </div>
    </div>

    <!-- FILTER & SEARCH CARD -->
    <div class="bg-white rounded-premium border border-slate-200 shadow-sm mb-6 animate-slide-up" style="animation-delay: 80ms">
        <form method="GET" class="flex flex-col md:flex-row gap-3 p-4 sm:p-5 border-b border-slate-200">
            <div class="md:w-52">
                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Filter Status</label>
                <select name="status" onchange="this.form.submit()" class="w-full px-3 py-3 rounded-card border border-border bg-muted/50 text-primary text-sm font-bold focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200 focus:bg-surface transition-all appearance-none pr-8">
                    <option value="all"      <?= $filterStatus === 'all' ? 'selected' : '' ?>>Semua Status</option>
                    <option value="active"   <?= $filterStatus === 'active' ? 'selected' : '' ?>>✅ AKTIF (muncul dropdown)</option>
                    <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>⛔ NONAKTIF (disembunyikan)</option>
                </select>
            </div>
            <div class="flex-1">
                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Pencarian</label>
                <div class="relative">
                    <i class="fas fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                    <input type="search" name="search" value="<?= cleanInput($search) ?>"
                           placeholder="Cari kode 606101 / nama Printing / kategori..."
                           class="w-full pl-11 pr-28 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200 focus:bg-surface transition-all text-sm">
                    <button type="submit" class="absolute right-1.5 top-1/2 -translate-y-1/2 px-3.5 py-2 rounded-lg bg-slate-900 text-white text-xs font-bold hover:bg-slate-800 transition shadow-sm">
                        <i class="fas fa-search"></i> Cari
                    </button>
                </div>
            </div>
            <?php if ($search !== '' || $filterStatus !== 'all'): ?>
                <div class="md:self-end md:pb-0.5">
                    <a href="?" class="inline-flex items-center gap-1.5 px-4 py-3 rounded-card bg-white text-slate-700 text-xs font-bold border border-slate-200 hover:bg-slate-50 transition shadow-sm"><i class="fas fa-rotate-left"></i> Reset</a>
                </div>
            <?php endif; ?>
        </form>

        <!-- TABLE LIST -->
        <div class="overflow-x-auto -mx-4 sm:mx-0 px-4 sm:px-0">
            <table class="w-full text-sm min-w-[900px]">
                <thead class="bg-slate-50 border-b-2 border-slate-200">
                    <tr class="text-left text-secondary">
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap w-12">#</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap">Kode Cost Code</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold">Nama Biaya</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap">Kategori</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold text-center whitespace-nowrap">Terpakai di Order</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold whitespace-nowrap">Status</th>
                        <th class="px-4 sm:px-5 py-3.5 font-semibold text-right whitespace-nowrap">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php if (!empty($list)): $no = 1; ?>
                    <?php foreach ($list as $cc):
                        $ccid = (int)$cc['id'];
                        $code = cleanInput($cc['code']);
                        $name = cleanInput($cc['name']);
                        $cat  = cleanInput($cc['category'] ?? '');
                        $useCount = (int)($cc['usage_count'] ?? 0);
                        $active = (int)$cc['is_active'];
                    ?>
                    <tr class="hover:bg-slate-50 transition-colors group">
                        <td class="px-4 sm:px-5 py-4 align-top text-xs font-bold text-slate-500"><?= $no++ ?>.</td>
                        <td class="px-4 sm:px-5 py-4 align-top">
                            <input type="hidden" id="cc_<?= $ccid ?>_code"  value="<?= htmlspecialchars((string)$cc['code'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" id="cc_<?= $ccid ?>_name"  value="<?= htmlspecialchars((string)$cc['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" id="cc_<?= $ccid ?>_cat"   value="<?= htmlspecialchars((string)($cc['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" id="cc_<?= $ccid ?>_act"   value="<?= (int)$cc['is_active'] ?>">
                            <span class="inline-flex items-center px-3 py-1.5 rounded-xl bg-white border-2 border-slate-200 text-slate-800 text-sm font-black tracking-wide font-mono shadow-sm">
                                <i class="fas fa-barcode mr-2 text-slate-400 text-xs"></i><?= $code ?>
                            </span>
                        </td>
                        <td class="px-4 sm:px-5 py-4 align-top">
                            <p class="font-semibold text-primary leading-tight"><?= $name ?></p>
                            <p class="text-[11px] text-slate-500 mt-0.5">Dibuat: <?= $cc['created_at'] ? date('d M Y', strtotime($cc['created_at'])) : '-' ?></p>
                        </td>
                        <td class="px-4 sm:px-5 py-4 align-top">
                            <?php if ($cat !== ''): ?>
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-100 border border-slate-200 text-slate-700 text-[11px] font-bold"><i class="fas fa-tag mr-1 text-slate-400"></i><?= $cat ?></span>
                            <?php else: ?>
                                <span class="text-[11px] text-slate-400 italic">tidak ada kategori</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 sm:px-5 py-4 text-center align-top">
                            <span class="inline-flex items-center justify-center px-3 py-1 rounded-full bg-slate-100 border border-slate-200 text-slate-700 font-bold min-w-[52px]">
                                <?= $useCount ?>
                                <i class="fas fa-file-invoice ml-1.5 text-[10px] text-slate-400"></i>
                            </span>
                        </td>
                        <td class="px-4 sm:px-5 py-4 align-top"><?= $fnActiveBadge($active) ?></td>
                        <td class="px-4 sm:px-5 py-4 pr-5 align-top">
                            <div class="flex gap-2 justify-end flex-wrap items-center">
                                <button type="button" onclick="openCcModal(<?= $ccid ?>)"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200 hover:bg-slate-200 hover:-translate-y-0.5 transition-all">
                                    <i class="fas fa-pencil"></i> Edit
                                </button>
                                <form method="POST" onsubmit="return confirmToggle(<?= $ccid ?>, <?= json_encode($code . ' - ' . $name, JSON_UNESCAPED_UNICODE) ?>, <?= $active ?>)">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= $ccid ?>">
                                    <button type="submit"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-white text-slate-600 border border-slate-200 hover:-translate-y-0.5 transition-all <?= $active ? 'hover:bg-amber-50 hover:text-amber-700 hover:border-amber-200' : 'hover:bg-emerald-50 hover:text-emerald-700 hover:border-emerald-200' ?>">
                                        <i class="fas <?= $active ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                        <?= $active ? 'Nonaktifkan' : 'Aktifkan' ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                <tr><td colspan="7" class="px-4 sm:px-5 py-20 text-center text-secondary">
                    <div class="w-20 h-20 mx-auto mb-4 rounded-3xl bg-white border-2 border-slate-200 flex items-center justify-center text-slate-400">
                        <i class="fas fa-barcode text-3xl"></i>
                    </div>
                    <h3 class="font-black text-xl text-slate-700 mb-2">
                        <?= $search || $filterStatus !== 'all' ? 'Tidak ada Cost Code yang cocok' : 'Belum ada data Cost Code' ?>
                    </h3>
                    <p class="text-sm text-slate-500 mb-5 max-w-md mx-auto">
                        <?= $search || $filterStatus !== 'all' ? 'Coba ubah kata kunci pencarian atau reset filter Status.' : 'Klik tombol di pojok kanan atas untuk menambahkan Cost Code pertama.' ?>
                    </p>
                    <?php if ($search || $filterStatus !== 'all'): ?>
                        <a href="?" class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-card bg-white text-slate-700 text-xs font-bold border border-slate-200 hover:bg-slate-50 transition shadow-sm">
                            <i class="fas fa-rotate-left"></i> Reset Filter
                        </a>
                    <?php else: ?>
                        <button type="button" onclick="openCcModal(0)" class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-card bg-slate-900 text-white text-xs font-bold shadow-md hover:bg-slate-800 hover:shadow-lg transition">
                            <i class="fas fa-plus"></i> Tambah Cost Code Pertama
                        </button>
                    <?php endif; ?>
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- FOOTER INFO CARD -->
        <div class="p-4 sm:p-5 border-t border-slate-200 bg-slate-50">
            <p class="text-[11px] text-slate-500 flex items-start gap-2">
                <i class="fas fa-circle-info text-slate-400 mt-0.5"></i>
                <span>
                    <strong class="text-slate-700">Catatan Penting Data Integrity:</strong>
                    Cost Code <strong>TIDAK BISA di-HAPUS (hard delete)</strong> karena terhubung ke ratusan data Order Request lama. Cukup klik tombol <strong>⛔ Nonaktifkan</strong> jika kode sudah tidak dipakai → otomatis hilang dari semua dropdown Cost Code di sistem, tapi Order Lama yang pakai kode tersebut TETAP AMAN dan TIDAK RUSAK. Jika suatu saat butuh lagi, tinggal klik <strong>✅ Aktifkan</strong> kembali.
                </span>
            </p>
        </div>
    </div>
</div>

<!-- ============================================== MODAL CRUD COST CODE ============================================== -->
<div id="modal-cc" class="fixed inset-0 z-[99999] hidden items-center justify-center p-4 animate-fade-in-modal"
     onclick="if(event.target===this)closeCcModal()">
    <div class="absolute inset-0 bg-slate-900/65 backdrop-blur-md"></div>
    <div class="relative w-full max-w-2xl max-h-[93vh] bg-white rounded-3xl shadow-[0_30px_100px_-20px_rgba(30,41,59,0.45)] overflow-hidden flex flex-col animate-slide-up-modal border-2 border-slate-200">
        <!-- HEADER MODAL -->
        <div class="bg-white p-5 sm:p-6 text-primary relative border-b-2 border-slate-200">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-slate-900 text-white flex items-center justify-center shadow-lg shrink-0">
                        <i id="ccModalIcon" class="fas fa-barcode text-2xl sm:text-3xl"></i>
                    </div>
                    <div>
                        <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.25em] text-slate-500 mb-1.5">Form Data Master</p>
                        <h4 id="ccModalTitle" class="font-display text-2xl sm:text-3xl font-black tracking-wide leading-tight text-primary">Tambah Cost Code Baru</h4>
                        <div class="flex items-center gap-3 mt-2">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-slate-100 border border-slate-200 text-slate-600 text-xs font-bold">
                                <i class="fas fa-database text-slate-500"></i> Tabel: cost_codes
                            </span>
                        </div>
                    </div>
                </div>
                <button type="button"
                        onclick="closeCcModal()"
                        class="shrink-0 w-11 h-11 rounded-2xl bg-slate-100 hover:bg-rose-500 text-slate-600 hover:text-white border border-slate-200 hover:border-rose-500 flex items-center justify-center transition-all duration-200 hover:scale-110 active:scale-95 shadow-md"
                        aria-label="Tutup modal">
                    <i class="fas fa-xmark text-xl font-black"></i>
                </button>
            </div>
        </div>

        <form method="POST" id="formCc" class="flex-1 overflow-y-auto">
            <input type="hidden" name="action" value="save_cost_code">
            <input type="hidden" name="id" id="cc_id" value="0">

            <div class="p-5 sm:p-6 space-y-5 bg-white">
                <!-- KODE + NAMA -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-1">
                        <label class="block text-sm font-semibold text-primary mb-2">
                            <i class="fas fa-barcode mr-1.5 text-slate-500"></i>Kode Cost Code <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <i class="fas fa-hashtag absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                            <input type="text" name="code" id="cc_code" required autocomplete="off" maxlength="20"
                                   placeholder="606101"
                                   oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')"
                                   class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200 focus:bg-surface transition-all text-sm font-mono font-bold tracking-wide">
                        </div>
                        <p class="text-[10px] text-slate-500 mt-1">Contoh: <strong>606101, 610108, 690001</strong>. Huruf &amp; angka saja, unik tidak boleh sama.</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-primary mb-2">
                            <i class="fas fa-signature mr-1.5 text-slate-500"></i>Nama Cost Code <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <i class="fas fa-file-lines absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                            <input type="text" name="name" id="cc_name" required autocomplete="off" maxlength="150"
                                   placeholder="Printing and Stationery"
                                   class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200 focus:bg-surface transition-all text-sm font-semibold">
                        </div>
                    </div>
                </div>

                <!-- KATEGORI + STATUS -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-primary mb-2">
                            <i class="fas fa-tags mr-1.5 text-slate-500"></i>Kategori (Opsional)
                        </label>
                        <div class="relative">
                            <i class="fas fa-tag absolute left-3.5 top-1/2 -translate-y-1/2 text-secondary"></i>
                            <input type="text" name="category" id="cc_category" autocomplete="off" maxlength="50"
                                   placeholder="Supply, Maintenance, Contract, Logistic, Energy, ..."
                                   class="w-full pl-11 pr-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200 focus:bg-surface transition-all text-sm">
                        </div>
                        <p class="text-[10px] text-slate-500 mt-1">Kategori opsional, untuk mempermudah filter nanti (bisa dikosongkan).</p>
                    </div>
                    <div class="md:col-span-1">
                        <label class="block text-sm font-semibold text-primary mb-2">
                            <i class="fas fa-toggle-on mr-1.5 text-slate-500"></i>Status Aktif
                        </label>
                        <label class="flex items-center gap-3 p-3.5 rounded-xl border-2 border-slate-200 bg-white cursor-pointer hover:bg-slate-50 transition peer-has-[:checked]:border-slate-900">
                            <input type="checkbox" name="is_active" id="cc_active" value="1" checked class="w-5 h-5 rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                            <div>
                                <p class="font-bold text-sm text-primary leading-tight">Aktifkan di Dropdown</p>
                                <p class="text-[10px] text-slate-500 leading-tight mt-0.5">Uncheck = sembunyikan dari semua dropdown</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- WARNING BOX -->
                <div class="mt-3 p-3 rounded-xl bg-slate-50 border border-slate-200 text-[11px] text-slate-600 animate-fade-in">
                    <i class="fas fa-circle-info mr-1"></i>
                    <strong>Pastikan Kode + Nama BENAR!</strong> Setelah disimpan &amp; terpakai di Order, tidak bisa dihapus permanen (hanya bisa Nonaktifkan) agar data laporan lama tetap konsisten.
                </div>
            </div>

            <!-- FOOTER MODAL -->
            <div class="p-4 sm:p-5 border-t border-slate-200 bg-slate-50 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                <p class="text-[11px] font-semibold text-slate-500 flex items-center gap-1.5">
                    <i class="fas fa-info-circle text-slate-400"></i>
                    Kode Cost Code wajib UNIQUE (tidak boleh sama antar data).
                </p>
                <div class="flex items-center justify-end gap-2">
                    <button type="button" onclick="closeCcModal()"
                            class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-100 text-slate-700 text-sm font-bold shadow-sm transition flex items-center justify-center gap-1.5">
                        <i class="fas fa-xmark"></i> Batal
                    </button>
                    <button type="submit"
                            class="px-6 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-sm font-bold shadow-md hover:shadow-lg hover:-translate-y-0.5 transition flex items-center justify-center gap-2 group">
                        <i id="ccBtnSaveIcon" class="fas fa-floppy-disk group-hover:scale-110 transition"></i>
                        <span id="ccBtnSaveText">Simpan Cost Code Baru</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================== COST CODE MODAL JS ==============================================
function openCcModal(id) {
    const modal = document.getElementById('modal-cc');
    const title = document.getElementById('ccModalTitle');
    const icon  = document.getElementById('ccModalIcon');
    const btnSaveText = document.getElementById('ccBtnSaveText');
    const btnSaveIcon = document.getElementById('ccBtnSaveIcon');
    const fId   = document.getElementById('cc_id');
    const fCode = document.getElementById('cc_code');
    const fName = document.getElementById('cc_name');
    const fCat  = document.getElementById('cc_category');
    const fAct  = document.getElementById('cc_active');

    if (id === 0) {
        title.textContent = 'Tambah Cost Code Baru';
        icon.className = 'fas fa-plus text-2xl sm:text-3xl';
        btnSaveText.textContent = 'Simpan Cost Code Baru';
        btnSaveIcon.className = 'fas fa-circle-plus group-hover:scale-110 transition';
        fId.value = '0';
        fCode.value = '';
        fName.value = '';
        fCat.value = '';
        fAct.checked = true;
    } else {
        title.textContent = 'Edit Cost Code';
        icon.className = 'fas fa-pencil text-2xl sm:text-3xl';
        btnSaveText.textContent = 'Update Cost Code';
        btnSaveIcon.className = 'fas fa-pen-to-square group-hover:scale-110 transition';
        fId.value = String(id);
        fCode.value = document.getElementById('cc_'+id+'_code').value;
        fName.value = document.getElementById('cc_'+id+'_name').value;
        fCat.value  = document.getElementById('cc_'+id+'_cat').value;
        fAct.checked = (document.getElementById('cc_'+id+'_act').value === '1');
    }

    document.body.style.overflow = 'hidden';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => fCode.focus(), 80);
}

function closeCcModal() {
    const modal = document.getElementById('modal-cc');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
    try { document.getElementById('formCc').reset(); } catch(e){}
}

function confirmToggle(id, label, activeNow) {
    const will = (activeNow === 1) ? 'MENONAKTIFKAN' : 'MENGAKTIFKAN KEMBALI';
    const warn = (activeNow === 1)
        ? 'Cost Code ini akan HILANG dari semua dropdown Order Request. Order lama TETAP AMAN (tidak dihapus).'
        : 'Cost Code ini MUNCUL kembali di semua dropdown Order Request.';
    return confirm(`[${will}] Cost Code: ${label}\n\n${warn}\n\nLanjutkan?`);
}

// ============== ESC KEY (PRIORITAS TUTUP: User Modal > Master Activity Modal > Cost Code Modal) ==============
document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') {
        const mu = document.getElementById('modal-user');
        const mm = document.getElementById('masterModal');
        const mc = document.getElementById('modal-cc');
        if (mu && !mu.classList.contains('hidden'))      { try { closeUserModal(); } catch(e){} }
        else if (mm && !mm.classList.contains('hidden')) { try { closeMasterModal(); } catch(e){} }
        else if (mc && !mc.classList.contains('hidden')) { try { closeCcModal(); } catch(e){} }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>