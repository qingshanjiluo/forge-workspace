<?php
require_once 'config.php';
requireLogin();

$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard', 'todos', 'logs', 'messages', 'meetings', 'announcements', 'admin', 'public_chat', 'documents'];
if (!in_array($page, $allowed)) $page = 'dashboard';

$user = getCurrentUser();
$username = $user['username'] ?? '用户';
$role = $user['role'] ?? 'member';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" />
    <title>工作交流平台 · Forge Workspace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-1ycn6IcaQQ40/MKBW2W4Rhis/DbILU74C1vSrLJxCq57o941Ym01SwNsOMqvEBFlcgUa6xLiPY/NS5R+E6ztJQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        /* ============================================================
           Forge Workspace · 全局样式
           ============================================================ */
        :root {
            --bg: #0b0a0c;
            --bg-card: rgba(255, 255, 255, 0.04);
            --bg-card-hover: rgba(255, 255, 255, 0.08);
            --border: rgba(255, 255, 255, 0.07);
            --border-active: rgba(255, 255, 255, 0.16);
            --text: #f0ede8;
            --text-sec: #9a9792;
            --text-ter: #5e5b56;
            --accent: #d48c5c;
            --accent-soft: rgba(212, 140, 92, 0.15);
            --accent-glow: rgba(212, 140, 92, 0.25);
            --danger: #e74c6f;
            --success: #4ecdc4;
            --warning: #f9ca24;
            --font: 'Inter', -apple-system, 'Segoe UI', Roboto, 'PingFang SC', sans-serif;
            --radius: 12px;
            --radius-sm: 6px;
            --radius-full: 9999px;
            --shadow: 0 12px 48px rgba(0, 0, 0, 0.5);
            --transition: 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
            --header-height: 60px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            background-image: radial-gradient(ellipse at 20% 30%, rgba(212,140,92,0.04) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 70%, rgba(100,80,200,0.03) 0%, transparent 50%);
            display: flex;
            flex-direction: column;
        }

        ::selection { background: var(--accent); color: #0b0a0c; }
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: var(--radius-full); }
        ::-webkit-scrollbar-thumb:hover { background: var(--accent); }

        /* 顶部导航 */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
            padding: 0 16px;
            height: var(--header-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .topbar__brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 18px;
            color: var(--text);
            text-decoration: none;
        }
        .topbar__brand i { color: var(--accent); font-size: 22px; }
        .topbar__brand span {
            background: linear-gradient(135deg, var(--accent), #c0846a);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .topbar__right { display: flex; align-items: center; gap: 12px; }
        .topbar__user { font-size: 13px; color: var(--text-sec); display: flex; align-items: center; gap: 6px; }
        .topbar__user .name { font-weight: 600; color: var(--text); }
        .topbar__hamburger {
            background: none; border: none; color: var(--text); font-size: 24px; cursor: pointer;
            padding: 4px 8px; border-radius: var(--radius-sm); transition: background var(--transition);
            display: none;
        }
        .topbar__hamburger:hover { background: var(--bg-card-hover); }

        /* 滚动公告 */
        #scrollAnnouncement {
            background: var(--accent-soft);
            border-bottom: 1px solid var(--border);
            padding: 6px 0;
            overflow: hidden;
            white-space: nowrap;
            position: relative;
            z-index: 999;
        }
        #announcementText {
            display: inline-block;
            padding-left: 100%;
            animation: scrollAnnounce 20s linear infinite;
            font-size: 14px;
            color: var(--text);
        }
        @keyframes scrollAnnounce {
            0% { transform: translateX(0); }
            100% { transform: translateX(-100%); }
        }
        @media (max-width: 768px) {
            #announcementText { animation-duration: 15s; }
        }

        /* 侧边栏 */
        .sidebar {
            width: 220px;
            min-height: calc(100vh - var(--header-height));
            background: var(--bg-card);
            border-right: 1px solid var(--border);
            padding: 20px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
            overflow-y: auto;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            transition: transform 0.3s ease;
        }
        .sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            color: var(--text-sec);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all var(--transition);
            border: 1px solid transparent;
        }
        .sidebar .nav-link i { width: 20px; text-align: center; font-size: 16px; }
        .sidebar .nav-link:hover {
            background: var(--bg-card-hover);
            color: var(--text);
            border-color: var(--border);
        }
        .sidebar .nav-link.active {
            background: var(--accent-soft);
            color: var(--accent);
            border-color: var(--accent);
        }
        .sidebar .nav-link .badge {
            margin-left: auto;
            background: var(--accent);
            color: #0b0a0c;
            font-size: 10px;
            font-weight: 700;
            padding: 1px 8px;
            border-radius: var(--radius-full);
        }
        .sidebar__logout {
            margin-top: auto;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }
        .sidebar__logout .nav-link { color: var(--text-ter); }
        .sidebar__logout .nav-link:hover { color: var(--danger); border-color: var(--danger); }

        .sidebar__user {
            padding: 12px 8px;
            background: var(--bg-card-hover);
            border-radius: var(--radius-sm);
            margin-bottom: 12px;
            font-size: 13px;
            border: 1px solid var(--border);
        }
        .sidebar__user .name { font-weight: 600; color: var(--text); }
        .sidebar__user .role { font-size: 11px; color: var(--text-ter); }
        .sidebar__user .change-pwd-link {
            display: inline-block;
            margin-top: 6px;
            font-size: 12px;
            color: var(--accent);
            cursor: pointer;
            text-decoration: none;
        }
        .sidebar__user .change-pwd-link:hover { text-decoration: underline; }

        /* 主内容布局 */
        .app-wrapper { display: flex; flex: 1; }
        .main {
            flex: 1;
            padding: 24px 32px 40px;
            overflow-y: auto;
            max-width: calc(100% - 220px);
        }

        /* 移动端适配 */
        @media (max-width: 768px) {
            .topbar__hamburger { display: block; }
            .topbar__user .name { display: none; }
            .sidebar {
                position: fixed;
                top: var(--header-height);
                left: 0;
                bottom: 0;
                width: 280px;
                transform: translateX(-100%);
                z-index: 999;
                background: var(--bg);
                border-right: 1px solid var(--border);
                box-shadow: 4px 0 24px rgba(0,0,0,0.4);
                transition: transform 0.3s ease;
                padding: 16px 12px;
                overflow-y: auto;
            }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: var(--header-height);
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 998;
            }
            .sidebar-overlay.active { display: block; }
            .main { max-width: 100%; padding: 16px 16px 30px; }
        }

        /* 通用组件 */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 24px;
            margin-bottom: 20px;
            transition: border-color var(--transition);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .card:hover { border-color: var(--border-active); }
        .card__title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        @media (max-width: 1024px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }

        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: var(--text-sec); margin-bottom: 4px; }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text);
            font-size: 14px;
            font-family: var(--font);
            transition: border-color var(--transition);
            outline: none;
        }
        .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        .form-control::placeholder { color: var(--text-ter); }
        textarea.form-control { resize: vertical; min-height: 80px; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 20px;
            border-radius: var(--radius-full);
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--text-sec);
            font-size: 13px;
            font-weight: 500;
            font-family: var(--font);
            cursor: pointer;
            transition: all var(--transition);
            text-decoration: none;
        }
        .btn:hover {
            background: var(--bg-card-hover);
            border-color: var(--border-active);
            color: var(--text);
            transform: translateY(-1px);
        }
        .btn:active { transform: scale(0.96); }
        .btn--primary { background: var(--accent); border-color: var(--accent); color: #0b0a0c; }
        .btn--primary:hover { background: #e09e5a; border-color: #e09e5a; color: #0b0a0c; box-shadow: 0 4px 24px var(--accent-glow); }
        .btn--danger { background: var(--danger); border-color: var(--danger); color: #fff; }
        .btn--danger:hover { background: #c0392b; border-color: #c0392b; color: #fff; }
        .btn--success { background: var(--success); border-color: var(--success); color: #0b0a0c; }
        .btn--success:hover { background: #45b7a0; border-color: #45b7a0; color: #0b0a0c; }
        .btn--sm { padding: 4px 12px; font-size: 12px; }
        .btn--ghost { background: transparent; border-color: transparent; }
        .btn--ghost:hover { background: var(--bg-card-hover); border-color: var(--border); }

        /* TODO 列表 */
        .todo-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }
        .todo-item:last-child { border-bottom: none; }
        .todo-item .todo-text { flex: 1; font-size: 14px; }
        .todo-item .todo-text.done-text { text-decoration: line-through; color: var(--text-ter); }
        .todo-item .todo-meta { font-size: 11px; color: var(--text-ter); }
        .todo-item .todo-actions button {
            background: none;
            border: none;
            color: var(--text-ter);
            cursor: pointer;
            padding: 4px 6px;
            border-radius: var(--radius-sm);
            transition: all var(--transition);
            font-size: 14px;
        }
        .todo-item .todo-actions button:hover { background: var(--bg-card-hover); color: var(--text); }
        .todo-item .todo-actions .del:hover { color: var(--danger); }

        .progress-slider {
            -webkit-appearance: none;
            appearance: none;
            height: 4px;
            border-radius: var(--radius-full);
            background: var(--border);
            outline: none;
            transition: background 0.2s;
        }
        .progress-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--accent);
            cursor: pointer;
            border: 2px solid var(--bg);
            box-shadow: 0 2px 8px var(--accent-glow);
        }
        .progress-slider::-moz-range-thumb {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--accent);
            cursor: pointer;
            border: 2px solid var(--bg);
        }
        .progress-label { font-weight: 600; font-size: 13px; min-width: 40px; text-align: center; color: var(--text); }

        /* 留言/日志/公告 */
        .message-item, .log-item, .announcement-item {
            padding: 14px 0;
            border-bottom: 1px solid var(--border);
        }
        .message-item:last-child, .log-item:last-child, .announcement-item:last-child { border-bottom: none; }
        .message-item .head, .log-item .head, .announcement-item .head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: var(--text-sec);
            margin-bottom: 4px;
            flex-wrap: wrap;
            gap: 4px;
        }
        .message-item .head .user, .log-item .head .user, .announcement-item .head .user {
            font-weight: 600;
            color: var(--text);
        }
        .message-item .body, .log-item .body, .announcement-item .body {
            font-size: 14px;
            line-height: 1.7;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .message-item .footer, .announcement-item .footer {
            margin-top: 6px;
            font-size: 12px;
            color: var(--text-ter);
            display: flex;
            gap: 12px;
            align-items: center;
        }

        /* 会议卡片 */
        .meeting-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
            transition: all var(--transition);
        }
        .meeting-card:hover { border-color: var(--border-active); transform: translateY(-2px); }
        .meeting-card .title { font-size: 16px; font-weight: 600; }
        .meeting-card .meta { font-size: 13px; color: var(--text-sec); margin: 6px 0 10px; }
        .meeting-card .status {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 12px;
            border-radius: var(--radius-full);
            background: var(--accent-soft);
            color: var(--accent);
        }
        .meeting-card .status.active { background: rgba(78,205,196,0.2); color: var(--success); }
        .meeting-card .status.ended { background: rgba(231,76,111,0.15); color: var(--danger); }

        /* 空状态 & 提示 */
        .empty-state { text-align: center; padding: 32px 0; color: var(--text-ter); }
        .empty-state i { opacity: 0.15; font-size: 48px; margin-bottom: 12px; display: block; }
        .alert {
            padding: 12px 18px;
            border-radius: var(--radius-sm);
            margin-bottom: 16px;
            font-size: 14px;
            border: 1px solid transparent;
        }
        .alert--success { background: rgba(78,205,196,0.15); border-color: var(--success); color: var(--success); }
        .alert--danger { background: rgba(231,76,111,0.15); border-color: var(--danger); color: var(--danger); }
        .alert--warning { background: rgba(249,202,36,0.15); border-color: var(--warning); color: var(--warning); }

        .flex { display: flex; }
        .flex-center { align-items: center; justify-content: center; }
        .gap-sm { gap: 8px; }
        .gap-md { gap: 16px; }
        .mt-sm { margin-top: 8px; }
        .mt-md { margin-top: 16px; }
        .mb-sm { margin-bottom: 8px; }
        .mb-md { margin-bottom: 16px; }
        .text-center { text-align: center; }
        .text-ter { color: var(--text-ter); }
        .w-full { width: 100%; }

        .fade-in {
            animation: fadeUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* 时间轴滚动条 */
        .timeline-wrapper::-webkit-scrollbar { height: 4px; }
        .timeline-wrapper::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 2px; }
        .timeline-wrapper::-webkit-scrollbar-track { background: var(--border); border-radius: 2px; }

        /* 聊天室 */
        #chatMessages::-webkit-scrollbar { width: 4px; }
        #chatMessages::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 2px; }
        #chatMessages::-webkit-scrollbar-track { background: var(--border); border-radius: 2px; }

        .filter-btn.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #0b0a0c;
            font-weight: 600;
        }

        /* 公共聊天室 - 消息容器固定高度 */
        #chatMessages { scroll-behavior: smooth; }
        #chatMessages .html-preview iframe, #chatMessages .html-preview img { max-width: 100%; }
        #chatMessages .html-preview { background: var(--bg); }
        #chatMessages a { color: var(--accent); }

        /* 文档编辑器 */
        #editorContent { transition: border-color 0.2s; }
        #editorContent:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        #previewContent { color: var(--text); word-break: break-word; }
        #previewContent h1, #previewContent h2, #previewContent h3 { color: var(--text); }
        #previewContent a { color: var(--accent); }
        #previewContent code { background: var(--bg); padding: 1px 6px; border-radius: 4px; font-size: 12px; }
        #previewContent pre { background: var(--bg); padding: 12px; border-radius: 8px; overflow-x: auto; }
        #previewContent img { max-width: 100%; border-radius: 8px; }
        #previewContent blockquote { border-left: 3px solid var(--accent); padding-left: 12px; margin: 8px 0; color: var(--text-sec); }
        #previewContent table { border-collapse: collapse; width: 100%; margin: 8px 0; }
        #previewContent th, #previewContent td { border: 1px solid var(--border); padding: 6px 12px; text-align: left; }
        #previewContent th { background: var(--bg-card); }

        /* 文档列表 */
        .doc-item:hover { background: var(--bg-card-hover); border-radius: 8px; margin: 0 -8px; padding: 12px 8px !important; }

        /* 版本历史弹窗 */
        #historyModal { backdrop-filter: blur(4px); }
        #historyModal > div { animation: fadeUp 0.2s ease; }

        /* 聊天消息中代码块 */
        .chat-msg code { background: var(--bg-card); padding: 1px 6px; border-radius: 4px; font-size: 12px; border: 1px solid var(--border); }
        /* 链接预览卡片 */
        .link-preview-card { display:block; margin-top:8px; text-decoration:none; color:inherit; border:1px solid var(--border); border-radius:8px; overflow:hidden; transition:border-color 0.2s; }
        .link-preview-card:hover { border-color: var(--accent); }
        .link-preview-card-sm { display:block; margin-top:6px; text-decoration:none; color:inherit; border:1px solid var(--border); border-radius:8px; overflow:hidden; transition:border-color 0.2s; }
        .link-preview-card-sm:hover { border-color: var(--accent); }
    </style>
</head>
<body>

    <!-- 顶部导航 -->
    <header class="topbar">
        <a href="?page=dashboard" class="topbar__brand">
            <i class="fas fa-cube"></i>
            <span>Workspace</span>
        </a>
        <div class="topbar__right">
            <div class="topbar__user">
                <i class="fas fa-user-circle"></i>
                <span class="name"><?= htmlspecialchars($username) ?></span>
                <span style="font-size:11px; color:var(--text-ter);">(<?= htmlspecialchars($role) ?>)</span>
            </div>
            <button class="topbar__hamburger" id="hamburgerBtn" aria-label="切换菜单">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </header>

    <!-- 滚动公告 -->
    <div id="scrollAnnouncement">
        <div id="announcementText">
            <i class="fas fa-bullhorn" style="margin-right:8px;color:var(--accent);"></i>
            <span id="announcementContent">加载中...</span>
        </div>
    </div>

    <!-- 移动端遮罩 -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- 主体 -->
    <div class="app-wrapper">
        <!-- 侧边栏 -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar__user">
                <div class="name"><i class="fas fa-user" style="margin-right:6px;"></i><?= htmlspecialchars($username) ?></div>
                <div class="role"><?= htmlspecialchars($role) ?></div>
                <a class="change-pwd-link" id="changePwdLink"><i class="fas fa-key"></i> 修改密码</a>
            </div>
            <nav>
                <a href="?page=dashboard" class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>">
                    <i class="fas fa-th-large"></i> 仪表盘
                </a>
                <a href="?page=todos" class="nav-link <?= $page === 'todos' ? 'active' : '' ?>">
                    <i class="fas fa-check-circle"></i> TODO清单
                    <span class="badge" id="todoBadge">0%</span>
                </a>
                <a href="?page=logs" class="nav-link <?= $page === 'logs' ? 'active' : '' ?>">
                    <i class="fas fa-history"></i> 工作日志
                </a>
                <a href="?page=messages" class="nav-link <?= $page === 'messages' ? 'active' : '' ?>">
                    <i class="fas fa-comment-dots"></i> 留言板
                    <span class="badge" id="msgBadge">0</span>
                </a>
                <a href="?page=meetings" class="nav-link <?= $page === 'meetings' ? 'active' : '' ?>">
                    <i class="fas fa-users"></i> 会议密室
                </a>
                <a href="?page=public_chat" class="nav-link <?= $page === 'public_chat' ? 'active' : '' ?>">
                    <i class="fas fa-comments"></i> 公共聊天室
                </a>
                <a href="?page=documents" class="nav-link <?= $page === 'documents' ? 'active' : '' ?>">
                    <i class="fas fa-file-alt"></i> 共享文档
                </a>
                <a href="?page=announcements" class="nav-link <?= $page === 'announcements' ? 'active' : '' ?>">
                    <i class="fas fa-bullhorn"></i> 公告
                </a>
                <?php if ($role === 'admin') : ?>
                <a href="?page=admin" class="nav-link <?= $page === 'admin' ? 'active' : '' ?>">
                    <i class="fas fa-user-cog"></i> 管理
                </a>
                <?php endif; ?>
            </nav>
            <div class="sidebar__logout">
                <a href="logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> 退出
                </a>
            </div>
        </aside>

        <!-- 主内容 -->
        <main class="main">
            <?php include "pages/{$page}.php"; ?>
        </main>
    </div>

    <script>
        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // 更新徽章
        async function updateBadges() {
            try {
                const todoRes = await fetch('api/todo_count.php');
                const todoData = await todoRes.json();
                const todoBadge = document.getElementById('todoBadge');
                if (todoBadge) todoBadge.textContent = todoData.avg_progress + '%';

                const msgRes = await fetch('api/message_count.php');
                const msgData = await msgRes.json();
                const msgBadge = document.getElementById('msgBadge');
                if (msgBadge) msgBadge.textContent = msgData.count || 0;
            } catch (_) {}
        }

        // 修改密码
        document.getElementById('changePwdLink')?.addEventListener('click', async function(e) {
            e.preventDefault();
            const oldPwd = prompt('请输入当前密码：');
            if (oldPwd === null) return;
            const newPwd = prompt('请输入新密码（至少4位）：');
            if (newPwd === null || newPwd.length < 4) return alert('密码至少4位');
            const confirmPwd = prompt('请再次输入新密码：');
            if (confirmPwd !== newPwd) return alert('两次密码不一致');

            const formData = new FormData();
            formData.append('old_password', oldPwd);
            formData.append('new_password', newPwd);
            formData.append('confirm_password', confirmPwd);

            try {
                const res = await fetch('api/change_password.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    alert('密码已修改，请重新登录');
                    location.href = 'logout.php';
                } else {
                    alert('错误: ' + (data.error || '修改失败'));
                }
            } catch (_) {
                alert('网络错误，请重试');
            }
        });

        // 移动端菜单
        const hamburger = document.getElementById('hamburgerBtn');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        function toggleMenu() {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        }

        hamburger.addEventListener('click', toggleMenu);
        overlay.addEventListener('click', toggleMenu);

        sidebar.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });

        // 加载滚动公告
        async function loadAnnouncement() {
            try {
                const res = await fetch('api/scroll_announcement.php');
                const data = await res.json();
                document.getElementById('announcementContent').textContent = data.content || '暂无公告';
            } catch (_) {
                document.getElementById('announcementContent').textContent = '公告加载失败';
            }
        }
        loadAnnouncement();

        updateBadges();
        setInterval(updateBadges, 30000);
        console.log('Forge Workspace 已启动 (Font Awesome 版)');
    </script>

</body>
</html>