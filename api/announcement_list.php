<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->prepare("SELECT a.*, u.username FROM announcements a JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC");
$stmt->execute();
$anns = $stmt->fetchAll();

foreach ($anns as &$a) {
    $a['created_at'] = date('Y-m-d H:i:s', strtotime($a['created_at']));
}
jsonResponse($anns);
?>