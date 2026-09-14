<?php
require_once 'db.php';
requireLogin();

$user = getCurrentUser($pdo);
$theme = getUserTheme($pdo);
$setId = intval($_GET['set_id'] ?? 0);
$probId = intval($_GET['prob_id'] ?? 0);

if (!$setId || !$probId) {
    header('Location: practice.php');
    exit;
}

// Get set info
$stmt = $pdo->prepare("SELECT * FROM `cq_problem_sets` WHERE `set_id` = ? AND (`visibility` = 'public' OR `owner_id` = ?)");
$stmt->execute([$setId, $user['user_id']]);
$set = $stmt->fetch();

if (!$set) {
    header('Location: practice.php');
    exit;
}

// Get problem info
$pStmt = $pdo->prepare("SELECT * FROM `cq_problems` WHERE `problem_id` = ? AND `set_id` = ?");
$pStmt->execute([$probId, $setId]);
$problem = $pStmt->fetch();

if (!$problem) {
    header('Location: practice.php?set_id=' . $setId);
    exit;
}

// Get sample test cases
$tcStmt = $pdo->prepare("SELECT * FROM `cq_testcases` WHERE `problem_id` = ? AND `is_sample` = 1 ORDER BY `order_index`");
$tcStmt->execute([$probId]);
$sampleTestcases = $tcStmt->fetchAll();

// Get past submissions
$subStmt = $pdo->prepare("SELECT * FROM `cq_submissions` WHERE `problem_id` = ? AND `user_id` = ? AND `room_id` IS NULL ORDER BY `submitted_at` DESC LIMIT 10");
$subStmt->execute([$probId, $user['user_id']]);
$submissions = $subStmt->fetchAll();

// Find previous saved code
$savedCode = $problem['starter_code'] ?? '';
if (count($submissions) > 0) {
    $savedCode = $submissions[0]['code'];
}
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodeQuest - <?= htmlspecialchars($problem['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    
    <!-- CodeMirror -->
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
        body { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        .workspace { flex: 1; display: flex; overflow: hidden; }
        .panel-left { width: 40%; border-right: 1px solid var(--cq-border); display: flex; flex-direction: column; background: var(--cq-bg-body); overflow-y: auto; -ms-overflow-style: none; scrollbar-width: none; }
        .panel-left::-webkit-scrollbar { display: none; }
        .panel-right { width: 60%; display: flex; flex-direction: column; }
        .editor-container { flex: 1; overflow: hidden; position: relative; }
        .CodeMirror { height: 100%; font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 14px; }
        .console-container { height: 35%; border-top: 1px solid var(--cq-border); background: var(--cq-bg-card); display: flex; flex-direction: column; }
        .console-header { padding: 8px 16px; border-bottom: 1px solid var(--cq-border); font-size: 0.85rem; font-weight: 600; display: flex; justify-content: space-between; }
        .console-body { flex: 1; overflow-y: auto; padding: 12px 16px; font-family: 'JetBrains Mono', monospace; font-size: 0.85rem; }
        .tc-box { background: var(--cq-bg-body); border: 1px solid var(--cq-border); border-radius: 6px; padding: 12px; margin-bottom: 12px; }
        .tc-box pre { margin: 0; padding: 8px; background: rgba(0,0,0,0.2); border-radius: 4px; border: 1px solid rgba(255,255,255,0.05); }
        .workspace-header { padding: 12px 20px; border-bottom: 1px solid var(--cq-border); background: var(--cq-bg-card); display: flex; justify-content: space-between; align-items: center; }
        
        [data-bs-theme="light"] .tc-box pre { background: rgba(0,0,0,0.05); border-color: rgba(0,0,0,0.1); }
        
        @media (max-width: 768px) {
            body { overflow: auto; height: auto; }
            .workspace { flex-direction: column; height: auto; overflow: visible; }
            .panel-left { width: 100%; height: auto; border-right: none; border-bottom: 2px solid var(--cq-border); }
            .panel-right { width: 100%; height: 85vh; min-height: 500px; display: flex; flex-direction: column; }
        }
        @keyframes blink { 50% { opacity: 0; } }
    </style>
</head>
<body class="preload">
    <!-- Top Nav -->
    <div class="workspace-header">
        <div class="d-flex align-items-center gap-3">
            <a href="practice.php?set_id=<?= $setId ?>" class="btn btn-sm text-muted d-flex align-items-center justify-content-center" style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.05);transition:background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.05)'" title="Back"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg></a>
            <div>
                <h5 class="mb-0 fw-bold" style="font-size:1.1rem;"><?= htmlspecialchars($problem['title']) ?></h5>
                <span style="font-size:0.75rem;color:var(--cq-text-muted);"><?= htmlspecialchars($set['title']) ?></span>
            </div>
            <span class="badge" style="background:var(--cq-bg-body);border:1px solid var(--cq-border);color: <?= $problem['difficulty'] === 'Easy' ? 'var(--cq-success)' : ($problem['difficulty'] === 'Medium' ? 'var(--cq-warning)' : 'var(--cq-danger)') ?>">
                <?= $problem['difficulty'] ?>
            </span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary me-2"><?= htmlspecialchars(strtoupper($set['language'])) ?></span>
            <!-- Theme toggle from include -->
            <button class="theme-toggle btn btn-sm btn-link text-muted p-0 me-3" type="button" onclick="CQTheme.toggle()">🌓</button>
            
            <button class="btn btn-sm btn-cq-secondary" id="btn-run" onclick="runCode()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><polygon points="5 3 19 12 5 21 5 3"/></svg> Run Code
            </button>
            <button class="btn btn-sm btn-cq-primary" id="btn-submit" onclick="submitCode()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Submit
            </button>
        </div>
    </div>

    <!-- Workspace -->
    <div class="workspace">
        <!-- Problem Description -->
        <div class="panel-left">
            <div class="p-4">
                <div class="markdown-content" style="line-height:1.6;font-size:0.95rem;color:var(--cq-text-primary);" id="problem-description">
                    <?= nl2br(htmlspecialchars($problem['description'])) ?>
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
                                    <pre class="sample-io sample-input" style="line-height:1.5;margin:0;"><?php foreach($inLines as $i => $line): ?><div class="sample-line" data-line="<?= $i ?>"><?= htmlspecialchars($line) ?></div><?php endforeach; ?></pre>
                                </div>
                                <?php endif; ?>
                                <div class="col-12">
                                    <div style="font-size:0.75rem;color:var(--cq-text-muted);margin-bottom:4px;">Expected Output:</div>
                                    <pre class="sample-io sample-output" style="line-height:1.5;margin:0;"><?php if(empty($outLines)) echo '(empty)'; else foreach($outLines as $i => $line): ?><div class="sample-line" data-line="<?= $i ?>"><?= htmlspecialchars($line) ?></div><?php endforeach; ?></pre>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if (!empty($submissions)): ?>
                <hr class="my-4" style="border-color:var(--cq-border);">
                <h6 class="fw-bold mb-3">Recent Submissions</h6>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($submissions as $sub): ?>
                        <?php
                            $scolor = 'var(--cq-text-muted)';
                            if ($sub['status'] === 'accepted') $scolor = 'var(--cq-success)';
                            if ($sub['status'] === 'wrong_answer') $scolor = 'var(--cq-danger)';
                            if ($sub['status'] === 'error') $scolor = 'var(--cq-warning)';
                        ?>
                        <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background:var(--cq-bg-card);border:1px solid var(--cq-border);font-size:0.8rem;">
                            <span style="color:<?= $scolor ?>;font-weight:600;">
                                <?= strtoupper(str_replace('_', ' ', $sub['status'])) ?>
                            </span>
                            <span style="color:var(--cq-text-muted);"><?= date('H:i:s d/m/y', strtotime($sub['submitted_at'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Editor & Console -->
        <div class="panel-right">
            <div class="editor-container">
                <textarea id="code-editor"><?= htmlspecialchars($savedCode) ?></textarea>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/edit/closebrackets.min.js"></script>
    
    <script>
        const language = '<?= $set['language'] ?>';
        const probId = <?= $probId ?>;
        const langKey = language; // for WebSocket
        const langDisplay = language.charAt(0).toUpperCase() + language.slice(1);
        
        let modeMap = {
            'python': 'python',
            'javascript': 'javascript',
            'c': 'text/x-csrc',
            'cpp': 'text/x-c++src',
            'java': 'text/x-java'
        };

        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        let editor = CodeMirror.fromTextArea(document.getElementById("code-editor"), {
            mode: modeMap[language] || 'python',
            theme: isDark ? "material-ocean" : "eclipse",
            lineNumbers: true,
            indentUnit: 4,
            matchBrackets: true,
            autoCloseBrackets: true,
            lineWrapping: true
        });

        // Sync theme with editor
        const originalApplyTheme = CQTheme.apply;
        CQTheme.apply = function(theme) {
            originalApplyTheme(theme);
            editor.setOption('theme', theme === 'light' ? 'eclipse' : 'material-ocean');
        };

        const consoleOut = document.getElementById('console-output');

        // Render ||text|| as spoiler blocks
        (function() {
            const descEl = document.getElementById('problem-description');
            if (descEl) {
                descEl.innerHTML = descEl.innerHTML.replace(/\|\|(.+?)\|\|/g, 
                    '<span class="spoiler" onclick="this.classList.toggle(\'revealed\')" style="background:#333;color:transparent;border-radius:4px;padding:2px 6px;cursor:pointer;transition:all 0.3s;user-select:none;">$1</span>');
            }
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
        })();

        // Auto-save logic
        const storageKey = `cq_saved_code_practice_${probId}`;
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

        function killProcess() {
            if (ws && ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({ type: 'kill' }));
            }
        }

        // Handle stdin input
        stdinInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const line = stdinLineBuffer + '\n';
                // Echo input in terminal (green color)
                termWriteHtml(`<span style="color:var(--cq-success);">${stdinLineBuffer}</span>\n`);
                stdinLineBuffer = '';
                stdinInput.value = '';

                if (ws && ws.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({ type: 'stdin', data: line }));
                }
            } else if (e.key === 'Backspace') {
                if (stdinLineBuffer.length > 0) {
                    stdinLineBuffer = stdinLineBuffer.slice(0, -1);
                    // Re-render cursor line
                    updateCursorLine();
                }
            } else if (e.key.length === 1) {
                stdinLineBuffer += e.key;
                updateCursorLine();
            }
        });

        function updateCursorLine() {
            // Remove previous cursor display
            const cursor = document.getElementById('stdin-cursor');
            if (cursor) cursor.remove();
            
            const cursorSpan = document.createElement('span');
            cursorSpan.id = 'stdin-cursor';
            cursorSpan.innerHTML = `<span style="color:var(--cq-success);">${escapeHtml(stdinLineBuffer)}</span><span style="background:var(--cq-text-primary);color:var(--cq-bg-body);animation:blink 1s step-end infinite;">&nbsp;</span>`;
            terminalBody.appendChild(cursorSpan);
            terminalBody.scrollTop = terminalBody.scrollHeight;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Run Code via WebSocket (Interactive Terminal)
        function runCode() {
            const code = editor.getValue();
            if (!code.trim()) return;

            const btn = document.getElementById('btn-run');
            
            // Clear terminal
            terminalBody.innerHTML = '';
            termWrite(`$ Running ${langDisplay} program...\n`, 'text-muted');
            setTerminalStatus('● Connecting...');
            stdinLineBuffer = '';
            stdinInput.value = '';

            // Close existing connection
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
                        // Remove cursor before writing
                        const cursor = document.getElementById('stdin-cursor');
                        if (cursor) cursor.remove();
                        termWrite(msg.data);
                        // Focus input for potential stdin
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
                const res = await fetch('api/practice_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'submit_code',
                        problem_id: probId,
                        code: code
                    })
                });
                const data = await res.json();

                if (data.error) {
                    termWriteHtml(`<span style="color:var(--cq-danger);">Error: ${escapeHtml(data.error)}</span>\n`);
                } else {
                    if (data.status === 'accepted') {
                        termWriteHtml(`<span style="color:var(--cq-success);">🎉 ACCEPTED! All test cases passed.</span>\n`);
                    } else if (data.status === 'wrong_answer') {
                        termWriteHtml(`<span style="color:var(--cq-danger);">❌ WRONG ANSWER. Passed ${data.passed_cases}/${data.total_cases} test cases.</span>\n`);
                        if (data.failed_sample) {
                            termWrite(`\nFailed on sample case:\n  Input:    ${data.failed_sample.input}\n  Expected: ${data.failed_sample.expected}\n  Actual:   ${data.failed_sample.actual}\n`);
                        }
                    } else if (data.status === 'time_limit_exceeded') {
                        termWriteHtml(`<span style="color:var(--cq-warning);">⏱ TIME LIMIT EXCEEDED</span>\n`);
                    } else if (data.status === 'memory_limit_exceeded') {
                        termWriteHtml(`<span style="color:var(--cq-warning);">💾 MEMORY LIMIT EXCEEDED</span>\n`);
                    } else {
                        termWriteHtml(`<span style="color:var(--cq-warning);">⚠️ ${data.status.toUpperCase()}</span>\n`);
                        if (data.error_output) {
                            termWriteHtml(`<span style="color:var(--cq-danger);">${escapeHtml(data.error_output)}</span>\n`);
                        }
                    }
                    setTimeout(() => window.location.reload(), 2000);
                }
            } catch (err) {
                termWriteHtml(`<span style="color:var(--cq-danger);">Network error: ${escapeHtml(err.message)}</span>\n`);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Submit';
            }
        }
    </script>
</body>
</html>