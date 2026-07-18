<?php
require_once '../config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'generate';

if ($id <= 0) { echo json_encode(['error' => '无效ID']); exit; }

$stmt = $pdo->prepare("SELECT id, share_token FROM shared_documents WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) { echo json_encode(['error' => '文档不存在']); exit; }

if ($action === 'revoke') {
    $stmt = $pdo->prepare("UPDATE shared_documents SET share_token = NULL WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true, 'share_token' => null]);
    exit;
}

if (!$doc['share_token']) {
    $token = bin2hex(random_bytes(24));
    $stmt = $pdo->prepare("UPDATE shared_documents SET share_token = ? WHERE id = ?");
    $stmt->execute([$token, $id]);
} else {
    $token = $doc['share_token'];
}

echo json_encode(['success' => true, 'share_token' => $token]);
