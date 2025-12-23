<?php
session_start();

// Clear all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Regenerate session ID
session_regenerate_id(true);

// Redirect to the login page
header("Location: ./index.php");
exit();
?>