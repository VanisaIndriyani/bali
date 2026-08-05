<?php
require_once __DIR__ . '/config/config.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

$sql = file_get_contents(__DIR__ . '/config/schema.sql');

$message = '';
$installed = false;

try {
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $totalAffected = 0;
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            $totalAffected += (int)@$pdo->exec($stmt);
        }
    }

    $checkUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $checkTables = $pdo->query("SHOW TABLES LIKE 'daily_logs'")->rowCount();

    if ($checkTables > 0 && $checkUsers >= 1) {
        $installed = true;
        if ($totalAffected > 0) {
            $message = 'Database berhasil diinstall!';
        } else {
            $message = 'Database sudah pernah diinstall - semua tabel & user sudah tersedia.';
        }
    } else {
        throw new Exception('Table atau user default tidak berhasil dibuat.');
    }
} catch (PDOException $e) {
    try {
        $checkUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $checkTables = $pdo->query("SHOW TABLES LIKE 'daily_logs'")->rowCount();
        if ($checkTables > 0 && $checkUsers >= 1) {
            $installed = true;
            $message = 'Database sudah terinstal (sebagian query dilewati karena sudah ada).';
        } else {
            die("<div style='font-family:system-ui;padding:30px;max-width:600px;margin:50px auto;background:#fee2e2;border:1px solid #fca5a5;border-radius:12px;'>
                <h3 style='color:#991b1b;margin-top:0;'>❌ Installation Gagal</h3>
                <p style='color:#7f1d1d;'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
                <p style='font-size:13px;color:#991b1b;'>Cek koneksi MySQL di <code>config/config.php</code> (DB_HOST, DB_USER, DB_PASS) dan pastikan user MySQL memiliki akses CREATE DATABASE.</p>
                <p><a href='login.php' style='display:inline-block;padding:10px 20px;background:#111;color:#fff;border-radius:8px;text-decoration:none;'>Coba Login &rarr;</a></p>
            </div>");
        }
    } catch (Exception $ex) {
        die("<div style='font-family:system-ui;padding:30px;max-width:600px;margin:50px auto;background:#fee2e2;border:1px solid #fca5a5;border-radius:12px;'>
            <h3 style='color:#991b1b;margin-top:0;'>❌ Database Error</h3>
            <p style='color:#7f1d1d;'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
            <p style='font-size:13px;color:#991b1b;'>Pastikan MySQL di Laragon sudah aktif. Klik Start MySQL di Laragon Control Panel dulu.</p>
        </div>");
    }
}

if ($installed) {
    setFlash('success', $message . ' Default login: engineer@stregisbali.com / supervisor@stregisbali.com | Password: 123456');
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
