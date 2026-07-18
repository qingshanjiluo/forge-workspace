<?php
require_once '../config.php';
requireLogin();

$user_id = $_SESSION['user_id'];

// 计算该用户所有 TODO 的平均进度
$stmt = $pdo->prepare("SELECT AVG(progress) AS avg_progress FROM todos WHERE user_id = ?");
$stmt->execute([$user_id]);
$row = $stmt->fetch();
$avgProgress = $row['avg_progress'] !== null ? round($row['avg_progress']) : 0;

// 同时返回未完成数量（可选，以便前端保留计数显示）
$stmt2 = $pdo->prepare("SELECT COUNT(*) AS undone FROM todos WHERE user_id = ? AND progress < 100");
$stmt2->execute([$user_id]);
$undone = $stmt2->fetch()['undone'] ?? 0;

jsonResponse([
    'avg_progress' => $avgProgress,
    'undone' => $undone
]);