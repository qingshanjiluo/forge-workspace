<?php
require_once '../config.php';
requireLogin();

$id = (int)$_POST['id'];
$stmt = $pdo->prepare("SELECT content FROM todos WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $_SESSION['user_id']]);
$todo = $stmt->fetch();
if ($todo) {
    addLog($_SESSION['user_id'], "删除了任务「{$todo['content']}」");
}

$stmt = $pdo->prepare("DELETE FROM todos WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $_SESSION['user_id']]);
jsonResponse(['success' => true]);
?>