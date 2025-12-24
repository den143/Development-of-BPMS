<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');

require_once __DIR__ . '/../app/models/Event.php';
require_once __DIR__ . '/../app/config/database.php';

$u_id = $_SESSION['user_id'];
$active_tab = $_GET['tab'] ?? 'event_details'; // Default tab

// --- 1. FETCH ACTIVE EVENT ---
$active_evt_query = $conn->prepare("SELECT * FROM events WHERE user_id = ? AND status = 'Active' LIMIT 1");
$active_evt_query->bind_param("i", $u_id);
$active_evt_query->execute();
$active_event = $active_evt_query->get_result()->fetch_assoc();

// --- 2. FETCH TICKET DATA (If Active Event Exists) ---
$tickets = [];
$total_unused = 0;
$total_used = 0;

if ($active_event) {
    $event_id = $active_event['id'];
    
    // Fetch Ticket Stats
    $res = $conn->query("SELECT status, COUNT(*) as count FROM tickets WHERE event_id = $event_id GROUP BY status");
    while($row = $res->fetch_assoc()) {
        if ($row['status'] == 'Unused') $total_unused = $row['count'];
        if ($row['status'] == 'Used') $total_used = $row['count'];
    }

    // Fetch Recent Tickets (Limit 100 for display performance)
    $tickets = $conn->query("SELECT * FROM tickets WHERE event_id = $event_id ORDER BY created_at DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
}

// --- 3. HANDLE FORM SUBMISSIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // A. UPDATE ACTIVE EVENT DETAILS
    if (isset($_POST['update_event_details'])) {
        $eid = (int)$_POST['event_id'];
        $name = trim($_POST['event_name']);
        $date = $_POST['event_date'];
        $venue = trim($_POST['venue']);

        $stmt = $conn->prepare("UPDATE events SET name=?, event_date=?, venue=? WHERE id=? AND user_id=?");
        $stmt->bind_param("sssii", $name, $date, $venue, $eid, $u_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Event details updated successfully.";
        } else {
            $_SESSION['error'] = "Failed to update event.";
        }
        header("Location: settings.php?tab=event_details");
        exit();
    }

    // B. UPDATE MY ACCOUNT
    if (isset($_POST['update_profile'])) {
        $name = trim($_POST['my_name']);
        $current_pass = $_POST['current_password'];
        $new_pass = trim($_POST['my_password']);

        // Verify Current Password
        $verifyStmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $verifyStmt->bind_param("i", $u_id);
        $verifyStmt->execute();
        $stored_hash = $verifyStmt->get_result()->fetch_assoc()['password'];

        if (!password_verify($current_pass, $stored_hash)) {
            $_SESSION['error'] = "Incorrect current password. Changes NOT saved.";
            header("Location: settings.php?tab=account");
            exit();
        }

        // Proceed with Update
        if (!empty($new_pass)) {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET name=?, password=? WHERE id=?");
            $stmt->bind_param("ssi", $name, $hashed, $u_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name=? WHERE id=?");
            $stmt->bind_param("si", $name, $u_id);
        }

        if ($stmt->execute()) {
            $_SESSION['success'] = "Account updated successfully.";
            $_SESSION['name'] = $name;
        } else {
            $_SESSION['error'] = "Failed to update account.";
        }
        header("Location: settings.php?tab=account");
        exit();
    }
}

// --- 4. HANDLE SWITCH EVENT (GET) ---
if (isset($_GET['open_id'])) {
    $open_id = (int) $_GET['open_id']; 
    $conn->begin_transaction();
    try {
        $conn->query("UPDATE events SET status = 'Inactive' WHERE user_id = $u_id");
        $conn->query("UPDATE events SET status = 'Active' WHERE id = $open_id");
        $conn->commit();
        $_SESSION['success'] = "Event switched successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Failed to switch event.";
    }
    header("Location: ./dashboard.php");
    exit();
}

// Fetch All Events for History Tab
$hist_stmt = $conn->prepare("SELECT * FROM events WHERE user_id = ? ORDER BY created_at DESC");
$hist_stmt->bind_param("i", $u_id);
$hist_stmt->execute();
$all_events = $hist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch My Profile Data
$me_stmt = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
$me_stmt->bind_param("i", $u_id);
$me_stmt->execute();
$me = $me_stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings - BPMS</title>
    <link rel="stylesheet" href="./assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* TABS CONTAINER */
        .settings-container { display: flex; gap: 30px; align-items: flex-start; }
        
        /* LEFT SIDE: VERTICAL TABS */
        .settings-sidebar {
            width: 250px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            flex-shrink: 0;
        }
        .tab-btn {
            display: block;
            width: 100%;
            padding: 15px 20px;
            text-align: left;
            background: none;
            border: none;
            border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
            font-size: 14px;
            color: #6b7280;
            font-weight: 600;
            transition: all 0.2s;
            text-decoration: none;
            display: flex; align-items: center; gap: 10px;
        }
        .tab-btn:hover { background-color: #f9fafb; color: #374151; }
        .tab-btn.active { background-color: #fffbeb; color: #F59E0B; border-left: 4px solid #F59E0B; }
        .tab-btn i { width: 20px; text-align: center; }

        /* RIGHT SIDE: CONTENT */
        .settings-content { flex-grow: 1; }
        
        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); display: none; }
        .card.active { display: block; animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        .card-header { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #f3f4f6; display:flex; justify-content:space-between; align-items:center; }
        .card-header h3 { font-size: 18px; color: #111827; margin:0; }
        .card-header p { font-size: 13px; color: #6b7280; margin-top: 5px; margin-bottom:0; }

        /* FORMS */
        .form-group { margin-bottom: 20px; position: relative; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 600; color: #374151; font-size: 13px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
        .btn-save { background-color: #F59E0B; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .btn-save:hover { background-color: #d97706; }

        /* HISTORY TABLE REUSE */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 15px; text-align: left; border-bottom: 1px solid #f3f4f6; }
        .data-table th { background: #f9fafb; font-size: 12px; text-transform: uppercase; color: #6b7280; }
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #f3f4f6; color: #6b7280; }
        
        /* --- TICKET SPECIFIC STYLES --- */
        .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        .stat-box { background: #f9fafb; padding: 15px; border-radius: 6px; text-align: center; border: 1px solid #e5e7eb; }
        .stat-num { font-size: 24px; font-weight: bold; color: #1f2937; }
        .stat-label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }

        .ticket-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .ticket-table th { background: #f3f4f6; padding: 10px; text-align: left; color: #6b7280; font-size: 12px; }
        .ticket-table td { padding: 10px; border-bottom: 1px solid #f3f4f6; font-family: 'Courier New', monospace; font-weight: bold; }
        .status-used { color: #dc2626; background: #fee2e2; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-family: sans-serif; }
        .status-unused { color: #059669; background: #ecfdf5; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-family: sans-serif; }

        .btn-generate { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size:13px; }
        .btn-print { background: #4b5563; color: white; text-decoration: none; padding: 8px 15px; border-radius: 6px; font-size: 13px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; border: none; }
        .btn-clear { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-size: 13px; }

        /* LOADING & UTILS */
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255, 255, 255, 0.95); display: none; justify-content: center; align-items: center; z-index: 2000; flex-direction: column; }
        .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #F59E0B; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin-bottom: 15px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .toggle-password { position: absolute; right: 10px; top: 35px; cursor: pointer; color: #9ca3af; }
    </style>
</head>
<body>

    <div id="loadingOverlay" class="loading-overlay">
        <div class="spinner"></div>
        <div style="font-weight:600; color:#374151;">Switching Event...</div>
    </div>

    <div class="main-wrapper">
        <?php require_once __DIR__ . '/../app/views/partials/sidebar.php'; ?>

        <div class="content-area">
            <div class="navbar">
                <div class="navbar-title">Settings</div>
            </div>

            <div class="container">
                <div id="toast-container" class="toast-container"></div>

                <div class="settings-container">
                    
                    <div class="settings-sidebar">
                        <a href="?tab=event_details" class="tab-btn <?= $active_tab == 'event_details' ? 'active' : '' ?>">
                            <i class="fas fa-sliders-h"></i> Event Configuration
                        </a>
                        <a href="?tab=tickets" class="tab-btn <?= $active_tab == 'tickets' ? 'active' : '' ?>">
                            <i class="fas fa-ticket-alt"></i> Audience Tickets
                        </a>
                        <a href="?tab=history" class="tab-btn <?= $active_tab == 'history' ? 'active' : '' ?>">
                            <i class="fas fa-history"></i> Switch Event
                        </a>
                        <a href="?tab=account" class="tab-btn <?= $active_tab == 'account' ? 'active' : '' ?>">
                            <i class="fas fa-user-cog"></i> Account Settings
                        </a>
                    </div>

                    <div class="settings-content">
                        
                        <div class="card <?= $active_tab == 'event_details' ? 'active' : '' ?>">
                            <div class="card-header">
                                <div>
                                    <h3>Current Event Configuration</h3>
                                    <p>Edit details for the currently active event.</p>
                                </div>
                            </div>

                            <?php if (!$active_event): ?>
                                <div style="text-align:center; padding:20px; color:#9ca3af;">
                                    <i class="fas fa-exclamation-circle"></i> No active event selected. Go to "Switch Event" to create or select one.
                                </div>
                            <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="event_id" value="<?= $active_event['id'] ?>">
                                    <div class="form-group">
                                        <label class="form-label">Event Name</label>
                                        <input type="text" name="event_name" class="form-control" value="<?= htmlspecialchars($active_event['name']) ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Event Date</label>
                                        <input type="date" name="event_date" class="form-control" value="<?= htmlspecialchars($active_event['event_date']) ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Venue</label>
                                        <input type="text" name="venue" class="form-control" value="<?= htmlspecialchars($active_event['venue']) ?>" required>
                                    </div>
                                    <div style="text-align:right;">
                                        <button type="submit" name="update_event_details" class="btn-save">Save Changes</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>

                        <div class="card <?= $active_tab == 'tickets' ? 'active' : '' ?>">
                            <div class="card-header">
                                <div>
                                    <h3>Audience Voting Tickets</h3>
                                    <p>Generate unique codes for "People's Choice" voting.</p>
                                </div>
                                <?php if ($active_event): ?>
                                <button class="btn-print" onclick="printTickets()"><i class="fas fa-print"></i> Print List</button>
                                <?php endif; ?>
                            </div>

                            <?php if (!$active_event): ?>
                                <div style="text-align:center; padding:20px; color:#9ca3af;">
                                    <i class="fas fa-exclamation-circle"></i> No active event selected.
                                </div>
                            <?php else: ?>
                                
                                <div class="stats-grid">
                                    <div class="stat-box">
                                        <div class="stat-num" style="color:#059669;"><?= $total_unused ?></div>
                                        <div class="stat-label">Available Tickets</div>
                                    </div>
                                    <div class="stat-box">
                                        <div class="stat-num" style="color:#dc2626;"><?= $total_used ?></div>
                                        <div class="stat-label">Votes Cast (Used)</div>
                                    </div>
                                </div>

                                <div style="display:flex; gap:10px; align-items:flex-end; margin-bottom:20px; background:#f9fafb; padding:15px; border-radius:8px; flex-wrap:wrap;">
                                    <form action="../api/tickets.php" method="POST" style="display:flex; gap:10px; align-items:flex-end; flex:1;">
                                        <input type="hidden" name="action" value="generate">
                                        <input type="hidden" name="event_id" value="<?= $active_event['id'] ?>">
                                        <div style="flex:1; max-width:200px;">
                                            <label style="display:block; font-size:13px; margin-bottom:5px; color:#4b5563;">Generate Quantity</label>
                                            <input type="number" name="quantity" class="form-control" value="50" min="1" max="500">
                                        </div>
                                        <button type="submit" class="btn-generate">Generate Codes</button>
                                    </form>
                                    
                                    <div style="margin-left:auto;">
                                        <form action="../api/tickets.php" method="POST" onsubmit="return confirm('Are you sure? This will delete all UNUSED tickets.');">
                                            <input type="hidden" name="action" value="clear_unused">
                                            <input type="hidden" name="event_id" value="<?= $active_event['id'] ?>">
                                            <button type="submit" class="btn-clear"><i class="fas fa-trash"></i> Clear Unused</button>
                                        </form>
                                    </div>
                                </div>

                                <div style="max-height: 400px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 6px;">
                                    <table class="ticket-table" id="printableTable">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Ticket Code</th>
                                                <th>Status</th>
                                                <th>Generated Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($tickets)): ?>
                                                <tr><td colspan="4" style="text-align:center; padding:20px;">No tickets generated yet.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($tickets as $index => $t): ?>
                                                    <tr>
                                                        <td style="color:#9ca3af; font-family:sans-serif;"><?= $index + 1 ?></td>
                                                        <td style="font-size:16px; letter-spacing:1px;"><?= htmlspecialchars($t['code']) ?></td>
                                                        <td>
                                                            <span class="<?= ($t['status'] == 'Used') ? 'status-used' : 'status-unused' ?>">
                                                                <?= $t['status'] ?>
                                                            </span>
                                                        </td>
                                                        <td style="font-family:sans-serif; font-weight:normal; font-size:12px; color:#6b7280;">
                                                            <?= date('M d, Y h:i A', strtotime($t['created_at'])) ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="card <?= $active_tab == 'history' ? 'active' : '' ?>">
                            <div class="card-header">
                                <div>
                                    <h3>Event Switcher</h3>
                                    <p>Manage multiple pageants and switch between them.</p>
                                </div>
                                <button onclick="openModal()" style="background:#1f2937; color:white; border:none; padding:8px 15px; border-radius:6px; cursor:pointer; font-size:13px;">
                                    + Create New
                                </button>
                            </div>

                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Event Name</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_events as $evt): $is_curr = ($evt['status'] === 'Active'); ?>
                                    <tr style="<?= $is_curr ? 'background:#f0fdf4;' : '' ?>">
                                        <td><strong><?= htmlspecialchars($evt['name']) ?></strong></td>
                                        <td><?= $evt['event_date'] ?></td>
                                        <td><span class="badge <?= $is_curr ? 'badge-active' : 'badge-inactive' ?>"><?= $evt['status'] ?></span></td>
                                        <td>
                                            <?php if (!$is_curr): ?>
                                                <a href="#" onclick="confirmSwitch(<?= $evt['id'] ?>, '<?= htmlspecialchars($evt['name'], ENT_QUOTES) ?>')" style="color:#2563eb; text-decoration:none; font-size:13px; font-weight:600;">Switch</a>
                                            <?php else: ?>
                                                <span style="color:#059669; font-size:13px; font-weight:bold;"><i class="fas fa-check"></i> Active</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="card <?= $active_tab == 'account' ? 'active' : '' ?>">
                            <div class="card-header">
                                <div>
                                    <h3>Account Settings</h3>
                                    <p>Manage your login credentials.</p>
                                </div>
                            </div>
                            <form method="POST">
                                <div class="form-group">
                                    <label class="form-label">Your Name</label>
                                    <input type="text" name="my_name" class="form-control" value="<?= htmlspecialchars($me['name']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Email Address (Read Only)</label>
                                    <input type="email" class="form-control" value="<?= htmlspecialchars($me['email']) ?>" disabled style="background:#f3f4f6;">
                                </div>
                                
                                <hr style="border:0; border-top:1px solid #f3f4f6; margin:20px 0;">
                                <h4 style="margin-bottom:15px; font-size:14px; color:#111827;">Security Verification</h4>

                                <div class="form-group">
                                    <label class="form-label">Current Password (Required)</label>
                                    <input type="password" name="current_password" id="currPass" class="form-control" required placeholder="Enter current password to save changes">
                                    <i class="fas fa-eye toggle-password" onclick="togglePassword('currPass', this)"></i>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">New Password</label>
                                    <input type="password" name="my_password" id="newPass" class="form-control" placeholder="Leave blank to keep current password">
                                    <i class="fas fa-eye toggle-password" onclick="togglePassword('newPass', this)"></i>
                                </div>

                                <div style="text-align:right;">
                                    <button type="submit" name="update_profile" class="btn-save">Update Profile</button>
                                </div>
                            </form>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="createModal" class="modal-overlay" style="display:none;">
        <div style="background:white; padding:30px; width:400px; border-radius:12px; position:relative;">
            <h3 style="margin-bottom:15px;">Create New Event</h3>
            <form action="../api/event.php" method="POST">
                <input type="text" name="event_name" class="form-control" placeholder="Event Name" required style="margin-bottom:10px;">
                <input type="date" name="event_date" class="form-control" required style="margin-bottom:10px;">
                <input type="text" name="venue" class="form-control" placeholder="Venue" required style="margin-bottom:20px;">
                <div style="text-align:right; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="document.getElementById('createModal').style.display='none'" style="background:#e5e7eb; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">Cancel</button>
                    <button type="submit" style="background:#F59E0B; color:white; border:none; padding:8px 15px; border-radius:4px; font-weight:bold; cursor:pointer;">Create</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() { document.getElementById('createModal').style.display = 'flex'; }

        function confirmSwitch(id, name) {
            if(confirm("Switch to '" + name + "'?")) {
                document.getElementById('loadingOverlay').style.display = 'flex';
                setTimeout(() => { window.location.href = "settings.php?open_id=" + id; }, 2000);
            }
        }

        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                input.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        }

        // --- NEW: PRINT TICKETS ---
        function printTickets() {
            var divToPrint = document.getElementById("printableTable");
            var newWin = window.open("");
            newWin.document.write("<html><head><title>Ticket Codes</title>");
            newWin.document.write("<style>body{font-family: sans-serif;} table{width:100%; border-collapse:collapse;} th, td{border:1px solid #000; padding:8px; text-align:left;} th{background:#eee;}</style>");
            newWin.document.write("</head><body>");
            newWin.document.write("<h3>Audience Tickets for <?= htmlspecialchars($active_event['name'] ?? 'Event') ?></h3>");
            newWin.document.write(divToPrint.outerHTML);
            newWin.document.write("</body></html>");
            newWin.print();
            newWin.close();
        }

        // Toast Logic
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icon = type === 'success' ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-exclamation-circle"></i>';
            toast.innerHTML = `${icon} <span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => { toast.remove(); }, 3500);
        }

        <?php if (isset($_SESSION['success'])): ?>
            showToast("<?= $_SESSION['success'] ?>", "success");
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            showToast("<?= $_SESSION['error'] ?>", "error");
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        // Handle URL Params for Success/Error (e.g. from Ticket API)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('success')) showToast(urlParams.get('success'), 'success');
        if (urlParams.has('error')) showToast(urlParams.get('error'), 'error');
        if (urlParams.has('success') || urlParams.has('error')) {
            // Clean URL but keep current tab
            const newUrl = window.location.pathname + "?tab=" + "<?= $active_tab ?>";
            window.history.replaceState({}, document.title, newUrl);
        }
    </script>
</body>
</html>