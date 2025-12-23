<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole(['Event Manager', 'Contestant Manager']);
require_once __DIR__ . '/../app/config/database.php';

// --- TAB LOGIC ---
$view = $_GET['view'] ?? 'active';
$status_filter = ($view === 'pending') ? 'Pending' : 'Active';
$my_id = $_SESSION['user_id'];

// Fetch Contestants
$sql = "SELECT u.id, u.name, u.email, u.status, 
               cd.age, cd.height, cd.vital_stats, cd.hometown, cd.motto, cd.photo, 
               e.name as event_name 
        FROM users u 
        JOIN contestant_details cd ON u.id = cd.user_id 
        JOIN events e ON cd.event_id = e.id 
        WHERE e.user_id = ? AND u.role = 'Contestant' AND u.status = ? 
        ORDER BY u.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $my_id, $status_filter);
$stmt->execute();
$contestants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch Events for Modal
$evt_stmt = $conn->prepare("SELECT id, name FROM events WHERE user_id = ? AND status = 'Active'");
$evt_stmt->bind_param("i", $my_id);
$evt_stmt->execute();
$my_events = $evt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Contestants - BPMS</title>
    <link rel="stylesheet" href="./assets/css/style.css">
    <style>
        /* Styles from previous step */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; }
        .tab-link { padding: 10px 20px; text-decoration: none; color: #6b7280; font-weight: 600; border-bottom: 2px solid transparent; margin-bottom: -2px; }
        .tab-link:hover { color: #1f2937; }
        .tab-link.active { border-bottom-color: #F59E0B; color: #F59E0B; }
        .btn-add { background-color: #F59E0B; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .btn-add:hover { background-color: #d97706; }
        .contestant-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        .contestant-card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); display: flex; flex-direction: column; }
        .card-img { width: 100%; height: 250px; object-fit: cover; background: #f3f4f6; }
        .card-body { padding: 20px; flex-grow: 1; }
        .card-title { font-size: 18px; font-weight: bold; color: #1f2937; margin-bottom: 5px; }
        .card-subtitle { font-size: 13px; color: #F59E0B; margin-bottom: 15px; font-weight: 600; text-transform: uppercase; }
        .stats-row { display: flex; justify-content: space-between; font-size: 13px; color: #6b7280; margin-bottom: 8px; border-bottom: 1px solid #f9fafb; padding-bottom: 8px; }
        .motto-text { font-style: italic; color: #4b5563; font-size: 13px; margin-top: 10px; line-height: 1.4; }
        .card-actions { padding: 15px 20px; background: #f9fafb; border-top: 1px solid #f3f4f6; display: flex; justify-content: flex-end; gap: 10px; }
        .btn-sm { padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-approve { background: #d1fae5; color: #059669; }
        .btn-reject { background: #fee2e2; color: #dc2626; }
        .btn-remove { color: #ef4444; background: transparent; }
        .btn-remove:hover { text-decoration: underline; }

        /* Modal Styles */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 30px; width: 500px; border-radius: 12px; max-height: 90vh; overflow-y: auto; }
        .form-group { margin-bottom: 15px; position: relative; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 14px; }
        .form-control { width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; }
        .form-row { display: flex; gap: 15px; }
        .form-row .form-group { flex: 1; }

        /* Password Toggle */
        .toggle-password { position: absolute; right: 10px; top: 32px; cursor: pointer; color: #9ca3af; }
    </style>
</head>
<body>

    <div class="main-wrapper">
        <?php require_once __DIR__ . '/../app/views/partials/sidebar.php'; ?>

        <div class="content-area">
            <div class="navbar">
                <div class="navbar-title">Register Contestant</div>
            </div>

            <div class="container">
                <div id="toast-container" class="toast-container"></div>

                <div class="page-header">
                    <h2>Manage Contestants</h2>
                    <button class="btn-add" onclick="openModal('addModal')">+ Manually Add</button>
                </div>

                <div class="tabs">
                    <a href="?view=active" class="tab-link <?= $view === 'active' ? 'active' : '' ?>"><i class="fas fa-user-check"></i> Official Candidates</a>
                    <a href="?view=pending" class="tab-link <?= $view === 'pending' ? 'active' : '' ?>"><i class="fas fa-clock"></i> Pending Applications</a>
                </div>

                <?php if (empty($contestants)): ?>
                    <div style="text-align:center; padding:50px; color:#9ca3af; background:white; border-radius:12px;">
                        <i class="fas fa-users-slash" style="font-size:40px; margin-bottom:15px;"></i>
                        <p>No <?= htmlspecialchars($status_filter) ?> contestants found.</p>
                    </div>
                <?php else: ?>
                    <div class="contestant-grid">
                        <?php foreach ($contestants as $c): ?>
                            <div class="contestant-card">
                                <img src="./assets/uploads/contestants/<?= htmlspecialchars($c['photo']) ?>" alt="Photo" class="card-img">
                                <div class="card-body">
                                    <div class="card-title"><?= htmlspecialchars($c['name']) ?></div>
                                    <div class="card-subtitle"><?= htmlspecialchars($c['hometown']) ?></div>
                                    <div class="stats-row">
                                        <span><strong>Age:</strong> <?= $c['age'] ?></span>
                                        <span><strong>Height:</strong> <?= htmlspecialchars($c['height']) ?></span>
                                    </div>
                                    <div class="stats-row">
                                        <span><strong>Event:</strong> <?= htmlspecialchars($c['event_name']) ?></span>
                                    </div>
                                    <?php if (!empty($c['motto'])): ?>
                                        <div class="motto-text">"<?= htmlspecialchars($c['motto']) ?>"</div>
                                    <?php endif; ?>
                                </div>
                                <div class="card-actions">
                                    <?php if ($view === 'pending'): ?>
                                        <a href="../api/contestant.php?action=approve&id=<?= $c['id'] ?>" class="btn-sm btn-approve" onclick="return confirm('Approve this candidate?');">Approve</a>
                                        <a href="../api/contestant.php?action=reject&id=<?= $c['id'] ?>" class="btn-sm btn-reject" onclick="return confirm('Reject this application?');">Reject</a>
                                    <?php else: ?>
                                        <button class="btn-sm" style="color:#2563eb; background:none; border:none; cursor:pointer;">Edit</button>
                                        <a href="../api/contestant.php?action=remove&id=<?= $c['id'] ?>" class="btn-sm btn-remove" onclick="return confirm('Remove this contestant?');">Remove</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div id="addModal" class="modal-overlay">
        <div class="modal-content">
            <h3 style="margin-bottom:20px;">Add New Contestant</h3>
            <form action="../api/contestant.php" method="POST" enctype="multipart/form-data">
                
                <div class="form-group">
                    <label>Assign to Event</label>
                    <select name="event_id" class="form-control" required>
                        <?php foreach ($my_events as $evt): ?>
                            <option value="<?= $evt['id'] ?>"><?= htmlspecialchars($evt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Age</label>
                        <input type="number" name="age" class="form-control" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" id="manualPass" class="form-control" value="123456" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePassword('manualPass', this)"></i>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Height</label>
                        <input type="text" name="height" class="form-control" placeholder="170cm">
                    </div>
                    <div class="form-group">
                        <label>Vital Stats</label>
                        <input type="text" name="vital_stats" class="form-control" placeholder="34-24-36">
                    </div>
                </div>

                <div class="form-group">
                    <label>Hometown</label>
                    <input type="text" name="hometown" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Motto</label>
                    <input type="text" name="motto" class="form-control">
                </div>

                <div class="form-group">
                    <label>Photo</label>
                    <input type="file" name="photo" class="form-control" accept="image/*" required>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                    <button type="button" onclick="closeModal('addModal')" style="padding:10px; border:none; background:#e5e7eb; border-radius:6px; cursor:pointer;">Cancel</button>
                    <button type="submit" style="padding:10px 20px; border:none; background:#F59E0B; color:white; border-radius:6px; font-weight:bold; cursor:pointer;">Save Contestant</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }

        // --- PASSWORD TOGGLE ---
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

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('success')) showToast(urlParams.get('success'), 'success');
        if (urlParams.has('error')) showToast(urlParams.get('error'), 'error');
        
        if (urlParams.has('success') || urlParams.has('error')) {
            const newUrl = window.location.pathname + (urlParams.has('view') ? '?view=' + urlParams.get('view') : '');
            window.history.replaceState({}, document.title, newUrl);
        }
    </script>

</body>
</html>