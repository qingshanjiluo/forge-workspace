<?php
require_once '../config.php';
requireLogin();

$filter = $_GET['filter'] ?? 'all';
$sort = $_GET['sort'] ?? 'created_at';

// 查询所有字段
$sql = "SELECT id, content, progress, priority, deadline, created_at FROM todos WHERE user_id = ?";

// 筛选
$params = [$_SESSION['user_id']];
if ($filter === 'active') {
    $sql .= " AND progress < 100";
} elseif ($filter === 'done') {
    $sql .= " AND progress >= 100";
} elseif ($filter === 'high') {
    $sql .= " AND priority = 'high'";
} elseif ($filter === 'expiring') {
    $sql .= " AND deadline IS NOT NULL AND deadline <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND progress < 100";
}

// 排序
if ($sort === 'priority') {
    $sql .= " ORDER BY CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 END, created_at DESC";
} elseif ($sort === 'deadline') {
    $sql .= " ORDER BY CASE WHEN deadline IS NULL THEN 1 ELSE 0 END, deadline ASC, created_at DESC";
} elseif ($sort === 'progress') {
    $sql .= " ORDER BY progress ASC, created_at DESC";
} else {
    $sql .= " ORDER BY created_at DESC";
}

$sql .= " LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$todos = $stmt->fetchAll();

foreach ($todos as &$todo) {
    $todo['created_at'] = date('Y-m-d H:i:s', strtotime($todo['created_at']));
    $todo['deadline'] = $todo['deadline'] ?: null;
}
jsonResponse($todos);
