<?php
require_once '../config.php';
requireLogin();

$todo_id = (int)($_POST['id'] ?? 0);
if ($todo_id <= 0) jsonResponse(['error' => '无效的任务ID']);

// 验证该TODO属于当前用户
$stmt = $pdo->prepare("SELECT id, content, progress FROM todos WHERE id = ? AND user_id = ?");
$stmt->execute([$todo_id, $_SESSION['user_id']]);
$todo = $stmt->fetch();
if (!$todo) jsonResponse(['error' => '任务不存在']);

$progress = isset($_POST['progress']) ? (int)$_POST['progress'] : null;
$content  = trim($_POST['content'] ?? '');
$remark   = trim($_POST['remark'] ?? '');
$priority = $_POST['priority'] ?? null;
$deadline = $_POST['deadline'] ?? null;

$changed = false;
$updateRemark = '';

// 更新进度
if ($progress !== null && $progress >= 0 && $progress <= 100 && $progress != $todo['progress']) {
    $stmt = $pdo->prepare("UPDATE todos SET progress = ? WHERE id = ?");
    $stmt->execute([$progress, $todo_id]);
    $updateRemark = $remark ?: '进度更新至 ' . $progress . '%';
    $changed = true;
}

// 更新内容
if ($content !== '' && $content !== $todo['content']) {
    $stmt = $pdo->prepare("UPDATE todos SET content = ? WHERE id = ?");
    $stmt->execute([$content, $todo_id]);
    if (!$changed) {
        $updateRemark = $remark ?: '内容已更新';
    }
    $changed = true;
}

// 更新优先级
if ($priority && in_array($priority, ['high', 'medium', 'low'])) {
    $stmt = $pdo->prepare("UPDATE todos SET priority = ? WHERE id = ?");
    $stmt->execute([$priority, $todo_id]);
    if (!$changed) {
        $updateRemark = '优先级调整为 ' . $priority;
    }
    $changed = true;
}

// 更新截止日期
if ($deadline !== null) {
    $stmt = $pdo->prepare("UPDATE todos SET deadline = ? WHERE id = ?");
    $stmt->execute([$deadline === '' ? null : $deadline, $todo_id]);
    if (!$changed) {
        $updateRemark = $deadline === '' ? '已清除截止日期' : '截止日期设为 ' . $deadline;
    }
    $changed = true;
}

// 只添加备注（无其他变更时）
if (!$changed && $remark !== '') {
    $updateRemark = $remark;
    $changed = true;
}

if ($changed) {
    // 记录更新历史
    $stmt = $pdo->prepare("INSERT INTO todo_updates (todo_id, user_id, content, progress) VALUES (?, ?, ?, ?)");
    $stmt->execute([$todo_id, $_SESSION['user_id'], $updateRemark, $progress]);

    // 记录日志
    addLog($_SESSION['user_id'], "更新了任务进度: {$todo['content']} → " . ($progress ?? $todo['progress']) . '%');
}

jsonResponse(['success' => true, 'remark' => $updateRemark]);
