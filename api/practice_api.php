<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$user = getCurrentUser($pdo);
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$probId = intval($input['problem_id'] ?? 0);
$code = $input['code'] ?? '';

if (!$probId || empty($code)) {
    jsonResponse(['error' => 'Missing problem_id or code'], 400);
}

// Get problem and language
$stmt = $pdo->prepare("SELECT p.*, ps.language FROM `cq_problems` p JOIN `cq_problem_sets` ps ON p.`set_id` = ps.`set_id` WHERE p.`problem_id` = ?");
$stmt->execute([$probId]);
$problem = $stmt->fetch();

if (!$problem) {
    jsonResponse(['error' => 'Problem not found'], 404);
}

switch ($action) {
    // ==========================================
    // Run Code (Sample Test Cases)
    // ==========================================
    case 'run_code':
        $customInput = $input['custom_input'] ?? '';
        $result = executeCode($problem['language'], $code, $customInput);
        
        if (isset($result['error'])) {
            jsonResponse(['error' => $result['error']]);
        }
        
        jsonResponse([
            'results' => [[
                'passed' => true,
                'input' => $customInput,
                'expected' => '',
                'actual' => trim($result['stdout'] ?? ''),
                'error' => $result['stderr'] ?? '',
                'output' => trim($result['stdout'] ?? '')
            ]]
        ]);
        break;

    // ==========================================
    // Submit Code (All Test Cases + DB Record)
    // ==========================================
    case 'submit_code':
        $tcStmt = $pdo->prepare("SELECT * FROM `cq_testcases` WHERE `problem_id` = ? ORDER BY `order_index`");
        $tcStmt->execute([$probId]);
        $testcases = $tcStmt->fetchAll();
        
        $totalCases = count($testcases);
        
        if ($totalCases > 0) {
            // Use batch grading - sends all testcases to grader in one request
            $gradeResult = gradeCode($problem['language'], $code, $testcases);
            $status = $gradeResult['status'] ?? 'error';
            $passedCount = $gradeResult['passed_cases'] ?? 0;
            $errorOutput = $gradeResult['error_output'] ?? '';
            $failedSample = $gradeResult['failed_sample'] ?? null;
        } else {
            // No test cases, just run it
            $result = executeCode($problem['language'], $code, "");
            $status = 'accepted';
            $passedCount = 0;
            $errorOutput = '';
            $failedSample = null;
            if (isset($result['error']) || !empty($result['stderr'])) {
                $status = 'error';
                $errorOutput = $result['error'] ?? $result['stderr'];
            }
        }
        
        // Save submission (Practice mode, room_id is NULL)
        $stmt = $pdo->prepare("INSERT INTO `cq_submissions` (`user_id`, `problem_id`, `room_id`, `code`, `status`) VALUES (?, ?, NULL, ?, ?)");
        $stmt->execute([$user['user_id'], $probId, $code, $status]);
        $submissionId = $pdo->lastInsertId();
        
        // Update user score if first time accept
        if ($status === 'accepted') {
            // Check if this is the first accept for this user on this problem
            $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM `cq_submissions` WHERE `user_id` = ? AND `problem_id` = ? AND `status` = 'accepted' AND `submission_id` != ?");
            $chkStmt->execute([$user['user_id'], $probId, $submissionId]);
            
            if ($chkStmt->fetchColumn() == 0) {
                // Award points (e.g., 10 for Easy, 20 for Medium, 30 for Hard)
                $ptsMap = ['Easy' => 10, 'Medium' => 20, 'Hard' => 30];
                $pts = $ptsMap[$problem['difficulty']] ?? 10;
                
                $pdo->prepare("UPDATE `cq_users` SET `total_score` = `total_score` + ?, `practice_solved` = `practice_solved` + 1 WHERE `user_id` = ?")
                    ->execute([$pts, $user['user_id']]);
            }
        }
        
        jsonResponse([
            'status' => $status,
            'passed_cases' => $passedCount,
            'total_cases' => $totalCases,
            'error_output' => $errorOutput,
            'failed_sample' => $failedSample
        ]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
?>