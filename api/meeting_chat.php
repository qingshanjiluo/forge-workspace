<?php
require_once '../config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$meeting_id = (int)($_REQUEST['meeting_id'] ?? 0);
if ($meeting_id <= 0) { echo json_encode(['error' => '无效的会议ID']); exit; }

$stmt = $pdo->prepare("SELECT id, title, status FROM meetings WHERE id = ?");
$stmt->execute([$meeting_id]);
$meeting = $stmt->fetch();
if (!$meeting) { echo json_encode(['error' => '会议不存在']); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = trim($_POST['content'] ?? '');
    $message_type = $_POST['message_type'] ?? 'text';
    if (empty($content)) { echo json_encode(['error' => '消息不能为空']); exit; }
    if (mb_strlen($content) > 5000) { echo json_encode(['error' => '消息过长（最多5000字）']); exit; }

    $meta_data = null;

    // /todo 命令：创建待办事项
    if (strpos($content, '/todo ') === 0) {
        $todoContent = trim(substr($content, 6));
        if ($todoContent) {
            $stmt2 = $pdo->prepare("INSERT INTO todos (user_id, content, priority) VALUES (?, ?, 'medium')");
            $stmt2->execute([$_SESSION['user_id'], $todoContent]);
            $todoId = $pdo->lastInsertId();
            $message_type = 'todo';
            $meta_data = json_encode(['todo_id' => $todoId, 'todo_content' => $todoContent], JSON_UNESCAPED_UNICODE);
            addLog($_SESSION['user_id'], "在会议 #{$meeting_id} 中通过 /todo 创建了任务: {$todoContent}");
        }
    }

    $stmt = $pdo->prepare("INSERT INTO meeting_messages (meeting_id, user_id, content, message_type, meta_data) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$meeting_id, $_SESSION['user_id'], $content, $message_type, $meta_data]);
    $msgId = $pdo->lastInsertId();

    $stmtPresence = $pdo->prepare("INSERT INTO meeting_presence (meeting_id, user_id, last_seen) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE last_seen = NOW()");
    $stmtPresence->execute([$meeting_id, $_SESSION['user_id']]);

    echo json_encode(['success' => true, 'id' => $msgId]);
    exit;
}

$since_id = (int)($_GET['since_id'] ?? 0);
$limit = min((int)($_GET['limit'] ?? 50), 100);

if ($since_id > 0) {
    $stmt = $pdo->prepare("SELECT m.id, m.meeting_id, m.user_id, m.content, m.message_type, LEFT(m.meta_data, 500) AS meta_data, m.created_at,
                                  u.username, COALESCE(u.display_name, u.username) AS display_name
                           FROM meeting_messages m
                           JOIN users u ON m.user_id = u.id
                           WHERE m.meeting_id = ? AND m.id > ?
                           ORDER BY m.id ASC LIMIT ?");
    $stmt->execute([$meeting_id, $since_id, $limit]);
} else {
    $stmt = $pdo->prepare("SELECT m.id, m.meeting_id, m.user_id, m.content, m.message_type, LEFT(m.meta_data, 500) AS meta_data, m.created_at,
                                  u.username, COALESCE(u.display_name, u.username) AS display_name
                           FROM meeting_messages m
                           JOIN users u ON m.user_id = u.id
                           WHERE m.meeting_id = ?
                           ORDER BY m.id DESC LIMIT ?");
    $stmt->execute([$meeting_id, $limit]);
    $rows = $stmt->fetchAll();
    $rows = array_reverse($rows);
    echo json_encode($rows);
    exit;
}

$rows = $stmt->fetchAll();
echo json_encode($rows);
