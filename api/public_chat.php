<?php
require_once '../config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$meeting_id = null; // 公共聊天室无 meeting_id

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = trim($_POST['content'] ?? '');
    $message_type = $_POST['message_type'] ?? 'text';
    if (empty($content)) { echo json_encode(['error' => '消息不能为空']); exit; }
    if (mb_strlen($content) > 5000) { echo json_encode(['error' => '消息过长（最多5000字）']); exit; }

    $meta_data = null;

    // 处理 /todo 命令：创建待办事项
    if (strpos($content, '/todo ') === 0) {
        $todoContent = trim(substr($content, 6));
        if ($todoContent) {
            $stmt = $pdo->prepare("INSERT INTO todos (user_id, content, priority) VALUES (?, ?, 'medium')");
            $stmt->execute([$_SESSION['user_id'], $todoContent]);
            $todoId = $pdo->lastInsertId();
            $message_type = 'todo';
            $meta_data = json_encode(['todo_id' => $todoId, 'todo_content' => $todoContent], JSON_UNESCAPED_UNICODE);
            addLog($_SESSION['user_id'], "在公共聊天室通过 /todo 创建了任务: {$todoContent}");
        }
    }

    $stmt = $pdo->prepare("INSERT INTO public_chat_messages (user_id, content, message_type, meta_data) VALUES (?, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $content, $message_type, $meta_data]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

$since_id = (int)($_GET['since_id'] ?? 0);
$limit = min((int)($_GET['limit'] ?? 50), 100);

if ($since_id > 0) {
    $stmt = $pdo->prepare("SELECT m.id, m.user_id, m.content, m.message_type, LEFT(m.meta_data, 500) AS meta_data, m.created_at,
                                  u.username, COALESCE(u.display_name, u.username) AS display_name
                           FROM public_chat_messages m
                           JOIN users u ON m.user_id = u.id
                           WHERE m.id > ?
                           ORDER BY m.id ASC LIMIT ?");
    $stmt->execute([$since_id, $limit]);
} else {
    $stmt = $pdo->prepare("SELECT m.id, m.user_id, m.content, m.message_type, LEFT(m.meta_data, 500) AS meta_data, m.created_at,
                                  u.username, COALESCE(u.display_name, u.username) AS display_name
                           FROM public_chat_messages m
                           JOIN users u ON m.user_id = u.id
                           ORDER BY m.id DESC LIMIT ?");
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll();
    $rows = array_reverse($rows);
    echo json_encode($rows);
    exit;
}

$rows = $stmt->fetchAll();
echo json_encode($rows);
