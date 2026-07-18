<?php
require_once '../config.php';
requireLogin();

$title = trim($_POST['title'] ?? '');
$meeting_time = $_POST['meeting_time'] ?? '';
$participants = trim($_POST['participants'] ?? '');
$description = trim($_POST['description'] ?? '');
if (empty($title) || !$meeting_time) jsonResponse(['error' => '请填写主题和时间']);

$stmt = $pdo->prepare("INSERT INTO meetings (user_id, title, description, meeting_time, participants) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$_SESSION['user_id'], $title, $description, $meeting_time, $participants]);
jsonResponse(['success' => true]);
?>