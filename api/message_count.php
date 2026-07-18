<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM messages");
$stmt->execute();
$row = $stmt->fetch();
jsonResponse(['count' => (int)$row['cnt']]);