<?php
/**
 * ┌──────────────────────────────────────────────────────────────┐
 * │  📋 SOP INPUT DATA LOG HARIAN HISTORIS (BACKFILL TAHUN 2025) │
 * │  Berlaku tanggal 1 s.d 31 Agt 2025 dll yg blm di DB          │
 * └──────────────────────────────────────────────────────────────┘
 *
 * ─────────────────────────────────────
 * LANGKAH 1 — BUKA FORM DENGAN TANGGAL YANG TEPAT
 * ─────────────────────────────────────
 * ✅ Login engineer. Menu → Daily Log
 * ✅ Di Select Date, PILIH TANGGAL = 16/08/2025 (BUKAN tanggal hari ini 2026)
 *    Contoh URL: daily_log_form.php?date=2025-08-16
 * ✅ Pilih Engineer yang mau di-backfill data-nya
 * ✅ Pilih Shift = MALAM (karena shift malam total 1 hari)
 *    Atau mau isi 3 shift (Pagi/Siang/Malam) masing-masing 1 log juga boleh
 *
 * ─────────────────────────────────────
 * LANGKAH 2 — KLIK ✏️ DI YESTERDAY (PENTING! WAJIB UNTUK BACKFILL)
 * ─────────────────────────────────────
 * Saat backfill tahun 2025: "Yesterday" otomatis ambil dari DB tgl 15/08/2025.
 * Kebanyakan data tgl 15/08/2025 BELUM ADA → Yesterday = 0 → selisih JAUH SALAH.
 *
 * ✅ SOLUSI: DI 3 TEMPAT INI WAJIB KLIK ✏️ LALU ISI SENDIRI:
 *    1. KWH WBP    → Yesterday (15/08/2025): klik ✏️ → input angka reading kemarin
 *    2. KWH LWBP   → Yesterday (15/08/2025): klik ✏️ → input angka reading kemarin
 *    3. WATER Main Building → Kemarin: klik ✏️ → input angka reading kemarin
 * Setelah klik ✏️, tombol jadi 🔒 Lock (warna indigo, input jadi putih bisa diketik).
 *
 * ─────────────────────────────────────
 * LANGKAH 3 — ISI TODAY VALUE SEMUA FIELD (WBP, LWBP, WATER, GAS, FUEL, DLL)
 * ─────────────────────────────────────
 * Isi Today WBP = reading akhir tanggal 16/08/2025.
 * Isi Today LWBP = reading akhir tanggal 16/08/2025.
 * Isi lainnya seperti hari-hari biasa (Water MB, PDAM, IKI, DW1, DW2, dll; Gas; Fuel).
 *
 * ─────────────────────────────────────
 * LANGKAH 4 — CEK LIVE SUMMARY
 * ─────────────────────────────────────
 * 🔴 PENTING: SEBELUM KLIK SUBMIT → Pastikan angka di card "LIVE SUMMARY HASIL AKHIR"
 *    (Total Listrik, Total Air, dll) COCOK dengan hitungan manual!
 *    Contoh gambar customer: TOTAL LISTRIK = 26240,00 kWh ✅
 *
 *    Jika angka summary 0 padahal form di-bawah sudah ada isinya → coba refresh 1x
 *    (fallback timeout 300ms sudah auto-recompute. Tap di WBP/LWBP mana saja → auto recalc).
 *
 * ─────────────────────────────────────
 * LANGKAH 5 — KLIK SUBMIT
 * ─────────────────────────────────────
 * ✅ Klik SUBMIT.
 * ✅ Notif sukses: "Daily Log berhasil disimpan dan menunggu approval"
 * ✅ Setelah submit = status PENDING (belum masuk Dashboard LY)
 *
 * ─────────────────────────────────────
 * LANGKAH 6 — SUPERVISOR APPROVE (WAJIB AGAR MASUK DASHBOARD LY)
 * ─────────────────────────────────────
 * 🔴🔴 PALING PENTING 🔴🔴
 * Dashboard (termasuk LAST YEAR LY badge) HANYA menghitung row dengan
 * status = 'approved'. Jadi meskipun Engineer submit total_electricity = 26240,
 * jika Supervisor BELUM approve → LY tetap "Belum ada data".
 *
 * ✅ Login sebagai Supervisor.
 * ✅ Menu Review → cari tanggal 16/08/2025 engineer yang tadi submit.
 * ✅ Klik Approve → tanda tangan Supervisor → Submit Approve.
 *
 * ─────────────────────────────────────
 * LANGKAH 7 — VERIFIKASI DI DASHBOARD
 * ─────────────────────────────────────
 * ✅ Buka Dashboard.
 * ✅ Pilih Data Date = 16/08/2026 (tanggal SAMA tahun ini).
 * ✅ Section ELECTRICITY → LY • Avg/Day → harus menampilkan angka total_electricity
 *    26240 kWh (sesuai form yang diapprove).
 * ✅ COST LY = 26240 × tarif listrik.
 */

// ⛔ GUARD: Tidak boleh diakses langsung via URL / browser.
//     HANYA untuk catatan internal staff via IDE / cPanel File Manager.
if (!defined('IN_APP') && count(get_included_files()) === 1) {
    http_response_code(403);
    exit('<h1>403 Forbidden</h1>');
}
