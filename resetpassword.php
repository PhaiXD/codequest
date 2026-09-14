<?php
require_once 'db.php';

$error = '';
$success = '';
$validToken = false;
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้อง';
} else {
    // Verify token
    $stmt = $pdo->prepare("SELECT * FROM `cq_password_resets` WHERE `token` = ? AND `used` = 0 AND `expires_at` > NOW()");
    $stmt->execute([$token]);
    $resetRecord = $stmt->fetch();
    
    if (!$resetRecord) {
        $error = 'ลิงก์รีเซ็ตรหัสผ่านหมดอายุหรือถูกใช้แล้ว';
    } else {
        $validToken = true;
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (strlen($password) < 6) {
                $error = 'Password ต้องมีอย่างน้อย 6 ตัวอักษร';
            } elseif ($password !== $confirmPassword) {
                $error = 'Password ทั้งสองไม่ตรงกัน';
            } else {
                // Update password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE `cq_users` SET `password` = ? WHERE `email` = ?")->execute([$hashedPassword, $resetRecord['email']]);
                
                // Mark token as used
                $pdo->prepare("UPDATE `cq_password_resets` SET `used` = 1 WHERE `token` = ?")->execute([$token]);
                
                header('Location: login.php?success=' . urlencode('เปลี่ยนรหัสผ่านสำเร็จแล้ว กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่'));
                exit;
            }
        }
    }
}

$theme = $_COOKIE['cq_theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodeQuest - Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/theme.js"></script>
</head>
<body class="auth-page">

    <div class="auth-header">
        <div class="brand-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
        </div>
        <span class="brand-name">Code<span class="text-gradient">Quest</span></span>
    </div>

    <div class="auth-container">
        <div class="auth-card">
            <h1>Reset Password</h1>
            <p>ตั้งรหัสผ่านใหม่ของคุณ</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($validToken): ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <div class="password-wrapper">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" name="password" class="form-control" id="new-password" placeholder="••••••••" required minlength="6">
                        <button type="button" class="password-toggle" onclick="togglePassword('new-password', this)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="eye-open"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="eye-closed" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Confirm Password</label>
                    <div class="password-wrapper">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" name="confirm_password" class="form-control" id="confirm-password" placeholder="••••••••" required minlength="6">
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm-password', this)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="eye-open"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="eye-closed" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-cq-primary w-100" style="min-height:48px;">Reset Password</button>
            </form>
            <?php else: ?>
                <div class="auth-switch"><a href="forgotpassword.php">ขอลิงก์ใหม่</a> | <a href="login.php">Login</a></div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const eyeOpen = btn.querySelector('.eye-open');
            const eyeClosed = btn.querySelector('.eye-closed');
            if (input.type === 'password') { input.type = 'text'; eyeOpen.style.display = 'none'; eyeClosed.style.display = 'block'; }
            else { input.type = 'password'; eyeOpen.style.display = 'block'; eyeClosed.style.display = 'none'; }
        }
    </script>
</body>
</html>