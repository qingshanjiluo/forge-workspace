<?php
require_once __DIR__ . '/../config.php';

$share_token = $_GET['share'] ?? '';
if (!$share_token) {
    http_response_code(400);
    die('缺少分享令牌');
}

$stmt = $pdo->prepare("SELECT d.*, u.username AS author_name, COALESCE(u.display_name, u.username) AS author_display
                       FROM shared_documents d JOIN users u ON d.user_id = u.id
                       WHERE d.share_token = ?");
$stmt->execute([$share_token]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('文档不存在或已取消分享');
}

$content = $doc['content'] ?? '';
$doc_type = $doc['doc_type'] ?? 'text';
$title = $doc['title'] ?? '未命名文档';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> · 共享文档</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', -apple-system, 'Segoe UI', 'PingFang SC', sans-serif;
            background: #0b0a0c; color: #f0ede8; min-height: 100vh;
        }
        .container { max-width: 800px; margin: 0 auto; padding: 40px 20px; }
        .header { margin-bottom: 32px; }
        .header h1 { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
        .header .meta { font-size: 13px; color: #5e5b56; }
        .header .meta i { margin:0 4px; }
        .doc-content {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 12px;
            padding: 32px;
            line-height: 1.8;
            font-size: 15px;
            word-break: break-word;
        }
        .doc-content h1, .doc-content h2, .doc-content h3 { color: #f0ede8; margin: 20px 0 10px; }
        .doc-content h1 { font-size: 22px; }
        .doc-content h2 { font-size: 18px; }
        .doc-content h3 { font-size: 16px; }
        .doc-content a { color: #d48c5c; }
        .doc-content code {
            background: #0b0a0c; padding: 2px 8px; border-radius: 4px;
            font-size: 13px; border: 1px solid rgba(255,255,255,0.07);
        }
        .doc-content pre {
            background: #0b0a0c; padding: 16px; border-radius: 8px;
            overflow-x: auto; border: 1px solid rgba(255,255,255,0.07);
        }
        .doc-content pre code { background: none; border: none; padding: 0; }
        .doc-content img { max-width: 100%; border-radius: 8px; margin: 12px 0; }
        .doc-content blockquote {
            border-left: 3px solid #d48c5c; padding-left: 16px;
            margin: 12px 0; color: #9a9792;
        }
        .doc-content table { border-collapse: collapse; width: 100%; margin: 12px 0; }
        .doc-content th, .doc-content td {
            border: 1px solid rgba(255,255,255,0.07); padding: 8px 12px; text-align: left;
        }
        .doc-content th { background: rgba(255,255,255,0.04); }
        .back-link {
            display: inline-flex; align-items: center; gap: 6px;
            margin-bottom: 20px; color: #9a9792; text-decoration: none; font-size: 14px;
        }
        .back-link:hover { color: #d48c5c; }
        .footer { text-align: center; margin-top: 32px; font-size: 12px; color: #5e5b56; }
        .empty-state { text-align: center; padding: 60px 0; color: #5e5b56; }
        .empty-state i { font-size: 48px; opacity: 0.15; margin-bottom: 12px; display: block; }
        .type-badge {
            display: inline-block; font-size: 11px; font-weight: 600;
            padding: 2px 10px; border-radius: 9999px; margin-left: 8px;
            background: rgba(212,140,92,0.15); color: #d48c5c;
        }
        iframe.sandbox {
            width: 100%; border: none; border-radius: 8px; background: #fff;
            min-height: 400px;
        }
    </style>
</head>
<body>
<div class="container">
    <a href="javascript:history.back()" class="back-link"><i class="fas fa-arrow-left"></i> 返回</a>
    <div class="header">
        <h1><?= htmlspecialchars($title) ?>
            <span class="type-badge"><?= strtoupper(htmlspecialchars($doc_type)) ?></span>
        </h1>
        <div class="meta">
            <i class="fas fa-user"></i> <?= htmlspecialchars($doc['author_display'] ?? $doc['author_name']) ?>
            <i class="fas fa-clock"></i> <?= htmlspecialchars($doc['created_at'] ?? '') ?>
        </div>
    </div>

    <?php if (trim($content) === ''): ?>
        <div class="empty-state">
            <i class="fas fa-file-alt"></i>
            <div>此文档暂无内容</div>
        </div>
    <?php elseif ($doc_type === 'html'): ?>
        <iframe class="sandbox" srcdoc="<?= htmlspecialchars($content) ?>" sandbox="allow-same-origin"></iframe>
    <?php elseif ($doc_type === 'markdown'): ?>
        <div class="doc-content"><?= (function($text) {
            $html = htmlspecialchars($text);
            $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
            $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);
            $html = preg_replace('/`(.+?)`/', '<code>$1</code>', $html);
            $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $html);
            $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
            $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
            $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);
            $paragraphs = preg_split('/\n\n+/', $html);
            return '<div>' . implode('', array_map(function($p) {
                $p = trim($p);
                if (!$p) return '';
                if (preg_match('/^<(h[1-3]|ul|ol|blockquote|pre)/', $p)) return $p;
                return '<p style="margin:10px 0;">' . nl2br($p) . '</p>';
            }, $paragraphs)) . '</div>';
        })($content) ?></div>
    <?php else: ?>
        <div class="doc-content"><?= nl2br(htmlspecialchars($content)) ?></div>
    <?php endif; ?>

    <div class="footer">由 Forge Workspace 提供 · 共享文档</div>
</div>
</body>
</html>
