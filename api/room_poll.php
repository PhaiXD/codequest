<?php
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['error' => 'Unauthorized'], 401);
    exit;
}
$userId = $_SESSION['user_id'];
$roomId = intval($_GET['room_id'] ?? 0);
$lastEventId = intval($_GET['last_event_id'] ?? 0);

if (!$roomId) {
    jsonResponse(['error' => 'Invalid room ID'], 400);
    exit;
}

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
    jsonResponse(['error' => 'Forbidden'], 403);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM `cq_room_events` WHERE `room_id` = ? AND `event_id` > ? ORDER BY `event_id` ASC");
$stmt->execute([$roomId, $lastEventId]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$decodedEvents = array_map(function($e) {
    $e['event_data'] = json_decode($e['event_data'], true);
    return $e;
}, $events);

jsonResponse(['events' => $decodedEvents]);
