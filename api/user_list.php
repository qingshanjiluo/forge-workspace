<?php
require_once '../config.php';
requireLogin();
if ($_SESSION['role'] !== 'admin') jsonResponse(['error' => '权限不足']);

$stmt = $pdo->query("SELECT id, username, role, created_at FROM users ORDER BY id");
jsonResponse($stmt->fetchAll());