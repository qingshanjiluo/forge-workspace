<?php
require_once '../config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("INSERT INTO public_chat_presence (user_id, last_seen) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_seen = NOW()");
    $stmt->execute([$_SESSION['user_id']]);
    echo json_encode(['success' => true]);
    exit;
}

$stmt = $pdo->prepare("SELECT p.user_id, u.username, COALESCE(u.display_name, u.username) AS display_name
                       FROM public_chat_presence p
                       JOIN users u ON p.user_id = u.id
                       WHERE p.last_seen >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                       ORDER BY p.last_seen DESC");
$stmt->execute();
echo json_encode($stmt->fetchAll());
