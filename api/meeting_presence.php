<?php
require_once '../config.php';
requireLogin();

$meeting_id = (int)($_REQUEST['meeting_id'] ?? 0);
if ($meeting_id <= 0) jsonResponse(['error' => '无效的会议ID']);

// POST: 更新在线状态（心跳）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("INSERT INTO meeting_presence (meeting_id, user_id, last_seen) VALUES (?, ?, NOW())
                           ON DUPLICATE KEY UPDATE last_seen = NOW()");
    $stmt->execute([$meeting_id, $_SESSION['user_id']]);
    jsonResponse(['success' => true]);
}

// GET: 获取在线用户列表（2分钟内活跃）
$stmt = $pdo->prepare("SELECT p.user_id, u.username, COALESCE(u.display_name, u.username) AS display_name, p.last_seen
                       FROM meeting_presence p
                       JOIN users u ON p.user_id = u.id
                       WHERE p.meeting_id = ? AND p.last_seen >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                       ORDER BY p.last_seen DESC");
$stmt->execute([$meeting_id]);
$users = $stmt->fetchAll();
jsonResponse($users);
