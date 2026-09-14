<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'update_theme':
        $theme = $input['theme'] ?? 'dark';
        if (!in_array($theme, ['light', 'dark'])) $theme = 'dark';
        
        if (isset($_SESSION['user_id'])) {
            $stmt = $pdo->prepare("UPDATE `cq_users` SET `theme` = ? WHERE `user_id` = ?");
            $stmt->execute([$theme, $_SESSION['user_id']]);
        }
        
        jsonResponse(['success' => true]);
        break;
        
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
?>