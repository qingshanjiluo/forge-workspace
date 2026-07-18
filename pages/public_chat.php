<?php requireLogin(); ?>
<div class="page-header">
    <h1><i class="fas fa-comments"></i> 公共聊天室 <small>全员交流 · 实时协作</small></h1>
</div>

<div class="card" style="display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
    <i class="fas fa-info-circle" style="color:var(--accent);"></i>
    <span style="font-size:13px;color:var(--text-sec);">
        <code>/todo 内容</code> 快速创建待办 ·
        <code>[html]...[/html]</code> 渲染HTML ·
        自动识别链接预览
    </span>
    <span style="margin-left:auto;font-size:12px;color:var(--text-ter);" id="onlineCount"><i class="fas fa-circle" style="color:#10b981;font-size:8px;"></i> 实时聊天中</span>
</div>

<div style="display:flex;gap:16px;flex-wrap:wrap;">
    <div class="card" style="flex:1;min-width:300px;padding:12px;display:flex;flex-direction:column;height:calc(100vh - 280px);min-height:400px;">
        <div id="chatMessages" style="flex:1;overflow-y:auto;padding:8px 4px;">
            <div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载消息中...</div>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);flex-wrap:wrap;">
            <input type="text" id="chatInput" class="form-control" placeholder="输入消息..." style="flex:1;" maxlength="5000">
            <button class="btn btn--success btn--sm" id="chatTodoBtn"><i class="fas fa-check-circle"></i> 待办</button>
            <button class="btn btn--primary" id="chatSendBtn"><i class="fas fa-paper-plane"></i> 发送</button>
        </div>
    </div>

    <div class="card" style="width:260px;flex-shrink:0;padding:12px;max-height:calc(100vh - 280px);overflow-y:auto;">
        <div class="card__title" style="font-size:14px;margin-bottom:12px;"><i class="fas fa-user-friends"></i> 在线成员 <span id="onlineCount" style="font-size:11px;color:var(--text-ter);font-weight:400;"></span></div>
        <div id="onlineUsers" style="font-size:12px;margin-bottom:12px;line-height:1.8;">
            <div class="empty-state" style="padding:4px 0;font-size:12px;">加载中...</div>
        </div>

        <div class="card__title" style="font-size:14px;margin-bottom:12px;"><i class="fas fa-info-circle"></i> 快捷指令</div>
        <div style="font-size:12px;line-height:2;">
            <div><code style="background:var(--bg);padding:2px 6px;border-radius:4px;font-size:11px;">/todo 内容</code> — 创建待办</div>
            <div><code style="background:var(--bg);padding:2px 6px;border-radius:4px;font-size:11px;">[html]...[/html]</code> — 渲染 HTML</div>
            <div><code style="background:var(--bg);padding:2px 6px;border-radius:4px;font-size:11px;">`code`</code> — 内联代码</div>
            <div>粘贴 URL 自动预览链接</div>
        </div>

        <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--border);">
            <div class="card__title" style="font-size:14px;margin-bottom:8px;"><i class="fas fa-key"></i> API 令牌</div>
            <div id="tokenList" style="font-size:12px;margin-bottom:8px;">
                <div class="empty-state" style="padding:8px 0;">暂无</div>
            </div>
            <div style="display:flex;gap:4px;">
                <input type="text" id="tokenLabel" class="form-control" placeholder="名称" style="flex:1;font-size:11px;padding:4px 8px;">
                <button class="btn btn--sm btn--primary" id="genTokenBtn" style="font-size:11px;padding:4px 10px;">生成</button>
            </div>
        </div>
    </div>
</div>

<script src="js/chat-common.js"></script>
<script>
let chatLastId = 0;
let chatPollTimer = null;
let presenceTimer = null;

function renderMessage(m) {
    const isOwn = m.user_id == <?= $_SESSION['user_id'] ?>;
    const time = m.created_at ? m.created_at.substring(11, 16) : '';
    const contentHtml = renderMessageContent(m);

    return `
        <div class="chat-msg" data-msg-id="${m.id}" style="display:flex;gap:8px;padding:8px 0;${isOwn ? 'flex-direction:row-reverse;' : ''}">
            <div style="flex-shrink:0;width:32px;height:32px;border-radius:50%;background:${isOwn ? 'var(--accent)' : 'var(--primary)'};display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:700;">
                ${escapeHtml((m.username || m.display_name || '?').charAt(0).toUpperCase())}
            </div>
            <div style="max-width:80%;">
                <div style="font-size:11px;color:var(--text-ter);margin-bottom:2px;${isOwn ? 'text-align:right;' : ''}">
                    ${isOwn ? '你' : escapeHtml(m.username || m.display_name || '未知')} · ${time}
                </div>
                <div style="background:${isOwn ? 'var(--accent-soft)' : 'var(--bg)'};padding:8px 14px;border-radius:${isOwn ? '12px 4px 12px 12px' : '4px 12px 12px 12px'};border:1px solid var(--border);">
                    ${contentHtml}
                </div>
            </div>
        </div>`;
}

async function loadMessages() {
    const container = document.getElementById('chatMessages');
    container.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载消息中...</div>';
    try {
        const res = await fetch('api/public_chat.php?limit=50');
        const messages = await res.json();
        if (messages.length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="fas fa-comments"></i> 开始第一次对话吧！</div>';
            return;
        }
        container.innerHTML = messages.map(m => renderMessage(m)).join('');
        chatLastId = messages[messages.length - 1].id;
        scrollToBottom();
        for (const m of messages) checkLinkPreview(m);
    } catch (_) {
        container.innerHTML = '<div class="alert alert--danger">消息加载失败</div>';
    }
}

async function pollMessages() {
    try {
        const res = await fetch(`api/public_chat.php?since_id=${chatLastId}&limit=100`);
        const messages = await res.json();
        if (messages.length > 0) {
            const container = document.getElementById('chatMessages');
            const empty = container.querySelector('.empty-state');
            if (empty) empty.remove();
            for (const m of messages) {
                container.insertAdjacentHTML('beforeend', renderMessage(m));
                checkLinkPreview(m);
            }
            chatLastId = messages[messages.length - 1].id;
            scrollToBottom();
        }
    } catch (_) {}
}

function checkLinkPreview(msg) {
    return window.checkLinkPreview(msg, '.chat-msg[data-msg-id="{id}"]', 'sm');
}

function scrollToBottom() {
    scrollChatToBottom(document.getElementById('chatMessages'));
}

async function sendMessage() {
    const input = document.getElementById('chatInput');
    const content = input.value.trim();
    if (!content) return;

    document.getElementById('chatSendBtn').disabled = true;
    try {
        let messageType = 'text';
        if (content.startsWith('/todo ')) messageType = 'todo';
        else if (content.includes('[html]') && content.includes('[/html]')) messageType = 'html';

        await fetch('api/public_chat.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `content=${encodeURIComponent(content)}&message_type=${messageType}`
        });
        input.value = '';
        input.focus();
        await pollMessages();
    } catch (_) { alert('发送失败'); }
    document.getElementById('chatSendBtn').disabled = false;
}

function quickTodo() {
    const input = document.getElementById('chatInput');
    const text = input.value.trim();
    if (text && !text.startsWith('/todo ')) {
        input.value = '/todo ' + text;
    } else if (!text) {
        const todo = prompt('输入待办任务：');
        if (todo) input.value = '/todo ' + todo;
    }
    input.focus();
}

async function loadTokens() {
    try {
        const res = await fetch('api/auth_token.php');
        const tokens = await res.json();
        const container = document.getElementById('tokenList');
        if (tokens.length === 0) {
            container.innerHTML = '<div class="empty-state" style="padding:8px 0;">暂无</div>';
            return;
        }
        container.innerHTML = tokens.map(t => `
            <div style="display:flex;align-items:center;gap:4px;padding:4px 0;border-bottom:1px solid var(--border);">
                <span style="flex:1;font-size:11px;">${escapeHtml(t.label)}</span>
                <button class="revoke-token" data-id="${t.id}" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:10px;"><i class="fas fa-times"></i></button>
            </div>`).join('');
        container.querySelectorAll('.revoke-token').forEach(btn => {
            btn.addEventListener('click', async function() {
                await fetch(`api/auth_token.php?id=${this.dataset.id}`, { method: 'DELETE' });
                loadTokens();
            });
        });
    } catch(_) {}
}

document.getElementById('chatSendBtn').addEventListener('click', sendMessage);
document.getElementById('chatTodoBtn').addEventListener('click', quickTodo);
document.getElementById('chatInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});
document.getElementById('genTokenBtn').addEventListener('click', async function() {
    const label = document.getElementById('tokenLabel').value.trim() || '公共聊天令牌';
    const res = await fetch('api/auth_token.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `label=${encodeURIComponent(label)}`
    });
    const data = await res.json();
    if (data.token) {
        alert(`令牌已生成：\n${data.token}`);
        document.getElementById('tokenLabel').value = '';
        loadTokens();
    }
});

loadMessages();
loadTokens();
chatPollTimer = setInterval(pollMessages, 2000);

// 页面不可见时暂停轮询
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        if (chatPollTimer) { clearInterval(chatPollTimer); chatPollTimer = null; }
        if (presenceTimer) { clearInterval(presenceTimer); presenceTimer = null; }
    } else {
        if (!chatPollTimer) { chatPollTimer = setInterval(pollMessages, 2000); pollMessages(); }
        if (!presenceTimer) { presenceTimer = setInterval(sendPresence, 30000); sendPresence(); }
    }
});

// 在线心跳
async function sendPresence() {
    try { await fetch('api/public_presence.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'} }); } catch(_) {}
}

async function updateOnlineUsers() {
    try {
        const res = await fetch('api/public_presence.php');
        const users = await res.json();
        const el = document.getElementById('onlineUsers');
        const count = document.getElementById('onlineCount');
        if (users.length === 0) {
            el.innerHTML = '<div style="color:var(--text-ter);font-size:12px;">暂无在线用户</div>';
            count.textContent = '';
        } else {
            el.innerHTML = users.map(u => '<div><i class="fas fa-circle" style="color:#10b981;font-size:8px;"></i> ' + escapeHtml(u.username || u.display_name) + '</div>').join('');
            count.textContent = '(' + users.length + ')';
        }
    } catch(_) {}
}

// 启动
sendPresence();
presenceTimer = setInterval(sendPresence, 30000);
setInterval(updateOnlineUsers, 10000);
</script>
