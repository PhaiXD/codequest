<?php
require_once 'db.php';

// Redirect to appropriate page
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
?>