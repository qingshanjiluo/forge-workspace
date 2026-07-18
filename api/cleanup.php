<?php
require_once __DIR__ . '/../config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$user = getCurrentUser();
if (!$user || $user['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => '仅管理员可执行清理']);
    exit;
}

$retention_days = max(1, (int)($_GET['days'] ?? 90));
$cutoff = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

$deleted_meeting = 0;
$deleted_public = 0;

try {
    $stmt = $pdo->prepare("DELETE FROM meeting_messages WHERE created_at < ?");
    $stmt->execute([$cutoff]);
    $deleted_meeting = $stmt->rowCount();

    $stmt = $pdo->prepare("DELETE FROM public_chat_messages WHERE created_at < ?");
    $stmt->execute([$cutoff]);
    $deleted_public = $stmt->rowCount();

    echo json_encode([
        'success' => true,
        'retention_days' => $retention_days,
        'deleted_meeting_messages' => $deleted_meeting,
        'deleted_public_messages' => $deleted_public,
        'cutoff_date' => $cutoff
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => '清理失败: ' . $e->getMessage()]);
}
