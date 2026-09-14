<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$user = getCurrentUser($pdo);
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

switch ($action) {
    // ==========================================
    // Create Room (Host)
    // ==========================================
    case 'create_room':
        $setId = intval($input['set_id'] ?? 0);
        $mode = $input['mode'] ?? 'realtime'; // 'realtime' or 'assign'
        $settingsInput = $input['settings'] ?? [];
        
        // Verify set ownership
        $stmt = $pdo->prepare("SELECT * FROM `cq_problem_sets` WHERE `set_id` = ? AND `owner_id` = ?");
        $stmt->execute([$setId, $user['user_id']]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'Not found or no permission'], 403);
        }
        
        // Generate unique 6-digit PIN
        $pinCode = '';
        while (true) {
            $pinCode = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $chk = $pdo->prepare("SELECT COUNT(*) FROM `cq_rooms` WHERE `pin_code` = ?");
            $chk->execute([$pinCode]);
            if ($chk->fetchColumn() == 0) break;
        }

        // Generate share link token
        $shareLink = bin2hex(random_bytes(8)); // 16-char hex token
        
        // For assignment mode, auto-start immediately
        $initialStatus = ($mode === 'assign') ? 'started' : 'waiting';
        
        // Handle deadline for assignments
        $deadline = null;
        if ($mode === 'assign' && !empty($settingsInput['deadline'])) {
            $deadline = $settingsInput['deadline'];
        }

        // Duration settings
        $duration = intval($settingsInput['duration_minutes'] ?? 0);
        $settings = json_encode($settingsInput);
        
        $pdo->beginTransaction();
        try {
            // Calculate end time for assignments with duration
            $startedAt = ($initialStatus === 'started') ? date('Y-m-d H:i:s') : null;
            $endedAt = null;
            
            if ($initialStatus === 'started' && $duration > 0) {
                $endedAt = date('Y-m-d H:i:s', strtotime("+$duration minutes"));
            }
            
            $stmt = $pdo->prepare("INSERT INTO `cq_rooms` (`host_id`, `set_id`, `pin_code`, `mode`, `status`, `settings`, `share_link`, `deadline`, `started_at`, `ended_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user['user_id'], $setId, $pinCode, $mode, $initialStatus, $settings, $shareLink, $deadline, $startedAt, $endedAt]);
            $roomId = $pdo->lastInsertId();
            
            // Add room creation event
            pushRoomEvent($pdo, $roomId, 'room_created', ['host_id' => $user['user_id']]);
            
            if ($initialStatus === 'started') {
                pushRoomEvent($pdo, $roomId, 'room_started', ['end_time' => $endedAt]);
            }
            
            $pdo->commit();
            jsonResponse(['success' => true, 'room_id' => $roomId, 'pin_code' => $pinCode, 'share_link' => $shareLink]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Failed to create room: ' . $e->getMessage()], 500);
        }
        break;

    // ==========================================
    // Join Room (Student)
    // ==========================================
    case 'join_room':
        $pinCode = trim($input['pin_code'] ?? '');
        if (empty($pinCode)) {
            jsonResponse(['error' => 'กรุณากรอกรหัส PIN'], 400);
        }
        
        $stmt = $pdo->prepare("SELECT * FROM `cq_rooms` WHERE `pin_code` = ?");
        $stmt->execute([$pinCode]);
        $room = $stmt->fetch();
        
        if (!$room) {
            jsonResponse(['error' => 'ไม่พบห้องสอบนี้ หรือรหัส PIN ไม่ถูกต้อง'], 404);
        }
        
        if ($room['status'] === 'ended') {
            jsonResponse(['error' => 'ห้องสอบนี้สิ้นสุดแล้ว'], 400);
        }
        
        if ($room['host_id'] === $user['user_id']) {
            // Host trying to join own room -> redirect to host view
            jsonResponse(['success' => true, 'room_id' => $room['room_id'], 'is_host' => true]);
        }
        
        $pdo->beginTransaction();
        try {
            // Check if already joined
            $chk = $pdo->prepare("SELECT * FROM `cq_participants` WHERE `room_id` = ? AND `user_id` = ?");
            $chk->execute([$room['room_id'], $user['user_id']]);
            
            if (!$chk->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO `cq_participants` (`room_id`, `user_id`) VALUES (?, ?)");
                $stmt->execute([$room['room_id'], $user['user_id']]);
                
                // Notify host that a new student joined
                pushRoomEvent($pdo, $room['room_id'], 'participant_joined', [
                    'user_id' => $user['user_id'],
                    'username' => $user['username'],
                    'display_name' => $user['display_name'],
                    'avatar_url' => $user['avatar_url']
                ]);
            }
            
            $pdo->commit();
            
            // For assignment mode rooms that are already started, redirect to exam
            $redirectPage = ($room['status'] === 'started') ? 'exam' : 'room_student';
            jsonResponse(['success' => true, 'room_id' => $room['room_id'], 'is_host' => false, 'redirect' => $redirectPage]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Join failed: ' . $e->getMessage()], 500);
        }
        break;

    // ==========================================
    // Join via Share Link
    // ==========================================
    case 'join_by_link':
        $link = trim($input['share_link'] ?? '');
        if (empty($link)) {
            jsonResponse(['error' => 'Invalid link'], 400);
        }
        
        $stmt = $pdo->prepare("SELECT * FROM `cq_rooms` WHERE `share_link` = ?");
        $stmt->execute([$link]);
        $room = $stmt->fetch();
        
        if (!$room) {
            jsonResponse(['error' => 'ลิงก์ไม่ถูกต้องหรือหมดอายุ'], 404);
        }
        
        if ($room['status'] === 'ended') {
            jsonResponse(['error' => 'ห้องสอบนี้สิ้นสุดแล้ว'], 400);
        }
        
        if ($room['host_id'] === $user['user_id']) {
            jsonResponse(['success' => true, 'room_id' => $room['room_id'], 'is_host' => true]);
        }
        
        $pdo->beginTransaction();
        try {
            $chk = $pdo->prepare("SELECT * FROM `cq_participants` WHERE `room_id` = ? AND `user_id` = ?");
            $chk->execute([$room['room_id'], $user['user_id']]);
            
            if (!$chk->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO `cq_participants` (`room_id`, `user_id`) VALUES (?, ?)");
                $stmt->execute([$room['room_id'], $user['user_id']]);
                
                pushRoomEvent($pdo, $room['room_id'], 'participant_joined', [
                    'user_id' => $user['user_id'],
                    'username' => $user['username'],
                    'display_name' => $user['display_name'],
                    'avatar_url' => $user['avatar_url']
                ]);
            }
            
            $pdo->commit();
            $redirectPage = ($room['status'] === 'started') ? 'exam' : 'room_student';
            jsonResponse(['success' => true, 'room_id' => $room['room_id'], 'is_host' => false, 'redirect' => $redirectPage]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Join failed: ' . $e->getMessage()], 500);
        }
        break;

    // ==========================================
    // Update Room Status (Host only)
    // ==========================================
    case 'update_status':
        $roomId = intval($input['room_id'] ?? 0);
        $status = $input['status'] ?? ''; // started, ended
        
        if (!in_array($status, ['started', 'ended'])) {
            jsonResponse(['error' => 'Invalid status'], 400);
        }
        
        $stmt = $pdo->prepare("SELECT * FROM `cq_rooms` WHERE `room_id` = ? AND `host_id` = ?");
        $stmt->execute([$roomId, $user['user_id']]);
        $room = $stmt->fetch();
        
        if (!$room) {
            jsonResponse(['error' => 'Not found or no permission'], 403);
        }
        
        if ($room['status'] === $status || $room['status'] === 'ended') {
            jsonResponse(['success' => true]); // Already in state
        }
        
        $pdo->beginTransaction();
        try {
            if ($status === 'started') {
                $settings = json_decode($room['settings'], true) ?: [];
                $duration = intval($settings['duration_minutes'] ?? 0);
                
                $endTimeVal = null;
                if ($duration > 0) {
                    $endTimeVal = date('Y-m-d H:i:s', strtotime("+$duration minutes"));
                }
                
                if ($endTimeVal) {
                    $pdo->prepare("UPDATE `cq_rooms` SET `status` = 'started', `started_at` = NOW(), `ended_at` = ? WHERE `room_id` = ?")
                        ->execute([$endTimeVal, $roomId]);
                } else {
                    $pdo->prepare("UPDATE `cq_rooms` SET `status` = 'started', `started_at` = NOW() WHERE `room_id` = ?")
                        ->execute([$roomId]);
                }
                
                pushRoomEvent($pdo, $roomId, 'room_started', ['end_time' => $endTimeVal]);
                
            } elseif ($status === 'ended') {
                $pdo->prepare("UPDATE `cq_rooms` SET `status` = 'ended', `ended_at` = NOW() WHERE `room_id` = ?")
                    ->execute([$roomId]);
                
                pushRoomEvent($pdo, $roomId, 'room_ended', []);
            }
            
            $pdo->commit();
            jsonResponse(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Update failed'], 500);
        }
        break;

    // ==========================================
    // Kick Participant (Host only)
    // ==========================================
    case 'kick_participant':
        $roomId = intval($input['room_id'] ?? 0);
        $targetUserId = $input['target_user_id'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM `cq_rooms` WHERE `room_id` = ? AND `host_id` = ?");
        $stmt->execute([$roomId, $user['user_id']]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'No permission'], 403);
        }
        
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM `cq_participants` WHERE `room_id` = ? AND `user_id` = ?")->execute([$roomId, $targetUserId]);
            pushRoomEvent($pdo, $roomId, 'participant_kicked', ['user_id' => $targetUserId]);
            $pdo->commit();
            jsonResponse(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Kick failed'], 500);
        }
        break;

    // ==========================================
    // Check Room Status (Polling fallback)
    // ==========================================
    case 'check_room_status':
        $roomId = intval($input['room_id'] ?? 0);
        if (!$roomId) {
            jsonResponse(['error' => 'Invalid room ID'], 400);
        }
        
        $stmt = $pdo->prepare("SELECT `status` FROM `cq_rooms` WHERE `room_id` = ?");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();
        
        if (!$room) {
            jsonResponse(['error' => 'Room not found'], 404);
        }
        
        jsonResponse(['status' => $room['status']]);
        break;

    case 'cheat_flag':
        $roomId = intval($input['room_id'] ?? 0);
        $flagType = $input['flag_type'] ?? '';
        $details = $input['details'] ?? '';
        
        $chk = $pdo->prepare("SELECT `participant_id` FROM `cq_participants` WHERE `room_id` = ? AND `user_id` = ?");
        $chk->execute([$roomId, $user['user_id']]);
        $part = $chk->fetch();
        
        if ($part && in_array($flagType, ['tab_switch', 'fullscreen_exit', 'copy_paste', 'idle'])) {
            $stmt = $pdo->prepare("INSERT INTO `cq_flags` (`room_id`, `participant_id`, `flag_type`, `details`) VALUES (?, ?, ?, ?)");
            $stmt->execute([$roomId, $part['participant_id'], $flagType, $details]);
            
            // Notify host via websocket
            pushRoomEvent($pdo, $roomId, 'cheat_flag', [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'display_name' => $user['display_name'],
                'flag_type' => $flagType,
                'details' => $details
            ]);
            jsonResponse(['success' => true]);
        } else {
            jsonResponse(['error' => 'Invalid flag or participant'], 400);
        }
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
?>
