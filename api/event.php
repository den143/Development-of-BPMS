<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();

require_once __DIR__ . '/../app/models/Event.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate inputs
    $name  = trim($_POST['event_name'] ?? '');
    $date  = trim($_POST['event_date'] ?? '');
    $venue = trim($_POST['venue'] ?? '');

    if (empty($name) || empty($date) || empty($venue)) {
        $_SESSION['error'] = "All fields are required.";
        header("Location: ../public/dashboard.php");
        exit();
    }

    // Optional: validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $_SESSION['error'] = "Invalid date format.";
        header("Location: ../public/dashboard.php");
        exit();
    }

    // Create event
    $success = Event::create($_SESSION['user_id'], $name, $date, $venue);

    if ($success) {
        $_SESSION['show_modal'] = false;
        $_SESSION['success'] = "Event created successfully.";
    } else {
        $_SESSION['error'] = "Failed to create event. Please try again.";
    }

    // Use relative path, not absolute
    header("Location: ../public/dashboard.php");
    exit();
}
