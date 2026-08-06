<?php
/**
 * ⚡ ENERGY DASHBOARD (WA 18.09)
 * Bisa diakses SEMUA ROLE (Manager, Supervisor, Engineer/Staff)
 * Posisi: Sidebar DI BAWAH Dashboard Utama (COLLAPSIBLE)
 * Style DOMINAN PUTIH SLATE NETRAL. Data placeholder dulu, siap diisi logic nanti.
 */

require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Energy Dashboard';
$pageSubtitle = 'Dashboard Ringkasan Konsumsi Energi Harian St. Regis Bali. Listrik, Solar, Gas, Air & Utility Lainnya.';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content px-4 sm:px-6 lg:px-8 py-6 max-w-[1500px] mx-auto">
    <!-- BREADCRUMB -->
    <div class="mb-4 flex items-center gap-2 text-xs font-semibold text-slate-500">
        <a href="<?= BASE_URL ?>index.php" class="hover:text-primary transition"><i class="fas fa-house mr-1"></i> Dashboard</a>
        <i class="fas fa-chevron-right text-[10px] text-slate-400"></i>
        <span class="text-primary font-black">⚡ Energy</span>
    </div>

    <!-- HEADER JUDUL -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 animate-slide-up">
        <div>
            <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-500 mb-1 flex items-center gap-1.5">
                <i class="fas fa-bolt"></i> Divisi Energy &amp; Utility
            </p>
            <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">⚡ Energy Dashboard</h1>
            <p class="text-secondary text-sm">Ringkasan konsumsi listrik, solar, gas, &amp; air bersih. Data <strong class="text-primary">placeholder</strong>, siap diisi logic input per periode tanggal.</p>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-end">
            <a href="<?= BASE_URL ?>energy_logsheet.php" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-card bg-white border border-slate-200 text-slate-700 text-sm font-bold hover:bg-slate-50 hover:-translate-y-0.5 transition shadow-sm">
                <i class="fas fa-table-list text-slate-500"></i>
                Buka Log Sheet
            </a>
        </div>
    </div>

    <!-- FILTER PERIODE TANGGAL (placeholder) -->
    <div class="bg-white rounded-premium border border-slate-200 shadow-sm mb-6 p-4 sm:p-5 animate-slide-up" style="animation-delay: 60ms">
        <div class="flex flex-col md:flex-row md:items-end gap-3">
            <div class="md:w-44">
                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Tanggal Mulai</label>
                <input type="date" value="<?= date('Y-m-01') ?>" class="w-full px-3 py-2.5 rounded-card border border-border bg-muted/50 text-primary text-sm font-bold focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200">
            </div>
            <div class="md:w-44">
                <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Tanggal Akhir</label>
                <input type="date" value="<?= date('Y-m-t') ?>" class="w-full px-3 py-2.5 rounded-card border border-border bg-muted/50 text-primary text-sm font-bold focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200">
            </div>
            <div class="md:w-44">
                <button type="button" disabled class="w-full px-4 py-2.5 rounded-card bg-slate-900 text-white text-sm font-bold shadow-sm opacity-80 cursor-not-allowed inline-flex items-center justify-center gap-2">
                    <i class="fas fa-filter"></i> Terapkan Filter
                </button>
            </div>
            <div class="md:ml-auto">
                <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-700 text-[11px] font-bold">
                    <i class="fas fa-triangle-exclamation"></i>
                    Mode Placeholder: Data statis dulu
                </span>
            </div>
        </div>
    </div>

    <!-- 6 STATISTIC CARDS ENERGY (DOMINAN PUTIH!) -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6 animate-slide-up" style="animation-delay: 80ms">
        <?php
        $energyStats = [
            ['icon'=>'fa-bolt','iconColor'=>'text-amber-500','iconBg'=>'bg-amber-50 border-amber-200','label'=>'Konsumsi Listrik','unit'=>'kWh','val'=>'12,450.25','delta'=>'▲ +3.2%','deltaColor'=>'text-rose-600','subtitle'=>'Bulan Ini'],
            ['icon'=>'fa-droplet','iconColor'=>'text-orange-500','iconBg'=>'bg-orange-50 border-orange-200','label'=>'Konsumsi Solar','unit'=>'Liter','val'=>'2,890.00','delta'=>'▼ -1.8%','deltaColor'=>'text-emerald-600','subtitle'=>'Bulan Ini'],
            ['icon'=>'fa-fire-flame-curved','iconColor'=>'text-red-500','iconBg'=>'bg-red-50 border-red-200','label'=>'Konsumsi Gas LPG','unit'=>'Kg','val'=>'456.5','delta'=>'▲ +0.5%','deltaColor'=>'text-rose-600','subtitle'=>'Bulan Ini'],
            ['icon'=>'fa-faucet','iconColor'=>'text-sky-500','iconBg'=>'bg-sky-50 border-sky-200','label'=>'Konsumsi Air Bersih','unit'=>'m³','val'=>'3,210.8','delta'=>'▼ -4.1%','deltaColor'=>'text-emerald-600','subtitle'=>'Bulan Ini'],
            ['icon'=>'fa-temperature-half','iconColor'=>'text-cyan-500','iconBg'=>'bg-cyan-50 border-cyan-200','label'=>'Suhu Rata-rata Outdoor','unit'=>'°C','val'=>'29.8','delta'=>'→ ±0','deltaColor'=>'text-slate-600','subtitle'=>'Hari Ini'],
            ['icon'=>'fa-solar-panel','iconColor'=>'text-emerald-500','iconBg'=>'bg-emerald-50 border-emerald-200','label'=>'Produksi Solar Panel','unit'=>'kWh','val'=>'0.00','delta'=>'— belum terpasang','deltaColor'=>'text-slate-500','subtitle'=>'Proyeksi 2026'],
        ];
        foreach ($energyStats as $s): ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition">
            <div class="flex items-start justify-between gap-3 mb-3">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.15em] text-slate-500"><?= $s['label'] ?></p>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5"><?= $s['subtitle'] ?></p>
                </div>
                <span class="w-11 h-11 rounded-2xl border-2 <?= $s['iconBg'] ?> flex items-center justify-center <?= $s['iconColor'] ?> shrink-0 shadow-sm">
                    <i class="fas <?= $s['icon'] ?> text-lg"></i>
                </span>
            </div>
            <div class="flex items-end justify-between gap-2">
                <div>
                    <p class="font-display text-3xl font-black text-primary leading-none">
                        <?= $s['val'] ?>
                        <span class="text-sm font-bold text-slate-400 ml-1"><?= $s['unit'] ?></span>
                    </p>
                </div>
                <span class="text-[11px] font-black <?= $s['deltaColor'] ?> bg-white border border-slate-200 px-2 py-1 rounded-full shrink-0">
                    <?= $s['delta'] ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 2 COLUMN: RINGKASAN HARIAN + UTILITY LAIN (PLACEHOLDER CARDS) -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <!-- COL KIRI 2/3: RINGKASAN HARIAN (TABLE PLACEHOLDER) -->
        <div class="lg:col-span-2 bg-white rounded-premium border border-slate-200 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 100ms">
            <div class="flex items-center justify-between p-4 sm:p-5 border-b border-slate-200">
                <div>
                    <h3 class="font-display text-lg font-black text-primary flex items-center gap-2"><i class="fas fa-calendar-day text-slate-500"></i> Catatan Harian Minggu Ini</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Preview 5 entri terakhir Log Sheet Energy.</p>
                </div>
                <a href="<?= BASE_URL ?>energy_logsheet.php" class="text-xs font-bold text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 px-3 py-2 rounded-lg transition shadow-sm inline-flex items-center gap-1.5">
                    Lihat Semua <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[650px]">
                    <thead class="bg-slate-50 border-b-2 border-slate-200">
                        <tr class="text-left text-secondary text-xs">
                            <th class="px-4 sm:px-5 py-3 font-bold whitespace-nowrap">Tanggal</th>
                            <th class="px-4 sm:px-5 py-3 font-bold whitespace-nowrap">Shift</th>
                            <th class="px-4 sm:px-5 py-3 font-bold whitespace-nowrap text-right">Listrik (kWh)</th>
                            <th class="px-4 sm:px-5 py-3 font-bold whitespace-nowrap text-right">Solar (L)</th>
                            <th class="px-4 sm:px-5 py-3 font-bold whitespace-nowrap text-right">Air (m³)</th>
                            <th class="px-4 sm:px-5 py-3 font-bold whitespace-nowrap">PIC</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php
                        $dummyRows = [
                            ['06 Aug 2026','Pagi','1,250.5','210.0','310.2','Pak Wayan'],
                            ['05 Aug 2026','Malam','1,180.0','190.5','298.0','Pak Kadek'],
                            ['05 Aug 2026','Pagi','1,310.8','225.2','321.4','Pak Wayan'],
                            ['04 Aug 2026','Malam','1,105.4','180.0','288.6','Pak Kadek'],
                            ['04 Aug 2026','Pagi','1,288.1','218.5','305.9','Pak Wayan'],
                        ];
                        foreach ($dummyRows as $r): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-4 sm:px-5 py-3 font-semibold text-primary whitespace-nowrap"><?= $r[0] ?></td>
                            <td class="px-4 sm:px-5 py-3">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-100 border border-slate-200 text-slate-700 text-[11px] font-bold">
                                    <i class="fas fa-clock mr-1.5 text-slate-400 text-[10px]"></i> Shift <?= $r[1] ?>
                                </span>
                            </td>
                            <td class="px-4 sm:px-5 py-3 text-right font-mono font-bold text-primary"><?= $r[2] ?></td>
                            <td class="px-4 sm:px-5 py-3 text-right font-mono font-bold text-primary"><?= $r[3] ?></td>
                            <td class="px-4 sm:px-5 py-3 text-right font-mono font-bold text-primary"><?= $r[4] ?></td>
                            <td class="px-4 sm:px-5 py-3 text-xs text-slate-600 font-semibold"><?= $r[5] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- COL KANAN 1/3: CATATAN PENTING + QUICK ACTION -->
        <div class="space-y-4 animate-slide-up" style="animation-delay: 120ms">
            <div class="bg-white rounded-premium border border-slate-200 shadow-sm p-4 sm:p-5">
                <h4 class="font-black text-primary mb-3 flex items-center gap-2"><i class="fas fa-lightbulb text-amber-500"></i> Catatan Quick Reminder</h4>
                <ul class="space-y-2.5 text-sm text-slate-600">
                    <li class="flex items-start gap-2"><i class="fas fa-square-check text-emerald-500 mt-1 text-xs shrink-0"></i> <span>Input Meter Listrik PLN &amp; Genset <strong class="text-primary">setiap pergantian shift</strong>.</span></li>
                    <li class="flex items-start gap-2"><i class="fas fa-square-check text-emerald-500 mt-1 text-xs shrink-0"></i> <span>Cek tekanan pompa air submersible area Selatan jam 07.00 &amp; 19.00.</span></li>
                    <li class="flex items-start gap-2"><i class="fas fa-square-check text-emerald-500 mt-1 text-xs shrink-0"></i> <span>Solar Drum area Depot 1 update stok minimum <strong>250 Liter</strong>.</span></li>
                    <li class="flex items-start gap-2"><i class="fas fa-triangle-exclamation text-amber-500 mt-1 text-xs shrink-0"></i> <span>Maintenance Chiller Area Ballroom schedule Senin depan.</span></li>
                </ul>
            </div>
            <div class="bg-white rounded-premium border border-slate-200 shadow-sm p-4 sm:p-5">
                <h4 class="font-black text-primary mb-3 flex items-center gap-2"><i class="fas fa-bolt text-slate-800"></i> Quick Action</h4>
                <div class="grid grid-cols-2 gap-2">
                    <a href="<?= BASE_URL ?>energy_logsheet.php" class="flex flex-col items-start gap-1.5 p-3 rounded-xl border-2 border-slate-200 hover:border-slate-900 hover:bg-slate-50 transition text-left">
                        <span class="w-9 h-9 rounded-lg bg-slate-900 text-white flex items-center justify-center text-sm"><i class="fas fa-pen"></i></span>
                        <span class="font-bold text-primary text-xs">Input Log Baru</span>
                        <span class="text-[10px] text-slate-500 font-semibold">Tambah entri shift</span>
                    </a>
                    <a href="#" onclick="return false" class="flex flex-col items-start gap-1.5 p-3 rounded-xl border-2 border-slate-200 hover:border-slate-900 hover:bg-slate-50 transition text-left cursor-not-allowed opacity-80">
                        <span class="w-9 h-9 rounded-lg bg-white border border-slate-200 text-slate-500 flex items-center justify-center text-sm"><i class="fas fa-file-pdf"></i></span>
                        <span class="font-bold text-slate-700 text-xs">Export PDF</span>
                        <span class="text-[10px] text-slate-500 font-semibold">Segera hadir</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>