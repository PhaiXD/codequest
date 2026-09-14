<?php
require_once 'db.php';
requireLogin();

$user = getCurrentUser($pdo);
$theme = getUserTheme($pdo);

// Get user's problem sets
$mySetStmt = $pdo->prepare("
    SELECT ps.*, 
           (SELECT COUNT(*) FROM `cq_problems` WHERE `set_id` = ps.`set_id`) as problem_count
    FROM `cq_problem_sets` ps 
    WHERE ps.`owner_id` = ? 
    ORDER BY ps.`updated_at` DESC
");
$mySetStmt->execute([$user['user_id']]);
$mySets = $mySetStmt->fetchAll();

// Get public problem sets (not owned by current user)
$publicSetStmt = $pdo->prepare("
    SELECT ps.*, u.username as owner_name, u.display_name as owner_display,
           (SELECT COUNT(*) FROM `cq_problems` WHERE `set_id` = ps.`set_id`) as problem_count
    FROM `cq_problem_sets` ps 
    JOIN `cq_users` u ON ps.`owner_id` = u.`user_id`
    WHERE ps.`visibility` = 'public'
    ORDER BY ps.`created_at` DESC
    LIMIT 12
");
$publicSetStmt->execute([]);
$publicSets = $publicSetStmt->fetchAll();

// Get recent rooms hosted
$roomStmt = $pdo->prepare("
    SELECT r.*, ps.title as set_title,
           (SELECT COUNT(*) FROM `cq_participants` WHERE `room_id` = r.`room_id`) as participant_count
    FROM `cq_rooms` r
    JOIN `cq_problem_sets` ps ON r.`set_id` = ps.`set_id`
    WHERE r.`host_id` = ?
    ORDER BY r.`created_at` DESC
    LIMIT 5
");
$roomStmt->execute([$user['user_id']]);
$myRooms = $roomStmt->fetchAll();

// Stats
$submissionCount = $pdo->prepare("SELECT COUNT(DISTINCT `problem_id`) FROM `cq_submissions` WHERE `user_id` = ? AND `status` = 'accepted'");
$submissionCount->execute([$user['user_id']]);
$solvedCount = $submissionCount->fetchColumn();
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodeQuest - Dashboard</title>
    <meta name="description" content="CodeQuest Dashboard - จัดการชุดโจทย์และห้องสอบ">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/theme.js"></script>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="dashboard-container">
        <!-- Hero Section -->
        <div class="dashboard-hero animate-fadeInUp">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2>สวัสดี, <?= htmlspecialchars($user['display_name'] ?? $user['username']) ?>! 👋</h2>
                    <p>พร้อมสร้างโจทย์ เปิดห้องสอบ หรือฝึกทำโจทย์ได้เลย</p>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="problemset_editor.php" class="btn btn-cq-primary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            สร้างชุดโจทย์ใหม่
                        </a>
                        <a href="room_join.php" class="btn btn-cq-secondary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                            Join ห้องสอบ
                        </a>
                        <a href="practice.php" class="btn btn-cq-outline">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            ฝึกทำโจทย์
                        </a>
                    </div>
                </div>
                <div class="col-md-4 mt-3 mt-md-0">
                    <div class="row g-2">
                        <div class="col-12 col-sm-4">
                            <div class="stat-card">
                                <div>
                                    <div class="stat-value text-gradient"><?= count($mySets) ?></div>
                                    <div class="stat-label">ชุดโจทย์</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4">
                            <div class="stat-card">
                                <div>
                                    <div class="stat-value" style="color:var(--cq-success);"><?= $solvedCount ?></div>
                                    <div class="stat-label">ข้อที่ทำได้</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4">
                            <div class="stat-card">
                                <div>
                                    <div class="stat-value" style="color:var(--cq-accent);"><?= count($myRooms) ?></div>
                                    <div class="stat-label">ห้องสอบ</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" id="dashTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#my-sets" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    ชุดโจทย์ของฉัน <span class="badge bg-secondary ms-1"><?= count($mySets) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#explore" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
                    Explore
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#my-rooms" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    ห้องสอบของฉัน
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- My Problem Sets -->
            <div class="tab-pane fade show active" id="my-sets">
                <?php if (empty($mySets)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📝</div>
                        <h3>ยังไม่มีชุดโจทย์</h3>
                        <p>เริ่มสร้างชุดโจทย์แรกของคุณเลย!</p>
                        <a href="problemset_editor.php" class="btn btn-cq-primary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            สร้างชุดโจทย์ใหม่
                        </a>
                    </div>
                <?php else: ?>
                    <div class="row g-3 stagger-children">
                        <?php foreach ($mySets as $set): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="problemset-card" onclick="location.href='problemset_view.php?id=<?= $set['set_id'] ?>'">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="ps-badge <?= $set['visibility'] ?>">
                                        <?= $set['visibility'] === 'public' ? '🌐 Public' : '🔒 Private' ?>
                                    </span>
                                    <span style="font-size:0.75rem;color:var(--cq-text-muted);"><?= htmlspecialchars($set['language']) ?></span>
                                </div>
                                <div class="ps-title"><?= htmlspecialchars($set['title']) ?></div>
                                <div class="ps-desc"><?= htmlspecialchars($set['description'] ?? 'ไม่มีคำอธิบาย') ?></div>
                                <div class="ps-meta">
                                    <span>📝 <?= $set['problem_count'] ?> ข้อ</span>
                                    <span>📅 <?= date('d/m/Y', strtotime($set['updated_at'])) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <!-- Create New Set Card -->
                        <div class="col-md-6 col-lg-4">
                            <div class="problemset-card-add" onclick="location.href='problemset_editor.php'">
                                <div class="icon">+</div>
                                <div class="text">สร้างโจทย์ใหม่</div>
                            </div>
                        </div>

                    </div>
                <?php endif; ?>
            </div>

            <!-- Explore -->
            <div class="tab-pane fade" id="explore">
                <?php if (empty($publicSets)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">🌐</div>
                        <h3>ยังไม่มีชุดโจทย์สาธารณะ</h3>
                        <p>เมื่อมีคนแชร์ชุดโจทย์แบบ Public จะแสดงที่นี่</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3 stagger-children">
                        <?php foreach ($publicSets as $set): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="problemset-card" onclick="location.href='problemset_view.php?id=<?= $set['set_id'] ?>'">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="ps-badge public">🌐 Public</span>
                                    <span style="font-size:0.75rem;color:var(--cq-text-muted);"><?= htmlspecialchars($set['language']) ?></span>
                                </div>
                                <div class="ps-title"><?= htmlspecialchars($set['title']) ?></div>
                                <div class="ps-desc"><?= htmlspecialchars($set['description'] ?? 'ไม่มีคำอธิบาย') ?></div>
                                <div class="ps-meta">
                                    <span>📝 <?= $set['problem_count'] ?> ข้อ</span>
                                    <span>👤 <?= htmlspecialchars($set['owner_display'] ?? $set['owner_name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <!-- Create New Set Card -->
                        <div class="col-md-6 col-lg-4">
                            <div class="problemset-card-add" onclick="location.href='problemset_editor.php'">
                                <div class="icon">+</div>
                                <div class="text">สร้างโจทย์ใหม่</div>
                            </div>
                        </div>

                    </div>
                <?php endif; ?>
            </div>

            <!-- My Rooms -->
            <div class="tab-pane fade" id="my-rooms">
                <?php if (empty($myRooms)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">🎮</div>
                        <h3>ยังไม่เคยเปิดห้องสอบ</h3>
                        <p>สร้างชุดโจทย์ก่อน แล้วก็เปิดห้องสอบได้เลย!</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>PIN</th>
                                    <th>ชุดโจทย์</th>
                                    <th>โหมด</th>
                                    <th>สถานะ</th>
                                    <th>ผู้เข้าร่วม</th>
                                    <th>สร้างเมื่อ</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myRooms as $room): ?>
                                <tr>
                                    <td><span class="font-mono fw-bold" style="color:var(--cq-primary);"><?= htmlspecialchars($room['pin_code']) ?></span></td>
                                    <td><?= htmlspecialchars($room['set_title']) ?></td>
                                    <td>
                                        <span class="ps-badge <?= $room['mode'] === 'realtime' ? 'public' : 'private' ?>">
                                            <?= $room['mode'] === 'realtime' ? 'Real-time' : 'Assign' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $statusColors = ['waiting' => 'var(--cq-warning)', 'started' => 'var(--cq-success)', 'ended' => 'var(--cq-text-muted)'];
                                        $statusLabels = ['waiting' => 'รอเริ่ม', 'started' => 'กำลังสอบ', 'ended' => 'สิ้นสุด'];
                                        ?>
                                        <span style="color:<?= $statusColors[$room['status']] ?>;font-weight:600;font-size:0.85rem;">
                                            ● <?= $statusLabels[$room['status']] ?>
                                        </span>
                                    </td>
                                    <td><?= $room['participant_count'] ?> คน</td>
                                    <td style="font-size:0.8rem;color:var(--cq-text-muted);"><?= date('d/m/Y H:i', strtotime($room['created_at'])) ?></td>
                                    <td>
                                        <?php if ($room['status'] !== 'ended'): ?>
                                        <a href="room_host.php?id=<?= $room['room_id'] ?>" class="btn btn-sm btn-cq-primary">เข้าห้อง</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>