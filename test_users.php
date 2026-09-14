<?php
require_once 'db.php';
$stmt = $pdo->query('SELECT username, role FROM cq_users');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo '<pre>';
print_r($users);
echo '</pre>';
