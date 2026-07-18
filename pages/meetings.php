<?php requireLogin(); ?>
<div class="page-header">
    <h1><i class="fas fa-users"></i> 会议密室 <small>组织会议 · 实时交流 · 智能工具</small></h1>
</div>

<div id="meetingListView">
    <div class="card">
        <form id="meetingForm" class="grid-2" style="grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group" style="grid-column:1 / -1;">
                <label>会议主题</label>
                <input type="text" id="meetingTitle" class="form-control" required>
            </div>
            <div class="form-group">
                <label>时间</label>
                <input type="datetime-local" id="meetingTime" class="form-control" required>
            </div>
            <div class="form-group">
                <label>参与人（逗号分隔）</label>
                <input type="text" id="meetingParticipants" class="form-control" placeholder="张三, 李四">
            </div>
            <div class="form-group" style="grid-column:1 / -1;">
                <label>描述</label>
                <textarea id="meetingDesc" class="form-control" rows="2"></textarea>
            </div>
            <div style="grid-column:1 / -1;">
                <button type="submit" class="btn btn--primary"><i class="fas fa-plus"></i> 创建会议</button>
            </div>
        </form>
    </div>

    <div class="grid-2">
        <div class="card">
            <div class="card__title"><i class="fas fa-circle" style="color:var(--success);"></i> 进行中</div>
            <div id="activeMeetings"><div class="empty-state">加载中...</div></div>
        </div>
        <div class="card">
            <div class="card__title"><i class="fas fa-check-circle"></i> 已结束</div>
            <div id="endedMeetings"><div class="empty-state">加载中...</div></div>
        </div>
    </div>
</div>

<div id="chatRoomView" style="display:none;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
        <button class="btn btn--sm" id="backToMeetings"><i class="fas fa-arrow-left"></i> 返回</button>
        <h2 id="chatRoomTitle" style="font-size:18px;font-weight:600;margin:0;"></h2>
        <span id="chatRoomStatus" style="font-size:11px;padding:2px 10px;border-radius:10px;"></span>
        <div id="onlineUsers" style="margin-left:auto;display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-sec);"></div>
    </div>

    <div style="display:flex;gap:16px;flex-wrap:wrap;">
        <div class="card" style="flex:1;min-width:300px;padding:12px;">
            <div id="chatMessages" style="max-height:500px;overflow-y:auto;padding:8px 4px;min-height:250px;">
                <div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载消息中...</div>
            </div>
            <div style="display:flex;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);flex-wrap:wrap;">
                <input type="text" id="chatInput" class="form-control" placeholder="输入消息 /todo xxx 创建任务 [html]...[/html] 渲染HTML" style="flex:1;" maxlength="5000">
                <button class="btn btn--success btn--sm" id="chatTodoBtn" title="快速创建任务"><i class="fas fa-check-circle"></i> 待办</button>
                <button class="btn btn--primary" id="chatSendBtn"><i class="fas fa-paper-plane"></i> 发送</button>
            </div>
        </div>

        <div class="card" style="width:280px;flex-shrink:0;padding:12px;display:none;" id="authPanel">
            <div class="card__title" style="font-size:14px;"><i class="fas fa-key"></i> API 令牌</div>
            <div id="tokenList" style="font-size:12px;margin-bottom:10px;">
                <div class="empty-state" style="padding:12px 0;">暂无令牌</div>
            </div>
            <div style="display:flex;gap:6px;">
                <input type="text" id="tokenLabel" class="form-control" placeholder="令牌名称" style="flex:1;font-size:12px;padding:6px 10px;">
                <button class="btn btn--sm btn--primary" id="genTokenBtn"><i class="fas fa-plus"></i> 生成</button>
            </div>
        </div>
    </div>
</div>

<script src="js/chat-common.js"></script>
<script>
let currentMeetingId = null;
let chatPollTimer = null;
let chatLastId = 0;
let presenceTimer = null;

// 渲染聊天消息（扩展版）
function renderMessage(m) {
    const isOwn = m.user_id == <?= $_SESSION['user_id'] ?>;
    const time = m.created_at ? m.created_at.substring(11, 16) : '';
    const type = m.message_type || 'text';
    let meta = {};
    try { meta = m.meta_data ? JSON.parse(m.meta_data) : {}; } catch(e) {}

    let contentHtml = renderMessageContent(m);
    if (type === 'html') {
        // 追加原始内容提示（仅在会议聊天中）
        contentHtml += `<div style="font-size:13px;margin-top:4px;opacity:0.7;">${escapeHtml(m.content.replace(/\[html\]([\s\S]*?)\[\/html\]/g, '[HTML内容]'))}</div>`;
    }
    if (type === 'text' && meta.link_preview) {
        contentHtml += renderLinkPreview(meta.link_preview);
    }

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

// ==================== 会议列表 ====================
async function loadMeetings() {
    try {
        const res = await fetch('api/meeting_list.php');
        const meetings = await res.json();
        const active = meetings.filter(m => m.status === 'active');
        const ended = meetings.filter(m => m.status === 'ended');
        document.getElementById('activeMeetings').innerHTML = active.length
            ? active.map(m => renderMeeting(m)).join('')
            : '<div class="empty-state">暂无进行中的会议</div>';
        document.getElementById('endedMeetings').innerHTML = ended.length
            ? ended.map(m => renderMeeting(m)).join('')
            : '<div class="empty-state">暂无已结束的会议</div>';
        bindMeetingButtons();
    } catch (_) {
        document.getElementById('activeMeetings').innerHTML = '<div class="alert alert--danger">会议加载失败</div>';
    }
}

function renderMeeting(m) {
    return `
        <div class="meeting-card" style="margin-bottom:10px;">
            <div class="title">${escapeHtml(m.title)}</div>
            <div class="meta"><i class="fas fa-calendar"></i> ${m.meeting_time} · <i class="fas fa-users"></i> ${escapeHtml(m.participants || '无')}</div>
            <div class="meta">${escapeHtml(m.description || '')}</div>
            <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;align-items:center;">
                <span class="status ${m.status === 'active' ? 'active' : 'ended'}">${m.status === 'active' ? '进行中' : '已结束'}</span>
                ${m.status === 'active' ? `<button class="btn btn--sm btn--success enter-chat" data-id="${m.id}" data-title="${escapeHtml(m.title)}"><i class="fas fa-comments"></i> 进入聊天室</button>` : ''}
                ${m.status === 'active' ? `<button class="btn btn--sm btn--warning end-meeting" data-id="${m.id}"><i class="fas fa-stop"></i> 结束</button>` : ''}
                <button class="btn btn--sm btn--danger del-meeting" data-id="${m.id}"><i class="fas fa-trash-alt"></i> 删除</button>
            </div>
        </div>`;
}

function bindMeetingButtons() {
    document.querySelectorAll('.enter-chat').forEach(btn => {
        btn.addEventListener('click', function() { enterChatRoom(parseInt(this.dataset.id), this.dataset.title); });
    });
    document.querySelectorAll('.end-meeting').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('确认结束此会议？')) return;
            await fetch('api/meeting_delete.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `id=${this.dataset.id}&action=end` });
            loadMeetings();
        });
    });
    document.querySelectorAll('.del-meeting').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('删除此会议？')) return;
            await fetch('api/meeting_delete.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `id=${this.dataset.id}&action=delete` });
            loadMeetings();
        });
    });
}

// ==================== 聊天室 ====================
async function enterChatRoom(meetingId, title) {
    leaveChatRoom();
    currentMeetingId = meetingId;
    chatLastId = 0;

    document.getElementById('meetingListView').style.display = 'none';
    document.getElementById('chatRoomView').style.display = 'block';
    document.getElementById('chatRoomTitle').textContent = title;

    const statusEl = document.getElementById('chatRoomStatus');
    statusEl.textContent = '进行中';
    statusEl.style.cssText = 'background:rgba(16,185,129,0.15);color:#10b981;';

    const messagesEl = document.getElementById('chatMessages');
    messagesEl.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载消息中...</div>';

    try {
        const res = await fetch(`api/meeting_chat.php?meeting_id=${meetingId}&limit=50`);
        const messages = await res.json();
        renderMessages(messages);
        if (messages.length > 0) chatLastId = messages[messages.length - 1].id;
    } catch (_) {
        messagesEl.innerHTML = '<div class="alert alert--danger">消息加载失败</div>';
    }

    await sendPresence();
    chatPollTimer = setInterval(pollMessages, 2000);
    presenceTimer = setInterval(sendPresence, 30000);
    scrollChatToBottom();
    document.getElementById('authPanel').style.display = 'block';
    loadTokens();
}

function leaveChatRoom() {
    if (chatPollTimer) clearInterval(chatPollTimer);
    if (presenceTimer) clearInterval(presenceTimer);
    chatPollTimer = null;
    presenceTimer = null;
    currentMeetingId = null;
    document.getElementById('authPanel').style.display = 'none';
}

async function pollMessages() {
    if (!currentMeetingId) return;
    try {
        const res = await fetch(`api/meeting_chat.php?meeting_id=${currentMeetingId}&since_id=${chatLastId}&limit=100`);
        const messages = await res.json();
        if (messages.length > 0) {
            appendMessages(messages);
            chatLastId = messages[messages.length - 1].id;
            // 处理新消息中的链接预览
            for (const m of messages) {
                if (m.message_type === 'text') checkLinkPreview(m);
            }
        }
        await updateOnlineUsers();
    } catch (_) {}
}

async function sendPresence() {
    if (!currentMeetingId) return;
    try {
        await fetch('api/meeting_presence.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `meeting_id=${currentMeetingId}`
        });
    } catch (_) {}
}

async function updateOnlineUsers() {
    if (!currentMeetingId) return;
    try {
        const res = await fetch(`api/meeting_presence.php?meeting_id=${currentMeetingId}`);
        const users = await res.json();
        const el = document.getElementById('onlineUsers');
        el.innerHTML = users.length
            ? `<i class="fas fa-circle" style="color:#10b981;font-size:8px;"></i> ${users.length} 人在线 · ${users.map(u => escapeHtml(u.username || u.display_name)).join(', ')}`
            : '<i class="fas fa-circle" style="color:#94a3b8;font-size:8px;"></i> 暂无在线用户';
    } catch (_) {}
}

function renderMessages(messages) {
    const container = document.getElementById('chatMessages');
    if (messages.length === 0) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-comments"></i> 暂无消息，开始对话吧！</div>';
        return;
    }
    container.innerHTML = messages.map(m => renderMessage(m)).join('');
    // 检查已有消息的链接预览
    for (const m of messages) {
        if (m.message_type === 'text') checkLinkPreview(m);
    }
}

function appendMessages(messages) {
    const container = document.getElementById('chatMessages');
    const emptyState = container.querySelector('.empty-state');
    if (emptyState) emptyState.remove();
    for (const m of messages) {
        container.insertAdjacentHTML('beforeend', renderMessage(m));
        if (m.message_type === 'text') checkLinkPreview(m);
    }
}

function scrollChatToBottom() {
    scrollChatToBottomEl(document.getElementById('chatMessages'));
}

function checkLinkPreview(msg) {
    return window.checkLinkPreview(msg, '.chat-msg[data-msg-id="{id}"]');
}

// ==================== 发送消息 ====================
async function sendChatMessage() {
    if (!currentMeetingId) return;
    const input = document.getElementById('chatInput');
    const content = input.value.trim();
    if (!content) return;

    const btn = document.getElementById('chatSendBtn');
    btn.disabled = true;

    try {
        let messageType = 'text';
        if (content.startsWith('/todo ')) messageType = 'todo';
        else if (content.includes('[html]') && content.includes('[/html]')) messageType = 'html';

        await fetch('api/meeting_chat.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `meeting_id=${currentMeetingId}&content=${encodeURIComponent(content)}&message_type=${messageType}`
        });
        input.value = '';
        input.focus();
        await pollMessages();
    } catch (_) {
        alert('发送失败');
    }
    btn.disabled = false;
}

// 快速创建待办
async function quickCreateTodo() {
    const input = document.getElementById('chatInput');
    const current = input.value.trim();
    if (current && !current.startsWith('/todo ')) {
        input.value = '/todo ' + current;
    } else if (!current) {
        const todo = prompt('输入待办任务内容：');
        if (todo) input.value = '/todo ' + todo;
    }
    input.focus();
}

// ==================== API 令牌管理 ====================
async function loadTokens() {
    try {
        const res = await fetch('api/auth_token.php');
        const tokens = await res.json();
        const container = document.getElementById('tokenList');
        if (tokens.length === 0) {
            container.innerHTML = '<div class="empty-state" style="padding:12px 0;">暂无令牌</div>';
            return;
        }
        container.innerHTML = tokens.map(t => `
            <div style="display:flex;align-items:center;gap:6px;padding:6px 0;border-bottom:1px solid var(--border);">
                <i class="fas fa-key" style="font-size:10px;color:var(--accent);"></i>
                <span style="flex:1;">${escapeHtml(t.label)}</span>
                <span style="font-size:10px;color:var(--text-ter);">${t.last_used_at ? '上次:'+t.last_used_at.substring(5,16) : '未使用'}</span>
                <button class="btn btn--sm btn--danger revoke-token" data-id="${t.id}" style="font-size:10px;padding:2px 8px;"><i class="fas fa-times"></i></button>
            </div>
        `).join('');
        container.querySelectorAll('.revoke-token').forEach(btn => {
            btn.addEventListener('click', async function() {
                await fetch(`api/auth_token.php?id=${this.dataset.id}`, { method: 'DELETE' });
                loadTokens();
            });
        });
    } catch(_) {}
}

// ==================== 事件绑定 ====================
document.getElementById('meetingForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const title = document.getElementById('meetingTitle').value.trim();
    const time = document.getElementById('meetingTime').value;
    const participants = document.getElementById('meetingParticipants').value.trim();
    const description = document.getElementById('meetingDesc').value.trim();
    if (!title || !time) return alert('请填写主题和时间');
    await fetch('api/meeting_add.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `title=${encodeURIComponent(title)}&meeting_time=${encodeURIComponent(time)}&participants=${encodeURIComponent(participants)}&description=${encodeURIComponent(description)}`
    });
    this.reset();
    document.getElementById('meetingTime').value = new Date().toISOString().slice(0,16);
    loadMeetings();
});

document.getElementById('backToMeetings').addEventListener('click', function() {
    leaveChatRoom();
    document.getElementById('chatRoomView').style.display = 'none';
    document.getElementById('meetingListView').style.display = 'block';
    loadMeetings();
});

document.getElementById('chatSendBtn').addEventListener('click', sendChatMessage);
document.getElementById('chatTodoBtn').addEventListener('click', quickCreateTodo);
document.getElementById('chatInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChatMessage(); }
});

// 令牌生成
document.getElementById('genTokenBtn').addEventListener('click', async function() {
    const label = document.getElementById('tokenLabel').value.trim() || '会议令牌';
    const res = await fetch('api/auth_token.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `label=${encodeURIComponent(label)}`
    });
    const data = await res.json();
    if (data.token) {
        alert(`令牌已生成（仅显示一次）：\n${data.token}\n\n请妥善保管！`);
        document.getElementById('tokenLabel').value = '';
        loadTokens();
    }
});

document.getElementById('meetingTime').value = new Date().toISOString().slice(0,16);
loadMeetings();

setInterval(() => { if (!currentMeetingId) loadMeetings(); }, 30000);
</script>
