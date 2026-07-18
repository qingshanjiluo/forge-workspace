<?php requireLogin(); ?>
<div class="page-header">
    <h1><i class="fas fa-file-alt"></i> 共享文档 <small>协同编辑 · Markdown / HTML / 纯文本</small></h1>
</div>

<div id="docListView">
    <div class="card">
        <form id="docForm" class="flex gap-sm" style="flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:200px;">
                <label style="font-size:12px;color:var(--text-sec);display:block;margin-bottom:4px;">文档标题</label>
                <input type="text" id="docTitle" class="form-control" placeholder="输入文档标题..." required>
            </div>
            <div style="min-width:120px;">
                <label style="font-size:12px;color:var(--text-sec);display:block;margin-bottom:4px;">文档类型</label>
                <select id="docTypeSelect" class="form-control">
                    <option value="markdown">Markdown</option>
                    <option value="html">HTML</option>
                    <option value="txt">纯文本</option>
                </select>
            </div>
            <button type="submit" class="btn btn--primary" style="margin-bottom:0;"><i class="fas fa-plus"></i> 新建文档</button>
        </form>
    </div>

    <div class="card">
        <div class="card__title"><i class="fas fa-folder-open"></i> 所有文档</div>
        <div id="docList"><div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载中...</div></div>
    </div>
</div>

<div id="docEditorView" style="display:none;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
        <button class="btn btn--sm" id="backToDocs"><i class="fas fa-arrow-left"></i> 返回</button>
        <input type="text" id="editorTitle" class="form-control" style="flex:1;min-width:150px;font-size:16px;font-weight:600;padding:8px 12px;" placeholder="文档标题">
        <select id="editorType" class="form-control" style="width:auto;">
            <option value="markdown">Markdown</option>
            <option value="html">HTML</option>
            <option value="txt">纯文本</option>
        </select>
        <button class="btn btn--success btn--sm" id="saveDocBtn"><i class="fas fa-save"></i> 保存</button>
        <button class="btn btn--sm" id="shareDocBtn"><i class="fas fa-share-alt"></i> 分享</button>
        <button class="btn btn--sm" id="historyBtn"><i class="fas fa-history"></i> 版本</button>
    </div>

    <div style="display:flex;gap:16px;flex-wrap:wrap;">
        <div class="card" style="flex:1;min-width:300px;padding:12px;">
            <div style="font-size:12px;color:var(--text-ter);margin-bottom:8px;display:flex;gap:16px;">
                <span><i class="fas fa-user"></i> 作者: <span id="editorAuthor">-</span></span>
                <span><i class="fas fa-code-branch"></i> 版本: <span id="editorVersion">1</span></span>
                <span><i class="fas fa-clock"></i> <span id="editorUpdated">-</span></span>
            </div>
            <textarea id="editorContent" class="form-control" style="min-height:400px;font-family:'Courier New',monospace;font-size:14px;line-height:1.6;resize:vertical;" placeholder="在此输入文档内容..."></textarea>
        </div>

        <div class="card" style="width:380px;flex-shrink:0;padding:12px;display:none;" id="previewPanel">
            <div class="card__title" style="font-size:14px;"><i class="fas fa-eye"></i> 预览</div>
            <div id="previewContent" style="min-height:300px;overflow-y:auto;font-size:14px;line-height:1.8;padding:8px;"></div>
        </div>
    </div>
</div>

<!-- 版本历史弹窗 -->
<div id="historyModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:9999;display:none;align-items:center;justify-content:center;">
    <div style="background:var(--bg-card);border-radius:12px;padding:24px;width:90%;max-width:600px;max-height:80vh;overflow-y:auto;border:1px solid var(--border);box-shadow:0 8px 40px rgba(0,0,0,0.4);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <h3 style="margin:0;"><i class="fas fa-history"></i> 版本历史</h3>
            <button class="btn btn--sm" id="closeHistoryBtn"><i class="fas fa-times"></i></button>
        </div>
        <div id="revisionList"><div class="empty-state">加载中...</div></div>
    </div>
</div>

<script>
let currentVersion = 1;
let previewTimer = null;

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

// 简单 Markdown 渲染
function renderMarkdown(text) {
    let html = escapeHtml(text);
    html = html.replace(/^### (.+)$/gm, '<h3 style="margin:12px 0 6px;font-size:16px;font-weight:600;">$1</h3>');
    html = html.replace(/^## (.+)$/gm, '<h2 style="margin:16px 0 8px;font-size:20px;font-weight:700;">$1</h2>');
    html = html.replace(/^# (.+)$/gm, '<h1 style="margin:20px 0 10px;font-size:24px;font-weight:800;">$1</h1>');
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
    html = html.replace(/`([^`]+)`/g, '<code style="background:var(--bg);padding:1px 6px;border-radius:4px;font-size:12px;">$1</code>');
    html = html.replace(/^- (.+)$/gm, '<li style="margin:2px 0 2px 20px;">$1</li>');
    html = html.replace(/^\d+\. (.+)$/gm, '<li style="margin:2px 0 2px 20px;list-style:decimal;">$1</li>');
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" style="color:var(--accent);">$1</a>');
    html = html.replace(/(https?:\/\/[^\s<>"']+)/g, '<a href="$1" target="_blank" style="color:var(--accent);">$1</a>');
    // 按段落分割，正确处理换行
    const paragraphs = html.split(/\n\n+/);
    html = paragraphs.map(function(p) {
        return '<p style="margin:8px 0;">' + p.replace(/\n/g, '<br>') + '</p>';
    }).join('');
    return '<div style="padding:4px 0;">' + html + '</div>';
}

// ==================== 文档列表 ====================
async function loadDocuments() {
    const container = document.getElementById('docList');
    container.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载中...</div>';
    try {
        const res = await fetch('api/document_list.php');
        const docs = await res.json();
        if (docs.length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="fas fa-file-alt" style="font-size:40px;"></i> 还没有文档，点击上方创建吧！</div>';
            return;
        }
        container.innerHTML = docs.map(d => `
            <div class="doc-item" style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--border);flex-wrap:wrap;">
                <div style="flex:1;min-width:150px;">
                    <div style="font-weight:600;cursor:pointer;" class="open-doc" data-id="${d.id}">
                        <i class="fas fa-${d.doc_type === 'html' ? 'code' : (d.doc_type === 'markdown' ? 'pen-fancy' : 'file-alt')}" style="color:var(--accent);margin-right:6px;"></i>
                        ${escapeHtml(d.title)}
                    </div>
                    <div style="font-size:11px;color:var(--text-ter);margin-top:2px;">
                        ${escapeHtml(d.author_display || d.author_name)} · v${d.version} · ${d.updated_at}
                    </div>
                </div>
                <span style="font-size:11px;padding:2px 8px;border-radius:10px;background:var(--accent-soft);color:var(--accent);text-transform:uppercase;">${d.doc_type}</span>
                <div style="display:flex;gap:4px;">
                    <button class="btn btn--sm open-doc" data-id="${d.id}"><i class="fas fa-edit"></i> 编辑</button>
                    <button class="btn btn--sm btn--danger del-doc" data-id="${d.id}"><i class="fas fa-trash-alt"></i></button>
                </div>
            </div>`).join('');
        container.querySelectorAll('.open-doc').forEach(btn => {
            btn.addEventListener('click', function() { openEditor(parseInt(this.dataset.id)); });
        });
        container.querySelectorAll('.del-doc').forEach(btn => {
            btn.addEventListener('click', async function() {
                if (!confirm('确认删除此文档？')) return;
                await fetch('api/document_delete.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `id=${this.dataset.id}` });
                loadDocuments();
            });
        });
    } catch (_) {
        container.innerHTML = '<div class="alert alert--danger">文档加载失败</div>';
    }
}

// ==================== 文档编辑器 ====================
let currentDocId = null;
let isModified = false;

async function openEditor(id) {
    currentDocId = id;
    document.getElementById('docListView').style.display = 'none';
    document.getElementById('docEditorView').style.display = 'block';

    try {
        const res = await fetch(`api/document_get.php?id=${id}`);
        const doc = await res.json();
        document.getElementById('editorTitle').value = doc.title || '';
        document.getElementById('editorContent').value = doc.content || '';
        document.getElementById('editorType').value = doc.doc_type || 'markdown';
        document.getElementById('editorAuthor').textContent = doc.author_display || doc.author_name || '-';
        document.getElementById('editorVersion').textContent = 'v' + (doc.version || 1);
        document.getElementById('editorUpdated').textContent = doc.updated_at || '-';
        currentVersion = doc.version || 1;
        updatePreview();
    } catch (_) {
        alert('文档加载失败');
        closeEditor();
    }
}

function closeEditor() {
    if (isModified && !confirm('有未保存的更改，确定离开吗？')) return;
    isModified = false;
    currentDocId = null;
    document.getElementById('docEditorView').style.display = 'none';
    document.getElementById('docListView').style.display = 'block';
    loadDocuments();
}

window.addEventListener('beforeunload', function(e) {
    if (isModified) { e.preventDefault(); e.returnValue = ''; }
});

function updatePreview() {
    const type = document.getElementById('editorType').value;
    const content = document.getElementById('editorContent').value;
    const panel = document.getElementById('previewPanel');
    const preview = document.getElementById('previewContent');

    if (!content.trim()) { panel.style.display = 'none'; return; }
    panel.style.display = 'block';

    if (type === 'markdown') {
        preview.innerHTML = renderMarkdown(content);
    } else if (type === 'html') {
        preview.innerHTML = '<iframe srcdoc="' + escapeHtml(content) + '" sandbox="allow-same-origin" style="width:100%;height:400px;border:none;background:#fff;border-radius:8px;"></iframe>';
    } else {
        preview.innerHTML = '<pre style="white-space:pre-wrap;font-family:inherit;">' + escapeHtml(content) + '</pre>';
    }
}

// 跟踪修改状态 + 防抖预览
document.getElementById('editorContent').addEventListener('input', function() { isModified = true; if (previewTimer) clearTimeout(previewTimer); previewTimer = setTimeout(updatePreview, 500); });
document.getElementById('editorTitle').addEventListener('input', function() { isModified = true; });
document.getElementById('editorType').addEventListener('change', updatePreview);

async function saveDocument() {
    const title = document.getElementById('editorTitle').value.trim();
    const content = document.getElementById('editorContent').value;
    const docType = document.getElementById('editorType').value;
    if (!title) { alert('请输入文档标题'); return; }

    const summary = prompt('保存备注（可选，描述这次改动）：') || '';
    document.getElementById('saveDocBtn').disabled = true;

    try {
        const res = await fetch('api/document_save.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `id=${currentDocId || ''}&title=${encodeURIComponent(title)}&content=${encodeURIComponent(content)}&doc_type=${docType}&summary=${encodeURIComponent(summary)}`
        });
        const data = await res.json();
        if (data.success) {
            isModified = false;
            currentDocId = data.id;
            currentVersion = data.version;
            document.getElementById('editorVersion').textContent = 'v' + data.version;
            document.getElementById('editorUpdated').textContent = new Date().toISOString().replace('T', ' ').substring(0, 19);
            loadDocuments();
        } else {
            alert('保存失败: ' + (data.error || ''));
        }
    } catch (_) { alert('网络错误'); }
    document.getElementById('saveDocBtn').disabled = false;
}

async function shareDocument() {
    if (!currentDocId) return;
    const res = await fetch('api/document_share.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `id=${currentDocId}&action=generate`
    });
    const data = await res.json();
    if (data.share_token) {
        const shareUrl = window.location.origin + window.location.pathname.replace('index.php', '') + `pages/doc_view.php?share=${data.share_token}`;
        prompt('分享链接（点击复制）：', shareUrl);
    }
}

async function showHistory() {
    if (!currentDocId) return;
    const modal = document.getElementById('historyModal');
    modal.style.display = 'flex';
    document.getElementById('revisionList').innerHTML = '<div class="empty-state">加载中...</div>';

    try {
        const res = await fetch(`api/document_revisions.php?document_id=${currentDocId}`);
        const revisions = await res.json();
        const container = document.getElementById('revisionList');
        if (revisions.length === 0) {
            container.innerHTML = '<div class="empty-state">暂无历史版本</div>';
            return;
        }
        container.innerHTML = revisions.map(r => `
            <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);">
                <span style="background:var(--accent);color:#0b0a0c;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;">v${r.version}</span>
                <span style="flex:1;font-size:13px;">${escapeHtml(r.summary || r.display_name + ' 的编辑')}</span>
                <span style="font-size:11px;color:var(--text-ter);">${escapeHtml(r.display_name)}</span>
                <span style="font-size:11px;color:var(--text-ter);">${r.created_at}</span>
                <button class="btn btn--sm restore-version" data-doc-id="${currentDocId}" data-version="${r.version}" data-revision-id="${r.id}" style="font-size:11px;">还原</button>
            </div>`).join('');
        container.querySelectorAll('.restore-version').forEach(btn => {
            btn.addEventListener('click', async function() {
                if (!confirm(`将文档内容还原到 v${this.dataset.version}？`)) return;
                try {
                    const revRes = await fetch(`api/document_revisions.php?document_id=${this.dataset.docId}&revision_id=${this.dataset.revisionId}`);
                    const rev = await revRes.json();
                    if (rev.content !== undefined) {
                        document.getElementById('editorContent').value = rev.content;
                        updatePreview();
                        document.getElementById('historyModal').style.display = 'none';
                        alert(`已加载 v${this.dataset.version} 的内容，点击保存以完成还原。`);
                    }
                } catch(_) { alert('还原失败'); }
            });
        });
    } catch(_) {
        document.getElementById('revisionList').innerHTML = '<div class="alert alert--danger">加载失败</div>';
    }
}

// ==================== 事件绑定 ====================
document.getElementById('docForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const title = document.getElementById('docTitle').value.trim();
    if (!title) return alert('请输入标题');
    const res = await fetch('api/document_save.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `title=${encodeURIComponent(title)}&doc_type=${document.getElementById('docTypeSelect').value}&content=`
    });
    const data = await res.json();
    if (data.success) {
        document.getElementById('docTitle').value = '';
        openEditor(data.id);
    }
});

document.getElementById('backToDocs').addEventListener('click', closeEditor);
document.getElementById('saveDocBtn').addEventListener('click', saveDocument);
document.getElementById('shareDocBtn').addEventListener('click', shareDocument);
document.getElementById('historyBtn').addEventListener('click', showHistory);
document.getElementById('closeHistoryBtn').addEventListener('click', function() {
    document.getElementById('historyModal').style.display = 'none';
});
document.getElementById('historyModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

// 键盘快捷键
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        if (document.getElementById('docEditorView').style.display !== 'none') saveDocument();
    }
});

loadDocuments();
</script>
