<?php
require_once 'db.php';
requireLogin();

$user = getCurrentUser($pdo);
$theme = getUserTheme($pdo);

// If set_id is provided, show problems in that set
$setId = intval($_GET['set_id'] ?? 0);
$currentSet = null;

if ($setId) {
    // Check if user has access to this set (public or owner)
    $stmt = $pdo->prepare("SELECT ps.*, u.display_name FROM `cq_problem_sets` ps LEFT JOIN `cq_users` u ON ps.`owner_id` = u.`user_id` WHERE ps.`set_id` = ? AND (ps.`visibility` = 'public' OR ps.`owner_id` = ?)");
    $stmt->execute([$setId, $user['user_id']]);
    $currentSet = $stmt->fetch();
    
    if (!$currentSet) {
        header('Location: practice.php');
        exit;
    }
    
    $pStmt = $pdo->prepare("
        SELECT p.*,
            (SELECT `status` FROM `cq_submissions` s WHERE s.`problem_id` = p.`problem_id` AND s.`user_id` = ? AND s.`room_id` IS NULL ORDER BY s.`submitted_at` DESC LIMIT 1) as my_status
        FROM `cq_problems` p 
        WHERE p.`set_id` = ? 
        ORDER BY p.`order_index`
    ");
    $pStmt->execute([$user['user_id'], $setId]);
    $problems = $pStmt->fetchAll();
} else {
    // Show public sets for practice
    $stmt = $pdo->prepare("
        SELECT ps.*, u.display_name,
               (SELECT COUNT(*) FROM `cq_problems` WHERE `set_id` = ps.`set_id`) as problem_count,
               (SELECT COUNT(DISTINCT s.`problem_id`) FROM `cq_submissions` s 
                JOIN `cq_problems` p ON s.`problem_id` = p.`problem_id`
                WHERE p.`set_id` = ps.`set_id` AND s.`user_id` = ? AND s.`status` = 'accepted' AND s.`room_id` IS NULL) as solved_count
        FROM `cq_problem_sets` ps 
        JOIN `cq_users` u ON ps.`owner_id` = u.`user_id` 
        WHERE ps.`visibility` = 'public'
        ORDER BY ps.`created_at` DESC
    ");
    $stmt->execute([$user['user_id']]);
    $publicSets = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodeQuest - ฝึกทำโจทย์</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/theme.js"></script>
    <style>
        .progress-bar-custom { height: 6px; background: var(--cq-border); border-radius: 3px; overflow: hidden; margin-top: 12px; }
        .progress-fill { height: 100%; background: var(--cq-success); border-radius: 3px; transition: width 0.5s ease; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="dashboard-container">
        <?php if ($setId && $currentSet): ?>
            <!-- Set View -->
            <a href="practice.php" class="btn btn-sm btn-link px-0 mb-3" style="color:var(--cq-text-muted);text-decoration:none;">
                &larr; กลับหน้ารวมชุดโจทย์
            </a>
            
            <div class="cq-card mb-4 animate-fadeInUp">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h3 class="fw-bold mb-0"><?= htmlspecialchars($currentSet['title']) ?></h3>
                    <span class="badge bg-secondary"><?= htmlspecialchars(strtoupper($currentSet['language'])) ?></span>
                </div>
                <div style="font-size:0.9rem;color:var(--cq-text-muted);">
                    สร้างโดย: <?= htmlspecialchars($currentSet['display_name']) ?>
                </div>
                
                <?php
                    $totalProbs = count($problems);
                    $solvedProbs = count(array_filter($problems, fn($p) => $p['my_status'] === 'accepted'));
                    $percent = $totalProbs > 0 ? ($solvedProbs / $totalProbs) * 100 : 0;
                ?>
                <div class="mt-4">
                    <div class="d-flex justify-content-between align-items-end mb-1">
                        <span style="font-size:0.85rem;font-weight:600;">ความคืบหน้าการฝึก</span>
                        <span style="font-size:0.8rem;color:var(--cq-text-muted);"><?= $solvedProbs ?>/<?= $totalProbs ?> ข้อ</span>
                    </div>
                    <div class="progress-bar-custom">
                        <div class="progress-fill" style="width: <?= $percent ?>%"></div>
                    </div>
                </div>
            </div>

            <div class="row g-3 stagger-children">
                <?php foreach ($problems as $index => $prob): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="problemset-card" onclick="location.href='practice_solve.php?set_id=<?= $setId ?>&prob_id=<?= $prob['problem_id'] ?>'" style="cursor:pointer;position:relative;">
                        <?php if ($prob['my_status'] === 'accepted'): ?>
                            <div style="position:absolute;top:12px;right:12px;color:var(--cq-success);">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                        <?php endif; ?>
                        
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge" style="background:var(--cq-bg-body);color:var(--cq-text-primary);border:1px solid var(--cq-border);">ข้อ <?= $index + 1 ?></span>
                            <span style="font-size:0.75rem;font-weight:600;color: <?= $prob['difficulty'] === 'Easy' ? 'var(--cq-success)' : ($prob['difficulty'] === 'Medium' ? 'var(--cq-warning)' : 'var(--cq-danger)') ?>">
                                <?= $prob['difficulty'] ?>
                            </span>
                        </div>
                        <div class="fw-bold mb-1" style="font-size:1.1rem;padding-right:24px;"><?= htmlspecialchars($prob['title']) ?></div>
                        <div class="ps-desc" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($prob['description']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <!-- Catalog View -->
            <div class="dashboard-hero animate-fadeInUp mb-4" style="background: linear-gradient(135deg, rgba(0,212,170,0.1), rgba(123,97,255,0.05));">
                <h2>Practice Mode 💻</h2>
                <p>เลือกชุดโจทย์สาธารณะเพื่อฝึกเขียนโค้ดและพัฒนาทักษะของคุณ คะแนนที่ได้จะไม่ถูกนำไปคิดใน Leaderboard ห้องสอบ</p>
            </div>

            <?php if (empty($publicSets)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🌱</div>
                    <h3>ยังไม่มีชุดโจทย์ให้ฝึก</h3>
                    <p>ระบบยังไม่มีชุดโจทย์แบบ Public ในขณะนี้</p>
                </div>
            <?php else: ?>
                <div class="row g-3 stagger-children">
                    <?php foreach ($publicSets as $set): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="problemset-card" onclick="location.href='practice.php?set_id=<?= $set['set_id'] ?>'">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge bg-secondary"><?= htmlspecialchars(strtoupper($set['language'])) ?></span>
                                <span style="font-size:0.75rem;color:var(--cq-text-muted);"><?= htmlspecialchars($set['display_name']) ?></span>
                            </div>
                            <div class="ps-title"><?= htmlspecialchars($set['title']) ?></div>
                            <div class="ps-desc"><?= htmlspecialchars($set['description'] ?: 'ไม่มีคำอธิบาย') ?></div>
                            
                            <?php
                                $pPercent = $set['problem_count'] > 0 ? ($set['solved_count'] / $set['problem_count']) * 100 : 0;
                            ?>
                            <div class="mt-3">
                                <div class="d-flex justify-content-between align-items-end mb-1">
                                    <span style="font-size:0.75rem;color:var(--cq-text-muted);">ความคืบหน้า</span>
                                    <span style="font-size:0.75rem;font-weight:600;"><?= $set['solved_count'] ?>/<?= $set['problem_count'] ?> ข้อ</span>
                                </div>
                                <div class="progress-bar-custom" style="height:4px;margin-top:4px;">
                                    <div class="progress-fill" style="width: <?= $pPercent ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php include 'includes/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>