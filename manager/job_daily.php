<?php
/**
 * Manager Area: Job Daily Trip Transport Logistik
 * - Kolom: Harga Jual Customer, Harga Beli Vendor (Harga bila beli ke vendor sesuai kertas)
 * - 3 Filter Preset SESUAI KERTAS FILTER KEUANGAN: Today 1 Trip, Sopir KM, All
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();
requireRole('manager');

$db = Database::getInstance();

try {
    @$db->query("CREATE TABLE IF NOT EXISTS transport_job_daily (
      id INT PRIMARY KEY AUTO_INCREMENT,
      trip_no VARCHAR(60) NULL UNIQUE,
      trip_date DATE NOT NULL,
      customer_name VARCHAR(180) NULL,
      route_from VARCHAR(120) NULL,
      route_to VARCHAR(120) NULL,
      vehicle_id INT NULL,
      driver_id INT NULL,
      driver2_id INT NULL,
      km_start DECIMAL(10,1) DEFAULT 0,
      km_end DECIMAL(10,1) DEFAULT 0,
      total_km DECIMAL(10,1) DEFAULT 0,
      trip_count INT DEFAULT 1,
      price_sell DECIMAL(14,0) DEFAULT 0,
      price_vendor DECIMAL(14,0) DEFAULT 0,
      fee_driver DECIMAL(14,0) DEFAULT 0,
      status ENUM('draft','in_progress','completed','cancelled') DEFAULT 'completed',
      payment_status ENUM('unpaid','paid') DEFAULT 'unpaid',
      notes TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $_) {}

$pageTitle = 'Job Daily Trip Transport';
$pageSubtitle = 'Catatan harian perjalanan trip transportasi hotel / vendor. Wajib ada kolom Harga Jual Customer & Harga Beli Vendor sesuai catatan kertas. 3 tombol filter: Today 1 Trip, Sopir KM, All.';

$preset = cleanInput($_GET['preset'] ?? 'all');
$search = cleanInput($_GET['search'] ?? '');
$from   = cleanInput($_GET['from'] ?? '');
$to     = cleanInput($_GET['to'] ?? '');

if ($from === '') { $from = date('Y-m-01'); }
if ($to === '')   { $to = date('Y-m-d'); }
if ($preset === 'today') { $from = date('Y-m-d'); $to = date('Y-m-d'); }

$where = ['1=1']; $params = [];
$where[] = 'trip_date BETWEEN ? AND ?'; $params[] = $from; $params[] = $to;
if ($preset === 'today') {
    $where[] = 'trip_count = 1';
}
if ($search !== '') {
    $where[] = '(IFNULL(trip_no,\'\') LIKE ? OR IFNULL(customer_name,\'\') LIKE ? OR IFNULL(route_from,\'\') LIKE ? OR IFNULL(route_to,\'\') LIKE ?)';
    $kw = "%{$search}%"; for($i=0;$i<4;$i++) $params[] = $kw;
}
$whereSql = implode(' AND ', $where);
$rows = $db->fetchAll("SELECT j.*,v.plat_no,v.type_name as vehicle_name,d.fullname as driver_name,d2.fullname as driver2_name FROM transport_job_daily j
    LEFT JOIN transport_vehicles v ON v.id = j.vehicle_id
    LEFT JOIN transport_drivers d ON d.id = j.driver_id
    LEFT JOIN transport_drivers d2 ON d2.id = j.driver2_id
    WHERE {$whereSql} ORDER BY j.trip_date DESC, j.id DESC LIMIT 500", $params);

$vehicles = $db->fetchAll("SELECT id,plat_no,type_name FROM transport_vehicles WHERE is_active=1 ORDER BY plat_no ASC");
$drivers  = $db->fetchAll("SELECT id,fullname FROM transport_drivers WHERE is_active=1 ORDER BY fullname ASC");

$statTrip = count($rows);
$statJual = 0; $statVendor = 0; $statKM = 0;
foreach ($rows as $r) {
    $statJual += (float)($r['price_sell'] ?? 0);
    $statVendor += (float)($r['price_vendor'] ?? 0);
    $statKM += (float)($r['total_km'] ?? 0);
}
$statLaba = $statJual - $statVendor;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = cleanInput($_POST['action'] ?? '');
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $no = trim((string)($_POST['trip_no'] ?? ''));
        if ($no === '') $no = 'TRIP-' . date('ymdHis');
        $date = trim((string)($_POST['trip_date'] ?? date('Y-m-d'))); if ($date === '') $date = date('Y-m-d');
        $cust = trim((string)($_POST['customer_name'] ?? ''));
        $fromLoc = trim((string)($_POST['route_from'] ?? ''));
        $toLoc = trim((string)($_POST['route_to'] ?? ''));
        $vid = (int)($_POST['vehicle_id'] ?? 0); if ($vid <= 0) $vid = null;
        $did = (int)($_POST['driver_id'] ?? 0); if ($did <= 0) $did = null;
        $d2id = (int)($_POST['driver2_id'] ?? 0); if ($d2id <= 0) $d2id = null;
        $kms = (float)($_POST['km_start'] ?? 0);
        $kme = (float)($_POST['km_end'] ?? 0);
        $totalKm = (float)($_POST['total_km'] ?? 0); if ($totalKm <= 0 && $kme > $kms) $totalKm = $kme - $kms;
        $tc = (int)($_POST['trip_count'] ?? 1); if ($tc < 1) $tc = 1;
        $ps = (float)($_POST['price_sell'] ?? 0);
        $pv = (float)($_POST['price_vendor'] ?? 0);
        $fd = (float)($_POST['fee_driver'] ?? 0);
        $st = trim((string)($_POST['status'] ?? 'completed'));
        if (!in_array($st,['draft','in_progress','completed','cancelled'],true)) $st = 'completed';
        $pay = trim((string)($_POST['payment_status'] ?? 'unpaid'));
        if (!in_array($pay,['unpaid','paid'],true)) $pay = 'unpaid';
        $notes = trim((string)($_POST['notes'] ?? ''));
        $fields = 'trip_no,trip_date,customer_name,route_from,route_to,vehicle_id,driver_id,driver2_id,km_start,km_end,total_km,trip_count,price_sell,price_vendor,fee_driver,status,payment_status,notes';
        $vals = [$no,$date,$cust,$fromLoc,$toLoc,$vid,$did,$d2id,$kms,$kme,$totalKm,$tc,$ps,$pv,$fd,$st,$pay,$notes];
        if ($id === 0) {
            $db->query("INSERT INTO transport_job_daily ({$fields}) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", $vals);
            setFlash('success',"✅ Job Trip <strong>{$no}</strong> berhasil ditambahkan.");
        } else {
            $upd = 'trip_no=?,trip_date=?,customer_name=?,route_from=?,route_to=?,vehicle_id=?,driver_id=?,driver2_id=?,km_start=?,km_end=?,total_km=?,trip_count=?,price_sell=?,price_vendor=?,fee_driver=?,status=?,payment_status=?,notes=?';
            $vals[] = $id;
            $db->query("UPDATE transport_job_daily SET {$upd} WHERE id=? LIMIT 1", $vals);
            setFlash('success',"✅ Job Trip <strong>{$no}</strong> berhasil di-update.");
        }
        redirect('manager/job_daily.php?preset=' . urlencode($preset) . '&from=' . urlencode($from) . '&to=' . urlencode($to));
    }
    if ($action === 'del') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->query("DELETE FROM transport_job_daily WHERE id=? LIMIT 1", [$id]);
            setFlash('success',"🗑️ Job Trip ID #{$id} berhasil dihapus.");
        }
        redirect('manager/job_daily.php?preset=' . urlencode($preset) . '&from=' . urlencode($from) . '&to=' . urlencode($to));
    }
}

function _xls3Esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if (isset($_GET['export']) && (string)$_GET['export'] === '1') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    $fname = 'Job-Daily-Trip-' . date('Ymd-His') . '.xls';
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    $pl = ['today'=>'Today 1 Trip','driver'=>'Sopir KM','all'=>'All Data'];
    $plLabel = $pl[$preset] ?? 'Custom';
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="UTF-8"><style>
        body{font-family:Calibri,Arial,sans-serif;font-size:12px;color:#111;}
        h1{font-size:18px;font-weight:900;color:#000;margin:0 0 6px;}
        .meta{font-size:11px;color:#333;margin-bottom:10px;}
        table{border-collapse:collapse;width:100%;margin-bottom:14px;}
        th{background:#111827;color:#fff;font-weight:900;font-size:11px;text-align:left;padding:7px 9px;border:1px solid #0f172a;}
        td{padding:6px 9px;border:1px solid #b4b4b4;vertical-align:top;}
        tr:nth-child(even) td{background:#f8fafc;}
        .num{text-align:right;font-family:"Consolas",monospace;}
        .rupiah{text-align:right;font-family:"Consolas",monospace;font-weight:700;}
        .laba{text-align:right;font-family:"Consolas",monospace;font-weight:900;color:#b45309;}
        .rugi{text-align:right;font-family:"Consolas",monospace;font-weight:900;color:#991b1b;}
        .titleHead{background:#4338ca;color:#fff;font-weight:900;padding:10px;border:1px solid #312e81;font-size:14px;}
        .rekap td{background:#eef2ff;}
        .rekap b{color:#312e81;}
        .paidOk{color:#065f46;font-weight:900;}
        .paidNo{color:#991b1b;font-weight:900;}
    </style></head><body>';
    echo '<h1>📋 Job Daily Trip Transport</h1>';
    echo '<div class="meta"><strong>The St. Regis Bali — Engineering Dept</strong> | Preset Filter: <b>'.htmlspecialchars($plLabel,ENT_QUOTES,'UTF-8').'</b> | Periode <b>'.$from.' s/d '.$to.'</b> | Search: '.htmlspecialchars($search ?: '-',ENT_QUOTES,'UTF-8').' | Export: '.date('d M Y H:i:s').'</div>';

    echo '<table class="rekap"><thead><tr><th colspan="6" class="titleHead">📊 REKAP PERIODE INI</th></tr></thead><tbody>';
    $rekap = [
        ['🎯 Total Trip (Unique)', $statTrip.' trip', '🛣️ Total Jarak (KM)', number_format($statKM,1,',','.').' KM'],
        ['💰 Harga Jual Customer', 'Rp '.number_format($statJual,0,',','.'), '🧾 Harga Beli Vendor', 'Rp '.number_format($statVendor,0,',','.')],
        ['⭐ Laba Kotor (Jual - Vendor)', '<b>Rp '.number_format($statLaba,0,',','.').'</b>', '🟦 Jumlah Data ditampilkan', count($rows).' baris'],
    ];
    foreach ($rekap as $rr) {
        echo '<tr><td style="width:20%"><b>'.$rr[0].':</b></td><td class="num" style="width:30%">'.$rr[1].'</td><td style="width:20%"><b>'.$rr[2].':</b></td><td class="num" style="width:30%">'.$rr[3].'</td></tr>';
    }
    echo '</tbody></table>';

    $cols = ['No','ID','NO. TRIP','TANGGAL','CUSTOMER','RUTE ASAL','RUTE TUJUAN','PLAT NOMOR','UNIT','SOPIR 1','SOPIR 2','TRIP (x)','KM AWAL','KM AKHIR','TOTAL KM','HARGA JUAL (Rp)','HARGA BELI VENDOR (Rp)','FEE DRIVER (Rp)','LABA / RUGI (Rp)','STATUS TRIP','STATUS BAYAR','CATATAN'];
    echo '<table><thead><tr><th colspan="'.count($cols).'" class="titleHead">🧾 LIST JOB DAILY TRIP ('.count($rows).' DATA)</th></tr><tr>';
    foreach($cols as $c) echo '<th>'._xls3Esc($c).'</th>';
    echo '</tr></thead><tbody>';
    $n = 0;
    foreach ($rows as $r) { $n++;
        $laba = (float)($r['price_sell'] ?? 0) - (float)($r['price_vendor'] ?? 0) - (float)($r['fee_driver'] ?? 0);
        $st = ['draft'=>'DRAFT','in_progress'=>'ON PROGRESS','completed'=>'COMPLETED','cancelled'=>'CANCELLED'][$r['status']] ?? strtoupper((string)$r['status']);
        $pay = strtoupper((string)($r['payment_status'] ?? 'unpaid'));
        $labaCls = $laba >= 0 ? 'laba' : 'rugi';
        echo '<tr>';
        echo '<td class="num">'._xls3Esc($n).'</td>';
        echo '<td class="num">'._xls3Esc((int)$r['id']).'</td>';
        echo '<td><b>'._xls3Esc((string)($r['trip_no'] ?? '#'.$r['id'])).'</b></td>';
        echo '<td>'._xls3Esc((string)($r['trip_date'] ?? '')).'</td>';
        echo '<td>'._xls3Esc((string)($r['customer_name'] ?? '')).'</td>';
        echo '<td>'._xls3Esc((string)($r['route_from'] ?? '')).'</td>';
        echo '<td>'._xls3Esc((string)($r['route_to'] ?? '')).'</td>';
        echo '<td><b style="background:#0369a1;color:#fff;padding:2px 6px;border-radius:4px;">'._xls3Esc(strtoupper((string)($r['plat_no'] ?? ''))).'</b></td>';
        echo '<td>'._xls3Esc((string)($r['vehicle_name'] ?? '')).'</td>';
        echo '<td>'._xls3Esc((string)($r['driver_name'] ?? '')).'</td>';
        echo '<td>'._xls3Esc((string)($r['driver2_name'] ?? '')).'</td>';
        echo '<td class="num">'._xls3Esc((int)($r['trip_count'] ?? 1)).'</td>';
        echo '<td class="num">'._xls3Esc(number_format((float)($r['km_start'] ?? 0),1,',','.')).'</td>';
        echo '<td class="num">'._xls3Esc(number_format((float)($r['km_end'] ?? 0),1,',','.')).'</td>';
        echo '<td class="num"><b>'._xls3Esc(number_format((float)($r['total_km'] ?? 0),1,',','.')).'</b></td>';
        echo '<td class="rupiah" style="color:#065f46;">Rp '._xls3Esc(number_format((float)($r['price_sell'] ?? 0),0,',','.')).'</td>';
        echo '<td class="rupiah" style="color:#991b1b;">Rp '._xls3Esc(number_format((float)($r['price_vendor'] ?? 0),0,',','.')).'</td>';
        echo '<td class="rupiah" style="color:#92400e;">Rp '._xls3Esc(number_format((float)($r['fee_driver'] ?? 0),0,',','.')).'</td>';
        echo '<td class="'.$labaCls.'">Rp '._xls3Esc(number_format($laba,0,',','.')).'</td>';
        echo '<td>'._xls3Esc($st).'</td>';
        $badgeCls = $pay === 'PAID' ? 'paidOk' : 'paidNo';
        echo '<td class="'.$badgeCls.'">'._xls3Esc($pay).'</td>';
        echo '<td>'._xls3Esc(preg_replace('/\s+/',' ',(string)($r['notes'] ?? ''))).'</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

$_GET['_inline_header'] = 1;
$pageHeaderActive = 'job_daily';
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
        <span class="text-slate-800">Job Daily Trip</span>
    </div>
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <span class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center text-white text-lg shadow-md shadow-violet-500/25"><i class="fas fa-clipboard-list"></i></span>
                <div>
                    <h1 class="font-display text-2xl lg:text-3xl font-black text-slate-900 leading-tight">Job Daily Trip Transport</h1>
                    <p class="text-sm text-slate-500 mt-0.5"><?= $pageSubtitle ?></p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <button type="button" onclick="exportExcel()" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-emerald-300 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-sm font-bold shadow-sm transition">
                <i class="fas fa-file-excel"></i> Export Excel
            </button>
            <button type="button" onclick="openTrip(0)" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-sm font-bold shadow-sm transition">
                <i class="fas fa-plus-circle"></i> Tambah Trip
            </button>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center text-indigo-700"><i class="fas fa-clipboard-list"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Total Trip Periode</div></div>
        <div class="text-3xl font-black text-indigo-700 leading-none"><?= $statTrip ?></div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-sky-100 flex items-center justify-center text-sky-700"><i class="fas fa-road"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Total Jarak KM</div></div>
        <div class="text-3xl font-black text-sky-700 leading-none"><?= number_format($statKM,1,',','.') ?><span class="text-sm text-slate-500 ml-1 font-bold">KM</span></div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-700"><i class="fas fa-sack-dollar"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Harga Jual (Revenue)</div></div>
        <div class="text-2xl font-black text-emerald-700 leading-none">Rp <?= number_format($statJual,0,',','.') ?></div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center text-amber-700"><i class="fas fa-hand-holding-dollar"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Laba Kotor (Jual - Vendor)</div></div>
        <div class="text-2xl font-black <?= $statLaba >= 0 ? 'text-amber-700' : 'text-rose-600' ?> leading-none">Rp <?= number_format($statLaba,0,',','.') ?></div>
    </div>
</div>

<div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
    <div class="p-4 lg:p-5 border-b border-slate-100 bg-slate-50/40 space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-xl border border-slate-200">
                <?php $presets = [
                    'today' => ['🔘 Today 1 Trip','bg-white shadow text-slate-900','text-slate-600 hover:text-slate-900'],
                    'driver' => ['🔘 Sopir KM','bg-white shadow text-slate-900','text-slate-600 hover:text-slate-900'],
                    'all'   => ['🔘 All Data','bg-white shadow text-slate-900','text-slate-600 hover:text-slate-900'],
                ];
                foreach($presets as $pKey => $pLabel):
                    $isActive = $preset === $pKey;
                    $cls = $isActive ? $pLabel[1] : $pLabel[2];
                ?>
                    <a href="?preset=<?= $pKey ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="px-3.5 py-1.5 rounded-lg text-sm font-bold transition <?= $cls ?>"><?= $pLabel[0] ?></a>
                <?php endforeach; ?>
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <form method="get" class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                    <input type="hidden" name="preset" value="<?= htmlspecialchars($preset) ?>">
                    <div class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-slate-300 bg-white items-center">
                        <i class="far fa-calendar text-slate-500 text-sm"></i>
                        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="px-2 py-1 rounded text-sm border-0 outline-none w-[135px]">
                        <i class="fas fa-minus text-xs text-slate-400"></i>
                        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="px-2 py-1 rounded text-sm border-0 outline-none w-[135px]">
                    </div>
                    <div class="relative w-full md:w-72">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari No. Trip, Customer, Rute..." class="w-full pl-10 pr-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none transition">
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-bold hover:bg-slate-800 transition">Terapkan</button>
                    <a href="?preset=all" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-bold hover:bg-slate-50 transition">Reset</a>
                </form>
            </div>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[1400px]">
            <thead>
                <tr class="bg-gradient-to-r from-slate-100 to-slate-50 text-slate-700 text-[11px] uppercase tracking-[0.12em] font-black">
                    <th class="px-4 py-3 text-left font-black">Trip & Tanggal</th>
                    <th class="px-4 py-3 text-left font-black">Customer & Rute</th>
                    <th class="px-4 py-3 text-left font-black">Unit & Sopir</th>
                    <th class="px-4 py-3 text-center font-black">Trip</th>
                    <th class="px-4 py-3 text-right font-black">KM</th>
                    <th class="px-4 py-3 text-right font-black">💵 Harga Jual</th>
                    <th class="px-4 py-3 text-right font-black">🧾 Harga Beli Vendor</th>
                    <th class="px-4 py-3 text-right font-black">💳 Fee Driver</th>
                    <th class="px-4 py-3 text-right font-black">🟩 Laba</th>
                    <th class="px-4 py-3 text-center font-black">Status</th>
                    <th class="px-4 py-3 text-center font-black w-32">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if (count($rows) === 0): ?>
                <tr><td colspan="11" class="px-5 py-12 text-center">
                    <div class="mx-auto w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-3"><i class="fas fa-clipboard-list text-slate-400 text-2xl"></i></div>
                    <p class="text-slate-600 font-semibold">Belum ada data Job Trip di periode ini.</p>
                    <button type="button" onclick="openTrip(0)" class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold"><i class="fas fa-plus-circle"></i> Tambah Trip Pertama</button>
                </td></tr>
            <?php else: foreach ($rows as $r):
                $laba = (float)($r['price_sell'] ?? 0) - (float)($r['price_vendor'] ?? 0) - (float)($r['fee_driver'] ?? 0);
                $sClass = ['draft'=>'bg-slate-100 border-slate-200 text-slate-700','in_progress'=>'bg-amber-50 border-amber-200 text-amber-700','completed'=>'bg-emerald-50 border-emerald-200 text-emerald-700','cancelled'=>'bg-rose-50 border-rose-200 text-rose-700'][$r['status']] ?? 'bg-slate-100 border-slate-200 text-slate-600';
                $pClass = ((string)($r['payment_status'] ?? '') === 'paid') ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-rose-50 border-rose-200 text-rose-700';
            ?>
                <tr class="hover:bg-slate-50/60 transition">
                    <td class="px-4 py-3">
                        <div>
                            <p class="font-mono font-black text-indigo-700 text-sm"><?= cleanInput($r['trip_no'] ?? ('#'.$r['id'])) ?></p>
                            <p class="text-xs text-slate-600 mt-0.5"><i class="far fa-calendar text-slate-400 mr-1"></i><?= (new DateTime($r['trip_date']))->format('d M Y') ?></p>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <?php if (strlen($r['customer_name'] ?? '') > 0): ?>
                            <p class="font-bold text-slate-900"><?= cleanInput($r['customer_name']) ?></p>
                        <?php else: ?>
                            <p class="text-xs text-slate-500 font-semibold">—</p>
                        <?php endif; ?>
                        <p class="text-[12px] text-slate-600 mt-0.5">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-white border border-slate-200 text-slate-700">
                                <i class="fas fa-location-dot text-slate-400 text-[10px]"></i><?= cleanInput($r['route_from'] ?? '-') ?>
                                <i class="fas fa-arrow-right text-indigo-500 text-[10px] mx-0.5"></i>
                                <i class="fas fa-flag-checkered text-emerald-600 text-[10px]"></i><?= cleanInput($r['route_to'] ?? '-') ?>
                            </span>
                        </p>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <?php if (strlen($r['plat_no'] ?? '') > 0): ?>
                            <p><span class="px-2 py-0.5 rounded bg-sky-100 border border-sky-200 text-sky-800 font-black text-[11px] mr-1"><?= cleanInput($r['plat_no']) ?></span> <span class="text-slate-700"><?= cleanInput($r['vehicle_name'] ?? '') ?></span></p>
                        <?php endif; ?>
                        <?php if (strlen($r['driver_name'] ?? '') > 0): ?>
                            <p class="text-[12px] text-slate-700 mt-1"><i class="fas fa-id-card text-slate-400 mr-1 text-[11px]"></i><?= cleanInput($r['driver_name']) ?><?php if (strlen($r['driver2_name'] ?? '') > 0): ?> <span class="mx-1 text-slate-400">+</span> <?= cleanInput($r['driver2_name']) ?><?php endif; ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-700 font-black text-xs"><?= (int)$r['trip_count'] ?>x</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <p class="font-mono font-bold text-slate-800"><?= number_format((float)($r['total_km'] ?? 0),1,',','.') ?></p>
                        <?php if ((float)($r['km_start'] ?? 0) > 0 || (float)($r['km_end'] ?? 0) > 0): ?>
                            <p class="text-[10px] text-slate-500 font-mono mt-0.5"><?= number_format((float)($r['km_start'] ?? 0),0,',','.') ?> → <?= number_format((float)($r['km_end'] ?? 0),0,',','.') ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <p class="font-black text-emerald-700">Rp <?= number_format((float)($r['price_sell'] ?? 0),0,',','.') ?></p>
                        <p class="text-[10px] text-emerald-600/70 font-bold uppercase tracking-wide mt-0.5">Jual / Customer</p>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <p class="font-black text-rose-700">Rp <?= number_format((float)($r['price_vendor'] ?? 0),0,',','.') ?></p>
                        <p class="text-[10px] text-rose-600/70 font-bold uppercase tracking-wide mt-0.5">Beli Vendor (sesuai kertas)</p>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <p class="font-bold text-slate-700">Rp <?= number_format((float)($r['fee_driver'] ?? 0),0,',','.') ?></p>
                        <p class="text-[10px] text-slate-500 font-bold uppercase tracking-wide mt-0.5">Gaji / Fee Sopir</p>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <p class="font-black text-lg <?= $laba >= 0 ? 'text-amber-600' : 'text-rose-600' ?>">Rp <?= number_format($laba,0,',','.') ?></p>
                        <p class="text-[10px] text-slate-500 font-bold uppercase tracking-wide mt-0.5"><?= $laba >= 0 ? 'Laba' : 'Rugi' ?> (Jual - Vendor - Driver)</p>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex flex-col items-center gap-1">
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border text-[11px] font-bold <?= $sClass ?>">
                                <i class="fas fa-flag text-[10px]"></i> <?= ucfirst(str_replace('_',' ',$r['status'])) ?>
                            </span>
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border text-[11px] font-bold <?= $pClass ?>">
                                <i class="fas <?= ((string)($r['payment_status'] ?? '') === 'paid') ? 'fa-check' : 'fa-clock' ?> text-[10px]"></i> <?= strtoupper($r['payment_status']) ?>
                            </span>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-center gap-1.5">
                            <button type="button" onclick='openTrip(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS) ?>)' class="w-9 h-9 rounded-lg border border-slate-300 hover:bg-indigo-50 hover:border-indigo-300 text-slate-600 hover:text-indigo-700 flex items-center justify-center transition" title="Edit Trip"><i class="fas fa-pen-to-square text-[14px]"></i></button>
                            <form method="post" onsubmit="return confirm('Hapus trip ini?')" class="inline">
                                <input type="hidden" name="action" value="del">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="w-9 h-9 rounded-lg border border-slate-300 hover:bg-rose-50 hover:border-rose-300 text-slate-600 hover:text-rose-700 flex items-center justify-center transition" title="Hapus"><i class="fas fa-trash text-[13px]"></i></button>
                            </form>
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
function exportExcel(){
    const params = new URLSearchParams(window.location.search);
    params.set('export', '1');
    window.location.href = '?' + params.toString();
}
function openTrip(data){
    const isNew = typeof data === 'number' && data === 0;
    const d = isNew ? {id:0,trip_no:'',trip_date:'<?= date('Y-m-d') ?>',customer_name:'',route_from:'',route_to:'',vehicle_id:0,driver_id:0,driver2_id:0,km_start:0,km_end:0,total_km:0,trip_count:1,price_sell:0,price_vendor:0,fee_driver:0,status:'completed',payment_status:'unpaid',notes:''} : data;
    document.getElementById('j_id').value = +d.id || 0;
    document.getElementById('j_no').value = d.trip_no || '';
    document.getElementById('j_date').value = d.trip_date || '<?= date('Y-m-d') ?>';
    document.getElementById('j_cust').value = d.customer_name || '';
    document.getElementById('j_from').value = d.route_from || '';
    document.getElementById('j_to').value = d.route_to || '';
    document.getElementById('j_vid').value = +d.vehicle_id || 0;
    document.getElementById('j_did').value = +d.driver_id || 0;
    document.getElementById('j_d2id').value = +d.driver2_id || 0;
    document.getElementById('j_kms').value = +d.km_start || 0;
    document.getElementById('j_kme').value = +d.km_end || 0;
    document.getElementById('j_km').value  = +d.total_km || 0;
    document.getElementById('j_tc').value  = +d.trip_count || 1;
    document.getElementById('j_ps').value  = +d.price_sell || 0;
    document.getElementById('j_pv').value  = +d.price_vendor || 0;
    document.getElementById('j_fd').value  = +d.fee_driver || 0;
    document.getElementById('j_st').value  = d.status || 'completed';
    document.getElementById('j_pay').value = d.payment_status || 'unpaid';
    document.getElementById('j_notes').value = d.notes || '';
    document.getElementById('jModalTitle').textContent = isNew ? 'Tambah Job Trip Baru' : ('Edit Trip ' + (d.trip_no || ''));
    document.getElementById('jOverlay').classList.remove('hidden');
    document.getElementById('jModal').classList.remove('hidden');
    setTimeout(()=>{ document.getElementById('j_no').focus(); }, 40);
}
function closeTrip(){ document.getElementById('jOverlay').classList.add('hidden'); document.getElementById('jModal').classList.add('hidden'); }
document.addEventListener('click',(e)=>{ if(e.target.id==='jOverlay') closeTrip(); });
document.getElementById('j_kme').addEventListener('input',calcKm);
document.getElementById('j_kms').addEventListener('input',calcKm);
function calcKm(){
    const s = parseFloat(document.getElementById('j_kms').value) || 0;
    const e = parseFloat(document.getElementById('j_kme').value) || 0;
    if (e > s) document.getElementById('j_km').value = (e - s).toFixed(1);
}
</script>

<div id="jOverlay" class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-sm hidden"></div>
<div id="jModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xl w-full max-w-4xl my-8 overflow-hidden">
        <form method="post" class="flex flex-col max-h-[88vh]">
            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-white via-indigo-50/30 to-white flex items-center justify-between gap-3">
                <div>
                    <h3 id="jModalTitle" class="font-display text-xl font-black text-slate-900">Tambah Job Trip Baru</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Job Daily Trip sesuai catatan kertas: Harga Jual, Harga Beli Vendor, Fee Sopir, & 3 Filter Preset (Today 1 Trip / Sopir KM / All).</p>
                </div>
                <button type="button" onclick="closeTrip()" class="w-9 h-9 rounded-lg bg-slate-100 hover:bg-rose-50 text-slate-500 hover:text-rose-600 flex items-center justify-center transition"><i class="fas fa-times"></i></button>
            </div>
            <div class="px-6 py-5 space-y-4 overflow-y-auto">
                <input type="hidden" name="action" value="save">
                <input type="hidden" id="j_id" name="id" value="0">
                <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                    <div class="md:col-span-2">
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Nomor Trip</label>
                        <input type="text" id="j_no" name="trip_no" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-mono font-bold focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="TRIP-YYYYMMDD-001">
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Tanggal Trip <span class="text-rose-600">*</span></label>
                        <input type="date" id="j_date" name="trip_date" required class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Jumlah Trip</label>
                        <input type="number" id="j_tc" name="trip_count" min="1" step="1" value="1" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-mono font-bold focus:ring-2 focus:ring-indigo-300 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Status Trip</label>
                        <select id="j_st" name="status" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-semibold focus:ring-2 focus:ring-indigo-300 outline-none">
                            <option value="draft">📝 Draft</option>
                            <option value="in_progress">🚚 On Progress</option>
                            <option value="completed">✅ Completed</option>
                            <option value="cancelled">❌ Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Nama Customer</label>
                        <input type="text" id="j_cust" name="customer_name" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="Nama tamu / client">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Rute Lokasi Awal (From)</label>
                        <input type="text" id="j_from" name="route_from" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="St. Regis Nusa Dua">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Rute Tujuan (To)</label>
                        <input type="text" id="j_to" name="route_to" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="Airport DPS">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Kendaraan (Unit)</label>
                        <select id="j_vid" name="vehicle_id" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-semibold focus:ring-2 focus:ring-indigo-300 outline-none">
                            <option value="0">— Pilih Unit —</option>
                            <?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>"><?= cleanInput($v['plat_no']) ?> - <?= cleanInput($v['type_name'] ?? '') ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Sopir Utama (Driver 1)</label>
                        <select id="j_did" name="driver_id" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-semibold focus:ring-2 focus:ring-indigo-300 outline-none">
                            <option value="0">— Pilih Sopir —</option>
                            <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>"><?= cleanInput($d['fullname']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Co-Driver (Driver 2 - optional)</label>
                        <select id="j_d2id" name="driver2_id" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-semibold focus:ring-2 focus:ring-indigo-300 outline-none">
                            <option value="0">— (Kosong) —</option>
                            <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>"><?= cleanInput($d['fullname']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">KM Awal (Start)</label>
                        <input type="number" id="j_kms" name="km_start" step="0.1" min="0" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-mono focus:ring-2 focus:ring-indigo-300 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">KM Akhir (End)</label>
                        <input type="number" id="j_kme" name="km_end" step="0.1" min="0" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-mono focus:ring-2 focus:ring-indigo-300 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Total Jarak (KM)</label>
                        <input type="number" id="j_km" name="total_km" step="0.1" min="0" class="w-full px-3 py-2 rounded-lg border-2 border-sky-300 bg-sky-50 text-sky-900 text-sm font-mono font-bold focus:ring-2 focus:ring-sky-300 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Status Pembayaran</label>
                        <select id="j_pay" name="payment_status" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-semibold focus:ring-2 focus:ring-indigo-300 outline-none">
                            <option value="unpaid">⏳ Belum Dibayar</option>
                            <option value="paid">✅ Sudah Dibayar</option>
                        </select>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-4 space-y-4">
                    <p class="text-[11px] font-black uppercase tracking-wider text-slate-600 flex items-center gap-1.5"><i class="fas fa-cash-register text-emerald-600"></i> Keuangan Trip (sesuai catatan kertas: Harga Jual Customer + Harga Beli Vendor)</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wider text-emerald-700 mb-1.5 block">💵 Harga Jual (Customer) Rp</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 font-bold">Rp</span>
                                <input type="number" id="j_ps" name="price_sell" min="0" step="500" value="0" class="w-full pl-10 pr-3 py-2.5 rounded-lg border border-emerald-300 bg-emerald-50/40 text-emerald-900 text-sm font-mono font-black text-right focus:ring-2 focus:ring-emerald-300 outline-none">
                            </div>
                            <p class="text-[10px] text-emerald-700/70 font-bold uppercase mt-0.5">Harga yang dikenakan ke customer (hotel/tamu)</p>
                        </div>
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wider text-rose-700 mb-1.5 block">🧾 Harga Beli Vendor Rp <span class="font-bold">(sesuai kertas)</span></label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 font-bold">Rp</span>
                                <input type="number" id="j_pv" name="price_vendor" min="0" step="500" value="0" class="w-full pl-10 pr-3 py-2.5 rounded-lg border border-rose-300 bg-rose-50/40 text-rose-900 text-sm font-mono font-black text-right focus:ring-2 focus:ring-rose-300 outline-none">
                            </div>
                            <p class="text-[10px] text-rose-700/70 font-bold uppercase mt-0.5">Biaya sewa bila pakai kendaraan vendor luar</p>
                        </div>
                        <div>
                            <label class="text-xs font-bold uppercase tracking-wider text-amber-700 mb-1.5 block">💳 Fee / Gaji Driver Rp</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 font-bold">Rp</span>
                                <input type="number" id="j_fd" name="fee_driver" min="0" step="500" value="0" class="w-full pl-10 pr-3 py-2.5 rounded-lg border border-amber-300 bg-amber-50/40 text-amber-900 text-sm font-mono font-black text-right focus:ring-2 focus:ring-amber-300 outline-none">
                            </div>
                            <p class="text-[10px] text-amber-700/70 font-bold uppercase mt-0.5">Gaji / insentif sopir per trip</p>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5 block">Catatan / Notes</label>
                    <textarea id="j_notes" name="notes" rows="2" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none" placeholder="Keterangan tambahan..."></textarea>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-slate-100 bg-slate-50/60 flex items-center justify-end gap-2">
                <button type="button" onclick="closeTrip()" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-bold hover:bg-white transition">Batal</button>
                <button type="submit" class="px-5 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-bold shadow-sm transition"><i class="fas fa-save mr-1"></i> Simpan Job Trip</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>