<?php
require_once '../config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $label = trim($_POST['label'] ?? 'API Token');
    $token = bin2hex(random_bytes(32));
    $expires = $_POST['expires_days'] ?? null;
    $expires_at = $expires ? date('Y-m-d H:i:s', strtotime("+{$expires} days")) : null;

    $stmt = $pdo->prepare("INSERT INTO auth_tokens (user_id, token, label, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $token, $label, $expires_at]);
    echo json_encode(['success' => true, 'token' => $token, 'label' => $label, 'expires_at' => $expires_at]);
    exit;
}

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT id, label, last_used_at, expires_at, created_at FROM auth_tokens WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['error' => '无效ID']); exit; }
    $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $_SESSION['user_id']]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => '不支持的请求方法']);
