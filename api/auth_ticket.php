<?php
session_start();

// FIX 1: Point to the correct database file location
// We go up one level (..) then into app/config/
require_once '../app/config/database.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $code = trim($_POST['ticket_code']);

    // FIX 2: Update redirect to point to the 'public' folder
    if (empty($code)) {
        header("Location: ../public/index.php?error=Ticket code required");
        exit();
    }

    // Check ticket exists
    // Note: We use $conn because that is the variable name defined in database.php
    $stmt = $conn->prepare("SELECT id, status, voted_contestant_id FROM tickets WHERE code = ?");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $ticket = $result->fetch_assoc();

        // If status is 'Used', they cannot login
        if ($ticket['status'] === 'Used') {
            header("Location: ../public/index.php?error=This ticket has expired.");
            exit();
        }

        // Login Success
        $_SESSION['user_id'] = $ticket['id'];
        $_SESSION['role'] = 'Audience';
        $_SESSION['ticket_code'] = $code;
        
        // FIX 3: Update redirect to audience_dashboard inside 'public'
        header("Location: ../public/audience_dashboard.php");
        exit();

    } else {
        header("Location: ../public/index.php?error=Invalid Ticket Code");
        exit();
    }
}
?>