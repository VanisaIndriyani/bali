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
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-6 p-4 sm:p-5 animate-slide-up" style="animation-delay: 60ms">
        <div class="flex flex-col md:flex-row md:items-end gap-3">
            <div class="md:w-44">
                <label class="text-[11px] sm:text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Tanggal Mulai</label>
                <input type="date" value="<?= date('Y-m-01') ?>" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-slate-900 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary/70">
            </div>
            <div class="md:w-44">
                <label class="text-[11px] sm:text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Tanggal Akhir</label>
                <input type="date" value="<?= date('Y-m-t') ?>" class="w-full px-3 py-2.5 rounded-lg border border-slate-200 bg-white text-slate-900 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary/70">
            </div>
            <div class="md:w-44">
                <button type="button" class="w-full px-4 py-2.5 rounded-lg bg-slate-900 text-white text-sm font-bold shadow-sm hover:bg-slate-800 transition inline-flex items-center justify-center gap-2">
                    <i class="fas fa-filter text-xs"></i> Terapkan
                </button>
            </div>
        </div>
    </div>

    <!-- 6 STATISTIC CARDS ENERGY (SIMPEL TANPA ICON RAME, SAMA DENGAN ENERGY LOG SHEET!) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-6 gap-3 mb-6 animate-slide-up" style="animation-delay: 80ms">
        <?php
        $energyStats = [
            ['label'=>'Konsumsi Listrik','unit'=>'kWh','val'=>'12.450,25','sub'=>'Bulan Ini'],
            ['label'=>'Konsumsi Solar','unit'=>'Liter','val'=>'2.890,00','sub'=>'Bulan Ini'],
            ['label'=>'Konsumsi Gas LPG','unit'=>'Kg','val'=>'456,5','sub'=>'Bulan Ini'],
            ['label'=>'Konsumsi Air Bersih','unit'=>'m³','val'=>'3.210,8','sub'=>'Bulan Ini'],
            ['label'=>'Suhu Rata-rata Outdoor','unit'=>'°C','val'=>'29,8','sub'=>'Hari Ini'],
            ['label'=>'Produksi Solar Panel','unit'=>'kWh','val'=>'450,0','sub'=>'Proyeksi Harian'],
        ];
        foreach ($energyStats as $s): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500 leading-tight"><?= $s['label'] ?></p>
            <?php if (!empty($s['sub'])): ?>
            <p class="text-[9px] font-bold text-slate-400 mt-0.5 leading-tight"><?= $s['sub'] ?></p>
            <?php endif; ?>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-2 leading-none">
                <?= $s['val'] ?>
                <span class="text-sm font-bold text-slate-400 ml-1"><?= $s['unit'] ?></span>
            </p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 2 COLUMN: RINGKASAN HARIAN + INFO SOLAR (SIMPEL, TANPA ICON RAME!) -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <!-- COL KIRI 2/3: RINGKASAN HARIAN (TABLE) -->
        <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 100ms">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 sm:p-5 border-b border-slate-200">
                <div>
                    <h3 class="font-display text-lg font-black text-primary">Catatan Harian Minggu Ini</h3>
                    <p class="text-xs text-slate-500 mt-1">Preview 5 entri terakhir Log Sheet Energy.</p>
                </div>
                <a href="<?= BASE_URL ?>energy_logsheet.php" class="text-xs font-bold text-slate-700 bg-slate-50 border border-slate-200 hover:bg-slate-100 px-3 py-2 rounded-lg transition shadow-sm inline-flex items-center justify-center gap-1.5 whitespace-nowrap">
                    Lihat Semua <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
            </div>
            <div class="overflow-x-auto pb-3 pr-2">
                <table class="w-full text-sm min-w-[650px] table-auto">
                    <thead class="bg-slate-50 border-b-2 border-slate-200">
                        <tr class="text-left text-secondary text-xs">
                            <th class="px-3 sm:px-4 py-3 font-bold whitespace-nowrap">Tanggal</th>
                            <th class="px-3 sm:px-4 py-3 font-bold whitespace-nowrap w-[140px]">Shift</th>
                            <th class="px-3 sm:px-4 py-3 font-bold whitespace-nowrap text-right w-[120px]">Listrik (kWh)</th>
                            <th class="px-3 sm:px-4 py-3 font-bold whitespace-nowrap text-right w-[105px]">Solar (L)</th>
                            <th class="px-3 sm:px-4 py-3 font-bold whitespace-nowrap text-right w-[100px]">Air (m³)</th>
                            <th class="px-3 sm:px-4 py-3 font-bold whitespace-nowrap w-[140px]">PIC</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php
                        $dummyRows = [
                            ['06 Aug 2026','Pagi','1.250,5','210,0','310,2','Pak Wayan'],
                            ['05 Aug 2026','Malam','1.180,0','190,5','298,0','Pak Kadek'],
                            ['05 Aug 2026','Pagi','1.310,8','225,2','321,4','Pak Wayan'],
                            ['04 Aug 2026','Malam','1.105,4','180,0','288,6','Pak Kadek'],
                            ['04 Aug 2026','Pagi','1.288,1','218,5','305,9','Pak Wayan'],
                        ];
                        foreach ($dummyRows as $r): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-3 sm:px-4 py-3 font-semibold text-primary whitespace-nowrap"><?= $r[0] ?></td>
                            <td class="px-3 sm:px-4 py-3">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-100 border border-slate-200 text-slate-700 text-[11px] font-semibold">
                                    Shift <?= $r[1] ?>
                                </span>
                            </td>
                            <td class="px-3 sm:px-4 py-3 text-right font-mono font-semibold text-slate-800 tabular-nums"><?= $r[2] ?></td>
                            <td class="px-3 sm:px-4 py-3 text-right font-mono font-semibold text-slate-800 tabular-nums"><?= $r[3] ?></td>
                            <td class="px-3 sm:px-4 py-3 text-right font-mono font-semibold text-slate-800 tabular-nums"><?= $r[4] ?></td>
                            <td class="px-3 sm:px-4 py-3 text-xs text-slate-600 font-semibold whitespace-nowrap"><?= $r[5] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- COL KANAN 1/3: INFO PRODUKSI SOLAR PANEL (SIMPEL TANPA RAME!) -->
        <div class="lg:col-span-1 flex flex-col gap-4 animate-slide-up" style="animation-delay: 130ms">

            <!-- CARD 1: PRODUKSI SOLAR PANEL (SIMPEL!) -->
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="px-4 sm:px-5 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <h3 class="font-display text-base sm:text-lg font-black text-slate-900">Produksi Solar Panel</h3>
                        <p class="text-[11px] font-semibold text-slate-500 mt-0.5">Status Pemasangan 2026</p>
                    </div>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-50 border border-amber-200 text-amber-700 text-[10px] font-black uppercase tracking-wider shrink-0 w-fit">
                        Belum Terpasang
                    </span>
                </div>
                <div class="px-4 sm:px-5 py-4 space-y-4">
                    <!-- Info Proyeksi -->
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5">Estimasi Produksi Harian</p>
                        <p class="font-display text-2xl sm:text-3xl font-black text-slate-900 leading-none">450,0<span class="text-sm font-bold text-slate-400 ml-1">kWh</span></p>
                    </div>

                    <!-- Progress -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-[11px] font-semibold text-slate-600">Progress Pemasangan</span>
                            <span class="text-[11px] font-black text-slate-700">5% dari Target 100 kWp</span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden border border-slate-200">
                            <div class="h-full w-[5%] rounded-full bg-emerald-500"></div>
                        </div>
                    </div>

                    <!-- 3 Mini Stat -->
                    <div class="grid grid-cols-3 gap-2 pt-2">
                        <div class="rounded-lg bg-slate-50 border border-slate-200 p-2.5 text-center">
                            <p class="text-[9px] font-black uppercase tracking-wider text-slate-400 leading-tight">Estimasi<br>Harian</p>
                            <p class="font-mono font-black text-sm text-slate-800 mt-1">450<span class="text-[9px] font-bold text-slate-400 ml-0.5">kWh</span></p>
                        </div>
                        <div class="rounded-lg bg-slate-50 border border-slate-200 p-2.5 text-center">
                            <p class="text-[9px] font-black uppercase tracking-wider text-slate-400 leading-tight">Estimasi<br>Bulanan</p>
                            <p class="font-mono font-black text-sm text-slate-800 mt-1">13,5<span class="text-[9px] font-bold text-slate-400 ml-0.5">MWh</span></p>
                        </div>
                        <div class="rounded-lg bg-slate-50 border border-slate-200 p-2.5 text-center">
                            <p class="text-[9px] font-black uppercase tracking-wider text-slate-400 leading-tight">Hemat<br>Listrik</p>
                            <p class="font-mono font-black text-sm text-slate-800 mt-1">32<span class="text-[9px] font-bold text-slate-500 ml-0.5">%</span></p>
                        </div>
                    </div>

                    <!-- Info Survey -->
                    <p class="text-[11px] text-slate-600 font-semibold pt-1 leading-relaxed">Survey lokasi rooftop selesai. Pemasangan panel 450W dijadwalkan bulan September 2026.</p>
                </div>
            </div>

            <!-- CARD 2: INFO SINGKAT (TANPA TOMBOL WARNA RAME!) -->
            <div class="rounded-xl border border-slate-200 bg-slate-50/50 shadow-sm overflow-hidden">
                <div class="px-4 sm:px-5 py-3.5 border-b border-slate-200 bg-white">
                    <h3 class="font-display text-base font-black text-slate-900">Catatan Shift</h3>
                </div>
                <div class="px-4 sm:px-5 py-3.5 space-y-2">
                    <p class="text-[11px] text-slate-600 font-semibold leading-relaxed flex items-start gap-1.5">
                        <i class="fas fa-check-circle text-emerald-600 mt-0.5 shrink-0"></i>
                        <span>Pastikan semua meter utility (listrik PLN/Genset, solar, gas, air) dicatat setiap pergantian shift.</span>
                    </p>
                    <p class="text-[11px] text-slate-600 font-semibold leading-relaxed flex items-start gap-1.5">
                        <i class="fas fa-exclamation-circle text-amber-600 mt-0.5 shrink-0"></i>
                        <span>Jika ditemukan anomali (tekanan air turun, bau gas, kebocoran pipa solar), segera laporkan ke Supervisor.</span>
                    </p>
                    <p class="text-[11px] text-slate-600 font-semibold leading-relaxed flex items-start gap-1.5">
                        <i class="fas fa-info-circle text-sky-600 mt-0.5 shrink-0"></i>
                        <span>Input data energi harian melalui menu Log Sheet di sidebar Energy, atau klik tombol "Buka Log Sheet" di kanan atas.</span>
                    </p>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>