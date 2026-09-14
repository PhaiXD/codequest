<?php
require_once 'db.php';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = $_GET['success'] ?? '';
$errorParam = $_GET['error'] ?? '';
if ($errorParam) $error = $errorParam;

// ========================================
// Handle Login
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอก Username และ Password';
    } else {
        // Allow login by username or email
        $stmt = $pdo->prepare("SELECT * FROM `cq_users` WHERE `username` = ? OR `email` = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Username หรือ Password ไม่ถูกต้อง';
        }
    }
}

// ========================================
// Handle Google OAuth Callback
// ========================================
if (isset($_GET['code'])) {
    $code = $_GET['code'];

    $tokenUrl = 'https://oauth2.googleapis.com/token';
    $tokenData = [
        'code' => $code,
        'client_id' => $google_client_id,
        'client_secret' => $google_client_secret,
        'redirect_uri' => $google_redirect_uri,
        'grant_type' => 'authorization_code'
    ];

    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $tokenResponse = curl_exec($ch);
    curl_close($ch);

    $tokenInfo = json_decode($tokenResponse, true);

    if (isset($tokenInfo['access_token'])) {
        // Get user info from Google
        $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $tokenInfo['access_token']]);
        $userResponse = curl_exec($ch);
        curl_close($ch);

        $googleUser = json_decode($userResponse, true);

        if (isset($googleUser['id']) && isset($googleUser['email'])) {
            $googleId = $googleUser['id'];
            $email = $googleUser['email'];
            $name = $googleUser['name'] ?? explode('@', $email)[0];
            $avatarUrl = $googleUser['picture'] ?? null;

            // Check if user exists with this Google ID
            $stmt = $pdo->prepare("SELECT * FROM `cq_users` WHERE `google_id` = ?");
            $stmt->execute([$googleId]);
            $existingUser = $stmt->fetch();

            if ($existingUser) {
                // Update avatar if changed
                if ($avatarUrl && $avatarUrl !== $existingUser['avatar_url']) {
                    $pdo->prepare("UPDATE `cq_users` SET `avatar_url` = ? WHERE `user_id` = ?")->execute([$avatarUrl, $existingUser['user_id']]);
                }
                $_SESSION['user_id'] = $existingUser['user_id'];
            } else {
                // Check if email already exists
                $stmt = $pdo->prepare("SELECT * FROM `cq_users` WHERE `email` = ?");
                $stmt->execute([$email]);
                $emailUser = $stmt->fetch();

                if ($emailUser) {
                    $pdo->prepare("UPDATE `cq_users` SET `google_id` = ?, `avatar_url` = COALESCE(`avatar_url`, ?) WHERE `user_id` = ?")->execute([$googleId, $avatarUrl, $emailUser['user_id']]);
                    $_SESSION['user_id'] = $emailUser['user_id'];
                } else {
                    // Create new user
                    $username = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
                    $baseUsername = $username ?: 'user';
                    $counter = 0;
                    while (true) {
                        $checkName = $counter > 0 ? $baseUsername . $counter : $baseUsername;
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `cq_users` WHERE `username` = ?");
                        $stmt->execute([$checkName]);
                        if ($stmt->fetchColumn() == 0) {
                            $username = $checkName;
                            break;
                        }
                        $counter++;
                    }

                    $userId = generateUserId();
                    $stmt = $pdo->prepare("INSERT INTO `cq_users` (`user_id`, `username`, `display_name`, `email`, `password`, `google_id`, `avatar_url`) VALUES (?, ?, ?, ?, '', ?, ?)");
                    $stmt->execute([$userId, $username, $name, $email, $googleId, $avatarUrl]);
                    $_SESSION['user_id'] = $userId;
                }
            }

            header('Location: dashboard.php');
            exit;
        }
    }
    $error = 'ไม่สามารถเข้าสู่ระบบด้วย Google ได้';
}

// Build Google OAuth URL
$googleAuthUrl = '';
if (!empty($google_client_id)) {
    $googleAuthUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $google_client_id,
        'redirect_uri' => $google_redirect_uri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'access_type' => 'online',
        'prompt' => 'consent'
    ]);
}

$theme = $_COOKIE['cq_theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodeQuest - Login</title>
    <meta name="description" content="CodeQuest - แพลตฟอร์มสอบเขียนโปรแกรมแบบ Real-time">
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

    <!-- Login Container -->
    <div class="auth-container">
        <div class="auth-card">

            <h1>Welcome back</h1>
            <p>เข้าสู่ระบบเพื่อเริ่มแข่งขันเขียนโค้ด</p>

            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success d-flex align-items-center gap-2" role="alert">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="" id="form-login">
                <input type="hidden" name="action" value="login">

                <div class="mb-3">
                    <label class="form-label" for="login-username">Username or Email</label>
                    <div class="input-icon-wrapper">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" name="username" class="form-control has-icon" placeholder="username or email" autocomplete="username" required id="login-username">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="login-password">Password</label>
                    <div class="password-wrapper">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" name="password" class="form-control" id="login-password" placeholder="••••••••" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('login-password', this)" aria-label="Toggle password visibility">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="eye-open"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="eye-closed" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                    <a href="forgotpassword.php" class="forgot-link">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-cq-primary w-100 d-flex align-items-center justify-content-center gap-2" style="min-height:48px;" id="btn-login">
                    <span>Login</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                </button>

                <div class="auth-divider">or</div>

                <?php if (!empty($googleAuthUrl)): ?>
                <a href="<?= htmlspecialchars($googleAuthUrl) ?>" class="btn btn-google" id="btn-google-login">
                <?php else: ?>
                <button type="button" class="btn btn-google" id="btn-google-login" onclick="alert('Google OAuth ยังไม่ได้ตั้งค่า')">
                <?php endif; ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                    </svg>
                    Continue with Google
                <?php if (!empty($googleAuthUrl)): ?>
                </a>
                <?php else: ?>
                </button>
                <?php endif; ?>

                <div class="auth-switch">
                    Don't have an account? <a href="register.php">Register</a>
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