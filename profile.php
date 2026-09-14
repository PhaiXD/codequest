<?php
require_once 'db.php';
requireLogin();

$user = getCurrentUser($pdo);
$error = '';
$success = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        $displayName = trim($_POST['display_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($displayName)) {
            $error = 'กรุณากรอกชื่อแสดง';
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'รูปแบบอีเมลไม่ถูกต้อง';
        } else {
            // Check email uniqueness
            if (!empty($email) && $email !== $user['email']) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `cq_users` WHERE `email` = ? AND `user_id` != ?");
                $stmt->execute([$email, $user['user_id']]);
                if ($stmt->fetchColumn() > 0) {
                    $error = 'อีเมลนี้ถูกใช้แล้ว';
                }
            }
            
            if (empty($error)) {
                $pdo->prepare("UPDATE `cq_users` SET `display_name` = ?, `email` = ? WHERE `user_id` = ?")
                    ->execute([$displayName, $email, $user['user_id']]);
                $success = 'อัปเดตโปรไฟล์สำเร็จ';
                $user = getCurrentUser($pdo);
            }
        }
    } elseif ($_POST['action'] === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($user['password']) || empty($user['google_id'])) {
            // User has password set
        }
        
        if (empty($currentPassword) && !empty($user['password'])) {
            $error = 'กรุณากรอกรหัสผ่านปัจจุบัน';
        } elseif (!empty($user['password']) && !password_verify($currentPassword, $user['password'])) {
            $error = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
        } elseif (strlen($newPassword) < 6) {
            $error = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'รหัสผ่านใหม่ทั้งสองไม่ตรงกัน';
        } else {
            $pdo->prepare("UPDATE `cq_users` SET `password` = ? WHERE `user_id` = ?")
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['user_id']]);
            $success = 'เปลี่ยนรหัสผ่านสำเร็จ';
        }
    }
}

$theme = getUserTheme($pdo);
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodeQuest - Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/theme.js"></script>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="dashboard-container" style="max-width:800px;">
        <div class="mb-4 animate-fadeInUp">
            <h2 class="fw-bold mb-1">Profile Settings</h2>
            <p style="color:var(--cq-text-secondary)">จัดการข้อมูลส่วนตัวและการตั้งค่า</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- Profile Info -->
        <div class="cq-card mb-4 animate-fadeInUp">
            <div class="d-flex align-items-center gap-3 mb-4">
                <div class="profile-avatar-lg">
                    <?php if (!empty($user['avatar_url'])): ?>
                        <img src="<?= htmlspecialchars($user['avatar_url']) ?>" alt="avatar">
                    <?php else: ?>
                        <?= getInitials($user['display_name'] ?? $user['username']) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <h4 class="mb-0 fw-bold"><?= htmlspecialchars($user['display_name'] ?? $user['username']) ?></h4>
                    <span style="color:var(--cq-text-muted);font-size:0.9rem;">@<?= htmlspecialchars($user['username']) ?></span>
                    <?php if ($user['role'] === 'admin'): ?>
                        <span class="ms-2 ps-badge" style="background:rgba(255,170,0,0.15);color:var(--cq-warning);">ADMIN</span>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                    <div class="form-text">Username ไม่สามารถเปลี่ยนได้</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Display Name</label>
                    <input type="text" name="display_name" class="form-control" value="<?= htmlspecialchars($user['display_name'] ?? '') ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                </div>

                <button type="submit" class="btn btn-cq-primary">Save Changes</button>
            </form>
        </div>

        <!-- Theme Settings -->
        <div class="cq-card mb-4 animate-fadeInUp">
            <h5 class="fw-bold mb-3">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-2" style="vertical-align:-3px;">
                    <circle cx="12" cy="12" r="5"></circle>
                    <line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                    <line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                </svg>
                Appearance
            </h5>
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="fw-semibold">Dark Mode</div>
                    <div style="font-size:0.85rem;color:var(--cq-text-muted);">เปลี่ยนธีมของเว็บ</div>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="darkModeSwitch" <?= $theme === 'dark' ? 'checked' : '' ?> style="cursor:pointer;">
                </div>
            </div>
        </div>

        <!-- Change Password -->
        <div class="cq-card mb-4 animate-fadeInUp">
            <h5 class="fw-bold mb-3">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-2" style="vertical-align:-3px;">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
                Change Password
            </h5>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                
                <?php if (!empty($user['password'])): ?>
                <div class="mb-3">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6">
                </div>

                <div class="mb-3">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                </div>

                <button type="submit" class="btn btn-cq-secondary">Change Password</button>
            </form>
        </div>

        <!-- Stats -->
        <div class="cq-card mb-4 animate-fadeInUp">
            <h5 class="fw-bold mb-3">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-2" style="vertical-align:-3px;">
                    <path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/>
                </svg>
                Statistics
            </h5>
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div>
                            <div class="stat-value"><?= number_format($user['total_score']) ?></div>
                            <div class="stat-label">Total Score</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div>
                            <div class="stat-value"><?= number_format($user['practice_solved']) ?></div>
                            <div class="stat-label">Problems Solved</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <?php
                    $setCount = $pdo->prepare("SELECT COUNT(*) FROM `cq_problem_sets` WHERE `owner_id` = ?");
                    $setCount->execute([$user['user_id']]);
                    ?>
                    <div class="stat-card">
                        <div>
                            <div class="stat-value"><?= $setCount->fetchColumn() ?></div>
                            <div class="stat-label">Problem Sets</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div>
                            <div class="stat-value"><?= date('d/m/y', strtotime($user['created_at'])) ?></div>
                            <div class="stat-label">Joined</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('darkModeSwitch').addEventListener('change', function() {
            CQTheme.apply(this.checked ? 'dark' : 'light');
        });
    </script>
</body>
</html>