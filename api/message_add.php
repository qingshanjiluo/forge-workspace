<?php
require_once '../config.php';
requireLogin();

$content = trim($_POST['content'] ?? '');
if (empty($content)) jsonResponse(['error' => '内容不能为空']);

$stmt = $pdo->prepare("INSERT INTO messages (user_id, content) VALUES (?, ?)");
$stmt->execute([$_SESSION['user_id'], $content]);
jsonResponse(['success' => true]);