<?php
require_once '../config.php';
requireLogin();

$user_id = isset($_GET['user_id']) && $_GET['user_id'] !== 'all' ? (int)$_GET['user_id'] : null;
$params = [];

$sql = "SELECT l.*, u.username FROM logs l JOIN users u ON l.user_id = u.id";
if ($user_id) {
    $sql .= " WHERE l.user_id = ?";
    $params[] = $user_id;
}
$sql .= " ORDER BY l.log_date DESC, l.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

foreach ($logs as &$log) {
    $log['log_date'] = date('Y-m-d', strtotime($log['log_date']));
    $log['created_at'] = date('Y-m-d H:i:s', strtotime($log['created_at']));
}
jsonResponse($logs);
?>