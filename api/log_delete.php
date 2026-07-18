<?php
require_once '../config.php';
requireLogin();

$id = (int)$_POST['id'];
$stmt = $pdo->prepare("DELETE FROM logs WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $_SESSION['user_id']]);
jsonResponse(['success' => true]);