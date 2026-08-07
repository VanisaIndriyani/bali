<?php
/**
 * 📋 ENERGY LOG SHEET (WA 18.09) - Form & List Catatan Konsumsi Energi
 * Bisa diakses SEMUA ROLE (Manager, Supervisor, Engineer/Staff)
 * Posisi: Sidebar ⚡ Energy > submenu 📋 Log Sheet
 * Style DOMINAN PUTIH SLATE NETRAL. Placeholder dulu, siap CRUD logic nanti.
 */

require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Energy Log Sheet';
$pageSubtitle = 'Form Input & Daftar Catatan Konsumsi Energi Harian (Listrik, Solar, Gas, Air).';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content px-4 sm:px-6 lg:px-8 py-6 pb-16 max-w-[1800px] mx-auto">
    <!-- BREADCRUMB -->
    <div class="mb-4 flex items-center gap-2 text-xs font-semibold text-slate-500">
        <a href="<?= BASE_URL ?>index.php" class="hover:text-primary transition"><i class="fas fa-house mr-1"></i> Dashboard</a>
        <i class="fas fa-chevron-right text-[10px] text-slate-400"></i>
        <a href="<?= BASE_URL ?>energy.php" class="hover:text-primary transition">Energy</a>
        <i class="fas fa-chevron-right text-[10px] text-slate-400"></i>
        <span class="text-primary font-black">Log Sheet</span>
    </div>

    <!-- HEADER JUDUL -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 animate-slide-up">
        <div>
            <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1">Energy Log Sheet</h1>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-end flex-wrap">
            <a href="<?= BASE_URL ?>energy.php" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-card bg-white border border-slate-200 text-slate-700 text-sm font-bold hover:bg-slate-50 hover:-translate-y-0.5 transition shadow-sm">
                <i class="fas fa-chart-line text-slate-500"></i>
                Kembali ke Dashboard
            </a>
            <button type="button" onclick="alert('Fitur Input Log Sheet CRUD segera hadir! Saat ini mode placeholder data statis.')"
                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-card bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold shadow-md hover:shadow-lg hover:-translate-y-0.5 transition">
                <i class="fas fa-plus text-lg"></i>
                + Tambah Log Baru
            </button>
        </div>
    </div>

    <!-- FILTER + TOMBOL EXPORT (placeholder) -->
    <div class="bg-white rounded-premium border border-slate-200 shadow-sm mb-6 p-4 sm:p-5 animate-slide-up" style="animation-delay: 60ms">
        <div class="flex flex-col md:flex-row md:items-end gap-3 flex-wrap">
            <div class="md:w-44">
                <label class="text-[11px] sm:text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Tanggal</label>
                <input type="date" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2.5 rounded-card border border-border bg-muted/50 text-primary text-sm font-bold focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200">
            </div>
            <div class="md:w-44">
                <label class="text-[11px] sm:text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">s/d Tanggal (Opsional)</label>
                <input type="date" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2.5 rounded-card border border-border bg-muted/50 text-primary text-sm font-bold focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200">
            </div>
            <div class="md:w-44">
                <label class="text-[11px] sm:text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1.5 block">Shift</label>
                <select class="w-full px-3 py-2.5 rounded-card border border-border bg-muted/50 text-primary text-sm font-bold focus:outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200">
                    <option value="">Semua Shift</option>
                    <option>Pagi (07.00 - 15.00)</option>
                    <option>Siang (15.00 - 23.00)</option>
                    <option>Malam (23.00 - 07.00)</option>
                </select>
            </div>
            <div class="md:w-auto">
                <button type="button" disabled class="w-full md:w-auto px-4 py-2.5 rounded-card bg-slate-900 text-white text-sm font-bold shadow-sm opacity-80 cursor-not-allowed inline-flex items-center justify-center gap-2">
                    <i class="fas fa-filter"></i> Terapkan
                </button>
            </div>
            <div class="md:ml-auto flex items-center gap-2">
                <button type="button" onclick="alert('Export segera hadir!')" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-white text-slate-700 text-xs font-bold border border-slate-200 hover:bg-slate-50 transition shadow-sm"><i class="fas fa-file-pdf text-rose-500"></i> PDF</button>
                <button type="button" onclick="alert('Export segera hadir!')" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-white text-slate-700 text-xs font-bold border border-slate-200 hover:bg-slate-50 transition shadow-sm"><i class="fas fa-file-excel text-emerald-500"></i> Excel</button>
                <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-700 text-[11px] font-bold">
                    <i class="fas fa-triangle-exclamation"></i>
                    Placeholder Data
                </span>
            </div>
        </div>
    </div>

    <!-- 6 QUICK INFO CARDS MINI (FILTER RESULT) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-6 gap-3 mb-6">
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Total Entri</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1">186</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Total kWh</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1">62.847,5</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Total Solar</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1">14.520,0 L</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Total Gas</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1">2.300,0 Kg</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Total Air</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1">16.185,4 m³</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">PIC Aktif</p>
            <p class="font-display text-xl sm:text-2xl font-black text-primary mt-1">5 Orang</p>
        </div>
    </div>

    <!-- TABLE LIST LOG SHEET (DATA STATIS + PLACEHOLDER) -->
    <div class="bg-white rounded-premium border border-slate-200 shadow-sm overflow-hidden mb-6 animate-slide-up" style="animation-delay: 90ms">
        <div class="px-3 sm:px-5 py-2.5 text-[11px] sm:text-xs text-slate-500 italic font-semibold flex flex-wrap items-center gap-1.5 bg-slate-50/70 border-b border-slate-100">
            <i class="fas fa-hand-point-right text-indigo-500 flex-shrink-0"></i>
            <span class="flex-shrink">Scroll ke kanan untuk melihat kolom <strong class="not-italic text-slate-700">PIC</strong> &amp; <strong class="not-italic text-slate-700">Aksi</strong> yang ada di sisi kanan tabel</span>
            <i class="fas fa-arrow-right text-indigo-500 flex-shrink-0"></i>
        </div>
        <div class="overflow-x-auto -mx-4 sm:mx-0 px-4 sm:px-0 pb-3 pr-2">
            <table class="w-full text-sm min-w-[950px] table-auto border-collapse">
                <thead class="bg-slate-50 border-b-2 border-slate-200">
                    <tr class="text-left text-secondary text-xs">
                        <th class="px-3 sm:px-4 py-3.5 font-bold whitespace-nowrap w-12 text-center">#</th>
                        <th class="px-3 sm:px-4 py-3.5 font-bold whitespace-nowrap">Tanggal</th>
                        <th class="px-3 sm:px-4 py-3.5 font-bold whitespace-nowrap w-[220px]">Shift</th>
                        <th class="px-3 sm:px-4 py-3.5 font-bold text-right whitespace-nowrap w-[110px]">PLN (kWh)</th>
                        <th class="px-3 sm:px-4 py-3.5 font-bold text-right whitespace-nowrap w-[115px]">Genset (kWh)</th>
                        <th class="px-3 sm:px-4 py-3.5 font-bold text-right whitespace-nowrap w-[105px]">Solar (L)</th>
                        <th class="px-3 sm:px-4 py-3.5 font-bold text-right whitespace-nowrap w-[100px]">Gas (Kg)</th>
                        <th class="px-3 sm:px-4 py-3.5 font-bold text-right whitespace-nowrap w-[100px]">Air (m³)</th>
                        <th class="px-3 sm:px-4 py-3.5 font-bold whitespace-nowrap w-[200px]">PIC</th>
                        <th class="px-3 sm:px-4 py-3.5 pr-3 sm:pr-4 font-bold text-right whitespace-nowrap w-[130px]">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php
                    $logs = [
                        ['06 Aug 2026','Pagi (07.00 - 15.00)','980.5','8.0','145.0','32.5','205.2','Pak Wayan (Engineer)'],
                        ['05 Aug 2026','Malam (23.00 - 07.00)','870.4','15.2','110.5','28.0','182.1','Pak Kadek (Engineer)'],
                        ['05 Aug 2026','Siang (15.00 - 23.00)','1,010.1','12.8','115.2','30.5','188.4','Pak Made (Engineer)'],
                        ['05 Aug 2026','Pagi (07.00 - 15.00)','1,005.0','5.5','140.0','33.0','201.8','Pak Wayan (Engineer)'],
                        ['04 Aug 2026','Malam (23.00 - 07.00)','855.3','10.1','102.0','26.5','178.3','Pak Kadek (Engineer)'],
                        ['04 Aug 2026','Siang (15.00 - 23.00)','995.8','14.2','118.5','29.5','190.1','Pak Made (Engineer)'],
                        ['04 Aug 2026','Pagi (07.00 - 15.00)','1,020.8','6.0','148.2','34.0','208.6','Pak Wayan (Engineer)'],
                        ['03 Aug 2026','Malam (23.00 - 07.00)','845.1','11.3','100.0','25.0','175.0','Pak Kadek (Engineer)'],
                    ];
                    foreach ($logs as $i => $r): ?>
                    <tr class="hover:bg-slate-50 transition-colors group">
                        <td class="px-3 sm:px-4 py-3 text-xs font-bold text-slate-500 align-top text-center"><?= ($i+1) ?>.</td>
                        <td class="px-3 sm:px-4 py-3 font-bold text-primary whitespace-nowrap align-top"><?= $r[0] ?></td>
                        <td class="px-3 sm:px-4 py-3 align-top">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-100 border border-slate-200 text-slate-700 text-[11px] font-bold">
                                <i class="fas fa-clock mr-1.5 text-slate-400 text-[10px]"></i><?= $r[1] ?>
                            </span>
                        </td>
                        <td class="px-3 sm:px-4 py-3 text-right font-mono font-bold text-primary align-top tabular-nums"><?= $r[2] ?></td>
                        <td class="px-3 sm:px-4 py-3 text-right font-mono font-bold text-slate-600 align-top tabular-nums"><?= $r[3] ?></td>
                        <td class="px-3 sm:px-4 py-3 text-right font-mono font-bold text-primary align-top tabular-nums"><?= $r[4] ?></td>
                        <td class="px-3 sm:px-4 py-3 text-right font-mono font-bold text-slate-600 align-top tabular-nums"><?= $r[5] ?></td>
                        <td class="px-3 sm:px-4 py-3 text-right font-mono font-bold text-primary align-top tabular-nums"><?= $r[6] ?></td>
                        <td class="px-3 sm:px-4 py-3 text-xs text-slate-700 font-semibold align-top whitespace-nowrap"><?= $r[7] ?></td>
                        <td class="px-3 sm:px-4 py-3 pr-3 sm:pr-4 text-right whitespace-nowrap align-top">
                            <div class="flex gap-1.5 justify-end items-center">
                                <button type="button" onclick="alert('Edit Log segera hadir!')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-slate-700 border border-slate-200 hover:bg-slate-200 transition" title="Edit">
                                    <i class="fas fa-pencil text-xs"></i>
                                </button>
                                <button type="button" onclick="alert('Lihat Detail segera hadir!')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 transition" title="Lihat Detail">
                                    <i class="fas fa-eye text-xs"></i>
                                </button>
                                <button type="button" onclick="return confirm('Yakin hapus log tanggal <?= $r[0] ?> shift <?= $r[1] ?>? (PLACEHOLDER - belum tersambung DB)')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white text-slate-600 border border-slate-200 hover:bg-rose-50 hover:text-rose-600 hover:border-rose-200 transition" title="Hapus">
                                    <i class="fas fa-trash text-xs"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION PLACEHOLDER -->
        <div class="p-4 sm:p-5 border-t border-slate-200 bg-slate-50 flex flex-col md:flex-row md:items-center justify-between gap-3">
            <p class="text-xs font-semibold text-slate-500">Menampilkan 8 dari 186 entri log sheet.</p>
            <div class="flex items-center gap-1.5">
                <button disabled class="w-9 h-9 rounded-lg bg-white border border-slate-200 text-slate-400 cursor-not-allowed shadow-sm"><i class="fas fa-chevron-left text-[10px]"></i></button>
                <button class="w-9 h-9 rounded-lg bg-slate-900 text-white font-bold shadow-sm text-sm">1</button>
                <button class="w-9 h-9 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 font-bold text-sm shadow-sm">2</button>
                <button class="w-9 h-9 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 font-bold text-sm shadow-sm">3</button>
                <span class="w-9 h-9 flex items-center justify-center text-slate-400 text-xs font-bold">...</span>
                <button class="w-9 h-9 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 font-bold text-sm shadow-sm">24</button>
                <button class="w-9 h-9 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 shadow-sm"><i class="fas fa-chevron-right text-[10px]"></i></button>
            </div>
        </div>
    </div>

    <!-- INFO CARD FOOTER -->
    <div class="bg-white rounded-premium border border-slate-200 shadow-sm p-4 sm:p-5">
        <p class="text-[11px] text-slate-500 flex items-start gap-2">
            <i class="fas fa-circle-info text-slate-400 mt-0.5"></i>
            <span>
                <strong class="text-slate-700">Catatan:</strong> Halaman Log Sheet ini masih <strong>MODE PLACEHOLDER data statis</strong>. Form input, CRUD, koneksi table DB energy_logs &amp; integrasi Logic report Dashboard akan ditambahkan selanjutnya setelah konfirmasi struktur field yang dibutuhkan (jumlah meter per area, kategori utility tambahan dll).
            </span>
        </p>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>