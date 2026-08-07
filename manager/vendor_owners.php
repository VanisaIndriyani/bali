<?php
/**
 * Manager Data Master: OWNER / VENDOR / INVESTOR
 * Hanya akses Role Manager
 * Data investor / pemilik modal / vendor kerjasama
 * Style dominan putih netral
 */

require_once __DIR__ . '/../config/config.php';
requireLogin();
requireRole('manager');

$db = Database::getInstance();

try {
    $tbl = "CREATE TABLE IF NOT EXISTS transport_vendors (
      id INT PRIMARY KEY AUTO_INCREMENT,
      type ENUM('owner','vendor','investor') NOT NULL DEFAULT 'vendor',
      name VARCHAR(180) NOT NULL,
      pic_name VARCHAR(120) NULL,
      phone VARCHAR(40) NULL,
      email VARCHAR(150) NULL,
      bank_name VARCHAR(80) NULL,
      bank_account VARCHAR(80) NULL,
      bank_holder VARCHAR(150) NULL,
      share_pct DECIMAL(6,2) NULL DEFAULT 0,
      address TEXT NULL,
      is_active TINYINT(1) DEFAULT 1,
      notes TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$db->query($tbl);
} catch (Throwable $_) {}

$pageTitle = 'Data Owner / Vendor / Investor';
$pageSubtitle = 'Kelola data pemilik modal (investor), vendor kerjasama, dan owner aset kendaraan. Data ini dipakai di Job Daily & Laporan Keuangan Transport.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = cleanInput($_POST['action'] ?? '');
    if ($action === 'save_vendor') {
        $id    = (int)($_POST['id'] ?? 0);
        $type  = cleanInput($_POST['type'] ?? 'vendor');
        if (!in_array($type, ['owner','vendor','investor'], true)) $type = 'vendor';
        $name     = trim((string)($_POST['name'] ?? ''));
        $picName  = trim((string)($_POST['pic_name'] ?? ''));
        $phone    = trim((string)($_POST['phone'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $bank     = trim((string)($_POST['bank_name'] ?? ''));
        $bankAcc  = trim((string)($_POST['bank_account'] ?? ''));
        $bankHold = trim((string)($_POST['bank_holder'] ?? ''));
        $share    = (float)($_POST['share_pct'] ?? 0);
        $address  = trim((string)($_POST['address'] ?? ''));
        $notes    = trim((string)($_POST['notes'] ?? ''));
        $isActive = (int)($_POST['is_active'] ?? 1);
        if (strlen($name) < 3) { setFlash('danger','Nama minimal 3 karakter.'); redirect('manager/vendor_owners.php'); }
        if ($id === 0) {
            $db->query("INSERT INTO transport_vendors (type,name,pic_name,phone,email,bank_name,bank_account,bank_holder,share_pct,address,notes,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [$type,$name,$picName,$phone,$email,$bank,$bankAcc,$bankHold,$share,$address,$notes,$isActive]);
            setFlash('success',"✅ Data BARU <strong>{$name}</strong> berhasil ditambahkan.");
        } else {
            $db->query("UPDATE transport_vendors SET type=?,name=?,pic_name=?,phone=?,email=?,bank_name=?,bank_account=?,bank_holder=?,share_pct=?,address=?,notes=?,is_active=? WHERE id=? LIMIT 1",
                [$type,$name,$picName,$phone,$email,$bank,$bankAcc,$bankHold,$share,$address,$notes,$isActive,$id]);
            setFlash('success',"✅ Data <strong>{$name}</strong> berhasil di-update.");
        }
        redirect('manager/vendor_owners.php');
    }
    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $db->fetchOne("SELECT id,name,is_active FROM transport_vendors WHERE id=? LIMIT 1",[$id]);
        if (!$row) { setFlash('danger','Data tidak ditemukan.'); redirect('manager/vendor_owners.php'); }
        $next = ((int)$row['is_active'] === 1) ? 0 : 1;
        $db->query("UPDATE transport_vendors SET is_active=? WHERE id=? LIMIT 1",[$next,$id]);
        setFlash($next ? 'success' : 'warning', "Data <strong>{$row['name']}</strong> " . ($next ? '✅ AKTIF' : '⛔ NON-AKTIF'));
        redirect('manager/vendor_owners.php');
    }
}

$search = cleanInput($_GET['search'] ?? '');
$filterType = cleanInput($_GET['type'] ?? 'all');
$where = ['1=1']; $params = [];
if ($search !== '') { $where[] = "(name LIKE ? OR pic_name LIKE ? OR phone LIKE ? OR email LIKE ?)"; $kw = "%{$search}%"; for($i=0;$i<4;$i++) $params[] = $kw; }
if ($filterType !== 'all') { $where[] = "type=?"; $params[] = $filterType; }
$whereSql = implode(' AND ', $where);
$rows = $db->fetchAll("SELECT * FROM transport_vendors WHERE {$whereSql} ORDER BY is_active DESC, name ASC", $params);
$statTotal   = (int)$db->fetchOne("SELECT COUNT(*) FROM transport_vendors")['COUNT(*)'];
$statActive  = (int)$db->fetchOne("SELECT COUNT(*) FROM transport_vendors WHERE is_active=1")['COUNT(*)'];
$statOwner   = (int)$db->fetchOne("SELECT COUNT(*) FROM transport_vendors WHERE type='owner'")['COUNT(*)'];
$statVendor  = (int)$db->fetchOne("SELECT COUNT(*) FROM transport_vendors WHERE type='vendor'")['COUNT(*)'];
$statInv     = max(0, $statTotal - $statOwner - $statVendor);

$_GET['_inline_header'] = 1;
$pageHeaderActive = 'vendor_owners';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="flex-1 min-w-0">
<div class="p-5 lg:p-8">

<div class="mb-6">
    <div class="text-[11px] font-bold text-slate-500 uppercase tracking-[0.22em] mb-2 flex items-center gap-2">
        <a href="<?= BASE_URL ?>index.php" class="hover:text-slate-900">Dashboard</a>
        <i class="fas fa-chevron-right text-[9px] text-slate-400"></i>
        Manager Area
        <i class="fas fa-chevron-right text-[9px] text-slate-400"></i>
        <span class="text-slate-800">Data Owner / Vendor / Investor</span>
    </div>
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <span class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-lg shadow-md shadow-indigo-500/25"><i class="fas fa-building-columns"></i></span>
                <div>
                    <h1 class="font-display text-2xl lg:text-3xl font-black text-slate-900 leading-tight">Data Owner / Vendor / Investor</h1>
                    <p class="text-sm text-slate-500 mt-0.5"><?= $pageSubtitle ?></p>
                </div>
            </div>
        </div>
        <button type="button" onclick="openVendor(0)" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-sm font-bold shadow-sm transition shadow-slate-900/10">
            <i class="fas fa-plus-circle"></i> Tambah Data Owner / Vendor
        </button>
    </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center text-slate-700"><i class="fas fa-users"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Total Data</div></div>
        <div class="text-3xl font-black text-slate-900 leading-none"><?= $statTotal ?></div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-700"><i class="fas fa-check-circle"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Aktif</div></div>
        <div class="text-3xl font-black text-emerald-700 leading-none"><?= $statActive ?></div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-sky-100 flex items-center justify-center text-sky-700"><i class="fas fa-car"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Owner Aset</div></div>
        <div class="text-3xl font-black text-sky-700 leading-none"><?= $statOwner ?></div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center text-amber-700"><i class="fas fa-handshake"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Vendor Kerjasama</div></div>
        <div class="text-3xl font-black text-amber-700 leading-none"><?= $statVendor ?></div>
    </div>
</div>

<div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
    <div class="flex flex-wrap items-center gap-3 p-4 lg:p-5 border-b border-slate-100 bg-slate-50/40">
        <div class="flex-1 min-w-[200px]">
            <form method="get" id="fSearch" class="flex items-center gap-2">
                <div class="relative flex-1">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama, PIC, No. HP, email..." class="w-full pl-10 pr-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition">
                </div>
                <select name="type" class="px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-indigo-300 outline-none">
                    <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>Semua Tipe</option>
                    <option value="owner"    <?= $filterType === 'owner'    ? 'selected' : '' ?>>Owner Aset</option>
                    <option value="vendor"   <?= $filterType === 'vendor'   ? 'selected' : '' ?>>Vendor</option>
                    <option value="investor" <?= $filterType === 'investor' ? 'selected' : '' ?>>Investor</option>
                </select>
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-bold hover:bg-slate-800 transition">Filter</button>
                <a href="vendor_owners.php" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-bold hover:bg-slate-50 transition">Reset</a>
            </form>
        </div>
        <div class="ml-auto flex items-center gap-2">
            <button type="button" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-slate-300 bg-white text-slate-700 text-sm font-bold hover:bg-slate-50 transition">
                <i class="fas fa-file-excel text-emerald-600"></i> Export Excel
            </button>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gradient-to-r from-slate-100 to-slate-50 text-slate-700 text-[11px] uppercase tracking-[0.12em] font-black">
                    <th class="px-5 py-3 text-left font-black">Tipe</th>
                    <th class="px-5 py-3 text-left font-black">Nama / Perusahaan</th>
                    <th class="px-5 py-3 text-left font-black">PIC & Kontak</th>
                    <th class="px-5 py-3 text-left font-black">Bank</th>
                    <th class="px-5 py-3 text-center font-black">Share %</th>
                    <th class="px-5 py-3 text-center font-black">Status</th>
                    <th class="px-5 py-3 text-center font-black w-32">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if (count($rows) === 0): ?>
                <tr><td colspan="7" class="px-5 py-12 text-center">
                    <div class="mx-auto w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-3"><i class="fas fa-folder-open text-slate-400 text-2xl"></i></div>
                    <p class="text-slate-600 font-semibold">Belum ada data Owner / Vendor.</p>
                    <button type="button" onclick="openVendor(0)" class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold">
                        <i class="fas fa-plus-circle"></i> Tambah Data Pertama
                    </button>
                </td></tr>
            <?php else: foreach ($rows as $r):
                $_t = (string)($r['type'] ?? 'vendor');
                if ($_t === 'owner') {
                    $typeBadge = ['bg-sky-50 border-sky-200 text-sky-700', 'Owner', 'fa-car'];
                } elseif ($_t === 'vendor') {
                    $typeBadge = ['bg-amber-50 border-amber-200 text-amber-700', 'Vendor', 'fa-handshake'];
                } elseif ($_t === 'investor') {
                    $typeBadge = ['bg-violet-50 border-violet-200 text-violet-700', 'Investor', 'fa-coins'];
                } else {
                    $typeBadge = ['bg-slate-50 border-slate-200 text-slate-600', 'Lainnya', 'fa-user-tag'];
                }
            ?>
                <tr class="hover:bg-slate-50/60 transition">
                    <td class="px-5 py-3.5">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border font-bold text-[11px] <?= $typeBadge[0] ?>"><i class="fas <?= $typeBadge[2] ?> text-[10px]"></i> <?= $typeBadge[1] ?></span>
                    </td>
                    <td class="px-5 py-3.5">
                        <p class="font-bold text-slate-900 text-base leading-tight"><?= cleanInput($r['name']) ?></p>
                        <?php if (strlen($r['address'] ?? '') > 0): ?>
                            <p class="text-[11px] text-slate-500 mt-0.5 line-clamp-1"><i class="fas fa-location-dot mr-0.5 text-slate-400"></i><?= cleanInput($r['address']) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-sm text-slate-700">
                        <?php if (strlen($r['pic_name'] ?? '') > 0): ?><p class="font-bold text-slate-800 text-[13px]"><?= cleanInput($r['pic_name']) ?></p><?php endif; ?>
                        <?php if (strlen($r['phone'] ?? '') > 0): ?><p class="text-[12px] text-slate-600"><i class="fas fa-phone mr-1 text-slate-400"></i><?= cleanInput($r['phone']) ?></p><?php endif; ?>
                        <?php if (strlen($r['email'] ?? '') > 0): ?><p class="text-[12px] text-slate-500"><i class="fas fa-envelope mr-1 text-slate-400"></i><?= cleanInput($r['email']) ?></p><?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-sm text-slate-700">
                        <?php if (strlen($r['bank_name'] ?? '') > 0): ?>
                            <p class="font-bold text-slate-800 text-[13px]"><?= cleanInput($r['bank_name']) ?></p>
                            <?php if (strlen($r['bank_account'] ?? '') > 0): ?><p class="text-[12px] text-slate-600 font-mono">No. Rek: <?= cleanInput($r['bank_account']) ?></p><?php endif; ?>
                            <?php if (strlen($r['bank_holder'] ?? '') > 0): ?><p class="text-[11px] text-slate-500">a.n. <?= cleanInput($r['bank_holder']) ?></p><?php endif; ?>
                        <?php else: ?>
                            <span class="text-xs text-slate-400 font-semibold">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-center">
                        <?php if ((float)$r['share_pct'] > 0): ?>
                            <span class="inline-flex items-center justify-center min-w-[54px] px-2.5 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-black"><?= (float)$r['share_pct'] ?>%</span>
                        <?php else: ?>
                            <span class="text-xs text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-center">
                        <form method="post" onsubmit="return confirm('Ubah status aktif data ini?')">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border text-[11px] font-bold transition <?= (int)$r['is_active'] ? 'bg-emerald-50 border-emerald-200 text-emerald-700 hover:bg-emerald-100' : 'bg-slate-100 border-slate-200 text-slate-600 hover:bg-slate-200' ?>">
                                <i class="fas fa-power-off text-[10px]"></i>
                                <?= (int)$r['is_active'] ? 'Aktif' : 'Non Aktif' ?>
                            </button>
                        </form>
                    </td>
                    <td class="px-5 py-3.5">
                        <div class="flex items-center justify-center gap-1.5">
                            <button type="button" onclick='openVendor(<?= json_encode($r) ?>)' class="w-9 h-9 rounded-lg border border-slate-300 hover:bg-indigo-50 hover:border-indigo-300 text-slate-600 hover:text-indigo-700 flex items-center justify-center transition" title="Edit">
                                <i class="fas fa-pen-to-square text-[14px]"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>
</div>

<script>
function openVendor(data){
    const isNew = typeof data === 'number' && data === 0;
    const d = isNew ? {id:0,type:'vendor',name:'',pic_name:'',phone:'',email:'',bank_name:'',bank_account:'',bank_holder:'',share_pct:0,address:'',notes:'',is_active:1} : data;
    document.getElementById('v_id').value = +d.id || 0;
    document.getElementById('v_type').value = d.type || 'vendor';
    document.getElementById('v_name').value = d.name || '';
    document.getElementById('v_pic').value  = d.pic_name || '';
    document.getElementById('v_phone').value = d.phone || '';
    document.getElementById('v_email').value = d.email || '';
    document.getElementById('v_bank').value  = d.bank_name || '';
    document.getElementById('v_bankacc').value = d.bank_account || '';
    document.getElementById('v_bankhold').value = d.bank_holder || '';
    document.getElementById('v_share').value = (+d.share_pct || 0);
    document.getElementById('v_address').value = d.address || '';
    document.getElementById('v_notes').value = d.notes || '';
    document.getElementById('v_active').checked = (+d.is_active || 0) === 1;
    document.getElementById('vModalTitle').textContent = isNew ? 'Tambah Data Owner / Vendor / Investor' : ('Edit Data: ' + (d.name || ''));
    document.getElementById('vOverlay').classList.remove('hidden');
    document.getElementById('vModal').classList.remove('hidden');
    setTimeout(()=>{ document.getElementById('v_name').focus(); }, 50);
}
function closeVendor(){ document.getElementById('vOverlay').classList.add('hidden'); document.getElementById('vModal').classList.add('hidden'); }
document.addEventListener('click',(e)=>{ if(e.target.id === 'vOverlay') closeVendor(); });
</script>

<!-- MODAL CREATE / EDIT -->
<div id="vOverlay" class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-sm hidden"></div>
<div id="vModal" class="fixed top-0 left-0 right-0 bottom-0 z-50 hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xl w-full max-w-2xl my-8 overflow-hidden">
        <form method="post" class="flex flex-col max-h-[88vh]">
            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-white via-indigo-50/30 to-white flex items-center justify-between gap-3">
                <div>
                    <h3 id="vModalTitle" class="font-display text-xl font-black text-slate-900">Tambah Data Owner / Vendor / Investor</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Data owner aset kendaraan, vendor kerjasama, dan investor.</p>
                </div>
                <button type="button" onclick="closeVendor()" class="w-9 h-9 rounded-lg bg-slate-100 hover:bg-rose-50 text-slate-500 hover:text-rose-600 flex items-center justify-center transition"><i class="fas fa-times"></i></button>
            </div>
            <div class="px-6 py-5 space-y-4 overflow-y-auto">
                <input type="hidden" id="v_id" name="id" value="0">
                <input type="hidden" name="action" value="save_vendor">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Tipe</label>
                        <select id="v_type" name="type" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-semibold focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none">
                            <option value="owner">Owner Aset (Pemilik Kendaraan)</option>
                            <option value="vendor">Vendor Kerjasama</option>
                            <option value="investor">Investor (Pemilik Saham)</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Nama / Perusahaan <span class="text-rose-600">*</span></label>
                        <input type="text" id="v_name" name="name" required class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-semibold focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none" placeholder="Contoh: CV Adhi Jaya Trans, PT Mobil Indo, dll">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">PIC / Kontak Orang</label>
                        <input type="text" id="v_pic" name="pic_name" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="Nama PIC">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">No. HP / WA</label>
                        <input type="text" id="v_phone" name="phone" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-mono focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="08xx...">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Email</label>
                        <input type="email" id="v_email" name="email" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="email@perusahaan.com">
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-4 space-y-4">
                    <p class="text-[11px] font-black uppercase tracking-wider text-slate-600 flex items-center gap-1.5"><i class="fas fa-building-columns text-indigo-500"></i> Rekening Bank (Untuk Transfer Pembayaran)</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Nama Bank</label>
                            <input type="text" id="v_bank" name="bank_name" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="BCA, BRI, Mandiri, BNI">
                        </div>
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Nomor Rekening</label>
                            <input type="text" id="v_bankacc" name="bank_account" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-mono focus:ring-2 focus:ring-indigo-300 outline-none">
                        </div>
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Atas Nama Rekening</label>
                            <input type="text" id="v_bankhold" name="bank_holder" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                        </div>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Persentase Saham / Bagi Hasil (%)</label>
                    <div class="flex items-center gap-3">
                        <input type="number" id="v_share" name="share_pct" step="0.01" min="0" max="100" value="0" class="w-40 px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-bold font-mono text-right focus:ring-2 focus:ring-indigo-300 outline-none">
                        <span class="text-sm font-bold text-slate-600">% (Kosongkan / 0 jika tidak ada bagi hasil)</span>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Alamat Kantor</label>
                    <textarea id="v_address" name="address" rows="2" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="Alamat lengkap"></textarea>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Catatan Tambahan</label>
                    <textarea id="v_notes" name="notes" rows="2" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="Catatan khusus, term & condition, dll"></textarea>
                </div>
                <label class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 cursor-pointer w-fit">
                    <input type="checkbox" id="v_active" name="is_active" value="1" checked class="w-4 h-4 accent-emerald-600">
                    <span class="text-sm font-bold">✅ Data ini AKTIF (tampil di dropdown form lain)</span>
                </label>
            </div>
            <div class="px-6 py-4 border-t border-slate-100 bg-slate-50/60 flex items-center justify-end gap-2">
                <button type="button" onclick="closeVendor()" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-bold hover:bg-white transition">Batal</button>
                <button type="submit" class="px-5 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-bold shadow-sm transition">
                    <i class="fas fa-save mr-1"></i> Simpan Data
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>