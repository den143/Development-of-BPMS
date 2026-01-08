<?php
$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "bpms_db";

// Suppress default warnings to handle errors manually
mysqli_report(MYSQLI_REPORT_OFF);

try {
    $conn = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

    if (!$conn) {
        throw new Exception(mysqli_connect_error());
    }

    // Ensure proper character encoding
    if (!mysqli_set_charset($conn, "utf8mb4")) {
         throw new Exception(mysqli_error($conn));
    }

} catch (Exception $e) {
    // Log error to server log (not displayed to user)
    error_log("Database Connection Error: " . $e->getMessage());
    // Show a generic safe message
    die("System Error: Unable to connect to the database. Please try again later.");
}
