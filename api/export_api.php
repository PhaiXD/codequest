<?php
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$user = getCurrentUser($pdo);
$roomId = intval($_GET['room_id'] ?? 0);
$columns = explode(',', $_GET['columns'] ?? 'name,score');

if (!$roomId) {
    http_response_code(400);
    echo 'Missing room_id';
    exit;
}

// Verify host
$stmt = $pdo->prepare("
    SELECT r.*, ps.title as set_title
    FROM `cq_rooms` r
    JOIN `cq_problem_sets` ps ON r.`set_id` = ps.`set_id`
    WHERE r.`room_id` = ? AND r.`host_id` = ?
");
$stmt->execute([$roomId, $user['user_id']]);
$room = $stmt->fetch();

if (!$room) {
    http_response_code(403);
    echo 'Not found or no permission';
    exit;
}

// Get problems for this set
$probStmt = $pdo->prepare("SELECT problem_id, title FROM `cq_problems` WHERE `set_id` = ? ORDER BY `order_index`");
$probStmt->execute([$room['set_id']]);
$problems = $probStmt->fetchAll();

// Get participants with submissions
$pStmt = $pdo->prepare("
    SELECT p.user_id, u.username, u.display_name,
           (SELECT SUM(score) FROM `cq_submissions` s WHERE s.`user_id` = p.`user_id` AND s.`room_id` = ?) as total_score,
           (SELECT MAX(submitted_at) FROM `cq_submissions` s WHERE s.`user_id` = p.`user_id` AND s.`room_id` = ?) as last_submit
    FROM `cq_participants` p
    JOIN `cq_users` u ON p.`user_id` = u.`user_id`
    WHERE p.`room_id` = ?
    ORDER BY total_score DESC, last_submit ASC
");
$pStmt->execute([$roomId, $roomId, $roomId]);
$participants = $pStmt->fetchAll();

// Generate CSV
$filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $room['set_title']) . '_results_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// BOM for Excel UTF-8 compatibility
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Header row
$headers = ['#'];
if (in_array('name', $columns)) $headers[] = 'ชื่อ';
if (in_array('score', $columns)) $headers[] = 'คะแนนรวม';
if (in_array('time', $columns)) $headers[] = 'เวลาส่งล่าสุด';
if (in_array('answers', $columns)) {
    foreach ($problems as $prob) {
        $headers[] = 'คำตอบ: ' . $prob['title'];
    }
}
fputcsv($output, $headers);

// Data rows
foreach ($participants as $idx => $p) {
    $row = [$idx + 1];
    
    if (in_array('name', $columns)) {
        $row[] = $p['display_name'] ?? $p['username'];
    }
    if (in_array('score', $columns)) {
        $row[] = intval($p['total_score']);
    }
    if (in_array('time', $columns)) {
        $row[] = $p['last_submit'] ? date('d/m/Y H:i:s', strtotime($p['last_submit'])) : '-';
    }
    if (in_array('answers', $columns)) {
        foreach ($problems as $prob) {
            // Get best submission for this problem
            $sStmt = $pdo->prepare("
                SELECT `code`, `status` FROM `cq_submissions` 
                WHERE `user_id` = ? AND `problem_id` = ? AND `room_id` = ? 
                ORDER BY `score` DESC, `submitted_at` DESC LIMIT 1
            ");
            $sStmt->execute([$p['user_id'], $prob['problem_id'], $roomId]);
            $sub = $sStmt->fetch();
            $row[] = $sub ? $sub['code'] : '(ไม่ได้ส่ง)';
        }
    }
    
    fputcsv($output, $row);
}

fclose($output);
exit;
?>
