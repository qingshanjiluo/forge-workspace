<?php
require_once '../config.php';
requireLogin();
if ($_SESSION['role'] !== 'admin') jsonResponse(['error' => '权限不足']);

$content = trim($_POST['content'] ?? '');
$stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('scroll_announcement', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
$stmt->execute([$content, $content]);
jsonResponse(['success' => true]);
?>