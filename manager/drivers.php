<?php
/**
 * Manager Area: Driver & Kendaraan (Data Sopir + Mobil Operasional)
 * Akses: Manager Only
 * Tombol Export Excel (dari catatan: Download Excel)
 */

require_once __DIR__ . '/../config/config.php';
requireLogin();
requireRole('manager');

$db = Database::getInstance();

try {
    $t1 = "CREATE TABLE IF NOT EXISTS transport_drivers (
      id INT PRIMARY KEY AUTO_INCREMENT,
      fullname VARCHAR(180) NOT NULL,
      phone VARCHAR(40) NULL,
      id_card VARCHAR(60) NULL,
      sim_type VARCHAR(10) NULL,
      sim_expiry DATE NULL,
      address TEXT NULL,
      join_date DATE NULL,
      base_salary DECIMAL(14,0) DEFAULT 0,
      rate_per_km DECIMAL(10,0) DEFAULT 0,
      is_active TINYINT(1) DEFAULT 1,
      notes TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$db->query($t1);
    $t2 = "CREATE TABLE IF NOT EXISTS transport_vehicles (
      id INT PRIMARY KEY AUTO_INCREMENT,
      plat_no VARCHAR(20) NOT NULL UNIQUE,
      merk VARCHAR(100) NULL,
      type_name VARCHAR(120) NULL,
      year VARCHAR(4) NULL,
      color VARCHAR(40) NULL,
      capacity INT NULL,
      chasis_no VARCHAR(60) NULL,
      engine_no VARCHAR(60) NULL,
      owner_id INT NULL,
      stnk_date DATE NULL,
      kir_date DATE NULL,
      tax_date DATE NULL,
      is_active TINYINT(1) DEFAULT 1,
      notes TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$db->query($t2);
} catch (Throwable $_) {}

$pageTitle = 'Driver & Kendaraan Operasional';
$pageSubtitle = 'Kelola data sopir / driver, mobil / kendaraan operasional & unit. Tombol Export Excel (Download Excel) sesuai catatan Data Driver.';

$type = cleanInput($_GET['tab'] ?? 'driver');
if (!in_array($type, ['driver','vehicle'], true)) $type = 'driver';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = cleanInput($_POST['action'] ?? '');
    if ($action === 'save_driver') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['fullname'] ?? ''));
        if (strlen($name) < 3) { setFlash('danger','Nama Driver minimal 3 karakter'); redirect('manager/drivers.php'); }
        $phone = trim((string)($_POST['phone'] ?? ''));
        $idCard = trim((string)($_POST['id_card'] ?? ''));
        $simType = trim((string)($_POST['sim_type'] ?? ''));
        $simExp = trim((string)($_POST['sim_expiry'] ?? '')); if ($simExp === '') $simExp = null;
        $address = trim((string)($_POST['address'] ?? ''));
        $join = trim((string)($_POST['join_date'] ?? '')); if ($join === '') $join = null;
        $base = (float)($_POST['base_salary'] ?? 0);
        $rate = (float)($_POST['rate_per_km'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        $aktif = (int)($_POST['is_active'] ?? 1);
        if ($id === 0) {
            $db->query("INSERT INTO transport_drivers (fullname,phone,id_card,sim_type,sim_expiry,address,join_date,base_salary,rate_per_km,notes,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [$name,$phone,$idCard,$simType,$simExp,$address,$join,$base,$rate,$notes,$aktif]);
            setFlash('success',"✅ Driver BARU <strong>{$name}</strong> berhasil ditambahkan.");
        } else {
            $db->query("UPDATE transport_drivers SET fullname=?,phone=?,id_card=?,sim_type=?,sim_expiry=?,address=?,join_date=?,base_salary=?,rate_per_km=?,notes=?,is_active=? WHERE id=? LIMIT 1",
                [$name,$phone,$idCard,$simType,$simExp,$address,$join,$base,$rate,$notes,$aktif,$id]);
            setFlash('success',"✅ Driver <strong>{$name}</strong> berhasil di-update.");
        }
        redirect('manager/drivers.php?tab=driver');
    }
    if ($action === 'save_vehicle') {
        $id = (int)($_POST['id'] ?? 0);
        $plat = strtoupper(trim((string)($_POST['plat_no'] ?? '')));
        if (strlen($plat) < 4) { setFlash('danger','Plat Nomor minimal 4 karakter'); redirect('manager/drivers.php?tab=vehicle'); }
        $merk = trim((string)($_POST['merk'] ?? ''));
        $tipe = trim((string)($_POST['type_name'] ?? ''));
        $tahun = trim((string)($_POST['year'] ?? ''));
        $warna = trim((string)($_POST['color'] ?? ''));
        $kap = (int)($_POST['capacity'] ?? 0);
        $chasis = trim((string)($_POST['chasis_no'] ?? ''));
        $mesin  = trim((string)($_POST['engine_no'] ?? ''));
        $own = (int)($_POST['owner_id'] ?? 0); if ($own <= 0) $own = null;
        $stnk = trim((string)($_POST['stnk_date'] ?? '')); if ($stnk === '') $stnk = null;
        $kir = trim((string)($_POST['kir_date'] ?? '')); if ($kir === '') $kir = null;
        $pajak = trim((string)($_POST['tax_date'] ?? '')); if ($pajak === '') $pajak = null;
        $notes = trim((string)($_POST['notes'] ?? ''));
        $aktif = (int)($_POST['is_active'] ?? 1);
        if ($id === 0) {
            $cek = $db->fetchOne("SELECT id FROM transport_vehicles WHERE plat_no=? LIMIT 1",[$plat]);
            if ($cek) { setFlash('danger',"Plat nomor <strong>{$plat}</strong> SUDAH ADA, tidak boleh double."); redirect('manager/drivers.php?tab=vehicle'); }
            $db->query("INSERT INTO transport_vehicles (plat_no,merk,type_name,year,color,capacity,chasis_no,engine_no,owner_id,stnk_date,kir_date,tax_date,notes,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$plat,$merk,$tipe,$tahun,$warna,$kap,$chasis,$mesin,$own,$stnk,$kir,$pajak,$notes,$aktif]);
            setFlash('success',"✅ Kendaraan <strong>{$plat} {$tipe}</strong> berhasil ditambahkan.");
        } else {
            $cek = $db->fetchOne("SELECT id FROM transport_vehicles WHERE plat_no=? AND id<>? LIMIT 1",[$plat,$id]);
            if ($cek) { setFlash('danger',"Plat nomor <strong>{$plat}</strong> SUDAH ADA yang lain."); redirect('manager/drivers.php?tab=vehicle'); }
            $db->query("UPDATE transport_vehicles SET plat_no=?,merk=?,type_name=?,year=?,color=?,capacity=?,chasis_no=?,engine_no=?,owner_id=?,stnk_date=?,kir_date=?,tax_date=?,notes=?,is_active=? WHERE id=? LIMIT 1",
                [$plat,$merk,$tipe,$tahun,$warna,$kap,$chasis,$mesin,$own,$stnk,$kir,$pajak,$notes,$aktif,$id]);
            setFlash('success',"✅ Kendaraan <strong>{$plat}</strong> berhasil di-update.");
        }
        redirect('manager/drivers.php?tab=vehicle');
    }
}

$search = cleanInput($_GET['search'] ?? '');
$where = ['1=1']; $params = [];
if ($search !== '') {
    if ($type === 'driver') {
        $where[] = "(fullname LIKE ? OR phone LIKE ? OR IFNULL(id_card,'') LIKE ?)";
    } else {
        $where[] = "(plat_no LIKE ? OR IFNULL(merk,'') LIKE ? OR IFNULL(type_name,'') LIKE ?)";
    }
    $kw = "%{$search}%"; for($i=0;$i<3;$i++) $params[] = $kw;
}
$whereSql = implode(' AND ', $where);
if ($type === 'driver') {
    $rows = $db->fetchAll("SELECT * FROM transport_drivers WHERE {$whereSql} ORDER BY is_active DESC, fullname ASC", $params);
} else {
    $rows = $db->fetchAll("SELECT v.*, o.name as owner_name FROM transport_vehicles v LEFT JOIN transport_vendors o ON v.owner_id=o.id WHERE {$whereSql} ORDER BY v.is_active DESC, v.plat_no ASC", $params);
}
$ownerOptions = $db->fetchAll("SELECT id,name FROM transport_vendors WHERE is_active=1 ORDER BY name ASC");

$statDriver = (int)$db->fetchOne("SELECT COUNT(*) FROM transport_drivers WHERE is_active=1")['COUNT(*)'];
$statMobil  = (int)$db->fetchOne("SELECT COUNT(*) FROM transport_vehicles WHERE is_active=1")['COUNT(*)'];

function _xls2Esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function _xls2Header($title) {
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="UTF-8"><style>
        body{font-family:Calibri,Arial,sans-serif;font-size:12px;color:#111;}
        h1{font-size:18px;font-weight:900;color:#000;margin:0 0 6px;}
        .meta{font-size:11px;color:#333;margin-bottom:14px;}
        table{border-collapse:collapse;width:100%;}
        th{background:#0f172a;color:#fff;font-weight:900;font-size:11px;text-align:left;padding:7px 9px;border:1px solid #0f172a;}
        td{padding:6px 9px;border:1px solid #b4b4b4;vertical-align:top;}
        tr:nth-child(even) td{background:#f8fafc;}
        .num{text-align:right;font-family:"Consolas",monospace;}
        .titleHead{background:#0f766e;color:#fff;font-weight:900;padding:8px 10px;border:1px solid #115e59;font-size:13px;}
    </style></head><body><h1>'._xls2Esc($title).'</h1>';
}
if (isset($_GET['export']) && (string)$_GET['export'] === '1') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    if ($type === 'driver') {
        $fname = 'Data-Driver-Sopir-' . date('Ymd-His') . '.xls';
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        _xls2Header('🪪 Data Driver / Sopir Operasional');
        echo '<div class="meta"><strong>The St. Regis Bali — Engineering Dept</strong> | Search: '._xls2Esc($search ?: '-').' | Export: '.date('d M Y H:i:s').'</div>';
        $cols = ['No','ID','NAMA LENGKAP','NO HP','NO KTP','TIPE SIM','BERLAKU SAMPAI SIM','ALAMAT','TANGGAL GABUNG','GAJI POKOK (Rp)','RATE PER KM (Rp)','STATUS','CATATAN'];
        echo '<table><thead><tr><th colspan="'.count($cols).'" class="titleHead">LIST DATA DRIVER / SOPIR ('.count($rows).' DATA)</th></tr><tr>';
        foreach($cols as $c) echo '<th>'._xls2Esc($c).'</th>';
        echo '</tr></thead><tbody>';
        $n = 0;
        foreach ($rows as $r) { $n++;
            $st = ((int)$r['is_active']===1)?'AKTIF':'NON AKTIF';
            echo '<tr>';
            echo '<td class="num">'._xls2Esc($n).'</td>';
            echo '<td class="num">'._xls2Esc((int)$r['id']).'</td>';
            echo '<td><strong>'._xls2Esc($r['fullname']).'</strong></td>';
            echo '<td>'._xls2Esc($r['phone']).'</td>';
            echo '<td class="num">'._xls2Esc($r['id_card']).'</td>';
            echo '<td>'._xls2Esc($r['sim_type']).'</td>';
            echo '<td>'._xls2Esc((string)($r['sim_expiry'] ?? '')).'</td>';
            echo '<td>'._xls2Esc(preg_replace('/\s+/',' ',(string)($r['address'] ?? ''))).'</td>';
            echo '<td>'._xls2Esc((string)($r['join_date'] ?? '')).'</td>';
            echo '<td class="num">'._xls2Esc(number_format((float)($r['base_salary'] ?? 0),0,',','.')).'</td>';
            echo '<td class="num">'._xls2Esc(number_format((float)($r['rate_per_km'] ?? 0),0,',','.')).'</td>';
            echo '<td><strong>'._xls2Esc($st).'</strong></td>';
            echo '<td>'._xls2Esc(preg_replace('/\s+/',' ',(string)($r['notes'] ?? ''))).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
    } else {
        $fname = 'Data-Kendaraan-Unit-' . date('Ymd-His') . '.xls';
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        _xls2Header('🚗 Data Kendaraan / Unit Mobil Operasional');
        echo '<div class="meta"><strong>The St. Regis Bali — Engineering Dept</strong> | Search: '._xls2Esc($search ?: '-').' | Export: '.date('d M Y H:i:s').'</div>';
        $cols = ['No','ID','PLAT NOMOR','MERK','TIPE / MODEL','TAHUN','WARNA','KAPASITAS (org)','NO RANGKA (Chasis)','NO MESIN','OWNER / VENDOR','STNK BERLAKU S/D','KIR BERLAKU S/D','PAJAK BERLAKU S/D','STATUS','CATATAN'];
        echo '<table><thead><tr><th colspan="'.count($cols).'" class="titleHead">LIST DATA KENDARAAN / UNIT MOBIL ('.count($rows).' UNIT)</th></tr><tr>';
        foreach($cols as $c) echo '<th>'._xls2Esc($c).'</th>';
        echo '</tr></thead><tbody>';
        $n = 0;
        foreach ($rows as $r) { $n++;
            $st = ((int)$r['is_active']===1)?'AKTIF':'NON AKTIF';
            echo '<tr>';
            echo '<td class="num">'._xls2Esc($n).'</td>';
            echo '<td class="num">'._xls2Esc((int)$r['id']).'</td>';
            echo '<td><strong style="background:#0369a1;color:#fff;padding:2px 6px;border-radius:4px;">'._xls2Esc(strtoupper($r['plat_no'])).'</strong></td>';
            echo '<td>'._xls2Esc($r['merk'] ?? '').'</td>';
            echo '<td>'._xls2Esc($r['type_name'] ?? '').'</td>';
            echo '<td class="num">'._xls2Esc($r['year'] ?? '').'</td>';
            echo '<td>'._xls2Esc($r['color'] ?? '').'</td>';
            echo '<td class="num">'._xls2Esc((int)($r['capacity'] ?? 0)).'</td>';
            echo '<td class="num">'._xls2Esc($r['chasis_no'] ?? '').'</td>';
            echo '<td class="num">'._xls2Esc($r['engine_no'] ?? '').'</td>';
            echo '<td>'._xls2Esc($r['owner_name'] ?? '').'</td>';
            echo '<td>'._xls2Esc((string)($r['stnk_date'] ?? '')).'</td>';
            echo '<td>'._xls2Esc((string)($r['kir_date'] ?? '')).'</td>';
            echo '<td>'._xls2Esc((string)($r['tax_date'] ?? '')).'</td>';
            echo '<td><strong>'._xls2Esc($st).'</strong></td>';
            echo '<td>'._xls2Esc(preg_replace('/\s+/',' ',(string)($r['notes'] ?? ''))).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
    }
    exit;
}

$_GET['_inline_header'] = 1;
$pageHeaderActive = 'drivers';
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
        <span class="text-slate-800">Driver & Kendaraan</span>
    </div>
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <span class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center text-white text-lg shadow-md shadow-orange-500/25"><i class="fas fa-id-card"></i></span>
                <div>
                    <h1 class="font-display text-2xl lg:text-3xl font-black text-slate-900 leading-tight">Driver & Kendaraan Operasional</h1>
                    <p class="text-sm text-slate-500 mt-0.5"><?= $pageSubtitle ?></p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <button type="button" onclick="openExport()" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-emerald-300 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-sm font-bold shadow-sm transition">
                <i class="fas fa-file-excel"></i> Download Excel
            </button>
            <button type="button" onclick="<?= $type === 'driver' ? 'openDriver(0)' : 'openVehicle(0)' ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-sm font-bold shadow-sm transition">
                <i class="fas fa-plus-circle"></i> <?= $type === 'driver' ? 'Tambah Driver' : 'Tambah Kendaraan' ?>
            </button>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center text-indigo-700"><i class="fas fa-id-card"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Driver Aktif</div></div>
        <div class="text-3xl font-black text-indigo-700 leading-none"><?= $statDriver ?></div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-sky-100 flex items-center justify-center text-sky-700"><i class="fas fa-car-side"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Unit Kendaraan Aktif</div></div>
        <div class="text-3xl font-black text-sky-700 leading-none"><?= $statMobil ?></div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-700"><i class="fas fa-route"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Trip Hari Ini</div></div>
        <div class="text-3xl font-black text-emerald-700 leading-none">-</div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-rose-100 flex items-center justify-center text-rose-700"><i class="fas fa-triangle-exclamation"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">STNK / KIR Jatuh Tempo</div></div>
        <div class="text-3xl font-black text-rose-700 leading-none">0</div>
    </div>
</div>

<div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
    <div class="flex flex-wrap items-center gap-3 p-4 lg:p-5 border-b border-slate-100 bg-slate-50/40">
        <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-xl border border-slate-200">
            <a href="?tab=driver<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="px-3.5 py-1.5 rounded-lg text-sm font-bold transition <?= $type === 'driver' ? 'bg-white shadow text-slate-900' : 'text-slate-600 hover:text-slate-900' ?>">
                <i class="fas fa-id-card mr-1.5 text-[13px]"></i> Data Driver (Sopir)
            </a>
            <a href="?tab=vehicle<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="px-3.5 py-1.5 rounded-lg text-sm font-bold transition <?= $type === 'vehicle' ? 'bg-white shadow text-slate-900' : 'text-slate-600 hover:text-slate-900' ?>">
                <i class="fas fa-car-side mr-1.5 text-[13px]"></i> Data Kendaraan (Unit Mobil)
            </a>
        </div>
        <div class="flex-1 min-w-[200px] flex items-center gap-2 flex ml-auto">
            <form method="get" class="flex items-center gap-2 w-full">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($type) ?>">
                <div class="relative flex-1 min-w-[200px]">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="<?= $type === 'driver' ? 'Cari nama driver, no HP, KTP' : 'Cari plat, merk, tipe mobil' ?>" class="w-full pl-10 pr-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition">
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-bold hover:bg-slate-800 transition">Cari</button>
                <a href="?tab=<?= $type ?>" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-bold hover:bg-slate-50 transition">Reset</a>
            </form>
        </div>
    </div>
    <div class="overflow-x-auto">
    <?php if ($type === 'driver'): ?>
        <table class="w-full text-sm min-w-[900px]">
            <thead>
                <tr class="bg-gradient-to-r from-slate-100 to-slate-50 text-slate-700 text-[11px] uppercase tracking-[0.12em] font-black">
                    <th class="px-5 py-3 text-left font-black">Nama Driver</th>
                    <th class="px-5 py-3 text-left font-black">SIM & Exp</th>
                    <th class="px-5 py-3 text-left font-black">Kontak & Alamat</th>
                    <th class="px-5 py-3 text-left font-black">Tanggal Gabung</th>
                    <th class="px-5 py-3 text-center font-black">Gaji & Rate</th>
                    <th class="px-5 py-3 text-center font-black">Status</th>
                    <th class="px-5 py-3 text-center font-black w-28">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if (count($rows) === 0): ?>
                <tr><td colspan="7" class="px-5 py-12 text-center">
                    <div class="mx-auto w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-3"><i class="fas fa-id-card text-slate-400 text-2xl"></i></div>
                    <p class="text-slate-600 font-semibold">Belum ada data Driver.</p>
                    <button type="button" onclick="openDriver(0)" class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold"><i class="fas fa-plus-circle"></i> Tambah Driver Pertama</button>
                </td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr class="hover:bg-slate-50/60 transition">
                    <td class="px-5 py-3.5">
                        <div class="flex items-center gap-3">
                            <span class="w-10 h-10 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white font-black shadow-sm"><?= strtoupper(mb_substr($r['fullname'],0,1) ?: 'D') ?></span>
                            <div>
                                <p class="font-bold text-slate-900 text-base leading-tight"><?= cleanInput($r['fullname']) ?></p>
                                <?php if (strlen($r['id_card'] ?? '') > 0): ?>
                                    <p class="text-[11px] text-slate-500 mt-0.5"><i class="far fa-id-card mr-1 text-slate-400"></i><?= cleanInput($r['id_card']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-3.5 text-sm text-slate-700">
                        <?php if (strlen($r['sim_type'] ?? '') > 0): ?>
                            <p class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-indigo-50 border border-indigo-200 text-indigo-700 font-bold text-[11px]">SIM <?= cleanInput($r['sim_type']) ?></p>
                        <?php endif; ?>
                        <?php if (strlen($r['sim_expiry'] ?? '') > 0): ?>
                            <p class="text-[11px] text-slate-600 mt-1">Exp: <?= (new DateTime($r['sim_expiry']))->format('d M Y') ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-sm text-slate-700">
                        <?php if (strlen($r['phone'] ?? '') > 0): ?><p class="font-bold text-slate-800 text-[13px]"><i class="fas fa-phone mr-1 text-slate-400"></i><?= cleanInput($r['phone']) ?></p><?php endif; ?>
                        <?php if (strlen($r['address'] ?? '') > 0): ?><p class="text-[12px] text-slate-600 line-clamp-1"><?= cleanInput($r['address']) ?></p><?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-sm text-slate-700 text-center">
                        <?php if (strlen($r['join_date'] ?? '') > 0): ?>
                            <p class="font-bold text-slate-800 text-[13px]"><?= (new DateTime($r['join_date']))->format('d M Y') ?></p>
                        <?php else: ?>
                            <span class="text-xs text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-sm text-slate-700 text-right">
                        <?php if ((float)$r['base_salary'] > 0): ?><p class="font-black text-slate-900">Rp <?= number_format((float)$r['base_salary'],0,',','.') ?></p><?php endif; ?>
                        <?php if ((float)$r['rate_per_km'] > 0): ?><p class="text-[11px] text-slate-500">+ Rp <?= number_format((float)$r['rate_per_km']) ?> / km</p><?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-center">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border text-[11px] font-bold transition <?= (int)$r['is_active'] ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-slate-100 border-slate-200 text-slate-600' ?>">
                            <i class="fas fa-power-off text-[10px]"></i> <?= (int)$r['is_active'] ? 'Aktif' : 'Non Aktif' ?></span>
                    </td>
                    <td class="px-5 py-3.5">
                        <div class="flex items-center justify-center gap-1.5">
                            <button type="button" onclick='openDriver(<?= json_encode($r) ?>)' class="w-9 h-9 rounded-lg border border-slate-300 hover:bg-indigo-50 hover:border-indigo-300 text-slate-600 hover:text-indigo-700 flex items-center justify-center transition" title="Edit"><i class="fas fa-pen-to-square text-[14px]"></i></button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    <?php else: ?>
        <table class="w-full text-sm min-w-[1100px]">
            <thead>
                <tr class="bg-gradient-to-r from-slate-100 to-slate-50 text-slate-700 text-[11px] uppercase tracking-[0.12em] font-black">
                    <th class="px-5 py-3 text-left font-black">Plat & Unit</th>
                    <th class="px-5 py-3 text-left font-black">Owner</th>
                    <th class="px-5 py-3 text-left font-black">Spesifikasi</th>
                    <th class="px-5 py-3 text-center font-black">STNK</th>
                    <th class="px-5 py-3 text-center font-black">KIR</th>
                    <th class="px-5 py-3 text-center font-black">Pajak</th>
                    <th class="px-5 py-3 text-center font-black">Status</th>
                    <th class="px-5 py-3 text-center font-black w-28">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if (count($rows) === 0): ?>
                <tr><td colspan="8" class="px-5 py-12 text-center">
                    <div class="mx-auto w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-3"><i class="fas fa-car-side text-slate-400 text-2xl"></i></div>
                    <p class="text-slate-600 font-semibold">Belum ada data Kendaraan.</p>
                    <button type="button" onclick="openVehicle(0)" class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold"><i class="fas fa-plus-circle"></i> Tambah Kendaraan Pertama</button>
                </td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr class="hover:bg-slate-50/60 transition">
                    <td class="px-5 py-3.5">
                        <div class="flex items-center gap-3">
                            <span class="px-3 py-2 rounded-lg bg-gradient-to-br from-sky-500 to-blue-700 text-white font-black text-[14px] shadow-sm"><?= cleanInput($r['plat_no']) ?></span>
                            <div>
                                <p class="font-bold text-slate-900 text-base leading-tight"><?= cleanInput($r['merk'] ?? '') ?> <?= cleanInput($r['type_name'] ?? '') ?></p>
                                <p class="text-[11px] text-slate-500">
                                    <?php if (strlen($r['year'] ?? '') > 0): ?><span class="mr-2"><?= cleanInput($r['year']) ?></span><?php endif; ?>
                                    <?php if (strlen($r['color'] ?? '') > 0): ?><span class="mr-2"><i class="fas fa-palette text-[10px]"></i> <?= cleanInput($r['color']) ?></span><?php endif; ?>
                                    <?php if ((int)$r['capacity'] > 0): ?><span><i class="fas fa-users text-[10px]"></i> <?= (int)$r['capacity'] ?> penumpang</span><?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-3.5 text-sm text-slate-700">
                        <?php if (strlen($r['owner_name'] ?? '') > 0): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-violet-50 border border-violet-200 text-violet-700 font-bold text-[12px]"><i class="fas fa-user-tag text-[11px]"></i> <?= cleanInput($r['owner_name']) ?></span>
                        <?php else: ?>
                            <span class="text-xs text-slate-400 font-semibold">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-sm text-slate-700">
                        <?php if (strlen($r['chasis_no'] ?? '') > 0): ?><p class="text-[11px]"><span class="text-slate-400 font-bold mr-1">No. Rangka:</span><span class="font-mono"><?= cleanInput($r['chasis_no']) ?></span></p><?php endif; ?>
                        <?php if (strlen($r['engine_no'] ?? '') > 0): ?><p class="text-[11px]"><span class="text-slate-400 font-bold mr-1">No. Mesin:</span><span class="font-mono"><?= cleanInput($r['engine_no']) ?></span></p><?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-center text-sm">
                        <?php if (strlen($r['stnk_date'] ?? '') > 0): ?>
                            <p class="font-bold text-slate-800 text-[13px]"><?= (new DateTime($r['stnk_date']))->format('d M Y') ?></p>
                        <?php else: ?>
                            <span class="text-xs text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-center text-sm">
                        <?php if (strlen($r['kir_date'] ?? '') > 0): ?>
                            <p class="font-bold text-slate-800 text-[13px]"><?= (new DateTime($r['kir_date']))->format('d M Y') ?></p>
                        <?php else: ?>
                            <span class="text-xs text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-center text-sm">
                        <?php if (strlen($r['tax_date'] ?? '') > 0): ?>
                            <p class="font-bold text-slate-800 text-[13px]"><?= (new DateTime($r['tax_date']))->format('d M Y') ?></p>
                        <?php else: ?>
                            <span class="text-xs text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-center">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border text-[11px] font-bold transition <?= (int)$r['is_active'] ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-slate-100 border-slate-200 text-slate-600' ?>">
                            <i class="fas fa-power-off text-[10px]"></i> <?= (int)$r['is_active'] ? 'Aktif' : 'Non Aktif' ?></span>
                    </td>
                    <td class="px-5 py-3.5">
                        <div class="flex items-center justify-center gap-1.5">
                            <button type="button" onclick='openVehicle(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS) ?>)' class="w-9 h-9 rounded-lg border border-slate-300 hover:bg-indigo-50 hover:border-indigo-300 text-slate-600 hover:text-indigo-700 flex items-center justify-center transition" title="Edit"><i class="fas fa-pen-to-square text-[14px]"></i></button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</div>

</div>
</div>

<script>
const owners = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name']], $ownerOptions)) ?>;
const currentTab = <?= json_encode($type) ?>;
function openExport(){
    const params = new URLSearchParams(window.location.search);
    params.set('export', '1');
    params.set('tab', currentTab);
    window.location.href = '?' + params.toString();
}
function openDriver(data){
    const isNew = typeof data === 'number' && data === 0;
    const d = isNew ? {id:0,fullname:'',phone:'',id_card:'',sim_type:'SIM A',sim_expiry:'',address:'',join_date:'',base_salary:0,rate_per_km:0,notes:'',is_active:1} : data;
    document.getElementById('d_id').value = +d.id || 0;
    document.getElementById('d_name').value = d.fullname || '';
    document.getElementById('d_phone').value = d.phone || '';
    document.getElementById('d_idcard').value = d.id_card || '';
    document.getElementById('d_simtype').value = d.sim_type || 'SIM A';
    document.getElementById('d_simexp').value = d.sim_expiry || '';
    document.getElementById('d_addr').value = d.address || '';
    document.getElementById('d_join').value = d.join_date || '';
    document.getElementById('d_base').value = +d.base_salary || 0;
    document.getElementById('d_rate').value = +d.rate_per_km || 0;
    document.getElementById('d_notes').value = d.notes || '';
    document.getElementById('d_active').checked = (+d.is_active || 0) === 1;
    document.getElementById('dModalTitle').textContent = isNew ? 'Tambah Driver Baru' : ('Edit Driver: ' + (d.fullname || ''));
    document.getElementById('dOverlay').classList.remove('hidden');
    document.getElementById('dModal').classList.remove('hidden');
    setTimeout(()=>{ document.getElementById('d_name').focus(); }, 50);
}
function closeDriver(){ document.getElementById('dOverlay').classList.add('hidden'); document.getElementById('dModal').classList.add('hidden'); }
function openVehicle(data){
    const isNew = typeof data === 'number' && data === 0;
    const d = isNew ? {id:0,plat_no:'',merk:'',type_name:'',year:'',color:'',capacity:0,chasis_no:'',engine_no:'',owner_id:0,stnk_date:'',kir_date:'',tax_date:'',notes:'',is_active:1} : data;
    document.getElementById('v2_id').value = +d.id || 0;
    document.getElementById('v2_plat').value = d.plat_no || '';
    document.getElementById('v2_merk').value = d.merk || '';
    document.getElementById('v2_tipe').value = d.type_name || '';
    document.getElementById('v2_tahun').value = d.year || '';
    document.getElementById('v2_warna').value = d.color || '';
    document.getElementById('v2_kap').value = +d.capacity || 0;
    document.getElementById('v2_chasis').value = d.chasis_no || '';
    document.getElementById('v2_mesin').value = d.engine_no || '';
    document.getElementById('v2_own').value = +d.owner_id || 0;
    document.getElementById('v2_stnk').value = d.stnk_date || '';
    document.getElementById('v2_kir').value = d.kir_date || '';
    document.getElementById('v2_pajak').value = d.tax_date || '';
    document.getElementById('v2_notes').value = d.notes || '';
    document.getElementById('v2_active').checked = (+d.is_active || 0) === 1;
    document.getElementById('v2ModalTitle').textContent = isNew ? 'Tambah Kendaraan Baru' : ('Edit Kendaraan: ' + (d.plat_no || ''));
    document.getElementById('v2Overlay').classList.remove('hidden');
    document.getElementById('v2Modal').classList.remove('hidden');
    setTimeout(()=>{ document.getElementById('v2_plat').focus(); }, 50);
}
function closeVehicle(){ document.getElementById('v2Overlay').classList.add('hidden'); document.getElementById('v2Modal').classList.add('hidden'); }
document.addEventListener('click',(e)=>{ if(e.target.id==='dOverlay') closeDriver(); if(e.target.id==='v2Overlay') closeVehicle(); });
</script>

<!-- MODAL DRIVER -->
<div id="dOverlay" class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-sm hidden"></div>
<div id="dModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xl w-full max-w-2xl my-8 overflow-hidden">
        <form method="post" class="flex flex-col max-h-[88vh]">
            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-white via-amber-50/30 to-white flex items-center justify-between gap-3">
                <div>
                    <h3 id="dModalTitle" class="font-display text-xl font-black text-slate-900">Tambah Driver Baru</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Data Sopir / Driver Operasional.</p>
                </div>
                <button type="button" onclick="closeDriver()" class="w-9 h-9 rounded-lg bg-slate-100 hover:bg-rose-50 text-slate-500 hover:text-rose-600 flex items-center justify-center transition"><i class="fas fa-times"></i></button>
            </div>
            <div class="px-6 py-5 space-y-4 overflow-y-auto">
                <input type="hidden" id="d_id" name="id" value="0">
                <input type="hidden" name="action" value="save_driver">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Nama Lengkap Driver <span class="text-rose-600">*</span></label>
                        <input type="text" id="d_name" name="fullname" required class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-semibold focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none" placeholder="Nama sopir lengkap">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">No. HP / WA</label>
                        <input type="text" id="d_phone" name="phone" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-mono focus:ring-2 focus:ring-indigo-300 outline-none">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">No. KTP</label>
                        <input type="text" id="d_idcard" name="id_card" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-mono focus:ring-2 focus:ring-indigo-300 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Tipe SIM</label>
                        <select id="d_simtype" name="sim_type" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-semibold focus:ring-2 focus:ring-indigo-300 outline-none">
                            <option>SIM A</option><option>SIM B1</option><option>SIM B2 Umum</option><option>SIM C</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Berlaku Sampai</label>
                        <input type="date" id="d_simexp" name="sim_expiry" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Tanggal Gabung</label>
                        <input type="date" id="d_join" name="join_date" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Alamat Domisili</label>
                    <textarea id="d_addr" name="address" rows="2" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none"></textarea>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-4 space-y-4">
                    <p class="text-[11px] font-black uppercase tracking-wider text-slate-600 flex items-center gap-1.5"><i class="fas fa-wallet text-amber-600"></i> Kompensasi Driver</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Gaji Pokok (Rp)</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 font-bold text-sm">Rp</span>
                                <input type="number" id="d_base" name="base_salary" min="0" step="1000" value="0" class="w-full pl-10 pr-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-mono font-bold text-right focus:ring-2 focus:ring-indigo-300 outline-none">
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Rate / Insentif per KM (Rp)</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 font-bold text-sm">Rp</span>
                                <input type="number" id="d_rate" name="rate_per_km" min="0" step="50" value="0" class="w-full pl-10 pr-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-mono font-bold text-right focus:ring-2 focus:ring-indigo-300 outline-none">
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Catatan</label>
                    <textarea id="d_notes" name="notes" rows="2" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none"></textarea>
                </div>
                <label class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 cursor-pointer w-fit">
                    <input type="checkbox" id="d_active" name="is_active" value="1" checked class="w-4 h-4 accent-emerald-600">
                    <span class="text-sm font-bold">✅ Driver ini AKTIF</span>
                </label>
            </div>
            <div class="px-6 py-4 border-t border-slate-100 bg-slate-50/60 flex items-center justify-end gap-2">
                <button type="button" onclick="closeDriver()" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-bold hover:bg-white transition">Batal</button>
                <button type="submit" class="px-5 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-bold shadow-sm transition"><i class="fas fa-save mr-1"></i> Simpan Driver</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL KENDARAAN -->
<div id="v2Overlay" class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-sm hidden"></div>
<div id="v2Modal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xl w-full max-w-3xl my-8 overflow-hidden">
        <form method="post" class="flex flex-col max-h-[88vh]">
            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-white via-sky-50/30 to-white flex items-center justify-between gap-3">
                <div>
                    <h3 id="v2ModalTitle" class="font-display text-xl font-black text-slate-900">Tambah Kendaraan Baru</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Data unit mobil operasional (plat, STNK, KIR, Pajak, owner).</p>
                </div>
                <button type="button" onclick="closeVehicle()" class="w-9 h-9 rounded-lg bg-slate-100 hover:bg-rose-50 text-slate-500 hover:text-rose-600 flex items-center justify-center transition"><i class="fas fa-times"></i></button>
            </div>
            <div class="px-6 py-5 space-y-4 overflow-y-auto">
                <input type="hidden" id="v2_id" name="id" value="0">
                <input type="hidden" name="action" value="save_vehicle">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Plat Nomor <span class="text-rose-600">*</span></label>
                        <input type="text" id="v2_plat" name="plat_no" required class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-bold uppercase focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="DK 1234 AB">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Merk</label>
                        <input type="text" id="v2_merk" name="merk" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="Toyota">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Tipe / Model</label>
                        <input type="text" id="v2_tipe" name="type_name" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="Innova Reborn">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Tahun & Kapasitas Penumpang</label>
                        <div class="flex gap-2 flex">
                            <input type="text" id="v2_tahun" name="year" placeholder="2024" class="flex-1 px-2.5 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-mono focus:ring-2 focus:ring-indigo-300 outline-none">
                            <input type="number" id="v2_kap" name="capacity" min="0" placeholder="7" class="w-24 px-2.5 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-mono focus:ring-2 focus:ring-indigo-300 outline-none">
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Warna</label>
                        <input type="text" id="v2_warna" name="color" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="Hitam Metalik">
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">No. Rangka (Chasis)</label>
                        <input type="text" id="v2_chasis" name="chasis_no" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-mono focus:ring-2 focus:ring-indigo-300 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">No. Mesin</label>
                        <input type="text" id="v2_mesin" name="engine_no" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-mono focus:ring-2 focus:ring-indigo-300 outline-none">
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Pemilik Kendaraan (Owner) *link ke Data Owner / Vendor</label>
                    <select id="v2_own" name="owner_id" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-semibold focus:ring-2 focus:ring-indigo-300 outline-none">
                        <option value="0">— Pabrik / Milik Perusahaan (tidak spesifik owner)</option>
                        <?php foreach($ownerOptions as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= cleanInput($o['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-4 space-y-4">
                    <p class="text-[11px] font-black uppercase tracking-wider text-slate-600 flex items-center gap-1.5"><i class="fas fa-calendar-check text-sky-600"></i> Masa Berlaku Dokumen (Reminder nanti)</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">STNK Berlaku Sampai</label>
                            <input type="date" id="v2_stnk" name="stnk_date" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                        </div>
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">KIR Berlaku Sampai</label>
                            <input type="date" id="v2_kir" name="kir_date" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                        </div>
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Pajak Tahunan</label>
                            <input type="date" id="v2_pajak" name="tax_date" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                        </div>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Catatan</label>
                    <textarea id="v2_notes" name="notes" rows="2" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none"></textarea>
                </div>
                <label class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 cursor-pointer w-fit">
                    <input type="checkbox" id="v2_active" name="is_active" value="1" checked class="w-4 h-4 accent-emerald-600">
                    <span class="text-sm font-bold">✅ Unit ini AKTIF</span>
                </label>
            </div>
            <div class="px-6 py-4 border-t border-slate-100 bg-slate-50/60 flex items-center justify-end gap-2">
                <button type="button" onclick="closeVehicle()" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-bold hover:bg-white transition">Batal</button>
                <button type="submit" class="px-5 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-bold shadow-sm transition"><i class="fas fa-save mr-1"></i> Simpan Kendaraan</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>