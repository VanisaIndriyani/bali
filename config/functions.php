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
