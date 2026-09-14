<?php
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'กรุณากรอกอีเมล';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'รูปแบบอีเมลไม่ถูกต้อง';
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT * FROM `cq_users` WHERE `email` = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Generate token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Invalidate previous tokens
            $pdo->prepare("UPDATE `cq_password_resets` SET `used` = 1 WHERE `email` = ? AND `used` = 0")->execute([$email]);
            
            // Insert new token
            $stmt = $pdo->prepare("INSERT INTO `cq_password_resets` (`email`, `token`, `expires_at`) VALUES (?, ?, ?)");
            $stmt->execute([$email, $token, $expiresAt]);
            
            // Send email via Resend
            $resetUrl = $app_url . '/resetpassword.php?token=' . $token;
            $html = "
                <div style='font-family:Inter,sans-serif;max-width:600px;margin:0 auto;padding:40px 20px;'>
                    <div style='text-align:center;margin-bottom:30px;'>
                        <h1 style='color:#00D4AA;font-size:28px;margin:0;'>CodeQuest</h1>
                    </div>
                    <div style='background:#16162A;border-radius:16px;padding:32px;border:1px solid rgba(255,255,255,0.06);'>
                        <h2 style='color:#F0F0F5;font-size:20px;margin:0 0 16px;'>รีเซ็ตรหัสผ่าน</h2>
                        <p style='color:#A0A0B8;font-size:14px;line-height:1.6;margin:0 0 24px;'>
                            คลิกปุ่มด้านล่างเพื่อรีเซ็ตรหัสผ่านของคุณ ลิงก์นี้จะหมดอายุภายใน 1 ชั่วโมง
                        </p>
                        <div style='text-align:center;margin:24px 0;'>
                            <a href='" . htmlspecialchars($resetUrl) . "' style='display:inline-block;background:linear-gradient(135deg,#00D4AA,#00C4A0);color:#0A0A12;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:600;font-size:14px;'>
                                รีเซ็ตรหัสผ่าน
                            </a>
                        </div>
                        <p style='color:#6C6C88;font-size:12px;margin:0;'>
                            หากคุณไม่ได้ร้องขอรีเซ็ตรหัสผ่าน กรุณาละเลยอีเมลนี้
                        </p>
                    </div>
                </div>
            ";
            
            $emailSent = sendEmail($email, 'CodeQuest - รีเซ็ตรหัสผ่าน', $html);
            
            if ($emailSent) {
                $success = 'ส่งลิงก์รีเซ็ตรหัสผ่านไปที่อีเมลของคุณแล้ว กรุณาตรวจสอบกล่องขาเข้าด้วย';
            } else {
                $error = 'ไม่สามารถส่งอีเมลได้ กรุณาลองอีกครั้ง';
            }
        } else {
            $error = 'ไม่มีอีเมลนี้ในระบบ (อีเมลนี้ยังไม่ได้ลงทะเบียน)';
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
    <title>CodeQuest - Forgot Password</title>
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
            <h1>Forgot Password</h1>
            <p>กรอกอีเมลที่ลงทะเบียนไว้ เราจะส่งลิงก์รีเซ็ตรหัสผ่านไปให้</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-4">
                    <label class="form-label" for="fp-email">Email</label>
                    <div class="input-icon-wrapper">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <input type="email" name="email" class="form-control has-icon" placeholder="email@example.com" required id="fp-email">
                    </div>
                </div>

                <button type="submit" class="btn btn-cq-primary w-100" style="min-height:48px;">
                    Send Reset Link
                </button>

                <div class="auth-switch">
                    <a href="login.php">&larr; Back to Login</a>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>