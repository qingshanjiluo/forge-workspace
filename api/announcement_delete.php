<?php
require_once '../config.php';
requireLogin();

if ($_SESSION['role'] !== 'admin') jsonResponse(['error' => '权限不足']);

$id = (int)$_POST['id'];
$stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
$stmt->execute([$id]);
jsonResponse(['success' => true]);