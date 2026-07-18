<?php
require_once '../config.php';
requireLogin();

$content = trim($_POST['content'] ?? '');
if (empty($content)) jsonResponse(['error' => '内容不能为空']);

$priority = $_POST['priority'] ?? 'medium';
if (!in_array($priority, ['high', 'medium', 'low'])) {
    $priority = 'medium';
}

$deadline = trim($_POST['deadline'] ?? '');
$deadline = $deadline ?: null;

$stmt = $pdo->prepare("INSERT INTO todos (user_id, content, priority, deadline) VALUES (?, ?, ?, ?)");
$stmt->execute([$_SESSION['user_id'], $content, $priority, $deadline]);

// 记录日志
$priorityLabel = ['high' => '高优先', 'medium' => '中优先', 'low' => '低优先'][$priority];
addLog($_SESSION['user_id'], "添加了新任务「{$content}」({$priorityLabel})");

jsonResponse(['success' => true, 'id' => $pdo->lastInsertId()]);
