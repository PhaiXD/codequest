<?php
require_once 'db.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

// Handle Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $username = trim($_POST['username'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($username) || empty($email) || empty($password)) {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $error = 'Username ต้องมีความยาว 3-50 ตัวอักษร';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $error = 'Username ใช้ได้เฉพาะ ตัวอักษร, ตัวเลข, และ _ เท่านั้น';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'รูปแบบอีเมลไม่ถูกต้อง';
    } elseif (strlen($password) < 6) {
        $error = 'Password ต้องมีอย่างน้อย 6 ตัวอักษร';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password ทั้งสองไม่ตรงกัน';
    } else {
        // Check unique username
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `cq_users` WHERE `username` = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Username นี้ถูกใช้แล้ว';
        } else {
            // Check unique email
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `cq_users` WHERE `email` = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'อีเมลนี้ถูกใช้แล้ว';
            } else {
                // Create user
                $userId = generateUserId();
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                if (empty($displayName)) $displayName = $username;
                
                $stmt = $pdo->prepare("INSERT INTO `cq_users` (`user_id`, `username`, `display_name`, `email`, `password`) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $username, $displayName, $email, $hashedPassword]);
                
                // Auto login
                $_SESSION['user_id'] = $userId;
                header('Location: dashboard.php');
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
    <title>CodeQuest - Register</title>
    <meta name="description" content="CodeQuest - สมัครสมาชิกใหม่เพื่อเริ่มแข่งขันเขียนโค้ด">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/theme.js"></script>
</head>
<body class="auth-page">

    <!-- Header -->
    <div class="auth-header">
        <div class="brand-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="16 18 22 12 16 6"></polyline>
                <polyline points="8 6 2 12 8 18"></polyline>
            </svg>
        </div>
        <span class="brand-name">Code<span class="text-gradient">Quest</span></span>
    </div>

    <!-- Register Container -->
    <div class="auth-container">
        <div class="auth-card">

            <h1>Create Account</h1>
            <p>สร้างบัญชีเพื่อเริ่มต้นเขียนโค้ดและแข่งขัน</p>

            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Register Form -->
            <form method="POST" action="" id="form-register">
                <input type="hidden" name="action" value="register">

                <div class="mb-3">
                    <label class="form-label" for="reg-username">Username <span class="text-danger">*</span></label>
                    <div class="input-icon-wrapper">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" name="username" class="form-control has-icon" placeholder="username" required id="reg-username" pattern="[a-zA-Z0-9_]+" minlength="3" maxlength="50" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                    <div class="form-text">ใช้ได้เฉพาะ a-z, 0-9 และ _ (3-50 ตัว)</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="reg-displayname">Display Name</label>
                    <div class="input-icon-wrapper">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <input type="text" name="display_name" class="form-control has-icon" placeholder="ชื่อที่แสดง" id="reg-displayname" maxlength="100" value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="reg-email">Email <span class="text-danger">*</span></label>
                    <div class="input-icon-wrapper">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <input type="email" name="email" class="form-control has-icon" placeholder="email@example.com" required id="reg-email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="reg-password">Password <span class="text-danger">*</span></label>
                    <div class="password-wrapper">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" name="password" class="form-control" id="reg-password" placeholder="••••••••" required minlength="6">
                        <button type="button" class="password-toggle" onclick="togglePassword('reg-password', this)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="eye-open"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="eye-closed" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                    <div class="form-text">อย่างน้อย 6 ตัวอักษร</div>
                </div>

                <div class="mb-4">
                    <label class="form-label" for="reg-confirm">Confirm Password <span class="text-danger">*</span></label>
                    <div class="password-wrapper">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" name="confirm_password" class="form-control" id="reg-confirm" placeholder="••••••••" required minlength="6">
                        <button type="button" class="password-toggle" onclick="togglePassword('reg-confirm', this)">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="eye-open"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="eye-closed" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-cq-primary w-100 d-flex align-items-center justify-content-center gap-2" style="min-height:48px;" id="btn-register">
                    <span>Create Account</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                </button>

                <div class="auth-switch">
                    Already have an account? <a href="login.php">Login</a>
                </div>
            </form>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const eyeOpen = btn.querySelector('.eye-open');
            const eyeClosed = btn.querySelector('.eye-closed');
            if (input.type === 'password') {
                input.type = 'text';
                eyeOpen.style.display = 'none';
                eyeClosed.style.display = 'block';
            } else {
                input.type = 'password';
                eyeOpen.style.display = 'block';
                eyeClosed.style.display = 'none';
            }
        }
    </script>
</body>
</html>