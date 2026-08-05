<?php

global $LANG;
$LANG = [];

function initLanguage() {
    global $LANG;
    $allowed = ['id', 'en'];
    $current = 'id';

    if (isset($_GET['lang']) && in_array(strtolower((string)$_GET['lang']), $allowed, true)) {
        $current = strtolower((string)$_GET['lang']);
        $_SESSION['app_lang'] = $current;
    } elseif (isset($_SESSION['app_lang']) && in_array((string)$_SESSION['app_lang'], $allowed, true)) {
        $current = (string)$_SESSION['app_lang'];
    }

    $file = __DIR__ . '/lang/' . $current . '.php';
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) $LANG = $loaded;
    }

    if (!defined('APP_LANG')) define('APP_LANG', $current);
    if (!defined('APP_LANG_NAME')) define('APP_LANG_NAME', $current === 'en' ? 'English' : 'Indonesia');
}

function T($key, $fallback = '') {
    global $LANG;
    if (isset($LANG[$key]) && trim((string)$LANG[$key]) !== '') return (string)$LANG[$key];
    return $fallback !== '' ? $fallback : $key;
}

function isLoggedIn() {
    return isset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email'], $_SESSION['user_role']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        session_unset();
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}

function requireRole($role) {
    requireLogin();
    $userRole = (string)($_SESSION['user_role'] ?? '');
    $allowed = is_array($role) ? $role : [$role];
    if (!in_array($userRole, $allowed, true)) {
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

function currentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id'    => $_SESSION['user_id'],
        'name'  => (string)($_SESSION['user_name']   ?? ''),
        'email' => (string)($_SESSION['user_email']  ?? ''),
        'role'  => (string)($_SESSION['user_role']   ?? ''),
    ];
}

function redirect($url) {
    header('Location: ' . BASE_URL . ltrim($url, '/'));
    exit;
}

function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function cleanInput($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function formatDate($date, $format = 'd/m/Y') {
    return (new DateTime($date))->format($format);
}

function formatDateTime($date, $format = 'd/m/Y H:i') {
    return (new DateTime($date))->format($format);
}

function formatNumber($num) {
    return number_format($num, 0, ',', '.');
}

function handleFileUpload($fileInput, $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif']) {
    if (!isset($_FILES[$fileInput]) || $_FILES[$fileInput]['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No file uploaded or upload error'];
    }

    $file = $_FILES[$fileInput];
    
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'error' => 'Invalid file type. Allowed: JPG, PNG, GIF'];
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File too large. Max 10MB'];
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('dailylog_') . '.' . $ext;
    $target = UPLOAD_PATH . $filename;

    if (move_uploaded_file($file['tmp_name'], $target)) {
        return ['success' => true, 'filename' => $filename];
    }

    return ['success' => false, 'error' => 'Failed to move uploaded file'];
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'pending': return 'badge-warning';
        case 'approved': return 'badge-success';
        case 'rejected': return 'badge-danger';
        default: return 'badge-secondary';
    }
}

function getStatusText($status) {
    switch ($status) {
        case 'pending': return T('status_pending', 'Menunggu Approval');
        case 'approved': return T('status_approved', 'Disetujui');
        case 'rejected': return T('status_rejected', 'Ditolak');
        default: return $status;
    }
}

function hasRoleAccess($requiredRoles) {
    if (!isLoggedIn()) return false;
    return in_array($_SESSION['user_role'], (array)$requiredRoles);
}

function formatCurrency($num, $prefix = '') {
    $num = (float)$num;
    $negative = $num < 0;
    $formatted = number_format(abs($num), 2, ',', '.');
    return ($negative ? '-' : '') . $prefix . $formatted;
}

function getOrderStatusBadgeClass($status) {
    switch ($status) {
        case 'draft': return 'bg-gray-100 text-gray-700 border-gray-200';
        case 'pending_supervisor': return 'bg-yellow-50 text-yellow-700 border-yellow-200';
        case 'pending_manager': return 'bg-blue-50 text-blue-700 border-blue-200';
        case 'approved': return 'bg-green-50 text-green-700 border-green-200';
        case 'rejected': return 'bg-red-50 text-red-700 border-red-200';
        case 'completed': return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        default: return 'bg-gray-100 text-gray-700 border-gray-200';
    }
}

function getOrderStatusText($status) {
    switch ($status) {
        case 'draft': return T('order_status_draft', 'Draft');
        case 'pending_supervisor': return T('order_status_pending_spv', 'Menunggu Supervisor');
        case 'pending_manager': return T('order_status_pending_mgr', 'Menunggu Manager');
        case 'approved': return T('order_status_approved', 'Disetujui');
        case 'rejected': return T('order_status_rejected', 'Ditolak');
        case 'completed': return T('order_status_completed', 'Selesai');
        default: return $status;
    }
}

function generateOrderNo($db, $prefix = 'PR') {
    $ym = date('Ym');
    $sql = "SELECT MAX(CAST(SUBSTRING_INDEX(order_no, '-', -1) AS UNSIGNED)) as no_max FROM orders WHERE order_no LIKE ?";
    $row = $db->fetchOne($sql, ["{$prefix}-{$ym}-%"]);
    $next = (int)($row['no_max'] ?? 0) + 1;
    return sprintf('%s-%s-%04d', $prefix, $ym, $next);
}

function addOrderApproval($db, $orderId, $userId, $role, $action, $notes = null) {
    try {
        $db->query(
            "INSERT INTO order_approvals (order_id, user_id, role, action, notes) VALUES (?,?,?,?,?)",
            [(int)$orderId, (int)$userId, (string)$role, (string)$action, $notes]
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
