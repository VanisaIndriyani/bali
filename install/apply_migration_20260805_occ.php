<?php
if (!defined('SKIP_SESSION_START')) {
    define('SKIP_SESSION_START', true);
}

require_once __DIR__ . '/../config/config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre style=\"font-family: Consolas, monospace; font-size:13px; background:#f8f9fa; padding:20px; border:1px solid #e5e7eb; border-radius:12px;\">";
echo "=== ST REGIS BALI - MIGRASI OCCUPANCY RATE (OCC %) ===\n";
echo "Tanggal Jalankan: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 70) . "\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();

$dbNameRow = $db->fetchOne("SELECT DATABASE() as dbname");
$dbName = (string)($dbNameRow['dbname'] ?? '');
if (!$dbName) {
    die("[FATAL] Tidak bisa detect nama database.");
}
echo "✅ Nama Database aktif: {$dbName}\n\n";

$checkCol = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");

$checkCol->execute([$dbName, 'daily_logs', 'occ_rate']);
$colExists = (int)$checkCol->fetchColumn();

if ($colExists === 0) {
    try {
        $pdo->exec("ALTER TABLE daily_logs ADD COLUMN occ_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Occupancy Rate % (0-100)' AFTER total_fuel");
        echo "[CREATE ✅] Kolom daily_logs.occ_rate (DECIMAL 5,2) BERHASIL ditambahkan\n";
        try {
            $pdo->exec("ALTER TABLE daily_logs ADD INDEX idx_occ_date (occ_rate, log_date)");
            echo "[INDEX ✅] Index idx_occ_date ditambahkan untuk query laporan cepat\n";
        } catch (Throwable $e) {
            echo "[INDEX SKIP] idx_occ_date: " . $e->getMessage() . "\n";
        }
    } catch (Throwable $e) {
        die("[FATAL ❌] Gagal tambah kolom occ_rate: " . $e->getMessage() . "\n");
    }
} else {
    echo "[SKIP  ✅] Kolom daily_logs.occ_rate SUDAH ADA\n";
}

echo "\n=== MIGRASI SELESAI ===\n";
echo "⚠️  SEGERA HAPUS file install/apply_migration_20260805_occ.php dari hosting setelah ini.\n";
echo "</pre>";
