<?php
require_once '../config.php';
requireLogin();
if ($_SESSION['role'] !== 'admin') jsonResponse(['error' => '权限不足']);

$id = (int)$_POST['id'];
$newPassword = $_POST['password'] ?? '';
if (strlen($newPassword) < 4) jsonResponse(['error' => '新密码至少4个字符']);

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->execute([$hash, $id]);
jsonResponse(['success' => true]);