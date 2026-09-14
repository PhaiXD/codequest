<?php
require_once 'db.php';
requireLogin();

$user = getCurrentUser($pdo);
$theme = getUserTheme($pdo);
$roomId = intval($_GET['id'] ?? 0);

if (!$roomId) {
    header('Location: dashboard.php');
    exit;
}

// Get room details
$stmt = $pdo->prepare("
    SELECT r.*, ps.title as set_title, ps.language, u.display_name as host_name
    FROM `cq_rooms` r
    JOIN `cq_problem_sets` ps ON r.`set_id` = ps.`set_id`
    JOIN `cq_users` u ON r.`host_id` = u.`user_id`
    WHERE r.`room_id` = ?
");
$stmt->execute([$roomId]);
$room = $stmt->fetch();

if (!$room) {
    header('Location: dashboard.php');
    exit;
}

$isHost = ($room['host_id'] === $user['user_id']);

// Get Leaderboard
$lStmt = $pdo->prepare("
    SELECT p.user_id, u.username, u.display_name, u.avatar_url,
           (SELECT SUM(score) FROM `cq_submissions` s WHERE s.`user_id` = p.`user_id` AND s.`room_id` = ?) as total_score,
           (SELECT MAX(created_at) FROM `cq_submissions` s WHERE s.`user_id` = p.`user_id` AND s.`room_id` = ?) as last_submit
    FROM `cq_participants` p
    JOIN `cq_users` u ON p.`user_id` = u.`user_id`
    WHERE p.`room_id` = ?
    ORDER BY total_score DESC, last_submit ASC
");
$lStmt->execute([$roomId, $roomId, $roomId]);
$leaderboard = $lStmt->fetchAll();

// Find current user's rank
$myRank = 0;
$myScore = 0;
foreach ($leaderboard as $idx => $p) {
    if ($p['user_id'] === $user['user_id']) {
        $myRank = $idx + 1;
        $myScore = intval($p['total_score']);
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Results - CodeQuest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/theme.js"></script>
    <style>
        .rank-card { background: var(--cq-bg-card); border: 1px solid var(--cq-border); border-radius: 12px; padding: 16px; display: flex; align-items: center; margin-bottom: 12px; transition: transform 0.2s; }
        .rank-card:hover { transform: translateX(5px); }
        .rank-number { width: 40px; font-size: 1.5rem; font-weight: 800; color: var(--cq-text-muted); text-align: center; }
        .rank-1 .rank-number { color: #FFD700; text-shadow: 0 0 10px rgba(255, 215, 0, 0.5); font-size: 2rem; }
        .rank-2 .rank-number { color: #C0C0C0; text-shadow: 0 0 10px rgba(192, 192, 192, 0.5); font-size: 1.8rem; }
        .rank-3 .rank-number { color: #CD7F32; text-shadow: 0 0 10px rgba(205, 127, 50, 0.5); font-size: 1.6rem; }
        .rank-score { font-size: 1.5rem; font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--cq-warning); margin-left: auto; }
        
        .confetti { position: absolute; width: 10px; height: 10px; background-color: #f00; animation: fall 3s linear infinite; }
        @keyframes fall { to { transform: translateY(100vh) rotate(720deg); } }

        @media (max-width: 576px) {
            .rank-card { padding: 12px; }
            .rank-number { width: 30px; font-size: 1.2rem; }
            .rank-1 .rank-number { font-size: 1.5rem; }
            .rank-2 .rank-number { font-size: 1.3rem; }
            .rank-3 .rank-number { font-size: 1.2rem; }
            .rank-score { font-size: 1.2rem; }
        }
    </style>
</head>
<body style="overflow-x: hidden;">
    <?php include 'includes/navbar.php'; ?>

    <div class="dashboard-container" style="max-width: 800px; position: relative; z-index: 1;">
        <div class="text-center mb-5 animate-fadeInUp">
            <h1 class="fw-bold" style="font-size: 2.5rem; text-shadow: 0 0 20px rgba(0, 212, 170, 0.3);">
                <?= $room['status'] === 'ended' ? '🎉 Exam Finished!' : '📊 Live Leaderboard' ?>
            </h1>
            <h4 class="text-muted"><?= htmlspecialchars($room['set_title']) ?></h4>
            
            <?php if (!$isHost && $room['status'] === 'ended'): ?>
                <div class="mt-4 p-4 rounded" style="background: rgba(255, 170, 0, 0.1); border: 1px solid rgba(255, 170, 0, 0.2); display: inline-block;">
                    <div style="font-size: 0.9rem; color: var(--cq-text-muted); text-transform: uppercase; letter-spacing: 2px;">Your Final Rank</div>
                    <div style="font-size: 3rem; font-weight: 800; color: var(--cq-warning); line-height: 1;">
                        #<?= $myRank ?: '-' ?>
                    </div>
                    <div style="font-size: 1.2rem; font-weight: 600; margin-top: 8px;">Score: <?= $myScore ?> pts</div>
                </div>
            <?php endif; ?>

            <?php if ($isHost): ?>
            <div class="mt-3">
                <button class="btn btn-cq-secondary" onclick="showExportModal()">
                    📥 ดาวน์โหลดผลลัพธ์ (CSV)
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="animate-fadeInUp" style="animation-delay: 0.1s;">
            <?php if (empty($leaderboard)): ?>
                <div class="text-center text-muted p-5">ไม่มีผู้เข้าร่วมในห้องนี้</div>
            <?php else: ?>
                <?php foreach ($leaderboard as $idx => $p): 
                    $rank = $idx + 1;
                    $cls = $rank <= 3 ? "rank-$rank" : "";
                    if ($p['user_id'] === $user['user_id']) $cls .= " border-primary shadow-glow";
                ?>
                    <div class="rank-card <?= $cls ?>">
                        <div class="rank-number"><?= $rank ?></div>
                        <div class="profile-avatar ms-3 me-3" style="width: 48px; height: 48px;">
                            <?php if (!empty($p['avatar_url'])): ?>
                                <img src="<?= htmlspecialchars($p['avatar_url']) ?>" alt="avatar">
                            <?php else: ?>
                                <?= getInitials($p['display_name'] ?? $p['username']) ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="fw-bold" style="font-size: 1.1rem;">
                                <?= htmlspecialchars($p['display_name'] ?? $p['username']) ?>
                                <?php if ($p['user_id'] === $user['user_id']): ?>
                                    <span class="badge bg-primary ms-2" style="font-size:0.7rem;">YOU</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--cq-text-muted);">
                                <?= $p['last_submit'] ? 'ล่าสุด: ' . date('H:i:s', strtotime($p['last_submit'])) : 'ยังไม่ส่งคำตอบ' ?>
                            </div>
                        </div>
                        <div class="rank-score"><?= intval($p['total_score']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="text-center mt-5">
            <a href="dashboard.php" class="btn btn-cq-outline">กลับหน้าหลัก</a>
        </div>
    </div>

    <?php if ($isHost): ?>
    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--cq-bg-surface); border: 1px solid var(--cq-border);">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title fw-bold">📥 ดาวน์โหลดผลลัพธ์</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body py-3">
            <p class="text-muted mb-3" style="font-size:0.9rem;">เลือกข้อมูลที่ต้องการส่งออก:</p>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="exp-name" checked><label class="form-check-label" for="exp-name">ชื่อผู้เข้าร่วม</label></div>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="exp-score" checked><label class="form-check-label" for="exp-score">คะแนน</label></div>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="exp-time" checked><label class="form-check-label" for="exp-time">เวลาที่ส่ง</label></div>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="exp-answers"><label class="form-check-label" for="exp-answers">คำตอบ (โค้ด)</label></div>
          </div>
          <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
            <button class="btn btn-cq-outline" data-bs-dismiss="modal">ยกเลิก</button>
            <button class="btn btn-cq-primary" onclick="downloadExport()">📥 ดาวน์โหลด CSV</button>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($room['status'] === 'ended' && ($myRank <= 3 && $myRank > 0)): ?>
    <script>
        // Simple confetti effect for top 3
        const colors = ['#00D4AA', '#7B61FF', '#FFD700', '#FF4444'];
        for (let i = 0; i < 50; i++) {
            const confetti = document.createElement('div');
            confetti.classList.add('confetti');
            confetti.style.left = Math.random() * 100 + 'vw';
            confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
            confetti.style.animationDuration = (Math.random() * 3 + 2) + 's';
            confetti.style.animationDelay = Math.random() * 2 + 's';
            document.body.appendChild(confetti);
        }
    </script>
    <?php endif; ?>

    <?php include 'includes/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if ($isHost): ?>
    <script>
        const roomId = <?= $roomId ?>;
        
        function showExportModal() {
            new bootstrap.Modal(document.getElementById('exportModal')).show();
        }

        function downloadExport() {
            const cols = [];
            if (document.getElementById('exp-name').checked) cols.push('name');
            if (document.getElementById('exp-score').checked) cols.push('score');
            if (document.getElementById('exp-time').checked) cols.push('time');
            if (document.getElementById('exp-answers').checked) cols.push('answers');
            
            if (cols.length === 0) return;
            
            bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
            
            const params = new URLSearchParams({ room_id: roomId, columns: cols.join(',') });
            window.location.href = 'api/export_api.php?' + params.toString();
        }
    </script>
    <?php endif; ?>
</body>
</html>
