<?php
if (!defined('SKIP_SESSION_START')) {
    define('SKIP_SESSION_START', true);
}

require_once __DIR__ . '/../config/config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre style=\"font-family: Consolas, monospace; font-size:13px; background:#f8f9fa; padding:20px; border:1px solid #e5e7eb; border-radius:12px;\">";
echo "=== ST REGIS BALI - MIGRASI TABEL AKTIVITAS PEKERJAAN PER BARIS (ENG ACTIVITY) ===\n";
echo "Tanggal Jalankan: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 80) . "\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();

$dbNameRow = $db->fetchOne("SELECT DATABASE() as dbname");
$dbName = (string)($dbNameRow['dbname'] ?? '');
if (!$dbName) die("[FATAL] Tidak bisa detect nama database.");
echo "✅ Nama Database aktif: {$dbName}\n\n";

$logIdRow = $db->fetchOne("SHOW COLUMNS FROM daily_logs LIKE 'id'");
$logIdType = (string)($logIdRow['Type'] ?? 'INT UNSIGNED');
echo "ℹ️  Type daily_logs.id = {$logIdType} — menyesuaikan FK...\n\n";

$checkTbl = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");

// --- 1) Create daily_log_activities table ---
$checkTbl->execute([$dbName, 'daily_log_activities']);
$tblExists = (int)$checkTbl->fetchColumn();
if ($tblExists === 0) {
    $createSql = "CREATE TABLE daily_log_activities (
        id {$logIdType} NOT NULL AUTO_INCREMENT PRIMARY KEY,
        daily_log_id {$logIdType} NOT NULL,
        category ENUM('operation','maintenance','project','landscape') NOT NULL DEFAULT 'operation',
        activity_title VARCHAR(255) NOT NULL,
        activity_desc TEXT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_logid (daily_log_id),
        INDEX idx_cat (category),
        INDEX idx_created (created_at),
        CONSTRAINT fk_act_log FOREIGN KEY (daily_log_id) REFERENCES daily_logs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try {
        $pdo->exec($createSql);
        echo "[CREATE 🔥] Tabel daily_log_activities BERHASIL dibuat (4 kategori enum & FK ke daily_logs CASCADE)\n";
    } catch (Throwable $e) {
        die("[FATAL ❌] Gagal create daily_log_activities: " . $e->getMessage() . "\n");
    }
} else {
    echo "[SKIP  ✅] Tabel daily_log_activities SUDAH ADA\n";
}

// --- 2) Migrasi backward data: kolom work_activities TEXT (lama) -> pecah per baris ke tabel baru ---
$checkTbl->execute([$dbName, 'daily_log_activities']);
$migrationRan = $pdo->query("SELECT COUNT(*) FROM daily_log_activities")->fetchColumn();
if ((int)$migrationRan === 0) {
    echo "\n[MIGRASI DATA 📦] Memindahkan data work_activities lama ke child tabel baru per baris...\n";
    $oldLogs = $db->fetchAll("SELECT id, work_activities FROM daily_logs WHERE COALESCE(work_activities,'') <> ''");
    $insertAct = $pdo->prepare("INSERT INTO daily_log_activities (daily_log_id, category, activity_title, sort_order) VALUES (?,?,?,?)");
    $total = 0;
    $catOrder = ['operation','maintenance','project','landscape'];
    foreach ($oldLogs as $ol) {
        $lines = preg_split('/\r\n|\r|\n/', (string)$ol['work_activities']);
        $lines = array_values(array_filter(array_map('trim', $lines), 'strlen'));
        $i = 0;
        foreach ($lines as $line) {
            if (strlen($line) > 240) $line = mb_substr($line, 0, 240) . '...';
            $cat = $catOrder[$i % 4];
            try {
                $insertAct->execute([(int)$ol['id'], $cat, $line, $i]);
                $total++;
            } catch (Throwable $e) { /* ignore */ }
            $i++;
        }
    }
    echo "[SELESAI 👷] Total " . count($oldLogs) . " log lama dipindah = {$total} baris aktivitas pekerjaan (auto bagi per kategori)\n";

    // --- 3) Sinkron counter parent lama (count_operation dll) dari child table ---
    echo "\n[SINKRON COUNTER] Update kolom activity_* di daily_logs berdasarkan child table COUNT(*)...\n";
    foreach (['operation','maintenance','project','landscape'] as $c) {
        $upd = "UPDATE daily_logs dl SET dl.activity_{$c} = COALESCE((SELECT COUNT(*) FROM daily_log_activities dla WHERE dla.daily_log_id = dl.id AND dla.category = '{$c}'), 0)";
        try {
            $pdo->exec($upd);
            echo "   ✅ activity_{$c} di-UPDATE berdasarkan COUNT child\n";
        } catch (Throwable $e) {
            echo "   ⚠️ activity_{$c}: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "[SKIP  ✅] Tabel daily_log_activities sudah terisi data → tidak migrate ulang data lama.\n";
}

echo "\n=== MIGRASI SELESAI ===\n";
echo "⚠️  SEGERA HAPUS file install/apply_migration_20260805_activity_items.php dari folder hosting setelah ini!\n";
echo "</pre>";
