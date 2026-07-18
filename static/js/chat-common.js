/**
 * chat-common.js — 聊天室通用工具函数
 * 被 meetings.php 和 public_chat.php 共享
 */

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

// 安全策略：剥离脚本/事件处理器/危险标签，仅保留基础展示标签
const ALLOWED_HTML_TAGS = /^(p|b|i|u|strong|em|br|hr|h1|h2|h3|h4|h5|h6|ul|ol|li|div|span|table|thead|tbody|tr|td|th|blockquote|code|pre|a|img|font|small|mark|sub|sup)$/i;
const ALLOWED_HTML_ATTRS = /^(href|src|alt|title|width|height|style|target|rel|class|id)$/i;
function sanitizeHtmlFragment(html) {
    const tpl = document.createElement('template');
    tpl.innerHTML = html || '';
    (function walk(node) {
        const childs = Array.from(node.childNodes);
        childs.forEach(child => {
            if (child.nodeType === 1) {
                const tag = child.tagName.toLowerCase();
                if (!ALLOWED_HTML_TAGS.test(tag) || tag === 'script' || tag === 'iframe' || tag === 'object' || tag === 'embed' || tag === 'form' || tag === 'input' || tag === 'link' || tag === 'meta' || tag === 'style') {
                    child.remove(); return;
                }
                Array.from(child.attributes).forEach(attr => {
                    const an = attr.name.toLowerCase();
                    if (!ALLOWED_HTML_ATTRS.test(an)) { child.removeAttribute(attr.name); return; }
                    if (/^\s*javascript:/i.test(attr.value) || /on\w+\s*=/.test(attr.value)) { child.removeAttribute(attr.name); return; }
                    if (an === 'style' && /expression|url\s*\(|@import/i.test(attr.value)) { child.removeAttribute(attr.name); return; }
                });
                if (tag === 'a') { child.setAttribute('target', '_blank'); child.setAttribute('rel', 'noopener noreferrer'); }
                walk(child);
            }
        });
    })(tpl.content);
    return tpl.innerHTML;
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
        let htmlContent = m.content.replace(/\[html\]([\s\S]*?)\[\/html\]/g, '$1');
        htmlContent = sanitizeHtmlFragment(htmlContent);
        return `
            <div style="margin:4px 0;">
                <div style="font-size:11px;color:var(--accent);margin-bottom:4px;"><i class="fas fa-code"></i> HTML 预览</div>
                <iframe srcdoc="${escapeHtml(htmlContent)}" sandbox=""
                    style="width:100%;border:1px solid var(--border);border-radius:8px;max-height:400px;background:#fff;"></iframe>
            </div>`;
    }

    if (type === 'image') {
        return `<img src="${escapeHtml(m.content)}" class="chat-image" onclick="window.open(this.src)" loading="lazy">`;
    }

    if (type === 'drawing') {
        let meta = {};
        try { meta = m.meta_data ? JSON.parse(m.meta_data) : {}; } catch(e) {}
        const cid = meta.canvas_id;
        const title = meta.title || '协作画布';
        const modeLabel = meta.mode === 'pixel' ? '像素画' : '自由绘制';
        return `<div class="drawing-msg-card" onclick="openCollaborativeCanvas(${cid})" style="cursor:pointer;display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(212,140,92,0.1);border:1px solid var(--accent);border-radius:8px;margin:2px 0;">
            <i class="fas fa-paint-brush" style="color:var(--accent);font-size:20px;"></i>
            <div style="flex:1;">
                <div style="font-weight:600;font-size:14px;">🎨 ${escapeHtml(title)}</div>
                <div style="font-size:11px;color:var(--text-ter);">协作画布 · ${modeLabel} · 点击进入</div>
            </div>
            <i class="fas fa-arrow-right" style="color:var(--accent);"></i>
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
        const res = await fetch(`/api/link-preview?url=${encodeURIComponent(urls[0])}`);
        const data = await res.json();
        if (data.title) {
            const el = document.querySelector(containerSelector.replace('{id}', msg.id));
            if (el) el.insertAdjacentHTML('beforeend', renderLinkPreview(data, size));
        }
    } catch(_) {}
}
