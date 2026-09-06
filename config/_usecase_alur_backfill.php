<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  📘 USE CASE LENGKAP: CARA INPUT BACKFILL DATA HISTORIS TAHUN 2025
 *  Goal: Form Total Listrik = 26240 kWh → Dashboard LY (tanggal sama tahun ini)
 *        JUGA menampilkan 26240 kWh (BUKAN 3 kWh / "Belum ada data")
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  ❗ PRE-REQUISITE (SEBELUM JALANKAN FLOW INI — PASTIKAN DULU!)          │
 * └─────────────────────────────────────────────────────────────────────────┘
 * [PR-1] KODE PRODUCTION HOSTING registengineering.com SUDAH UPDATE KE
 *        COMMIT TERBARU 3ecbe11 (PHP POST handler baca Yesterday ✏️ override).
 *        👉 Jalankan di SSH:
 *           cd /path/to/public_html
 *           git fetch origin main ; git reset --hard origin/main ; git clean -fd
 *        👉 Verifikasi: di daily_log_form.php search string "_y_elec_wbp_override"
 *           KETEMU = OK. TIDAK KETEMU = deploy belum berhasil.
 *
 * [PR-2] Supervisor sudah punya akun login, dan bisa buka halaman Review.
 * [PR-3] Engineer sudah punya data fisik: Yesterday (tgl N-1) + Today (tgl N)
 *        reading listrik WBP/LWBP, water, gas, fuel.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  🔍 ROOT CAUSE ANALYSIS:
 * │  Kenapa customer lihat: FORM TOTAL LISTRIK = 26240, DASHBOARD LY = 3?
 * └─────────────────────────────────────────────────────────────────────────┘
 *  Penyebab 1 (BUG KODE LAMA SEBELUM commit 3ecbe11 — SUDAH DIFIX):
 *     ✏️ User input Yesterday override 3202.44 kWh → Form JS hitung
 *     Selisih × 8000 = 26240 ✅. TAPI PHP waktu Submit TIDAK baca override,
 *     pakai Yesterday auto-DB (tgl 15 Agu 2025 KOSONG = 0) → DB tersimpan
 *     total_electricity = (3265.12 - 0) × 8000 = 26.xxx × 8000 = SALAH BESAR!
 *     Setelah disave, jika Engineer buka log lagi, Yesterday DB kosong tadi
 *     dihitung ulang → total_electricity berubah drastis / 0 / kecil.
 *     ✔️ FIX commit 3ecbe11: PHP POST baca hidden _y_elec_wbp_override /
 *       _y_elec_lwbp_override / _y_water_mb_override → pakai itu jika terisi.
 *
 *  Penyebab 2 (BUKAN BUG, TAPI SOP YANG TIDAK DIJALANKAN):
 *     Engineer submit → status = PENDING. Dashboard LY HANYA menghitung
 *     row dengan status = 'approved'. Jadi meskipun Engineer iseng coba
 *     submit 1 log tanpa di-approve Supervisor, angka TIDAK akan muncul
 *     di LY Dashboard (dianggap "belum ada data" / LY = 3 kWh shift pagi).
 *
 *  Penyebab 3 (SHIFT BUKAN MALAM / HANYA 1 SHIFT DARI 3):
 *     Daily log bisa diisi 3x sehari: Shift Pagi, Siang, Malam.
 *     Dashboard LY menjumlah SEMUA shift tgl itu.
 *     Jika Engineer baru input Shift Pagi saja (total_electricity=3 kWh),
 *     Shift Siang + Malam BELUM diinput → LY menampilkan 3 kWh (BUKAN 26240).
 *     Solusi: Isi Shift MALAM yang mewakili 1 hari (total all shift), atau isi
 *     3 shift lengkap dan approve ketiganya.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  🎯 USE CASE: Backfill Historis Tanggal 16 Agustus 2025
 * │  Target Output: Dashboard tgl 16/08/2026 → ELECTRICITY LY = 26240 kWh
 * └─────────────────────────────────────────────────────────────────────────┘
 * ═══════════════════════════════════════════════════════════════════════════
 *  ACTOR: 👷 ENGINEER + 👮 SUPERVISOR
 *  DATE TARGET (LY): 16/08/2025 → agar muncul di LY Dashboard 16/08/2026
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
 * ┃ STEP 1 👷 ENGINEER: Buka Form dengan tanggal TEPAT                     ┃
 * ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 * 1a. Login sebagai Engineer (https://registengineering.com → Login)
 * 1b. Navigasi ke: Daily Log → Pilih Date → 16/08/2025 (TAHUN 2025)
 *     ⚠️ JANGAN SALAH PILIH TAHUN → harus 2025, bukan 2026.
 *     URL Contoh: engineer/daily_log_form.php?date=2025-08-16
 * 1c. Pilih Engineer Name = nama yang backfill (jika multi-engineer)
 * 1d. Pilih SHIFT = MALAM 🌙
 *     → Alasan: Shift Malam merekam total 1 hari penuh.
 *        Kalau mau 3 shift (Pagi/Siang/Malam) di-input 3x log juga boleh,
 *        nanti Dashboard otomatis SUM semua shift yang diapprove.
 *
 * ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
 * ┃ STEP 2 👷 ENGINEER: UNLOCK ✏️ YESTERDAY (TIGA TEMPAT)                 ┃
 * ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 *    ❗ JIKA MENGINPUT DATA HISTORIS TAHUN 2025 → WAJIB LANGKAH INI ❗
 *    (Karena tgl 15/08/2025 kemungkinan besar BELUM PERNAH diinput → DB = 0)
 *
 * 2a. Section KWH WBP:
 *     Cari label "Yesterday (15/08/2025)" → klik tombol ✏️ di sebelahnya.
 *     Tombol berubah jadi 🔒 Lock warna indigo. Input Yesterday sekarang PUTIH
 *     BISA DIKETIK (bukan abu-abu readonly).
 *       → Isi sesuai reading fisik WBP tanggal 15/08/2025.
 *       → Contoh: 3202.44 kWh
 * 2b. Section KWH LWBP:
 *     Sama langkah: klik ✏️ "Yesterday (15/08/2025)" → 🔒 Lock → isi reading.
 *       → Contoh: 3263.64 kWh
 * 2c. Section WATER Main Building:
 *     Cari label "Kemarin (15/08/2025)" → klik ✏️ → 🔒 Lock → isi reading air.
 *
 * ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
 * ┃ STEP 3 👷 ENGINEER: Isi TODAY value SEMUA section                     ┃
 * ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 * Isi semua field Today sesuai tanggal 16/08/2025 (reading akhir hari itu):
 *  3a. KWH WBP Today   = contoh 3265.12 kWh
 *  3b. KWH LWBP Today  = sesuai fisik
 *  3c. Water Main Building Today = sesuai fisik
 *  3d. Water 9 sumber lain (PDAM, IKI, DW1, DW2, DW Asean, DW LPB,
 *      Cooling Tower, Bottling, Irrigation) → isi jika ada.
 *  3e. Gas LPG + LNG → isi
 *  3f. Fuel → isi
 *  3g. Chillers, SWRO deductions, Bottling, Equipment Section → isi sesuai SOP
 *  3h. Occupancy Rate, ITR, M&U, GITB → isi jika ada
 *  3i. Section ACTIVITIES: isi PROJECT / OPERATION / MAINTENANCE / LANDSCAPE
 *      (urutan tampil di Dashboard: PROJECT > OPERATION > MAINTENANCE > LANDSCAPE)
 *  3j. Upload foto bukti attendance / meter reader di Photo section.
 *
 * ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
 * ┃ STEP 4 👷 ENGINEER: CEKPOINT 1 — LIVE SUMMARY HARUS BENAR             ┃
 * ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 * 🔴 🔴 JANGAN SUBMIT DULU SEBELUM PASTIKAN INI! 🔴 🔴
 *
 * Lihat CARD ATAS paling atas: "LIVE SUMMARY HASIL AKHIR & KALKULASI"
 *   → Expected angka TOTAL LISTRIK (sesuai contoh gambar customer):
 *        = 26240,00 kWh ✅
 *   → Expected TOTAL AIR = sesuai formula 10 sumber
 *   → Expected GRAND TOTAL = Rupiah gabungan semua utility
 *
 * Jika card atas TETAP 0.00 Rp 0 padahal field dibawah sudah diisi:
 *   [Fix A] Refresh page 1x (F5 / tarik kebawah di Mobile)
 *           → setTimeout 100ms & 300ms akan auto re-hitung calcTotals()
 *   [Fix B] Ketik sesuatu secara singkat di field WBP Today, lalu hapus
 *           kembali → trigger oninput → calcTotals() jalankan
 *   [Fix C] Developer Tools → Console: harusnya TIDAK ada error bertuliskan
 *           "[calcTotals] ERROR". Kalau ada, copy error text kirim tim dev.
 *
 * ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
 * ┃ STEP 5 👷 ENGINEER: SUBMIT FORM                                        ┃
 * ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 * 5a. SCROLL SAMPAI PALING BAWAH
 * 5b. Klik TOMBOL SUBMIT berwarna HITAM (Submit Daily Log) dengan icon cloud.
 * 5c. Jika sukses: redirect ke select_date.php dan muncul toast:
 *        ✅ "Daily Log berhasil disimpan dan menunggu approval"
 * 5d. Engineer perhatikan status sekarang: ⏳ PENDING
 *     LANGKAH INI BELUM MENGAKIBATKAN APA-APA DI DASHBOARD.
 *
 * ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
 * ┃ STEP 6 🚨 CEKPOINT 2 (Developer / QA): Verifikasi Data di DB TERSIMPAN ┃
 * ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 *   (Opsional / Jika LY masih tidak sesuai → lakukan cek ini via phpMyAdmin)
 *
 *   Jalankan query:
 *   ```sql
 *   SELECT id, log_date, shift, engineer_id, status,
 *          total_electricity,   electricity_wbp,  electricity_lwbp,
 *          total_water,         water_main_building,
 *          total_gas,           total_fuel
 *   FROM daily_logs
 *   WHERE log_date = '2025-08-16'
 *   ORDER BY shift ASC ;
 *   ```
 *   HARUSNYA (contoh data shift malam):
 *    - status           = 'pending' (belum di-approve step 7)
 *    - total_electricity= 26240.00  ✔️ (bukan 3 / 0 / (Today × 8000))
 *    - electricity_wbp  = 3265.12   (Today WBP reading)
 *    - electricity_lwbp = ...       (Today LWBP reading)
 *   ❌ Jika total_electricity BUKAN 26240 → kembali ke Step 2 (Unlock Yesterday)
 *      atau deploy commit 3ecbe11 di hosting belum berhasil (cek PR-1).
 *
 * ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
 * ┃ STEP 7 👮 SUPERVISOR: REVIEW & APPROVE (PALING PENTING!)               ┃
 * ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 * 🔴🔴 INI RAHASIANYA! TANPA STEP 7 = LY TIDAK AKAN PERNAH MUNCUL 🔴🔴
 *
 * 7a. Login sebagai SUPERVISOR (https://registengineering.com → Login)
 * 7b. Menu: Supervisor → Review Log
 * 7c. Filter tanggal: 16/08/2025 → klik Search.
 * 7d. Akan muncul list log Engineer yang baru disubmit status = PENDING.
 * 7e. Klik tombol [Detail] / [Review]
 * 7f. CEK SELURUH ISIAN:
 *       → Total Listrik 26240 kWh? ✅
 *       → Total Air / Gas / Fuel sesuai?
 *       → Activities, Photo bukti?
 * 7g. Jika BENAR SEMUA:
 *       → Tanda tangan Supervisor di kolom TTD
 *       → Klik tombol [✅ APPROVE LOG]
 * 7h. Hasil: status berubah dari PENDING → ✅ APPROVED, dan approved_at terisi
 *
 * ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
 * ┃ STEP 8 👥 SEMUA: VERIFIKASI DI DASHBOARD LAST YEAR LY                 ┃
 * ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 * 8a. Siapapun boleh login (Engineer/Supervisor/Manager/Admin)
 * 8b. Buka Dashboard Homepage
 * 8c. Klik bagian badge date di kanan atas Utility Report "Data: DD/MM/YYYY"
 * 8d. Ganti tanggal menjadi TANGGAL SAMA TAHUN INI: 16/08/2026 (TAHUN 2026)
 *     → Karena LY formula = sama tanggal, 1 tahun LALU (2025).
 * 8e. Scroll ke section [1] UTILITY REPORT → ELECTRICITY ⚡:
 *
 *       ✅ HASIL YANG DIHARAPKAN (sesuai gambar customer yang "udah benar"):
 *
 *       ┌─ ELECTRICITY ──────────────────────────────────────┐
 *       │  LY • Avg/Day                    COST LY           │
 *       │  26.240 kWh                      Rp [tarif × 26240]│
 *       │  TODAY                                            │
 *       │  [today 2026 actual kWh]         Rp [today cost]   │
 *       └────────────────────────────────────────────────────┘
 *
 * ❌ JIKA MASIH MENAMPILKAN "3 kWh" (seperti gambar customer lama):
 *
 *    [Salah 1] Status masih PENDING, Supervisor BELUM approve → Step 7.
 *    [Salah 2] Baru di-approve Shift Pagi saja = 3 kWh, Shift Malam 26240
 *              BELUM di-approve. → Approve juga shift MALAM.
 *    [Salah 3] total_electricity di DB BUKAN 26240 (lihat Step 6 query)
 *              → Redo Step 1 s.d 5, lalu Approve ulang.
 *    [Salah 4] Cache browser mobile → refresh hard (Android: tekan & tahan
 *              refresh di Chrome; iOS: tarik kebawah berkali-kali).
 *
 * ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
 * ┃ STEP 9 ♻️ ULANGI SEMUA STEP DI ATAS, UNTUK SEMUA TANGGAL YANG KOSONG ┃
 * ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 *  Tanggal yang harus backfill: 1 s/d 31 Agt 2025 (tanggal-tanggal lain tahun
 *  2025 yang kosong juga, sebelum masa sistem mulai online).
 *
 *  Polanya:
 *   16 Agustus 2025 ✅ → Lanjut ke 17 Agustus 2025 → 18 Agustus 2025 → dst
 *   (jangan lupa setiap ganti tanggal = Yesterday berubah juga)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *  📝 EXCEPTION HANDLING (PERTANYAAN UMUM STAFF)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Q: "Kemarin saya klik ✏️ Yesterday, tapi lupa klik 🔒 Lock. Tetap tersimpan gak?"
 * A: ✅ TETAP tersimpan. Nilai hidden override _y_* berubah saat ONINPUT
 *    (ketik di Yesterday input), bukan saat Lock. Lock hanya toggle readonly
 *    visual; tidak mempengaruhi nilai POST override. Kalau mau BATAL override,
 *    klik 🔒 Lock → hidden override dibersihkan, kembali pakai auto DB.
 *
 * Q: "Saya mau isi 3 shift sekaligus (Pagi/Siang/Malam) bukan cuma Malam?"
 * A: ✅ BISA. Input 3x form log (date sama, shift beda). Lalu Supervisor
 *    APPROVE KETIGA-TIGANYA. Dashboard LY otomatis SUM(3 shift) = grand total.
 *
 * Q: "Bagaimana kalau sudah saya Approve, ternyata ada yang salah input?"
 * A: Supervisor bisa REJECT / REVISE. Status berubah jadi Revision Request →
 *    Engineer update kembali → Submit ulang → Approve ulang.
 *
 * Q: "Data tanggal 15 Agu 2025 sudah ada di DB (Yesterday auto-isi). Perlu
 *     klik ✏️ Yesterday atau tidak?"
 * A: ❌ TIDAK PERLU. Biarkan readonly default. Yesterday otomatis = benar,
 *    hidden override _y_* tetap kosong → pakai auto DB fetch.
 *    Tombol ✏️ HANYA dipakai KETIKA YESTERDAY DI-DB TIDAK SESUAI / KOSONG.
 */

// ⛔ GUARD: Tidak boleh diakses langsung via URL browser (403 Forbidden).
// Dokumentasi ini hanya untuk dibaca via IDE / cPanel File Manager.
if (!defined('IN_APP') && count(get_included_files()) === 1) {
    http_response_code(403);
    exit('<h1>403 Forbidden</h1>');
}
