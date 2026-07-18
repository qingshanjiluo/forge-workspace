<?php
requireLogin();
if ($_SESSION['role'] !== 'admin') {
    echo '<div class="alert alert--danger">您没有权限访问此页面</div>';
    return;
}
?>
<div class="page-header">
    <h1><i class="fas fa-user-cog"></i> 用户管理 <small>创建 / 重置账户</small></h1>
</div>

<div class="card">
    <div class="card__title"><i class="fas fa-plus-circle"></i> 创建新用户</div>
    <form id="createUserForm" class="flex" style="flex-wrap:wrap; gap:8px;">
        <input type="text" id="newUsername" class="form-control" placeholder="用户名" style="flex:1;min-width:140px;" required>
        <input type="text" id="newPassword" class="form-control" placeholder="密码" style="flex:1;min-width:140px;" required>
        <button type="submit" class="btn btn--success"><i class="fas fa-user-plus"></i> 创建</button>
    </form>
    <div id="createMsg" class="mt-sm" style="font-size:14px;"></div>
</div>

<div class="card">
    <div class="card__title"><i class="fas fa-users"></i> 现有用户</div>
    <div id="userList">
        <div class="empty-state">加载中...</div>
    </div>
</div>

<div class="card">
    <div class="card__title"><i class="fas fa-bullhorn"></i> 滚动公告设置</div>
    <form id="announcementForm" class="flex gap-sm">
        <input type="text" id="announcementInput" class="form-control" placeholder="输入公告内容..." style="flex:1;">
        <button type="submit" class="btn btn--primary"><i class="fas fa-save"></i> 保存</button>
    </form>
    <div id="announcementMsg" class="mt-sm"></div>
    <div style="margin-top:8px;font-size:13px;color:var(--text-ter);">当前公告：<span id="currentAnnouncement"></span></div>
</div>

<script>
async function loadUsers() {
    const res = await fetch('api/user_list.php');
    const users = await res.json();
    const container = document.getElementById('userList');
    if (users.error) {
        container.innerHTML = '<div class="alert alert--danger">' + users.error + '</div>';
        return;
    }
    if (users.length === 0) {
        container.innerHTML = '<div class="empty-state">暂无用户</div>';
        return;
    }
    container.innerHTML = users.map(u => `
        <div class="todo-item" style="border-bottom:1px solid var(--border);padding:8px 0;">
            <span style="flex:1;">${escapeHtml(u.username)}</span>
            <span style="color:var(--text-ter);font-size:12px;">${u.role}</span>
            <span style="color:var(--text-ter);font-size:12px;">${new Date(u.created_at).toLocaleDateString()}</span>
            <div class="todo-actions">
                <button class="reset-pwd" data-id="${u.id}" data-name="${escapeHtml(u.username)}" style="background:none;border:none;color:var(--accent);cursor:pointer;"><i class="fas fa-key"></i> 重置密码</button>
                <?php if ($_SESSION['user_id'] != 1) : ?><button class="del-user" data-id="${u.id}" style="background:none;border:none;color:var(--danger);cursor:pointer;"><i class="fas fa-trash-alt"></i></button><?php endif; ?>
            </div>
        </div>
    `).join('');

    container.querySelectorAll('.reset-pwd').forEach(btn => {
        btn.addEventListener('click', async function() {
            const newPwd = prompt('请输入新密码（至少4位）', '123456');
            if (!newPwd || newPwd.length < 4) return alert('密码至少4位');
            const id = this.dataset.id;
            const res = await fetch('api/user_reset.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `id=${id}&password=${encodeURIComponent(newPwd)}`
            });
            const data = await res.json();
            if (data.success) {
                alert('密码已重置');
            } else {
                alert('错误：' + (data.error || '未知错误'));
            }
        });
    });

    container.querySelectorAll('.del-user').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('确认删除此用户？')) return;
            const id = this.dataset.id;
            const res = await fetch('api/user_delete.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `id=${id}`
            });
            const data = await res.json();
            if (data.success) {
                loadUsers();
            } else {
                alert('错误：' + (data.error || '未知错误'));
            }
        });
    });
}

document.getElementById('createUserForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const username = document.getElementById('newUsername').value.trim();
    const password = document.getElementById('newPassword').value.trim();
    if (!username || !password) return alert('请填写完整');
    const res = await fetch('api/user_create.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}`
    });
    const data = await res.json();
    const msg = document.getElementById('createMsg');
    if (data.success) {
        msg.innerHTML = '<span style="color:var(--success);"><i class="fas fa-check-circle"></i> 用户创建成功</span>';
        this.reset();
        loadUsers();
    } else {
        msg.innerHTML = '<span style="color:var(--danger);"><i class="fas fa-exclamation-circle"></i> ' + (data.error || '创建失败') + '</span>';
    }
});

// 公告管理
async function loadCurrentAnnouncement() {
    const res = await fetch('api/scroll_announcement.php');
    const data = await res.json();
    document.getElementById('currentAnnouncement').textContent = data.content || '(空)';
}

document.getElementById('announcementForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const content = document.getElementById('announcementInput').value.trim();
    const res = await fetch('api/update_announcement.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `content=${encodeURIComponent(content)}`
    });
    const data = await res.json();
    const msg = document.getElementById('announcementMsg');
    if (data.success) {
        msg.innerHTML = '<span style="color:var(--success);"><i class="fas fa-check-circle"></i> 公告已更新</span>';
        loadCurrentAnnouncement();
        // 刷新顶部公告（如果当前页面是index，可重新加载）
        if (window.loadAnnouncement) window.loadAnnouncement();
    } else {
        msg.innerHTML = '<span style="color:var(--danger);"><i class="fas fa-exclamation-circle"></i> ' + (data.error || '更新失败') + '</span>';
    }
});

loadCurrentAnnouncement();
loadUsers();
</script>