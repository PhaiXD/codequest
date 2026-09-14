<?php
require_once 'db.php';
requireLogin();

$user = getCurrentUser($pdo);
$theme = getUserTheme($pdo);
$setId = $_GET['id'] ?? null;
$problemSet = null;
$problems = [];

// Load existing set if editing
if ($setId) {
    $stmt = $pdo->prepare("SELECT * FROM `cq_problem_sets` WHERE `set_id` = ? AND `owner_id` = ?");
    $stmt->execute([$setId, $user['user_id']]);
    $problemSet = $stmt->fetch();
    
    if (!$problemSet) {
        header('Location: dashboard.php');
        exit;
    }
    
    // Load problems with test cases
    $pStmt = $pdo->prepare("SELECT * FROM `cq_problems` WHERE `set_id` = ? ORDER BY `order_index`, `problem_id`");
    $pStmt->execute([$setId]);
    $problems = $pStmt->fetchAll();
    
    foreach ($problems as &$prob) {
        $tcStmt = $pdo->prepare("SELECT * FROM `cq_testcases` WHERE `problem_id` = ? ORDER BY `order_index`, `testcase_id`");
        $tcStmt->execute([$prob['problem_id']]);
        $prob['testcases'] = $tcStmt->fetchAll();
    }
    unset($prob);
}
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodeQuest - <?= $setId ? 'แก้ไขชุดโจทย์' : 'สร้างชุดโจทย์ใหม่' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/theme.js"></script>
    <style>
        .problem-item { background: var(--cq-bg-card); border: 1px solid var(--cq-border); border-radius: var(--cq-radius-md); margin-bottom: 1rem; transition: var(--cq-transition); overflow: hidden; }
        .problem-item:hover { border-color: var(--cq-border-light); }
        .problem-item.active { border-color: var(--cq-primary); box-shadow: var(--cq-shadow-glow); }
        .problem-header { padding: 1rem 1.25rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center; user-select: none; transition: background 0.2s; }
        .problem-header:hover { background: var(--cq-bg-hover); }
        .problem-body { padding: 0 1.25rem 1.25rem; }
        .problem-body.collapsed { display: none; }
        .collapse-icon { transition: transform 0.3s ease; display: inline-flex; }
        .collapse-icon.collapsed { transform: rotate(-90deg); }
        .tc-row { display: flex; gap: 0.5rem; align-items: start; margin-bottom: 0.5rem; }
        .tc-row textarea { font-family: 'JetBrains Mono', monospace; font-size: 0.8rem; }
        .problem-number { width: 28px; height: 28px; border-radius: var(--cq-radius-full); background: var(--cq-primary-light); color: var(--cq-primary); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; flex-shrink: 0; }
        .save-indicator { position: fixed; bottom: 84px; right: 24px; z-index: 1060; }
        
        /* Auto-resize textarea */
        textarea.auto-resize { overflow: hidden; resize: none; min-height: 42px; transition: height 0.1s ease; }
        textarea.tc-auto-resize { overflow: hidden; resize: none; min-height: 60px; }
        
        /* Rich text toolbar */
        .rich-toolbar { display: flex; gap: 2px; padding: 6px 8px; background: var(--cq-bg-body); border: 1px solid var(--cq-border); border-bottom: none; border-radius: var(--cq-radius-md) var(--cq-radius-md) 0 0; flex-wrap: wrap; }
        .rich-toolbar + textarea { border-radius: 0 0 var(--cq-radius-md) var(--cq-radius-md) !important; }
        .rich-toolbar .tb-btn { width: 32px; height: 28px; border: none; border-radius: 4px; background: transparent; color: var(--cq-text-secondary); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem; font-weight: 600; transition: all 0.15s; position: relative; }
        .rich-toolbar .tb-btn:hover { background: var(--cq-primary-light); color: var(--cq-primary); }
        .rich-toolbar .tb-btn:active { transform: scale(0.92); }
        .rich-toolbar .tb-sep { width: 1px; background: var(--cq-border); margin: 4px 4px; }
        .rich-toolbar .tb-btn[title]:hover::after { content: attr(title); position: absolute; top: -30px; left: 50%; transform: translateX(-50%); background: var(--cq-bg-surface); color: var(--cq-text-primary); padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 400; white-space: nowrap; border: 1px solid var(--cq-border); z-index: 10; pointer-events: none; }
        
        /* Starter code toggle */
        .starter-code-toggle { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border: 1px dashed var(--cq-border); border-radius: var(--cq-radius-md); background: transparent; color: var(--cq-text-muted); cursor: pointer; font-size: 0.85rem; transition: all 0.2s; }
        .starter-code-toggle:hover { border-color: var(--cq-primary); color: var(--cq-primary); background: var(--cq-primary-light); }
        .starter-code-area { position: relative; }
        .starter-code-area .remove-starter { position: absolute; top: 8px; right: 8px; background: var(--cq-bg-card); border: 1px solid var(--cq-border); border-radius: 4px; color: var(--cq-danger); cursor: pointer; padding: 2px 6px; font-size: 0.75rem; z-index: 2; transition: all 0.15s; }
        .starter-code-area .remove-starter:hover { background: rgba(255,68,68,0.1); }

        @media (max-width: 768px) {
            .problem-header { padding: 0.75rem 1rem; }
            .problem-body { padding: 0 1rem 1rem; }
            .tc-row { flex-direction: column; }
            .tc-row > div { width: 100% !important; }
            .rich-toolbar .tb-btn { width: 28px; height: 26px; font-size: 0.75rem; }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="dashboard-container" style="max-width: 1000px;">
        <div class="d-flex justify-content-between align-items-center mb-4 animate-fadeInUp">
            <div>
                <a href="dashboard.php" style="color:var(--cq-text-muted);font-size:0.85rem;">← กลับ Dashboard</a>
                <h2 class="fw-bold mt-1 mb-0"><?= $setId ? 'แก้ไขชุดโจทย์' : 'สร้างชุดโจทย์ใหม่' ?></h2>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-cq-secondary d-none d-md-inline-flex align-items-center gap-1" onclick="toggleAllProblems()" title="หุบ/กางทุกข้อ">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="7 8 12 3 17 8"/><polyline points="17 16 12 21 7 16"/></svg>
                </button>
                <button class="btn btn-cq-primary" id="btn-save" onclick="saveAll()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Save All
                </button>
            </div>
        </div>

        <!-- Set Info -->
        <div class="cq-card mb-4 animate-fadeInUp">
            <h5 class="fw-bold mb-3">ข้อมูลชุดโจทย์</h5>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">ชื่อชุดโจทย์ <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="set-title" placeholder="เช่น ข้อสอบกลางภาค เรื่อง Python เบื้องต้น" value="<?= htmlspecialchars($problemSet['title'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">ภาษา</label>
                    <select class="form-select" id="set-language">
                        <option value="python" <?= ($problemSet['language'] ?? 'python') === 'python' ? 'selected' : '' ?>>Python</option>
                        <option value="javascript" <?= ($problemSet['language'] ?? '') === 'javascript' ? 'selected' : '' ?>>JavaScript</option>
                        <option value="c" <?= ($problemSet['language'] ?? '') === 'c' ? 'selected' : '' ?>>C</option>
                        <option value="cpp" <?= ($problemSet['language'] ?? '') === 'cpp' ? 'selected' : '' ?>>C++</option>
                        <option value="java" <?= ($problemSet['language'] ?? '') === 'java' ? 'selected' : '' ?>>Java</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">คำอธิบาย</label>
                    <textarea class="form-control auto-resize" id="set-description" rows="2" placeholder="อธิบายเกี่ยวกับชุดโจทย์นี้"><?= htmlspecialchars($problemSet['description'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">การมองเห็น</label>
                    <select class="form-select" id="set-visibility">
                        <option value="private" <?= ($problemSet['visibility'] ?? 'private') === 'private' ? 'selected' : '' ?>>🔒 Private - เฉพาะตัวเอง</option>
                        <option value="public" <?= ($problemSet['visibility'] ?? '') === 'public' ? 'selected' : '' ?>>🌐 Public - ทุกคนเห็นและใช้ได้</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Problems List -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">โจทย์ (<span id="problem-count"><?= count($problems) ?></span> ข้อ)</h5>
            <button class="btn btn-cq-accent btn-sm" onclick="addProblem()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                เพิ่มโจทย์
            </button>
        </div>

        <div id="problems-container">
            <?php if (empty($problems)): ?>
            <!-- Empty state will be shown via JS -->
            <?php endif; ?>
        </div>

        <div class="text-center py-3">
            <button class="btn btn-cq-outline" onclick="addProblem()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                เพิ่มโจทย์
            </button>
        </div>
    </div>

    
    <!-- Scroll to Top Button -->
    <button id="scrollToTopBtn" class="btn btn-cq-primary" style="display: none; position: fixed; bottom: 24px; right: 24px; z-index: 1050; border-radius: 50%; width: 50px; height: 50px; padding: 0; box-shadow: var(--cq-shadow-md);" onclick="window.scrollTo({top: 0, behavior: 'smooth'})" title="เลื่อนขึ้นบนสุด">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>
    </button>
    
    <!-- Save Indicator -->
    <div class="save-indicator" id="save-indicator" style="display:none;">
        <div class="alert alert-success d-flex align-items-center gap-2 py-2 px-3 shadow animate-scaleIn" style="font-size:0.85rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Saved!
        </div>
    </div>

    
    <!-- Unsaved Changes Modal -->
    <div class="modal fade" id="unsavedModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--cq-bg-surface); border: 1px solid var(--cq-border);">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title" style="color: var(--cq-warning); font-weight: 700;">⚠ มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body text-center pt-4 pb-4">
            <p class="mb-0" style="color: var(--cq-text-secondary);">หากคุณออกจากหน้านี้ การเปลี่ยนแปลงทั้งหมดจะสูญหาย<br>คุณต้องการบันทึกก่อนหรือไม่?</p>
          </div>
          <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
            <button type="button" class="btn btn-cq-outline" onclick="discardAndLeave()">ละทิ้ง (Discard)</button>
            <button type="button" class="btn btn-cq-primary" onclick="saveAndLeave()">บันทึก (Save)</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirm Delete Problem Modal -->
    <div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="background: var(--cq-bg-surface); border: 1px solid var(--cq-border);">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title" style="color: var(--cq-danger); font-weight: 700;">🗑️ ลบโจทย์</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body text-center pt-3 pb-3">
            <p class="mb-0" style="color: var(--cq-text-secondary);" id="confirmDeleteMsg">ต้องการลบโจทย์ข้อนี้หรือไม่?</p>
          </div>
          <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
            <button type="button" class="btn btn-cq-outline" data-bs-dismiss="modal">ยกเลิก</button>
            <button type="button" class="btn btn-cq-danger" id="confirmDeleteBtn">ลบ</button>
          </div>
        </div>
      </div>
    </div>

    <?php include 'includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const setId = <?= $setId ? intval($setId) : 'null' ?>;
    let problems = <?= json_encode($problems) ?> || [];
    let problemCounter = problems.length;
    let isDirty = false;
    let targetUrl = '';
    let collapsedStates = {}; // track collapsed state per problem index
    let tcCollapsedStates = {}; // track collapsed state for test cases

    function markDirty() {
        isDirty = true;
    }

    document.addEventListener('input', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
            markDirty();
        }
    });

    window.addEventListener('beforeunload', function (e) {
        if (isDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (link && link.href && !link.hasAttribute('data-bs-toggle') && link.href !== window.location.href && !link.href.includes('#')) {
            if (isDirty) {
                e.preventDefault();
                targetUrl = link.href;
                const modal = new bootstrap.Modal(document.getElementById('unsavedModal'));
                modal.show();
            }
        }
    });

    function discardAndLeave() {
        isDirty = false;
        if (targetUrl) window.location.href = targetUrl;
    }

    async function saveAndLeave() {
        const modalEl = document.getElementById('unsavedModal');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
        
        await saveAll(true);
    }
    
    function moveProblem(idx, dir) {
        if (idx + dir < 0 || idx + dir >= problems.length) return;
        const temp = problems[idx];
        problems[idx] = problems[idx + dir];
        problems[idx + dir] = temp;
        // swap collapsed states too
        const tempC = collapsedStates[idx];
        collapsedStates[idx] = collapsedStates[idx + dir];
        collapsedStates[idx + dir] = tempC;
        markDirty();
        renderProblems();
        
        setTimeout(() => {
            const el = document.getElementById('prob-' + (idx + dir));
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 50);
    }

    // ========================
    // Auto-resize textarea
    // ========================
    function autoResize(el) {
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }

    function initAutoResize() {
        document.querySelectorAll('textarea.auto-resize, textarea.tc-auto-resize').forEach(ta => {
            autoResize(ta);
            ta.removeEventListener('input', handleAutoResize);
            ta.addEventListener('input', handleAutoResize);
        });
    }

    function handleAutoResize(e) {
        autoResize(e.target);
    }

    // ========================
    // Toggle collapse
    // ========================
    function toggleProblem(idx) {
        collapsedStates[idx] = !collapsedStates[idx];
        const body = document.getElementById('prob-body-' + idx);
        const icon = document.getElementById('collapse-icon-' + idx);
        if (body) body.classList.toggle('collapsed', collapsedStates[idx]);
        if (icon) icon.classList.toggle('collapsed', collapsedStates[idx]);
    }

    function toggleAllProblems() {
        const anyExpanded = problems.some((_, idx) => !collapsedStates[idx]);
        problems.forEach((_, idx) => {
            collapsedStates[idx] = anyExpanded;
        });
        renderProblems();
    }

    function toggleTestCase(probIdx, tcIdx) {
        const key = probIdx + '-' + tcIdx;
        tcCollapsedStates[key] = !tcCollapsedStates[key];
        const body = document.getElementById(`tc-body-${probIdx}-${tcIdx}`);
        const icon = document.getElementById(`tc-collapse-icon-${probIdx}-${tcIdx}`);
        if (body) body.classList.toggle('collapsed', tcCollapsedStates[key]);
        if (icon) icon.classList.toggle('collapsed', tcCollapsedStates[key]);
    }

    function moveTestCase(probIdx, tcIdx, dir) {
        const tcs = problems[probIdx].testcases;
        if (tcIdx + dir < 0 || tcIdx + dir >= tcs.length) return;
        
        const temp = tcs[tcIdx];
        tcs[tcIdx] = tcs[tcIdx + dir];
        tcs[tcIdx + dir] = temp;
        
        const keyA = probIdx + '-' + tcIdx;
        const keyB = probIdx + '-' + (tcIdx + dir);
        const tempC = tcCollapsedStates[keyA];
        tcCollapsedStates[keyA] = tcCollapsedStates[keyB];
        tcCollapsedStates[keyB] = tempC;
        
        markDirty();
        renderProblems();
    }

    // ========================
    // Rich text toolbar helpers
    // ========================
    function wrapSelection(textareaId, before, after) {
        const ta = document.getElementById(textareaId);
        if (!ta) return;
        const start = ta.selectionStart;
        const end = ta.selectionEnd;
        const text = ta.value;
        const selected = text.substring(start, end);
        
        // If already wrapped, unwrap
        const bLen = before.length;
        const aLen = after.length;
        if (start >= bLen && text.substring(start - bLen, start) === before && text.substring(end, end + aLen) === after) {
            ta.value = text.substring(0, start - bLen) + selected + text.substring(end + aLen);
            ta.selectionStart = start - bLen;
            ta.selectionEnd = end - bLen;
        } else {
            const newText = before + (selected || 'text') + after;
            ta.value = text.substring(0, start) + newText + text.substring(end);
            if (selected) {
                ta.selectionStart = start + bLen;
                ta.selectionEnd = start + bLen + selected.length;
            } else {
                ta.selectionStart = start + bLen;
                ta.selectionEnd = start + bLen + 4; // select "text"
            }
        }
        ta.focus();
        // Trigger change for data binding
        const probIdx = ta.getAttribute('data-prob-idx');
        if (probIdx !== null) {
            problems[parseInt(probIdx)].description = ta.value;
        }
        markDirty();
        autoResize(ta);
    }

    function renderToolbar(probIdx) {
        const taId = `desc-${probIdx}`;
        return `
        <div class="rich-toolbar">
            <button type="button" class="tb-btn" title="Bold (Ctrl+B)" onclick="wrapSelection('${taId}','**','**')"><b>B</b></button>
            <button type="button" class="tb-btn" title="Italic (Ctrl+I)" onclick="wrapSelection('${taId}','*','*')"><i>I</i></button>
            <button type="button" class="tb-btn" title="Underline (Ctrl+U)" onclick="wrapSelection('${taId}','__','__')"><u>U</u></button>
            <div class="tb-sep"></div>
            <button type="button" class="tb-btn" title="Code (Ctrl+E)" onclick="wrapSelection('${taId}','\`','\`')" style="font-family:'JetBrains Mono',monospace;font-size:0.75rem;">&lt;/&gt;</button>
            <button type="button" class="tb-btn" title="Strikethrough (Ctrl+Shift+S)" onclick="wrapSelection('${taId}','~~','~~')"><s>S</s></button>
            <button type="button" class="tb-btn" title="Spoiler" onclick="wrapSelection('${taId}','||','||')">👁</button>
            <div class="tb-sep"></div>
            <button type="button" class="tb-btn" title="Code Block" onclick="wrapSelection('${taId}','\\n\`\`\`\\n','\\n\`\`\`\\n')" style="font-family:'JetBrains Mono',monospace;font-size:0.65rem;">{ }</button>
        </div>`;
    }

    // Keyboard shortcuts for rich text
    document.addEventListener('keydown', function(e) {
        const ta = document.activeElement;
        if (!ta || !ta.classList.contains('desc-textarea')) return;
        
        const probIdx = ta.getAttribute('data-prob-idx');
        const taId = ta.id;
        
        if (e.ctrlKey || e.metaKey) {
            if (e.key === 'b' || e.key === 'B') {
                e.preventDefault();
                wrapSelection(taId, '**', '**');
            } else if (e.key === 'i' || e.key === 'I') {
                e.preventDefault();
                wrapSelection(taId, '*', '*');
            } else if (e.key === 'u' || e.key === 'U') {
                e.preventDefault();
                wrapSelection(taId, '__', '__');
            } else if (e.key === 'e' || e.key === 'E') {
                e.preventDefault();
                wrapSelection(taId, '`', '`');
            } else if ((e.key === 's' || e.key === 'S') && e.shiftKey) {
                e.preventDefault();
                wrapSelection(taId, '~~', '~~');
            }
        }
    });


    function renderProblems() {
        const container = document.getElementById('problems-container');
        if (problems.length === 0) {
            container.innerHTML = '<div class="empty-state"><div class="empty-icon">❓</div><h3>ยังไม่มีโจทย์</h3><p>กดปุ่ม "เพิ่มโจทย์" เพื่อเริ่มต้น</p></div>';
            document.getElementById('problem-count').textContent = '0';
            return;
        }
        
        let html = '';
        problems.forEach((p, idx) => {
            const tcs = p.testcases || [];
            const isCollapsed = !!collapsedStates[idx];
            const hasStarterCode = !!(p.starter_code && p.starter_code.trim());
            html += `
            <div class="problem-item animate-fadeInUp" id="prob-${idx}">
                <div class="problem-header" onclick="toggleProblem(${idx})">
                    <div class="d-flex align-items-center gap-2">
                        <span class="collapse-icon ${isCollapsed ? 'collapsed' : ''}" id="collapse-icon-${idx}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </span>
                        <span class="problem-number">${idx + 1}</span>
                        <h6 class="fw-bold mb-0">${escHtml(p.title || 'โจทย์ข้อที่ ' + (idx + 1))}</h6>
                        <span class="badge" style="font-size:0.65rem;background:${p.difficulty === 'Easy' ? 'rgba(0,212,170,0.15);color:var(--cq-success)' : p.difficulty === 'Hard' ? 'rgba(255,68,68,0.15);color:var(--cq-danger)' : 'rgba(255,170,0,0.15);color:var(--cq-warning)'};">${p.difficulty || 'Easy'}</span>
                    </div>
                    <div class="d-flex gap-1" onclick="event.stopPropagation()">
                        <button class="btn btn-sm btn-cq-secondary" onclick="moveProblem(${idx}, -1)" ${idx === 0 ? 'disabled' : ''} title="เลื่อนขึ้น" style="padding:4px 6px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>
                        </button>
                        <button class="btn btn-sm btn-cq-secondary" onclick="moveProblem(${idx}, 1)" ${idx === problems.length - 1 ? 'disabled' : ''} title="เลื่อนลง" style="padding:4px 6px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <button class="btn btn-sm" style="color:var(--cq-danger); border: 1px solid var(--cq-border);padding:4px 6px;" onclick="confirmRemoveProblem(${idx})" title="ลบโจทย์">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                </div>
                <div class="problem-body ${isCollapsed ? 'collapsed' : ''}" id="prob-body-${idx}">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">ชื่อโจทย์ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" value="${escHtml(p.title || '')}" onchange="problems[${idx}].title=this.value; updateProblemTitle(${idx}, this.value)" placeholder="เช่น Hello World">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ระดับความยาก</label>
                            <select class="form-select" onchange="problems[${idx}].difficulty=this.value">
                                <option value="Easy" ${p.difficulty === 'Easy' ? 'selected' : ''}>🟢 Easy</option>
                                <option value="Medium" ${p.difficulty === 'Medium' ? 'selected' : ''}>🟡 Medium</option>
                                <option value="Hard" ${p.difficulty === 'Hard' ? 'selected' : ''}>🔴 Hard</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">รายละเอียดโจทย์ <span class="text-danger">*</span></label>
                            ${renderToolbar(idx)}
                            <textarea class="form-control auto-resize desc-textarea" id="desc-${idx}" data-prob-idx="${idx}" onchange="problems[${idx}].description=this.value" oninput="problems[${idx}].description=this.value" placeholder="อธิบายโจทย์ที่นักเรียนต้องทำ (รองรับ Markdown: **bold**, *italic*, \`code\`)">${escHtml(p.description || '')}</textarea>
                        </div>
                        
                        <!-- Starter Code (Toggle) -->
                        <div class="col-12">
                            <div id="starter-toggle-${idx}" style="${hasStarterCode ? 'display:none' : ''}">
                                <button type="button" class="starter-code-toggle" onclick="showStarterCode(${idx})">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    เพิ่ม Starter Code
                                </button>
                            </div>
                            <div class="starter-code-area" id="starter-area-${idx}" style="${hasStarterCode ? '' : 'display:none'}">
                                <label class="form-label">โค้ดเริ่มต้น (Starter Code)</label>
                                <button type="button" class="remove-starter" onclick="hideStarterCode(${idx})" title="ลบ Starter Code">✕ ลบ</button>
                                <textarea class="form-control font-mono auto-resize" id="starter-${idx}" onchange="problems[${idx}].starter_code=this.value" oninput="problems[${idx}].starter_code=this.value" placeholder="# Write your code here" style="font-size:0.85rem;">${escHtml(p.starter_code || '')}</textarea>
                            </div>
                        </div>
                        
                        <!-- Test Cases -->
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0 fw-bold">🧪 Test Cases (${tcs.length})</label>
                                <button class="btn btn-sm btn-cq-secondary" onclick="addTestCase(${idx})">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    เพิ่ม Test Case
                                </button>
                            </div>
                            <div id="tc-container-${idx}">
                                ${tcs.map((tc, tcIdx) => renderTestCase(idx, tcIdx, tc)).join('')}
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;
        });
        
        container.innerHTML = html;
        document.getElementById('problem-count').textContent = problems.length;
        
        // Initialize auto-resize after render
        requestAnimationFrame(initAutoResize);
    }

    function updateProblemTitle(idx, title) {
        // Update the header title in real-time without full re-render
        const header = document.querySelector(`#prob-${idx} .problem-header h6`);
        if (header) header.textContent = title || 'โจทย์ข้อที่ ' + (idx + 1);
    }

    function showStarterCode(idx) {
        document.getElementById('starter-toggle-' + idx).style.display = 'none';
        document.getElementById('starter-area-' + idx).style.display = '';
        const ta = document.getElementById('starter-' + idx);
        if (ta) { ta.focus(); autoResize(ta); }
        markDirty();
    }

    function hideStarterCode(idx) {
        problems[idx].starter_code = '';
        document.getElementById('starter-toggle-' + idx).style.display = '';
        document.getElementById('starter-area-' + idx).style.display = 'none';
        markDirty();
    }

    function renderTestCase(probIdx, tcIdx, tc) {
        const key = probIdx + '-' + tcIdx;
        const isCollapsed = !!tcCollapsedStates[key];
        const tcsLength = problems[probIdx].testcases.length;
        
        return `
        <div class="problem-item mb-2" id="tc-box-${probIdx}-${tcIdx}" style="padding: 0; background: var(--cq-bg-card); border-radius: 6px;">
            <div class="problem-header" onclick="toggleTestCase(${probIdx}, ${tcIdx})" style="padding: 8px 12px; border-bottom: ${isCollapsed ? 'none' : '1px solid var(--cq-border)'}; background: transparent; cursor: pointer;">
                <div class="d-flex align-items-center gap-2">
                    <span class="collapse-icon ${isCollapsed ? 'collapsed' : ''}" id="tc-collapse-icon-${probIdx}-${tcIdx}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </span>
                    <span style="font-size: 0.85rem; font-weight: 600;">Test Case #${tcIdx + 1} ${tc.is_sample ? '<span class="badge bg-cq-secondary ms-1" style="font-size:0.6rem;">Sample</span>' : ''}</span>
                </div>
                <div class="d-flex gap-1" onclick="event.stopPropagation()">
                    <button class="btn btn-sm btn-cq-secondary" onclick="moveTestCase(${probIdx}, ${tcIdx}, -1)" ${tcIdx === 0 ? 'disabled' : ''} title="เลื่อนขึ้น" style="padding:2px 4px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>
                    </button>
                    <button class="btn btn-sm btn-cq-secondary" onclick="moveTestCase(${probIdx}, ${tcIdx}, 1)" ${tcIdx === tcsLength - 1 ? 'disabled' : ''} title="เลื่อนลง" style="padding:2px 4px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <button class="btn btn-sm" style="color:var(--cq-danger); border: 1px solid var(--cq-border); padding:2px 4px;" onclick="removeTestCase(${probIdx}, ${tcIdx})" title="ลบ Test Case">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    </button>
                </div>
            </div>
            <div class="problem-body ${isCollapsed ? 'collapsed' : ''}" id="tc-body-${probIdx}-${tcIdx}" style="padding: 12px; background: transparent;">
                <div class="tc-row" style="margin-bottom:0;">
                    <div class="flex-fill">
                        <label class="form-label" style="font-size:0.75rem;">Input</label>
                        <textarea class="form-control tc-auto-resize" onchange="problems[${probIdx}].testcases[${tcIdx}].input_data=this.value" oninput="autoResize(this)" placeholder="Input data">${escHtml(tc.input_data || '')}</textarea>
                    </div>
                    <div class="flex-fill">
                        <label class="form-label" style="font-size:0.75rem;">Expected Output</label>
                        <textarea class="form-control tc-auto-resize" onchange="problems[${probIdx}].testcases[${tcIdx}].expected_output=this.value" oninput="autoResize(this)" placeholder="Expected output">${escHtml(tc.expected_output || '')}</textarea>
                    </div>
                    <div style="padding-top:24px;">
                        <div class="form-check" title="แสดงเป็นตัวอย่าง">
                            <input class="form-check-input" type="checkbox" id="sample-chk-${probIdx}-${tcIdx}" ${tc.is_sample ? 'checked' : ''} onchange="markDirty(); problems[${probIdx}].testcases[${tcIdx}].is_sample=this.checked?1:0; document.getElementById('hover-map-container-${probIdx}-${tcIdx}').style.display = this.checked ? 'block' : 'none'; renderProblems();">
                            <label class="form-label" style="font-size:0.7rem;" for="sample-chk-${probIdx}-${tcIdx}">Sample</label>
                        </div>
                    </div>
                </div>
                <div id="hover-map-container-${probIdx}-${tcIdx}" style="display: ${tc.is_sample ? 'block' : 'none'}; margin-top:8px; padding-top:8px; border-top:1px dashed var(--cq-border);">
                    <label class="form-label" style="font-size:0.7rem; color:var(--cq-text-muted);">การเชื่อมโยงบรรทัดไฮไลท์ (Hover Mapping) - <i>(เฉพาะโจทย์ที่มีหลายบรรทัด)</i></label>
                    <input type="text" class="form-control form-control-sm" style="font-family:'JetBrains Mono',monospace; font-size:0.75rem;" placeholder="เช่น 1:1, 2-3:2 (เว้นว่างไว้ให้ระบบซิงค์ 1:1 อัตโนมัติ)" value="${escHtml(tc.hover_mapping || '')}" onchange="problems[${probIdx}].testcases[${tcIdx}].hover_mapping=this.value">
                </div>
            </div>
        </div>`;
    }

    function addProblem() {
        markDirty();
        problems.push({
            title: '',
            description: '',
            difficulty: 'Easy',
            order_index: problems.length,
            starter_code: '',
            solution_code: '',
            testcases: []
        });
        renderProblems();
        // Scroll to new problem
        setTimeout(() => {
            const el = document.getElementById('prob-' + (problems.length - 1));
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 100);
    }

    // Confirm delete with modal instead of confirm()
    let pendingDeleteIdx = null;
    function confirmRemoveProblem(idx) {
        pendingDeleteIdx = idx;
        document.getElementById('confirmDeleteMsg').textContent = 'ต้องการลบโจทย์ข้อที่ ' + (idx + 1) + ' หรือไม่?';
        const modal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));
        modal.show();
    }
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (pendingDeleteIdx !== null) {
            problems.splice(pendingDeleteIdx, 1);
            // Rebuild collapsed states
            const newStates = {};
            Object.keys(collapsedStates).forEach(k => {
                const ki = parseInt(k);
                if (ki < pendingDeleteIdx) newStates[ki] = collapsedStates[ki];
                else if (ki > pendingDeleteIdx) newStates[ki - 1] = collapsedStates[ki];
            });
            collapsedStates = newStates;
            markDirty();
            renderProblems();
            pendingDeleteIdx = null;
        }
        bootstrap.Modal.getInstance(document.getElementById('confirmDeleteModal')).hide();
    });

    function addTestCase(probIdx) {
        if (!problems[probIdx].testcases) problems[probIdx].testcases = [];
        problems[probIdx].testcases.push({ input_data: '', expected_output: '', is_sample: 0 });
        markDirty();
        renderProblems();
        // Expand if collapsed
        if (collapsedStates[probIdx]) {
            toggleProblem(probIdx);
        }
    }

    function removeTestCase(probIdx, tcIdx) {
        problems[probIdx].testcases.splice(tcIdx, 1);
        markDirty();
        renderProblems();
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    async function saveAll(skipRedirect = false) {
        const title = document.getElementById('set-title').value.trim();
        if (!title) {
            document.getElementById('set-title').focus();
            document.getElementById('set-title').classList.add('is-invalid');
            setTimeout(() => document.getElementById('set-title').classList.remove('is-invalid'), 2000);
            return;
        }

        const btn = document.getElementById('btn-save');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-cq" style="width:16px;height:16px;border-width:2px;"></span> Saving...';

        const data = {
            action: setId ? 'update_set' : 'create_set',
            set_id: setId,
            title: title,
            description: document.getElementById('set-description').value.trim(),
            language: document.getElementById('set-language').value,
            visibility: document.getElementById('set-visibility').value,
            problems: problems.map((p, idx) => ({
                problem_id: p.problem_id || null,
                title: p.title,
                description: p.description,
                difficulty: p.difficulty || 'Easy',
                order_index: idx,
                starter_code: p.starter_code || '',
                solution_code: p.solution_code || '',
                testcases: (p.testcases || []).map((tc, tcIdx) => ({
                    testcase_id: tc.testcase_id || null,
                    input_data: tc.input_data || '',
                    expected_output: tc.expected_output || '',
                    is_sample: tc.is_sample ? 1 : 0,
                    order_index: tcIdx,
                    hover_mapping: tc.hover_mapping || null
                }))
            }))
        };

        try {
            const res = await fetch('api/problemset_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await res.json();
            
            if (result.success) {
                isDirty = false;
                const indicator = document.getElementById('save-indicator');
                indicator.style.display = 'block';
                setTimeout(() => { indicator.style.display = 'none'; }, 2000);
                
                if (skipRedirect) {
                    if (targetUrl) window.location.href = targetUrl;
                    return true;
                }
                
                if (!setId && result.set_id) {
                    window.location.href = 'problemset_editor.php?id=' + result.set_id;
                } else {
                    window.location.href = 'problemset_editor.php?id=' + (setId || result.set_id);
                }
            } else {
                alert(result.error || 'Error saving');
            }
        } catch (err) {
            alert('Network error: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save All';
        }
    }

    
    // Scroll to Top Logic
    window.addEventListener('scroll', function() {
        const btn = document.getElementById('scrollToTopBtn');
        if (window.scrollY > 300) {
            btn.style.display = 'block';
        } else {
            btn.style.display = 'none';
        }
    });

    // Initial render
    renderProblems();
    </script>
</body>
</html>
