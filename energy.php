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
<div class="main-content px-4 sm:px-6 lg:px-8 py-6 max-w-[1800px] mx-auto">
    <!-- BREADCRUMB -->
    <div class="mb-4 flex items-center gap-2 text-xs font-semibold text-slate-500">
        <a href="<?= BASE_URL ?>index.php" class="hover:text-primary transition"><i class="fas fa-house mr-1"></i> Dashboard</a>
        <i class="fas fa-chevron-right text-[10px] text-slate-400"></i>
        <span class="text-primary font-black">⚡ Energy</span>
    </div>

    <!-- HEADER JUDUL -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 animate-slide-up">
        <div>
            <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">Energy Dashboard</h1>
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
                <label class="text-[11px] sm:text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Tanggal Mulai</label>
                <input type="date" value="<?= date('Y-m-01') ?>" class="w-full px-3 py-2.5 rounded-card border border-border bg-muted/50 text-primary text-sm font-bold focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200">
            </div>
            <div class="md:w-44">
                <label class="text-[11px] sm:text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Tanggal Akhir</label>
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
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-6 gap-4 mb-6 animate-slide-up" style="animation-delay: 80ms">
        <?php
        $energyStats = [
            ['icon'=>'fa-bolt','iconColor'=>'text-amber-500','iconBg'=>'bg-amber-50 border-amber-200','label'=>'Konsumsi Listrik','unit'=>'kWh','val'=>'12,450.25','delta'=>'▲ +3.2%','deltaColor'=>'text-rose-600','subtitle'=>'Bulan Ini'],
            ['icon'=>'fa-droplet','iconColor'=>'text-orange-500','iconBg'=>'bg-orange-50 border-orange-200','label'=>'Konsumsi Solar','unit'=>'Liter','val'=>'2,890.00','delta'=>'▼ -1.8%','deltaColor'=>'text-emerald-600','subtitle'=>'Bulan Ini'],
            ['icon'=>'fa-fire-flame-curved','iconColor'=>'text-red-500','iconBg'=>'bg-red-50 border-red-200','label'=>'Konsumsi Gas LPG','unit'=>'Kg','val'=>'456.5','delta'=>'▲ +0.5%','deltaColor'=>'text-rose-600','subtitle'=>'Bulan Ini'],
            ['icon'=>'fa-faucet','iconColor'=>'text-sky-500','iconBg'=>'bg-sky-50 border-sky-200','label'=>'Konsumsi Air Bersih','unit'=>'m³','val'=>'3,210.8','delta'=>'▼ -4.1%','deltaColor'=>'text-emerald-600','subtitle'=>'Bulan Ini'],
            ['icon'=>'fa-temperature-half','iconColor'=>'text-cyan-500','iconBg'=>'bg-cyan-50 border-cyan-200','label'=>'Suhu Rata-rata Outdoor','unit'=>'°C','val'=>'29.8','delta'=>'→ ±0','deltaColor'=>'text-slate-600','subtitle'=>'Hari Ini'],
            ['icon'=>'fa-solar-panel','iconColor'=>'text-emerald-500','iconBg'=>'bg-emerald-50 border-emerald-200','label'=>'Produksi Solar Panel','unit'=>'kWh','val'=>'450.0','delta'=>'📊 Target Proyeksi','deltaColor'=>'text-emerald-600','subtitle'=>'Proyeksi Harian 2026'],
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
                    <p class="font-display text-2xl sm:text-3xl font-black text-primary leading-none">
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
        <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 100ms">
            <div class="flex items-center justify-between p-4 sm:p-5 border-b border-slate-200">
                <div>
                    <h3 class="font-display text-lg font-black text-primary flex items-center gap-2"><i class="fas fa-calendar-day text-slate-500"></i> Catatan Harian Minggu Ini</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Preview 5 entri terakhir Log Sheet Energy.</p>
                </div>
                <a href="<?= BASE_URL ?>energy_logsheet.php" class="text-xs font-bold text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 px-3 py-2 rounded-lg transition shadow-sm inline-flex items-center gap-1.5">
                    Lihat Semua <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
            </div>
            <div class="overflow-x-auto pb-3 pr-2">
                <table class="w-full text-sm min-w-[650px] table-auto">
                    <thead class="bg-slate-50 border-b-2 border-slate-200">
                        <tr class="text-left text-secondary text-xs">
                            <th class="px-3 sm:px-4 py-3 font-bold whitespace-nowrap">Tanggal</th>
                            <th class="px-3 sm:px-4 py-3 font-bold whitespace-nowrap w-[150px]">Shift</th>
                            <th class="px-3 sm:px-4 py-3 font-bold whitespace-nowrap text-right w-[130px]">Listrik (kWh)</th>
                            <th class="px-3 sm:px-4 py-3 font-bold whitespace-nowrap text-right w-[110px]">Solar (L)</th>
                            <th class="px-3 sm:px-4 py-3 font-bold whitespace-nowrap text-right w-[105px]">Air (m³)</th>
                            <th class="px-3 sm:px-4 py-3 font-bold whitespace-nowrap w-[140px]">PIC</th>
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
                            <td class="px-3 sm:px-4 py-3 font-semibold text-primary whitespace-nowrap"><?= $r[0] ?></td>
                            <td class="px-3 sm:px-4 py-3">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-100 border border-slate-200 text-slate-700 text-[11px] font-bold">
                                    <i class="fas fa-clock mr-1.5 text-slate-400 text-[10px]"></i> Shift <?= $r[1] ?>
                                </span>
                            </td>
                            <td class="px-3 sm:px-4 py-3 text-right font-mono font-bold text-primary tabular-nums"><?= $r[2] ?></td>
                            <td class="px-3 sm:px-4 py-3 text-right font-mono font-bold text-primary tabular-nums"><?= $r[3] ?></td>
                            <td class="px-3 sm:px-4 py-3 text-right font-mono font-bold text-primary tabular-nums"><?= $r[4] ?></td>
                            <td class="px-3 sm:px-4 py-3 text-xs text-slate-600 font-semibold whitespace-nowrap"><?= $r[5] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- COL KANAN 1/3: PRODUKSI SOLAR PANEL (STATUS BELUM TERPASANG) + QUICK ACTION -->
        <div class="lg:col-span-1 flex flex-col gap-4 animate-slide-up" style="animation-delay: 130ms">

            <!-- CARD 1: PRODUKSI SOLAR PANEL -->
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="px-4 sm:px-5 pt-4 pb-2 border-b border-slate-200 flex items-center justify-between gap-2 flex-wrap">
                    <div class="flex items-center gap-2">
                        <span class="w-9 h-9 rounded-2xl bg-emerald-50 border border-emerald-200 flex items-center justify-center text-emerald-600">
                            <i class="fas fa-solar-panel text-lg"></i>
                        </span>
                        <div>
                            <h3 class="font-display text-lg font-black text-slate-900">Produksi Solar Panel</h3>
                            <p class="text-[11px] font-bold text-slate-500 mt-0.5">Status Pemasangan 2026</p>
                        </div>
                    </div>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-50 border border-amber-200 text-amber-700 text-[10px] font-black uppercase tracking-wider shrink-0">
                        <i class="fas fa-hourglass-half text-amber-500 mr-0.5"></i> Belum Terpasang
                    </span>
                </div>
                <div class="px-4 sm:px-5 py-4">
                    <!-- Big Visual Produksi Proyeksi -->
                    <div class="rounded-xl bg-gradient-to-br from-emerald-50 via-white to-slate-50 border border-emerald-100/70 p-3 sm:p-4 mb-3">
                        <div class="flex items-end justify-between gap-3 mb-3">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Estimasi Produksi Hari Ini</p>
                                <p class="font-display text-2xl sm:text-3xl font-black text-emerald-700 leading-none mt-1">450.0<span class="text-base font-bold text-slate-400 ml-1">kWh</span></p>
                            </div>
                            <div class="w-14 h-14 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center shrink-0">
                                <i class="fas fa-chart-line text-emerald-500 text-2xl"></i>
                            </div>
                        </div>
                        <!-- Progress Target Pemasangan -->
                        <div class="mb-2">
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-[11px] font-bold text-slate-600">Progress Pemasangan</span>
                                <span class="text-[11px] font-black text-emerald-700">5% dari Target 100 kWp</span>
                            </div>
                            <div class="h-2.5 w-full rounded-full bg-emerald-100 overflow-hidden border border-emerald-200">
                                <div class="h-full w-[5%] rounded-full bg-gradient-to-r from-emerald-500 to-emerald-400"></div>
                            </div>
                        </div>
                        <p class="text-[11px] text-slate-600 font-semibold mt-2.5 flex items-start gap-1.5">
                            <i class="fas fa-circle-info text-emerald-500 mt-0.5"></i>
                            <span>Tahap Survey lokasi rooftop selesai. Vendor panel 450W akan melakukan pemasangan bulan September 2026.</span>
                        </p>
                    </div>

                    <!-- 3 Mini Stat Estimasi -->
                    <div class="grid grid-cols-3 gap-2 mb-3">
                        <div class="rounded-xl bg-white border border-slate-200 p-2 text-center">
                            <p class="text-[9px] font-black uppercase tracking-wider text-slate-400 leading-tight">Estimasi<br>Harian</p>
                            <p class="font-mono font-black text-sm text-slate-800 mt-1">450 <span class="text-[9px] font-bold text-slate-400">kWh</span></p>
                        </div>
                        <div class="rounded-xl bg-white border border-slate-200 p-2 text-center">
                            <p class="text-[9px] font-black uppercase tracking-wider text-slate-400 leading-tight">Estimasi<br>Bulanan</p>
                            <p class="font-mono font-black text-sm text-slate-800 mt-1">13.5 <span class="text-[9px] font-bold text-slate-400">MWh</span></p>
                        </div>
                        <div class="rounded-xl bg-white border border-slate-200 p-2 text-center">
                            <p class="text-[9px] font-black uppercase tracking-wider text-slate-400 leading-tight">Hemat<br>Listrik</p>
                            <p class="font-mono font-black text-sm text-emerald-700 mt-1">32<span class="text-[9px] font-bold text-emerald-500 ml-0.5">%</span></p>
                        </div>
                    </div>

                    <button type="button" onclick="alert('Setup Solar Panel: fitur konfigurasi target produksi & vendor segera hadir!')" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold shadow-md hover:shadow-lg hover:-translate-y-0.5 transition">
                        <i class="fas fa-gear"></i> Setup Panel Solar
                    </button>
                </div>
            </div>

            <!-- CARD 2: QUICK ACTIONS UTILITY -->
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="px-4 sm:px-5 pt-4 pb-2 border-b border-slate-200 flex items-center justify-between">
                    <h3 class="font-display text-lg font-black text-primary flex items-center gap-2">
                        <i class="fas fa-bolt-lightning text-amber-500"></i> Quick Actions
                    </h3>
                </div>
                <div class="px-3 sm:px-4 py-3 grid grid-cols-2 gap-2">
                    <button type="button" onclick="alert('Check Meter Listrik PLN/Genset')" class="inline-flex flex-col items-center justify-center gap-1.5 px-2.5 py-3 rounded-xl bg-amber-50 hover:bg-amber-100 border border-amber-200 text-amber-800 transition hover:-translate-y-0.5">
                        <i class="fas fa-gauge-high text-lg"></i>
                        <span class="text-[11px] font-black leading-tight text-center">Check Meter</span>
                    </button>
                    <button type="button" onclick="alert('Lapor Kebocoran Pipa / Solar')" class="inline-flex flex-col items-center justify-center gap-1.5 px-2.5 py-3 rounded-xl bg-sky-50 hover:bg-sky-100 border border-sky-200 text-sky-800 transition hover:-translate-y-0.5">
                        <i class="fas fa-droplet text-lg"></i>
                        <span class="text-[11px] font-black leading-tight text-center">Lapor Bocor</span>
                    </button>
                    <button type="button" onclick="alert('Reset Shift Energy Counter')" class="inline-flex flex-col items-center justify-center gap-1.5 px-2.5 py-3 rounded-xl bg-slate-50 hover:bg-slate-100 border border-slate-200 text-slate-800 transition hover:-translate-y-0.5">
                        <i class="fas fa-rotate-right text-lg"></i>
                        <span class="text-[11px] font-black leading-tight text-center">Reset Shift</span>
                    </button>
                    <a href="<?= BASE_URL ?>energy_logsheet.php" class="inline-flex flex-col items-center justify-center gap-1.5 px-2.5 py-3 rounded-xl bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-800 transition hover:-translate-y-0.5">
                        <i class="fas fa-file-circle-plus text-lg"></i>
                        <span class="text-[11px] font-black leading-tight text-center">Log Baru</span>
                    </a>
                </div>
                <div class="px-4 sm:px-5 py-3 border-t border-slate-100 bg-slate-50/50">
                    <p class="text-[11px] text-slate-500 font-semibold flex items-start gap-1.5">
                        <i class="fas fa-lightbulb text-amber-500 mt-0.5"></i>
                        <span>Catatan Shift: Untuk lapor anomali tekanan air / suhu AC chiller, gunakan tombol Lapor Bocor di atas.</span>
                    </p>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>