<?php
require_once __DIR__ . '/../db.php';

// Server-Sent Events (SSE) endpoint for real-time updates
// Optimized for fast participant updates (Kahoot-like speed)

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable buffering for Nginx
header('Access-Control-Allow-Origin: *');

// Disable output buffering completely
while (ob_get_level()) ob_end_clean();

// Check Auth
if (!isset($_SESSION['user_id'])) {
    echo "event: error\ndata: {\"message\": \"Unauthorized\"}\n\n";
    flush();
    exit;
}
$userId = $_SESSION['user_id'];
$roomId = intval($_GET['room_id'] ?? 0);
$lastEventId = intval($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['last_event_id'] ?? 0);

if (!$roomId) {
    echo "event: error\ndata: {\"message\": \"Invalid room ID\"}\n\n";
    flush();
    exit;
}

// Verify access (host or participant)
$hasAccess = false;
$stmt = $pdo->prepare("SELECT `host_id` FROM `cq_rooms` WHERE `room_id` = ?");
$stmt->execute([$roomId]);
$room = $stmt->fetch();
if ($room && $room['host_id'] === $userId) {
    $hasAccess = true;
} else {
    $chk = $pdo->prepare("SELECT 1 FROM `cq_participants` WHERE `room_id` = ? AND `user_id` = ?");
    $chk->execute([$roomId, $userId]);
    if ($chk->fetch()) $hasAccess = true;
}

if (!$hasAccess) {
    echo "event: error\ndata: {\"message\": \"Forbidden\"}\n\n";
    flush();
    exit;
}

// Ensure session doesn't block other requests
session_write_close();

// Send retry interval (500ms = fast reconnection)
echo "retry: 500\n\n";

// Send initial ping to establish connection
echo ": connected\n\n";
flush();

$timeout = 55; // Longer timeout = fewer reconnections = less overhead
$startTime = time();
$pollInterval = 300000; // 0.3 seconds in microseconds (fast like Kahoot)

while (time() - $startTime < $timeout) {
    if (connection_aborted()) break;

    // Fetch new events in batch
    $stmt = $pdo->prepare("SELECT * FROM `cq_room_events` WHERE `room_id` = ? AND `event_id` > ? ORDER BY `event_id` ASC LIMIT 50");
    $stmt->execute([$roomId, $lastEventId]);
    $events = $stmt->fetchAll();

    if ($events) {
        foreach ($events as $event) {
            echo "id: {$event['event_id']}\n";
            echo "event: {$event['event_type']}\n";
            echo "data: {$event['event_data']}\n\n";
            $lastEventId = $event['event_id'];
        }
        flush();
    } else {
        // Send keep-alive ping every 10 seconds when no events
        static $lastPing = 0;
        $now = time();
        if ($now - $lastPing >= 10) {
            echo ": ping\n\n";
            flush();
            $lastPing = $now;
        }
    }

    // Fast polling interval (300ms)
    usleep($pollInterval);
}

// Send reconnect instruction
echo "event: ping\ndata: reconnect\n\n";
flush();
?>