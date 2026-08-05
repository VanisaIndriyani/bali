<?php
/**
 * Apply Migration: 2026-08-05 Order System (Purchase Request)
 * - Role 'manager' support (3-level approval: engineer -> supervisor -> manager)
 * - Tabel cost_codes (45 kode akun biaya dari kertas SPV)
 * - Tabel orders (header PR), order_items (detail), order_approvals (jejak approval)
 * AMAN dijalankan BERULANG KALI (cek via INFORMATION_SCHEMA)
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/install/apply_migration_20260805_order_system.php';
    $_SERVER['SCRIPT_NAME'] = '/AGUSTUS/regist_bali/install/apply_migration_20260805_order_system.php';
    $_SERVER['HTTPS'] = null;
}
if (session_status() === PHP_SESSION_NONE && headers_sent() === false) {
    define('SKIP_SESSION_START', true);
}

require_once __DIR__ . '/../config/config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre style=\"font-family: Consolas, monospace; font-size:13px; background:#f8f9fa; padding:20px; border:1px solid #e5e7eb; border-radius:12px;\">";
echo "=== ST REGIS BALI - ORDER SYSTEM MIGRATION ===\n";
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
$checkTbl = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");

// --- 1) Pastikan users.role support 'manager' + tambah kolom yang hilang ---
$roleColRow = $db->fetchOne("SHOW COLUMNS FROM users LIKE 'role'");
$roleEnumStr = (string)($roleColRow['Type'] ?? '');
if (stripos($roleEnumStr, "'manager'") === false) {
    try {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','supervisor','engineer','manager') NOT NULL DEFAULT 'engineer'");
        echo "[UPDATE ✅] Kolom users.role ditambah opsi 'manager'\n";
    } catch (Throwable $e) {
        echo "[SKIP/ERR] users.role: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP  ✅] users.role SUDAH support 'manager'\n";
}

// Tambah kolom 'status' dan 'created_by' jika belum ada
$addCols = [
    'status' => "ALTER TABLE users ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER role",
    'created_by' => "ALTER TABLE users ADD COLUMN created_by INT UNSIGNED NULL AFTER signature_image",
];
foreach ($addCols as $cname => $addSql) {
    $checkCol->execute([$dbName, 'users', $cname]);
    if ((int)$checkCol->fetchColumn() === 0) {
        try { $pdo->exec($addSql); echo "[UPDATE ✅] Tambah kolom users.{$cname}\n"; }
        catch (Throwable $e) { echo "[ERROR] users.{$cname}: " . $e->getMessage() . "\n"; }
    } else {
        echo "[SKIP  ✅] users.{$cname} SUDAH ADA\n";
    }
}

// --- 2) Create cost_codes table ---
$checkTbl->execute([$dbName, 'cost_codes']);
if ((int)$checkTbl->fetchColumn() === 0) {
    $createCost = "CREATE TABLE cost_codes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        is_active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try {
        $pdo->exec($createCost);
        echo "[CREATE 🔥] Tabel cost_codes BERHASIL\n";
    } catch (Throwable $e) {
        echo "[ERROR ❌] cost_codes: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP  ✅] Tabel cost_codes SUDAH ADA\n";
}

// --- 3) Insert 45 cost codes (dari kertas SPV) ---
$costCodes = [
    ['606101','Printing and Stationery'],
    ['610108','Operating Supplies'],
    ['610109','Electric Supplies'],
    ['610111','Maintenance Supplies'],
    ['610112','Uniforms Costs'],
    ['610117','Light Bulbs'],
    ['611001','Office Supplies'],
    ['616211','Contract Services'],
    ['630003','Plumbing Maintenance'],
    ['630009','Gen Bldg Laundry Maintenance'],
    ['630010','Pool Equip-Repair Maintenance'],
    ['630019','Kitchen Equip Repair'],
    ['630102','POM Lock Maintenance'],
    ['630103','Tools'],
    ['630110','Air Filters'],
    ['630112','Elec Mech Guest Room'],
    ['630115','Elec Mech Kitchen'],
    ['630117','Elec Mech Laundry'],
    ['630141','Air Cond Repair'],
    ['630145','Refrig Kitchen'],
    ['630218','Non Mc Elevator'],
    ['631102','MC AC Chillers'],
    ['631103','Mc - Elevators'],
    ['631122','Mc - Water Treatment'],
    ['631123','Mc - Fire Protection'],
    ['631124','Mc - Pest Control'],
    ['631126','Mc - Kitchen Hood Clng'],
    ['631134','Mc - Pool -Spa'],
    ['631137','MC Health Club Equipment'],
    ['632101','Building Repair'],
    ['632104','Painting and Wallcovering'],
    ['631208','Cleaning Contract'],
    ['632109','Floor Covering'],
    ['632131','Furniture'],
    ['632301','Fire Prevention Systems'],
    ['632401','Electrical-Mechanical'],
    ['632602','Gen Bldg Guest Rooms'],
    ['632605','Gen Bldg Kitchen'],
    ['632610','Gen Bldg Repair Misc'],
    ['634101','Plants Maintenance'],
    ['634105','Grounds - Maintenance'],
    ['651811','Vehicle Repairs and Maint'],
    ['664901','In-House Laundry'],
    ['690001','Miscellaneous'],
    ['691003','Licenses - Fees'],
];

$insCost = $pdo->prepare("INSERT IGNORE INTO cost_codes (code, name, is_active) VALUES (?, ?, 1)");
$costAdded = 0; $costSkip = 0;
foreach ($costCodes as [$c, $n]) {
    try { $insCost->execute([$c, $n]); $costAdded += $insCost->rowCount(); }
    catch (Throwable $e) { $costSkip++; }
}
$totalCost = (int)$db->fetchOne("SELECT COUNT(*) as c FROM cost_codes")['c'];
echo "   => 45 Cost Codes dimuat: IGNORE NEW = {$costAdded}, Total sekarang = {$totalCost}\n\n";

// --- 4) Create orders table ---
$checkTbl->execute([$dbName, 'orders']);
if ((int)$checkTbl->fetchColumn() === 0) {
    $createOrd = "CREATE TABLE orders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_no VARCHAR(50) NOT NULL UNIQUE,
        requested_by INT UNSIGNED NOT NULL,
        cost_code_id INT UNSIGNED NULL,
        title VARCHAR(255) NOT NULL,
        purpose TEXT NULL,
        requested_date DATE NOT NULL,
        needed_date DATE NULL,
        total_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        status ENUM('draft','pending_supervisor','pending_manager','approved','rejected','completed') NOT NULL DEFAULT 'pending_supervisor',
        supervisor_id INT UNSIGNED NULL,
        manager_id INT UNSIGNED NULL,
        supervisor_approved_at TIMESTAMP NULL,
        manager_approved_at TIMESTAMP NULL,
        rejected_by INT UNSIGNED NULL,
        rejected_reason TEXT NULL,
        rejected_at TIMESTAMP NULL,
        completed_at TIMESTAMP NULL,
        notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_req (requested_by),
        INDEX idx_cost (cost_code_id),
        INDEX idx_date (requested_date),
        CONSTRAINT fk_ord_req FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_ord_cost FOREIGN KEY (cost_code_id) REFERENCES cost_codes(id) ON DELETE SET NULL,
        CONSTRAINT fk_ord_spv FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_ord_mgr FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_ord_rej FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try {
        $pdo->exec($createOrd);
        echo "[CREATE 🔥] Tabel orders BERHASIL\n";
    } catch (Throwable $e) {
        echo "[ERROR ❌] orders: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP  ✅] Tabel orders SUDAH ADA\n";
}

// --- 5) Create order_items table ---
$checkTbl->execute([$dbName, 'order_items']);
if ((int)$checkTbl->fetchColumn() === 0) {
    $createItem = "CREATE TABLE order_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        qty DECIMAL(12,2) NOT NULL DEFAULT 1.00,
        unit VARCHAR(30) NULL,
        unit_price DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        subtotal DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ord (order_id),
        CONSTRAINT fk_item_ord FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try {
        $pdo->exec($createItem);
        echo "[CREATE 🔥] Tabel order_items BERHASIL\n";
    } catch (Throwable $e) {
        echo "[ERROR ❌] order_items: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP  ✅] Tabel order_items SUDAH ADA\n";
}

// --- 6) Create order_approvals table (jejak audit) ---
$checkTbl->execute([$dbName, 'order_approvals']);
if ((int)$checkTbl->fetchColumn() === 0) {
    $createApp = "CREATE TABLE order_approvals (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        role ENUM('engineer','supervisor','manager','admin') NOT NULL,
        action ENUM('submit','approve','reject','update','complete') NOT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ord (order_id),
        INDEX idx_user (user_id),
        CONSTRAINT fk_app_ord FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_app_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try {
        $pdo->exec($createApp);
        echo "[CREATE 🔥] Tabel order_approvals BERHASIL\n";
    } catch (Throwable $e) {
        echo "[ERROR ❌] order_approvals: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP  ✅] Tabel order_approvals SUDAH ADA\n";
}

// --- 7) Buat default user Manager jika belum ada ---
$mgrEmail = 'manager@stregisbali.com';
$existMgr = $db->fetchOne("SELECT id FROM users WHERE email = ?", [$mgrEmail]);
if (!$existMgr) {
    try {
        $mgrPass = password_hash('manager123', PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("INSERT INTO users (name, email, password, role, status, created_by) VALUES (?,?,?,?,?,?)")
            ->execute(['Manager Engineering', $mgrEmail, $mgrPass, 'manager', 'active', 1]);
        echo "[CREATE 🔥] User Manager default dibuat. Email=manager@stregisbali.com, Password=manager123\n";
    } catch (Throwable $e) {
        echo "[SKIP/ERR] User Manager: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP  ✅] User Manager SUDAH ADA (id={$existMgr['id']})\n";
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "=== MIGRATION ORDER SYSTEM SELESAI ===\n";
echo "- Role users    : support manager (3 level: engineer -> spv -> manager)\n";
echo "- Tabel baru    : cost_codes, orders, order_items, order_approvals\n";
echo "- Cost Codes    : 45 kode akun biaya dari kertas SPV siap dipakai\n";
echo "- Default User  : manager@stregisbali.com / manager123 (GANTI SECEPKNYA!)\n";
echo str_repeat("=", 70) . "\n";
echo "</pre>";
