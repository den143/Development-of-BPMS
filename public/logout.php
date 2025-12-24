<?php
session_start();

// CORRECT PATH: Go up one level (..) to root, then into app/config/database.php
require_once '../app/config/database.php';

// Check if it is an Audience member logging out
if (isset($_SESSION['role']) && $_SESSION['role'] === 'Audience' && isset($_SESSION['user_id'])) {
    $ticket_id = $_SESSION['user_id'];
    
    // Mark ticket as USED so it cannot be used to login again
    // Note: Ensure your database variable is named $conn. 
    // If database.php uses $pdo or $db, update this variable below.
    if (isset($conn)) {
        $stmt = $conn->prepare("UPDATE tickets SET status = 'Used' WHERE id = ?");
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
    }
}

// Destroy session
session_unset();
session_destroy();

header("Location: index.php");
exit();
?>