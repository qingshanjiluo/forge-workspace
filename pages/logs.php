<?php requireLogin(); ?>
<div class="page-header">
    <h1><i class="fas fa-history"></i> 工作日志 <small>记录每天的工作内容</small></h1>
</div>

<div class="card">
    <form id="logForm" class="flex" style="flex-wrap:wrap; gap:8px;">
        <input type="date" id="logDate" class="form-control" style="width:160px;" required>
        <textarea id="logContent" class="form-control" placeholder="今天做了什么..." style="flex:1;min-height:60px;" required></textarea>
        <button type="submit" class="btn btn--primary" style="align-self:flex-end;"><i class="fas fa-plus"></i> 添加日志</button>
    </form>
</div>

<div class="card">
    <div class="card__title" style="justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span><i class="fas fa-list"></i> 日志列表</span>
        <div style="display:flex;gap:8px;align-items:center;">
            <label style="font-size:13px;color:var(--text-sec);">筛选用户:</label>
            <select id="userFilter" class="form-control" style="width:auto;padding:4px 30px 4px 12px;font-size:13px;">
                <option value="all">全部</option>
            </select>
        </div>
    </div>
    <div id="logList">
        <div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载中...</div>
    </div>
</div>

<script>
let allUsers = [];

async function loadUsers() {
    const res = await fetch('api/user_list.php');
    const users = await res.json();
    allUsers = users;
    const filter = document.getElementById('userFilter');
    filter.innerHTML = '<option value="all">全部</option>';
    allUsers.forEach(u => {
        const selected = u.id == <?= $_SESSION['user_id'] ?> ? ' selected' : '';
        filter.innerHTML += `<option value="${u.id}"${selected}>${escapeHtml(u.username)}${u.id == <?= $_SESSION['user_id'] ?> ? ' (我)' : ''}</option>`;
    });
}

async function loadLogs(userId = 'all') {
    const container = document.getElementById('logList');
    container.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载中...</div>';

    try {
        let url = 'api/log_list.php';
        if (userId !== 'all') {
            url += `?user_id=${userId}`;
        }
        const res = await fetch(url);
        if (!res.ok) throw new Error('网络错误');
        const logs = await res.json();

        if (logs.length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i> 暂无日志</div>';
            return;
        }

        container.innerHTML = logs.map(l => `
            <div class="log-item">
                <div class="head">
                    <span class="user"><i class="fas fa-user"></i> ${escapeHtml(l.username)}</span>
                    <span class="text-ter"><i class="fas fa-calendar"></i> ${l.log_date} ${l.created_at.split(' ')[1]}</span>
                </div>
                <div class="body">${escapeHtml(l.content)}</div>
                <?php if ($_SESSION['role'] === 'admin' || $_SESSION['user_id'] == $l['user_id']) : ?>
                <div class="footer">
                    <button class="btn btn--danger btn--sm del-log" data-id="${l.id}"><i class="fas fa-trash-alt"></i> 删除</button>
                </div>
                <?php endif; ?>
            </div>
        `).join('');

        container.querySelectorAll('.del-log').forEach(btn => {
            btn.addEventListener('click', async function() {
                if (!confirm('确认删除此日志？')) return;
                const id = this.dataset.id;
                await fetch('api/log_delete.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `id=${id}` });
                loadLogs(document.getElementById('userFilter').value);
            });
        });

    } catch (error) {
        container.innerHTML = `<div class="alert alert--danger"><i class="fas fa-exclamation-circle"></i> 加载失败，<a href="javascript:loadLogs()" style="color:var(--accent);">点击重试</a></div>`;
    }
}

document.getElementById('logForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const date = document.getElementById('logDate').value;
    const content = document.getElementById('logContent').value.trim();
    if (!date || !content) return alert('请填写完整');
    await fetch('api/log_add.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `log_date=${encodeURIComponent(date)}&content=${encodeURIComponent(content)}`
    });
    document.getElementById('logContent').value = '';
    loadLogs(document.getElementById('userFilter').value);
});

document.getElementById('userFilter').addEventListener('change', function() {
    loadLogs(this.value);
});

document.getElementById('logDate').valueAsDate = new Date();

loadUsers().then(() => {
    loadLogs('all');
});
</script>