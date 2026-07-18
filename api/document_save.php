<?php
require_once '../config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_POST['id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$content = $_POST['content'] ?? '';
$doc_type = $_POST['doc_type'] ?? 'markdown';
$summary = trim($_POST['summary'] ?? '');

if (!in_array($doc_type, ['markdown', 'html', 'txt'])) $doc_type = 'markdown';
if (empty($title)) { echo json_encode(['error' => '文档标题不能为空']); exit; }
if (mb_strlen($content) > 1048576) { echo json_encode(['error' => '文档内容过长']); exit; }

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT id, version FROM shared_documents WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    if (!$doc) { echo json_encode(['error' => '文档不存在']); exit; }

    $newVersion = $doc['version'] + 1;

    $stmt = $pdo->prepare("INSERT INTO document_revisions (document_id, user_id, content, version, summary) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$id, $_SESSION['user_id'], $content, $newVersion, $summary]);

    $stmt = $pdo->prepare("UPDATE shared_documents SET title = ?, content = ?, doc_type = ?, version = ?, last_editor_id = ? WHERE id = ?");
    $stmt->execute([$title, $content, $doc_type, $newVersion, $_SESSION['user_id'], $id]);

    echo json_encode(['success' => true, 'id' => $id, 'version' => $newVersion]);
} else {
    $stmt = $pdo->prepare("INSERT INTO shared_documents (title, content, doc_type, user_id, last_editor_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$title, $content, $doc_type, $_SESSION['user_id'], $_SESSION['user_id']]);
    $newId = $pdo->lastInsertId();

    // 创建初始版本记录
    $stmt = $pdo->prepare("INSERT INTO document_revisions (document_id, user_id, content, version, summary) VALUES (?, ?, ?, 1, '初始版本')");
    $stmt->execute([$newId, $_SESSION['user_id'], $content]);

    addLog($_SESSION['user_id'], "创建了共享文档: {$title}");

    echo json_encode(['success' => true, 'id' => $newId, 'version' => 1]);
}
