<?php
/**
 * 🔍🔍🔍 SUPER AUDIT HOSTING (1 KLIK CEK APAKAH FILE PATCH SUDAH MASUK KE PRODUCTION?
 *
 * CARA PAKAI:
 * 1. Upload file ini ke hosting (root, selevel index.php)
 * 2. Buka: https://registengineering.com/super_cek_hosting.php
 * 3. Lihat hasil: 🔴 MERAH = BELUM FIX, 🟢 HIJAU = SUDAH FIX
 * 4. HAPUS FILE INI JIKA SELESAI!
 */
$scriptDir = __DIR__;
$configPath = $scriptDir . '/config/config.php';
if (!file_exists($configPath)) { $configPath = $scriptDir . '/../config/config.php'; }
require_once $configPath;
if (!class_exists('Database', false)) { die('Class Database tidak ditemukan! Cek config/config.php path benar?'); }
$db = Database::getInstance();
if (!$db) { die('Gagal connect DB! Cek username/password database di config/config.php.'); }
header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(0);
date_default_timezone_set('Asia/Makassar');

echo "================================================================\n";
echo "🔍🔍🔍 SUPER AUDIT HOSTING v1 (Auto-Detect Sudah Fix / Belum) 🔍🔍🔍\n";
echo "================================================================\n";
echo "  Waktu audit server: " . date('d/m/Y H:i:s') . "\n\n";

/* ----------------------------------------------------------------
 * CHECK #1:  FILE PHP BENAR = DAILY_LOG_FORM.PHP?
 * ---------------------------------------------------------------- */
echo "--- CHECK #1: engineeer/daily_log_form.php SUDAH PATCH TERBARU?\n";
echo "    (Harusnya $waterFields = [] → TIDAK ADA Cooling Tower / Bottling / Irrigation)\n";
$formPath = __DIR__ . '/engineer/daily_log_form.php';
$formExists = file_exists($formPath);
if (!$formExists) {
    echo "  ❌ FILE TIDAK DITEMUKAN? Cek path!\n";
} else {
    $formContent = @file_get_contents($formPath);
    if ($formContent === false) { echo "  ❌ GAGAL BACA FILE!\n"; }
    else {
        $hasEmptyWaterFields = (strpos($formContent, '$waterFields = [];') !== false);
        $hasOldWaterFields = (strpos($formContent, "'water_cooling_tower'") !== false
            && strpos($formContent, "'water_bottling'") !== false
            && strpos($formContent, "'water_irrigation'") !== false
            && strpos($formContent, "['water_cooling_tower', 'Cooling Tower']") !== false);
        $hasRemoveComment = (strpos($formContent, "HAPUS SEMUA WATER LAIN SELAIN: Main Building (Meter) + Water PDAM") !== false);
        if ($hasEmptyWaterFields && $hasRemoveComment) {
            echo "  🟢 BERHASIL! daily_log_form.php = PATCH TERBARU (Cooling Tower dll sudah dihapus dari form)\n";
        } elseif ($hasOldWaterFields) {
            echo "  🔴 GAGAL! daily_log_form.php MASIH VERSI LAMA (masukkan Cooling Tower, Bottling, Irrigation di form!)\n";
            echo "     → SOLUSI: Upload ulang file 'engineer/daily_log_form.php' versi PATCH TERBARU ke hosting!\n";
        } else {
            echo "  ⚠️ VERSI TIDAK JELAS! Cek manual file form.\n";
        }
    }
}
echo "\n";

/* ----------------------------------------------------------------
 * CHECK #2:  RECALC DATA LAMA SUDAH DIJALANKAN?
 *   (Tanggal 01/09/2026 total_water SEHARUSNYA 12.666,60 (MB 377 + PDAM 12289,60)
 *    SEBELUM RECALC: 207.375,80 (termasuk CT 97777 + Bottling + Irrigation)
 * ---------------------------------------------------------------- */
echo "--- CHECK #2: Recalc Data Lama (recalc_utility_all_dates.php) SUDAH DIJALANKAN?\n";
echo "    (Test tanggal 01/09/2026 → TOTAL WATER BENAR = 12.666,60; SALAH = 207.375,80)\n";
$row0109 = $db->fetchOne("
    SELECT DATE(log_date) tgl, total_water, water_main_building, water_pdam,
           water_cooling_tower, water_bottling, water_irrigation,
           total_electricity, total_gas, total_fuel
    FROM daily_logs
    WHERE DATE(log_date) = '2026-09-01'
    ORDER BY (CASE WHEN COALESCE(water_main_building,0) > 0 THEN 0 ELSE 1 END) ASC,
             log_date DESC, id DESC
    LIMIT 1
");
if (!$row0109) {
    echo "  ⚠️ Data 01/09/2026 TIDAK ADA di DB daily_logs?\n";
} else {
    $totW = (float)($row0109['total_water'] ?? 0);
    $wMb = (float)($row0109['water_main_building'] ?? 0);
    $wPd = (float)($row0109['water_pdam'] ?? 0);
    $wCt = (float)($row0109['water_cooling_tower'] ?? 0);
    $wBo = (float)($row0109['water_bottling'] ?? 0);
    $wIr = (float)($row0109['water_irrigation'] ?? 0);
    $elec = (float)($row0109['total_electricity'] ?? 0);
    $gas = (float)($row0109['total_gas'] ?? 0);
    $fuel = (float)($row0109['total_fuel'] ?? 0);

    echo "    -> Raw DB 01/09:\n";
    echo "       • total_water      = $totW\n";
    echo "       • water_mb       = $wMb\n";
    echo "       • water_pdam     = $wPd\n";
    echo "       • cooling_tower  = $wCt\n";
    echo "       • bottling       = $wBo\n";
    echo "       • irrigation   = $wIr\n";
    echo "       • total_listrik  = $elec\n";
    echo "       • total_gas      = $gas\n";
    echo "       • total_fuel     = $fuel\n";
    echo "       • (HARUSNYA SETELAH RECALC) = \n";

    $totFromFields = ($wMb - 75090.20) * 10.0 + $wPd; // approx
    $isRecalcAlready = false;

    if ($totW >= 12000 && $totW <= 14000) {
        echo "  🟢 BERHASIL! RECALC SUDAH JALAN! total_water = $totW (12.666,60)\n";
        $isRecalcAlready = true;
    } else if ($totW > 200000) {
        echo "  🔴 GAGAL! RECALC BELUM DIJALANKAN! total_water masih $totW (masih termasuk CT+Bottling+Irrigation)\n";
        echo "     → SOLUSI: Upload recalc_utility_all_dates.php ke hosting → BUKA URLnya, TUNGGU SELESAI.\n";
    } else if ($wCt > 1 || $wBo > 1 || $wIr > 1) {
        echo "  ⚠️ PERINGATAN: CT/Bottling/Irrigation di DB 01/09 masih >0 ($wCt / $wBo / $wIr), tapi total_water = $totW.\n";
        echo "     → Jalankan recalc untuk clean up!\n";
    } else {
        echo "  ⚠️ Hasil tidak jelas (totW=$totW).\n";
    }
}
echo "\n";

/* ----------------------------------------------------------------
 * CHECK #3: TARIF LISTRIK BENAR?
 *   (default = 1850, recalc harus pake getTariffSettings = 1850, BUKAN 1115!)
 * ---------------------------------------------------------------- */
echo "--- CHECK #3: TARIF LISTRIK SISTEM? (harusnya DEFAULT = 1850)\n";
if (function_exists('getTariffSettings')) {
    $t = getTariffSettings();
    $el = (int)($t['electricity_per_kwh'] ?? 0);
    $wa = (int)($t['water_per_m3'] ?? 0);
    $ga = (int)($t['gas_per_kg'] ?? 0);
    $fu = (int)($t['fuel_per_liter'] ?? 0);
    echo "    Listrik/kWh = $el\n";
    echo "    Water/m³   = $wa\n";
    echo "    Gas/kg     = $ga\n";
    echo "    Fuel/L     = $fu\n";
    if ($el == 1850 && $wa == 9600 && $ga == 24500 && $fu == 17450) {
        echo "  🟢 BERHASIL! TARIF BENAR (DEFAULT 1850 / 9600 / 24500 / 17450).\n";
    } else if ($el > 0) {
        echo "  ⚠️ TARIF TIDAK DEFAULT (mungkin user setting sendiri). Recalc akan pake tarif ini (OTOMATIS dari settings DB, jadi sudah benar).\n";
    } else {
        echo "  🔴 GAGAL! getTariffSettings TIDAK RETURN apa-apa? Cek config/config.php fungsi ada!\n";
    }
} else {
    echo "  ❌ Fungsi getTariffSettings() TIDAK ADA! config.php tidak include dengan benar!\n";
}
echo "\n";

/* ----------------------------------------------------------------
 * CHECK #4: helper_util.php ADA isinya auto-fix v2 + CAP WATER 200.000?
 * ---------------------------------------------------------------- */
echo "--- CHECK #4: includes/helper_util.php SUDAH ada SHARED HELPER (versi CAP 200.000)?\n";
echo "    (versi LAMA cap water=800 → akan memotong 12.666,60 → 800 saja!)\n";
$hp = __DIR__ . '/includes/helper_util.php';
if (!file_exists($hp)) {
    echo "  🔴 GAGAL! includes/helper_util.php TIDAK ADA di hosting!\n";
    echo "     → Upload includes/helper_util.php VERSI BARU! (tanpa ini index.php & daily_summary.php ERROR include missing)\n";
} else {
    $hc = @file_get_contents($hp);
    $hasFixFunc = (strpos($hc, 'repAutoFixUtilityFormulaLama') !== false);
    $hasWaterOnly = (strpos($hc, 'COALESCE(water_pdam,0) as others_water') !== false);
    $hasCap200k  = (strpos($hc, '200000.0') !== false || strpos($hc, '200000') !== false || strpos($hc, 'cap air ≤200.000') !== false);
    if ($hasFixFunc && $hasWaterOnly && $hasCap200k) {
        echo "  🟢 BERHASIL! helper_util.php = VERSI BARU (total air = MB + PDAM + CAP AIR 200.000).\n";
    } else {
        if (!$hasCap200k) {
            echo "  🔴 GAGAL! helper_util.php VERSI LAMA (CAP AIR MASIH 800! → nanti water 12.666,60 dipotong jd 800)\n";
            echo "     → Upload includes/helper_util.php VERSI TERBARU (cap water 200000)!\n";
        } else {
            echo "  ⚠️ helper_util.php TIDAK LENGKAP (fungsi auto-fix / water only MB+PDAM tidak ada).\n";
        }
    }
}
echo "\n";

/* ----------------------------------------------------------------
 * CHECK #5 (BARU): index.php + daily_summary.php CAP WATER = 200.000 BUKAN 800?
 *   (BUG BESAR kemarin: Recalc simpan 12.666,60 → Dashboard/print cap 800 → Scale down /100 = 126,67)
 * ---------------------------------------------------------------- */
echo "--- CHECK #5: index.php & daily_summary.php SAFETY CAP WATER BUKAN 800? (harus 200.000)\n";
echo "    (CAP 800 = WATER 12.666,60 → /100 = 126,67 ❌ SALAH! CAP 200.000 = BENAR ✅)\n";
$filesToCheck = [
    [__DIR__ . '/index.php',                 'index.php (DASHBOARD)'],
    [__DIR__ . '/reports/daily_summary.php', 'reports/daily_summary.php (PRINT)'],
];
$allCapOK = true;
foreach ($filesToCheck as $fc) {
    list($fp, $label) = $fc;
    if (!file_exists($fp)) {
        echo "  ❌ FILE TIDAK DITEMUKAN: $label → check path!\n";
        $allCapOK = false; continue;
    }
    $fc = @file_get_contents($fp);
    if ($fc === false) { echo "  ❌ GAGAL BACA $label\n"; $allCapOK = false; continue; }
    $oldCap800  = preg_match("/'water'\s*=>\s*800\.0/", $fc);
    $hasCap200k = preg_match("/'water'\s*=>\s*200000\.?0*/", $fc);
    if ($hasCap200k && !$oldCap800) {
        echo "  🟢 $label → CAP WATER = 200.000 m3 ✅\n";
    } elseif ($oldCap800) {
        echo "  🔴 $label → CAP WATER MASIH 800! (SALAH! Water 12.666,60 → /100 = 126,67)\n";
        echo "     → Upload ulang $label VERSI TERBARU (cap dinaikkan 800 → 200.000)!\n";
        $allCapOK = false;
    } else {
        echo "  ⚠️ $label → pola CAP TIDAK KETEMU (cek manual).\n";
    }
}
echo "\n";

/* ----------------------------------------------------------------
 * RINGKASAN AKHIR
 * ---------------------------------------------------------------- */
echo "================================================================\n";
echo "📋 RINGKASAN LANGKAH PERBAIKI YANG PERLU DILAKUKAN:\n";
echo "================================================================\n";
echo "  Jika ada tanda 🔴 MERAH di atas → lakukan PETUNJUK SETIAP 🔴 MERAH itu.\n\n";
echo "  1. 🔴 (CHECK1 merah) → Upload 'engineer/daily_log_form.php PATCH TERBARU!\n";
echo "  2. 🔴 (CHECK2 merah) → Upload 'recalc_utility_all_dates.php' → BUKA URLnya, TUNGGU sampai tulisan SELESAI.\n";
echo "  3. 🔴 (CHECK4 merah) → Upload includes/helper_util.php CAP 200.000 VERSI BARU!\n";
echo "  4. 🔴 (CHECK5 merah) → Upload index.php & reports/daily_summary.php CAP WATER 200.000!\n";
echo "  5. Setelah SEMUA 🟢 → Buka dashboard tanggal 01/09 → REFRESH (Ctrl+Shift+R) → COBA PRINT → bandingkan Utility Report card ↔ PDF.\n\n";
echo "     🎯 HASIL YANG DIHARAPKAN 01/09/2026:\n";
echo "        • ⚡ Listrik = 26.720 kWh\n";
echo "        • 💧 Air     = 12.666,60 m3 (MB 377×10 + PDAM 12.289,60)\n";
echo "        • 🔥 Gas     = 60,74 kg (atau sesuai user set)\n\n";
echo "  🔒 PENTING: Setelah semua selesai → HAPUS super_cek_hosting.php + recalc_utility_all_dates.php + cek_kemarin_auto.php dari hosting!\n";
