<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->prepare("SELECT m.*, u.username FROM messages m JOIN users u ON m.user_id = u.id ORDER BY m.created_at DESC");
$stmt->execute();
$msgs = $stmt->fetchAll();

foreach ($msgs as &$m) {
    $m['created_at'] = date('Y-m-d H:i:s', strtotime($m['created_at']));
}
jsonResponse($msgs);
?>