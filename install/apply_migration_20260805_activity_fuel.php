<?php
/**
 * Apply Migration: 2026-08-05 Add Fuel + 4 Activity Counters
 * AMAN dijalankan BERULANG KALI (cek kolom sudah ada via INFORMATION_SCHEMA)
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/install/apply_migration_20260805_activity_fuel.php';
    $_SERVER['SCRIPT_NAME'] = '/AGUSTUS/regist_bali/install/apply_migration_20260805_activity_fuel.php';
    $_SERVER['HTTPS'] = null;
}
if (session_status() === PHP_SESSION_NONE && headers_sent() === false) {
    define('SKIP_SESSION_START', true);
}

require_once __DIR__ . '/../config/config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre style=\"font-family: Consolas, monospace; font-size:13px; background:#f8f9fa; padding:20px; border:1px solid #e5e7eb; border-radius:12px;\">";
echo "=== ST REGIS BALI - MIGRATION FUEL + ACTIVITY COUNTERS ===\n";
echo "Tanggal Jalankan: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 70) . "\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();

$dbNameRow = $db->fetchOne("SELECT DATABASE() as dbname");
$dbName = (string)($dbNameRow['dbname'] ?? '');
if (!$dbName) {
    die("[FATAL] Tidak bisa detect nama database. Pastikan koneksi DB OK.");
}
echo "✅ Nama Database aktif: {$dbName}\n\n";

$requiredColumns = [
    'total_fuel' => "ALTER TABLE daily_logs ADD COLUMN total_fuel DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Liter Fuel/Solar' AFTER chiller_pressure_cwp",
    'activity_operation' => "ALTER TABLE daily_logs ADD COLUMN activity_operation INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Jumlah Aktivitas Operation' AFTER total_fuel",
    'activity_maintenance' => "ALTER TABLE daily_logs ADD COLUMN activity_maintenance INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Jumlah Aktivitas Maintenance' AFTER activity_operation",
    'activity_project' => "ALTER TABLE daily_logs ADD COLUMN activity_project INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Jumlah Aktivitas Project' AFTER activity_maintenance",
    'activity_landscape' => "ALTER TABLE daily_logs ADD COLUMN activity_landscape INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Jumlah Aktivitas Landscape' AFTER activity_project",
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
    echo "\n🎉 MIGRATION SUKSES TOTAL! Fuel + 4 Activity Counters SUDAH READY.\n";
    echo "👉 Form Daily Log & Dashboard ENG ACTIVITY sudah siap dipakai.\n";
} else {
    echo "\n⚠️  Ada kolom yang GAGAL. Cek pesan ERROR di atas, perbaiki lalu jalankan ulang file ini.\n";
}
echo "</pre>";
