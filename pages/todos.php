<?php requireLogin(); ?>
<div class="page-header">
    <h1><i class="fas fa-check-circle"></i> TODO清单 <small>管理你的任务</small></h1>
</div>

<div class="card">
    <form id="todoForm" class="flex gap-sm" style="flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:1;min-width:200px;">
            <input type="text" id="todoInput" class="form-control" placeholder="添加新任务..." required>
        </div>
        <div style="min-width:100px;">
            <select id="todoPriority" class="form-control">
                <option value="medium">中优先</option>
                <option value="high">高优先</option>
                <option value="low">低优先</option>
            </select>
        </div>
        <div style="min-width:120px;">
            <input type="date" id="todoDeadline" class="form-control" placeholder="截止日期(可选)">
        </div>
        <button type="submit" class="btn btn--primary"><i class="fas fa-plus"></i> 添加</button>
    </form>
</div>

<div class="card">
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:16px;">
        <span style="font-weight:600;font-size:13px;color:var(--text-sec);">筛选：</span>
        <button class="btn btn--sm filter-btn active" data-filter="all">全部</button>
        <button class="btn btn--sm filter-btn" data-filter="active">进行中</button>
        <button class="btn btn--sm filter-btn" data-filter="done">已完成</button>
        <button class="btn btn--sm filter-btn" data-filter="high"><i class="fas fa-flag" style="color:#ef4444;"></i> 高优先</button>
        <button class="btn btn--sm filter-btn" data-filter="expiring"><i class="fas fa-hourglass-half" style="color:#f59e0b;"></i> 即将到期</button>
        <span style="margin-left:auto;display:flex;align-items:center;gap:6px;">
            <span style="font-size:12px;color:var(--text-ter);">排序：</span>
            <select id="sortSelect" class="form-control" style="width:auto;padding:4px 8px;font-size:12px;">
                <option value="created_at">按创建时间</option>
                <option value="priority">按优先级</option>
                <option value="deadline">按截止日期</option>
                <option value="progress">按进度</option>
            </select>
        </span>
    </div>
    <div id="todoList">
        <div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载中...</div>
    </div>
    <div id="todoCount" style="text-align:center;font-size:12px;color:var(--text-ter);margin-top:12px;"></div>
</div>

<script>
let currentFilter = 'all';
let currentSort = 'created_at';

// 筛选按钮
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        currentFilter = this.dataset.filter;
        loadTodos();
    });
});

document.getElementById('sortSelect').addEventListener('change', function() {
    currentSort = this.value;
    loadTodos();
});

// 优先级颜色映射
const priorityColors = { high: '#ef4444', medium: '#f59e0b', low: '#10b981' };
const priorityIcons = { high: 'fa-flag', medium: 'fa-minus', low: 'fa-arrow-down' };
const priorityLabels = { high: '高', medium: '中', low: '低' };

// 缓存 todo updates 结果，避免 N+1 重复请求
const updatesCache = {};
const CACHE_TTL = 30000;

async function fetchUpdates(todoId) {
    const cached = updatesCache[todoId];
    if (cached && Date.now() - cached.time < CACHE_TTL) return cached.data;
    try {
        const res = await fetch(`api/todo_updates.php?todo_id=${todoId}`);
        const data = await res.json();
        updatesCache[todoId] = { data, time: Date.now() };
        return data;
    } catch(_) { return []; }
}

async function loadTodos() {
    const container = document.getElementById('todoList');
    container.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-pulse"></i> 加载中...</div>';

    try {
        const res = await fetch(`api/todo_list.php?filter=${currentFilter}&sort=${currentSort}`);
        if (!res.ok) throw new Error('网络错误');
        const todos = await res.json();

        document.getElementById('todoCount').textContent = `共 ${todos.length} 项任务`;

        if (todos.length === 0) {
            const msgs = { all: '还没有任务，快来添加吧！', active: '所有任务已完成！', done: '暂无已完成任务', high: '没有高优先任务', expiring: '没有即将到期的任务' };
            container.innerHTML = `<div class="empty-state"><i class="fas fa-check-circle" style="font-size:48px;"></i>${msgs[currentFilter] || '暂无数据'}</div>`;
            return;
        }

        let html = '';
        for (const t of todos) {
            const updates = await fetchUpdates(t.id);

            const progressColor = t.progress >= 100 ? 'var(--success)' : (t.progress >= 50 ? '#f59e0b' : 'var(--text-ter)');
            const isDone = t.progress >= 100;
            const isExpiring = t.deadline && !isDone && new Date(t.deadline) <= new Date(Date.now() + 3*86400000);
            const isOverdue = t.deadline && !isDone && new Date(t.deadline) < new Date();

            html += `
            <div class="todo-item" data-id="${t.id}" style="flex-direction:column;align-items:stretch;padding:12px 0;${isDone ? 'opacity:0.6;' : ''}">
                <div class="flex" style="flex-wrap:wrap;align-items:center;gap:8px;">
                    <span style="display:inline-flex;align-items:center;gap:6px;min-width:120px;">
                        <span class="priority-toggle" data-id="${t.id}" data-priority="${t.priority}" style="background:${priorityColors[t.priority]};color:#fff;font-size:10px;font-weight:700;padding:1px 8px;border-radius:10px;white-space:nowrap;cursor:pointer;" title="点击切换优先级（当前：${priorityLabels[t.priority]}）">
                            <i class="fas ${priorityIcons[t.priority]}" style="font-size:8px;margin-right:3px;"></i>${priorityLabels[t.priority]}
                        </span>
                        <span class="todo-text editable" data-id="${t.id}" style="cursor:pointer;${isDone ? 'text-decoration:line-through;' : ''}" title="双击编辑内容">${escapeHtml(t.content)}</span>
                    </span>
                    <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:160px;">
                        <input type="range" min="0" max="100" value="${t.progress}" class="progress-slider" data-id="${t.id}" style="flex:1;max-width:150px;accent-color:${progressColor};">
                        <span class="progress-label" data-id="${t.id}" style="color:${progressColor};">${t.progress}%</span>
                    </div>
                    ${t.deadline ? `
                    <span style="font-size:11px;padding:2px 8px;border-radius:10px;white-space:nowrap;${isOverdue ? 'background:rgba(239,68,68,0.15);color:#ef4444;' : (isExpiring ? 'background:rgba(245,158,11,0.15);color:#f59e0b;' : 'background:var(--bg);color:var(--text-sec);')}">
                        <i class="fas fa-calendar" style="font-size:10px;margin-right:3px;"></i>${isOverdue ? '已逾期: ' : '截止: '}${t.deadline}
                    </span>` : ''}
                    <span class="todo-meta">${t.created_at.substring(5)}</span>
                    <div class="todo-actions">
                        <button class="add-remark" data-id="${t.id}" title="添加备注"><i class="fas fa-pen"></i></button>
                        <button class="del" data-id="${t.id}" title="删除"><i class="fas fa-trash-alt"></i></button>
                    </div>
                </div>
                ${updates.length > 0 ? `
                <div class="timeline-wrapper" style="margin-top:10px;overflow-x:auto;padding:8px 0;">
                    <div style="display:flex;gap:12px;white-space:nowrap;padding:4px 8px;">
                        ${updates.map(u => `
                            <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:6px 12px;min-width:130px;flex-shrink:0;">
                                <div style="font-size:10px;color:var(--text-ter);">${u.created_at}</div>
                                <div style="font-size:12px;font-weight:500;">${escapeHtml(u.content || '更新')}</div>
                                ${u.progress !== null ? `<div style="font-size:11px;color:var(--accent);">进度: ${u.progress}%</div>` : ''}
                            </div>
                        `).join('')}
                    </div>
                </div>` : ''}
            </div>`;
        }
        container.innerHTML = html;
        bindTodoEvents();
    } catch (error) {
        container.innerHTML = `<div class="alert alert--danger"><i class="fas fa-exclamation-circle"></i> 加载失败，<a href="javascript:loadTodos()" style="color:var(--accent);font-weight:600;">点击重试</a></div>`;
    }
}

function bindTodoEvents() {
    const container = document.getElementById('todoList');

    // 进度滑块
    container.querySelectorAll('.progress-slider').forEach(slider => {
        slider.addEventListener('input', function() {
            const id = this.dataset.id;
            const val = parseInt(this.value);
            const label = document.querySelector(`.progress-label[data-id="${id}"]`);
            if (label) label.textContent = val + '%';
        });
        slider.addEventListener('change', async function() {
            const id = this.dataset.id;
            const progress = parseInt(this.value);
            // 内联备注输入替代 prompt
            const remark = await inlinePrompt(id, '请输入进度更新备注（可选）：', 'progress-remark');
            if (remark === null) { loadTodos(); return; }
            await fetch('api/todo_update.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `id=${id}&progress=${progress}&remark=${encodeURIComponent(remark)}`
            });
            loadTodos();
            updateBadges();
        });
    });

    // 双击编辑内容
    container.querySelectorAll('.editable').forEach(span => {
        span.addEventListener('dblclick', function() {
            const id = this.dataset.id;
            const currentText = this.textContent.trim();
            const wrapper = this.parentElement;
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control';
            input.style.cssText = 'width:100%;max-width:300px;padding:4px 8px;font-size:13px;';
            input.value = currentText;
            this.replaceWith(input);
            input.focus();
            input.select();

            const save = async () => {
                const newContent = input.value.trim();
                if (newContent && newContent !== currentText) {
                    const remark = await inlinePrompt(id, '请输入本次内容更新的备注（可选）：', 'content-remark');
                    await fetch('api/todo_update.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `id=${id}&content=${encodeURIComponent(newContent)}&remark=${encodeURIComponent(remark || '')}`
                    });
                }
                loadTodos();
                updateBadges();
            };

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); save(); }
                if (e.key === 'Escape') loadTodos();
            });
            input.addEventListener('blur', save);
        });
    });

    // 添加备注按钮 - 使用内联输入替代 prompt
    container.querySelectorAll('.add-remark').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            const remark = await inlinePrompt(id, '请输入备注内容：', 'remark-input');
            if (remark === null || remark.trim() === '') return;
            await fetch('api/todo_update.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `id=${id}&remark=${encodeURIComponent(remark.trim())}`
            });
            loadTodos();
            updateBadges();
        });
    });

    // 删除
    container.querySelectorAll('.del').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            // 使用自定义确认替代原生 confirm
            const confirmed = await customConfirm('确认删除此任务？此操作不可恢复。');
            if (!confirmed) return;
            await fetch('api/todo_delete.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `id=${id}` });
            loadTodos();
            updateBadges();
        });
    });

    // 优先级标签点击切换
    container.querySelectorAll('.priority-toggle').forEach(tag => {
        tag.addEventListener('click', async function() {
            const id = this.dataset.id;
            const current = this.dataset.priority;
            const next = current === 'high' ? 'medium' : (current === 'medium' ? 'low' : 'high');
            await fetch('api/todo_update.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `id=${id}&priority=${next}`
            });
            loadTodos();
            updateBadges();
        });
    });
}

// 内联提示输入框（替代 prompt）
function inlinePrompt(id, placeholder, cls) {
    return new Promise(resolve => {
        // 移除旧的内联输入
        const old = document.querySelector('.inline-prompt-overlay');
        if (old) old.remove();

        const overlay = document.createElement('div');
        overlay.className = 'inline-prompt-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
        overlay.innerHTML = `
            <div style="background:var(--bg-card);border-radius:12px;padding:20px 24px;min-width:320px;max-width:450px;box-shadow:0 8px 40px rgba(0,0,0,0.3);border:1px solid var(--border);">
                <div style="font-size:14px;font-weight:600;margin-bottom:12px;color:var(--text);">${placeholder}</div>
                <input type="text" class="form-control" id="${cls}-${id}" placeholder="直接回车跳过..." style="margin-bottom:12px;" autofocus>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button class="btn btn--sm inline-prompt-cancel">取消</button>
                    <button class="btn btn--sm btn--primary inline-prompt-ok">确定</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        const input = overlay.querySelector('input');
        setTimeout(() => input.focus(), 100);

        const done = (val) => { overlay.remove(); resolve(val); };

        overlay.querySelector('.inline-prompt-ok').addEventListener('click', () => done(input.value));
        overlay.querySelector('.inline-prompt-cancel').addEventListener('click', () => done(null));
        overlay.addEventListener('click', function(e) { if (e.target === overlay) done(null); });
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); done(input.value); }
            if (e.key === 'Escape') done(null);
        });
    });
}

// 自定义确认对话框（替代 confirm）
function customConfirm(message) {
    return new Promise(resolve => {
        const old = document.querySelector('.custom-confirm-overlay');
        if (old) old.remove();

        const overlay = document.createElement('div');
        overlay.className = 'custom-confirm-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
        overlay.innerHTML = `
            <div style="background:var(--bg-card);border-radius:12px;padding:20px 24px;min-width:300px;max-width:400px;box-shadow:0 8px 40px rgba(0,0,0,0.3);border:1px solid var(--border);text-align:center;">
                <div style="font-size:40px;margin-bottom:8px;color:#ef4444;"><i class="fas fa-exclamation-triangle"></i></div>
                <div style="font-size:14px;margin-bottom:16px;color:var(--text);">${message}</div>
                <div style="display:flex;gap:8px;justify-content:center;">
                    <button class="btn btn--sm custom-confirm-cancel">取消</button>
                    <button class="btn btn--sm btn--danger custom-confirm-ok">确认删除</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        overlay.querySelector('.custom-confirm-ok').addEventListener('click', () => { overlay.remove(); resolve(true); });
        overlay.querySelector('.custom-confirm-cancel').addEventListener('click', () => { overlay.remove(); resolve(false); });
        overlay.addEventListener('click', function(e) { if (e.target === overlay) { overlay.remove(); resolve(false); } });
    });
}

document.getElementById('todoForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const input = document.getElementById('todoInput');
    const priority = document.getElementById('todoPriority').value;
    const deadline = document.getElementById('todoDeadline').value;
    const content = input.value.trim();
    if (!content) return;
    await fetch('api/todo_add.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `content=${encodeURIComponent(content)}&priority=${priority}&deadline=${deadline}`
    });
    input.value = '';
    document.getElementById('todoDeadline').value = '';
    loadTodos();
    updateBadges();
});

async function updateBadges() {
    try {
        const res = await fetch('api/todo_count.php');
        const data = await res.json();
        const badge = document.getElementById('todoBadge');
        if (badge) badge.textContent = data.avg_progress + '%';
    } catch(_) {}
}

// 初始加载
loadTodos();
updateBadges();
</script>
