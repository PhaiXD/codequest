<?php
require_once 'db.php';
requireLogin();

$user = getCurrentUser($pdo);
$theme = getUserTheme($pdo);

// Check admin role
if ($user['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

// Handle Admin Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete_user') {
        $targetId = trim($_POST['target_id'] ?? '');
        if ($targetId && $targetId !== $user['user_id']) {
            $pdo->prepare("DELETE FROM `cq_users` WHERE `user_id` = ?")->execute([$targetId]);
        }
    } elseif ($action === 'toggle_role') {
        $targetId = trim($_POST['target_id'] ?? '');
        $newRole = $_POST['new_role'] ?? 'user';
        if ($targetId && $targetId !== $user['user_id'] && in_array($newRole, ['user', 'admin'])) {
            $pdo->prepare("UPDATE `cq_users` SET `role` = ? WHERE `user_id` = ?")->execute([$newRole, $targetId]);
        }
    }
    
    // Redirect to prevent form resubmission
    header('Location: admin.php');
    exit;
}

// Get Stats
$stats = [
    'users' => $pdo->query("SELECT COUNT(*) FROM `cq_users`")->fetchColumn(),
    'problem_sets' => $pdo->query("SELECT COUNT(*) FROM `cq_problem_sets`")->fetchColumn(),
    'rooms' => $pdo->query("SELECT COUNT(*) FROM `cq_rooms`")->fetchColumn(),
    'submissions' => $pdo->query("SELECT COUNT(*) FROM `cq_submissions`")->fetchColumn()
];

// Get User List
$users = $pdo->query("
    SELECT u.*,
           (SELECT COUNT(*) FROM `cq_problem_sets` WHERE `owner_id` = u.`user_id`) as sets_count,
           (SELECT COUNT(*) FROM `cq_rooms` WHERE `host_id` = u.`user_id`) as rooms_hosted
    FROM `cq_users` u
    ORDER BY u.`created_at` DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodeQuest - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/theme.js"></script>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="dashboard-container">
        <div class="d-flex justify-content-between align-items-center mb-4 animate-fadeInUp">
            <div>
                <h2 class="fw-bold mb-1">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-2" style="color:var(--cq-warning);"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                    Admin Dashboard
                </h2>
                <p style="color:var(--cq-text-secondary)">จัดการผู้ใช้และดูสถิติภาพรวมของระบบ</p>
            </div>
        </div>

        <!-- System Stats -->
        <div class="row g-3 mb-4 animate-fadeInUp" style="animation-delay: 0.1s;">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value text-gradient"><?= number_format($stats['users']) ?></div>
                        <div class="stat-label">ผู้ใช้ทั้งหมด</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" style="color:var(--cq-accent);"><?= number_format($stats['problem_sets']) ?></div>
                        <div class="stat-label">ชุดโจทย์</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" style="color:var(--cq-warning);"><?= number_format($stats['rooms']) ?></div>
                        <div class="stat-label">ห้องสอบ</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" style="color:var(--cq-success);"><?= number_format($stats['submissions']) ?></div>
                        <div class="stat-label">โค้ดที่ส่ง (Submissions)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- User Management -->
        <div class="cq-card p-0 animate-fadeInUp" style="animation-delay: 0.2s;">
            <div class="p-4 border-bottom" style="border-color:var(--cq-border) !important;">
                <h5 class="fw-bold mb-0">ผู้ใช้งานระบบ (User Management)</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead style="background:rgba(0,0,0,0.02);">
                        <tr>
                            <th class="ps-4">User</th>
                            <th>Role</th>
                            <th>Score</th>
                            <th>Sets/Rooms</th>
                            <th>Joined</th>
                            <th class="pe-4 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="profile-avatar" style="width:32px;height:32px;font-size:0.8rem;">
                                        <?php if (!empty($u['avatar_url'])): ?>
                                            <img src="<?= htmlspecialchars($u['avatar_url']) ?>" alt="avatar">
                                        <?php else: ?>
                                            <?= getInitials($u['display_name'] ?? $u['username']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fw-semibold" style="line-height:1.2;"><?= htmlspecialchars($u['display_name'] ?? $u['username']) ?></div>
                                        <div style="font-size:0.75rem;color:var(--cq-text-muted);">@<?= htmlspecialchars($u['username']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="ps-badge" style="background:rgba(255,170,0,0.15);color:var(--cq-warning);">ADMIN</span>
                                <?php else: ?>
                                    <span class="ps-badge" style="background:var(--cq-bg-body);color:var(--cq-text-muted);border:1px solid var(--cq-border);">USER</span>
                                <?php endif; ?>
                            </td>
                            <td class="font-mono" style="color:var(--cq-warning);"><?= number_format($u['total_score']) ?></td>
                            <td style="font-size:0.85rem;">
                                <span class="text-muted">Sets:</span> <?= $u['sets_count'] ?> <br>
                                <span class="text-muted">Rooms:</span> <?= $u['rooms_hosted'] ?>
                            </td>
                            <td style="font-size:0.85rem;color:var(--cq-text-muted);">
                                <?= date('d/m/y', strtotime($u['created_at'])) ?>
                            </td>
                            <td class="pe-4 text-end">
                                <?php if ($u['user_id'] !== $user['user_id']): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-link text-muted p-0" type="button" data-bs-toggle="dropdown">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow">
                                            <li>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="toggle_role">
                                                    <input type="hidden" name="target_id" value="<?= $u['user_id'] ?>">
                                                    <input type="hidden" name="new_role" value="<?= $u['role'] === 'admin' ? 'user' : 'admin' ?>">
                                                    <button type="submit" class="dropdown-item">
                                                        <?= $u['role'] === 'admin' ? 'ลดเป็น User' : 'ตั้งเป็น Admin' ?>
                                                    </button>
                                                </form>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('ยืนยันการลบผู้ใช้นี้ (และข้อมูลที่เกี่ยวข้องทั้งหมด)?');">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="target_id" value="<?= $u['user_id'] ?>">
                                                    <button type="submit" class="dropdown-item text-danger">ลบผู้ใช้</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>