<?php
require_once '../config.php';
requireLogin();

if ($_SESSION['role'] !== 'admin') jsonResponse(['error' => '权限不足']);

$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');
if (empty($title) || empty($content)) jsonResponse(['error' => '请填写完整']);

$stmt = $pdo->prepare("INSERT INTO announcements (user_id, title, content) VALUES (?, ?, ?)");
$stmt->execute([$_SESSION['user_id'], $title, $content]);
jsonResponse(['success' => true]);