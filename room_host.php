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

// Get room and set details
$stmt = $pdo->prepare("
    SELECT r.*, ps.title as set_title, ps.language,
           (SELECT COUNT(*) FROM `cq_problems` WHERE `set_id` = ps.`set_id`) as problem_count
    FROM `cq_rooms` r
    JOIN `cq_problem_sets` ps ON r.`set_id` = ps.`set_id`
    WHERE r.`room_id` = ? AND r.`host_id` = ?
");
$stmt->execute([$roomId, $user['user_id']]);
$room = $stmt->fetch();

if (!$room) {
    header('Location: dashboard.php');
    exit;
}

// Get current participants
$pStmt = $pdo->prepare("
    SELECT p.*, u.username, u.display_name, u.avatar_url,
           (SELECT SUM(score) FROM `cq_submissions` s WHERE s.`user_id` = p.`user_id` AND s.`room_id` = ?) as total_score
    FROM `cq_participants` p
    JOIN `cq_users` u ON p.`user_id` = u.`user_id`
    WHERE p.`room_id` = ?
");
$pStmt->execute([$roomId, $roomId]);
$participants = $pStmt->fetchAll();

$settings = json_decode($room['settings'], true) ?: [];
$duration = intval($settings['duration_minutes'] ?? 0);
$isAssignment = ($room['mode'] === 'assign');
$joinUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/ac/room_join.php?pin=' . urlencode($room['pin_code']);
$shareLink = $room['share_link'] ? ('https://' . $_SERVER['HTTP_HOST'] . '/ac/join/' . $room['share_link']) : null;

// Get max event ID to prevent replaying old events
$evStmt = $pdo->prepare("SELECT MAX(event_id) FROM `cq_room_events` WHERE `room_id` = ?");
$evStmt->execute([$roomId]);
$startEventId = intval($evStmt->fetchColumn() ?: 0);
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host Room: <?= htmlspecialchars($room['pin_code']) ?> - CodeQuest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/theme.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        .pin-display { font-size: 3.5rem; font-family: 'JetBrains Mono', monospace; font-weight: 800; letter-spacing: 0.2em; color: var(--cq-primary); text-align: center; text-shadow: 0 0 20px rgba(0, 212, 170, 0.3); margin: 0.75rem 0; }
        .participant-card { background: var(--cq-bg-card); border: 1px solid var(--cq-border); border-radius: var(--cq-radius-md); padding: 12px; display: flex; align-items: center; justify-content: space-between; animation: scaleIn 0.3s ease; }
        .participant-card:hover { border-color: var(--cq-primary); }
        .grid-participants { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem; }
        .share-box { background: var(--cq-bg-body); border: 1px solid var(--cq-border); border-radius: var(--cq-radius-md); padding: 10px 14px; display: flex; align-items: center; gap: 8px; font-family: 'JetBrains Mono', monospace; font-size: 0.8rem; word-break: break-all; }
        .share-box .copy-btn { flex-shrink: 0; padding: 4px 10px; border: 1px solid var(--cq-border); border-radius: 6px; background: var(--cq-bg-card); color: var(--cq-text-secondary); cursor: pointer; font-size: 0.75rem; transition: all 0.2s; }
        .share-box .copy-btn:hover { border-color: var(--cq-primary); color: var(--cq-primary); }
        .qr-container { background: #fff; padding: 12px; border-radius: 12px; display: inline-block; }
        
        @media (max-width: 768px) {
            .pin-display { font-size: 2.5rem; letter-spacing: 0.15em; }
            .grid-participants { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="dashboard-container">
        <!-- Room Header -->
        <div class="cq-card mb-4">
            <div class="row align-items-center">
                <div class="col-md-7">
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <span class="badge bg-secondary"><?= htmlspecialchars(strtoupper($room['language'])) ?></span>
                        <span class="ps-badge <?= $isAssignment ? 'private' : 'public' ?>">
                            <?= $isAssignment ? 'Assignment' : 'Real-time' ?>
                        </span>
                        <span class="ps-badge" id="status-badge" style="background:var(--cq-border);color:var(--cq-text-primary);">
                            <?= strtoupper($room['status']) ?>
                        </span>
                    </div>
                    <h3 class="fw-bold mb-1"><?= htmlspecialchars($room['set_title']) ?></h3>
                    <div style="font-size:0.9rem;color:var(--cq-text-muted);">
                        <?= $room['problem_count'] ?> ข้อ • <?= $duration > 0 ? $duration . ' นาที' : 'ไม่จำกัดเวลา' ?>
                        <?php if ($isAssignment && $room['deadline']): ?>
                            • กำหนดส่ง: <?= date('d/m/Y H:i', strtotime($room['deadline'])) ?>
                        <?php elseif ($isAssignment): ?>
                            • ไม่มีกำหนดส่ง
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex flex-column gap-2">
                    <?php if ($room['status'] === 'waiting' && !$isAssignment): ?>
                        <button class="btn btn-lg btn-cq-primary w-100 shadow-glow" id="btn-start" onclick="showConfirmStart()">
                            ▶ เริ่มการสอบ
                        </button>
                    <?php elseif ($room['status'] === 'started'): ?>
                        <button class="btn btn-lg btn-cq-danger w-100" id="btn-end" onclick="showConfirmEnd()">
                            ⏹ ยุติการสอบ
                        </button>
                        <button class="btn btn-cq-outline w-100" onclick="showExportModal()">
                            📊 ดาวน์โหลดผลลัพธ์
                        </button>
                    <?php elseif ($room['status'] === 'ended'): ?>
                        <a href="exam_result.php?id=<?= $roomId ?>" class="btn btn-cq-outline w-100">📊 ดูผลสอบ</a>
                        <button class="btn btn-cq-secondary w-100" onclick="showExportModal()">
                            📥 ดาวน์โหลดผลลัพธ์
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- PIN Code / Share Section -->
        <?php if ($room['status'] !== 'ended'): ?>
        <div class="cq-card mb-4 animate-fadeInUp">
            <div class="row align-items-center">
                <div class="col-md-7 text-center text-md-start">
                    <h5 class="fw-bold text-muted mb-0">รหัสเข้าร่วม (PIN)</h5>
                    <div class="pin-display"><?= htmlspecialchars($room['pin_code']) ?></div>
                    
                    <!-- Share Link -->
                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.8rem;color:var(--cq-text-muted);">ลิงก์สำหรับแชร์:</label>
                        <div class="share-box">
                            <span class="flex-fill" id="share-url"><?= htmlspecialchars($joinUrl) ?></span>
                            <button class="copy-btn" onclick="copyToClipboard('share-url', this)">📋 Copy</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-5 text-center">
                    <div class="mb-2" style="font-size:0.8rem;color:var(--cq-text-muted);">สแกน QR Code</div>
                    <div class="qr-container" id="qrcode"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Participants List / Leaderboard -->
        <div class="d-flex justify-content-between align-items-end mb-3 flex-wrap gap-2">
            <h5 class="fw-bold mb-0">
                <span id="tab-title"><?= $room['status'] === 'waiting' ? 'ผู้เข้าร่วม' : 'Leaderboard' ?></span>
                (<span id="participant-count"><?= count($participants) ?></span>)
            </h5>
        </div>

        <div id="participants-container" class="grid-participants">
            <?php foreach ($participants as $p): ?>
                <div class="participant-card" id="participant-<?= $p['user_id'] ?>">
                    <div class="d-flex align-items-center gap-2">
                        <div class="profile-avatar">
                            <?php if (!empty($p['avatar_url'])): ?>
                                <img src="<?= htmlspecialchars($p['avatar_url']) ?>" alt="avatar">
                            <?php else: ?>
                                <?= getInitials($p['display_name'] ?? $p['username']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="fw-semibold text-truncate" style="max-width:120px;" title="<?= htmlspecialchars($p['display_name'] ?? $p['username']) ?>">
                            <?= htmlspecialchars($p['display_name'] ?? $p['username']) ?>
                        </div>
                    </div>
                    <?php if ($room['status'] === 'waiting'): ?>
                        <button class="btn btn-sm btn-link text-danger p-0" onclick="showKickConfirm(<?= json_encode($p['user_id']) ?>, '<?= htmlspecialchars($p['display_name'] ?? $p['username']) ?>')" title="เตะออก">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    <?php else: ?>
                        <div class="fw-bold score-value" style="color:var(--cq-warning);"><?= intval($p['total_score']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div id="empty-state" class="empty-state" style="<?= count($participants) > 0 ? 'display:none;' : '' ?>">
            <div class="empty-icon">👥</div>
            <h3>ยังไม่มีผู้เข้าร่วม</h3>
            <p>รอนักเรียนกรอกรหัส PIN หรือสแกน QR Code เพื่อเข้าร่วมห้อง</p>
        </div>
    </div>

    <!-- Confirm Start Modal -->
    <div class="modal fade" id="confirmStartModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="background: var(--cq-bg-surface); border: 1px solid var(--cq-border);">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title fw-bold" style="color:var(--cq-primary);">▶ เริ่มการสอบ</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-center py-3">
            <p style="color:var(--cq-text-secondary);">เมื่อเริ่มแล้วนักเรียนจะเข้าสู่หน้าทำข้อสอบทันที<br>ต้องการเริ่มใช่หรือไม่?</p>
          </div>
          <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
            <button class="btn btn-cq-outline" data-bs-dismiss="modal">ยกเลิก</button>
            <button class="btn btn-cq-primary" onclick="changeStatus('started')">เริ่มเลย</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirm End Modal -->
    <div class="modal fade" id="confirmEndModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="background: var(--cq-bg-surface); border: 1px solid var(--cq-border);">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title fw-bold" style="color:var(--cq-danger);">⏹ ยุติการสอบ</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-center py-3">
            <p style="color:var(--cq-text-secondary);">ต้องการยุติการสอบใช่หรือไม่?<br>นักเรียนจะไม่สามารถส่งคำตอบได้อีก</p>
          </div>
          <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
            <button class="btn btn-cq-outline" data-bs-dismiss="modal">ยกเลิก</button>
            <button class="btn btn-cq-danger" onclick="changeStatus('ended')">ยุติ</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirm Kick Modal -->
    <div class="modal fade" id="confirmKickModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="background: var(--cq-bg-surface); border: 1px solid var(--cq-border);">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title fw-bold" style="color:var(--cq-danger);">เตะออกจากห้อง</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-center py-3">
            <p style="color:var(--cq-text-secondary);">ต้องการเตะ <strong id="kickUserName"></strong> ออกจากห้องหรือไม่?</p>
          </div>
          <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
            <button class="btn btn-cq-outline" data-bs-dismiss="modal">ยกเลิก</button>
            <button class="btn btn-cq-danger" id="confirmKickBtn">เตะออก</button>
          </div>
        </div>
      </div>
    </div>

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

    <?php include 'includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const roomId = <?= $roomId ?>;
        const pinCode = '<?= $room['pin_code'] ?>';
        const joinUrl = '<?= $joinUrl ?>';

        // Generate QR Code
        document.addEventListener('DOMContentLoaded', () => {
            const qrEl = document.getElementById('qrcode');
            if (qrEl && typeof QRCode !== 'undefined') {
                new QRCode(qrEl, {
                    text: joinUrl,
                    width: 140,
                    height: 140,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        });

        function copyToClipboard(elId, btn) {
            const text = document.getElementById(elId).textContent.trim();
            navigator.clipboard.writeText(text).then(() => {
                const old = btn.innerHTML;
                btn.innerHTML = '✅ Copied!';
                btn.style.color = 'var(--cq-success)';
                setTimeout(() => { btn.innerHTML = old; btn.style.color = ''; }, 1500);
            });
        }

        // =============================================
        // WebSocket Real-Time Connection
        // =============================================
        const ROOM_WS_URL = '<?= str_replace("https://", "wss://", GRADER_URL) ?>/ws/room?id=<?= $roomId ?>&key=<?= GRADER_SECRET ?>&role=host&userId=<?= $user['user_id'] ?>';
        let roomWs = null;
        let wsReconnectTimer = null;

        function connectRoomWs() {
            if (roomWs && roomWs.readyState === WebSocket.OPEN) return;

            roomWs = new WebSocket(ROOM_WS_URL);

            roomWs.onopen = () => {
                console.log('[Room WS] Connected');
                // Keep alive ping every 25 seconds
                if (wsReconnectTimer) clearInterval(wsReconnectTimer);
                wsReconnectTimer = setInterval(() => {
                    if (roomWs && roomWs.readyState === WebSocket.OPEN) {
                        roomWs.send(JSON.stringify({ type: 'ping' }));
                    }
                }, 25000);
            };

            roomWs.onmessage = (event) => {
                try {
                    const msg = JSON.parse(event.data);
                    if (msg.type !== 'room_event') return;

                    const { event: eventType, data } = msg;

                    switch (eventType) {
                        case 'participant_joined':
                            addParticipant(data);
                            break;
                        case 'participant_kicked':
                            removeParticipant(data.user_id);
                            break;
                        case 'submission_update':
                            updateScore(data.user_id, data.new_total_score);
                            break;
                        case 'room_started':
                            window.location.reload();
                            break;
                        case 'room_ended':
                            window.location.reload();
                            break;
                    }
                } catch (e) {
                    console.error('[Room WS] Parse error:', e);
                }
            };

            roomWs.onerror = (err) => {
                console.error('[Room WS] Error');
            };

            roomWs.onclose = () => {
                console.log('[Room WS] Disconnected, reconnecting in 2s...');
                if (wsReconnectTimer) clearInterval(wsReconnectTimer);
                setTimeout(connectRoomWs, 2000);
            };
        }

        connectRoomWs();

        function addParticipant(user) {
            const container = document.getElementById('participants-container');
            document.getElementById('empty-state').style.display = 'none';
            
            if (document.getElementById(`participant-${user.user_id}`)) return;
            
            const initials = user.display_name ? user.display_name.charAt(0).toUpperCase() : user.username.charAt(0).toUpperCase();
            const avatarHtml = user.avatar_url 
                ? `<img src="${user.avatar_url}" alt="avatar">` 
                : `${initials}`;

            const isWaiting = document.getElementById('status-badge').textContent.trim() === 'WAITING';
            const actionHtml = isWaiting 
                ? `<button class="btn btn-sm btn-link text-danger p-0" onclick="showKickConfirm('${user.user_id}', '${(user.display_name || user.username).replace(/'/g, "\\'")}')" title="เตะออก"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>`
                : `<div class="fw-bold score-value" style="color:var(--cq-warning);">0</div>`;

            const html = `
                <div class="participant-card" id="participant-${user.user_id}">
                    <div class="d-flex align-items-center gap-2">
                        <div class="profile-avatar">${avatarHtml}</div>
                        <div class="fw-semibold text-truncate" style="max-width:120px;" title="${user.display_name || user.username}">${user.display_name || user.username}</div>
                    </div>
                    ${actionHtml}
                </div>
            `;
            container.insertAdjacentHTML('beforeend', html);
            updateCount();
        }

        function removeParticipant(userId) {
            const el = document.getElementById(`participant-${userId}`);
            if (el) {
                el.style.transform = 'scale(0)';
                el.style.opacity = '0';
                setTimeout(() => {
                    el.remove();
                    updateCount();
                }, 300);
            }
        }

        function updateScore(userId, newScore) {
            const el = document.getElementById(`participant-${userId}`);
            if (el) {
                const scoreEl = el.querySelector('.score-value');
                if (scoreEl) scoreEl.textContent = newScore;
            }
        }

        function updateCount() {
            const count = document.querySelectorAll('.participant-card').length;
            document.getElementById('participant-count').textContent = count;
            if (count === 0) document.getElementById('empty-state').style.display = 'block';
        }

        // Modal helpers
        function showConfirmStart() {
            new bootstrap.Modal(document.getElementById('confirmStartModal')).show();
        }
        function showConfirmEnd() {
            new bootstrap.Modal(document.getElementById('confirmEndModal')).show();
        }

        let pendingKickUserId = null;
        function showKickConfirm(userId, name) {
            pendingKickUserId = userId;
            document.getElementById('kickUserName').textContent = name;
            new bootstrap.Modal(document.getElementById('confirmKickModal')).show();
        }
        document.getElementById('confirmKickBtn').addEventListener('click', function() {
            if (pendingKickUserId) kickParticipant(pendingKickUserId);
            bootstrap.Modal.getInstance(document.getElementById('confirmKickModal')).hide();
        });

        async function kickParticipant(userId) {
            try {
                const res = await fetch('api/room_api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'kick_participant', room_id: roomId, target_user_id: userId })
                });
                const data = await res.json();
                if (!data.success) {
                    alert(data.error || 'Kick failed');
                } else {
                    window.location.reload();
                }
            } catch (e) {
                alert('Network error');
            }
        }

        async function changeStatus(status) {
            // Close modals
            document.querySelectorAll('.modal.show').forEach(m => {
                const inst = bootstrap.Modal.getInstance(m);
                if (inst) inst.hide();
            });
            
            try {
                const res = await fetch('api/room_api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'update_status', room_id: roomId, status: status })
                });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.error || 'Update failed');
                }
            } catch (e) {
                alert('Network error');
            }
        }

        function showExportModal() {
            new bootstrap.Modal(document.getElementById('exportModal')).show();
        }

        async function downloadExport() {
            const cols = [];
            if (document.getElementById('exp-name').checked) cols.push('name');
            if (document.getElementById('exp-score').checked) cols.push('score');
            if (document.getElementById('exp-time').checked) cols.push('time');
            if (document.getElementById('exp-answers').checked) cols.push('answers');
            
            if (cols.length === 0) return;
            
            bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
            
            // Redirect to download
            const params = new URLSearchParams({ room_id: roomId, columns: cols.join(',') });
            window.location.href = 'api/export_api.php?' + params.toString();
        }
    </script>
</body>
</html>
