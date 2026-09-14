<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$user = getCurrentUser($pdo);
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    // ==========================================
    // Create Problem Set
    // ==========================================
    case 'create_set':
        $title = trim($input['title'] ?? '');
        if (empty($title)) {
            jsonResponse(['error' => 'Title is required'], 400);
        }
        
        $pdo->beginTransaction();
        try {
            // Create set
            $stmt = $pdo->prepare("INSERT INTO `cq_problem_sets` (`owner_id`, `title`, `description`, `language`, `visibility`) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $user['user_id'],
                $title,
                $input['description'] ?? '',
                $input['language'] ?? 'python',
                $input['visibility'] ?? 'private'
            ]);
            $setId = $pdo->lastInsertId();
            
            // Create problems
            if (!empty($input['problems'])) {
                foreach ($input['problems'] as $idx => $prob) {
                    $pStmt = $pdo->prepare("INSERT INTO `cq_problems` (`set_id`, `title`, `description`, `difficulty`, `order_index`, `starter_code`, `solution_code`) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $pStmt->execute([
                        $setId,
                        $prob['title'] ?? 'Untitled',
                        $prob['description'] ?? '',
                        $prob['difficulty'] ?? 'Easy',
                        $prob['order_index'] ?? $idx,
                        $prob['starter_code'] ?? '',
                        $prob['solution_code'] ?? ''
                    ]);
                    $problemId = $pdo->lastInsertId();
                    
                    // Create test cases
                    if (!empty($prob['testcases'])) {
                        $tcStmt = $pdo->prepare("INSERT INTO `cq_testcases` (`problem_id`, `input_data`, `expected_output`, `is_sample`, `order_index`) VALUES (?, ?, ?, ?, ?)");
                        foreach ($prob['testcases'] as $tcIdx => $tc) {
                            $tcStmt->execute([
                                $problemId,
                                $tc['input_data'] ?? '',
                                $tc['expected_output'] ?? '',
                                $tc['is_sample'] ?? 0,
                                $tc['order_index'] ?? $tcIdx
                            ]);
                        }
                    }
                }
            }
            
            $pdo->commit();
            jsonResponse(['success' => true, 'set_id' => $setId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Failed to create: ' . $e->getMessage()], 500);
        }
        break;
    
    // ==========================================
    // Update Problem Set
    // ==========================================
    case 'update_set':
        $setId = intval($input['set_id'] ?? 0);
        if (!$setId) {
            jsonResponse(['error' => 'Set ID required'], 400);
        }
        
        // Verify ownership
        $stmt = $pdo->prepare("SELECT * FROM `cq_problem_sets` WHERE `set_id` = ? AND `owner_id` = ?");
        $stmt->execute([$setId, $user['user_id']]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'Not found or no permission'], 403);
        }
        
        $pdo->beginTransaction();
        try {
            // Update set info
            $stmt = $pdo->prepare("UPDATE `cq_problem_sets` SET `title` = ?, `description` = ?, `language` = ?, `visibility` = ? WHERE `set_id` = ?");
            $stmt->execute([
                trim($input['title'] ?? ''),
                $input['description'] ?? '',
                $input['language'] ?? 'python',
                $input['visibility'] ?? 'private',
                $setId
            ]);
            
            // Get existing problem IDs
            $existingProblems = $pdo->prepare("SELECT `problem_id` FROM `cq_problems` WHERE `set_id` = ?");
            $existingProblems->execute([$setId]);
            $existingIds = $existingProblems->fetchAll(PDO::FETCH_COLUMN);
            
            $newProblemIds = [];
            
            if (!empty($input['problems'])) {
                foreach ($input['problems'] as $idx => $prob) {
                    if (!empty($prob['problem_id']) && in_array($prob['problem_id'], $existingIds)) {
                        // Update existing problem
                        $pStmt = $pdo->prepare("UPDATE `cq_problems` SET `title` = ?, `description` = ?, `difficulty` = ?, `order_index` = ?, `starter_code` = ?, `solution_code` = ? WHERE `problem_id` = ? AND `set_id` = ?");
                        $pStmt->execute([
                            $prob['title'] ?? 'Untitled',
                            $prob['description'] ?? '',
                            $prob['difficulty'] ?? 'Easy',
                            $prob['order_index'] ?? $idx,
                            $prob['starter_code'] ?? '',
                            $prob['solution_code'] ?? '',
                            $prob['problem_id'],
                            $setId
                        ]);
                        $problemId = $prob['problem_id'];
                        
                        // Delete old test cases and re-insert
                        $pdo->prepare("DELETE FROM `cq_testcases` WHERE `problem_id` = ?")->execute([$problemId]);
                    } else {
                        // Create new problem
                        $pStmt = $pdo->prepare("INSERT INTO `cq_problems` (`set_id`, `title`, `description`, `difficulty`, `order_index`, `starter_code`, `solution_code`) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $pStmt->execute([
                            $setId,
                            $prob['title'] ?? 'Untitled',
                            $prob['description'] ?? '',
                            $prob['difficulty'] ?? 'Easy',
                            $prob['order_index'] ?? $idx,
                            $prob['starter_code'] ?? '',
                            $prob['solution_code'] ?? ''
                        ]);
                        $problemId = $pdo->lastInsertId();
                    }
                    
                    $newProblemIds[] = $problemId;
                    
                    // Insert test cases
                    if (!empty($prob['testcases'])) {
                        $tcStmt = $pdo->prepare("INSERT INTO `cq_testcases` (`problem_id`, `input_data`, `expected_output`, `is_sample`, `order_index`, `hover_mapping`) VALUES (?, ?, ?, ?, ?, ?)");
                        foreach ($prob['testcases'] as $tcIdx => $tc) {
                            $tcStmt->execute([
                                $problemId,
                                $tc['input_data'] ?? '',
                                $tc['expected_output'] ?? '',
                                $tc['is_sample'] ?? 0,
                                $tc['order_index'] ?? $tcIdx,
                                $tc['hover_mapping'] ?? null
                            ]);
                        }
                    }
                }
            }
            
            // Delete removed problems
            $removedIds = array_diff($existingIds, $newProblemIds);
            if (!empty($removedIds)) {
                $placeholders = implode(',', array_fill(0, count($removedIds), '?'));
                $pdo->prepare("DELETE FROM `cq_problems` WHERE `problem_id` IN ($placeholders)")->execute(array_values($removedIds));
            }
            
            $pdo->commit();
            jsonResponse(['success' => true, 'set_id' => $setId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Failed to update: ' . $e->getMessage()], 500);
        }
        break;
    
    // ==========================================
    // Delete Problem Set
    // ==========================================
    case 'delete_set':
        $setId = intval($input['set_id'] ?? $_GET['set_id'] ?? 0);
        
        // Verify ownership (or admin)
        $stmt = $pdo->prepare("SELECT * FROM `cq_problem_sets` WHERE `set_id` = ?");
        $stmt->execute([$setId]);
        $set = $stmt->fetch();
        
        if (!$set || ($set['owner_id'] !== $user['user_id'] && $user['role'] !== 'admin')) {
            jsonResponse(['error' => 'No permission'], 403);
        }
        
        $pdo->prepare("DELETE FROM `cq_problem_sets` WHERE `set_id` = ?")->execute([$setId]);
        jsonResponse(['success' => true]);
        break;
    
    // ==========================================
    // Fork Problem Set
    // ==========================================
    case 'fork_set':
        $setId = intval($input['set_id'] ?? 0);
        
        $stmt = $pdo->prepare("SELECT * FROM `cq_problem_sets` WHERE `set_id` = ? AND (`visibility` = 'public' OR `owner_id` = ?)");
        $stmt->execute([$setId, $user['user_id']]);
        $originalSet = $stmt->fetch();
        
        if (!$originalSet) {
            jsonResponse(['error' => 'Set not found'], 404);
        }
        
        $pdo->beginTransaction();
        try {
            // Copy set
            $stmt = $pdo->prepare("INSERT INTO `cq_problem_sets` (`owner_id`, `title`, `description`, `language`, `visibility`, `forked_from`) VALUES (?, ?, ?, ?, 'private', ?)");
            $stmt->execute([
                $user['user_id'],
                $originalSet['title'] . ' (Copy)',
                $originalSet['description'],
                $originalSet['language'],
                $setId
            ]);
            $newSetId = $pdo->lastInsertId();
            
            // Copy problems and test cases
            $problems = $pdo->prepare("SELECT * FROM `cq_problems` WHERE `set_id` = ? ORDER BY `order_index`");
            $problems->execute([$setId]);
            
            foreach ($problems->fetchAll() as $prob) {
                $pStmt = $pdo->prepare("INSERT INTO `cq_problems` (`set_id`, `title`, `description`, `difficulty`, `order_index`, `starter_code`, `solution_code`) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $pStmt->execute([$newSetId, $prob['title'], $prob['description'], $prob['difficulty'], $prob['order_index'], $prob['starter_code'], $prob['solution_code']]);
                $newProbId = $pdo->lastInsertId();
                
                // Copy test cases
                $tcs = $pdo->prepare("SELECT * FROM `cq_testcases` WHERE `problem_id` = ?");
                $tcs->execute([$prob['problem_id']]);
                foreach ($tcs->fetchAll() as $tc) {
                    $pdo->prepare("INSERT INTO `cq_testcases` (`problem_id`, `input_data`, `expected_output`, `is_sample`, `order_index`) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newProbId, $tc['input_data'], $tc['expected_output'], $tc['is_sample'], $tc['order_index']]);
                }
            }
            
            $pdo->commit();
            jsonResponse(['success' => true, 'set_id' => $newSetId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Fork failed: ' . $e->getMessage()], 500);
        }
        break;
    
    // ==========================================
    // Get Problem Set
    // ==========================================
    case 'get_set':
        $setId = intval($_GET['set_id'] ?? 0);
        
        $stmt = $pdo->prepare("SELECT ps.*, u.username, u.display_name FROM `cq_problem_sets` ps JOIN `cq_users` u ON ps.`owner_id` = u.`user_id` WHERE ps.`set_id` = ? AND (ps.`visibility` = 'public' OR ps.`owner_id` = ?)");
        $stmt->execute([$setId, $user['user_id']]);
        $set = $stmt->fetch();
        
        if (!$set) {
            jsonResponse(['error' => 'Not found'], 404);
        }
        
        // Get problems
        $problems = $pdo->prepare("SELECT * FROM `cq_problems` WHERE `set_id` = ? ORDER BY `order_index`");
        $problems->execute([$setId]);
        $set['problems'] = $problems->fetchAll();
        
        // Get test cases (only sample ones for non-owners)
        foreach ($set['problems'] as &$prob) {
            if ($set['owner_id'] === $user['user_id'] || $user['role'] === 'admin') {
                $tcStmt = $pdo->prepare("SELECT * FROM `cq_testcases` WHERE `problem_id` = ? ORDER BY `order_index`");
            } else {
                $tcStmt = $pdo->prepare("SELECT * FROM `cq_testcases` WHERE `problem_id` = ? AND `is_sample` = 1 ORDER BY `order_index`");
            }
            $tcStmt->execute([$prob['problem_id']]);
            $prob['testcases'] = $tcStmt->fetchAll();
        }
        
        jsonResponse(['success' => true, 'set' => $set]);
        break;
    
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
?>