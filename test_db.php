<?php
require_once 'db.php';
try {
    $stmt = $pdo->query("DESCRIBE cq_room_events");
    $schema = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($schema);
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
