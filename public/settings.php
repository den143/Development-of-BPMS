<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');

require_once __DIR__ . '/../app/models/Event.php';
require_once __DIR__ . '/../app/config/database.php';

$u_id = $_SESSION['user_id'];

// Handle event switching
if (isset($_GET['open_id'])) {
    $open_id = (int) $_GET['open_id']; // cast to integer to avoid SQL injection
    $stmt1 = $conn->prepare("UPDATE events SET status = 'Inactive' WHERE user_id = ?");
    $stmt1->bind_param("i", $u_id);
    $stmt1->execute();

    $stmt2 = $conn->prepare("UPDATE events SET status = 'Active' WHERE id = ?");
    $stmt2->bind_param("i", $open_id);
    $stmt2->execute();

    $_SESSION['success'] = "Active event switched successfully.";
    header("Location: ./dashboard.php");
    exit();
}

// Fetch event history
$stmt = $conn->prepare("SELECT * FROM events WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $u_id);
$stmt->execute();
$result = $stmt->get_result();
$events = $result->fetch_all(MYSQLI_ASSOC);

// Capture messages
$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Settings - Event History</title>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        .history-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .history-table th, .history-table td { border: 1px solid #ddd; padding: 10px; }
        .active-row { background-color: #d4edda; font-weight: bold; }
        .btn-new { background: #e91e63; color: white; padding: 10px; text-decoration: none; border-radius: 5px; }
        .success { color: green; margin-bottom: 10px; }
        .error { color: red; margin-bottom: 10px; }
    </style>
</head>
<body>
    <a href="./dashboard.php">← Back to Dashboard</a>
    <h1>Settings</h1>

    <?php if ($success): ?>
        <p class="success"><?= htmlspecialchars($success) ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <a href="#" class="btn-new" onclick="confirmNewEvent()">Create New Event</a>

    <h3>Event History</h3>
    <table class="history-table">
        <tr>
            <th>Event Name</th>
            <th>Date</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        <?php foreach ($events as $row): 
            $class = ($row['status'] === 'Active') ? 'active-row' : '';
        ?>
            <tr class="<?= $class ?>">
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= htmlspecialchars($row['event_date']) ?></td>
                <td><?= htmlspecialchars($row['status']) ?></td>
                <td>
                    <?php if ($row['status'] === 'Inactive'): ?>
                       <a href="#" onclick="return confirmOpenEvent(<?= $row['id'] ?>);">Open Event</a>
                    <?php else: ?>
                      Current Active
                    <?php endif; ?>

                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <div id="newModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center;">
        <div style="background:white; padding:20px; border-radius:10px;">
            <h2>Create New Event</h2>
            <form action="../api/event.php" method="POST">
                <input type="text" name="event_name" placeholder="Event Name" required><br><br>
                <input type="date" name="event_date" required><br><br>
                <input type="text" name="venue" placeholder="Venue" required><br><br>
                <button type="submit">Confirm and Create</button>
                <button type="button" onclick="document.getElementById('newModal').style.display='none'">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function confirmNewEvent() {
            if (confirm("Creating a New Event will make the current event inactive. Continue?")) {
               document.getElementById('newModal').style.display = 'flex';
            }    
        }

        function confirmOpenEvent(eventId) {
         if (confirm("Are you sure you want to switch to this event?")) {
           // Redirect to settings.php with open_id, then immediately go to dashboard after PHP updates
           window.location.href = "settings.php?open_id=" + eventId + "&redirect=dashboard";
         }
          return false; // Prevent default link click
        }
    </script>
</body>
</html>
