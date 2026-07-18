<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->prepare("SELECT * FROM meetings ORDER BY meeting_time DESC LIMIT 200");
$stmt->execute();
$meetings = $stmt->fetchAll();

foreach ($meetings as &$m) {
    $m['meeting_time'] = date('Y-m-d H:i:s', strtotime($m['meeting_time']));
    $m['created_at'] = date('Y-m-d H:i:s', strtotime($m['created_at']));
}
jsonResponse($meetings);
?>