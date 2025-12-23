<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');

require_once __DIR__ . '/../app/models/Event.php';

$active_event = Event::getActiveByUser($_SESSION['user_id']);
$event_title = $active_event['name'] ?? "No Active Event";

// Capture session messages
$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['show_modal']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Event Manager Dashboard</title>
    <style>
        body { font-family: sans-serif; margin: 0; padding: 0; }
        .navbar { background: #333; color: white; padding: 1rem; display: flex; justify-content: space-between; }
        .navbar a { color: white; text-decoration: none; padding: 0 10px; }
        .container { padding: 20px; }
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 25px; width: 400px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); text-align: center; }
        .modal-content input { width: 90%; margin: 10px 0; padding: 10px; }
        .btn-create { background-color: #28a745; color: white; border: none; padding: 10px 20px; cursor: pointer; width: 100%; }
        .success { color: green; margin-bottom: 10px; text-align: center; }
        .error { color: red; margin-bottom: 10px; text-align: center; }
    </style>
</head>
<body>

    <div class="navbar">
        <h3>BPMS - <?= htmlspecialchars($event_title) ?></h3>
        <div>
            <a href="./settings.php">Settings</a>
            <a href="./logout.php" onclick="return confirmLogout();">Logout</a>
        </div>
    </div>

    <div class="container">
        <h1>Dashboard</h1>

        <?php if ($success): ?>
            <p class="success"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <p>Your events will appear here.</p>
    </div>

    <div id="createEventModal" class="modal-overlay">
        <div class="modal-content">
            <h2>Create New Event</h2>
            <p>Please setup your event details to proceed.</p>
            <form action="../api/event.php" method="POST">
                <input type="text" name="event_name" placeholder="Event Name (e.g., Miss Universe 2025)" required>
                <input type="date" name="event_date" required>
                <input type="text" name="venue" placeholder="Venue" required>
                <button type="submit" class="btn-create">Create Event</button>
            </form>
        </div>
    </div>

    <script>
        <?php if (isset($_SESSION['show_modal']) && $_SESSION['show_modal'] === true): ?>
            document.getElementById('createEventModal').style.display = 'flex';
        <?php endif; ?>

        function confirmLogout() {
            return confirm("Are you sure you want to logout?");
        }
    </script>

</body>
</html>
