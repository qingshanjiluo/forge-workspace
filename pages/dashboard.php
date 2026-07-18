<?php
requireLogin();
$user = getCurrentUser();
?>
<div class="page-header">
    <h1><i class="fas fa-th-large"></i> 仪表盘 <small>欢迎回来，<?= htmlspecialchars($user['username']) ?></small></h1>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card__title"><i class="fas fa-check-circle"></i> 待办事项</div>
        <div id="dashboardTodos">
            <div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载中...</div>
        </div>
        <a href="?page=todos" class="btn btn--primary btn--sm mt-md"><i class="fas fa-arrow-right"></i> 查看全部</a>
    </div>
    <div class="card">
        <div class="card__title"><i class="fas fa-bullhorn"></i> 最新公告</div>
        <div id="dashboardAnnouncements">
            <div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载中...</div>
        </div>
        <a href="?page=announcements" class="btn btn--primary btn--sm mt-md"><i class="fas fa-arrow-right"></i> 查看全部</a>
    </div>
</div>

<script>
async function loadDashboard() {
    try {
        const todoRes = await fetch('api/todo_list.php');
        const todos = await todoRes.json();
        const todoContainer = document.getElementById('dashboardTodos');
        if (todos.length === 0) {
            todoContainer.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i> 暂无待办</div>';
        } else {
            todoContainer.innerHTML = todos.slice(0, 3).map(t =>
                `<div class="todo-item" style="border-bottom:1px solid var(--border);padding:8px 0;">
                    <span style="display:flex;align-items:center;gap:8px;width:100%;">
                        <span class="checkbox ${t.progress >= 100 ? 'done' : ''}" style="pointer-events:none;width:16px;height:16px;border-radius:50%;border:2px solid var(--border);display:inline-block;flex-shrink:0;background:${t.progress >= 100 ? 'var(--success)' : 'transparent'};"></span>
                        <span class="todo-text ${t.progress >= 100 ? 'done-text' : ''}">${escapeHtml(t.content)}</span>
                        <span style="margin-left:auto;font-size:12px;color:var(--text-ter);">${t.progress}%</span>
                    </span>
                </div>`
            ).join('');
        }

        const annRes = await fetch('api/announcement_list.php');
        const anns = await annRes.json();
        const annContainer = document.getElementById('dashboardAnnouncements');
        if (anns.length === 0) {
            annContainer.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i> 暂无公告</div>';
        } else {
            annContainer.innerHTML = anns.slice(0, 3).map(a =>
                `<div class="announcement-item" style="border-bottom:1px solid var(--border);padding:8px 0;">
                    <div class="head"><span class="user"><i class="fas fa-bullhorn"></i> ${escapeHtml(a.title)}</span></div>
                    <div class="body" style="font-size:13px;color:var(--text-sec);">${escapeHtml(a.content)}</div>
                </div>`
            ).join('');
        }
    } catch (_) {}
}
loadDashboard();
</script>