<?php
session_start();
date_default_timezone_set('Asia/Shanghai');

define('DB_HOST', 'sql306.infinityfree.com');
define('DB_NAME', 'if0_42225417_1940');
define('DB_USER', 'if0_42225417');
define('DB_PASS', '1XSgihKKh8eaYY');

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET time_zone = '+08:00';");
} catch (PDOException $e) {
    die('数据库连接失败: ' . $e->getMessage());
}

function getCurrentUser() {
    global $pdo;
    if (!isset($_SESSION['user_id'])) return null;
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function addLog($user_id, $content, $log_date = null) {
    global $pdo;
    if (!$log_date) $log_date = date('Y-m-d');
    $stmt = $pdo->prepare("INSERT INTO logs (user_id, log_date, content) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $log_date, $content]);
}

function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 通过 Bearer token 进行 API 认证
 * 在 API 入口调用：if (!authenticateRequest()) { jsonResponse(['error' => '未授权']); exit; }
 */
function authenticateRequest() {
    global $pdo;
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        $token = $m[1];
        $stmt = $pdo->prepare("SELECT user_id FROM auth_tokens WHERE token = ? AND (expires_at IS NULL OR expires_at > NOW())");
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        if ($result) {
            $pdo->prepare("UPDATE auth_tokens SET last_used_at = NOW() WHERE token = ?")->execute([$token]);
            $_SESSION['user_id'] = (int)$result['user_id'];
            return true;
        }
    }
    return false;
}
?>