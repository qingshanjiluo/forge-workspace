<?php
require_once '../config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { echo json_encode(['error' => '无效ID']); exit; }

$stmt = $pdo->prepare("SELECT user_id FROM shared_documents WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) { echo json_encode(['error' => '文档不存在']); exit; }
if ($doc['user_id'] != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
    echo json_encode(['error' => '无权删除']); exit;
}

$pdo->prepare("DELETE FROM document_revisions WHERE document_id = ?")->execute([$id]);
$pdo->prepare("DELETE FROM shared_documents WHERE id = ?")->execute([$id]);

echo json_encode(['success' => true]);
