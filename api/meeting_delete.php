<?php
require_once '../config.php';
requireLogin();

$id = (int)$_POST['id'];
$action = $_POST['action'] ?? 'delete';

// 权限校验：仅创建者或 admin 可操作
$stmt = $pdo->prepare("SELECT user_id FROM meetings WHERE id = ?");
$stmt->execute([$id]);
$meeting = $stmt->fetch();
if (!$meeting) jsonResponse(['error' => '会议不存在']);
if ($meeting['user_id'] != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
    jsonResponse(['error' => '无权操作']);
}

if ($action === 'end') {
    $stmt = $pdo->prepare("UPDATE meetings SET status = 'ended' WHERE id = ?");
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("DELETE FROM meetings WHERE id = ?");
    $stmt->execute([$id]);
}
jsonResponse(['success' => true]);
?>