<?php
require_once 'db.php';
requireLogin();

$user = getCurrentUser($pdo);
$theme = getUserTheme($pdo);
$roomId = intval($_GET['id'] ?? 0);

if (!$roomId) {
    header('Location: room_join.php');
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
    header('Location: room_join.php');
    exit;
}

// Verify participant is in room
$chk = $pdo->prepare("SELECT * FROM `cq_participants` WHERE `room_id` = ? AND `user_id` = ?");
$chk->execute([$roomId, $user['user_id']]);
if (!$chk->fetch()) {
    header('Location: room_join.php');
    exit;
}

// Get current participants
$pStmt = $pdo->prepare("
    SELECT p.user_id, u.username, u.display_name, u.avatar_url
    FROM `cq_participants` p
    JOIN `cq_users` u ON p.`user_id` = u.`user_id`
    WHERE p.`room_id` = ?
");
$pStmt->execute([$roomId]);
$participants = $pStmt->fetchAll();

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
    <title>Room: <?= htmlspecialchars($room['pin_code']) ?> - CodeQuest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/theme.js"></script>
    <style>
        .waiting-animation { font-size: 4rem; margin-bottom: 1.5rem; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.1); opacity: 0.7; } 100% { transform: scale(1); opacity: 1; } }
        .participant-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 0.75rem; max-width: 600px; margin: 0 auto; }
        .participant-chip { background: var(--cq-bg-card); border: 1px solid var(--cq-border); border-radius: var(--cq-radius-md); padding: 10px 14px; display: flex; align-items: center; gap: 10px; animation: scaleIn 0.3s ease; font-size: 0.9rem; }
        .participant-chip .p-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--cq-primary-light); color: var(--cq-primary); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; flex-shrink: 0; overflow: hidden; }
        .participant-chip .p-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .participant-chip.is-me { border-color: var(--cq-primary); box-shadow: 0 0 8px rgba(0, 212, 170, 0.15); }
        @keyframes scaleIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .pulse-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--cq-success); animation: pulseDot 1.5s infinite; display: inline-block; }
        @keyframes pulseDot { 0% { box-shadow: 0 0 0 0 rgba(0,212,170,0.5); } 70% { box-shadow: 0 0 0 8px rgba(0,212,170,0); } 100% { box-shadow: 0 0 0 0 rgba(0,212,170,0); } }

        @media (max-width: 576px) {
            .participant-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
            .waiting-animation { font-size: 3rem; }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <!-- Waiting Screen -->
    <div id="screen-waiting" class="dashboard-container d-flex flex-column align-items-center justify-content-center text-center" style="min-height:calc(100vh - 160px); <?= $room['status'] === 'waiting' ? '' : 'display:none !important;' ?>">
        <div class="waiting-animation">⏳</div>
        <h2 class="fw-bold mb-2">คุณเข้ามาในห้องแล้ว</h2>
        <h4 style="color:var(--cq-primary);"><?= htmlspecialchars($room['set_title']) ?></h4>
        <p class="text-muted mt-2 mb-1">ผู้สอน: <?= htmlspecialchars($room['host_name']) ?></p>
        
        <div class="d-flex align-items-center gap-2 mt-3 mb-4">
            <span class="pulse-dot"></span>
            <span class="text-muted" style="font-size:0.9rem;">กรุณารอสักครู่... ผู้สอนกำลังจะเริ่มการสอบ</span>
        </div>

        <!-- Participants List -->
        <div class="w-100" style="max-width: 650px;">
            <h6 class="fw-bold mb-3" style="color:var(--cq-text-muted);">
                ผู้เข้าร่วม (<span id="participant-count"><?= count($participants) ?></span> คน)
            </h6>
            <div class="participant-grid" id="participants-container">
                <?php foreach ($participants as $p): ?>
                <div class="participant-chip <?= $p['user_id'] === $user['user_id'] ? 'is-me' : '' ?>" id="student-<?= $p['user_id'] ?>">
                    <div class="p-avatar">
                        <?php if (!empty($p['avatar_url'])): ?>
                            <img src="<?= htmlspecialchars($p['avatar_url']) ?>" alt="">
                        <?php else: ?>
                            <?= strtoupper(substr($p['display_name'] ?? $p['username'], 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <span class="text-truncate" style="max-width:100px;" title="<?= htmlspecialchars($p['display_name'] ?? $p['username']) ?>">
                        <?= htmlspecialchars($p['display_name'] ?? $p['username']) ?>
                        <?php if ($p['user_id'] === $user['user_id']): ?>
                            <span class="badge bg-primary" style="font-size:0.6rem;vertical-align:middle;">YOU</span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Active/Ended Placeholder (Will redirect to exam UI) -->
    <div id="screen-active" class="dashboard-container text-center" style="margin-top:100px; <?= $room['status'] !== 'waiting' ? '' : 'display:none !important;' ?>">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="mt-3">กำลังโหลดข้อสอบ...</p>
    </div>

    <?php include 'includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const roomId = <?= $roomId ?>;
        const currentStatus = '<?= $room['status'] ?>';
        const myUserId = '<?= $user['user_id'] ?>';
        
        if (currentStatus === 'started') {
            window.location.href = 'exam.php?id=' + roomId;
        } else if (currentStatus === 'ended') {
            window.location.href = 'exam_result.php?id=' + roomId;
        }

        // =============================================
        // WebSocket Real-Time Connection
        // =============================================
        const ROOM_WS_URL = '<?= str_replace("https://", "wss://", GRADER_URL) ?>/ws/room?id=<?= $roomId ?>&key=<?= GRADER_SECRET ?>&role=student&userId=<?= $user['user_id'] ?>';
        let roomWs = null;
        let wsReconnectTimer = null;

        function connectRoomWs() {
            if (roomWs && roomWs.readyState === WebSocket.OPEN) return;

            roomWs = new WebSocket(ROOM_WS_URL);

            roomWs.onopen = () => {
                console.log('[Room WS] Connected');
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
                        case 'room_started':
                            window.location.href = 'exam.php?id=' + roomId;
                            break;
                        case 'room_ended':
                            window.location.href = 'exam_result.php?id=' + roomId;
                            break;
                        case 'participant_joined':
                            addParticipant(data);
                            break;
                        case 'participant_kicked':
                            if (String(data.user_id) === String(myUserId)) {
                                alert('คุณถูกเชิญออกจากห้อง');
                                window.location.href = 'dashboard.php';
                            } else {
                                removeParticipant(data.user_id);
                            }
                            break;
                    }
                } catch (e) {
                    console.error('[Room WS] Parse error:', e);
                }
            };

            roomWs.onerror = () => {
                console.error('[Room WS] Error');
            };

            roomWs.onclose = () => {
                console.log('[Room WS] Disconnected, reconnecting in 2s...');
                if (wsReconnectTimer) clearInterval(wsReconnectTimer);
                setTimeout(connectRoomWs, 2000);
            };
        }

        function addParticipant(user) {
            const container = document.getElementById('participants-container');
            if (document.getElementById(`student-${user.user_id}`)) return;
            
            const initial = (user.display_name || user.username || '?').charAt(0).toUpperCase();
            const avatarHtml = user.avatar_url 
                ? `<img src="${user.avatar_url}" alt="">` 
                : initial;
            const isMe = String(user.user_id) === String(myUserId);

            const html = `
                <div class="participant-chip ${isMe ? 'is-me' : ''}" id="student-${user.user_id}">
                    <div class="p-avatar">${avatarHtml}</div>
                    <span class="text-truncate" style="max-width:100px;" title="${user.display_name || user.username}">
                        ${user.display_name || user.username}
                        ${isMe ? '<span class="badge bg-primary" style="font-size:0.6rem;vertical-align:middle;">YOU</span>' : ''}
                    </span>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', html);
            updateCount();
        }

        function removeParticipant(userId) {
            const el = document.getElementById(`student-${userId}`);
            if (el) {
                el.style.transform = 'scale(0)';
                el.style.opacity = '0';
                setTimeout(() => { el.remove(); updateCount(); }, 300);
            }
        }

        function updateCount() {
            const count = document.querySelectorAll('.participant-chip').length;
            document.getElementById('participant-count').textContent = count;
        }

        if (currentStatus === 'waiting') {
            connectRoomWs();
        }
    </script>
</body>
</html>
