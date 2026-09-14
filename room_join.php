<?php
require_once 'db.php';
requireLogin();
$theme = getUserTheme($pdo);
$prefillPin = htmlspecialchars($_GET['pin'] ?? '');
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Room - CodeQuest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/theme.js"></script>
    <style>
        .pin-input { font-size: 2.5rem; text-align: center; letter-spacing: 0.5em; font-family: 'JetBrains Mono', monospace; font-weight: bold; border: 2px solid var(--cq-border); height: 80px; text-transform: uppercase; }
        .pin-input:focus { border-color: var(--cq-primary); box-shadow: 0 0 15px rgba(0,212,170,0.2); letter-spacing: 0.5em; }
        @media (max-width: 576px) {
            .pin-input { font-size: 1.8rem; height: 65px; letter-spacing: 0.3em; }
            .pin-input:focus { letter-spacing: 0.3em; }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="dashboard-container d-flex align-items-center justify-content-center" style="min-height: calc(100vh - 160px);">
        <div class="cq-card p-4 p-md-5 text-center animate-fadeInUp shadow-glow" style="max-width: 500px; width: 100%;">
            <div class="mb-4">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--cq-primary);">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
                </svg>
            </div>
            <h2 class="fw-bold mb-2">Join Room</h2>
            <p class="text-muted mb-4">กรอกรหัส PIN 6 หลัก จากผู้สอนเพื่อเข้าร่วมการสอบ</p>

            <form id="joinForm" onsubmit="event.preventDefault(); joinRoom();">
                <div class="mb-4">
                    <input type="text" class="form-control pin-input" id="pinCode" 
                           maxlength="6" placeholder="000000" autocomplete="off" required
                           inputmode="numeric" pattern="[0-9]*"
                           value="<?= $prefillPin ?>">
                </div>
                <button type="submit" class="btn btn-lg btn-cq-primary w-100" id="btnJoin">เข้าร่วมห้องสอบ</button>
            </form>
            
            <div id="errorMsg" class="alert alert-danger mt-3" style="display:none;font-size:0.9rem;"></div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const pinInput = document.getElementById('pinCode');
        
        // Only allow digits
        pinInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Auto-submit if PIN is pre-filled from URL
        if (pinInput.value.length === 6) {
            setTimeout(() => joinRoom(), 300);
        }

        async function joinRoom() {
            const pin = pinInput.value;
            if (pin.length !== 6) return;

            const btn = document.getElementById('btnJoin');
            const errorDiv = document.getElementById('errorMsg');
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-cq" style="width:20px;height:20px;"></span>';
            errorDiv.style.display = 'none';

            try {
                const res = await fetch('api/room_api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'join_room', pin_code: pin })
                });
                const data = await res.json();
                
                if (data.success) {
                    if (data.is_host) {
                        window.location.href = 'room_host.php?id=' + data.room_id;
                    } else if (data.redirect === 'exam') {
                        window.location.href = 'exam.php?id=' + data.room_id;
                    } else {
                        window.location.href = 'room_student.php?id=' + data.room_id;
                    }
                } else {
                    errorDiv.textContent = data.error || 'ไม่สามารถเข้าร่วมห้องได้';
                    errorDiv.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = 'เข้าร่วมห้องสอบ';
                }
            } catch (e) {
                errorDiv.textContent = 'Network error';
                errorDiv.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = 'เข้าร่วมห้องสอบ';
            }
        }
    </script>
</body>
</html>
