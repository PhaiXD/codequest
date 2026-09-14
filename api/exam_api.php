<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$user = getCurrentUser($pdo);
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$roomId = intval($input['room_id'] ?? 0);
$probId = intval($input['problem_id'] ?? 0);
$code = $input['code'] ?? '';

if (!$roomId) {
    jsonResponse(['error' => 'Missing room_id'], 400);
}

// Verify participant and room status
$stmt = $pdo->prepare("SELECT r.*, p.participant_id FROM `cq_rooms` r JOIN `cq_participants` p ON r.`room_id` = p.`room_id` WHERE r.`room_id` = ? AND p.`user_id` = ?");
$stmt->execute([$roomId, $user['user_id']]);
$roomInfo = $stmt->fetch();

if (!$roomInfo) {
    jsonResponse(['error' => 'Not a participant'], 403);
}

if ($roomInfo['status'] !== 'started') {
    jsonResponse(['error' => 'Room is not active'], 400);
}

// Verify problem belongs to the room's set
if ($probId) {
    $pStmt = $pdo->prepare("SELECT p.*, ps.language FROM `cq_problems` p JOIN `cq_problem_sets` ps ON p.`set_id` = ps.`set_id` WHERE p.`problem_id` = ? AND p.`set_id` = ?");
    $pStmt->execute([$probId, $roomInfo['set_id']]);
    $problem = $pStmt->fetch();
    
    if (!$problem && $action !== 'get_room_state') {
        jsonResponse(['error' => 'Problem not found in this room'], 404);
    }
}

switch ($action) {
    // ==========================================
    // Submit Code (Exam Mode)
    // ==========================================
    case 'submit_code':
        if (empty($code)) {
            jsonResponse(['error' => 'Code is empty'], 400);
        }
        
        $tcStmt = $pdo->prepare("SELECT * FROM `cq_testcases` WHERE `problem_id` = ? ORDER BY `order_index`");
        $tcStmt->execute([$probId]);
        $testcases = $tcStmt->fetchAll();
        
        $totalCases = count($testcases);
        
        if ($totalCases > 0) {
            // Use batch grading
            $gradeResult = gradeCode($problem['language'], $code, $testcases);
            $status = $gradeResult['status'] ?? 'error';
            $passedCount = $gradeResult['passed_cases'] ?? 0;
            $errorOutput = $gradeResult['error_output'] ?? '';
        } else {
            $result = executeCode($problem['language'], $code, "");
            $status = 'accepted';
            $passedCount = 0;
            $errorOutput = '';
            if (isset($result['error']) || !empty($result['stderr'])) {
                $status = 'error';
                $errorOutput = $result['error'] ?? $result['stderr'];
            }
        }
        
        // Save submission
        $stmt = $pdo->prepare("INSERT INTO `cq_submissions` (`user_id`, `problem_id`, `room_id`, `code`, `status`, `score`) VALUES (?, ?, ?, ?, ?, ?)");
        
        // Calculate score
        $score = 0;
        if ($status === 'accepted') {
            // Check if already solved in this room
            $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM `cq_submissions` WHERE `user_id` = ? AND `problem_id` = ? AND `room_id` = ? AND `status` = 'accepted'");
            $chkStmt->execute([$user['user_id'], $probId, $roomId]);
            
            if ($chkStmt->fetchColumn() == 0) {
                $ptsMap = ['Easy' => 100, 'Medium' => 200, 'Hard' => 300];
                $score = $ptsMap[$problem['difficulty']] ?? 100;
                
                // Time bonus could be added here
            }
        }
        
        $stmt->execute([$user['user_id'], $probId, $roomId, $code, $status, $score]);
        
        if ($score > 0) {
            // Notify room of score change (for realtime leaderboard)
            $newTotalStmt = $pdo->prepare("SELECT SUM(score) FROM `cq_submissions` WHERE `user_id` = ? AND `room_id` = ?");
            $newTotalStmt->execute([$user['user_id'], $roomId]);
            $newTotal = $newTotalStmt->fetchColumn();
            
            pushRoomEvent($pdo, $roomId, 'submission_update', [
                'user_id' => $user['user_id'],
                'new_total_score' => $newTotal
            ]);
            
            // Also add to global total_score
            $pdo->prepare("UPDATE `cq_users` SET `total_score` = `total_score` + ? WHERE `user_id` = ?")->execute([$score, $user['user_id']]);
        }
        
        jsonResponse([
            'status' => $status,
            'passed_cases' => $passedCount,
            'total_cases' => $totalCases,
            'score_earned' => $score,
            'error_output' => $errorOutput
        ]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
?>