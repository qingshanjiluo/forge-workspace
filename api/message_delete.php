<?php
require_once '../config.php';
requireLogin();

$id = (int)$_POST['id'];
// 仅允许本人或管理员删除
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
if ($role === 'admin') {
    $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ?");
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user_id]);
}
jsonResponse(['success' => true]);