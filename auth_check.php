<?php
// 1. Safe Session Start: Only start if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Security Check: User must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 3. Role Check (Optional)
if (isset($required_role) && $_SESSION['role'] !== $required_role) {
    // If role is required (e.g., 'admin') and user is 'driver', kick them out
    header("Location: login.php?error=unauthorized");
    exit();
}
?>