/**
 * chat-common.js — 聊天室通用工具函数
 * 被 meetings.php 和 public_chat.php 共享
 */

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

function extractUrls(text) {
    return text.match(/https?:\/\/[^\s<>"]+/g) || [];
}

function renderLinkPreview(preview, size) {
    const cls = size === 'sm' ? 'link-preview-card-sm' : 'link-preview-card';
    return `
        <a href="${escapeHtml(preview.url)}" target="_blank" rel="noopener" class="${cls}">
            <div style="display:flex;${preview.image ? '' : 'flex-direction:column;'}">
                ${preview.image ? `<div style="width:80px;min-height:60px;flex-shrink:0;background:#1a1a1e url(${escapeHtml(preview.image)}) center/cover no-repeat;"></div>` : ''}
                <div style="padding:8px 12px;flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(preview.title)}</div>
                    ${preview.description ? `<div style="font-size:11px;color:var(--text-ter);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(preview.description)}</div>` : ''}
                    <div style="font-size:10px;color:var(--accent);margin-top:4px;display:flex;align-items:center;gap:4px;">
                        ${preview.favicon ? `<img src="${escapeHtml(preview.favicon)}" style="width:12px;height:12px;" onerror="this.style.display='none'">` : '<i class="fas fa-link"></i>'}
                        ${escapeHtml(preview.domain)}
                    </div>
                </div>
            </div>
        </a>`;
}

function enhanceText(text) {
    if (!text) return '';
    let html = escapeHtml(text);
    html = html.replace(/`([^`]+)`/g, '<code style="background:var(--bg-card);padding:1px 6px;border-radius:4px;font-size:12px;border:1px solid var(--border);">$1</code>');
    html = html.replace(/(https?:\/\/[^\s<>"']+)/g, '<a href="$1" target="_blank" rel="noopener" style="color:var(--accent);text-decoration:underline;" data-url="$1">$1</a>');
    html = html.replace(/\n/g, '<br>');
    return html;
}

function renderMessageContent(m) {
    const type = m.message_type || 'text';
    let meta = {};
    try { meta = m.meta_data ? JSON.parse(m.meta_data) : {}; } catch(e) {}

    if (type === 'todo') {
        const todoContent = meta.todo_content || m.content.replace('/todo ', '');
        return `
            <div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:rgba(16,185,129,0.1);border-radius:8px;border-left:3px solid var(--success);">
                <i class="fas fa-check-circle" style="color:var(--success);font-size:16px;"></i>
                <div>
                    <div style="font-size:11px;color:var(--text-ter);">创建了待办任务</div>
                    <div style="font-weight:600;font-size:14px;">${escapeHtml(todoContent)}</div>
                </div>
                <a href="?page=todos" style="margin-left:auto;font-size:11px;color:var(--accent);" target="_blank">查看</a>
            </div>`;
    }

    if (type === 'html') {
        const htmlContent = m.content.replace(/\[html\]([\s\S]*?)\[\/html\]/g, '$1');
        return `
            <div style="margin:4px 0;">
                <div style="font-size:11px;color:var(--accent);margin-bottom:4px;"><i class="fas fa-code"></i> HTML 预览</div>
                <iframe srcdoc="${escapeHtml(htmlContent)}" sandbox="allow-same-origin"
                    style="width:100%;border:1px solid var(--border);border-radius:8px;max-height:400px;background:#fff;">
                </iframe>
            </div>`;
    }

    return `<div style="font-size:14px;line-height:1.6;word-break:break-word;">${enhanceText(m.content)}</div>`;
}

function scrollChatToBottom(el) {
    if (el) el.scrollTop = el.scrollHeight;
}

const previewCache = {};
async function checkLinkPreview(msg, containerSelector, size) {
    if (previewCache[msg.id] || (msg.message_type && msg.message_type !== 'text')) return;
    const urls = extractUrls(msg.content);
    if (urls.length === 0) return;
    previewCache[msg.id] = true;

    try {
        const res = await fetch(`https://forge-workspace.sifangzhiji.workers.dev/api/link-preview?url=${encodeURIComponent(urls[0])}`);
        const data = await res.json();
        if (data.title) {
            const el = document.querySelector(containerSelector.replace('{id}', msg.id));
            if (el) el.insertAdjacentHTML('beforeend', renderLinkPreview(data, size));
        }
    } catch(_) {}
}
