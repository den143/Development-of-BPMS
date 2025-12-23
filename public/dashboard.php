<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/models/Event.php';

$active_event = Event::getActiveByUser($_SESSION['user_id']);
$event_title = $active_event['name'] ?? "No Active Event";
$event_venue = $active_event['venue'] ?? "No Venue Selected";
$event_date_str  = $active_event['event_date'] ?? null;

// --- LOGIC: Calculate Days To Go ---
$days_to_go = 0;
if ($event_date_str) {
    $event_date = new DateTime($event_date_str);
    $today = new DateTime();
    
    // Only count if event is in the future
    if ($event_date > $today) {
        $interval = $today->diff($event_date);
        $days_to_go = $interval->days;
    }
}

// --- LOGIC: Fetch Counts (Mock Data for now) ---
// TODO: Replace 0 with real DB counts once we create those tables
$count_contestants = 0; 
$count_judges = 0;
$count_rounds = 0;

// Capture session messages
$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['show_modal']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Manager Dashboard</title>
    <link rel="stylesheet" href="./assets/css/style.css">
    
    <style>
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 25px; width: 400px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); text-align: center; }
        .modal-content input { width: 90%; margin: 10px 0; padding: 10px; border:1px solid #ddd; border-radius:4px; }
        .btn-create { background-color: #28a745; color: white; border: none; padding: 10px 20px; cursor: pointer; width: 100%; border-radius:4px; }
    </style>
</head>
<body>

    <div class="main-wrapper">
        
        <?php require_once __DIR__ . '/../app/views/partials/sidebar.php'; ?>

        <div class="content-area">
            
            <div class="navbar">
                <div class="navbar-title">Event Manager</div>
            </div>

            <div class="container">

                <div id="toast-container" class="toast-container"></div>

                <div class="event-header">
                    <h1><?= htmlspecialchars($event_title) ?></h1>
                    <div class="event-meta">
                        <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($event_venue) ?></span>
                        <span style="margin: 0 10px;">|</span>
                        <span><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($event_date_str ?? 'TBA') ?></span>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-female"></i></div>
                        <div class="stat-info">
                            <h3><?= $count_contestants ?></h3>
                            <p>Contestants</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-gavel"></i></div>
                        <div class="stat-info">
                            <h3><?= $count_judges ?></h3>
                            <p>Judges</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-list-ol"></i></div>
                        <div class="stat-info">
                            <h3><?= $count_rounds ?></h3>
                            <p>Rounds</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stat-info">
                            <h3><?= $days_to_go ?></h3>
                            <p>Days to Go</p>
                        </div>
                    </div>
                </div>

                <div class="dashboard-grid">
                    
                    <div class="card-section">
                        <div class="card-title">Setup Progress</div>
                        <ul class="checklist">
                            <li class="checklist-item">
                                <div class="check-icon done"><i class="fas fa-check"></i></div>
                                <div class="task-content">
                                    <strong>Create Event</strong>
                                    <span>Event details and venue are set.</span>
                                </div>
                                <a href="settings.php" class="btn-action">View</a>
                            </li>

                            <li class="checklist-item">
                                <div class="check-icon <?= $count_contestants > 0 ? 'done' : 'pending' ?>">
                                    <i class="fas <?= $count_contestants > 0 ? 'fa-check' : 'fa-exclamation' ?>"></i>
                                </div>
                                <div class="task-content">
                                    <strong>Register Contestants</strong>
                                    <span><?= $count_contestants > 0 ? 'Contestants registered.' : 'No contestants added yet.' ?></span>
                                </div>
                                <a href="contestants.php" class="btn-action">Manage</a>
                            </li>

                            <li class="checklist-item">
                                <div class="check-icon <?= $count_judges > 0 ? 'done' : 'pending' ?>">
                                    <i class="fas <?= $count_judges > 0 ? 'fa-check' : 'fa-exclamation' ?>"></i>
                                </div>
                                <div class="task-content">
                                    <strong>Register Judges</strong>
                                    <span><?= $count_judges > 0 ? 'Judges ready.' : 'Recruit judges for scoring.' ?></span>
                                </div>
                                <a href="judges.php" class="btn-action">Manage</a>
                            </li>

                             <li class="checklist-item">
                                <div class="check-icon pending">
                                    <i class="fas fa-exclamation"></i>
                                </div>
                                <div class="task-content">
                                    <strong>Setup Criteria & Segments</strong>
                                    <span>Define how contestants will be scored.</span>
                                </div>
                                <a href="criteria.php" class="btn-action">Setup</a>
                            </li>
                        </ul>
                    </div>

                    <div class="card-section">
                        <div class="card-title">Quick Actions</div>
                        <p style="color: #6b7280; font-size: 14px;">Shortcuts will appear here once setup is complete.</p>
                        <button style="margin-top: 20px; padding: 10px; width: 100%; background: #e5e7eb; border: none; border-radius: 6px; color: #9ca3af; cursor: not-allowed;" disabled>Open Score Sheet</button>
                    </div>

                </div>

            </div>
        </div>
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
        // Modal Logic
        <?php if (isset($_SESSION['show_modal']) && $_SESSION['show_modal'] === true): ?>
            document.getElementById('createEventModal').style.display = 'flex';
        <?php endif; ?>

        // Toast Notification Logic
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icon = type === 'success' ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-exclamation-circle"></i>';
            toast.innerHTML = `${icon} <span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => {
                toast.style.animation = 'fadeOut 0.5s ease-out forwards';
                setTimeout(() => { toast.remove(); }, 500);
            }, 3000);
        }

        <?php if ($success): ?> showToast("<?= htmlspecialchars($success) ?>", "success"); <?php endif; ?>
        <?php if ($error): ?> showToast("<?= htmlspecialchars($error) ?>", "error"); <?php endif; ?>
    </script>

</body>
</html>