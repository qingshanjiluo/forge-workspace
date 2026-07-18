<?php
require_once '../config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$document_id = (int)($_GET['document_id'] ?? 0);
$revision_id = (int)($_GET['revision_id'] ?? 0);

if ($revision_id > 0) {
    $stmt = $pdo->prepare("SELECT r.*, u.username, COALESCE(u.display_name, u.username) AS display_name
                           FROM document_revisions r
                           JOIN users u ON r.user_id = u.id
                           WHERE r.id = ? AND r.document_id = ?");
    $stmt->execute([$revision_id, $document_id]);
    echo json_encode($stmt->fetch());
    exit;
}

$stmt = $pdo->prepare("SELECT r.*, u.username, COALESCE(u.display_name, u.username) AS display_name
                       FROM document_revisions r
                       JOIN users u ON r.user_id = u.id
                       WHERE r.document_id = ?
                       ORDER BY r.version DESC LIMIT 50");
$stmt->execute([$document_id]);
echo json_encode($stmt->fetchAll());
