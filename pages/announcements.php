<?php requireLogin(); ?>
<div class="page-header">
    <h1><i class="fas fa-bullhorn"></i> 公告 <small>重要通知</small></h1>
</div>

<?php if ($_SESSION['role'] === 'admin') : ?>
<div class="card">
    <form id="annForm" class="flex" style="flex-wrap:wrap; gap:8px;">
        <input type="text" id="annTitle" class="form-control" placeholder="公告标题" style="flex:1;min-width:200px;" required>
        <textarea id="annContent" class="form-control" placeholder="公告内容..." style="flex:2;min-height:60px;" required></textarea>
        <button type="submit" class="btn btn--primary" style="align-self:flex-end;"><i class="fas fa-plus"></i> 发布公告</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="card__title"><i class="fas fa-list"></i> 全部公告</div>
    <div id="announcementList">
        <div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载中...</div>
    </div>
</div>

<script>
async function loadAnnouncements() {
    const res = await fetch('api/announcement_list.php');
    const anns = await res.json();
    const container = document.getElementById('announcementList');
    if (anns.length === 0) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i> 暂无公告</div>';
        return;
    }
    container.innerHTML = anns.map(a => `
        <div class="announcement-item">
            <div class="head">
                <span class="user"><i class="fas fa-bullhorn"></i> ${escapeHtml(a.title)}</span>
                <span class="text-ter">${a.created_at}</span>
            </div>
            <div class="body">${escapeHtml(a.content)}</div>
            <?php if ($_SESSION['role'] === 'admin') : ?>
            <div class="footer">
                <button class="btn btn--danger btn--sm del-ann" data-id="${a.id}"><i class="fas fa-trash-alt"></i> 删除</button>
            </div>
            <?php endif; ?>
        </div>
    `).join('');

    container.querySelectorAll('.del-ann').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('确认删除此公告？')) return;
            const id = this.dataset.id;
            await fetch('api/announcement_delete.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `id=${id}` });
            loadAnnouncements();
        });
    });
}

<?php if ($_SESSION['role'] === 'admin') : ?>
document.getElementById('annForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const title = document.getElementById('annTitle').value.trim();
    const content = document.getElementById('annContent').value.trim();
    if (!title || !content) return alert('请填写完整');
    await fetch('api/announcement_add.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `title=${encodeURIComponent(title)}&content=${encodeURIComponent(content)}`
    });
    this.reset();
    loadAnnouncements();
});
<?php endif; ?>

loadAnnouncements();
</script>