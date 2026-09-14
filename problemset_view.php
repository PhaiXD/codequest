
<?php
require_once 'db.php';
requireLogin();

$user = getCurrentUser($pdo);
$theme = getUserTheme($pdo);
$setId = intval($_GET['id'] ?? 0);

if (!$setId) {
    header('Location: dashboard.php');
    exit;
}

// Fetch problem set details
if ($user['role'] === 'admin') {
    $stmt = $pdo->prepare("
        SELECT ps.*, u.username, u.display_name,
               (SELECT COUNT(*) FROM `cq_problems` WHERE `set_id` = ps.`set_id`) as problem_count
        FROM `cq_problem_sets` ps 
        JOIN `cq_users` u ON ps.`owner_id` = u.`user_id` 
        WHERE ps.`set_id` = ?
    ");
    $stmt->execute([$setId]);
} else {
    $stmt = $pdo->prepare("
        SELECT ps.*, u.username, u.display_name,
               (SELECT COUNT(*) FROM `cq_problems` WHERE `set_id` = ps.`set_id`) as problem_count
        FROM `cq_problem_sets` ps 
        JOIN `cq_users` u ON ps.`owner_id` = u.`user_id` 
        WHERE ps.`set_id` = ? AND (ps.`visibility` = 'public' OR ps.`owner_id` = ?)
    ");
    $stmt->execute([$setId, $user['user_id']]);
}
$set = $stmt->fetch();

if (!$set) {
    header('Location: dashboard.php');
    exit;
}

$isOwner = ($set['owner_id'] === $user['user_id'] || $user['role'] === 'admin');

// Fetch problems with stats
$pStmt = $pdo->prepare("
    SELECT p.*,
           (SELECT `status` FROM `cq_submissions` s WHERE s.`problem_id` = p.`problem_id` AND s.`user_id` = ? ORDER BY s.`submitted_at` DESC LIMIT 1) as my_status
    FROM `cq_problems` p 
    WHERE p.`set_id` = ? 
    ORDER BY p.`order_index`, p.`problem_id`
");
$pStmt->execute([$user['user_id'], $setId]);
$problems = $pStmt->fetchAll();

// Get recent rooms for this set (if owner)
$recentRooms = [];
if ($isOwner) {
    $rStmt = $pdo->prepare("SELECT * FROM `cq_rooms` WHERE `set_id` = ? ORDER BY `created_at` DESC LIMIT 5");
    $rStmt->execute([$setId]);
    $recentRooms = $rStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodeQuest - <?= htmlspecialchars($set['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/theme.js"></script>
    <style>
        .prob-row { padding: 16px; border-bottom: 1px solid var(--cq-border); transition: var(--cq-transition); display: flex; align-items: center; justify-content: space-between; }
        .prob-row:last-child { border-bottom: none; }
        .prob-row:hover { background: var(--cq-bg-hover); }
        .prob-info { display: flex; align-items: center; gap: 16px; }
        .prob-num { width: 32px; height: 32px; border-radius: 8px; background: var(--cq-bg-body); display: flex; align-items: center; justify-content: center; font-weight: bold; color: var(--cq-text-muted); }
        .status-icon { width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; }

        @media (max-width: 768px) {
            .prob-row { padding: 12px; }
            .prob-info { gap: 10px; }
            .prob-num { width: 28px; height: 28px; font-size: 0.85rem; }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="dashboard-container">
        <a href="dashboard.php" class="btn btn-sm btn-link px-0 mb-3" style="color:var(--cq-text-muted);text-decoration:none;">
            ← กลับ Dashboard
        </a>

        <div class="row g-4">
            <!-- Left Col (Details & Actions) -->
            <div class="col-lg-4">
                <div class="cq-card mb-4 animate-fadeInUp">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <span class="ps-badge <?= $set['visibility'] ?>"><?= $set['visibility'] === 'public' ? '🌐 Public' : '🔒 Private' ?></span>
                        <span class="badge bg-secondary"><?= htmlspecialchars(strtoupper($set['language'])) ?></span>
                    </div>
                    <h3 class="fw-bold mb-2"><?= htmlspecialchars($set['title']) ?></h3>
                    <div style="font-size:0.9rem;color:var(--cq-text-secondary);margin-bottom:1rem;">
                        สร้างโดย: <?= htmlspecialchars($set['display_name'] ?? $set['username']) ?>
                        <?php if ($set['forked_from']): ?>
                            <span class="ms-2 badge bg-dark text-light border border-secondary" title="Forked">🔀 Forked</span>
                        <?php endif; ?>
                    </div>
                    <p class="mb-4" style="font-size:0.95rem;color:var(--cq-text-muted);">
                        <?= nl2br(htmlspecialchars($set['description'] ?: 'ไม่มีคำอธิบาย')) ?>
                    </p>
                    
                    <div class="d-flex flex-column gap-2">
                        <?php if ($isOwner): ?>
                            <!-- Host Actions -->
                            <button class="btn btn-cq-primary w-100" onclick="hostRoom('realtime')">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                                สร้างห้องสอบ (Real-time)
                            </button>
                            <button class="btn btn-cq-secondary w-100" onclick="hostRoom('assign')">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                มอบหมายงาน (Assignment)
                            </button>
                            <a href="problemset_editor.php?id=<?= $setId ?>" class="btn btn-cq-outline w-100">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                แก้ไขชุดโจทย์
                            </a>
                        <?php else: ?>
                            <!-- Player Actions -->
                            <a href="practice.php?set_id=<?= $setId ?>" class="btn btn-cq-primary w-100">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                ฝึกทำโจทย์ชุดนี้ (Practice)
                            </a>
                            <button class="btn btn-cq-outline w-100" onclick="showForkModal()">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><circle cx="12" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><circle cx="18" cy="6" r="3"/><path d="M18 9v1a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V9"/><path d="M12 12v3"/></svg>
                                Fork ชุดโจทย์นี้
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($isOwner && !empty($recentRooms)): ?>
                <div class="cq-card animate-fadeInUp" style="animation-delay: 0.1s;">
                    <h6 class="fw-bold mb-3">ห้องที่เปิดล่าสุด</h6>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($recentRooms as $room): ?>
                        <div class="p-2 rounded" style="background:var(--cq-bg-body);border:1px solid var(--cq-border);">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="font-mono fw-bold" style="color:var(--cq-primary);"><?= htmlspecialchars($room['pin_code']) ?></span>
                                <?php
                                $sColor = $room['status'] === 'started' ? 'var(--cq-success)' : ($room['status'] === 'waiting' ? 'var(--cq-warning)' : 'var(--cq-text-muted)');
                                ?>
                                <span style="font-size:0.75rem;color:<?= $sColor ?>;">• <?= strtoupper($room['status']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span style="font-size:0.75rem;color:var(--cq-text-muted);">
                                    <?= $room['mode'] === 'assign' ? '📋' : '🔑' ?> <?= date('d/m/Y', strtotime($room['created_at'])) ?>
                                </span>
                                <a href="room_host.php?id=<?= $room['room_id'] ?>" style="font-size:0.8rem;text-decoration:none;">จัดการ →</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Col (Problems List) -->
            <div class="col-lg-8">
                <div class="cq-card p-0 animate-fadeInUp" style="animation-delay: 0.1s;">
                    <div class="p-3 border-bottom d-flex justify-content-between align-items-center" style="border-color:var(--cq-border) !important;">
                        <h5 class="fw-bold mb-0">รายการโจทย์ (<?= $set['problem_count'] ?> ข้อ)</h5>
                    </div>
                    
                    <div>
                        <?php if (empty($problems)): ?>
                            <div class="p-5 text-center text-muted">
                                ยังไม่มีโจทย์ในชุดนี้
                            </div>
                        <?php else: ?>
                            <?php foreach ($problems as $index => $prob): ?>
                            <div class="prob-row">
                                <div class="prob-info">
                                    <div class="prob-num"><?= $index + 1 ?></div>
                                    <div>
                                        <div class="fw-bold mb-1"><?= htmlspecialchars($prob['title']) ?></div>
                                        <div class="d-flex align-items-center gap-3" style="font-size:0.8rem;">
                                            <span style="color: <?= $prob['difficulty'] === 'Easy' ? 'var(--cq-success)' : ($prob['difficulty'] === 'Medium' ? 'var(--cq-warning)' : 'var(--cq-danger)') ?>">
                                                <?= $prob['difficulty'] ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-flex align-items-center gap-3">
                                    <?php if ($prob['my_status'] === 'accepted'): ?>
                                        <div class="status-icon" style="color:var(--cq-success);" title="ทำผ่านแล้ว">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        </div>
                                    <?php elseif ($prob['my_status']): ?>
                                        <div class="status-icon" style="color:var(--cq-warning);" title="เคยส่งแล้วแต่ยังไม่ผ่าน">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!$isOwner): ?>
                                    <a href="practice_solve.php?set_id=<?= $setId ?>&prob_id=<?= $prob['problem_id'] ?>" class="btn btn-sm btn-cq-outline">
                                        ทำข้อนี้
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Host Room Modal (Real-time) -->
    <div class="modal fade" id="hostModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background:var(--cq-bg-card);border:1px solid var(--cq-border);">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold" id="hostModalTitle">ตั้งค่าห้องสอบ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="hostForm">
                        <input type="hidden" id="roomMode" value="realtime">
                        <div class="mb-3">
                            <label class="form-label">กำหนดเวลา (นาที)</label>
                            <input type="number" class="form-control" id="roomDuration" value="60" min="0" max="1440" inputmode="numeric">
                            <div class="form-text">ใส่ 0 หากไม่จำกัดเวลา</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">แสดง Leaderboard ให้นักเรียนเห็น</label>
                            <select class="form-select" id="showLeaderboard">
                                <option value="1">แสดงตลอดเวลา</option>
                                <option value="0">ซ่อนไว้ (เห็นเฉพาะผู้สอน)</option>
                            </select>
                        </div>

                        <!-- Assignment-specific: Deadline -->
                        <div id="deadline-section" style="display:none;">
                            <div class="mb-3">
                                <label class="form-label">กำหนดส่ง (Deadline)</label>
                                <select class="form-select mb-2" id="deadlineType" onchange="toggleDeadlineInput()">
                                    <option value="none">ไม่มีกำหนดส่ง</option>
                                    <option value="custom">ตั้งค่ากำหนดส่ง</option>
                                </select>
                                <div id="deadlineInputGroup" style="display:none;">
                                    <input type="datetime-local" class="form-control" id="deadlineDatetime">
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-link text-muted" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="button" class="btn btn-cq-primary" id="submitHostBtn" onclick="submitHostRoom()">สร้างห้องสอบ</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Fork Confirm Modal -->
    <div class="modal fade" id="forkModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="background: var(--cq-bg-surface); border: 1px solid var(--cq-border);">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title fw-bold">🔀 Fork ชุดโจทย์</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-center py-3">
            <p style="color:var(--cq-text-secondary);">ต้องการคัดลอก (Fork) ชุดโจทย์นี้<br>ไปเป็นของคุณหรือไม่?</p>
          </div>
          <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
            <button class="btn btn-cq-outline" data-bs-dismiss="modal">ยกเลิก</button>
            <button class="btn btn-cq-primary" onclick="forkSet()">Fork เลย</button>
          </div>
        </div>
      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const setId = <?= $setId ?>;
        let hostModal;

        document.addEventListener('DOMContentLoaded', () => {
            hostModal = new bootstrap.Modal(document.getElementById('hostModal'));
        });

        function hostRoom(mode) {
            document.getElementById('roomMode').value = mode;
            const isAssign = mode === 'assign';
            document.getElementById('hostModalTitle').textContent = isAssign ? 'มอบหมายงาน (Assignment)' : 'สร้างห้องสอบ (Real-time)';
            document.getElementById('submitHostBtn').textContent = isAssign ? 'สร้าง Assignment' : 'สร้างห้องสอบ';
            document.getElementById('deadline-section').style.display = isAssign ? '' : 'none';
            hostModal.show();
        }

        function toggleDeadlineInput() {
            const type = document.getElementById('deadlineType').value;
            document.getElementById('deadlineInputGroup').style.display = type === 'custom' ? '' : 'none';
        }

        async function submitHostRoom() {
            const btn = document.getElementById('submitHostBtn');
            const mode = document.getElementById('roomMode').value;
            const duration = parseInt(document.getElementById('roomDuration').value) || 0;
            const showLeaderboard = document.getElementById('showLeaderboard').value === '1';

            // Build settings
            const settings = {
                duration_minutes: duration,
                show_leaderboard: showLeaderboard
            };

            // Assignment deadline
            if (mode === 'assign') {
                const deadlineType = document.getElementById('deadlineType').value;
                if (deadlineType === 'custom') {
                    const dt = document.getElementById('deadlineDatetime').value;
                    if (dt) settings.deadline = dt;
                }
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-cq" style="width:16px;height:16px;border-width:2px;"></span> กำลังสร้าง...';

            try {
                const res = await fetch('api/room_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create_room',
                        set_id: setId,
                        mode: mode,
                        settings: settings
                    })
                });
                const data = await res.json();
                
                if (data.success) {
                    window.location.href = 'room_host.php?id=' + data.room_id;
                } else {
                    alert(data.error || 'เกิดข้อผิดพลาด');
                    btn.disabled = false;
                    btn.innerHTML = mode === 'assign' ? 'สร้าง Assignment' : 'สร้างห้องสอบ';
                }
            } catch (err) {
                alert('Network error');
                btn.disabled = false;
                btn.innerHTML = mode === 'assign' ? 'สร้าง Assignment' : 'สร้างห้องสอบ';
            }
        }

        function showForkModal() {
            new bootstrap.Modal(document.getElementById('forkModal')).show();
        }

        async function forkSet() {
            const btn = document.querySelector('#forkModal .btn-cq-primary');
            const oldHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-cq" style="width:16px;height:16px;border-width:2px;"></span> Forking...';

            try {
                const res = await fetch('api/problemset_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'fork_set', set_id: setId })
                });
                const data = await res.json();
                
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('forkModal')).hide();
                    window.location.href = 'problemset_editor.php?id=' + data.set_id;
                } else {
                    alert(data.error || 'เกิดข้อผิดพลาดในการ Fork');
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
            } catch (err) {
                alert('Network error');
                btn.disabled = false;
                btn.innerHTML = oldHtml;
            }
        }
    </script>
</body>
</html>

