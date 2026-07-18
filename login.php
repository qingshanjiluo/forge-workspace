<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header('Location: index.php');
        exit;
    } else {
        $error = '用户名或密码错误';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>登录 - Forge Workspace</title>
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
        <h1><i class="fas fa-lock"></i> 登录</h1>
        <div class="sub">欢迎回到工作交流平台</div>
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
            <button type="submit" class="btn">登录</button>
        </form>
        <div class="footer-link">还没有账号？ <a href="register.php">立即注册</a></div>
    </div>
</body>
</html>