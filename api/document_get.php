<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
$share_token = $_GET['share'] ?? '';

// 通过 share_token 访问时不强制登录；通过 ID 访问需要登录
if ($id > 0) {
    requireLogin();
}

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT d.*, u.username AS author_name, COALESCE(u.display_name, u.username) AS author_display,
                                  e.username AS editor_name
                           FROM shared_documents d
                           JOIN users u ON d.user_id = u.id
                           LEFT JOIN users e ON d.last_editor_id = e.id
                           WHERE d.id = ?");
    $stmt->execute([$id]);
} elseif ($share_token) {
    $stmt = $pdo->prepare("SELECT d.*, u.username AS author_name, COALESCE(u.display_name, u.username) AS author_display,
                                  e.username AS editor_name
                           FROM shared_documents d
                           JOIN users u ON d.user_id = u.id
                           LEFT JOIN users e ON d.last_editor_id = e.id
                           WHERE d.share_token = ?");
    $stmt->execute([$share_token]);
} else {
    echo json_encode(['error' => '请指定文档ID或分享令牌']);
    exit;
}

$doc = $stmt->fetch();
if (!$doc) { echo json_encode(['error' => '文档不存在']); exit; }

echo json_encode($doc);
