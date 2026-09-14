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
    SELECT r.*, ps.title as set_title, ps.language, ps.description as set_desc
    FROM `cq_rooms` r
    JOIN `cq_problem_sets` ps ON r.`set_id` = ps.`set_id`
    WHERE r.`room_id` = ?
");
$stmt->execute([$roomId]);
$room = $stmt->fetch();

if (!$room) {
    header('Location: dashboard.php');
    exit;
}

if ($room['status'] === 'ended') {
    header('Location: exam_result.php?id=' . $roomId);
    exit;
}

if ($room['status'] === 'waiting') {
    header('Location: room_student.php?id=' . $roomId);
    exit;
}

// Verify participant
$chk = $pdo->prepare("SELECT * FROM `cq_participants` WHERE `room_id` = ? AND `user_id` = ?");
$chk->execute([$roomId, $user['user_id']]);
if (!$chk->fetch() && $room['host_id'] !== $user['user_id'] && $user['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

// Get all problems in the set
$pStmt = $pdo->prepare("
    SELECT p.*,
           (SELECT `status` FROM `cq_submissions` s WHERE s.`problem_id` = p.`problem_id` AND s.`user_id` = ? AND s.`room_id` = ? ORDER BY s.`submitted_at` DESC LIMIT 1) as my_status
    FROM `cq_problems` p 
    WHERE p.`set_id` = ? 
    ORDER BY p.`order_index`
");
$pStmt->execute([$user['user_id'], $roomId, $room['set_id']]);
$problems = $pStmt->fetchAll();

// Select active problem (default to first)
$activeProbId = intval($_GET['prob'] ?? ($problems[0]['problem_id'] ?? 0));
$activeProblem = null;
foreach ($problems as $p) {
    if ($p['problem_id'] == $activeProbId) {
        $activeProblem = $p;
        break;
    }
}

if (!$activeProblem && count($problems) > 0) {
    $activeProblem = $problems[0];
    $activeProbId = $activeProblem['problem_id'];
}

// Get sample test cases for active problem
$sampleTestcases = [];
if ($activeProblem) {
    $tcStmt = $pdo->prepare("SELECT * FROM `cq_testcases` WHERE `problem_id` = ? AND `is_sample` = 1 ORDER BY `order_index`");
    $tcStmt->execute([$activeProbId]);
    $sampleTestcases = $tcStmt->fetchAll();
}

// Get time remaining
$endTime = strtotime($room['ended_at']);
$timeRemaining = $endTime - time();
if ($timeRemaining < 0) $timeRemaining = 0;
if (!$room['ended_at']) $timeRemaining = -1; // Unlimited

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
    <title>Exam: <?= htmlspecialchars($room['set_title']) ?> - CodeQuest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/theme/material-ocean.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/theme/eclipse.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/edit/closebrackets.min.js"></script>
    <script src="js/theme.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            setTimeout(function() {
                document.body.classList.remove('preload');
            }, 100);
        });
    </script>
    <style>
        .preload * {
            -webkit-transition: none !important;
            -moz-transition: none !important;
            -ms-transition: none !important;
            -o-transition: none !important;
            transition: none !important;
        }
        body { display: flex; flex-direction: column; height: 100vh; overflow: hidden; background: var(--cq-bg-body); }
        .exam-header { padding: 10px 20px; background: var(--cq-bg-card); border-bottom: 1px solid var(--cq-border); display: flex; justify-content: space-between; align-items: center; }
        .exam-timer { font-family: 'JetBrains Mono', monospace; font-size: 1.25rem; font-weight: 700; color: var(--cq-warning); background: rgba(255, 170, 0, 0.1); padding: 4px 12px; border-radius: 6px; border: 1px solid rgba(255, 170, 0, 0.2); }
        .exam-timer.danger { color: var(--cq-danger); background: rgba(255, 68, 68, 0.1); border-color: rgba(255, 68, 68, 0.2); animation: pulse 1s infinite; }
        
        .exam-layout { flex: 1; display: flex; overflow: hidden; }
        
        .prob-nav { width: 60px; background: var(--cq-bg-card); border-right: 1px solid var(--cq-border); display: flex; flex-direction: column; padding: 16px 10px; gap: 12px; overflow-y: auto; z-index: 10; -ms-overflow-style: none; scrollbar-width: none; }
        .prob-nav::-webkit-scrollbar { display: none; }
        .prob-btn { width: 40px; height: 40px; border-radius: 8px; border: 1px solid var(--cq-border); background: var(--cq-bg-body); color: var(--cq-text-primary); font-weight: bold; display: flex; align-items: center; justify-content: center; text-decoration: none; transition: background-color 0.2s, border-color 0.2s; position: relative; flex-shrink: 0; padding: 0; }
        .prob-number { text-align: center; }
        .prob-btn:hover { border-color: var(--cq-primary); }
        .prob-btn.active { border-color: var(--cq-primary); background: var(--cq-primary-light); color: var(--cq-primary); }
        .prob-btn.accepted { border-color: var(--cq-success); background: rgba(0, 212, 170, 0.1); color: var(--cq-success); }
        .prob-btn.wrong_answer, .prob-btn.error { border-color: var(--cq-warning); background: rgba(255, 170, 0, 0.1); color: var(--cq-warning); }
        
        .panel-left { width: 35%; border-right: 1px solid var(--cq-border); display: flex; flex-direction: column; overflow-y: auto; background: var(--cq-bg-body); }
        .panel-right { width: 65%; display: flex; flex-direction: column; }
        
        .editor-container { flex: 1; overflow: hidden; }
        .CodeMirror { height: 100%; font-family: 'JetBrains Mono', monospace; font-size: 14px; }
        
        .console-container { height: 35%; border-top: 1px solid var(--cq-border); background: var(--cq-bg-card); display: flex; flex-direction: column; }
        .console-header { padding: 8px 16px; border-bottom: 1px solid var(--cq-border); font-size: 0.85rem; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
        .console-body { flex: 1; overflow-y: auto; padding: 12px 16px; font-family: 'JetBrains Mono', monospace; font-size: 0.85rem; }
        
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }
        
        @media (max-width: 768px) {
            body { overflow: auto; height: auto; }
            .exam-layout { flex-direction: column; height: auto; overflow: visible; }
            .prob-nav { width: 100%; height: 60px; flex-direction: row; border-right: none; border-bottom: 1px solid var(--cq-border); padding: 0 16px; overflow-x: auto; gap: 8px; justify-content: flex-start; align-items: center; }
            .panel-left { width: 100%; height: auto; border-right: none; border-bottom: 2px solid var(--cq-border); }
            .panel-right { width: 100%; height: 85vh; min-height: 500px; border-right: none; display: flex; flex-direction: column; }
        }
        @keyframes blink { 50% { opacity: 0; } }
    </style>
</head>
<body class="preload">
    <!-- Navbar -->
    <div class="exam-header">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-sm btn-outline-danger" onclick="if(confirm('คุณต้องการออกจากการสอบใช่หรือไม่? (โค้ดจะถูกบันทึกอัตโนมัติ)')) window.location.href='dashboard.php';" title="ออกจากการสอบ">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg> ออก
            </button>
            <span class="badge bg-secondary"><?= htmlspecialchars(strtoupper($room['language'])) ?></span>
            <h5 class="mb-0 fw-bold d-none d-md-block"><?= htmlspecialchars($room['set_title']) ?></h5>
        </div>
        
        <?php if ($timeRemaining >= 0): ?>
            <div class="exam-timer" id="timer">00:00:00</div>
        <?php else: ?>
            <div class="badge bg-dark border">ไม่จำกัดเวลา</div>
        <?php endif; ?>
        
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-sm btn-cq-secondary" id="btn-run" onclick="runCode()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> Run
            </button>
            <button class="btn btn-sm btn-cq-primary" id="btn-submit" onclick="submitCode()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Submit
            </button>
        </div>
    </div>

    <div class="exam-layout">
        <!-- Problem Navigator -->
        <div class="prob-nav">
            <?php foreach ($problems as $idx => $p): ?>
                <?php
                    $cls = '';
                    if ($p['problem_id'] == $activeProbId) $cls .= ' active';
                    if ($p['my_status']) $cls .= ' ' . $p['my_status'];
                ?>
                <a href="exam.php?id=<?= $roomId ?>&prob=<?= $p['problem_id'] ?>" class="prob-btn <?= $cls ?>" title="<?= htmlspecialchars($p['title']) ?>">
                    <span class="prob-number"><?= $idx + 1 ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Problem Details -->
        <div class="panel-left">
            <?php if ($activeProblem): ?>
            <div class="p-4">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h4 class="fw-bold mb-0"><?= htmlspecialchars($activeProblem['title']) ?></h4>
                    <span class="badge" style="background:var(--cq-bg-body);border:1px solid var(--cq-border);color: <?= $activeProblem['difficulty'] === 'Easy' ? 'var(--cq-success)' : ($activeProblem['difficulty'] === 'Medium' ? 'var(--cq-warning)' : 'var(--cq-danger)') ?>">
                        <?= $activeProblem['difficulty'] ?>
                    </span>
                </div>
                
                <div class="markdown-content mt-4" style="line-height:1.6;font-size:0.95rem;" id="problem-description">
                    <?= nl2br(htmlspecialchars($activeProblem['description'])) ?>
                </div>

                <hr class="my-4" style="border-color:var(--cq-border);">

                <h6 class="fw-bold mb-3">Sample Test Cases</h6>
                <?php if (empty($sampleTestcases)): ?>
                    <p class="text-muted" style="font-size:0.85rem;">ไม่มีตัวอย่าง Test Case</p>
                <?php else: ?>
                    <?php foreach ($sampleTestcases as $idx => $tc): 
                        $inLines = $tc['input_data'] ? explode("\n", str_replace("\r", "", $tc['input_data'])) : [];
                        $outLines = $tc['expected_output'] ? explode("\n", str_replace("\r", "", $tc['expected_output'])) : [];
                    ?>
                        <div class="tc-box sample-container" data-hover-map="<?= htmlspecialchars($tc['hover_mapping'] ?? '') ?>">
                            <div class="fw-semibold mb-2" style="font-size:0.85rem;">Sample <?= $idx + 1 ?></div>
                            <div class="row g-2">
                                <?php if (!empty($inLines)): ?>
                                <div class="col-12">
                                    <div style="font-size:0.75rem;color:var(--cq-text-muted);margin-bottom:4px;">Input:</div>
                                    <pre class="sample-io sample-input" style="margin:0;padding:8px;background:rgba(0,0,0,0.1);border-radius:4px;font-size:0.85rem;line-height:1.5;"><?php foreach($inLines as $i => $line): ?><div class="sample-line" data-line="<?= $i ?>"><?= htmlspecialchars($line) ?></div><?php endforeach; ?></pre>
                                </div>
                                <?php endif; ?>
                                <div class="col-12">
                                    <div style="font-size:0.75rem;color:var(--cq-text-muted);margin-bottom:4px;">Expected Output:</div>
                                    <pre class="sample-io sample-output" style="margin:0;padding:8px;background:rgba(0,0,0,0.1);border-radius:4px;font-size:0.85rem;line-height:1.5;"><?php if(empty($outLines)) echo '(empty)'; else foreach($outLines as $i => $line): ?><div class="sample-line" data-line="<?= $i ?>"><?= htmlspecialchars($line) ?></div><?php endforeach; ?></pre>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php else: ?>
                <div class="p-4 text-center text-muted">ไม่พบโจทย์</div>
            <?php endif; ?>
        </div>

        <!-- Editor & Console -->
        <div class="panel-right">
            <div class="editor-container">
                <textarea id="code-editor"><?= htmlspecialchars($activeProblem['starter_code'] ?? '') ?></textarea>
            </div>
            
            <div class="console-container d-flex flex-column">
                <div class="console-header d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-3">
                        <span class="fw-bold text-primary">Terminal</span>
                        <span id="terminal-status" style="font-size:0.7rem;color:var(--cq-text-muted);"></span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button class="btn btn-sm btn-link p-0 text-danger" id="btn-kill" onclick="killProcess()" style="display:none;font-size:0.75rem;" title="Stop">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/></svg>
                        </button>
                        <button class="btn btn-sm btn-link p-0 text-muted" onclick="clearTerminal()" title="Clear">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                </div>
                <div id="terminal-body" class="console-body" style="flex:1; overflow-y:auto; padding:12px 16px; font-family:'JetBrains Mono',monospace; font-size:0.85rem; cursor:text; white-space:pre-wrap; word-break:break-all;" onclick="focusTerminalInput()">
                    <span style="color:var(--cq-text-muted);">$ Press Run Code to execute your program</span>
                </div>
                <!-- Hidden input for capturing keyboard -->
                <input type="text" id="terminal-stdin-input" style="position:absolute;left:-9999px;opacity:0;" autocomplete="off" />
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/python/python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/clike/clike.min.js"></script>
    
    <script>
        const roomId = <?= $roomId ?>;
        const probId = <?= $activeProbId ?>;
        const language = '<?= $room['language'] ?? 'python' ?>';
        const langKey = language; // for WebSocket
        const langDisplay = language.charAt(0).toUpperCase() + language.slice(1);
        let timeRemaining = <?= $timeRemaining ?>;
        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';

        // Timer Logic
        function triggerTimeout() {
            const timeSpan = document.getElementById('timer');
            if (timeSpan) timeSpan.textContent = '00:00:00';
            const btn = document.getElementById('btn-submit');
            if (btn) btn.disabled = true;
            
            let modalEl = document.getElementById('timeoutModal');
            if (!modalEl) {
                const modalHtml = `
                <div class="modal fade" id="timeoutModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-warning text-dark">
                                <h5 class="modal-title">⏱ หมดเวลาสอบแล้ว</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body text-center py-4">
                                <p class="mb-2">เวลาในการทำข้อสอบสิ้นสุดลงแล้ว</p>
                                <p class="text-muted small mb-0">คุณจะไม่สามารถส่งคำตอบได้อีก แต่ยังสามารถดูโค้ดที่เขียนค้างไว้ได้</p>
                            </div>
                            <div class="modal-footer justify-content-center">
                                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">รับทราบ</button>
                                <a href="room_student.php?id=${roomId}" class="btn btn-primary px-4">กลับหน้าหลัก</a>
                            </div>
                        </div>
                    </div>
                </div>`;
                document.body.insertAdjacentHTML('beforeend', modalHtml);
                modalEl = document.getElementById('timeoutModal');
            }
            if(typeof bootstrap !== 'undefined') {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            } else {
                // fallback if bootstrap not loaded yet
                setTimeout(() => {
                    if(typeof bootstrap !== 'undefined') new bootstrap.Modal(modalEl).show();
                }, 1000);
            }
        }

        if (timeRemaining > 0) {
            const timeSpan = document.getElementById('timer') || document.querySelector('.badge.bg-dark');
            setInterval(() => {
                timeRemaining--;
                if (timeRemaining <= 0) {
                    triggerTimeout();
                } else {
                    const h = Math.floor(timeRemaining / 3600).toString().padStart(2, '0');
                    const m = Math.floor((timeRemaining % 3600) / 60).toString().padStart(2, '0');
                    const s = (timeRemaining % 60).toString().padStart(2, '0');
                    if (timeSpan) {
                        timeSpan.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ${h}:${m}:${s}`;
                    }
                }
            }, 1000);
        } else if (timeRemaining === 0) {
            triggerTimeout();
        }

        // Render ||text|| as spoiler blocks
        document.addEventListener('DOMContentLoaded', () => {
            const descEl = document.getElementById('problem-description');
            if (descEl) {
                descEl.innerHTML = descEl.innerHTML.replace(/\|\|(.+?)\|\|/g, 
                    '<span class="spoiler" onclick="this.classList.toggle(\'revealed\')" style="background:#333;color:transparent;border-radius:4px;padding:2px 6px;cursor:pointer;transition:all 0.3s;user-select:none;">$1</span>');
            }
            // Add custom styles for spoiler and hover highlight
            const style = document.createElement('style');
            style.textContent = `
                .spoiler.revealed { color: var(--cq-warning) !important; background: rgba(255,170,0,0.15) !important; }
                [data-bs-theme="light"] .spoiler:not(.revealed) { background: #ccc !important; }
                .sample-io .sample-line { border-radius: 2px; }
                .sample-io .sample-line.highlighted { background: rgba(255,170,0,0.3); }
            `;
            document.head.appendChild(style);

            // Sample Hover Logic
            document.querySelectorAll('.sample-container').forEach(container => {
                let hoverMapStr = container.getAttribute('data-hover-map');
                let mapping = {}; // mapping input line (0-indexed) to array of output lines (0-indexed)
                if (hoverMapStr) {
                    try {
                        let parts = hoverMapStr.split(',');
                        parts.forEach(p => {
                            let [inPart, outPart] = p.split(':').map(s => s.trim());
                            if (!inPart || !outPart) return;
                            
                            // parse output lines
                            let outLines = [];
                            if (outPart.includes('-')) {
                                let [s, e] = outPart.split('-').map(Number);
                                for(let i = s; i <= e; i++) outLines.push(i - 1);
                            } else {
                                outLines.push(Number(outPart) - 1);
                            }
                            
                            // parse input lines
                            if (inPart.includes('-')) {
                                let [s, e] = inPart.split('-').map(Number);
                                for(let i = s; i <= e; i++) {
                                    mapping[i - 1] = outLines;
                                }
                            } else {
                                mapping[Number(inPart) - 1] = outLines;
                            }
                        });
                    } catch(e) { console.error('Invalid hover mapping', e); mapping = null; }
                } else {
                    mapping = null; // null means 1:1 fallback
                }

                container.querySelectorAll('.sample-input .sample-line').forEach(el => {
                    el.addEventListener('mouseenter', function() {
                        let line = parseInt(this.getAttribute('data-line'));
                        let outBox = container.querySelector('.sample-output');
                        if (!outBox) return;
                        
                        let targetOutLines = [];
                        if (mapping !== null) {
                            if (mapping[line] !== undefined) targetOutLines = mapping[line];
                        } else {
                            targetOutLines = [line];
                        }
                        
                        targetOutLines.forEach(outIdx => {
                            let outLine = outBox.querySelector(`[data-line="${outIdx}"]`);
                            if (outLine) outLine.classList.add('highlighted');
                        });
                        if (targetOutLines.length > 0 || mapping === null) {
                            this.classList.add('highlighted');
                        }
                    });
                    el.addEventListener('mouseleave', function() {
                        container.querySelectorAll('.sample-line').forEach(l => l.classList.remove('highlighted'));
                    });
                });
            });
        });
        
        let modeMap = {
            'python': 'python',
            'javascript': 'javascript',
            'c': 'text/x-csrc',
            'cpp': 'text/x-c++src',
            'java': 'text/x-java'
        };

        let editor = CodeMirror.fromTextArea(document.getElementById("code-editor"), {
            mode: modeMap[language] || 'python',
            theme: isDark ? "material-ocean" : "eclipse",
            lineNumbers: true,
            indentUnit: 4,
            matchBrackets: true,
            autoCloseBrackets: true,
            lineWrapping: true
        });// Auto-save logic
        const storageKey = `cq_saved_code_exam_${roomId}_${probId}`;
        const savedCode = localStorage.getItem(storageKey);
        if (savedCode) {
            editor.setValue(savedCode);
        }
        editor.on('change', () => {
            localStorage.setItem(storageKey, editor.getValue());
        });

        // =============================================
        // WebSocket Interactive Terminal
        // =============================================
        const GRADER_WS_URL = '<?= str_replace("https://", "wss://", GRADER_URL) ?>/ws/terminal?key=<?= GRADER_SECRET ?>';
        const terminalBody = document.getElementById('terminal-body');
        const stdinInput = document.getElementById('terminal-stdin-input');
        const terminalStatus = document.getElementById('terminal-status');
        const btnKill = document.getElementById('btn-kill');
        let ws = null;
        let isRunning = false;
        let stdinLineBuffer = '';

        function termWrite(text, className = '') {
            const span = document.createElement('span');
            if (className) span.className = className;
            span.textContent = text;
            terminalBody.appendChild(span);
            terminalBody.scrollTop = terminalBody.scrollHeight;
        }

        function termWriteHtml(html) {
            const div = document.createElement('span');
            div.innerHTML = html;
            terminalBody.appendChild(div);
            terminalBody.scrollTop = terminalBody.scrollHeight;
        }

        function clearTerminal() {
            terminalBody.innerHTML = '';
            termWrite('$ Press Run Code to execute your program\n', 'text-muted');
        }

        function focusTerminalInput() {
            if (isRunning) stdinInput.focus();
        }

        function setTerminalStatus(text) {
            terminalStatus.textContent = text;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function killProcess() {
            if (ws && ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({ type: 'kill' }));
            }
        }

        stdinInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const line = stdinLineBuffer + '\n';
                termWriteHtml(`<span style="color:var(--cq-success);">${stdinLineBuffer}</span>\n`);
                stdinLineBuffer = '';
                stdinInput.value = '';

                if (ws && ws.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({ type: 'stdin', data: line }));
                }
            } else if (e.key === 'Backspace') {
                if (stdinLineBuffer.length > 0) {
                    stdinLineBuffer = stdinLineBuffer.slice(0, -1);
                    updateCursorLine();
                }
            } else if (e.key.length === 1) {
                stdinLineBuffer += e.key;
                updateCursorLine();
            }
        });

        function updateCursorLine() {
            const cursor = document.getElementById('stdin-cursor');
            if (cursor) cursor.remove();
            const cursorSpan = document.createElement('span');
            cursorSpan.id = 'stdin-cursor';
            cursorSpan.innerHTML = `<span style="color:var(--cq-success);">${escapeHtml(stdinLineBuffer)}</span><span style="background:var(--cq-text-primary);color:var(--cq-bg-body);animation:blink 1s step-end infinite;">&nbsp;</span>`;
            terminalBody.appendChild(cursorSpan);
            terminalBody.scrollTop = terminalBody.scrollHeight;
        }

        // Run Code via WebSocket (Interactive Terminal)
        function runCode() {
            const code = editor.getValue();
            if (!code.trim()) return;

            const btn = document.getElementById('btn-run');
            
            terminalBody.innerHTML = '';
            termWrite(`$ Running ${langDisplay} program...\n`, 'text-muted');
            setTerminalStatus('● Connecting...');
            stdinLineBuffer = '';
            stdinInput.value = '';

            if (ws) {
                ws.close();
                ws = null;
            }

            isRunning = true;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-cq me-1" style="width:14px;height:14px;border-width:2px;"></span> Running...';
            btnKill.style.display = 'inline-block';

            ws = new WebSocket(GRADER_WS_URL);

            ws.onopen = () => {
                setTerminalStatus('● Running');
                ws.send(JSON.stringify({
                    type: 'run',
                    language: langKey,
                    code: code,
                    timeLimit: 10,
                    memoryLimit: 128,
                }));
            };

            ws.onmessage = (event) => {
                const msg = JSON.parse(event.data);
                switch (msg.type) {
                    case 'stdout':
                        const cursor = document.getElementById('stdin-cursor');
                        if (cursor) cursor.remove();
                        termWrite(msg.data);
                        stdinInput.focus();
                        break;
                    case 'stderr':
                        termWriteHtml(`<span style="color:var(--cq-danger);">${escapeHtml(msg.data)}</span>`);
                        break;
                    case 'exit':
                        isRunning = false;
                        const cursorEnd = document.getElementById('stdin-cursor');
                        if (cursorEnd) cursorEnd.remove();
                        
                        let exitMsg = '';
                        if (msg.status === 'time_limit_exceeded') {
                            exitMsg = `\n⏱ Time Limit Exceeded`;
                            termWriteHtml(`<span style="color:var(--cq-warning);">${exitMsg}</span>\n`);
                        } else if (msg.status === 'memory_limit_exceeded') {
                            exitMsg = `\n💾 Memory Limit Exceeded`;
                            termWriteHtml(`<span style="color:var(--cq-warning);">${exitMsg}</span>\n`);
                        } else if (msg.code !== 0) {
                            termWriteHtml(`\n<span style="color:var(--cq-danger);">Process exited with code ${msg.code}</span>\n`);
                        } else {
                            termWriteHtml(`\n<span style="color:var(--cq-text-muted);">Process exited with code 0</span>\n`);
                        }
                        setTerminalStatus('');
                        btn.disabled = false;
                        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><polygon points="5 3 19 12 5 21 5 3"/></svg> Run Code';
                        btnKill.style.display = 'none';
                        break;
                    case 'error':
                        termWriteHtml(`<span style="color:var(--cq-danger);">Error: ${escapeHtml(msg.data)}</span>\n`);
                        break;
                    case 'status':
                        if (msg.data === 'running') {
                            setTerminalStatus('● Running');
                            stdinInput.focus();
                        }
                        break;
                }
            };

            ws.onerror = () => {
                termWriteHtml(`<span style="color:var(--cq-danger);">Connection error. Is the Grader Server running?</span>\n`);
                resetRunButton();
            };

            ws.onclose = () => {
                if (isRunning) {
                    resetRunButton();
                }
            };
        }

        function resetRunButton() {
            isRunning = false;
            const btn = document.getElementById('btn-run');
            btn.disabled = false;
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><polygon points="5 3 19 12 5 21 5 3"/></svg> Run Code';
            btnKill.style.display = 'none';
            setTerminalStatus('');
        }

        // Submit Code (REST API - batch grading via GCP server)
        async function submitCode() {
            const code = editor.getValue();
            if (!code.trim()) return;

            const btn = document.getElementById('btn-submit');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-cq" style="width:12px;height:12px;"></span> Submitting...';
            
            terminalBody.innerHTML = '';
            termWrite('$ Submitting for grading...\n', 'text-muted');

            try {
                const res = await fetch('api/exam_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'submit_code',
                        room_id: roomId,
                        problem_id: probId,
                        code: code
                    })
                });
                const data = await res.json();

                if (data.error) {
                    termWriteHtml(`<span style="color:var(--cq-danger);">Error: ${escapeHtml(data.error)}</span>\n`);
                } else {
                    if (data.status === 'accepted') {
                        termWriteHtml(`<span style="color:var(--cq-success);">🎉 ACCEPTED! All ${data.total_cases} test cases passed.</span>\n`);
                        if (data.score_earned !== undefined) {
                            termWriteHtml(`<span style="color:var(--cq-success);">Earned +${data.score_earned} points!</span>\n`);
                        }
                        const navBtn = document.querySelector(`.prob-btn[href*="prob=${probId}"]`);
                        if (navBtn) navBtn.className = 'prob-btn active accepted';
                    } else if (data.status === 'wrong_answer') {
                        termWriteHtml(`<span style="color:var(--cq-danger);">❌ WRONG ANSWER. Passed ${data.passed_cases}/${data.total_cases} test cases.</span>\n`);
                        const navBtn = document.querySelector(`.prob-btn[href*="prob=${probId}"]`);
                        if (navBtn && !navBtn.classList.contains('accepted')) navBtn.className = 'prob-btn active wrong_answer';
                    } else if (data.status === 'time_limit_exceeded') {
                        termWriteHtml(`<span style="color:var(--cq-warning);">⏱ TIME LIMIT EXCEEDED</span>\n`);
                    } else {
                        termWriteHtml(`<span style="color:var(--cq-warning);">⚠️ ${data.status.toUpperCase()}</span>\n`);
                        if (data.error_output) {
                            termWriteHtml(`<span style="color:var(--cq-danger);">${escapeHtml(data.error_output)}</span>\n`);
                        }
                    }
                }
            } catch (err) {
                termWriteHtml(`<span style="color:var(--cq-danger);">Network error: ${escapeHtml(err.message)}</span>\n`);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Submit';
            }
        } // Ensure submitCode function is closed

        // Anti-cheat mechanisms
        let lastFlagTime = 0;
        
        function showStudentWarningModal(details) {
            let modalEl = document.getElementById('cheatWarningModal');
            if (!modalEl) {
                const modalHtml = `
                <div class="modal fade" id="cheatWarningModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content" style="border: 1px solid var(--cq-danger); overflow: hidden;">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title">⚠️ แจ้งเตือนพฤติกรรมต้องสงสัย</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body text-center py-4">
                                <p class="mb-2">ระบบตรวจพบพฤติกรรมที่อาจเข้าข่ายการทุจริต</p>
                                <p class="fw-bold text-danger mb-3" id="cheatWarningDetails"></p>
                                <p class="text-muted small mb-0">ระบบได้บันทึกและส่งข้อมูลแจ้งเตือนไปยังผู้คุมสอบเรียบร้อยแล้ว หากมีข้อผิดพลาดโปรดแจ้งผู้คุมสอบทันที</p>
                            </div>
                            <div class="modal-footer justify-content-center">
                                <button type="button" class="btn btn-danger px-4" data-bs-dismiss="modal">รับทราบ</button>
                            </div>
                        </div>
                    </div>
                </div>`;
                document.body.insertAdjacentHTML('beforeend', modalHtml);
                modalEl = document.getElementById('cheatWarningModal');
            }
            document.getElementById('cheatWarningDetails').textContent = '(' + details + ')';
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        }

        function sendCheatFlag(flagType, details = '') {
            const now = Date.now();
            if (now - lastFlagTime < 5000) return; // limit to 1 per 5s
            lastFlagTime = now;
            
            try {
                showStudentWarningModal(details);
            } catch(e) {
                console.error("Modal error", e);
            }

            fetch('api/room_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                keepalive: true,
                body: JSON.stringify({ action: 'cheat_flag', room_id: roomId, flag_type: flagType, details: details })
            }).then(r => r.json()).then(d => console.log('Flag sent:', d)).catch(e => console.error(e));
        }

        // Poll room events for 'room_ended'
        let lastRoomEventId = 0;
        async function pollRoomEvents() {
            try {
                const res = await fetch(`api/room_poll.php?room_id=${roomId}&last_event_id=${lastRoomEventId}`);
                if (res.ok) {
                    const data = await res.json();
                    if (data.events && data.events.length > 0) {
                        for (const ev of data.events) {
                            lastRoomEventId = Math.max(lastRoomEventId, ev.event_id);
                            if (ev.event_type === 'room_ended') {
                                triggerTimeout();
                                return;
                            }
                        }
                    }
                }
            } catch (e) {}
        }
        setInterval(pollRoomEvents, 5000);
        pollRoomEvents();
        
        // Listen for tab switch (visibility change)
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                sendCheatFlag('tab_switch', 'ผู้เข้าสอบเปลี่ยนแท็บหรือย่อหน้าต่าง');
            }
        });
        
        // Listen for window blur (clicking outside the window)
        window.addEventListener('blur', () => {
            sendCheatFlag('tab_switch', 'ผู้เข้าสอบคลิกสลับหน้าต่างหรือใช้งานโปรแกรมอื่น');
        });
        
        // Disable Right-Click (context menu) to prevent Google Lens etc.
        document.addEventListener('contextmenu', (e) => {
            e.preventDefault();
        });
        
        // Detect copy/paste
        document.addEventListener('copy', () => sendCheatFlag('copy_paste', 'มีการคัดลอกข้อความ'));
        document.addEventListener('paste', () => sendCheatFlag('copy_paste', 'มีการวางข้อความ'));

        // Initialize Split.js
        Split(['#left-pane', '#right-pane'], {
            sizes: [50, 50],
            minSize: 300,
            gutterSize: 8,
            cursor: 'col-resize'
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>