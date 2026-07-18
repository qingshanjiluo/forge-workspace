<?php requireLogin(); ?>
<div class="page-header">
    <h1><i class="fas fa-comment-dots"></i> 留言板 <small>团队交流空间</small></h1>
</div>

<div class="card">
    <form id="msgForm" class="flex gap-sm">
        <textarea id="msgContent" class="form-control" placeholder="写下你想说的话..." required style="flex:1;min-height:60px;"></textarea>
        <button type="submit" class="btn btn--primary" style="align-self:flex-end;"><i class="fas fa-paper-plane"></i> 发布留言</button>
    </form>
</div>

<div class="card">
    <div class="card__title"><i class="fas fa-inbox"></i> 所有留言</div>
    <div id="messageList">
        <div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载中...</div>
    </div>
</div>

<script>
async function loadMessages() {
    const res = await fetch('api/message_list.php');
    const msgs = await res.json();
    const container = document.getElementById('messageList');
    if (msgs.length === 0) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i> 还没有留言，来发表第一条吧！</div>';
        return;
    }
    container.innerHTML = msgs.map(m => `
        <div class="message-item">
            <div class="head">
                <span class="user"><i class="fas fa-user"></i> ${escapeHtml(m.username)}</span>
                <span class="text-ter">${m.created_at}</span>
            </div>
            <div class="body">${escapeHtml(m.content)}</div>
            <div class="footer">
                <?php if ($_SESSION['role'] === 'admin' || $_SESSION['user_id'] == $m['user_id']) : ?>
                <button class="btn btn--danger btn--sm del-msg" data-id="${m.id}"><i class="fas fa-trash-alt"></i> 删除</button>
                <?php endif; ?>
            </div>
        </div>
    `).join('');

    container.querySelectorAll('.del-msg').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('删除这条留言？')) return;
            const id = this.dataset.id;
            await fetch('api/message_delete.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `id=${id}` });
            loadMessages();
            updateMsgBadge();
        });
    });
}

document.getElementById('msgForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const content = document.getElementById('msgContent').value.trim();
    if (!content) return;
    await fetch('api/message_add.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `content=${encodeURIComponent(content)}`
    });
    document.getElementById('msgContent').value = '';
    loadMessages();
    updateMsgBadge();
});

async function updateMsgBadge() {
    const res = await fetch('api/message_count.php');
    const data = await res.json();
    document.getElementById('msgBadge').textContent = data.count || 0;
}

loadMessages();
</script>