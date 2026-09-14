<?php
session_start();

// ========================================
// CodeQuest — Database Configuration
// ========================================
$db_host = 'localhost';
$db_name = 'ac54010_db';
$db_user = 'ac54010_db';
$db_pass = 'Assumption1885ac54010';

// ========================================
// Require API Configuration
// ========================================
require_once __DIR__ . '/config.php';

// ========================================
// Database Connection
// ========================================
try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    try {
        $pdo_temp = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
        $pdo_temp->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo_temp = null;
        $pdo = new PDO(
            "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
            $db_user,
            $db_pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    } catch (PDOException $e2) {
        die("<div style='font-family:sans-serif;padding:40px;color:#FF5252;background:#0a0a0f;min-height:100vh;'>
            <h2>Database Connection Error</h2>
            <p>ไม่สามารถเชื่อมต่อ MySQL ได้</p>
            <p style='color:#888;'>Error: " . htmlspecialchars($e2->getMessage()) . "</p>
        </div>");
    }
}

// ========================================
// Generate unique user_id (ULID-like 26 chars)
// ========================================
function generateUserId() {
    $chars = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $time = intval(microtime(true) * 1000);
    $timeStr = '';
    for ($i = 9; $i >= 0; $i--) {
        $timeStr = $chars[$time % 32] . $timeStr;
        $time = intval($time / 32);
    }
    $randStr = '';
    for ($i = 0; $i < 16; $i++) {
        $randStr .= $chars[random_int(0, 31)];
    }
    return $timeStr . $randStr;
}

// ========================================
// Generate PIN code (6 digits)
// ========================================
function generatePinCode($pdo) {
    $maxAttempts = 100;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `cq_rooms` WHERE `pin_code` = ? AND `status` != 'ended'");
        $stmt->execute([$pin]);
        if ($stmt->fetchColumn() == 0) {
            return $pin;
        }
    }
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// ========================================
// Create Tables
// ========================================

// Users
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `cq_users` (
        `user_id` VARCHAR(26) NOT NULL PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `display_name` VARCHAR(100) DEFAULT NULL,
        `email` VARCHAR(100) DEFAULT NULL,
        `password` VARCHAR(255) NOT NULL DEFAULT '',
        `google_id` VARCHAR(255) DEFAULT NULL,
        `avatar_url` VARCHAR(500) DEFAULT NULL,
        `role` ENUM('user','admin') DEFAULT 'user',
        `theme` ENUM('light','dark') DEFAULT 'dark',
        `total_score` INT DEFAULT 0,
        `practice_solved` INT DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Problem Sets
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `cq_problem_sets` (
        `set_id` INT AUTO_INCREMENT PRIMARY KEY,
        `owner_id` VARCHAR(26) NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `visibility` ENUM('public','private') DEFAULT 'private',
        `language` VARCHAR(50) DEFAULT 'python',
        `forked_from` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`owner_id`) REFERENCES `cq_users`(`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Problems
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `cq_problems` (
        `problem_id` INT AUTO_INCREMENT PRIMARY KEY,
        `set_id` INT NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `description` TEXT NOT NULL,
        `difficulty` ENUM('Easy','Medium','Hard') DEFAULT 'Easy',
        `order_index` INT DEFAULT 0,
        `time_limit_ms` INT DEFAULT 5000,
        `memory_limit_mb` INT DEFAULT 256,
        `starter_code` TEXT DEFAULT NULL,
        `solution_code` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`set_id`) REFERENCES `cq_problem_sets`(`set_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Test Cases
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `cq_testcases` (
        `testcase_id` INT AUTO_INCREMENT PRIMARY KEY,
        `problem_id` INT NOT NULL,
        `input_data` TEXT NOT NULL,
        `expected_output` TEXT NOT NULL,
        `is_sample` TINYINT(1) DEFAULT 0,
        `order_index` INT DEFAULT 0,
        `hover_mapping` TEXT DEFAULT NULL,
        FOREIGN KEY (`problem_id`) REFERENCES `cq_problems`(`problem_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Alter table to add hover_mapping if it doesn't exist
try {
    $pdo->exec("ALTER TABLE `cq_testcases` ADD COLUMN `hover_mapping` TEXT DEFAULT NULL");
} catch (PDOException $e) {
    // Ignore error if column already exists
}

// Rooms
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `cq_rooms` (
        `room_id` INT AUTO_INCREMENT PRIMARY KEY,
        `host_id` VARCHAR(26) NOT NULL,
        `set_id` INT NOT NULL,
        `pin_code` VARCHAR(8) DEFAULT NULL UNIQUE,
        `mode` ENUM('realtime','assign') DEFAULT 'realtime',
        `status` ENUM('waiting','started','ended') DEFAULT 'waiting',
        `enable_screen_share` TINYINT(1) DEFAULT 1,
        `enable_tab_detection` TINYINT(1) DEFAULT 1,
        `enable_leaderboard` TINYINT(1) DEFAULT 1,
        `enable_fullscreen` TINYINT(1) DEFAULT 1,
        `time_limit_minutes` INT DEFAULT 0,
        `deadline` DATETIME DEFAULT NULL,
        `share_link` VARCHAR(100) DEFAULT NULL,
        `max_attempts` INT DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `started_at` DATETIME DEFAULT NULL,
        `ended_at` DATETIME DEFAULT NULL,
        FOREIGN KEY (`host_id`) REFERENCES `cq_users`(`user_id`) ON DELETE CASCADE,
        FOREIGN KEY (`set_id`) REFERENCES `cq_problem_sets`(`set_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Participants
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `cq_participants` (
        `participant_id` INT AUTO_INCREMENT PRIMARY KEY,
        `room_id` INT NOT NULL,
        `user_id` VARCHAR(26) NOT NULL,
        `display_name` VARCHAR(100) DEFAULT NULL,
        `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `started_at` DATETIME DEFAULT NULL,
        `finished_at` DATETIME DEFAULT NULL,
        `total_score` INT DEFAULT 0,
        `total_time_ms` BIGINT DEFAULT 0,
        `tab_switch_count` INT DEFAULT 0,
        `is_flagged` TINYINT(1) DEFAULT 0,
        FOREIGN KEY (`room_id`) REFERENCES `cq_rooms`(`room_id`) ON DELETE CASCADE,
        FOREIGN KEY (`user_id`) REFERENCES `cq_users`(`user_id`) ON DELETE CASCADE,
        UNIQUE KEY `unique_room_user` (`room_id`, `user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Submissions
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `cq_submissions` (
        `submission_id` INT AUTO_INCREMENT PRIMARY KEY,
        `room_id` INT DEFAULT NULL,
        `participant_id` INT DEFAULT NULL,
        `user_id` VARCHAR(26) NOT NULL,
        `problem_id` INT NOT NULL,
        `code` TEXT NOT NULL,
        `language` VARCHAR(50) DEFAULT 'python',
        `status` ENUM('pending','running','accepted','wrong_answer','runtime_error','time_limit','compilation_error') DEFAULT 'pending',
        `passed_tests` INT DEFAULT 0,
        `total_tests` INT DEFAULT 0,
        `execution_time_ms` INT DEFAULT 0,
        `score` INT DEFAULT 0,
        `error_output` TEXT DEFAULT NULL,
        `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `cq_users`(`user_id`) ON DELETE CASCADE,
        FOREIGN KEY (`problem_id`) REFERENCES `cq_problems`(`problem_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Flags (anti-cheat events)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `cq_flags` (
        `flag_id` INT AUTO_INCREMENT PRIMARY KEY,
        `room_id` INT NOT NULL,
        `participant_id` INT NOT NULL,
        `flag_type` ENUM('tab_switch','fullscreen_exit','copy_paste','idle') NOT NULL,
        `details` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`room_id`) REFERENCES `cq_rooms`(`room_id`) ON DELETE CASCADE,
        FOREIGN KEY (`participant_id`) REFERENCES `cq_participants`(`participant_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Password Resets
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `cq_password_resets` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `email` VARCHAR(100) NOT NULL,
        `token` VARCHAR(64) NOT NULL UNIQUE,
        `expires_at` DATETIME NOT NULL,
        `used` TINYINT(1) DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// WebRTC Signaling
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `cq_signaling` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `room_id` INT NOT NULL,
        `from_user` VARCHAR(26) NOT NULL,
        `to_user` VARCHAR(26) NOT NULL,
        `type` ENUM('offer','answer','ice') NOT NULL,
        `payload` TEXT NOT NULL,
        `is_read` TINYINT(1) DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Room Events (for SSE polling)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `cq_room_events` (
        `event_id` INT AUTO_INCREMENT PRIMARY KEY,
        `room_id` INT NOT NULL,
        `event_type` VARCHAR(50) NOT NULL,
        `event_data` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_room_events` (`room_id`, `event_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ========================================
// Seed admin user
// ========================================
$userCount = $pdo->query("SELECT COUNT(*) FROM `cq_users`")->fetchColumn();
if ($userCount == 0) {
    $stmt = $pdo->prepare("INSERT INTO `cq_users` (`user_id`, `username`, `display_name`, `email`, `password`, `role`) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([generateUserId(), 'admin', 'Administrator', 'admin@codequest.dev', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
}

// ========================================
// Helper Functions
// ========================================

/**
 * Get currently logged-in user data
 */
function getCurrentUser($pdo) {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM `cq_users` WHERE `user_id` = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * Check if user is logged in, redirect to login if not
 */
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Check if user is admin
 */
function isAdmin($pdo) {
    $user = getCurrentUser($pdo);
    return $user && $user['role'] === 'admin';
}

/**
 * Get user initials for avatar
 */
function getInitials($name) {
    if (empty($name)) return '??';
    $parts = explode(' ', $name);
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

/**
 * Send JSON response
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Send email via Resend API
 */
function sendEmail($to, $subject, $html) {
    global $resend_api_key, $resend_from;
    
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $resend_api_key,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'from' => $resend_from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode >= 200 && $httpCode < 300;
}

/**
 * Grader Server Configuration
 * Change GRADER_URL to your Cloudflare Tunnel URL once set up
 */
define('GRADER_URL', 'https://remote-alexandria-bias-into.trycloudflare.com'); // Cloudflare Tunnel URL to GCP VM
define('GRADER_SECRET', 'cq-grader-secret-2024');

/**
 * Execute code via Local Grader Server (single run with stdin)
 */
function executeCode($language, $code, $stdin = '', $timeLimit = 5, $memoryLimit = 128) {
    $payload = [
        'language' => $language,
        'code' => $code,
        'stdin' => $stdin,
        'timeLimit' => $timeLimit,
        'memoryLimit' => $memoryLimit,
    ];
    
    $ch = curl_init(GRADER_URL . '/api/execute');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Grader-Key: ' . GRADER_SECRET,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeLimit + 10); // extra buffer
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['success' => false, 'error' => 'Grader server unavailable: ' . $curlError];
    }
    
    if ($httpCode !== 200) {
        return ['success' => false, 'error' => 'Grader server error (HTTP ' . $httpCode . ')'];
    }
    
    $result = json_decode($response, true);
    if (!$result) {
        return ['success' => false, 'error' => 'Invalid response from grader'];
    }
    
    if (isset($result['error'])) {
        return ['success' => false, 'error' => $result['error']];
    }
    
    return [
        'success' => $result['success'] ?? false,
        'stdout' => $result['stdout'] ?? '',
        'stderr' => $result['stderr'] ?? '',
        'exit_code' => $result['exit_code'] ?? -1,
        'status' => $result['status'] ?? 'unknown',
    ];
}

/**
 * Grade code against multiple testcases via Local Grader Server (batch)
 * Returns: { status, passed_cases, total_cases, error_output, failed_sample }
 */
function gradeCode($language, $code, $testcases, $timeLimit = 5, $memoryLimit = 128) {
    $tcPayload = [];
    foreach ($testcases as $tc) {
        $tcPayload[] = [
            'input' => $tc['input_data'] ?? '',
            'expected' => $tc['expected_output'] ?? '',
            'is_sample' => (bool)($tc['is_sample'] ?? false),
        ];
    }
    
    $payload = [
        'language' => $language,
        'code' => $code,
        'testcases' => $tcPayload,
        'timeLimit' => $timeLimit,
        'memoryLimit' => $memoryLimit,
    ];
    
    $ch = curl_init(GRADER_URL . '/api/grade');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Grader-Key: ' . GRADER_SECRET,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, ($timeLimit * count($testcases)) + 30);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['status' => 'error', 'error_output' => 'Grader server unavailable: ' . $curlError, 'passed_cases' => 0, 'total_cases' => count($testcases)];
    }
    
    if ($httpCode !== 200) {
        return ['status' => 'error', 'error_output' => 'Grader server error (HTTP ' . $httpCode . ')', 'passed_cases' => 0, 'total_cases' => count($testcases)];
    }
    
    $result = json_decode($response, true);
    if (!$result) {
        return ['status' => 'error', 'error_output' => 'Invalid response from grader', 'passed_cases' => 0, 'total_cases' => count($testcases)];
    }
    
    return $result;
}

/**
 * Add room event for SSE
 */
function addRoomEvent($pdo, $roomId, $eventType, $eventData = null) {
    $stmt = $pdo->prepare("INSERT INTO `cq_room_events` (`room_id`, `event_type`, `event_data`) VALUES (?, ?, ?)");
    $stmt->execute([$roomId, $eventType, $eventData ? json_encode($eventData) : null]);
}

/**
 * Get user theme preference
 */
function getUserTheme($pdo) {
    $user = getCurrentUser($pdo);
    if ($user) {
        return $user['theme'] ?? 'dark';
    }
    return $_COOKIE['cq_theme'] ?? 'dark';
}

function pushRoomEvent($pdo, $roomId, $eventType, $eventData = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO `cq_room_events` (`room_id`, `event_type`, `event_data`) VALUES (?, ?, ?)");
        $dataStr = $eventData !== null ? (is_string($eventData) ? $eventData : json_encode($eventData)) : null;
        $stmt->execute([$roomId, $eventType, $dataStr]);
        
        // Also broadcast via WebSocket (non-blocking, fire-and-forget)
        notifyRoomWs($roomId, $eventType, $eventData);
        
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Notify room clients via WebSocket (through Node.js grader server)
 * This sends a POST to the grader server which broadcasts to all connected WS clients.
 * Non-blocking: failures are silently ignored to not break the main flow.
 */
function notifyRoomWs($roomId, $eventType, $eventData = null, $excludeUserId = null) {
    $payload = [
        'room_id' => intval($roomId),
        'event_type' => $eventType,
        'event_data' => $eventData ?? new \stdClass(),
    ];
    if ($excludeUserId) {
        $payload['exclude_user_id'] = $excludeUserId;
    }
    
    $ch = curl_init(GRADER_URL . '/api/room-event');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Grader-Key: ' . GRADER_SECRET,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // Short timeout — don't block
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Silent — don't throw errors if grader is down
    return ($httpCode >= 200 && $httpCode < 300);
}
?>