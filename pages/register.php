<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (strlen($username) < 3) $error = '用户名至少3个字符';
    elseif (strlen($password) < 4) $error = '密码至少4个字符';
    elseif ($password !== $confirm) $error = '两次密码不一致';
    else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = '用户名已被使用';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'member')");
            $stmt->execute([$username, $hash]);
            header('Location: login.php?registered=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>注册 - Forge Workspace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body { font-family: 'Inter', sans-serif; background: #0b0a0c; color: #f0ede8; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin:0; }
        .auth-box { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.07); border-radius: 12px; padding: 40px; width: 100%; max-width: 400px; backdrop-filter: blur(16px); }
        .auth-box h1 { font-size: 24px; margin-bottom: 6px; }
        .auth-box .sub { color: #9a9792; margin-bottom: 24px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; color: #9a9792; margin-bottom: 4px; }
        .form-control { width: 100%; padding: 10px 14px; background: #0b0a0c; border: 1px solid rgba(255,255,255,0.07); border-radius: 6px; color: #f0ede8; font-size: 14px; }
        .form-control:focus { border-color: #d48c5c; outline: none; }
        .btn { display: inline-block; padding: 10px 20px; border-radius: 9999px; background: #d48c5c; color: #0b0a0c; border: none; font-weight: 600; cursor: pointer; width: 100%; font-size: 14px; }
        .btn:hover { background: #e09e5a; }
        .footer-link { text-align: center; margin-top: 16px; font-size: 14px; color: #9a9792; }
        .footer-link a { color: #d48c5c; text-decoration: none; }
        .alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; background: rgba(231,76,111,0.15); border: 1px solid #e74c6f; color: #e74c6f; }
    </style>
</head>
<body>
    <div class="auth-box">
        <h1><i class="fas fa-pen-to-square"></i> 注册</h1>
        <div class="sub">加入工作交流平台</div>
        <?php if (isset($error)) echo '<div class="alert">' . htmlspecialchars($error) . '</div>'; ?>
        <form method="POST">
            <div class="form-group">
                <label>用户名</label>
                <input type="text" name="username" class="form-control" required>
            </div>
            <div class="form-group">
                <label>密码</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-group">
                <label>确认密码</label>
                <input type="password" name="confirm" class="form-control" required>
            </div>
            <button type="submit" class="btn">注册</button>
        </form>
        <div class="footer-link">已有账号？ <a href="login.php">去登录</a></div>
    </div>
</body>
</html>