<?php
/**
 * Manager Area: Laporan Keuangan Transport (placeholder sesuai menu ke-4)
 * Summary laba rugi per periode = Σ Jual - Σ Vendor - Σ Gaji Driver
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();
requireRole('manager');

$db = Database::getInstance();

$pageTitle = 'Laporan Keuangan Transport';
$pageSubtitle = 'Ringkasan Laporan Keuangan Trip Transport Hotel per periode. Akan menampilkan pendapatan, pengeluaran vendor, biaya driver, & laba bersih.';

$preset = cleanInput($_GET['preset'] ?? 'month');
$from = cleanInput($_GET['from'] ?? '');
$to   = cleanInput($_GET['to'] ?? '');
if ($from === '') { $from = date('Y-m-01'); }
if ($to === '')   { $to = date('Y-m-d'); }

$where = ['status != ?']; $params = ['cancelled'];
$where[] = 'trip_date BETWEEN ? AND ?'; $params[] = $from; $params[] = $to;
$sqlWhere = implode(' AND ', $where);

$sumRow = $db->fetchOne("SELECT
    COUNT(*) as total_trip,
    COALESCE(SUM(total_km),0) as total_km,
    COALESCE(SUM(trip_count),0) as total_trip_count,
    COALESCE(SUM(price_sell),0) as total_sell,
    COALESCE(SUM(price_vendor),0) as total_vendor,
    COALESCE(SUM(fee_driver),0) as total_driver,
    COALESCE(SUM(CASE WHEN payment_status='paid' THEN price_sell ELSE 0 END),0) as paid_revenue,
    COALESCE(SUM(CASE WHEN payment_status='unpaid' THEN price_sell ELSE 0 END),0) as unpaid_revenue
    FROM transport_job_daily WHERE {$sqlWhere}", $params);

$totalSell   = (float)($sumRow['total_sell'] ?? 0);
$totalVendor = (float)($sumRow['total_vendor'] ?? 0);
$totalDriver = (float)($sumRow['total_driver'] ?? 0);
$labaKotor   = $totalSell - $totalVendor;
$labaBersih  = $totalSell - $totalVendor - $totalDriver;

$driverSum = $db->fetchAll("SELECT
    d.id as driver_id, d.fullname,
    COUNT(j.id) as trip_count,
    COALESCE(SUM(j.total_km),0) as sum_km,
    COALESCE(SUM(j.fee_driver),0) as sum_fee
    FROM transport_drivers d
    LEFT JOIN transport_job_daily j ON (j.driver_id = d.id OR j.driver2_id = d.id) AND j.trip_date BETWEEN ? AND ? AND j.status != ?
    GROUP BY d.id, d.fullname
    ORDER BY sum_km DESC, trip_count DESC
    LIMIT 50", [$from, $to, 'cancelled']);

$vendorSum = $db->fetchAll("SELECT
    v.id, v.type, v.name,
    COALESCE(SUM(CASE WHEN j.vehicle_id IS NOT NULL THEN j.price_vendor ELSE 0 END),0) as vendor_bill
    FROM transport_vendors v
    LEFT JOIN transport_vehicles veh ON veh.owner_id = v.id
    LEFT JOIN transport_job_daily j ON j.vehicle_id = veh.id AND j.trip_date BETWEEN ? AND ? AND j.status != ?
    WHERE v.is_active = 1
    GROUP BY v.id, v.type, v.name
    ORDER BY vendor_bill DESC LIMIT 50", [$from, $to, 'cancelled']);

function _xls4Esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if (isset($_GET['export']) && (string)$_GET['export'] === '1') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    $fname = 'Laporan-Keuangan-Transport-' . date('Ymd-His') . '.xls';
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="UTF-8"><style>
        body{font-family:Calibri,Arial,sans-serif;font-size:12px;color:#111;}
        h1{font-size:20px;font-weight:900;color:#111827;margin:0 0 6px;}
        .meta{font-size:11px;color:#333;margin-bottom:14px;}
        table{border-collapse:collapse;width:100%;margin-bottom:18px;}
        th{background:#312e81;color:#fff;font-weight:900;font-size:11px;text-align:left;padding:7px 9px;border:1px solid #1e1b4b;}
        td{padding:6px 9px;border:1px solid #b4b4b4;vertical-align:top;}
        tr:nth-child(even) td{background:#f5f3ff;}
        .num{text-align:right;font-family:"Consolas",monospace;}
        .rupiah{text-align:right;font-family:"Consolas",monospace;font-weight:700;}
        .titleHead{background:#312e81;color:#fff;font-weight:900;padding:10px 12px;border:1px solid #1e1b4b;font-size:14px;}
        .sHead{background:#1e3a8a;color:#fff;font-weight:900;padding:8px 10px;border:1px solid #1e40af;font-size:13px;}
        .rowBig td{font-weight:900;font-size:13px;background:#c7d2fe !important;color:#1e1b4b;}
        .green{color:#065f46;}
        .red{color:#991b1b;}
        .amber{color:#92400e;}
        .bgLaba{background:#ecfdf5 !important;}
    </style></head><body>';
    echo '<h1>👛 LAPORAN KEUANGAN TRANSPORT</h1>';
    echo '<div class="meta"><strong>The St. Regis Bali — Engineering Dept</strong> | Preset: <b>'.strtoupper($preset).'</b> | Periode <b>'.$from.' s/d '.$to.'</b> | Export: '.date('d M Y H:i:s').'</div>';

    $paidRev  = (float)($sumRow['paid_revenue'] ?? 0);
    $unpaidRev= (float)($sumRow['unpaid_revenue'] ?? 0);
    echo '<table><thead><tr><th colspan="4" class="titleHead">1) 🔢 RINGKASAN KEUANGAN PERIODE INI</th></tr></thead><tbody>';
    $ring = [
        ['Total Trip (completed + draft)', (int)($sumRow['total_trip'] ?? 0), 'Total Trip Count (akumulasi per trip x jumlah)', (int)($sumRow['total_trip_count'] ?? 0)],
        ['Total Jarak (KM)', number_format((float)($sumRow['total_km'] ?? 0),1,',','.'), '—', '—'],
        ['<b>💰 TOTAL PENDAPATAN</b> (Harga Jual Customer)', '<b class="rupiah green">Rp '.number_format($totalSell,0,',','.').'</b>', 'Sudah Dibayar / Paid', '<span class="green"><b>Rp '.number_format($paidRev,0,',','.').'</b></span>'],
        ['—', '—', 'Belum Dibayar / Unpaid', '<span class="red"><b>Rp '.number_format($unpaidRev,0,',','.').'</b></span>'],
        ['<b class="red">🧾 BIAYA BELI KE VENDOR</b>', '<b class="rupiah red">Rp '.number_format($totalVendor,0,',','.').'</b>', '<b class="amber">💳 FEE / GAJI SOPIR</b>', '<b class="rupiah amber">Rp '.number_format($totalDriver,0,',','.').'</b>'],
    ];
    foreach ($ring as $rr) {
        echo '<tr><td style="width:28%">'.$rr[0].'</td><td class="num" style="width:22%">'.$rr[1].'</td><td style="width:28%">'.$rr[2].'</td><td class="num" style="width:22%">'.$rr[3].'</td></tr>';
    }
    echo '<tr class="rowBig"><td>⭐ Laba Kotor (Jual - Vendor)</td><td class="num">Rp '.number_format($labaKotor,0,',','.').'</td><td>🟢 Laba Bersih (Jual - Vendor - Driver)</td><td class="num">Rp '.number_format($labaBersih,0,',','.').'</td></tr>';
    echo '</tbody></table>';

    echo '<table><thead><tr><th colspan="4" class="sHead">2) 👷 STATISTIK SOPIR (Preset: Sopir KM sesuai catatan)</th></tr><tr><th>No</th><th>Nama Sopir</th><th class="num">Total Trip (x)</th><th class="num">Total Jarak (KM)</th><th class="num">Total Fee Driver (Rp)</th></tr></thead><tbody>';
    $n = 0;
    foreach ($driverSum as $dr) {
        if ((int)($dr['trip_count'] ?? 0) === 0 && (float)($dr['sum_km'] ?? 0) === 0) continue;
        $n++;
        echo '<tr>';
        echo '<td class="num">'._xls4Esc($n).'</td>';
        echo '<td><b>'._xls4Esc($dr['fullname']).'</b></td>';
        echo '<td class="num">'._xls4Esc((int)$dr['trip_count']).'</td>';
        echo '<td class="num">'._xls4Esc(number_format((float)($dr['sum_km'] ?? 0),1,',','.')).'</td>';
        echo '<td class="rupiah">Rp '._xls4Esc(number_format((float)($dr['sum_fee'] ?? 0),0,',','.')).'</td>';
        echo '</tr>';
    }
    if ($n === 0) echo '<tr><td colspan="5" style="color:#64748b;">Belum ada data sopir untuk periode ini.</td></tr>';
    echo '</tbody></table>';

    echo '<table><thead><tr><th colspan="4" class="sHead">3) 🏢 TAGIHAN OWNER / VENDOR (Harga Beli ke Vendor per Owner)</th></tr><tr><th>No</th><th>Nama Owner / Vendor</th><th>Tipe</th><th class="num">Total Tagihan Vendor (Rp)</th></tr></thead><tbody>';
    $nv = 0;
    foreach ($vendorSum as $v) {
        if ((float)($v['vendor_bill'] ?? 0) <= 0) continue;
        $nv++;
        $_t = (string)($v['type'] ?? 'vendor');
        if ($_t === 'owner') $tl = '<span class="green"><b>OWNER ASET</b></span>';
        elseif ($_t === 'investor') $tl = '<span class="amber"><b>INVESTOR</b></span>';
        else $tl = '<span class="red"><b>VENDOR KERJASAMA</b></span>';
        echo '<tr>';
        echo '<td class="num">'._xls4Esc($nv).'</td>';
        echo '<td><b>'._xls4Esc($v['name']).'</b></td>';
        echo '<td>'.$tl.'</td>';
        echo '<td class="rupiah">Rp '._xls4Esc(number_format((float)($v['vendor_bill']),0,',','.')).'</td>';
        echo '</tr>';
    }
    if ($nv === 0) echo '<tr><td colspan="4" style="color:#64748b;">Belum ada tagihan vendor untuk periode ini.</td></tr>';
    echo '</tbody></table>';

    echo '</body></html>';
    exit;
}

$_GET['_inline_header'] = 1;
$pageHeaderActive = 'finance_transport';
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
        <span class="text-slate-800">Laporan Keuangan Transport</span>
    </div>
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <span class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white text-lg shadow-md shadow-emerald-500/25"><i class="fas fa-wallet"></i></span>
                <div>
                    <h1 class="font-display text-2xl lg:text-3xl font-black text-slate-900 leading-tight">Laporan Keuangan Transport</h1>
                    <p class="text-sm text-slate-500 mt-0.5"><?= $pageSubtitle ?></p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <button type="button" onclick="window.location.href='?export=1&preset=<?= urlencode($preset) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>'" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-emerald-300 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-sm font-bold shadow-sm transition">
                <i class="fas fa-file-excel"></i> Export Laporan
            </button>
        </div>
    </div>
</div>

<div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
    <div class="p-4 lg:p-5 border-b border-slate-100 bg-slate-50/40 space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-xl border border-slate-200">
                <?php
                $presets = [
                    'today' => ['Today','bg-white shadow text-slate-900','text-slate-600 hover:text-slate-900'],
                    'week'  => ['7 Hari','bg-white shadow text-slate-900','text-slate-600 hover:text-slate-900'],
                    'month' => ['1 Bulan','bg-white shadow text-slate-900','text-slate-600 hover:text-slate-900'],
                    'quarter' => ['3 Bulan','bg-white shadow text-slate-900','text-slate-600 hover:text-slate-900'],
                    'year' => ['Tahun Ini','bg-white shadow text-slate-900','text-slate-600 hover:text-slate-900'],
                    'custom' => ['Custom','bg-white shadow text-slate-900','text-slate-600 hover:text-slate-900'],
                ];
                foreach($presets as $pKey => $pl): $isActive = $preset === $pKey; $cls = $isActive ? $pl[1] : $pl[2]; ?>
                    <a href="?preset=<?= $pKey ?>" class="px-3.5 py-1.5 rounded-lg text-sm font-bold transition <?= $cls ?>"><?= $pl[0] ?></a>
                <?php endforeach; ?>
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <form method="get" class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-slate-300 bg-white items-center">
                        <i class="far fa-calendar text-slate-500 text-sm"></i>
                        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" onchange="this.form.preset.value='custom';" class="px-2 py-1 rounded text-sm border-0 outline-none w-[135px]">
                        <i class="fas fa-minus text-xs text-slate-400"></i>
                        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" onchange="this.form.preset.value='custom';" class="px-2 py-1 rounded text-sm border-0 outline-none w-[135px]">
                    </div>
                    <input type="hidden" name="preset" value="<?= htmlspecialchars($preset) ?>">
                    <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-bold hover:bg-slate-800 transition">Terapkan</button>
                    <a href="?preset=month" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-bold hover:bg-slate-50 transition">Reset</a>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-700"><i class="fas fa-sack-dollar"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Total Pendapatan</div></div>
        <div class="text-3xl font-black text-emerald-700 leading-none">Rp <?= number_format($totalSell,0,',','.') ?></div>
        <div class="flex items-center justify-between text-[11px] mt-2 text-slate-600 font-bold">
            <span class="inline-flex items-center gap-1 text-emerald-700"><i class="fas fa-check-circle"></i> Paid Rp <?= number_format((float)($sumRow['paid_revenue'] ?? 0),0,',','.') ?></span>
            <span class="inline-flex items-center gap-1 text-rose-700"><i class="fas fa-clock"></i> Unpaid Rp <?= number_format((float)($sumRow['unpaid_revenue'] ?? 0),0,',','.') ?></span>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-rose-100 flex items-center justify-center text-rose-700"><i class="fas fa-hand-holding-dollar"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Biaya Vendor Sewa</div></div>
        <div class="text-3xl font-black text-rose-700 leading-none">Rp <?= number_format($totalVendor,0,',','.') ?></div>
        <p class="text-[11px] text-slate-500 mt-2 font-bold">Harga Beli Vendor per periode ini</p>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center text-amber-700"><i class="fas fa-id-card"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Fee / Gaji Sopir</div></div>
        <div class="text-3xl font-black text-amber-700 leading-none">Rp <?= number_format($totalDriver,0,',','.') ?></div>
        <p class="text-[11px] text-slate-500 mt-2 font-bold"><?= (int)($sumRow['total_trip'] ?? 0) ?> Trip · <?= (float)($sumRow['total_km'] ?? 0) ?> KM Total</p>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-900 via-indigo-900 to-slate-900 p-4 shadow-md">
        <div class="flex items-center gap-3 mb-2"><span class="w-10 h-10 rounded-lg bg-white/10 flex items-center justify-center text-white"><i class="fas fa-chart-line"></i></span><div class="text-[11px] font-bold uppercase tracking-wider text-white/70">Laba Bersih</div></div>
        <div class="text-3xl font-black text-white leading-none">Rp <?= number_format($labaBersih,0,',','.') ?></div>
        <p class="text-[11px] mt-2 font-bold text-white/70">Kotor Rp <?= number_format($labaKotor,0,',','.') ?> (Jual - Vendor)</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 bg-slate-50/40 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center text-indigo-700"><i class="fas fa-id-card"></i></span>
                <div>
                    <h3 class="font-black text-slate-900 leading-tight">Statistik Sopir per Periode</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Urut berdasarkan total KM terbanyak (preset: 🔘 Sopir KM sesuai kertas)</p>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto max-h-[420px] overflow-y-auto">
            <table class="w-full text-sm min-w-[520px]">
                <thead class="sticky top-0">
                    <tr class="bg-gradient-to-r from-slate-100 to-slate-50 text-slate-700 text-[11px] uppercase tracking-[0.12em] font-black">
                        <th class="px-4 py-2.5 text-left font-black">Sopir</th>
                        <th class="px-4 py-2.5 text-center font-black">Trip</th>
                        <th class="px-4 py-2.5 text-right font-black">Total KM</th>
                        <th class="px-4 py-2.5 text-right font-black">Fee Driver</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php if (count($driverSum) === 0): ?>
                    <tr><td colspan="4" class="px-5 py-10 text-center text-sm text-slate-500 font-semibold">Belum ada data.</td></tr>
                <?php else: foreach ($driverSum as $dr):
                    if ((int)($dr['trip_count'] ?? 0) === 0 && (float)($dr['sum_km'] ?? 0) === 0) continue;
                ?>
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <span class="w-9 h-9 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white font-black text-[13px] shadow-sm"><?= strtoupper(mb_substr($dr['fullname'],0,1) ?: 'S') ?></span>
                                <p class="font-bold text-slate-900"><?= cleanInput($dr['fullname']) ?></p>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center"><span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-700 font-black text-xs"><?= (int)$dr['trip_count'] ?>x</span></td>
                        <td class="px-4 py-3 text-right font-mono font-black text-sky-700"><?= number_format((float)($dr['sum_km'] ?? 0),1,',','.') ?> <span class="text-[10px] text-slate-500">KM</span></td>
                        <td class="px-4 py-3 text-right font-bold text-amber-700">Rp <?= number_format((float)($dr['sum_fee'] ?? 0),0,',','.') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 bg-slate-50/40 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="w-9 h-9 rounded-lg bg-rose-100 flex items-center justify-center text-rose-700"><i class="fas fa-building-columns"></i></span>
                <div>
                    <h3 class="font-black text-slate-900 leading-tight">Tagihan Owner / Vendor</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Total biaya beli ke vendor per owner / investor periode ini</p>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto max-h-[420px] overflow-y-auto">
            <table class="w-full text-sm min-w-[480px]">
                <thead class="sticky top-0">
                    <tr class="bg-gradient-to-r from-slate-100 to-slate-50 text-slate-700 text-[11px] uppercase tracking-[0.12em] font-black">
                        <th class="px-4 py-2.5 text-left font-black">Owner / Vendor</th>
                        <th class="px-4 py-2.5 text-left font-black">Tipe</th>
                        <th class="px-4 py-2.5 text-right font-black">Total Tagihan Vendor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php if (count($vendorSum) === 0): ?>
                    <tr><td colspan="3" class="px-5 py-10 text-center text-sm text-slate-500 font-semibold">Belum ada data.</td></tr>
                <?php else: foreach ($vendorSum as $v):
                    if ((float)($v['vendor_bill'] ?? 0) <= 0) continue;
                    $_t = (string)($v['type'] ?? 'vendor');
                    if ($_t === 'owner') { $tBg = 'bg-sky-50 border-sky-200 text-sky-700'; $tL = 'Owner'; }
                    elseif ($_t === 'investor') { $tBg = 'bg-violet-50 border-violet-200 text-violet-700'; $tL = 'Investor'; }
                    else { $tBg = 'bg-amber-50 border-amber-200 text-amber-700'; $tL = 'Vendor'; }
                ?>
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="px-4 py-3">
                            <p class="font-bold text-slate-900"><?= cleanInput($v['name']) ?></p>
                        </td>
                        <td class="px-4 py-3"><span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border font-bold text-[11px] <?= $tBg ?>"><?= $tL ?></span></td>
                        <td class="px-4 py-3 text-right font-mono font-black text-rose-700">Rp <?= number_format((float)($v['vendor_bill'] ?? 0),0,',','.') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-start gap-3 p-4 rounded-xl bg-gradient-to-r from-indigo-50 via-white to-indigo-50 border border-indigo-100">
        <div class="w-10 h-10 rounded-xl bg-white shadow-md border border-indigo-100 flex items-center justify-center text-indigo-700 flex-shrink-0"><i class="fas fa-info-circle"></i></div>
        <div>
            <p class="font-black text-slate-900 text-sm">Keterangan Laporan Keuangan Transport (sesuai catatan kertas):</p>
            <ul class="text-[12px] text-slate-600 mt-2 space-y-1 list-disc pl-5 font-semibold leading-relaxed">
                <li>🔘 <strong>Filter Today 1 Trip</strong> → tersedia di Menu Job Daily, menampilkan hanya trip hari ini dengan 1x trip per data.</li>
                <li>🔘 <strong>Filter Sopir KM</strong> → tab statistik sopir di atas, mengurutkan berdasarkan total KM driver (performance sopir).</li>
                <li>🔘 <strong>Filter All Data</strong> → semua data tanpa batas filter (semua periode / semua data).</li>
                <li>Rumus <strong>Laba Bersih = Harga Jual Customer - Harga Beli Vendor - Fee / Gaji Driver</strong>.</li>
            </ul>
        </div>
    </div>
</div>

</div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>