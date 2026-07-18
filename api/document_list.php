<?php
require_once '../config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$stmt = $pdo->prepare("SELECT d.id, d.title, LEFT(d.content, 200) AS content_preview, d.doc_type, d.user_id, d.last_editor_id,
                              d.version, d.share_token, d.created_at, d.updated_at,
                              u.username AS author_name, COALESCE(u.display_name, u.username) AS author_display,
                              e.username AS editor_name
                       FROM shared_documents d
                       JOIN users u ON d.user_id = u.id
                       LEFT JOIN users e ON d.last_editor_id = e.id
                       ORDER BY d.updated_at DESC LIMIT 100");
$stmt->execute();
$docs = $stmt->fetchAll();
echo json_encode($docs);
