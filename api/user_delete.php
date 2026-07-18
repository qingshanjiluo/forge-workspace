<?php
require_once '../config.php';
requireLogin();
if ($_SESSION['role'] !== 'admin') jsonResponse(['error' => '权限不足']);

$id = (int)$_POST['id'];
if ($id == $_SESSION['user_id']) jsonResponse(['error' => '不能删除自己']);

$stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
$stmt->execute([$id]);
jsonResponse(['success' => true]);