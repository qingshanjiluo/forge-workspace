<?php
require_once '../config.php';
requireLogin();

$old = $_POST['old_password'] ?? '';
$new = $_POST['new_password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';

if (strlen($new) < 4) jsonResponse(['error' => '新密码至少4位']);
if ($new !== $confirm) jsonResponse(['error' => '两次密码不一致']);

$stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
if (!$user || !password_verify($old, $user['password'])) {
    jsonResponse(['error' => '原密码错误']);
}

$hash = password_hash($new, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->execute([$hash, $_SESSION['user_id']]);
jsonResponse(['success' => true]);
?>