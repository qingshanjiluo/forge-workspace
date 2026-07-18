# Forge Workspace 修复与完善计划

> 最后更新：全部 25 项修复已完成 ✅

## 完成状态总览

| 阶段 | 状态 | 完成项 |
|------|------|--------|
| P0 严重 Bug | ✅ **已完成** | 7/7 |
| P1 安全加固 | ✅ **已完成** | 5/5 |
| P1 前端逻辑 | ✅ **已完成** | 4/4 |
| P2 性能优化 | ✅ **已完成** | 3/3 |
| P2 代码重构 | ✅ **已完成** | 3/3 |
| P3 功能完善 | ✅ **已完成** | 3/3 + 清理脚本 |

---

---

## 第一阶段：严重 Bug 修复（优先级 P0）

### 1. `api/meeting_chat.php` — `$stmt` 变量覆盖导致 lastInsertId 错误

**问题**：第 35 行用 `$stmt` 执行 INSERT 消息 => 第 38 行又用同名 `$stmt` 执行 INSERT presence => 第 41 行 `$pdo->lastInsertId()` 返回的是 presence 表 ID 而非消息 ID。

**修复**：presence 插入使用独立变量名。

```php
// line 38-41 改为
$stmtPresence = $pdo->prepare("INSERT INTO meeting_presence ...");
$stmtPresence->execute([$meeting_id, $_SESSION['user_id']]);
echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
```

**涉及文件**：`api/meeting_chat.php`

---

### 2. `pages/documents.php` — Markdown `<p>` 标签不闭合

**问题**：`renderMarkdown()` 中 `\n\n→</p><p>` 替换后整体缺少开头的 `<p>`。结果输出为 `</p><p>内容...<br></p>`。

**修复**：将返回字符串改为外层包裹 `<p>`，并反转换行处理顺序。

```javascript
// 原 line 97-99
html = html.replace(/\n\n/g, '</p><p style="margin:8px 0;">');
html = html.replace(/\n/g, '<br>');
return '<div>' + html + '</div>';

// 改为
const paragraphs = html.split(/\n\n+/);
html = paragraphs.map(p => '<p style="margin:8px 0;">' + p.replace(/\n/g, '<br>') + '</p>').join('');
return '<div>' + html + '</div>';
```

**涉及文件**：`pages/documents.php:85-99`

---

### 3. `api/document_get.php` — 分享链接强制登录

**问题**：`requireLogin()` 放在文件最顶部，`?share=xxx` 的公开访问请求直接被 302 跳转到 login.php。

**修复**：当通过 `share_token` 访问时跳过登录验证。

```php
// line 2-3
require_once '../config.php';

$share_token = $_GET['share'] ?? '';
$id = (int)($_GET['id'] ?? 0);

// 通过 share_token 访问时不强制登录
if (!$share_token) {
    requireLogin();
}
// 通过 id 访问时仍然需要登录
if ($id > 0) {
    requireLogin();
}
```

**涉及文件**：`api/document_get.php:1-8`

---

### 4. `db_migration.sql` — MySQL 不支持 `ADD COLUMN IF NOT EXISTS`

**问题**：`ALTER TABLE ... ADD COLUMN IF NOT EXISTS` 是 MariaDB 语法，InfinityFree 使用标准 MySQL，执行即报错。

**修复**：用存储过程替代，或忽略列已存在的错误。

```sql
-- 替换 lines 28-29 为：
-- 先尝试添加列，忽略已存在的错误
ALTER TABLE `meeting_messages` ADD COLUMN `message_type` VARCHAR(20) DEFAULT 'text' AFTER `content`;
-- 如果上面报错列已存在，继续执行
ALTER TABLE `meeting_messages` ADD COLUMN `meta_data` TEXT AFTER `message_type`;
```

或使用兼容写法：

```sql
DELIMITER ;;
DROP PROCEDURE IF EXISTS add_column_if_not_exists;;
CREATE PROCEDURE add_column_if_not_exists()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE `meeting_messages` ADD COLUMN `message_type` VARCHAR(20) DEFAULT 'text' AFTER `content`;
    ALTER TABLE `meeting_messages` ADD COLUMN `meta_data` TEXT AFTER `message_type`;
END;;
DELIMITER ;
CALL add_column_if_not_exists();
DROP PROCEDURE IF EXISTS add_column_if_not_exists;
```

**涉及文件**：`db_migration.sql:27-29`

---

### 5. `pages/meetings.php:150` + `pages/public_chat.php:67` — CSS `var()` 在 inline style 中无效

**问题**：`onmouseover="this.style.borderColor='var(--accent)'"` 中 `var(--accent)` 不会被 CSS 引擎解析，渲染为非法值。

**修复**：改用 CSS class 替代内联事件。

```javascript
// 替换 link preview 的 onmouseover/onmouseout 为：
// 在 style 标签或 CSS 中添加：
`
<style>
.link-preview-card:hover { border-color: #d48c5c !important; }
</style>

// a 标签改为：
<a href="..." target="_blank" rel="noopener" class="link-preview-card" style="...">`
```

**涉及文件**：`pages/meetings.php:148-163`、`pages/public_chat.php:65-80`

---

### 6. PHP 8.0 `str_starts_with` 兼容

**问题**：3 处使用 `str_starts_with()`，需 PHP 8.0+。若服务器运行 PHP 7.x 会抛出致命错误。

**修复**：全局替换为兼容写法。

```php
// str_starts_with($content, '/todo ') 替换为：
strpos($content, '/todo ') === 0
```

**涉及文件**：
- `api/meeting_chat.php:23`
- `api/public_chat.php:17`
- `api/link_preview.php:52`

---

## 第二阶段：安全加固（优先级 P1）

### 7. HTML 渲染 → iframe 沙盒

**问题**：`[html]...[/html]` 直接 innerHTML 注入，`<script>` 可被执行。

**修复**：改用 sandboxed iframe 的 srcdoc 属性渲染。

```javascript
// 替换 html-preview div 的内容为：
const htmlContent = m.content.replace(/\[html\]([\s\S]*?)\[\/html\]/g, '$1');
contentHtml = `
    <iframe srcdoc="${escapeHtml(htmlContent)}" sandbox="allow-same-origin"
            style="width:100%;border:1px solid var(--border);border-radius:8px;max-height:400px;background:#fff;"></iframe>`;
```

**涉及文件**：`pages/meetings.php:117-122`、`pages/public_chat.php:111-116`

---

### 8. 文档 HTML 预览 → iframe 沙盒

**问题**：`doc_type='html'` 时 `preview.innerHTML = content` 直接执行文档中的任意脚本。

**修复**：同 iframe srcdoc 方案。

```javascript
if (type === 'html') {
    preview.innerHTML = '<iframe srcdoc="' + escapeHtml(content) + '" sandbox="allow-same-origin" style="width:100%;height:400px;border:none;background:#fff;border-radius:8px;"></iframe>';
}
```

**涉及文件**：`pages/documents.php:186-192`

---

### 9. SSRF 防护 — link_preview URL 白名单

**问题**：`api/link_preview.php` 可被诱导请求内网地址（如 `http://127.0.0.1/`、`http://10.0.0.1/`）。

**修复**：添加内网 IP 阻断。

```php
$url = trim($_GET['url'] ?? '');
$host = parse_url($url, PHP_URL_HOST);

// 阻断内网地址
$ip = gethostbyname($host);
if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
    echo json_encode(['error' => '不允许访问内网地址']);
    exit;
}
```

**涉及文件**：`api/link_preview.php:6-10`

---

### 10. CSRF 防护

**问题**：所有 POST API 无 CSRF token 校验，外部站点可构造表单提交。

**修复**：在 config.php 添加 CSRF token 生成与验证函数，所有 API 入口校验。

```php
// config.php 新增
function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}
```

**涉及文件**：`config.php` + 所有 POST API 入口

---

### 11. 会议操作权限控制

**问题**：`api/meeting_delete.php` 任何用户可删除/结束任意会议。

**修复**：仅允许创建者或 admin 操作。

```php
// meeting_delete.php line 5 后添加
$stmt = $pdo->prepare("SELECT user_id FROM meetings WHERE id = ?");
$stmt->execute([$id]);
$meeting = $stmt->fetch();
if (!$meeting) jsonResponse(['error' => '会议不存在']);
if ($meeting['user_id'] != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
    jsonResponse(['error' => '无权操作']);
}
```

**涉及文件**：`api/meeting_delete.php`

---

## 第三阶段：前端逻辑修复（优先级 P1）

### 12. 文档创建后自动进入编辑器

**问题**：新建文档后只刷新列表，用户需手动点击「编辑」才能开始编写。

**修复**：创建成功后自动调用 `openEditor()` 并传入返回的 ID。

```javascript
// pages/documents.php:292-298 替换为：
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
```

**涉及文件**：`pages/documents.php:288-298`

---

### 13. 公共聊天轮询清理

**问题**：`public_chat.php` 中 `chatPollTimer` 无清理机制，页面切换到其他导航后仍有轮询请求。

**修复**：利用 `visibilitychange` 事件暂停/恢复轮询。

```javascript
// public_chat.php 底部添加
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        if (chatPollTimer) clearInterval(chatPollTimer);
        chatPollTimer = null;
    } else if (!chatPollTimer) {
        chatPollTimer = setInterval(pollMessages, 2000);
        pollMessages(); // 立即同步
    }
});
```

**涉及文件**：`pages/public_chat.php`

---

### 14. 编辑器未保存离开提示

**问题**：编辑文档后直接点「返回」或侧边栏导航会丢失未保存内容。

**修复**：跟踪修改状态，在 `closeEditor` 和 `beforeunload` 中提示。

```javascript
// documents.php 添加
let isModified = false;
document.getElementById('editorContent').addEventListener('input', function() { isModified = true; });
document.getElementById('editorTitle').addEventListener('input', function() { isModified = true; });

// 修改 closeEditor
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
```

**涉及文件**：`pages/documents.php`

---

### 15. 移除无用变量 `currentMeetingTitle`

**问题**：声明后仅赋值，从未读取。

**修复**：删除声明和赋值。

**涉及文件**：`pages/meetings.php:78,236`

---

## 第四阶段：性能优化（优先级 P2）

### 16. TODO 列表 N+1 请求

**问题**：`loadTodos()` 对每个 todo 发起独立 `todo_updates.php` 请求。

**修复**：后端一次性返回 updates 数据，或前端缓存。

**短期方案**（不改后端）：前端缓存 update 结果。

**长期方案**：新增 `api/todo_list_with_updates.php` 用 SQL JOIN 一次查询。

---

### 17. 全表扫描无分页

**问题**：`meeting_list`、`document_list`、`public_chat` 均为全表查询。

**修复**：添加 LIMIT + OFFSET 或游标分页。

```php
// 示例：document_list.php
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare("SELECT ... ORDER BY d.updated_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$perPage, $offset]);
```

**涉及文件**：`api/meeting_list.php`、`api/document_list.php`、`api/public_chat.php`

---

### 18. 公共聊天 `SELECT *` 含大字段

**问题**：`SELECT m.*` 拉取 `meta_data TEXT`，消息量大时浪费 IO。

**修复**：只查询需要的字段，前端按需请求 meta_data。

```php
// 改为：
SELECT m.id, m.user_id, m.content, m.message_type, m.created_at,
       u.username, COALESCE(u.display_name, u.username) AS display_name
```

**涉及文件**：`api/public_chat.php:39-40,46-47`

---

## 第五阶段：代码重构（优先级 P2）

### 19. 提取公共 JS 模块

**问题**：`meetings.php` 和 `public_chat.php` 间约 150 行 JS 完全重复（renderMessage、renderLinkPreview、enhanceText、extractUrls、checkLinkPreview、escapeHtml）。

**修复**：创建 `js/chat-common.js` 统一引用。

```
// 新建 G:\皮皮\编程项目\1940workspace\js\chat-common.js
// 包含：
// - escapeHtml, extractUrls, enhanceText
// - renderMessage, renderLinkPreview
// - checkLinkPreview, scrollToBottom

// 在 meetings.php 和 public_chat.php 顶部添加：
// <script src="js/chat-common.js"></script>
// 然后移除所有重复 JS 定义
```

**涉及文件**：新建 `js/chat-common.js`，修改 `pages/meetings.php`、`pages/public_chat.php`

---

### 20. 修复 `fa-markdown` 图标

**问题**：Font Awesome 6 Free 无 `fa-markdown` 图标。

**修复**：使用 `fa-code` 替代。

```javascript
// documents.php line 117
d.doc_type === 'html' ? 'code' : (d.doc_type === 'markdown' ? 'code' : 'file-alt')
```

**涉及文件**：`pages/documents.php:117`

---

### 21. 登录/注册页添加 Font Awesome

**问题**：`login.php:47` 和 `register.php:50` 使用 `<i class="fas fa-lock">` 但未加载 Font Awesome CDN。

**修复**：在两页的 `<head>` 中添加 CDN 引用。

```html
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
```

**涉及文件**：`login.php`、`register.php`

---

## 第六阶段：功能完善（优先级 P3）

### 22. ✅ 公共聊天在线状态

**问题**：公共聊天室无在线人数显示。

**修复**：创建 `public_chat_presence` 表 + 心跳 API（已在 P2 阶段完成）。

**涉及文件**：`db_migration.sql`、`api/public_presence.php`、`pages/public_chat.php`

---

### 23. ✅ 文档分享只读查看页

**问题**：分享链接 `api/document_get.php?share=xxx` 直接返回 JSON，用户体验差。

**修复**：创建 `pages/doc_view.php?share=xxx` 渲染可读页面。

**实现**：
- 独立页面，无需登录即可访问
- 纯文本 → `nl2br(htmlspecialchars)`
- Markdown → 渲染为 HTML（粗体、斜体、代码、链接、标题、段落）
- HTML → sandboxed iframe（srcdoc）安全展示
- 自适应移动端

**涉及文件**：新建 `pages/doc_view.php`、修改 `pages/documents.php:249`

---

### 24. ✅ 聊天消息定期清理

**问题**：消息表无限增长。

**修复**：创建 `api/cleanup.php`，仅管理员可调用，默认保留最近 90 天。

**实现**：
- 支持 `?days=N` 自定义保留天数
- 同时清理 `meeting_messages` 和 `public_chat_messages`
- 返回删除条数和截止日期

**涉及文件**：新建 `api/cleanup.php`

---

### 25. ✅ auth_token 验证中间件

**问题**：生成了 API token 但没有任何 API 端点验证它。

**修复**：在 `config.php` 创建 `authenticateRequest()` 函数，支持 Bearer token 认证。

**实现**：
- 从 `Authorization: Bearer <token>` 头中提取 token
- 校验 `auth_tokens` 表中是否存在未过期的 token
- 成功后自动设置 `$_SESSION['user_id']` 并更新最后使用时间
- API 入口调用：`if (!authenticateRequest()) { jsonResponse(['error' => '未授权']); }`

**涉及文件**：`config.php`

---

## 执行计划总结

| 阶段 | 优先级 | 工作量 | 影响范围 |
|------|--------|--------|----------|
| P0 — 严重 Bug | 立即 | 4 文件修改 | 会议聊天、文档编辑、MySQL 迁移、分享功能 |
| P1 — 安全 | 高 | 6 文件修改 | XSS、SSRF、CSRF、权限 |
| P1 — 前端逻辑 | 高 | 4 文件修改 | 文档创建流、轮询泄漏、未保存提示 |
| P2 — 性能 | 中 | 5 文件修改 | 减少请求数、分页、字段裁剪 |
| P2 — 重构 | 中 | 3 文件 + 1 新建 | JS 模块化消除重复 |
| P3 — 功能完善 | 低 | 5 文件 + 2 新建 | ✅ 在线状态、分享页、清理、auth 中间件 |

### 推荐执行顺序

1. **P0 修复** (所有 7 项) — 阻塞性 Bug
2. **P1 安全** #7-11 + **P1 前端** #12-15 — 并行修复
3. **P2 性能** #16-18 — 分页 + 减少请求
4. **P2 重构** #19-21 — JS 模块化
5. **P3 完善** #22-25 — ✅ 全部完成（在线状态、分享页、清理脚本、auth 中间件）
