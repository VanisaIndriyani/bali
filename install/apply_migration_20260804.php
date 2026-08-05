<?php
/**
 * Apply Migration: 2026-08-04 Add 26 utility detail columns
 * AMAN dijalankan BERULANG KALI (cek kolom sudah ada via INFORMATION_SCHEMA)
 * Support jalankan via CLI (PHP command) & via Browser.
 */
// FORCE ERROR DISPLAY AGAR JIKA ERROR LANGSUNG KELUAR (tidak tersembunyi!)
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// FIX CLI: $_SERVER['HTTP_HOST'] TIDAK ADA di CLI → ENV detect salah jadi PRODUCTION, SET MANUAL ke localhost
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/install/apply_migration_20260804.php';
    $_SERVER['SCRIPT_NAME'] = '/AGUSTUS/regist_bali/install/apply_migration_20260804.php';
    $_SERVER['HTTPS'] = null;
}
if (session_status() === PHP_SESSION_NONE && headers_sent() === false) {
    // session start disable untuk CLI install agar tidak warning session_start tanpa cookie
    define('SKIP_SESSION_START', true);
}

require_once __DIR__ . '/../config/config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre style=\"font-family: Consolas, monospace; font-size:13px; background:#f8f9fa; padding:20px; border:1px solid #e5e7eb; border-radius:12px;\">";
echo "=== ST REGIS BALI - MIGRATION DAILY LOG UTILITY DETAILS ===\n";
echo "Tanggal Jalankan: " . date('Y-m-d H:i:s') . "\n";
echo "DB Host: " . ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost') . "\n";
echo str_repeat("=", 70) . "\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();

$dbNameRow = $db->fetchOne("SELECT DATABASE() as dbname");
$dbName = (string)($dbNameRow['dbname'] ?? '');
if (!$dbName) {
    die("[FATAL] Tidak bisa detect nama database. Pastikan koneksi DB OK.");
}
echo "✅ Nama Database aktif: {$dbName}\n\n";

// -- Daftar 26 kolom yang harus ada. Cek per 1 via INFORMATION_SCHEMA sebelum ALTER.
$requiredColumns = [
    // ① Listrik
    'electricity_wbp' => "ALTER TABLE daily_logs ADD COLUMN electricity_wbp DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kWh WBP' AFTER total_electricity",
    'electricity_lwbp' => "ALTER TABLE daily_logs ADD COLUMN electricity_lwbp DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kWh LWBP' AFTER electricity_wbp",
    // ② Water 9
    'water_pdam' => "ALTER TABLE daily_logs ADD COLUMN water_pdam DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 PDAM' AFTER total_water",
    'water_iki_gaban' => "ALTER TABLE daily_logs ADD COLUMN water_iki_gaban DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 IKI Gaban' AFTER water_pdam",
    'water_deepwell_1' => "ALTER TABLE daily_logs ADD COLUMN water_deepwell_1 DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 Deep Well 1' AFTER water_iki_gaban",
    'water_deepwell_2_brr' => "ALTER TABLE daily_logs ADD COLUMN water_deepwell_2_brr DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 Deep Well 2 Brr' AFTER water_deepwell_1",
    'water_deepwell_asean' => "ALTER TABLE daily_logs ADD COLUMN water_deepwell_asean DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 Deep Well ASEAN' AFTER water_deepwell_2_brr",
    'water_deepwell_lpb' => "ALTER TABLE daily_logs ADD COLUMN water_deepwell_lpb DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 Deep Well LPB' AFTER water_deepwell_asean",
    'water_main_building' => "ALTER TABLE daily_logs ADD COLUMN water_main_building DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 Main Building' AFTER water_deepwell_lpb",
    'water_cooling_tower' => "ALTER TABLE daily_logs ADD COLUMN water_cooling_tower DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 Cooling Tower' AFTER water_main_building",
    'water_bottling' => "ALTER TABLE daily_logs ADD COLUMN water_bottling DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 Bottling Water' AFTER water_cooling_tower",
    // ③ Gas
    'gas_lpg' => "ALTER TABLE daily_logs ADD COLUMN gas_lpg DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kg LPG' AFTER total_gas",
    'gas_lng' => "ALTER TABLE daily_logs ADD COLUMN gas_lng DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kg LNG' AFTER gas_lpg",
    // ④ SWRO
    'swro_watermeter' => "ALTER TABLE daily_logs ADD COLUMN swro_watermeter DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 SWRO Watermeter' AFTER gas_lng",
    'swro_kwh' => "ALTER TABLE daily_logs ADD COLUMN swro_kwh DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kWh SWRO Electric' AFTER swro_watermeter",
    'swro_tds' => "ALTER TABLE daily_logs ADD COLUMN swro_tds DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ppm SWRO TDS' AFTER swro_kwh",
    // ⑤ Bottling
    'bottling_kwh' => "ALTER TABLE daily_logs ADD COLUMN bottling_kwh DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kWh Bottling Electric' AFTER swro_tds",
    'bottling_watermeter' => "ALTER TABLE daily_logs ADD COLUMN bottling_watermeter DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 Bottling Watermeter' AFTER bottling_kwh",
    // ⑥ Chiller
    'chiller_1_on' => "ALTER TABLE daily_logs ADD COLUMN chiller_1_on TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Chiller 1 ON' AFTER bottling_watermeter",
    'chiller_2_on' => "ALTER TABLE daily_logs ADD COLUMN chiller_2_on TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Chiller 2 ON' AFTER chiller_1_on",
    'chiller_3_on' => "ALTER TABLE daily_logs ADD COLUMN chiller_3_on TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Chiller 3 ON' AFTER chiller_2_on",
    'chiller_water_ph' => "ALTER TABLE daily_logs ADD COLUMN chiller_water_ph DECIMAL(4,2) NOT NULL DEFAULT 0.00 COMMENT 'pH Chiller' AFTER chiller_3_on",
    'chiller_water_tds' => "ALTER TABLE daily_logs ADD COLUMN chiller_water_tds DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ppm TDS Chiller' AFTER chiller_water_ph",
    'chiller_temp' => "ALTER TABLE daily_logs ADD COLUMN chiller_temp DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT 'C Chiller Temp' AFTER chiller_water_tds",
    'chiller_pressure_chwp' => "ALTER TABLE daily_logs ADD COLUMN chiller_pressure_chwp DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT 'CHWP Pressure' AFTER chiller_temp",
    'chiller_pressure_cwp' => "ALTER TABLE daily_logs ADD COLUMN chiller_pressure_cwp DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT 'CWP Pressure' AFTER chiller_pressure_chwp",
];

$checkStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'daily_logs' AND COLUMN_NAME = ?");

$added = 0;
$skipped = 0;
$failed = 0;

foreach ($requiredColumns as $col => $alterSql) {
    $checkStmt->execute([$dbName, $col]);
    $exists = (int)$checkStmt->fetchColumn() > 0;
    if ($exists) {
        echo "[SKIP  ✅] Kolom {$col} SUDAH ADA\n";
        $skipped++;
        continue;
    }
    try {
        $pdo->exec($alterSql);
        echo "[TAMBAH 🔥] Kolom {$col} BERHASIL ditambahkan\n";
        $added++;
    } catch (Throwable $e) {
        echo "[ERROR ❌] Kolom {$col} GAGAL: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "=== MIGRATION RINGKASAN ===\n";
echo "Total Kolom Yg Seharusnya: " . count($requiredColumns) . "\n";
echo "✅ Kolom TAMBAH BARU    : {$added}\n";
echo "⏭️  Kolom SUDAH ADA (Skip): {$skipped}\n";
echo "❌ Kolom GAGAL          : {$failed}\n";
echo str_repeat("=", 70) . "\n";

if ($failed === 0 && $added + $skipped === count($requiredColumns)) {
    echo "\n🎉 MIGRATION SUKSES TOTAL! 26 kolom detail utility SUDAH READY.\n";
    echo "👉 SEKARANG LANJUT: Form Daily Log & Dashboard Clickable Card Modal Grafik sudah bisa dipakai.\n";
    echo "⚠️  PENTING: HAPUS file install/apply_migration_20260804.php SETELAH INI untuk KEAMANAN (tidak boleh dijalankan berulang di hosting!) — Atau biarin juga ga papa cuma kurang secure.\n";
} else {
    echo "\n⚠️  Ada kolom yang GAGAL. Cek pesan ERROR di atas, perbaiki lalu jalankan ulang file ini.\n";
}
echo "</pre>";
