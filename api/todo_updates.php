<?php
require_once '../config.php';
requireLogin();

$todo_id = (int)$_GET['todo_id'];
if ($todo_id <= 0) jsonResponse([]);

// 验证该TODO属于当前用户
$stmt = $pdo->prepare("SELECT id FROM todos WHERE id = ? AND user_id = ?");
$stmt->execute([$todo_id, $_SESSION['user_id']]);
if (!$stmt->fetch()) jsonResponse([]);

$stmt = $pdo->prepare("SELECT tu.id, tu.content, tu.progress, tu.created_at, u.username 
                       FROM todo_updates tu 
                       LEFT JOIN users u ON tu.user_id = u.id 
                       WHERE tu.todo_id = ? 
                       ORDER BY tu.created_at DESC");
$stmt->execute([$todo_id]);
$updates = $stmt->fetchAll();

foreach ($updates as &$u) {
    $u['created_at'] = date('Y-m-d H:i:s', strtotime($u['created_at']));
}
jsonResponse($updates);
