<?php
require_once '../config.php';
requireLogin();

$log_date = $_POST['log_date'] ?? '';
$content = trim($_POST['content'] ?? '');
if (!$log_date || empty($content)) jsonResponse(['error' => '请填写完整']);

$stmt = $pdo->prepare("INSERT INTO logs (user_id, log_date, content) VALUES (?, ?, ?)");
$stmt->execute([$_SESSION['user_id'], $log_date, $content]);
jsonResponse(['success' => true]);