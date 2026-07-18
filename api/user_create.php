<?php
require_once '../config.php';
requireLogin();
if ($_SESSION['role'] !== 'admin') jsonResponse(['error' => '权限不足']);

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
if (strlen($username) < 3) jsonResponse(['error' => '用户名至少3个字符']);
if (strlen($password) < 4) jsonResponse(['error' => '密码至少4个字符']);

// 检查重名
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$username]);
if ($stmt->fetch()) jsonResponse(['error' => '用户名已存在']);

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'member')");
$stmt->execute([$username, $hash]);
jsonResponse(['success' => true, 'id' => $pdo->lastInsertId()]);